<?php
// @versao Sys_02 V_01_16/07/2026
/**
 * views/layout/base.php
 * Template base HTML — inclui sidebar, topbar e abre o main-content
 *
 * Como usar em uma view:
 *
 *   <?php
 *   $pageTitle    = 'Processos';
 *   $activeModule = 'processes';
 *   $currentUser  = ['nome' => 'João Silva', 'perfil' => 'gestor'];
 *   ob_start();
 *   ?>
 *     ... conteúdo da view ...
 *   <?php
 *   $content = ob_get_clean();
 *   include __DIR__ . '/../layout/base.php';
 *   ?>
 *
 * Variáveis disponíveis para o template:
 *   $pageTitle    string   Título exibido na topbar e no <title>
 *   $activeModule string   Módulo ativo (para highlight da sidebar)
 *   $currentUser  array    ['nome' => '...', 'perfil' => '...']
 *   $unreadCount  int      Notificações não lidas (padrão 0)
 *   $content      string   HTML do corpo da view (via ob_get_clean)
 *   $extraHead    string   HTML adicional para o <head> (scripts, meta) — opcional
 *   $embed        bool     Modo embutido (iframe dentro de modal) — oculta a sidebar — opcional
 */

$pageTitle    = $pageTitle    ?? 'Exemplo NF';
$activeModule = $activeModule ?? 'dashboard';
$currentUser  = $currentUser  ?? ['nome' => 'Usuário', 'perfil' => 'usuario'];
$content      = $content      ?? '';
$extraHead    = $extraHead    ?? '';
$embed        = $embed        ?? false;

// Auto-fetch de notificações para o dropdown da topbar (dispensável em modo embutido, sem topbar)
$recentNotifications = [];
$unreadCount         = 0;
if (!$embed && !empty($currentUser['id'])) {
    require_once SRC . '/Models/Notification.php';
    $unreadCount         = Notification::unreadCount($currentUser['id']);
    $recentNotifications = Notification::listRecent($currentUser['id'], 8);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= htmlspecialchars($pageTitle) ?> — Exemplo financeiro</title>

  <!-- Design System Exemplo -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/design-system.css?v=<?= filemtime(ROOT . '/public/assets/css/design-system.css') ?>">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/layout.css?v=<?= filemtime(ROOT . '/public/assets/css/layout.css') ?>">

  <!-- Módulos CSS específicos (carregados pelas views quando necessário) -->
  <?= $extraHead ?>

  <!-- Lucide Icons — cópia local para evitar dependência de CDN -->
  <script src="<?= BASE_URL ?>/public/assets/js/vendor/lucide.min.js" defer></script>
</head>
<body<?= $embed ? ' class="layout-embed"' : '' ?>>

  <?php if (!$embed): ?>
  <?php include __DIR__ . '/sidebar.php'; ?>
  <?php include __DIR__ . '/topbar.php'; ?>
  <?php endif; ?>

  <main class="main-content<?= !empty($layoutFull) ? ' main-content--full' : '' ?>" id="main-content" role="main">
    <?= $content ?>
  </main>

  <!-- App JS -->
  <script src="<?= BASE_URL ?>/public/assets/js/app.js?v=<?= filemtime(ROOT . '/public/assets/js/app.js') ?>"></script>

  <!-- Inicializa Lucide após carregamento -->
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (typeof lucide !== 'undefined') lucide.createIcons();
    });
  </script>

  <!-- Alert dismiss & auto-dismiss -->
  <script>
  (function(){
    document.querySelectorAll('.alert-dismiss').forEach(function(btn){
      btn.addEventListener('click',function(){ this.closest('.alert').remove(); });
    });
    document.querySelectorAll('[data-auto-dismiss]').forEach(function(el){
      setTimeout(function(){ if(el.parentNode) el.parentNode.removeChild(el); }, parseInt(el.dataset.autoDismiss));
    });
  })();
  </script>

</body>
</html>
