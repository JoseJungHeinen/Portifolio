<?php
require_once '../config/session.php';
exigirLogin();

try {
    $pdo = db();

    $backlog = $pdo->query("
        SELECT t.*, COALESCE(t.data_fim, t.prazo) AS data_fim_eff, p.nome as projeto
        FROM tarefas t
        LEFT JOIN projetos p ON p.id = t.projeto_id
        WHERE t.status = 'backlog'
        ORDER BY FIELD(t.prioridade,'Alta','Média','Baixa'), COALESCE(t.data_fim, t.prazo) ASC
    ")->fetchAll();

    $em_andamento = $pdo->query("
        SELECT t.*, COALESCE(t.data_fim, t.prazo) AS data_fim_eff, p.nome as projeto, u.nome as responsavel
        FROM tarefas t
        LEFT JOIN projetos p ON p.id = t.projeto_id
        LEFT JOIN usuarios u ON u.id = t.responsavel_id
        WHERE t.status = 'doing'
        ORDER BY FIELD(t.prioridade,'Alta','Média','Baixa'), COALESCE(t.data_fim, t.prazo) ASC
    ")->fetchAll();

    $total_projetos = $pdo->query("SELECT COUNT(*) FROM projetos")->fetchColumn();
    $total_tarefas  = $pdo->query("SELECT COUNT(*) FROM tarefas")->fetchColumn();
    $concluidas     = $pdo->query("SELECT COUNT(*) FROM tarefas WHERE status='done'")->fetchColumn();

} catch (PDOException $e) {
    $backlog = $em_andamento = [];
    $total_projetos = $total_tarefas = $concluidas = 0;
}

$prio_cls = [
    'Alta'  => 'text-red-600 bg-red-50',
    'Média' => 'text-amber-600 bg-amber-50',
    'Baixa' => 'text-zinc-500 bg-zinc-100',
];
?>
<!DOCTYPE html>
<html class="light" lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Backlog & Sprints | Exemplo DevSystems</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Epilogue:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body class="bg-surface text-on-surface font-body antialiased">

<?php include '../config/topnav.php'; ?>
<?php include '../config/notificacoes.php'; ?>

<div class="app-shell">
<?php include '../config/sidebar.php'; ?>

<main class="app-main p-8 page-fade-in">
<div class="max-w-6xl mx-auto">

  <!-- Cabeçalho -->
  <div class="mb-8">
    <div class="flex items-center gap-2 mb-2">
      <span class="w-8 h-[2px] bg-primary inline-block"></span>
      <span class="text-[10px] font-label font-bold uppercase tracking-widest text-secondary">Planejamento</span>
    </div>
    <h1 class="font-headline text-4xl font-extrabold tracking-tight">Backlog & Sprints</h1>
  </div>

  <!-- Métricas rápidas -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
    <?php
    $metricas = [
        ['Projetos',       $total_projetos,          'folder_open',  ''],
        ['Em Andamento',   count($em_andamento),     'pending_actions', 'text-amber-500'],
        ['Total Tarefas',  $total_tarefas,            'assignment',   ''],
        ['Concluídas',     $concluidas,              'task_alt',     'text-emerald-500'],
    ];
    foreach ($metricas as [$l, $v, $ic, $cor]):
    ?>
    <div class="bg-white rounded-xl p-5 shadow-sm border border-zinc-100 text-center">
      <span class="material-symbols-outlined text-2xl <?= $cor ?: 'text-primary' ?>"
            style="font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24"><?= $ic ?></span>
      <p class="font-headline text-3xl font-extrabold mt-2 <?= $cor ?>"><?= $v ?></p>
      <p class="text-xs text-secondary mt-1"><?= $l ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Em Andamento ── -->
  <section class="mb-10">
    <div class="flex items-center justify-between mb-4">
      <h2 class="font-headline text-lg font-bold flex items-center gap-2">
        <span class="w-2.5 h-2.5 rounded-full bg-amber-400 inline-block"></span>
        Em Andamento
        <span class="text-secondary text-sm font-body font-normal ml-1">
          <?= count($em_andamento) ?> <?= count($em_andamento) === 1 ? 'tarefa' : 'tarefas' ?>
        </span>
      </h2>
    </div>

    <?php if (empty($em_andamento)): ?>
    <div class="bg-white rounded-xl p-10 text-center border border-zinc-100">
      <span class="material-symbols-outlined text-4xl text-zinc-300 block mb-3">check_circle</span>
      <p class="text-secondary text-sm">Nenhuma tarefa em andamento no momento.</p>
    </div>
    <?php else: ?>
    <div class="space-y-3">
      <?php foreach ($em_andamento as $t):
        $prazo_str = '';
        $prazo_cor = 'text-secondary';
        if (!empty($t['data_fim_eff'])) {
            $dias = (int)((strtotime($t['data_fim_eff']) - time()) / 86400);
            if ($dias < 0)       { $prazo_str = '⚠️ Atrasada';   $prazo_cor = 'text-red-500 font-semibold'; }
            elseif ($dias === 0) { $prazo_str = '🔴 Vence hoje'; $prazo_cor = 'text-red-500 font-semibold'; }
            elseif ($dias <= 3)  { $prazo_str = '🟡 ' . date('d/m', strtotime($t['data_fim_eff'])); $prazo_cor = 'text-amber-600 font-medium'; }
            else                 { $prazo_str = '📅 ' . date('d/m', strtotime($t['data_fim_eff'])); }
        }
      ?>
      <div class="bg-white rounded-xl p-4 shadow-sm border border-amber-100 hover:border-amber-300 transition-colors flex items-center gap-4">

        <!-- Pontos / Story points -->
        <div class="w-8 h-8 bg-amber-50 rounded-lg flex items-center justify-center flex-shrink-0 font-headline font-bold text-xs text-amber-600">
          <?= $t['pontos'] ?: '—' ?>
        </div>

        <!-- Conteúdo -->
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-on-surface truncate"><?= htmlspecialchars($t['titulo']) ?></p>
          <div class="flex items-center gap-2 flex-wrap mt-0.5">
            <?php if (!empty($t['projeto'])): ?>
            <span class="text-xs text-secondary">📁 <?= htmlspecialchars($t['projeto']) ?></span>
            <?php endif; ?>
            <?php if (!empty($t['responsavel'])): ?>
            <span class="text-xs text-secondary">👤 <?= htmlspecialchars($t['responsavel']) ?></span>
            <?php endif; ?>
            <?php if ($prazo_str): ?>
            <span class="text-xs <?= $prazo_cor ?>"><?= $prazo_str ?></span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Prioridade -->
        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full flex-shrink-0 <?= $prio_cls[$t['prioridade']] ?? '' ?>">
          <?= htmlspecialchars($t['prioridade']) ?>
        </span>

        <!-- Status badge -->
        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full flex-shrink-0 bg-amber-50 text-amber-600">
          Em andamento
        </span>

        <!-- Link Kanban -->
        <a href="kanban.php" class="flex-shrink-0 p-1.5 text-zinc-400 hover:text-primary transition-colors" title="Ver no Kanban">
          <span class="material-symbols-outlined text-sm">open_in_new</span>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <!-- ── Backlog ── -->
  <section>
    <div class="flex items-center justify-between mb-4">
      <h2 class="font-headline text-lg font-bold flex items-center gap-2">
        <span class="w-2.5 h-2.5 rounded-full bg-zinc-300 inline-block"></span>
        Backlog
        <span class="text-secondary text-sm font-body font-normal ml-1">
          <?= count($backlog) ?> <?= count($backlog) === 1 ? 'tarefa' : 'tarefas' ?>
        </span>
      </h2>
    </div>

    <?php if (empty($backlog)): ?>
    <div class="bg-white rounded-xl p-10 text-center border border-zinc-100">
      <span class="material-symbols-outlined text-4xl text-zinc-300 block mb-3">inbox</span>
      <p class="text-secondary text-sm">Nenhuma tarefa no backlog.</p>
    </div>
    <?php else: ?>
    <div class="space-y-3">
      <?php foreach ($backlog as $t): ?>
      <div class="bg-white rounded-xl p-4 shadow-sm border border-zinc-100 hover:border-primary/20 transition-colors flex items-center gap-4">

        <!-- Pontos -->
        <div class="w-8 h-8 bg-surface-container rounded-lg flex items-center justify-center flex-shrink-0 font-headline font-bold text-xs text-secondary">
          <?= $t['pontos'] ?: '—' ?>
        </div>

        <!-- Conteúdo -->
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-on-surface truncate"><?= htmlspecialchars($t['titulo']) ?></p>
          <p class="text-xs text-secondary mt-0.5">
            <?= htmlspecialchars($t['projeto'] ?? '') ?>
            <?= !empty($t['data_fim_eff']) ? ' · 📅 ' . date('d/m/Y', strtotime($t['data_fim_eff'])) : '' ?>
          </p>
        </div>

        <!-- Prioridade -->
        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full flex-shrink-0 <?= $prio_cls[$t['prioridade']] ?? '' ?>">
          <?= htmlspecialchars($t['prioridade']) ?>
        </span>

        <!-- Link Kanban -->
        <a href="kanban.php" class="flex-shrink-0 p-1.5 text-zinc-400 hover:text-primary transition-colors" title="Ver no Kanban">
          <span class="material-symbols-outlined text-sm">open_in_new</span>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

</div>
</main>
</div>

<script src="../assets/js/app.js"></script>
</body>
</html>
