<?php
// @versao Fin_04 V_08_13/08/2026
declare(strict_types=1);

/**
 * Model do módulo Financeiro.
 *
 * Gerencia nf_parcelas e nf_parcela_historico no banco `compras`.
 * Sem ORM — apenas PDO com prepared statements.
 */
class Financial
{
    // ─────────────────────────────────────────
    // Utilitário interno
    // ─────────────────────────────────────────

    private static function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }

    // ─────────────────────────────────────────
    // Resolução de status real (vencido / agvenc)
    // ─────────────────────────────────────────

    /**
     * Atualiza status de parcelas vencidas em lote antes de qualquer listagem.
     * - previsto  + vencimento < hoje  → vencido
     * - agendado  + vencimento < hoje  → agvenc
     */
    public static function atualizarStatusVencidos(): void
    {
        $pdo  = Database::getInstance();
        $hoje = date('Y-m-d');

        $pdo->prepare(
            "UPDATE nf_parcelas
             SET status = 'vencido', atualizado_em = NOW()
             WHERE status = 'previsto' AND vencimento < ? AND deletado_em IS NULL"
        )->execute([$hoje]);

        $pdo->prepare(
            "UPDATE nf_parcelas
             SET status = 'agvenc', atualizado_em = NOW()
             WHERE status = 'agendado' AND vencimento < ? AND deletado_em IS NULL"
        )->execute([$hoje]);
    }

    // ─────────────────────────────────────────
    // Contagens por aba
    // ─────────────────────────────────────────

    /**
     * Retorna contagem de parcelas por grupo de status para os badges das abas.
     *
     * @param array $filtros  filtros base (busca, fornecedor_id, setor_id, periodo_*)
     * @param bool  $is_teste incluir registros de teste
     */
    public static function contagens(array $filtros = [], bool $is_teste = false): array
    {
        $pdo    = Database::getInstance();
        [$where, $params] = self::buildWhere($filtros, $is_teste, '_global');

        $sql = "SELECT
                  SUM(p.status NOT IN ('pago','cancelado')) AS todos,
                  SUM(p.status IN ('vencido','agvenc')) AS atraso,
                  SUM(p.status = 'previsto') AS previsto,
                  SUM(p.status IN ('agendado','agvenc')) AS agendado,
                  SUM(p.status = 'pago') AS pago,
                  SUM(p.status = 'cancelado') AS cancelado
                FROM nf_parcelas p
                JOIN nf_processos pr ON pr.id = p.processo_id
                LEFT JOIN fornecedores f ON f.id = pr.fornecedor_id
                LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
                LEFT JOIN setores s ON s.id = tv.setor_id
                WHERE " . implode(' AND ', $where);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'todos'     => (int)($row['todos']     ?? 0),
            'atraso'    => (int)($row['atraso']     ?? 0),
            'previsto'  => (int)($row['previsto']   ?? 0),
            'agendado'  => (int)($row['agendado']   ?? 0),
            'pago'      => (int)($row['pago']       ?? 0),
            'cancelado' => (int)($row['cancelado']  ?? 0),
        ];
    }

    // ─────────────────────────────────────────
    // KPIs financeiros
    // ─────────────────────────────────────────

    /**
     * Retorna somas dos KPIs para o recorte ativo.
     */
    public static function kpis(array $filtros = [], string $aba = 'todos', bool $is_teste = false): array
    {
        $pdo    = Database::getInstance();
        $hoje   = date('Y-m-d');
        $d7     = date('Y-m-d', strtotime('+7 days'));

        [$where, $params] = self::buildWhere($filtros, $is_teste, $aba);

        $sql = "SELECT
                  SUM(CASE WHEN p.status IN ('vencido','agvenc') THEN p.valor ELSE 0 END)  AS vencido,
                  SUM(CASE WHEN p.status IN ('vencido','agvenc') THEN 1 ELSE 0 END)        AS vencido_qtd,
                  SUM(CASE WHEN p.status IN ('previsto','vencido')
                            AND p.vencimento > ? AND p.vencimento <= ?  THEN p.valor ELSE 0 END) AS avencer_7d,
                  SUM(CASE WHEN p.status IN ('previsto','vencido')
                            AND p.vencimento > ? AND p.vencimento <= ?  THEN 1 ELSE 0 END)       AS avencer_7d_qtd,
                  SUM(CASE WHEN p.status IN ('agendado','agvenc')        THEN p.valor ELSE 0 END) AS agendado,
                  SUM(CASE WHEN p.status IN ('agendado','agvenc')        THEN 1 ELSE 0 END)       AS agendado_qtd,
                  SUM(CASE WHEN p.status = 'previsto'                    THEN p.valor ELSE 0 END) AS previsto,
                  SUM(CASE WHEN p.status = 'previsto'                    THEN 1 ELSE 0 END)       AS previsto_qtd,
                  SUM(CASE WHEN p.status = 'pago'                        THEN p.valor ELSE 0 END) AS pago,
                  SUM(CASE WHEN p.status = 'pago'                        THEN 1 ELSE 0 END)       AS pago_qtd
                FROM nf_parcelas p
                JOIN nf_processos pr ON pr.id = p.processo_id
                LEFT JOIN fornecedores f ON f.id = pr.fornecedor_id
                LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
                LEFT JOIN setores s ON s.id = tv.setor_id
                WHERE " . implode(' AND ', $where);

        // parâmetros extras para a janela 7d (2 pares: valor + qtd)
        array_splice($params, 0, 0, [$hoje, $d7, $hoje, $d7]);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'vencido'        => (float)($r['vencido']        ?? 0),
            'vencido_qtd'    => (int)($r['vencido_qtd']      ?? 0),
            'avencer_7d'     => (float)($r['avencer_7d']     ?? 0),
            'avencer_7d_qtd' => (int)($r['avencer_7d_qtd']   ?? 0),
            'agendado'       => (float)($r['agendado']       ?? 0),
            'agendado_qtd'   => (int)($r['agendado_qtd']     ?? 0),
            'previsto'       => (float)($r['previsto']       ?? 0),
            'previsto_qtd'   => (int)($r['previsto_qtd']     ?? 0),
            'pago'           => (float)($r['pago']           ?? 0),
            'pago_qtd'       => (int)($r['pago_qtd']         ?? 0),
        ];
    }

    // ─────────────────────────────────────────
    // Alertas de banner
    // ─────────────────────────────────────────

    /**
     * Retorna parcelas vencidas para o banner vermelho (máx. 5).
     */
    public static function alertasVencidos(): array
    {
        $pdo  = Database::getInstance();
        $hoje = date('Y-m-d');

        $escopo = [];
        $paramsEscopo = [];
        self::aplicarEscopoVisibilidade($escopo, $paramsEscopo);
        $escopoSql = $escopo ? 'AND ' . implode(' AND ', $escopo) : '';

        $stmt = $pdo->prepare(
            "SELECT p.id, p.vencimento, p.valor, p.status,
                    pr.codigo AS proc_codigo, f.nome_fantasia AS fornecedor,
                    DATEDIFF(?, p.vencimento) AS dias_atraso
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN fornecedores f ON f.id = pr.fornecedor_id
             WHERE p.status IN ('vencido','agvenc')
               AND p.deletado_em IS NULL
               AND p.is_teste = 0
               {$escopoSql}
             ORDER BY p.vencimento ASC
             LIMIT 5"
        );
        $stmt->execute(array_merge([$hoje], $paramsEscopo));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna parcelas previstas vencendo nos próximos 5 dias sem agendamento (máx. 5).
     */
    public static function alertasProximos(): array
    {
        $pdo  = Database::getInstance();
        $hoje = date('Y-m-d');
        $d5   = date('Y-m-d', strtotime('+5 days'));

        $escopo = [];
        $paramsEscopo = [];
        self::aplicarEscopoVisibilidade($escopo, $paramsEscopo);
        $escopoSql = $escopo ? 'AND ' . implode(' AND ', $escopo) : '';

        $stmt = $pdo->prepare(
            "SELECT p.id, p.vencimento, p.valor,
                    pr.codigo AS proc_codigo, f.nome_fantasia AS fornecedor,
                    DATEDIFF(p.vencimento, ?) AS dias_restantes
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN fornecedores f ON f.id = pr.fornecedor_id
             WHERE p.status = 'previsto'
               AND p.vencimento BETWEEN ? AND ?
               AND p.deletado_em IS NULL
               AND p.is_teste = 0
               {$escopoSql}
             ORDER BY p.vencimento ASC
             LIMIT 5"
        );
        $stmt->execute(array_merge([$hoje, $hoje, $d5], $paramsEscopo));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────
    // Ordenação (cabeçalhos clicáveis das telas Financeiro e Pagamentos)
    // ─────────────────────────────────────────

    /** Chaves aceitas em `ordem` — usadas também para validar o GET no controller. */
    public const ORDENS = [
        'venc-asc', 'venc-desc',
        'val-asc', 'val-desc',
        'proc-asc', 'proc-desc',
        'nf-asc', 'nf-desc',
        'depto-asc', 'depto-desc',
    ];

    /**
     * Traduz a chave de ordenação em ORDER BY.
     *
     * @param bool $comSetor a consulta junta `setores s` (só a tela Financeiro)
     */
    private static function orderSql(string $ordem, bool $comSetor): string
    {
        $map = [
            'venc-asc'  => 'p.vencimento ASC',
            'venc-desc' => 'p.vencimento DESC',
            'val-asc'   => 'p.valor ASC',
            'val-desc'  => 'p.valor DESC',
            'proc-asc'  => 'pr.codigo ASC',
            'proc-desc' => 'pr.codigo DESC',
            'nf-asc'    => 'pr.numero_nf ASC',
            'nf-desc'   => 'pr.numero_nf DESC',
        ];
        if ($comSetor) {
            $map['depto-asc']  = 's.nome ASC';
            $map['depto-desc'] = 's.nome DESC';
        }
        return $map[$ordem] ?? 'p.vencimento ASC';
    }

    // ─────────────────────────────────────────
    // Listagem paginada
    // ─────────────────────────────────────────

    /**
     * Lista parcelas com filtros, aba e paginação.
     *
     * @param array  $filtros   busca, fornecedor_id, setor_id, periodo_inicio, periodo_fim, periodo_preset
     * @param string $aba       todos|vencido|previsto|agendado|pago|cancelado
     * @param string $ordem     uma das chaves de self::ORDENS
     * @param int    $offset
     * @param int    $limit
     * @param bool   $is_teste
     */
    public static function listar(
        array  $filtros  = [],
        string $aba      = 'todos',
        string $ordem    = 'venc-asc',
        int    $offset   = 0,
        int    $limit    = 20,
        bool   $is_teste = false
    ): array {
        $pdo = Database::getInstance();
        [$where, $params] = self::buildWhere($filtros, $is_teste, $aba);

        $orderSql = self::orderSql($ordem, true);

        $sql = "SELECT
                  p.id, p.processo_id, p.numero_parcela, p.total_parcelas,
                  p.vencimento, p.valor, p.status,
                  p.data_agendamento, p.data_pagamento, p.observacao, p.is_teste,
                  pr.codigo AS proc_codigo, pr.numero_nf,
                  pr.status AS proc_status,
                  pr.etapa_atual AS proc_etapa_atual,
                  (SELECT COUNT(*) FROM nf_processo_etapas WHERE processo_id = pr.id) AS proc_total_etapas,
                  eta.nome_etapa AS proc_etapa_nome,
                  CASE eta.tipo_aprovador
                    WHEN 'usuario' THEN (SELECT u.nome FROM usuarios u WHERE u.id = eta.aprovador_id LIMIT 1)
                    WHEN 'grupo'   THEN (SELECT g.nome FROM nf_grupos g WHERE g.id = eta.aprovador_id LIMIT 1)
                    ELSE NULL
                  END AS proc_responsavel_nome,
                  eta.tipo_aprovador AS proc_tipo_aprovador,
                  f.nome_fantasia AS fornecedor_nome, f.razao_social AS fornecedor_razao,
                  s.nome AS setor_nome,
                  pr.filial_id
                FROM nf_parcelas p
                JOIN nf_processos pr ON pr.id = p.processo_id
                LEFT JOIN nf_processo_etapas eta ON eta.processo_id = pr.id AND eta.numero_etapa = pr.etapa_atual
                LEFT JOIN fornecedores f ON f.id = pr.fornecedor_id
                LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
                LEFT JOIN setores s ON s.id = tv.setor_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderSql}
                LIMIT ? OFFSET ?";

        $paramsLista = array_merge($params, [$limit, $offset]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($paramsLista);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Total de parcelas para paginação (sem LIMIT).
     */
    public static function total(
        array  $filtros  = [],
        string $aba      = 'todos',
        bool   $is_teste = false
    ): int {
        $pdo = Database::getInstance();
        [$where, $params] = self::buildWhere($filtros, $is_teste, $aba);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN fornecedores f ON f.id = pr.fornecedor_id
             LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             LEFT JOIN setores s ON s.id = tv.setor_id
             WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    // ══════════════════════════════════════════════════════════════
    // TELA "PAGAMENTOS" (V2) — abas por etapa de fluxo "Pg Agendados"
    //   aberto     : não pago, não cancelado (visão geral)
    //   atraso     : vencido/agvenc (vencimento < hoje, não pago)
    //   andamento  : ainda não chegou na etapa "Pg Agendados"
    //   liberados  : etapa atual do processo é "Pg Agendados"
    //   pagos      : pagos no mês vigente
    // ══════════════════════════════════════════════════════════════

    /** Nome (padronizado) da etapa que libera o pagamento. */
    private const ETAPA_LIBERA_PAGAMENTO = 'Pagar';

    private static function buildWhereV2(array $filtros, bool $is_teste, string $aba = 'aberto'): array
    {
        $where  = ['p.deletado_em IS NULL'];
        $params = [];
        self::aplicarEscopoVisibilidade($where, $params);

        if (!$is_teste) {
            $where[] = 'p.is_teste = 0';
        }

        // Liberado = a etapa ATUAL do processo é a de pagamento ("Pagar")
        $liberado = "EXISTS (SELECT 1 FROM nf_processo_etapas e2
                             WHERE e2.processo_id = pr.id
                               AND e2.numero_etapa = pr.etapa_atual
                               AND e2.nome_etapa = " . self::etapaPagarSqlLiteral() . ")";

        switch ($aba) {
            case 'atraso':
                $where[] = "p.status IN ('vencido','agvenc') AND pr.status NOT IN ('cancelado','encerrado_apollo')";
                break;
            case 'andamento':
                $where[] = "p.status NOT IN ('pago','cancelado') AND pr.status NOT IN ('cancelado','encerrado_apollo') AND NOT {$liberado}";
                break;
            case 'liberados':
                $where[] = "p.status NOT IN ('pago','cancelado') AND pr.status NOT IN ('cancelado','encerrado_apollo') AND {$liberado}";
                break;
            case 'pagos':
                $where[] = "p.status = 'pago' AND p.data_pagamento IS NOT NULL
                            AND YEAR(p.data_pagamento) = YEAR(CURDATE())
                            AND MONTH(p.data_pagamento) = MONTH(CURDATE())";
                break;
            case 'aberto':
            default:
                $where[] = "p.status NOT IN ('pago','cancelado') AND pr.status NOT IN ('cancelado','encerrado_apollo')";
                break;
        }

        // Filtros comuns
        if (!empty($filtros['busca'])) {
            $like      = '%' . $filtros['busca'] . '%';
            $likeDigit = '%' . preg_replace('/[\.\-\/\s]/', '', $filtros['busca']) . '%';
            $where[]   = "(pr.codigo LIKE ? OR pr.numero_nf LIKE ? OR f.nome_fantasia LIKE ? OR f.razao_social LIKE ? OR f.cnpj LIKE ?)";
            $params    = array_merge($params, [$like, $like, $like, $like, $likeDigit]);
        }
        if (!empty($filtros['fornecedor_ids']) && is_array($filtros['fornecedor_ids'])) {
            $ph = implode(',', array_fill(0, count($filtros['fornecedor_ids']), '?'));
            $where[] = "pr.fornecedor_id IN ({$ph})";
            foreach ($filtros['fornecedor_ids'] as $fid) { $params[] = $fid; }
        }
        if (!empty($filtros['setor_ids']) && is_array($filtros['setor_ids'])) {
            $ph = implode(',', array_fill(0, count($filtros['setor_ids']), '?'));
            $where[] = "tv.setor_id IN ({$ph})";
            foreach ($filtros['setor_ids'] as $sid) { $params[] = $sid; }
        }
        if (!empty($filtros['proc_status']) && is_array($filtros['proc_status'])) {
            $ph = implode(',', array_fill(0, count($filtros['proc_status']), '?'));
            $where[] = "pr.status IN ({$ph})";
            foreach ($filtros['proc_status'] as $ps) { $params[] = $ps; }
        }
        // Responsável da etapa ATUAL — mesma semântica de buildWhere()
        if (!empty($filtros['resp_ids']) && is_array($filtros['resp_ids'])) {
            $conds = [];
            foreach ($filtros['resp_ids'] as $r) {
                $conds[]  = "(ea.tipo_aprovador = ? AND ea.aprovador_id = ?)";
                $params[] = $r['tipo'];
                $params[] = $r['id'];
            }
            $where[] = "pr.status IN ('aberto','devolvido','cancelamento_solicitado')";
            $where[] = "EXISTS (SELECT 1 FROM nf_processo_etapas ea
                                WHERE ea.processo_id = pr.id
                                  AND ea.numero_etapa = pr.etapa_atual
                                  AND (" . implode(' OR ', $conds) . "))";
        }
        // Uma loja (trava antiga) ou várias (filtro multi-loja da tela).
        $fIds = array_values(array_unique(array_filter(
            array_map(static fn($v): string => trim((string) $v), (array) ($filtros['filial_id'] ?? [])),
            static fn(string $v): bool => $v !== ''
        )));
        if ($fIds !== []) {
            $where[] = 'pr.filial_id IN (' . implode(',', array_fill(0, count($fIds), '?')) . ')';
            $params  = array_merge($params, $fIds);
        }
        if (!empty($filtros['periodo_inicio'])) { $where[] = 'p.vencimento >= ?'; $params[] = $filtros['periodo_inicio']; }
        if (!empty($filtros['periodo_fim']))    { $where[] = 'p.vencimento <= ?'; $params[] = $filtros['periodo_fim']; }

        return [$where, $params];
    }

    /** Literal SQL seguro (aspas simples escapadas) do nome da etapa "Pagar". */
    private static function etapaPagarSqlLiteral(): string
    {
        return "'" . str_replace("'", "''", self::ETAPA_LIBERA_PAGAMENTO) . "'";
    }

    public static function listarV2(
        array $filtros, string $aba, string $ordem, int $offset, int $limit, bool $is_teste = false
    ): array {
        $pdo = Database::getInstance();
        [$where, $params] = self::buildWhereV2($filtros, $is_teste, $aba);

        // A consulta V2 não junta `setores` — sem coluna Depto, sem ordenação por ela.
        $orderSql = self::orderSql($ordem, false);

        $sql = "SELECT
                  p.id, p.processo_id, p.numero_parcela, p.total_parcelas,
                  p.vencimento, p.valor, p.status,
                  p.data_agendamento, p.data_pagamento, p.observacao, p.is_teste,
                  pr.codigo AS proc_codigo, pr.numero_nf,
                  CASE WHEN JSON_VALID(pr.dados_formulario)
                       THEN JSON_UNQUOTE(JSON_EXTRACT(pr.dados_formulario, '$.num_nota'))
                       ELSE NULL END AS num_nota_servico,
                  pr.status AS proc_status,
                  pr.etapa_atual AS proc_etapa_atual,
                  eta.nome_etapa AS proc_etapa_nome,
                  CASE eta.tipo_aprovador
                    WHEN 'usuario' THEN (SELECT u.nome FROM usuarios u WHERE u.id = eta.aprovador_id LIMIT 1)
                    WHEN 'grupo'   THEN (SELECT g.nome FROM nf_grupos g WHERE g.id = eta.aprovador_id LIMIT 1)
                    ELSE NULL
                  END AS proc_responsavel_nome,
                  eta.tipo_aprovador AS proc_tipo_aprovador,
                  f.nome_fantasia AS fornecedor_nome, f.razao_social AS fornecedor_razao,
                  pr.filial_id
                FROM nf_parcelas p
                JOIN nf_processos pr ON pr.id = p.processo_id
                LEFT JOIN nf_processo_etapas eta ON eta.processo_id = pr.id AND eta.numero_etapa = pr.etapa_atual
                LEFT JOIN fornecedores f ON f.id = pr.fornecedor_id
                LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderSql}
                LIMIT ? OFFSET ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [$limit, $offset]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function totalV2(array $filtros, string $aba, bool $is_teste = false): int
    {
        $pdo = Database::getInstance();
        [$where, $params] = self::buildWhereV2($filtros, $is_teste, $aba);
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN fornecedores f ON f.id = pr.fornecedor_id
             LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** Contagens das 5 abas da tela Pagamentos. */
    public static function contagensV2(array $filtros, bool $is_teste = false): array
    {
        $out = [];
        foreach (['aberto', 'atraso', 'andamento', 'liberados', 'pagos'] as $aba) {
            $out[$aba] = self::totalV2($filtros, $aba, $is_teste);
        }
        return $out;
    }

    /**
     * Pagamento pela tela Pagamentos: aceita qualquer parcela não paga/cancelada
     * (a liberação é dada pela etapa "Pg Agendados", não pelo status da parcela).
     */
    public static function pagarV2(string $id, string $dataPagamento, string $obs, array $usuario, string $comprovante = ''): bool
    {
        $pdo     = Database::getInstance();
        $parcela = self::findById($id);
        if (!$parcela || in_array($parcela['status'], ['pago', 'cancelado'], true)) {
            return false;
        }

        $pdo->prepare(
            "UPDATE nf_parcelas
             SET status = 'pago', data_pagamento = ?, observacao = ?, numero_comprovante = ?, atualizado_em = NOW()
             WHERE id = ? AND deletado_em IS NULL"
        )->execute([$dataPagamento, $obs ?: $parcela['observacao'], $comprovante ?: null, $id]);

        self::registrarHistorico($id, 'status', $parcela['status'], 'pago', $usuario);
        self::registrarHistorico($id, 'data_pagamento', $parcela['data_pagamento'] ?? '', $dataPagamento, $usuario);
        if ($comprovante) {
            self::registrarHistorico($id, 'numero_comprovante', $parcela['numero_comprovante'] ?? '', $comprovante, $usuario);
        }
        if ($obs) {
            self::registrarHistorico($id, 'observacao', $parcela['observacao'] ?? '', $obs, $usuario);
        }
        return true;
    }

    public static function pagarLoteV2(array $ids, string $dataPagamento, string $obs, array $usuario, string $comprovante = ''): int
    {
        $n = 0;
        foreach ($ids as $id) {
            if (self::pagarV2($id, $dataPagamento, $obs, $usuario, $comprovante)) { $n++; }
        }
        return $n;
    }

    // ─────────────────────────────────────────
    // Detalhe de uma parcela
    // ─────────────────────────────────────────

    public static function findById(string $id): ?array
    {
        $pdo  = Database::getInstance();

        // Detalhe de parcela também respeita sigilo/escopo: sem isso o id na
        // URL abriria a parcela de um processo de RH ou de outra filial.
        $escopo = [];
        $paramsEscopo = [];
        self::aplicarEscopoVisibilidade($escopo, $paramsEscopo);
        $escopoSql = $escopo ? 'AND ' . implode(' AND ', $escopo) : '';

        $stmt = $pdo->prepare(
            "SELECT
               p.id, p.processo_id, p.numero_parcela, p.total_parcelas,
               p.vencimento, p.valor, p.status,
               p.data_agendamento, p.data_pagamento, p.observacao, p.is_teste,
               pr.codigo AS proc_codigo, pr.numero_nf, pr.filial_id, tv.setor_id,
               f.nome_fantasia AS fornecedor_nome, f.razao_social AS fornecedor_razao,
               s.nome AS setor_nome
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN fornecedores f ON f.id = pr.fornecedor_id
             LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             LEFT JOIN setores s ON s.id = tv.setor_id
             WHERE p.id = ? AND p.deletado_em IS NULL {$escopoSql}"
        );
        $stmt->execute(array_merge([$id], $paramsEscopo));
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    // ─────────────────────────────────────────
    // Ações individuais
    // ─────────────────────────────────────────

    /**
     * Agenda uma parcela: status → agendado, define data_agendamento.
     */
    public static function agendar(string $id, string $dataAgendamento, string $obs, array $usuario): bool
    {
        $pdo    = Database::getInstance();
        $parcela = self::findById($id);
        if (!$parcela || !in_array($parcela['status'], ['previsto', 'vencido'], true)) {
            return false;
        }

        $pdo->prepare(
            "UPDATE nf_parcelas
             SET status = 'agendado', data_agendamento = ?, observacao = ?,
                 atualizado_em = NOW()
             WHERE id = ?"
        )->execute([$dataAgendamento, $obs ?: $parcela['observacao'], $id]);

        self::registrarHistorico($id, 'status', $parcela['status'], 'agendado', $usuario);
        self::registrarHistorico($id, 'data_agendamento', $parcela['data_agendamento'] ?? '', $dataAgendamento, $usuario);
        if ($obs) {
            self::registrarHistorico($id, 'observacao', $parcela['observacao'] ?? '', $obs, $usuario);
        }

        return true;
    }

    /**
     * Registra o pagamento de uma parcela: status → pago, define data_pagamento.
     */
    public static function pagar(string $id, string $dataPagamento, string $obs, array $usuario, string $comprovante = ''): bool
    {
        $pdo    = Database::getInstance();
        $parcela = self::findById($id);
        if (!$parcela || !in_array($parcela['status'], ['agendado', 'agvenc'], true)) {
            return false;
        }

        $pdo->prepare(
            "UPDATE nf_parcelas
             SET status = 'pago', data_pagamento = ?, observacao = ?,
                 numero_comprovante = ?,
                 atualizado_em = NOW()
             WHERE id = ?"
        )->execute([$dataPagamento, $obs ?: $parcela['observacao'], $comprovante ?: null, $id]);

        self::registrarHistorico($id, 'status', $parcela['status'], 'pago', $usuario);
        self::registrarHistorico($id, 'data_pagamento', $parcela['data_pagamento'] ?? '', $dataPagamento, $usuario);
        if ($comprovante) {
            self::registrarHistorico($id, 'numero_comprovante', $parcela['numero_comprovante'] ?? '', $comprovante, $usuario);
        }
        if ($obs) {
            self::registrarHistorico($id, 'observacao', $parcela['observacao'] ?? '', $obs, $usuario);
        }

        return true;
    }

    // ─────────────────────────────────────────
    // Ação em lote
    // ─────────────────────────────────────────

    /**
     * Agenda múltiplas parcelas de uma vez.
     *
     * @param string[] $ids
     */
    public static function agendarLote(array $ids, string $dataAgendamento, string $obs, array $usuario): int
    {
        $ok = 0;
        foreach ($ids as $id) {
            if (self::agendar((string)$id, $dataAgendamento, $obs, $usuario)) {
                $ok++;
            }
        }
        return $ok;
    }

    /**
     * Paga múltiplas parcelas de uma vez.
     *
     * @param string[] $ids
     */
    public static function pagarLote(array $ids, string $dataPagamento, string $obs, array $usuario, string $comprovante = ''): int
    {
        $ok = 0;
        foreach ($ids as $id) {
            if (self::pagar((string)$id, $dataPagamento, $obs, $usuario, $comprovante)) {
                $ok++;
            }
        }
        return $ok;
    }

    // ─────────────────────────────────────────
    // Dados para dropdowns de filtro
    // ─────────────────────────────────────────

    public static function fornecedores(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            "SELECT DISTINCT f.id, f.nome_fantasia
             FROM fornecedores f
             JOIN nf_processos pr ON pr.fornecedor_id = f.id
             JOIN nf_parcelas p ON p.processo_id = pr.id
             WHERE p.deletado_em IS NULL
             ORDER BY f.nome_fantasia"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function setores(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            "SELECT DISTINCT s.id, s.nome
             FROM setores s
             JOIN nf_template_versoes tv ON tv.setor_id = s.id
             JOIN nf_processos pr ON pr.template_versao_id = tv.id
             JOIN nf_parcelas p ON p.processo_id = pr.id
             WHERE p.deletado_em IS NULL
             ORDER BY s.nome"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Responsáveis (grupos e usuários) que aparecem como aprovador da etapa
     * ATUAL de algum processo com parcela — opções do filtro "Responsável".
     * Valor codificado como "grupo:<uuid>" | "usuario:<uuid>".
     */
    public static function responsaveis(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            "SELECT DISTINCT eta.tipo_aprovador AS tipo, eta.aprovador_id AS id,
                    CASE eta.tipo_aprovador
                      WHEN 'usuario' THEN (SELECT u.nome FROM usuarios u WHERE u.id = eta.aprovador_id LIMIT 1)
                      WHEN 'grupo'   THEN (SELECT g.nome FROM nf_grupos g WHERE g.id = eta.aprovador_id LIMIT 1)
                    END AS nome
             FROM nf_processo_etapas eta
             JOIN nf_processos pr ON pr.id = eta.processo_id AND pr.etapa_atual = eta.numero_etapa
             JOIN nf_parcelas p ON p.processo_id = pr.id
             WHERE p.deletado_em IS NULL
               AND pr.status IN ('aberto','devolvido','cancelamento_solicitado')
               AND eta.tipo_aprovador IN ('usuario', 'grupo')
               AND eta.aprovador_id IS NOT NULL
             HAVING nome IS NOT NULL
             ORDER BY nome"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────
    // Exportação
    // ─────────────────────────────────────────

    /**
     * Retorna todas as parcelas do recorte (sem paginação) para exportação.
     */
    public static function listarTodos(
        array  $filtros  = [],
        string $aba      = 'todos',
        string $ordem    = 'venc-asc',
        bool   $is_teste = false
    ): array {
        $pdo = Database::getInstance();
        [$where, $params] = self::buildWhere($filtros, $is_teste, $aba);

        $orderSql = self::orderSql($ordem, true);

        $stmt = $pdo->prepare(
            "SELECT
               p.id, p.numero_parcela, p.total_parcelas,
               p.vencimento, p.valor, p.status,
               p.data_agendamento, p.data_pagamento, p.observacao, p.is_teste,
               pr.codigo AS proc_codigo, pr.numero_nf,
               pr.status AS proc_status,
               pr.etapa_atual AS proc_etapa_atual,
               (SELECT COUNT(*) FROM nf_processo_etapas WHERE processo_id = pr.id) AS proc_total_etapas,
               eta.nome_etapa AS proc_etapa_nome,
               CASE eta.tipo_aprovador
                 WHEN 'usuario' THEN (SELECT u.nome FROM usuarios u WHERE u.id = eta.aprovador_id LIMIT 1)
                 WHEN 'grupo'   THEN (SELECT g.nome FROM nf_grupos g WHERE g.id = eta.aprovador_id LIMIT 1)
                 ELSE NULL
               END AS proc_responsavel_nome,
               eta.tipo_aprovador AS proc_tipo_aprovador,
               f.nome_fantasia AS fornecedor_nome,
               s.nome AS setor_nome
             FROM nf_parcelas p
             JOIN nf_processos pr ON pr.id = p.processo_id
             LEFT JOIN nf_processo_etapas eta ON eta.processo_id = pr.id AND eta.numero_etapa = pr.etapa_atual
             LEFT JOIN fornecedores f ON f.id = pr.fornecedor_id
             LEFT JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             LEFT JOIN setores s ON s.id = tv.setor_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY {$orderSql}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────
    // Helpers internos
    // ─────────────────────────────────────────

    /**
     * Monta cláusulas WHERE e array de parâmetros PDO.
     *
     * @return array{0: string[], 1: array}
     */
    /**
     * Recortes de visibilidade do usuário logado aplicados às parcelas:
     * sigilo por template (RH/Jurídico) e escopo por filial do processo.
     * Fora do contexto web (CLI) não muda nada.
     *
     * @param list<string> $where
     * @param list<mixed>  $params
     */
    private static function aplicarEscopoVisibilidade(array &$where, array &$params, string $aliasProcesso = 'pr'): void
    {
        // Contexto sem os helpers carregados (CLI enxuto: brobot, SLA): sem
        // recorte — esses jobs precisam varrer tudo e não têm sessão.
        if (!class_exists('TemplateSigilo') || !class_exists('FilialScope') || !class_exists('ProcessAccess')) {
            return;
        }

        [$sigilo, $pSig] = TemplateSigilo::sqlExcluiProcessosSessao($aliasProcesso);
        if ($sigilo !== '') {
            $where[] = $sigilo;
            $params  = array_merge($params, $pSig);
        }

        [$filial, $pFil] = FilialScope::sqlRestringeSessao("{$aliasProcesso}.filial_doc");
        if ($filial !== '') {
            $where[] = $filial;
            $params  = array_merge($params, $pFil);
        }

        $participa = ProcessAccess::sqlParticipaSessao($aliasProcesso);
        if ($participa !== '') {
            $where[] = $participa;
        }
    }

    private static function buildWhere(array $filtros, bool $is_teste, string $aba = 'todos'): array
    {
        $where  = ['p.deletado_em IS NULL'];
        $params = [];
        self::aplicarEscopoVisibilidade($where, $params);

        // Filtro de teste
        if (!$is_teste) {
            $where[]  = 'p.is_teste = 0';
        }

        // Aba
        switch ($aba) {
            case 'todos':
                $where[] = "p.status NOT IN ('pago','cancelado')";
                break;
            case 'vencido':
                $where[] = "p.status IN ('vencido','agvenc')";
                break;
            case 'previsto':
                $where[] = "p.status = 'previsto'";
                break;
            case 'agendado':
                $where[] = "p.status IN ('agendado','agvenc')";
                break;
            case 'pago':
                $where[] = "p.status = 'pago'";
                break;
            case 'cancelado':
                $where[] = "p.status = 'cancelado'";
                break;
        }

        // Parcela de processo cancelado/encerrado não é trabalho pendente de
        // ninguém — só aparece nas abas terminais (pago/cancelado), onde o
        // usuário foi buscá-la de propósito.
        if (!in_array($aba, ['pago', 'cancelado'], true)) {
            $where[] = "pr.status NOT IN ('cancelado','encerrado_apollo')";
        }

        // Busca livre
        if (!empty($filtros['busca'])) {
            $like      = '%' . $filtros['busca'] . '%';
            $likeDigit = '%' . preg_replace('/[\.\-\/\s]/', '', $filtros['busca']) . '%';
            $where[]   = "(pr.codigo LIKE ? OR pr.numero_nf LIKE ? OR f.nome_fantasia LIKE ? OR f.razao_social LIKE ? OR f.cnpj LIKE ?)";
            $params    = array_merge($params, [$like, $like, $like, $like, $likeDigit]);
        }

        // Fornecedor (array de UUIDs via multi-select)
        if (!empty($filtros['fornecedor_ids']) && is_array($filtros['fornecedor_ids'])) {
            $placeholders = implode(',', array_fill(0, count($filtros['fornecedor_ids']), '?'));
            $where[]      = "pr.fornecedor_id IN ({$placeholders})";
            foreach ($filtros['fornecedor_ids'] as $fid) {
                $params[] = $fid;
            }
        }

        // Setor / Departamento (array de UUIDs via multi-select)
        if (!empty($filtros['setor_ids']) && is_array($filtros['setor_ids'])) {
            $placeholders = implode(',', array_fill(0, count($filtros['setor_ids']), '?'));
            $where[]      = "tv.setor_id IN ({$placeholders})";
            foreach ($filtros['setor_ids'] as $sid) {
                $params[] = $sid;
            }
        }

        // Status do processo (array de valores fixos via multi-select)
        if (!empty($filtros['proc_status']) && is_array($filtros['proc_status'])) {
            $placeholders = implode(',', array_fill(0, count($filtros['proc_status']), '?'));
            $where[]      = "pr.status IN ({$placeholders})";
            foreach ($filtros['proc_status'] as $ps) {
                $params[] = $ps;
            }
        }

        // Responsável da etapa ATUAL (multi-select; itens ['tipo'=>..., 'id'=>uuid]).
        // Mesma semântica da coluna na tela: só processos que ESTÃO na mão de
        // alguém (aberto/devolvido/canc. solicitado) — finalizado não tem
        // responsável atual. EXISTS para não depender do JOIN com etapas.
        if (!empty($filtros['resp_ids']) && is_array($filtros['resp_ids'])) {
            $conds = [];
            foreach ($filtros['resp_ids'] as $r) {
                $conds[]  = "(ea.tipo_aprovador = ? AND ea.aprovador_id = ?)";
                $params[] = $r['tipo'];
                $params[] = $r['id'];
            }
            $where[] = "pr.status IN ('aberto','devolvido','cancelamento_solicitado')";
            $where[] = "EXISTS (SELECT 1 FROM nf_processo_etapas ea
                                WHERE ea.processo_id = pr.id
                                  AND ea.numero_etapa = pr.etapa_atual
                                  AND (" . implode(' OR ', $conds) . "))";
        }

        // Filial (scoping multi-tenant)
        // Uma loja (trava antiga) ou várias (filtro multi-loja da tela).
        $fIds = array_values(array_unique(array_filter(
            array_map(static fn($v): string => trim((string) $v), (array) ($filtros['filial_id'] ?? [])),
            static fn(string $v): bool => $v !== ''
        )));
        if ($fIds !== []) {
            $where[] = 'pr.filial_id IN (' . implode(',', array_fill(0, count($fIds), '?')) . ')';
            $params  = array_merge($params, $fIds);
        }

        // Período
        if (!empty($filtros['periodo_inicio'])) {
            $where[]  = 'p.vencimento >= ?';
            $params[] = $filtros['periodo_inicio'];
        }
        if (!empty($filtros['periodo_fim'])) {
            $where[]  = 'p.vencimento <= ?';
            $params[] = $filtros['periodo_fim'];
        }

        return [$where, $params];
    }

    /**
     * Insere registro no histórico de alterações da parcela.
     */
    private static function registrarHistorico(
        string $parcelaId,
        string $campo,
        ?string $anterior,
        ?string $novo,
        array $usuario
    ): void {
        $pdo = Database::getInstance();
        $pdo->prepare(
            "INSERT INTO nf_parcela_historico
               (id, parcela_id, campo_alterado, valor_anterior, valor_novo,
                alterado_por, alterado_por_nome, alterado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            self::uuid(),
            $parcelaId,
            $campo,
            $anterior,
            $novo,
            $usuario['id'],
            $usuario['nome'],
        ]);
    }
}
