<?php
// @versao Sys_05 V_01_16/07/2026
/**
 * views/components/alert.php
 * Componente de alerta / flash message
 *
 * Uso 1 — variáveis diretas:
 *   $alertType    = 'success'; // success | warning | danger | info | accent
 *   $alertMessage = 'Processo criado com sucesso!';
 *   $alertTitle   = 'Sucesso!'; // opcional
 *   $alertDismissible = true;  // opcional, padrão true
 *   include 'views/components/alert.php';
 *
 * Uso 2 — flash da sessão (automático):
 *   // Basta incluir o arquivo; se $_SESSION['flash'] existir, exibe e limpa.
 */

// Flash da sessão tem prioridade
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $alertType    = $flash['type']    ?? 'info';
    $alertMessage = $flash['message'] ?? '';
    $alertTitle   = $flash['title']   ?? '';
    unset($_SESSION['flash']);
} elseif (!empty($flash) && is_array($flash)) {
    // Fallback: controller leu da sessão e passou como variável $flash
    $alertType    = $flash['type']    ?? 'info';
    $alertMessage = $flash['message'] ?? '';
    $alertTitle   = $flash['title']   ?? '';
}

$alertType        = $alertType        ?? null;
$alertMessage     = $alertMessage     ?? '';
$alertTitle       = $alertTitle       ?? '';
$alertDismissible = $alertDismissible ?? true;
$alertAutoDismiss = $alertAutoDismiss ?? 0; // ms, 0 = desativado

if (!$alertType || !$alertMessage) return;

// Ícone por tipo
$icons = [
    'success' => 'check-circle',
    'warning' => 'alert-triangle',
    'danger'  => 'x-circle',
    'info'    => 'info',
    'accent'  => 'bell',
];
$icon = $icons[$alertType] ?? 'info';

$autoDismissAttr = $alertAutoDismiss > 0 ? ' data-auto-dismiss="' . (int)$alertAutoDismiss . '"' : '';
?>
<div class="alert alert-<?= htmlspecialchars($alertType) ?>"
     role="alert"<?= $autoDismissAttr ?>>

  <span class="alert-icon">
    <i data-lucide="<?= $icon ?>" width="18" height="18"></i>
  </span>

  <div class="alert-content">
    <?php if ($alertTitle): ?>
    <div class="alert-title"><?= htmlspecialchars($alertTitle) ?></div>
    <?php endif; ?>
    <div><?= htmlspecialchars($alertMessage) ?></div>
  </div>

  <?php if ($alertDismissible): ?>
  <button class="alert-dismiss" aria-label="Fechar aviso">
    <i data-lucide="x" width="16" height="16"></i>
  </button>
  <?php endif; ?>

</div>
<?php
// Limpa variáveis para não vazar para o escopo pai
unset($alertType, $alertMessage, $alertTitle, $alertDismissible, $alertAutoDismiss, $icon, $icons, $autoDismissAttr, $flash);
