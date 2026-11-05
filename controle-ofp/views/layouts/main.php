<?php
$usuario = auth();
$perfil = $usuario['perfil'] ?? '';
$rota = rota_atual();
$menu = [
    ['Painel', '/painel', 'bi-speedometer2', []],
    ['Minhas pendências', '/pendencias', 'bi-list-check', ['estoque', 'comprador', 'gerente', 'admin']],
    ['OFPs', '/ofps', 'bi-box-seam', []],
    ['Nova OFP', '/ofps/nova', 'bi-plus-circle', ['estoque', 'comprador', 'gerente', 'admin']],
    ['Financeiro', '/financeiro', 'bi-cash-coin', ['financeiro', 'gerente', 'diretor', 'admin']],
    ['Perdas sugeridas', '/perdas', 'bi-exclamation-triangle', ['gerente', 'admin']],
    ['Causais', '/cadastros/causais', 'bi-journal-text', ['admin']],
    ['Motivos', '/cadastros/motivos', 'bi-tags', ['admin']],
    ['Unidades', '/cadastros/unidades', 'bi-building', ['admin']],
    ['Usuários', '/usuarios', 'bi-people', ['admin']],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'OFP') ?> — Exemplo OFP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= asset('css/app.css') ?>" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
</head>
<body>
<aside class="sidebar">
    <a class="sidebar__logo" href="<?= url('/') ?>"><span class="sidebar__b">B</span><span><strong>Exemplo</strong><small>ofp</small></span></a>
    <nav class="sidebar__nav">
        <?php foreach ($menu as [$rotulo, $href, $icone, $perfis]): ?>
            <?php if ($perfis && !in_array($perfil, $perfis, true)) continue; ?>
            <?php $ativo = $rota === $href || ($href !== '/ofps' && str_starts_with($rota, $href . '/')); ?>
            <a href="<?= url($href) ?>" class="<?= $ativo ? 'ativo' : '' ?>"><i class="bi <?= $icone ?>"></i> <?= e($rotulo) ?></a>
        <?php endforeach; ?>
    </nav>
</aside>
<header class="topbar">
    <span class="small text-muted"><?= e($usuario['nome'] ?? '') ?> · <?= e(\App\Models\Usuario::PERFIS[$perfil] ?? $perfil) ?></span>
    <form method="post" action="<?= url('/logout') ?>" class="m-0"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-right"></i> Sair</button></form>
</header>
<main class="conteudo">
    <?php foreach (['success' => 'success', 'error' => 'danger', 'warning' => 'warning'] as $chave => $cor): ?>
        <?php if ($mensagem = flash($chave)): ?>
            <div class="alert alert-<?= $cor ?> alert-dismissible fade show" role="alert"><?= e($mensagem) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button></div>
        <?php endif; ?>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
