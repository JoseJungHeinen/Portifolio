<?php
// @versao Ver.002.281026

namespace App\Services;

/**
 * Consumo por Período: por mês, o que cada estoque (item + filial) consumiu e quanto faltava,
 * no fim do mês, para chegar ao estoque mínimo.
 *
 *   consumo      saídas com motivo de uso (ConsumoService::MOTIVOS, mesmo "motivo efetivo") do
 *                estoque físico da filial — aqui não se usa a filial atribuída do Consumo por
 *                Filial, porque a reposição é do estoque que ficou sem o item
 *   valor        quantidade × preço da época do dia da saída (ValorEstoqueService)
 *   saldo_fim    saldo no fim do mês, reconstruído de trás para frente: saldo atual − movimentos
 *                (entradas − saídas, qualquer motivo) posteriores ao fim do mês
 *   minimo       estoque.estoque_minimo de hoje (o mínimo não tem histórico); item inativo = 0
 *   repor        máx(0, mínimo − saldo_fim); saldo negativo conta como zero. Meses anteriores ao
 *                primeiro movimento do estoque não têm reposição (o estoque ainda não existia);
 *                estoque sem movimento nenhum (legado) usa o saldo atual em todos os meses
 *   repor_valor  repor × preço da época no fim do mês (mês corrente: agora)
 */
class ConsumoReposicaoService
{
    public function __construct(private \PDO $db, private ValorEstoqueService $valores) {}

    /**
     * Uma linha por mês × item × filial com consumo ou reposição no mês.
     *
     * @param string                  $de      'Y-m' (primeiro mês)
     * @param string                  $ate     'Y-m' (último mês; meses futuros são ignorados)
     * @param array<int, string>|null $filiais null = todas; [] = nenhuma
     * @param string|null             $agora   'Y-m-d H:i:s'
     * @return list<array{mes: string, item_id: string, codigo: ?string, nome: string, unidade: ?string,
     *                    filial_id: string, filial: string, consumo_qtd: float, consumo_valor: float,
     *                    saldo_fim: float, minimo: float, repor: float, repor_valor: float}>
     */
    public function linhas(string $de, string $ate, ?array $filiais, ?string $grupoId, ?string $agora = null): array
    {
        $agora = $agora ?? date('Y-m-d H:i:s');
        $ate   = min($ate, substr($agora, 0, 7));
        $meses = self::meses($de, $ate);
        if (!$meses) {
            return [];
        }

        [$condEstoque, $paramsEstoque] = sql_escopo_in('e.filial_id', $filiais);
        [$condMov, $paramsMov]         = sql_escopo_in('me.filial_id', $filiais);
        $condGrupo = $grupoId ? ' AND i.grupo_produto_id = ?' : '';

        // Estoque de hoje: saldo e mínimo de cada item + filial
        $st = $this->db->prepare(
            "SELECT e.item_id, e.filial_id, e.quantidade, e.estoque_minimo, i.ativo
             FROM estoque e
             JOIN itens i ON i.id = e.item_id
             WHERE 1=1 {$condEstoque} {$condGrupo}"
        );
        $st->execute([...$paramsEstoque, ...($grupoId ? [$grupoId] : [])]);
        $estoque = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $estoque[$r['item_id'] . '|' . $r['filial_id']] = [
                'atual'  => (float)$r['quantidade'],
                'minimo' => $r['ativo'] ? (float)$r['estoque_minimo'] : 0.0,
            ];
        }

        // Movimentos do início do período até agora, somados por dia no banco
        $motivos = implode(',', array_fill(0, count(ConsumoService::MOTIVOS), '?'));
        $st = $this->db->prepare(
            "SELECT m.item_id, m.filial_id, m.dia,
                    SUM(CASE WHEN m.tipo = 'entrada' THEN m.quantidade ELSE -m.quantidade END) AS liquido,
                    SUM(CASE WHEN m.tipo = 'saida' AND m.quantidade > 0 AND m.motivo_efetivo IN ($motivos)
                             THEN m.quantidade ELSE 0 END) AS consumo
             FROM (
                 SELECT me.item_id, me.filial_id, me.tipo, me.quantidade, DATE(me.criado_em) AS dia,
                        COALESCE(
                            NULLIF(me.motivo, ''),
                            CASE WHEN me.referencia_tipo IN ('requisicao', 'brinde') THEN me.referencia_tipo END
                        ) AS motivo_efetivo
                 FROM movimentos_estoque me
                 JOIN itens i ON i.id = me.item_id
                 WHERE me.criado_em >= ? AND me.criado_em <= ? {$condMov} {$condGrupo}
             ) m
             GROUP BY m.item_id, m.filial_id, m.dia"
        );
        $st->execute([...ConsumoService::MOTIVOS, $de . '-01 00:00:00', $agora, ...$paramsMov,
                      ...($grupoId ? [$grupoId] : [])]);
        $movimentos = $st->fetchAll(\PDO::FETCH_ASSOC);

        // Mês do primeiro movimento de cada estoque (qualquer data): antes dele não há reposição
        $st = $this->db->prepare(
            "SELECT me.item_id, me.filial_id, DATE_FORMAT(MIN(me.criado_em), '%Y-%m') AS mes
             FROM movimentos_estoque me
             JOIN itens i ON i.id = me.item_id
             WHERE 1=1 {$condMov} {$condGrupo}
             GROUP BY me.item_id, me.filial_id"
        );
        $st->execute([...$paramsMov, ...($grupoId ? [$grupoId] : [])]);
        $primeiroMes = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $primeiroMes[$r['item_id'] . '|' . $r['filial_id']] = $r['mes'];
        }

        $this->valores->carregar(array_merge(
            array_column($movimentos, 'item_id'),
            array_map(fn($k) => explode('|', $k)[0], array_keys($estoque))
        ));

        // Por estoque: líquido por mês (inclusive meses depois do período) e consumo dos meses do período
        $liquido = [];
        $consumo = [];
        foreach ($movimentos as $m) {
            $chave = $m['item_id'] . '|' . $m['filial_id'];
            $mes   = substr($m['dia'], 0, 7);
            $liquido[$chave][$mes] = ($liquido[$chave][$mes] ?? 0.0) + (float)$m['liquido'];
            $qtd = (float)$m['consumo'];
            if ($qtd > 0 && $mes <= $ate) {
                $consumo[$chave][$mes] ??= ['qtd' => 0.0, 'valor' => 0.0];
                $consumo[$chave][$mes]['qtd']   += $qtd;
                $consumo[$chave][$mes]['valor'] += $qtd * $this->valores->precoEm($m['item_id'], $m['dia'] . ' 23:59:59');
            }
        }

        $fimMes = [];
        foreach ($meses as $mes) {
            $fim          = date('Y-m-t 23:59:59', strtotime($mes . '-01'));
            $fimMes[$mes] = min($fim, $agora);
        }

        $linhas = [];
        foreach (array_unique([...array_keys($estoque), ...array_keys($consumo)]) as $chave) {
            [$itemId, $filialId] = explode('|', $chave);
            $atual  = $estoque[$chave]['atual'] ?? 0.0;
            $minimo = $estoque[$chave]['minimo'] ?? 0.0;
            if ($minimo <= 0 && !isset($consumo[$chave])) {
                continue;
            }

            foreach ($meses as $mes) {
                $depois = 0.0;
                foreach ($liquido[$chave] ?? [] as $m => $v) {
                    if ($m > $mes) {
                        $depois += $v;
                    }
                }
                $saldoFim = $atual - $depois;
                $repor    = isset($primeiroMes[$chave]) && $mes < $primeiroMes[$chave]
                    ? 0.0
                    : max(0.0, $minimo - max(0.0, $saldoFim));
                $c        = $consumo[$chave][$mes] ?? ['qtd' => 0.0, 'valor' => 0.0];
                if ($c['qtd'] <= 0 && $repor <= 0) {
                    continue;
                }
                $linhas[] = [
                    'mes'           => $mes,
                    'item_id'       => $itemId,
                    'filial_id'     => $filialId,
                    'consumo_qtd'   => $c['qtd'],
                    'consumo_valor' => $c['valor'],
                    'saldo_fim'     => $saldoFim,
                    'minimo'        => $minimo,
                    'repor'         => $repor,
                    'repor_valor'   => $repor > 0 ? $repor * $this->valores->precoEm($itemId, $fimMes[$mes]) : 0.0,
                ];
            }
        }

        return $this->comNomes($linhas);
    }

    /** Acrescenta código/nome/unidade do item e nome da filial. */
    private function comNomes(array $linhas): array
    {
        if (!$linhas) {
            return [];
        }
        $nomes = [];
        foreach ([['itens', 'item_id', 'id, codigo, nome, unidade'], ['filiais', 'filial_id', 'id, nome']] as [$tabela, $campo, $colunas]) {
            $ids = array_values(array_unique(array_column($linhas, $campo)));
            foreach (array_chunk($ids, 500) as $lote) {
                $in = implode(',', array_fill(0, count($lote), '?'));
                $st = $this->db->prepare("SELECT {$colunas} FROM {$tabela} WHERE id IN ($in)");
                $st->execute($lote);
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $nomes[$tabela][$r['id']] = $r;
                }
            }
        }
        foreach ($linhas as &$l) {
            $item         = $nomes['itens'][$l['item_id']] ?? [];
            $l['codigo']  = $item['codigo'] ?? null;
            $l['nome']    = $item['nome'] ?? '(item removido)';
            $l['unidade'] = $item['unidade'] ?? null;
            $l['filial']  = $nomes['filiais'][$l['filial_id']]['nome'] ?? '(filial removida)';
        }
        unset($l);
        return $linhas;
    }

    /** @return list<string> meses 'Y-m' de $de a $ate, inclusive */
    public static function meses(string $de, string $ate): array
    {
        $meses = [];
        for ($d = new \DateTimeImmutable($de . '-01'); $d->format('Y-m') <= $ate; $d = $d->modify('+1 month')) {
            $meses[] = $d->format('Y-m');
        }
        return $meses;
    }

    /**
     * Totais por mês para a tela. Não acessa o banco.
     *
     * @return list<array{mes: string, consumo_valor: float, itens_consumidos: int,
     *                    abaixo_minimo: int, repor_valor: float}>
     */
    public static function porMes(array $linhas, array $meses): array
    {
        $porMes = [];
        foreach ($meses as $mes) {
            $porMes[$mes] = ['mes' => $mes, 'consumo_valor' => 0.0, 'itens_consumidos' => 0,
                             'abaixo_minimo' => 0, 'repor_valor' => 0.0];
        }
        foreach ($linhas as $l) {
            if (!isset($porMes[$l['mes']])) {
                continue;
            }
            $p = &$porMes[$l['mes']];
            $p['consumo_valor'] += $l['consumo_valor'];
            $p['repor_valor']   += $l['repor_valor'];
            $p['itens_consumidos'] += $l['consumo_qtd'] > 0 ? 1 : 0;
            $p['abaixo_minimo']    += $l['repor'] > 0 ? 1 : 0;
            unset($p);
        }
        return array_values($porMes);
    }

    /**
     * Linhas formatadas para CSV.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    public static function exportar(array $linhas): array
    {
        $num = fn($v): string => number_format((float)$v, 2, ',', '');
        return [
            ['Mês', 'Código', 'Item', 'Unidade', 'Filial', 'Consumido', 'Valor consumido',
             'Saldo fim do mês', 'Mínimo', 'A repor', 'Valor a repor'],
            array_map(fn($l) => [
                substr($l['mes'], 5, 2) . '/' . substr($l['mes'], 0, 4), (string)$l['codigo'], $l['nome'],
                (string)$l['unidade'], $l['filial'], $num($l['consumo_qtd']), $num($l['consumo_valor']),
                $num($l['saldo_fim']), $num($l['minimo']), $num($l['repor']), $num($l['repor_valor']),
            ], $linhas),
        ];
    }
}
