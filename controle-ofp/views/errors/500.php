<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><title>Erro — Exemplo OFP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="text-center" style="max-width:560px"><h1 class="display-5 fw-bold">Erro inesperado</h1>
<p class="text-muted">Tente novamente. Se persistir, avise o suporte (o erro foi registrado em <code>storage/logs/error.log</code>).</p>
<?php if (!empty($mensagem)): ?><pre class="text-start bg-dark text-light p-3 rounded small"><?= e($mensagem) ?></pre><?php endif; ?>
<a class="btn btn-danger" href="<?= url('/') ?>">Voltar ao início</a></div></body></html>
