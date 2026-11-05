<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Entrar') ?> — Exemplo OFP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= asset('css/app.css') ?>" rel="stylesheet">
</head>
<body class="auth">
<main class="auth__card">
    <div class="auth__logo"><span class="sidebar__b">B</span><span><strong>Exemplo</strong><small>ofp</small></span></div>
    <?php if ($m = flash('error')): ?><div class="alert alert-danger py-2"><?= e($m) ?></div><?php endif; ?>
    <?= $content ?>
</main>
</body>
</html>
