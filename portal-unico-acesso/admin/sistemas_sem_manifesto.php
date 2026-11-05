<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';

if (empty($_SESSION['portal_logado']) || empty($_SESSION['portal_is_admin'])) {
    header('Location: ../login.php');
    exit;
}

$sistemas = carregar_todos_sistemas();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sistemas configurados — Portal Único</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/design-system.css">
<style>
.page-title{font-family:var(--font-bold);font-size:var(--text-h1);color:var(--text-primary);margin-bottom:var(--space-4)}
.card-sub{font-family:var(--font-regular);font-size:var(--text-body-sm);color:var(--text-muted);margin-bottom:var(--space-5)}
ul{list-style:none}
li{padding:var(--space-3) var(--space-4);border-bottom:1px solid var(--color-gray-light);font-family:var(--font-regular);font-size:var(--text-body);display:flex;align-items:center;gap:var(--space-3)}
li:last-child{border-bottom:none}
.dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.caminho{color:var(--text-muted);font-size:var(--text-caption)}
</style>
</head>
<body>
<nav class="topbar">
    <div class="topbar__brand">
        <img src="../assets/img/logo.svg" alt="Portal Único" style="height:36px;border-radius:8px">
        <div class="topbar__title">Sistemas configurados<span>config/sistemas/*.json</span></div>
    </div>
    <div class="topbar__nav">
        <a href="../index.php">Sistemas</a>
        <a href="usuarios.php">Administração</a>
        <a href="sistemas_sem_manifesto.php" class="active">Sistemas configurados</a>
    </div>
</nav>
<div class="main-content">
    <h1 class="page-title">Sistemas carregados neste servidor</h1>
    <p class="card-sub">
        Lê os arquivos em <code>config/sistemas/*.json</code> deste servidor — não depende mais de outras pastas
        estarem na mesma máquina. Pra integrar um sistema novo (ou atualizar um existente), copie/edite o
        <code>.json</code> correspondente nessa pasta.
    </p>
    <div class="card">
        <?php if (!$sistemas): ?>
        <p>Nenhum sistema configurado — a pasta <code>config/sistemas/</code> está vazia ou não existe.</p>
        <?php else: ?>
        <ul>
            <?php foreach ($sistemas as $id => $cfg): ?>
            <li>
                <span class="dot" style="background:<?= htmlspecialchars($cfg['cor'] ?? '#888') ?>"></span>
                <strong><?= htmlspecialchars($cfg['nome'] ?? $id) ?></strong>
                <span class="caminho">config/sistemas/<?= htmlspecialchars($id) ?>.json — banco <?= htmlspecialchars($cfg['db']['name'] ?? '?') ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
