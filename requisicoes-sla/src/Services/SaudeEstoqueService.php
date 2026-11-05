<?php
// @versao Ver.001.160926

namespace App\Services;

/**
 * Saúde do Estoque: foto do estoque por item + filial.
 *
 *   valor         quantidade × preço: usa estoque.valor_unitario da própria linha quando > 0 (mesmo
 *                 critério da tela de Estoque), senão o preço da época no momento (ValorEstoqueService)
 *   ultima_saida  última saída física deste estoque; inventário, correção e ajuste de valor não contam,
 *                 transferência conta (o item se mexeu)
 *   saida_90      quantidade que saiu deste estoque nos últimos 90 dias (mesmas regras)
 *   minimo        estoque.estoque_minimo desta filial (itens.estoque_minimo não é usado aqui)
 *   primeira_mov  data do primeiro movimento (entrada OU saída, qualquer motivo) deste item+filial;
 *                 usado para não marcar estoque recém-chegado, sem saída ainda, como "parado"
 *
 * A cobertura usa a saída física do próprio estoque, e não o consumo atribuído à filial do
 * Consumo por Filial: requisição interna atendida pela matriz sai do estoque da matriz, e a
 * pergunta aqui é quantos dias ESTE estoque dura.
 *
 * Spec: docs/superpowers/specs/2026-09-16-reestruturacao-relatorios-design.md (Parte 3)
 */
class SaudeEstoqueService
{
    public const MOTIVOS_IGNORADOS = ['inventario', 'correcao', 'ajuste_valor'];
    public const DIAS_CONSUMO      = 90;

    public function __construct(private \PDO $db, private ValorEstoqueService $valores) {}

    /**
     * Inclui estoque zerado (necessário para "abaixo do mínimo"); resumir() separa o resto.
     *
     * @param array<int, string>|null $filiais null = todas; [] = nenhuma
     * @param string|null             $agora   'Y-m-d H:i:s'; movimentos depois disso são ignorados
     * @return list<array{item_id: string, codigo: ?string, nome: string, unidade: ?string, filial_id: string,
     *                    filial: string, quantidade: float, minimo: float, ultima_saida: ?string,
     *                    primeira_mov: ?string, saida_90: float, valor: float}>
     */
    public function linhas(?array $filiais, ?string $grupoId, ?string $agora = null): array
    {
        $agora    = $agora ?? date('Y-m-d H:i:s');
        $desde    = date('Y-m-d H:i:s', strtotime($agora . ' -' . self::DIAS_CONSUMO . ' days'));
        $ignorados = implode(',', array_fill(0, count(self::MOTIVOS_IGNORADOS), '?'));
        [$condMov, $paramsMov]         = sql_escopo_in('me.filial_id', $filiais);
        [$condMov2, $paramsMov2]       = sql_escopo_in('me2.filial_id', $filiais);
        [$condEstoque, $paramsEstoque] = sql_escopo_in('e.filial_id', $filiais);

        $sql = "SELECT e.item_id, i.codigo, i.nome, i.unidade, e.filial_id, f.nome AS filial, e.quantidade,
                       e.estoque_minimo AS minimo,
                       e.valor_unitario, m.ultima_saida, COALESCE(m.saida_90, 0) AS saida_90, pm.primeira_mov
                FROM estoque e
                JOIN itens i   ON i.id = e.item_id   AND i.ativo = 1
                JOIN filiais f ON f.id = e.filial_id AND f.ativo = 1
                LEFT JOIN (
                    SELECT me.item_id, me.filial_id, MAX(me.criado_em) AS ultima_saida,
                           SUM(CASE WHEN me.criado_em >= ? THEN me.quantidade ELSE 0 END) AS saida_90
                    FROM movimentos_estoque me
                    WHERE me.tipo = 'saida'
                      AND me.quantidade > 0
                      AND me.criado_em <= ?
                      AND (me.motivo IS NULL OR me.motivo NOT IN ($ignorados))
                      {$condMov}
                    GROUP BY me.item_id, me.filial_id
                ) m ON m.item_id = e.item_id AND m.filial_id = e.filial_id
                LEFT JOIN (
                    SELECT me2.item_id, me2.filial_id, MIN(me2.criado_em) AS primeira_mov
                    FROM movimentos_estoque me2
                    WHERE me2.criado_em <= ?
                      {$condMov2}
                    GROUP BY me2.item_id, me2.filial_id
                ) pm ON pm.item_id = e.item_id AND pm.filial_id = e.filial_id
                WHERE 1=1 {$condEstoque}";
        $params = [$desde, $agora, ...self::MOTIVOS_IGNORADOS, ...$paramsMov, $agora, ...$paramsMov2, ...$paramsEstoque];

        if ($grupoId) {
            $sql     .= ' AND i.grupo_produto_id = ?';
            $params[] = $grupoId;
        }
        $sql .= ' ORDER BY i.nome, f.nome';

        $st = $this->db->prepare($sql);
        $st->execute($params);
        $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);

        $this->valores->carregar(array_column($linhas, 'item_id'));
        foreach ($linhas as &$l) {
            $l['quantidade']    = (float)$l['quantidade'];
            $l['minimo']        = (float)$l['minimo'];
            $l['saida_90']      = (float)$l['saida_90'];
            $valorUnitarioProprio = (float)$l['valor_unitario'];
            $preco              = $valorUnitarioProprio > 0 ? $valorUnitarioProprio : $this->valores->precoEm($l['item_id'], $agora);
            $l['valor']         = $l['quantidade'] * $preco;
            unset($l['valor_unitario']);
        }
        unset($l);

        return $linhas;
    }

    /**
     * Cálculos da tela a partir de linhas(). Não acessa o banco.
     * Cada linha devolvida ganha: media_dia, cobertura (?float, null = sem consumo), falta,
     * parado (bool) e dias_parado (?int, null = sem data de referência: nunca saiu e sem histórico algum).
     *
     * parado: tem saldo E (nunca saiu OU saiu antes do prazo) E (sem histórico algum OU o primeiro
     * movimento também é anterior ao prazo) — estoque que só chegou agora, sem saída ainda, não é parado.
     * dias_parado conta a partir da última saída; sem saída, conta a partir do primeiro movimento;
     * sem nenhum dos dois (estoque legado, sem histórico), fica null.
     *
     * @param 'acaba'|'sobra' $ordemCobertura
     * @return array{cards: array{valor_imobilizado: float, parados_qtd: int, parados_valor: float,
     *                            abaixo_minimo_qtd: int, cobertura_mediana: ?float},
     *               grafico: list<array{filial: string, parado: float, giro: float}>,
     *               parados: list<array>, minimo: list<array>, cobertura: list<array>}
     */
    public static function resumir(array $linhas, int $prazoParado, string $agora, string $ordemCobertura = 'acaba'): array
    {
        $limiteParado = date('Y-m-d H:i:s', strtotime($agora . " -{$prazoParado} days"));
        $hoje         = new \DateTimeImmutable(substr($agora, 0, 10));

        $cards     = ['valor_imobilizado' => 0.0, 'parados_qtd' => 0, 'parados_valor' => 0.0,
                      'abaixo_minimo_qtd' => 0, 'cobertura_mediana' => null];
        $porFilial = [];
        $parados   = [];
        $minimo    = [];
        $cobertura = [];
        $valoresCobertura = [];

        foreach ($linhas as $l) {
            $l['media_dia']   = $l['saida_90'] / self::DIAS_CONSUMO;
            $l['cobertura']   = $l['media_dia'] > 0 ? $l['quantidade'] / $l['media_dia'] : null;
            $l['falta']       = max(0.0, $l['minimo'] - $l['quantidade']);
            $l['parado']      = $l['quantidade'] > 0
                && ($l['ultima_saida'] === null || $l['ultima_saida'] < $limiteParado)
                && ($l['primeira_mov'] === null || $l['primeira_mov'] < $limiteParado);
            $dataBase         = $l['ultima_saida'] ?? $l['primeira_mov'];
            $l['dias_parado'] = $dataBase === null
                ? null
                : $hoje->diff(new \DateTimeImmutable(substr($dataBase, 0, 10)))->days;

            if ($l['minimo'] > 0 && $l['quantidade'] < $l['minimo']) {
                $minimo[] = $l;
            }
            if ($l['quantidade'] <= 0) {
                continue;
            }

            $cards['valor_imobilizado'] += $l['valor'];
            $porFilial[$l['filial_id']] ??= ['filial' => $l['filial'], 'parado' => 0.0, 'giro' => 0.0];
            $porFilial[$l['filial_id']][$l['parado'] ? 'parado' : 'giro'] += $l['valor'];

            if ($l['parado']) {
                $parados[] = $l;
                $cards['parados_qtd']++;
                $cards['parados_valor'] += $l['valor'];
            }
            if ($l['cobertura'] !== null) {
                $valoresCobertura[] = $l['cobertura'];
            }
            $cobertura[] = $l;
        }

        $cards['abaixo_minimo_qtd']  = count($minimo);
        $cards['cobertura_mediana']  = self::mediana($valoresCobertura);

        usort($parados, fn($a, $b) => $b['valor'] <=> $a['valor']);
        usort($minimo, fn($a, $b) => $b['falta'] <=> $a['falta']);
        $grafico = array_values($porFilial);
        usort($grafico, fn($a, $b) => ($b['parado'] + $b['giro']) <=> ($a['parado'] + $a['giro']));

        return [
            'cards'     => $cards,
            'grafico'   => $grafico,
            'parados'   => $parados,
            'minimo'    => $minimo,
            'cobertura' => self::ordenarCobertura($cobertura, $ordemCobertura),
        ];
    }

    /** Mediana de uma lista de floats; [] = null. Quantidade par: média dos dois valores centrais. */
    private static function mediana(array $valores): ?float
    {
        $n = count($valores);
        if ($n === 0) {
            return null;
        }
        sort($valores);
        $meio = intdiv($n, 2);
        return $n % 2 === 1 ? $valores[$meio] : ($valores[$meio - 1] + $valores[$meio]) / 2;
    }

    /** acaba: menor cobertura primeiro e "sem consumo" no fim; sobra: "sem consumo" primeiro e maior cobertura antes. */
    public static function ordenarCobertura(array $linhas, string $ordem): array
    {
        $semConsumoPrimeiro = $ordem === 'sobra';
        usort($linhas, function ($a, $b) use ($ordem, $semConsumoPrimeiro) {
            $ca = $a['cobertura'];
            $cb = $b['cobertura'];
            if ($ca === null || $cb === null) {
                if ($ca === $cb) {
                    return strcmp($a['nome'] . $a['filial'], $b['nome'] . $b['filial']);
                }
                return ($ca === null) === $semConsumoPrimeiro ? -1 : 1;
            }
            return $ordem === 'sobra' ? $cb <=> $ca : $ca <=> $cb;
        });
        return $linhas;
    }

    /**
     * Linhas da aba formatadas para CSV.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    public static function exportar(string $aba, array $lista): array
    {
        $num       = fn($v): string => number_format((float)$v, 2, ',', '');
        $cobertura = fn(array $l): string => $l['cobertura'] === null ? 'sem consumo' : number_format($l['cobertura'], 0, ',', '');

        return match ($aba) {
            'minimo' => [
                ['Código', 'Item', 'Filial', 'Quantidade', 'Mínimo', 'Falta', 'Cobertura (dias)'],
                array_map(fn($l) => [(string)$l['codigo'], $l['nome'], $l['filial'], $num($l['quantidade']),
                                     $num($l['minimo']), $num($l['falta']), $cobertura($l)], $lista),
            ],
            'cobertura' => [
                ['Código', 'Item', 'Filial', 'Quantidade', 'Saída 90 dias', 'Média/dia', 'Cobertura (dias)'],
                array_map(fn($l) => [(string)$l['codigo'], $l['nome'], $l['filial'], $num($l['quantidade']),
                                     $num($l['saida_90']), $num($l['media_dia']), $cobertura($l)], $lista),
            ],
            default => [
                ['Código', 'Item', 'Filial', 'Quantidade', 'Valor', 'Última saída', 'Dias parado'],
                array_map(fn($l) => [(string)$l['codigo'], $l['nome'], $l['filial'], $num($l['quantidade']), $num($l['valor']),
                                     $l['ultima_saida'] ? date('d/m/Y', strtotime($l['ultima_saida'])) : 'nunca',
                                     $l['dias_parado'] === null ? 'nunca saiu' : (string)$l['dias_parado']], $lista),
            ],
        };
    }
}
