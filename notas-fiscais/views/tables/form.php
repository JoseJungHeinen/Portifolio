<?php
// @versao Tab_02 V_01_16/07/2026
/**
 * views/tables/form.php — Criar / Editar lista de referência
 *
 * Variáveis: $lista (null=criação | array=edição), $currentUser
 */

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$lista       = $lista ?? null;
$isEdit      = $lista !== null;
$isPreConfig = $isEdit && ($lista['tipo'] ?? '') === 'pre_configurada';
$formAction  = $isEdit ? '?module=tabelas&action=atualizar' : '?module=tabelas&action=salvar';
?>

<?php include VIEWS . '/components/alert.php'; ?>

<!-- Header -->
<div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:28px; flex-wrap:wrap; gap:12px">
  <div>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
      <?= $isEdit ? 'Editar' : 'Nova' ?> <span style="color:#2347C8">Lista</span>
    </h1>
    <p style="font-size:14px; color:#888; margin:0">
      <?= $isEdit ? 'Edite as informações da lista.' : 'Crie uma nova lista de referência.' ?>
    </p>
  </div>
  <div style="display:flex; gap:8px">
    <a href="<?= $isEdit ? '?module=tabelas&action=itens&id=' . h($lista['id']) : '?module=tabelas&action=index' ?>"
       class="btn btn-tertiary">
      <i data-lucide="arrow-left" width="15" height="15"></i>
      Cancelar
    </a>
    <button type="submit" form="form-lista" class="btn btn-primary">
      <i data-lucide="save" width="15" height="15"></i>
      Salvar
    </button>
  </div>
</div>

<form id="form-lista" method="post" action="<?= $formAction ?>">
  <?= Csrf::field() ?>
  <?php if ($isEdit): ?>
  <input type="hidden" name="id" value="<?= h($lista['id']) ?>">
  <?php endif; ?>

  <div class="card" style="padding:24px; max-width:600px;">
    <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 20px; display:flex; align-items:center; gap:8px">
      <i data-lucide="info" width="18" height="18" style="color:#2347C8"></i>
      Informações da lista
    </h2>

    <!-- Nome -->
    <div style="margin-bottom:16px">
      <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
        Nome da lista <span style="color:#B3261E">*</span>
      </label>
      <input type="text" name="nome" id="input-nome"
             value="<?= $isEdit ? h($lista['nome']) : '' ?>"
             <?= $isPreConfig ? 'readonly' : 'required' ?>
             placeholder="Ex: Tipos de nota, Centros de custo"
             style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
             onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      <?php if ($isPreConfig): ?>
      <div style="font-size:11px; color:#888; margin-top:4px; display:flex; align-items:center; gap:4px">
        <i data-lucide="lock" width="11" height="11"></i>
        Listas pré-configuradas não podem ser renomeadas.
      </div>
      <?php endif; ?>
    </div>

    <!-- Descrição -->
    <div>
      <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
        Descrição
      </label>
      <textarea name="descricao" rows="3"
                placeholder="Descreva o propósito desta lista..."
                style="width:100%; border:1px solid #DFDFDF; border-radius:4px; padding:8px 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none; resize:vertical"
                onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'"><?= $isEdit ? h($lista['descricao'] ?? '') : '' ?></textarea>
    </div>
  </div>
</form>
