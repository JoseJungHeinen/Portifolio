<?php
// @versao Not_01 V_01_16/07/2026
/**
 * views/notificacoes/index.php
 * Lista completa e paginada de notificações do usuário logado.
 *
 * Variáveis fornecidas pelo NotificationController:
 *   $result  array  { itens, total, pagina, por_pag, paginas }
 *   $flash   array|null  { type, message }
 */

$itens   = $result['itens']   ?? [];
$total   = $result['total']   ?? 0;
$pagina  = $result['pagina']  ?? 1;
$paginas = $result['paginas'] ?? 1;

function notifPageUrl(int $p): string
{
    $params = $_GET;
    $params['pag'] = $p;
    $params['module'] = 'notificacoes';
    $params['action'] = 'index';
    return '?' . http_build_query($params);
}

function notifStatusBadge(array $n): string
{
    if ($n['lida']) {
        return '<span class="badge badge-muted" style="font-size:10px;">Lida</span>';
    }
    return '<span class="badge badge-primary" style="font-size:10px;">Nova</span>';
}

function notifTimeAgo(string $dt): string
{
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'Agora mesmo';
    if ($diff < 3600)  return (int)($diff / 60) . ' min atrás';
    if ($diff < 86400) return (int)($diff / 3600) . 'h atrás';
    $date = new DateTimeImmutable($dt);
    return $date->format('d/m/Y \à\s H:i');
}
?>

<?php include VIEWS . '/components/alert.php'; ?>

<!-- Page header -->
<div class="page-header">
  <div class="page-header__left">
    <h1 class="page-header__title">
      Notificações <span style="color:#2347C8;">do sistema</span>
    </h1>
    <p class="page-header__subtitle">
      <?= $total ?> notificação<?= $total !== 1 ? 'ões' : '' ?> no total
      <?php if ($unreadCount > 0): ?>
      — <strong style="color:#2347C8;"><?= $unreadCount ?> não lida<?= $unreadCount !== 1 ? 's' : '' ?></strong>
      <?php endif; ?>
    </p>
  </div>
  <div class="page-header__actions">
    <?php if ($unreadCount > 0): ?>
    <form method="post" action="?module=notificacoes&action=marcar_todas">
      <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
      <button type="submit" class="btn btn-secondary">
        <i data-lucide="check-check" width="16" height="16"></i>
        Marcar todas como lidas
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- Lista -->
<div class="card" style="padding:0; overflow:hidden;">
  <?php if (empty($itens)): ?>
  <?php
  $emptyIcon    = 'bell-off';
  $emptyTitle   = 'Nenhuma notificação';
  $emptyMessage = 'Você está em dia — não há notificações.';
  $emptyAction  = null;
  include VIEWS . '/components/empty-state.php';
  ?>
  <?php else: ?>
  <table class="table">
    <thead>
      <tr>
        <th style="width:40px;"></th>
        <th>Notificação</th>
        <th style="width:120px;">Status</th>
        <th style="width:150px;">Quando</th>
        <th style="width:80px;"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($itens as $notif):
        $meta = Notification::meta($notif['tipo']);
        $url  = Notification::url($notif);
      ?>
      <tr class="<?= $notif['lida'] ? '' : 'notif-row--unread' ?>">
        <!-- Ícone -->
        <td style="text-align:center;vertical-align:middle;">
          <div class="notif-item__icon notif-item__icon--<?= h($meta['color']) ?>"
               style="width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;">
            <i data-lucide="<?= h($meta['icon']) ?>" width="16" height="16"></i>
          </div>
        </td>

        <!-- Título + mensagem -->
        <td style="vertical-align:middle;">
          <div style="font-family:var(--font-bold);font-size:13px;color:<?= $notif['lida'] ? '#606060' : '#141922' ?>;">
            <?= h($notif['titulo']) ?>
          </div>
          <?php if ($notif['mensagem']): ?>
          <div style="font-family:var(--font-regular);font-size:12px;color:#888888;margin-top:2px;">
            <?= h($notif['mensagem']) ?>
          </div>
          <?php endif; ?>
        </td>

        <!-- Status -->
        <td style="vertical-align:middle;"><?= notifStatusBadge($notif) ?></td>

        <!-- Quando -->
        <td style="vertical-align:middle;font-family:var(--font-regular);font-size:12px;color:#888888;">
          <?= notifTimeAgo($notif['criado_em']) ?>
        </td>

        <!-- Ação -->
        <td style="vertical-align:middle;text-align:center;">
          <?php if (!$notif['lida']): ?>
          <form method="post" action="?module=notificacoes&action=marcar" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <input type="hidden" name="id"       value="<?= h($notif['id']) ?>">
            <input type="hidden" name="redirect" value="<?= h($url) ?>">
            <button type="submit" class="btn btn-tertiary btn-sm" title="Marcar como lida e abrir">
              <i data-lucide="check" width="14" height="14"></i>
            </button>
          </form>
          <?php else: ?>
          <a href="<?= h($url) ?>" class="btn btn-tertiary btn-sm" title="Abrir">
            <i data-lucide="arrow-right" width="14" height="14"></i>
          </a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Paginação -->
<?php if ($paginas > 1): ?>
<div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:24px;">
  <?php if ($pagina > 1): ?>
  <a href="<?= notifPageUrl($pagina - 1) ?>" class="btn btn-tertiary btn-sm">
    <i data-lucide="chevron-left" width="14" height="14"></i>
  </a>
  <?php endif; ?>

  <?php for ($p = 1; $p <= $paginas; $p++):
    if ($p === 1 || $p === $paginas || abs($p - $pagina) <= 1): ?>
    <a href="<?= notifPageUrl($p) ?>"
       class="btn btn-sm <?= $p === $pagina ? 'btn-primary' : 'btn-tertiary' ?>">
      <?= $p ?>
    </a>
    <?php elseif (abs($p - $pagina) === 2): ?>
    <span style="padding:0 4px;color:#888888;">…</span>
    <?php endif; ?>
  <?php endfor; ?>

  <?php if ($pagina < $paginas): ?>
  <a href="<?= notifPageUrl($pagina + 1) ?>" class="btn btn-tertiary btn-sm">
    <i data-lucide="chevron-right" width="14" height="14"></i>
  </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<style>
.notif-row--unread td {
  background: #FFF8F8 !important;
}
.table tbody tr.notif-row--unread:hover td {
  background: #EEF2FD !important;
}
</style>
