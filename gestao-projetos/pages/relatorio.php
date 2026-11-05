<?php
require_once '../config/session.php';
exigirLogin();

$semana_atual = date('W');
$ano_atual    = date('Y');
$semana = (int)($_GET['semana'] ?? $semana_atual);
$ano    = (int)($_GET['ano']    ?? $ano_atual);

$totais          = [];
$projetos        = [];
$tarefas_recentes = [];
$tarefas_semana  = [];
$erro_db         = null;

try {
    $pdo = db();

    // Totais gerais — usa CASE para máxima compatibilidade
    $totais = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'Concluído'   THEN 1 ELSE 0 END) AS concluidos,
            SUM(CASE WHEN status = 'Em andamento' THEN 1 ELSE 0 END) AS em_andamento,
            SUM(CASE WHEN status = 'Planejado'   THEN 1 ELSE 0 END) AS planejados,
            SUM(CASE WHEN status = 'Revisão'     THEN 1 ELSE 0 END) AS revisao,
            SUM(CASE WHEN status = 'Pausado'     THEN 1 ELSE 0 END) AS pausados,
            ROUND(AVG(progresso))                                     AS progresso_medio
        FROM projetos
    ")->fetch(PDO::FETCH_ASSOC);

    // Projetos detalhados
    $projetos = $pdo->query("
        SELECT
            p.id,
            p.nome,
            p.cliente,
            p.prioridade,
            p.prazo,
            p.progresso,
            p.status+0                       AS status_idx,
            COUNT(t.id)                      AS total_tarefas,
            SUM(t.status = 'done')           AS tarefas_done,
            SUM(t.status = 'doing')          AS tarefas_doing,
            SUM(t.status IN ('todo','backlog')) AS tarefas_abertas
        FROM projetos p
        LEFT JOIN tarefas t ON t.projeto_id = p.id
        GROUP BY p.id, p.nome, p.cliente, p.prioridade, p.prazo, p.progresso, p.status
        ORDER BY p.status+0 ASC, p.progresso DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Tarefas recentes (últimos 7 dias)
    $tarefas_recentes = $pdo->query("
        SELECT
            t.titulo,
            t.status,
            p.nome    AS projeto,
            u.nome    AS responsavel
        FROM tarefas t
        LEFT JOIN projetos p ON p.id  = t.projeto_id
        LEFT JOIN usuarios u ON u.id  = t.responsavel_id
        WHERE t.atualizado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY t.atualizado_em DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Tarefas com prazo esta semana
    $tarefas_semana = $pdo->query("
        SELECT
            t.titulo,
            COALESCE(t.data_fim, t.prazo) AS prazo,
            t.status,
            p.nome AS projeto,
            u.nome AS responsavel
        FROM tarefas t
        LEFT JOIN projetos p ON p.id = t.projeto_id
        LEFT JOIN usuarios u ON u.id = t.responsavel_id
        WHERE COALESCE(t.data_fim, t.prazo) IS NOT NULL
          AND COALESCE(t.data_fim, t.prazo) BETWEEN
                DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
                AND
                DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 6 DAY)
          AND t.status != 'done'
        ORDER BY COALESCE(t.data_fim, t.prazo) ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $erro_db = $e->getMessage();
}

$status_label = [1=>'Em Análise', 2=>'Em Andamento', 3=>'Revisão', 4=>'Concluído', 5=>'Pausado'];
$status_cor   = [1=>'#6366f1', 2=>'#f59e0b', 3=>'#a855f7', 4=>'#10b981', 5=>'#f97316'];
$status_bg    = [1=>'#eef2ff', 2=>'#fffbeb', 3=>'#faf5ff', 4=>'#ecfdf5', 5=>'#fff7ed'];
$prio_cor     = ['Alta'=>'#ef4444', 'Média'=>'#f59e0b', 'Baixa'=>'#6b7280'];
$prio_bg      = ['Alta'=>'#fef2f2', 'Média'=>'#fffbeb', 'Baixa'=>'#f9fafb'];
$task_label   = ['done'=>'Concluída','doing'=>'Em andamento','review'=>'Revisão','todo'=>'A Fazer','backlog'=>'Backlog'];
$task_cor     = ['done'=>'#10b981','doing'=>'#f59e0b','review'=>'#a855f7','todo'=>'#6366f1','backlog'=>'#9ca3af'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Relatório Semanal | Exemplo DevSystems</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/app.css">
  <style>
    :root {
      --ink: #0f172a;
      --ink-muted: #64748b;
      --ink-faint: #cbd5e1;
      --paper: #ffffff;
      --paper-warm: #fafaf9;
      --accent: #0f172a;
      --rule: #e2e8f0;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--paper-warm);
      color: var(--ink);
    }

    /* ── Controles (não imprimem) ── */
    .page-controls {
      background: var(--ink);
      color: #fff;
      padding: 12px 32px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }
    .page-controls .logo {
      font-family: 'Playfair Display', serif;
      font-size: 15px; font-weight: 700; letter-spacing: .02em;
    }
    .btn-print {
      display: flex; align-items: center; gap: 6px;
      background: #fff; color: var(--ink);
      border: none; border-radius: 6px;
      padding: 8px 18px; font-size: 12px; font-weight: 600;
      cursor: pointer; font-family: 'DM Sans', sans-serif;
      transition: opacity .15s;
    }
    .btn-print:hover { opacity: .85; }
    .btn-back {
      color: #94a3b8; text-decoration: none; font-size: 13px;
      display: flex; align-items: center; gap: 4px;
    }
    .btn-back:hover { color: #fff; }

    /* ── Relatório ── */
    .report { max-width: 900px; margin: 0 auto; padding: 48px 32px; }

    .report-header {
      border-bottom: 3px solid var(--ink);
      padding-bottom: 24px; margin-bottom: 40px;
      display: flex; justify-content: space-between; align-items: flex-end;
    }
    .report-header .brand {
      font-family: 'Playfair Display', serif;
      font-size: 13px; font-weight: 600;
      text-transform: uppercase; letter-spacing: .12em; color: var(--ink-muted);
    }
    .report-header h1 {
      font-family: 'Playfair Display', serif;
      font-size: 42px; font-weight: 900; line-height: 1; color: var(--ink); margin-top: 6px;
    }
    .report-header .meta { text-align: right; }
    .report-header .meta .date { font-size: 13px; color: var(--ink-muted); font-weight: 400; }
    .report-header .meta .semana {
      font-family: 'Playfair Display', serif;
      font-size: 28px; font-weight: 700; color: var(--ink);
    }

    .section { margin-bottom: 44px; }
    .section-title {
      font-family: 'Playfair Display', serif;
      font-size: 11px; font-weight: 600;
      text-transform: uppercase; letter-spacing: .16em; color: var(--ink-muted);
      border-bottom: 1px solid var(--rule);
      padding-bottom: 8px; margin-bottom: 20px;
    }

    /* KPIs */
    .kpi-grid {
      display: grid; grid-template-columns: repeat(4, 1fr);
      gap: 1px; background: var(--rule);
      border: 1px solid var(--rule); border-radius: 10px; overflow: hidden;
    }
    .kpi { background: var(--paper); padding: 20px 18px; text-align: center; }
    .kpi .num {
      font-family: 'Playfair Display', serif;
      font-size: 36px; font-weight: 900; color: var(--ink); line-height: 1;
    }
    .kpi .lbl {
      font-size: 11px; font-weight: 500; color: var(--ink-muted);
      text-transform: uppercase; letter-spacing: .08em; margin-top: 4px;
    }

    /* Barra de status */
    .status-bar {
      height: 10px; border-radius: 99px; background: #f1f5f9;
      display: flex; overflow: hidden; gap: 2px;
    }
    .status-bar-seg { height: 100%; }
    .status-legend {
      display: flex; gap: 16px; flex-wrap: wrap; margin-top: 10px;
    }
    .status-legend-item {
      display: flex; align-items: center; gap: 5px;
      font-size: 11px; color: var(--ink-muted);
    }
    .status-legend-dot { width: 8px; height: 8px; border-radius: 50%; }

    /* Tabela */
    .proj-table { width: 100%; border-collapse: collapse; }
    .proj-table th {
      font-size: 10px; font-weight: 600;
      text-transform: uppercase; letter-spacing: .1em; color: var(--ink-muted);
      padding: 8px 10px; text-align: left; border-bottom: 1px solid var(--rule);
    }
    .proj-table td {
      padding: 12px 10px; border-bottom: 1px solid #f8fafc;
      font-size: 13px; vertical-align: middle;
    }
    .proj-table tr:last-child td { border-bottom: none; }
    .proj-table tr:hover td { background: #f8fafc; }

    .prog-bar {
      height: 5px; background: #f1f5f9; border-radius: 99px;
      width: 80px; display: inline-block; vertical-align: middle; overflow: hidden;
    }
    .prog-fill { height: 100%; border-radius: 99px; }

    .badge {
      display: inline-flex; align-items: center;
      font-size: 10px; font-weight: 700;
      padding: 2px 8px; border-radius: 99px; white-space: nowrap;
    }

    /* Tarefas */
    .task-list { display: flex; flex-direction: column; gap: 8px; }
    .task-item {
      display: flex; align-items: center; gap: 12px;
      padding: 10px 14px;
      background: var(--paper); border: 1px solid var(--rule); border-radius: 8px;
    }
    .task-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .task-titulo { font-size: 13px; font-weight: 500; flex: 1; }
    .task-proj { font-size: 11px; color: var(--ink-muted); }

    /* Alerta de erro */
    .db-error {
      background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;
      padding: 16px 20px; color: #991b1b; font-size: 13px;
      margin-bottom: 24px;
    }
    .db-error strong { display: block; margin-bottom: 4px; }
    .db-error code { font-size: 12px; opacity: .8; word-break: break-all; }

    /* Rodapé */
    .report-footer {
      margin-top: 48px; padding-top: 20px; border-top: 1px solid var(--rule);
      display: flex; justify-content: space-between; align-items: center;
      font-size: 11px; color: var(--ink-muted);
    }

    /* Modal de captura */
    .modal-overlay {
      display: none;
      position: fixed; inset: 0; z-index: 9999;
      background: rgba(0,0,0,.6);
      align-items: center; justify-content: center;
      padding: 24px;
    }
    .modal-box {
      background: #fff; border-radius: 12px;
      max-width: 960px; width: 100%; max-height: 90vh;
      display: flex; flex-direction: column; overflow: hidden;
    }
    .modal-header {
      padding: 16px 20px; border-bottom: 1px solid #e2e8f0;
      display: flex; align-items: center; justify-content: space-between;
    }
    .modal-header span { font-weight: 700; font-size: 14px; }
    .modal-body { overflow: auto; padding: 16px; text-align: center; }
    .modal-body img {
      max-width: 100%; border-radius: 6px;
      box-shadow: 0 2px 12px rgba(0,0,0,.1);
    }

    @media print {
      .page-controls { display: none !important; }
      .modal-overlay { display: none !important; }
      body { background: #fff; }
      .report { padding: 24px 20px; }
      .kpi { padding: 14px 12px; }
      .kpi .num { font-size: 28px; }
      .task-item, tr, .section { break-inside: avoid; }
      .db-error { display: none; }
    }
  </style>
</head>
<body>

<div class="page-controls">
  <div style="display:flex;align-items:center;gap:20px;">
    <a href="kanban.php" class="btn-back">
      <span class="material-symbols-outlined" style="font-size:16px;">arrow_back</span> Voltar
    </a>
    <span class="logo">Exemplo DevSystems</span>
  </div>
  <div style="display:flex;align-items:center;gap:12px;">
    <span style="font-size:12px;color:#94a3b8;">Semana <?= $semana_atual ?> · <?= date('d/m/Y') ?></span>
    <button class="btn-print" onclick="capturarImagem(this)" style="background:#10b981;color:#fff;">
      <span class="material-symbols-outlined" style="font-size:15px;">photo_camera</span>
      Capturar Imagem
    </button>
    <button class="btn-print" onclick="window.print()">
      <span class="material-symbols-outlined" style="font-size:15px;">print</span>
      Imprimir / PDF
    </button>
  </div>
</div>

<div class="report">

  <!-- Cabeçalho -->
  <div class="report-header">
    <div>
      <div class="brand">Exemplo Gestão de Projetos</div>
      <h1>Relatório<br>Semanal</h1>
    </div>
    <div class="meta">
      <div class="date">Gerado em <?= date('d \d\e F \d\e Y') ?></div>
      <div class="semana">Semana <?= $semana_atual ?></div>
      <div class="date" style="margin-top:4px;">
        <?= date('d/m') ?> — <?= date('d/m', strtotime('+6 days')) ?>
      </div>
    </div>
  </div>

  <!-- Erro de banco (só aparece na tela, não imprime) -->
  <?php if ($erro_db): ?>
  <div class="db-error">
    <strong>⚠️ Erro ao carregar dados do banco:</strong>
    <code><?= htmlspecialchars($erro_db) ?></code>
  </div>
  <?php endif; ?>

  <!-- ====== INÍCIO DA ÁREA DE CAPTURA ====== -->
  <div id="capture-area">

  <!-- KPIs -->
  <div class="section">
    <div class="section-title">Visão Geral</div>
    <div class="kpi-grid">
      <div class="kpi">
        <div class="num"><?= (int)($totais['total'] ?? 0) ?></div>
        <div class="lbl">Projetos</div>
      </div>
      <div class="kpi">
        <div class="num" style="color:#10b981"><?= (int)($totais['concluidos'] ?? 0) ?></div>
        <div class="lbl">Concluídos</div>
      </div>
      <div class="kpi">
        <div class="num" style="color:#f59e0b"><?= (int)($totais['em_andamento'] ?? 0) ?></div>
        <div class="lbl">Em Andamento</div>
      </div>
      <div class="kpi">
        <div class="num" style="color:#6366f1"><?= (int)($totais['progresso_medio'] ?? 0) ?>%</div>
        <div class="lbl">Progresso Médio</div>
      </div>
    </div>

    <!-- Barra de distribuição -->
    <?php
    $total_proj = max(1, (int)($totais['total'] ?? 1));
    $segs = [
        ['n' => (int)($totais['concluidos']   ?? 0), 'c' => '#10b981', 'l' => 'Concluído'],
        ['n' => (int)($totais['em_andamento'] ?? 0), 'c' => '#f59e0b', 'l' => 'Em Andamento'],
        ['n' => (int)($totais['revisao']      ?? 0), 'c' => '#a855f7', 'l' => 'Revisão'],
        ['n' => (int)($totais['planejados']   ?? 0), 'c' => '#6366f1', 'l' => 'Em Análise'],
        ['n' => (int)($totais['pausados']     ?? 0), 'c' => '#f97316', 'l' => 'Pausado'],
    ];
    ?>
    <div style="margin-top:20px;">
      <div class="status-bar">
        <?php foreach ($segs as $s): if ($s['n'] <= 0) continue; ?>
        <div class="status-bar-seg"
             style="width:<?= round($s['n'] / $total_proj * 100) ?>%;background:<?= $s['c'] ?>;"></div>
        <?php endforeach; ?>
      </div>
      <div class="status-legend">
        <?php foreach ($segs as $s): if ($s['n'] <= 0) continue; ?>
        <div class="status-legend-item">
          <div class="status-legend-dot" style="background:<?= $s['c'] ?>"></div>
          <?= $s['l'] ?> (<?= $s['n'] ?>)
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Projetos detalhados -->
  <?php if (!empty($projetos)): ?>
  <div class="section">
    <div class="section-title">Status dos Projetos</div>
    <table class="proj-table">
      <thead>
        <tr>
          <th>Projeto</th>
          <th>Status</th>
          <th>Prioridade</th>
          <th>Progresso</th>
          <th>Tarefas</th>
          <th>Prazo</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($projetos as $p):
          $si    = (int)$p['status_idx'];
          $sc    = $status_cor[$si]  ?? '#6b7280';
          $sb    = $status_bg[$si]   ?? '#f9fafb';
          $sl    = $status_label[$si]?? '—';
          $pc    = $prio_cor[$p['prioridade']] ?? '#6b7280';
          $pb    = $prio_bg[$p['prioridade']]  ?? '#f9fafb';
          $prog  = (int)$p['progresso'];
          $prog_c = $prog >= 80 ? '#10b981' : ($prog >= 40 ? '#f59e0b' : '#6366f1');
        ?>
        <tr>
          <td>
            <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($p['nome']) ?></div>
            <?php if (!empty($p['cliente'])): ?>
            <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($p['cliente']) ?></div>
            <?php endif; ?>
          </td>
          <td><span class="badge" style="background:<?= $sb ?>;color:<?= $sc ?>;"><?= $sl ?></span></td>
          <td><span class="badge" style="background:<?= $pb ?>;color:<?= $pc ?>;"><?= htmlspecialchars($p['prioridade']) ?></span></td>
          <td>
            <div style="display:flex;align-items:center;gap:6px;">
              <div class="prog-bar">
                <div class="prog-fill" style="width:<?= $prog ?>%;background:<?= $prog_c ?>;"></div>
              </div>
              <span style="font-size:11px;font-weight:700;color:<?= $prog_c ?>"><?= $prog ?>%</span>
            </div>
          </td>
          <td style="font-size:12px;color:#64748b;">
            <?php if ((int)$p['total_tarefas'] > 0): ?>
              <span style="color:#10b981;font-weight:600;"><?= (int)$p['tarefas_done'] ?></span>
              / <?= (int)$p['total_tarefas'] ?>
              <?php if ((int)$p['tarefas_doing'] > 0): ?>
              · <span style="color:#f59e0b;"><?= (int)$p['tarefas_doing'] ?> em andamento</span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:#cbd5e1;">—</span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:#64748b;">
            <?= !empty($p['prazo']) ? date('d/m/Y', strtotime($p['prazo'])) : '—' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  </div>
  <!-- ====== FIM DA ÁREA DE CAPTURA ====== -->

  <!-- Prazos desta semana -->
  <?php if (!empty($tarefas_semana)): ?>
  <div class="section">
    <div class="section-title">
      Tarefas com prazo esta semana (<?= count($tarefas_semana) ?>)
    </div>
    <div class="task-list">
      <?php foreach ($tarefas_semana as $t):
        $tc = $task_cor[$t['status']] ?? '#9ca3af';
      ?>
      <div class="task-item">
        <div class="task-dot" style="background:<?= $tc ?>"></div>
        <div style="flex:1;min-width:0;">
          <div class="task-titulo"><?= htmlspecialchars($t['titulo']) ?></div>
          <div class="task-proj">📁 <?= htmlspecialchars($t['projeto'] ?? '—') ?></div>
        </div>
        <?php if (!empty($t['responsavel'])): ?>
        <div style="font-size:11px;color:#64748b;flex-shrink:0;">👤 <?= htmlspecialchars($t['responsavel']) ?></div>
        <?php endif; ?>
        <div style="font-size:11px;color:#64748b;flex-shrink:0;">
          📅 <?= date('d/m', strtotime($t['prazo'])) ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Atividade recente -->
  <?php if (!empty($tarefas_recentes)): ?>
  <div class="section">
    <div class="section-title">Atividade nos Últimos 7 Dias</div>
    <div class="task-list">
      <?php foreach ($tarefas_recentes as $t):
        $tc = $task_cor[$t['status']] ?? '#9ca3af';
        $tl = $task_label[$t['status']] ?? $t['status'];
      ?>
      <div class="task-item">
        <div class="task-dot" style="background:<?= $tc ?>"></div>
        <div style="flex:1;min-width:0;">
          <div class="task-titulo"><?= htmlspecialchars($t['titulo']) ?></div>
          <div class="task-proj">📁 <?= htmlspecialchars($t['projeto'] ?? '—') ?></div>
        </div>
        <?php if (!empty($t['responsavel'])): ?>
        <div style="font-size:11px;color:#64748b;flex-shrink:0;">👤 <?= htmlspecialchars($t['responsavel']) ?></div>
        <?php endif; ?>
        <span class="badge" style="background:<?= $tc ?>18;color:<?= $tc ?>;flex-shrink:0;"><?= $tl ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Estado vazio (sem erro, mas sem dados) -->
  <?php if (!$erro_db && empty($projetos)): ?>
  <div style="text-align:center;padding:48px 0;color:var(--ink-muted);">
    <div style="font-size:48px;margin-bottom:12px;">📋</div>
    <div style="font-size:15px;font-weight:600;">Nenhum projeto encontrado</div>
    <div style="font-size:13px;margin-top:4px;">Crie projetos no Kanban para visualizá-los aqui.</div>
  </div>
  <?php endif; ?>

  <!-- Rodapé -->
  <div class="report-footer">
    <div>Exemplo Gestão de Projetos · Relatório Semanal <?= $semana_atual ?>/<?= $ano_atual ?></div>
    <div>Gerado em <?= date('d/m/Y H:i') ?></div>
  </div>

</div>

<!-- Modal de captura -->
<div id="modal-capture" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-header">
      <span>Preview da Captura</span>
      <div style="display:flex;gap:8px;">
        <button onclick="copiarImagem()" class="btn-print" style="background:#6366f1;color:#fff;font-size:11px;padding:6px 14px;">
          <span class="material-symbols-outlined" style="font-size:14px;">content_copy</span> Copiar
        </button>
        <button onclick="baixarImagem()" class="btn-print" style="font-size:11px;padding:6px 14px;">
          <span class="material-symbols-outlined" style="font-size:14px;">download</span> Baixar PNG
        </button>
        <button onclick="fecharModal()" class="btn-print" style="font-size:11px;padding:6px 14px;background:#f1f5f9;color:#64748b;">
          ✕ Fechar
        </button>
      </div>
    </div>
    <div class="modal-body">
      <img id="capture-preview" src="" alt="Preview da captura">
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
let capturedBlob = null;

function capturarImagem(btn) {
  const area = document.getElementById('capture-area');
  const textoOriginal = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:15px;">hourglass_top</span> Gerando...';

  html2canvas(area, {
    scale: 2,
    useCORS: true,
    backgroundColor: '#ffffff',
    logging: false
  }).then(function(canvas) {
    canvas.toBlob(function(blob) {
      capturedBlob = blob;
      var url = URL.createObjectURL(blob);
      document.getElementById('capture-preview').src = url;
      document.getElementById('modal-capture').style.display = 'flex';
      btn.disabled = false;
      btn.innerHTML = textoOriginal;
    }, 'image/png');
  }).catch(function() {
    alert('Erro ao gerar a imagem. Tente novamente.');
    btn.disabled = false;
    btn.innerHTML = textoOriginal;
  });
}

function baixarImagem() {
  if (!capturedBlob) return;
  var a = document.createElement('a');
  a.href = URL.createObjectURL(capturedBlob);
  a.download = 'relatorio-semana-<?= $semana_atual ?>-<?= $ano_atual ?>.png';
  a.click();
}

async function copiarImagem() {
  if (!capturedBlob) return;
  try {
    await navigator.clipboard.write([
      new ClipboardItem({ 'image/png': capturedBlob })
    ]);
    alert('Imagem copiada! Cole no WhatsApp, e-mail ou onde preferir.');
  } catch (e) {
    alert('Seu navegador não suporta copiar imagem. Use o botão Baixar.');
  }
}

function fecharModal() {
  document.getElementById('modal-capture').style.display = 'none';
}

document.getElementById('modal-capture').addEventListener('click', function(e) {
  if (e.target === this) fecharModal();
});
</script>

</body>
</html>
