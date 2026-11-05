<?php
require_once '../config/session.php';
exigirLogin();

$projetos = [];
$erro_db = null;

try {
    $stmt = db()->query("
        SELECT
            p.id AS projeto_id,
            p.nome AS projeto_nome,
            p.cliente,
            p.status AS projeto_status,
            p.progresso AS projeto_progresso,
            t.id AS tarefa_id,
            t.titulo AS tarefa_titulo,
            t.status AS tarefa_status,
            t.prioridade AS tarefa_prioridade,
            t.data_inicio AS tarefa_inicio,
            COALESCE(t.data_fim, t.prazo) AS tarefa_fim,
            u.nome AS responsavel_nome
        FROM projetos p
        LEFT JOIN tarefas t ON t.projeto_id = p.id
        LEFT JOIN usuarios u ON u.id = t.responsavel_id
        ORDER BY p.nome ASC, COALESCE(t.data_inicio, DATE(t.criado_em)) ASC, COALESCE(t.data_fim, t.prazo) ASC, t.titulo ASC
    ");

    foreach ($stmt->fetchAll() as $row) {
        $pid = (int)$row['projeto_id'];
        if (!isset($projetos[$pid])) {
            $projetos[$pid] = [
                'id' => $pid,
                'nome' => $row['projeto_nome'],
                'cliente' => $row['cliente'],
                'status' => $row['projeto_status'],
                'progresso' => (int)($row['projeto_progresso'] ?? 0),
                'inicio' => null,
                'fim' => null,
                'tarefas' => [],
            ];
        }

        if ($row['tarefa_id']) {
            $inicio = $row['tarefa_inicio'];
            $fim = $row['tarefa_fim'];
            $sem_datas = empty($inicio) || empty($fim);

            $projetos[$pid]['tarefas'][] = [
                'id' => (int)$row['tarefa_id'],
                'titulo' => $row['tarefa_titulo'],
                'status' => $row['tarefa_status'],
                'prioridade' => $row['tarefa_prioridade'],
                'responsavel' => $row['responsavel_nome'],
                'inicio' => $inicio,
                'fim' => $fim,
                'sem_datas' => $sem_datas,
            ];

            if (!$sem_datas) {
                if ($projetos[$pid]['inicio'] === null || $inicio < $projetos[$pid]['inicio']) {
                    $projetos[$pid]['inicio'] = $inicio;
                }
                if ($projetos[$pid]['fim'] === null || $fim > $projetos[$pid]['fim']) {
                    $projetos[$pid]['fim'] = $fim;
                }
            }
        }
    }
} catch (PDOException $e) {
    $erro_db = $e->getMessage();
}

$ganttData = ['projetos' => array_values($projetos), 'hoje' => date('Y-m-d')];
?>
<!DOCTYPE html>
<html class="light" lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cronograma | Exemplo DevSystems</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Epilogue:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/frappe-gantt/dist/frappe-gantt.css">
  <link rel="stylesheet" href="../assets/css/app.css">
  <style>
    :root { --gantt-row-height: 42px; }
    .cronograma-shell {
      background: #fff;
      border: 1px solid #f4f4f5;
      border-radius: 0.5rem;
      box-shadow: 0 1px 2px rgba(0,0,0,.05);
      overflow: hidden;
    }
    .cronograma-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 1rem;
      border-bottom: 1px solid #f4f4f5;
      flex-wrap: wrap;
    }
    .cronograma-select {
      min-width: 220px;
      border: 1px solid #e4e4e7;
      border-radius: .25rem;
      background-color: #f0edeb;
      padding: .625rem 2.5rem .625rem .875rem;
      font-size: .875rem;
      outline: none;
    }
    .scale-btn {
      height: 34px;
      padding: 0 .85rem;
      border-radius: .25rem;
      font-size: 10px;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: #5f5e5d;
      transition: all .15s;
    }
    .scale-btn.active {
      color: #fff;
      background: linear-gradient(to bottom, #ea0600, #bb0400);
    }
    .gantt-layout {
      display: grid;
      grid-template-columns: minmax(260px, 340px) minmax(520px, 1fr);
      overflow-x: auto;
    }
    .gantt-left {
      border-right: 1px solid #f4f4f5;
      background: #fff;
      position: sticky;
      left: 0;
      z-index: 2;
    }
    .gantt-left-head {
      height: 75px;
      display: grid;
      align-content: center;
      padding: 0 1rem;
      border-bottom: 1px solid #f4f4f5;
      font-size: 10px;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: #71717a;
      background: #fafafa;
    }
    .gantt-left-row {
      height: var(--gantt-row-height);
      display: flex;
      align-items: center;
      gap: .6rem;
      padding: 0 .85rem;
      border-bottom: 1px solid #fafafa;
      min-width: 0;
    }
    .gantt-left-row.project { background: #fff; font-weight: 800; }
    .gantt-left-row.task { padding-left: 2.55rem; color: #5f5e5d; }
    .gantt-left-row.missing { color: #a1a1aa; }
    .toggle-project {
      width: 24px;
      height: 24px;
      border-radius: .25rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: #f0edeb;
      color: #bb0400;
      flex-shrink: 0;
      transition: all .15s;
    }
    .toggle-project:hover { background: rgba(187,4,0,.1); }
    .toggle-project[disabled] { color: #d4d4d8; cursor: default; }
    .row-title { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .82rem; }
    .row-meta { margin-left: auto; color: #a1a1aa; font-size: 10px; flex-shrink: 0; }
    .gantt-right { min-width: 760px; overflow-x: auto; }
    #gantt { min-height: 260px; }
    .gantt .bar-wrapper.project-row .bar { fill: #bb0400; }
    .gantt .bar-wrapper.project-row .bar-progress { fill: #ea0600; }
    .gantt .bar-wrapper.task-row .bar { fill: rgba(187,4,0,.28); }
    .gantt .bar-wrapper.task-row .bar-progress { fill: rgba(187,4,0,.55); }
    .gantt .bar-wrapper.task-doing .bar { fill: #f59e0b; }
    .gantt .bar-wrapper.task-doing .bar-progress { fill: #d97706; }
    .gantt .bar-wrapper.no-dates .bar { fill: #e4e4e7; stroke-dasharray: 4 3; }
    .gantt .bar-wrapper.no-dates .bar-progress { fill: #d4d4d8; }
    .gantt .bar-label { display: none; }
    .cronograma-empty {
      padding: 3rem 1rem;
      text-align: center;
      color: #71717a;
      font-size: .875rem;
    }
    @media (max-width: 767px) {
      .cronograma-toolbar { align-items: stretch; }
      .cronograma-select { width: 100%; }
      .gantt-layout { grid-template-columns: 260px 760px; }
    }
  </style>
</head>
<body class="bg-surface text-on-surface font-body antialiased">
<?php include '../config/topnav.php'; ?>
<?php include '../config/notificacoes.php'; ?>
<div class="app-shell">
<?php include '../config/sidebar.php'; ?>

<main class="app-main p-8 page-fade-in">
<div class="max-w-7xl mx-auto">
  <div class="mb-8 flex items-end justify-between flex-wrap gap-4">
    <div>
      <div class="flex items-center gap-2 mb-2">
        <span class="w-8 h-[2px] bg-primary inline-block"></span>
        <span class="text-[10px] font-label font-bold uppercase tracking-widest text-secondary">Planejamento</span>
      </div>
      <h1 class="font-headline text-4xl font-extrabold tracking-tight">Cronograma</h1>
      <p class="text-secondary text-sm mt-1">Projetos e tarefas em linha do tempo</p>
    </div>
    <a href="nova-tarefa.php"
       class="flex items-center gap-2 bg-gradient-to-b from-primary-container to-primary text-white font-headline font-bold uppercase tracking-widest text-xs px-6 py-3 hover:opacity-90 transition-all">
      <span class="material-symbols-outlined text-sm">add_task</span> Nova Tarefa
    </a>
  </div>

  <?php if ($erro_db): ?>
  <div class="mb-6 bg-red-50 border-l-2 border-red-400 px-4 py-3 rounded-r-lg flex items-center gap-2 text-sm text-red-600">
    <span class="material-symbols-outlined text-sm">error</span> Erro ao carregar cronograma: <?= htmlspecialchars($erro_db) ?>
  </div>
  <?php endif; ?>

  <section class="cronograma-shell">
    <div class="cronograma-toolbar">
      <div class="flex items-center gap-3 flex-wrap">
        <select id="project-filter" class="cronograma-select" aria-label="Filtrar por projeto">
          <option value="">Todos os projetos</option>
          <?php foreach ($ganttData['projetos'] as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" id="expand-all" class="text-xs font-bold text-primary hover:underline">Expandir todos</button>
        <button type="button" id="collapse-all" class="text-xs font-bold text-secondary hover:text-on-surface">Recolher todos</button>
      </div>
      <div class="flex items-center gap-1 rounded-md bg-surface-container p-1">
        <button type="button" class="scale-btn" data-view="Day">Dia</button>
        <button type="button" class="scale-btn active" data-view="Week">Semana</button>
        <button type="button" class="scale-btn" data-view="Month">Mês</button>
      </div>
    </div>

    <?php if (empty($ganttData['projetos'])): ?>
    <div class="cronograma-empty">Nenhum projeto encontrado para montar o cronograma.</div>
    <?php else: ?>
    <div class="gantt-layout">
      <div class="gantt-left">
        <div class="gantt-left-head">Projetos e tarefas</div>
        <div id="gantt-left-rows"></div>
      </div>
      <div class="gantt-right">
        <div id="gantt"></div>
      </div>
    </div>
    <?php endif; ?>
  </section>
</div>
</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/frappe-gantt/dist/frappe-gantt.umd.js"></script>
<script src="../assets/js/app.js"></script>
<script>
const GANTT_DATA = <?= json_encode($ganttData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

(function() {
  if (!document.getElementById('gantt')) return;

  const rowsEl = document.getElementById('gantt-left-rows');
  const filterEl = document.getElementById('project-filter');
  const expandedKey = 'cronograma-expanded-projects';
  const today = GANTT_DATA.hoje;
  let currentView = 'Week';
  let gantt = null;
  let expanded = new Set(JSON.parse(localStorage.getItem(expandedKey) || '[]'));
  const monthTranslations = {
    January: 'Janeiro', Jan: 'Jan',
    February: 'Fevereiro', Feb: 'Fev',
    March: 'Março', Mar: 'Mar',
    April: 'Abril', Apr: 'Abr',
    May: 'Maio',
    June: 'Junho', Jun: 'Jun',
    July: 'Julho', Jul: 'Jul',
    August: 'Agosto', Aug: 'Ago',
    September: 'Setembro', Sep: 'Set',
    October: 'Outubro', Oct: 'Out',
    November: 'Novembro', Nov: 'Nov',
    December: 'Dezembro', Dec: 'Dez'
  };

  function saveExpanded() {
    localStorage.setItem(expandedKey, JSON.stringify(Array.from(expanded)));
  }

  function fmtDate(value) {
    if (!value) return '--';
    const parts = value.split('-');
    return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : value;
  }

  function clampProjectDates(project) {
    if (project.inicio && project.fim) return project.fim < project.inicio ? [project.inicio, project.inicio] : [project.inicio, project.fim];
    const dated = (project.tarefas || []).filter(t => t.inicio && t.fim);
    if (!dated.length) return [today, today];
    const starts = dated.map(t => t.inicio).sort();
    const ends = dated.map(t => t.fim).sort();
    return [project.inicio || starts[0], project.fim || ends[ends.length - 1]];
  }

  function filteredProjects() {
    const id = filterEl.value;
    return GANTT_DATA.projetos.filter(p => !id || String(p.id) === id);
  }

  function buildVisibleRows() {
    const rows = [];
    filteredProjects().forEach(project => {
      const dates = clampProjectDates(project);
      rows.push({
        kind: 'project',
        project,
        task: {
          id: 'p-' + project.id,
          name: project.nome,
          start: dates[0],
          end: dates[1],
          progress: project.progresso || 0,
          custom_class: 'project-row'
        }
      });

      if (expanded.has(project.id)) {
        (project.tarefas || []).forEach(task => {
          const semDatas = !task.inicio || !task.fim;
          const fallbackDate = task.inicio || dates[0] || today;
          const taskStart = task.inicio || fallbackDate;
          const taskEnd = (task.fim || fallbackDate) < taskStart ? taskStart : (task.fim || fallbackDate);
          rows.push({
            kind: 'task',
            project,
            rawTask: task,
            task: {
              id: 't-' + task.id,
              name: task.titulo,
              start: taskStart,
              end: taskEnd,
              progress: task.status === 'done' ? 100 : 0,
              custom_class: [
                'task-row',
                task.status === 'doing' ? 'task-doing' : '',
                semDatas ? 'no-dates' : ''
              ].filter(Boolean).join(' ')
            }
          });
        });
      }
    });
    return rows;
  }

  function renderLeft(rows) {
    rowsEl.innerHTML = rows.map(row => {
      if (row.kind === 'project') {
        const p = row.project;
        const hasTasks = p.tarefas && p.tarefas.length > 0;
        const isOpen = expanded.has(p.id);
        return `
          <div class="gantt-left-row project">
            <button type="button" class="toggle-project" data-project="${p.id}" ${hasTasks ? '' : 'disabled'} aria-label="${isOpen ? 'Recolher' : 'Expandir'} projeto">
              <span class="material-symbols-outlined text-sm">${hasTasks ? (isOpen ? 'remove' : 'add') : 'horizontal_rule'}</span>
            </button>
            <span class="row-title" title="${escapeHtml(p.nome)}">${escapeHtml(p.nome)}</span>
            <span class="row-meta">${p.tarefas.length}</span>
          </div>`;
      }
      const t = row.rawTask;
      const missing = !t.inicio || !t.fim;
      return `
        <div class="gantt-left-row task ${missing ? 'missing' : ''}">
          <span class="row-title" title="${escapeHtml(t.titulo)}">${escapeHtml(t.titulo)}</span>
          <span class="row-meta">${missing ? 'sem datas' : fmtDate(t.fim)}</span>
        </div>`;
    }).join('');

    rowsEl.querySelectorAll('.toggle-project').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = Number(btn.dataset.project);
        expanded.has(id) ? expanded.delete(id) : expanded.add(id);
        saveExpanded();
        render();
      });
    });
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, chr => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[chr]));
  }

  function translateGanttLabels() {
    const ganttEl = document.getElementById('gantt');
    if (!ganttEl) return;
    ganttEl.querySelectorAll('text').forEach(el => {
      let text = el.textContent;
      Object.keys(monthTranslations).forEach(en => {
        text = text.replace(new RegExp('\\b' + en + '\\b', 'g'), monthTranslations[en]);
      });
      el.textContent = text;
    });
  }

  function queueTranslateGanttLabels() {
    requestAnimationFrame(translateGanttLabels);
    setTimeout(translateGanttLabels, 120);
  }

  function render() {
    const rows = buildVisibleRows();
    renderLeft(rows);
    const tasks = rows.map(row => row.task);
    document.getElementById('gantt').innerHTML = '';
    gantt = new Gantt('#gantt', tasks, {
      view_mode: currentView,
      date_format: 'YYYY-MM-DD',
      readonly: true,
      language: 'pt',
      bar_height: 18,
      padding: 24,
      container_height: 'auto',
      lines: 'both',
      popup_on: 'hover',
      scroll_to: 'start',
      today_button: false,
      popup: ({ task }) => {
        const raw = rows.find(row => row.task.id === task.id);
        if (!raw) return false;
        if (raw.kind === 'project') {
          return `<div class="details-container"><h5>${escapeHtml(raw.project.nome)}</h5><p>${fmtDate(task.start)} - ${fmtDate(task.end)}</p></div>`;
        }
        return `<div class="details-container"><h5>${escapeHtml(raw.rawTask.titulo)}</h5><p>${fmtDate(raw.rawTask.inicio)} - ${fmtDate(raw.rawTask.fim)}</p><p>${escapeHtml(raw.rawTask.responsavel || 'Sem responsavel')}</p></div>`;
      }
    });
    queueTranslateGanttLabels();
  }

  filterEl.addEventListener('change', render);
  document.getElementById('expand-all').addEventListener('click', () => {
    filteredProjects().forEach(p => { if (p.tarefas.length) expanded.add(p.id); });
    saveExpanded();
    render();
  });
  document.getElementById('collapse-all').addEventListener('click', () => {
    filteredProjects().forEach(p => expanded.delete(p.id));
    saveExpanded();
    render();
  });
  document.querySelectorAll('.scale-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      currentView = btn.dataset.view;
      document.querySelectorAll('.scale-btn').forEach(b => b.classList.toggle('active', b === btn));
      if (gantt && typeof gantt.change_view_mode === 'function') {
        gantt.change_view_mode(currentView, true);
        queueTranslateGanttLabels();
      } else {
        render();
      }
    });
  });

  if (!expanded.size && GANTT_DATA.projetos[0] && GANTT_DATA.projetos[0].tarefas.length) {
    expanded.add(GANTT_DATA.projetos[0].id);
    saveExpanded();
  }
  render();
})();
</script>
</body>
</html>
