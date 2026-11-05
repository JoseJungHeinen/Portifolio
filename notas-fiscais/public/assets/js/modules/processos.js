// @versao Proc_11 V_03_18/09/2026
/**
 * public/assets/js/modules/processos.js
 * JS do módulo Processos de NF — Exemplo
 *
 * Funcionalidades:
 * - Autocomplete de fornecedores (debounce 300ms)
 * - Geração dinâmica de parcelas
 * - Modal de devolução com validação
 * - Inicialização de ícones Lucide
 */

(function () {
  'use strict';

  // ── Utilitário: criar elemento com textContent seguro ───
  // Nunca usa innerHTML com dados do servidor.
  function criarEl(tag, attrs, textContent) {
    var el = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        if (k === 'style') {
          el.setAttribute('style', attrs[k]);
        } else {
          el.setAttribute(k, attrs[k]);
        }
      });
    }
    if (textContent !== undefined && textContent !== null) {
      el.textContent = String(textContent);
    }
    return el;
  }

  // Mantido apenas para o escapeHtml usado na geração de parcelas (template HTML estático)
  function escapeAttr(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  // ── Utilitário: formatar valor BR ────────────────────────
  function parseBrToFloat(str) {
    if (!str) return 0;
    var limpo = String(str).replace(/\s/g, '').replace(/\./g, '').replace(',', '.');
    return parseFloat(limpo) || 0;
  }

  function mascaraMonetaria(input) {
    var val = input.value.replace(/\D/g, '');
    if (val === '') { input.value = ''; return; }
    var num = parseInt(val, 10);
    input.value = (num / 100).toLocaleString('pt-BR', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  // ── Máscara CPF/CNPJ ──────────────────────────────────
  function mascaraCpfCnpj(input) {
    var v = input.value.replace(/\D/g, '');
    if (v.length <= 11) {
      v = v.replace(/(\d{3})(\d)/, '$1.$2');
      v = v.replace(/(\d{3})(\d)/, '$1.$2');
      v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    } else {
      v = v.substring(0, 14);
      v = v.replace(/^(\d{2})(\d)/, '$1.$2');
      v = v.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
      v = v.replace(/\.(\d{3})(\d)/, '.$1/$2');
      v = v.replace(/(\d{4})(\d)/, '$1-$2');
    }
    input.value = v;
  }
  window.mascaraCpfCnpj = mascaraCpfCnpj;

  // ── Máscara Telefone ─────────────────────────────────
  function mascaraTelefone(input) {
    var v = input.value.replace(/\D/g, '').substring(0, 11);
    if (v.length > 10) {
      v = v.replace(/^(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
    } else if (v.length > 6) {
      v = v.replace(/^(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3');
    } else if (v.length > 2) {
      v = v.replace(/^(\d{2})(\d{0,5})/, '($1) $2');
    } else if (v.length > 0) {
      v = v.replace(/^(\d{0,2})/, '($1');
    }
    input.value = v;
  }
  window.mascaraTelefone = mascaraTelefone;

  // Expor globalmente para uso inline
  window.formatarValorInput = mascaraMonetaria;
  window.formatarValorBlur  = mascaraMonetaria;

  // ── Utilitário: formatar CNPJ/CPF ────────────────────────
  function formatarCnpjCpf(v) {
    var d = String(v).replace(/\D/g, '');
    if (d.length === 14) return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
    if (d.length === 11) return d.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
    return v;
  }
  window.formatarCnpjCpf = formatarCnpjCpf;

  // ── Modal de cadastro de fornecedor ──────────────────────
  function abrirModalFornecedor(nomeInicial) {
    var existing = document.getElementById('modal-fornecedor');
    if (existing) existing.remove();

    var overlay = criarEl('div', {
      id: 'modal-fornecedor',
      style: 'position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center; padding:20px'
    });

    var card = criarEl('div', {
      style: 'background:#fff; border-radius:12px; box-shadow:0 8px 32px rgba(0,0,0,0.2); width:100%; max-width:520px; max-height:90vh; overflow-y:auto'
    });

    // Header
    var header = criarEl('div', {style: 'padding:20px 24px 0; display:flex; align-items:center; justify-content:space-between'});
    var title = criarEl('h2', {style: 'font-size:18px; font-weight:700; color:#141922; margin:0; display:flex; align-items:center; gap:8px'});
    var ico = document.createElement('i');
    ico.setAttribute('data-lucide', 'user-plus');
    ico.setAttribute('width', '20');
    ico.setAttribute('height', '20');
    ico.style.color = '#2347C8';
    title.appendChild(ico);
    title.appendChild(document.createTextNode('Cadastrar Fornecedor'));
    header.appendChild(title);

    var btnFechar = criarEl('button', {
      type: 'button',
      style: 'background:none; border:none; cursor:pointer; color:#888; font-size:20px; padding:4px'
    }, '×');
    btnFechar.addEventListener('click', function () { overlay.remove(); });
    header.appendChild(btnFechar);
    card.appendChild(header);

    // Body
    var body = criarEl('div', {style: 'padding:20px 24px'});
    var fieldStyle = 'width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none';

    function addField(label, name, required, placeholder, value) {
      var wrap = criarEl('div', {style: 'margin-bottom:16px'});
      var lbl = criarEl('label', {style: 'display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px'});
      lbl.textContent = label;
      if (required) {
        var req = criarEl('span', {style: 'color:#B3261E'}, ' *');
        lbl.appendChild(req);
      }
      wrap.appendChild(lbl);
      var inp = criarEl('input', {type: 'text', name: name, placeholder: placeholder || '', style: fieldStyle});
      if (value) inp.value = value;
      if (required) inp.required = true;
      inp.addEventListener('focus', function () { this.style.borderColor = '#2347C8'; });
      inp.addEventListener('blur', function () { this.style.borderColor = '#DFDFDF'; });
      wrap.appendChild(inp);
      body.appendChild(wrap);
      return inp;
    }

    // O que veio digitado na busca: dígitos = CNPJ, texto = nome fantasia
    var digitosIniciais = String(nomeInicial || '').replace(/\D/g, '');
    var cnpjInicial     = digitosIniciais.length >= 11 ? digitosIniciais : '';
    var fantasiaInicial = cnpjInicial ? '' : (nomeInicial || '');

    // ── CNPJ primeiro, com botão de busca na Receita (cnpj.ws) ──
    var wrapCnpj = criarEl('div', {style: 'margin-bottom:16px'});
    var lblCnpj  = criarEl('label', {style: 'display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px'});
    lblCnpj.textContent = 'CNPJ / CPF';
    lblCnpj.appendChild(criarEl('span', {style: 'color:#B3261E'}, ' *'));
    wrapCnpj.appendChild(lblCnpj);
    var rowCnpj = criarEl('div', {style: 'display:flex; gap:8px'});
    var inpCnpj = criarEl('input', {type: 'text', name: 'cnpj', placeholder: '11.222.333/0001-81', style: fieldStyle + '; flex:1'});
    if (cnpjInicial) inpCnpj.value = formatarCnpjCpf(cnpjInicial);
    inpCnpj.required = true;
    inpCnpj.addEventListener('focus', function () { this.style.borderColor = '#2347C8'; });
    inpCnpj.addEventListener('blur',  function () { this.style.borderColor = '#DFDFDF'; });
    var btnBuscar = criarEl('button', {
      type: 'button',
      title: 'Buscar dados na Receita (consulta cnpj.ws — apenas CNPJ)',
      style: 'display:inline-flex; align-items:center; gap:6px; padding:0 16px; height:40px; background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap'
    }, 'Buscar');
    rowCnpj.appendChild(inpCnpj);
    rowCnpj.appendChild(btnBuscar);
    wrapCnpj.appendChild(rowCnpj);
    var hintCnpj = criarEl('div', {style: 'font-size:11px; color:#888; margin-top:4px'},
      'Preencha o CNPJ e clique em Buscar para trazer os dados da Receita. CPF: preenchimento manual.');
    wrapCnpj.appendChild(hintCnpj);
    body.appendChild(wrapCnpj);

    var inpRazao    = addField('Razão Social', 'razao_social', true, 'Razão social completa', '');
    var inpFantasia = addField('Nome Fantasia', 'nome_fantasia', false, 'Nome fantasia (opcional)', fantasiaInicial);
    var inpEndereco = addField('Endereço', 'endereco', false, 'Logradouro, número, bairro, CEP', '');

    // Cidade + UF lado a lado
    var rowCidade = criarEl('div', {style: 'display:flex; gap:12px; margin-bottom:16px'});
    var wrapCid = criarEl('div', {style: 'flex:1'});
    wrapCid.appendChild(criarEl('label', {style: 'display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px'}, 'Cidade'));
    var inpCidade = criarEl('input', {type: 'text', name: 'cidade', placeholder: 'Cidade', style: fieldStyle});
    wrapCid.appendChild(inpCidade);
    var wrapUf = criarEl('div', {style: 'width:90px'});
    wrapUf.appendChild(criarEl('label', {style: 'display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px'}, 'UF'));
    var inpUf = criarEl('input', {type: 'text', name: 'estado', placeholder: 'UF', maxLength: 2, style: fieldStyle + '; text-transform:uppercase'});
    wrapUf.appendChild(inpUf);
    rowCidade.appendChild(wrapCid);
    rowCidade.appendChild(wrapUf);
    body.appendChild(rowCidade);

    var inpEmail    = addField('E-mail', 'email', false, 'contato@empresa.com', '');
    var inpTelefone = addField('Telefone', 'telefone', false, '(00) 00000-0000', '');

    // ── Dados da Receita (somente leitura, preenchidos pela busca) ──
    var dadosReceita = { data_abertura: '', situacao_cadastral: '', optante_mei: '', optante_simples: '' };
    var infoStyle = fieldStyle + '; background:#F6F6F6; color:#606060; cursor:default';
    var gridInfo = criarEl('div', {style: 'display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px'});
    function addInfo(label) {
      var w = criarEl('div');
      w.appendChild(criarEl('label', {style: 'display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px'}, label));
      var i = criarEl('input', {type: 'text', readOnly: true, value: '—', style: infoStyle});
      i.readOnly = true;
      w.appendChild(i);
      gridInfo.appendChild(w);
      return i;
    }
    var infoData     = addInfo('Data de cadastro');
    var infoSituacao = addInfo('Situação atual');
    var infoMei      = addInfo('Optante MEI');
    var infoSimples  = addInfo('Simples Nacional');
    body.appendChild(gridInfo);

    // ── Busca na Receita com cooldown de 20s (limite global do servidor) ──
    var cooldownTimer = null;
    function iniciarCooldown(segundos) {
      var resta = Math.max(1, parseInt(segundos, 10) || 20);
      btnBuscar.disabled = true;
      btnBuscar.style.opacity = '0.5';
      btnBuscar.textContent = 'Aguarde ' + resta + 's';
      if (cooldownTimer) clearInterval(cooldownTimer);
      cooldownTimer = setInterval(function () {
        resta--;
        if (resta <= 0) {
          clearInterval(cooldownTimer);
          btnBuscar.disabled = false;
          btnBuscar.style.opacity = '1';
          btnBuscar.textContent = 'Buscar';
        } else {
          btnBuscar.textContent = 'Aguarde ' + resta + 's';
        }
      }, 1000);
    }

    btnBuscar.addEventListener('click', function () {
      var c = inpCnpj.value.replace(/\D/g, '');
      if (c.length !== 14) {
        mostrarMsgModal('Informe um CNPJ com 14 dígitos para buscar (CPF não tem consulta).', 'danger');
        inpCnpj.focus();
        return;
      }
      btnBuscar.disabled = true;
      btnBuscar.style.opacity = '0.5';
      btnBuscar.textContent = 'Buscando...';

      var csrfMeta = document.querySelector('meta[name="csrf-token"]');
      var fd = new FormData();
      fd.append('csrf_token', csrfMeta ? csrfMeta.content : '');
      fd.append('cnpj', c);

      fetch('?module=processos&action=consultar_cnpj', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          if (!resp.success) {
            mostrarMsgModal(resp.message || 'Consulta indisponível.', 'danger');
            iniciarCooldown(resp.retry || 20);
            return;
          }
          var d = resp.dados;
          if (d.razao_social)  inpRazao.value    = d.razao_social;
          if (d.nome_fantasia) inpFantasia.value = d.nome_fantasia;
          if (d.endereco)      inpEndereco.value = d.endereco;
          if (d.cidade)        inpCidade.value   = d.cidade;
          if (d.uf)            inpUf.value       = d.uf;
          if (d.email)         inpEmail.value    = d.email;
          if (d.telefone)      inpTelefone.value = d.telefone;
          inpCnpj.value = formatarCnpjCpf(c);

          infoData.value     = d.data_abertura_br || '—';
          infoSituacao.value = d.situacao_cadastral || '—';
          infoMei.value      = d.optante_mei === 1 ? 'Sim' : (d.optante_mei === 0 ? 'Não' : '—');
          infoSimples.value  = d.optante_simples === 1 ? 'Sim' : (d.optante_simples === 0 ? 'Não' : '—');

          dadosReceita = {
            data_abertura:      d.data_abertura || '',
            situacao_cadastral: d.situacao_cadastral || '',
            optante_mei:        d.optante_mei === null ? '' : String(d.optante_mei),
            optante_simples:    d.optante_simples === null ? '' : String(d.optante_simples)
          };

          mostrarMsgModal('Dados preenchidos a partir da Receita — confira e ajuste se precisar.', 'success');
          iniciarCooldown(20);
        })
        .catch(function () {
          mostrarMsgModal('Erro de conexão na consulta. Tente novamente.', 'danger');
          iniciarCooldown(20);
        });
    });

    // Mensagem de erro
    var msgDiv = criarEl('div', {id: 'modal-forn-msg', style: 'display:none; padding:8px 12px; border-radius:4px; font-size:13px; margin-bottom:12px'});
    body.appendChild(msgDiv);

    card.appendChild(body);

    // Footer
    var footer = criarEl('div', {style: 'padding:0 24px 20px; display:flex; gap:8px; justify-content:flex-end'});
    var btnCancel = criarEl('button', {
      type: 'button',
      style: 'padding:10px 20px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer'
    }, 'Cancelar');
    btnCancel.addEventListener('click', function () { overlay.remove(); });

    var btnSalvar = criarEl('button', {
      type: 'button',
      style: 'padding:10px 20px; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer'
    }, 'Cadastrar');

    btnSalvar.addEventListener('click', function () {
      var razao = inpRazao.value.trim();
      var cnpjVal = inpCnpj.value.trim();
      if (!razao) { mostrarMsgModal('Razão social é obrigatória.', 'danger'); inpRazao.focus(); return; }
      if (!cnpjVal) { mostrarMsgModal('CNPJ/CPF é obrigatório.', 'danger'); inpCnpj.focus(); return; }

      btnSalvar.disabled = true;
      btnSalvar.style.opacity = '0.6';
      btnSalvar.textContent = 'Salvando...';

      var csrfMeta = document.querySelector('meta[name="csrf-token"]');
      var csrfToken = csrfMeta ? csrfMeta.content : '';

      var formData = new FormData();
      formData.append('csrf_token', csrfToken);
      formData.append('razao_social', razao);
      formData.append('nome_fantasia', inpFantasia.value.trim());
      formData.append('cnpj', cnpjVal);
      formData.append('email', inpEmail.value.trim());
      formData.append('telefone', inpTelefone.value.trim());
      formData.append('endereco', inpEndereco.value.trim());
      formData.append('cidade', inpCidade.value.trim());
      formData.append('estado', inpUf.value.trim());
      formData.append('data_abertura', dadosReceita.data_abertura);
      formData.append('situacao_cadastral', dadosReceita.situacao_cadastral);
      formData.append('optante_mei', dadosReceita.optante_mei);
      formData.append('optante_simples', dadosReceita.optante_simples);

      fetch('?module=processos&action=cadastrar_fornecedor', { method: 'POST', body: formData })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          if (!resp.success) {
            mostrarMsgModal(resp.message, 'danger');
            btnSalvar.disabled = false;
            btnSalvar.style.opacity = '1';
            btnSalvar.textContent = 'Cadastrar';
            return;
          }

          var f = resp.fornecedor;
          var inputSearch = document.getElementById('fornecedor_search');
          var inputId     = document.getElementById('fornecedor_id');
          var inputNome   = document.getElementById('fornecedor_nome');
          var inputDoc    = document.getElementById('fornecedor_doc');

          if (inputSearch) inputSearch.value = f.nome;
          if (inputId)     inputId.value     = f.id;
          if (inputNome)   inputNome.value   = f.nome;
          if (inputDoc)    inputDoc.value    = f.cnpj;

          overlay.remove();
        })
        .catch(function () {
          mostrarMsgModal('Erro de conexão. Tente novamente.', 'danger');
          btnSalvar.disabled = false;
          btnSalvar.style.opacity = '1';
          btnSalvar.textContent = 'Cadastrar';
        });
    });

    footer.appendChild(btnCancel);
    footer.appendChild(btnSalvar);
    card.appendChild(footer);

    overlay.appendChild(card);
    document.body.appendChild(overlay);

    if (window.lucide) window.lucide.createIcons();
    (inpCnpj.value ? inpRazao : inpCnpj).focus();

    function mostrarMsgModal(msg, tipo) {
      msgDiv.textContent = msg;
      msgDiv.style.display = 'block';
      msgDiv.style.background = tipo === 'danger' ? '#FFE8EE' : '#D8F5E5';
      msgDiv.style.color = tipo === 'danger' ? '#B3261E' : '#00A960';
    }
  }
  window.abrirModalFornecedor = abrirModalFornecedor;

  // ── Autocomplete de fornecedor ───────────────────────────
  var _fornecedorTimer = null;

  function initFornecedorAutocomplete() {
    var input    = document.getElementById('fornecedor_search');
    var results  = document.getElementById('fornecedor_results');
    var inputId  = document.getElementById('fornecedor_id');
    var inputNome= document.getElementById('fornecedor_nome');
    var inputDoc = document.getElementById('fornecedor_doc');

    if (!input || !results) return;

    input.addEventListener('input', function () {
      clearTimeout(_fornecedorTimer);
      var q = this.value.trim();
      var digitos = q.replace(/\D/g, '');

      // Limpar seleção anterior ao digitar
      if (inputId) inputId.value = '';

      if (digitos.length < 3) {
        results.style.display = 'none';
        return;
      }

      _fornecedorTimer = setTimeout(function () {
        fetch('?module=processos&action=buscar_fornecedor&q=' + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(function (data) {
            while (results.firstChild) { results.removeChild(results.firstChild); }

            if (!Array.isArray(data) || data.length === 0) {
              if (inputId)   inputId.value   = '';
              if (inputNome) inputNome.value = input.value;
              if (inputDoc)  inputDoc.value  = '';

              var noResult = criarEl('div', {style: 'padding:12px 14px; text-align:center'});
              var noMsg = criarEl('div', {style: 'font-size:13px; color:#888; margin-bottom:8px'}, 'Nenhum fornecedor encontrado');
              var noBtn = criarEl('button', {
                type: 'button',
                style: 'display:inline-flex; align-items:center; gap:6px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:8px 16px; font-size:13px; font-weight:700; cursor:pointer'
              }, '+ Cadastrar novo fornecedor');
              noBtn.addEventListener('mousedown', function (ev) {
                ev.preventDefault();
                results.style.display = 'none';
                abrirModalFornecedor(input.value.trim());
              });
              noResult.appendChild(noMsg);
              noResult.appendChild(noBtn);
              results.appendChild(noResult);
              results.style.display = 'block';
              return;
            }

            data.forEach(function (f) {
              var id   = String(f.id   || '');
              var nome = String(f.nome || '');
              var cnpj = String(f.cnpj || '');
              var cnpjFmt = formatarCnpjCpf(cnpj);

              var item = criarEl('div', {
                'class':    'fornecedor-result-item',
                'role':     'option',
                'tabindex': '0',
                'style':    'padding:10px 14px; cursor:pointer; border-bottom:1px solid #F0F0F0; font-size:14px',
              });
              item.dataset.id   = id;
              item.dataset.nome = nome;
              item.dataset.cnpj = cnpj;

              var linhaNome = criarEl('div', {'style': 'font-weight:700; color:#141922'}, nome);
              var linhaCnpj = criarEl('div', {'style': 'font-size:12px; color:#888'}, cnpjFmt);
              item.appendChild(linhaNome);
              item.appendChild(linhaCnpj);

              item.addEventListener('mouseover', function () { this.style.background = '#EEF2FD'; });
              item.addEventListener('mouseout',  function () { this.style.background = '#fff'; });
              item.addEventListener('click', function () {
                input.value = this.dataset.nome;
                if (inputId)   inputId.value   = this.dataset.id;
                if (inputNome) inputNome.value = this.dataset.nome;
                if (inputDoc)  inputDoc.value  = this.dataset.cnpj;
                results.style.display = 'none';
              });

              results.appendChild(item);
            });

            // Botão cadastrar no final da lista
            var addItem = criarEl('div', {style: 'padding:10px 14px; border-top:1px solid #EAEAEA; text-align:center'});
            var addBtn = criarEl('button', {
              type: 'button',
              style: 'display:inline-flex; align-items:center; gap:6px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:6px 14px; font-size:12px; font-weight:700; cursor:pointer'
            }, '+ Cadastrar novo fornecedor');
            addBtn.addEventListener('mousedown', function (ev) {
              ev.preventDefault();
              results.style.display = 'none';
              abrirModalFornecedor('');
            });
            addItem.appendChild(addBtn);
            results.appendChild(addItem);
            results.style.display = 'block';
          })
          .catch(function () {
            results.style.display = 'none';
          });
      }, 300);
    });

    // Fechar ao clicar fora
    document.addEventListener('click', function (e) {
      if (!input.contains(e.target) && !results.contains(e.target)) {
        results.style.display = 'none';
        // Se nenhum fornecedor foi selecionado, usar texto como nome manual
        if (inputId && !inputId.value && input.value.trim()) {
          if (inputNome) inputNome.value = input.value.trim();
        }
      }
    });

    // Garantir que nome preenchido ao submeter o form
    var form = document.getElementById('form-step2');
    if (form) {
      form.addEventListener('submit', function () {
        if (inputNome && !inputNome.value && input.value.trim()) {
          inputNome.value = input.value.trim();
        }
      });
    }
  }

  // ── Geração de parcelas via DOM (sem innerHTML com dados externos) ──

  /** Uma linha da tabela de parcelas — usada tanto pela sugestão quanto pelo preenchimento com dado real. */
  function criarLinhaParcela(i, n, dtStr, valStr, formaPagamentoId, formasPagamento) {
    var bgRow = i % 2 === 0 ? '#fff' : '#F8F8F8';

    var tr = document.createElement('tr');
    tr.setAttribute('style', 'background:' + bgRow);

    // Coluna #
    var tdNum = criarEl('td', {style: 'padding:10px 12px; text-align:center; color:#141922; font-weight:700'}, (i + 1) + '/' + n);
    tr.appendChild(tdNum);

    // Coluna Vencimento
    var tdDt = criarEl('td', {style: 'padding:10px 12px'});
    var inputDt = document.createElement('input');
    inputDt.type  = 'date';
    inputDt.name  = 'parcela[' + i + '][vencimento]';
    inputDt.value = dtStr;
    inputDt.required = true;
    inputDt.setAttribute('style', 'height:36px; border:1px solid #DFDFDF; border-radius:4px; padding:0 8px; font-size:13px; width:100%; box-sizing:border-box; outline:none');
    inputDt.addEventListener('focus', function () { this.style.borderColor = '#2347C8'; });
    inputDt.addEventListener('blur',  function () { this.style.borderColor = '#DFDFDF'; });
    tdDt.appendChild(inputDt);
    tr.appendChild(tdDt);

    // Coluna Valor
    var tdVal = criarEl('td', {style: 'padding:10px 12px'});
    var inputVal = document.createElement('input');
    inputVal.type  = 'text';
    inputVal.name  = 'parcela[' + i + '][valor]';
    inputVal.value = valStr;
    inputVal.required = true;
    inputVal.setAttribute('style', 'height:36px; border:1px solid #DFDFDF; border-radius:4px; padding:0 8px; font-size:13px; width:100%; box-sizing:border-box; outline:none');
    inputVal.addEventListener('focus', function () { this.style.borderColor = '#2347C8'; });
    inputVal.addEventListener('blur',  function () { this.style.borderColor = '#DFDFDF'; });
    inputVal.addEventListener('input', function () { mascaraMonetaria(this); });
    tdVal.appendChild(inputVal);
    tr.appendChild(tdVal);

    // Coluna Forma de Pagamento
    var tdFp = criarEl('td', {style: 'padding:10px 12px'});
    var sel  = document.createElement('select');
    sel.name = 'parcela[' + i + '][forma_pagamento_id]';
    sel.setAttribute('style', 'height:36px; border:1px solid #DFDFDF; border-radius:4px; padding:0 8px; font-size:13px; width:100%; box-sizing:border-box; outline:none');
    sel.addEventListener('focus', function () { this.style.borderColor = '#2347C8'; });
    sel.addEventListener('blur',  function () { this.style.borderColor = '#DFDFDF'; });

    var optVazia = criarEl('option', {value: ''}, 'Selecione...');
    sel.appendChild(optVazia);

    if (Array.isArray(formasPagamento)) {
      formasPagamento.forEach(function (fp) {
        var opt = criarEl('option', {value: String(fp.id || '')}, String(fp.label || ''));
        if (formaPagamentoId && String(fp.id || '') === String(formaPagamentoId)) {
          opt.selected = true;
        }
        sel.appendChild(opt);
      });
    }

    tdFp.appendChild(sel);
    tr.appendChild(tdFp);

    return tr;
  }

  /** Monta a tabela (cabeçalho + linhas via $linhas) e substitui o container. */
  function montarTabelaParcelas(linhas) {
    var container = document.getElementById('parcelas-grid');
    if (!container) return;

    var table = document.createElement('table');
    table.setAttribute('style', 'width:100%; border-collapse:collapse; font-size:13px');

    var thead = document.createElement('thead');
    var trHead = document.createElement('tr');
    trHead.setAttribute('style', 'background:#606060; color:#fff');
    ['#', 'Vencimento', 'Valor (R$)', 'Forma de Pagamento'].forEach(function (label) {
      var th = criarEl('th', {style: 'padding:10px 12px; font-weight:700; font-size:12px; text-transform:uppercase'}, label);
      trHead.appendChild(th);
    });
    thead.appendChild(trHead);
    table.appendChild(thead);

    var tbody = document.createElement('tbody');
    linhas.forEach(function (tr) { tbody.appendChild(tr); });
    table.appendChild(tbody);

    while (container.firstChild) { container.removeChild(container.firstChild); }
    container.appendChild(table);

    var numParcelasInput = document.getElementById('num_parcelas');
    if (numParcelasInput) numParcelasInput.value = linhas.length;
  }

  /**
   * Sugestão automática: divide o valor da NF em n parcelas iguais, vencimento
   * a cada `diasSugestao` dias a partir de hoje (parcela 1 = hoje+diasSugestao,
   * parcela 2 = hoje+2×diasSugestao, ...). Configurável em Geral →
   * "Dias para sugestão de vencimento" (nf_configuracoes, padrão 15).
   */
  function gerarLinhasParcelas(n, formasPagamento, diasSugestao) {
    n = parseInt(n, 10) || 1;
    if (n < 1) n = 1;
    if (n > 60) n = 60;
    diasSugestao = parseInt(diasSugestao, 10) || 15;

    var campoValor = document.getElementById('valor_nf');
    var valorStr   = campoValor ? campoValor.value : '0';
    var valorTotal = parseFloat(valorStr.replace(/\./g, '').replace(',', '.')) || 0;
    var valorParc  = n > 0 ? (valorTotal / n) : 0;

    var hoje = new Date();
    var valStr = valorParc.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    var linhas = [];
    for (var i = 0; i < n; i++) {
      var dt = new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate() + diasSugestao * (i + 1));
      linhas.push(criarLinhaParcela(i, n, dt.toISOString().substring(0, 10), valStr, null, formasPagamento));
    }
    montarTabelaParcelas(linhas);
  }

  /**
   * Preenche o grid com parcelas REAIS (vencimento/valor/forma_pagamento já
   * definidos — vieram da nota capturada, de uma importação ou do processo em
   * edição). Nunca recalcula: mostrar a sugestão por cima de um dado real é
   * exatamente o bug que isto evita (vencimento da nota sendo substituído
   * por "hoje + N dias" ao simplesmente abrir a tela).
   */
  function preencherLinhasParcelas(parcelas, formasPagamento) {
    if (!Array.isArray(parcelas) || parcelas.length === 0) return false;
    var n = parcelas.length;
    var linhas = parcelas.map(function (p, i) {
      var dtStr  = String(p.vencimento || '').substring(0, 10);
      var valor  = parseFloat(p.valor) || 0;
      var valStr = valor.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      return criarLinhaParcela(i, n, dtStr, valStr, p.forma_pagamento_id || null, formasPagamento);
    });
    montarTabelaParcelas(linhas);
    return true;
  }

  // Expor para uso inline no step2.php
  window.gerarLinhasParcelas = function (n) {
    gerarLinhasParcelas(n, window._formasPagamento || [], window._diasVencimentoSugestao || 15);
  };
  window.preencherLinhasParcelas = function (parcelas) {
    return preencherLinhasParcelas(parcelas, window._formasPagamento || []);
  };

  // ── Modais: usa padrão do design-system (classe is-open) ──
  window.abrirModalDevolucao = function () {
    var modal = document.getElementById('modal-devolucao');
    if (modal) {
      modal.classList.add('is-open');
      var ta = document.getElementById('motivo_devolucao');
      if (ta) ta.focus();
    }
  };

  window.fecharModalDevolucao = function () {
    var modal = document.getElementById('modal-devolucao');
    if (modal) modal.classList.remove('is-open');
  };

  window.submeterDevolucao = function () {
    var motivo = document.getElementById('motivo_devolucao');
    var erro   = document.getElementById('motivo-erro');

    if (!motivo) return;

    if (motivo.value.trim().length < 10) {
      if (erro) {
        erro.textContent = 'O motivo deve ter pelo menos 10 caracteres.';
        erro.style.display = 'block';
      }
      return;
    }
    if (erro) erro.style.display = 'none';

    window.fecharModalDevolucao();

    var form = document.getElementById('form-devolucao');
    if (typeof window.mostrarToastESumbeter === 'function') {
      window.mostrarToastESumbeter(form, 'Processo devolvido com sucesso. A etapa anterior foi reaberta para ajuste.');
    } else {
      form.submit();
    }
  };

  // ── Inicialização ────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {

    // Autocomplete de fornecedor
    initFornecedorAutocomplete();

    // Formatar campos de valor que já estiverem na página
    document.querySelectorAll('input[data-type="valor"]').forEach(function (el) {
      el.addEventListener('input', function () { mascaraMonetaria(this); });
    });

    // Reinicializar Lucide após qualquer conteúdo dinâmico
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }

  });

})();
