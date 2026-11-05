<?php
// @versao Sys_08 V_01_16/07/2026
/**
 * views/components/filter-period.php
 * Filtro de período reutilizável
 *
 * Variáveis esperadas:
 *   $activePeriod   string  Período ativo: 'week' | 'month' | 'quarter' | 'custom'
 *   $dateStart      string  Data inicial para modo custom (YYYY-MM-DD)
 *   $dateEnd        string  Data final para modo custom (YYYY-MM-DD)
 *   $filterFormId   string  ID do form pai (para submit via JS) — opcional
 */

$activePeriod = $activePeriod ?? 'month';
$dateStart    = $dateStart    ?? date('Y-m-01');
$dateEnd      = $dateEnd      ?? date('Y-m-d');
$filterFormId = $filterFormId ?? '';

$periods = [
    'week'    => 'Esta semana',
    'month'   => 'Este mês',
    'quarter' => 'Este trimestre',
    'custom'  => 'Personalizado',
];
?>
<div class="filter-period" role="group" aria-label="Filtrar por período">

  <?php foreach ($periods as $key => $label): ?>
  <button type="button"
          class="filter-period-btn<?= $activePeriod === $key ? ' is-active' : '' ?>"
          data-period="<?= $key ?>">
    <?= htmlspecialchars($label) ?>
  </button>
  <?php endforeach; ?>

  <!-- Datas customizadas (visível só quando 'custom' ativo) -->
  <div class="filter-period-custom<?= $activePeriod === 'custom' ? ' is-visible' : '' ?>">
    <input type="date"
           class="form-input"
           name="date_start"
           value="<?= htmlspecialchars($dateStart) ?>"
           aria-label="Data inicial">
    <span style="color:#888888; font-size:13px;">até</span>
    <input type="date"
           class="form-input"
           name="date_end"
           value="<?= htmlspecialchars($dateEnd) ?>"
           aria-label="Data final">
    <?php if ($filterFormId): ?>
    <button type="submit" form="<?= htmlspecialchars($filterFormId) ?>" class="btn btn-primary btn-sm">
      <i data-lucide="search" width="14" height="14"></i>
      Filtrar
    </button>
    <?php else: ?>
    <button type="button" class="btn btn-primary btn-sm" onclick="this.closest('form')?.submit()">
      <i data-lucide="search" width="14" height="14"></i>
      Filtrar
    </button>
    <?php endif; ?>
  </div>

</div>
<?php
unset($activePeriod, $dateStart, $dateEnd, $filterFormId, $periods);
