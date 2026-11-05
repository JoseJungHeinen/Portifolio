<?php
// @versao Sys_06 V_01_16/07/2026
/**
 * views/components/badge.php
 * Componente de badge/chip de status
 *
 * Variáveis esperadas:
 *   $badgeText     string  Texto do badge
 *   $badgeVariant  string  success | warning | danger | muted | primary | accent
 *   $badgeIcon     string  Nome do ícone Lucide (opcional)
 */

$badgeText    = $badgeText    ?? '';
$badgeVariant = $badgeVariant ?? 'muted';
$badgeIcon    = $badgeIcon    ?? '';

if (!$badgeText) return;
?>
<span class="badge badge-<?= htmlspecialchars($badgeVariant) ?>">
  <?php if ($badgeIcon): ?>
  <i data-lucide="<?= htmlspecialchars($badgeIcon) ?>" width="10" height="10"></i>
  <?php endif; ?>
  <?= htmlspecialchars($badgeText) ?>
</span>
<?php
unset($badgeText, $badgeVariant, $badgeIcon);
