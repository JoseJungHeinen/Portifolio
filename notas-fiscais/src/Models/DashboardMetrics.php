<?php
// @versao Dash_03 V_06_12/08/2026
declare(strict_types=1);

/**
 * Model do módulo Dashboard.
 *
 * Agrega métricas de processos, aprovações, financeiro e desempenho.
 * Todas as queries excluem registros is_teste = 1 por padrão.
 *
 * Schema real de referência:
 *   nf_processos  : id, codigo, template_versao_id, fornecedor_id, fornecedor_nome,
 *                   fornecedor_doc, numero_nf, valor_nf, data_nf, chave_nf, filial_id,
 *                   filial_doc, dados_formulario, etapa_atual, status(*), is_teste,
 *                   estagio_teste, criado_em, criado_por, concluido_em, deletado_em
 *                   status ENUM: aberto | aprovado | devolvido | cancelado | cancelamento_solicitado
 *
 *   nf_processo_etapas : id, processo_id, numero_etapa, nome_etapa,
 *                        tipo_aprovador ENUM(grupo|usuario), aprovador_id,
 *                        status(**) ENUM(pendente|aprovado|devolvido|pulado),
 *                        atuado_por, atuado_em, motivo_devolucao
 *
 *   nf_processo_historico : id, processo_id, acao, numero_etapa, ator_id, ator_nome,
 *                           detalhe, criado_em
 *
 *   nf_parcelas : id, processo_id, numero_parcela, total_parcelas, vencimento (date),
 *                 valor, forma_pagamento_id, status(***), data_agendamento,
 *                 data_pagamento, observacao, numero_comprovante, is_teste,
 *                 criado_em, atualizado_em, deletado_em
 *                 status ENUM: previsto | agendado | agvenc | vencido | pago | cancelado
 */
class DashboardMetrics
{
    // ─────────────────────────────────────────
    // Utilitário: resolução de período
    // ─────────────────────────────────────────

    /**
     * Recorte de visibilidade do usuário logado (sigilo por template e escopo
     * por filial) como trecho SQL autocontido — sem placeholders, então pode
     * ser concatenado em qualquer query sem mexer nos params do execute().
     * Fora do contexto web (CLI) devolve string vazia.
     */
    private static function escopoSql(string $alias = 'pr'): string
    {
        // Contexto sem os helpers carregados (CLI enxuto: brobot, SLA): sem
        // recorte — esses jobs precisam varrer tudo e não têm sessão.
        if (!class_exists('TemplateSigilo') || !class_exists('FilialScope') || !class_exists('ProcessAccess')) {
            return '';
        }

        [$sigilo]  = TemplateSigilo::sqlExcluiProcessosSessao($alias);
        [$filial]  = FilialScope::sqlRestringeSessao("{$alias}.filial_doc");

        $participa = ProcessAccess::sqlParticipaSessao($alias);

        $partes = array_filter([$sigilo, $filial, $participa], static fn($c) => $c !== '');
        return $partes ? ' AND ' . implode(' AND ', $partes) : '';
    }

    /**
     * Filtro de lojas escolhido na tela — um id (link antigo) ou vários, que é
     * o que a multi-seleção manda em `filial_id[]`. Devolve ['', []] quando
     * nada foi marcado ("todas as lojas que o usuário já enxerga").
     *
     * Não é recorte de segurança: quem limita é escopoSql().
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

    public static function resolvePeriodo(array $filtros): array
    {
        $preset = $filtros['periodo_preset'] ?? '';

        switch ($preset) {
            case 'esta-semana':
                return [
                    date('Y-m-d', strtotime('monday this week')),
                    date('Y-m-d', strtotime('sunday this week')),
                ];
            case 'este-mes':
                return [date('Y-m-01'), date('Y-m-t')];
            case 'este-trimestre':
                $ano      = (int)date('Y');
                $mes      = (int)date('n');
                $trimestre = (int)ceil($mes / 3);
                $mesIni   = ($trimestre - 1) * 3 + 1;
                $mesFim   = $trimestre * 3;
                return [
                    sprintf('%04d-%02d-01', $ano, $mesIni),
                    date('Y-m-t', mktime(0, 0, 0, $mesFim, 1, $ano)),
                ];
        }

        $inicio = $filtros['periodo_inicio'] ?? null;
        $fim    = $filtros['periodo_fim']    ?? null;
        return [$inicio ?: null, $fim ?: null];
    }

    /**
     * Período anterior equivalente (mesma duração, imediatamente antes) — usado
     * pra dar contexto de comparação a qualquer métrica "no período".
     * Sem período resolvido (all-time), cai no mês passado como referência.
     */
    public static function resolvePeriodoAnterior(array $filtros): array
    {
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if (!$ini || !$fim) {
            return [
                date('Y-m-01', strtotime('first day of last month')),
                date('Y-m-t',  strtotime('last day of last month')),
            ];
        }
        $dias         = (int) ((strtotime($fim) - strtotime($ini)) / 86400) + 1;
        $fimAnterior  = date('Y-m-d', strtotime($ini . ' -1 day'));
        $iniAnterior  = date('Y-m-d', strtotime($fimAnterior . ' -' . ($dias - 1) . ' days'));
        return [$iniAnterior, $fimAnterior];
    }

    // ─────────────────────────────────────────
    // Aba: Visão Geral — KPIs
    // ─────────────────────────────────────────

    public static function kpisVisaoGeral(array $filtros = []): array
    {
        $pdo  = Database::getInstance();
        [$iniMes, $fimMes] = [date('Y-m-01'), date('Y-m-t')];

        // Contagens de processos
        $stmt = $pdo->prepare(
            "SELECT
               SUM(CASE WHEN pr.status NOT IN ('aprovado','cancelado','encerrado_apollo') THEN 1 ELSE 0 END) AS abertos,
               SUM(CASE WHEN pr.status = 'aprovado'
                         AND pr.concluido_em BETWEEN ? AND ?           THEN 1 ELSE 0 END) AS concluidos_mes,
               SUM(CASE WHEN pr.status NOT IN ('aprovado','cancelado','encerrado_apollo') THEN pr.valor_nf ELSE 0 END) AS volume_aberto
             FROM nf_processos pr
             WHERE pr.deletado_em IS NULL AND pr.is_teste = 0" . self::escopoSql() . ""
        );
        $stmt->execute([$iniMes . ' 00:00:00', $fimMes . ' 23:59:59']);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        // Mesmo recorte de "abertos", mas só dentro do período selecionado no filtro —
        // dá contexto ao total (que é sempre histórico completo, sem filtro de data)
        [$iniP, $fimP] = self::resolvePeriodo($filtros);
        $whereP  = ["pr.deletado_em IS NULL", self::escopoSql() ? substr(self::escopoSql(), 5) : "1=1", "pr.is_teste = 0", "pr.status NOT IN ('aprovado','cancelado','encerrado_apollo')"];
        $paramsP = [];
        if ($iniP) { $whereP[] = 'pr.criado_em >= ?'; $paramsP[] = $iniP . ' 00:00:00'; }
        if ($fimP) { $whereP[] = 'pr.criado_em <= ?'; $paramsP[] = $fimP . ' 23:59:59'; }
        $stmtP = $pdo->prepare(
            "SELECT COUNT(*) AS qtd, COALESCE(SUM(pr.valor_nf),0) AS volume
             FROM nf_processos pr
             WHERE " . implode(' AND ', $whereP)
        );
        $stmtP->execute($paramsP);
        $rP = $stmtP->fetch(PDO::FETCH_ASSOC);

        // Aprovações pendentes e devolvidos
        // pe.numero_etapa = pr.etapa_atual é essencial aqui — sem isso conta também
        // etapas futuras ainda não alcançadas (nascem como 'pendente' desde a criação)
        $stmt2 = $pdo->prepare(
            "SELECT
               SUM(CASE WHEN pe.status = 'pendente' THEN 1 ELSE 0 END) AS aguardando_aprovacao,
               SUM(CASE WHEN pr.status = 'devolvido' THEN 1 ELSE 0 END) AS devolvidos
             FROM nf_processos pr
             LEFT JOIN nf_processo_etapas pe
                    ON pe.processo_id = pr.id AND pe.numero_etapa = pr.etapa_atual
             WHERE pr.deletado_em IS NULL AND pr.is_teste = 0" . self::escopoSql() . "
               AND pr.status NOT IN ('aprovado','cancelado','encerrado_apollo')"
        );
        $stmt2->execute();
        $r2 = $stmt2->fetch(PDO::FETCH_ASSOC);

        // Financeiro
        $stmt3 = $pdo->prepare(
            "SELECT
               SUM(CASE WHEN p.status IN ('previsto','agendado','agvenc','vencido') THEN 1 ELSE 0 END) AS aguardando_pgto,
               SUM(CASE WHEN p.status = 'vencido' THEN 1 ELSE 0 END) AS aguardando_pgto_vencido,
               SUM(CASE WHEN p.status = 'pago'
                         AND p.data_pagamento BETWEEN ? AND ?           THEN p.valor ELSE 0 END) AS pago_mes
             FROM nf_parcelas p
             WHERE p.deletado_em IS NULL AND p.is_teste = 0"
        );
        $stmt3->execute([$iniMes, $fimMes]);
        $r3 = $stmt3->fetch(PDO::FETCH_ASSOC);

        // Comparação com o mês anterior — dá contexto a "Concluídos no mês" e "Pago no mês"
        $iniMesAnt = date('Y-m-01', strtotime('first day of last month'));
        $fimMesAnt = date('Y-m-t',  strtotime('last day of last month'));
        $stmt4 = $pdo->prepare(
            "SELECT
               (SELECT COUNT(*) FROM nf_processos
                 WHERE deletado_em IS NULL AND is_teste = 0 AND status = 'aprovado'
                   AND concluido_em BETWEEN ? AND ?) AS concluidos_mes_anterior,
               (SELECT COALESCE(SUM(valor),0) FROM nf_parcelas
                 WHERE deletado_em IS NULL AND is_teste = 0 AND status = 'pago'
                   AND data_pagamento BETWEEN ? AND ?) AS pago_mes_anterior"
        );
        $stmt4->execute([
            $iniMesAnt . ' 00:00:00', $fimMesAnt . ' 23:59:59',
            $iniMesAnt, $fimMesAnt,
        ]);
        $r4 = $stmt4->fetch(PDO::FETCH_ASSOC);

        return [
            'abertos'                => (int)($r['abertos']              ?? 0),
            'abertos_periodo'        => (int)($rP['qtd']                 ?? 0),
            'atrasados'              => self::contarSlaVencidos(),
            'concluidos_mes'         => (int)($r['concluidos_mes']       ?? 0),
            'concluidos_mes_anterior'=> (int)($r4['concluidos_mes_anterior'] ?? 0),
            'volume_aberto'          => (float)($r['volume_aberto']      ?? 0),
            'volume_aberto_periodo'  => (float)($rP['volume']            ?? 0),
            'aguardando_aprovacao'   => (int)($r2['aguardando_aprovacao'] ?? 0),
            'devolvidos'             => (int)($r2['devolvidos']          ?? 0),
            'aguardando_pgto'        => (int)($r3['aguardando_pgto']     ?? 0),
            'aguardando_pgto_vencido'=> (int)($r3['aguardando_pgto_vencido'] ?? 0),
            'pago_mes'               => (float)($r3['pago_mes']          ?? 0),
            'pago_mes_anterior'      => (float)($r4['pago_mes_anterior'] ?? 0),
        ];
    }

    /**
     * Conta processos abertos com SLA vencido (>100% do sla_horas da etapa atual).
     */
    public static function contarSlaVencidos(): int
    {
        $pdo = Database::getInstance();
        require_once __DIR__ . '/../Helpers/SlaCalculator.php';

        $stmt = $pdo->prepare(
            "SELECT
               pr.id, pr.etapa_atual, pr.criado_em,
               tv.etapas AS etapas_json,
               COALESCE(
                   (SELECT pe2.atuado_em
                    FROM nf_processo_etapas pe2
                    WHERE pe2.processo_id = pr.id
                      AND pe2.numero_etapa = pr.etapa_atual - 1
                    LIMIT 1),
                   pr.criado_em
               ) AS entrada_etapa
             FROM nf_processos pr
             JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             JOIN nf_processo_etapas pe
                  ON pe.processo_id = pr.id
                  AND pe.numero_etapa = pr.etapa_atual
                  AND pe.status = 'pendente'
             WHERE pr.deletado_em IS NULL
               AND pr.is_teste = 0
               AND pr.status NOT IN ('aprovado','cancelado','encerrado_apollo')"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $count = 0;
        foreach ($rows as $row) {
            $slaHoras = SlaCalculator::extrairSlaHoras($row['etapas_json'], (int) $row['etapa_atual']);
            if ($slaHoras === null) continue;
            $info = SlaCalculator::classificar($row['entrada_etapa'], $slaHoras);
            if ($info['situacao'] === SlaCalculator::VENCIDO) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Lista processos em andamento para a tabela da Visão Geral.
     */
    /**
     * Fragmento WHERE compartilhado entre processosEmAndamento() e
     * contarProcessosEmAndamento() — mesmo motivo do sqlFiltrosMeusProcessos():
     * lista e contagem precisam enxergar exatamente o mesmo recorte pra
     * paginação não ficar inconsistente. Preenche $params por referência.
     */
    private static function sqlFiltrosProcessosEmAndamento(array $filtros, array &$params): string
    {
        $where = [
            "pr.deletado_em IS NULL", self::escopoSql() ? substr(self::escopoSql(), 5) : "1=1",
            "pr.is_teste = 0",
            "pr.status NOT IN ('aprovado','cancelado','encerrado_apollo')",
        ];

        if (!empty($filtros['busca'])) {
            $like     = '%' . $filtros['busca'] . '%';
            $where[]  = "(pr.codigo LIKE ? OR pr.fornecedor_nome LIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($filtros['status'])) {
            $where[]  = 'pr.status = ?';
            $params[] = $filtros['status'];
        }
        [$condFil, $pFil] = self::filtroFiliais('pr.filial_id', $filtros['filial_id'] ?? null);
        if ($condFil !== '') {
            $where[] = $condFil;
            $params  = array_merge($params, $pFil);
        }
        if (!empty($filtros['tipo_nota_id'])) {
            $where[]  = 'tv.tipo_nota_id = ?';
            $params[] = $filtros['tipo_nota_id'];
        }
        if (!empty($filtros['responsavel_id'])) {
            $where[]  = 'pr.criado_por = ?';
            $params[] = $filtros['responsavel_id'];
        }
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if ($ini) { $where[] = 'pr.criado_em >= ?'; $params[] = $ini . ' 00:00:00'; }
        if ($fim) { $where[] = 'pr.criado_em <= ?'; $params[] = $fim . ' 23:59:59'; }

        return implode(' AND ', $where);
    }

    /**
     * Total de processos em andamento com os mesmos filtros de
     * processosEmAndamento() — usado pra calcular a paginação.
     */
    public static function contarProcessosEmAndamento(array $filtros = []): int
    {
        $pdo    = Database::getInstance();
        $params = [];
        $whereSql = self::sqlFiltrosProcessosEmAndamento($filtros, $params);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM nf_processos pr
             JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             JOIN nf_processo_etapas pe_atual
                  ON pe_atual.processo_id = pr.id
                  AND pe_atual.numero_etapa = pr.etapa_atual
                  AND pe_atual.status = 'pendente'
             WHERE {$whereSql}"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function processosEmAndamento(array $filtros = [], int $offset = 0, int $limit = 100): array
    {
        $pdo    = Database::getInstance();
        require_once __DIR__ . '/../Helpers/SlaCalculator.php';

        $params   = [];
        $whereSql = self::sqlFiltrosProcessosEmAndamento($filtros, $params);

        $orderMap = [
            'mais-recentes' => 'pr.criado_em DESC',
            'mais-antigos'  => 'pr.criado_em ASC',
            'maior-valor'   => 'pr.valor_nf DESC',
        ];
        $ordem    = $filtros['ordem'] ?? 'mais-recentes';
        $orderSql = $orderMap[$ordem] ?? 'pr.criado_em DESC';

        $stmt = $pdo->prepare(
            "SELECT
               pr.id, pr.codigo, pr.status, pr.valor_nf AS valor_total, pr.criado_em,
               pr.fornecedor_nome AS fornecedor, pr.etapa_atual AS etapa_num,
               u.nome AS responsavel_nome,
               pe_atual.nome_etapa AS etapa_atual,
               tv.etapas AS etapas_json,
               " . self::sqlEntradaEtapaAtual('pr') . " AS entrada_etapa
             FROM nf_processos pr
             LEFT JOIN usuarios u ON u.id = pr.criado_por
             JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             JOIN nf_processo_etapas pe_atual
                  ON pe_atual.processo_id = pr.id
                  AND pe_atual.numero_etapa = pr.etapa_atual
                  AND pe_atual.status = 'pendente'
             WHERE {$whereSql}
             ORDER BY {$orderSql}
             LIMIT ? OFFSET ?"
        );
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $slaHoras = SlaCalculator::extrairSlaHoras($row['etapas_json'], (int) $row['etapa_num']);
            $sla = SlaCalculator::classificar($row['entrada_etapa'], $slaHoras);

            // Mesmo critério de "atrasado" usado em processosAtrasados() — vencido
            // pelo SLA da etapa, ou 3+ dias parado quando a etapa não tem SLA definido.
            $row['dias_atraso'] = ($sla['situacao'] === SlaCalculator::VENCIDO
                || ($sla['situacao'] === SlaCalculator::SEM_SLA && $sla['dias_parado'] >= 3))
                ? $sla['dias_parado'] : 0;
            $row['sla'] = $sla;
            unset($row['etapas_json']);
        }
        unset($row);

        return $rows;
    }

    // ─────────────────────────────────────────
    // Aba: Processos
    // ─────────────────────────────────────────

    public static function contagensPorEtapa(array $filtros = []): array
    {
        $pdo  = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);

        $where  = [
            "pr.deletado_em IS NULL", self::escopoSql() ? substr(self::escopoSql(), 5) : "1=1",
            "pr.is_teste = 0",
            "pr.status NOT IN ('aprovado','cancelado','encerrado_apollo')",
        ];
        $params = [];

        if ($ini) { $where[] = 'pr.criado_em >= ?'; $params[] = $ini . ' 00:00:00'; }
        if ($fim) { $where[] = 'pr.criado_em <= ?'; $params[] = $fim . ' 23:59:59'; }
        if (!empty($filtros['busca'])) {
            $like     = '%' . $filtros['busca'] . '%';
            $where[]  = "(pr.codigo LIKE ? OR pr.fornecedor_nome LIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($filtros['responsavel_id'])) {
            $where[]  = 'pr.criado_por = ?';
            $params[] = $filtros['responsavel_id'];
        }

        $stmt = $pdo->prepare(
            "SELECT
               COUNT(*) AS total,
               SUM(CASE WHEN pr.status = 'aberto'                  THEN 1 ELSE 0 END) AS qtd_aberto,
               SUM(CASE WHEN pr.status = 'devolvido'               THEN 1 ELSE 0 END) AS qtd_devolvido,
               SUM(CASE WHEN pr.status = 'cancelamento_solicitado' THEN 1 ELSE 0 END) AS qtd_cancel_solic
             FROM nf_processos pr
             WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = (int)($r['total'] ?? 0);

        // Concluídos no período (por concluido_em, não criado_em — pode ter sido
        // aberto num período anterior e concluído neste)
        $whereConc  = ["pr.deletado_em IS NULL", self::escopoSql() ? substr(self::escopoSql(), 5) : "1=1", "pr.is_teste = 0", "pr.status = 'aprovado'"];
        $paramsConc = [];
        if ($ini) { $whereConc[] = 'pr.concluido_em >= ?'; $paramsConc[] = $ini . ' 00:00:00'; }
        if ($fim) { $whereConc[] = 'pr.concluido_em <= ?'; $paramsConc[] = $fim . ' 23:59:59'; }
        if (!empty($filtros['busca'])) {
            $like        = '%' . $filtros['busca'] . '%';
            $whereConc[] = "(pr.codigo LIKE ? OR pr.fornecedor_nome LIKE ?)";
            $paramsConc[] = $like;
            $paramsConc[] = $like;
        }
        if (!empty($filtros['responsavel_id'])) {
            $whereConc[]  = 'pr.criado_por = ?';
            $paramsConc[] = $filtros['responsavel_id'];
        }
        $stmt2 = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_processos pr WHERE " . implode(' AND ', $whereConc)
        );
        $stmt2->execute($paramsConc);
        $concluidos = (int)$stmt2->fetchColumn();

        // Cancelados no período (mesmo critério de data que "total" — por criado_em)
        $whereCanc  = ["pr.deletado_em IS NULL", self::escopoSql() ? substr(self::escopoSql(), 5) : "1=1", "pr.is_teste = 0", "pr.status = 'cancelado'"];
        $paramsCanc = [];
        if ($ini) { $whereCanc[] = 'pr.criado_em >= ?'; $paramsCanc[] = $ini . ' 00:00:00'; }
        if ($fim) { $whereCanc[] = 'pr.criado_em <= ?'; $paramsCanc[] = $fim . ' 23:59:59'; }
        if (!empty($filtros['busca'])) {
            $like        = '%' . $filtros['busca'] . '%';
            $whereCanc[] = "(pr.codigo LIKE ? OR pr.fornecedor_nome LIKE ?)";
            $paramsCanc[] = $like;
            $paramsCanc[] = $like;
        }
        if (!empty($filtros['responsavel_id'])) {
            $whereCanc[]  = 'pr.criado_por = ?';
            $paramsCanc[] = $filtros['responsavel_id'];
        }
        $stmt2b = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_processos pr WHERE " . implode(' AND ', $whereCanc)
        );
        $stmt2b->execute($paramsCanc);
        $cancelados = (int)$stmt2b->fetchColumn();

        return [
            'total'        => $total,
            'concluidos'   => $concluidos,
            // Funil pelos status reais de nf_processos.status — os mesmos exibidos
            // nos badges da tabela, nada de categoria inventada
            'aberto'       => (int)($r['qtd_aberto']        ?? 0),
            'devolvido'    => (int)($r['qtd_devolvido']      ?? 0),
            'cancel_solic' => (int)($r['qtd_cancel_solic']   ?? 0),
            'cancelados'   => $cancelados,
        ];
    }

    /**
     * CTE compartilhada entre processosAtrasados()/Parados() e seus contadores.
     *
     * Antes, essa classificação (vencido/atenção/sem-sla) só existia depois de
     * buscar TODOS os processos em andamento e rodar SlaCalculator linha a
     * linha em PHP — inviável no volume de 5-10 mil notas/mês (dobrava o
     * trabalho, já que list() e contar() repetiam a busca completa). Agora o
     * SLA é calculado dentro do próprio SQL (JSON_EXTRACT pra pegar sla_horas
     * da etapa, TIMESTAMPDIFF pra horas paradas), então o banco filtra, ordena
     * e pagina como qualquer outra query — só a formatação final do badge
     * (SlaCalculator::classificar) roda em PHP, e só para as linhas da página
     * atual, nunca para o conjunto inteiro. Validado com dados reais: a
     * classificação em SQL bate 1:1 com SlaCalculator::classificar() em PHP.
     *
     * $params é preenchido por referência, na ordem dos `?` do SQL retornado.
     */
    private static function sqlBaseProcessosComSla(array $filtros, array &$params): string
    {
        $where  = [
            "pr.deletado_em IS NULL", self::escopoSql() ? substr(self::escopoSql(), 5) : "1=1",
            "pr.is_teste = 0",
            "pr.status NOT IN ('aprovado','cancelado','encerrado_apollo')",
            "pe.status = 'pendente'",
        ];

        if (!empty($filtros['busca'])) {
            $like     = '%' . $filtros['busca'] . '%';
            $where[]  = "(pr.codigo LIKE ? OR pr.fornecedor_nome LIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($filtros['responsavel_id'])) {
            $where[]  = 'pr.criado_por = ?';
            $params[] = $filtros['responsavel_id'];
        }
        $whereSql = implode(' AND ', $where);

        // sla_horas > 0 vira pct = horas_parado/sla_horas; sla_horas = 0 é
        // tratado como "sempre vencido" (pct=999), mesma regra do PHP original.
        return "
            WITH base AS (
                SELECT
                    pr.id, pr.codigo, pr.valor_nf AS valor_total, pr.etapa_atual AS etapa_num,
                    pr.fornecedor_nome AS fornecedor, pr.criado_em,
                    u.nome AS responsavel_nome,
                    pe.nome_etapa AS etapa_atual,
                    " . self::sqlEntradaEtapaAtual('pr') . " AS entrada_etapa,
                    (SELECT MAX(ph.criado_em) FROM nf_processo_historico ph
                     WHERE ph.processo_id = pr.id) AS ultima_movimentacao,
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(tv.etapas, CONCAT('\$[', pr.etapa_atual - 1, '].sla_horas'))) AS DECIMAL(10,2)) AS sla_horas
                FROM nf_processos pr
                LEFT JOIN usuarios u ON u.id = pr.criado_por
                JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
                JOIN nf_processo_etapas pe
                     ON pe.processo_id = pr.id
                     AND pe.numero_etapa = pr.etapa_atual
                     AND pe.status = 'pendente'
                WHERE {$whereSql}
            ),
            calc AS (
                SELECT *,
                    TIMESTAMPDIFF(SECOND, entrada_etapa, NOW()) / 3600 AS horas_parado
                FROM base
            ),
            classificado AS (
                SELECT *,
                    FLOOR(horas_parado / 24) AS dias_parado,
                    CASE
                        WHEN sla_horas IS NULL THEN 'sem-sla'
                        WHEN (CASE WHEN sla_horas > 0 THEN horas_parado / sla_horas ELSE 999 END) > 1.0 THEN 'vencido'
                        WHEN (CASE WHEN sla_horas > 0 THEN horas_parado / sla_horas ELSE 999 END) >= 0.80 THEN 'atencao'
                        ELSE 'ok'
                    END AS sla_situacao
                FROM calc
            )
            SELECT * FROM classificado
        ";
    }

    /**
     * Monta o array 'sla' (classe/label/etc, usado por diasAtrasoBadge()) a
     * partir das colunas já classificadas em SQL — só roda pras linhas da
     * página atual, nunca pro conjunto inteiro de candidatos.
     */
    private static function montarSlaDaLinha(array $row): array
    {
        $slaHoras = $row['sla_horas'] !== null ? (float) $row['sla_horas'] : null;
        return SlaCalculator::classificar($row['entrada_etapa'], $slaHoras);
    }

    /**
     * Processos com SLA vencido ou em atenção (baseado em sla_horas da etapa).
     * Fallback: processos há 3+ dias sem SLA configurado.
     */
    public static function processosAtrasados(array $filtros = [], int $offset = 0, int $limit = 20): array
    {
        $pdo = Database::getInstance();
        require_once __DIR__ . '/../Helpers/SlaCalculator.php';

        $params = [];
        $cte = self::sqlBaseProcessosComSla($filtros, $params);
        $stmt = $pdo->prepare(
            "{$cte}
             WHERE sla_situacao = 'vencido' OR (sla_situacao = 'sem-sla' AND dias_parado >= 3)
             ORDER BY horas_parado DESC
             LIMIT ? OFFSET ?"
        );
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['dias_atraso'] = (int) $row['dias_parado'];
            $row['sla']         = self::montarSlaDaLinha($row);
        }
        unset($row);

        return $rows;
    }

    public static function contarProcessosAtrasados(array $filtros = []): int
    {
        $pdo = Database::getInstance();
        $params = [];
        $cte = self::sqlBaseProcessosComSla($filtros, $params);
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM ({$cte}
                WHERE sla_situacao = 'vencido' OR (sla_situacao = 'sem-sla' AND dias_parado >= 3)
             ) t"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Processos em atenção de SLA (80-100%) ou parados 5+ dias sem SLA.
     * Risco preventivo — ainda não venceram, mas estão próximos.
     */
    public static function processosParados(array $filtros = [], int $offset = 0, int $limit = 20): array
    {
        $pdo = Database::getInstance();
        require_once __DIR__ . '/../Helpers/SlaCalculator.php';

        $params = [];
        $cte = self::sqlBaseProcessosComSla($filtros, $params);
        $stmt = $pdo->prepare(
            "{$cte}
             WHERE sla_situacao = 'atencao' OR (sla_situacao = 'sem-sla' AND dias_parado >= 5)
             ORDER BY horas_parado DESC
             LIMIT ? OFFSET ?"
        );
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            if (empty($row['ultima_movimentacao'])) {
                $row['ultima_movimentacao'] = $row['criado_em'];
            }
            $row['dias_parado'] = (int) $row['dias_parado'];
            $row['sla']         = self::montarSlaDaLinha($row);
        }
        unset($row);

        return $rows;
    }

    public static function contarProcessosParados(array $filtros = []): int
    {
        $pdo = Database::getInstance();
        $params = [];
        $cte = self::sqlBaseProcessosComSla($filtros, $params);
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM ({$cte}
                WHERE sla_situacao = 'atencao' OR (sla_situacao = 'sem-sla' AND dias_parado >= 5)
             ) t"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // ─────────────────────────────────────────
    // Aba: Meus Processos — acompanhamento pessoal
    // (sem agregados de R$ — apenas contagens e o valor individual de cada linha)
    // ─────────────────────────────────────────

    /**
     * Fragmento SQL: data em que o processo ENTROU na etapa atual — respeitando
     * ciclos de devolução, não só a progressão linear pra frente.
     *
     * Um "COALESCE(atuado_em da etapa anterior, criado_em)" simples quebra quando
     * o processo é devolvido: Process::devolver()/Task::devolverEtapa() jogam
     * etapa_atual de volta pra um número MENOR, mas a etapa imediatamente anterior
     * a essa (etapa_atual - 1) já tinha sido aprovada há muito mais tempo — antes
     * de todo o caminho que foi percorrido e devolvido depois. Usar só ela faz
     * "dias parado" contar tempo que não existiu.
     *
     * A entrada real na etapa atual é o evento mais recente dentre:
     *   1. Aprovação normal da etapa anterior (progressão pra frente)
     *   2. 'etapa_reaberta' no histórico pra este número de etapa — gravado no
     *      exato momento em que uma devolução manda o processo de volta pra cá
     *      (Process::devolver / Task::devolverEtapa)
     *   3. 'processo_reenviado' no histórico pra este número de etapa — gravado
     *      quando o solicitante reenvia um processo devolvido (Process::reenviar)
     * O MAX() entre os três sempre escolhe o evento mais recente, então uma
     * devolução corretamente "reseta o relógio" mesmo que a etapa anterior tenha
     * sido aprovada há semanas.
     */
    private static function sqlEntradaEtapaAtual(string $alias = 'pr'): string
    {
        // Duas subqueries escalares correlacionadas + GREATEST, em vez de um
        // UNION dentro de tabela derivada: alias externo ({$alias}) não é
        // visível dentro de derived table no MySQL/MariaDB (só com LATERAL),
        // o que quebrava o prepare com "Unknown column '{$alias}.id'".
        // Ambas as datas são sempre >= criado_em, então o COALESCE individual
        // para criado_em preserva a semântica do MAX original.
        return "GREATEST(
            COALESCE(
                (SELECT MAX(pe_ant.atuado_em)
                   FROM nf_processo_etapas pe_ant
                  WHERE pe_ant.processo_id = {$alias}.id
                    AND pe_ant.numero_etapa = {$alias}.etapa_atual - 1
                    AND pe_ant.status = 'aprovado'),
                {$alias}.criado_em
            ),
            COALESCE(
                (SELECT MAX(ph.criado_em)
                   FROM nf_processo_historico ph
                  WHERE ph.processo_id = {$alias}.id
                    AND ph.numero_etapa = {$alias}.etapa_atual
                    AND ph.acao IN ('etapa_reaberta', 'processo_reenviado')),
                {$alias}.criado_em
            )
        )";
    }

    public static function kpisMeusProcessos(string $usuarioId, array $filtros = []): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if (!$ini) $ini = date('Y-m-01');
        if (!$fim) $fim = date('Y-m-t');

        // Processos que eu abri e ainda estão em andamento
        $stmt1 = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_processos
             WHERE criado_por = ? AND deletado_em IS NULL AND is_teste = 0
               AND status NOT IN ('aprovado','cancelado','encerrado_apollo')"
        );
        $stmt1->execute([$usuarioId]);
        $abertosPorMim = (int) $stmt1->fetchColumn();

        // Processos que eu abri e que voltaram devolvidos — aguardando minha correção
        $stmt1b = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_processos
             WHERE criado_por = ? AND deletado_em IS NULL AND is_teste = 0
               AND status = 'devolvido'"
        );
        $stmt1b->execute([$usuarioId]);
        $devolvidosPorMim = (int) $stmt1b->fetchColumn();

        // Aguardando minha ação agora (direto ou via grupo) + há quanto tempo o mais antigo espera
        $grupos = Process::getGruposDoUsuario($usuarioId);
        $condicoes = ["(pe.tipo_aprovador = 'usuario' AND pe.aprovador_id = ?)"];
        $params    = [$usuarioId];
        if (!empty($grupos)) {
            $placeholders = implode(',', array_fill(0, count($grupos), '?'));
            $condicoes[]  = "(pe.tipo_aprovador = 'grupo' AND pe.aprovador_id IN ($placeholders))";
            $params        = array_merge($params, $grupos);
        }
        $stmt2 = $pdo->prepare(
            "SELECT
               COUNT(*) AS qtd,
               MAX(DATEDIFF(NOW(), " . self::sqlEntradaEtapaAtual('pr') . ")) AS max_dias_parado
             FROM nf_processo_etapas pe
             JOIN nf_processos pr ON pr.id = pe.processo_id
             WHERE pe.status = 'pendente'
               AND pe.numero_etapa = pr.etapa_atual
               AND pr.status NOT IN ('aprovado','cancelado','encerrado_apollo')
               AND pr.is_teste = 0 AND pr.deletado_em IS NULL" . self::escopoSql() . "
               AND (" . implode(' OR ', $condicoes) . ')'
        );
        $stmt2->execute($params);
        $r2 = $stmt2->fetch(PDO::FETCH_ASSOC);

        // Concluídos no período — processos que eu ABRI e que já foram aprovados
        $stmt3 = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_processos
             WHERE criado_por = ? AND status = 'aprovado' AND is_teste = 0 AND deletado_em IS NULL
               AND concluido_em BETWEEN ? AND ?"
        );
        $stmt3->execute([$usuarioId, $ini . ' 00:00:00', $fim . ' 23:59:59']);
        $concluidosAbri = (int) $stmt3->fetchColumn();

        // Concluídos no período — processos em que eu PARTICIPEI aprovando alguma
        // etapa (mesmo sem ser o criador), já concluídos. Usa o histórico (ação
        // real que a pessoa executou), não a etapa atual — o processo já terminou.
        $stmt4 = $pdo->prepare(
            "SELECT COUNT(DISTINCT ph.processo_id)
             FROM nf_processo_historico ph
             JOIN nf_processos pr ON pr.id = ph.processo_id
             WHERE ph.acao = 'etapa_aprovada' AND ph.ator_id = ?
               AND pr.status = 'aprovado' AND pr.is_teste = 0 AND pr.deletado_em IS NULL" . self::escopoSql() . "
               AND pr.concluido_em BETWEEN ? AND ?"
        );
        $stmt4->execute([$usuarioId, $ini . ' 00:00:00', $fim . ' 23:59:59']);
        $concluidosParticipei = (int) $stmt4->fetchColumn();

        return [
            'abertos_por_mim'         => $abertosPorMim,
            'devolvidos_por_mim'      => $devolvidosPorMim,
            'aguardando_minha_acao'   => (int) ($r2['qtd'] ?? 0),
            'max_dias_parado'         => (int) ($r2['max_dias_parado'] ?? 0),
            'concluidos_abri'         => $concluidosAbri,
            'concluidos_participei'   => $concluidosParticipei,
        ];
    }

    /**
     * Monta o fragmento WHERE (busca + responsável atual + status) comum entre
     * listarMeusProcessos() e contarMeusProcessos() — os dois precisam enxergar
     * exatamente o mesmo recorte, ou a paginação (total vs. página exibida) fica
     * inconsistente. Preenche $params por referência, na ordem em que os `?`
     * aparecem no fragmento retornado.
     */
    private static function sqlFiltrosMeusProcessos(array $filtros, array &$params): string
    {
        $sql   = '';
        $busca = trim((string) ($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $sql .= ' AND (pr.codigo LIKE ? OR pr.fornecedor_nome LIKE ? OR pr.numero_nf LIKE ?)';
            $params[] = '%' . $busca . '%';
            $params[] = '%' . $busca . '%';
            $params[] = '%' . $busca . '%';
        }

        // Responsável ATUAL — pessoa ou grupo com a etapa atual em mãos (o mesmo
        // que aparece na coluna "Responsável atual"), não quem abriu o processo.
        if (!empty($filtros['mp_resp_tipo']) && !empty($filtros['mp_resp_id'])) {
            $sql .= ' AND pe_atual.tipo_aprovador = ? AND pe_atual.aprovador_id = ?';
            $params[] = $filtros['mp_resp_tipo'];
            $params[] = $filtros['mp_resp_id'];
        }

        // Status — multi-seleção via checkbox. Lista vazia (usuário desmarcou tudo)
        // não deve trazer nenhuma linha, daí o fallback pra um valor inexistente.
        $statusSelecionados = $filtros['mp_status'] ?? ['aberto', 'devolvido', 'cancelamento_solicitado'];
        if (empty($statusSelecionados)) {
            $statusSelecionados = ['__nenhum__'];
        }
        $statusPlaceholders = implode(',', array_fill(0, count($statusSelecionados), '?'));
        $sql .= " AND pr.status IN ($statusPlaceholders)";
        foreach ($statusSelecionados as $s) {
            $params[] = $s;
        }

        // Período — mesmo filtro usado nos KPIs, agora também recorta a tabela.
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if ($ini) { $sql .= ' AND pr.criado_em >= ?'; $params[] = $ini . ' 00:00:00'; }
        if ($fim) { $sql .= ' AND pr.criado_em <= ?'; $params[] = $fim . ' 23:59:59'; }

        return $sql;
    }

    /**
     * Total de processos em que o usuário participa, com os mesmos filtros de
     * listarMeusProcessos() — usado pra calcular a paginação.
     */
    public static function contarMeusProcessos(string $usuarioId, array $filtros = []): int
    {
        $pdo    = Database::getInstance();
        $params = [$usuarioId, $usuarioId, $usuarioId];
        $sqlFiltros = self::sqlFiltrosMeusProcessos($filtros, $params);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM nf_processos pr
             LEFT JOIN nf_processo_etapas pe_atual
                    ON pe_atual.processo_id = pr.id AND pe_atual.numero_etapa = pr.etapa_atual
             WHERE pr.deletado_em IS NULL AND pr.is_teste = 0" . self::escopoSql() . "
               AND (
                   pr.criado_por = ?
                   OR EXISTS (
                       SELECT 1 FROM nf_processo_etapas pe4
                       WHERE pe4.processo_id = pr.id
                         AND (
                             (pe4.tipo_aprovador = 'usuario' AND pe4.aprovador_id = ?)
                             OR (pe4.tipo_aprovador = 'grupo' AND pe4.aprovador_id IN (SELECT grupo_id FROM nf_grupo_membros WHERE usuario_id = ?))
                         )
                   )
               )" . $sqlFiltros
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lista os processos em que o usuário participa — como criador ou como
     * aprovador (direto ou via grupo) de alguma etapa, passada ou pendente.
     * Cada linha traz seu próprio valor individual; nada aqui é somado.
     */
    public static function listarMeusProcessos(string $usuarioId, array $filtros = [], int $offset = 0, int $limit = 20): array
    {
        $pdo    = Database::getInstance();
        $where  = ['pr.deletado_em IS NULL', 'pr.is_teste = 0'];
        $params = [$usuarioId, $usuarioId, $usuarioId];
        $sqlFiltros = self::sqlFiltrosMeusProcessos($filtros, $params);

        $orderMap = [
            'mais-recentes' => 'pr.criado_em DESC',
            'mais-antigos'  => 'pr.criado_em ASC',
            'maior-valor'   => 'pr.valor_nf DESC',
            'menor-valor'   => 'pr.valor_nf ASC',
        ];
        $orderSql = $orderMap[$filtros['ordem'] ?? 'mais-recentes'] ?? 'pr.criado_em DESC';

        $stmt = $pdo->prepare(
            "SELECT
               pr.id, pr.codigo, pr.numero_nf, pr.fornecedor_nome, pr.valor_nf, pr.status,
               pr.etapa_atual, pr.criado_em, pr.concluido_em,
               (SELECT COUNT(*) FROM nf_processo_etapas pe2 WHERE pe2.processo_id = pr.id) AS total_etapas,
               pe_atual.nome_etapa AS etapa_atual_nome,
               COALESCE(u_resp.nome, g_resp.nome) AS responsavel_atual_nome,
               DATEDIFF(NOW(), " . self::sqlEntradaEtapaAtual('pr') . ") AS dias_parado
             FROM nf_processos pr
             LEFT JOIN nf_processo_etapas pe_atual
                    ON pe_atual.processo_id = pr.id AND pe_atual.numero_etapa = pr.etapa_atual
             LEFT JOIN usuarios  u_resp ON u_resp.id = pe_atual.aprovador_id AND pe_atual.tipo_aprovador = 'usuario'
             LEFT JOIN nf_grupos g_resp ON g_resp.id = pe_atual.aprovador_id AND pe_atual.tipo_aprovador = 'grupo'
             WHERE " . implode(' AND ', $where) . "
               AND (
                   pr.criado_por = ?
                   OR EXISTS (
                       SELECT 1 FROM nf_processo_etapas pe4
                       WHERE pe4.processo_id = pr.id
                         AND (
                             (pe4.tipo_aprovador = 'usuario' AND pe4.aprovador_id = ?)
                             OR (pe4.tipo_aprovador = 'grupo' AND pe4.aprovador_id IN (SELECT grupo_id FROM nf_grupo_membros WHERE usuario_id = ?))
                         )
                   )
               )" . $sqlFiltros . "
             ORDER BY {$orderSql}
             LIMIT ? OFFSET ?"
        );
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Pessoas e grupos que estão, agora, com a etapa atual de algum processo em
     * que o usuário participa — alimenta o filtro "Responsável" da tabela
     * "Meus Processos", que precisa cobrir os dois tipos (a coluna "Responsável
     * atual" também mostra pessoa OU grupo).
     */
    public static function responsaveisAtuaisMeusProcessos(string $usuarioId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT DISTINCT pe.tipo_aprovador AS tipo, pe.aprovador_id AS id,
                    COALESCE(u.nome, g.nome) AS nome
             FROM nf_processo_etapas pe
             JOIN nf_processos pr ON pr.id = pe.processo_id AND pe.numero_etapa = pr.etapa_atual
             LEFT JOIN usuarios  u ON u.id = pe.aprovador_id AND pe.tipo_aprovador = 'usuario'
             LEFT JOIN nf_grupos g ON g.id = pe.aprovador_id AND pe.tipo_aprovador = 'grupo'
             WHERE pr.deletado_em IS NULL AND pr.is_teste = 0" . self::escopoSql() . "
               AND (
                   pr.criado_por = ?
                   OR EXISTS (
                       SELECT 1 FROM nf_processo_etapas pe4
                       WHERE pe4.processo_id = pr.id
                         AND (
                             (pe4.tipo_aprovador = 'usuario' AND pe4.aprovador_id = ?)
                             OR (pe4.tipo_aprovador = 'grupo' AND pe4.aprovador_id IN (SELECT grupo_id FROM nf_grupo_membros WHERE usuario_id = ?))
                         )
                   )
               )
             ORDER BY nome"
        );
        $stmt->execute([$usuarioId, $usuarioId, $usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────
    // Aba: Aprovações
    // ─────────────────────────────────────────

    public static function kpisAprovacoes(array $filtros = []): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if (!$ini) $ini = date('Y-m-01');
        if (!$fim) $fim = date('Y-m-t');

        // Aguardando aprovação agora — só a etapa atual de cada processo (não as futuras,
        // que também nascem 'pendente'), e só processos ainda não concluídos/cancelados
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_processo_etapas pe
             JOIN nf_processos pr ON pr.id = pe.processo_id
             WHERE pe.status = 'pendente'
               AND pe.numero_etapa = pr.etapa_atual
               AND pr.status NOT IN ('aprovado','cancelado','encerrado_apollo')
               AND pr.is_teste = 0 AND pr.deletado_em IS NULL" . self::escopoSql() . ""
        );
        $stmt->execute();
        $aguardando = (int)$stmt->fetchColumn();

        // Total de processos abertos — contexto pra "aguardando" (% do backlog total)
        $stmtTotal = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_processos
             WHERE deletado_em IS NULL AND is_teste = 0 AND status NOT IN ('aprovado','cancelado','encerrado_apollo')"
        );
        $stmtTotal->execute();
        $totalAbertos = (int)$stmtTotal->fetchColumn();

        // Aprovados no período
        $stmt2 = $pdo->prepare(
            "SELECT COUNT(*) AS qtd, COALESCE(SUM(pr.valor_nf), 0) AS valor
             FROM nf_processo_historico ph
             JOIN nf_processos pr ON pr.id = ph.processo_id
             WHERE ph.acao = 'processo_aprovado'
               AND ph.criado_em BETWEEN ? AND ?
               AND pr.is_teste = 0 AND pr.deletado_em IS NULL" . self::escopoSql() . ""
        );
        $stmt2->execute([$ini . ' 00:00:00', $fim . ' 23:59:59']);
        $r2 = $stmt2->fetch(PDO::FETCH_ASSOC);

        // Devolvidos no período
        $stmt3 = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_processo_historico ph
             JOIN nf_processos pr ON pr.id = ph.processo_id
             WHERE ph.acao = 'processo_devolvido'
               AND ph.criado_em BETWEEN ? AND ?
               AND pr.is_teste = 0 AND pr.deletado_em IS NULL" . self::escopoSql() . ""
        );
        $stmt3->execute([$ini . ' 00:00:00', $fim . ' 23:59:59']);
        $devolvidos = (int)$stmt3->fetchColumn();

        // Tempo médio de resposta (dias entre criação do processo e primeira ação de aprovação)
        $stmt4 = $pdo->prepare(
            "SELECT AVG(DATEDIFF(ph.criado_em, pr.criado_em)) AS media_dias
             FROM nf_processo_historico ph
             JOIN nf_processos pr ON pr.id = ph.processo_id
             WHERE ph.acao IN ('processo_aprovado','processo_devolvido')
               AND ph.criado_em BETWEEN ? AND ?
               AND pr.is_teste = 0 AND pr.deletado_em IS NULL" . self::escopoSql() . ""
        );
        $stmt4->execute([$ini . ' 00:00:00', $fim . ' 23:59:59']);
        $mediaDias = (float)($stmt4->fetchColumn() ?? 0);

        // Mesmas 3 métricas, mas no período anterior equivalente — contexto de tendência
        [$iniAnt, $fimAnt] = self::resolvePeriodoAnterior($filtros);
        $stmt5 = $pdo->prepare(
            "SELECT
               SUM(CASE WHEN ph.acao = 'processo_aprovado'  THEN 1 ELSE 0 END) AS aprovados_qtd,
               SUM(CASE WHEN ph.acao = 'processo_devolvido' THEN 1 ELSE 0 END) AS devolvidos,
               AVG(CASE WHEN ph.acao IN ('processo_aprovado','processo_devolvido') THEN DATEDIFF(ph.criado_em, pr.criado_em) END) AS media_dias
             FROM nf_processo_historico ph
             JOIN nf_processos pr ON pr.id = ph.processo_id
             WHERE ph.criado_em BETWEEN ? AND ?
               AND pr.is_teste = 0 AND pr.deletado_em IS NULL" . self::escopoSql() . ""
        );
        $stmt5->execute([$iniAnt . ' 00:00:00', $fimAnt . ' 23:59:59']);
        $r5 = $stmt5->fetch(PDO::FETCH_ASSOC);

        return [
            'aguardando'             => $aguardando,
            'total_abertos'          => $totalAbertos,
            'aprovados_qtd'          => (int)($r2['qtd']    ?? 0),
            'aprovados_valor'        => (float)($r2['valor'] ?? 0),
            'aprovados_qtd_anterior' => (int)($r5['aprovados_qtd'] ?? 0),
            'devolvidos'             => $devolvidos,
            'devolvidos_anterior'    => (int)($r5['devolvidos'] ?? 0),
            'media_dias'             => round($mediaDias, 1),
            'media_dias_anterior'    => round((float)($r5['media_dias'] ?? 0), 1),
        ];
    }

    /**
     * Fila de aprovação por aprovador.
     */
    /**
     * Fragmento WHERE compartilhado entre filaPorAprovador() e
     * contarFilaPorAprovador().
     */
    private static function sqlFiltrosFilaPorAprovador(array $filtros, array &$params): string
    {
        $where  = [
            "pe.status = 'pendente'",
            "pe.numero_etapa = pr.etapa_atual",
            "pr.status NOT IN ('aprovado','cancelado','encerrado_apollo')",
            "pr.is_teste = 0",
            "pr.deletado_em IS NULL",
        ];

        if (!empty($filtros['busca'])) {
            $like     = '%' . $filtros['busca'] . '%';
            $where[]  = "(u.nome LIKE ? OR pr.codigo LIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($filtros['grupo_id'])) {
            $where[]  = "u.id IN (SELECT usuario_id FROM nf_grupo_membros WHERE grupo_id = ?)";
            $params[] = $filtros['grupo_id'];
        }

        return implode(' AND ', $where);
    }

    public static function contarFilaPorAprovador(array $filtros = []): int
    {
        $pdo    = Database::getInstance();
        $params = [];
        $whereSql = self::sqlFiltrosFilaPorAprovador($filtros, $params);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM (
                 SELECT u.id
                 FROM nf_processo_etapas pe
                 JOIN nf_processos pr ON pr.id = pe.processo_id
                 JOIN usuarios u ON u.id = pe.aprovador_id
                 WHERE {$whereSql} AND pe.tipo_aprovador = 'usuario'
                 GROUP BY u.id
             ) t"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function filaPorAprovador(array $filtros = [], int $offset = 0, int $limit = 20): array
    {
        $pdo    = Database::getInstance();
        $params = [];
        $whereSql = self::sqlFiltrosFilaPorAprovador($filtros, $params);

        $stmt = $pdo->prepare(
            "SELECT
               u.id, u.nome AS aprovador,
               COUNT(pe.id) AS na_fila,
               MIN(pr.criado_em) AS mais_antigo,
               DATEDIFF(NOW(), MIN(pr.criado_em)) AS dias_mais_antigo,
               ROUND(AVG(DATEDIFF(NOW(),
                   COALESCE(
                       (SELECT pe2.atuado_em
                        FROM nf_processo_etapas pe2
                        WHERE pe2.processo_id = pe.processo_id
                          AND pe2.numero_etapa = pe.numero_etapa - 1
                        LIMIT 1),
                       pr.criado_em
                   )
               )), 1) AS dias_parado_aprovador,
               (SELECT ROUND(AVG(DATEDIFF(pe3.atuado_em,
                   COALESCE(
                       (SELECT pe4.atuado_em
                        FROM nf_processo_etapas pe4
                        WHERE pe4.processo_id = pe3.processo_id
                          AND pe4.numero_etapa = pe3.numero_etapa - 1
                        LIMIT 1),
                       pr3.criado_em
                   ))), 1)
                FROM nf_processo_etapas pe3
                JOIN nf_processos pr3 ON pr3.id = pe3.processo_id
                WHERE pe3.aprovador_id = u.id
                  AND pe3.tipo_aprovador = 'usuario'
                  AND pe3.status = 'aprovado'
                  AND pr3.is_teste = 0
               ) AS media_aprovacao
             FROM nf_processo_etapas pe
             JOIN nf_processos pr ON pr.id = pe.processo_id
             JOIN usuarios u ON u.id = pe.aprovador_id
             WHERE {$whereSql}
               AND pe.tipo_aprovador = 'usuario'
             GROUP BY u.id, u.nome
             ORDER BY na_fila DESC
             LIMIT ? OFFSET ?"
        );
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fila de aprovação pendente agregada por GRUPO (etapas com tipo_aprovador = 'grupo').
     * Espelha filaPorAprovador(), mas para etapas atribuídas a um grupo de aprovadores
     * em vez de a um usuário específico.
     */
    private static function sqlFiltrosFilaPorGrupo(array $filtros, array &$params): string
    {
        $where  = [
            "pe.status = 'pendente'",
            "pe.numero_etapa = pr.etapa_atual",
            "pr.status NOT IN ('aprovado','cancelado','encerrado_apollo')",
            "pr.is_teste = 0",
            "pr.deletado_em IS NULL",
        ];

        if (!empty($filtros['busca'])) {
            $like     = '%' . $filtros['busca'] . '%';
            $where[]  = "(g.nome LIKE ? OR pr.codigo LIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($filtros['grupo_id'])) {
            $where[]  = 'g.id = ?';
            $params[] = $filtros['grupo_id'];
        }

        return implode(' AND ', $where);
    }

    public static function contarFilaPorGrupo(array $filtros = []): int
    {
        $pdo    = Database::getInstance();
        $params = [];
        $whereSql = self::sqlFiltrosFilaPorGrupo($filtros, $params);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM (
                 SELECT g.id
                 FROM nf_processo_etapas pe
                 JOIN nf_processos pr ON pr.id = pe.processo_id
                 JOIN nf_grupos g ON g.id = pe.aprovador_id
                 WHERE {$whereSql} AND pe.tipo_aprovador = 'grupo'
                 GROUP BY g.id
             ) t"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function filaPorGrupo(array $filtros = [], int $offset = 0, int $limit = 20): array
    {
        $pdo    = Database::getInstance();
        $params = [];
        $whereSql = self::sqlFiltrosFilaPorGrupo($filtros, $params);

        $stmt = $pdo->prepare(
            "SELECT
               g.id, g.nome AS grupo,
               COUNT(pe.id) AS na_fila,
               MIN(pr.criado_em) AS mais_antigo,
               DATEDIFF(NOW(), MIN(pr.criado_em)) AS dias_mais_antigo,
               ROUND(AVG(DATEDIFF(NOW(),
                   COALESCE(
                       (SELECT pe2.atuado_em
                        FROM nf_processo_etapas pe2
                        WHERE pe2.processo_id = pe.processo_id
                          AND pe2.numero_etapa = pe.numero_etapa - 1
                        LIMIT 1),
                       pr.criado_em
                   )
               )), 1) AS dias_parado_grupo,
               (SELECT ROUND(AVG(DATEDIFF(pe3.atuado_em,
                   COALESCE(
                       (SELECT pe4.atuado_em
                        FROM nf_processo_etapas pe4
                        WHERE pe4.processo_id = pe3.processo_id
                          AND pe4.numero_etapa = pe3.numero_etapa - 1
                        LIMIT 1),
                       pr3.criado_em
                   ))), 1)
                FROM nf_processo_etapas pe3
                JOIN nf_processos pr3 ON pr3.id = pe3.processo_id
                WHERE pe3.aprovador_id = g.id
                  AND pe3.tipo_aprovador = 'grupo'
                  AND pe3.status = 'aprovado'
                  AND pr3.is_teste = 0
               ) AS media_aprovacao
             FROM nf_processo_etapas pe
             JOIN nf_processos pr ON pr.id = pe.processo_id
             JOIN nf_grupos g ON g.id = pe.aprovador_id
             WHERE {$whereSql}
               AND pe.tipo_aprovador = 'grupo'
             GROUP BY g.id, g.nome
             ORDER BY na_fila DESC
             LIMIT ? OFFSET ?"
        );
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Processos devolvidos no período.
     */
    public static function contarProcessosDevolvidos(array $filtros = []): int
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if (!$ini) $ini = date('Y-m-01');
        if (!$fim) $fim = date('Y-m-t');

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM nf_processo_historico ph
             JOIN nf_processos pr ON pr.id = ph.processo_id
             WHERE ph.acao = 'processo_devolvido'
               AND ph.criado_em BETWEEN ? AND ?
               AND pr.is_teste = 0 AND pr.deletado_em IS NULL" . self::escopoSql() . ""
        );
        $stmt->execute([$ini . ' 00:00:00', $fim . ' 23:59:59']);
        return (int) $stmt->fetchColumn();
    }

    public static function processosDevolvidos(array $filtros = [], int $offset = 0, int $limit = 20): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if (!$ini) $ini = date('Y-m-01');
        if (!$fim) $fim = date('Y-m-t');

        $stmt = $pdo->prepare(
            "SELECT
               pr.id, pr.codigo,
               SUBSTRING_INDEX(ph.detalhe, 'Motivo: ', -1) AS motivo,
               ph.ator_nome AS devolvido_por,
               pr.status AS status_atual,
               ph.criado_em AS devolvido_em,
               pr.fornecedor_nome AS fornecedor
             FROM nf_processo_historico ph
             JOIN nf_processos pr ON pr.id = ph.processo_id
             WHERE ph.acao = 'processo_devolvido'
               AND ph.criado_em BETWEEN ? AND ?
               AND pr.is_teste = 0 AND pr.deletado_em IS NULL" . self::escopoSql() . "
             ORDER BY ph.criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$ini . ' 00:00:00', $fim . ' 23:59:59', $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * SLA por etapa — tempo médio de permanência em cada etapa.
     * Compara com sla_horas configurado no template para classificar situação.
     */
    public static function slaPorEtapa(array $filtros = []): array
    {
        $pdo = Database::getInstance();
        require_once __DIR__ . '/../Helpers/SlaCalculator.php';
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if (!$ini) $ini = date('Y-m-01');
        if (!$fim) $fim = date('Y-m-t');

        $stmt = $pdo->prepare(
            "SELECT
               pe.nome_etapa AS etapa,
               pe.numero_etapa,
               tv.etapas AS etapas_json,
               COUNT(*) AS total_ocorrencias,
               ROUND(AVG(TIMESTAMPDIFF(HOUR,
                   COALESCE(
                       (SELECT pe2.atuado_em FROM nf_processo_etapas pe2
                        WHERE pe2.processo_id = pe.processo_id
                          AND pe2.numero_etapa = pe.numero_etapa - 1
                        LIMIT 1),
                       pr.criado_em
                   ),
                   pe.atuado_em
               )), 1) AS media_horas
             FROM nf_processo_etapas pe
             JOIN nf_processos pr ON pr.id = pe.processo_id
             JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             WHERE pe.status IN ('aprovado','devolvido')
               AND pe.atuado_em BETWEEN ? AND ?
               AND pr.is_teste = 0 AND pr.deletado_em IS NULL" . self::escopoSql() . "
             GROUP BY pe.nome_etapa, pe.numero_etapa, tv.etapas
             ORDER BY pe.numero_etapa ASC"
        );
        $stmt->execute([$ini . ' 00:00:00', $fim . ' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalHoras = array_sum(array_column($rows, 'media_horas'));
        foreach ($rows as &$row) {
            $slaHoras = SlaCalculator::extrairSlaHoras(
                $row['etapas_json'] ?? '[]',
                (int) $row['numero_etapa']
            );

            $mediaDias = round(((float)$row['media_horas']) / 24, 1);
            $row['media_dias'] = $mediaDias;

            if ($slaHoras !== null) {
                $esperadoDias = round($slaHoras / 24, 1);
                $row['esperado_dias'] = $esperadoDias;
                $variacao = $esperadoDias > 0
                    ? round((($mediaDias - $esperadoDias) / $esperadoDias) * 100, 1)
                    : 0;
                $row['variacao_pct'] = $variacao;

                $pct = $slaHoras > 0 ? ((float)$row['media_horas'] / $slaHoras) : 0;
                if ($pct > 1.0) {
                    $row['situacao'] = 'vencido';
                } elseif ($pct >= 0.80) {
                    $row['situacao'] = 'atencao';
                } else {
                    $row['situacao'] = 'ok';
                }
            } else {
                $row['esperado_dias'] = null;
                $row['variacao_pct']  = null;
                $row['situacao']      = 'sem-dados';
            }

            $row['pct_ciclo'] = $totalHoras > 0
                ? round((float)$row['media_horas'] / $totalHoras * 100)
                : 0;

            unset($row['etapas_json'], $row['media_horas'], $row['numero_etapa']);
        }
        return $rows;
    }

    // ─────────────────────────────────────────
    // Aba: Financeiro
    // ─────────────────────────────────────────

    public static function kpisFinanceiro(array $filtros = []): array
    {
        $pdo  = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if (!$ini) $ini = date('Y-m-01');
        if (!$fim) $fim = date('Y-m-t');

        // Aplica busca/filial_id (mesmo padrão de sqlFiltrosPendentesPagamento) —
        // "situacao" fica de fora de propósito: esses KPIs somam pendente/vencido/pago
        // como colunas separadas na mesma linha, então "filtrar por situação" não tem
        // um significado único aqui (ao contrário da lista de pendentes, que é uma
        // única coleção de linhas).
        $where  = ['p.deletado_em IS NULL', 'p.is_teste = 0'];
        $params = [];
        if (!empty($filtros['busca'])) {
            $like     = '%' . $filtros['busca'] . '%';
            $where[]  = '(pr.codigo LIKE ? OR pr.fornecedor_nome LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        [$condFil, $pFil] = self::filtroFiliais('pr.filial_id', $filtros['filial_id'] ?? null);
        if ($condFil !== '') {
            $where[] = $condFil;
            $params  = array_merge($params, $pFil);
        }
        $whereSql = implode(' AND ', $where);

        // "Pendente" e "vencido" precisam ser mutuamente exclusivos pra "Volume total em
        // aberto" (pendente + vencido) ser de fato uma soma, e não repetir o mesmo valor —
        // antes 'pendente' já incluía 'vencido'/'agvenc' dentro de si, então o card "Volume
        // total" só reexibia o mesmo número do card "Pendente", apesar do rótulo "Pendente + atrasado"
        $stmt = $pdo->prepare(
            "SELECT
               SUM(CASE WHEN p.status IN ('previsto','agendado')  THEN p.valor ELSE 0 END) AS pendente,
               SUM(CASE WHEN p.status IN ('vencido','agvenc')     THEN p.valor ELSE 0 END) AS vencido,
               SUM(CASE WHEN p.status = 'pago'
                         AND p.data_pagamento BETWEEN ? AND ?     THEN p.valor ELSE 0 END) AS pago_periodo
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             WHERE {$whereSql}"
        );
        $stmt->execute(array_merge([$ini, $fim], $params));
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        $pendente = (float)($r['pendente'] ?? 0);
        $vencido  = (float)($r['vencido']  ?? 0);

        // Pago no período anterior — contexto de tendência pra "Pago no período"
        // (mesmo filtro de busca/filial_id, pra a tendência ser coerente com o card)
        [$iniAnt, $fimAnt] = self::resolvePeriodoAnterior($filtros);
        $stmtAnt = $pdo->prepare(
            "SELECT COALESCE(SUM(p.valor),0)
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             WHERE {$whereSql} AND p.status = 'pago'
               AND p.data_pagamento BETWEEN ? AND ?"
        );
        $stmtAnt->execute(array_merge($params, [$iniAnt, $fimAnt]));
        $pagoAnterior = (float) $stmtAnt->fetchColumn();

        return [
            'pendente'          => $pendente,
            'vencido'           => $vencido,
            'pago_periodo'      => (float)($r['pago_periodo'] ?? 0),
            'pago_periodo_anterior' => $pagoAnterior,
            'total_aberto'      => $pendente + $vencido,
        ];
    }

    /**
     * Lista completa de pendentes de pagamento.
     */
    private static function sqlFiltrosPendentesPagamento(array $filtros, array &$params): string
    {
        $hoje  = date('Y-m-d');
        $where = [
            "p.deletado_em IS NULL",
            "p.is_teste = 0",
            "p.status IN ('previsto','agendado','agvenc','vencido')",
        ];

        if (!empty($filtros['busca'])) {
            $like     = '%' . $filtros['busca'] . '%';
            $where[]  = "(pr.codigo LIKE ? OR pr.fornecedor_nome LIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($filtros['situacao'])) {
            switch ($filtros['situacao']) {
                case 'vencido':
                    $where[] = "p.status IN ('vencido','agvenc')";
                    break;
                case 'vence-hoje':
                    $where[] = 'p.vencimento = ?';
                    $params[] = $hoje;
                    break;
                case 'a-vencer':
                    $where[]  = "p.vencimento > ? AND p.status NOT IN ('vencido','agvenc')";
                    $params[] = $hoje;
                    break;
            }
        }
        [$condFil, $pFil] = self::filtroFiliais('pr.filial_id', $filtros['filial_id'] ?? null);
        if ($condFil !== '') {
            $where[] = $condFil;
            $params  = array_merge($params, $pFil);
        }
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if ($ini) { $where[] = 'p.vencimento >= ?'; $params[] = $ini; }
        if ($fim) { $where[] = 'p.vencimento <= ?'; $params[] = $fim; }

        return implode(' AND ', $where);
    }

    public static function contarPendentesPagamento(array $filtros = []): int
    {
        $pdo    = Database::getInstance();
        $params = [];
        $whereSql = self::sqlFiltrosPendentesPagamento($filtros, $params);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             WHERE {$whereSql}"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function pendentesPagamento(array $filtros = [], int $offset = 0, int $limit = 20): array
    {
        $pdo    = Database::getInstance();
        $hoje   = date('Y-m-d');
        $params = [];
        $whereSql = self::sqlFiltrosPendentesPagamento($filtros, $params);

        $stmt = $pdo->prepare(
            "SELECT
               p.id, p.vencimento, p.valor, p.status,
               pr.id AS processo_id, pr.codigo AS proc_codigo,
               pr.fornecedor_nome AS fornecedor,
               fi.nome AS filial_nome,
               CASE WHEN p.status IN ('vencido','agvenc') THEN 'vencido'
                    WHEN p.vencimento = ?                  THEN 'vence-hoje'
                    ELSE 'a-vencer' END AS situacao_calc,
               CASE WHEN p.status IN ('vencido','agvenc')
                    THEN DATEDIFF(?, p.vencimento)
                    ELSE 0 END AS dias_atraso
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN filiais fi ON fi.id = pr.filial_id
             WHERE {$whereSql}
             ORDER BY p.vencimento ASC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge([$hoje, $hoje], $params, [$limit, $offset]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Volume financeiro por loja (filial).
     */
    public static function volumePorLoja(array $filtros = []): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if (!$ini) $ini = date('Y-m-01');
        if (!$fim) $fim = date('Y-m-t');

        // busca/filial_id aplicados (situacao fica de fora — ver kpisFinanceiro())
        $where  = ['p.deletado_em IS NULL', 'p.is_teste = 0'];
        $params = [];
        if (!empty($filtros['busca'])) {
            $like     = '%' . $filtros['busca'] . '%';
            $where[]  = '(pr.codigo LIKE ? OR pr.fornecedor_nome LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        [$condFil, $pFil] = self::filtroFiliais('pr.filial_id', $filtros['filial_id'] ?? null);
        if ($condFil !== '') {
            $where[] = $condFil;
            $params  = array_merge($params, $pFil);
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT
               fi.nome AS loja,
               SUM(CASE WHEN p.status IN ('previsto','agendado','agvenc','vencido') THEN p.valor ELSE 0 END) AS em_aberto,
               SUM(CASE WHEN p.status IN ('vencido','agvenc')                       THEN p.valor ELSE 0 END) AS atrasado,
               SUM(CASE WHEN p.status = 'pago'
                         AND p.data_pagamento BETWEEN ? AND ?                       THEN p.valor ELSE 0 END) AS pago_periodo,
               SUM(p.valor) AS total_movimentado
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN filiais fi ON fi.id = pr.filial_id
             WHERE {$whereSql}
             GROUP BY fi.id, fi.nome
             ORDER BY em_aberto DESC"
        );
        $stmt->execute(array_merge([$ini, $fim], $params));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Volume financeiro por setor do processo — via nf_template_versoes.setor_id
     * (o setor selecionado no wizard "Contexto da Nota Fiscal", não o setor do
     * usuário que abriu o processo).
     */
    public static function volumePorSetor(array $filtros = []): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if (!$ini) $ini = date('Y-m-01');
        if (!$fim) $fim = date('Y-m-t');

        // busca/filial_id aplicados (situacao fica de fora — ver kpisFinanceiro())
        $where  = ['p.deletado_em IS NULL', 'p.is_teste = 0'];
        $params = [];
        if (!empty($filtros['busca'])) {
            $like     = '%' . $filtros['busca'] . '%';
            $where[]  = '(pr.codigo LIKE ? OR pr.fornecedor_nome LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        [$condFil, $pFil] = self::filtroFiliais('pr.filial_id', $filtros['filial_id'] ?? null);
        if ($condFil !== '') {
            $where[] = $condFil;
            $params  = array_merge($params, $pFil);
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT
               COALESCE(s.nome, 'Sem setor') AS setor,
               SUM(CASE WHEN p.status IN ('previsto','agendado','agvenc','vencido') THEN p.valor ELSE 0 END) AS em_aberto,
               SUM(CASE WHEN p.status IN ('vencido','agvenc')                       THEN p.valor ELSE 0 END) AS atrasado,
               SUM(CASE WHEN p.status = 'pago'
                         AND p.data_pagamento BETWEEN ? AND ?                       THEN p.valor ELSE 0 END) AS pago_periodo,
               SUM(p.valor) AS total_movimentado
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             LEFT JOIN setores s ON s.id = tv.setor_id
             WHERE {$whereSql}
             GROUP BY s.id, s.nome
             ORDER BY em_aberto DESC"
        );
        $stmt->execute(array_merge([$ini, $fim], $params));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Volume financeiro por departamento do processo — via
     * nf_template_versoes.setor_id → setores.departamento_id → nf_lista_itens.
     */
    public static function volumePorDepartamento(array $filtros = []): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if (!$ini) $ini = date('Y-m-01');
        if (!$fim) $fim = date('Y-m-t');

        // busca/filial_id aplicados (situacao fica de fora — ver kpisFinanceiro())
        $where  = ['p.deletado_em IS NULL', 'p.is_teste = 0'];
        $params = [];
        if (!empty($filtros['busca'])) {
            $like     = '%' . $filtros['busca'] . '%';
            $where[]  = '(pr.codigo LIKE ? OR pr.fornecedor_nome LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        [$condFil, $pFil] = self::filtroFiliais('pr.filial_id', $filtros['filial_id'] ?? null);
        if ($condFil !== '') {
            $where[] = $condFil;
            $params  = array_merge($params, $pFil);
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT
               COALESCE(d.valor, 'Sem departamento') AS departamento,
               SUM(CASE WHEN p.status IN ('previsto','agendado','agvenc','vencido') THEN p.valor ELSE 0 END) AS em_aberto,
               SUM(CASE WHEN p.status IN ('vencido','agvenc')                       THEN p.valor ELSE 0 END) AS atrasado,
               SUM(CASE WHEN p.status = 'pago'
                         AND p.data_pagamento BETWEEN ? AND ?                       THEN p.valor ELSE 0 END) AS pago_periodo,
               SUM(p.valor) AS total_movimentado
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             LEFT JOIN setores s ON s.id = tv.setor_id
             LEFT JOIN nf_lista_itens d ON d.id = s.departamento_id
             WHERE {$whereSql}
             GROUP BY d.id, d.valor
             ORDER BY em_aberto DESC"
        );
        $stmt->execute(array_merge([$ini, $fim], $params));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────
    // Aba: Desempenho
    // ─────────────────────────────────────────

    public static function kpisDesempenho(array $filtros = []): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if (!$ini) $ini = date('Y-m-01');
        if (!$fim) $fim = date('Y-m-t');

        $stmt = $pdo->prepare(
            "SELECT
               COUNT(*) AS total,
               SUM(CASE WHEN pr.status = 'aprovado'  THEN 1 ELSE 0 END) AS concluidos,
               SUM(CASE WHEN pr.status = 'devolvido'
                         OR ph_dev.id IS NOT NULL      THEN 1 ELSE 0 END) AS com_devolucao
             FROM nf_processos pr
             LEFT JOIN (
                 SELECT DISTINCT processo_id AS id FROM nf_processo_historico
                 WHERE acao = 'processo_devolvido'
             ) ph_dev ON ph_dev.id = pr.id
             WHERE pr.deletado_em IS NULL AND pr.is_teste = 0" . self::escopoSql() . "
               AND pr.criado_em BETWEEN ? AND ?"
        );
        $stmt->execute([$ini . ' 00:00:00', $fim . ' 23:59:59']);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        $total      = (int)($r['total']         ?? 0);
        $concluidos = (int)($r['concluidos']    ?? 0);
        $comDev     = (int)($r['com_devolucao'] ?? 0);

        // Tempo médio de ciclo é medido pelos processos CONCLUÍDOS no período
        // (concluido_em), não pelos criados no período — senão um processo aberto
        // no fim do mês passado e aprovado neste mês fica de fora da amostra.
        $stmtCiclo = $pdo->prepare(
            "SELECT COUNT(*) AS n, AVG(DATEDIFF(pr.concluido_em, pr.criado_em)) AS tempo_medio
             FROM nf_processos pr
             WHERE pr.deletado_em IS NULL AND pr.is_teste = 0" . self::escopoSql() . "
               AND pr.status = 'aprovado'
               AND pr.concluido_em BETWEEN ? AND ?"
        );
        $stmtCiclo->execute([$ini . ' 00:00:00', $fim . ' 23:59:59']);
        $cicloRow    = $stmtCiclo->fetch(PDO::FETCH_ASSOC);
        $cicloN      = (int)($cicloRow['n'] ?? 0);
        $tempoMedio  = (float)($cicloRow['tempo_medio'] ?? 0);

        // Amostra pequena (ex.: 1 processo concluído no mês) faz a média não ser
        // representativa — "0 dias" nesse caso parece bug, não estatística real.
        // Cai pro histórico completo (todos os já aprovados) como referência.
        $cicloFallback = false;
        $MIN_AMOSTRA_CICLO = 3;
        if ($cicloN < $MIN_AMOSTRA_CICLO) {
            $rowAll = $pdo->query(
                "SELECT COUNT(*) AS n, AVG(DATEDIFF(concluido_em, criado_em)) AS tempo_medio
                 FROM nf_processos
                 WHERE deletado_em IS NULL AND is_teste = 0 AND status = 'aprovado'"
            )->fetch(PDO::FETCH_ASSOC);
            if ((int)($rowAll['n'] ?? 0) > 0) {
                $cicloN        = (int)$rowAll['n'];
                $tempoMedio    = (float)($rowAll['tempo_medio'] ?? 0);
                $cicloFallback = true;
            }
        }

        // Mesmas 4 métricas no período anterior equivalente — sem isso, "13 dias de
        // ciclo" ou "62% de conclusão" não dizem se a operação está melhorando ou piorando
        [$iniAnt, $fimAnt] = self::resolvePeriodoAnterior($filtros);
        $stmtAnt = $pdo->prepare(
            "SELECT
               COUNT(*) AS total,
               SUM(CASE WHEN pr.status = 'aprovado'  THEN 1 ELSE 0 END) AS concluidos,
               SUM(CASE WHEN pr.status = 'devolvido'
                         OR ph_dev.id IS NOT NULL      THEN 1 ELSE 0 END) AS com_devolucao
             FROM nf_processos pr
             LEFT JOIN (
                 SELECT DISTINCT processo_id AS id FROM nf_processo_historico
                 WHERE acao = 'processo_devolvido'
             ) ph_dev ON ph_dev.id = pr.id
             WHERE pr.deletado_em IS NULL AND pr.is_teste = 0" . self::escopoSql() . "
               AND pr.criado_em BETWEEN ? AND ?"
        );
        $stmtAnt->execute([$iniAnt . ' 00:00:00', $fimAnt . ' 23:59:59']);
        $rAnt = $stmtAnt->fetch(PDO::FETCH_ASSOC);

        $totalAnt      = (int)($rAnt['total']         ?? 0);
        $concluidosAnt = (int)($rAnt['concluidos']    ?? 0);
        $comDevAnt     = (int)($rAnt['com_devolucao'] ?? 0);

        $stmtCicloAnt = $pdo->prepare(
            "SELECT AVG(DATEDIFF(pr.concluido_em, pr.criado_em)) AS tempo_medio
             FROM nf_processos pr
             WHERE pr.deletado_em IS NULL AND pr.is_teste = 0" . self::escopoSql() . "
               AND pr.status = 'aprovado'
               AND pr.concluido_em BETWEEN ? AND ?"
        );
        $stmtCicloAnt->execute([$iniAnt . ' 00:00:00', $fimAnt . ' 23:59:59']);
        $tempoMedioAnt = (float)($stmtCicloAnt->fetchColumn() ?? 0);

        return [
            'tempo_medio'             => round($tempoMedio, 1),
            'tempo_medio_anterior'    => round($tempoMedioAnt, 1),
            'tempo_medio_amostra'     => $cicloN,
            'tempo_medio_fallback'    => $cicloFallback,
            'total_periodo'           => $total,
            'total_periodo_anterior'  => $totalAnt,
            'taxa_conclusao'          => $total > 0 ? round($concluidos / $total * 100) : 0,
            'taxa_conclusao_anterior' => $totalAnt > 0 ? round($concluidosAnt / $totalAnt * 100) : 0,
            'taxa_devolucao'          => $total > 0 ? round($comDev     / $total * 100) : 0,
            'taxa_devolucao_anterior' => $totalAnt > 0 ? round($comDevAnt / $totalAnt * 100) : 0,
        ];
    }

    /**
     * Tempo médio por etapa para a tabela de desempenho.
     */
    public static function tempoPorEtapa(array $filtros = []): array
    {
        $pdo = Database::getInstance();
        require_once __DIR__ . '/../Helpers/SlaCalculator.php';
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if (!$ini) $ini = date('Y-m-01');
        if (!$fim) $fim = date('Y-m-t');

        $sqlBase =
            "SELECT
               pe.nome_etapa AS etapa,
               pe.numero_etapa,
               tv.template_id,
               t.nome AS template_nome,
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
               ROUND(MIN(DATEDIFF(pe.atuado_em, pr.criado_em)), 1) AS min_dias,
               ROUND(MAX(DATEDIFF(pe.atuado_em, pr.criado_em)), 1) AS max_dias,
               COUNT(*) AS total_ocorrencias
             FROM nf_processo_etapas pe
             JOIN nf_processos pr ON pr.id = pe.processo_id
             JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             JOIN nf_templates t ON t.id = tv.template_id
             WHERE pe.status IN ('aprovado','devolvido')
               AND pr.is_teste = 0 AND pr.deletado_em IS NULL" . self::escopoSql() . "";

        $stmt = $pdo->prepare(
            $sqlBase . " AND pe.atuado_em BETWEEN ? AND ?
             GROUP BY pe.nome_etapa, pe.numero_etapa, tv.template_id, t.nome, tv.etapas
             ORDER BY t.nome, pe.numero_etapa"
        );
        $stmt->execute([$ini . ' 00:00:00', $fim . ' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Amostra do período pode não cobrir todas as etapas cadastradas nos
        // templates (ex.: uma etapa só teve movimentação fora do período
        // selecionado). Cai pro histórico completo pra não "sumir" com etapas
        // reais — mesma lógica do fallback do tempo médio de ciclo.
        $etapaFallback = false;
        $totalEtapasHistorico = (int)$pdo->query(
            "SELECT COUNT(DISTINCT CONCAT(pe.nome_etapa,'|',pe.numero_etapa))
             FROM nf_processo_etapas pe
             JOIN nf_processos pr ON pr.id = pe.processo_id
             WHERE pe.status IN ('aprovado','devolvido')
               AND pr.is_teste = 0 AND pr.deletado_em IS NULL" . self::escopoSql() . ""
        )->fetchColumn();

        if (count($rows) < $totalEtapasHistorico) {
            $stmtAll = $pdo->query(
                $sqlBase . "
             GROUP BY pe.nome_etapa, pe.numero_etapa, tv.template_id, t.nome, tv.etapas
             ORDER BY t.nome, pe.numero_etapa"
            );
            $rows = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
            $etapaFallback = true;
        }

        // % do ciclo é relativo à SOMA das etapas do MESMO template — misturar
        // etapas de fluxos diferentes no denominador dava um % sem sentido
        // (ex.: "Aprovação Financeiro" de 3 templates distintos somadas juntas).
        $totalHorasPorTemplate = [];
        foreach ($rows as $r) {
            $tplId = $r['template_id'] ?? '';
            $totalHorasPorTemplate[$tplId] = ($totalHorasPorTemplate[$tplId] ?? 0) + (float) $r['media_horas'];
        }

        foreach ($rows as &$r) {
            $mediaH = (float) $r['media_horas'];
            $r['media_dias'] = round($mediaH / 24, 1);
            $totalHorasTpl   = $totalHorasPorTemplate[$r['template_id'] ?? ''] ?? 0;
            $r['pct_ciclo']  = $totalHorasTpl > 0
                ? round($mediaH / $totalHorasTpl * 100)
                : 0;

            $slaHoras = SlaCalculator::extrairSlaHoras($r['etapas_json'] ?? '[]', (int) $r['numero_etapa']);
            if ($slaHoras !== null) {
                $pct = $slaHoras > 0 ? ($mediaH / $slaHoras) : 0;
                $r['gargalo']  = $pct > 1.0;
                $r['situacao'] = $pct > 1.0 ? 'vencido' : ($pct >= 0.80 ? 'atencao' : 'ok');
            } else {
                $r['gargalo']  = false;
                $r['situacao'] = 'sem-dados';
            }

            unset($r['etapas_json'], $r['media_horas'], $r['numero_etapa'], $r['template_id']);
            $r['_fallback'] = $etapaFallback;
        }
        return $rows;
    }

    /**
     * Ranking de usuários por desempenho.
     */
    public static function contarRankingUsuarios(array $filtros = []): int
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if (!$ini) $ini = date('Y-m-01');
        if (!$fim) $fim = date('Y-m-t');
        $ini_dt = $ini . ' 00:00:00';
        $fim_dt = $fim . ' 23:59:59';

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM (
                 SELECT u.id, COUNT(DISTINCT pr.id) AS processos_abertos
                 FROM usuarios u
                 LEFT JOIN nf_processos pr
                        ON pr.criado_por = u.id
                       AND pr.deletado_em IS NULL AND pr.is_teste = 0
                       AND pr.criado_em BETWEEN ? AND ?
                 WHERE u.ativo = 1
                 GROUP BY u.id
                 HAVING processos_abertos > 0
             ) t"
        );
        $stmt->execute([$ini_dt, $fim_dt]);
        return (int) $stmt->fetchColumn();
    }

    public static function rankingUsuarios(array $filtros = [], int $offset = 0, int $limit = 30): array
    {
        $pdo = Database::getInstance();
        [$ini, $fim] = self::resolvePeriodo($filtros);
        if (!$ini) $ini = date('Y-m-01');
        if (!$fim) $fim = date('Y-m-t');

        $ini_dt = $ini . ' 00:00:00';
        $fim_dt = $fim . ' 23:59:59';

        // LEFT JOIN a partir de usuarios (não de nf_processos) — senão um usuário
        // que não abriu nenhum processo NO PERÍODO some da lista inteira, mesmo
        // sendo um usuário ativo do sistema. A condição de período fica no ON
        // do LEFT JOIN, não no WHERE — WHERE derrubaria o LEFT JOIN pra INNER.
        $stmt = $pdo->prepare(
            "SELECT
               u.id, u.nome, u.perfil AS funcao,
               COUNT(DISTINCT pr.id) AS processos_abertos,
               SUM(CASE WHEN pr.status = 'aprovado'
                         AND pr.concluido_em BETWEEN ? AND ? THEN 1 ELSE 0 END) AS aprovados_concluidos,
               NULL AS media_resposta,
               SUM(CASE WHEN ph.acao = 'processo_devolvido'
                         AND ph.ator_id = u.id
                         AND ph.criado_em BETWEEN ? AND ?    THEN 1 ELSE 0 END) AS devolucoes
             FROM usuarios u
             LEFT JOIN nf_processos pr
                    ON pr.criado_por = u.id
                   AND pr.deletado_em IS NULL AND pr.is_teste = 0
                   AND pr.criado_em BETWEEN ? AND ?
             LEFT JOIN nf_processo_historico ph ON ph.processo_id = pr.id
             WHERE u.ativo = 1
             GROUP BY u.id, u.nome, u.perfil
             HAVING processos_abertos > 0
             ORDER BY processos_abertos DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$ini_dt, $fim_dt, $ini_dt, $fim_dt, $ini_dt, $fim_dt, $limit, $offset]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $total = (int)$r['processos_abertos'];
            $dev   = (int)$r['devolucoes'];
            $r['taxa_devolucao'] = $total > 0 ? round($dev / $total * 100, 1) : 0;
            $r['critico']        = $r['taxa_devolucao'] > 20;
        }
        return $rows;
    }

    /**
     * Comparativo mensal dos últimos 12 meses.
     */
    public static function comparativoMensal(): array
    {
        $pdo   = Database::getInstance();
        $rows  = [];

        // Não mostra meses anteriores ao primeiro processo já criado no sistema
        // — senão a tabela nasce com vários meses zerados só porque o sistema
        // ainda não existia naquela época.
        $primeiroCriado = $pdo->query(
            "SELECT MIN(criado_em) FROM nf_processos WHERE deletado_em IS NULL AND is_teste = 0"
        )->fetchColumn();
        $primeiroMes = $primeiroCriado ? date('Y-m', strtotime($primeiroCriado)) : date('Y-m');

        for ($i = 11; $i >= 0; $i--) {
            $mes = date('Y-m', strtotime("-{$i} months"));
            if ($mes < $primeiroMes) continue;
            [$ini, $fim] = [$mes . '-01', date('Y-m-t', strtotime($mes . '-01'))];
            $i1 = $ini . ' 00:00:00';
            $f1 = $fim . ' 23:59:59';

            // Coorte: todas as colunas se referem ao MESMO grupo — processos
            // abertos naquele mês — variando só pelo status ATUAL de cada um.
            // Abertos = tamanho do coorte; concluídos/cancelados/em andamento
            // somam exatamente o total de abertos, sem misturar com o mês em
            // que a conclusão/cancelamento efetivamente aconteceu.
            $stmt = $pdo->prepare(
                "SELECT
                   COUNT(*) AS abertos,
                   SUM(CASE WHEN pr.status = 'aprovado' THEN 1 ELSE 0 END) AS concluidos,
                   SUM(CASE WHEN pr.status IN ('cancelado','cancelamento_solicitado') THEN 1 ELSE 0 END) AS cancelados,
                   SUM(CASE WHEN pr.status NOT IN ('aprovado','cancelado','cancelamento_solicitado') THEN 1 ELSE 0 END) AS em_andamento,
                   SUM(pr.valor_nf) AS valor_total,
                   AVG(CASE WHEN pr.status = 'aprovado'
                             THEN DATEDIFF(pr.concluido_em, pr.criado_em)
                             ELSE NULL END) AS tempo_medio
                 FROM nf_processos pr
                 WHERE pr.deletado_em IS NULL AND pr.is_teste = 0" . self::escopoSql() . "
                   AND pr.criado_em BETWEEN ? AND ?"
            );
            $stmt->execute([$i1, $f1]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);

            $abertos    = (int)($r['abertos']    ?? 0);
            $concluidos = (int)($r['concluidos'] ?? 0);
            $rows[] = [
                'mes'            => $mes,
                'abertos'        => $abertos,
                'concluidos'     => $concluidos,
                'cancelados'     => (int)($r['cancelados']   ?? 0),
                'em_andamento'   => (int)($r['em_andamento'] ?? 0),
                'valor_total'    => (float)($r['valor_total'] ?? 0),
                'tempo_medio'    => round((float)($r['tempo_medio'] ?? 0), 1),
                'taxa_conclusao' => $abertos > 0 ? round($concluidos / $abertos * 100) : 0,
            ];
        }
        return $rows;
    }

    // ─────────────────────────────────────────
    // Dados para filtros (dropdowns)
    // ─────────────────────────────────────────

    /** Lojas oferecidas no filtro — só as que o usuário logado enxerga. */
    public static function filiais(): array
    {
        $uid = (string) ($_SESSION['usuario_id'] ?? '');
        if ($uid !== '' && class_exists('FilialScope')) {
            return FilialScope::filiaisVisiveis($uid);
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->query("SELECT id, nome FROM filiais ORDER BY nome");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function setores(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query("SELECT id, nome FROM setores ORDER BY nome");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function tiposNota(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT li.id, li.valor AS nome
             FROM nf_lista_itens li
             JOIN nf_listas l ON l.id = li.lista_id
             WHERE l.slug = 'tipos-nota' AND li.ativo = 1
             ORDER BY li.valor"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function grupos(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query("SELECT id, nome FROM nf_grupos WHERE ativo = 1 ORDER BY nome");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function responsaveis(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            "SELECT DISTINCT u.id, u.nome
             FROM usuarios u
             JOIN nf_processos pr ON pr.criado_por = u.id
             WHERE pr.deletado_em IS NULL
             ORDER BY u.nome"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
