<?php
// @versao Ver.001.281026
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? ($_ENV['APP_NAME'] ?? 'Exemplo Compras')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= asset('css/app.css') ?>" rel="stylesheet">
    <style>
        /* ── Auth layout — Manual de Design Empresa Exemplo ── */
        body {
            background: var(--color-bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .auth-wrap { width: 100%; max-width: 420px; }

        .auth-card {
            background: var(--color-surface);
            border: none;
            border-radius: 12px;
            box-shadow: rgba(0,0,0,.10) 0 4px 20px;
            overflow: hidden;
        }

        /* Cabeçalho — fundo escuro com logo na área vermelha (§ 1 e § 4.2) */
        .auth-header {
            background: var(--color-dark);
            padding: 2rem 2rem 1.75rem;
            text-align: center;
        }
        .auth-logo {
            width: 52px; height: 52px;
            background: var(--color-primary);
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: .875rem;
        }
        .auth-logo i { color: #fff; font-size: 1.4rem; }

        .auth-header-title {
            font-family: var(--font-bold);
            font-size: var(--text-h4);
            color: #fff;
            margin: 0 0 .25rem;
            line-height: 1.3;
        }
        /* Assinatura bicolor: "Exemplo" branco + nome do sistema em vermelho (§ 3.3) */
        .auth-header-title span { color: var(--color-primary); }

        .auth-header-sub {
            color: rgba(255,255,255,.40);
            font-family: var(--font-regular);
            font-size: var(--text-caption);
            margin: 0;
            text-transform: uppercase;
            letter-spacing: .1em;
        }

        /* Corpo do formulário */
        .auth-body { padding: 2rem; }

        .form-label {
            font-family: var(--font-regular);
            font-size: var(--text-body-sm);
            color: var(--text-secondary);
            margin-bottom: 4px;
        }
        .form-control {
            height: 40px;
            background: var(--color-surface);
            border: 1px solid var(--color-gray-light);
            border-radius: 4px;
            font-family: var(--font-regular);
            font-size: var(--text-body);
            color: var(--text-primary);
            padding: 8px 12px;
            transition: border-color .15s, box-shadow .15s;
        }
        .form-control:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 .2rem rgba(35,71,200,.12);
            outline: none;
        }
        .form-control::placeholder { color: var(--color-gray-medium); }

        .input-group-text {
            background: var(--color-bg);
            border: 1px solid var(--color-gray-light);
            color: var(--text-muted);
            font-size: var(--text-body);
            border-radius: 0 4px 4px 0;
        }

        /* Botão primário (§ 5.1) */
        .btn-primary {
            background: var(--color-primary) !important;
            border: none !important;
            color: #fff !important;
            font-family: var(--font-bold) !important;
            font-size: var(--text-body) !important;
            border-radius: 4px !important;
            padding: 9px 20px !important;
            transition: background .15s !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .375rem;
        }
        .btn-primary:hover { background: var(--color-primary-hover) !important; }
        .btn-primary:focus { box-shadow: 0 0 0 .2rem rgba(35,71,200,.22) !important; }

        /* Botão de toggle de senha */
        .btn-light {
            background: var(--color-bg) !important;
            border: 1px solid var(--color-gray-light) !important;
            color: var(--text-muted) !important;
            border-radius: 0 4px 4px 0 !important;
        }
        .btn-light:hover { color: var(--text-primary) !important; }

        /* Alertas (§ 5.7) */
        .alert {
            border: none;
            border-left: 4px solid;
            border-radius: 8px;
            padding: 10px 14px;
            font-family: var(--font-regular);
            font-size: var(--text-body-sm);
            display: flex;
            align-items: center;
            gap: .5rem;
        }
        .alert-danger  { background: #FFE8EE; color: var(--color-danger);  border-left-color: var(--color-danger); }
        .alert-success { background: #D8F5E5; color: var(--color-success); border-left-color: var(--color-success); }
        .alert-warning { background: #FFF3E0; color: var(--color-warning); border-left-color: var(--color-warning); }

        /* Links */
        a {
            color: var(--text-muted);
            text-decoration: none;
            font-family: var(--font-regular);
            font-size: var(--text-body-sm);
            transition: color .15s;
        }
        a:hover { color: var(--color-primary); }

        /* Rodapé */
        .auth-footer {
            text-align: center;
            padding: 1rem 2rem;
            border-top: 1px solid var(--color-gray-light);
            background: var(--color-bg);
        }
        .auth-footer small {
            font-family: var(--font-regular);
            font-size: var(--text-caption);
            color: var(--text-muted);
        }
    </style>
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">

        <?php $marca = sistema_marca(); ?>
        <div class="auth-header">
            <div class="auth-logo">
                <i class="bi <?= $marca['icone'] ?>"></i>
            </div>
            <!-- Assinatura bicolor obrigatória (Manual de Design § 3.3) -->
            <div class="auth-header-title">Exemplo <span><?= e($marca['nome']) ?></span></div>
            <p class="auth-header-sub"><?= e($marca['sub']) ?></p>
        </div>

        <div class="auth-body">
            <?php if ($msg = flash('error')): ?>
                <div class="alert alert-danger mb-3">
                    <i class="bi bi-exclamation-circle-fill"></i><?= e($msg) ?>
                </div>
            <?php endif; ?>
            <?php if ($msg = flash('success')): ?>
                <div class="alert alert-success mb-3">
                    <i class="bi bi-check-circle-fill"></i><?= e($msg) ?>
                </div>
            <?php endif; ?>
            <?php if ($msg = flash('warning')): ?>
                <div class="alert alert-warning mb-3">
                    <i class="bi bi-exclamation-triangle-fill"></i><?= e($msg) ?>
                </div>
            <?php endif; ?>

            <?= $content ?>
        </div>

        <div class="auth-footer">
            <small>Exemplo Automotive &copy; <?= date('Y') ?> — Todos os direitos reservados</small>
        </div>

    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
