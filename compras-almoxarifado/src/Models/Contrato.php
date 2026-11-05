<?php

namespace App\Models;

class Contrato extends BaseModel
{
    protected string $table = 'contratos';

    public function allComFornecedor(array $filtros = []): array
    {
        $where  = '1=1';
        $params = [];

        if (!empty($filtros['status']))       { $where .= ' AND c.status = ?';       $params[] = $filtros['status']; }
        if (!empty($filtros['recorrente']))   { $where .= ' AND c.recorrente = ?';   $params[] = $filtros['recorrente']; }
        if (!empty($filtros['fornecedor_id'])){ $where .= ' AND c.fornecedor_id = ?';$params[] = $filtros['fornecedor_id']; }
        if (!empty($filtros['setor_id']))     { $where .= ' AND c.setor_id = ?';     $params[] = $filtros['setor_id']; }

        return $this->query(
            "SELECT c.*, f.razao_social AS fornecedor_nome, f.nome_fantasia AS fornecedor_fantasia, f.cnpj AS fornecedor_cnpj,
                    s.nome AS setor_nome,
                    DATEDIFF(c.data_fim, CURDATE()) AS dias_vencimento
             FROM contratos c
             JOIN fornecedores f ON f.id = c.fornecedor_id
             LEFT JOIN setores s ON s.id = c.setor_id
             WHERE {$where}
             ORDER BY c.data_fim ASC",
            $params
        );
    }

    public function vencendoEmDias(int $dias = 30): array
    {
        return $this->query(
            "SELECT c.*, f.razao_social AS fornecedor_nome,
                    DATEDIFF(c.data_fim, CURDATE()) AS dias_restantes
             FROM contratos c
             JOIN fornecedores f ON f.id = c.fornecedor_id
             WHERE c.status = 'ativo'
               AND c.data_fim BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY c.data_fim ASC",
            [$dias]
        );
    }

    public function totalMensalRecorrentes(): float
    {
        $val = $this->queryScalar(
            "SELECT COALESCE(SUM(
                COALESCE(valor_mensal,
                    CASE WHEN is_valor_total = 1
                         THEN ROUND(valor / GREATEST(1, num_parcelas), 2)
                         ELSE valor
                    END
                )
             ), 0)
             FROM contratos
             WHERE status = 'ativo' AND recorrente = 1"
        );
        return (float)$val;
    }

    public function dashboardRecorrentes(): array
    {
        return $this->query(
            "SELECT c.*, f.razao_social AS fornecedor_nome,
                    DATEDIFF(c.data_fim, CURDATE()) AS dias_restantes,
                    COALESCE(c.valor_mensal,
                        CASE WHEN c.is_valor_total = 1
                             THEN ROUND(c.valor / GREATEST(1, c.num_parcelas), 2)
                             ELSE c.valor
                        END
                    ) AS valor_mensal
             FROM contratos c
             JOIN fornecedores f ON f.id = c.fornecedor_id
             WHERE c.status = 'ativo' AND c.recorrente = 1
             ORDER BY valor_mensal DESC
             LIMIT 20"
        );
    }

    public function atualizarStatusVencidos(): void
    {
        $this->execute(
            "UPDATE contratos SET status = 'vencido'
             WHERE status = 'ativo' AND data_fim < CURDATE()"
        );
    }
}
