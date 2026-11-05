<?php
require_once '../config/session.php';
exigirLogin();
$uid    = usuario()['id'];
$filtro = $_GET['filtro']   ?? 'todos';
$proj_f = (int)($_GET['projeto'] ?? 0);

// Ação: assumir / atualizar tarefa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'assumir') {
    $tid         = (int)($_POST['tarefa_id'] ?? 0);
    $data_inicio = trim($_POST['data_inicio'] ?? '');
    $data_fim    = trim($_POST['data_fim']    ?? ($_POST['prazo'] ?? ''));
    $novo_status = trim($_POST['novo_status']?? '');
    $status_ok   = ['backlog','todo','doing','review','done'];
    $atualiza_datas = array_key_exists('data_inicio', $_POST) || array_key_exists('data_fim', $_POST) || array_key_exists('prazo', $_POST);

    if ($tid && !($atualiza_datas && $data_inicio && $data_fim && $data_fim < $data_inicio)) {
        try {
            $pdo    = db();
            $campos = "responsavel_id=?, atualizado_em=NOW()";
            $params = [$uid];
            if ($atualiza_datas) {
                $campos .= ", data_inicio=?, data_fim=?, prazo=?";
                $params[] = $data_inicio ?: null;
                $params[] = $data_fim    ?: null;
                $params[] = $data_fim    ?: null;
            }
            if (in_array($novo_status, $status_ok, true)) {
                $campos .= ", status=?";
                $params[] = $novo_status;
            }
            $params[] = $tid;
            $pdo->prepare("UPDATE tarefas SET $campos WHERE id=?")->execute($params);

            // Recalcula progresso
            $row = $pdo->prepare("SELECT projeto_id FROM tarefas WHERE id=?");
            $row->execute([$tid]);
            $projeto_id = (int)$row->fetchColumn();
            if ($projeto_id) {
                $res = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status='done') AS concluidas FROM tarefas WHERE projeto_id=?");
                $res->execute([$projeto_id]);
                $nums      = $res->fetch();
                $progresso = $nums['total'] > 0 ? round(($nums['concluidas']/$nums['total'])*100) : 0;
                $pdo->prepare("UPDATE projetos SET progresso=?, atualizado_em=NOW() WHERE id=?")->execute([$progresso, $projeto_id]);
            }
        } catch (PDOException $e) {}
    }
    $redir = 'minhas-tarefas.php?filtro=' . urlencode($filtro) . '&ok=1';
    if ($proj_f) $redir .= '&projeto=' . $proj_f;
    header('Location: ' . $redir);
    exit;
}

// Lista de projetos para o filtro
try {
    $projetos_lista = db()->query("SELECT id, nome FROM projetos WHERE status+0 != 6 ORDER BY nome")->fetchAll();
} catch (PDOException $e) { $projetos_lista = []; }

// Colunas do Kanban de tarefas
$colunas = [
    'backlog' => 'Backlog',
    'todo'    => 'A Fazer',
    'doing'   => 'Em Andamento',
    'review'  => 'Revisão',
    'done'    => 'Concluído',
];

$col_cor = [
    'backlog' => 'border-zinc-300',
    'todo'    => 'border-blue-300',
    'doing'   => 'border-amber-400',
    'review'  => 'border-purple-300',
    'done'    => 'border-emerald-400',
];
$col_badge = [
    'backlog' => 'bg-zinc-100 text-zinc-600',
    'todo'    => 'bg-blue-50 text-blue-600',
    'doing'   => 'bg-amber-50 text-amber-600',
    'review'  => 'bg-purple-50 text-purple-600',
    'done'    => 'bg-emerald-50 text-emerald-700',
];
$pcls = [
    'Alta'  => 'text-red-600 bg-red-50',
    'Média' => 'text-amber-600 bg-amber-50',
    'Baixa' => 'text-zinc-500 bg-zinc-100',
];

// Monta WHERE base
$wheres = ['1=1'];
if ($filtro === 'minhas')     $wheres[] = "t.responsavel_id = $uid";
elseif ($filtro === 'livres') $wheres[] = "t.responsavel_id IS NULL";
if ($proj_f)                  $wheres[] = "t.projeto_id = $proj_f";

$where_sql = implode(' AND ', $wheres);

// Busca tarefas e organiza por coluna
$tarefas_por_col = [];
foreach (array_keys($colunas) as $col) $tarefas_por_col[$col] = [];

try {
    $stmt = db()->prepare("
        SELECT t.*, COALESCE(t.data_fim, t.prazo) AS data_fim_eff, p.nome AS projeto, u.nome AS responsavel_nome
        FROM tarefas t
        LEFT JOIN projetos p ON p.id = t.projeto_id
        LEFT JOIN usuarios u ON u.id = t.responsavel_id
        WHERE $where_sql
        ORDER BY FIELD(t.prioridade,'Alta','Média','Baixa'), COALESCE(t.data_fim, t.prazo) ASC
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $t) {
        $status = $t['status'];
        if (isset($tarefas_por_col[$status])) {
            $tarefas_por_col[$status][] = $t;
        }
    }
} catch (PDOException $e) { $tarefas_por_col = []; }

$total_tarefas = 0;
foreach ($tarefas_por_col as $arr) $total_tarefas += count($arr);

$filtros = [
    ['todos',  'Todas'],
    ['minhas', 'Minhas'],
    ['livres', 'Disponíveis'],
];

// Nome do projeto selecionado
$proj_nome = '';
foreach ($projetos_lista as $pl) {
    if ($pl['id'] == $proj_f) { $proj_nome = $pl['nome']; break; }
}
?>
<!DOCTYPE html>
<html class="light" lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tarefas | Exemplo DevSystems</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Epilogue:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/app.css">
  <style>
    /* ── Board layout ─────────────────────────────────────────── */
    #kanban-board {
      display: grid;
      grid-template-columns: 1fr;
      gap: 1rem;
      align-items: start;
    }
    @media (min-width: 640px) {
      #kanban-board { grid-template-columns: repeat(2, 1fr); }
    }
    @media (min-width: 1024px) {
      #kanban-board { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (min-width: 1400px) {
      #kanban-board {
        display: flex; flex-wrap: nowrap; overflow-x: auto;
        padding-bottom: 1.5rem;
        scrollbar-width: thin; scrollbar-color: #d4d4d8 transparent;
      }
      #kanban-board::-webkit-scrollbar       { height: 6px; }
      #kanban-board::-webkit-scrollbar-track  { background: transparent; }
      #kanban-board::-webkit-scrollbar-thumb  { background: #d4d4d8; border-radius: 99px; }
      #kanban-board > .kanban-col-wrap { flex: 0 0 280px; min-width: 280px; }
    }

    /* ── Coluna ───────────────────────────────────────────────── */
    .kanban-col {
      min-height: 40vh; border-radius: 0.75rem; padding: 0.75rem;
      background: rgba(0,0,0,0.02);
      transition: background 0.15s, box-shadow 0.15s;
    }
    .kanban-col.drag-over {
      background: rgba(99,102,241,0.06);
      box-shadow: inset 0 0 0 2px rgba(99,102,241,0.3);
    }

    /* ── Card ─────────────────────────────────────────────────── */
    .kanban-card { touch-action: none; }
    .kanban-card.dragging { opacity: 0.4; transform: rotate(1.5deg); }

    /* ── Placeholder de drop ──────────────────────────────────── */
    .drop-placeholder {
      min-height: 56px; border: 2px dashed #d4d4d8; border-radius: 0.75rem;
      display: flex; align-items: center; justify-content: center;
      font-size: 0.7rem; color: #a1a1aa; margin-top: 0.5rem;
      opacity: 0; transition: opacity 0.15s;
    }
    .kanban-col.drag-over .drop-placeholder { opacity: 1; }

    .line-clamp-2 {
      display: -webkit-box; -webkit-line-clamp: 2;
      -webkit-box-orient: vertical; overflow: hidden;
    }

    @media (max-width: 639px) {
      #kanban-board { scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; }
      #kanban-board > .kanban-col-wrap { scroll-snap-align: start; }
    }

    /* ── Project Picker ───────────────────────────────────────── */
    .proj-picker { position: relative; }
    .proj-trigger {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 7px 14px 7px 10px; border-radius: 10px;
      border: 1.5px solid #e4e4e7; background: #fff;
      font-size: 13px; font-weight: 600; color: #3f3f46;
      cursor: pointer; transition: all .18s;
      font-family: inherit; white-space: nowrap;
      max-width: 280px;
    }
    .proj-trigger:hover, .proj-trigger:focus-visible {
      border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.08);
    }
    .proj-trigger.ativo {
      border-color: #6366f1; background: #eef2ff; color: #4f46e5;
    }
    .proj-trigger .trigger-avatar {
      width: 22px; height: 22px; border-radius: 6px;
      display: flex; align-items: center; justify-content: center;
      font-size: 9px; font-weight: 800; letter-spacing: .04em;
      flex-shrink: 0;
    }
    .proj-trigger .trigger-label { overflow: hidden; text-overflow: ellipsis; }
    .proj-trigger .trigger-chevron {
      font-size: 16px; color: #a1a1aa; transition: transform .2s;
      margin-left: auto;
    }
    .proj-trigger.aberto .trigger-chevron { transform: rotate(180deg); }

    .proj-panel {
      position: absolute; top: calc(100% + 6px); left: 0; z-index: 60;
      width: 320px; max-height: 380px;
      background: #fff; border: 1.5px solid #e4e4e7;
      border-radius: 14px; box-shadow: 0 16px 48px rgba(0,0,0,.12), 0 2px 8px rgba(0,0,0,.06);
      opacity: 0; pointer-events: none;
      transform: translateY(-6px) scale(.98);
      transition: opacity .18s, transform .18s;
      display: flex; flex-direction: column;
    }
    .proj-panel.aberto { opacity: 1; pointer-events: all; transform: translateY(0) scale(1); }

    .proj-search-wrap {
      padding: 10px 12px 8px; border-bottom: 1px solid #f4f4f5;
      position: relative;
    }
    .proj-search-wrap .material-symbols-outlined {
      position: absolute; left: 20px; top: 50%; transform: translateY(-50%);
      font-size: 16px; color: #a1a1aa; pointer-events: none;
    }
    .proj-search {
      width: 100%; border: 1.5px solid #e4e4e7; border-radius: 8px;
      padding: 7px 10px 7px 32px; font-size: 12.5px;
      background: #fafafa; color: #27272a; outline: none;
      font-family: inherit; transition: border-color .15s, box-shadow .15s;
    }
    .proj-search:focus {
      border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.08);
      background: #fff;
    }
    .proj-search::placeholder { color: #a1a1aa; }

    .proj-list {
      overflow-y: auto; padding: 6px;
      scrollbar-width: thin; scrollbar-color: #e4e4e7 transparent;
      flex: 1;
    }
    .proj-list::-webkit-scrollbar { width: 4px; }
    .proj-list::-webkit-scrollbar-thumb { background: #e4e4e7; border-radius: 99px; }

    .proj-item {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 10px; border-radius: 9px;
      cursor: pointer; transition: all .12s;
      border: 1.5px solid transparent;
    }
    .proj-item:hover { background: #f4f4f5; }
    .proj-item.selecionado {
      background: #eef2ff; border-color: #c7d2fe;
    }
    .proj-item .item-avatar {
      width: 28px; height: 28px; border-radius: 7px;
      display: flex; align-items: center; justify-content: center;
      font-size: 10px; font-weight: 800; letter-spacing: .04em;
      flex-shrink: 0;
    }
    .proj-item .item-nome {
      font-size: 13px; font-weight: 600; color: #27272a;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
      flex: 1;
    }
    .proj-item .item-check {
      font-size: 16px; color: #6366f1; opacity: 0; transition: opacity .12s;
    }
    .proj-item.selecionado .item-check { opacity: 1; }

    .proj-item-all {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 10px; border-radius: 9px;
      cursor: pointer; transition: all .12s;
      border: 1.5px solid transparent;
      margin-bottom: 2px;
    }
    .proj-item-all:hover { background: #f4f4f5; }
    .proj-item-all.selecionado {
      background: #eef2ff; border-color: #c7d2fe;
    }
    .proj-item-all .item-icon {
      width: 28px; height: 28px; border-radius: 7px;
      background: #f4f4f5; color: #71717a;
      display: flex; align-items: center; justify-content: center;
      font-size: 16px; flex-shrink: 0;
    }
    .proj-item-all .item-nome { font-size: 13px; font-weight: 600; color: #52525b; flex: 1; }
    .proj-item-all .item-check { font-size: 16px; color: #6366f1; opacity: 0; transition: opacity .12s; }
    .proj-item-all.selecionado .item-check { opacity: 1; }

    .proj-empty {
      padding: 24px 12px; text-align: center;
      font-size: 12px; color: #a1a1aa;
    }

    /* Cores para avatares de projeto */
    .avatar-c0 { background: #dbeafe; color: #1d4ed8; }
    .avatar-c1 { background: #fce7f3; color: #be185d; }
    .avatar-c2 { background: #d1fae5; color: #047857; }
    .avatar-c3 { background: #fef3c7; color: #b45309; }
    .avatar-c4 { background: #ede9fe; color: #6d28d9; }
    .avatar-c5 { background: #ffe4e6; color: #be123c; }
    .avatar-c6 { background: #ccfbf1; color: #0f766e; }
    .avatar-c7 { background: #e0e7ff; color: #4338ca; }

    /* ── Modal ────────────────────────────────────────────────── */
    .modal-backdrop {
      position: fixed; inset: 0; background: rgba(0,0,0,0.35);
      backdrop-filter: blur(2px); z-index: 50;
      display: flex; align-items: center; justify-content: center;
      opacity: 0; pointer-events: none; transition: opacity 0.2s;
    }
    .modal-backdrop.open { opacity: 1; pointer-events: all; }
    .modal-box {
      background: #fff; border-radius: 1rem; padding: 2rem;
      width: 100%; max-width: 420px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.15);
      transform: translateY(12px) scale(0.98); transition: transform 0.2s;
    }
    .modal-backdrop.open .modal-box { transform: translateY(0) scale(1); }
    .field-label {
      display: block; font-size: 0.72rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: 0.06em;
      color: #71717a; margin-bottom: 0.4rem;
    }
    .field-input {
      width: 100%; border: 1.5px solid #e4e4e7; border-radius: 0.6rem;
      padding: 0.6rem 0.875rem; font-size: 0.875rem;
      background: #fff; color: inherit; outline: none;
      transition: border-color 0.15s, box-shadow 0.15s; font-family: inherit;
    }
    .field-input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.10); }
  </style>
</head>
<body class="bg-surface text-on-surface font-body antialiased">
<?php include '../config/topnav.php'; ?>
<?php include '../config/notificacoes.php'; ?>
<div class="flex">
<?php include '../config/sidebar.php'; ?>

<main class="flex-1 md:ml-64 p-4 sm:p-6 lg:p-8 min-h-screen page-fade-in">

  <!-- Header -->
  <div class="mb-6 sm:mb-8 flex items-start sm:items-end justify-between flex-wrap gap-4">
    <div>
      <div class="flex items-center gap-2 mb-2">
        <span class="w-8 h-[2px] bg-primary inline-block"></span>
        <span class="text-[10px] font-label font-bold uppercase tracking-widest text-secondary">Gestão</span>
      </div>
      <h1 class="font-headline text-3xl sm:text-4xl font-extrabold tracking-tight">
        Board de Tarefas
        <span class="text-secondary text-2xl font-medium">(<?= $total_tarefas ?>)</span>
      </h1>
      <?php if ($proj_nome): ?>
      <p class="text-secondary text-sm mt-1">
        Filtrando por: <span class="font-bold text-primary">📁 <?= htmlspecialchars($proj_nome) ?></span>
        <a href="?filtro=<?= urlencode($filtro) ?>" class="ml-2 text-zinc-400 hover:text-zinc-600 text-xs">✕ limpar</a>
      </p>
      <?php else: ?>
      <p class="text-secondary text-sm mt-1">Arraste para mudar o status — clique para editar</p>
      <?php endif; ?>
    </div>
    <div class="flex items-center gap-2 sm:gap-3 flex-wrap">
      <a href="nova-tarefa.php<?= $proj_f ? '?projeto_id='.$proj_f : '' ?>"
         class="flex items-center gap-2 bg-gradient-to-b from-primary-container to-primary
                text-white font-headline font-bold uppercase tracking-widest text-xs px-4 py-2.5
                rounded-md hover:opacity-90 transition-all active:scale-[0.98]">
        <span class="material-symbols-outlined text-sm">add_task</span> Nova Tarefa
      </a>
    </div>
  </div>

  <?php if ($_GET['ok'] ?? ''): ?>
  <div class="mb-5 flex items-center gap-2 bg-emerald-50 border-l-2 border-emerald-500 px-4 py-3 text-sm text-emerald-700 rounded-r-lg">
    <span class="material-symbols-outlined text-sm">check_circle</span> Tarefa atualizada com sucesso!
  </div>
  <?php endif; ?>

  <!-- Barra de filtros -->
  <div class="flex items-center gap-3 mb-6 flex-wrap">

    <!-- Filtros de responsável -->
    <div class="flex gap-1.5">
      <?php foreach ($filtros as [$v, $l]):
        $ativo = $filtro === $v;
        $href  = '?filtro=' . $v . ($proj_f ? '&projeto='.$proj_f : '');
      ?>
      <a href="<?= $href ?>"
         class="px-4 py-2 text-xs font-bold uppercase tracking-wider rounded-full transition-all
                <?= $ativo ? 'bg-primary text-white' : 'bg-surface-container text-secondary hover:bg-surface-container-high' ?>">
        <?= $l ?>
      </a>
      <?php endforeach; ?>
    </div>

    <div class="w-px h-6 bg-zinc-200 hidden sm:block"></div>

    <!-- Project Picker -->
    <div class="proj-picker" id="projPicker">
      <button type="button"
              class="proj-trigger <?= $proj_f ? 'ativo' : '' ?>"
              id="projTrigger"
              onclick="toggleProjPanel()"
              aria-haspopup="listbox"
              aria-expanded="false">
        <?php if ($proj_f && $proj_nome): ?>
          <span class="trigger-avatar avatar-c<?= $proj_f % 8 ?>">
            <?= strtoupper(mb_substr($proj_nome, 0, 2)) ?>
          </span>
          <span class="trigger-label"><?= htmlspecialchars($proj_nome) ?></span>
          <span class="material-symbols-outlined trigger-chevron">expand_more</span>
        <?php else: ?>
          <span class="material-symbols-outlined" style="font-size:16px;color:#71717a;">folder_open</span>
          <span class="trigger-label">Todos os projetos</span>
          <span class="material-symbols-outlined trigger-chevron">expand_more</span>
        <?php endif; ?>
      </button>

      <div class="proj-panel" id="projPanel">
        <div class="proj-search-wrap">
          <span class="material-symbols-outlined">search</span>
          <input type="text" class="proj-search" id="projSearch"
                 placeholder="Buscar projeto…"
                 autocomplete="off"
                 onkeyup="filtrarProjetos(this.value)">
        </div>
        <div class="proj-list" id="projList">

          <!-- Opção "Todos" -->
          <div class="proj-item-all <?= !$proj_f ? 'selecionado' : '' ?>"
               onclick="selecionarProjeto(0, 'Todos os projetos')"
               data-search="todos os projetos">
            <span class="item-icon">
              <span class="material-symbols-outlined" style="font-size:16px;">folder_open</span>
            </span>
            <span class="item-nome">Todos os projetos</span>
            <span class="material-symbols-outlined item-check" style="font-variation-settings:'FILL' 1">check_circle</span>
          </div>

          <!-- Projetos -->
          <?php foreach ($projetos_lista as $i => $pl): ?>
          <div class="proj-item <?= $proj_f == $pl['id'] ? 'selecionado' : '' ?>"
               onclick="selecionarProjeto(<?= (int)$pl['id'] ?>, '<?= htmlspecialchars(addslashes($pl['nome']), ENT_QUOTES) ?>')"
               data-search="<?= htmlspecialchars(mb_strtolower($pl['nome']), ENT_QUOTES) ?>">
            <span class="item-avatar avatar-c<?= $pl['id'] % 8 ?>">
              <?= strtoupper(mb_substr($pl['nome'], 0, 2)) ?>
            </span>
            <span class="item-nome"><?= htmlspecialchars($pl['nome']) ?></span>
            <span class="material-symbols-outlined item-check" style="font-variation-settings:'FILL' 1">check_circle</span>
          </div>
          <?php endforeach; ?>

          <div class="proj-empty" id="projEmpty" style="display:none;">
            Nenhum projeto encontrado
          </div>
        </div>
      </div>
    </div>

    <!-- Limpar filtro de projeto (se ativo) -->
    <?php if ($proj_f): ?>
    <a href="?filtro=<?= urlencode($filtro) ?>"
       class="flex items-center gap-1 text-[11px] text-zinc-400 hover:text-red-400 transition-colors"
       title="Limpar filtro de projeto">
      <span class="material-symbols-outlined" style="font-size:14px;">close</span>
      Limpar
    </a>
    <?php endif; ?>
  </div>

  <!-- Seletor de coluna (mobile) -->
  <div class="flex gap-2 overflow-x-auto pb-2 mb-4 sm:hidden scrollbar-none">
    <?php foreach ($colunas as $col_key => $col_label): ?>
    <button onclick="scrollToCol('<?= $col_key ?>')"
            class="flex-shrink-0 text-[11px] font-bold px-3 py-1.5 rounded-full border
                   <?= $col_badge[$col_key] ?> border-zinc-200 whitespace-nowrap">
      <?= $col_label ?>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- Board Kanban -->
  <div id="kanban-board">
    <?php foreach ($colunas as $col_key => $col_label):
      $cards = $tarefas_por_col[$col_key] ?? [];
      $borda = $col_cor[$col_key];
      $badge = $col_badge[$col_key];
    ?>
    <div class="kanban-col-wrap" data-wrap-col="<?= $col_key ?>" id="wrap-col-<?= $col_key ?>">

      <!-- Header da coluna -->
      <div class="flex items-center justify-between mb-3 pb-3 border-b-2 <?= $borda ?>">
        <span class="font-headline font-bold text-sm text-on-surface"><?= $col_label ?></span>
        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $badge ?> count-badge"
              data-col="<?= $col_key ?>"><?= count($cards) ?></span>
      </div>

      <!-- Coluna (zona de drop) -->
      <div class="kanban-col" data-col="<?= $col_key ?>">

        <?php foreach ($cards as $t):
          $e_minha  = (int)$t['responsavel_id'] === $uid;
          $tem_resp = !empty($t['responsavel_id']);
        ?>
        <div class="kanban-card bg-white rounded-xl p-4 shadow-sm border border-zinc-100
                    hover:border-primary/20 hover:shadow-md transition-all cursor-grab active:cursor-grabbing select-none mb-3"
             draggable="true"
             data-id="<?= $t['id'] ?>"
             data-status="<?= $col_key ?>"
             onclick="abrirModal(<?= htmlspecialchars(json_encode([
               'id'              => $t['id'],
               'titulo'          => $t['titulo'],
               'projeto'         => $t['projeto'] ?? '',
               'status'          => $t['status'],
               'prioridade'      => $t['prioridade'],
               'data_inicio'     => $t['data_inicio'] ?? '',
               'data_fim'        => $t['data_fim_eff'] ?? '',
               'prazo'           => $t['data_fim_eff'] ?? '',
               'responsavel_nome'=> $t['responsavel_nome'] ?? '',
               'e_minha'         => $e_minha,
             ]), ENT_QUOTES) ?>)">

          <!-- Prioridade + ID -->
          <div class="flex items-center justify-between mb-2">
            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $pcls[$t['prioridade']] ?? 'text-zinc-500 bg-zinc-100' ?>">
              <?= htmlspecialchars($t['prioridade']) ?>
            </span>
            <span class="text-[10px] text-zinc-400 font-mono">#<?= $t['id'] ?></span>
          </div>

          <!-- Título -->
          <p class="text-sm font-semibold text-on-surface mb-1 leading-snug line-clamp-2">
            <?= htmlspecialchars($t['titulo']) ?>
          </p>

          <!-- Projeto -->
          <?php if (!empty($t['projeto']) && !$proj_f): ?>
          <p class="text-[11px] text-secondary mb-2 leading-snug truncate">
            📁 <?= htmlspecialchars($t['projeto']) ?>
          </p>
          <?php endif; ?>

          <!-- Responsável -->
          <div class="flex items-center justify-between mt-2 pt-2 border-t border-zinc-50">
            <?php if ($t['responsavel_nome']): ?>
            <div class="flex items-center gap-1.5">
              <div class="w-5 h-5 rounded-full <?= $e_minha ? 'bg-emerald-100 text-emerald-600' : 'bg-primary/10 text-primary' ?> text-[10px] font-bold flex items-center justify-center">
                <?= strtoupper(substr($t['responsavel_nome'], 0, 2)) ?>
              </div>
              <span class="text-[10px] text-secondary truncate max-w-[100px]">
                <?= $e_minha ? 'Você' : htmlspecialchars($t['responsavel_nome']) ?>
              </span>
            </div>
            <?php elseif (!$tem_resp): ?>
            <span class="text-[10px] font-bold text-primary flex items-center gap-1">
              <span class="material-symbols-outlined text-xs">person_add</span> Disponível
            </span>
            <?php else: ?><div></div><?php endif; ?>

            <?php if (!empty($t['data_fim_eff'])): ?>
            <span class="text-[10px] text-secondary">📅 <?= htmlspecialchars($t['data_fim_eff']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <div class="drop-placeholder">Soltar aqui</div>
      </div>
    </div><!-- /.kanban-col-wrap -->
    <?php endforeach; ?>
  </div><!-- /#kanban-board -->

</main>
</div>

<!-- Modal -->
<div class="modal-backdrop" id="modal" onclick="fecharModal(event)">
  <div class="modal-box" onclick="event.stopPropagation()">
    <div class="flex items-start justify-between mb-5">
      <div class="flex-1 min-w-0 pr-4">
        <p class="text-[10px] font-bold uppercase tracking-widest text-secondary mb-1" id="m-projeto"></p>
        <h2 class="font-headline font-bold text-lg leading-snug text-on-surface" id="m-titulo"></h2>
      </div>
      <button onclick="fecharModal()" class="text-zinc-400 hover:text-zinc-700 transition-colors mt-0.5">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>

    <div class="flex gap-2 mb-5 flex-wrap" id="m-badges"></div>
    <div class="bg-zinc-50 rounded-lg px-3 py-2.5 mb-5 text-sm" id="m-resp-info"></div>

    <form method="POST" id="modal-form">
      <input type="hidden" name="acao"      value="assumir">
      <input type="hidden" name="tarefa_id" id="m-id">
      <input type="hidden" name="filtro"    value="<?= htmlspecialchars($filtro) ?>">

      <label class="field-label">Status</label>
      <div id="m-status-track" style="display:flex;border-radius:10px;overflow:hidden;border:1.5px solid #e4e4e7;background:#fff;margin-bottom:6px;">
        <?php foreach([
          ['backlog','○','Backlog'],['todo','▷','A Fazer'],['doing','◑','Andamento'],['review','◎','Revisão'],['done','●','Concluído']
        ] as $i => [$val,$ico,$lbl]): ?>
        <button type="button" onclick="selStatus(this,'<?= $val ?>')" data-val="<?= $val ?>" class="s-btn"
                style="flex:1;padding:10px 2px;border:none;cursor:pointer;font-size:10px;font-weight:700;background:transparent;color:#a1a1aa;transition:all .18s;<?= $i < 4 ? 'border-right:1px solid #e4e4e7;' : '' ?>">
          <div style="font-size:13px;margin-bottom:2px;"><?= $ico ?></div><?= $lbl ?>
        </button>
        <?php endforeach; ?>
      </div>
      <div style="height:3px;border-radius:0 0 8px 8px;background:#f1f1f1;margin-bottom:6px;">
        <div id="m-status-bar" style="height:100%;width:0%;transition:width .25s,background .25s;border-radius:inherit;"></div>
      </div>
      <div id="m-status-label" style="display:none;padding:8px 12px;border-radius:8px;font-size:12px;font-weight:600;margin-bottom:16px;"></div>
      <input type="hidden" name="novo_status" id="m-novo-status">

      <label class="field-label">Data de inicio</label>
      <input type="date" name="data_inicio" id="m-data-inicio" class="field-input mb-3">

      <label class="field-label">Data de termino</label>
      <input type="date" name="data_fim" id="m-data-fim" class="field-input mb-5">

      <div class="flex gap-3">
        <button type="submit"
                class="flex-1 flex items-center justify-center gap-2
                       bg-gradient-to-b from-primary-container to-primary
                       text-white font-headline font-bold uppercase tracking-widest text-xs
                       py-2.5 rounded-md hover:opacity-90 transition-all active:scale-[0.98]">
          <span class="material-symbols-outlined text-sm">person_check</span>
          <span id="m-btn-txt">Assumir tarefa</span>
        </button>
        <button type="button" onclick="fecharModal()"
                class="px-4 py-2.5 rounded-md text-sm text-secondary hover:text-on-surface transition-colors">
          Cancelar
        </button>
      </div>
    </form>
  </div>
</div>

<script src="../assets/js/app.js"></script>
<script>
// ── Modal ──────────────────────────────────────────────────────
var modal = document.getElementById('modal');
var scls = {todo:'text-zinc-500 bg-zinc-100',doing:'text-blue-600 bg-blue-50',review:'text-amber-600 bg-amber-50',done:'text-emerald-700 bg-emerald-50',backlog:'text-zinc-400 bg-zinc-50'};
var slbl = {todo:'A Fazer',doing:'Em Andamento',review:'Revisão',done:'Concluído',backlog:'Backlog'};
var pcls = {Alta:'text-red-600 bg-red-50','Média':'text-amber-600 bg-amber-50',Baixa:'text-zinc-500 bg-zinc-100'};
var statusCfg = {
  backlog:{ w:'8%',  bg:'#a1a1aa', label:'Sem prioridade definida', lbg:'#f4f4f5', lc:'#52525b' },
  todo:   { w:'28%', bg:'#3b82f6', label:'Aguardando início',       lbg:'#eff6ff', lc:'#1d4ed8' },
  doing:  { w:'54%', bg:'#f59e0b', label:'Em desenvolvimento',      lbg:'#fffbeb', lc:'#b45309' },
  review: { w:'78%', bg:'#a855f7', label:'Aguardando aprovação',    lbg:'#faf5ff', lc:'#7e22ce' },
  done:   { w:'100%',bg:'#10b981', label:'Tarefa concluída!',       lbg:'#ecfdf5', lc:'#065f46' },
};

var isDragging = false;

function abrirModal(t) {
  if (isDragging) return;
  document.getElementById('m-id').value            = t.id;
  document.getElementById('m-titulo').textContent  = t.titulo;
  document.getElementById('m-projeto').textContent = t.projeto ? '📁 ' + t.projeto : '';
  document.getElementById('m-data-inicio').value   = t.data_inicio || '';
  document.getElementById('m-data-fim').value      = t.data_fim || t.prazo || '';
  document.getElementById('m-btn-txt').textContent = t.e_minha ? 'Atualizar tarefa' : 'Assumir tarefa';

  selStatus(document.querySelector('#m-status-track [data-val="' + t.status + '"]'), t.status);

  document.getElementById('m-badges').innerHTML =
    '<span class="text-[10px] font-bold px-2 py-0.5 rounded-full ' + (pcls[t.prioridade]||'') + '">' + t.prioridade + '</span>' +
    '<span class="text-[10px] font-bold px-2 py-0.5 rounded-full ' + (scls[t.status]||'') + '">' + (slbl[t.status]||t.status) + '</span>';

  var resp = document.getElementById('m-resp-info');
  if (t.responsavel_nome) {
    resp.innerHTML = t.e_minha
      ? '<span class="text-emerald-600 font-semibold">✔ Você já é responsável por esta tarefa.</span>'
      : '<span class="text-secondary">Responsável: <strong>' + t.responsavel_nome + '</strong></span>';
  } else {
    resp.innerHTML = '<span class="text-primary font-semibold">Tarefa disponível — sem responsável.</span>';
  }

  modal.classList.add('open');
  document.getElementById('m-data-inicio').focus();
}

function fecharModal(e) {
  if (e && e.target !== modal) return;
  modal.classList.remove('open');
}

function selStatus(btn, val) {
  if (!btn) return;
  document.querySelectorAll('#m-status-track .s-btn').forEach(function(b) {
    b.style.background = 'transparent'; b.style.color = '#a1a1aa';
  });
  var c = statusCfg[val] || statusCfg.backlog;
  btn.style.background = c.bg + '20';
  btn.style.color = c.bg;
  document.getElementById('m-status-bar').style.cssText += ';width:' + c.w + ';background:' + c.bg;
  var lbl = document.getElementById('m-status-label');
  lbl.style.display = 'block';
  lbl.style.background = c.lbg;
  lbl.style.color = c.lc;
  lbl.textContent = c.label;
  document.getElementById('m-novo-status').value = val;
}

(function validarDatasModal() {
  var inicio = document.getElementById('m-data-inicio');
  var fim = document.getElementById('m-data-fim');
  function validar() {
    if (!inicio || !fim) return;
    fim.setCustomValidity(inicio.value && fim.value && fim.value < inicio.value
      ? 'Data de termino deve ser maior ou igual a data de inicio.'
      : '');
  }
  if (inicio && fim) {
    inicio.addEventListener('change', validar);
    fim.addEventListener('change', validar);
  }
})();

document.addEventListener('keydown', function(e) { if (e.key === 'Escape') modal.classList.remove('open'); });

// ── Project Picker ───────────────────────────────────────────
(function() {
  var trigger = document.getElementById('projTrigger');
  var panel   = document.getElementById('projPanel');
  var search  = document.getElementById('projSearch');
  var picker  = document.getElementById('projPicker');

  window.toggleProjPanel = function() {
    var aberto = panel.classList.contains('aberto');
    if (aberto) {
      fecharProjPanel();
    } else {
      panel.classList.add('aberto');
      trigger.classList.add('aberto');
      trigger.setAttribute('aria-expanded', 'true');
      setTimeout(function() { search.focus(); }, 80);
    }
  };

  function fecharProjPanel() {
    panel.classList.remove('aberto');
    trigger.classList.remove('aberto');
    trigger.setAttribute('aria-expanded', 'false');
    search.value = '';
    filtrarProjetos('');
  }

  // Fechar ao clicar fora
  document.addEventListener('click', function(e) {
    if (!picker.contains(e.target)) fecharProjPanel();
  });

  // Fechar com Escape
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && panel.classList.contains('aberto')) {
      fecharProjPanel();
      trigger.focus();
    }
  });

  // Navegação por teclado dentro do painel
  search.addEventListener('keydown', function(e) {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      var items = panel.querySelectorAll('.proj-item:not([style*="display: none"]), .proj-item-all:not([style*="display: none"])');
      if (items.length) items[0].focus();
    }
  });

  panel.addEventListener('keydown', function(e) {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    e.preventDefault();
    var items = Array.from(panel.querySelectorAll('.proj-item:not([style*="display: none"]), .proj-item-all:not([style*="display: none"])'));
    var idx = items.indexOf(document.activeElement);
    if (e.key === 'ArrowDown' && idx < items.length - 1) items[idx + 1].focus();
    if (e.key === 'ArrowUp' && idx > 0) items[idx - 1].focus();
    if (e.key === 'ArrowUp' && idx === 0) search.focus();
  });

  window.filtrarProjetos = function(q) {
    q = q.toLowerCase().trim();
    var items   = panel.querySelectorAll('.proj-item, .proj-item-all');
    var empty   = document.getElementById('projEmpty');
    var visivel = 0;
    items.forEach(function(el) {
      var txt = (el.getAttribute('data-search') || '').toLowerCase();
      var show = !q || txt.indexOf(q) !== -1;
      el.style.display = show ? '' : 'none';
      if (show) visivel++;
    });
    empty.style.display = visivel === 0 ? '' : 'none';
  };

  window.selecionarProjeto = function(id, nome) {
    var filtro = '<?= htmlspecialchars($filtro) ?>';
    var url = '?filtro=' + encodeURIComponent(filtro);
    if (id) url += '&projeto=' + id;
    window.location.href = url;
  };

  // Tornar items focáveis
  panel.querySelectorAll('.proj-item, .proj-item-all').forEach(function(el) {
    el.setAttribute('tabindex', '0');
    el.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        el.click();
      }
    });
  });
})();

// ── Drag & Drop ──────────────────────────────────────────────
(function () {
  document.querySelectorAll('.kanban-card').forEach(function(card) {
    card.addEventListener('dragstart', function(e) {
      isDragging = true;
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('cardId',  this.dataset.id);
      e.dataTransfer.setData('fromCol', this.dataset.status);
      var c = card;
      setTimeout(function() { c.classList.add('dragging'); }, 0);
    });

    card.addEventListener('dragend', function() {
      this.classList.remove('dragging');
      document.querySelectorAll('.kanban-col').forEach(function(c) {
        c.classList.remove('drag-over');
      });
      setTimeout(function() { isDragging = false; }, 100);
    });
  });

  document.querySelectorAll('.kanban-col').forEach(function(col) {
    col.addEventListener('dragover', function(e) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      document.querySelectorAll('.kanban-col').forEach(function(c) {
        c.classList.remove('drag-over');
      });
      this.classList.add('drag-over');
    });

    col.addEventListener('dragleave', function(e) {
      if (!this.contains(e.relatedTarget)) this.classList.remove('drag-over');
    });

    col.addEventListener('drop', function(e) {
      e.preventDefault();
      this.classList.remove('drag-over');

      var cardId  = e.dataTransfer.getData('cardId');
      var fromCol = e.dataTransfer.getData('fromCol');
      var toCol   = this.dataset.col;

      if (!cardId || fromCol === toCol) return;

      var card = document.querySelector('.kanban-card[data-id="' + cardId + '"]');
      if (!card) return;

      var colOrigem   = document.querySelector('.kanban-col[data-col="' + fromCol + '"]');
      var placeholder = this.querySelector('.drop-placeholder');
      this.insertBefore(card, placeholder);
      card.dataset.status = toCol;
      atualizarContador(fromCol);
      atualizarContador(toCol);

      // Salva via POST no mesmo arquivo
      var formData = new FormData();
      formData.append('acao', 'assumir');
      formData.append('tarefa_id', cardId);
      formData.append('novo_status', toCol);
      formData.append('filtro', '<?= htmlspecialchars($filtro) ?>');

      fetch('minhas-tarefas.php', {
        method: 'POST',
        body: new URLSearchParams({
          acao: 'assumir',
          tarefa_id: cardId,
          novo_status: toCol,
          filtro: '<?= htmlspecialchars($filtro) ?>'
        }),
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        redirect: 'manual'
      })
      .then(function(r) {
        // POST redirects com 302, o que é esperado
        if (r.type === 'opaqueredirect' || r.status === 302 || r.ok) return;
        throw new Error('HTTP ' + r.status);
      })
      .catch(function(err) {
        console.error('Erro ao salvar status:', err);
        if (colOrigem) {
          var phOrig = colOrigem.querySelector('.drop-placeholder');
          colOrigem.insertBefore(card, phOrig);
          card.dataset.status = fromCol;
          atualizarContador(fromCol);
          atualizarContador(toCol);
        }
        alert('Erro ao salvar:\n' + err.message + '\n\nCard revertido.');
      });
    });
  });

  function atualizarContador(statusVal) {
    var col   = document.querySelector('.kanban-col[data-col="' + statusVal + '"]');
    var badge = document.querySelector('.count-badge[data-col="' + statusVal + '"]');
    if (col && badge) badge.textContent = col.querySelectorAll('.kanban-card').length;
  }

  window.scrollToCol = function(colKey) {
    var el = document.getElementById('wrap-col-' + colKey);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
})();
</script>
</body>
</html>
