<?php
// @versao Tpl_01 V_04_20/08/2026
/**
 * views/templates/list.php
 * Listagem de templates de fluxo NF
 *
 * Variáveis:
 *   $templates   array    Lista de templates
 *   $total       int
 *   $totalPages  int
 *   $page        int
 *   $busca       string
 *   $currentUser array
 */
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$statusLabels = [
    'publicado'               => ['label' => 'Publicado',           'class' => 'badge-success'],
    'publicado_com_rascunho'  => ['label' => 'Publicado + Rascunho','class' => 'badge-warning'],
    'rascunho'                => ['label' => 'Rascunho',            'class' => 'badge-muted'],
    'teste_interno'           => ['label' => 'Teste Interno',       'class' => 'badge-primary'],
    'uat'                     => ['label' => 'Teste com Usuários',  'class' => 'badge-warning'],
    'sem_versao'              => ['label' => 'Sem versão',          'class' => 'badge-muted'],
];
?>

<!-- Page Header -->
<div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:28px; flex-wrap:wrap; gap:16px;">
  <div>
    <h1 class="page-title-h1">Templates de <span style="color:#2347C8">Fluxo</span></h1>
    <p style="font-family:var(--font-regular); font-size:var(--text-body); color:var(--color-gray-medium); margin-top:4px;">
      Configure os modelos de aprovação que guiam os processos de NF.
    </p>
  </div>
  <a href="?module=templates&action=novo" class="btn btn-primary">
    <i data-lucide="plus" width="16" height="16"></i>
    Novo Template
  </a>
</div>

<!-- Flash message -->
<?php include VIEWS . '/components/alert.php'; ?>

<!-- Stats -->
<?php
$totalPublicados = count(array_filter($templates, fn($t) => in_array($t['status_efetivo'], ['publicado', 'publicado_com_rascunho'])));
$totalRascunhos  = count(array_filter($templates, fn($t) => in_array($t['status_efetivo'], ['rascunho', 'publicado_com_rascunho', 'teste_interno', 'uat'])));
?>
<div style="display:flex; gap:12px; margin-bottom:24px; flex-wrap:wrap;">
  <div class="card" style="padding:16px 20px; display:flex; align-items:center; gap:12px; flex:1; min-width:160px;">
    <div style="width:36px;height:36px;border-radius:8px;background:#D8F5E5;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <i data-lucide="check-circle" width="18" height="18" style="color:#00A960;"></i>
    </div>
    <div>
      <div style="font-family:var(--font-bold);font-size:22px;color:var(--color-dark);line-height:1.2;"><?= $totalPublicados ?></div>
      <div style="font-family:var(--font-regular);font-size:12px;color:var(--color-gray-medium);">Publicados</div>
    </div>
  </div>
  <div class="card" style="padding:16px 20px; display:flex; align-items:center; gap:12px; flex:1; min-width:160px;">
    <div style="width:36px;height:36px;border-radius:8px;background:#FFF3E0;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <i data-lucide="file-edit" width="18" height="18" style="color:#FF8A2D;"></i>
    </div>
    <div>
      <div style="font-family:var(--font-bold);font-size:22px;color:var(--color-dark);line-height:1.2;"><?= $totalRascunhos ?></div>
      <div style="font-family:var(--font-regular);font-size:12px;color:var(--color-gray-medium);">Em edição / teste</div>
    </div>
  </div>
  <div class="card" style="padding:16px 20px; display:flex; align-items:center; gap:12px; flex:1; min-width:160px;">
    <div style="width:36px;height:36px;border-radius:8px;background:#F0F0F0;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <i data-lucide="layers" width="18" height="18" style="color:#606060;"></i>
    </div>
    <div>
      <div style="font-family:var(--font-bold);font-size:22px;color:var(--color-dark);line-height:1.2;"><?= $total ?></div>
      <div style="font-family:var(--font-regular);font-size:12px;color:var(--color-gray-medium);">Total</div>
    </div>
  </div>
</div>

<?php
$filtros      = $filtros      ?? ['busca' => '', 'setor_id' => '', 'tipo_nota_id' => '', 'tem_financeiro' => '', 'ativo' => ''];
$setores      = $setores      ?? [];
$tiposNota    = $tiposNota    ?? [];
$temFiltroAtivo = !empty($filtros['setor_id']) || !empty($filtros['tipo_nota_id']) || $filtros['tem_financeiro'] !== '' || (!empty($filtros['ativo']) && $filtros['ativo'] !== 'todos') || $busca !== '';
?>
<!-- Filtros -->
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:16px 20px; margin-bottom:20px">
  <form method="get" action="" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
    <input type="hidden" name="module" value="templates">
    <input type="hidden" name="action" value="index">

    <!-- Busca -->
    <div style="flex:1; min-width:180px; position:relative;">
      <i data-lucide="search" width="16" height="16" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#888;pointer-events:none;"></i>
      <input type="text" name="q" value="<?= h($busca) ?>" placeholder="Buscar template..."
             style="width:100%;height:40px;padding:0 12px 0 34px;border:1px solid #DFDFDF;border-radius:4px;font-size:14px;color:#141922;box-sizing:border-box;outline:none"
             onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
    </div>

    <!-- Departamento -->
    <select name="setor_id"
            style="height:40px;border:1px solid #DFDFDF;border-radius:4px;padding:0 12px;font-size:14px;color:#141922;min-width:160px;outline:none"
            onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      <option value="">Departamento</option>
      <?php foreach ($setores as $s): ?>
      <option value="<?= h($s['id']) ?>" <?= ($filtros['setor_id'] ?? '') === $s['id'] ? 'selected' : '' ?>><?= h($s['nome']) ?></option>
      <?php endforeach; ?>
    </select>

    <!-- Tipo de Nota -->
    <select name="tipo_nota_id"
            style="height:40px;border:1px solid #DFDFDF;border-radius:4px;padding:0 12px;font-size:14px;color:#141922;min-width:140px;outline:none"
            onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      <option value="">Tipo de nota</option>
      <?php foreach ($tiposNota as $tn): ?>
      <option value="<?= h($tn['id']) ?>" <?= ($filtros['tipo_nota_id'] ?? '') === $tn['id'] ? 'selected' : '' ?>><?= h($tn['nome']) ?></option>
      <?php endforeach; ?>
    </select>

    <!-- Financeiro -->
    <select name="tem_financeiro"
            style="height:40px;border:1px solid #DFDFDF;border-radius:4px;padding:0 12px;font-size:14px;color:#141922;min-width:130px;outline:none"
            onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      <option value="">Financeiro</option>
      <option value="1" <?= ($filtros['tem_financeiro'] ?? '') === '1' ? 'selected' : '' ?>>Com financeiro</option>
      <option value="0" <?= ($filtros['tem_financeiro'] ?? '') === '0' ? 'selected' : '' ?>>Sem financeiro</option>
    </select>

    <!-- Status -->
    <select name="ativo"
            style="height:40px;border:1px solid #DFDFDF;border-radius:4px;padding:0 12px;font-size:14px;color:#141922;min-width:120px;outline:none"
            onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      <option value="todos" <?= ($filtros['ativo'] ?? '') === 'todos' || ($filtros['ativo'] ?? '') === '' ? 'selected' : '' ?>>Todos os status</option>
      <option value="publicado" <?= ($filtros['ativo'] ?? '') === 'publicado' ? 'selected' : '' ?>>Publicados</option>
      <option value="rascunho" <?= ($filtros['ativo'] ?? '') === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
      <option value="teste" <?= ($filtros['ativo'] ?? '') === 'teste' ? 'selected' : '' ?>>Em teste</option>
      <option value="inativo" <?= ($filtros['ativo'] ?? '') === 'inativo' ? 'selected' : '' ?>>Inativos</option>
    </select>

    <?php // Botão único: sem filtro aplicado ele filtra; com filtro aplicado ele
          // limpa. Para trocar de recorte: Limpar → escolher → Filtrar. ?>
    <?php if ($temFiltroAtivo): ?>
    <a href="?module=templates&amp;action=index&amp;limpar=1" title="Remover os filtros aplicados"
       style="height:40px;padding:0 20px;background:#EAEAEA;color:#606060;border-radius:4px;font-size:14px;font-weight:700;display:inline-flex;align-items:center;gap:6px;text-decoration:none">
      <i data-lucide="x" width="15" height="15"></i> Limpar
    </a>
    <?php else: ?>
    <button type="submit"
            style="height:40px;padding:0 20px;background:#2347C8;color:#fff;border:none;border-radius:4px;font-size:14px;font-weight:700;cursor:pointer">
      Filtrar
    </button>
    <?php endif; ?>
  </form>
</div>

<!-- Tabela -->
<?php if (empty($templates)): ?>
<?php
$emptyIcon    = 'layout-template';
$emptyTitle   = 'Nenhum template cadastrado';
$emptyMessage = $temFiltroAtivo
    ? 'Nenhum template encontrado com os filtros aplicados.'
    : 'Templates definem o fluxo de aprovação.';
$emptyAction  = !$temFiltroAtivo
    ? ['label' => 'Novo Template', 'url' => '?module=templates&action=novo', 'icon' => 'plus']
    : null;
include VIEWS . '/components/empty-state.php';
?>
<?php else: ?>
<div class="card" style="overflow:hidden; padding:0;">
  <table class="table" style="width:100%; border-collapse:collapse;">
    <thead>
      <tr>
        <th style="width:28%;">Nome</th>
        <th style="width:14%;">Departamento</th>
        <th style="width:13%;">Status</th>
        <th style="width:13%;">Versão pub.</th>
        <th style="width:10%;">Criado em</th>
        <th style="width:22%; text-align:right;">Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($templates as $t): ?>
      <?php $statusInfo = $statusLabels[$t['status_efetivo']] ?? ['label' => $t['status_efetivo'], 'class' => 'badge-muted']; ?>
      <tr onclick="window.location='?module=templates&action=editor&id=<?= h($t['id']) ?>'" style="cursor:pointer;">
        <td>
          <div style="font-family:var(--font-bold);font-size:var(--text-body);color:var(--color-dark);"><?= h($t['nome']) ?></div>
          <?php if ($t['versao_rascunho_id'] && $t['versao_publicada_id']): ?>
          <div style="font-family:var(--font-regular);font-size:11px;color:var(--color-warning);margin-top:2px;">
            <i data-lucide="alert-circle" width="11" height="11" style="vertical-align:middle;"></i>
            Rascunho v<?= h($t['versao_rascunho_numero']) ?> em andamento
          </div>
          <?php endif; ?>
        </td>
        <td style="font-size:13px; color:#606060;">
          <?= h($t['setor_nome'] ?? '—') ?>
          <?php if ($t['tem_financeiro']): ?>
          <span style="display:inline-block; margin-left:4px; font-size:10px; font-weight:700; background:#D8F5E5; color:#00A960; padding:1px 6px; border-radius:8px">$</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge <?= h($statusInfo['class']) ?>"><?= h($statusInfo['label']) ?></span>
          <?php if (isset($t['ativo']) && (int) $t['ativo'] === 0): ?>
          <span class="badge badge-danger" style="margin-left:4px"
                title="Fluxo desativado — não aceita processos novos. Os já abertos seguem normalmente.<?php
                    if (!empty($t['desativado_em'])) {
                        echo ' Desativado em ' . date('d/m/Y', strtotime($t['desativado_em'])) . '.';
                    } ?>">Inativo</span>
          <?php endif; ?>
        </td>
        <td style="font-family:var(--font-regular);font-size:var(--text-body-sm);color:var(--color-gray-dark);">
          <?= $t['versao_publicada_id'] ? 'v' . h($t['versao_publicada_numero']) : '—' ?>
        </td>
        <td style="font-family:var(--font-regular);font-size:var(--text-body-sm);color:var(--color-gray-medium);">
          <?= date('d/m/Y', strtotime($t['criado_em'])) ?>
        </td>
        <td style="text-align:right;" onclick="event.stopPropagation()">
          <div style="display:flex; gap:6px; justify-content:flex-end;">
            <button type="button"
                    onclick="duplicarTemplate('<?= h($t['id']) ?>', '<?= h(addslashes($t['nome'])) ?>')"
                    style="font-size:12px; padding:6px 12px; background:none; border:1px solid #DFDFDF; border-radius:4px; color:#888; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:4px; white-space:nowrap"
                    onmouseover="this.style.borderColor='#2347C8';this.style.color='#2347C8'" onmouseout="this.style.borderColor='#DFDFDF';this.style.color='#888'">
              <i data-lucide="copy" width="13" height="13"></i>
              Duplicar
            </button>
            <a href="?module=templates&action=editor&id=<?= h($t['id']) ?>"
               class="btn btn-secondary" style="font-size:12px;padding:6px 12px;">
              <i data-lucide="edit-2" width="13" height="13"></i>
              Editar
            </a>
            <?php if (Permissions::isSuperAdmin()): ?>
            <?php
              $inativo = isset($t['ativo']) && (int) $t['ativo'] === 0;
              // Form PRÓPRIO por linha, com submit de verdade. A primeira versão
              // usava um form oculto compartilhado + função JS, e no navegador do
              // usuário o clique simplesmente não fazia nada — sem depender de
              // getElementById nem de a função ter sido definida, some a classe
              // inteira de falha. A confirmação vai no onsubmit: se o JS estiver
              // fora do ar, o form envia direto em vez de não fazer nada.
              // Aspas tipográficas: a aspa reta viraria &quot; no atributo e
              // apareceria escapada dentro do diálogo.
              $confirmMsg = $inativo
                ? 'Reativar o fluxo “' . $t['nome'] . '”?\n\nEle volta a aceitar a abertura de processos novos.'
                : 'Desativar o fluxo “' . $t['nome'] . '”?\n\nNinguém poderá abrir processo novo por ele.\nOs processos em andamento seguem normalmente, e o fluxo pode ser reativado depois.';
            ?>
            <form method="post" action="?module=templates&amp;action=toggle_ativo"
                  style="display:inline-flex; margin:0"
                  onsubmit="return confirm('<?= h(str_replace("'", "\\'", $confirmMsg), ENT_QUOTES) ?>')">
              <?= Csrf::field() ?>
              <input type="hidden" name="template_id" value="<?= h($t['id']) ?>">
              <input type="hidden" name="ativo" value="<?= $inativo ? '1' : '0' ?>">
              <button type="submit"
                      title="<?= $inativo
                          ? 'Reativar o fluxo — volta a aceitar processos novos'
                          : 'Desativar o fluxo — para de aceitar processos novos' ?>"
                      style="font-size:12px; padding:6px 12px; background:none; border:1px solid <?= $inativo ? '#00A960' : '#DFDFDF' ?>; border-radius:4px; color:<?= $inativo ? '#00A960' : '#888' ?>; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:4px; white-space:nowrap"
                      onmouseover="this.style.borderColor='<?= $inativo ? '#00A960' : '#FF8A2D' ?>';this.style.color='<?= $inativo ? '#00A960' : '#FF8A2D' ?>'"
                      onmouseout="this.style.borderColor='<?= $inativo ? '#00A960' : '#DFDFDF' ?>';this.style.color='<?= $inativo ? '#00A960' : '#888' ?>'">
                <i data-lucide="<?= $inativo ? 'play-circle' : 'ban' ?>" width="13" height="13"></i>
                <?= $inativo ? 'Reativar' : 'Desativar' ?>
              </button>
            </form>
            <?php endif; ?>
            <?php if (empty($t['versao_publicada_id'])): ?>
            <button type="button"
                    onclick="excluirTemplate('<?= h($t['id']) ?>', '<?= h(addslashes($t['nome'])) ?>')"
                    title="Excluir template"
                    style="font-size:12px; padding:6px 12px; background:none; border:1px solid #DFDFDF; border-radius:4px; color:#888; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:4px; white-space:nowrap"
                    onmouseover="this.style.borderColor='#B3261E';this.style.color='#B3261E'" onmouseout="this.style.borderColor='#DFDFDF';this.style.color='#888'">
              <i data-lucide="trash-2" width="13" height="13"></i>
              Excluir
            </button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Paginação -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-top:20px;">
  <span style="font-family:var(--font-regular);font-size:var(--text-body-sm);color:var(--color-gray-medium);">
    Mostrando <?= count($templates) ?> de <?= $total ?> template(s)
  </span>
  <div style="display:flex;gap:4px;">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <?php
    $paginaParams = array_filter([
        'module'         => 'templates',
        'action'         => 'index',
        'page'           => $p,
        'q'              => $filtros['busca'] ?? '',
        'setor_id'       => $filtros['setor_id'] ?? '',
        'tipo_nota_id'   => $filtros['tipo_nota_id'] ?? '',
        'tem_financeiro' => $filtros['tem_financeiro'] ?? '',
        'ativo'          => ($filtros['ativo'] ?? '') !== 'todos' ? ($filtros['ativo'] ?? '') : '',
    ]);
    ?>
    <a href="?<?= http_build_query($paginaParams) ?>"
       style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:4px;
              border:1px solid <?= $p === $page ? '#2347C8' : '#EAEAEA' ?>;
              background:<?= $p === $page ? '#2347C8' : '#fff' ?>;
              color:<?= $p === $page ? '#fff' : '#606060' ?>;
              font-family:var(--font-bold);font-size:12px;text-decoration:none;">
      <?= $p ?>
    </a>
    <?php endfor; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Form oculto: excluir template -->
<form id="form-excluir-template" method="post" action="?module=templates&amp;action=excluir" style="display:none">
  <?= Csrf::field() ?>
  <input type="hidden" name="template_id" value="">
</form>


<!-- Modal de duplicação -->
<div id="modal-duplicar" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center">
  <div style="background:#fff; border-radius:12px; width:100%; max-width:440px; margin:20px; box-shadow:0 8px 32px rgba(0,0,0,0.2)">
    <div style="padding:24px 24px 0; display:flex; align-items:center; justify-content:space-between">
      <h3 style="font-size:18px; font-weight:700; color:#141922; margin:0; display:flex; align-items:center; gap:8px">
        <i data-lucide="copy" width="20" height="20" style="color:#2347C8"></i>
        Duplicar Template
      </h3>
      <button type="button" onclick="fecharModalDuplicar()" style="background:none; border:none; cursor:pointer; color:#888; font-size:20px; padding:4px">×</button>
    </div>
    <form method="post" action="?module=templates&action=duplicar" id="form-duplicar">
      <?= Csrf::field() ?>
      <input type="hidden" name="template_id" id="dup-template-id">
      <div style="padding:20px 24px">
        <p style="font-size:13px; color:#888; margin:0 0 16px">Uma cópia será criada como rascunho com todas as etapas, campos e anexos do template original.</p>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
          Nome do novo template <span style="color:#B3261E">*</span>
        </label>
        <input type="text" name="novo_nome" id="dup-novo-nome" required maxlength="200"
               style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      </div>
      <div style="padding:0 24px 20px; display:flex; gap:8px; justify-content:flex-end">
        <button type="button" onclick="fecharModalDuplicar()"
                style="padding:10px 20px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">
          Cancelar
        </button>
        <button type="submit"
                style="display:inline-flex; align-items:center; gap:6px; padding:10px 20px; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer"
                onmouseover="this.style.background='#1B38A0'" onmouseout="this.style.background='#2347C8'">
          <i data-lucide="copy" width="14" height="14"></i>
          Duplicar
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function excluirTemplate(id, nome) {
  if (!confirm('Excluir o template "' + nome + '"?\n\nTodas as versões em rascunho/teste serão removidas. Templates publicados ou com processos vinculados não podem ser excluídos.')) return;
  var form = document.getElementById('form-excluir-template');
  form.querySelector('[name="template_id"]').value = id;
  form.submit();
}


function duplicarTemplate(id, nomeAtual) {
    document.getElementById('dup-template-id').value = id;
    document.getElementById('dup-novo-nome').value = 'Cópia de ' + nomeAtual;
    var modal = document.getElementById('modal-duplicar');
    modal.style.display = 'flex';
    var input = document.getElementById('dup-novo-nome');
    input.focus();
    input.select();
}
function fecharModalDuplicar() {
    document.getElementById('modal-duplicar').style.display = 'none';
}
// Modal fecha apenas pelos botões (sem clique-fora).
</script>
