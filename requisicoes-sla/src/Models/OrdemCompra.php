<?php
// @versao Ver.002.301026

namespace App\Models;

class OrdemCompra extends BaseModel
{
    protected string $table = 'ordens_compra';

    public const PRAZO_PAGAMENTO_MAX = 365;

    /**
     * Valida "Entrega prevista" (Y-m-d) e "Condição de pagamento" (parcelas em dias,
     * ex.: "30/60/90" — ver CondicaoPagamento::validar), obrigatórios ao gerar a OC
     * (painel fase 2 + R005). $entregaMinima: menor data aceita (Y-m-d) — hoje ao gerar;
     * na correção pelo detalhe, a data de emissão da OC. prazo_pagamento_dias = prazo
     * médio arredondado (o painel segue lendo esse campo).
     *
     * @return array{entrega_prevista: string, prazo_pagamento_dias: int, condicao_pagamento: string, parcelas_dias: string}|string
     *         dados ou a mensagem de erro
     */
    public static function validarPrazos(mixed $entrega, mixed $condicao, string $entregaMinima): array|string
    {
        $entrega  = trim((string)$entrega);
        $condicao = trim((string)$condicao);

        if ($entrega === '' || $condicao === '') {
            return 'Informe a entrega prevista e a condição de pagamento da ordem de compra.';
        }
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $entrega);
        if (!$data || $data->format('Y-m-d') !== $entrega) {
            return 'Entrega prevista inválida.';
        }
        if ($entrega < $entregaMinima) {
            return 'A entrega prevista não pode ser antes de ' . date('d/m/Y', strtotime($entregaMinima)) . '.';
        }
        $pagamento = \App\Services\CondicaoPagamento::validar($condicao);
        if (is_string($pagamento)) {
            return $pagamento;
        }

        return [
            'entrega_prevista'     => $entrega,
            'prazo_pagamento_dias' => $pagamento['prazo_pagamento_dias'],
            'condicao_pagamento'   => $pagamento['condicao_pagamento'],
            'parcelas_dias'        => $pagamento['parcelas_dias'],
        ];
    }

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
