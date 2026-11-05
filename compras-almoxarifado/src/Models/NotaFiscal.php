<?php

namespace App\Models;

class NotaFiscal extends BaseModel
{
    protected string $table = 'notas_fiscais';

    public function allComOC(array $filtros = []): array
    {
        $where  = '1=1';
        $params = [];
        if (!empty($filtros['status'])) { $where .= ' AND nf.status = ?'; $params[] = $filtros['status']; }

        return $this->query(
            "SELECT nf.*, oc.numero_oc, f.razao_social AS fornecedor_nome, u.nome AS conferidor
             FROM notas_fiscais nf
             JOIN ordens_compra oc ON oc.id = nf.oc_id
             JOIN fornecedores f   ON f.id  = oc.fornecedor_id
             LEFT JOIN usuarios u  ON u.id  = nf.conferido_por
             WHERE {$where}
             ORDER BY nf.criado_em DESC",
            $params
        );
    }

    public function conferir(string $id, array $ocItens, array $nfItens, float $tolerancia): array
    {
        $divergencias = [];

        foreach ($ocItens as $ocItem) {
            $nfItem = array_filter($nfItens, fn($n) => $n['item_id'] === $ocItem['item_id']);
            $nfItem = current($nfItem);

            if (!$nfItem) {
                $divergencias[] = ['tipo' => 'item_ausente', 'item' => $ocItem['item_nome']];
                continue;
            }

            $diffQtd = abs($nfItem['quantidade'] - $ocItem['quantidade']);
            if ($diffQtd > 0.001) {
                $divergencias[] = [
                    'tipo'  => 'quantidade_diferente',
                    'item'  => $ocItem['item_nome'],
                    'oc'    => $ocItem['quantidade'],
                    'nf'    => $nfItem['quantidade'],
                ];
            }

            $diffValor = abs($nfItem['valor_unitario'] - $ocItem['valor_unitario']);
            $pctDesvio = $ocItem['valor_unitario'] > 0
                ? $diffValor / $ocItem['valor_unitario'] * 100
                : 0;

            if ($pctDesvio > $tolerancia) {
                $divergencias[] = [
                    'tipo'  => 'valor_divergente',
                    'item'  => $ocItem['item_nome'],
                    'oc'    => $ocItem['valor_unitario'],
                    'nf'    => $nfItem['valor_unitario'],
                    'desvio'=> round($pctDesvio, 2),
                ];
            }
        }

        return $divergencias;
    }
}
