<?php
// @versao Sys_04 V_01_16/07/2026
/**
 * views/layout/topbar.php
 * Topbar com sino + dropdown inline de notificações.
 *
 * Variáveis injetadas por base.php:
 *   $pageTitle           string
 *   $currentUser         array   ['nome' => ..., 'id' => ...]
 *   $unreadCount         int
 *   $recentNotifications array   (Notification::listRecent)
 */

$pageTitle           = $pageTitle           ?? 'Exemplo NF';
$currentUser         = $currentUser         ?? ['nome' => 'Usuário', 'perfil' => 'usuario'];
$unreadCount         = $unreadCount         ?? 0;
$recentNotifications = $recentNotifications ?? [];

function topbarInitials(string $nome): string
{
    $parts = explode(' ', trim($nome));
    $ini   = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini ?: 'U';
}

function topbarTimeAgo(string $datetime): string
{
    $diff = max(0, time() - strtotime($datetime));
    if ($diff < 60)    return 'Agora mesmo';
    if ($diff < 3600)  return (int)($diff / 60) . ' min atrás';
    if ($diff < 86400) return (int)($diff / 3600) . 'h atrás';
    return (int)($diff / 86400) . 'd atrás';
}
?>
<header class="topbar" role="banner">

  <div class="topbar__left">
    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Recolher/expandir menu">
      <i data-lucide="menu" width="20" height="20"></i>
    </button>
    <h1 class="topbar__title"><?= htmlspecialchars($pageTitle) ?></h1>
  </div>

  <div class="topbar__right">

    <!-- ── Sino + Dropdown ──────────────────────────────────── -->
    <div class="notif-wrapper" id="notif-wrapper">

      <button class="topbar__notif" id="notif-btn"
              aria-label="Notificações (<?= $unreadCount ?> não lidas)"
              aria-haspopup="true" aria-expanded="false">
        <i data-lucide="bell" width="20" height="20"></i>
        <?php if ($unreadCount > 0): ?>
        <span class="topbar__notif-badge"><?= min($unreadCount, 99) ?></span>
        <?php endif; ?>
      </button>

      <div class="notif-dropdown" id="notif-dropdown" role="dialog" aria-label="Notificações">

        <!-- Cabeçalho -->
        <div class="nd-header">
          <span class="nd-header__title">
            Notificações
            <?php if ($unreadCount > 0): ?>
            <span class="nd-header__count"><?= $unreadCount ?></span>
            <?php endif; ?>
          </span>
          <?php if ($unreadCount > 0): ?>
          <form method="post" action="?module=notificacoes&action=marcar_todas" style="margin:0;padding:0;">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <button type="submit" class="nd-header__mark">Marcar todas como lidas</button>
          </form>
          <?php endif; ?>
        </div>

        <!-- Lista de notificações -->
        <div class="nd-list">

          <?php if (empty($recentNotifications)): ?>
          <div class="nd-empty">
            <i data-lucide="bell-off" width="28" height="28" style="color:#CCCCCC;"></i>
            <span>Nenhuma notificação</span>
          </div>

          <?php else: ?>
            <?php foreach ($recentNotifications as $notif):
              $meta   = Notification::meta($notif['tipo']);
              $url    = Notification::url($notif);
              $color  = htmlspecialchars($meta['color']);
              $icon   = htmlspecialchars($meta['icon']);
              $unread = !$notif['lida'];
              $formId = 'nf-form-' . substr(md5($notif['id']), 0, 8);
            ?>
            <?php /* Cada item é uma <div> clicável — submete o form oculto via JS */ ?>
            <div class="nd-item<?= $unread ? ' nd-item--unread' : '' ?>"
                 role="button" tabindex="0"
                 onclick="document.getElementById('<?= $formId ?>').submit()"
                 onkeydown="if(event.key==='Enter'||event.key===' ')document.getElementById('<?= $formId ?>').submit()">

              <div class="nd-strip nd-strip--<?= $color ?>"></div>

              <div class="nd-icon nd-icon--<?= $color ?>">
                <i data-lucide="<?= $icon ?>" width="16" height="16"></i>
              </div>

              <div class="nd-content">
                <span class="nd-title" title="<?= htmlspecialchars($notif['titulo']) ?>">
                  <?= htmlspecialchars($notif['titulo']) ?>
                </span>
                <span class="nd-time"><?= topbarTimeAgo($notif['criado_em']) ?></span>
              </div>

              <?php if ($unread): ?>
              <div class="nd-dot" aria-label="Não lida"></div>
              <?php endif; ?>

            </div>

            <?php /* Form oculto para marcar como lida + redirecionar */ ?>
            <form id="<?= $formId ?>" method="post"
                  action="?module=notificacoes&action=marcar"
                  style="display:none;" aria-hidden="true">
              <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
              <input type="hidden" name="id"       value="<?= htmlspecialchars($notif['id']) ?>">
              <input type="hidden" name="redirect" value="<?= htmlspecialchars($url) ?>">
            </form>

            <?php endforeach; ?>
          <?php endif; ?>

        </div><!-- /.nd-list -->

        <!-- Rodapé -->
        <div class="nd-footer">
          <a href="?module=notificacoes&action=index">
            Ver todas as notificações
            <i data-lucide="arrow-right" width="14" height="14"></i>
          </a>
        </div>

      </div><!-- /.notif-dropdown -->
    </div><!-- /.notif-wrapper -->

    <div class="topbar__separator" aria-hidden="true"></div>

    <!-- Usuário -->
    <a href="?module=account&action=profile" class="topbar__user" title="Meu perfil">
      <div class="topbar__user-avatar" aria-hidden="true">
        <?= topbarInitials($currentUser['nome']) ?>
      </div>
      <span class="topbar__user-name">
        <?= htmlspecialchars(explode(' ', $currentUser['nome'])[0]) ?>
      </span>
      <i data-lucide="chevron-down" width="16" height="16" style="color:#888888;"></i>
    </a>

    <div class="topbar__separator" aria-hidden="true"></div>

    <!-- Sair -->
    <a href="?module=auth&action=logout"
       class="btn btn-tertiary btn-sm"
       data-confirm="Deseja realmente sair do sistema?"
       title="Sair">
      <i data-lucide="log-out" width="16" height="16"></i>
    </a>

  </div><!-- /.topbar__right -->

</header>

<script>
(function () {
  'use strict';
  var btn = document.getElementById('notif-btn');
  var dd  = document.getElementById('notif-dropdown');
  if (!btn || !dd) return;

  function open() {
    dd.classList.add('is-open');
    btn.setAttribute('aria-expanded', 'true');
    if (typeof lucide !== 'undefined') lucide.createIcons();
  }
  function close() {
    dd.classList.remove('is-open');
    btn.setAttribute('aria-expanded', 'false');
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    dd.classList.contains('is-open') ? close() : open();
  });
  document.addEventListener('click', function (e) {
    if (!dd.contains(e.target) && e.target !== btn) close();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && dd.classList.contains('is-open')) {
      close();
      btn.focus();
    }
  });
})();
</script>
