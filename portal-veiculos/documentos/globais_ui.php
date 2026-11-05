<?php
declare(strict_types=1);

require_once __DIR__ . '/globais_helpers.php';

/**
 * Requisitos: $mysqli, $templateSelecionado, $tipos
 */

// ---------------------
// Carregar globais
// ---------------------
$globais = [];
$resG = mysqli_query(
    $mysqli,
    "SELECT g.*, l.nome AS lista_nome
     FROM documento_variaveis_globais g
     LEFT JOIN documento_listas l ON l.id = g.lista_id
     ORDER BY g.rotulo ASC"
);
if ($resG instanceof mysqli_result) {
    while ($rowG = $resG->fetch_assoc()) {
        $globais[] = $rowG;
    }
    $resG->free();
}

// ---------------------
// Carregar vínculos do template selecionado (inclui obrigatorio + ordem por template)
// ---------------------
$vinculos = []; // [global_id] => ['vinculado'=>bool,'obrigatorio'=>bool,'ordem'=>int]
if (!empty($templateSelecionado)) {
    $tid = (int)$templateSelecionado['id'];
    $stmt = $mysqli->prepare("SELECT global_id, obrigatorio, ordem FROM documento_template_globais WHERE template_id=?");
    if ($stmt) {
        $stmt->bind_param('i', $tid);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($rw = $r->fetch_assoc()) {
            $gid = (int)$rw['global_id'];
            $vinculos[$gid] = [
                'vinculado'   => true,
                'obrigatorio' => !empty($rw['obrigatorio']),
                'ordem'       => isset($rw['ordem']) ? (int)$rw['ordem'] : 0,
            ];
        }
        $stmt->close();
    }
}

// ---------------------
// Edit global (opcional)
// ---------------------
$editGlobal = null;
if (!empty($_GET['edit_global_id'])) {
    $gid = (int)$_GET['edit_global_id'];
    foreach ($globais as $g) {
        if ((int)$g['id'] === $gid) { $editGlobal = $g; break; }
    }
}
?>

<div class="card">
  <div class="card-header">
    <h3 class="card-title">🌐 Variáveis Globais (reutilizáveis)</h3>
    <span class="badge badge-info"><?= count($globais) ?> cadastradas</span>
    <?php if (!empty($templateSelecionado)): ?>
      <span class="badge badge-warning" style="margin-left:auto;">
        TEMPLATE: <?= htmlspecialchars((string)$templateSelecionado['nome'], ENT_QUOTES, 'UTF-8') ?>
      </span>
    <?php endif; ?>
  </div>

  <p class="card-subtitle">
    Variáveis globais podem ser reutilizadas em vários templates. O placeholder no Word será <code>{{CHAVE}}</code>.
  </p>

  <!-- Formulário de cadastro/edição -->
  <form method="post" style="margin-top:10px;">
    <input type="hidden" name="acao" value="salvar_global">
    <input type="hidden" name="global_id" value="<?= $editGlobal ? (int)$editGlobal['id'] : 0 ?>">
    <input type="hidden" name="template_id" value="<?= !empty($templateSelecionado) ? (int)$templateSelecionado['id'] : 0 ?>">

    <div class="row">
      <div class="col-6">
        <div class="form-group">
          <label class="form-label">Rótulo *</label>
          <input
            type="text"
            class="form-control"
            name="global_rotulo"
            value="<?= $editGlobal ? htmlspecialchars((string)$editGlobal['rotulo'], ENT_QUOTES, 'UTF-8') : '' ?>"
            placeholder="Ex: Nome do Cliente"
            required
          >
          <small class="form-text">Exibido para o usuário na tela de preenchimento.</small>
        </div>
      </div>

      <div class="col-6">
        <div class="form-group">
          <label class="form-label">Chave (placeholder)</label>
          <input
            type="text"
            class="form-control"
            name="global_chave"
            value="<?= $editGlobal ? htmlspecialchars((string)$editGlobal['chave'], ENT_QUOTES, 'UTF-8') : '' ?>"
            placeholder="Ex: NOME_CLIENTE"
          >
          <small class="form-text">Se deixar em branco, a chave será gerada automaticamente a partir do rótulo.</small>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col-6">
        <div class="form-group">
          <label class="form-label">Tipo</label>
          <select class="form-control" name="global_tipo">
            <?php foreach ($tipos as $valor => $label): ?>
              <option
                value="<?= htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8') ?>"
                <?= $editGlobal && (($editGlobal['tipo'] ?? '') === $valor) ? 'selected' : '' ?>
              >
                <?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="col-6">
        <div class="form-group">
          <label class="form-label">Lista (opcional)</label>
          <select name="global_lista_id" class="form-control">
            <option value="">— Nenhuma —</option>
            <?php foreach (($listas ?? []) as $l): ?>
              <option
                value="<?= (int)$l['id'] ?>"
                <?= ($editGlobal && (int)($editGlobal['lista_id'] ?? 0) === (int)$l['id']) ? 'selected' : '' ?>
              >
                <?= htmlspecialchars((string)$l['nome'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="form-text">Use uma lista reutilizável para popular campos do tipo lista/checkbox/radio.</small>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2" style="flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary">
        <?= $editGlobal ? '💾 Salvar Global' : '➕ Criar Global' ?>
      </button>

      <?php if ($editGlobal): ?>
        <a
          class="btn btn-outline"
          href="config_documentos.php?aba=campos&template_id=<?= !empty($templateSelecionado) ? (int)$templateSelecionado['id'] : 0 ?>"
        >
          Cancelar
        </a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Tabela única (globais + vínculo) -->
  <div style="margin-top:16px;">
    <?php if (empty($globais)): ?>
      <div class="alert alert-info">Nenhuma variável global cadastrada ainda.</div>
    <?php else: ?>

      <?php if (!empty($templateSelecionado)): ?>
        <p class="card-subtitle" style="margin-top:0;">
          Nesta tabela você também configura o uso das globais no template selecionado:
          <strong>Usar</strong>, <strong>Obrigatório</strong> e <strong>Ordem</strong> (a ordem só fica habilitada quando “Usar” estiver marcado).
        </p>
      <?php else: ?>
        <p class="card-subtitle" style="margin-top:0;">
          Selecione um template para habilitar as opções <strong>Usar</strong>, <strong>Obrigatório</strong> e <strong>Ordem</strong> nesta tabela.
        </p>
      <?php endif; ?>

      <!-- Form de salvar configurações do template (sem envolver a tabela para evitar form aninhado) -->
      <?php if (!empty($templateSelecionado)): ?>
        <form id="formVinculosGlobais" method="post" style="margin:0;">
          <input type="hidden" name="acao" value="salvar_vinculos_globais">
          <input type="hidden" name="template_id" value="<?= (int)$templateSelecionado['id'] ?>">
        </form>
      <?php endif; ?>

      <div class="table-responsive">
        <table class="tabela-globais tabela-globais-unica" style="width:100%;">
          <thead>
            <tr>
              <th style="width:70px;">Usar</th>
              <th style="width:140px;">Obrigatório</th>
              <th style="width:110px;">Ordem</th>
              <th>Rótulo</th>
              <th style="width:160px;">Tipo</th>
              <th style="width:120px;">Lista</th>
              <th style="width:360px;">Placeholder</th>
              <th class="text-center" style="width:180px;">Ações</th>
            </tr>
          </thead>

          <tbody>
            <?php foreach ($globais as $g): ?>
              <?php
                $gid = (int)$g['id'];
                $isVinc = !empty($vinculos[$gid]['vinculado']);
                $isObrigTpl = isset($vinculos[$gid]) ? !empty($vinculos[$gid]['obrigatorio']) : false;
                $ordemTpl = isset($vinculos[$gid]) ? (int)($vinculos[$gid]['ordem'] ?? 0) : 0;

                $chave = strtoupper(trim((string)($g['chave'] ?? '')));
                $ph = '{{' . $chave . '}}';
                $tipoLabel = $tipos[$g['tipo']] ?? ($g['tipo'] ?? '');
                $hasTemplate = !empty($templateSelecionado);
              ?>

              <tr class="<?= $isVinc ? 'is-selected' : '' ?>">
                <td>
                  <input
                    type="checkbox"
                    <?= $hasTemplate ? 'form="formVinculosGlobais"' : '' ?>
                    class="js-usar-global"
                    name="globais[]"
                    value="<?= $gid ?>"
                    <?= ($hasTemplate && $isVinc) ? 'checked' : '' ?>
                    <?= !$hasTemplate ? 'disabled' : '' ?>
                  >
                </td>

                <td>
                  <label style="display:inline-flex; align-items:center; gap:6px;">
                    <input
                      type="checkbox"
                      <?= $hasTemplate ? 'form="formVinculosGlobais"' : '' ?>
                      class="js-obrig-global"
                      name="globais_obrig[<?= $gid ?>]"
                      value="1"
                      <?= ($hasTemplate && $isObrigTpl) ? 'checked' : '' ?>
                      <?= (!$hasTemplate || !$isVinc) ? 'disabled' : '' ?>
                    >
                    <span>Obrigatório</span>
                  </label>
                </td>

                <td>
                  <input
                    type="number"
                    <?= $hasTemplate ? 'form="formVinculosGlobais"' : '' ?>
                    class="form-control form-control-sm js-ordem-global"
                    name="ordem_global[<?= $gid ?>]"
                    value="<?= $ordemTpl ?>"
                    min="0"
                    style="width:80px;"
                    <?= (!$hasTemplate || !$isVinc) ? 'disabled' : '' ?>
                  >
                </td>

                <td><strong><?= htmlspecialchars((string)$g['rotulo'], ENT_QUOTES, 'UTF-8') ?></strong></td>

                <td><?= htmlspecialchars((string)$tipoLabel, ENT_QUOTES, 'UTF-8') ?></td>

                <td><?= !empty($g['lista_nome']) ? htmlspecialchars((string)$g['lista_nome'], ENT_QUOTES, 'UTF-8') : '—' ?></td>

                <td>
                  <div class="copy-box" style="min-width:260px;">
                    <code><?= htmlspecialchars($ph, ENT_QUOTES, 'UTF-8') ?></code>
                    <button type="button" class="copy-btn" data-text="<?= htmlspecialchars($ph, ENT_QUOTES, 'UTF-8') ?>">Copiar</button>
                  </div>
                  <small class="form-text">Copie este placeholder e cole no Word.</small>
                </td>

                <td class="text-center">
                  <div class="actions-group">
                    <a
                      class="action-btn action-edit"
                      href="config_documentos.php?aba=campos&template_id=<?= $hasTemplate ? (int)$templateSelecionado['id'] : 0 ?>&edit_global_id=<?= $gid ?>"
                    >
                      Editar
                    </a>

                    <form method="post" style="display:inline;" onsubmit="return confirm('Excluir esta variável global?');">
                      <input type="hidden" name="acao" value="excluir_global">
                      <input type="hidden" name="global_id" value="<?= $gid ?>">
                      <input type="hidden" name="template_id" value="<?= $hasTemplate ? (int)$templateSelecionado['id'] : 0 ?>">
                      <button type="submit" class="action-btn action-danger">Excluir</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if (!empty($templateSelecionado)): ?>
        <div style="margin-top:14px;">
          <button type="submit" form="formVinculosGlobais" class="btn btn-success">
            💾 Salvar configurações do template
          </button>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  function toggleRow(row){
    var usar = row.querySelector('.js-usar-global');
    var obrig = row.querySelector('.js-obrig-global');
    var ordem = row.querySelector('.js-ordem-global');
    if(!usar || !obrig || !ordem) return;

    var enabled = !usar.disabled && usar.checked;
    obrig.disabled = !enabled;
    ordem.disabled = !enabled;
    row.classList.toggle('is-selected', enabled);

    if(!enabled){
      obrig.checked = false;
    }
  }

  document.querySelectorAll('.tabela-globais-unica tbody tr').forEach(function(row){
    toggleRow(row);
    var usar = row.querySelector('.js-usar-global');
    if(usar){
      usar.addEventListener('change', function(){ toggleRow(row); });
    }
  });
})();
</script>
