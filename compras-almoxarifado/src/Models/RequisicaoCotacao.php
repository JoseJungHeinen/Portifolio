<?php
// @versao Ver.001.061026

namespace App\Models;

/**
 * Cotações digitadas à mão numa requisição do tipo Ordem de Compra.
 * Uma linha por fornecedor; exatamente uma marcada como vencedora no envio.
 */
class RequisicaoCotacao extends BaseModel
{
    protected string $table = 'requisicao_cotacoes';

    /** @return list<array> cotações da requisição, menor (valor + frete) primeiro */
    public function daRequisicao(string $requisicaoId): array
    {
        return $this->query(
            "SELECT rc.*, COALESCE(NULLIF(f.nome_fantasia, ''), f.razao_social) AS fornecedor, f.ativo AS fornecedor_ativo
             FROM requisicao_cotacoes rc
             JOIN fornecedores f ON f.id = rc.fornecedor_id
             WHERE rc.requisicao_id = ?
             ORDER BY (rc.valor_total + rc.frete) ASC, f.razao_social",
            [$requisicaoId]
        );
    }

    /** @return array<string, list<array>> itens por requisicao_cotacao_id, em ordem de posição */
    public function itensPorCotacao(string $requisicaoId): array
    {
        $linhas = $this->query(
            "SELECT rci.*
             FROM requisicao_cotacao_itens rci
             JOIN requisicao_cotacoes rc ON rc.id = rci.requisicao_cotacao_id
             WHERE rc.requisicao_id = ?
             ORDER BY rci.posicao",
            [$requisicaoId]
        );

        $porCotacao = [];
        foreach ($linhas as $linha) {
            $porCotacao[$linha['requisicao_cotacao_id']][] = $linha;
        }

        return $porCotacao;
    }

    public function vencedora(string $requisicaoId): ?array
    {
        return $this->queryOne(
            "SELECT rc.*, COALESCE(NULLIF(f.nome_fantasia, ''), f.razao_social) AS fornecedor, f.ativo AS fornecedor_ativo
             FROM requisicao_cotacoes rc
             JOIN fornecedores f ON f.id = rc.fornecedor_id
             WHERE rc.requisicao_id = ? AND rc.vencedora = 1
             LIMIT 1",
            [$requisicaoId]
        );
    }

    /**
     * Regrava as cotações do pedido: apaga as antigas e insere as linhas que têm
     * fornecedor. Linha em branco é ignorada — o formulário sempre manda 3 blocos.
     *
     * @param list<array{fornecedor_id?: string, valor_total?: string|float, frete?: string|float,
     *                   prazo_dias?: string|int|null, condicao_pagamento?: ?string, observacao?: ?string,
     *                   itens?: list<array{descricao?: string, valor?: string|float, cotado?: int}>}> $linhas
     * @param int|null $vencedorIndice posição marcada no formulário
     */
    public function substituir(string $requisicaoId, array $linhas, ?int $vencedorIndice): void
    {
        // Apagar e regravar precisa ser tudo-ou-nada: sem transação, uma falha no
        // meio do INSERT (ex.: fornecedor duplicado) deixa a requisição sem
        // nenhuma cotação em vez de com as antigas.
        $this->db->beginTransaction();
        try {
            $this->execute("DELETE FROM requisicao_cotacoes WHERE requisicao_id = ?", [$requisicaoId]);

            foreach ($linhas as $i => $linha) {
                $fornecedorId = trim((string)($linha['fornecedor_id'] ?? ''));
                if ($fornecedorId === '') {
                    continue;
                }

                $prazo = trim((string)($linha['prazo_dias'] ?? ''));

                $itens = array_values($linha['itens'] ?? []);

                // Com itens, o valor da cotação é a soma dos que foram cotados —
                // o formulário não digita mais um total. Sem itens (pedido antigo,
                // fixture de teste), vale o valor_total informado.
                $valorTotal = $itens
                    ? array_sum(array_map(
                        fn($item) => (int)($item['cotado'] ?? 1) === 1 ? parse_decimal((string)($item['valor'] ?? '0')) : 0.0,
                        $itens
                    ))
                    : parse_decimal((string)($linha['valor_total'] ?? '0'));

                $cotacaoId = uuid();

                $this->execute(
                    "INSERT INTO requisicao_cotacoes
                     (id, requisicao_id, fornecedor_id, valor_total, frete, prazo_dias, condicao_pagamento, observacao, vencedora)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $cotacaoId,
                        $requisicaoId,
                        $fornecedorId,
                        $valorTotal,
                        parse_decimal((string)($linha['frete'] ?? '0')),
                        $prazo !== '' ? (int)$prazo : null,
                        trim((string)($linha['condicao_pagamento'] ?? '')) ?: null,
                        trim((string)($linha['observacao'] ?? '')) ?: null,
                        $vencedorIndice === $i ? 1 : 0,
                    ]
                );

                foreach ($itens as $posicao => $item) {
                    $descricao = trim((string)($item['descricao'] ?? ''));
                    if ($descricao === '') {
                        continue;
                    }
                    $this->execute(
                        "INSERT INTO requisicao_cotacao_itens
                         (id, requisicao_cotacao_id, posicao, descricao, valor, cotado)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [
                            uuid(),
                            $cotacaoId,
                            (int)$posicao,
                            mb_substr($descricao, 0, 255),
                            parse_decimal((string)($item['valor'] ?? '0')),
                            (int)($item['cotado'] ?? 1) === 1 ? 1 : 0,
                        ]
                    );
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
