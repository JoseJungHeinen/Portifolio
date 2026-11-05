<?php
// @versao Grp_01 V_01_16/07/2026
/**
 * views/groups/list.php
 * Listagem de Grupos de Aprovação
 *
 * Variáveis esperadas:
 *   $grupos       array  lista de grupos
 *   $total        int    total de registros
 *   $totais       array  [total, ativos, inativos]
 *   $totalPgs     int    total de páginas
 *   $pagina       int    página atual
 *   $filters      array  [status, busca, pagina, por_pagina]
 *   $currentUser  array  [id, nome, perfil]
 *   $flash        array|null [type, message]
 */

$grupos   = $grupos   ?? [];
$total    = $total    ?? 0;
$totais   = $totais   ?? ['total' => 0, 'ativos' => 0, 'inativos' => 0];
$totalPgs = $totalPgs ?? 1;
$pagina   = $pagina   ?? 1;
$filters  = $filters  ?? ['status' => '', 'busca' => '', 'pagina' => 1, 'por_pagina' => 20];

$pageTitle = 'Grupos de Aprovação';
$activeModule = 'grupos';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">

<?php include VIEWS . '/components/alert.php'; ?>

<!-- Page Header -->
<div class="page-header" style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:28px; flex-wrap:wrap; gap:12px">
  <div>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
      Grupos <span style="color:#2347C8">de Aprovação</span>
    </h1>
    <p style="font-size:14px; color:#888; margin:0">Gerencie os times de aprovação do workflow de NF</p>
  </div>
  <a href="?module=grupos&amp;action=novo"
     style="display:inline-flex; align-items:center; gap:8px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; text-decoration:none; cursor:pointer">
    <i data-lucide="plus" width="16" height="16"></i>
    Novo Grupo
  </a>
</div>

<!-- Filtros -->
<div class="tabelas-filterbar">
  <div class="tab-filters">
    <?php
    $tabs = [
      ['label' => 'Todos',    'value' => ''],
      ['label' => 'Ativos',   'value' => 'ativos'],
      ['label' => 'Inativos', 'value' => 'inativos'],
    ];
    foreach ($tabs as $tab):
      $active = ($filters['status'] === $tab['value']);
      $href = '?module=grupos&action=index&status=' . urlencode($tab['value'])
            . ($filters['busca'] ? '&busca=' . urlencode($filters['busca']) : '');
    ?>
    <a href="<?= h($href) ?>" class="tab-filter-btn <?= $active ? 'active' : '' ?>">
      <?= h($tab['label']) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <form method="GET" role="search" style="flex:1; max-width:320px; position:relative;">
    <input type="hidden" name="module" value="grupos">
    <input type="hidden" name="action" value="index">
    <input type="hidden" name="status" value="<?= h($filters['status']) ?>">
    <i data-lucide="search" width="16" height="16" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#888; pointer-events:none"></i>
    <input type="search" name="busca" value="<?= h($filters['busca']) ?>"
           placeholder="Buscar grupo..."
           style="width:100%; height:40px; padding:0 12px 0 34px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
           onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
  </form>
</div>

<!-- Tabela -->
<div style="background:#FFFFFF; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0px 2px 8px; overflow:hidden">
  <table style="width:100%; border-collapse:collapse">
    <thead>
      <tr style="background:#606060">
        <th style="padding:12px 16px; text-align:left; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:0.5px">Grupo</th>
        <th style="padding:12px 16px; text-align:center; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:0.5px">Membros</th>
        <th style="padding:12px 16px; text-align:center; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:0.5px">Status</th>
        <th style="padding:12px 16px; text-align:center; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:0.5px">Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($grupos)): ?>
      <tr>
        <td colspan="4" style="padding:0">
          <?php
          $emptyIcon    = 'layers';
          $emptyTitle   = 'Nenhum grupo cadastrado';
          $emptyMessage = !empty($filters['busca'])
              ? 'Tente uma busca diferente.'
              : 'Grupos organizam os templates por área.';
          $emptyAction  = empty($filters['busca'])
              ? ['label' => 'Novo Grupo', 'url' => '?module=grupos&action=novo', 'icon' => 'plus']
              : null;
          include VIEWS . '/components/empty-state.php';
          ?>
        </td>
      </tr>
      <?php else: ?>
      <?php foreach ($grupos as $i => $g): ?>
      <tr style="background:<?= $i % 2 === 0 ? '#FFFFFF' : '#F8F8F8' ?>; transition:background 0.1s"
          onmouseover="this.style.background='#EEF2FD'" onmouseout="this.style.background='<?= $i % 2 === 0 ? '#FFFFFF' : '#F8F8F8' ?>'">

        <!-- Grupo -->
        <td style="padding:14px 16px">
          <div style="font-weight:700; font-size:14px; color:#141922"><?= h($g['nome']) ?></div>
          <?php if (!empty($g['descricao'])): ?>
          <div style="font-size:12px; color:#888; margin-top:2px"><?= h($g['descricao']) ?></div>
          <?php endif; ?>
        </td>

        <!-- Membros -->
        <td style="padding:14px 16px; text-align:center">
          <span style="display:inline-flex; align-items:center; gap:5px; font-size:13px; color:#606060">
            <i data-lucide="users" width="14" height="14"></i>
            <?= (int)($g['total_membros'] ?? 0) ?>
          </span>
        </td>

        <!-- Status -->
        <td style="padding:14px 16px; text-align:center">
          <?php if ($g['ativo']): ?>
          <span style="display:inline-block; padding:3px 10px; border-radius:8px; background:#D8F5E5; color:#00A960; font-size:10px; font-weight:700">ATIVO</span>
          <?php else: ?>
          <span style="display:inline-block; padding:3px 10px; border-radius:8px; background:#EAEAEA; color:#888888; font-size:10px; font-weight:700">INATIVO</span>
          <?php endif; ?>
        </td>

        <!-- Ações -->
        <td style="padding:14px 16px; text-align:center">
          <div style="display:inline-flex; align-items:center; gap:8px">
            <a href="?module=grupos&action=detalhe&id=<?= h($g['id']) ?>"
               style="display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:4px; border:1px solid #2347C8; color:#2347C8; font-size:13px; font-weight:700; text-decoration:none">
              <i data-lucide="pencil" width="13" height="13"></i>
              Editar
            </a>
            <button class="btn-toggle-grupo"
                    data-id="<?= h($g['id']) ?>"
                    data-ativo="<?= $g['ativo'] ? '1' : '0' ?>"
                    data-nome="<?= h($g['nome']) ?>"
                    style="display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:4px; border:1px solid #EAEAEA; background:#EAEAEA; color:#606060; font-size:13px; font-weight:700; cursor:pointer">
              <?php if ($g['ativo']): ?>
              <i data-lucide="toggle-right" width="13" height="13"></i>
              Desativar
              <?php else: ?>
              <i data-lucide="toggle-left" width="13" height="13"></i>
              Ativar
              <?php endif; ?>
            </button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- Footer da tabela -->
  <div style="padding:14px 20px; border-top:1px solid #EAEAEA; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px">
    <div style="font-size:13px; color:#888">
      <strong style="color:#141922"><?= (int)($totais['total'] ?? 0) ?></strong> grupos ·
      <strong style="color:#00A960"><?= (int)($totais['ativos'] ?? 0) ?></strong> ativos ·
      <strong style="color:#888"><?= (int)($totais['inativos'] ?? 0) ?></strong> inativos
    </div>
    <?php if ($totalPgs > 1):
        $currentPage = $pagina;
        $totalPages  = $totalPgs;
        $module      = 'grupos';
        $action      = 'index';
        $extraParams = array_filter([
            'status' => $filters['status'] ?? '',
            'busca'  => $filters['busca'] ?? '',
        ]);
        include VIEWS . '/components/pagination.php';
    endif; ?>
  </div>
</div>

<!-- Modal de bloqueio -->
<div id="modal-bloqueio"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center">
  <div style="background:#fff; border-radius:12px; box-shadow:0 16px 48px rgba(0,0,0,0.2); padding:32px; max-width:440px; width:90%; text-align:center">
    <div style="width:52px; height:52px; border-radius:50%; background:#FFF3E0; display:flex; align-items:center; justify-content:center; margin:0 auto 16px">
      <i data-lucide="alert-triangle" width="24" height="24" style="color:#FF8A2D"></i>
    </div>
    <h3 style="font-size:20px; font-weight:700; color:#141922; margin:0 0 8px">Não é possível desativar este grupo</h3>
    <p id="modal-bloqueio-texto" style="font-size:14px; color:#606060; margin:0 0 24px">
      Este grupo possui processos ativos vinculados.
    </p>
    <p style="font-size:13px; color:#888; margin:0 0 24px">Resolva os processos pendentes primeiro.</p>
    <button onclick="document.getElementById('modal-bloqueio').style.display='none'"
            style="background:#EAEAEA; color:#606060; border:none; border-radius:4px; padding:10px 28px; font-size:14px; font-weight:700; cursor:pointer">
      Entendido
    </button>
  </div>
</div>

<script>
document.querySelectorAll('.btn-toggle-grupo').forEach(function(btn) {
    btn.addEventListener('click', async function() {
        var id   = btn.dataset.id;
        var ativo = btn.dataset.ativo;
        var csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        var acao = ativo === '1' ? 'Desativar' : 'Ativar';
        btModalConfirm(acao + ' grupo', acao + ' o grupo "' + btn.dataset.nome + '"?', async function () {
            try {
                var res = await fetch('?module=grupos&action=toggle_status', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(csrf)
                });
                var data = await res.json();
                if (data.success) {
                    location.reload();
                } else {
                    var msg = data.message || 'Não foi possível alterar o status do grupo.';
                    document.getElementById('modal-bloqueio-texto').textContent = msg;
                    document.getElementById('modal-bloqueio').style.display = 'flex';
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }
            } catch (e) {
                btModalErro('Erro de comunicação', 'Não foi possível conectar ao servidor. Tente novamente.');
            }
        });
    });
});
</script>
