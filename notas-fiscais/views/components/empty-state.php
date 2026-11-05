<?php
// @versao Sys_07 V_01_16/07/2026
/**
 * views/components/empty-state.php
 * Componente reutilizável de estado vazio (Empty State).
 *
 * Variáveis esperadas (devem ser definidas antes do include):
 *   $emptyIcon    string       Nome do ícone Lucide (ex: 'file-x')
 *   $emptyTitle   string       Título principal (ex: 'Nenhum processo encontrado')
 *   $emptyMessage string       Mensagem descritiva secundária
 *   $emptyAction  array|null   Botão de ação opcional:
 *                              ['label' => '...', 'url' => '...', 'icon' => '...']
 *
 * Estilos definidos em public/assets/css/design-system.css:
 *   .empty-state, .empty-state-icon, .empty-state-title, .empty-state-text
 */

$emptyIcon    = $emptyIcon    ?? 'inbox';
$emptyTitle   = $emptyTitle   ?? 'Nenhum item encontrado';
$emptyMessage = $emptyMessage ?? '';
$emptyAction  = $emptyAction  ?? null;

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<div class="empty-state" style="gap:12px;">
  <div class="empty-state-icon">
    <i data-lucide="<?= h($emptyIcon) ?>" style="width:48px; height:48px; color:#EAEAEA;"></i>
  </div>

  <p class="empty-state-title" style="font-size:16px; font-weight:700; color:#606060; margin:0;">
    <?= h($emptyTitle) ?>
  </p>

  <?php if ($emptyMessage !== ''): ?>
  <p class="empty-state-text" style="font-size:13px; color:#888888; margin:0;">
    <?= h($emptyMessage) ?>
  </p>
  <?php endif; ?>

  <?php if (!empty($emptyAction)): ?>
  <a href="<?= h($emptyAction['url']) ?>"
     class="btn btn-primary"
     style="margin-top:8px; display:inline-flex; align-items:center; gap:8px;">
    <?php if (!empty($emptyAction['icon'])): ?>
    <i data-lucide="<?= h($emptyAction['icon']) ?>" width="16" height="16"></i>
    <?php endif; ?>
    <?= h($emptyAction['label']) ?>
  </a>
  <?php endif; ?>
</div>
