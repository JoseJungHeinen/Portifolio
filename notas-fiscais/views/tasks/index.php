<?php
// @versao Tar_01 V_24_21/10/2026
/**
 * views/tasks/index.php
 * Minhas Tarefas — fila de aprovação do usuário logado
 *
 * Variáveis esperadas:
 *   $tarefas     array   lista de tarefas (resultado de Task::listByUsuario)
 *   $stats       array   [total, pessoais, grupo, sla_ok, sla_atencao, sla_vencido, sem_sla]
 *   $filtro      string  todas | pessoais | grupo
 *   $busca       string  texto de busca
 *   $currentUser array   [id, nome, perfil]
 *   $flash       array|null [type, message]
 */

$tarefas = $tarefas ?? [];
$stats   = $stats   ?? ['total' => 0, 'pessoais' => 0, 'grupo' => 0, 'sla_ok' => 0, 'sla_atencao' => 0, 'sla_vencido' => 0, 'sem_sla' => 0];
$filtro  = $filtro  ?? 'todas';
$busca   = $busca   ?? '';

// Helper local para formatar valor em BRL
if (!function_exists('formatBrl')) {
    function formatBrl(mixed $val): string {
        // Processo sem nota fiscal tem valor_nf NULL — NULL não é zero.
        if ($val === null || $val === '') return '—';
        return 'R$ ' . number_format((float) $val, 2, ',', '.');
    }
}

// SLA já calculado no model (Task::listByUsuario) via SlaCalculator
?>
<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">

<?php include VIEWS . '/components/alert.php'; ?>

<!-- ── Cabeçalho ─────────────────────────────────────────────── -->
<div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px">
  <div>
    <h1 style="font-size:32px;font-weight:700;color:#141922;margin:0 0 4px;">
      Minhas <span style="color:#2347C8;">Tarefas</span>
    </h1>
    <p style="font-size:14px;color:#888888;margin:0;">Processos aguardando a sua aprovação</p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
  <?php if (Permissions::isAdmin() || Permissions::can('processos', 'ver_todos')): ?>
  <a href="?module=tarefas&action=fila" class="btn btn-secondary" style="text-decoration:none; height:44px; display:inline-flex; align-items:center; gap:8px;">
    <i data-lucide="users" width="18" height="18"></i>
    Fila por pessoa
  </a>
  <?php endif; ?>
  <?php if (!empty($tarefas)): ?>
  <button type="button" id="btn-batch-mode"
          style="display:inline-flex; align-items:center; gap:8px; height:44px; padding:0 20px; border:1.5px solid #2347C8; border-radius:4px; background:#2347C8; font-size:14px; font-weight:700; color:#FFFFFF; cursor:pointer"
          onmouseover="this.style.background='#1B38A0';this.style.borderColor='#1B38A0'" onmouseout="this.style.background=this.classList.contains('active') ? '#1B38A0' : '#2347C8';this.style.borderColor=this.classList.contains('active') ? '#1B38A0' : '#2347C8'">
    <i data-lucide="check-square" width="18" height="18"></i>
    Lote
  </button>
  <?php // Sequencial: NÃO abre modo de seleção — usa o que já está marcado no
        // "Lote" e abre direto o primeiro processo na tela completa. ?>
  <button type="button" id="btn-seq-mode" onclick="iniciarSequencial()"
          style="display:inline-flex; align-items:center; gap:8px; height:44px; padding:0 20px; border:1.5px solid #2347C8; border-radius:4px; background:#fff; font-size:14px; font-weight:700; color:#2347C8; cursor:pointer"
          onmouseover="this.style.background='#EEF2FD'" onmouseout="this.style.background='#fff'">
    <i data-lucide="list-ordered" width="18" height="18"></i>
    Sequencial
  </button>
  <?php endif; ?>
  </div>
</div>

<!-- ── Stats (clicáveis — funcionam como filtro rápido) ────────── -->
<?php
$pctPessoais = $stats['total'] > 0 ? round($stats['pessoais']    / $stats['total'] * 100) : 0;
$pctGrupo    = $stats['total'] > 0 ? round($stats['grupo']       / $stats['total'] * 100) : 0;
$pctVencido  = $stats['total'] > 0 ? round($stats['sla_vencido'] / $stats['total'] * 100) : 0;
$pctAtencao  = $stats['total'] > 0 ? round($stats['sla_atencao'] / $stats['total'] * 100) : 0;
?>
<div class="tasks-stats-bar" style="margin-bottom:24px;">

  <div class="stat-card" id="stat-total" onclick="filterByStatCard('total')" title="Ver todas as tarefas da fila">
    <span class="stat-value"><?= (int) $stats['total'] ?></span>
    <span class="stat-label">Total</span>
    <span class="stat-context">na fila agora</span>
  </div>

  <div class="stat-card" id="stat-pessoal" onclick="filterByStatCard('pessoal')" title="Filtrar: tarefas comigo">
    <span class="stat-value"><?= (int) $stats['pessoais'] ?></span>
    <span class="stat-label">Comigo</span>
    <span class="stat-context"><?= $pctPessoais ?>% da fila</span>
  </div>

  <div class="stat-card" id="stat-grupo" onclick="filterByStatCard('grupo')" title="Filtrar: tarefas do grupo">
    <span class="stat-value"><?= (int) $stats['grupo'] ?></span>
    <span class="stat-label">Grupo</span>
    <span class="stat-context"><?= $pctGrupo ?>% da fila</span>
  </div>

  <?php if (($stats['devolvidos'] ?? 0) > 0): ?>
  <div class="stat-card" id="stat-devolvidos" onclick="filterByStatCard('devolvidos')" title="Filtrar: processos devolvidos (comigo + grupo)">
    <span class="stat-value" style="color:#FF8A2D"><?= (int) $stats['devolvidos'] ?></span>
    <span class="stat-label">Devolvidos</span>
    <span class="stat-context">comigo + grupo</span>
  </div>
  <?php endif; ?>

  <?php if ($stats['sla_vencido'] > 0): ?>
  <div class="stat-card stat-card--sla-vencido" id="stat-sla-vencido" onclick="filterByStatCard('sla-vencido')" title="Filtrar: SLA vencido">
    <span class="stat-value"><?= (int) $stats['sla_vencido'] ?></span>
    <span class="stat-label">SLA vencido</span>
    <span class="stat-context"><?= $pctVencido ?>% da fila</span>
  </div>
  <?php endif; ?>

  <?php if ($stats['sla_atencao'] > 0): ?>
  <div class="stat-card stat-card--sla-atencao" id="stat-sla-atencao" onclick="filterByStatCard('sla-atencao')" title="Filtrar: SLA em atenção">
    <span class="stat-value"><?= (int) $stats['sla_atencao'] ?></span>
    <span class="stat-label">Em atenção</span>
    <span class="stat-context"><?= $pctAtencao ?>% da fila</span>
  </div>
  <?php endif; ?>

</div>

<!-- ── Barra de filtros (linha única) ────────────────────────── -->
<?php
$templatesList = []; $gruposList = []; $deptosList = []; $lojasList = [];
foreach ($tarefas as $t) {
    $tn = $t['template_nome'] ?? '';
    if ($tn !== '' && !in_array($tn, $templatesList)) $templatesList[] = $tn;
    $gn = $t['grupo_nome'] ?? '';
    if ($gn !== '' && !in_array($gn, $gruposList)) $gruposList[] = $gn;
    $dn = $t['setor_nome'] ?? '';
    if ($dn !== '' && !in_array($dn, $deptosList)) $deptosList[] = $dn;
    $ln = $t['filial_nome'] ?? '';
    if ($ln !== '' && !in_array($ln, $lojasList)) $lojasList[] = $ln;
}
sort($templatesList); sort($gruposList); sort($deptosList); sort($lojasList);
$selS = 'height:44px; border:1.5px solid #E5E7EB; border-radius:8px; font-size:14px; font-weight:600; color:#606060; background:#fff; outline:none; cursor:pointer; padding:0 16px; white-space:nowrap; flex-shrink:0; box-sizing:border-box;';
?>
<div style="display:flex; align-items:center; gap:8px; margin-bottom:16px; flex-wrap:nowrap; overflow-x:auto">

  <!-- Fila: sem seletor aqui — os cards de resumo acima é que filtram (Total / Comigo / Grupo) -->

  <!-- Busca -->
  <div style="flex:1; min-width:200px; height:44px; box-sizing:border-box; position:relative; flex-shrink:1">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
         style="position:absolute; left:14px; top:50%; transform:translateY(-50%); pointer-events:none; display:inline-block">
      <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
    <input type="text" id="tasks-search"
           placeholder="Buscar por processo, NF, fornecedor, CNPJ..."
           value="<?= h($busca) ?>" autocomplete="off"
           style="width:100%; height:44px; border:1.5px solid #E5E7EB; border-radius:8px; padding:0 16px 0 40px; font-size:14px; color:#141922; outline:none; box-sizing:border-box"
           onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#E5E7EB'"
           oninput="applyAdvancedFilters()">
  </div>

  <?php // data-label guarda o nome puro da opção: o JS reescreve o texto a cada
        // filtro para pendurar o "(N)" e precisa do rótulo original de volta. ?>

  <!-- Grupo -->
  <select id="filter-grupo" onchange="applyAdvancedFilters()" style="<?= $selS ?>">
    <option value="" data-label="Grupo">Grupo</option>
    <?php foreach ($gruposList as $gn): ?>
    <option value="<?= h(mb_strtolower($gn)) ?>" data-label="<?= h($gn) ?>"><?= h($gn) ?></option>
    <?php endforeach; ?>
  </select>

  <!-- Departamento -->
  <select id="filter-depto" onchange="applyAdvancedFilters()" style="<?= $selS ?>">
    <option value="" data-label="Departamento">Departamento</option>
    <?php foreach ($deptosList as $dn): ?>
    <option value="<?= h(mb_strtolower($dn)) ?>" data-label="<?= h($dn) ?>"><?= h($dn) ?></option>
    <?php endforeach; ?>
  </select>

  <!-- Loja — multi-seleção; nada marcado = todas -->
  <?php
  // Aqui o filtro roda no CLIENTE, sobre os cards já renderizados: o valor é o
  // nome da loja em minúsculo (igual ao data-loja do card), não o filial_id.
  // A lista sai das tarefas carregadas, então já respeita o acesso do usuário.
  $flSufixo   = 'tar';
  $flOpcoes   = array_map(
      static fn(string $ln): array => ['id' => mb_strtolower($ln), 'nome' => $ln],
      $lojasList
  );
  $flSel      = [];
  $flCampo    = 'loja';
  $flVariante = 'inline';
  $flLabel    = 'Loja';
  $flOnChange = 'applyAdvancedFilters();';
  $flBtnExtra = 'height:44px; border-radius:8px; font-weight:600; flex-shrink:0;';
  include VIEWS . '/components/filtro-lojas.php';
  ?>

  <!-- Fluxo (mesmo dados do template, com label diferente) -->
  <select id="filter-fluxo" onchange="applyAdvancedFilters()" style="<?= $selS ?>">
    <option value="" data-label="Fluxo">Fluxo</option>
    <?php foreach ($templatesList as $tn): ?>
    <option value="<?= h(mb_strtolower($tn)) ?>" data-label="<?= h($tn) ?>"><?= h($tn) ?></option>
    <?php endforeach; ?>
  </select>

  <!-- Período (data do processo) -->
  <select id="filter-periodo" onchange="onPeriodoChange()" style="<?= $selS ?>" title="Filtrar pela data do processo">
    <option value="">Período</option>
    <option value="hoje">Hoje</option>
    <option value="ontem">Ontem</option>
    <option value="esta-semana">Esta semana</option>
    <option value="ultimos-7">Últimos 7 dias</option>
    <option value="ultimos-30">Últimos 30 dias</option>
    <option value="este-mes">Este mês</option>
    <option value="personalizado">Período personalizado</option>
  </select>
  <input type="date" id="filter-periodo-de"  onchange="applyAdvancedFilters()" title="De"  style="<?= $selS ?> font-weight:400; display:none">
  <input type="date" id="filter-periodo-ate" onchange="applyAdvancedFilters()" title="Até" style="<?= $selS ?> font-weight:400; display:none">

  <!-- Ordenação -->
  <select id="filter-sort" onchange="applyAdvancedFilters()" style="<?= $selS ?>">
    <option value="venc-asc">Primeiro vencimento</option>
    <option value="age-desc">Mais antigo primeiro</option>
    <option value="age-asc">Mais recente primeiro</option>
    <option value="value-desc">Maior valor</option>
    <option value="value-asc">Menor valor</option>
  </select>

</div>

<!-- ── Barra de seleção em lote (oculta por padrão) ──────────── -->
<div id="batch-bar" style="display:none; align-items:center; gap:10px; background:#EEF2FD; border:1.5px solid #2347C8; border-radius:8px; padding:10px 16px; margin-bottom:16px">
  <span id="batch-count" style="font-size:13px; font-weight:700; color:#2347C8; flex:1">0 tarefa(s) selecionada(s)</span>
  <button type="button" id="batch-select-all"
          style="font-size:12px; font-weight:700; color:#2347C8; background:none; border:none; cursor:pointer; text-decoration:underline">
    Selecionar todas
  </button>
  <button type="button" id="batch-approve-btn" disabled
          style="display:inline-flex; align-items:center; gap:6px; padding:8px 16px; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:13px; font-weight:700; cursor:pointer; opacity:0.5"
          onclick="submitBatchApproval()">
    <i data-lucide="check" width="14" height="14"></i>
    Revisar selecionadas
  </button>
  <button type="button" id="batch-seq-btn" disabled
          style="display:inline-flex; align-items:center; gap:6px; padding:8px 16px; background:#fff; color:#2347C8; border:1.5px solid #2347C8; border-radius:4px; font-size:13px; font-weight:700; cursor:pointer; opacity:0.5"
          onclick="iniciarSequencial()">
    <i data-lucide="list-ordered" width="14" height="14"></i>
    Sequencial
  </button>
  <button type="button" id="batch-cancel"
          style="font-size:12px; color:#888; background:none; border:none; cursor:pointer; padding:4px 8px">
    Cancelar
  </button>
</div>

<!-- Token CSRF para as ações AJAX da revisão sequencial -->
<input type="hidden" id="rv-csrf" value="<?= h(Csrf::token()) ?>">

<!-- ── Lista de tarefas ───────────────────────────────────────── -->
<div id="tasks-list">

<?php if (empty($tarefas)): ?>

  <!-- Estado vazio — sem tarefas para este usuário -->
  <div id="tasks-empty-initial">
    <?php
    $emptyIcon    = 'clipboard-check';
    $emptyTitle   = 'Sem tarefas pendentes';
    $emptyMessage = 'Você está em dia com suas aprovações.';
    $emptyAction  = null;
    include VIEWS . '/components/empty-state.php';
    ?>
  </div>

<?php else: ?>

  <?php
  // URL desta tela (com filtros na query) — vai no "voltar" do botão Entrar,
  // para o processo devolver o usuário exatamente à lista que ele estava vendo.
  $voltarUrl = $_SERVER['REQUEST_URI'] ?? '?module=tarefas&action=index';
  ?>
  <?php foreach ($tarefas as $tarefa):
    $tipoAprovador = $tarefa['tipo_aprovador'];  // usuario | grupo
    $fila          = $tipoAprovador === 'usuario' ? 'pessoal' : 'grupo';
    $sla           = $tarefa['sla'] ?? ['situacao' => 'sem-sla', 'classe' => 'sla-sem', 'label' => '—', 'label_sla' => 'Sem SLA', 'dias_parado' => 0];
    $isTeste       = (int) ($tarefa['is_teste'] ?? 0) === 1;
    $cardMod       = $tipoAprovador === 'usuario' ? 'usuario' : 'grupo';
    $urgente       = $sla['situacao'] === 'vencido';
  ?>
  <div
    class="task-card task-card--<?= $cardMod ?><?= $urgente ? ' task-card--urgente' : '' ?>"
    data-fila="<?= h($fila) ?>"
    data-status="<?= h($tarefa['status'] ?? '') ?>"
    data-fornecedor="<?= h(mb_strtolower($tarefa['fornecedor_nome'])) ?>"
    data-codigo="<?= h(mb_strtolower($tarefa['codigo'])) ?>"
    data-nf="<?= h(mb_strtolower($tarefa['numero_nf'] ?? '')) ?>"
    data-cnpj="<?= h(preg_replace('/[.\-\/\s]/', '', $tarefa['fornecedor_doc'] ?? '')) ?>"
    data-template="<?= h(mb_strtolower($tarefa['template_nome'] ?? '')) ?>"
    data-tipo-nota="<?= h(mb_strtolower($tarefa['tipo_nota_nome'] ?? '')) ?>"
    data-grupo="<?= h(mb_strtolower($tarefa['grupo_nome'] ?? '')) ?>"
    data-depto="<?= h(mb_strtolower($tarefa['setor_nome'] ?? '')) ?>"
    data-loja="<?= h(mb_strtolower($tarefa['filial_nome'] ?? '')) ?>"
    data-valor="<?= (float)($tarefa['valor_nf'] ?? 0) ?>"
    data-data="<?= h(date('Y-m-d', strtotime($tarefa['criado_em']))) ?>"
    data-vencimento="<?= h($tarefa['primeiro_vencimento'] ?? '') ?>"
    data-urgente="<?= $urgente ? '1' : '0' ?>"
    data-sla="<?= h($sla['situacao']) ?>"
    data-teste="<?= $isTeste ? '1' : '0' ?>"
    data-status="<?= h($tarefa['status'] ?? 'aberto') ?>"
    onclick="if (!event.target.closest('.task-card__actions') && !event.target.closest('.batch-checkbox')) { window.location.href = '?module=processos&action=detalhe&id=<?= h($tarefa['processo_id']) ?>&origem=tarefas&voltar=' + encodeURIComponent(location.pathname + location.search); }"
  >
    <div class="task-card__body">

      <!-- Checkbox lote (oculto por padrão) -->
      <input type="checkbox" class="batch-checkbox"
             data-etapa-id="<?= h($tarefa['etapa_id']) ?>"
             data-processo-id="<?= h($tarefa['processo_id']) ?>"
             style="display:none; width:18px; height:18px; accent-color:#2347C8; cursor:pointer; flex-shrink:0"
             onclick="event.stopPropagation(); updateBatchCount()">

      <!-- Ícone tipo -->
      <div class="task-card__icon task-card__icon--<?= $cardMod ?>" aria-hidden="true">
        <?php if ($tipoAprovador === 'usuario'): ?>
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
               fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
               stroke-linejoin="round">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        <?php else: ?>
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
               fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
               stroke-linejoin="round">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
          </svg>
        <?php endif; ?>
      </div>

      <!-- Informações principais -->
      <div class="task-card__info">

        <!-- Linha 1: código + badges -->
        <div class="task-card__top">
          <span class="task-card__codigo"><?= h($tarefa['codigo']) ?></span>

          <span class="badge badge--<?= $fila === 'pessoal' ? 'pessoal' : 'grupo' ?>">
            <?= $fila === 'pessoal' ? 'Comigo' : 'Grupo' ?>
          </span>

          <?php if (($tarefa['status'] ?? '') === 'devolvido'): ?>
            <span class="badge" style="background:#FFF3E0;color:#FF8A2D;font-size:10px;font-weight:700;padding:2px 8px;border-radius:8px">DEVOLVIDO</span>
          <?php endif; ?>

          <?php if ($isTeste): ?>
            <span class="badge badge--teste">TESTE</span>
          <?php endif; ?>
        </div>

        <!-- Linha 2: fornecedor -->
        <div class="task-card__step"><?= h($tarefa['fornecedor_nome']) ?></div>

        <!-- Linha 3: número da NF | filial | data criação -->
        <?php
          // Número com "/" indica nota de serviço (ex.: 1234/2026)
          $numNf   = trim((string)($tarefa['numero_nf'] ?? ''));
          $ehServ  = $numNf !== '' && str_contains($numNf, '/');
          $labelNf = $numNf === '' ? 'Sem nota' : ($ehServ ? 'Nota Serviço ' : 'NF ') . $numNf;
        ?>
        <div class="task-card__sub">
          <span><?= h($labelNf) ?></span>
          <?php if (!empty($tarefa['filial_nome'])): ?>
            <span class="task-card__sep" aria-hidden="true">·</span>
            <span><?= h($tarefa['filial_nome']) ?></span>
          <?php endif; ?>
          <span class="task-card__sep" aria-hidden="true">·</span>
          <span><?= h(date('d/m/Y', strtotime($tarefa['criado_em']))) ?></span>
        </div>

        <!-- Linha 4: etapa -->
        <div class="task-card__sub">
          Etapa: <strong><?= h($tarefa['nome_etapa']) ?></strong>
          <span class="task-card__sep" aria-hidden="true">·</span>
          <span><?= h($tarefa['template_nome'] ?? '—') ?></span>
        </div>

      </div>

      <!-- Meta: valor + tempo aguardando -->
      <div class="task-card__meta">
        <span class="task-card__valor"><?= h(formatBrl($tarefa['valor_nf'])) ?></span>
        <span class="task-card__valor-sub">
          NF <?= h($tarefa['data_nf'] ? date('d/m/Y', strtotime($tarefa['data_nf'])) : '—') ?>
        </span>
        <span class="task-card__tempo <?= h($sla['classe']) ?>"
              title="Na etapa desde <?= h(date('d/m/Y H:i', strtotime($tarefa['entrada_etapa'] ?? $tarefa['criado_em']))) ?>">
          <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24"
               fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
               stroke-linejoin="round" aria-hidden="true" style="display:inline-block">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
          </svg>
          <?= h($sla['situacao'] !== 'sem-sla' ? $sla['label_sla'] : $sla['label']) ?>
        </span>
      </div>

      <!-- Ações -->
      <?php // Aprovar/Devolver saíram da lista: a decisão só acontece DENTRO do
            // processo, com o documento e o histórico à vista. Aqui só se entra. ?>
      <div class="task-card__actions">
        <?php // Verde (#00A960), o mesmo do Aprovar: entrar na tarefa é o passo
              // para aprovar, e o vermelho dava ao card um tom de alerta que
              // competia com o aviso de prazo vencido ao lado. ?>
        <a
          class="btn btn--sucesso btn--sm"
          href="?module=processos&action=detalhe&id=<?= h($tarefa['processo_id']) ?>&origem=tarefas&voltar=<?= h(urlencode($voltarUrl)) ?>"
        >
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
               fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
               stroke-linejoin="round" aria-hidden="true">
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
            <polyline points="10 17 15 12 10 7"/>
            <line x1="15" y1="12" x2="3" y2="12"/>
          </svg>
          Entrar
        </a>
      </div>

    </div>
  </div>
  <?php endforeach; ?>

  <!-- Estado vazio após filtro/busca (oculto por padrão) -->
  <div class="tasks-empty tasks-empty--hidden" id="tasks-empty-filter">
    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24"
         fill="none" stroke="#CCCCCC" stroke-width="1.5" stroke-linecap="round"
         stroke-linejoin="round" aria-hidden="true">
      <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
      <line x1="8" y1="11" x2="14" y2="11"/>
    </svg>
    <p>Nenhuma tarefa encontrada.</p>
    <small>Tente ajustar os filtros ou limpar a busca.</small>
  </div>

<?php endif; ?>

</div><!-- /#tasks-list -->


<!-- ══════════════════════════════════════════════════════════════
     Modal de Devolução
     ══════════════════════════════════════════════════════════ -->
<div
  class="modal-overlay"
  id="modal-devolver"
  hidden
  role="dialog"
  aria-modal="true"
  aria-labelledby="modal-devolver-titulo"
>
  <div class="modal">

    <div class="modal__header">
      <h2 class="modal__title" id="modal-devolver-titulo">Devolver processo</h2>
      <button
        type="button"
        class="modal__close"
        id="btn-modal-fechar"
        aria-label="Fechar modal"
      >
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
             stroke-linejoin="round" aria-hidden="true">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <div class="modal__body">
      <p class="modal__desc" id="modal-devolver-desc">
        Informe o motivo da devolução. O solicitante será notificado.
      </p>

      <!-- Destaque do processo -->
      <div id="modal-devolver-info"
           style="background:#FFF3E0;border-left:3px solid #FF8A2D;border-radius:4px;
                  padding:10px 14px;margin-bottom:16px;font-size:13px;color:#856404;">
        Processo: <strong id="modal-devolver-codigo-display"></strong>
      </div>

      <form id="form-devolver" method="POST" action="?module=tarefas&action=devolver">
        <?= Csrf::field() ?>

        <!-- Preenchidos via JS usando setAttribute — sem innerHTML -->
        <input type="hidden" id="devolver-processo-id" name="processo_id" value="">
        <input type="hidden" id="devolver-etapa-id"    name="etapa_id"    value="">

        <!-- Destino: PESSOA que já agiu no fluxo. Preenchido ao abrir o modal
             (?module=tarefas&action=pessoas_devolucao) — embutir a lista em
             cada linha custaria uma consulta por tarefa no carregamento. -->
        <div style="margin-bottom:14px;">
          <label for="devolver-destino"
                 style="display:block;font-size:13px;font-weight:700;color:#141922;margin-bottom:6px;">
            Devolver para <span style="color:#B3261E;" aria-hidden="true">*</span>
          </label>
          <select id="devolver-destino" name="usuario_destino" required
                  style="width:100%;height:40px;border:1.5px solid #DFDFDF;border-radius:4px;
                         padding:0 12px;font-size:14px;color:#141922;background:#fff;box-sizing:border-box;">
            <option value="">Carregando…</option>
          </select>
          <p style="font-size:11px;color:#888;margin:4px 0 0;">
            O processo volta para essa pessoa e refaz o caminho a partir dali.
          </p>
        </div>

        <div style="margin-bottom:4px;">
          <label
            for="devolver-motivo"
            style="display:block;font-size:13px;font-weight:700;color:#141922;margin-bottom:6px;"
          >
            Motivo da devolução <span style="color:#B3261E;" aria-hidden="true">*</span>
          </label>

          <textarea
            id="devolver-motivo"
            name="motivo"
            rows="4"
            required
            minlength="10"
            maxlength="2000"
            placeholder="Descreva o motivo da devolução (mínimo 10 caracteres)…"
            style="width:100%;border:1.5px solid #DFDFDF;border-radius:4px;
                   padding:10px 12px;font-size:14px;font-family:inherit;
                   resize:vertical;outline:none;color:#141922;background:#fff;"
            class="devolver-textarea"
          ></textarea>

          <p
            id="devolver-motivo-erro"
            role="alert"
            style="display:none;color:#B3261E;font-size:12px;margin:4px 0 0;"
          >
            O motivo deve ter pelo menos 10 caracteres.
          </p>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
          <button type="button" class="btn btn--terciario" id="btn-modal-cancelar">
            Cancelar
          </button>
          <button type="submit" class="btn btn--aviso" id="btn-confirmar-devolucao">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
              <polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>
            </svg>
            Confirmar devolução
          </button>
        </div>
      </form>
    </div>

  </div>
</div>

<!-- ── Overlay: Revisão sequencial em lote ──────────────── -->
<!-- Mesmo layout da tela de processo único: documento à ESQUERDA, painel do
     processo à DIREITA. Cada Aprovar/Devolver grava imediatamente (AJAX) e
     avança para o próximo; ao esgotar a fila, volta a Minhas Tarefas. -->
<div id="batch-overlay" style="display:none; position:fixed; inset:0; background:#F6F6F6; z-index:2000; flex-direction:column">

  <!-- Topbar -->
  <div style="background:#fff; padding:10px 20px; display:flex; align-items:center; gap:14px; flex-shrink:0; border-bottom:1px solid #EAEAEA">
    <div style="flex:1; height:4px; background:#EAEAEA; border-radius:2px; overflow:hidden">
      <div id="rv-progress" style="height:100%; background:#2347C8; border-radius:2px; transition:width 0.3s; width:0%"></div>
    </div>
    <span id="rv-aviso-fora" style="display:none; font-size:12px; font-weight:700; color:#C75B00; background:#FFF3E0; border:1px solid #FFE0B2; border-radius:4px; padding:4px 10px; white-space:nowrap"></span>
    <span style="font-size:13px; color:#606060; white-space:nowrap; font-weight:600">
      Revisando <strong style="color:#141922" id="rv-cur">1</strong> de <strong style="color:#141922" id="rv-total">0</strong>
    </span>
    <button type="button" onclick="fecharRevisao()" style="background:#EAEAEA; border:none; color:#606060; cursor:pointer; border-radius:4px; padding:6px 14px; font-size:12px; font-weight:700">
      Cancelar revisão
    </button>
    <?php // Aprova de uma vez tudo que sobrou na revisão — o que foi devolvido
          // já saiu da fila e não entra. Passa por confirmação: é ação em massa. ?>
    <button type="button" id="rv-btn-aprovar-tudo" onclick="rvAbrirAprovarTudo()"
            style="background:#00A960; border:none; color:#fff; cursor:pointer; border-radius:4px; padding:6px 16px; font-size:12px; font-weight:700; white-space:nowrap">
      Aprovar tudo
    </button>
  </div>

  <!-- Painel principal -->
  <div style="flex:1; display:flex; overflow:hidden">

    <!-- ESQUERDA: visualizador de documento -->
    <div style="flex:1; display:flex; flex-direction:column; background:#F6F6F6; overflow:hidden">
      <!-- Barra de anexos (chips clicáveis) -->
      <div style="padding:10px 16px; background:#fff; border-bottom:1px solid #EAEAEA; flex-shrink:0">
        <div style="font-size:11px; font-weight:700; color:#606060; text-transform:uppercase; letter-spacing:.5px; margin-bottom:8px">Documentos anexos</div>
        <div id="rv-anexos-bar" style="display:flex; gap:8px; overflow-x:auto; padding-bottom:2px"></div>
      </div>
      <!-- Cabeçalho do documento aberto -->
      <div style="padding:8px 16px; background:#fff; border-bottom:1px solid #EAEAEA; display:flex; align-items:center; gap:8px; flex-shrink:0">
        <i data-lucide="file-text" width="14" height="14" style="color:#2347C8"></i>
        <span id="rv-viewer-fname" style="font-size:13px; font-weight:600; color:#141922; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">Nenhum documento</span>
      </div>
      <div style="flex:1; display:flex; align-items:center; justify-content:center; overflow:auto; padding:16px">
        <iframe id="rv-viewer-frame" style="display:none; width:100%; height:100%; border:none; border-radius:8px; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,0.1)"></iframe>
        <img id="rv-viewer-img" style="display:none; max-width:100%; max-height:100%; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.1)">
        <div id="rv-viewer-empty" style="text-align:center; color:#888">
          <i data-lucide="eye" width="36" height="36" style="color:#DFDFDF; display:block; margin:0 auto 10px"></i>
          <div style="font-size:14px; font-weight:600; color:#606060">Sem documento para visualizar</div>
          <div style="font-size:12px; color:#888; margin-top:4px">PDFs e imagens serão exibidos aqui</div>
        </div>
      </div>
    </div>

    <!-- DIREITA: painel do processo -->
    <div style="width:460px; flex-shrink:0; background:#fff; border-left:1px solid #EAEAEA; display:flex; flex-direction:column; overflow:hidden">
      <div style="padding:18px 24px; border-bottom:1px solid #F0F0F0; flex-shrink:0">
        <div style="display:flex; align-items:center; gap:10px">
          <span id="rv-codigo" style="font-size:22px; font-weight:700; color:#141922"></span>
          <span id="rv-teste-badge" style="display:none; font-size:11px; font-weight:700; background:#FFF3CD; color:#856404; padding:3px 9px; border-radius:4px">TESTE</span>
          <span id="rv-status-chip" style="margin-left:auto; padding:4px 12px; border-radius:4px; font-size:12px; font-weight:700; background:#EAEAEA; color:#606060">Pendente</span>
        </div>
        <div id="rv-template" style="font-size:14px; color:#606060; margin-top:4px"></div>
      </div>

      <!-- Abas -->
      <div style="display:flex; gap:2px; padding:0 24px; border-bottom:1px solid #EAEAEA; flex-shrink:0">
        <button type="button" id="rv-tabbtn-dados" onclick="rvSetTab('dados')" class="rv-tab"
                style="background:none; border:none; border-bottom:2px solid transparent; padding:12px 6px; margin-right:14px; font-size:13.5px; font-weight:700; color:#888; cursor:pointer">Dados</button>
        <button type="button" id="rv-tabbtn-campos" onclick="rvSetTab('campos')" class="rv-tab"
                style="display:none; background:none; border:none; border-bottom:2px solid transparent; padding:12px 6px; margin-right:14px; font-size:13.5px; font-weight:700; color:#888; cursor:pointer">Formulário
          <span id="rv-campos-badge" style="font-size:11px; font-weight:700; background:#EAEAEA; color:#606060; border-radius:4px; padding:1px 6px; margin-left:2px"></span>
        </button>
        <?php // Fluxo e Histórico: o revisor mostrava menos que a tela de
              // aprovação normal, e quem decide aqui decide a mesma coisa. ?>
        <button type="button" id="rv-tabbtn-fluxo" onclick="rvSetTab('fluxo')" class="rv-tab"
                style="background:none; border:none; border-bottom:2px solid transparent; padding:12px 6px; margin-right:14px; font-size:13.5px; font-weight:700; color:#888; cursor:pointer">Fluxo
          <span id="rv-fluxo-badge" style="font-size:11px; font-weight:700; background:#EAEAEA; color:#606060; border-radius:4px; padding:1px 6px; margin-left:2px"></span>
        </button>
        <button type="button" id="rv-tabbtn-historico" onclick="rvSetTab('historico')" class="rv-tab"
                style="background:none; border:none; border-bottom:2px solid transparent; padding:12px 6px; font-size:13.5px; font-weight:700; color:#888; cursor:pointer">Histórico
          <span id="rv-historico-badge" style="font-size:11px; font-weight:700; background:#EAEAEA; color:#606060; border-radius:4px; padding:1px 6px; margin-left:2px"></span>
        </button>
      </div>

      <div style="flex:1; overflow-y:auto; padding:20px 24px;">
        <?php // Observações ficam acima das abas, visíveis em qualquer uma —
              // escondidas numa aba própria passariam despercebidas na correria
              // do lote. Traz também o campo para escrever uma nova. ?>
        <div id="rv-obs" style="display:none; margin-bottom:18px"></div>
        <div id="rv-tab-dados">
          <div id="rv-fields" style="display:grid; grid-template-columns:1fr 1fr; gap:14px"></div>
          <!-- Parcelas: era aba própria, agora é o último bloco de Dados. -->
          <div id="rv-parcelas" style="font-size:14px; color:#606060"></div>
        </div>
        <div id="rv-tab-campos" style="display:none">
          <div id="rv-campos"></div>
        </div>
        <div id="rv-tab-fluxo" style="display:none">
          <div id="rv-fluxo"></div>
        </div>
        <div id="rv-tab-historico" style="display:none">
          <div id="rv-historico"></div>
        </div>
      </div>
    </div>

  </div>

  <!-- Footer: ações (commit imediato) -->
  <div style="background:#606060; padding:12px 24px; display:flex; align-items:center; justify-content:center; gap:12px; flex-shrink:0">
    <button type="button" id="rv-btn-return" onclick="rvAbrirDevolver()"
            style="display:inline-flex; align-items:center; gap:8px; padding:10px 30px; border-radius:4px; border:1.5px solid #FF6B6B; background:transparent; font-size:13px; font-weight:700; cursor:pointer; color:#FF6B6B; transition:all 0.15s; white-space:nowrap"
            onmouseover="this.style.background='#2347C8';this.style.borderColor='#2347C8';this.style.color='#fff'" onmouseout="this.style.background='transparent';this.style.borderColor='#FF6B6B';this.style.color='#FF6B6B'">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;flex-shrink:0;vertical-align:middle"><path d="M9 14l-4-4 4-4"/><path d="M5 10h11a4 4 0 010 8h-1"/></svg>
      Devolver
    </button>
    <button type="button" id="rv-btn-approve" onclick="rvAprovar()"
            style="display:inline-flex; align-items:center; gap:8px; padding:10px 40px; border-radius:4px; border:1px solid #00A960; background:#00A960; font-size:14px; font-weight:700; cursor:pointer; color:#fff; transition:all 0.15s; white-space:nowrap"
            onmouseover="this.style.background='#008F51';this.style.borderColor='#008F51'" onmouseout="this.style.background='#00A960';this.style.borderColor='#00A960'">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;flex-shrink:0;vertical-align:middle"><polyline points="20 6 9 17 4 12"/></svg>
      Aprovar
    </button>
    <button type="button" id="rv-btn-next" onclick="rvPular()"
            style="display:inline-flex; align-items:center; gap:6px; padding:10px 30px; border:1px solid rgba(255,255,255,.2); border-radius:4px; background:rgba(255,255,255,.08); font-size:13px; font-weight:700; color:#fff; cursor:pointer; white-space:nowrap"
            onmouseover="this.style.background='#fff';this.style.color='#606060'" onmouseout="this.style.background='rgba(255,255,255,.08)';this.style.color='#fff'">
      Pular
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;flex-shrink:0;vertical-align:middle"><polyline points="9 18 15 12 9 6"/></svg>
    </button>
  </div>

</div>

<!-- Modal: confirmar "Aprovar tudo" -->
<div id="rv-aprovar-tudo-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:3000; align-items:center; justify-content:center">
  <div style="background:#fff; border-radius:12px; width:100%; max-width:440px; margin:20px; box-shadow:0 8px 32px rgba(0,0,0,0.2); padding:24px">
    <h3 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 6px">Aprovar tudo</h3>
    <p style="font-size:13.5px; color:#606060; margin:0 0 14px; line-height:1.5">
      <span id="rv-tudo-qtd" style="font-weight:700; color:#141922"></span> serão aprovadas de uma vez,
      na etapa em que estão. O que você devolveu nesta revisão não entra.
    </p>
    <div style="display:flex; align-items:flex-start; gap:6px; padding:9px 12px; background:#FFF3E0; border-radius:6px; font-size:13px; color:#856404; line-height:1.5">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Aprovar não tem desfazer — cada processo segue para a próxima etapa.
    </div>
    <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:18px">
      <button type="button" onclick="rvFecharAprovarTudo()" style="padding:10px 20px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">
        Voltar
      </button>
      <button type="button" id="rv-tudo-confirm" onclick="rvConfirmarAprovarTudo()" style="padding:10px 24px; background:#00A960; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">
        Aprovar tudo
      </button>
    </div>
  </div>
</div>

<!-- Modal: motivo da devolução (commit imediato de 1 item) -->
<div id="rv-devolver-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:3000; align-items:center; justify-content:center">
  <div style="background:#fff; border-radius:12px; width:100%; max-width:480px; margin:20px; box-shadow:0 8px 32px rgba(0,0,0,0.2)">
    <div style="padding:20px 24px 0">
      <h3 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px">Devolver processo</h3>
      <!-- Mesmos detalhes do modal da fila: fornecedor, etapa e código em
           destaque. Sem isso, na revisão rápida (que corre item a item) dava
           para devolver sem saber de qual processo se tratava. -->
      <p id="rv-devolver-desc" style="font-size:13.5px; color:#888888; margin:0 0 16px; line-height:1.5">
        Informe o motivo da devolução. O solicitante será notificado.
      </p>
      <div id="rv-devolver-codigo"
           style="background:#FFF3E0; border-left:3px solid #FF8A2D; border-radius:4px;
                  padding:10px 14px; margin-bottom:16px; font-size:13px; color:#856404"></div>
      <label for="rv-devolver-destino" style="display:block; font-size:12px; font-weight:700; color:#606060; margin-bottom:6px">Devolver para (obrigatório)</label>
      <select id="rv-devolver-destino"
              style="width:100%; height:38px; border:1.5px solid #FFD6D6; border-radius:6px; padding:0 12px; font-size:13.5px; color:#141922; background:#fff; box-sizing:border-box; margin-bottom:14px"
              onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#FFD6D6'">
        <option value="">Carregando…</option>
      </select>
      <label style="display:block; font-size:12px; font-weight:700; color:#606060; margin-bottom:6px">Motivo da devolução (obrigatório)</label>
      <textarea id="rv-devolver-motivo" placeholder="Descreva o motivo da devolução..."
                style="width:100%; border:1.5px solid #FFD6D6; border-radius:6px; padding:9px 12px; font-size:13.5px; font-family:inherit; color:#141922; resize:vertical; min-height:80px; outline:none; box-sizing:border-box"
                onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#FFD6D6'"></textarea>
      <div id="rv-devolver-erro" style="display:none; font-size:12px; font-weight:700; color:#2347C8; margin-top:6px"></div>
    </div>
    <div style="padding:16px 24px; display:flex; gap:8px; justify-content:flex-end; border-top:1px solid #EAEAEA; margin-top:16px">
      <button type="button" onclick="rvFecharDevolver()" style="padding:10px 20px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">
        Cancelar
      </button>
      <button type="button" id="rv-devolver-confirm" onclick="rvConfirmarDevolver()" style="padding:10px 24px; background:var(--color-warning,#FF8A2D); color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">
        Confirmar devolução
      </button>
    </div>
  </div>
</div>

<script>
function formatarCnpjCpf(v) {
  var d = String(v).replace(/\D/g, '');
  if (d.length === 14) return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
  if (d.length === 11) return d.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
  return v;
}
(function () {
  'use strict';

  // ═══ MODO LOTE (seleção) ═══════════════════════════════════
  var batchMode = false;
  var btnMode   = document.getElementById('btn-batch-mode');
  var batchBar  = document.getElementById('batch-bar');
  var countEl   = document.getElementById('batch-count');
  var approveBtn = document.getElementById('batch-approve-btn');
  var seqBtn    = document.getElementById('batch-seq-btn');
  var selectAll = document.getElementById('batch-select-all');
  var cancelBtn = document.getElementById('batch-cancel');
  var checkboxes = document.querySelectorAll('.batch-checkbox');

  if (!btnMode || !batchBar) return;

  // O lote é SEMPRE o que o filtro está mostrando: um checkbox de card oculto
  // não vale nada, mesmo que tenha ficado marcado de uma filtragem anterior.
  function checkboxVisivel(cb) {
    var card = cb.closest('.task-card');
    return !!card && card.style.display !== 'none';
  }
  // Consulta o DOM na hora, e não a lista `checkboxes` capturada no load: a
  // ordenação do filtro move os cards de lugar (appendChild), e a lista antiga
  // ficava congelada na ordem de carregamento — o lote abria numa ordem
  // diferente da tela. Agora segue a ordem que o usuário está vendo.
  function checkboxesVisiveis() {
    return Array.from(document.querySelectorAll('.batch-checkbox')).filter(checkboxVisivel);
  }
  // Exposto porque applyAdvancedFilters (mais abaixo, mesmo escopo) precisa
  // saber se o modo está aberto para acertar a seleção quando o filtro muda.
  function batchAtivo() { return batchMode; }

  // Um único modo de seleção, aberto pelo "Lote". O "Sequencial" não abre modo
  // nenhum: ele consome a seleção que já existe e vai direto para o primeiro
  // processo — os dois são ações sobre a MESMA marcação.
  function toggleBatchMode() {
    batchMode = !batchMode;
    batchBar.style.display = batchMode ? 'flex' : 'none';
    btnMode.classList.toggle('active', batchMode);
    btnMode.style.borderColor = batchMode ? '#1B38A0' : '#2347C8';
    btnMode.style.color       = '#FFFFFF';
    btnMode.style.background  = batchMode ? '#1B38A0' : '#2347C8';
    checkboxes.forEach(function (cb) {
      cb.style.display = batchMode ? 'block' : 'none';
      // Abrir o Lote já marca o que está na tela — quem filtrou antes de clicar
      // já montou o lote que queria; refazer a marcação no braço era trabalho
      // repetido. Fechar o modo limpa tudo.
      cb.checked = batchMode && checkboxVisivel(cb);
    });
    updateBatchCount();
  }

  btnMode.addEventListener('click', toggleBatchMode);
  cancelBtn.addEventListener('click', toggleBatchMode);

  // Alterna apenas sobre o que está visível — "todas" quer dizer "todas as que
  // estão na tela", nunca as que o filtro escondeu.
  selectAll.addEventListener('click', function () {
    var visiveis   = checkboxesVisiveis();
    var allChecked = visiveis.length > 0 && visiveis.every(function (cb) { return cb.checked; });
    visiveis.forEach(function (cb) { cb.checked = !allChecked; });
    updateBatchCount();
  });

  window.updateBatchCount = function () {
    var visiveis = checkboxesVisiveis();
    var count    = visiveis.filter(function (cb) { return cb.checked; }).length;
    countEl.textContent = count + ' tarefa(s) selecionada(s)';
    selectAll.textContent = (visiveis.length > 0 && count === visiveis.length)
      ? 'Desmarcar todas'
      : 'Selecionar todas';
    approveBtn.disabled = count === 0;
    approveBtn.style.opacity = count > 0 ? '1' : '0.5';
    if (seqBtn) {
      seqBtn.disabled = count === 0;
      seqBtn.style.opacity = count > 0 ? '1' : '0.5';
    }
  };

  // ── Sequencial: abre os SELECIONADOS um a um, na tela cheia do processo ──
  // A ordem é a da tela: os cards já foram reordenados no DOM pelo filtro/sort,
  // então varrer `.task-card` na ordem do documento devolve o que o usuário vê.
  // A fila fica em sessionStorage porque cada decisão é um POST com redirect —
  // não sobrevive nada em memória entre uma tela e a próxima.
  window.iniciarSequencial = function () {
    var ids = [];
    document.querySelectorAll('.task-card').forEach(function (card) {
      if (card.style.display === 'none') return;             // fora do filtro
      var cb = card.querySelector('.batch-checkbox');
      if (cb && cb.checked) ids.push(cb.dataset.processoId);
    });
    // Sem marcação não há o que percorrer. Abrir tudo por conta própria seria
    // pior: a fila pode ter dezenas de processos.
    if (ids.length === 0) {
      alert('Marque as tarefas em "Lote" primeiro — o Sequencial percorre o que estiver selecionado.');
      return;
    }

    try {
      sessionStorage.setItem('seqFila', JSON.stringify(ids));
    } catch (e) {
      alert('Não foi possível iniciar a sequência neste navegador.');
      return;
    }
    window.location = location.pathname + '?module=processos&action=detalhe&id='
                    + encodeURIComponent(ids[0]) + '&seq=1&origem=tarefas';
  };

  // ═══ REVISÃO SEQUENCIAL ════════════════════════════════════
  var rvItems    = [];
  var rvIndex    = 0;
  var rvOverlay  = document.getElementById('batch-overlay');

  // Token CSRF das ações AJAX (aprovar_item / devolver_item)
  var rvCsrf = (document.getElementById('rv-csrf') || {}).value || '';
  // Aba ativa por padrão do item corrente
  var rvTab  = 'dados';

  // Entrar na revisão é ASSUMIR a fila: cada etapa de grupo selecionada vira
  // etapa do usuário antes de o revisor abrir. Sem isso o Devolver de dentro
  // agiria sobre uma tarefa que ainda é do grupo — e quebra.
  window.submitBatchApproval = function () {
    // Só o que está na tela: revisar um processo que o filtro escondeu seria
    // agir sobre algo que o usuário não está vendo.
    var selected = checkboxesVisiveis().filter(function (cb) { return cb.checked; });
    if (selected.length === 0) return;

    var btn = document.getElementById('batch-approve-btn');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; }

    var fd = new FormData();
    fd.append('csrf_token', rvCsrf);
    selected.forEach(function (cb) {
      fd.append('etapa_ids[]',    cb.dataset.etapaId);
      fd.append('processo_ids[]', cb.dataset.processoId);
    });

    fetch('?module=tarefas&action=assumir_lote', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; }

        if (!res || !res.success) {
          btModalErro('Não foi possível iniciar a revisão', (res && res.message) || 'Tente novamente.', 'danger');
          return;
        }

        var libs = res.liberados || [];
        var fora = res.indisponiveis || [];

        if (libs.length === 0) {
          // O motivo real vem do servidor, item a item. Antes esta mensagem era
          // fixa em "já foram assumidas por outra pessoa" e escondia o motivo
          // verdadeiro — foi assim que uma negativa de permissão passou dias
          // parecendo disputa de fila.
          var motivo = (fora[0] && fora[0].motivo) ? fora[0].motivo : '';
          btModalErro('Nenhuma tarefa disponível',
            motivo
              ? ('Nenhuma das ' + fora.length + ' tarefa(s) selecionada(s) pôde ser assumida. Motivo da primeira: ' + motivo + '.')
              : 'As tarefas selecionadas já foram assumidas ou tratadas por outra pessoa. Atualize a lista.',
            'warning');
          return;
        }

        rvAbrirFila(libs, fora.length);
      })
      .catch(function () {
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
        btModalErro('Falha de conexão', 'Não foi possível assumir as tarefas selecionadas.', 'danger');
      });
  };

  // Abre o overlay já com a fila assumida. `foraCount` = selecionadas que
  // ficaram de fora (outro assumiu antes) — vira aviso na barra de progresso.
  function rvAbrirFila(liberados, foraCount) {
    rvItems = liberados.map(function (l) {
      return {
        etapaId:    l.etapa_id,
        processoId: l.processo_id,
        acted:      false,   // já aprovado/devolvido (sai da fila)
        dados:      null
      };
    });

    var aviso = document.getElementById('rv-aviso-fora');
    if (aviso) {
      if (foraCount > 0) {
        aviso.textContent = foraCount === 1
          ? '1 tarefa saiu da fila (assumida por outra pessoa)'
          : foraCount + ' tarefas saíram da fila (assumidas por outra pessoa)';
        aviso.style.display = '';
      } else {
        aviso.style.display = 'none';
      }
    }

    rvIndex = rvFirstPending();
    rvOverlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    rvLoadCurrent();
    if (window.lucide) window.lucide.createIcons();
  }

  window.fecharRevisao = function () {
    rvOverlay.style.display = 'none';
    document.body.style.overflow = '';
    // Recarrega sempre: abrir a revisão já assumiu a fila, então a lista
    // atrás do overlay está desatualizada mesmo sem aprovar/devolver nada.
    rvFinish();
  };

  // Índices utilitários sobre itens ainda pendentes (não tratados)
  function rvFirstPending() {
    for (var i = 0; i < rvItems.length; i++) { if (!rvItems[i].acted) return i; }
    return -1;
  }
  function rvNextPending(from) {
    for (var i = from + 1; i < rvItems.length; i++) { if (!rvItems[i].acted) return i; }
    for (var j = 0; j < from; j++) { if (!rvItems[j].acted) return j; }
    return -1;
  }
  function rvPendingCount() {
    return rvItems.filter(function (it) { return !it.acted; }).length;
  }

  // Fim da revisão → volta a Minhas Tarefas (lista atualizada)
  window.rvFinish = function () {
    window.location.href = '?module=tarefas&action=index';
  };

  // Pular: mantém pendente, vai para o próximo pendente
  window.rvPular = function () {
    var nxt = rvNextPending(rvIndex);
    if (nxt === -1 || nxt === rvIndex) {
      btModalErro('Única tarefa pendente', 'Não há outra tarefa pendente para pular. Aprove ou devolva esta.', 'warning');
      return;
    }
    rvIndex = nxt;
    rvLoadCurrent();
  };

  // Após aprovar/devolver: marca tratado e avança (ou finaliza)
  function rvAposAcao() {
    rvItems[rvIndex].acted = true;
    if (rvPendingCount() === 0) { rvFinish(); return; }
    var nxt = rvNextPending(rvIndex);
    rvIndex = nxt === -1 ? rvFirstPending() : nxt;
    rvLoadCurrent();
  }

  function rvSetBtnsEnabled(on) {
    ['rv-btn-approve', 'rv-btn-return', 'rv-btn-next'].forEach(function (id) {
      var b = document.getElementById(id);
      if (b) { b.disabled = !on; b.style.opacity = on ? '1' : '0.5'; b.style.pointerEvents = on ? '' : 'none'; }
    });
  }

  // ── Aprovar tudo (o que sobrou na revisão) ──
  // Reaproveita o `aprovar_lote` já existente: ele valida item a item quem pode
  // aprovar o quê e volta para Minhas Tarefas com o resumo. Depois de aprovar
  // tudo não sobra o que revisar, então terminar na listagem é o certo.
  window.rvAbrirAprovarTudo = function () {
    var n = rvPendingCount();
    if (n === 0) return;
    document.getElementById('rv-tudo-qtd').textContent =
      n + (n === 1 ? ' tarefa' : ' tarefas');
    document.getElementById('rv-aprovar-tudo-modal').style.display = 'flex';
  };
  window.rvFecharAprovarTudo = function () {
    document.getElementById('rv-aprovar-tudo-modal').style.display = 'none';
  };
  window.rvConfirmarAprovarTudo = function () {
    var btn = document.getElementById('rv-tudo-confirm');
    btn.disabled = true; btn.style.opacity = '0.6'; btn.textContent = 'Aprovando…';

    var form = document.createElement('form');
    form.method = 'post';
    form.action = '?module=tarefas&action=aprovar_lote';
    form.style.display = 'none';

    var add = function (nome, valor) {
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = nome; i.value = valor;
      form.appendChild(i);
    };
    add('csrf_token', rvCsrf);

    var n = 0;
    rvItems.forEach(function (it) {
      if (it.acted) return;              // devolvido nesta revisão: fica de fora
      add('etapas[' + n + '][etapa_id]', it.etapaId);
      add('etapas[' + n + '][processo_id]', it.processoId);
      n++;
    });

    document.body.appendChild(form);
    form.submit();
  };

  // ── Aprovar (commit imediato) ──
  window.rvAprovar = function () {
    var it = rvItems[rvIndex];
    rvSetBtnsEnabled(false);
    var fd = new FormData();
    fd.append('csrf_token', rvCsrf);
    fd.append('etapa_id', it.etapaId);
    fd.append('processo_id', it.processoId);
    fetch('?module=tarefas&action=aprovar_item', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        rvSetBtnsEnabled(true);
        if (res && res.success) { rvAposAcao(); }
        else { btModalErro('Não foi possível aprovar', (res && res.message) || 'Tente novamente.', 'danger'); }
      })
      .catch(function () { rvSetBtnsEnabled(true); btModalErro('Falha de conexão', 'Não foi possível concluir a aprovação.', 'danger'); });
  };

  // ── Devolver (modal de motivo → commit imediato) ──
  window.rvAbrirDevolver = function () {
    var it = rvItems[rvIndex];
    var d  = it.dados || {};

    // Destaque do processo + descrição com fornecedor e etapa — mesmo conteúdo
    // do modal da fila. textContent, nunca innerHTML: os dados vêm do banco.
    var boxCod = document.getElementById('rv-devolver-codigo');
    boxCod.textContent = '';
    boxCod.appendChild(document.createTextNode('Processo: '));
    var strong = document.createElement('strong');
    strong.textContent = d.codigo || '—';
    boxCod.appendChild(strong);

    document.getElementById('rv-devolver-desc').textContent =
      'Informe o motivo da devolução para ' + (d.fornecedor || '—')
      + (d.etapa_nome ? ' — Etapa: ' + d.etapa_nome : '')
      + '. O solicitante será notificado.';

    var ta = document.getElementById('rv-devolver-motivo');
    ta.value = ''; ta.style.borderColor = '#FFD6D6';
    document.getElementById('rv-devolver-erro').style.display = 'none';
    // Destinos = pessoas que já agiram no fluxo (tasks.js)
    if (window.carregarPessoasDevolucao) {
      window.carregarPessoasDevolucao(it.processoId, document.getElementById('rv-devolver-destino'));
    }
    document.getElementById('rv-devolver-modal').style.display = 'flex';
    setTimeout(function () { ta.focus(); }, 50);
  };
  window.rvFecharDevolver = function () {
    document.getElementById('rv-devolver-modal').style.display = 'none';
  };
  window.rvConfirmarDevolver = function () {
    var it = rvItems[rvIndex];
    var ta = document.getElementById('rv-devolver-motivo');
    var motivo = ta.value.trim();
    var erro = document.getElementById('rv-devolver-erro');
    if (motivo.length < 5) {
      ta.style.borderColor = '#2347C8';
      erro.textContent = 'Descreva o motivo (mínimo 5 caracteres).';
      erro.style.display = 'block';
      ta.focus();
      return;
    }
    var destino = document.getElementById('rv-devolver-destino');
    if (!destino || !destino.value) {
      erro.textContent = 'Escolha para quem o processo deve voltar.';
      erro.style.display = 'block';
      return;
    }
    var btn = document.getElementById('rv-devolver-confirm');
    btn.disabled = true; btn.style.opacity = '0.6';
    var fd = new FormData();
    fd.append('csrf_token', rvCsrf);
    fd.append('etapa_id', it.etapaId);
    fd.append('processo_id', it.processoId);
    fd.append('motivo', motivo);
    fd.append('usuario_destino', destino.value);
    fetch('?module=tarefas&action=devolver_item', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false; btn.style.opacity = '1';
        if (res && res.success) {
          rvFecharDevolver();
          rvAposAcao();
        } else {
          erro.textContent = (res && res.message) || 'Não foi possível devolver.';
          erro.style.display = 'block';
        }
      })
      .catch(function () {
        btn.disabled = false; btn.style.opacity = '1';
        erro.textContent = 'Falha de conexão. Tente novamente.';
        erro.style.display = 'block';
      });
  };

  function rvLoadCurrent() {
    if (rvIndex < 0) { rvFinish(); return; }
    var item = rvItems[rvIndex];

    // Progresso: itens tratados / total
    var tratados = rvItems.length - rvPendingCount();
    document.getElementById('rv-cur').textContent   = rvIndex + 1;
    document.getElementById('rv-total').textContent  = rvItems.length;
    document.getElementById('rv-progress').style.width = (tratados / rvItems.length * 100) + '%';

    rvSetBtnsEnabled(true);

    // Reset viewer + abas
    rvResetViewer();
    rvTab = 'dados';

    if (item.dados) {
      rvRender(item.dados);
    } else {
      document.getElementById('rv-fields').innerHTML = '<div style="color:#606060;font-size:14px">Carregando...</div>';
      document.getElementById('rv-obs').style.display = 'none';
      // Sem o catch, erro do servidor (500, sessão expirada → HTML de login)
      // deixava o painel em "Carregando..." para sempre — foi o que aconteceu
      // em 21/10/2026. Agora a falha aparece e Aprovar/Devolver/Pular seguem
      // disponíveis.
      var rvErroCarga = function () {
        document.getElementById('rv-fields').innerHTML = '<div style="color:#2347C8;font-size:14px">Erro ao carregar o processo. Você ainda pode aprovar, devolver ou pular; se persistir, atualize a página.</div>';
      };
      fetch('?module=tarefas&action=detalhe_ajax&processo_id=' + item.processoId)
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (d) {
          if (d.success) { item.dados = d; rvRender(d); }
          else { rvErroCarga(); }
        })
        .catch(rvErroCarga);
    }
  }

  function rvResetViewer() {
    var fr = document.getElementById('rv-viewer-frame'); fr.style.display = 'none'; fr.removeAttribute('src');
    var im = document.getElementById('rv-viewer-img');   im.style.display = 'none'; im.removeAttribute('src');
    document.getElementById('rv-viewer-empty').style.display = '';
    document.getElementById('rv-viewer-fname').textContent = 'Nenhum documento';
  }

  function rvRender(d) {
    document.getElementById('rv-codigo').textContent   = d.codigo;
    document.getElementById('rv-template').textContent  = (d.template || '—') + ' · ' + (d.filial || '—');
    document.getElementById('rv-teste-badge').style.display = d.is_teste ? '' : 'none';

    // Dados
    var fields = [
      ['Fornecedor', d.fornecedor, true, false],
      ['CNPJ/CPF', formatarCnpjCpf(d.fornecedor_doc || ''), false, true],
      ['Número NF', d.numero_nf, false, true],
      ['Valor Total', 'R$ ' + d.valor_nf, false, false],
      ['Filial', d.filial, false, false],
      ['Emissão', d.data_nf, false, false],
      ['Etapa atual', d.etapa_nome, true, false],
      ['Chave NF', d.chave_nf || '—', true, true],
    ];
    var html = '';
    fields.forEach(function (f) {
      var mono = f[3] ? 'font-family:Consolas,monospace;font-size:14px;word-break:break-all;' : 'font-size:15px;';
      var accent = f[0] === 'Valor Total' ? 'color:#2347C8;font-weight:700;font-size:18px;' : 'color:#141922;font-weight:500;';
      html += '<div style="' + (f[2] ? 'grid-column:1/-1;' : '') + 'padding:8px 0;border-bottom:1px solid #F6F6F6">'
            + '<div style="font-size:12px;font-weight:700;color:#606060;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px">' + f[0] + '</div>'
            + '<div style="' + mono + accent + '">' + (f[1] || '—') + '</div></div>';
    });
    document.getElementById('rv-fields').innerHTML = html;

    // Formulário (campos do fluxo)
    var camposBtn = document.getElementById('rv-tabbtn-campos');
    var temCampos = d.campos && d.campos.length;
    if (temCampos) {
      var camHtml = '';
      d.campos.forEach(function (c) {
        camHtml += '<div style="padding:9px 0;border-bottom:1px solid #F6F6F6">'
                 + '<div style="font-size:12px;font-weight:700;color:#606060;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px">' + rvEsc(c.label) + '</div>'
                 + '<div style="font-size:15px;color:#141922;font-weight:500;word-break:break-word">' + rvEsc(c.valor) + '</div></div>';
      });
      document.getElementById('rv-campos').innerHTML = camHtml;
      document.getElementById('rv-campos-badge').textContent = d.campos.length;
      camposBtn.style.display = '';
    } else {
      document.getElementById('rv-campos').innerHTML = '';
      camposBtn.style.display = 'none';
    }

    // Parcelas — último bloco da aba Dados. Sem parcela o bloco inteiro some,
    // em vez de ocupar espaço do painel (460px) com um estado vazio.
    var parcHtml = '';
    if (d.parcelas && d.parcelas.length) {
      parcHtml += '<div style="margin-top:18px;padding-top:16px;border-top:1px solid #EAEAEA">';
      parcHtml += '<div style="font-size:12px;font-weight:700;color:#606060;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px">'
                + d.parcelas.length + (d.parcelas.length === 1 ? ' Parcela' : ' Parcelas') + '</div>';
      parcHtml += '<div style="background:#F8F8F8;border-radius:6px;overflow:hidden;border:1px solid #EAEAEA">';
      parcHtml += '<div style="display:grid;grid-template-columns:44px 1fr 1fr auto;gap:10px;padding:8px 14px;background:#606060;color:#fff;font-size:12px;font-weight:700;text-transform:uppercase">'
                + '<span>#</span><span>Vencimento</span><span>Forma</span><span style="text-align:right">Valor</span></div>';
      d.parcelas.forEach(function (p, pi) {
        parcHtml += '<div style="display:grid;grid-template-columns:44px 1fr 1fr auto;gap:10px;padding:8px 14px;font-size:14px;border-bottom:1px solid #F0F0F0;background:' + (pi % 2 ? '#F8F8F8' : '#fff') + '">'
                  + '<span style="color:#606060;font-weight:700">' + rvEsc(p.num) + '</span>'
                  + '<span style="color:#141922">' + rvEsc(p.venc) + '</span>'
                  + '<span style="color:#606060">' + rvEsc(p.forma || '—') + '</span>'
                  + '<span style="font-weight:700;color:#141922;text-align:right">' + rvEsc(p.valor) + '</span></div>';
      });
      parcHtml += '</div></div>';
    }
    document.getElementById('rv-parcelas').innerHTML = parcHtml;

    // Fluxo — timeline das etapas, mesma leitura da tela de aprovação normal
    var etapas = d.etapas || [];
    var corEtapa = {aprovado:'#00A960', devolvido:'#FF8A2D', pendente:'#888888', pulado:'#B0B0B0'};
    var fluxoHtml = '';
    etapas.forEach(function (e) {
      var atual = e.numero === d.etapa_atual && e.status === 'pendente';
      var cor   = atual ? '#2347C8' : (corEtapa[e.status] || '#888888');
      fluxoHtml += '<div style="display:flex;gap:10px;padding:10px 0;border-bottom:1px solid #F6F6F6">'
        + '<div style="width:24px;height:24px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;'
        + 'font-size:12px;font-weight:700;color:#fff;background:' + cor + '">' + e.numero + '</div>'
        + '<div style="flex:1;min-width:0">'
        + '<div style="font-size:14px;font-weight:700;color:#141922">' + rvEsc(e.nome)
        + (atual ? ' <span style="font-size:10px;font-weight:700;color:#2347C8;background:#EEF2FD;padding:1px 6px;border-radius:8px">ATUAL</span>' : '')
        + '</div>'
        + '<div style="font-size:12px;color:#606060;margin-top:2px">'
        + (e.tipo === 'grupo' ? 'Grupo: ' : '') + rvEsc(e.aprovador || '—')
        + (e.atuado_em ? ' · ' + rvEsc(e.atuado_por || '') + ' em ' + rvEsc(e.atuado_em) : '')
        + '</div>'
        + (e.observacao ? '<div style="font-size:12px;color:#888;margin-top:3px;font-style:italic">' + rvEsc(e.observacao) + '</div>' : '')
        + '</div></div>';
    });
    document.getElementById('rv-fluxo').innerHTML = fluxoHtml
      || '<div style="font-size:14px;color:#888">Sem etapas.</div>';
    document.getElementById('rv-fluxo-badge').textContent = (d.etapa_atual || 0) + '/' + (d.total_etapas || 0);

    // Histórico — mais recente primeiro
    var hist = d.historico || [];
    var histHtml = '';
    hist.forEach(function (h) {
      histHtml += '<div style="padding:9px 0;border-bottom:1px solid #F6F6F6">'
        + '<div style="font-size:13.5px;color:#141922">' + rvEsc(h.label || h.acao) + '</div>'
        + (h.detalhe ? '<div style="font-size:12px;color:#606060;margin-top:2px">' + rvEsc(h.detalhe) + '</div>' : '')
        + '<div style="font-size:11.5px;color:#888;margin-top:2px">' + rvEsc(h.ator || '—')
        + (h.quando ? ' · ' + rvEsc(h.quando) : '') + '</div></div>';
    });
    document.getElementById('rv-historico').innerHTML = histHtml
      || '<div style="font-size:14px;color:#888">Sem histórico.</div>';
    document.getElementById('rv-historico-badge').textContent = hist.length;

    rvRenderObs(d.observacoes || []);

    // Aba padrão: Formulário quando existir; senão Dados
    rvSetTab(temCampos ? 'campos' : 'dados');

    // Barra de anexos + auto-abrir a "Nota Fiscal"
    rvRenderAnexos(d.anexos || []);

    if (window.lucide) window.lucide.createIcons();
  }

  // Observações — mesma leitura do card da tela do processo, mais o campo
  // para escrever uma nova sem sair da revisão. Com observação o bloco fica
  // em destaque (laranja); sem nenhuma, só o campo, discreto.
  function rvRenderObs(obs) {
    var tem = obs.length > 0;
    var html = '<div style="border:1px solid ' + (tem ? '#FFE0B2' : '#EAEAEA') + ';border-left:4px solid ' + (tem ? '#FF8A2D' : '#DFDFDF')
      + ';border-radius:8px;background:' + (tem ? '#FFFAF3' : '#FAFAFA') + ';overflow:hidden">'
      + '<div style="padding:9px 14px 0;font-size:12px;font-weight:700;color:' + (tem ? '#C75B00' : '#606060') + ';text-transform:uppercase;letter-spacing:.04em">'
      + (tem ? (obs.length === 1 ? '1 Observação' : obs.length + ' Observações') : 'Observações') + '</div>';
    if (tem) {
      html += '<div id="rv-obs-lista" style="max-height:220px;overflow-y:auto;padding:0 12px">';
      obs.forEach(function (o) {
        html += '<div style="padding:9px 11px;background:#fff;border:1px solid #EAEAEA;border-radius:6px;margin-top:6px">'
          + '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">'
          + '<span style="font-size:12.5px;font-weight:700;color:#141922">' + rvEsc(o.autor) + '</span>'
          + (o.eu ? '<span style="font-size:10px;font-weight:700;color:#2347C8;background:#EEF2FD;border-radius:8px;padding:1px 6px">você</span>' : '')
          + '<span style="font-size:11.5px;color:#888;margin-left:auto">' + rvEsc(o.quando) + '</span></div>'
          + '<div style="font-size:13.5px;color:#141922;line-height:1.5;white-space:pre-wrap;word-break:break-word">' + rvEsc(o.texto) + '</div>'
          + '</div>';
      });
      html += '</div>';
    }
    html += '<div style="padding:10px 12px 12px">'
      + '<textarea id="rv-obs-texto" rows="2" placeholder="Adicionar observação…" '
      + 'onkeydown="if((event.ctrlKey||event.metaKey)&&event.key===\'Enter\'){event.preventDefault();rvSalvarObs();}" '
      + 'onfocus="this.style.borderColor=\'#2347C8\';this.style.background=\'#fff\'" onblur="this.style.borderColor=\'#DFDFDF\';this.style.background=\'#fff\'" '
      + 'style="width:100%;box-sizing:border-box;border:1.5px solid #DFDFDF;border-radius:6px;padding:8px 11px;font-size:13.5px;font-family:inherit;color:#141922;background:#fff;resize:vertical;min-height:58px;outline:none"></textarea>'
      + '<div style="display:flex;align-items:center;gap:10px;margin-top:6px">'
      + '<span id="rv-obs-erro" style="display:none;font-size:12px;font-weight:700;color:#B3261E;margin-right:auto"></span>'
      + '<span id="rv-obs-dica" style="font-size:11.5px;color:#888;margin-left:auto">Visível para todos os participantes · Ctrl+Enter</span>'
      + '<button type="button" id="rv-obs-salvar" onclick="rvSalvarObs()" '
      + 'style="background:transparent;color:#2347C8;border:1px solid #2347C8;border-radius:4px;padding:6px 14px;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap">Adicionar</button>'
      + '</div></div></div>';
    var box = document.getElementById('rv-obs');
    box.innerHTML = html;
    box.style.display = '';
    var lista = document.getElementById('rv-obs-lista');
    if (lista) lista.scrollTop = lista.scrollHeight;   // mais recente à vista
  }

  // Grava a observação do processo em revisão e redesenha o bloco com a
  // lista que o servidor devolve. Não sai da fila nem recarrega a página.
  window.rvSalvarObs = function () {
    var it    = rvItems[rvIndex];
    var ta    = document.getElementById('rv-obs-texto');
    var erro  = document.getElementById('rv-obs-erro');
    var btn   = document.getElementById('rv-obs-salvar');
    var texto = ta.value.trim();
    // No painel de 460px o aviso e a dica não cabem lado a lado: o aviso
    // ocupa o lugar da dica até a próxima tentativa.
    var dica  = document.getElementById('rv-obs-dica');
    var falha = function (msg) { erro.textContent = msg; erro.style.display = ''; dica.style.display = 'none'; ta.focus(); };
    if (texto.length < 3) { falha('Mínimo 3 caracteres.'); return; }

    btn.disabled = true; btn.style.opacity = '0.5'; btn.textContent = 'Salvando…';
    var fd = new FormData();
    fd.append('csrf_token', rvCsrf);
    fd.append('processo_id', it.processoId);
    fd.append('texto', texto);
    fetch('?module=tarefas&action=observacao_ajax', { method: 'POST', body: fd })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (res) {
        if (!res || !res.success) {
          btn.disabled = false; btn.style.opacity = '1'; btn.textContent = 'Adicionar';
          falha((res && res.message) || 'Não foi possível salvar.');
          return;
        }
        if (it.dados) it.dados.observacoes = res.observacoes;   // vale ao voltar pelo "Pular"
        // Só redesenha se o painel ainda mostra este processo (pode ter
        // pulado para o próximo enquanto salvava).
        if (rvItems[rvIndex] === it) rvRenderObs(res.observacoes);
      })
      .catch(function () {
        btn.disabled = false; btn.style.opacity = '1'; btn.textContent = 'Adicionar';
        falha('Falha de conexão — a observação não foi salva.');
      });
  };

  function rvEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  // Escolhe o anexo a abrir por padrão: prioriza "Nota Fiscal"
  function rvIndexNotaFiscal(anexos) {
    for (var i = 0; i < anexos.length; i++) {
      var t = ((anexos[i].tipo || '') + ' ' + (anexos[i].nome || '')).toLowerCase();
      if (t.indexOf('nota fiscal') !== -1) return i;
    }
    return anexos.length ? 0 : -1;
  }

  function rvRenderAnexos(anexos) {
    var bar = document.getElementById('rv-anexos-bar');
    if (!anexos.length) {
      bar.innerHTML = '<span style="font-size:13px;color:#888">Sem anexos</span>';
      rvResetViewer();
      return;
    }
    var html = '';
    anexos.forEach(function (a, ai) {
      html += '<button type="button" id="rv-anx-' + ai + '" class="rv-anx-chip" '
            + 'onclick="rvPreviewAnexo(' + ai + ')" '
            + 'style="display:inline-flex;align-items:center;gap:6px;flex-shrink:0;padding:7px 12px;border-radius:6px;border:1.5px solid #EAEAEA;background:#fff;cursor:pointer;font-size:12.5px;font-weight:600;color:#141922;transition:all 0.15s">'
            + '<i data-lucide="file-text" width="14" height="14" style="color:#2347C8;flex-shrink:0"></i>'
            + '<span style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + rvEsc(a.tipo || a.nome) + '</span>'
            + '</button>';
    });
    bar.innerHTML = html;
    // guarda a lista para o preview
    rvItems[rvIndex]._anexos = anexos;
    if (window.lucide) window.lucide.createIcons();

    var alvo = rvIndexNotaFiscal(anexos);
    if (alvo !== -1) rvPreviewAnexo(alvo);
  }

  window.rvPreviewAnexo = function (idx) {
    var anexos = rvItems[rvIndex]._anexos || [];
    var a = anexos[idx];
    if (!a) return;

    // Destaca o chip ativo
    document.querySelectorAll('.rv-anx-chip').forEach(function (el) {
      el.style.borderColor = '#EAEAEA'; el.style.background = '#fff'; el.style.color = '#141922';
    });
    var chip = document.getElementById('rv-anx-' + idx);
    if (chip) { chip.style.borderColor = '#2347C8'; chip.style.background = '#EEF2FD'; chip.style.color = '#2347C8'; }

    document.getElementById('rv-viewer-fname').textContent = a.nome;
    document.getElementById('rv-viewer-empty').style.display = 'none';

    var ext = (a.nome.split('.').pop() || '').toLowerCase();
    var isImg = ['jpg','jpeg','png','gif','webp','bmp'].indexOf(ext) !== -1
             || (a.mime || '').indexOf('image/') === 0;
    var fr = document.getElementById('rv-viewer-frame');
    var im = document.getElementById('rv-viewer-img');
    if (isImg) {
      fr.style.display = 'none'; fr.removeAttribute('src');
      im.src = a.url; im.style.display = 'block';
    } else {
      im.style.display = 'none'; im.removeAttribute('src');
      fr.src = a.url; fr.style.display = 'block';
    }
  };

  // Alterna abas do painel de processo
  window.rvSetTab = function (tab) {
    rvTab = tab;
    [['dados','rv-tab-dados','rv-tabbtn-dados'],
     ['campos','rv-tab-campos','rv-tabbtn-campos'],
     ['fluxo','rv-tab-fluxo','rv-tabbtn-fluxo'],
     ['historico','rv-tab-historico','rv-tabbtn-historico']].forEach(function (t) {
      var painel = document.getElementById(t[1]);
      var btn    = document.getElementById(t[2]);
      var ativo  = t[0] === tab;
      if (painel) painel.style.display = ativo ? '' : 'none';
      if (btn) {
        btn.style.color = ativo ? '#2347C8' : '#888';
        btn.style.borderBottomColor = ativo ? '#2347C8' : 'transparent';
      }
    });
  };

  // ═══ FILTROS AVANÇADOS (client-side) ═════════════════════
  var filterSlaSituacao  = '';
  // Fila (todas | pessoal | grupo): não existe mais select — quem seleciona
  // são os cards de resumo do topo.
  var filterFila         = '';
  // Devolvidos é eixo próprio: soma comigo + grupo, então não pode entrar em
  // filterFila (que é excludente entre as duas filas).
  var filterDevolvidos   = false;

  // Cards de resumo são o seletor da fila (toggle: clicar de novo remove o filtro)
  window.filterByStatCard = function (tipo) {
    if (tipo === 'total') {
      filterFila          = '';
      filterSlaSituacao   = '';
      filterDevolvidos    = false;
    } else if (tipo === 'pessoal' || tipo === 'grupo') {
      filterFila = (filterFila === tipo) ? '' : tipo;
    } else if (tipo === 'devolvidos') {
      filterDevolvidos = !filterDevolvidos;
    } else if (tipo === 'sla-vencido') {
      filterSlaSituacao = (filterSlaSituacao === 'vencido') ? '' : 'vencido';
    } else if (tipo === 'sla-atencao') {
      filterSlaSituacao = (filterSlaSituacao === 'atencao') ? '' : 'atencao';
    }

    applyAdvancedFilters();
  };

  function updateStatCardsActive() {
    var estado = {
      'stat-total':       !filterFila && !filterSlaSituacao && !filterDevolvidos,
      'stat-pessoal':     filterFila === 'pessoal',
      'stat-grupo':       filterFila === 'grupo',
      'stat-devolvidos':  filterDevolvidos,
      'stat-sla-vencido': filterSlaSituacao === 'vencido',
      'stat-sla-atencao': filterSlaSituacao === 'atencao'
    };
    Object.keys(estado).forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.classList.toggle('stat-card--active', estado[id]);
    });
  }

  // ═══ PERÍODO (data do processo) ══════════════════════════
  // Os campos De/Até só aparecem em "Período personalizado".
  window.onPeriodoChange = function () {
    var custom = document.getElementById('filter-periodo').value === 'personalizado';
    document.getElementById('filter-periodo-de').style.display  = custom ? '' : 'none';
    document.getElementById('filter-periodo-ate').style.display = custom ? '' : 'none';
    applyAdvancedFilters();
  };

  function ymd(d) {
    return d.getFullYear() + '-'
         + String(d.getMonth() + 1).padStart(2, '0') + '-'
         + String(d.getDate()).padStart(2, '0');
  }

  // Preset → intervalo {de, ate}. Datas de tarefa são passadas (entrada do
  // processo no fluxo), por isso os presets olham para trás.
  function periodoRange(preset) {
    var hoje = new Date(); hoje.setHours(0, 0, 0, 0);
    var ini = new Date(hoje), fim = new Date(hoje);

    switch (preset) {
      case 'hoje':
        break;
      case 'ontem':
        ini.setDate(ini.getDate() - 1);
        fim.setDate(fim.getDate() - 1);
        break;
      case 'esta-semana':
        ini.setDate(ini.getDate() - ((hoje.getDay() + 6) % 7)); // segunda-feira
        fim = new Date(ini); fim.setDate(fim.getDate() + 6);
        break;
      case 'ultimos-7':
        ini.setDate(ini.getDate() - 6);
        break;
      case 'ultimos-30':
        ini.setDate(ini.getDate() - 29);
        break;
      case 'este-mes':
        ini = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
        fim = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0);
        break;
      default:
        return null;
    }
    return { de: ymd(ini), ate: ymd(fim) };
  }

  function periodoAtual() {
    var preset = document.getElementById('filter-periodo').value;
    if (preset === 'personalizado') {
      var de  = document.getElementById('filter-periodo-de').value;
      var ate = document.getElementById('filter-periodo-ate').value;
      return (de || ate) ? { de: de, ate: ate } : null;
    }
    return periodoRange(preset);
  }

  // Lojas marcadas no filtro multi-seleção (valores = nome em minúsculo,
  // batendo com o data-loja de cada card). Vazio = todas.
  function tasksLojasMarcadas() {
    var out = [];
    document.querySelectorAll('#flt-lojas-tar input[type=checkbox]:checked')
            .forEach(function (cb) { out.push(cb.value); });
    return out;
  }

  // Teste de filtro de um card, num lugar só. `ignorar` deixa um eixo de fora
  // — é o que permite contar quantas tarefas cada opção do select traria sem
  // que a opção já escolhida zere as contagens das outras.
  function cardPassaFiltros(card, f, ignorar) {
    if (f.fila && (card.dataset.fila || '') !== f.fila) return false;
    if (f.search) {
      var text = (card.dataset.codigo || '') + ' ' + (card.dataset.fornecedor || '') + ' ' + (card.dataset.nf || '');
      var matchText = text.indexOf(f.search) !== -1;
      var matchCnpj = f.searchDigits.length > 0 && (card.dataset.cnpj || '').indexOf(f.searchDigits) !== -1;
      if (!matchText && !matchCnpj) return false;
    }
    if (ignorar !== 'grupo' && f.grupo && (card.dataset.grupo || '') !== f.grupo) return false;
    if (ignorar !== 'depto' && f.depto && (card.dataset.depto || '') !== f.depto) return false;
    if (f.lojas.length && f.lojas.indexOf(card.dataset.loja || '') === -1) return false;
    if (ignorar !== 'fluxo' && f.fluxo && (card.dataset.template || '') !== f.fluxo) return false;
    if (f.devolvidos && (card.dataset.status || '') !== 'devolvido') return false;
    if (f.sla && (card.dataset.sla || '') !== f.sla) return false;
    if (f.periodo) {
      var dt = card.dataset.data || '';
      if (!dt) return false;
      if (f.periodo.de  && dt < f.periodo.de)  return false;
      if (f.periodo.ate && dt > f.periodo.ate) return false;
    }
    return true;
  }

  // Pendura "(N)" em cada opção de Grupo, Departamento e Fluxo: N é quantas
  // tarefas da tela aquela opção mostraria, já contando os demais filtros
  // ativos. A opção em branco fica sem número — ela é o rótulo do filtro, e
  // um total ali não bate com a soma das opções (nem toda tarefa tem
  // departamento ou grupo).
  function updateFilterCounts(cards, f) {
    [['filter-grupo', 'grupo',    'grupo'],
     ['filter-depto', 'depto',    'depto'],
     ['filter-fluxo', 'template', 'fluxo']].forEach(function (cfg) {
      var sel = document.getElementById(cfg[0]);
      if (!sel) return;

      var contagem = {};
      cards.forEach(function (card) {
        if (!cardPassaFiltros(card, f, cfg[2])) return;
        var v = card.dataset[cfg[1]] || '';
        contagem[v] = (contagem[v] || 0) + 1;
      });

      Array.from(sel.options).forEach(function (opt) {
        var base = opt.dataset.label || opt.textContent;
        opt.dataset.label = base;   // guarda o rótulo puro na 1ª passada
        opt.textContent   = opt.value === '' ? base : base + ' (' + (contagem[opt.value] || 0) + ')';
      });
    });
  }

  // Estado atual de todos os eixos de filtro, lido dos controles da barra.
  function filtroAtual() {
    var searchVal = (document.getElementById('tasks-search').value || '').toLowerCase().trim();
    return {
      fila:         filterFila,
      search:       searchVal,
      searchDigits: searchVal.replace(/[^\d]/g, ''),
      grupo:        (document.getElementById('filter-grupo').value || '').toLowerCase(),
      depto:        (document.getElementById('filter-depto').value || '').toLowerCase(),
      lojas:        tasksLojasMarcadas(),
      fluxo:        (document.getElementById('filter-fluxo').value || '').toLowerCase(),
      devolvidos:   filterDevolvidos,
      sla:          filterSlaSituacao,
      periodo:      periodoAtual()
    };
  }

  window.applyAdvancedFilters = function () {
    var f       = filtroAtual();
    var periodo = f.periodo;
    var sortVal = document.getElementById('filter-sort').value;
    var cards   = document.querySelectorAll('.task-card');
    var listEl  = document.getElementById('tasks-list');

    // Destaca o seletor quando há período ativo (mesmo padrão dos demais filtros)
    var perSel = document.getElementById('filter-periodo');
    perSel.style.borderColor = periodo ? '#2347C8' : '#E5E7EB';
    perSel.style.color       = periodo ? '#2347C8' : '#606060';

    cards.forEach(function (card) {
      var antes = card.style.display !== 'none';
      var agora = cardPassaFiltros(card, f, null);
      card.style.display = agora ? '' : 'none';

      // Com o Lote aberto, a seleção acompanha o filtro: o que saiu da tela sai
      // do lote e o que entrou já entra marcado. Card que continuou visível
      // mantém o que o usuário tinha marcado ou desmarcado à mão.
      if (batchAtivo()) {
        var cb = card.querySelector('.batch-checkbox');
        if (cb) {
          if (!agora)      cb.checked = false;
          else if (!antes) cb.checked = true;
        }
      }
    });

    if (batchAtivo()) updateBatchCount();

    updateFilterCounts(cards, f);

    var visibleCards = Array.from(cards).filter(function (c) { return c.style.display !== 'none'; });
    visibleCards.sort(function (a, b) {
      if (sortVal === 'venc-asc') {
        // Sem parcela vai para o fim; empate desempata pelo mais antigo.
        var va = a.dataset.vencimento || '9999-12-31', vb = b.dataset.vencimento || '9999-12-31';
        if (va !== vb) return va < vb ? -1 : 1;
        return (a.dataset.codigo || '').localeCompare(b.dataset.codigo || '');
      }
      if (sortVal === 'value-desc') return parseFloat(b.dataset.valor) - parseFloat(a.dataset.valor);
      if (sortVal === 'value-asc')  return parseFloat(a.dataset.valor) - parseFloat(b.dataset.valor);
      if (sortVal === 'age-asc') return (a.dataset.codigo || '').localeCompare(b.dataset.codigo || '');
      return (b.dataset.codigo || '').localeCompare(a.dataset.codigo || '');
    });
    visibleCards.forEach(function (c) { listEl.appendChild(c); });

    updateStatCardsActive();
    syncFiltersToUrl();
  };

  // Mantém a URL sempre refletindo os filtros ativos — assim o botão
  // "Voltar" da tela de detalhe do processo (origem=tarefas&voltar=...)
  // consegue restaurar exatamente o que estava filtrado.
  function syncFiltersToUrl() {
    var params = new URLSearchParams();
    params.set('module', 'tarefas');
    params.set('action', 'index');

    if (filterFila) params.set('fila', filterFila);

    var busca = document.getElementById('tasks-search').value.trim();
    if (busca) params.set('busca', busca);

    var grupo = document.getElementById('filter-grupo').value;
    if (grupo) params.set('grupo', grupo);

    var depto = document.getElementById('filter-depto').value;
    if (depto) params.set('depto', depto);

    // Uma entrada `loja=` por loja marcada — link antigo com uma só continua valendo.
    tasksLojasMarcadas().forEach(function (v) { params.append('loja', v); });

    var fluxo = document.getElementById('filter-fluxo').value;
    if (fluxo) params.set('fluxo', fluxo);

    var periodo = document.getElementById('filter-periodo').value;
    if (periodo) {
      params.set('periodo', periodo);
      if (periodo === 'personalizado') {
        var pDe  = document.getElementById('filter-periodo-de').value;
        var pAte = document.getElementById('filter-periodo-ate').value;
        if (pDe)  params.set('de', pDe);
        if (pAte) params.set('ate', pAte);
      }
    }

    if (filterSlaSituacao) params.set('sla', filterSlaSituacao);

    var sort = document.getElementById('filter-sort').value;
    if (sort && sort !== 'venc-asc') params.set('sort', sort);

    history.replaceState(null, '', '?' + params.toString());
  }

  // Restaura os filtros a partir da URL ao carregar a página — cobre tanto
  // o retorno via botão "Voltar" do detalhe do processo quanto um simples
  // recarregar/favoritar da tela com filtros aplicados.
  function restoreFiltersFromUrl() {
    var params = new URLSearchParams(location.search);
    var known  = ['fila', 'busca', 'grupo', 'depto', 'loja', 'fluxo', 'sla', 'sort', 'periodo', 'de', 'ate'];
    var hasAny = known.some(function (k) { return params.has(k); });

    if (!hasAny) {
      updateStatCardsActive();
      // Sem filtro nenhum a lista já vem pronta do servidor — só as contagens
      // dos selects precisam ser escritas.
      updateFilterCounts(document.querySelectorAll('.task-card'), filtroAtual());
      return;
    }

    if (params.has('fila'))      filterFila                                        = params.get('fila');
    if (params.has('busca'))     document.getElementById('tasks-search').value     = params.get('busca');
    if (params.has('grupo'))     document.getElementById('filter-grupo').value     = params.get('grupo');
    if (params.has('depto'))     document.getElementById('filter-depto').value     = params.get('depto');
    if (params.has('loja')) {
      var lojasUrl = params.getAll('loja');
      document.querySelectorAll('#flt-lojas-tar input[type=checkbox]').forEach(function (cb) {
        cb.checked = lojasUrl.indexOf(cb.value) !== -1;
      });
      fltLojasAtualizarRotulo('tar');
    }
    if (params.has('fluxo'))     document.getElementById('filter-fluxo').value     = params.get('fluxo');
    if (params.has('sort'))      document.getElementById('filter-sort').value      = params.get('sort');

    if (params.has('periodo')) {
      document.getElementById('filter-periodo').value = params.get('periodo');
      if (params.has('de'))  document.getElementById('filter-periodo-de').value  = params.get('de');
      if (params.has('ate')) document.getElementById('filter-periodo-ate').value = params.get('ate');
      var custom = params.get('periodo') === 'personalizado';
      document.getElementById('filter-periodo-de').style.display  = custom ? '' : 'none';
      document.getElementById('filter-periodo-ate').style.display = custom ? '' : 'none';
    }

    if (params.has('sla')) filterSlaSituacao = params.get('sla');

    applyAdvancedFilters();
  }

  restoreFiltersFromUrl();

}());
</script>
