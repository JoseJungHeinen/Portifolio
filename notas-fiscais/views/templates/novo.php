<?php
// @versao Tpl_03 V_01_16/07/2026
/**
 * views/templates/novo.php
 * Criação de template — campo de nome com design Exemplo.
 */
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$exemplos = [
    'Aprovação de NF de Serviços',
    'NF de Produto — Almoxarifado',
    'Locação de Equipamentos',
    'Importação Direta',
];
?>

<!-- Breadcrumb -->
<div style="display:flex;align-items:center;gap:6px;margin-bottom:20px;">
  <a href="?module=templates&action=index"
     style="display:flex;align-items:center;gap:5px;font-size:13px;color:#888888;text-decoration:none;"
     onmouseover="this.style.color='#2347C8'" onmouseout="this.style.color='#888888'">
    <i data-lucide="layout-template" width="13" height="13"></i>
    Templates
  </a>
  <i data-lucide="chevron-right" width="12" height="12" style="color:#CCCCCC;"></i>
  <span style="font-size:13px;color:#141922;font-weight:600;">Novo template</span>
</div>

<!-- Título bicolor -->
<h1 style="font-size:28px;font-weight:700;color:#141922;margin-bottom:6px;">
  Novo <span style="color:#2347C8;">Template</span>
</h1>
<p style="font-size:13px;color:#888888;margin-bottom:28px;">
  Defina um nome. Você configurará etapas, campos e regras financeiras no próximo passo.
</p>

<?php include VIEWS . '/components/alert.php'; ?>

<!-- Card principal -->
<div style="max-width:580px;">
  <div class="card" style="padding:0;overflow:hidden;">

    <!-- Faixa de acento vermelha no topo -->
    <div style="height:3px;background:#2347C8;"></div>

    <div style="padding:36px 40px 40px;">

      <!-- Cabeçalho do card -->
      <div style="display:flex;align-items:flex-start;gap:14px;margin-bottom:30px;">
        <div style="width:44px;height:44px;flex-shrink:0;border-radius:10px;background:#EEF2FD;
                    display:flex;align-items:center;justify-content:center;">
          <i data-lucide="layout-template" width="20" height="20" style="color:#2347C8;"></i>
        </div>
        <div>
          <p style="font-size:14px;font-weight:700;color:#141922;margin-bottom:3px;">
            Identificação do template
          </p>
          <p style="font-size:13px;color:#888888;line-height:1.5;">
            O nome aparecerá ao criar novos processos de NF.<br>
            Seja específico — usuários precisarão escolher entre vários templates.
          </p>
        </div>
      </div>

      <!-- Separador -->
      <div style="height:1px;background:#F0F0F0;margin-bottom:28px;"></div>

      <!-- Formulário -->
      <form method="post" action="?module=templates&action=criar" id="form-novo-template"
            autocomplete="off">
        <?= Csrf::field() ?>

        <div style="margin-bottom:24px;">

          <label for="input-nome"
                 style="display:block;font-size:11px;font-weight:700;color:#606060;
                        letter-spacing:0.6px;text-transform:uppercase;margin-bottom:8px;">
            Nome do template <span style="color:#2347C8;">*</span>
          </label>

          <!-- Wrapper do input + counter -->
          <div id="input-wrapper" style="position:relative;">
            <input type="text"
                   id="input-nome"
                   name="nome"
                   maxlength="80"
                   required
                   autofocus
                   placeholder="Ex: Aprovação de NF de Serviços"
                   style="width:100%;height:52px;padding:0 52px 0 16px;
                          font-size:15px;font-family:'Segoe UI',Arial,sans-serif;
                          color:#141922;background:#FFFFFF;
                          border:2px solid #E0E0E0;border-radius:6px;
                          outline:none;transition:border-color 0.15s, box-shadow 0.15s;">
            <span id="nome-counter"
                  style="position:absolute;right:14px;top:50%;transform:translateY(-50%);
                         font-size:11px;color:#C8C8C8;pointer-events:none;
                         font-family:'Segoe UI',Arial,sans-serif;transition:color 0.15s;">
              0/80
            </span>
          </div>

          <!-- Preview do nome digitado -->
          <div id="nome-preview"
               style="display:none;margin-top:10px;padding:10px 14px;
                      background:#F8F8F8;border-radius:6px;
                      align-items:center;gap:8px;border-left:3px solid #2347C8;">
            <i data-lucide="eye" width="13" height="13" style="color:#888888;flex-shrink:0;"></i>
            <span style="font-size:12px;color:#888888;">Prévia: </span>
            <span id="nome-preview-text"
                  style="font-size:13px;font-weight:700;color:#141922;"></span>
          </div>

          <p style="font-size:12px;color:#AAAAAA;margin-top:8px;">
            Use no mínimo 3 caracteres. Máximo 80.
          </p>
        </div>

        <!-- Ações -->
        <div style="display:flex;gap:8px;align-items:center;">
          <a href="?module=templates&action=index" class="btn btn-tertiary"
             style="white-space:nowrap;">
            <i data-lucide="x" width="14" height="14"></i>
            Cancelar
          </a>
          <button type="submit" id="btn-criar-template" class="btn btn-primary"
                  style="flex:1;justify-content:center;gap:8px;">
            Criar e configurar
            <i data-lucide="arrow-right" width="15" height="15"></i>
          </button>
        </div>

      </form>
    </div>
  </div>

  <!-- Chips de sugestão abaixo do card -->
  <div style="margin-top:14px;display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
    <span style="font-size:11px;color:#AAAAAA;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;white-space:nowrap;">
      Sugestões:
    </span>
    <?php foreach ($exemplos as $ex): ?>
    <button type="button" class="sugestao-chip"
            data-nome="<?= h($ex) ?>"
            style="font-size:12px;padding:5px 12px;border-radius:20px;
                   border:1px solid #E0E0E0;background:#FFFFFF;color:#606060;
                   cursor:pointer;font-family:'Segoe UI',Arial,sans-serif;
                   transition:border-color 0.15s,color 0.15s,background 0.15s;">
      <?= h($ex) ?>
    </button>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function () {
  'use strict';

  var input       = document.getElementById('input-nome');
  var wrapper     = document.getElementById('input-wrapper');
  var counter     = document.getElementById('nome-counter');
  var preview     = document.getElementById('nome-preview');
  var previewText = document.getElementById('nome-preview-text');
  var form        = document.getElementById('form-novo-template');
  if (!input || !form) return;

  // ── Estados visuais do input ─────────────────────────
  function setInputState(state) {
    var styles = {
      default: { border: '#E0E0E0', shadow: 'none' },
      focus:   { border: '#2347C8', shadow: '0 0 0 3px rgba(35,71,200,0.08)' },
      error:   { border: '#B3261E', shadow: '0 0 0 3px rgba(248,12,91,0.08)' },
    };
    var s = styles[state] || styles.default;
    input.style.borderColor = s.border;
    input.style.boxShadow   = s.shadow;
  }

  input.addEventListener('focus', function () { setInputState('focus'); });
  input.addEventListener('blur',  function () {
    if (input.dataset.hasError !== 'true') setInputState('default');
  });

  // ── Counter + preview ────────────────────────────────
  input.addEventListener('input', function () {
    var len = this.value.length;
    counter.textContent = len + '/80';
    counter.style.color = len > 70 ? '#FF8A2D' : '#C8C8C8';

    var val = this.value.trim();
    preview.style.display   = val ? 'flex' : 'none';
    previewText.textContent = val;

    // Limpar erro se existir
    if (input.dataset.hasError === 'true') {
      input.dataset.hasError = 'false';
      setInputState('focus');
      var err = wrapper.querySelector('.field-error');
      if (err) err.remove();
    }
  });

  // ── Chips de sugestão ────────────────────────────────
  document.querySelectorAll('.sugestao-chip').forEach(function (btn) {
    btn.addEventListener('mouseenter', function () {
      this.style.borderColor = '#2347C8';
      this.style.color       = '#2347C8';
      this.style.background  = '#EEF2FD';
    });
    btn.addEventListener('mouseleave', function () {
      this.style.borderColor = '#E0E0E0';
      this.style.color       = '#606060';
      this.style.background  = '#FFFFFF';
    });
    btn.addEventListener('click', function () {
      input.value = this.dataset.nome;
      input.focus();
      input.dispatchEvent(new Event('input'));
    });
  });

  // ── Validação + anti double-submit ───────────────────
  function showError(msg) {
    var err = wrapper.querySelector('.field-error');
    if (!err) {
      err = document.createElement('span');
      err.className = 'field-error';
      err.style.cssText = 'display:block;color:#B3261E;font-size:12px;margin-top:6px;font-family:"Segoe UI",Arial,sans-serif;';
      wrapper.appendChild(err);
    }
    err.textContent = msg;
    input.dataset.hasError = 'true';
    setInputState('error');
  }

  form.addEventListener('submit', function (e) {
    var v = input.value.trim();
    if (!v) {
      e.preventDefault(); showError('O nome do template é obrigatório.'); input.focus(); return;
    }
    if (v.length < 3) {
      e.preventDefault(); showError('Use ao menos 3 caracteres.'); input.focus(); return;
    }
    var btn = document.getElementById('btn-criar-template');
    if (btn) {
      btn.disabled      = true;
      btn.style.opacity = '0.7';
      btn.style.cursor  = 'not-allowed';
    }
  });
}());
</script>
