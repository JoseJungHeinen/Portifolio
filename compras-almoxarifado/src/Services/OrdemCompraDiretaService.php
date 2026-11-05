<?php
// @versao Ver.002.281026

namespace App\Services;

use App\Config\Database;
use App\Models\RequisicaoCotacao;

/**
 * Regras da requisição tipo Ordem de Compra: validar o conjunto de cotações,
 * manter o valor da requisição e levar as cotações digitadas para o fluxo de
 * compras na aprovação.
 *
 * Fica num serviço porque a tela (envio) e a aprovação (geração da cotação)
 * precisam das mesmas regras.
 *
 * Specs: docs/superpowers/specs/2026-09-17-requisicao-ordem-compra-design.md
 *        docs/superpowers/specs/2026-09-23-ordem-compra-ajustes-design.md
 */
class OrdemCompraDiretaService
{
    private \PDO $db;
    private RequisicaoCotacao $cotacoes;

    public function __construct(?\PDO $db = null)
    {
        $this->db       = $db ?? Database::getInstance();
        $this->cotacoes = new RequisicaoCotacao();
    }

    public function minimoCotacoes(): int
    {
        return $this->parametro('ordem_compra.minimo_cotacoes', 2);
    }

    public function recomendadoCotacoes(): int
    {
        return $this->parametro('ordem_compra.recomendado_cotacoes', 3);
    }

    /**
     * Motivos para não enviar o pedido. Lista vazia = pode enviar.
     *
     * @return list<string>
     */
    public function erros(string $requisicaoId): array
    {
        $cotacoes = $this->cotacoes->daRequisicao($requisicaoId);
        $erros    = [];

        // Política de Compras §8: o mínimo depende do valor (2 até R$ 1.000; 3 acima).
        // Usa a maior cotação, para não escapar da faixa escolhendo a mais barata.
        $maiorValor = 0.0;
        foreach ($cotacoes as $c) {
            $maiorValor = max($maiorValor, (float)$c['valor_total'] + (float)$c['frete']);
        }
        $minimo = (new PoliticaComprasService($this->db))->minimoCotacoes($maiorValor);

        if (count($cotacoes) < $minimo) {
            $erros[] = sprintf('Registre no mínimo %d cotações para enviar (Política de Compras).', $minimo);
        }

        foreach ($cotacoes as $cotacao) {
            if ((float)$cotacao['valor_total'] <= 0) {
                $erros[] = 'Informe o valor dos itens cotados por ' . $cotacao['fornecedor'] . '.';
            }
            if ((float)$cotacao['frete'] < 0) {
                $erros[] = 'O frete da cotação de ' . $cotacao['fornecedor'] . ' não pode ser negativo.';
            }
            if ((int)($cotacao['fornecedor_ativo'] ?? 1) !== 1) {
                $erros[] = 'O fornecedor ' . $cotacao['fornecedor'] . ' está inativo.';
            }
        }

        $vencedoras = array_filter($cotacoes, fn($c) => (int)$c['vencedora'] === 1);
        if (count($vencedoras) !== 1) {
            $erros[] = 'Selecione a cotação vencedora.';
        }

        return $erros;
    }

    /** Aviso de cotações abaixo do recomendado, ou null quando está no recomendado. */
    public function aviso(string $requisicaoId): ?string
    {
        $quantidade  = count($this->cotacoes->daRequisicao($requisicaoId));
        $recomendado = $this->recomendadoCotacoes();

        return $quantidade > 0 && $quantidade < $recomendado
            ? sprintf('Enviado com %d cotação(ões) — o recomendado é %d.', $quantidade, $recomendado)
            : null;
    }

    /**
     * Grava em requisicoes.valor_total o valor da vencedora + frete — é o que a
     * empresa paga, e é esse número que passa pela alçada e consome orçamento.
     * Sem vencedora, zera. Não usa Requisicao::recalcularTotal(), que deriva dos itens.
     */
    public function sincronizarValor(string $requisicaoId): float
    {
        $vencedora = $this->cotacoes->vencedora($requisicaoId);
        $valor     = $vencedora ? (float)$vencedora['valor_total'] + (float)$vencedora['frete'] : 0.0;

        $this->db->prepare("UPDATE requisicoes SET valor_total = ? WHERE id = ?")->execute([$valor, $requisicaoId]);

        return $valor;
    }

    /**
     * Transforma as cotações digitadas numa cotação de compras já respondida:
     * é ela que leva o pedido para Compras > Cotações, onde o comprador usa o
     * comparativo e gera a OC pelo fluxo normal (CompraController::gerarOC).
     *
     * Idempotente: com uma cotação não cancelada já ligada ao pedido, devolve o
     * id dela sem gravar nada — aprovar duas vezes não pode duplicar nada.
     *
     * Devolve o id da cotação, ou null quando não há cotações digitadas ou a
     * gravação falha.
     */
    public function gerarCotacao(string $requisicaoId, string $usuarioId): ?string
    {
        $cotacoes = $this->cotacoes->daRequisicao($requisicaoId);
        if (empty($cotacoes)) {
            return null;
        }

        $existenteStmt = $this->db->prepare(
            "SELECT id FROM cotacoes WHERE requisicao_id = ? AND status <> 'cancelada' LIMIT 1"
        );
        $existenteStmt->execute([$requisicaoId]);
        $existente = $existenteStmt->fetchColumn();
        if ($existente) {
            return (string)$existente;
        }

        $stmt = $this->db->prepare("SELECT numero, justificativa FROM requisicoes WHERE id = ? LIMIT 1");
        $stmt->execute([$requisicaoId]);
        $req = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$req) {
            return null;
        }

        $itensPorCotacao = $this->cotacoes->itensPorCotacao($requisicaoId);

        // Descrições do pedido, pela posição. Todas as cotações carregam a mesma
        // lista (o formulário grava a matriz inteira), então basta a primeira que
        // tiver a posição. Pedido sem itens (formato antigo) cai no fallback.
        $descricoes = [];
        foreach ($itensPorCotacao as $itens) {
            foreach ($itens as $item) {
                $descricoes[(int)$item['posicao']] ??= (string)$item['descricao'];
            }
        }
        ksort($descricoes);

        if (!$descricoes) {
            $fallback = trim((string)($req['justificativa'] ?? ''));
            $descricoes = [0 => $fallback !== ''
                ? mb_substr($fallback, 0, 255)
                : 'Ordem de compra — requisição Nº ' . str_pad((string)$req['numero'], 5, '0', STR_PAD_LEFT)];
        }

        $cotacaoId = uuid();

        try {
            $this->db->beginTransaction();

            $this->db->prepare(
                "INSERT INTO cotacoes (id, requisicao_id, status, prazo_resposta, criado_por, criado_em, usar_credito)
                 VALUES (?, ?, 'aguardando_respostas', NOW(), ?, NOW(), 0)"
            )->execute([$cotacaoId, $requisicaoId, $usuarioId]);

            foreach ($descricoes as $descricao) {
                $this->db->prepare(
                    "INSERT INTO cotacao_itens_solicitados (id, cotacao_id, item_id, descricao_livre, quantidade)
                     VALUES (?, ?, NULL, ?, 1)"
                )->execute([uuid(), $cotacaoId, $descricao]);
            }

            foreach ($cotacoes as $cotacao) {
                $cotFornId = uuid();

                // token_acesso é NOT NULL e único. A linha já nasce 'respondida',
                // e salvarResposta() só aceita 'enviada' — o link nunca é divulgado
                // nem serve para responder de fora.
                $this->db->prepare(
                    "INSERT INTO cotacao_fornecedores
                     (id, cotacao_id, fornecedor_id, status, token_acesso, respondido_em, frete, observacao)
                     VALUES (?, ?, ?, 'respondida', ?, NOW(), ?, ?)"
                )->execute([
                    $cotFornId,
                    $cotacaoId,
                    $cotacao['fornecedor_id'],
                    bin2hex(random_bytes(16)),
                    (float)$cotacao['frete'],
                    trim((string)($cotacao['condicao_pagamento'] ?? '')) !== ''
                        ? 'Condição de pagamento: ' . $cotacao['condicao_pagamento']
                        : null,
                ]);

                $itensDaCotacao = $itensPorCotacao[$cotacao['id']] ?? [];

                if ($itensDaCotacao) {
                    foreach ($itensDaCotacao as $item) {
                        if ((int)$item['cotado'] !== 1) {
                            continue;
                        }
                        $this->db->prepare(
                            "INSERT INTO cotacao_itens
                             (id, cotacao_fornecedor_id, item_id, descricao, quantidade, valor_unitario)
                             VALUES (?, ?, NULL, ?, 1, ?)"
                        )->execute([uuid(), $cotFornId, (string)$item['descricao'], (float)$item['valor']]);
                    }
                } else {
                    // Pedido no formato antigo: uma linha com o valor cheio.
                    $this->db->prepare(
                        "INSERT INTO cotacao_itens
                         (id, cotacao_fornecedor_id, item_id, descricao, quantidade, valor_unitario)
                         VALUES (?, ?, NULL, ?, 1, ?)"
                    )->execute([uuid(), $cotFornId, reset($descricoes), (float)$cotacao['valor_total']]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[OrdemCompraDiretaService] Falha ao gerar cotação: ' . $e->getMessage());

            return null;
        }

        return $cotacaoId;
    }

    private function parametro(string $chave, int $padrao): int
    {
        $stmt = $this->db->prepare("SELECT valor FROM parametros_sistema WHERE chave = ? LIMIT 1");
        $stmt->execute([$chave]);
        $valor = $stmt->fetchColumn();

        return $valor !== false && (int)$valor > 0 ? (int)$valor : $padrao;
    }
}
