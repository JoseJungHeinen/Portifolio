<?php
// @versao Grp_02 V_02_12/08/2026
/**
 * views/groups/detail.php
 * Formulário de criação / edição de Grupo de Aprovação
 *
 * Variáveis esperadas:
 *   $grupo       array|null  null = novo; array = edição [id, nome, descricao, setor_id, setor_nome, ativo, membros]
 *   $setores     array       lista de setores [id, nome, tipo]
 *   $currentUser array       [id, nome, perfil]
 *   $flash       array|null  [type, message]
 */

$grupo   = $grupo   ?? null;
$setores = $setores ?? [];
$isEdit  = !empty($grupo['id']);
$nomePagina = $isEdit ? h($grupo['nome']) : 'Novo Grupo';
$membros = $grupo['membros'] ?? [];
$totalMembros = count($membros);

$pageTitle = $isEdit ? $grupo['nome'] : 'Novo Grupo';
$activeModule = 'grupos';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$avatarCores = ['#606060', '#2347C8', '#00A960', '#FF8A2D'];
?>

<?php if ($isEdit): ?>
<input type="hidden" id="grupo-id-hidden" value="<?= h($grupo['id']) ?>">
<?php endif; ?>
<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">

<?php include VIEWS . '/components/alert.php'; ?>

<!-- Breadcrumb -->
<div style="margin-bottom:20px; display:flex; align-items:center; gap:8px; font-size:14px; color:#888">
  <a href="?module=grupos&action=index"
     style="display:inline-flex; align-items:center; gap:6px; color:#2347C8; text-decoration:none; font-weight:700">
    <i data-lucide="arrow-left" width="15" height="15"></i>
    Grupos
  </a>
  <span style="color:#EAEAEA">/</span>
  <span style="color:#141922; font-weight:600"><?= $nomePagina ?></span>
</div>

<!-- Page Header -->
<div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:28px; flex-wrap:wrap; gap:12px">
  <div>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
      <?php if ($isEdit): ?>
        <?= h($grupo['nome']) ?>
      <?php else: ?>
        Novo <span style="color:#2347C8">Grupo</span>
      <?php endif; ?>
    </h1>
    <?php if ($isEdit): ?>
    <p style="font-size:14px; color:#888; margin:0">
      Grupo de aprovação —
      <span id="members-count" style="font-weight:700; color:#141922"><?= $totalMembros ?> <?= $totalMembros === 1 ? 'membro' : 'membros' ?></span>
    </p>
    <?php else: ?>
    <p style="font-size:14px; color:#888; margin:0">Preencha os dados para criar um novo grupo</p>
    <?php endif; ?>
  </div>
  <div style="display:flex; gap:10px">
    <a href="?module=grupos&action=index"
       style="display:inline-flex; align-items:center; gap:6px; padding:10px 20px; border-radius:4px; background:#EAEAEA; color:#606060; font-size:14px; font-weight:700; text-decoration:none">
      <i data-lucide="x" width="15" height="15"></i>
      Cancelar
    </a>
    <button type="submit" form="form-grupo"
            style="display:inline-flex; align-items:center; gap:6px; padding:10px 20px; border-radius:4px; background:#2347C8; color:#fff; border:none; font-size:14px; font-weight:700; cursor:pointer">
      <i data-lucide="check" width="15" height="15"></i>
      <?= $isEdit ? 'Salvar Alterações' : 'Criar Grupo' ?>
    </button>
  </div>
</div>

<!-- Grid de duas colunas -->
<form id="form-grupo"
      method="POST"
      action="?module=grupos&action=<?= $isEdit ? 'atualizar' : 'salvar' ?>">

  <?= Csrf::field() ?>
  <?php if ($isEdit): ?>
  <input type="hidden" name="id" value="<?= h($grupo['id']) ?>">
  <?php endif; ?>

  <div class="groups-detail-grid">

    <!-- Card: Informações do Grupo -->
    <div style="background:#FFFFFF; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0px 2px 8px; padding:28px">
      <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 24px; display:flex; align-items:center; gap:8px">
        <i data-lucide="info" width="18" height="18" style="color:#2347C8"></i>
        Informações do Grupo
      </h2>

      <!-- Nome -->
      <div style="margin-bottom:20px">
        <label for="nome" style="display:block; font-size:13px; font-weight:700; color:#141922; margin-bottom:6px">
          Nome do Grupo <span style="color:#2347C8">*</span>
        </label>
        <input type="text" id="nome" name="nome"
               value="<?= h($grupo['nome'] ?? '') ?>"
               placeholder="Ex: Aprovação Financeira SP"
               required maxlength="100"
               class="form-input"
               style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; transition:border-color 0.2s"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      </div>

      <!-- Gestor do grupo (recebe alertas de SLA excedido) -->
      <div style="margin-bottom:20px">
        <label for="gestor_id" style="display:block; font-size:13px; font-weight:700; color:#141922; margin-bottom:6px">
          Gestor do grupo
        </label>
        <div style="position:relative">
          <select id="gestor_id" name="gestor_id"
                  style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 36px 0 12px; font-size:14px; color:#141922; appearance:none; box-sizing:border-box; background:#fff; transition:border-color 0.2s"
                  onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
            <option value="">— Sem gestor definido —</option>
            <?php foreach (($usuariosAtivos ?? []) as $u): ?>
            <option value="<?= h($u['id']) ?>" <?= (($grupo['gestor_id'] ?? '') == $u['id']) ? 'selected' : '' ?>>
              <?= h($u['nome']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <i data-lucide="chevron-down" width="16" height="16"
             style="position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#888; pointer-events:none"></i>
        </div>
        <div style="font-size:11px; color:#888; margin-top:4px">
          Recebe o e-mail quando uma etapa deste grupo exceder o SLA (5 min após o estouro; reforço a cada hora, das 8h às 18h).
        </div>
      </div>

      <!-- Descrição -->
      <div style="margin-bottom:24px">
        <label for="descricao" style="display:block; font-size:13px; font-weight:700; color:#141922; margin-bottom:6px">
          Descrição
        </label>
        <textarea id="descricao" name="descricao" rows="3" maxlength="500"
                  placeholder="Descreva o propósito deste grupo de aprovação..."
                  style="width:100%; border:1px solid #DFDFDF; border-radius:4px; padding:10px 12px; font-size:14px; color:#141922; resize:vertical; box-sizing:border-box; font-family:inherit; transition:border-color 0.2s"
                  onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'"><?= h($grupo['descricao'] ?? '') ?></textarea>
      </div>

      <!-- Status (toggle) -->
      <div style="display:flex; align-items:center; justify-content:space-between; padding-top:16px; border-top:1px solid #EAEAEA">
        <div>
          <div style="font-weight:700; font-size:14px; color:#141922">Status do grupo</div>
          <div id="status-label" style="font-size:12px; color:#888; margin-top:2px">
            <?php if ($grupo['ativo'] ?? true): ?>
              Ativo — aparece nas listas de aprovação
            <?php else: ?>
              Inativo — não recebe novas tarefas
            <?php endif; ?>
          </div>
        </div>
        <label style="position:relative; display:inline-block; width:44px; height:24px; cursor:pointer; flex-shrink:0">
          <input type="checkbox" id="toggle-ativo" name="ativo" value="1"
                 <?= ($grupo['ativo'] ?? true) ? 'checked' : '' ?>
                 style="opacity:0; width:0; height:0"
                 onchange="handleStatusToggle(this)">
          <span class="toggle-slider"></span>
        </label>
      </div>
    </div>

    <!-- Card: Membros -->
    <div style="background:#FFFFFF; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0px 2px 8px; padding:28px">
      <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 20px; display:flex; align-items:center; gap:8px">
        <i data-lucide="users" width="18" height="18" style="color:#2347C8"></i>
        Membros do Grupo
      </h2>

      <?php if ($isEdit): ?>
      <!-- Busca de membros -->
      <div style="margin-bottom:20px">
        <label style="display:block; font-size:13px; font-weight:700; color:#141922; margin-bottom:6px">
          Adicionar membro
        </label>
        <div class="member-search-container" style="position:relative">
          <div style="display:flex; align-items:center; gap:8px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; height:40px; background:#fff; transition:border-color 0.2s"
               id="search-wrapper">
            <i data-lucide="search" width="16" height="16" style="color:#888; flex-shrink:0"></i>
            <input type="text" id="member-search-input"
                   placeholder="Digite nome ou e-mail..."
                   autocomplete="off"
                   style="border:none; outline:none; flex:1; font-size:14px; color:#141922; background:transparent"
                   onfocus="document.getElementById('search-wrapper').style.borderColor='#2347C8'"
                   onblur="document.getElementById('search-wrapper').style.borderColor='#DFDFDF'">
          </div>
          <div id="member-search-results" class="member-dropdown" style="display:none">
            <!-- populado por JS -->
          </div>
        </div>
      </div>
      <?php else: ?>
      <div style="margin-bottom:20px; padding:12px 16px; background:#EEF2FD; border-radius:8px; border-left:3px solid #2347C8">
        <p style="font-size:13px; color:#606060; margin:0">
          <strong>Dica:</strong> Salve o grupo primeiro para poder adicionar membros.
        </p>
      </div>
      <?php endif; ?>

      <!-- Contador de membros -->
      <?php if (!empty($membros)): ?>
      <div style="font-size:13px; font-weight:700; color:#141922; margin-bottom:12px; display:flex; align-items:center; gap:6px">
        <i data-lucide="user-check" width="14" height="14" style="color:#00A960"></i>
        <span id="members-count"><?= $totalMembros ?> <?= $totalMembros === 1 ? 'membro' : 'membros' ?></span>
      </div>
      <?php endif; ?>

      <!-- Lista de membros -->
      <div id="members-list">
        <?php foreach ($membros as $i => $m):
          $cor = $avatarCores[$i % 4];
          $partes = explode(' ', trim($m['nome']));
          $ini = mb_strtoupper(mb_substr($partes[0], 0, 1) . (isset($partes[1]) ? mb_substr($partes[1], 0, 1) : ''));
        ?>
        <div class="member-item" data-usuario-id="<?= h($m['usuario_id']) ?>">
          <div class="member-avatar" style="background: <?= h($cor) ?>"><?= h($ini) ?></div>
          <div style="flex:1; min-width:0">
            <div style="font-weight:600; font-size:14px; color:#141922; white-space:nowrap; overflow:hidden; text-overflow:ellipsis">
              <?= h($m['nome']) ?>
            </div>
            <div style="font-size:12px; color:#888">
              <?= h(ucfirst($m['perfil'] ?? '')) ?>
              <?php if (!empty($m['filial_nome'])): ?>
                · <?= h($m['filial_nome']) ?>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($isEdit): ?>
          <button type="button"
                  class="member-remove-btn"
                  data-usuario-id="<?= h($m['usuario_id']) ?>"
                  title="Remover membro do grupo"
                  style="margin-left:auto; background:#fff; border:1px solid #EAEAEA; cursor:pointer; color:#999; width:30px; height:30px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; transition:all 0.15s; flex-shrink:0"
                  onmouseover="this.style.background='#FFE8EE';this.style.borderColor='#B3261E';this.style.color='#B3261E'"
                  onmouseout="this.style.background='#fff';this.style.borderColor='#EAEAEA';this.style.color='#999'">
            <i data-lucide="trash-2" width="15" height="15"></i>
          </button>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if (empty($membros)): ?>
        <div class="empty-state" style="padding:32px 16px; text-align:center; color:#888">
          <i data-lucide="users" width="36" height="36" style="display:block; margin:0 auto 10px; color:#EAEAEA"></i>
          <p style="font-size:14px; font-weight:600; margin:0 0 4px">Nenhum membro ainda</p>
          <small style="font-size:13px">
            <?= $isEdit ? 'Busque e adicione membros acima' : 'Salve o grupo primeiro para adicionar membros' ?>
          </small>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /.groups-detail-grid -->
</form>

<!-- groups.js NÃO entra aqui: o GroupController já injeta no <head> com ?v=filemtime
     e defer. Ter as duas tags carregava o arquivo DUAS vezes (URLs diferentes,
     o browser não deduplica), registrando dois listeners no #members-list — um
     clique na lixeira abria dois modais idênticos, e fechar o de cima revelava
     o de baixo. Era esse o "modal não fecha ao remover membro". -->
<script>
function handleStatusToggle(checkbox) {
    var label = document.getElementById('status-label');
    if (checkbox.checked) {
        label.textContent = 'Ativo — aparece nas listas de aprovação';
    } else {
        label.textContent = 'Inativo — não recebe novas tarefas';
    }
}

// ── Validação client-side — Grupos ───────────────────────────────────────────
(function () {
    'use strict';

    function showFieldError(input, msg) {
        input.style.borderColor = '#B3261E';
        var wrap = input.parentNode;
        var err = wrap.querySelector('.field-error');
        if (!err) {
            err = document.createElement('span');
            err.className = 'field-error';
            err.style.cssText = 'display:block;color:#B3261E;font-size:12px;margin-top:4px;';
            wrap.appendChild(err);
        }
        err.textContent = msg;
    }

    function clearFieldError(input) {
        input.style.borderColor = '';
        var wrap = input.parentNode;
        var err = wrap.querySelector('.field-error');
        if (err) err.remove();
    }

    var form = document.getElementById('form-grupo');
    if (!form) return;

    var inputNome = document.getElementById('nome');
    if (inputNome) {
        inputNome.addEventListener('input', function () { clearFieldError(this); });
    }

    form.addEventListener('submit', function (e) {
        var valid = true;

        if (inputNome) {
            var v = inputNome.value.trim();
            if (!v) {
                showFieldError(inputNome, 'O nome do grupo é obrigatório.');
                valid = false;
            } else if (v.length < 3) {
                showFieldError(inputNome, 'O nome deve ter ao menos 3 caracteres.');
                valid = false;
            }
        }

        if (!valid) {
            e.preventDefault();
            if (inputNome) inputNome.focus();
            return;
        }

        // Anti double-submit: desabilita o botão de salvar
        var btn = form.querySelector('[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.style.opacity = '0.7';
            btn.style.cursor  = 'not-allowed';
        }
    });
}());
</script>
