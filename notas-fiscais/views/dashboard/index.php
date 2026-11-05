<?php
// @versao Dash_01 V_04_12/08/2026
/**
 * views/dashboard/index.php
 * Dashboard gerencial — 5 abas
 *
 * Variáveis disponíveis (definidas pelo DashboardController):
 *   $aba           string   aba ativa
 *   $filtros       array    filtros ativos
 *   $dados         array    dados da aba ativa
 *   $dropdowns     array    dados para filtros (filiais, setores, tipos_nota, grupos, responsaveis)
 *   $badgeAtrasados int     contador para badge da aba Processos
 *   $badgeAprovacoes int    contador para badge da aba Aprovações
 *   $periodoPreset string   preset ativo
 *   $periodoInicio string   data início (se personalizado)
 *   $periodoFim    string   data fim (se personalizado)
 */

// Helper local — label do período
function dashPeriodoLabel(string $preset, string $ini = '', string $fim = ''): string {
    return match($preset) {
        'esta-semana'    => 'Esta semana',
        'este-mes'       => 'Este mês',
        'este-trimestre' => 'Este trimestre',
        'todos'          => 'Todos',
        'personalizado'  => ($ini && $fim)
            ? DateHelper::formatBr($ini) . ' – ' . DateHelper::formatBr($fim)
            : 'Período personalizado',
        default => 'Todos',
    };
}

// Helper — formata valor monetário
function dashMoeda(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}

// Helper — chip de comparação "X% vs período anterior" (não assume se subir é bom
// ou ruim — isso depende da métrica — só mostra a direção e a magnitude)
function dashVariacao(float $atual, float $anterior): string {
    if ($anterior == 0) {
        if ($atual == 0) return '<span class="kpi-context"><i data-lucide="minus" width="11" height="11"></i> Igual ao período anterior</span>';
        return '<span class="kpi-context"><i data-lucide="arrow-up" width="11" height="11"></i> Novo (0 no período anterior)</span>';
    }
    $pct = round((($atual - $anterior) / $anterior) * 100);
    if ($pct == 0) return '<span class="kpi-context"><i data-lucide="minus" width="11" height="11"></i> Igual ao período anterior</span>';
    $icone = $pct > 0 ? 'arrow-up' : 'arrow-down';
    return '<span class="kpi-context"><i data-lucide="' . $icone . '" width="11" height="11"></i> '
         . ($pct > 0 ? '+' : '') . $pct . '% vs período anterior</span>';
}

// Helper — badge de dias com classificação SLA
function diasAtrasoBadge(int $dias, ?array $sla = null): string {
    if ($sla !== null) {
        $classe = $sla['classe'] ?? 'sla-sem';
        return '<span class="sla-badge ' . $classe . '">+' . $dias . ' ' . ($dias === 1 ? 'dia' : 'dias') . '</span>';
    }
    // Fallback legado (sem SLA)
    $cls = $dias >= 7 ? 'sla-vencido' : ($dias >= 3 ? 'sla-atencao' : 'sla-ok');
    return '<span class="sla-badge ' . $cls . '">+' . $dias . ' ' . ($dias === 1 ? 'dia' : 'dias') . '</span>';
}

// Helper — badge status processo
function statusBadge(string $status): string {
    return match($status) {
        'aberto'                    => '<span class="badge badge-blue">Aberto</span>',
        'aprovado'                  => '<span class="badge badge-success">Aprovado</span>',
        'devolvido'                 => '<span class="badge badge-warning">Devolvido</span>',
        'cancelado'                 => '<span class="badge badge-muted">Cancelado</span>',
        'cancelamento_solicitado'   => '<span class="badge badge-danger">Cancel. solicitado</span>',
        'encerrado_apollo'          => '<span class="badge badge-muted">Encerrado</span>',
        default                     => '<span class="badge badge-muted">' . h($status) . '</span>',
    };
}

// URL base para filtros
function dashUrl(string $aba, array $extra = [], array $remove = []): string {
    $base = ['module' => 'dashboard', 'action' => 'index', 'aba' => $aba];
    $current = $_GET;
    unset($current['module'], $current['action']);
    foreach ($remove as $k) unset($current[$k]);
    $merged = array_merge($current, $base, $extra);
    return '?' . http_build_query($merged);
}

$periodoLabel = dashPeriodoLabel(
    $periodoPreset ?? 'todos',
    $filtros['periodo_inicio'] ?? '',
    $filtros['periodo_fim'] ?? ''
);
?>

<!-- PAGE TABS — só exibe as abas que o usuário tem permissão pra ver -->
<div class="page-tabs">
  <?php if (in_array('visao-geral', $abasPermitidas, true)): ?>
  <a href="<?= dashUrl('visao-geral') ?>" class="page-tab<?= $aba === 'visao-geral' ? ' active' : '' ?>">
    <i data-lucide="layout-dashboard" width="14" height="14"></i>
    Visão Geral
  </a>
  <?php endif; ?>
  <?php if (in_array('meus-processos', $abasPermitidas, true)): ?>
  <a href="<?= dashUrl('meus-processos') ?>" class="page-tab<?= $aba === 'meus-processos' ? ' active' : '' ?>">
    <i data-lucide="user-check" width="14" height="14"></i>
    Meus Processos
    <?php if ($badgeMeusProcessos > 0): ?>
      <span class="tab-badge"><?= $badgeMeusProcessos ?></span>
    <?php endif; ?>
  </a>
  <?php endif; ?>
  <?php if (in_array('processos', $abasPermitidas, true)): ?>
  <a href="<?= dashUrl('processos') ?>" class="page-tab<?= $aba === 'processos' ? ' active' : '' ?>">
    <i data-lucide="file-text" width="14" height="14"></i>
    Processos
    <?php if ($badgeAtrasados > 0): ?>
      <span class="tab-badge red"><?= $badgeAtrasados ?></span>
    <?php endif; ?>
  </a>
  <?php endif; ?>
  <?php if (in_array('aprovacoes', $abasPermitidas, true)): ?>
  <a href="<?= dashUrl('aprovacoes') ?>" class="page-tab<?= $aba === 'aprovacoes' ? ' active' : '' ?>">
    <i data-lucide="check-circle" width="14" height="14"></i>
    Aprovações
    <?php if ($badgeAprovacoes > 0): ?>
      <span class="tab-badge"><?= $badgeAprovacoes ?></span>
    <?php endif; ?>
  </a>
  <?php endif; ?>
  <?php if (in_array('financeiro', $abasPermitidas, true)): ?>
  <a href="<?= dashUrl('financeiro') ?>" class="page-tab<?= $aba === 'financeiro' ? ' active' : '' ?>">
    <i data-lucide="credit-card" width="14" height="14"></i>
    Financeiro
  </a>
  <?php endif; ?>
  <?php if (in_array('desempenho', $abasPermitidas, true)): ?>
  <a href="<?= dashUrl('desempenho') ?>" class="page-tab<?= $aba === 'desempenho' ? ' active' : '' ?>">
    <i data-lucide="bar-chart-2" width="14" height="14"></i>
    Desempenho
  </a>
  <?php endif; ?>
</div>

<div class="dash-content">

<?php if ($aba === 'visao-geral'): ?>
<!-- ============================================================ -->
<!-- ABA: VISÃO GERAL -->
<!-- ============================================================ -->
<?php $kpis = $dados['kpis']; $processos = $dados['processos']; ?>

<!-- Título bicolor -->
<h1 style="font-family:var(--font-bold);font-size:22px;color:var(--color-dark);margin-bottom:20px">
  Visão <span style="color:var(--color-primary)">geral</span>
</h1>

<!-- KPIs principais -->
<div class="kpi-grid-4">
  <div class="kpi-card-lg accent-blue">
    <div class="kpi-label">Processos abertos</div>
    <div class="kpi-value blue"><?= $kpis['abertos'] ?></div>
    <div class="kpi-sub">Total no sistema, todas as datas</div>
    <div class="kpi-context">
      <i data-lucide="calendar" width="11" height="11"></i>
      <?= $kpis['abertos_periodo'] ?> abertos <?= h(lcfirst($periodoLabel)) ?>
    </div>
  </div>
  <div class="kpi-card-lg accent-red">
    <div class="kpi-label">Atrasados</div>
    <div class="kpi-value red"><?= $kpis['atrasados'] ?></div>
    <div class="kpi-sub">Passaram do prazo esperado</div>
    <div class="kpi-context">
      <?= $kpis['abertos'] > 0 ? round($kpis['atrasados'] / $kpis['abertos'] * 100) : 0 ?>% do total aberto
    </div>
  </div>
  <div class="kpi-card-lg accent-green">
    <div class="kpi-label">Concluídos no mês</div>
    <div class="kpi-value green"><?= $kpis['concluidos_mes'] ?></div>
    <div class="kpi-sub"><?= ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'][(int)date('m') - 1] . ' de ' . date('Y') ?></div>
    <?= dashVariacao((float)$kpis['concluidos_mes'], (float)$kpis['concluidos_mes_anterior']) ?>
  </div>
  <div class="kpi-card-lg accent-amber">
    <div class="kpi-label">Volume financeiro aberto</div>
    <div class="kpi-value amber"><?= dashMoeda($kpis['volume_aberto']) ?></div>
    <div class="kpi-sub">Total no sistema, todas as datas</div>
    <div class="kpi-context">
      <i data-lucide="calendar" width="11" height="11"></i>
      <?= dashMoeda($kpis['volume_aberto_periodo']) ?> <?= h(lcfirst($periodoLabel)) ?>
    </div>
  </div>
</div>

<!-- KPIs secundários -->
<?php
$pctAguardAprov = $kpis['abertos'] > 0 ? round($kpis['aguardando_aprovacao'] / $kpis['abertos'] * 100) : 0;
$pctDevolvidos  = $kpis['abertos'] > 0 ? round($kpis['devolvidos']          / $kpis['abertos'] * 100) : 0;
$pagoMesVarPct  = $kpis['pago_mes_anterior'] > 0
    ? round((($kpis['pago_mes'] - $kpis['pago_mes_anterior']) / $kpis['pago_mes_anterior']) * 100)
    : ($kpis['pago_mes'] > 0 ? null : 0); // null = "novo" (mês anterior zerado)
?>
<div class="kpi-grid-4-sm">
  <div class="kpi-card-sm">
    <div class="kpi-sm-icon blue"><i data-lucide="clock" width="18" height="18"></i></div>
    <div>
      <div class="kpi-sm-label">Aguard. aprovação</div>
      <div class="kpi-sm-val"><?= $kpis['aguardando_aprovacao'] ?></div>
      <div class="kpi-sm-context"><?= $pctAguardAprov ?>% do total aberto</div>
    </div>
  </div>
  <div class="kpi-card-sm">
    <div class="kpi-sm-icon red"><i data-lucide="rotate-ccw" width="18" height="18"></i></div>
    <div>
      <div class="kpi-sm-label">Devolvidos</div>
      <div class="kpi-sm-val"><?= $kpis['devolvidos'] ?></div>
      <div class="kpi-sm-context"><?= $pctDevolvidos ?>% do total aberto</div>
    </div>
  </div>
  <div class="kpi-card-sm">
    <div class="kpi-sm-icon amber"><i data-lucide="dollar-sign" width="18" height="18"></i></div>
    <div>
      <div class="kpi-sm-label">Aguard. pagamento</div>
      <div class="kpi-sm-val"><?= $kpis['aguardando_pgto'] ?></div>
      <div class="kpi-sm-context"><?= $kpis['aguardando_pgto_vencido'] ?> já vencidas</div>
    </div>
  </div>
  <div class="kpi-card-sm">
    <div class="kpi-sm-icon green"><i data-lucide="check-circle" width="18" height="18"></i></div>
    <div>
      <div class="kpi-sm-label">Pago no mês</div>
      <div class="kpi-sm-val"><?= dashMoeda($kpis['pago_mes']) ?></div>
      <div class="kpi-sm-context">
        <?= $pagoMesVarPct === null ? 'Novo (0 no mês anterior)' : (($pagoMesVarPct >= 0 ? '+' : '') . $pagoMesVarPct . '% vs mês anterior') ?>
      </div>
    </div>
  </div>
</div>

<!-- Lista de processos em andamento -->
<div class="section-card" style="overflow:visible">
  <div class="section-card-header">
    <i data-lucide="file-text" width="16" height="16" style="color:var(--color-primary)"></i>
    <span class="section-card-title">Processos em andamento <span style="color:var(--color-gray-medium);font-family:var(--font-regular);font-weight:400">— abertos <?= h(lcfirst($periodoLabel)) ?></span></span>
    <span class="section-count"><?= $dados['total'] ?? count($processos) ?></span>
  </div>
  <!-- Filtros -->
  <div class="filter-bar">
    <form method="get" action="" id="form-vg" style="display:contents">
      <input type="hidden" name="module" value="dashboard">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="visao-geral">

      <div class="filter-search">
        <i data-lucide="search" width="14" height="14" style="color:var(--color-gray-medium)"></i>
        <input type="text" name="busca" value="<?= h($filtros['busca'] ?? '') ?>"
               placeholder="Buscar processo, fornecedor...">
      </div>

      <!-- Período -->
      <div class="fp-wrap" id="fp-periodo-vg">
        <button type="button" class="filter-pill<?= ($periodoPreset ?? 'todos') !== 'todos' ? ' active' : '' ?>"
                onclick="dashToggleFP('periodo-vg')">
          <i data-lucide="calendar" width="12" height="12"></i>
          <span><?= h($periodoLabel) ?></span>
          <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
        </button>
        <div class="fp-dropdown" id="fp-dd-periodo-vg">
          <?php foreach (['todos'=>'Todos','esta-semana'=>'Esta semana','este-mes'=>'Este mês','este-trimestre'=>'Este trimestre'] as $v => $l): ?>
          <div class="fp-option<?= ($periodoPreset??'') === $v ? ' selected' : '' ?>"
               onclick="dashSelectPeriodo('vg','<?= $v ?>','<?= $l ?>')">
            <span class="fp-check"><?= ($periodoPreset??'') === $v ? '✓' : '' ?></span><?= $l ?>
          </div>
          <?php endforeach; ?>
          <div class="fp-option-custom" onclick="dashToggleCustom('periodo-vg')">
            <i data-lucide="calendar" width="12" height="12"></i> Período personalizado
          </div>
          <div class="fp-custom-period" id="fp-custom-periodo-vg">
            <div class="fp-custom-row">
              <label>De</label>
              <input type="date" id="fp-ini-vg" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
            </div>
            <div class="fp-custom-row">
              <label>Até</label>
              <input type="date" id="fp-fim-vg" value="<?= h($filtros['periodo_fim'] ?? '') ?>">
            </div>
            <button type="button" class="fp-custom-apply"
                    onclick="dashApplyCustom('vg')">Aplicar</button>
          </div>
        </div>
      </div>
      <input type="hidden" name="vg_periodo_preset" id="inp-periodo_preset-vg" value="<?= h($periodoPreset ?? 'todos') ?>">
      <input type="hidden" name="vg_periodo_inicio" id="inp-periodo_inicio-vg" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="vg_periodo_fim"    id="inp-periodo_fim-vg"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">

      <!-- Status — valores reais do ENUM nf_processos.status. Aprovado/Cancelado
           ficam de fora porque a lista já os exclui na base (só "em andamento"). -->
      <?php $statusVgOpcoes = ['aberto'=>'Aberto','devolvido'=>'Devolvido','cancelamento_solicitado'=>'Cancel. solicitado']; ?>
      <div class="fp-wrap" id="fp-status-vg">
        <button type="button" class="filter-pill<?= !empty($filtros['status']) ? ' active' : '' ?>"
                onclick="dashToggleFP('status-vg')">
          <span id="fpl-status-vg"><?= !empty($filtros['status']) ? h($statusVgOpcoes[$filtros['status']] ?? $filtros['status']) : 'Status' ?></span>
          <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
        </button>
        <div class="fp-dropdown" id="fp-dd-status-vg">
          <?php foreach ($statusVgOpcoes as $v=>$l): ?>
          <div class="fp-option" onclick="dashSelectSingle('status-vg','status','<?= $v ?>','<?= $l ?>')">
            <span class="fp-check"><?= ($filtros['status'] ?? '') === $v ? '✓' : '' ?></span><?= $l ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <input type="hidden" name="status" id="inp-status-vg" value="<?= h($filtros['status'] ?? '') ?>">

      <!-- Tipo de nota -->
      <div class="fp-wrap" id="fp-tipo-vg">
        <button type="button" class="filter-pill<?= !empty($filtros['tipo_nota_id']) ? ' active' : '' ?>"
                onclick="dashToggleFP('tipo-vg')">
          <span>Tipo de nota</span>
          <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
        </button>
        <div class="fp-dropdown" id="fp-dd-tipo-vg">
          <?php foreach ($dropdowns['tipos_nota'] as $tn): ?>
          <div class="fp-option" onclick="dashSelectSingle('tipo-vg','tipo_nota_id','<?= h($tn['id']) ?>','<?= h($tn['nome']) ?>')">
            <span class="fp-check"><?= ($filtros['tipo_nota_id'] ?? '') === $tn['id'] ? '✓' : '' ?></span>
            <?= h($tn['nome']) ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <input type="hidden" name="tipo_nota_id" id="inp-tipo_nota_id-vg" value="<?= h($filtros['tipo_nota_id'] ?? '') ?>">

      <button type="submit" style="margin-left:auto;padding:7px 16px;background:var(--color-primary);color:#fff;border:none;border-radius:4px;font-family:var(--font-bold);font-size:12px;cursor:pointer">
        Filtrar
      </button>
    </form>
  </div>
  <!-- Tabela -->
  <div class="tbl-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Processo</th><th>Fornecedor</th><th>Valor</th>
          <th>Status</th><th>Etapa atual</th><th>Responsável</th>
          <th>Abertura</th><th>Atraso</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($processos)): ?>
        <tr><td colspan="8" class="empty-state">Nenhum processo em andamento no período.</td></tr>
        <?php else: foreach ($processos as $p):
          $atrasado = !empty($p['dias_atraso']) && $p['dias_atraso'] > 0;
        ?>
        <tr class="<?= $atrasado ? 'row-atrasado' : '' ?>">
          <td><a class="link-cell" href="<?= h(linkDetalheProcesso($p['id'], 'dashboard')) ?>"><?= h($p['codigo']) ?></a></td>
          <td><?= h($p['fornecedor'] ?? '—') ?></td>
          <td style="font-weight:700"><?= $p['valor_total'] ? dashMoeda((float)$p['valor_total']) : '—' ?></td>
          <td><?= statusBadge($p['status']) ?></td>
          <td><?= h($p['etapa_atual'] ?? '—') ?></td>
          <td><?= h($p['responsavel_nome'] ?? '—') ?></td>
          <td><?= $p['criado_em'] ? DateHelper::formatBr(substr($p['criado_em'], 0, 10)) : '—' ?></td>
          <td><?= $atrasado ? diasAtrasoBadge((int)$p['dias_atraso'], $p['sla'] ?? null) : '—' ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <!-- Rodapé com contagem e paginação -->
  <?php $totalVg = $dados['total'] ?? count($processos); ?>
  <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-top:1px solid #EAEAEA; flex-wrap:wrap; gap:12px">
    <span style="font-size:13px; color:var(--color-gray-medium)">
      Exibindo <?= count($processos) ?> de <?= $totalVg ?> processo<?= $totalVg === 1 ? '' : 's' ?>
    </span>
    <?php
    $totalPgsVg = $dados['total_paginas'] ?? 1;
    if ($totalPgsVg > 1):
        $currentPage = $dados['pagina'] ?? 1;
        $totalPages  = $totalPgsVg;
        $module      = 'dashboard';
        $action      = 'index';
        $pageParam   = 'vg_page';
        $extraParams = array_filter([
            'aba'              => 'visao-geral',
            'busca'            => $filtros['busca'] ?? '',
            'vg_periodo_preset'=> $filtros['periodo_preset'] ?? '',
            'vg_periodo_inicio'=> $filtros['periodo_inicio'] ?? '',
            'vg_periodo_fim'   => $filtros['periodo_fim'] ?? '',
            'status'           => $filtros['status'] ?? '',
            'tipo_nota_id'     => $filtros['tipo_nota_id'] ?? '',
        ], fn($v) => $v !== '' && $v !== null);
        include VIEWS . '/components/pagination.php';
    endif;
    ?>
  </div>
</div>

<?php elseif ($aba === 'meus-processos'): ?>
<!-- ============================================================ -->
<!-- ABA: MEUS PROCESSOS — acompanhamento pessoal, sem R$ agregado -->
<!-- ============================================================ -->
<?php $kpisMP = $dados['kpis']; $listaMP = $dados['lista']; ?>

<h1 style="font-family:var(--font-bold);font-size:22px;color:var(--color-dark);margin-bottom:20px">
  Meus <span style="color:var(--color-primary)">processos</span>
</h1>

<!-- KPIs pessoais — apenas contagens, nunca valores somados -->
<div class="kpi-grid-6">
  <div class="kpi-card-lg accent-blue">
    <div class="kpi-label">Processos que abri</div>
    <div class="kpi-value blue"><?= $kpisMP['abertos_por_mim'] ?></div>
    <div class="kpi-sub">Em andamento agora</div>
  </div>
  <div class="kpi-card-lg accent-purple">
    <div class="kpi-label">Processos devolvidos</div>
    <div class="kpi-value purple"><?= $kpisMP['devolvidos_por_mim'] ?></div>
    <div class="kpi-sub">Aguardando minha correção</div>
  </div>
  <div class="kpi-card-lg accent-amber">
    <div class="kpi-label">Aguardando minha ação</div>
    <div class="kpi-value amber"><?= $kpisMP['aguardando_minha_acao'] ?></div>
    <div class="kpi-sub">Direto ou via meu grupo</div>
  </div>
  <div class="kpi-card-lg accent-red">
    <div class="kpi-label">Parado comigo há mais tempo</div>
    <div class="kpi-value red"><?= $kpisMP['max_dias_parado'] ?> <?= $kpisMP['max_dias_parado'] === 1 ? 'dia' : 'dias' ?></div>
    <div class="kpi-sub">Maior espera entre os que aguardam minha ação</div>
  </div>
  <div class="kpi-card-lg accent-green">
    <div class="kpi-label">Concluídos — que abri</div>
    <div class="kpi-value green"><?= $kpisMP['concluidos_abri'] ?></div>
    <div class="kpi-sub"><?= h($periodoLabel) ?> — processos que eu criei</div>
  </div>
  <div class="kpi-card-lg accent-green">
    <div class="kpi-label">Concluídos — que participei</div>
    <div class="kpi-value green"><?= $kpisMP['concluidos_participei'] ?></div>
    <div class="kpi-sub"><?= h($periodoLabel) ?> — aprovei alguma etapa</div>
  </div>
</div>

<!-- Lista dos meus processos -->
<div class="section-card" style="overflow:visible">
  <div class="section-card-header">
    <i data-lucide="user-check" width="16" height="16" style="color:var(--color-primary)"></i>
    <span class="section-card-title">Processos em que participo</span>
    <span class="section-count"><?= $dados['total'] ?? count($listaMP) ?></span>
  </div>
  <div class="filter-bar">
    <form method="get" action="" id="form-mp" style="display:contents">
      <input type="hidden" name="module" value="dashboard">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="meus-processos">
      <div class="filter-search filter-search--narrow">
        <i data-lucide="search" width="14" height="14" style="color:var(--color-gray-medium)"></i>
        <input type="text" name="busca" value="<?= h($filtros['busca'] ?? '') ?>"
               placeholder="Buscar processo, fornecedor, NF...">
      </div>
      <!-- Período — presets + personalizado. Padrão "Todos" (não recorta a
           tabela nem os KPIs até o usuário escolher um período manualmente,
           pra não esconder processos antigos ainda em aberto). -->
      <div class="fp-wrap" id="fp-periodo-mp">
        <button type="button" class="filter-pill<?= ($periodoPreset ?? 'todos') !== 'todos' ? ' active' : '' ?>"
                onclick="dashToggleFP('periodo-mp')">
          <i data-lucide="calendar" width="12" height="12"></i>
          <span><?= h($periodoLabel) ?></span>
          <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
        </button>
        <div class="fp-dropdown" id="fp-dd-periodo-mp">
          <?php foreach (['todos'=>'Todos','esta-semana'=>'Esta semana','este-mes'=>'Este mês','este-trimestre'=>'Este trimestre'] as $v => $l): ?>
          <div class="fp-option<?= ($periodoPreset??'') === $v ? ' selected' : '' ?>"
               onclick="dashSelectPeriodoMp('<?= $v ?>','<?= $l ?>')">
            <span class="fp-check"><?= ($periodoPreset??'') === $v ? '✓' : '' ?></span><?= $l ?>
          </div>
          <?php endforeach; ?>
          <div class="fp-option-custom" onclick="dashToggleCustom('periodo-mp')">
            <i data-lucide="calendar" width="12" height="12"></i> Período personalizado
          </div>
          <div class="fp-custom-period" id="fp-custom-periodo-mp">
            <div class="fp-custom-row"><label>De</label><input type="date" id="fp-ini-mp" value="<?= h($filtros['periodo_inicio'] ?? '') ?>"></div>
            <div class="fp-custom-row"><label>Até</label><input type="date" id="fp-fim-mp" value="<?= h($filtros['periodo_fim'] ?? '') ?>"></div>
            <button type="button" class="fp-custom-apply" onclick="dashApplyCustomMp()">Aplicar</button>
          </div>
        </div>
      </div>
      <input type="hidden" name="mp_periodo_preset" id="inp-periodo_preset-mp" value="<?= h($periodoPreset ?? 'todos') ?>">
      <input type="hidden" name="mp_periodo_inicio" id="inp-periodo_inicio-mp" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="mp_periodo_fim"    id="inp-periodo_fim-mp"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">

      <!-- Status — multi-seleção; por padrão aprovados/cancelados ficam fora da tabela -->
      <?php
      $statusMpTodos    = ['aberto'=>'Aberto','devolvido'=>'Devolvido','cancelamento_solicitado'=>'Cancel. solicitado','aprovado'=>'Aprovado','cancelado'=>'Cancelado','encerrado_apollo'=>'Encerrado'];
      $statusMpSelecionados = $filtros['mp_status'] ?? ['aberto','devolvido','cancelamento_solicitado'];
      $statusMpLabel = (count($statusMpSelecionados) === count($statusMpTodos))
          ? 'Todos os status'
          : (count($statusMpSelecionados) . ' status selecionado' . (count($statusMpSelecionados) === 1 ? '' : 's'));
      ?>
      <div class="fp-wrap" id="fp-status-mp">
        <button type="button" class="filter-pill<?= count($statusMpSelecionados) < count($statusMpTodos) ? ' active' : '' ?>"
                onclick="dashToggleFP('status-mp')">
          <span id="fpl-status-mp"><?= h($statusMpLabel) ?></span>
          <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
        </button>
        <div class="fp-dropdown" id="fp-dd-status-mp">
          <?php foreach ($statusMpTodos as $v => $l): ?>
          <label class="fp-option" style="cursor:pointer" onclick="event.stopPropagation()">
            <input type="checkbox" class="mp-status-chk" value="<?= $v ?>"
                   <?= in_array($v, $statusMpSelecionados, true) ? 'checked' : '' ?>
                   style="margin-right:8px;width:15px;height:15px;accent-color:var(--color-primary)">
            <?= $l ?>
          </label>
          <?php endforeach; ?>
          <div class="fp-custom-period open">
            <button type="button" class="fp-custom-apply" onclick="dashApplyStatusMp()">Aplicar</button>
          </div>
        </div>
      </div>
      <input type="hidden" name="mp_status" id="inp-mp_status" value="<?= h(implode(',', $statusMpSelecionados)) ?>">

      <!-- Responsável atual (pessoa OU grupo com a etapa atual em mãos — mesmo
           critério da coluna "Responsável atual" da tabela) -->
      <?php
      $respAtuaisTodos    = $dados['responsaveis_atuais'] ?? [];
      $respAtuaisPessoas  = array_values(array_filter($respAtuaisTodos, fn($r) => $r['tipo'] === 'usuario'));
      $respAtuaisGrupos   = array_values(array_filter($respAtuaisTodos, fn($r) => $r['tipo'] === 'grupo'));
      $respMpLabel = 'Responsável';
      foreach ($respAtuaisTodos as $r) {
          if (($filtros['mp_resp_tipo'] ?? '') === $r['tipo'] && ($filtros['mp_resp_id'] ?? '') === $r['id']) {
              $respMpLabel = $r['nome'];
              break;
          }
      }
      ?>
      <div class="fp-wrap" id="fp-resp-mp">
        <button type="button" class="filter-pill<?= !empty($filtros['mp_resp_id']) ? ' active' : '' ?>"
                onclick="dashToggleFP('resp-mp')">
          <span id="fpl-resp-mp"><?= h($respMpLabel) ?></span>
          <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
        </button>
        <div class="fp-dropdown fp-dropdown--wide" id="fp-dd-resp-mp">
          <?php if (empty($respAtuaisPessoas) && empty($respAtuaisGrupos)): ?>
          <div style="padding:12px 14px;font-size:13px;color:var(--color-gray-medium)">Nenhum responsável nos seus processos.</div>
          <?php endif; ?>
          <?php if (!empty($respAtuaisPessoas)): ?>
          <div style="padding:8px 14px 4px;font-size:11px;font-weight:700;color:var(--color-gray-medium);text-transform:uppercase">Pessoas</div>
          <?php foreach ($respAtuaisPessoas as $r): ?>
          <div class="fp-option" onclick="dashSelectSingle('resp-mp','mp_resp','usuario:<?= h($r['id']) ?>','<?= h($r['nome']) ?>')">
            <span class="fp-check"><?= (($filtros['mp_resp_tipo'] ?? '') === 'usuario' && ($filtros['mp_resp_id'] ?? '') === $r['id']) ? '✓' : '' ?></span>
            <?= h($r['nome']) ?>
          </div>
          <?php endforeach; endif; ?>
          <?php if (!empty($respAtuaisGrupos)): ?>
          <div style="padding:8px 14px 4px;font-size:11px;font-weight:700;color:var(--color-gray-medium);text-transform:uppercase">Grupos</div>
          <?php foreach ($respAtuaisGrupos as $r): ?>
          <div class="fp-option" onclick="dashSelectSingle('resp-mp','mp_resp','grupo:<?= h($r['id']) ?>','<?= h($r['nome']) ?>')">
            <span class="fp-check"><?= (($filtros['mp_resp_tipo'] ?? '') === 'grupo' && ($filtros['mp_resp_id'] ?? '') === $r['id']) ? '✓' : '' ?></span>
            <i data-lucide="users" width="12" height="12" style="margin-right:4px;vertical-align:-2px"></i><?= h($r['nome']) ?>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <input type="hidden" name="mp_resp" id="inp-mp_resp-mp" value="<?= h(!empty($filtros['mp_resp_tipo']) ? $filtros['mp_resp_tipo'] . ':' . $filtros['mp_resp_id'] : '') ?>">

      <!-- Ordenar -->
      <?php
      $ordemMpOpcoes = ['mais-recentes' => 'Mais recentes', 'mais-antigos' => 'Mais antigos', 'maior-valor' => 'Maior valor', 'menor-valor' => 'Menor valor'];
      $ordemMpAtual  = $filtros['ordem'] ?? 'mais-recentes';
      ?>
      <div class="fp-wrap" id="fp-ordem-mp">
        <button type="button" class="filter-pill<?= $ordemMpAtual !== 'mais-recentes' ? ' active' : '' ?>"
                onclick="dashToggleFP('ordem-mp')">
          <i data-lucide="arrow-up-down" width="12" height="12"></i>
          <span id="fpl-ordem-mp"><?= h($ordemMpOpcoes[$ordemMpAtual] ?? 'Ordenar') ?></span>
          <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
        </button>
        <div class="fp-dropdown" id="fp-dd-ordem-mp">
          <?php foreach ($ordemMpOpcoes as $v => $l): ?>
          <div class="fp-option" onclick="dashSelectSingle('ordem-mp','ordem','<?= $v ?>','<?= $l ?>')">
            <span class="fp-check"><?= $ordemMpAtual === $v ? '✓' : '' ?></span><?= $l ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <input type="hidden" name="ordem" id="inp-ordem-mp" value="<?= h($ordemMpAtual) ?>">

      <button type="submit" style="margin-left:auto;padding:7px 16px;background:var(--color-primary);color:#fff;border:none;border-radius:4px;font-family:var(--font-bold);font-size:12px;cursor:pointer">
        Filtrar
      </button>
    </form>
  </div>
  <div class="tbl-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Processo</th><th>NF</th><th>Fornecedor</th><th>Valor</th>
          <th>Status</th><th>Etapa atual</th><th>Responsável atual</th>
          <th>Abertura</th><th>Parado há</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($listaMP)): ?>
        <tr><td colspan="9" class="empty-state">Você ainda não participa de nenhum processo.</td></tr>
        <?php else: foreach ($listaMP as $p):
          $emAndamento = !in_array($p['status'], ['aprovado', 'cancelado', 'encerrado_apollo'], true);
          $diasParado  = (int) ($p['dias_parado'] ?? 0);
        ?>
        <tr class="<?= ($emAndamento && $diasParado >= 5) ? 'row-atrasado' : '' ?>"
            onclick="window.location='<?= h(linkDetalheProcesso($p['id'], 'dashboard')) ?>'"
            style="cursor:pointer">
          <td><a class="link-cell" href="<?= h(linkDetalheProcesso($p['id'], 'dashboard')) ?>"><?= h($p['codigo']) ?></a></td>
          <td><?= h($p['numero_nf'] ?? '—') ?></td>
          <td><?= h($p['fornecedor_nome'] ?? '—') ?></td>
          <td style="font-weight:700"><?= $p['valor_nf'] ? dashMoeda((float)$p['valor_nf']) : '—' ?></td>
          <td><?= statusBadge($p['status']) ?></td>
          <td><?= $emAndamento ? h($p['etapa_atual_nome'] ?? '—') . ' (' . (int)$p['etapa_atual'] . '/' . (int)$p['total_etapas'] . ')' : '—' ?></td>
          <td><?= $emAndamento ? h($p['responsavel_atual_nome'] ?? '—') : '—' ?></td>
          <td><?= $p['criado_em'] ? DateHelper::formatBr(substr($p['criado_em'], 0, 10)) : '—' ?></td>
          <td><?= $emAndamento ? diasAtrasoBadge($diasParado) : '<span style="color:var(--color-gray-medium)">—</span>' ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Rodapé com contagem e paginação -->
  <?php $totalMp = $dados['total'] ?? count($listaMP); ?>
  <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-top:1px solid #EAEAEA; flex-wrap:wrap; gap:12px">
    <span style="font-size:13px; color:var(--color-gray-medium)">
      Exibindo <?= count($listaMP) ?> de <?= $totalMp ?> processo<?= $totalMp === 1 ? '' : 's' ?>
    </span>
    <?php
    $totalPgsMp = $dados['total_paginas'] ?? 1;
    if ($totalPgsMp > 1):
        $currentPage = $dados['pagina'] ?? 1;
        $totalPages  = $totalPgsMp;
        $module      = 'dashboard';
        $action      = 'index';
        $pageParam   = 'mp_page';
        $extraParams = array_filter([
            'aba'               => 'meus-processos',
            'busca'             => $filtros['busca'] ?? '',
            'mp_periodo_preset' => $filtros['periodo_preset'] ?? '',
            'mp_periodo_inicio' => $filtros['periodo_inicio'] ?? '',
            'mp_periodo_fim'    => $filtros['periodo_fim'] ?? '',
            'mp_status'         => implode(',', $statusMpSelecionados),
            'mp_resp'           => !empty($filtros['mp_resp_tipo']) ? $filtros['mp_resp_tipo'] . ':' . $filtros['mp_resp_id'] : '',
            'ordem'             => $filtros['ordem'] ?? '',
        ], fn($v) => $v !== '' && $v !== null);
        include VIEWS . '/components/pagination.php';
    endif;
    ?>
  </div>
</div>

<?php elseif ($aba === 'processos'): ?>
<!-- ============================================================ -->
<!-- ABA: PROCESSOS -->
<!-- ============================================================ -->
<?php
$contagens = $dados['contagens'];
$atrasados = $dados['atrasados'];
$parados   = $dados['parados'];
$todos     = $dados['todos'];

// Filtros comuns da aba, preservados em todo link de paginação — cada uma das
// 3 listas tem seu próprio parâmetro de página, e cada link também carrega a
// página ATUAL das outras duas, pra paginar uma não resetar as demais.
$extraParamsPrBase = array_filter([
    'aba'              => 'processos',
    'busca'            => $filtros['busca'] ?? '',
    'pr_periodo_preset'=> $filtros['periodo_preset'] ?? '',
    'pr_periodo_inicio'=> $filtros['periodo_inicio'] ?? '',
    'pr_periodo_fim'   => $filtros['periodo_fim'] ?? '',
    'responsavel_id'   => $filtros['responsavel_id'] ?? '',
], fn($v) => $v !== '' && $v !== null);
?>

<h1 style="font-family:var(--font-bold);font-size:22px;color:var(--color-dark);margin-bottom:20px">
  Acompanhamento de <span style="color:var(--color-primary)">processos</span>
</h1>

<!-- Filtros + Funil -->
<div class="section-card" style="overflow:visible;margin-bottom:20px">
  <div class="filter-bar">
    <form method="get" action="" id="form-pr" style="display:contents">
      <input type="hidden" name="module" value="dashboard">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="processos">

      <div class="filter-search">
        <i data-lucide="search" width="14" height="14" style="color:var(--color-gray-medium)"></i>
        <input type="text" name="busca" value="<?= h($filtros['busca'] ?? '') ?>"
               placeholder="Buscar processo, fornecedor, responsável...">
      </div>

      <!-- Período -->
      <div class="fp-wrap" id="fp-periodo-pr">
        <button type="button" class="filter-pill<?= ($periodoPreset ?? 'todos') !== 'todos' ? ' active' : '' ?>"
                onclick="dashToggleFP('periodo-pr')">
          <i data-lucide="calendar" width="12" height="12"></i>
          <span><?= h($periodoLabel) ?></span>
          <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
        </button>
        <div class="fp-dropdown" id="fp-dd-periodo-pr">
          <?php foreach (['todos'=>'Todos','esta-semana'=>'Esta semana','este-mes'=>'Este mês','este-trimestre'=>'Este trimestre'] as $v => $l): ?>
          <div class="fp-option<?= ($periodoPreset??'') === $v ? ' selected' : '' ?>"
               onclick="dashSelectPeriodo('pr','<?= $v ?>','<?= $l ?>')">
            <span class="fp-check"><?= ($periodoPreset??'') === $v ? '✓' : '' ?></span><?= $l ?>
          </div>
          <?php endforeach; ?>
          <div class="fp-option-custom" onclick="dashToggleCustom('periodo-pr')">
            <i data-lucide="calendar" width="12" height="12"></i> Período personalizado
          </div>
          <div class="fp-custom-period" id="fp-custom-periodo-pr">
            <div class="fp-custom-row"><label>De</label><input type="date" id="fp-ini-pr" value="<?= h($filtros['periodo_inicio'] ?? '') ?>"></div>
            <div class="fp-custom-row"><label>Até</label><input type="date" id="fp-fim-pr" value="<?= h($filtros['periodo_fim'] ?? '') ?>"></div>
            <button type="button" class="fp-custom-apply" onclick="dashApplyCustom('pr')">Aplicar</button>
          </div>
        </div>
      </div>
      <input type="hidden" name="pr_periodo_preset" id="inp-periodo_preset-pr" value="<?= h($periodoPreset ?? 'todos') ?>">
      <input type="hidden" name="pr_periodo_inicio" id="inp-periodo_inicio-pr" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="pr_periodo_fim"    id="inp-periodo_fim-pr"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">

      <!-- Responsável -->
      <div class="fp-wrap" id="fp-resp-pr">
        <button type="button" class="filter-pill<?= !empty($filtros['responsavel_id']) ? ' active' : '' ?>"
                onclick="dashToggleFP('resp-pr')">
          <span>Responsável</span>
          <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
        </button>
        <div class="fp-dropdown" id="fp-dd-resp-pr">
          <?php foreach ($dropdowns['responsaveis'] as $r): ?>
          <div class="fp-option" onclick="dashSelectSingle('resp-pr','responsavel_id','<?= h($r['id']) ?>','<?= h($r['nome']) ?>')">
            <span class="fp-check"><?= ($filtros['responsavel_id'] ?? '') === $r['id'] ? '✓' : '' ?></span>
            <?= h($r['nome']) ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <input type="hidden" name="responsavel_id" id="inp-responsavel_id-pr" value="<?= h($filtros['responsavel_id'] ?? '') ?>">

      <button type="submit" style="margin-left:auto;padding:7px 16px;background:var(--color-primary);color:#fff;border:none;border-radius:4px;font-family:var(--font-bold);font-size:12px;cursor:pointer">
        Filtrar
      </button>
    </form>
  </div>

  <!-- Funil por status real do processo (nf_processos.status — os mesmos das
       badges da tabela abaixo, não uma categoria inventada) -->
  <?php $statusAtivo = $filtros['status'] ?? ''; ?>
  <div class="etapas-strip">
    <div class="etapa-pill">
      <a class="etapa-box<?= $statusAtivo === '' ? ' active' : '' ?>" href="<?= h(dashUrl('processos', [], ['status'])) ?>">
        <div class="etapa-box-num"><?= $contagens['total'] ?></div>
        <div class="etapa-box-label">Todos</div>
      </a>
    </div>
    <div class="etapa-arrow"><i data-lucide="chevron-right" width="16" height="16"></i></div>
    <div class="etapa-pill">
      <a class="etapa-box<?= $statusAtivo === 'aberto' ? ' active' : '' ?>" href="<?= h(dashUrl('processos', ['status' => 'aberto'])) ?>">
        <div class="etapa-box-num blue"><?= $contagens['aberto'] ?></div>
        <div class="etapa-box-label">Aberto</div>
      </a>
    </div>
    <div class="etapa-arrow"><i data-lucide="chevron-right" width="16" height="16"></i></div>
    <div class="etapa-pill">
      <a class="etapa-box<?= $statusAtivo === 'devolvido' ? ' active' : '' ?>" href="<?= h(dashUrl('processos', ['status' => 'devolvido'])) ?>">
        <div class="etapa-box-num amber"><?= $contagens['devolvido'] ?></div>
        <div class="etapa-box-label">Devolvido</div>
      </a>
    </div>
    <div class="etapa-arrow"><i data-lucide="chevron-right" width="16" height="16"></i></div>
    <div class="etapa-pill">
      <a class="etapa-box<?= $statusAtivo === 'cancelamento_solicitado' ? ' active' : '' ?>" href="<?= h(dashUrl('processos', ['status' => 'cancelamento_solicitado'])) ?>">
        <div class="etapa-box-num"><?= $contagens['cancel_solic'] ?></div>
        <div class="etapa-box-label">Cancel. solicitado</div>
      </a>
    </div>
    <div class="etapa-arrow"><i data-lucide="chevron-right" width="16" height="16"></i></div>
    <div class="etapa-pill">
      <div class="etapa-box" style="border-radius:0 6px 6px 0;cursor:default">
        <div class="etapa-box-num green"><?= $contagens['concluidos'] ?></div>
        <div class="etapa-box-label">Aprovado</div>
      </div>
    </div>
  </div>
  <?php if ($contagens['cancelados'] > 0): ?>
  <div style="padding:0 20px 14px;font-size:12.5px;color:var(--color-gray-medium)">
    <i data-lucide="x-circle" width="12" height="12" style="vertical-align:-2px"></i>
    + <?= $contagens['cancelados'] ?> cancelado<?= $contagens['cancelados'] > 1 ? 's' : '' ?> no período (fora do funil, já que não é uma etapa de progresso)
  </div>
  <?php endif; ?>

</div>

<!-- Processos atrasados -->
<?php if (!empty($atrasados)): ?>
<div class="alert-section">
  <div class="alert-section-header">
    <i data-lucide="alert-triangle" width="15" height="15" style="color:#dc2626"></i>
    <span class="alert-section-title">Processos atrasados — <?= $dados['atrasados_total'] ?? count($atrasados) ?> <?= ($dados['atrasados_total'] ?? count($atrasados)) === 1 ? 'processo' : 'processos' ?></span>
  </div>
  <div class="tbl-wrap">
    <table class="data-table">
      <thead><tr><th>Processo</th><th>Fornecedor</th><th>Etapa atual</th><th>Responsável</th><th>Dias de atraso</th><th>Valor</th></tr></thead>
      <tbody>
        <?php foreach ($atrasados as $p): ?>
        <tr>
          <td><a class="link-cell" href="<?= h(linkDetalheProcesso($p['id'], 'dashboard')) ?>"><?= h($p['codigo']) ?></a></td>
          <td><?= h($p['fornecedor'] ?? '—') ?></td>
          <td><?= h($p['etapa_atual'] ?? '—') ?></td>
          <td><?= h($p['responsavel_nome'] ?? '—') ?></td>
          <td><?= diasAtrasoBadge((int)$p['dias_atraso'], $p['sla'] ?? null) ?></td>
          <td style="font-weight:700"><?= $p['valor_total'] ? dashMoeda((float)$p['valor_total']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($dados['atrasados_total_paginas'] > 1):
      $currentPage = $dados['atrasados_pagina'];
      $totalPages  = $dados['atrasados_total_paginas'];
      $module      = 'dashboard';
      $action      = 'index';
      $pageParam   = 'pr_atrasados_page';
      $extraParams = array_merge($extraParamsPrBase, array_filter([
          'pr_parados_page' => $dados['parados_pagina'] > 1 ? $dados['parados_pagina'] : '',
          'pr_todos_page'   => $dados['todos_pagina']   > 1 ? $dados['todos_pagina']   : '',
      ], fn($v) => $v !== ''));
  ?>
  <div style="display:flex; justify-content:flex-end; padding:12px 20px; border-top:1px solid #EAEAEA">
    <?php include VIEWS . '/components/pagination.php'; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Parados sem movimentação -->
<?php if (!empty($parados)): ?>
<div class="warn-section">
  <div class="warn-section-header">
    <i data-lucide="clock" width="15" height="15" style="color:#d97706"></i>
    <span class="warn-section-title">Parados há mais de 5 dias sem movimentação — risco de atraso — <?= $dados['parados_total'] ?? count($parados) ?> <?= ($dados['parados_total'] ?? count($parados)) === 1 ? 'processo' : 'processos' ?></span>
  </div>
  <div class="tbl-wrap">
    <table class="data-table">
      <thead><tr><th>Processo</th><th>Fornecedor</th><th>Etapa atual</th><th>Responsável</th><th>Últ. movimentação</th><th>Valor</th></tr></thead>
      <tbody>
        <?php foreach ($parados as $p): ?>
        <tr>
          <td><a class="link-cell" href="<?= h(linkDetalheProcesso($p['id'], 'dashboard')) ?>"><?= h($p['codigo']) ?></a></td>
          <td><?= h($p['fornecedor'] ?? '—') ?></td>
          <td><?= h($p['etapa_atual'] ?? '—') ?></td>
          <td><?= h($p['responsavel_nome'] ?? '—') ?></td>
          <td>
            <?php if ($p['ultima_movimentacao']): ?>
              <?= DateHelper::formatBrDatetime($p['ultima_movimentacao']) ?>
              <span style="color:var(--color-gray-medium)">(<?= (int)$p['dias_parado'] ?> dias)</span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td style="font-weight:700"><?= $p['valor_total'] ? dashMoeda((float)$p['valor_total']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($dados['parados_total_paginas'] > 1):
      $currentPage = $dados['parados_pagina'];
      $totalPages  = $dados['parados_total_paginas'];
      $module      = 'dashboard';
      $action      = 'index';
      $pageParam   = 'pr_parados_page';
      $extraParams = array_merge($extraParamsPrBase, array_filter([
          'pr_atrasados_page' => $dados['atrasados_pagina'] > 1 ? $dados['atrasados_pagina'] : '',
          'pr_todos_page'     => $dados['todos_pagina']     > 1 ? $dados['todos_pagina']     : '',
      ], fn($v) => $v !== ''));
  ?>
  <div style="display:flex; justify-content:flex-end; padding:12px 20px; border-top:1px solid #EAEAEA">
    <?php include VIEWS . '/components/pagination.php'; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Lista completa -->
<div class="section-card">
  <div class="section-card-header">
    <span class="section-card-title">Todos os processos em andamento</span>
    <span class="section-count"><?= $dados['todos_total'] ?? count($todos) ?></span>
  </div>
  <div class="tbl-wrap">
    <table class="data-table">
      <thead><tr><th>Processo</th><th>Fornecedor</th><th>Valor</th><th>Status</th><th>Etapa</th><th>Responsável</th><th>Últ. movim.</th><th>Situação</th></tr></thead>
      <tbody>
        <?php if (empty($todos)): ?>
        <tr><td colspan="8" class="empty-state">Nenhum processo em andamento.</td></tr>
        <?php else: foreach ($todos as $p):
          $atrasado = !empty($p['dias_atraso']) && $p['dias_atraso'] > 0;
        ?>
        <tr class="<?= $atrasado ? 'row-atrasado' : '' ?>">
          <td><a class="link-cell" href="<?= h(linkDetalheProcesso($p['id'], 'dashboard')) ?>"><?= h($p['codigo']) ?></a></td>
          <td><?= h($p['fornecedor'] ?? '—') ?></td>
          <td style="font-weight:700"><?= $p['valor_total'] ? dashMoeda((float)$p['valor_total']) : '—' ?></td>
          <td><?= statusBadge($p['status']) ?></td>
          <td><?= h($p['etapa_atual'] ?? '—') ?></td>
          <td><?= h($p['responsavel_nome'] ?? '—') ?></td>
          <td><?= $p['criado_em'] ? DateHelper::formatBr(substr($p['criado_em'], 0, 10)) : '—' ?></td>
          <td><?= $atrasado ? diasAtrasoBadge((int)$p['dias_atraso'], $p['sla'] ?? null) : '<span style="color:var(--color-gray-medium)">—</span>' ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (($dados['todos_total_paginas'] ?? 1) > 1):
      $currentPage = $dados['todos_pagina'];
      $totalPages  = $dados['todos_total_paginas'];
      $module      = 'dashboard';
      $action      = 'index';
      $pageParam   = 'pr_todos_page';
      $extraParams = array_merge($extraParamsPrBase, array_filter([
          'pr_atrasados_page' => $dados['atrasados_pagina'] > 1 ? $dados['atrasados_pagina'] : '',
          'pr_parados_page'   => $dados['parados_pagina']   > 1 ? $dados['parados_pagina']   : '',
      ], fn($v) => $v !== ''));
  ?>
  <div style="display:flex; justify-content:flex-end; padding:12px 20px; border-top:1px solid #EAEAEA">
    <?php include VIEWS . '/components/pagination.php'; ?>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($aba === 'aprovacoes'): ?>
<!-- ============================================================ -->
<!-- ABA: APROVAÇÕES -->
<!-- ============================================================ -->
<?php
$kpis = $dados['kpis']; $fila = $dados['fila']; $filaGrupos = $dados['fila_grupos']; $devolvidos = $dados['devolvidos']; $sla = $dados['sla'];
$extraParamsApBase = array_filter([
    'aba'              => 'aprovacoes',
    'busca'            => $filtros['busca'] ?? '',
    'ap_periodo_preset'=> $filtros['periodo_preset'] ?? '',
    'ap_periodo_inicio'=> $filtros['periodo_inicio'] ?? '',
    'ap_periodo_fim'   => $filtros['periodo_fim'] ?? '',
    'grupo_id'         => $filtros['grupo_id'] ?? '',
], fn($v) => $v !== '' && $v !== null);
?>

<h1 style="font-family:var(--font-bold);font-size:22px;color:var(--color-dark);margin-bottom:20px">
  Painel de <span style="color:var(--color-primary)">aprovações</span>
</h1>

<!-- KPIs -->
<div class="kpi-grid-4">
  <div class="kpi-card-lg accent-blue">
    <div class="kpi-label">Aguardando aprovação</div>
    <div class="kpi-value blue"><?= $kpis['aguardando'] ?></div>
    <div class="kpi-sub">Na fila agora</div>
    <div class="kpi-context">
      <?= $kpis['total_abertos'] > 0 ? round($kpis['aguardando'] / $kpis['total_abertos'] * 100) : 0 ?>% do total aberto
    </div>
  </div>
  <div class="kpi-card-lg accent-green">
    <div class="kpi-label">Aprovados no período</div>
    <div class="kpi-value green"><?= $kpis['aprovados_qtd'] ?></div>
    <div class="kpi-sub"><?= dashMoeda($kpis['aprovados_valor']) ?> aprovados</div>
    <?= dashVariacao((float)$kpis['aprovados_qtd'], (float)$kpis['aprovados_qtd_anterior']) ?>
  </div>
  <div class="kpi-card-lg accent-red">
    <div class="kpi-label">Devolvidos</div>
    <div class="kpi-value red"><?= $kpis['devolvidos'] ?></div>
    <div class="kpi-sub"><?= h($periodoLabel) ?></div>
    <?= dashVariacao((float)$kpis['devolvidos'], (float)$kpis['devolvidos_anterior']) ?>
  </div>
  <div class="kpi-card-lg accent-purple">
    <div class="kpi-label">Tempo médio de aprovação</div>
    <div class="kpi-value purple"><?= $kpis['media_dias'] ?> dias</div>
    <div class="kpi-sub">Média do período</div>
    <?= dashVariacao($kpis['media_dias'], $kpis['media_dias_anterior']) ?>
  </div>
</div>

<!-- Filtros -->
<div class="section-card" style="overflow:visible;margin-bottom:20px">
  <div class="filter-bar">
    <form method="get" action="" style="display:contents">
      <input type="hidden" name="module" value="dashboard">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="aprovacoes">
      <div class="filter-search">
        <i data-lucide="search" width="14" height="14" style="color:var(--color-gray-medium)"></i>
        <input type="text" name="busca" value="<?= h($filtros['busca'] ?? '') ?>" placeholder="Buscar por aprovador, processo...">
      </div>
      <!-- Período -->
      <div class="fp-wrap" id="fp-periodo-ap">
        <button type="button" class="filter-pill<?= ($periodoPreset ?? 'todos') !== 'todos' ? ' active' : '' ?>"
                onclick="dashToggleFP('periodo-ap')">
          <i data-lucide="calendar" width="12" height="12"></i>
          <span><?= h($periodoLabel) ?></span>
          <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
        </button>
        <div class="fp-dropdown" id="fp-dd-periodo-ap">
          <?php foreach (['todos'=>'Todos','esta-semana'=>'Esta semana','este-mes'=>'Este mês','este-trimestre'=>'Este trimestre'] as $v => $l): ?>
          <div class="fp-option<?= ($periodoPreset??'') === $v ? ' selected' : '' ?>"
               onclick="dashSelectPeriodo('ap','<?= $v ?>','<?= $l ?>')">
            <span class="fp-check"><?= ($periodoPreset??'') === $v ? '✓' : '' ?></span><?= $l ?>
          </div>
          <?php endforeach; ?>
          <div class="fp-option-custom" onclick="dashToggleCustom('periodo-ap')">
            <i data-lucide="calendar" width="12" height="12"></i> Período personalizado
          </div>
          <div class="fp-custom-period" id="fp-custom-periodo-ap">
            <div class="fp-custom-row"><label>De</label><input type="date" id="fp-ini-ap" value="<?= h($filtros['periodo_inicio'] ?? '') ?>"></div>
            <div class="fp-custom-row"><label>Até</label><input type="date" id="fp-fim-ap" value="<?= h($filtros['periodo_fim'] ?? '') ?>"></div>
            <button type="button" class="fp-custom-apply" onclick="dashApplyCustom('ap')">Aplicar</button>
          </div>
        </div>
      </div>
      <input type="hidden" name="ap_periodo_preset" id="inp-periodo_preset-ap" value="<?= h($periodoPreset ?? 'todos') ?>">
      <input type="hidden" name="ap_periodo_inicio" id="inp-periodo_inicio-ap" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="ap_periodo_fim"    id="inp-periodo_fim-ap"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">
      <!-- Grupo -->
      <div class="fp-wrap" id="fp-grupo-ap">
        <button type="button" class="filter-pill" onclick="dashToggleFP('grupo-ap')">
          <span>Grupo</span>
          <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
        </button>
        <div class="fp-dropdown" id="fp-dd-grupo-ap">
          <?php foreach ($dropdowns['grupos'] as $g): ?>
          <div class="fp-option" onclick="dashSelectSingle('grupo-ap','grupo_id','<?= h($g['id']) ?>','<?= h($g['nome']) ?>')">
            <span class="fp-check"><?= ($filtros['grupo_id'] ?? '') === $g['id'] ? '✓' : '' ?></span>
            <?= h($g['nome']) ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <input type="hidden" name="grupo_id" id="inp-grupo_id-ap" value="<?= h($filtros['grupo_id'] ?? '') ?>">
      <button type="submit" style="margin-left:auto;padding:8px 16px;background:var(--color-primary);color:#fff;border:none;border-radius:4px;font-family:var(--font-bold);font-size:14px;cursor:pointer">Filtrar</button>
    </form>
  </div>
</div>

<!-- Fila por aprovador -->
<div class="section-card" style="margin-bottom:20px">
  <div class="section-card-header">
    <i data-lucide="users" width="16" height="16" style="color:var(--color-primary)"></i>
    <span class="section-card-title">Fila por aprovador</span>
    <span class="section-count"><?= $dados['fila_total'] ?? count($fila) ?></span>
  </div>
  <div class="tbl-wrap">
    <table class="data-table tbl-fila">
      <colgroup>
        <col style="width:22%"><col style="width:9%"><col style="width:16%">
        <col style="width:16%"><col style="width:16%"><col style="width:21%">
      </colgroup>
      <thead><tr><th>Aprovador</th><th style="text-align:center">Na fila</th><th>Processo mais antigo</th><th>Tempo médio parado com o aprovador</th><th style="text-align:center">Média de aprovação</th><th>Situação</th></tr></thead>
      <tbody>
        <?php if (empty($fila)): ?>
        <tr><td colspan="6" class="empty-state">Nenhum processo aguardando aprovação.</td></tr>
        <?php else: foreach ($fila as $f):
          $gargalo = (int)$f['na_fila'] >= 5 || (int)($f['dias_mais_antigo'] ?? 0) > 7;
        ?>
        <tr class="<?= $gargalo ? 'row-atrasado' : '' ?>">
          <td><strong><?= h($f['aprovador']) ?></strong></td>
          <td style="text-align:center"><strong style="<?= $gargalo ? 'color:var(--color-danger)' : '' ?>"><?= (int)$f['na_fila'] ?></strong></td>
          <td><?= (int)($f['dias_mais_antigo'] ?? 0) ?> dias atrás</td>
          <td><?= $f['dias_parado_aprovador'] ?? '0' ?> dias</td>
          <td style="text-align:center"><?= $f['media_aprovacao'] ?? '—' ?> dias</td>
          <td><?= $gargalo ? '<span class="badge badge-danger">Gargalo</span>' : '<span class="badge badge-success">Ok</span>' ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (($dados['fila_total_paginas'] ?? 1) > 1):
      $currentPage = $dados['fila_pagina'];
      $totalPages  = $dados['fila_total_paginas'];
      $module      = 'dashboard';
      $action      = 'index';
      $pageParam   = 'ap_fila_page';
      $extraParams = $extraParamsApBase;
  ?>
  <div style="display:flex; justify-content:flex-end; padding:12px 20px; border-top:1px solid #EAEAEA">
    <?php include VIEWS . '/components/pagination.php'; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Fila por grupo -->
<div class="section-card" style="margin-bottom:20px">
  <div class="section-card-header">
    <i data-lucide="users-2" width="16" height="16" style="color:var(--color-primary)"></i>
    <span class="section-card-title">Fila por grupo</span>
    <span class="section-count"><?= $dados['fila_grupos_total'] ?? count($filaGrupos) ?></span>
  </div>
  <div class="tbl-wrap">
    <table class="data-table tbl-fila">
      <colgroup>
        <col style="width:22%"><col style="width:9%"><col style="width:16%">
        <col style="width:16%"><col style="width:16%"><col style="width:21%">
      </colgroup>
      <thead><tr><th>Grupo</th><th style="text-align:center">Na fila</th><th>Processo mais antigo</th><th>Tempo médio parado no grupo</th><th style="text-align:center">Média de aprovação</th><th>Situação</th></tr></thead>
      <tbody>
        <?php if (empty($filaGrupos)): ?>
        <tr><td colspan="6" class="empty-state">Nenhum processo aguardando aprovação de grupo.</td></tr>
        <?php else: foreach ($filaGrupos as $f):
          $gargalo = (int)$f['na_fila'] >= 5 || (int)($f['dias_mais_antigo'] ?? 0) > 7;
        ?>
        <tr class="<?= $gargalo ? 'row-atrasado' : '' ?>">
          <td><strong><?= h($f['grupo']) ?></strong></td>
          <td style="text-align:center"><strong style="<?= $gargalo ? 'color:var(--color-danger)' : '' ?>"><?= (int)$f['na_fila'] ?></strong></td>
          <td><?= (int)($f['dias_mais_antigo'] ?? 0) ?> dias atrás</td>
          <td><?= $f['dias_parado_grupo'] ?? '0' ?> dias</td>
          <td style="text-align:center"><?= $f['media_aprovacao'] ?? '—' ?> dias</td>
          <td><?= $gargalo ? '<span class="badge badge-danger">Gargalo</span>' : '<span class="badge badge-success">Ok</span>' ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (($dados['fila_grupos_total_paginas'] ?? 1) > 1):
      $currentPage = $dados['fila_grupos_pagina'];
      $totalPages  = $dados['fila_grupos_total_paginas'];
      $module      = 'dashboard';
      $action      = 'index';
      $pageParam   = 'ap_filagrupo_page';
      $extraParams = $extraParamsApBase;
  ?>
  <div style="display:flex; justify-content:flex-end; padding:12px 20px; border-top:1px solid #EAEAEA">
    <?php include VIEWS . '/components/pagination.php'; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Processos devolvidos -->
<div class="section-card" style="margin-bottom:20px">
  <div class="section-card-header">
    <span class="section-card-title">Processos devolvidos no período</span>
    <span class="section-count red"><?= $dados['devolvidos_total'] ?? count($devolvidos) ?></span>
  </div>
  <div class="tbl-wrap">
    <table class="data-table">
      <thead><tr><th>Processo</th><th>Fornecedor</th><th>Motivo</th><th>Devolvido por</th><th>Status atual</th></tr></thead>
      <tbody>
        <?php if (empty($devolvidos)): ?>
        <tr><td colspan="5" class="empty-state">Nenhum processo devolvido no período.</td></tr>
        <?php else: foreach ($devolvidos as $d): ?>
        <tr>
          <td><a class="link-cell" href="<?= h(linkDetalheProcesso($d['id'], 'dashboard')) ?>"><?= h($d['codigo']) ?></a></td>
          <td><?= h($d['fornecedor'] ?? '—') ?></td>
          <td><?= h($d['motivo'] ?? '—') ?></td>
          <td><?= h($d['devolvido_por'] ?? '—') ?></td>
          <td><?= statusBadge($d['status_atual']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (($dados['devolvidos_total_paginas'] ?? 1) > 1):
      $currentPage = $dados['devolvidos_pagina'];
      $totalPages  = $dados['devolvidos_total_paginas'];
      $module      = 'dashboard';
      $action      = 'index';
      $pageParam   = 'ap_devolvidos_page';
      $extraParams = $extraParamsApBase;
  ?>
  <div style="display:flex; justify-content:flex-end; padding:12px 20px; border-top:1px solid #EAEAEA">
    <?php include VIEWS . '/components/pagination.php'; ?>
  </div>
  <?php endif; ?>
</div>

<!-- SLA por etapa -->
<div class="section-card">
  <div class="section-card-header">
    <span class="section-card-title">SLA por etapa — tempo médio vs. esperado</span>
  </div>
  <div class="tbl-wrap">
    <table class="data-table">
      <thead><tr><th>Etapa</th><th>Tempo esperado</th><th>Tempo médio real</th><th>Variação</th><th>Situação</th></tr></thead>
      <tbody>
        <?php if (empty($sla)): ?>
        <tr><td colspan="5" class="empty-state">Sem dados de SLA no período.</td></tr>
        <?php else: foreach ($sla as $s): ?>
        <tr>
          <td><?= h($s['etapa']) ?></td>
          <td><?= $s['esperado_dias'] !== null ? $s['esperado_dias'] . ' dias' : '—' ?></td>
          <td><?= $s['media_dias'] !== null ? $s['media_dias'] . ' dias' : '—' ?></td>
          <td style="color:<?= ($s['variacao_pct'] ?? 0) > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>">
            <?= $s['variacao_pct'] !== null ? (($s['variacao_pct'] > 0 ? '+' : '') . $s['variacao_pct'] . '%') : '—' ?>
          </td>
          <td><?php
            echo match($s['situacao']) {
                'vencido'   => '<span class="sla-badge sla-vencido">Gargalo</span>',
                'atencao'   => '<span class="sla-badge sla-atencao">Atenção</span>',
                'ok'        => '<span class="sla-badge sla-ok">Dentro do SLA</span>',
                default     => '<span class="sla-badge sla-sem">Sem SLA</span>',
            };
          ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($aba === 'financeiro'): ?>
<!-- ============================================================ -->
<!-- ABA: FINANCEIRO -->
<!-- ============================================================ -->
<?php $kpis = $dados['kpis']; $pendentes = $dados['pendentes']; $porLoja = $dados['por_loja']; $porSetor = $dados['por_setor']; $porDepartamento = $dados['por_departamento']; ?>

<h1 style="font-family:var(--font-bold);font-size:22px;color:var(--color-dark);margin-bottom:20px">
  Situação <span style="color:var(--color-primary)">financeira</span>
</h1>

<div class="kpi-grid-4">
  <div class="kpi-card-lg accent-amber">
    <div class="kpi-label">Pendente de pagamento</div>
    <div class="kpi-value amber"><?= dashMoeda($kpis['pendente']) ?></div>
    <div class="kpi-sub">Aprovados, ainda não vencidos</div>
    <div class="kpi-context">
      <?= $kpis['total_aberto'] > 0 ? round($kpis['pendente'] / $kpis['total_aberto'] * 100) : 0 ?>% do total em aberto
    </div>
  </div>
  <div class="kpi-card-lg accent-red">
    <div class="kpi-label">Atrasado (vencido)</div>
    <div class="kpi-value red"><?= dashMoeda($kpis['vencido']) ?></div>
    <div class="kpi-sub">Vencimento já passou</div>
    <div class="kpi-context">
      <?= $kpis['total_aberto'] > 0 ? round($kpis['vencido'] / $kpis['total_aberto'] * 100) : 0 ?>% do total em aberto
    </div>
  </div>
  <div class="kpi-card-lg accent-green">
    <div class="kpi-label">Pago no período</div>
    <div class="kpi-value green"><?= dashMoeda($kpis['pago_periodo']) ?></div>
    <div class="kpi-sub"><?= h($periodoLabel) ?></div>
    <?= dashVariacao($kpis['pago_periodo'], $kpis['pago_periodo_anterior']) ?>
  </div>
  <div class="kpi-card-lg accent-blue">
    <div class="kpi-label">Volume total em aberto</div>
    <div class="kpi-value blue"><?= dashMoeda($kpis['total_aberto']) ?></div>
    <div class="kpi-sub">Pendente + atrasado</div>
  </div>
</div>

<!-- Lista pendentes -->
<div class="section-card" style="overflow:visible;margin-bottom:20px">
  <div class="section-card-header">
    <i data-lucide="clock" width="16" height="16" style="color:var(--color-warning)"></i>
    <span class="section-card-title">Tudo pendente de pagamento</span>
    <span class="section-count amber"><?= $dados['pendentes_total'] ?? count($pendentes) ?></span>
  </div>
  <div class="filter-bar">
    <form method="get" action="" style="display:contents">
      <input type="hidden" name="module" value="dashboard">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="financeiro">
      <div class="filter-search">
        <i data-lucide="search" width="14" height="14" style="color:var(--color-gray-medium)"></i>
        <input type="text" name="busca" value="<?= h($filtros['busca'] ?? '') ?>" placeholder="Buscar processo, fornecedor...">
      </div>
      <!-- Período -->
      <div class="fp-wrap" id="fp-periodo-fi">
        <button type="button" class="filter-pill<?= ($periodoPreset ?? 'todos') !== 'todos' ? ' active' : '' ?>"
                onclick="dashToggleFP('periodo-fi')">
          <i data-lucide="calendar" width="12" height="12"></i>
          <span><?= h($periodoLabel) ?></span>
          <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
        </button>
        <div class="fp-dropdown" id="fp-dd-periodo-fi">
          <?php foreach (['todos'=>'Todos','esta-semana'=>'Esta semana','este-mes'=>'Este mês','este-trimestre'=>'Este trimestre'] as $v => $l): ?>
          <div class="fp-option<?= ($periodoPreset??'') === $v ? ' selected' : '' ?>"
               onclick="dashSelectPeriodo('fi','<?= $v ?>','<?= $l ?>')">
            <span class="fp-check"><?= ($periodoPreset??'') === $v ? '✓' : '' ?></span><?= $l ?>
          </div>
          <?php endforeach; ?>
          <div class="fp-option-custom" onclick="dashToggleCustom('periodo-fi')">
            <i data-lucide="calendar" width="12" height="12"></i> Período personalizado
          </div>
          <div class="fp-custom-period" id="fp-custom-periodo-fi">
            <div class="fp-custom-row"><label>De</label><input type="date" id="fp-ini-fi" value="<?= h($filtros['periodo_inicio'] ?? '') ?>"></div>
            <div class="fp-custom-row"><label>Até</label><input type="date" id="fp-fim-fi" value="<?= h($filtros['periodo_fim'] ?? '') ?>"></div>
            <button type="button" class="fp-custom-apply" onclick="dashApplyCustom('fi')">Aplicar</button>
          </div>
        </div>
      </div>
      <input type="hidden" name="fi_periodo_preset" id="inp-periodo_preset-fi" value="<?= h($periodoPreset ?? 'todos') ?>">
      <input type="hidden" name="fi_periodo_inicio" id="inp-periodo_inicio-fi" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="fi_periodo_fim"    id="inp-periodo_fim-fi"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">
      <!-- Situação -->
      <div class="fp-wrap" id="fp-sit-fi">
        <button type="button" class="filter-pill<?= !empty($filtros['situacao']) ? ' active' : '' ?>"
                onclick="dashToggleFP('sit-fi')">
          <span id="fpl-sit-fi"><?= !empty($filtros['situacao']) ? h($filtros['situacao']) : 'Situação' ?></span>
          <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
        </button>
        <div class="fp-dropdown" id="fp-dd-sit-fi">
          <?php foreach (['a-vencer'=>'A vencer','vence-hoje'=>'Vence hoje','vencido'=>'Atrasado'] as $v=>$l): ?>
          <div class="fp-option" onclick="dashSelectSingle('sit-fi','situacao','<?= $v ?>','<?= $l ?>')">
            <span class="fp-check"><?= ($filtros['situacao'] ?? '') === $v ? '✓' : '' ?></span><?= $l ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <input type="hidden" name="situacao" id="inp-situacao-fi" value="<?= h($filtros['situacao'] ?? '') ?>">
      <!-- Loja — multi-seleção; sem nada marcado vale "todas as que enxergo" -->
      <?php
      $flSufixo   = 'loja-fi';
      $flOpcoes   = $dropdowns['filiais'];
      $flSel      = (array) ($filtros['filial_id'] ?? []);
      $flVariante = 'pill';
      $flLabel    = 'Loja';
      include VIEWS . '/components/filtro-lojas.php';
      ?>
      <button type="submit" style="margin-left:auto;padding:8px 16px;background:var(--color-primary);color:#fff;border:none;border-radius:4px;font-family:var(--font-bold);font-size:14px;cursor:pointer">Filtrar</button>
    </form>
  </div>
  <div class="tbl-wrap">
    <table class="data-table">
      <thead><tr><th>Processo</th><th>Fornecedor</th><th>Loja</th><th>Valor</th><th>Vencimento</th><th>Situação</th></tr></thead>
      <tbody>
        <?php if (empty($pendentes)): ?>
        <tr><td colspan="6" class="empty-state">Nenhum pendente de pagamento.</td></tr>
        <?php else: foreach ($pendentes as $p):
          $sit = $p['situacao_calc'];
          $rowCls = match($sit) {
              'vencido'    => 'row-atrasado',
              'vence-hoje' => 'row-warn',
              default      => '',
          };
        ?>
        <tr class="<?= $rowCls ?>">
          <td><a class="link-cell" href="<?= h(linkDetalheProcesso($p['processo_id'], 'dashboard')) ?>"><?= h($p['proc_codigo']) ?></a></td>
          <td><?= h($p['fornecedor'] ?? '—') ?></td>
          <td><?= h($p['filial_nome'] ?? '—') ?></td>
          <td style="font-weight:700"><?= dashMoeda((float)$p['valor']) ?></td>
          <td style="<?= $sit === 'vencido' ? 'color:var(--color-danger);font-weight:700' : ($sit === 'vence-hoje' ? 'color:var(--color-warning);font-weight:700' : '') ?>">
            <?= DateHelper::formatBr($p['vencimento']) ?>
          </td>
          <td><?= match($sit) {
            'vencido'    => '<span class="badge badge-danger">Atrasado</span>',
            'vence-hoje' => '<span class="badge badge-warning">Vence hoje</span>',
            default      => '<span class="badge badge-muted">A vencer</span>',
          }; ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (($dados['pendentes_total_paginas'] ?? 1) > 1):
      $currentPage = $dados['pendentes_pagina'];
      $totalPages  = $dados['pendentes_total_paginas'];
      $module      = 'dashboard';
      $action      = 'index';
      $pageParam   = 'fi_page';
      $extraParams = array_filter([
          'aba'              => 'financeiro',
          'busca'            => $filtros['busca'] ?? '',
          'fi_periodo_preset'=> $filtros['periodo_preset'] ?? '',
          'fi_periodo_inicio'=> $filtros['periodo_inicio'] ?? '',
          'fi_periodo_fim'   => $filtros['periodo_fim'] ?? '',
          'situacao'         => $filtros['situacao'] ?? '',
          'filial_id'        => !empty($filtros['filial_id']) ? (array) $filtros['filial_id'] : '',
      ], fn($v) => $v !== '' && $v !== null);
  ?>
  <div style="display:flex; justify-content:flex-end; padding:12px 20px; border-top:1px solid #EAEAEA">
    <?php include VIEWS . '/components/pagination.php'; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Volume por loja -->
<div class="section-card" style="margin-bottom:20px">
  <div class="section-card-header">
    <span class="section-card-title">Volume por loja</span>
  </div>
  <div class="tbl-wrap">
    <table class="data-table tbl-volume">
      <colgroup>
        <col style="width:24%"><col style="width:19%"><col style="width:19%">
        <col style="width:19%"><col style="width:19%">
      </colgroup>
      <thead><tr><th>Loja</th><th>Em aberto</th><th>Atrasado</th><th>Pago no período</th><th>Total movimentado</th></tr></thead>
      <tbody>
        <?php if (empty($porLoja)): ?>
        <tr><td colspan="5" class="empty-state">Sem dados.</td></tr>
        <?php else: foreach ($porLoja as $l): ?>
        <tr>
          <td><?= h($l['loja'] ?? 'Sem loja') ?></td>
          <td style="font-weight:700"><?= dashMoeda((float)$l['em_aberto']) ?></td>
          <td style="font-weight:700;color:<?= (float)$l['atrasado'] > 0 ? 'var(--color-danger)' : 'inherit' ?>"><?= dashMoeda((float)$l['atrasado']) ?></td>
          <td style="font-weight:700;color:var(--color-success)"><?= dashMoeda((float)$l['pago_periodo']) ?></td>
          <td style="font-weight:700"><?= dashMoeda((float)$l['total_movimentado']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Volume por departamento -->
<div class="section-card" style="margin-bottom:20px">
  <div class="section-card-header">
    <span class="section-card-title">Volume por departamento</span>
  </div>
  <div class="tbl-wrap">
    <table class="data-table tbl-volume">
      <colgroup>
        <col style="width:24%"><col style="width:19%"><col style="width:19%">
        <col style="width:19%"><col style="width:19%">
      </colgroup>
      <thead><tr><th>Departamento</th><th>Em aberto</th><th>Atrasado</th><th>Pago no período</th><th>Total movimentado</th></tr></thead>
      <tbody>
        <?php if (empty($porDepartamento)): ?>
        <tr><td colspan="5" class="empty-state">Sem dados.</td></tr>
        <?php else: foreach ($porDepartamento as $d): ?>
        <tr>
          <td><?= h($d['departamento'] ?? 'Sem departamento') ?></td>
          <td style="font-weight:700"><?= dashMoeda((float)$d['em_aberto']) ?></td>
          <td style="font-weight:700;color:<?= (float)$d['atrasado'] > 0 ? 'var(--color-danger)' : 'inherit' ?>"><?= dashMoeda((float)$d['atrasado']) ?></td>
          <td style="font-weight:700;color:var(--color-success)"><?= dashMoeda((float)$d['pago_periodo']) ?></td>
          <td style="font-weight:700"><?= dashMoeda((float)$d['total_movimentado']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Volume por setor -->
<div class="section-card">
  <div class="section-card-header">
    <span class="section-card-title">Volume por setor</span>
  </div>
  <div class="tbl-wrap">
    <table class="data-table tbl-volume">
      <colgroup>
        <col style="width:24%"><col style="width:19%"><col style="width:19%">
        <col style="width:19%"><col style="width:19%">
      </colgroup>
      <thead><tr><th>Setor</th><th>Em aberto</th><th>Atrasado</th><th>Pago no período</th><th>Total movimentado</th></tr></thead>
      <tbody>
        <?php if (empty($porSetor)): ?>
        <tr><td colspan="5" class="empty-state">Sem dados.</td></tr>
        <?php else: foreach ($porSetor as $s): ?>
        <tr>
          <td><?= h($s['setor'] ?? 'Sem setor') ?></td>
          <td style="font-weight:700"><?= dashMoeda((float)$s['em_aberto']) ?></td>
          <td style="font-weight:700;color:<?= (float)$s['atrasado'] > 0 ? 'var(--color-danger)' : 'inherit' ?>"><?= dashMoeda((float)$s['atrasado']) ?></td>
          <td style="font-weight:700;color:var(--color-success)"><?= dashMoeda((float)$s['pago_periodo']) ?></td>
          <td style="font-weight:700"><?= dashMoeda((float)$s['total_movimentado']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($aba === 'desempenho'): ?>
<!-- ============================================================ -->
<!-- ABA: DESEMPENHO -->
<!-- ============================================================ -->
<?php $kpis = $dados['kpis']; $porEtapa = $dados['por_etapa']; $ranking = $dados['ranking']; $comparativo = $dados['comparativo']; ?>

<h1 style="font-family:var(--font-bold);font-size:22px;color:var(--color-dark);margin-bottom:20px">
  Análise de <span style="color:var(--color-primary)">desempenho</span>
</h1>

<!-- Filtros -->
<div class="section-card" style="overflow:visible;margin-bottom:20px">
  <div class="filter-bar">
    <form method="get" action="" style="display:contents">
      <input type="hidden" name="module" value="dashboard">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="desempenho">
      <!-- Período -->
      <div class="fp-wrap" id="fp-periodo-de">
        <button type="button" class="filter-pill<?= ($periodoPreset ?? 'todos') !== 'todos' ? ' active' : '' ?>"
                onclick="dashToggleFP('periodo-de')">
          <i data-lucide="calendar" width="12" height="12"></i>
          <span><?= h($periodoLabel) ?></span>
          <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
        </button>
        <div class="fp-dropdown" id="fp-dd-periodo-de">
          <?php foreach (['todos'=>'Todos','esta-semana'=>'Esta semana','este-mes'=>'Este mês','este-trimestre'=>'Este trimestre'] as $v => $l): ?>
          <div class="fp-option<?= ($periodoPreset??'') === $v ? ' selected' : '' ?>"
               onclick="dashSelectPeriodo('de','<?= $v ?>','<?= $l ?>')">
            <span class="fp-check"><?= ($periodoPreset??'') === $v ? '✓' : '' ?></span><?= $l ?>
          </div>
          <?php endforeach; ?>
          <div class="fp-option-custom" onclick="dashToggleCustom('periodo-de')">
            <i data-lucide="calendar" width="12" height="12"></i> Período personalizado
          </div>
          <div class="fp-custom-period" id="fp-custom-periodo-de">
            <div class="fp-custom-row"><label>De</label><input type="date" id="fp-ini-de" value="<?= h($filtros['periodo_inicio'] ?? '') ?>"></div>
            <div class="fp-custom-row"><label>Até</label><input type="date" id="fp-fim-de" value="<?= h($filtros['periodo_fim'] ?? '') ?>"></div>
            <button type="button" class="fp-custom-apply" onclick="dashApplyCustom('de')">Aplicar</button>
          </div>
        </div>
      </div>
      <input type="hidden" name="de_periodo_preset" id="inp-periodo_preset-de" value="<?= h($periodoPreset ?? 'todos') ?>">
      <input type="hidden" name="de_periodo_inicio" id="inp-periodo_inicio-de" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="de_periodo_fim"    id="inp-periodo_fim-de"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">
      <button type="submit" style="margin-left:auto;padding:8px 16px;background:var(--color-primary);color:#fff;border:none;border-radius:4px;font-family:var(--font-bold);font-size:14px;cursor:pointer">Filtrar</button>
    </form>
  </div>
</div>

<!-- KPIs integrados -->
<div class="kpi-grid-4" style="margin-bottom:20px">
  <div class="kpi-card-lg accent-blue">
    <div class="kpi-label">Tempo médio de ciclo</div>
    <div class="kpi-value blue"><?= $kpis['tempo_medio'] ?> dias</div>
    <?php if (!empty($kpis['tempo_medio_fallback'])): ?>
      <div class="kpi-sub">Do início ao pagamento</div>
      <div class="kpi-context">
        Amostra do período insuficiente — histórico completo (<?= $kpis['tempo_medio_amostra'] ?> processos)
      </div>
    <?php else: ?>
      <div class="kpi-sub">Do início ao pagamento (<?= $kpis['tempo_medio_amostra'] ?> concluídos no período)</div>
      <?= dashVariacao($kpis['tempo_medio'], $kpis['tempo_medio_anterior']) ?>
    <?php endif; ?>
  </div>
  <div class="kpi-card-lg accent-purple">
    <div class="kpi-label">Processos no período</div>
    <div class="kpi-value purple"><?= $kpis['total_periodo'] ?></div>
    <div class="kpi-sub">Volume de entrada</div>
    <?= dashVariacao((float)$kpis['total_periodo'], (float)$kpis['total_periodo_anterior']) ?>
  </div>
  <div class="kpi-card-lg accent-green">
    <div class="kpi-label">Taxa de conclusão</div>
    <div class="kpi-value green"><?= $kpis['taxa_conclusao'] ?>%</div>
    <div class="kpi-sub">Concluídos vs. abertos</div>
    <?= dashVariacao((float)$kpis['taxa_conclusao'], (float)$kpis['taxa_conclusao_anterior']) ?>
  </div>
  <div class="kpi-card-lg accent-amber">
    <div class="kpi-label">Taxa de devolução</div>
    <div class="kpi-value amber"><?= $kpis['taxa_devolucao'] ?>%</div>
    <div class="kpi-sub">Processos devolvidos ao menos 1x</div>
    <?= dashVariacao((float)$kpis['taxa_devolucao'], (float)$kpis['taxa_devolucao_anterior']) ?>
  </div>
</div>

<!-- Tempo por etapa -->
<div class="section-card" style="margin-bottom:20px">
  <div class="section-card-header">
    <span class="section-card-title">Tempo médio por etapa</span>
  </div>
  <?php if (!empty($porEtapa) && !empty($porEtapa[0]['_fallback'])): ?>
  <div style="padding:0 20px 12px;font-size:12.5px;color:var(--color-gray-medium)">
    Amostra do período não cobre todas as etapas cadastradas — exibindo histórico completo.
  </div>
  <?php endif; ?>
  <div class="tbl-wrap">
    <table class="data-table">
      <thead><tr><th style="width:34px"></th><th>Etapa / Fluxo</th><th>Tempo médio</th><th>Mínimo</th><th>Máximo</th><th>% do ciclo</th><th>Situação</th></tr></thead>
      <tbody>
        <?php if (empty($porEtapa)): ?>
        <tr><td colspan="7" class="empty-state">Sem dados de etapas no período.</td></tr>
        <?php else:
          $porFluxo = [];
          foreach ($porEtapa as $e) {
              $porFluxo[$e['template_nome'] ?? 'Sem fluxo'][] = $e;
          }
          $fluxoIdx = 0;
          foreach ($porFluxo as $fluxoNome => $etapas):
            $fluxoIdx++;
            $somaMedia  = array_sum(array_column($etapas, 'media_dias'));
            $minGlobal  = min(array_column($etapas, 'min_dias'));
            $maxGlobal  = max(array_column($etapas, 'max_dias'));
            $temGargalo = in_array(true, array_column($etapas, 'gargalo'), true);
        ?>
        <tr class="tbl-fluxo-header" onclick="dashToggleFluxo(<?= $fluxoIdx ?>)">
          <td style="text-align:center">
            <span class="tbl-fluxo-toggle" id="fluxo-chevron-<?= $fluxoIdx ?>">+</span>
          </td>
          <td style="font-family:var(--font-bold)">
            <?= h($fluxoNome) ?>
            <span class="section-count" style="margin-left:8px"><?= count($etapas) ?> etapa<?= count($etapas) > 1 ? 's' : '' ?></span>
          </td>
          <td style="font-weight:700"><?= round($somaMedia, 1) ?> dias</td>
          <td><?= $minGlobal !== null ? $minGlobal . ' dias' : '—' ?></td>
          <td><?= $maxGlobal !== null ? $maxGlobal . ' dias' : '—' ?></td>
          <td>—</td>
          <td><?= $temGargalo ? '<span class="badge badge-danger">Gargalo</span>' : '<span class="badge badge-success">Ok</span>' ?></td>
        </tr>
        <?php foreach ($etapas as $e): ?>
        <tr class="tbl-fluxo-child fluxo-child-<?= $fluxoIdx ?> <?= $e['gargalo'] ? 'row-atrasado' : '' ?>" style="display:none">
          <td></td>
          <td style="padding-left:36px"><?= h($e['etapa']) ?></td>
          <td><?= $e['media_dias'] !== null ? $e['media_dias'] . ' dias' : '—' ?></td>
          <td><?= $e['min_dias'] !== null ? $e['min_dias'] . ' dias' : '—' ?></td>
          <td><?= $e['max_dias'] !== null ? $e['max_dias'] . ' dias' : '—' ?></td>
          <td>
            <div class="progress-bar-wrap">
              <div class="progress-bar">
                <div class="progress-bar-fill<?= $e['gargalo'] ? '' : ' green' ?>"
                     style="width:<?= min(100, $e['pct_ciclo']) ?>%"></div>
              </div>
              <span style="font-size:13px;color:var(--color-gray-medium)"><?= $e['pct_ciclo'] ?>%</span>
            </div>
          </td>
          <td><?= match($e['situacao'] ?? 'sem-dados') {
              'vencido'  => '<span class="sla-badge sla-vencido">Gargalo</span>',
              'atencao'  => '<span class="sla-badge sla-atencao">Atenção</span>',
              'ok'       => '<span class="sla-badge sla-ok">Normal</span>',
              default    => '<span class="sla-badge sla-sem">Sem SLA</span>',
          }; ?></td>
        </tr>
        <?php endforeach; endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Ranking de usuários -->
<div class="section-card" style="margin-bottom:20px">
  <div class="section-card-header">
    <span class="section-card-title">Ranking de usuários</span>
    <span class="section-count"><?= $dados['ranking_total'] ?? count($ranking) ?></span>
  </div>
  <div class="tbl-wrap">
    <table class="data-table">
      <thead><tr><th>Usuário</th><th>Função</th><th>Processos abertos</th><th>Aprovados/Concluídos</th><th>Tempo médio resp.</th><th>Devoluções</th></tr></thead>
      <tbody>
        <?php if (empty($ranking)): ?>
        <tr><td colspan="6" class="empty-state">Sem dados de usuários no período.</td></tr>
        <?php else: foreach ($ranking as $u):
          $critico = (float)($u['media_resposta'] ?? 0) > 5 || (int)($u['devolucoes'] ?? 0) > 3;
        ?>
        <tr class="<?= $critico ? 'row-atrasado' : '' ?>">
          <td><?= h($u['nome']) ?></td>
          <td><?= h(ucfirst($u['funcao'] ?? '—')) ?></td>
          <td><?= (int)($u['processos_abertos'] ?? 0) ?></td>
          <td><?= (int)$u['aprovados_concluidos'] ?></td>
          <td><?= $u['media_resposta'] ? $u['media_resposta'] . ' dias' : '—' ?></td>
          <td><?= (int)($u['devolucoes'] ?? 0) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (($dados['ranking_total_paginas'] ?? 1) > 1):
      $currentPage = $dados['ranking_pagina'];
      $totalPages  = $dados['ranking_total_paginas'];
      $module      = 'dashboard';
      $action      = 'index';
      $pageParam   = 'de_page';
      $extraParams = array_filter([
          'aba'              => 'desempenho',
          'de_periodo_preset'=> $filtros['periodo_preset'] ?? '',
          'de_periodo_inicio'=> $filtros['periodo_inicio'] ?? '',
          'de_periodo_fim'   => $filtros['periodo_fim'] ?? '',
      ], fn($v) => $v !== '' && $v !== null);
  ?>
  <div style="display:flex; justify-content:flex-end; padding:12px 20px; border-top:1px solid #EAEAEA">
    <?php include VIEWS . '/components/pagination.php'; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Comparativo mensal -->
<div class="section-card">
  <div class="section-card-header">
    <span class="section-card-title">Comparativo mensal — últimos 12 meses</span>
  </div>
  <div class="tbl-wrap">
    <table class="comp-table">
      <thead>
        <tr>
          <th style="text-align:left">Mês</th>
          <th>Abertos</th><th>Concluídos</th><th>Cancelados</th>
          <th>Em andamento</th><th>Valor total</th><th>Tempo médio</th><th>% Conclusão</th>
        </tr>
      </thead>
      <tbody>
        <?php $mesAtual = date('Y-m'); foreach ($comparativo as $c): ?>
        <tr class="<?= $c['mes'] === $mesAtual ? 'mes-atual' : '' ?>">
          <td><?= ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'][(int)date('m', strtotime($c['mes'] . '-01')) - 1] . '/' . date('Y', strtotime($c['mes'] . '-01')) ?><?= $c['mes'] === $mesAtual ? ' <span style="font-size:12px;color:var(--color-primary)">(atual)</span>' : '' ?></td>
          <td><?= $c['abertos'] ?></td>
          <td><?= $c['concluidos'] ?></td>
          <td><?= $c['cancelados'] ?></td>
          <td><?= $c['em_andamento'] ?></td>
          <td style="font-weight:700"><?= dashMoeda($c['valor_total']) ?></td>
          <td><?= $c['tempo_medio'] ? $c['tempo_medio'] . 'd' : '—' ?></td>
          <td><?= $c['taxa_conclusao'] ?>%</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

</div><!-- /dash-content -->

<script>
// ─── Dropdown helpers ────────────────────────────────────────────
function dashToggleFP(id) {
    var wrap = document.getElementById('fp-' + id);
    var dd   = document.getElementById('fp-dd-' + id);
    if (!wrap || !dd) return;
    var open = wrap.classList.toggle('open');
    if (open) {
        // Fecha outros abertos
        document.querySelectorAll('.fp-wrap.open').forEach(function(el) {
            if (el !== wrap) el.classList.remove('open');
        });
    }
}

function dashSelectPeriodo(suffix, preset, label) {
    document.getElementById('inp-periodo_preset-' + suffix).value = preset;
    document.getElementById('inp-periodo_inicio-' + suffix).value = '';
    document.getElementById('inp-periodo_fim-'    + suffix).value = '';
    // Atualiza label do botão
    var wrap = document.getElementById('fp-periodo-' + suffix);
    if (wrap) wrap.querySelector('span').textContent = label;
    document.getElementById('fp-' + 'periodo-' + suffix)?.classList.remove('open');
    wrap?.classList.remove('open');
    // Aplica só quando o usuário clicar em "Filtrar"
}

function dashToggleCustom(id) {
    var cp = document.getElementById('fp-custom-' + id);
    if (cp) cp.classList.toggle('open');
}

function dashApplyCustom(suffix) {
    var ini = document.getElementById('fp-ini-' + suffix)?.value;
    var fim = document.getElementById('fp-fim-' + suffix)?.value;
    if (!ini || !fim) return;
    document.getElementById('inp-periodo_preset-' + suffix).value = 'personalizado';
    document.getElementById('inp-periodo_inicio-' + suffix).value = ini;
    document.getElementById('inp-periodo_fim-'    + suffix).value = fim;
    // Atualiza label do botão com o intervalo escolhido
    var wrap = document.getElementById('fp-periodo-' + suffix);
    if (wrap) {
        var fmt = function(d) { var p = d.split('-'); return p[2] + '/' + p[1]; };
        wrap.querySelector('span').textContent = fmt(ini) + ' - ' + fmt(fim);
    }
    document.getElementById('fp-custom-periodo-' + suffix)?.classList.remove('open');
    wrap?.classList.remove('open');
    // Aplica só quando o usuário clicar em "Filtrar"
}

function dashSelectSingle(wrapperId, inputName, value, label) {
    var wrap = document.getElementById('fp-' + wrapperId);
    if (wrap) {
        var inp = document.getElementById('inp-' + inputName + '-' + wrapperId.split('-').pop());
        if (!inp) {
            // Fallback: busca pelo name
            inp = wrap.closest('form')?.querySelector('input[name="' + inputName + '"]');
        }
        if (inp) inp.value = value;
        wrap.querySelector('span')?.textContent && (wrap.querySelector('button span').textContent = label);
        wrap.classList.remove('open');
        // Aplica só quando o usuário clicar em "Filtrar"
    }
}

// ─── Meus Processos: filtro de período (presets + personalizado) ───────────
function dashSelectPeriodoMp(preset, label) {
    document.getElementById('inp-periodo_preset-mp').value = preset;
    document.getElementById('inp-periodo_inicio-mp').value = '';
    document.getElementById('inp-periodo_fim-mp').value = '';
    var wrap = document.getElementById('fp-periodo-mp');
    if (wrap) wrap.querySelector('span').textContent = label;
    wrap?.classList.remove('open');
}

function dashApplyCustomMp() {
    var ini = document.getElementById('fp-ini-mp')?.value;
    var fim = document.getElementById('fp-fim-mp')?.value;
    if (!ini || !fim) return;
    document.getElementById('inp-periodo_preset-mp').value = 'personalizado';
    document.getElementById('inp-periodo_inicio-mp').value = ini;
    document.getElementById('inp-periodo_fim-mp').value = fim;
    var wrap = document.getElementById('fp-periodo-mp');
    if (wrap) {
        var fmt = function(d) { var p = d.split('-'); return p[2] + '/' + p[1]; };
        wrap.querySelector('span').textContent = fmt(ini) + ' - ' + fmt(fim);
    }
    document.getElementById('fp-custom-periodo-mp')?.classList.remove('open');
    wrap?.classList.remove('open');
}

function dashApplyStatusMp() {
    var checks = document.querySelectorAll('.mp-status-chk:checked');
    var vals = Array.prototype.map.call(checks, function(c) { return c.value; });
    document.getElementById('inp-mp_status').value = vals.join(',');
    document.getElementById('form-mp').submit();
}

// ─── Tempo por etapa: expandir/recolher fluxo ───────────────────────
function dashToggleFluxo(idx) {
    var rows     = document.querySelectorAll('.fluxo-child-' + idx);
    var toggle   = document.getElementById('fluxo-chevron-' + idx);
    var expanded = rows.length > 0 && rows[0].style.display !== 'none';
    rows.forEach(function(r) { r.style.display = expanded ? 'none' : 'table-row'; });
    if (toggle) toggle.textContent = expanded ? '+' : '−';
}

// Fecha dropdowns ao clicar fora
document.addEventListener('click', function(e) {
    if (!e.target.closest('.fp-wrap')) {
        document.querySelectorAll('.fp-wrap.open').forEach(function(el) {
            el.classList.remove('open');
        });
    }
});
</script>
