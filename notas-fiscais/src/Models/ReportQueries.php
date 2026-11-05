<?php
// @versao Rel_03 V_08_12/08/2026
declare(strict_types=1);

/**
 * Model do módulo Relatórios.
 *
 * 9 relatórios em 3 abas (Financeiro, Operacional e Meus processos).
 * Todas as queries excluem is_teste = 1 por padrão.
 *
 * Schema real (confirmado via DESCRIBE):
 *
 *   nf_parcelas         : id, processo_id, numero_parcela, total_parcelas,
 *                          vencimento (date), valor, forma_pagamento_id,
 *                          status ENUM: previsto|agendado|agvenc|vencido|pago|cancelado,
 *                          data_agendamento, data_pagamento, observacao, numero_comprovante,
 *                          is_teste, criado_em, atualizado_em, deletado_em
 *
 *   nf_processos         : id, codigo, template_versao_id, fornecedor_id, fornecedor_nome,
 *                          fornecedor_doc, numero_nf, valor_nf, data_nf, chave_nf,
 *                          filial_id, filial_doc, dados_formulario, etapa_atual,
 *                          status ENUM: aberto|aprovado|devolvido|cancelado|cancelamento_solicitado,
 *                          is_teste, estagio_teste, criado_em, criado_por, concluido_em, deletado_em
 *
 *   nf_processo_etapas   : id, processo_id, numero_etapa, nome_etapa,
 *                          tipo_aprovador ENUM: grupo|usuario, aprovador_id,
 *                          status ENUM: pendente|aprovado|devolvido|pulado,
 *                          atuado_por, atuado_em, motivo_devolucao
 *
 *   nf_processo_historico: id, processo_id, acao, numero_etapa, ator_id, ator_nome,
 *                          detalhe, criado_em
 */
class ReportQueries
{
    // ─────────────────────────────────────────
    // Utilitário: resolução de período
    // ─────────────────────────────────────────

    public static function resolvePeriodo(array $filtros): array
    {
        $preset = $filtros['periodo_preset'] ?? '';
        switch ($preset) {
            case 'esta-semana':
                return [date('Y-m-d', strtotime('monday this week')),
                        date('Y-m-d', strtotime('sunday this week'))];
            case 'este-mes':
                return [date('Y-m-01'), date('Y-m-t')];
            case 'este-trimestre':
                $mes = (int)date('n');
                $tri = (int)ceil($mes / 3);
                $mi  = ($tri - 1) * 3 + 1;
                $mf  = $tri * 3;
                return [
                    date('Y-' . str_pad((string)$mi, 2, '0', STR_PAD_LEFT) . '-01'),
                    date('Y-' . str_pad((string)$mf, 2, '0', STR_PAD_LEFT) . '-t'),
                ];
        }
        $ini = $filtros['periodo_inicio'] ?? null;
        $fim = $filtros['periodo_fim']    ?? null;
        return [$ini ?: date('Y-m-01'), $fim ?: date('Y-m-t')];
    }

    // ─────────────────────────────────────────
    // ABA FINANCEIRO — 1: Fluxo de caixa previsto
    // ─────────────────────────────────────────

    public static function fluxoCaixaKpis(array $filtros): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);

        $where  = ["p.deletado_em IS NULL", "p.is_teste = 0",
                   "p.status NOT IN ('cancelado','pago')",
                   "p.vencimento BETWEEN ? AND ?"];
        $params = [$ini, $fim];
        self::appendEscopo($where, $params);
        self::appendLojaSetor($where, $params, $filtros);

        $stmt = $pdo->prepare(
            "SELECT
               COUNT(*) AS qtd_parcelas,
               SUM(p.valor) AS total_previsto,
               SUM(CASE WHEN p.status IN ('agendado','agvenc') THEN p.valor ELSE 0 END) AS agendado,
               SUM(CASE WHEN p.status IN ('previsto','vencido') THEN p.valor ELSE 0 END) AS nao_agendado
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'total_previsto' => (float)($r['total_previsto'] ?? 0),
            'agendado'       => (float)($r['agendado']       ?? 0),
            'nao_agendado'   => (float)($r['nao_agendado']   ?? 0),
            'qtd_parcelas'   => (int)($r['qtd_parcelas']     ?? 0),
        ];
    }

    public static function fluxoCaixaTabela(array $filtros): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);

        $where  = ["p.deletado_em IS NULL", "p.is_teste = 0",
                   "p.status NOT IN ('cancelado','pago')",
                   "p.vencimento BETWEEN ? AND ?"];
        $params = [$ini, $fim];
        self::appendEscopo($where, $params);
        self::appendLojaSetor($where, $params, $filtros);

        $stmt = $pdo->prepare(
            "SELECT
               YEARWEEK(p.vencimento, 1) AS semana_key,
               MIN(p.vencimento) AS semana_ini,
               COUNT(*) AS parcelas,
               SUM(p.valor) AS valor_previsto,
               SUM(CASE WHEN p.status IN ('agendado','agvenc') THEN p.valor ELSE 0 END) AS valor_agendado,
               SUM(CASE WHEN p.status IN ('previsto','vencido') THEN p.valor ELSE 0 END) AS nao_agendado
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY semana_key
             ORDER BY semana_key"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────
    // ABA FINANCEIRO — 2: Pagamentos realizados
    // ─────────────────────────────────────────

    public static function pagamentosKpis(array $filtros): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        [$escopoSql, $escopoParams] = self::escopoSufixo();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS qtd,
               COALESCE(SUM(p.valor), 0) AS total,
               COALESCE(AVG(p.valor), 0) AS ticket_medio,
               COALESCE(MAX(p.valor), 0) AS maior
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             WHERE p.status = 'pago' AND p.data_pagamento BETWEEN ? AND ?
               AND p.deletado_em IS NULL AND p.is_teste = 0 {$escopoSql}"
        );
        $stmt->execute(array_merge([$ini, $fim], $escopoParams));
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'total'        => (float)($r['total']        ?? 0),
            'qtd'          => (int)($r['qtd']            ?? 0),
            'ticket_medio' => (float)($r['ticket_medio'] ?? 0),
            'maior'        => (float)($r['maior']        ?? 0),
        ];
    }

    public static function pagamentosTabela(array $filtros): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);

        $agruparPor = $filtros['agrupar_por'] ?? 'fornecedor';
        $groupField = match($agruparPor) {
            'loja'         => 'fi.nome',
            'departamento' => 's.nome',
            default        => 'f.nome_fantasia',
        };

        $busca  = !empty($filtros['busca']) ? '%' . $filtros['busca'] . '%' : null;
        $where  = ["p.status = 'pago'", "p.data_pagamento BETWEEN ? AND ?",
                   "p.deletado_em IS NULL", "p.is_teste = 0"];
        $params = [$ini, $fim];
        self::appendEscopo($where, $params);
        if ($busca) {
            $where[]  = "(f.nome_fantasia LIKE ? OR pr.codigo LIKE ?)";
            $params[] = $busca;
            $params[] = $busca;
        }

        $stmt = $pdo->prepare(
            "SELECT
               COALESCE({$groupField}, 'Sem informação') AS grupo,
               COUNT(*) AS pagamentos,
               SUM(p.valor) AS total_pago
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN fornecedores f ON f.id = pr.fornecedor_id
             LEFT JOIN filiais fi ON fi.id = pr.filial_id
             LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             LEFT JOIN setores s ON s.id = tv.setor_id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY grupo
             ORDER BY total_pago DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalGeral = array_sum(array_column($rows, 'total_pago'));
        foreach ($rows as &$r) {
            $r['pct'] = $totalGeral > 0 ? round((float)$r['total_pago'] / $totalGeral * 100, 1) : 0;
        }
        return $rows;
    }

    // ─────────────────────────────────────────
    // ABA FINANCEIRO — 3: Contas a pagar
    // ─────────────────────────────────────────

    public static function contasPagarKpis(array $filtros): array
    {
        $pdo  = Database::getInstance();
        $hoje = date('Y-m-d');

        $where  = ["p.deletado_em IS NULL", "p.is_teste = 0",
                   "p.status NOT IN ('pago','cancelado')"];
        $params = [];
        self::appendEscopo($where, $params);
        self::appendSituacaoFiltro($where, $params, $filtros['situacao'] ?? '', $hoje);
        self::appendLojaSetor($where, $params, $filtros);
        [$ini, $fim] = self::resolvePeriodo($filtros);
        $where[]  = 'p.vencimento BETWEEN ? AND ?';
        $params[] = $ini;
        $params[] = $fim;

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS qtd,
               SUM(p.valor) AS total,
               SUM(CASE WHEN p.vencimento < ? THEN p.valor ELSE 0 END) AS vencido,
               SUM(CASE WHEN p.vencimento >= ? THEN p.valor ELSE 0 END) AS a_vencer
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             WHERE " . implode(' AND ', $where)
        );
        $stmt->execute(array_merge([$hoje, $hoje], $params));
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'total'    => (float)($r['total']    ?? 0),
            'vencido'  => (float)($r['vencido']  ?? 0),
            'a_vencer' => (float)($r['a_vencer'] ?? 0),
            'qtd'      => (int)($r['qtd']        ?? 0),
        ];
    }

    public static function contasPagarTabela(array $filtros): array
    {
        $pdo  = Database::getInstance();
        $hoje = date('Y-m-d');

        $where  = ["p.deletado_em IS NULL", "p.is_teste = 0",
                   "p.status NOT IN ('pago','cancelado')"];
        $params = [];
        self::appendEscopo($where, $params);

        if (!empty($filtros['busca'])) {
            $like     = '%' . $filtros['busca'] . '%';
            $where[]  = "(pr.codigo LIKE ? OR f.nome_fantasia LIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }
        self::appendSituacaoFiltro($where, $params, $filtros['situacao'] ?? '', $hoje);
        self::appendLojaSetor($where, $params, $filtros);
        [$ini, $fim] = self::resolvePeriodo($filtros);
        $where[]  = 'p.vencimento BETWEEN ? AND ?';
        $params[] = $ini;
        $params[] = $fim;

        $stmt = $pdo->prepare(
            "SELECT
               p.id, p.vencimento, p.valor, p.status,
               pr.id AS processo_id, pr.codigo AS proc_codigo,
               f.nome_fantasia AS fornecedor,
               fi.nome AS loja,
               CASE WHEN p.vencimento < ? THEN 'vencido'
                    WHEN p.vencimento = ? THEN 'vence-hoje'
                    ELSE 'a-vencer' END AS situacao_calc,
               CASE WHEN p.vencimento < ?
                    THEN DATEDIFF(?, p.vencimento)
                    ELSE 0 END AS dias_atraso
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN fornecedores f ON f.id = pr.fornecedor_id
             LEFT JOIN filiais fi ON fi.id = pr.filial_id
             LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY p.vencimento ASC
             LIMIT 500"
        );
        $stmt->execute(array_merge([$hoje, $hoje, $hoje, $hoje], $params));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────
    // ABA FINANCEIRO — 4: Gastos por loja/departamento
    // ─────────────────────────────────────────

    public static function gastosPorGrupoTabela(array $filtros): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        [$escopoSql, $escopoParams] = self::escopoSufixo();

        $agruparPor = $filtros['agrupar_por'] ?? 'loja';
        $groupField = match($agruparPor) {
            'departamento' => 's.nome',
            default        => 'fi.nome',
        };

        $stmt = $pdo->prepare(
            "SELECT
               COALESCE({$groupField}, 'Sem informação') AS grupo,
               COUNT(DISTINCT pr.id) AS processos,
               SUM(CASE WHEN p.status = 'pago'
                         AND p.data_pagamento BETWEEN ? AND ? THEN p.valor ELSE 0 END) AS total_pago,
               SUM(CASE WHEN p.status NOT IN ('pago','cancelado') THEN p.valor ELSE 0 END) AS em_aberto,
               SUM(p.valor) AS total_movimentado
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN filiais fi ON fi.id = pr.filial_id
             LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             LEFT JOIN setores s ON s.id = tv.setor_id
             WHERE p.deletado_em IS NULL AND p.is_teste = 0 {$escopoSql}
             GROUP BY grupo
             ORDER BY total_movimentado DESC"
        );
        $stmt->execute(array_merge([$ini, $fim], $escopoParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalGeral = array_sum(array_column($rows, 'total_movimentado'));
        foreach ($rows as &$r) {
            $r['pct'] = $totalGeral > 0 ? round((float)$r['total_movimentado'] / $totalGeral * 100, 1) : 0;
        }
        return $rows;
    }

    // ─────────────────────────────────────────
    // ABA OPERACIONAL — 5: Volume de processos
    // ─────────────────────────────────────────

    public static function volumeProcessosKpis(array $filtros): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);

        $where  = ["pr.deletado_em IS NULL", "pr.is_teste = 0",
                   "pr.criado_em BETWEEN ? AND ?"];
        $params = [$ini . ' 00:00:00', $fim . ' 23:59:59'];
        self::appendEscopo($where, $params);

        // filial filter (tipo_nota_id não existe no schema)
        [$condFil, $pFil] = self::filtroFiliais('pr.filial_id', $filtros['filial_id'] ?? null);
        if ($condFil !== '') {
            $where[] = $condFil;
            $params  = array_merge($params, $pFil);
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total,
               SUM(CASE WHEN pr.status NOT IN ('aprovado','cancelado','encerrado_apollo') THEN 1 ELSE 0 END) AS em_andamento,
               SUM(CASE WHEN pr.status = 'aprovado' THEN 1 ELSE 0 END) AS concluidos,
               SUM(CASE WHEN pr.status = 'cancelado' THEN 1 ELSE 0 END) AS cancelados
             FROM nf_processos pr
             WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        $total      = (int)($r['total']      ?? 0);
        $concluidos = (int)($r['concluidos'] ?? 0);
        return [
            'total'         => $total,
            'em_andamento'  => (int)($r['em_andamento'] ?? 0),
            'concluidos'    => $concluidos,
            'cancelados'    => (int)($r['cancelados']   ?? 0),
            'pct_conclusao' => $total > 0 ? round($concluidos / $total * 100) : 0,
        ];
    }

    public static function volumeProcessosTabela(array $filtros): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        [$escopoSql, $escopoParams] = self::escopoSufixo();

        // Gera lista de meses no intervalo
        $meses = [];
        $dt    = new \DateTime(substr($ini, 0, 7) . '-01');
        $dtFim = new \DateTime(substr($fim, 0, 7) . '-01');
        while ($dt <= $dtFim) {
            $meses[] = $dt->format('Y-m');
            $dt->modify('+1 month');
        }

        $rows = [];
        foreach ($meses as $mes) {
            $mi = $mes . '-01 00:00:00';
            $mf = date('Y-m-t', strtotime($mes . '-01')) . ' 23:59:59';

            $stmt = $pdo->prepare(
                "SELECT
                   SUM(CASE WHEN pr.criado_em BETWEEN ? AND ?       THEN 1 ELSE 0 END) AS abertos,
                   SUM(CASE WHEN pr.status = 'aprovado'
                             AND pr.concluido_em BETWEEN ? AND ?    THEN 1 ELSE 0 END) AS concluidos,
                   SUM(CASE WHEN pr.status = 'cancelado'
                             AND pr.concluido_em BETWEEN ? AND ?    THEN 1 ELSE 0 END) AS cancelados,
                   SUM(CASE WHEN pr.status NOT IN ('aprovado','cancelado','encerrado_apollo') THEN 1 ELSE 0 END) AS em_andamento
                 FROM nf_processos pr
                 WHERE pr.deletado_em IS NULL AND pr.is_teste = 0 {$escopoSql}"
            );
            $stmt->execute(array_merge([$mi, $mf, $mi, $mf, $mi, $mf], $escopoParams));
            $r = $stmt->fetch(PDO::FETCH_ASSOC);

            $abertos    = (int)($r['abertos']    ?? 0);
            $concluidos = (int)($r['concluidos'] ?? 0);
            $rows[] = [
                'mes'            => $mes,
                'abertos'        => $abertos,
                'concluidos'     => $concluidos,
                'cancelados'     => (int)($r['cancelados']   ?? 0),
                'em_andamento'   => (int)($r['em_andamento'] ?? 0),
                'taxa_conclusao' => $abertos > 0 ? round($concluidos / $abertos * 100) : 0,
            ];
        }
        return $rows;
    }

    // ─────────────────────────────────────────
    // ABA OPERACIONAL — 6: SLA e tempo de ciclo
    // ─────────────────────────────────────────

    public static function slaKpis(array $filtros): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        [$escopoSql, $escopoParams] = self::escopoSufixo();

        $stmt = $pdo->prepare(
            "SELECT
               ROUND(AVG(DATEDIFF(pr.concluido_em, pr.criado_em)), 1) AS tempo_medio,
               COUNT(*) AS concluidos
             FROM nf_processos pr
             WHERE pr.status = 'aprovado'
               AND pr.concluido_em BETWEEN ? AND ?
               AND pr.deletado_em IS NULL AND pr.is_teste = 0 {$escopoSql}"
        );
        $stmt->execute(array_merge([$ini . ' 00:00:00', $fim . ' 23:59:59'], $escopoParams));
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        // Etapa mais lenta e mais rápida (por tempo médio de resolução)
        $stmt2 = $pdo->prepare(
            "SELECT pe.nome_etapa AS etapa,
               ROUND(AVG(DATEDIFF(pe.atuado_em, pr.criado_em)), 1) AS media
             FROM nf_processo_etapas pe
             JOIN nf_processos pr ON pr.id = pe.processo_id
             WHERE pe.status IN ('aprovado','devolvido')
               AND pe.atuado_em BETWEEN ? AND ?
               AND pr.is_teste = 0
             GROUP BY pe.nome_etapa
             ORDER BY media DESC"
        );
        $stmt2->execute([$ini . ' 00:00:00', $fim . ' 23:59:59']);
        $etapas = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        return [
            'tempo_medio'        => (float)($r['tempo_medio'] ?? 0),
            'concluidos'         => (int)($r['concluidos']    ?? 0),
            'etapa_mais_lenta'   => !empty($etapas) ? $etapas[0]['etapa'] : '—',
            'etapa_mais_rapida'  => count($etapas) > 1 ? end($etapas)['etapa'] : '—',
        ];
    }

    public static function slaTabela(array $filtros): array
    {
        $pdo = Database::getInstance();
        require_once __DIR__ . '/../Helpers/SlaCalculator.php';
        [$ini, $fim] = self::resolvePeriodo($filtros);
        [$escopoSql, $escopoParams] = self::escopoSufixo();

        $stmt = $pdo->prepare(
            "SELECT
               pe.nome_etapa AS etapa,
               pe.numero_etapa,
               tv.etapas AS etapas_json,
               ROUND(AVG(TIMESTAMPDIFF(HOUR,
                   COALESCE(
                       (SELECT pe2.atuado_em FROM nf_processo_etapas pe2
                        WHERE pe2.processo_id = pe.processo_id
                          AND pe2.numero_etapa = pe.numero_etapa - 1
                        LIMIT 1),
                       pr.criado_em
                   ),
                   pe.atuado_em
               )), 1) AS media_horas,
               ROUND(MIN(TIMESTAMPDIFF(HOUR,
                   COALESCE(
                       (SELECT pe2.atuado_em FROM nf_processo_etapas pe2
                        WHERE pe2.processo_id = pe.processo_id
                          AND pe2.numero_etapa = pe.numero_etapa - 1
                        LIMIT 1),
                       pr.criado_em
                   ),
                   pe.atuado_em
               )), 1) AS min_horas,
               ROUND(MAX(TIMESTAMPDIFF(HOUR,
                   COALESCE(
                       (SELECT pe2.atuado_em FROM nf_processo_etapas pe2
                        WHERE pe2.processo_id = pe.processo_id
                          AND pe2.numero_etapa = pe.numero_etapa - 1
                        LIMIT 1),
                       pr.criado_em
                   ),
                   pe.atuado_em
               )), 1) AS max_horas,
               COUNT(*) AS ocorrencias
             FROM nf_processo_etapas pe
             JOIN nf_processos pr ON pr.id = pe.processo_id
             JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             WHERE pe.status IN ('aprovado','devolvido')
               AND pe.atuado_em BETWEEN ? AND ?
               AND pr.is_teste = 0 AND pr.deletado_em IS NULL {$escopoSql}
             GROUP BY pe.nome_etapa, pe.numero_etapa, tv.etapas
             ORDER BY pe.numero_etapa ASC"
        );
        $stmt->execute(array_merge([$ini . ' 00:00:00', $fim . ' 23:59:59'], $escopoParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalHoras = array_sum(array_column($rows, 'media_horas'));
        foreach ($rows as &$r) {
            $mediaH = (float) $r['media_horas'];
            $r['media_dias'] = round($mediaH / 24, 1);
            $r['min_dias']   = round((float)$r['min_horas'] / 24, 1);
            $r['max_dias']   = round((float)$r['max_horas'] / 24, 1);

            $slaHoras = SlaCalculator::extrairSlaHoras($r['etapas_json'] ?? '[]', (int) $r['numero_etapa']);

            if ($slaHoras !== null) {
                $esperadoDias = round($slaHoras / 24, 1);
                $r['esperado_dias'] = $esperadoDias;
                $r['variacao_pct']  = $esperadoDias > 0
                    ? round(($r['media_dias'] - $esperadoDias) / $esperadoDias * 100, 1)
                    : 0;

                $pct = $slaHoras > 0 ? ($mediaH / $slaHoras) : 0;
                if ($pct > 1.0)       $r['situacao'] = 'vencido';
                elseif ($pct >= 0.80) $r['situacao'] = 'atencao';
                else                  $r['situacao'] = 'ok';
            } else {
                $r['esperado_dias'] = null;
                $r['variacao_pct']  = null;
                $r['situacao']      = 'sem-dados';
            }

            $r['pct_ciclo'] = $totalHoras > 0 ? round($mediaH / $totalHoras * 100) : 0;

            unset($r['etapas_json'], $r['media_horas'], $r['min_horas'], $r['max_horas'], $r['numero_etapa']);
        }
        return $rows;
    }

    // ─────────────────────────────────────────
    // ABA OPERACIONAL — 7: Devoluções e retrabalho
    // ─────────────────────────────────────────

    public static function devolucoesKpis(array $filtros): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        $ini_dt = $ini . ' 00:00:00';
        $fim_dt = $fim . ' 23:59:59';
        [$escopoSql, $escopoParams] = self::escopoSufixo();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total FROM nf_processo_historico ph
             JOIN nf_processos pr ON pr.id = ph.processo_id
             WHERE ph.acao = 'processo_devolvido'
               AND ph.criado_em BETWEEN ? AND ?
               AND pr.is_teste = 0 AND pr.deletado_em IS NULL {$escopoSql}"
        );
        $stmt->execute(array_merge([$ini_dt, $fim_dt], $escopoParams));
        $totalDev = (int)$stmt->fetchColumn();

        // Motivo mais frequente
        $stmt2 = $pdo->prepare(
            "SELECT COALESCE(NULLIF(TRIM(SUBSTRING_INDEX(ph.detalhe, 'Motivo: ', -1)),''), 'Não informado') AS motivo,
               COUNT(*) AS cnt
             FROM nf_processo_historico ph
             JOIN nf_processos pr ON pr.id = ph.processo_id
             WHERE ph.acao = 'processo_devolvido'
               AND ph.criado_em BETWEEN ? AND ?
               AND pr.is_teste = 0
             GROUP BY motivo ORDER BY cnt DESC LIMIT 1"
        );
        $stmt2->execute([$ini_dt, $fim_dt]);
        $motivoFreq = $stmt2->fetchColumn() ?: '—';

        // Maior devolvedor (quem mais gerou devoluções)
        $stmt3 = $pdo->prepare(
            "SELECT ph.ator_nome, COUNT(*) AS cnt
             FROM nf_processo_historico ph
             JOIN nf_processos pr ON pr.id = ph.processo_id
             WHERE ph.acao = 'processo_devolvido'
               AND ph.criado_em BETWEEN ? AND ?
               AND pr.is_teste = 0
             GROUP BY ph.ator_id, ph.ator_nome ORDER BY cnt DESC LIMIT 1"
        );
        $stmt3->execute([$ini_dt, $fim_dt]);
        $maiorDev = $stmt3->fetchColumn() ?: '—';

        // Taxa: devolvidos / total processos no período
        $stmtT = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_processos pr
             WHERE pr.deletado_em IS NULL AND pr.is_teste = 0
               AND pr.criado_em BETWEEN ? AND ? {$escopoSql}"
        );
        $stmtT->execute(array_merge([$ini_dt, $fim_dt], $escopoParams));
        $totalProc = (int)$stmtT->fetchColumn();

        return [
            'total'            => $totalDev,
            'taxa_devolucao'   => $totalProc > 0 ? round($totalDev / $totalProc * 100, 1) : 0,
            'motivo_freq'      => $motivoFreq,
            'maior_devolvedor' => $maiorDev,
        ];
    }

    public static function devolucoesTabela(array $filtros): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        [$escopoSql, $escopoParams] = self::escopoSufixo();

        $stmt = $pdo->prepare(
            "SELECT
               COALESCE(NULLIF(TRIM(SUBSTRING_INDEX(ph.detalhe, 'Motivo: ', -1)),''), 'Motivo não informado') AS motivo,
               COUNT(*) AS ocorrencias,
               NULL AS etapa_comum
             FROM nf_processo_historico ph
             JOIN nf_processos pr ON pr.id = ph.processo_id
             WHERE ph.acao = 'processo_devolvido'
               AND ph.criado_em BETWEEN ? AND ?
               AND pr.is_teste = 0 AND pr.deletado_em IS NULL {$escopoSql}
             GROUP BY motivo
             ORDER BY ocorrencias DESC
             LIMIT 50"
        );
        $stmt->execute(array_merge([$ini . ' 00:00:00', $fim . ' 23:59:59'], $escopoParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalOcorrencias = array_sum(array_column($rows, 'ocorrencias'));
        foreach ($rows as &$r) {
            $r['pct'] = $totalOcorrencias > 0
                ? round((int)$r['ocorrencias'] / $totalOcorrencias * 100, 1)
                : 0;
        }
        return $rows;
    }

    // ─────────────────────────────────────────
    // ABA OPERACIONAL — 8: Desempenho por usuário
    // ─────────────────────────────────────────

    public static function desempenhoUsuariosTabela(array $filtros): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        $ini_dt = $ini . ' 00:00:00';
        $fim_dt = $fim . ' 23:59:59';

        $where  = ["pr.deletado_em IS NULL", "pr.is_teste = 0",
                   "pr.criado_em BETWEEN ? AND ?"];
        $params = [$ini_dt, $fim_dt];
        self::appendEscopo($where, $params);

        if (!empty($filtros['busca'])) {
            $like     = '%' . $filtros['busca'] . '%';
            $where[]  = "u.nome LIKE ?";
            $params[] = $like;
        }

        $stmt = $pdo->prepare(
            "SELECT
               u.id, u.nome, u.perfil AS funcao,
               COUNT(DISTINCT pr.id) AS processos_abertos,
               SUM(CASE WHEN pr.status = 'aprovado'
                         AND pr.concluido_em BETWEEN ? AND ? THEN 1 ELSE 0 END) AS aprovados_concluidos,
               NULL AS media_resposta,
               SUM(CASE WHEN ph.acao = 'processo_devolvido'
                         AND ph.ator_id = u.id
                         AND ph.criado_em BETWEEN ? AND ? THEN 1 ELSE 0 END) AS devolucoes
             FROM usuarios u
             JOIN nf_processos pr ON pr.criado_por = u.id
             LEFT JOIN nf_processo_historico ph ON ph.processo_id = pr.id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY u.id, u.nome, u.perfil
             ORDER BY processos_abertos DESC
             LIMIT 50"
        );
        $stmt->execute(array_merge([$ini_dt, $fim_dt, $ini_dt, $fim_dt], $params));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $total = (int)$r['processos_abertos'];
            $dev   = (int)$r['devolucoes'];
            $r['taxa_devolucao'] = $total > 0 ? round($dev / $total * 100, 1) : 0;
            $r['critico']        = $r['taxa_devolucao'] > 20;
        }
        return $rows;
    }

    // ─────────────────────────────────────────
    // ABA OPERACIONAL — 5, 6 e 7: Processos por departamento / setor / fluxo
    // ─────────────────────────────────────────

    /**
     * Departamentos que o usuário logado pode consultar.
     *
     * Participação DIRETA  — o setor do próprio usuário (usuarios.setor_id).
     * Participação INDIRETA — grupo do qual é membro que aprova alguma etapa,
     *                         ou processo que ele mesmo abriu.
     *
     * Devolve `null` quando não há restrição (quem tem `processos.ver_todos`
     * enxerga todos os departamentos — é o caso de Controladoria e Diretoria,
     * que perderiam a visão consolidada sem essa saída).
     *
     * @return list<string>|null ids permitidos, ou null para "todos"
     */
    public static function departamentosPermitidos(string $usuarioId): ?array
    {
        if ($usuarioId === '') {
            return [];
        }
        if (class_exists('Permissions') && Permissions::can('processos', 'ver_todos')) {
            return null;
        }

        $stmt = Database::getInstance()->prepare(
            "SELECT DISTINCT s.departamento_id
               FROM setores s
              WHERE s.departamento_id IS NOT NULL
                AND s.id = (SELECT u.setor_id FROM usuarios u WHERE u.id = ?)
             UNION
             SELECT DISTINCT s2.departamento_id
               FROM nf_grupo_membros gm
               JOIN nf_processo_etapas pe ON pe.aprovador_id = gm.grupo_id
                                         AND pe.tipo_aprovador = 'grupo'
               JOIN nf_processos p        ON p.id  = pe.processo_id
               JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
               JOIN setores s2            ON s2.id = tv.setor_id
              WHERE gm.usuario_id = ? AND s2.departamento_id IS NOT NULL
             UNION
             SELECT DISTINCT s3.departamento_id
               FROM nf_processos p3
               JOIN nf_template_versoes tv3 ON tv3.id = p3.template_versao_id
               JOIN setores s3              ON s3.id = tv3.setor_id
              WHERE p3.criado_por = ? AND s3.departamento_id IS NOT NULL"
        );
        $stmt->execute([$usuarioId, $usuarioId, $usuarioId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * WHERE compartilhado dos três relatórios de listagem detalhada.
     *
     * Diferença central para a tela de Processos: aqui **nenhum status é
     * escondido**. A listagem esconde `aprovado` já pago, `cancelado` e
     * `encerrado_apollo` — por isso o usuário não consegue ver o histórico
     * completo de um departamento por lá. O relatório existe justamente para
     * isso, então o recorte é só período + dimensão + visibilidade.
     *
     * A restrição de departamento entra SEMPRE, mesmo quando o usuário não
     * escolheu nenhum: limitar apenas o <select> seria contornável trocando o
     * parâmetro na URL.
     *
     * @return array{0:list<string>,1:list<mixed>}
     */
    private static function processosDetalhadoWhere(array $filtros): array
    {
        [$ini, $fim] = self::resolvePeriodo($filtros);

        $where  = ['p.deletado_em IS NULL', 'p.is_teste = 0',
                   'DATE(p.criado_em) BETWEEN ? AND ?'];
        $params = [$ini, $fim];

        if (!empty($filtros['departamento_id'])) {
            $where[]  = 'tv.setor_id IN (SELECT sd.id FROM setores sd WHERE sd.departamento_id = ?)';
            $params[] = $filtros['departamento_id'];
        }
        if (!empty($filtros['setor_id'])) {
            $where[]  = 'tv.setor_id = ?';
            $params[] = $filtros['setor_id'];
        }
        if (!empty($filtros['template_id'])) {
            $where[]  = 'tv.template_id = ?';
            $params[] = $filtros['template_id'];
        }
        [$condFil, $pFil] = self::filtroFiliais('p.filial_id', $filtros['filial_id'] ?? null);
        if ($condFil !== '') {
            $where[] = $condFil;
            $params  = array_merge($params, $pFil);
        }
        if (!empty($filtros['busca'])) {
            $where[]  = '(p.codigo LIKE ? OR p.fornecedor_nome LIKE ? OR p.numero_nf LIKE ?)';
            $like     = '%' . $filtros['busca'] . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        // Teto de departamentos do usuário — null = sem restrição.
        $uid = (string) ($_SESSION['usuario_id'] ?? '');
        if ($uid !== '') {
            $permitidos = self::departamentosPermitidos($uid);
            if ($permitidos !== null) {
                if ($permitidos === []) {
                    $where[] = '1 = 0';   // nenhum departamento: nada a mostrar
                } else {
                    $ph       = implode(',', array_fill(0, count($permitidos), '?'));
                    $where[]  = "tv.setor_id IN (SELECT sp.id FROM setores sp WHERE sp.departamento_id IN ({$ph}))";
                    $params   = array_merge($params, $permitidos);
                }
            }
        }

        // Sigilo de template + escopo de filial + participação, como em todos
        // os demais relatórios.
        self::appendEscopo($where, $params, 'p');

        return [$where, $params];
    }

    /**
     * Uma linha por processo, em todos os status.
     *
     * @param int $limite teto de linhas (tela: 500; exportação: mais alto)
     */
    public static function processosDetalhadoTabela(array $filtros, int $limite = 500): array
    {
        [$where, $params] = self::processosDetalhadoWhere($filtros);
        $limite = max(1, min($limite, 20000));

        $sql = "
            SELECT
                p.id, p.codigo, p.fornecedor_nome, p.numero_nf, p.valor_nf,
                p.status, p.etapa_atual, p.criado_em, p.concluido_em,
                f.nome  AS filial_nome,
                s.nome  AS setor_nome,
                t.nome  AS template_nome,
                li.valor AS departamento_nome,
                (SELECT COUNT(*) FROM nf_processo_etapas pe_tot
                  WHERE pe_tot.processo_id = p.id) AS total_etapas,
                eta.nome_etapa AS etapa_nome,
                CASE eta.tipo_aprovador
                  WHEN 'usuario' THEN (SELECT ua.nome FROM usuarios ua WHERE ua.id = eta.aprovador_id LIMIT 1)
                  WHEN 'grupo'   THEN (SELECT g.nome  FROM nf_grupos g WHERE g.id  = eta.aprovador_id LIMIT 1)
                  ELSE NULL
                END AS responsavel_nome
            FROM nf_processos p
            LEFT JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
            LEFT JOIN nf_templates t         ON t.id  = tv.template_id
            LEFT JOIN setores s              ON s.id  = tv.setor_id
            LEFT JOIN nf_lista_itens li      ON li.id = s.departamento_id
            LEFT JOIN filiais f              ON f.id  = p.filial_id
            LEFT JOIN nf_processo_etapas eta ON eta.processo_id = p.id
                                            AND eta.numero_etapa = p.etapa_atual
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.criado_em DESC
            LIMIT {$limite}
        ";

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Totais do recorte — mesma contagem de status da tela de Processos. */
    public static function processosDetalhadoKpis(array $filtros): array
    {
        [$where, $params] = self::processosDetalhadoWhere($filtros);

        $stmt = Database::getInstance()->prepare(
            "SELECT
               COUNT(*) AS qtd,
               COALESCE(SUM(p.valor_nf), 0) AS valor_total,
               SUM(p.status IN ('aberto','devolvido','cancelamento_solicitado')) AS em_andamento,
               SUM(p.status = 'aprovado')                                        AS finalizados,
               SUM(p.status IN ('cancelado','encerrado_apollo'))                  AS encerrados
             FROM nf_processos p
             LEFT JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
             WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'qtd'          => (int)   ($r['qtd']          ?? 0),
            'valor_total'  => (float) ($r['valor_total']  ?? 0),
            'em_andamento' => (int)   ($r['em_andamento'] ?? 0),
            'finalizados'  => (int)   ($r['finalizados']  ?? 0),
            'encerrados'   => (int)   ($r['encerrados']   ?? 0),
        ];
    }

    // ─────────────────────────────────────────
    // Dados para dropdowns
    // ─────────────────────────────────────────

    /** Lojas oferecidas no filtro — só as que o usuário logado enxerga. */
    public static function filiais(): array
    {
        $uid = (string) ($_SESSION['usuario_id'] ?? '');
        if ($uid !== '' && class_exists('FilialScope')) {
            return FilialScope::filiaisVisiveis($uid);
        }

        return Database::getInstance()->query(
            "SELECT id, nome FROM filiais ORDER BY nome"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Departamentos que o usuário pode escolher — já recortado pela regra de
     * participação, para o <select> não oferecer o que a query vai negar.
     */
    public static function departamentos(): array
    {
        $uid        = (string) ($_SESSION['usuario_id'] ?? '');
        $permitidos = $uid !== '' ? self::departamentosPermitidos($uid) : [];

        if ($permitidos === []) {
            return [];
        }
        if ($permitidos === null) {
            return Database::getInstance()->query(
                "SELECT DISTINCT li.id, li.valor AS nome
                   FROM nf_lista_itens li
                   JOIN setores s ON s.departamento_id = li.id
                  ORDER BY li.valor"
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        $ph   = implode(',', array_fill(0, count($permitidos), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT DISTINCT li.id, li.valor AS nome
               FROM nf_lista_itens li
              WHERE li.id IN ({$ph})
              ORDER BY li.valor"
        );
        $stmt->execute($permitidos);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Fluxos (templates) que têm ao menos um processo. */
    public static function fluxos(): array
    {
        return Database::getInstance()->query(
            "SELECT DISTINCT t.id, t.nome
               FROM nf_templates t
               JOIN nf_template_versoes tv ON tv.template_id = t.id
               JOIN nf_processos p ON p.template_versao_id = tv.id AND p.deletado_em IS NULL
              ORDER BY t.nome"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function setores(): array
    {
        return Database::getInstance()->query(
            "SELECT id, nome FROM setores ORDER BY nome"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function tiposNota(): array
    {
        return [];
    }

    public static function grupos(): array
    {
        return Database::getInstance()->query(
            "SELECT id, nome FROM nf_grupos WHERE ativo = 1 ORDER BY nome"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────
    // ABA MEUS PROCESSOS — participação do usuário logado
    // ─────────────────────────────────────────

    /**
     * Participação DIRETA no processo: o usuário aparece nominalmente nele —
     * criou, é o responsável designado de alguma etapa, atuou (aprovou ou
     * devolveu) em alguma etapa, ou registrou alguma ação no histórico
     * (observação, anexo, solicitação de cancelamento, reatribuição).
     *
     * As duas últimas condições cobrem o que a tela de Processos não cobre:
     * quem atuou continua enxergando o processo mesmo depois de a etapa ser
     * reatribuída (Process::reatribuir sobrescreve aprovador_id) ou de ele
     * sair do grupo aprovador. 'processo_visualizado' fica de fora — abrir
     * um processo não é participar dele.
     *
     * Consome 4 placeholders, todos o usuario_id.
     */
    private static function sqlParticipaDireta(string $alias = 'p'): string
    {
        return "(
            {$alias}.criado_por = ?
            OR EXISTS (
                SELECT 1 FROM nf_processo_etapas pe_dir
                 WHERE pe_dir.processo_id = {$alias}.id
                   AND (
                       (pe_dir.tipo_aprovador = 'usuario' AND pe_dir.aprovador_id = ?)
                       OR pe_dir.atuado_por = ?
                   )
            )
            OR EXISTS (
                SELECT 1 FROM nf_processo_historico ph_dir
                 WHERE ph_dir.processo_id = {$alias}.id
                   AND ph_dir.ator_id = ?
                   AND ph_dir.acao <> 'processo_visualizado'
            )
        )";
    }

    /**
     * Participação VIA GRUPO: alguma etapa do processo tem como responsável um
     * grupo do qual o usuário é membro hoje — mesmo que a etapa tenha sido
     * aprovada por outro colega do time.
     *
     * Consome 1 placeholder (usuario_id).
     */
    private static function sqlParticipaGrupo(string $alias = 'p'): string
    {
        return "EXISTS (
            SELECT 1 FROM nf_processo_etapas pe_grp
              JOIN nf_grupo_membros gm_grp ON gm_grp.grupo_id = pe_grp.aprovador_id
             WHERE pe_grp.processo_id = {$alias}.id
               AND pe_grp.tipo_aprovador = 'grupo'
               AND gm_grp.usuario_id = ?
        )";
    }

    /**
     * WHERE compartilhado por meusProcessosKpis() e meusProcessosTabela() —
     * as duas TÊM que bater.
     *
     * Diferente dos outros relatórios, aqui o recorte por participação é o
     * próprio relatório: vale para todo mundo, inclusive admin e quem tem
     * `processos.ver_todos`. Cada usuário vê só os seus. Sigilo por template
     * e escopo por filial continuam por cima, iguais à tela de Processos.
     *
     * @return array{0:list<string>,1:list<mixed>} [where, params]
     */
    private static function meusProcessosWhere(array $filtros, string $usuarioId): array
    {
        $where  = ['p.deletado_em IS NULL', 'p.is_teste = 0'];
        $params = [];

        self::appendEscopoSigiloFilial($where, $params, 'p');

        [$condFil, $pFil] = self::filtroFiliais('p.filial_id', $filtros['filial_id'] ?? null);
        if ($condFil !== '') {
            $where[] = $condFil;
            $params  = array_merge($params, $pFil);
        }
        if (!empty($filtros['setor_id'])) {
            $where[]  = 'tv.setor_id = ?';
            $params[] = $filtros['setor_id'];
        }

        // Data de / até = data de ABERTURA do processo (coluna Abertura).
        [$ini, $fim] = self::resolvePeriodo($filtros);
        $where[]  = 'DATE(p.criado_em) BETWEEN ? AND ?';
        $params[] = $ini;
        $params[] = $fim;

        // Participação: pelo menos um dos dois tipos precisa estar marcado.
        $conds = [];
        if (!empty($filtros['part_direta'])) {
            $conds[] = self::sqlParticipaDireta('p');
            array_push($params, $usuarioId, $usuarioId, $usuarioId, $usuarioId);
        }
        if (!empty($filtros['part_grupo'])) {
            $conds[] = self::sqlParticipaGrupo('p');
            $params[] = $usuarioId;
        }
        $where[] = $conds ? '(' . implode(' OR ', $conds) . ')' : '1 = 0';

        return [$where, $params];
    }

    public static function meusProcessosKpis(array $filtros, string $usuarioId): array
    {
        [$where, $params] = self::meusProcessosWhere($filtros, $usuarioId);

        $stmt = Database::getInstance()->prepare(
            "SELECT
               COUNT(*) AS qtd,
               COALESCE(SUM(p.valor_nf), 0) AS valor_total,
               SUM(p.status IN ('aberto','devolvido','cancelamento_solicitado')) AS em_andamento,
               SUM(p.status = 'aprovado') AS finalizados,
               SUM(p.status IN ('cancelado','encerrado_apollo')) AS encerrados
             FROM nf_processos p
             LEFT JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
             WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'qtd'          => (int)   ($r['qtd']          ?? 0),
            'valor_total'  => (float) ($r['valor_total']  ?? 0),
            'em_andamento' => (int)   ($r['em_andamento'] ?? 0),
            'finalizados'  => (int)   ($r['finalizados']  ?? 0),
            'encerrados'   => (int)   ($r['encerrados']   ?? 0),
        ];
    }

    /**
     * Linhas do relatório — mesmas colunas da tela de Processos, mais duas
     * flags indicando COMO o usuário participa de cada processo.
     *
     * @param int $limite teto de linhas (tela: 500; exportação: mais alto)
     */
    public static function meusProcessosTabela(array $filtros, string $usuarioId, int $limite = 500): array
    {
        [$where, $params] = self::meusProcessosWhere($filtros, $usuarioId);

        // As flags do SELECT reusam as mesmas expressões do WHERE, então os
        // 5 placeholders delas vêm ANTES dos params do WHERE no execute().
        $selParams = array_fill(0, 5, $usuarioId);
        $limite    = max(1, min($limite, 20000));

        $sql = "
            SELECT
                p.id,
                p.codigo,
                p.fornecedor_nome,
                p.numero_nf,
                p.valor_nf,
                p.status,
                p.etapa_atual,
                p.criado_em,
                f.nome AS filial_nome,
                t.nome AS template_nome,
                s.nome AS setor_nome,
                (SELECT COUNT(*) FROM nf_processo_etapas pe_tot
                  WHERE pe_tot.processo_id = p.id) AS total_etapas,
                eta.nome_etapa      AS etapa_nome,
                eta.tipo_aprovador  AS tipo_aprovador,
                CASE eta.tipo_aprovador
                  WHEN 'usuario' THEN (SELECT ua.nome FROM usuarios ua WHERE ua.id = eta.aprovador_id LIMIT 1)
                  WHEN 'grupo'   THEN (SELECT g.nome  FROM nf_grupos g WHERE g.id  = eta.aprovador_id LIMIT 1)
                  ELSE NULL
                END AS responsavel_nome,
                " . self::sqlParticipaDireta('p') . " AS part_direta,
                " . self::sqlParticipaGrupo('p')  . " AS part_grupo
            FROM nf_processos p
            LEFT JOIN filiais f ON f.id = p.filial_id
            LEFT JOIN nf_processo_etapas eta ON eta.processo_id = p.id AND eta.numero_etapa = p.etapa_atual
            LEFT JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
            LEFT JOIN nf_templates t ON t.id = tv.template_id
            LEFT JOIN setores s ON s.id = tv.setor_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.criado_em DESC
            LIMIT {$limite}
        ";

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute(array_merge($selParams, $params));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────
    // Helpers internos
    // ─────────────────────────────────────────

    /**
     * Recorte de visibilidade do usuário logado aplicado aos relatórios:
     * processos de template confidencial (RH/Jurídico) e filiais fora do
     * escopo somem de TODAS as tabelas e KPIs. Fora do contexto web (CLI)
     * não muda nada.
     *
     * @param list<string> $where
     * @param list<mixed>  $params
     */
    private static function appendEscopo(array &$where, array &$params, string $alias = 'pr'): void
    {
        if (!self::escopoDisponivel()) {
            return;
        }

        self::appendEscopoSigiloFilial($where, $params, $alias);

        // Relatório também respeita o fluxo: sem ver_todos, o usuário só soma
        // o que ele mesmo participa.
        $participa = ProcessAccess::sqlParticipaSessao($alias);
        if ($participa !== '') {
            $where[] = $participa;
        }
    }

    /**
     * Sigilo por template + escopo por filial, sem o recorte de participação.
     * Usado por quem já aplica o próprio recorte por usuário (Meus processos).
     *
     * @param list<string> $where
     * @param list<mixed>  $params
     */
    private static function appendEscopoSigiloFilial(array &$where, array &$params, string $alias = 'pr'): void
    {
        if (!self::escopoDisponivel()) {
            return;
        }

        [$sigilo, $pSig] = TemplateSigilo::sqlExcluiProcessosSessao($alias);
        if ($sigilo !== '') {
            $where[] = $sigilo;
            $params  = array_merge($params, $pSig);
        }

        [$filial, $pFil] = FilialScope::sqlRestringeSessao("{$alias}.filial_doc");
        if ($filial !== '') {
            $where[] = $filial;
            $params  = array_merge($params, $pFil);
        }
    }

    /**
     * Contexto sem os helpers carregados (CLI enxuto: brobot, SLA): sem
     * recorte — esses jobs precisam varrer tudo e não têm sessão.
     */
    private static function escopoDisponivel(): bool
    {
        return class_exists('TemplateSigilo')
            && class_exists('FilialScope')
            && class_exists('ProcessAccess');
    }

    /**
     * Versão para queries de SQL cru: devolve o trecho " AND ..." pronto e os
     * params correspondentes (que devem ir no FIM da lista de execute()).
     *
     * @return array{0:string,1:list<mixed>}
     */
    private static function escopoSufixo(string $alias = 'pr'): array
    {
        $where = [];
        $params = [];
        self::appendEscopo($where, $params, $alias);
        return [$where ? ' AND ' . implode(' AND ', $where) : '', $params];
    }

    /**
     * Filtro de lojas escolhido na tela — um id (link antigo) ou vários, que é
     * o que a multi-seleção manda em `filial_id[]`. Devolve ['', []] quando
     * nada foi marcado ("todas as lojas que o usuário já enxerga").
     *
     * Não é recorte de segurança: quem limita é appendEscopo().
     *
     * @return array{0:string,1:list<string>}
     */
    private static function filtroFiliais(string $coluna, mixed $valor): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($v): string => trim((string) $v), (array) $valor),
            static fn(string $v): bool => $v !== ''
        )));
        if ($ids === []) {
            return ['', []];
        }
        return [$coluna . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    private static function appendLojaSetor(array &$where, array &$params, array $filtros): void
    {
        [$condFil, $pFil] = self::filtroFiliais('pr.filial_id', $filtros['filial_id'] ?? null);
        if ($condFil !== '') {
            $where[] = $condFil;
            $params  = array_merge($params, $pFil);
        }
        if (!empty($filtros['setor_id'])) {
            $where[]  = 'tv.setor_id = ?';
            $params[] = $filtros['setor_id'];
        }
    }

    private static function appendSituacaoFiltro(array &$where, array &$params, string $situacao, string $hoje): void
    {
        switch ($situacao) {
            case 'vencido':
                $where[]  = "p.vencimento < ?";
                $params[] = $hoje;
                break;
            case 'vence-hoje':
                $where[]  = "p.vencimento = ?";
                $params[] = $hoje;
                break;
            case 'a-vencer':
                $where[]  = "p.vencimento >= ?";
                $params[] = $hoje;
                break;
        }
    }
}
