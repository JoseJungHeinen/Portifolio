<?php

namespace App\Models;

class OrdemCompra extends BaseModel
{
    protected string $table = 'ordens_compra';

    public function allComDetalhes(array $filtros = []): array
    {
        $where  = '1=1';
        $params = [];
        if (!empty($filtros['status']))       { $where .= ' AND oc.status = ?';       $params[] = $filtros['status']; }
        if (!empty($filtros['fornecedor_id'])){ $where .= ' AND oc.fornecedor_id = ?';$params[] = $filtros['fornecedor_id']; }

        return $this->query(
            "SELECT oc.*, f.razao_social AS fornecedor_nome, u.nome AS criador_nome,
                    r.filial_id, fl.nome AS filial_nome
             FROM ordens_compra oc
             JOIN fornecedores f ON f.id = oc.fornecedor_id
             JOIN usuarios u     ON u.id = oc.criado_por
             JOIN requisicoes r  ON r.id = oc.requisicao_id
             JOIN filiais fl     ON fl.id = r.filial_id
             WHERE {$where}
             ORDER BY oc.criado_em DESC",
            $params
        );
    }

    public function itens(string $ocId): array
    {
        return $this->query(
            "SELECT oi.*, i.nome AS item_nome, i.unidade
             FROM ordens_compra_itens oi
             JOIN itens i ON i.id = oi.item_id
             WHERE oi.oc_id = ?",
            [$ocId]
        );
    }

    public function proximoNumero(): string
    {
        $prefixo = date('Ym'); // ex: 202605
        $row = $this->queryOne(
            "SELECT COUNT(*) AS total FROM ordens_compra WHERE numero_oc LIKE ?",
            ['OC-' . $prefixo . '%']
        );
        $seq = (int)($row['total'] ?? 0) + 1;
        return 'OC-' . $prefixo . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }
}
