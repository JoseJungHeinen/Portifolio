<?php
// @versao Log_01 V_05_10/08/2026
/**
 * views/auth/login.php
 * Tela de login nativa — standalone (sem sidebar/topbar).
 *
 * Variáveis esperadas:
 *   $erro   string|null  Mensagem de erro (credenciais inválidas, bloqueio)
 *   $aviso  string|null  Mensagem informativa (motivo do redirecionamento)
 *   $next   string       URL de destino após login (já validada)
 */

$erro  = $erro  ?? null;
$aviso = $aviso ?? null;
$next  = $next  ?? '';

$cssVer = filemtime(ROOT . '/public/assets/css/modules/login.css');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Entrar — Notas</title>

  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/design-system.css?v=<?= filemtime(ROOT . '/public/assets/css/design-system.css') ?>">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/modules/login.css?v=<?= $cssVer ?>">

  <script src="<?= BASE_URL ?>/public/assets/js/vendor/lucide.min.js" defer></script>
</head>
<body class="login-page">

  <div class="login-card">

    <div class="login-logo">
      <img class="login-logo__symbol" src="<?= BASE_URL ?>/public/assets/img/LOGO%20EXEMPLO_ARREDONDADO.png?v=<?= filemtime(ROOT . '/public/assets/img/logo.svg') ?>" alt="Exemplo">
      <div class="login-logo__text">
        <span class="login-logo__brand">Notas</span>
        <span class="login-logo__vertical">Empresa Exemplo</span>
      </div>
    </div>

    <div class="login-rule"></div>

    <h1 class="login-title">Sistema Inteligente de <span style="color:var(--color-primary);">Gestão Orçamentária</span></h1>
    <p class="login-subtitle">Entre com seu e-mail e senha para continuar.</p>

    <?php if ($aviso): ?>
    <div class="alert alert-info" role="status">
      <span class="alert-icon"><i data-lucide="info" width="18" height="18"></i></span>
      <div class="alert-content"><div><?= h($aviso) ?></div></div>
    </div>
    <?php endif; ?>

    <?php if ($erro): ?>
    <div class="alert alert-danger" role="alert">
      <span class="alert-icon"><i data-lucide="x-circle" width="18" height="18"></i></span>
      <div class="alert-content"><div><?= h($erro) ?></div></div>
    </div>
    <?php endif; ?>

    <form method="post" action="?module=auth&action=login" autocomplete="on">
      <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
      <input type="hidden" name="next" value="<?= h($next) ?>">

      <div class="form-group">
        <label class="form-label" for="login-email">E-mail</label>
        <input type="email" id="login-email" name="email" class="form-input"
               required autofocus>
      </div>

      <div class="form-group">
        <label class="form-label" for="login-senha">Senha</label>
        <input type="password" id="login-senha" name="senha" class="form-input"
               required>
      </div>

      <button type="submit" class="btn btn-primary login-submit">
        <i data-lucide="log-in" width="16" height="16"></i>
        Entrar
      </button>
    </form>

    <footer class="login-footer">
      Desenvolvido <strong>Empresa Exemplo</strong> &middot; <?= date('Y') ?><br>
      <a href="tel:+555137140800">51 3714 0800</a>
    </footer>

  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (typeof lucide !== 'undefined') lucide.createIcons();
    });
  </script>
</body>
</html>
