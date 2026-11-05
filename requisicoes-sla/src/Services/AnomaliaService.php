<?php

namespace App\Services;

use App\Models\Anomalia;
use App\Config\Database;

class AnomaliaService
{
    private Anomalia $model;
    private AuditoriaService $auditoria;

    public function __construct()
    {
        $this->model     = new Anomalia();
        $this->auditoria = new AuditoriaService();
    }

    /** Roda após cada OC aprovada */
    public function analisarPosOC(array $oc, array $itens): void
    {
        foreach ($itens as $item) {
            $this->verificarCompraDuplicada($oc, $item);
            $this->verificarPrecoAcimaHistorico($oc, $item);
            $this->verificarFornecedorUnico($oc, $item);
        }
        $this->verificarSemCotacao($oc);
    }

    /** Roda toda virada de mês (cron) */
    public function analisarMensal(): void
    {
        $this->detectarEventualRecorrente();
        $this->detectarCentralizacaoPossivel();
        $this->detectarContratosReajusteAcimaInflacao();
    }

    /** Roda todo dia 26 (cron) */
    public function analisarFimDeMes(): void
    {
        $this->detectarPicoDeFimDeMes();
        $this->detectarOrcamentoEsgotadoCedo();
    }

    // ─── Verificações individuais ───────────────────────────────────────────

    private function verificarCompraDuplicada(array $oc, array $item): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT oci.id FROM ordens_compra_itens oci
             JOIN ordens_compra oc2 ON oc2.id = oci.oc_id
             WHERE oci.item_id = ?
               AND oc2.id != ?
               AND oc2.status NOT IN ('cancelado','rascunho')
               AND oc2.criado_em >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             LIMIT 1"
        );
        $stmt->execute([$item['item_id'], $oc['id']]);

        if ($stmt->fetch()) {
            $this->model->registrar(
                'compra_duplicada',
                'Possível compra duplicada',
                "Item '{$item['item_nome']}' foi comprado nos últimos 30 dias. Verifique se há necessidade real.",
                (float)$item['valor_unitario'] * (float)$item['quantidade'],
                'ordem_compra', $oc['id'],
                $oc['filial_id'] ?? null
            );
        }
    }

    private function verificarPrecoAcimaHistorico(array $oc, array $item): void
    {
        $db      = Database::getInstance();
        $stmt    = $db->prepare(
            "SELECT AVG(valor_unitario) AS media
             FROM historico_precos
             WHERE item_id = ?
               AND registrado_em >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
               AND origem_id != ?"
        );
        $stmt->execute([$item['item_id'], $oc['id']]);
        $row = $stmt->fetch();

        if ($row && $row['media'] > 0) {
            $desvio = ((float)$item['valor_unitario'] - (float)$row['media']) / (float)$row['media'] * 100;
            if ($desvio > 15) { // acima de 15% da média histórica
                $impacto = ((float)$item['valor_unitario'] - (float)$row['media']) * (float)$item['quantidade'];
                $this->model->registrar(
                    'preco_acima_historico',
                    'Preço acima da média histórica',
                    sprintf(
                        "Item '%s' comprado por %s (%.0f%% acima da média histórica de %s).",
                        $item['item_nome'],
                        money($item['valor_unitario']),
                        $desvio,
                        money($row['media'])
                    ),
                    $impacto,
                    'ordem_compra', $oc['id']
                );
            }
        }
    }

    private function verificarFornecedorUnico(array $oc, array $item): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT oc2.fornecedor_id) AS qtd_forn
             FROM ordens_compra_itens oci
             JOIN ordens_compra oc2 ON oc2.id = oci.oc_id
             WHERE oci.item_id = ?
               AND oc2.status NOT IN ('cancelado','rascunho')
               AND oc2.criado_em >= DATE_SUB(NOW(), INTERVAL 6 MONTH)"
        );
        $stmt->execute([$item['item_id']]);
        $row = $stmt->fetch();

        if ($row && (int)$row['qtd_forn'] === 1) {
            $this->model->registrar(
                'fornecedor_unico',
                'Fornecedor único nos últimos 6 meses',
                "Item '{$item['item_nome']}' foi comprado de apenas 1 fornecedor nos últimos 6 meses. Realize cotação comparativa.",
                (float)$item['valor_unitario'] * (float)$item['quantidade'] * 0.05,
                'item', $item['item_id']
            );
        }
    }

    private function verificarSemCotacao(array $oc): void
    {
        $limiteMinimo = 5000.0; // R$ 5.000 — poderia vir de parametros_sistema

        if ((float)$oc['valor_total'] >= $limiteMinimo && empty($oc['cotacao_id'])) {
            $this->model->registrar(
                'sem_cotacao_obrigatoria',
                'OC acima do limite sem cotação',
                sprintf(
                    "Ordem de compra #%s no valor de %s foi gerada sem cotação comparativa.",
                    $oc['numero_oc'], money($oc['valor_total'])
                ),
                (float)$oc['valor_total'] * 0.05,
                'ordem_compra', $oc['id']
            );
        }
    }

    private function detectarEventualRecorrente(): void
    {
        $db   = Database::getInstance();
        $stmt = $db->query(
            "SELECT grupo_produto_id, setor_id, filial_id, COUNT(*) AS ocorrencias,
                    SUM(valor_total) AS valor_total
             FROM requisicoes
             WHERE tipo_despesa = 'eventual'
               AND criado_em >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
               AND status = 'aprovado'
             GROUP BY grupo_produto_id, setor_id, filial_id
             HAVING ocorrencias >= 3"
        );

        foreach ($stmt->fetchAll() as $row) {
            $this->model->registrar(
                'despesa_eventual_recorrente',
                'Despesa eventual recorrente detectada',
                sprintf(
                    'Grupo de produto apareceu %d vezes como "eventual" em 6 meses. Total: %s. Considere incluir no orçamento fixo.',
                    $row['ocorrencias'], money($row['valor_total'])
                ),
                (float)$row['valor_total'] * 0.20,
                'grupo_produto', $row['grupo_produto_id'],
                $row['filial_id'], $row['setor_id']
            );
        }
    }

    private function detectarCentralizacaoPossivel(): void
    {
        $db   = Database::getInstance();
        $mes  = (int)date('n');
        $ano  = (int)date('Y');
        $stmt = $db->prepare(
            "SELECT oci.item_id, i.nome AS item_nome,
                    COUNT(DISTINCT oc.filial_id) AS qtd_filiais,
                    SUM(oci.quantidade * oci.valor_unitario) AS valor_total
             FROM ordens_compra_itens oci
             JOIN ordens_compra oc ON oc.id = oci.oc_id
             JOIN itens i ON i.id = oci.item_id
             WHERE MONTH(oc.criado_em) = ? AND YEAR(oc.criado_em) = ?
               AND oc.status NOT IN ('cancelado','rascunho')
             GROUP BY oci.item_id, i.nome
             HAVING qtd_filiais >= 2"
        );
        $stmt->execute([$mes, $ano]);

        foreach ($stmt->fetchAll() as $row) {
            $this->model->registrar(
                'centralizacao_possivel',
                'Compra centralizada possível',
                sprintf(
                    "Item '%s' foi comprado separadamente por %d filiais este mês. Centralize para ganho de escala.",
                    $row['item_nome'], $row['qtd_filiais']
                ),
                (float)$row['valor_total'] * 0.08,
                'item', $row['item_id']
            );
        }
    }

    private function detectarPicoDeFimDeMes(): void
    {
        $db   = Database::getInstance();
        $stmt = $db->query(
            "SELECT filial_id,
                    AVG(gasto_diario) AS media_diaria,
                    MAX(CASE WHEN dia >= 26 THEN gasto_diario ELSE 0 END) AS pico_fim
             FROM (
                SELECT filial_id,
                       DAY(criado_em) AS dia,
                       SUM(valor_total) AS gasto_diario
                FROM requisicoes
                WHERE MONTH(criado_em) = MONTH(CURDATE())
                  AND YEAR(criado_em)  = YEAR(CURDATE())
                  AND status = 'aprovado'
                GROUP BY filial_id, DAY(criado_em)
             ) t
             GROUP BY filial_id
             HAVING pico_fim > media_diaria * 2.5"
        );

        foreach ($stmt->fetchAll() as $row) {
            $this->model->registrar(
                'pico_fim_mes',
                'Pico de gastos no fim do mês',
                'Gastos acima de 2,5x a média diária detectados nos últimos dias do mês. Possível "queima de orçamento".',
                ((float)$row['pico_fim'] - (float)$row['media_diaria']) * 5,
                'filial', $row['filial_id'],
                $row['filial_id']
            );
        }
    }

    private function detectarContratosReajusteAcimaInflacao(): void
    {
        $ipca = 6.20; // poderia vir de parametros_sistema ou API do Banco Central
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT c.id, c.numero, c.valor, c.percentual_reajuste, c.reajuste_indexador,
                    f.razao_social AS fornecedor_nome
             FROM contratos c
             JOIN fornecedores f ON f.id = c.fornecedor_id
             WHERE c.status = 'ativo'
               AND c.percentual_reajuste > ?",
            [$ipca]
        );
        $stmt->execute([$ipca]);

        foreach ($stmt->fetchAll() as $row) {
            $this->model->registrar(
                'reajuste_acima_inflacao',
                'Contrato com reajuste acima da inflação',
                sprintf(
                    "Contrato com %s tem reajuste de %.1f%% (%s), acima do IPCA (%.1f%%). Renegocie no vencimento.",
                    $row['fornecedor_nome'],
                    $row['percentual_reajuste'],
                    $row['reajuste_indexador'],
                    $ipca
                ),
                (float)$row['valor'] * ($row['percentual_reajuste'] - $ipca) / 100,
                'contrato', $row['id']
            );
        }
    }

    private function detectarOrcamentoEsgotadoCedo(): void
    {
        $dia = (int)date('j');
        if ($dia > 20) return; // só alerta se esgotou antes do dia 20

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT o.*, s.nome AS setor_nome, gp.nome AS grupo_nome
             FROM orcamentos o
             JOIN setores s ON s.id = o.setor_id
             JOIN grupos_produtos gp ON gp.id = o.grupo_produto_id
             WHERE o.status = 'esgotado'
               AND o.mes = MONTH(CURDATE()) AND o.ano = YEAR(CURDATE())"
        );
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            $this->model->registrar(
                'orcamento_esgotado_cedo',
                'Orçamento esgotado antes do dia 20',
                sprintf(
                    "Setor '%s' / Grupo '%s' esgotou o orçamento antes do dia 20.",
                    $row['setor_nome'], $row['grupo_nome']
                ),
                (float)$row['valor_aprovado'] * 0.10,
                'orcamento', $row['id'],
                $row['filial_id'], $row['setor_id']
            );
        }
    }
}
