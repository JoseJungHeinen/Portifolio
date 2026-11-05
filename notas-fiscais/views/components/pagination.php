<?php
// @versao Sys_10 V_01_16/07/2026
/**
 * views/components/pagination.php
 * Componente de paginação com ellipsis
 *
 * Variáveis esperadas:
 *   $currentPage  int    Página atual (1-based)
 *   $totalPages   int    Total de páginas
 *   $module       string Módulo para montar a URL (ex: 'processes')
 *   $action       string Action para montar a URL (ex: 'index')
 *   $extraParams  array  Parâmetros GET adicionais ['q' => 'busca', ...]
 *   $pageParam    string Nome do parâmetro de página na URL (default 'page') —
 *                        use um nome exclusivo quando várias listas paginadas
 *                        convivem na mesma tela, pra não colidir entre si.
 */

// As funções são declaradas uma única vez (guard), já que este componente pode
// ser incluído mais de uma vez na mesma página — uma tela com várias listas
// paginadas simultâneas (ex: aba Processos) inclui este arquivo várias vezes.
if (!function_exists('paginationUrl')) {
    function paginationUrl(array $params, int $page, string $pageParam): string {
        $params[$pageParam] = $page;
        return '?' . http_build_query($params);
    }
}

if (!function_exists('paginationRange')) {
    function paginationRange(int $current, int $total): array {
        if ($total <= 7) {
            return range(1, $total);
        }
        $range = [];
        $window = 2; // páginas ao redor da atual
        $start = max(2, $current - $window);
        $end   = min($total - 1, $current + $window);

        $range[] = 1;
        if ($start > 2) $range[] = '...';
        for ($i = $start; $i <= $end; $i++) $range[] = $i;
        if ($end < $total - 1) $range[] = '...';
        $range[] = $total;

        return $range;
    }
}

$currentPage = (int)($currentPage ?? 1);
$totalPages  = (int)($totalPages  ?? 1);
$module      = $module      ?? '';
$action      = $action      ?? 'index';
$extraParams = $extraParams ?? [];
$pageParam   = $pageParam   ?? 'page';

if ($totalPages <= 1) {
    unset($currentPage, $totalPages, $module, $action, $extraParams, $pageParam);
    return;
}

// Monta base da URL
$paginationParams = array_merge(['module' => $module, 'action' => $action], $extraParams);
$paginationRangeList = paginationRange($currentPage, $totalPages);
?>
<nav class="pagination" role="navigation" aria-label="Paginação">

  <!-- Anterior -->
  <?php if ($currentPage > 1): ?>
  <a href="<?= paginationUrl($paginationParams, $currentPage - 1, $pageParam) ?>"
     class="pagination-item"
     aria-label="Página anterior">
    <i data-lucide="chevron-left" width="14" height="14"></i>
  </a>
  <?php else: ?>
  <span class="pagination-item is-disabled" aria-disabled="true">
    <i data-lucide="chevron-left" width="14" height="14"></i>
  </span>
  <?php endif; ?>

  <!-- Páginas -->
  <?php foreach ($paginationRangeList as $page): ?>
    <?php if ($page === '...'): ?>
    <span class="pagination-ellipsis" aria-hidden="true">…</span>
    <?php elseif ((int)$page === $currentPage): ?>
    <span class="pagination-item is-active" aria-current="page"><?= $page ?></span>
    <?php else: ?>
    <a href="<?= paginationUrl($paginationParams, (int)$page, $pageParam) ?>"
       class="pagination-item"
       aria-label="Página <?= $page ?>">
      <?= $page ?>
    </a>
    <?php endif; ?>
  <?php endforeach; ?>

  <!-- Próxima -->
  <?php if ($currentPage < $totalPages): ?>
  <a href="<?= paginationUrl($paginationParams, $currentPage + 1, $pageParam) ?>"
     class="pagination-item"
     aria-label="Próxima página">
    <i data-lucide="chevron-right" width="14" height="14"></i>
  </a>
  <?php else: ?>
  <span class="pagination-item is-disabled" aria-disabled="true">
    <i data-lucide="chevron-right" width="14" height="14"></i>
  </span>
  <?php endif; ?>

</nav>
<?php
unset($currentPage, $totalPages, $module, $action, $extraParams, $pageParam, $paginationParams, $paginationRangeList, $page);
