<?php
// @versao Tab_01 V_05_05/08/2026
/**
 * views/tables/list.php
 * Listagem de Tabelas & Listas — Módulo Tabelas
 *
 * Variáveis disponíveis (injetadas pelo controller):
 *   $tabelas      array   Lista de tabelas [id, nome, slug, tipo, descricao, ativo, total_colunas, total_itens]
 *   $total        int     Total de registros (com filtro)
 *   $totalAtivas  int     Contagem de tabelas ativas
 *   $totalInativas int    Contagem de tabelas inativas
 *   $currentPage  int     Página atual
 *   $totalPages   int     Total de páginas
 *   $filters      array   ['status' => '', 'q' => '', 'page' => 1]
 *   $currentUser  array   Usuário logado
 */

/** @var callable $h — função de escape definida em base.php */
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$filters     = $filters     ?? ['status' => '', 'q' => '', 'page' => 1];
$tabelas     = $tabelas     ?? [];
$total       = $total       ?? 0;
$totalAtivas  = $totalAtivas  ?? 0;
$totalInativas = $totalInativas ?? 0;
$currentPage = $currentPage ?? 1;
$totalPages  = $totalPages  ?? 1;

$currentStatus = $filters['status'] ?? '';
$currentQ      = $filters['q']      ?? '';

// URL base para filtros
// status e q vão sempre na URL, mesmo vazios: o filtro agora fica guardado na
// sessão e é a presença do campo na query que diz "este é o novo recorte".
// Omitir o parâmetro faria a aba clicada reabrir com o filtro anterior.
function tabelasFilterUrl(string $status = '', string $q = '', int $page = 1): string {
    $params = ['module' => 'tabelas', 'action' => 'index', 'status' => $status, 'q' => $q];
    if ($page > 1) $params['page'] = $page;
    return '?' . http_build_query($params);
}

$tabFiltroAtivo = $currentStatus !== '' || $currentQ !== '';
?>

<!-- Page Header -->
<div class="tabelas-header">
  <div class="tabelas-header-text">
    <h1 class="page-title-h1">Listas de <span style="color:#2347C8">Referência</span></h1>
    <p>Gerencie as listas de valores do sistema</p>
  </div>
  <div class="tabelas-header-actions">
    <a href="?module=tabelas&amp;action=nova" class="btn btn-primary">
      <i data-lucide="plus" width="16" height="16"></i>
      Nova Lista
    </a>
  </div>
</div>

<!-- Flash message -->
<?php include VIEWS . '/components/alert.php'; ?>

<!-- Filter Bar -->
<div class="tabelas-filterbar">
  <div class="tab-filters">
    <a href="<?= tabelasFilterUrl('', $currentQ) ?>"
       class="tab-filter-btn <?= $currentStatus === '' ? 'active' : '' ?>">
      Todas
    </a>
    <a href="<?= tabelasFilterUrl('ativo', $currentQ) ?>"
       class="tab-filter-btn <?= $currentStatus === 'ativo' ? 'active' : '' ?>">
      Ativas
    </a>
    <a href="<?= tabelasFilterUrl('inativo', $currentQ) ?>"
       class="tab-filter-btn <?= $currentStatus === 'inativo' ? 'active' : '' ?>">
      Inativas
    </a>
  </div>

  <form method="get" action="" role="search" style="flex:1; max-width:320px; position:relative;">
    <input type="hidden" name="module" value="tabelas">
    <input type="hidden" name="action" value="index">
    <?php if ($currentStatus !== ''): ?>
    <input type="hidden" name="status" value="<?= h($currentStatus) ?>">
    <?php endif; ?>
    <i data-lucide="search" width="16" height="16"
       style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#888; pointer-events:none;"></i>
    <input type="search"
           name="q"
           value="<?= h($currentQ) ?>"
           placeholder="Buscar lista..."
           aria-label="Buscar lista"
           style="width:100%; height:40px; padding:0 12px 0 34px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; color:#141922; box-sizing:border-box; outline:none;"
           onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
  </form>

  <?php // Esta tela não tem botão de aplicar (a busca vale no Enter), mas
        // precisa de saída: o recorte fica guardado até ser limpo. ?>
  <?php if ($tabFiltroAtivo): ?>
  <a href="?module=tabelas&amp;action=index&amp;limpar=1" title="Remover os filtros aplicados"
     style="height:40px; display:inline-flex; align-items:center; gap:6px; background:#EAEAEA; color:#606060; border-radius:4px; padding:0 20px; font-size:14px; font-weight:700; text-decoration:none">
    <i data-lucide="x" width="15" height="15"></i> Limpar
  </a>
  <?php endif; ?>
</div>

<!-- Tabela -->
<div class="tabelas-table-wrapper">
  <?php if (empty($tabelas)): ?>
    <?php
    $emptyIcon    = 'database';
    $emptyTitle   = $currentQ !== '' ? 'Nenhuma tabela encontrada' : 'Nenhuma tabela cadastrada';
    $emptyMessage = $currentQ !== ''
        ? 'Tente um termo diferente ou limpe o filtro.'
        : 'Listas definem os valores de referência do sistema.';
    $emptyAction  = ($currentQ === '' && $currentStatus === '')
        ? ['label' => 'Nova Lista', 'url' => '?module=tabelas&action=nova', 'icon' => 'plus']
        : null;
    include VIEWS . '/components/empty-state.php';
    ?>
  <?php else: ?>
  <table class="tabelas-table">
    <thead>
      <tr>
        <th>Lista</th>
        <th>Tipo</th>
        <th style="text-align:center">Itens</th>
        <th>Status</th>
        <th style="text-align:center">Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tabelas as $t):
        // Listas com tela própria (ex.: Fornecedores) trazem a URL pronta
        $linkLista = $t['url'] ?? ('?module=tabelas&amp;action=itens&amp;id=' . h($t['id']));
      ?>
      <tr class="<?= $t['ativo'] ? '' : 'row-inativo' ?>">
        <td class="col-nome">
          <a href="<?= $linkLista ?>"
             style="text-decoration:none; color:#141922; display:block; font-weight:700;">
            <?= h($t['nome']) ?>
            <?php if (!empty($t['descricao'])): ?>
            <div style="font-size:12px; color:#888; margin-top:2px;"><?= h($t['descricao']) ?></div>
            <?php endif; ?>
          </a>
        </td>
        <td>
          <?php if ($t['tipo'] === 'sistema'): ?>
            <span class="badge-tipo-pre" style="background:#D8D4E4; color:#606060">
              <i data-lucide="building-2" width="10" height="10"></i>
              Sistema
            </span>
          <?php elseif ($t['tipo'] === 'pre_configurada'): ?>
            <span class="badge-tipo-pre">
              <i data-lucide="lock" width="10" height="10"></i>
              Pré-configurada
            </span>
          <?php else: ?>
            <span class="badge-tipo-personalizada">
              <i data-lucide="settings" width="10" height="10"></i>
              Personalizada
            </span>
          <?php endif; ?>
        </td>
        <td style="text-align:center; color:#606060; font-size:13px;">
          <?= (int)($t['total_itens'] ?? 0) ?>
        </td>
        <td>
          <?php if ($t['ativo']): ?>
            <span class="badge-ativo">Ativa</span>
          <?php else: ?>
            <span class="badge-inativo">Inativa</span>
          <?php endif; ?>
        </td>
        <td style="text-align:center;">
          <a href="<?= $linkLista ?>"
             style="display:inline-flex; align-items:center; gap:4px; padding:6px 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:12px; font-weight:600; color:#606060; text-decoration:none; background:#fff"
             onmouseover="this.style.borderColor='#2347C8';this.style.color='#2347C8'" onmouseout="this.style.borderColor='#DFDFDF';this.style.color='#606060'">
            <i data-lucide="list" width="13" height="13"></i>
            Ver itens
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Footer -->
  <div class="tabelas-footer">
    <div class="tabelas-footer-stats">
      <span><?= $total ?> <?= $total === 1 ? 'tabela' : 'tabelas' ?></span>
      <span style="color:#00A960;"><?= $totalAtivas ?> ativas</span>
      <span style="color:#888888;"><?= $totalInativas ?> inativas</span>
    </div>
    <?php if ($totalPages > 1):
      $module      = 'tabelas';
      $action      = 'index';
      $extraParams = [];
      if ($currentStatus !== '') $extraParams['status'] = $currentStatus;
      if ($currentQ !== '')      $extraParams['q']      = $currentQ;
      include VIEWS . '/components/pagination.php';
    endif; ?>
  </div>
  <?php endif; ?>
</div>
