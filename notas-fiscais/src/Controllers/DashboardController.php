<?php
// @versao Dash_02 V_03_12/08/2026
declare(strict_types=1);

/**
 * Controller do módulo Dashboard.
 *
 * Roteamento: ?module=dashboard&action=X
 *
 * Actions:
 *   index   — Dashboard gerencial com 5 abas
 */

Auth::check();

// Permissão granular por aba — cada aba do Dashboard tem sua própria ação
// ('dashboard.ver_visao_geral', 'dashboard.ver_meus_processos' etc.) ao invés
// de um único 'ver' que liberava (ou bloqueava) o módulo inteiro de uma vez.
// Isso existe pra dar, por exemplo, a compradores/solicitantes acesso só à
// aba "Meus Processos" (dados do próprio usuário), sem expor as abas
// agregadas/gerenciais (Financeiro, Aprovações, Desempenho...).
$abaPermissao = [
    'visao-geral'    => 'ver_visao_geral',
    'meus-processos' => 'ver_meus_processos',
    'processos'      => 'ver_processos',
    'aprovacoes'     => 'ver_aprovacoes',
    'financeiro'     => 'ver_financeiro',
    'desempenho'     => 'ver_desempenho',
];
$abasPermitidas = array_keys(array_filter(
    $abaPermissao,
    fn(string $acao): bool => Auth::can('dashboard', $acao)
));

if (empty($abasPermitidas)) {
    http_response_code(403);
    include VIEWS . '/errors/403.php';
    exit;
}

require_once SRC . '/Models/DashboardMetrics.php';
require_once SRC . '/Models/Process.php';

// Lê a página (>=1) de um parâmetro GET específico — cada lista paginada do
// dashboard tem o seu próprio nome de parâmetro (ex: vg_page, pr_atrasados_page),
// nunca um "page" genérico, pra evitar que abas/listas diferentes colidam
// quando convivem na mesma tela ou quando a URL é preservada ao trocar de aba.
function dashPagina(string $param): int
{
    return max(1, (int) (Sanitize::fromGet([$param])[$param] ?: 1));
}

$currentUser = Auth::user();
$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

if ($action === 'index') {
    $abaRequisitada = Sanitize::fromGet(['aba'])['aba'];
    // Aba inválida OU sem permissão pra essa aba específica cai na primeira
    // aba (na ordem acima) que o usuário efetivamente tem acesso.
    $aba = in_array($abaRequisitada, $abasPermitidas, true) ? $abaRequisitada : $abasPermitidas[0];

    // ─── Filtros comuns (sanitizados) ───────────────────────────────
    $filtros = [];

    $busca = trim(Sanitize::fromGet(['busca'])['busca']);
    if ($busca !== '') $filtros['busca'] = $busca;

    // Período — cada aba tem seu PRÓPRIO parâmetro de período (ex: mp_periodo_preset,
    // vg_periodo_preset...), nunca um nome genérico compartilhado. Isso é proposital:
    // um nome compartilhado pode chegar na querystring por caminhos que nada têm a ver
    // com uma escolha do usuário NESSA aba — troca de aba preservando querystring, o
    // botão "voltar" da tela de processo (que grava a URL completa de onde veio), um
    // link salvo, etc. Com nome exclusivo por aba, um período só aparece selecionado
    // se o usuário literalmente escolheu isso dentro daquela aba especificamente.
    // Padrão sempre "todos" (sem recorte) — Ano/Mês foram descontinuados, cada aba
    // usa só os presets (semana/mês/trimestre) + período personalizado.
    $sufixosAba = [
        'visao-geral' => 'vg', 'meus-processos' => 'mp', 'processos' => 'pr',
        'aprovacoes' => 'ap', 'financeiro' => 'fi', 'desempenho' => 'de',
    ];
    $sufixoAba = $sufixosAba[$aba];

    $periodoPreset = Sanitize::fromGet(["{$sufixoAba}_periodo_preset"])["{$sufixoAba}_periodo_preset"];
    $presetValidos = ['todos', 'esta-semana', 'este-mes', 'este-trimestre'];
    $filtros['periodo_preset'] = in_array($periodoPreset, $presetValidos, true) ? $periodoPreset : 'todos';

    $periodoInicio = Sanitize::fromGet(["{$sufixoAba}_periodo_inicio"])["{$sufixoAba}_periodo_inicio"];
    $periodoFim    = Sanitize::fromGet(["{$sufixoAba}_periodo_fim"])["{$sufixoAba}_periodo_fim"];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodoInicio)) {
        $filtros['periodo_inicio'] = $periodoInicio;
        $filtros['periodo_preset'] = 'personalizado';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodoFim)) {
        $filtros['periodo_fim'] = $periodoFim;
    }

    $periodoPreset = $filtros['periodo_preset'];

    // Filtros específicos por aba
    $status     = Sanitize::fromGet(['status'])['status'];
    $tipoNotaId = Sanitize::fromGet(['tipo_nota_id'])['tipo_nota_id'];
    // Lojas: multi-seleção (`filial_id[]`), já recortada pelo escopo do usuário.
    // Fora do fromGet, que só trata escalar; link antigo com um id só continua valendo.
    $filiaisSel = FilialScope::idsDoFiltro($_GET['filial_id'] ?? null);
    $responsavelId = Sanitize::fromGet(['responsavel_id'])['responsavel_id'];
    $grupoId    = Sanitize::fromGet(['grupo_id'])['grupo_id'];
    $situacaoFin = Sanitize::fromGet(['situacao'])['situacao'];
    $ordem      = Sanitize::fromGet(['ordem'])['ordem'];

    if ($status !== '')        $filtros['status']         = $status;
    if ($tipoNotaId !== '')    { try { $filtros['tipo_nota_id']  = Sanitize::uuid($tipoNotaId);  } catch (\InvalidArgumentException) {} }
    if ($filiaisSel !== [])    $filtros['filial_id']      = $filiaisSel;
    if ($responsavelId !== '') { try { $filtros['responsavel_id']= Sanitize::uuid($responsavelId); } catch (\InvalidArgumentException) {} }
    if ($grupoId !== '')       { try { $filtros['grupo_id']      = Sanitize::uuid($grupoId);     } catch (\InvalidArgumentException) {} }
    if ($situacaoFin !== '')   $filtros['situacao']       = $situacaoFin;
    if ($ordem !== '')         $filtros['ordem']          = $ordem;

    // Status da tabela "Meus Processos" — multi-seleção via checkbox. Por padrão
    // (sem o parâmetro na URL) oculta aprovados e cancelados da TABELA; os KPIs
    // não são afetados. Uma vez que o usuário filtra (mesmo limpando tudo), o
    // parâmetro passa a vir sempre preenchido pelo hidden input via JS.
    $statusMpValidos = ['aberto', 'devolvido', 'cancelamento_solicitado', 'aprovado', 'cancelado', 'encerrado_apollo'];
    if (array_key_exists('mp_status', $_GET)) {
        $mpStatusRaw = Sanitize::fromGet(['mp_status'])['mp_status'];
        $filtros['mp_status'] = array_values(array_intersect($statusMpValidos, array_filter(explode(',', $mpStatusRaw))));
    } else {
        $filtros['mp_status'] = ['aberto', 'devolvido', 'cancelamento_solicitado'];
    }

    // Responsável ATUAL da tabela "Meus Processos" — pessoa ou grupo com a etapa
    // em mãos agora. Valor no formato "usuario:<uuid>" ou "grupo:<uuid>".
    if (!empty($_GET['mp_resp'])) {
        $mpRespRaw = Sanitize::fromGet(['mp_resp'])['mp_resp'];
        $mpRespPartes = explode(':', $mpRespRaw, 2);
        if (count($mpRespPartes) === 2 && in_array($mpRespPartes[0], ['usuario', 'grupo'], true)) {
            try {
                $filtros['mp_resp_tipo'] = $mpRespPartes[0];
                $filtros['mp_resp_id']   = Sanitize::uuid($mpRespPartes[1]);
            } catch (\InvalidArgumentException) {}
        }
    }

    // ─── Dados por aba ───────────────────────────────────────────────
    $dados = [];

    switch ($aba) {
        case 'visao-geral':
            $paginaVg = dashPagina('vg_page');
            $offsetVg = ($paginaVg - 1) * ITEMS_PER_PAGE;

            $dados['kpis']      = DashboardMetrics::kpisVisaoGeral($filtros);
            $dados['processos'] = DashboardMetrics::processosEmAndamento($filtros, $offsetVg, ITEMS_PER_PAGE);
            $dados['total']         = DashboardMetrics::contarProcessosEmAndamento($filtros);
            $dados['pagina']        = $paginaVg;
            $dados['total_paginas'] = (int) ceil($dados['total'] / ITEMS_PER_PAGE);
            break;

        case 'meus-processos':
            $paginaMp = dashPagina('mp_page');
            $offsetMp = ($paginaMp - 1) * ITEMS_PER_PAGE;

            $dados['kpis']  = DashboardMetrics::kpisMeusProcessos($currentUser['id'], $filtros);
            $dados['lista'] = DashboardMetrics::listarMeusProcessos($currentUser['id'], $filtros, $offsetMp, ITEMS_PER_PAGE);
            $dados['responsaveis_atuais'] = DashboardMetrics::responsaveisAtuaisMeusProcessos($currentUser['id']);
            $dados['total']         = DashboardMetrics::contarMeusProcessos($currentUser['id'], $filtros);
            $dados['pagina']        = $paginaMp;
            $dados['total_paginas'] = (int) ceil($dados['total'] / ITEMS_PER_PAGE);
            break;

        case 'processos':
            $paginaPrAtrasados = dashPagina('pr_atrasados_page');
            $paginaPrParados   = dashPagina('pr_parados_page');
            $paginaPrTodos     = dashPagina('pr_todos_page');

            $dados['contagens']  = DashboardMetrics::contagensPorEtapa($filtros);

            $dados['atrasados']       = DashboardMetrics::processosAtrasados($filtros, ($paginaPrAtrasados - 1) * ITEMS_PER_PAGE, ITEMS_PER_PAGE);
            $dados['atrasados_total'] = DashboardMetrics::contarProcessosAtrasados($filtros);
            $dados['atrasados_pagina']= $paginaPrAtrasados;
            $dados['atrasados_total_paginas'] = (int) ceil($dados['atrasados_total'] / ITEMS_PER_PAGE);

            $dados['parados']       = DashboardMetrics::processosParados($filtros, ($paginaPrParados - 1) * ITEMS_PER_PAGE, ITEMS_PER_PAGE);
            $dados['parados_total'] = DashboardMetrics::contarProcessosParados($filtros);
            $dados['parados_pagina']= $paginaPrParados;
            $dados['parados_total_paginas'] = (int) ceil($dados['parados_total'] / ITEMS_PER_PAGE);

            $dados['todos']       = DashboardMetrics::processosEmAndamento($filtros, ($paginaPrTodos - 1) * ITEMS_PER_PAGE, ITEMS_PER_PAGE);
            $dados['todos_total'] = DashboardMetrics::contarProcessosEmAndamento($filtros);
            $dados['todos_pagina']= $paginaPrTodos;
            $dados['todos_total_paginas'] = (int) ceil($dados['todos_total'] / ITEMS_PER_PAGE);
            break;

        case 'aprovacoes':
            $paginaApFila       = dashPagina('ap_fila_page');
            $paginaApFilaGrupo  = dashPagina('ap_filagrupo_page');
            $paginaApDevolvidos = dashPagina('ap_devolvidos_page');

            $dados['kpis'] = DashboardMetrics::kpisAprovacoes($filtros);

            $dados['fila']       = DashboardMetrics::filaPorAprovador($filtros, ($paginaApFila - 1) * ITEMS_PER_PAGE, ITEMS_PER_PAGE);
            $dados['fila_total'] = DashboardMetrics::contarFilaPorAprovador($filtros);
            $dados['fila_pagina']= $paginaApFila;
            $dados['fila_total_paginas'] = (int) ceil($dados['fila_total'] / ITEMS_PER_PAGE);

            $dados['fila_grupos']       = DashboardMetrics::filaPorGrupo($filtros, ($paginaApFilaGrupo - 1) * ITEMS_PER_PAGE, ITEMS_PER_PAGE);
            $dados['fila_grupos_total'] = DashboardMetrics::contarFilaPorGrupo($filtros);
            $dados['fila_grupos_pagina']= $paginaApFilaGrupo;
            $dados['fila_grupos_total_paginas'] = (int) ceil($dados['fila_grupos_total'] / ITEMS_PER_PAGE);

            $dados['devolvidos']       = DashboardMetrics::processosDevolvidos($filtros, ($paginaApDevolvidos - 1) * ITEMS_PER_PAGE, ITEMS_PER_PAGE);
            $dados['devolvidos_total'] = DashboardMetrics::contarProcessosDevolvidos($filtros);
            $dados['devolvidos_pagina']= $paginaApDevolvidos;
            $dados['devolvidos_total_paginas'] = (int) ceil($dados['devolvidos_total'] / ITEMS_PER_PAGE);

            $dados['sla'] = DashboardMetrics::slaPorEtapa($filtros);
            break;

        case 'financeiro':
            $paginaFi = dashPagina('fi_page');

            $dados['kpis']           = DashboardMetrics::kpisFinanceiro($filtros);
            $dados['pendentes']       = DashboardMetrics::pendentesPagamento($filtros, ($paginaFi - 1) * ITEMS_PER_PAGE, ITEMS_PER_PAGE);
            $dados['pendentes_total'] = DashboardMetrics::contarPendentesPagamento($filtros);
            $dados['pendentes_pagina']= $paginaFi;
            $dados['pendentes_total_paginas'] = (int) ceil($dados['pendentes_total'] / ITEMS_PER_PAGE);
            $dados['por_loja']       = DashboardMetrics::volumePorLoja($filtros);
            $dados['por_setor']      = DashboardMetrics::volumePorSetor($filtros);
            $dados['por_departamento'] = DashboardMetrics::volumePorDepartamento($filtros);
            break;

        case 'desempenho':
            $paginaDe = dashPagina('de_page');

            $dados['kpis']       = DashboardMetrics::kpisDesempenho($filtros);
            $dados['por_etapa']  = DashboardMetrics::tempoPorEtapa($filtros);
            $dados['ranking']       = DashboardMetrics::rankingUsuarios($filtros, ($paginaDe - 1) * ITEMS_PER_PAGE, ITEMS_PER_PAGE);
            $dados['ranking_total'] = DashboardMetrics::contarRankingUsuarios($filtros);
            $dados['ranking_pagina']= $paginaDe;
            $dados['ranking_total_paginas'] = (int) ceil($dados['ranking_total'] / ITEMS_PER_PAGE);
            $dados['comparativo']= DashboardMetrics::comparativoMensal();
            break;
    }

    // Dados para dropdowns de filtro
    $dropdowns = [
        'filiais'      => DashboardMetrics::filiais(),
        'setores'      => DashboardMetrics::setores(),
        'tipos_nota'   => DashboardMetrics::tiposNota(),
        'grupos'       => DashboardMetrics::grupos(),
        'responsaveis' => DashboardMetrics::responsaveis(),
    ];

    // Badges do tab-nav — só computados/exibidos pra abas que o usuário pode
    // ver (evita vazar contagem de uma aba que ele nem consegue abrir), e
    // usando contagem exata (não mais count() sobre uma lista já limitada
    // por LIMIT/OFFSET, que sub-contava acima do tamanho de página).
    $badgeAtrasados = in_array('processos', $abasPermitidas, true)
        ? DashboardMetrics::contarProcessosAtrasados([])
        : 0;
    $badgeAprovacoes = in_array('aprovacoes', $abasPermitidas, true)
        ? DashboardMetrics::kpisAprovacoes([])['aguardando']
        : 0;
    $badgeMeusProcessos = in_array('meus-processos', $abasPermitidas, true)
        ? DashboardMetrics::kpisMeusProcessos($currentUser['id'], [])['aguardando_minha_acao']
        : 0;

    // ─── Render ──────────────────────────────────────────────────────
    $pageTitle    = 'Dashboard';
    $activeModule = 'dashboard';
    $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/dashboard.css?v=' . filemtime(ROOT . '/public/assets/css/dashboard.css') . '">';

    ob_start();
    include VIEWS . '/dashboard/index.php';
    $content = ob_get_clean();
    include VIEWS . '/layout/base.php';
    exit;
}

// Ação desconhecida
http_response_code(404);
include VIEWS . '/errors/404.php';
