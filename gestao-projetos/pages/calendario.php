<?php
require_once '../config/session.php';
exigirLogin();

$mes = (int)($_GET['mes'] ?? date('n'));
$ano = (int)($_GET['ano'] ?? date('Y'));
if ($mes < 1)  { $mes = 12; $ano--; }
if ($mes > 12) { $mes = 1;  $ano++; }

$primeiro_dia = mktime(0,0,0,$mes,1,$ano);
$dias_no_mes  = (int)date('t', $primeiro_dia);
$dia_semana   = (int)date('w', $primeiro_dia); // 0=Dom

try {
    $stmt = db()->prepare("
        SELECT t.id, t.titulo, COALESCE(t.data_fim, t.prazo) AS prazo, t.prioridade, t.status, p.nome AS projeto
        FROM tarefas t
        LEFT JOIN projetos p ON p.id = t.projeto_id
        WHERE COALESCE(t.data_fim, t.prazo) BETWEEN ? AND ?
        ORDER BY COALESCE(t.data_fim, t.prazo) ASC
    ");
    $stmt->execute([
        "$ano-".str_pad($mes,2,'0',STR_PAD_LEFT)."-01",
        "$ano-".str_pad($mes,2,'0',STR_PAD_LEFT)."-$dias_no_mes"
    ]);
    $tarefas_raw = $stmt->fetchAll();
} catch (PDOException $e) { $tarefas_raw = []; }

// Agrupar tarefas por dia
$eventos = [];
foreach ($tarefas_raw as $t) {
    $dia = (int)date('j', strtotime($t['prazo']));
    $eventos[$dia][] = $t;
}

// Marcar todas as terças do mês com reunião
$tercas = [];
for ($d = 1; $d <= $dias_no_mes; $d++) {
    $ts = mktime(0,0,0,$mes,$d,$ano);
    if (date('w', $ts) == 2) { // 2 = Terça
        $tercas[$d] = true;
    }
}

$meses_pt = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
$dias_sem  = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
$prev_mes  = $mes - 1; $prev_ano = $ano; if ($prev_mes < 1)  { $prev_mes = 12; $prev_ano--; }
$next_mes  = $mes + 1; $next_ano = $ano; if ($next_mes > 12) { $next_mes = 1;  $next_ano++; }
$prio_cor  = ['Alta'=>'#ef4444','Média'=>'#f59e0b','Baixa'=>'#3b82f6'];
$slbl      = ['todo'=>'A Fazer','doing'=>'Em Andamento','review'=>'Revisão','done'=>'Concluído','backlog'=>'Backlog'];
$hoje_dia  = (int)date('j'); $hoje_mes = (int)date('n'); $hoje_ano = (int)date('Y');

// Prepara dados do mês para JS
$eventos_js = [];
for ($d = 1; $d <= $dias_no_mes; $d++) {
    $eventos_js[$d] = [
        'reuniao' => isset($tercas[$d]),
        'tarefas' => $eventos[$d] ?? [],
    ];
}
?>
<!DOCTYPE html>
<html class="light" lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Calendário | Exemplo DevSystems</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Epilogue:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/app.css">
  <style>
    /* Modal */
    .modal-backdrop {
      position: fixed; inset: 0;
      background: rgba(0,0,0,.35);
      backdrop-filter: blur(3px);
      z-index: 50;
      display: flex; align-items: center; justify-content: center;
      opacity: 0; pointer-events: none;
      transition: opacity .2s;
    }
    .modal-backdrop.open { opacity: 1; pointer-events: all; }
    .modal-box {
      background: #fff;
      border-radius: 1rem;
      width: 100%; max-width: 460px;
      max-height: 85vh;
      display: flex; flex-direction: column;
      box-shadow: 0 24px 64px rgba(0,0,0,.13);
      transform: translateY(12px) scale(.98);
      transition: transform .2s;
      overflow: hidden;
    }
    .modal-backdrop.open .modal-box { transform: none; }

    /* Células do calendário */
    .cal-cell {
      min-height: 88px;
      padding: 6px;
      border-radius: 10px;
      cursor: pointer;
      transition: background .12s;
      position: relative;
    }
    .cal-cell:hover { background: #f4f4f5; }
    .cal-cell.hoje  { outline: 2px solid #6366f1; background: rgba(99,102,241,.05); }
    .cal-cell.terca-cell { background: rgba(16,185,129,.04); }
    .cal-cell.terca-cell:hover { background: rgba(16,185,129,.09); }

    /* Evento reunião */
    .tag-reuniao {
      display: flex; align-items: center; gap: 3px;
      background: #ecfdf5; color: #065f46;
      font-size: 9px; font-weight: 700;
      padding: 2px 5px; border-radius: 4px;
      margin-bottom: 2px; white-space: nowrap;
      overflow: hidden; text-overflow: ellipsis;
    }

    /* Tag tarefa */
    .tag-tarefa {
      font-size: 9px; font-weight: 700;
      padding: 2px 5px; border-radius: 4px;
      margin-bottom: 2px; color: #fff;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    /* Tarefa row no modal */
    .tarefa-row {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 0; border-bottom: 1px solid #f1f1f1;
      text-decoration: none; color: inherit;
      transition: background .12s;
    }
    .tarefa-row:last-child { border-bottom: none; }
    .tarefa-row:hover { background: #f8f8f8; margin: 0 -1rem; padding-left: 1rem; padding-right: 1rem; border-radius: 8px; }
    .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  </style>
</head>
<body class="bg-surface text-on-surface font-body antialiased">
<?php include '../config/topnav.php'; ?>
<?php include '../config/notificacoes.php'; ?>
<div class="app-shell">
<?php include '../config/sidebar.php'; ?>
<main class="app-main p-8 page-fade-in">
<div class="max-w-5xl mx-auto">

  <!-- Header -->
  <div class="mb-8 flex items-end justify-between flex-wrap gap-4">
    <div>
      <div class="flex items-center gap-2 mb-2">
        <span class="w-8 h-[2px] bg-primary inline-block"></span>
        <span class="text-[10px] font-label font-bold uppercase tracking-widest text-secondary">Agenda</span>
      </div>
      <h1 class="font-headline text-4xl font-extrabold tracking-tight">Calendário</h1>
    </div>
    <!-- Legenda -->
    <div class="flex items-center gap-4 text-xs text-secondary">
      <span class="flex items-center gap-1.5">
        <span style="width:10px;height:10px;border-radius:3px;background:#10b981;display:inline-block;"></span>
        Reunião (toda terça 08h)
      </span>
      <span class="flex items-center gap-1.5">
        <span style="width:10px;height:10px;border-radius:3px;background:#ef4444;display:inline-block;"></span>
        Tarefa alta
      </span>
      <span class="flex items-center gap-1.5">
        <span style="width:10px;height:10px;border-radius:3px;background:#f59e0b;display:inline-block;"></span>
        Tarefa média
      </span>
    </div>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-zinc-100 p-6">

    <!-- Navegação -->
    <div class="flex items-center justify-between mb-6">
      <h2 class="font-headline font-bold text-xl"><?= $meses_pt[$mes] ?> <?= $ano ?></h2>
      <div class="flex gap-1 items-center">
        <a href="?mes=<?= $prev_mes ?>&ano=<?= $prev_ano ?>"
           class="p-2 hover:bg-surface-container rounded-lg transition-colors">
          <span class="material-symbols-outlined text-secondary">chevron_left</span>
        </a>
        <a href="?mes=<?= date('n') ?>&ano=<?= date('Y') ?>"
           class="px-3 py-1.5 text-xs font-bold bg-surface-container text-secondary rounded-lg hover:bg-surface-container-high transition-colors">
          Hoje
        </a>
        <a href="?mes=<?= $next_mes ?>&ano=<?= $next_ano ?>"
           class="p-2 hover:bg-surface-container rounded-lg transition-colors">
          <span class="material-symbols-outlined text-secondary">chevron_right</span>
        </a>
      </div>
    </div>

    <!-- Cabeçalho dias semana -->
    <div class="grid grid-cols-7 mb-1">
      <?php foreach ($dias_sem as $i => $ds): ?>
      <div class="text-center text-[10px] font-bold uppercase tracking-wider py-2
                  <?= $i === 2 ? 'text-emerald-600' : 'text-secondary' ?>">
        <?= $ds ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Grid de dias -->
    <div class="grid grid-cols-7 gap-1">
      <?php
      for ($i = 0; $i < $dia_semana; $i++) echo '<div class="min-h-[88px]"></div>';

      for ($dia = 1; $dia <= $dias_no_mes; $dia++):
        $is_hoje  = ($dia === $hoje_dia && $mes === $hoje_mes && $ano === $hoje_ano);
        $is_terca = isset($tercas[$dia]);
        $tarefas_dia = $eventos[$dia] ?? [];
        $total_ev = count($tarefas_dia) + ($is_terca ? 1 : 0);
        $classes  = 'cal-cell' . ($is_hoje ? ' hoje' : '') . ($is_terca ? ' terca-cell' : '');
      ?>
      <div class="<?= $classes ?>"
           onclick="abrirDia(<?= $dia ?>, <?= (int)$is_terca ?>, <?= htmlspecialchars(json_encode($tarefas_dia), ENT_QUOTES) ?>)">

        <!-- Número do dia -->
        <span class="text-xs font-bold block mb-1 <?= $is_hoje ? 'text-primary' : ($is_terca ? 'text-emerald-700' : 'text-on-surface') ?>">
          <?= $dia ?>
        </span>

        <!-- Reunião (terça) -->
        <?php if ($is_terca): ?>
        <div class="tag-reuniao">
          <span class="material-symbols-outlined" style="font-size:9px;font-variation-settings:'FILL' 1;">groups</span>
          08h Reunião
        </div>
        <?php endif; ?>

        <!-- Tarefas (máx 2 visíveis) -->
        <?php foreach (array_slice($tarefas_dia, 0, $is_terca ? 1 : 2) as $ev):
          $cor = $prio_cor[$ev['prioridade']] ?? '#6b7280';
        ?>
        <div class="tag-tarefa" style="background:<?= $cor ?>;" title="<?= htmlspecialchars($ev['titulo']) ?>">
          <?= htmlspecialchars(mb_substr($ev['titulo'], 0, 14)) ?>
        </div>
        <?php endforeach; ?>

        <!-- "+N mais" -->
        <?php $ocultos = $total_ev - ($is_terca ? 2 : 2); if ($ocultos > 0): ?>
        <div style="font-size:9px;color:#a1a1aa;font-weight:700;">+<?= $ocultos ?> mais</div>
        <?php endif; ?>
      </div>
      <?php endfor; ?>
    </div>
  </div>

  <!-- Lista tarefas do mês -->
  <?php if (!empty($tarefas_raw)): ?>
  <div class="mt-6">
    <h3 class="font-headline font-bold text-lg mb-4">Prazos em <?= $meses_pt[$mes] ?></h3>
    <div class="space-y-2">
      <?php foreach ($tarefas_raw as $t):
        $cor = $prio_cor[$t['prioridade']] ?? '#6b7280';
        $concluida = $t['status'] === 'done';
      ?>
      <div class="bg-white rounded-xl px-4 py-3 shadow-sm border border-zinc-100 flex items-center gap-3 hover:border-primary/20 transition-colors">
        <div class="dot" style="background:<?= $cor ?>;"></div>
        <span class="text-sm font-bold text-primary w-8 flex-shrink-0">
          <?= date('d', strtotime($t['prazo'])) ?>
        </span>
        <p class="flex-1 text-sm font-medium truncate <?= $concluida ? 'line-through text-secondary' : '' ?>">
          <?= htmlspecialchars($t['titulo']) ?>
        </p>
        <span class="text-xs text-secondary hidden sm:block"><?= htmlspecialchars($t['projeto'] ?? '') ?></span>
        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full"
              style="background:<?= $cor ?>18;color:<?= $cor ?>">
          <?= $t['prioridade'] ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>
</main>
</div>

<!-- Modal do dia -->
<div class="modal-backdrop" id="modal" onclick="fecharModal(event)">
  <div class="modal-box" onclick="event.stopPropagation()">

    <!-- Header -->
    <div style="padding:1.25rem 1.5rem 1rem;border-bottom:1px solid #f1f1f1;flex-shrink:0;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <p style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#71717a;margin:0 0 2px;">
          <?= $meses_pt[$mes] ?> <?= $ano ?>
        </p>
        <h2 style="font-size:22px;font-weight:800;margin:0;" id="m-titulo-dia"></h2>
      </div>
      <button onclick="fecharModal()" style="background:none;border:none;cursor:pointer;color:#a1a1aa;">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>

    <!-- Corpo -->
    <div style="flex:1;overflow-y:auto;padding:1.25rem 1.5rem;" id="m-corpo"></div>

  </div>
</div>

<script src="../assets/js/app.js"></script>
<script>
const modal   = document.getElementById('modal');
const mTitulo = document.getElementById('m-titulo-dia');
const mCorpo  = document.getElementById('m-corpo');
const diasSem = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
const mesPHP  = <?= $mes ?>; const anoPHP = <?= $ano ?>;

const prio_bg = {Alta:'#fef2f2',Média:'#fffbeb',Baixa:'#eff6ff'};
const prio_c  = {Alta:'#b91c1c',Média:'#b45309',Baixa:'#1d4ed8'};
const slbl    = {todo:'A Fazer',doing:'Em Andamento',review:'Revisão',done:'Concluído',backlog:'Backlog'};
const s_bg    = {todo:'#f4f4f5',doing:'#fffbeb',review:'#faf5ff',done:'#ecfdf5',backlog:'#f4f4f5'};
const s_c     = {todo:'#52525b',doing:'#b45309',review:'#7e22ce',done:'#065f46',backlog:'#71717a'};

function abrirDia(dia, temReuniao, tarefas) {
  const ts  = new Date(anoPHP, mesPHP - 1, dia);
  const dsw = ts.getDay();
  mTitulo.textContent = diasSem[dsw] + ', ' + String(dia).padStart(2,'0');

  let html = '';

  // Reunião fixa
  if (temReuniao) {
    html += `
    <div style="background:#ecfdf5;border-left:3px solid #10b981;border-radius:0 8px 8px 0;padding:12px 14px;margin-bottom:1rem;display:flex;align-items:center;gap:10px;">
      <span class="material-symbols-outlined" style="color:#10b981;font-size:20px;font-variation-settings:'FILL' 1">groups</span>
      <div>
        <p style="font-weight:700;font-size:14px;color:#065f46;margin:0;">Reunião de Atualização</p>
        <p style="font-size:12px;color:#059669;margin:0;">08:00 — Toda terça-feira · Recorrente</p>
      </div>
    </div>`;
  }

  // Tarefas do dia
  if (tarefas.length > 0) {
    html += `<p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#71717a;margin:0 0 10px;">Tarefas com prazo hoje (${tarefas.length})</p>`;
    tarefas.forEach(t => {
      const pb = prio_bg[t.prioridade] || '#f4f4f5';
      const pc = prio_c[t.prioridade]  || '#52525b';
      const sb = s_bg[t.status]  || '#f4f4f5';
      const sc = s_c[t.status]   || '#52525b';
      const sl = slbl[t.status]  || t.status;
      const concluida = t.status === 'done';
      html += `
      <a href="minhas-tarefas.php" class="tarefa-row" style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f1f1f1;text-decoration:none;color:inherit;">
        <div style="width:8px;height:8px;border-radius:50%;background:${pc};flex-shrink:0;"></div>
        <div style="flex:1;min-width:0;">
          <p style="font-size:13px;font-weight:600;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;${concluida?'text-decoration:line-through;color:#a1a1aa':''}">${t.titulo}</p>
          <p style="font-size:11px;color:#71717a;margin:0;">${t.projeto || ''}</p>
        </div>
        <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:2rem;background:${pb};color:${pc};flex-shrink:0;">${t.prioridade}</span>
        <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:2rem;background:${sb};color:${sc};flex-shrink:0;">${sl}</span>
      </a>`;
    });
  }

  if (!temReuniao && tarefas.length === 0) {
    html = `<div style="text-align:center;padding:2rem 0;color:#a1a1aa;">
      <span class="material-symbols-outlined" style="font-size:40px;display:block;margin-bottom:8px;">event_available</span>
      <p style="font-size:13px;">Nenhum evento neste dia.</p>
    </div>`;
  }

  mCorpo.innerHTML = html;
  modal.classList.add('open');
}

function fecharModal(e) {
  if (e && e.target !== modal) return;
  modal.classList.remove('open');
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') modal.classList.remove('open');
});
</script>
</body>
</html>
