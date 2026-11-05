<?php
// @versao Ver.001.281026
?>
<?php $voltar = sistema() === 'requisicoes' ? 'Voltar ao início' : 'Voltar ao Dashboard'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><title>404 — <?= e($_ENV['APP_NAME'] ?? 'Exemplo Compras') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="text-center">
    <div class="display-1 fw-bold text-muted">404</div>
    <h4 class="mt-2 mb-3">Página não encontrada</h4>
    <a href="<?= url('dashboard') ?>" class="btn btn-primary"><?= $voltar ?></a>
</div>
</body></html>
