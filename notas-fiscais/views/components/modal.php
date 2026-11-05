<?php
// @versao Sys_09 V_01_16/07/2026
/**
 * views/components/modal.php
 * Componente reutilizável de modal
 *
 * Variáveis esperadas:
 *   $modalId      string  ID único do overlay (ex: 'modal-excluir')
 *   $modalTitle   string  Título do modal
 *   $modalBody    string  HTML do corpo do modal (via ob_get_clean ou string direta)
 *   $modalFooter  string  HTML do rodapé com botões (opcional)
 *   $modalSize    string  'sm' | '' | 'lg' | 'xl' (opcional, padrão '')
 */

$modalId     = $modalId     ?? 'modal-' . uniqid();
$modalTitle  = $modalTitle  ?? 'Modal';
$modalBody   = $modalBody   ?? '';
$modalFooter = $modalFooter ?? '';
$modalSize   = $modalSize   ?? '';

$sizeClass = $modalSize ? ' modal-' . $modalSize : '';
?>
<div class="modal-overlay<?= $sizeClass ?>" id="<?= htmlspecialchars($modalId) ?>" role="dialog" aria-modal="true" aria-labelledby="<?= htmlspecialchars($modalId) ?>-title">
  <div class="modal">

    <div class="modal-header">
      <h3 class="modal-title" id="<?= htmlspecialchars($modalId) ?>-title">
        <?= htmlspecialchars($modalTitle) ?>
      </h3>
      <button class="modal-close" data-modal-close aria-label="Fechar modal">
        <i data-lucide="x" width="20" height="20"></i>
      </button>
    </div>

    <div class="modal-body">
      <?= $modalBody ?>
    </div>

    <?php if ($modalFooter): ?>
    <div class="modal-footer">
      <?= $modalFooter ?>
    </div>
    <?php endif; ?>

  </div>
</div>
