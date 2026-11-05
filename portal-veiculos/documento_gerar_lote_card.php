<?php
// documento_gerar_lote_card.php
?>

<style>
/* ===========================
   Card Lote — visual moderno
   =========================== */

.page-container { max-width: 980px !important; }

.lote-section { margin-top: 10px; }

.lote-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}

.lote-title {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
    color: #111;
    line-height: 1.2;
}

.lote-title .icon {
    width: 36px; height: 36px; min-width: 36px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    background: rgba(204,0,0,.10);
    border: 1px solid rgba(204,0,0,.18);
    font-size: 1rem;
}

.lote-sub {
    margin-top: 5px;
    color: #888;
    font-size: .83rem;
    line-height: 1.4;
}

/* caixa de templates */
.lote-box {
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 12px;
    background: #fafafa;
}

.lote-templates {
    display: grid;
    grid-template-columns: 1fr;
    gap: 8px;
}
@media (min-width: 640px) {
    .lote-templates { grid-template-columns: 1fr 1fr; }
}

/* card de template */
.tpl-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #fff;
    cursor: pointer;
    transition: background .12s, border-color .12s, box-shadow .12s, transform .06s;
    user-select: none;
    min-height: 46px;
}
.tpl-card:hover {
    background: #fff;
    border-color: #cc0000;
    box-shadow: 0 2px 12px rgba(204,0,0,.1);
}
.tpl-card:active { transform: translateY(1px); }

.tpl-card input[type="checkbox"] {
    width: 17px; height: 17px;
    accent-color: #cc0000;
    flex: 0 0 auto;
}

.tpl-text { min-width: 0; flex: 1 1 auto; }

.tpl-name {
    font-weight: 700;
    color: #111;
    font-size: .88rem;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ações */
.lote-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-top: 14px;
    flex-wrap: wrap;
}
.lote-actions .btn-primary { flex: 1; min-width: 200px; }
.lote-actions .btn-secondary { margin-top: 0 !important; white-space: nowrap; }

.lote-tip {
    margin-top: 8px;
    font-size: .8rem;
    color: #aaa;
}

.lote-msg { margin-top: 10px; }
.lote-campos { margin-top: 16px; }

/* seções de campos */
.lote-fields-section {
    margin-top: 14px;
    border: 1px solid #e5e7eb;
    background: #fff;
    border-radius: 14px;
    overflow: hidden;
}

.lote-fields-head {
    padding: 10px 14px;
    border-bottom: 1px solid #f0f0f0;
    background: #fafafa;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.lote-fields-head strong { font-size: .88rem; color: #111; }
.lote-fields-head span  { font-size: .78rem; color: #aaa; }

.lote-fields-body { padding: 14px; }
.lote-fields-body .field-row { margin-bottom: 12px; }
.lote-fields-body .field-row label { font-weight: 700; font-size: .83rem; color: #374151; }

/* inputs dentro do lote */
#lote-campos input[type="text"],
#lote-campos input[type="date"],
#lote-campos select,
#lote-campos textarea { border-radius: 12px; }

/* chips de opções */
#lote-campos .options-group {
    display: flex; flex-wrap: wrap; gap: 8px;
    padding: 10px;
    border: 1px dashed #e5e7eb;
    border-radius: 12px;
    background: #fafafa;
}
#lote-campos .options-group label {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 7px 12px; margin: 0;
    border: 1px solid #e0e0e0; border-radius: 999px;
    background: #fff; font-weight: 600; font-size: .83rem;
    cursor: pointer;
    transition: border-color .12s, background .12s;
}
#lote-campos .options-group label:hover { border-color: #cc0000; background: #fff8f8; }
#lote-campos .options-group input[type="radio"],
#lote-campos .options-group input[type="checkbox"] {
    width: 15px; height: 15px; accent-color: #cc0000;
}

/* botão gerar */
#btn-gerar-pdfs {
    width: 100%;
    justify-content: center;
    font-weight: 800;
    margin-top: 4px;
}

/* divisor */
.lote-divider {
    border: none;
    border-top: 1px solid #f0f0f0;
    margin: 1.4rem 0 1rem;
}
</style>

<hr class="lote-divider">

<div class="lote-section">
  <div class="lote-header">
    <div>
      <div class="lote-title">
        <span class="icon">📄</span>
        <div>
          <strong>Gerar documentos (fluxo único)</strong>
          <div class="lote-sub">
            Selecione 1 template para gerar 1 PDF ou selecione 2+ templates para gerar em lote.
          </div>
        </div>
      </div>

      <div class="lote-sub" style="margin-top:10px;">
        Campos <strong>globais</strong> aparecem uma vez só. Campos <strong>exclusivos</strong> aparecem por template.
      </div>
    </div>
  </div>
</div>

<form method="post" action="documento_gerar_lote.php" target="_blank" id="form-lote">
  <div class="form-group">
    <label>Templates para gerar</label>

    <div id="lote-lista-templates" class="lote-box">
      <div class="lote-templates">
        <?php foreach ($templates as $tpl): ?>
          <label class="tpl-card" title="<?= htmlspecialchars($tpl['nome'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="checkbox" name="template_ids[]" value="<?= (int)$tpl['id'] ?>">
            <div class="tpl-text">
              <div class="tpl-name"><?= htmlspecialchars($tpl['nome'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="lote-actions">
    <button type="button" id="btn-carregar-campos" class="btn-primary">
      🔄 Carregar campos
    </button>

    <button type="button" id="btn-limpar-lote" class="btn-secondary">
      🧹 Limpar
    </button>
  </div>

  <div class="lote-tip">
    Dica: selecione 2–5 templates para manter o processo rápido.
  </div>

  <div id="lote-msg" class="message error lote-msg" style="display:none;"></div>

  <div id="lote-campos" class="dynamic-fields lote-campos">
    <div class="message info">
      Selecione os templates e clique em <strong>Carregar campos</strong>.
    </div>
  </div>

  <button type="submit" id="btn-gerar-pdfs" class="btn-secondary" style="margin-top:12px;">
    📄 Gerar PDFs
  </button>
</form>

<script>
(function () {
  const btnCarregar = document.getElementById('btn-carregar-campos');
  const btnLimpar   = document.getElementById('btn-limpar-lote');
  const msgBox      = document.getElementById('lote-msg');
  const camposWrap  = document.getElementById('lote-campos');
  const formLote    = document.getElementById('form-lote');

  function showMsg(text) {
    msgBox.textContent = text;
    msgBox.style.display = 'block';
  }
  function hideMsg() {
    msgBox.textContent = '';
    msgBox.style.display = 'none';
  }

  function getSelectedTemplateIds() {
    const checks = formLote.querySelectorAll('input[name="template_ids[]"]:checked');
    const ids = [];
    checks.forEach(ch => ids.push(parseInt(ch.value, 10)));
    return ids.filter(v => Number.isFinite(v) && v > 0);
  }

  function esc(s) {
    return String(s)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function renderLoteCampos(data) {
    const globais = Array.isArray(data.globais) ? data.globais : [];
    const templates = Array.isArray(data.templates) ? data.templates : [];
    const camposPorTemplate = (data.campos_por_template && typeof data.campos_por_template === 'object')
      ? data.campos_por_template
      : {};

    let html = '';

    // Globais (sem mostrar placeholder)
    html += '<div class="lote-fields-section">';
    html += '  <div class="lote-fields-head"><strong>Campos globais</strong><span style="color:#6b7280;font-size:.82rem;">Preencha 1 vez</span></div>';
    html += '  <div class="lote-fields-body">';

    if (globais.length === 0) {
      html += '<div class="message info">Nenhuma variável global vinculada aos templates selecionados.</div>';
    } else {
      globais.forEach(g => {
        const chave = (g.chave || '').toUpperCase().trim();
        const obrig = g.obrigatorio ? ' *' : '';
        const tipo = (g.tipo || 'texto_curto');
        const opcoes = Array.isArray(g.opcoes) ? g.opcoes : [];

        html += '<div class="field-row">';
        html += '<label>' + esc(g.rotulo || chave) + obrig + '</label>';

        // Tipos com opções (select/enum/radio/checkbox/booleano)
        if ((tipo === 'select' || tipo === 'enum') && opcoes.length) {
          html += '<select ' +
            'name="globais[' + esc(chave) + ']" ' +
            'data-tipo="' + esc(tipo) + '" ' +
            'data-nome-campo="' + esc(chave) + '" ' +
            'data-obrigatorio="' + (g.obrigatorio ? '1' : '0') + '">';
          html += '<option value="">Selecione...</option>';
          opcoes.forEach(o => {
            html += '<option value="' + esc(o.valor) + '">' + esc(o.label || o.valor) + '</option>';
          });
          html += '</select>';

        } else if ((tipo === 'radio' || tipo === 'checkbox' || tipo === 'booleano') && (opcoes.length || tipo === 'booleano')) {
          const base = 'g_' + esc(chave);
          const inputType = (tipo === 'checkbox') ? 'checkbox' : 'radio';
          const nameAttr = (tipo === 'checkbox')
            ? 'globais[' + chave + '][]'
            : 'globais[' + chave + ']';

          const opts = opcoes.length ? opcoes : [
            {valor:'1', label:'Sim'},
            {valor:'0', label:'Não'}
          ];

          html += '<div class="options-group" ' +
            'data-tipo="' + esc(tipo) + '" ' +
            'data-nome-campo="' + esc(chave) + '" ' +
            'data-obrigatorio="' + (g.obrigatorio ? '1' : '0') + '">';
          opts.forEach((o, oi) => {
            const oid = base + '_' + oi;
            html += '<label for="' + oid + '">';
            html += '<input type="' + inputType + '" id="' + oid + '" name="' + esc(nameAttr) + '" value="' + esc(o.valor) + '"> ';
            html += esc(o.label || o.valor);
            html += '</label>';
          });
          html += '</div>';

        } else {
          // Demais tipos: input/textarea/date
          if (tipo === 'texto_longo' || tipo === 'observacao' || tipo === 'endereco') {
            html += '<textarea ' +
              'name="globais[' + esc(chave) + ']" ' +
              'data-tipo="' + esc(tipo) + '" ' +
              'data-nome-campo="' + esc(chave) + '" ' +
              'data-obrigatorio="' + (g.obrigatorio ? '1' : '0') + '"></textarea>';
          } else if (tipo === 'data') {
            html += '<input type="date" ' +
              'name="globais[' + esc(chave) + ']" ' +
              'data-tipo="data" ' +
              'data-nome-campo="' + esc(chave) + '" ' +
              'data-obrigatorio="' + (g.obrigatorio ? '1' : '0') + '">';
          } else {
            html += '<input type="text" ' +
              'name="globais[' + esc(chave) + ']" ' +
              'data-tipo="' + esc(tipo) + '" ' +
              'data-nome-campo="' + esc(chave) + '" ' +
              'data-obrigatorio="' + (g.obrigatorio ? '1' : '0') + '">';
          }
        }
        html += '</div>';
      });
    }

    html += '  </div>';
    html += '</div>';

    // Exclusivos por template
    templates.forEach(t => {
      const tid = t.id;
      const campos = Array.isArray(camposPorTemplate[tid]) ? camposPorTemplate[tid] : [];

      html += '<div class="lote-fields-section" style="margin-top:14px;">';
      html += '  <div class="lote-fields-head"><strong>Campos exclusivos — ' + esc(t.nome) + '</strong><span style="color:#6b7280;font-size:.82rem;">Somente neste template</span></div>';
      html += '  <div class="lote-fields-body">';

      if (campos.length === 0) {
        html += '<div class="message info">Nenhum campo exclusivo configurado para este template.</div>';
      } else {
        campos.forEach((c, idx) => {
          const nomeCampo = c.nome_campo;
          const obrig = c.obrigatorio ? ' *' : '';

          const tipo = c.tipo || 'texto_curto';
          const opcoes = Array.isArray(c.opcoes) ? c.opcoes : [];

          html += '<div class="field-row">';
          html += '<label>' + esc(c.rotulo || nomeCampo) + obrig + '</label>';

          if ((tipo === 'select' || tipo === 'enum') && opcoes.length) {
            html += '<select name="exclusivos[' + tid + '][' + esc(nomeCampo) + ']" data-obrigatorio="' + (c.obrigatorio ? '1' : '0') + '">';
            html += '<option value="">Selecione...</option>';
            opcoes.forEach(o => {
              html += '<option value="' + esc(o.valor) + '">' + esc(o.label || o.valor) + '</option>';
            });
            html += '</select>';

          } else if ((tipo === 'radio' || tipo === 'checkbox' || tipo === 'booleano') && (opcoes.length || tipo === 'booleano')) {
            const base = 'tpl_' + tid + '_c_' + idx;
            const inputType = (tipo === 'checkbox') ? 'checkbox' : 'radio';
            const nameAttr = (tipo === 'checkbox')
              ? 'exclusivos[' + tid + '][' + nomeCampo + '][]'
              : 'exclusivos[' + tid + '][' + nomeCampo + ']';

            const opts = opcoes.length ? opcoes : [
              {valor:'1', label:'Sim'},
              {valor:'0', label:'Não'}
            ];

            html += '<div class="options-group" data-obrigatorio="' + (c.obrigatorio ? '1' : '0') + '">';
            opts.forEach((o, oi) => {
              const oid = base + '_' + oi;
              html += '<label for="' + oid + '">';
              html += '<input type="' + inputType + '" id="' + oid + '" name="' + esc(nameAttr) + '" value="' + esc(o.valor) + '"> ';
              html += esc(o.label || o.valor);
              html += '</label>';
            });
            html += '</div>';

          } else if (tipo === 'texto_longo' || tipo === 'observacao' || tipo === 'endereco') {
            html += '<textarea ' +
        'name="exclusivos[' + tid + '][' + esc(nomeCampo) + ']" ' +
        'data-tipo="' + esc(tipo) + '" ' +
        'data-nome-campo="' + esc(nomeCampo) + '" ' +
        'data-obrigatorio="' + (c.obrigatorio ? '1' : '0') + '"></textarea>';
          } else if (tipo === 'data') {
            html += '<input type="date" ' +
        'name="exclusivos[' + tid + '][' + esc(nomeCampo) + ']" ' +
        'data-tipo="data" ' +
        'data-nome-campo="' + esc(nomeCampo) + '" ' +
        'data-obrigatorio="' + (c.obrigatorio ? '1' : '0') + '">';
          } else {
            html += '<input type="text" ' +
        'name="exclusivos[' + tid + '][' + esc(nomeCampo) + ']" ' +
        'data-tipo="' + esc(tipo) + '" ' +
        'data-nome-campo="' + esc(nomeCampo) + '" ' +
        'data-obrigatorio="' + (c.obrigatorio ? '1' : '0') + '">';
          }

          html += '</div>';
        });
      }

      html += '  </div>';
      html += '</div>';
    });

    camposWrap.innerHTML = html;

    if (window.attachMasks) { window.attachMasks(camposWrap); }
  }

  btnCarregar.addEventListener('click', async function () {
    hideMsg();

    const ids = getSelectedTemplateIds();
    if (!ids.length) {
      showMsg('Selecione ao menos 1 template.');
      return;
    }

    try {
      const params = new URLSearchParams();
      params.set('template_ids', ids.join(','));
      const url = 'documento_campos_multi_api.php?' + params.toString();

      const resp = await fetch(url, { credentials: 'same-origin' });
      if (!resp.ok) {
        const txt = await resp.text();
        console.error('API multi erro:', resp.status, txt);
        showMsg('Falha ao carregar campos. Verifique console e a API.');
        return;
      }

      const data = await resp.json();
      if (data && data.error) {
        console.error('API multi retornou error:', data);
        showMsg('Falha ao carregar campos: ' + (data.error || 'erro'));
        return;
      }

      renderLoteCampos(data);
    } catch (e) {
      console.error('Erro fetch multi:', e);
      showMsg('Falha ao carregar campos. Verifique console e a API.');
    }
  });

  btnLimpar.addEventListener('click', function () {
    hideMsg();

    const checks = formLote.querySelectorAll('input[name="template_ids[]"]');
    checks.forEach(ch => ch.checked = false);

    camposWrap.innerHTML = '<div class="message info">Selecione os templates e clique em <strong>Carregar campos</strong>.</div>';
  });
})();
</script>