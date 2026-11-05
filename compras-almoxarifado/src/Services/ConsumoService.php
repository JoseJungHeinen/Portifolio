<?php
// @versao Ver.001.160926

namespace App\Services;

/**
 * Consumo de estoque = saídas que representam uso, atribuídas a quem consumiu.
 *
 *   requisicao        → filial destino da requisição (senão a filial da requisição) e setor
 *                        da requisição (a saída pode ter sido gravada na matriz)
 *   brinde            → filial da requisição de brinde
 *   perda/avaria/outro → filial do movimento
 *   transferencia, inventario, correcao, ajuste_valor → não é consumo
 *
 * Referência apagada: usa a filial do movimento.
 *
 * Movimento antigo com `motivo` NULL/vazio mas `referencia_tipo` 'requisicao' ou 'brinde'
 * é tratado como se `motivo` fosse esse `referencia_tipo` (motivo efetivo).
 *
 * Spec: docs/superpowers/specs/2026-09-16-reestruturacao-relatorios-design.md
 */
class ConsumoService
{
    public const MOTIVOS = ['requisicao', 'brinde', 'perda', 'avaria', 'outro'];

    public function __construct(private \PDO $db, private ValorEstoqueService $valores) {}

    /**
     * Agregado por item/filial/setor/dia — a soma é feita no banco (GROUP BY) para não
     * carregar um movimento por linha na memória do PHP em períodos longos.
     *
     * @param string                  $de       'Y-m-d'
     * @param string                  $ate      'Y-m-d' (inclusive)
     * @param array<int, string>|null $filiais  null = todas; [] = nenhuma; [ids] = filiais atribuídas aceitas
     * @return list<array{item_id: string, item_nome: string, item_codigo: ?string, unidade: ?string,
     *                    filial_id: string, filial_nome: string, setor_id: ?string,
     *                    dia: string, quantidade: float, valor: float}>
     */
    public function linhas(string $de, string $ate, ?array $filiais, ?string $setorId = null): array
    {
        $motivos = implode(',', array_fill(0, count(self::MOTIVOS), '?'));
        [$condFilial, $paramsFilial] = sql_escopo_in('c.filial_id', $filiais);

        // motivo_efetivo: usa referencia_tipo quando motivo está vazio (movimentos antigos).
        $sql = "SELECT c.item_id, c.item_nome, c.item_codigo, c.unidade, c.filial_id, f.nome AS filial_nome,
                       c.setor_id, c.dia, SUM(c.quantidade) AS quantidade
                FROM (
                    SELECT me2.item_id, i.nome AS item_nome, i.codigo AS item_codigo, i.unidade,
                           me2.quantidade, DATE(me2.criado_em) AS dia,
                           CASE me2.motivo_efetivo
                               WHEN 'requisicao' THEN COALESCE(r.filial_destino_id, r.filial_id, me2.filial_id)
                               WHEN 'brinde'     THEN COALESCE(rb.filial_id, me2.filial_id)
                               ELSE me2.filial_id
                           END AS filial_id,
                           CASE WHEN me2.motivo_efetivo = 'requisicao' THEN r.setor_id END AS setor_id
                    FROM (
                        SELECT me.item_id, me.filial_id, me.quantidade, me.criado_em, me.referencia_id,
                               COALESCE(
                                   NULLIF(me.motivo, ''),
                                   CASE WHEN me.referencia_tipo IN ('requisicao', 'brinde') THEN me.referencia_tipo END
                               ) AS motivo_efetivo
                        FROM movimentos_estoque me
                        WHERE me.tipo = 'saida'
                          AND me.quantidade > 0
                          AND me.criado_em BETWEEN ? AND ?
                    ) me2
                    JOIN itens i ON i.id = me2.item_id
                    LEFT JOIN requisicoes r         ON me2.motivo_efetivo = 'requisicao' AND r.id  = me2.referencia_id
                    LEFT JOIN requisicoes_brinde rb ON me2.motivo_efetivo = 'brinde'     AND rb.id = me2.referencia_id
                    WHERE me2.motivo_efetivo IN ($motivos)
                ) c
                JOIN filiais f ON f.id = c.filial_id
                WHERE 1=1 {$condFilial}";
        $params = [$de . ' 00:00:00', $ate . ' 23:59:59', ...self::MOTIVOS, ...$paramsFilial];

        if ($setorId) {
            $sql     .= ' AND c.setor_id = ?';
            $params[] = $setorId;
        }
        $sql .= ' GROUP BY c.item_id, c.item_nome, c.item_codigo, c.unidade, c.filial_id, f.nome, c.setor_id, c.dia
                   ORDER BY c.dia ASC';

        $st = $this->db->prepare($sql);
        $st->execute($params);
        $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);

        $this->valores->carregar(array_column($linhas, 'item_id'));
        foreach ($linhas as &$l) {
            $l['quantidade'] = (float)$l['quantidade'];
            $l['valor']      = $l['quantidade'] * $this->valores->precoEm($l['item_id'], $l['dia'] . ' 23:59:59');
        }
        unset($l);

        return $linhas;
    }

    private const MESES = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];

    /**
     * Agrega as linhas de linhas() para a tela. Não acessa o banco.
     *
     * @param list<array>              $linhas
     * @param 'dia'|'mes'|'ano'        $agrupamento
     * @param 'valor'|'quantidade'     $ordemTop
     * @return array{total: float, itens_distintos: int, maior_filial: ?array, rotulos: list<string>,
     *               series: list<array{nome: string, valores: list<float>}>,
     *               ranking: list<array{filial_id: string, nome: string, valor: float, pct: float}>,
     *               top_itens: list<array{item_id: string, nome: string, codigo: ?string, unidade: ?string, quantidade: float, valor: float}>}
     */
    public static function resumir(array $linhas, string $agrupamento, string $de, string $ate, string $ordemTop = 'valor'): array
    {
        $tamanhoChave = ['dia' => 10, 'mes' => 7, 'ano' => 4][$agrupamento];
        $incremento   = ['dia' => '+1 day', 'mes' => '+1 month', 'ano' => '+1 year'][$agrupamento];
        $inicio       = ['dia' => $de, 'mes' => substr($de, 0, 7) . '-01', 'ano' => substr($de, 0, 4) . '-01-01'][$agrupamento];
        // dia: rótulo com ano (d/m/y) quando De e Até caem em anos diferentes; senão d/m.
        $formatoDia = substr($de, 0, 4) !== substr($ate, 0, 4) ? 'd/m/y' : 'd/m';

        // Todos os períodos do intervalo, inclusive os sem consumo
        $posicao = [];
        $rotulos = [];
        $fim     = new \DateTimeImmutable($ate);
        for ($d = new \DateTimeImmutable($inicio); $d <= $fim; $d = $d->modify($incremento)) {
            $posicao[substr($d->format('Y-m-d'), 0, $tamanhoChave)] = count($rotulos);
            $rotulos[] = match ($agrupamento) {
                'dia' => $d->format($formatoDia),
                'mes' => self::MESES[(int)$d->format('n') - 1] . '/' . $d->format('y'),
                'ano' => $d->format('Y'),
            };
        }
        $zeros = array_fill(0, count($rotulos), 0.0);

        $total     = 0.0;
        $porFilial = [];
        $serie     = [];
        $porItem   = [];
        foreach ($linhas as $l) {
            $chave = substr($l['dia'], 0, $tamanhoChave);
            if (!isset($posicao[$chave]) || $l['dia'] < $de || $l['dia'] > $ate) {
                continue;
            }
            $valor = (float)$l['valor'];
            $total += $valor;

            $fid = $l['filial_id'];
            $porFilial[$fid] ??= ['filial_id' => $fid, 'nome' => $l['filial_nome'], 'valor' => 0.0, 'pct' => 0.0];
            $porFilial[$fid]['valor'] += $valor;
            $serie[$fid] ??= $zeros;
            $serie[$fid][$posicao[$chave]] += $valor;

            $iid = $l['item_id'];
            $porItem[$iid] ??= ['item_id' => $iid, 'nome' => $l['item_nome'], 'codigo' => $l['item_codigo'],
                                'unidade' => $l['unidade'], 'quantidade' => 0.0, 'valor' => 0.0];
            $porItem[$iid]['quantidade'] += (float)$l['quantidade'];
            $porItem[$iid]['valor']      += $valor;
        }

        $ranking = array_values($porFilial);
        usort($ranking, fn($a, $b) => $b['valor'] <=> $a['valor']);
        foreach ($ranking as &$f) {
            $f['pct'] = $total > 0 ? round($f['valor'] / $total * 100, 1) : 0.0;
        }
        unset($f);

        // Gráfico: as 8 maiores filiais + "Outras"
        $series = [];
        $outras = $zeros;
        foreach ($ranking as $i => $f) {
            if ($i < 8) {
                $series[] = ['nome' => $f['nome'], 'valores' => $serie[$f['filial_id']]];
            } else {
                foreach ($serie[$f['filial_id']] as $j => $v) {
                    $outras[$j] += $v;
                }
            }
        }
        if (count($ranking) > 8) {
            $series[] = ['nome' => 'Outras', 'valores' => $outras];
        }

        $campo = $ordemTop === 'quantidade' ? 'quantidade' : 'valor';
        $top   = array_values($porItem);
        usort($top, fn($a, $b) => $b[$campo] <=> $a[$campo]);

        return [
            'total'           => $total,
            'itens_distintos' => count($porItem),
            'maior_filial'    => $ranking[0] ?? null,
            'rotulos'         => $rotulos,
            'series'          => $series,
            'ranking'         => $ranking,
            'top_itens'       => array_slice($top, 0, 20),
        ];
    }
}
