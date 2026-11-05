<?php
// @versao Ver.001.160926

namespace App\Services;

/**
 * Preço de um item numa data ("preço da época"):
 *   1. último historico_precos.valor_unitario > 0 com registrado_em <= data
 *   2. custo médio atual do estoque (linhas com valor_unitario > 0 e quantidade > 0)
 *   3. itens.valor_referencia
 *   4. 0
 *
 * Movimentos de estoque só guardam quantidade; relatórios valorizam cada um por aqui.
 * carregar() busca vários itens de uma vez para não fazer uma consulta por movimento.
 */
class ValorEstoqueService
{
    /** @var array<string, list<array{0: string, 1: float}>> item_id => [[registrado_em, valor], ...] em ordem cronológica */
    private array $precos = [];

    /** @var array<string, float> item_id => preço usado quando não há histórico até a data */
    private array $fallback = [];

    public function __construct(private \PDO $db) {}

    /** @param array<int, string> $itemIds */
    public function carregar(array $itemIds): void
    {
        $faltam = array_values(array_unique(array_filter($itemIds, fn($id) => !isset($this->fallback[$id]))));

        foreach (array_chunk($faltam, 500) as $lote) {
            $in = implode(',', array_fill(0, count($lote), '?'));
            foreach ($lote as $id) {
                $this->precos[$id]   = [];
                $this->fallback[$id] = 0.0;
            }

            $st = $this->db->prepare(
                "SELECT item_id, registrado_em, valor_unitario
                 FROM historico_precos
                 WHERE item_id IN ($in) AND valor_unitario > 0
                 ORDER BY item_id, registrado_em ASC"
            );
            $st->execute($lote);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $this->precos[$r['item_id']][] = [$r['registrado_em'], (float)$r['valor_unitario']];
            }

            $st = $this->db->prepare(
                "SELECT i.id, i.valor_referencia,
                        (SELECT SUM(e.quantidade * e.valor_unitario) / NULLIF(SUM(e.quantidade), 0)
                           FROM estoque e
                          WHERE e.item_id = i.id AND e.valor_unitario > 0 AND e.quantidade > 0) AS custo_medio
                 FROM itens i
                 WHERE i.id IN ($in)"
            );
            $st->execute($lote);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $this->fallback[$r['id']] = (float)($r['custo_medio'] ?: 0) ?: (float)($r['valor_referencia'] ?? 0);
            }
        }
    }

    /** @param string $dataHora 'Y-m-d H:i:s' */
    public function precoEm(string $itemId, string $dataHora): float
    {
        if (!isset($this->fallback[$itemId])) {
            $this->carregar([$itemId]);
        }

        $preco = null;
        foreach ($this->precos[$itemId] as [$em, $valor]) {
            if ($em > $dataHora) {
                break;
            }
            $preco = $valor;
        }
        return $preco ?? $this->fallback[$itemId];
    }

    /** Há alguma fonte de preço (histórico, custo ou referência)? */
    public function temPreco(string $itemId): bool
    {
        if (!isset($this->fallback[$itemId])) {
            $this->carregar([$itemId]);
        }
        return !empty($this->precos[$itemId]) || $this->fallback[$itemId] > 0;
    }
}
