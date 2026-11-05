// @versao Tpl_06 V_09_01/10/2026
/**
 * templates.js — Editor de Templates de Fluxo NF
 *
 * Gerencia as 5 abas do editor:
 *   Geral | Etapas | Campos | Anexos | Financeiro
 *
 * Segurança: todos os dados do servidor são inseridos via
 *   textContent / setAttribute — nunca innerHTML com dados externos.
 */

(function () {
  'use strict';

  // ── Leitura dos dados do servidor (via data-* — sem XSS) ────────────
  var editorEl   = document.getElementById('editor-data');
  if (!editorEl) return; // página sem editor (ex: lista)

  var templateId  = editorEl.dataset.templateId;
  var versaoId    = editorEl.dataset.versaoId;
  var readonly    = editorEl.dataset.readonly === '1';
  var grupos         = JSON.parse(editorEl.dataset.grupos        || '[]');
  var todosUsuarios  = JSON.parse(editorEl.dataset.todosUsuarios || '[]');
  var tiposCampo     = JSON.parse(editorEl.dataset.tiposCampo    || '{}');
  var fontesLista    = JSON.parse(editorEl.dataset.fontesLista   || '[]');
  var etapasCatalogo = JSON.parse(editorEl.dataset.etapasCatalogo || '[]');
  // { listaId: [ {indice, rotulo}, ... ] } — quantos caminhos cada lista de
  // decisão abre, e como cada um se chama. Alimenta a etapa de decisão.
  var listasIndices  = JSON.parse(editorEl.dataset.listasIndices  || '{}');
  // Templates que a etapa "Abrir Fluxo" pode disparar (só os publicados).
  var templatesDestino = JSON.parse(editorEl.dataset.templatesDestino || '[]');
  var etapaFinanceiroNome = editorEl.dataset.etapaFinanceiroNome || 'Aprovação Financeiro';

  // ── Assinatura eletrônica ────────────────────────────────────────────
  // Os nomes vêm do PHP (constantes de Template) — nunca escrever literal
  // aqui: a validação do publish compara por eles, e um literal divergente
  // deixaria o editor montando um template que o servidor recusa publicar.
  var etapaAssinaturaNome = editorEl.dataset.etapaAssinaturaNome || 'Assinatura de Documento';
  var anexoAssinaturaNome = editorEl.dataset.anexoAssinaturaNome || 'Documento para Assinatura';
  // Clicksign (01/10/2026): escolher a etapa "Assina ClickSign" liga a trava e
  // monta os campos dos DOIS signatários. O provedor vem da etapa de catálogo.
  var etapaClicksignNome  = editorEl.dataset.etapaClicksignNome || 'Assina ClickSign';
  var listaTiposAssinatura = editorEl.dataset.listaTiposAssinatura || '';
  var provedorAssinatura  = editorEl.dataset.assinaturaProvedor || 'assinafy';
  var SIGNATARIOS_CLICKSIGN = 2;
  var assinaturaNaturezas = JSON.parse(editorEl.dataset.assinaturaNaturezas || '{"assina":"Assina"}');
  var assinaturaMax       = parseInt(editorEl.dataset.assinaturaMax, 10) || 8;

  // Lista de papéis — estado mutável, serializado no submit igual a campos/etapas
  var signatarios = JSON.parse(editorEl.dataset.assinaturaSignatarios || '[]');

  // Estado mutável — será serializado no submit
  var etapas     = JSON.parse(editorEl.dataset.etapas     || '[]');
  var campos     = JSON.parse(editorEl.dataset.campos     || '[]');
  var tiposAnexo = JSON.parse(editorEl.dataset.tiposAnexo || '[]');

  // ── Elementos do DOM ─────────────────────────────────────────────────
  var inputEtapas     = document.getElementById('input-etapas');
  var inputCampos     = document.getElementById('input-campos');
  var inputTiposAnexo = document.getElementById('input-tipos-anexo');
  var etapasList      = document.getElementById('etapas-list');
  var camposList      = document.getElementById('campos-list');
  var anexosList      = document.getElementById('anexos-list');
  var chkFinanceiro   = document.getElementById('chk-financeiro');
  var chkAssinatura    = document.getElementById('chk-assinatura');
  var signatariosList  = document.getElementById('assinatura-signatarios-list');
  var inputSignatarios = document.getElementById('input-assinatura-signatarios');

  /** Assinatura ligada? */
  function assinaturaAtiva() {
    return !!(chkAssinatura && chkAssinatura.checked);
  }

  /**
   * Definição dos campos gerados por um papel, conforme o provedor —
   * espelha Template::camposSignatario(). [qual, rótulo, tipo, obrigatório].
   * Telefone não é obrigatório no template: só SMS/WhatsApp o exigem, e quem
   * confere isso é o envio, que conhece o tipo escolhido.
   */
  function defsCamposPapel(papel) {
    var p = (papel || '').trim() || 'Signatário';
    if (provedorAssinatura === 'clicksign') {
      return [
        ['nome',     p + ' — Nome completo',     'texto',    true],
        ['cpf',      p + ' — CPF',               'cpf_cnpj', true],
        ['email',    p + ' — E-mail',            'email',    true],
        ['telefone', p + ' — Telefone',          'telefone', false],
        ['tipo',     p + ' — Tipo de assinatura', 'select',   true]
      ];
    }
    return [
      ['nome',  p + ' — Nome completo', 'texto', true],
      ['email', p + ' — E-mail',        'email', true]
    ];
  }

  /** Rótulos dos campos gerados por um papel (qual → rótulo). */
  function camposDoPapel(papel) {
    var out = {};
    defsCamposPapel(papel).forEach(function (d) { out[d[0]] = d[1]; });
    return out;
  }

  /** Nomes dos campos exigidos pela lista atual de papéis. */
  function nomesCamposSignatario() {
    var out = [];
    signatarios.forEach(function (s) {
      defsCamposPapel(s.papel).forEach(function (d) { out.push(d[1]); });
    });
    return out;
  }

  /** A etapa é de assinatura eletrônica (de qualquer provedor)? */
  function ehEtapaAssinatura(e) {
    var c = catalogoDe(e);
    if (c) return !!c.assinatura;
    return e.nome === etapaAssinaturaNome || e.nome === etapaClicksignNome;
  }

  /** Nome da etapa de catálogo do provedor atual. */
  function nomeEtapaDoProvedor() {
    return provedorAssinatura === 'clicksign' ? etapaClicksignNome : etapaAssinaturaNome;
  }

  /**
   * Espaçador com cadeado, no lugar do botão remover.
   * Itens criados pela trava de assinatura não podem ser apagados à mão
   * enquanto ela estiver ligada — apagar deixaria o template impublicável e a
   * mensagem de erro apareceria só lá na frente, no publish.
   */
  function lockSpacer(titulo) {
    var sp = document.createElement('div');
    sp.style.width = '28px';
    sp.style.display = 'flex';
    sp.style.alignItems = 'center';
    sp.style.justifyContent = 'center';
    sp.title = titulo;
    var ic = document.createElement('i');
    ic.setAttribute('data-lucide', 'lock');
    ic.setAttribute('width', '13');
    ic.setAttribute('height', '13');
    ic.style.color = '#C8C8C8';
    sp.appendChild(ic);
    return sp;
  }

  // ── Navegação por abas ───────────────────────────────────────────────
  var tabs   = document.querySelectorAll('.template-tab');
  var panels = document.querySelectorAll('.tab-panel');

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      var target = tab.dataset.tab;

      tabs.forEach(function (t) {
        t.classList.remove('active');
        t.setAttribute('aria-selected', 'false');
      });
      panels.forEach(function (p) { p.classList.remove('active'); });

      tab.classList.add('active');
      tab.setAttribute('aria-selected', 'true');

      var panel = document.querySelector('[data-panel="' + target + '"]');
      if (panel) panel.classList.add('active');

      // Re-renderizar ícones Lucide para novos elementos
      if (window.lucide) window.lucide.createIcons();
    });
  });

  // ── Helper: limpa todos os filhos de um elemento (seguro, sem innerHTML) ──
  function clearEl(el) {
    while (el.firstChild) el.removeChild(el.firstChild);
  }

  // ── Serialização antes do submit ─────────────────────────────────────
  var formEditor = document.getElementById('form-editor');
  if (formEditor) {
    formEditor.addEventListener('submit', function () {
      // A etapa de assinatura pode ter sido arrastada de lugar depois que o
      // anexo foi ancorado. Remontar aqui garante que o que vai para o banco
      // está consistente, independente da ordem em que o usuário mexeu.
      if (assinaturaAtiva()) montarAssinatura();
      if (inputSignatarios) inputSignatarios.value = JSON.stringify(signatarios);

      if (inputEtapas)     inputEtapas.value     = JSON.stringify(etapas);
      if (inputCampos)     inputCampos.value     = JSON.stringify(campos);
      if (inputTiposAnexo) inputTiposAnexo.value = JSON.stringify(tiposAnexo);
    });
  }

  // ════════════════════════════════════════════════════════════════════
  // ETAPAS
  // ════════════════════════════════════════════════════════════════════

  function renderEtapas() {
    if (!etapasList) return;
    clearEl(etapasList);

    if (etapas.length === 0) {
      var emptyMsg = document.createElement('div');
      emptyMsg.className = 'empty-state';
      emptyMsg.style.padding = '40px';
      emptyMsg.id = 'etapas-empty';
      var icon = document.createElement('i');
      icon.setAttribute('data-lucide', 'git-branch');
      icon.setAttribute('width', '36');
      icon.setAttribute('height', '36');
      emptyMsg.appendChild(icon);
      var p = document.createElement('p');
      p.textContent = 'Nenhuma etapa adicionada.';
      emptyMsg.appendChild(p);
      etapasList.appendChild(emptyMsg);
      if (window.lucide) window.lucide.createIcons();
      return;
    }

    // Todo destino de decisão aponta para o `uid` da linha, nunca para a
    // posição: arrastar as etapas renumera tudo e quebraria a referência.
    etapas.forEach(garantirUid);

    etapas.forEach(function (etapa, idx) {
      etapasList.appendChild(buildEtapaRow(etapa, idx));
    });

    if (window.lucide) window.lucide.createIcons();
    initDragDrop(etapasList, etapas, renderEtapas);

    // Anexos referenciam etapas no select "Exigido em" — manter em sincronia
    if (typeof renderAnexos === 'function') renderAnexos();
  }

  // ── Conversão de SLA (minutos/horas/dias) ↔ horas (armazenamento canônico) ──
  var SLA_UNIDADES = [
    { value: 'minutos', label: 'Minutos' },
    { value: 'horas',   label: 'Horas' },
    { value: 'dias',    label: 'Dias' }
  ];

  function slaParaHoras(valor, unidade) {
    if (!valor || valor <= 0) return null;
    if (unidade === 'minutos') return valor / 60;
    if (unidade === 'dias') return valor * 24;
    return valor;
  }

  // Reconstrói valor+unidade a partir de sla_horas (templates legados sem sla_unidade)
  function horasParaValorUnidade(horas) {
    if (!horas || horas <= 0) return { valor: '', unidade: 'horas' };
    if (horas < 1) return { valor: Math.round(horas * 60), unidade: 'minutos' };
    if (horas >= 24 && horas % 24 === 0) return { valor: horas / 24, unidade: 'dias' };
    return { valor: horas, unidade: 'horas' };
  }

  // ── Custom dropdown builder (substitui select nativo) ──
  function buildCustomDropdown(options, selectedValue, placeholder, isDisabled, onChange) {
    var wrap = document.createElement('div');
    wrap.className = 'custom-dropdown';
    if (isDisabled) wrap.classList.add('disabled');

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'custom-dropdown__trigger';
    if (isDisabled) trigger.disabled = true;

    var labelSpan = document.createElement('span');
    labelSpan.className = 'custom-dropdown__label';
    var sel = options.find(function(o) { return o.value === selectedValue; });
    if (sel) {
      labelSpan.textContent = sel.label;
    } else {
      labelSpan.textContent = placeholder;
      labelSpan.classList.add('placeholder');
    }
    trigger.appendChild(labelSpan);

    var chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    chevron.setAttribute('viewBox', '0 0 24 24');
    chevron.setAttribute('fill', 'none');
    chevron.setAttribute('stroke', 'currentColor');
    chevron.setAttribute('stroke-width', '2');
    chevron.setAttribute('stroke-linecap', 'round');
    chevron.setAttribute('stroke-linejoin', 'round');
    chevron.classList.add('custom-dropdown__chevron');
    var polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
    polyline.setAttribute('points', '6 9 12 15 18 9');
    chevron.appendChild(polyline);
    trigger.appendChild(chevron);
    wrap.appendChild(trigger);

    var list = document.createElement('div');
    list.className = 'custom-dropdown__list';

    options.forEach(function(opt) {
      var item = document.createElement('div');
      item.className = 'custom-dropdown__option';
      if (opt.value === selectedValue) item.classList.add('selected');
      item.textContent = opt.label;
      item.dataset.value = opt.value;
      item.addEventListener('click', function(e) {
        e.stopPropagation();
        labelSpan.textContent = opt.label;
        labelSpan.classList.remove('placeholder');
        list.querySelectorAll('.custom-dropdown__option').forEach(function(el) {
          el.classList.remove('selected');
        });
        item.classList.add('selected');
        wrap.classList.remove('open');
        if (onChange) onChange(opt.value);
      });
      list.appendChild(item);
    });
    wrap.appendChild(list);

    trigger.addEventListener('click', function(e) {
      e.stopPropagation();
      var wasOpen = wrap.classList.contains('open');
      document.querySelectorAll('.custom-dropdown.open').forEach(function(el) {
        el.classList.remove('open');
      });
      if (!wasOpen) wrap.classList.add('open');
    });

    return wrap;
  }

  // Fechar dropdowns ao clicar fora
  document.addEventListener('click', function() {
    document.querySelectorAll('.custom-dropdown.open').forEach(function(el) {
      el.classList.remove('open');
    });
  });

  // ── Catálogo de etapas (nf_etapas): resolve a etapa do template no catálogo ──
  function catalogoDe(etapa) {
    if (etapa.etapa_id) {
      var porId = etapasCatalogo.find(function (c) { return c.id === etapa.etapa_id; });
      if (porId) return porId;
    }
    var nome = (etapa.nome || '').trim().toLowerCase();
    if (!nome) return null;
    return etapasCatalogo.find(function (c) { return c.nome.trim().toLowerCase() === nome; }) || null;
  }

  // ── Etapa de DECISÃO ────────────────────────────────────────────────
  // A etapa de decisão não tem aprovador: quem a resolve é o sistema, lendo um
  // campo do formulário. O que ela precisa do usuário é o mapa
  //   índice da lista → etapa para onde o fluxo salta.
  // O salto pula tudo que estiver entre a decisão e o destino; do destino em
  // diante o fluxo volta a ser sequencial.

  /** Identificador estável da linha — sobrevive a drag-and-drop e renumeração. */
  function garantirUid(etapa) {
    if (!etapa.uid) {
      etapa.uid = 'e' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    }
    return etapa.uid;
  }

  /** Índices da lista que governa esta etapa de decisão ([] se não for decisão). */
  function indicesDaDecisao(cat) {
    if (!cat || !cat.decisao_lista_id) return [];
    return listasIndices[cat.decisao_lista_id] || [];
  }

  /**
   * Campos do formulário alimentados pela lista da decisão.
   * Um só campo = o vínculo é óbvio e fica implícito; dois ou mais = o usuário
   * precisa dizer qual deles decide.
   */
  function camposDaLista(listaId) {
    return campos.filter(function (c) { return (c.lista_id || '') === listaId; });
  }

  /** Cabeçalho de um bloco de etapa especial (decisão / ir para / abrir fluxo). */
  function tituloBloco(icone, texto) {
    var el = document.createElement('div');
    el.className = 'etapa-decisao-titulo';
    var ic = document.createElement('i');
    ic.setAttribute('data-lucide', icone);
    ic.setAttribute('width', '13');
    ic.setAttribute('height', '13');
    el.appendChild(ic);
    var sp = document.createElement('span');
    sp.textContent = texto;
    el.appendChild(sp);
    return el;
  }

  /**
   * Opções de etapa-destino: qualquer OUTRA linha do template, para frente ou
   * para trás. O valor é o `uid` da linha, nunca a posição — arrastar as etapas
   * renumera tudo e quebraria a referência.
   */
  function destinosDoTemplate(idxOrigem) {
    var opts = [{ value: '', label: '— Selecione a etapa —' }];
    etapas.forEach(function (e, i) {
      if (i === idxOrigem) return;   // saltar para si mesmo é laço imediato
      opts.push({
        value: garantirUid(e),
        label: (i + 1) + '. ' + (e.nome || 'Etapa sem nome')
      });
    });
    return opts;
  }

  /** Aviso vermelho dentro do bloco de decisão — erro que trava a publicação. */
  function avisoDecisao(texto) {
    var el = document.createElement('div');
    el.className = 'etapa-decisao-aviso';
    var ic = document.createElement('i');
    ic.setAttribute('data-lucide', 'alert-triangle');
    ic.setAttribute('width', '12');
    ic.setAttribute('height', '12');
    el.appendChild(ic);
    var sp = document.createElement('span');
    sp.textContent = texto;
    el.appendChild(sp);
    return el;
  }

  function buildEtapaRow(etapa, idx) {
    var row = document.createElement('div');
    row.className = 'etapa-row';
    row.draggable = !readonly;
    row.dataset.idx = idx;

    // Etapa obrigatória do financeiro: nome fica travado e não pode ser removida,
    // mas o tipo de aprovador (Grupo ou Usuário) e a ordem continuam livres.
    var isProtegida = !readonly && (
      (!!(chkFinanceiro && chkFinanceiro.checked) && etapa.nome === etapaFinanceiroNome) ||
      (assinaturaAtiva() && ehEtapaAssinatura(etapa))
    );

    // Vincula ao catálogo (por id ou, para etapas legadas, por nome)
    var cat = catalogoDe(etapa);
    if (cat && etapa.etapa_id !== cat.id) {
      etapa.etapa_id = cat.id;
      etapa.nome     = cat.nome;
    }
    var isArquivo = !!(cat && cat.encerra);
    if (isArquivo) {
      etapa.tipo_aprovador = 'arquivo';
      etapa.aprovador_id   = '';
    } else if (etapa.tipo_aprovador === 'arquivo'
            || etapa.tipo_aprovador === 'decisao'
            || etapa.tipo_aprovador === 'abre_fluxo') {
      // A etapa deixou de arquivar / decidir / saltar / abrir fluxo no
      // catálogo: volta a pedir aprovador e larga o que só fazia sentido antes.
      var aindaEspecial = cat && (cat.decisao_lista_id || cat.salto || cat.abre_fluxo);
      if (!aindaEspecial) {
        etapa.tipo_aprovador = (cat && cat.tipo === 'usuario') ? 'usuario' : 'grupo';
        delete etapa.decisao_saltos;
        delete etapa.decisao_campo;
        delete etapa.abre_template_id;
      }
    }

    // 1. Handle de drag
    var handle = document.createElement('div');
    handle.className = 'etapa-drag-handle';
    if (readonly) handle.style.cursor = 'default';
    var handleIcon = document.createElement('i');
    handleIcon.setAttribute('data-lucide', 'grip-vertical');
    handleIcon.setAttribute('width', '14');
    handleIcon.setAttribute('height', '14');
    handle.appendChild(handleIcon);
    row.appendChild(handle);

    // 2. Botão remover (coluna fixa de 28px)
    if (!readonly && !isProtegida) {
      var btnRem = document.createElement('button');
      btnRem.type = 'button';
      btnRem.className = 'btn-remove-item';
      btnRem.title = 'Remover etapa';
      var remIcon = document.createElement('i');
      remIcon.setAttribute('data-lucide', 'x');
      remIcon.setAttribute('width', '14');
      remIcon.setAttribute('height', '14');
      btnRem.appendChild(remIcon);
      btnRem.addEventListener('click', function () {
        btModalConfirm('Remover etapa', 'Remover a etapa ' + JSON.stringify(etapa.nome || ('Etapa ' + (idx + 1))) + '?', function () {
          etapas.splice(idx, 1);
          renderEtapas();
        }, {tipo: 'error', confirmLabel: 'Remover', confirmBg: '#B3261E'});
      });
      row.appendChild(btnRem);
    } else if (isProtegida) {
      var lockSpacer = document.createElement('div');
      lockSpacer.style.width = '28px';
      lockSpacer.style.display = 'flex';
      lockSpacer.style.alignItems = 'center';
      lockSpacer.style.justifyContent = 'center';
      lockSpacer.title = 'Etapa obrigatória enquanto o controle financeiro estiver habilitado — não pode ser removida';
      var lockIcon = document.createElement('i');
      lockIcon.setAttribute('data-lucide', 'lock');
      lockIcon.setAttribute('width', '13');
      lockIcon.setAttribute('height', '13');
      lockIcon.style.color = '#888888';
      lockSpacer.appendChild(lockIcon);
      row.appendChild(lockSpacer);
    } else {
      var spacer = document.createElement('div');
      spacer.style.width = '28px';
      row.appendChild(spacer);
    }

    // 3. Etapa (dropdown do catálogo — padroniza a escrita; 3fr)
    var etapaOptions = etapasCatalogo.map(function (c) {
      return { value: c.id, label: c.nome };
    });
    // Etapa legada fora do catálogo: mantém visível para o usuário trocar
    if (!cat && etapa.nome) {
      etapaOptions.unshift({ value: '', label: etapa.nome + ' (fora do catálogo)' });
    }
    var ddEtapa = buildCustomDropdown(
      etapaOptions,
      cat ? cat.id : '',
      '— Selecionar etapa —',
      readonly || isProtegida,
      function (val) {
        var novo = etapasCatalogo.find(function (c) { return c.id === val; });
        etapas[idx].etapa_id = val;
        if (novo) {
          etapas[idx].nome = novo.nome;
          if (novo.encerra) {
            etapas[idx].tipo_aprovador = 'arquivo';
            etapas[idx].aprovador_id   = '';
          } else {
            var tipoAnterior = etapas[idx].tipo_aprovador;
            var tipoValido = (tipoAnterior === 'usuario' || tipoAnterior === 'solicitante')
              ? tipoAnterior : 'grupo';
            // "Solicitante" é uma pessoa: sobrevive à troca para uma etapa que
            // aceita usuário; só cai fora se a etapa nova exigir grupo.
            if (novo.tipo === 'usuario' && tipoValido !== 'solicitante') tipoValido = 'usuario';
            if (novo.tipo === 'grupo')   tipoValido = 'grupo';
            if (etapas[idx].tipo_aprovador !== tipoValido) etapas[idx].aprovador_id = '';
            etapas[idx].tipo_aprovador = tipoValido;
          }
          if (novo.sla_horas != null) {
            // SLA vem do catálogo — limpa o SLA por template
            etapas[idx].sla_horas = null;
            etapas[idx].sla_valor = null;
          }
          if (novo.assinatura) {
            aoEscolherEtapaAssinatura(etapas[idx], novo);
            renderTudoAssinatura();
            return;
          }
        }
        renderEtapas();
      }
    );
    if (cat && cat.descricao) ddEtapa.title = cat.descricao;
    row.appendChild(ddEtapa);

    if (isArquivo) {
      // Etapa de arquivamento: sem aprovador nem SLA — o processo é concluído
      // e arquivado automaticamente ao atingir esta etapa.
      var infoAuto = document.createElement('div');
      infoAuto.className = 'etapa-auto-info';
      infoAuto.title = 'Ao atingir esta etapa o processo recebe os dados e é arquivado automaticamente, registrando quem executou o vínculo.';
      var infoIcon = document.createElement('i');
      infoIcon.setAttribute('data-lucide', 'archive');
      infoIcon.setAttribute('width', '14');
      infoIcon.setAttribute('height', '14');
      infoAuto.appendChild(infoIcon);
      var infoTxt = document.createElement('span');
      infoTxt.textContent = 'Encerra automaticamente — o processo é arquivado nesta etapa';
      infoAuto.appendChild(infoTxt);
      if (cat && cat.restrita) {
        var chipRestr = document.createElement('span');
        chipRestr.className = 'etapa-chip-restrita';
        chipRestr.textContent = 'Visualização restrita';
        infoAuto.appendChild(chipRestr);
      }
      row.appendChild(infoAuto);
      return row;
    }

    // 3a. Etapa "IR PARA": salto incondicional, um destino só.
    //     Mesma gravação da decisão (decisao_saltos), sob o índice 0 — nenhuma
    //     lista usa 0, o formulário só aceita índice a partir de 1.
    if (cat && cat.salto) {
      etapa.tipo_aprovador = 'decisao';
      etapa.aprovador_id   = '';
      etapa.sla_horas      = null;
      etapa.sla_valor      = null;
      etapa.decisao_campo  = '';
      if (!etapa.decisao_saltos || typeof etapa.decisao_saltos !== 'object') {
        etapa.decisao_saltos = {};
      }

      var boxIr = document.createElement('div');
      boxIr.className = 'etapa-decisao-box';
      boxIr.appendChild(tituloBloco('corner-down-right',
        'Ir para — o fluxo salta direto para esta etapa, pulando o que ficar no caminho'));

      var linhaIr = document.createElement('div');
      linhaIr.className = 'etapa-decisao-linha';
      var lblIr = document.createElement('span');
      lblIr.className = 'etapa-decisao-indice';
      lblIr.textContent = 'Ir para';
      linhaIr.appendChild(lblIr);
      var setaIr = document.createElement('span');
      setaIr.className = 'etapa-decisao-seta';
      setaIr.textContent = '→';
      linhaIr.appendChild(setaIr);
      linhaIr.appendChild(buildCustomDropdown(
        destinosDoTemplate(idx),
        etapa.decisao_saltos['0'] || '',
        '— Selecione a etapa —',
        readonly,
        function (val) {
          if (val) etapas[idx].decisao_saltos = { '0': val };
          else     etapas[idx].decisao_saltos = {};
        }
      ));
      linhaIr.appendChild(document.createElement('span'));
      boxIr.appendChild(linhaIr);

      row.appendChild(boxIr);
      return row;
    }

    // 3a-bis. Etapa "ABRIR FLUXO": encadeia outro template.
    if (cat && cat.abre_fluxo) {
      etapa.tipo_aprovador = 'abre_fluxo';
      etapa.aprovador_id   = '';
      etapa.sla_horas      = null;
      etapa.sla_valor      = null;

      var boxAf = document.createElement('div');
      boxAf.className = 'etapa-decisao-box';
      boxAf.appendChild(tituloBloco('git-fork',
        'Abrir Fluxo — abre um processo novo no template escolhido e encerra este'));

      if (templatesDestino.length === 0) {
        boxAf.appendChild(avisoDecisao(
          'Nenhum outro template publicado para abrir. Publique o template de destino primeiro.'
        ));
      } else {
        var linhaAf = document.createElement('div');
        linhaAf.className = 'etapa-decisao-linha';
        var lblAf = document.createElement('span');
        lblAf.className = 'etapa-decisao-indice';
        lblAf.textContent = 'Abrir';
        linhaAf.appendChild(lblAf);
        var setaAf = document.createElement('span');
        setaAf.className = 'etapa-decisao-seta';
        setaAf.textContent = '→';
        linhaAf.appendChild(setaAf);

        var optsTpl = [{ value: '', label: '— Selecione o fluxo —' }];
        templatesDestino.forEach(function (t) { optsTpl.push({ value: t.id, label: t.nome }); });
        linhaAf.appendChild(buildCustomDropdown(
          optsTpl, etapa.abre_template_id || '', '— Selecione o fluxo —', readonly,
          function (val) { etapas[idx].abre_template_id = val; }
        ));

        var notaAf = document.createElement('span');
        notaAf.className = 'etapa-decisao-rotulo';
        notaAf.textContent = 'leva nota, campos e anexos';
        linhaAf.appendChild(notaAf);
        boxAf.appendChild(linhaAf);
      }

      row.appendChild(boxAf);
      return row;
    }

    // 3b. Etapa de DECISÃO: no lugar de Usuário/Grupo e SLA, o mapa de saltos.
    var indices = indicesDaDecisao(cat);
    if (cat && cat.decisao_lista_id) {
      etapa.tipo_aprovador = 'decisao';
      etapa.aprovador_id   = '';
      etapa.sla_horas      = null;
      etapa.sla_valor      = null;
      if (!etapa.decisao_saltos || typeof etapa.decisao_saltos !== 'object') {
        etapa.decisao_saltos = {};
      }

      var box = document.createElement('div');
      box.className = 'etapa-decisao-box';

      box.appendChild(tituloBloco('git-branch',
        'Decisão automática — o fluxo salta para a etapa do índice escolhido'));

      // De qual campo do formulário sai o valor. Com um único campo ligado à
      // lista o vínculo é evidente e fica implícito — não faz sentido pedir ao
      // usuário para escolher entre uma opção só.
      var candidatos = camposDaLista(cat.decisao_lista_id);
      if (candidatos.length === 1) {
        etapa.decisao_campo = candidatos[0].nome;
      } else if (candidatos.length === 0) {
        etapa.decisao_campo = '';
        box.appendChild(avisoDecisao(
          'Nenhum campo da aba Campos usa esta lista — a decisão não teria o que ler. '
          + 'Crie um campo do tipo lista apontando para ela.'
        ));
      } else {
        // Dois ou mais campos da mesma lista: aí sim é ambíguo.
        var linhaCampo = document.createElement('div');
        linhaCampo.className = 'etapa-decisao-linha';
        var lblCampo = document.createElement('span');
        lblCampo.className = 'etapa-decisao-indice';
        lblCampo.textContent = 'Decide pelo campo';
        linhaCampo.appendChild(lblCampo);
        linhaCampo.appendChild(document.createElement('span'));
        var optsCampo = [{ value: '', label: '— Selecione o campo —' }];
        candidatos.forEach(function (c) { optsCampo.push({ value: c.nome, label: c.nome }); });
        linhaCampo.appendChild(buildCustomDropdown(
          optsCampo, etapa.decisao_campo || '', '— Selecione o campo —', readonly,
          function (val) { etapas[idx].decisao_campo = val; }
        ));
        box.appendChild(linhaCampo);
      }

      if (indices.length === 0) {
        box.appendChild(avisoDecisao(
          'A lista desta etapa não tem itens com índice. Defina os índices em '
          + 'Listas → itens da lista para que a decisão tenha caminhos.'
        ));
      }

      var destinoOptions = destinosDoTemplate(idx);

      indices.forEach(function (ix) {
        var chave = String(ix.indice);
        var linha = document.createElement('div');
        linha.className = 'etapa-decisao-linha';

        var lbl = document.createElement('span');
        lbl.className = 'etapa-decisao-indice';
        lbl.textContent = 'Índice ' + ix.indice;
        lbl.title = ix.rotulo;
        linha.appendChild(lbl);

        var seta = document.createElement('span');
        seta.className = 'etapa-decisao-seta';
        seta.textContent = '→';
        linha.appendChild(seta);

        linha.appendChild(buildCustomDropdown(
          destinoOptions,
          etapa.decisao_saltos[chave] || '',
          '— Selecione a etapa —',
          readonly,
          function (val) {
            if (val) etapas[idx].decisao_saltos[chave] = val;
            else delete etapas[idx].decisao_saltos[chave];
          }
        ));

        var rot = document.createElement('span');
        rot.className = 'etapa-decisao-rotulo';
        rot.textContent = ix.rotulo;
        linha.appendChild(rot);

        box.appendChild(linha);
      });

      row.appendChild(box);
      return row;
    }

    // 4. Tipo de aprovador (custom dropdown) — restrito ao que a etapa aceita
    var tipoOptions = [];
    if (!cat || cat.tipo === 'ambos' || cat.tipo === 'grupo')   tipoOptions.push({ value: 'grupo',   label: 'Grupo' });
    if (!cat || cat.tipo === 'ambos' || cat.tipo === 'usuario') tipoOptions.push({ value: 'usuario', label: 'Usuário' });
    // "Solicitante" é o responsável que o template não consegue nomear: cai em
    // quem abrir o processo, resolvido na criação. Vale onde cabe uma pessoa.
    if (!cat || cat.tipo === 'ambos' || cat.tipo === 'usuario') tipoOptions.push({ value: 'solicitante', label: 'Solicitante' });
    var tipoAtual = etapa.tipo_aprovador || tipoOptions[0].value;
    if (!tipoOptions.some(function (o) { return o.value === tipoAtual; })) {
      tipoAtual = tipoOptions[0].value;
      etapa.tipo_aprovador = tipoAtual;
      etapa.aprovador_id   = '';
    }
    var ddTipo = buildCustomDropdown(
      tipoOptions,
      tipoAtual,
      'Tipo',
      readonly || tipoOptions.length === 1,
      function(val) {
        etapas[idx].tipo_aprovador = val;
        etapas[idx].aprovador_id   = '';
        renderEtapas();
      }
    );
    if (cat && cat.tipo === 'grupo')   ddTipo.title = 'Esta etapa aceita apenas grupo (definido no cadastro da etapa)';
    if (cat && cat.tipo === 'usuario') ddTipo.title = 'Esta etapa aceita apenas pessoa — usuário fixo ou o solicitante (definido no cadastro da etapa)';
    row.appendChild(ddTipo);

    // 5. Seletor de grupo/usuário (custom dropdown)
    //    "Solicitante" não tem quem selecionar — o dono só existe quando o
    //    processo é aberto. No lugar do seletor entra o rótulo fixo.
    if (tipoAtual === 'solicitante') {
      etapa.aprovador_id = '';
      var fixo = document.createElement('div');
      fixo.className = 'etapa-aprovador-fixo';
      fixo.textContent = 'Quem abriu o processo';
      fixo.title = 'Esta etapa cai sempre na pessoa que abriu o processo, seja ela quem for.';
      row.appendChild(fixo);
    } else {
      var listaRaw = tipoAtual === 'usuario' ? todosUsuarios : grupos;
      var grupoOptions = [{ value: '', label: '— Selecione —' }];
      listaRaw.forEach(function(item) {
        grupoOptions.push({ value: item.id, label: item.nome });
      });
      var ddGrupo = buildCustomDropdown(
        grupoOptions,
        etapa.aprovador_id || '',
        '— Selecione —',
        readonly,
        function(val) {
          etapas[idx].aprovador_id = val;
        }
      );
      row.appendChild(ddGrupo);
    }

    // 6. SLA — herdado do catálogo quando a etapa define um SLA padrão
    if (cat && cat.sla_horas != null) {
      etapa.sla_horas = null;
      etapa.sla_valor = null;
      var slaFixo = document.createElement('div');
      slaFixo.className = 'etapa-sla-fixo';
      slaFixo.title = 'SLA padrão definido no cadastro da etapa — vale para todos os fluxos que a usam';
      var vf = horasParaValorUnidade(cat.sla_horas);
      slaFixo.textContent = 'SLA ' + vf.valor + (vf.unidade === 'minutos' ? 'min' : (vf.unidade === 'dias' ? 'd' : 'h'));
      row.appendChild(slaFixo);
      return row;
    }

    // SLA por template (etapa sem SLA padrão no catálogo)
    var slaState = etapa.sla_unidade
      ? { valor: etapa.sla_valor != null ? etapa.sla_valor : '', unidade: etapa.sla_unidade }
      : horasParaValorUnidade(etapa.sla_horas);

    function atualizarSla() {
      etapas[idx].sla_valor   = slaState.valor === '' ? null : slaState.valor;
      etapas[idx].sla_unidade = slaState.unidade;
      etapas[idx].sla_horas   = slaParaHoras(slaState.valor, slaState.unidade);
    }

    var inputSla = document.createElement('input');
    inputSla.type = 'number';
    inputSla.min = '0';
    inputSla.step = '1';
    inputSla.placeholder = 'SLA';
    inputSla.title = 'Prazo (SLA) para esta etapa';
    inputSla.value = slaState.valor;
    inputSla.readOnly = readonly;
    inputSla.addEventListener('input', function () {
      var v = parseFloat(this.value);
      slaState.valor = isNaN(v) || v <= 0 ? '' : v;
      atualizarSla();
    });
    row.appendChild(inputSla);

    var ddSlaUnidade = buildCustomDropdown(
      SLA_UNIDADES,
      slaState.unidade,
      'Unidade',
      readonly,
      function (val) {
        slaState.unidade = val;
        atualizarSla();
      }
    );
    row.appendChild(ddSlaUnidade);

    // Garante que sla_valor/sla_unidade/sla_horas fiquem sincronizados mesmo
    // sem interação do usuário (ex: templates legados que só tinham sla_horas)
    atualizarSla();

    return row;
  }

  var btnAddEtapa = document.getElementById('btn-add-etapa');
  if (btnAddEtapa) {
    btnAddEtapa.addEventListener('click', function () {
      etapas.push({ etapa_id: '', nome: '', tipo_aprovador: 'grupo', aprovador_id: '', sla_horas: null, sla_valor: null, sla_unidade: 'horas' });
      renderEtapas();
    });
  }

  // Habilitar financeiro insere a etapa obrigatória "Aprovação Financeiro" (se ainda não
  // existir) — responsável fica em branco, para o usuário selecionar manualmente.
  if (chkFinanceiro && !readonly) {
    chkFinanceiro.addEventListener('change', function () {
      if (this.checked) {
        var jaTem = etapas.some(function (e) { return e.nome === etapaFinanceiroNome; });
        if (!jaTem) {
          var catFin = etapasCatalogo.find(function (c) {
            return c.nome.trim().toLowerCase() === etapaFinanceiroNome.trim().toLowerCase();
          });
          etapas.push({
            etapa_id: catFin ? catFin.id : '',
            nome: etapaFinanceiroNome,
            tipo_aprovador: 'grupo',
            aprovador_id: '',
            sla_horas: null, sla_valor: null, sla_unidade: 'horas'
          });
          var tabEtapas = document.querySelector('.template-tab[data-tab="etapas"]');
          if (tabEtapas) tabEtapas.click();
        }
      }
      renderEtapas();
    });
  }

  // ════════════════════════════════════════════════════════════════════
  // ASSINATURA ELETRÔNICA — a trava
  //
  // Ligar o toggle monta o conjunto inteiro: etapa + anexo obrigatório +
  // campos do signatário. Sem isso o usuário publicaria um template que só
  // revelaria o problema em produção, com o processo já parado na etapa.
  // ════════════════════════════════════════════════════════════════════

  /** Índice (1-based) da etapa de assinatura, ou 0 se não existir. */
  function posEtapaAssinatura() {
    for (var i = 0; i < etapas.length; i++) {
      if (ehEtapaAssinatura(etapas[i])) return i + 1;
    }
    return 0;
  }

  /** Cria o que faltar e ajusta o que sobrar. Idempotente. */
  function montarAssinatura() {
    // 1. Etapa — no fim do fluxo, responsável em branco para o usuário definir.
    if (posEtapaAssinatura() === 0) {
      var nomePadrao = nomeEtapaDoProvedor();
      var cat = etapasCatalogo.find(function (c) {
        return c.nome.trim().toLowerCase() === nomePadrao.trim().toLowerCase();
      });
      etapas.push({
        etapa_id: cat ? cat.id : '',
        nome: nomePadrao,
        tipo_aprovador: 'grupo',
        aprovador_id: '',
        sla_horas: null, sla_valor: null, sla_unidade: 'horas'
      });
    }

    // 2. Anexo obrigatório, exigido na etapa ANTERIOR à assinatura.
    //    Preso à própria etapa de assinatura, o gate pediria o arquivo numa
    //    etapa que nenhum humano aprova — e o processo nunca sairia dali.
    var pos = posEtapaAssinatura();
    var ordemAnexo = pos > 1 ? pos - 1 : 1;
    var anexo = null;
    for (var a = 0; a < tiposAnexo.length; a++) {
      if (tiposAnexo[a].nome === anexoAssinaturaNome) { anexo = tiposAnexo[a]; break; }
    }
    if (!anexo) {
      tiposAnexo.push({
        nome: anexoAssinaturaNome,
        obrigatorio: true,
        tipo_doc: 'pdf',
        etapa_ordem: ordemAnexo
      });
    } else {
      anexo.obrigatorio = true;
      // Reancorar se a etapa de assinatura mudou de lugar e o anexo ficou
      // sendo exigido depois dela (ou em etapa que não existe mais).
      var oa = parseInt(anexo.etapa_ordem, 10);
      if (isNaN(oa) || oa < 1 || (pos > 0 && oa >= pos)) anexo.etapa_ordem = ordemAnexo;
    }

    // 3. Campos de cada papel.
    //
    // O casamento é por `origem`+`papel_idx`, NUNCA por nome. O papel é texto
    // livre: renomear "Signatário" para "Contratante" mudaria o nome dos dois
    // campos, e um casamento por nome criaria um par novo deixando o antigo
    // órfão e obrigatório — o solicitante passaria a preencher quatro campos
    // para um signatário só.
    signatarios.forEach(function (s, i) {
      defsCamposPapel(s.papel).forEach(function (def) {
        var achou = null;
        for (var k = 0; k < campos.length; k++) {
          if (campos[k].origem === 'assinatura' && campos[k].papel_idx === i && campos[k].qual === def[0]) {
            achou = campos[k];
            break;
          }
        }
        if (achou) {
          achou.nome = def[1];
          achou.tipo = def[2];
          achou.obrigatorio = def[3];
        } else {
          achou = {
            nome: def[1], tipo: def[2], obrigatorio: def[3],
            origem: 'assinatura', papel_idx: i, qual: def[0]
          };
          campos.push(achou);
        }
        // O tipo de assinatura é uma lista fixa (ID amarrado no servidor).
        if (def[0] === 'tipo') achou.lista_id = listaTiposAssinatura;
      });
    });

    // Papéis removidos — e campos de um provedor anterior (trocou Assinafy por
    // Clicksign) — deixam campos para trás.
    var qualDoProvedor = defsCamposPapel('x').map(function (d) { return d[0]; });
    removerOnde(campos, function (c) {
      return c.origem === 'assinatura' &&
        (c.papel_idx >= signatarios.length || qualDoProvedor.indexOf(c.qual) === -1);
    });
  }

  /**
   * Remove itens NO LUGAR (splice), nunca reatribuindo o array.
   * initDragDrop guarda a referência do array — trocar a referência deixaria o
   * drag-and-drop reordenando uma lista fantasma, e a tela e o estado
   * divergiriam sem erro nenhum aparecer.
   */
  function removerOnde(arr, pred) {
    for (var i = arr.length - 1; i >= 0; i--) {
      if (pred(arr[i])) arr.splice(i, 1);
    }
  }

  /** Remove tudo que a trava criou. */
  function desmontarAssinatura() {
    removerOnde(etapas,     ehEtapaAssinatura);
    removerOnde(tiposAnexo, function (a) { return a.nome === anexoAssinaturaNome; });
    removerOnde(campos,     function (c) { return c.origem === 'assinatura'; });
    // Religar o toggle recomeça do padrão (Assinafy, um papel).
    provedorAssinatura = 'assinafy';
    signatarios.length = 0;
  }

  /**
   * O usuário escolheu, no dropdown, uma etapa de ASSINATURA do catálogo.
   * Liga a trava se estiver desligada, adota o provedor da etapa e remonta
   * papéis e campos. Só pode haver uma etapa de assinatura por fluxo: uma
   * eventual outra (do outro provedor) sai.
   */
  function aoEscolherEtapaAssinatura(ref, cat) {
    var novoProv = cat.provedor === 'clicksign' ? 'clicksign' : 'assinafy';
    var mudou    = novoProv !== provedorAssinatura || !assinaturaAtiva();

    removerOnde(etapas, function (e) { return e !== ref && ehEtapaAssinatura(e); });
    provedorAssinatura = novoProv;

    if (!assinaturaAtiva() && chkAssinatura) {
      chkAssinatura.checked = true;
      var painel = document.getElementById('assinatura-info');
      if (painel) painel.style.display = 'block';
    }

    if (mudou) {
      // Campos de outro provedor não servem: recomeça os papéis do provedor novo.
      removerOnde(campos, function (c) { return c.origem === 'assinatura'; });
      signatarios.length = 0;
      if (novoProv === 'clicksign') {
        for (var n = 1; n <= SIGNATARIOS_CLICKSIGN; n++) {
          signatarios.push({ papel: 'Signatário ' + n, natureza: 'assina' });
        }
      } else {
        signatarios.push({ papel: 'Signatário', natureza: 'assina' });
      }
    }
    renderSignatarios();
    montarAssinatura();
  }

  // ── Lista de papéis ──────────────────────────────────────────────────

  function renderSignatarios() {
    if (!signatariosList) return;
    clearEl(signatariosList);

    var ehClick = provedorAssinatura === 'clicksign';
    var nota = document.getElementById('assinatura-clicksign-nota');
    if (nota) nota.style.display = ehClick ? 'block' : 'none';
    var nomeEt = document.getElementById('assinatura-etapa-nome');
    if (nomeEt) nomeEt.textContent = nomeEtapaDoProvedor();
    var btnAdd = document.getElementById('btn-add-signatario');
    if (btnAdd) btnAdd.style.display = ehClick ? 'none' : '';

    signatarios.forEach(function (s, idx) {
      var row = document.createElement('div');
      row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px;';

      var inp = document.createElement('input');
      inp.type = 'text';
      inp.value = s.papel || '';
      inp.maxLength = 60;
      inp.placeholder = 'Papel (ex.: Contratante, Testemunha)';
      inp.readOnly = readonly;
      inp.style.cssText = 'flex:1;min-width:0;height:40px;border:1px solid #DFDFDF;border-radius:4px;' +
                          'padding:0 10px;font-size:13px;color:#141922;';
      inp.addEventListener('input', function () {
        signatarios[idx].papel = this.value;
        if (assinaturaAtiva()) { montarAssinatura(); renderCampos(); }
      });
      row.appendChild(inp);

      var sel = document.createElement('select');
      sel.disabled = readonly || ehClick;   // Clicksign: todos assinam
      sel.style.cssText = 'width:150px;flex-shrink:0;height:40px;border:1px solid #DFDFDF;border-radius:4px;' +
                          'padding:0 8px;font-size:13px;color:#141922;background:#FFFFFF;';
      Object.keys(assinaturaNaturezas).forEach(function (k) {
        var o = document.createElement('option');
        o.value = k;
        o.textContent = assinaturaNaturezas[k];
        if ((s.natureza || 'assina') === k) o.selected = true;
        sel.appendChild(o);
      });
      sel.addEventListener('change', function () { signatarios[idx].natureza = this.value; });
      row.appendChild(sel);

      // O último que assina não pode sair: sem ninguém assinando, o documento
      // sairia e nunca voltaria. O publish barraria — melhor impedir aqui.
      var podeRemover = !readonly && signatarios.length > 1 && !ehClick;
      if (podeRemover) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-remove-item';
        btn.title = 'Remover papel';
        var ic = document.createElement('i');
        ic.setAttribute('data-lucide', 'x');
        ic.setAttribute('width', '14');
        ic.setAttribute('height', '14');
        btn.appendChild(ic);
        btn.addEventListener('click', function () {
          // A ORDEM aqui importa e não é a intuitiva.
          //
          // 1º apagar os campos do papel que sai; só DEPOIS reindexar os de
          // cima. Ao contrário, os campos do índice removido continuam com o
          // mesmo papel_idx dos que desceram para o lugar dele — sobram dois
          // pares no mesmo índice, e o remontar renomeia os dois para o papel
          // seguinte. Resultado: quatro campos, dois pares idênticos.
          removerOnde(campos, function (c) {
            return c.origem === 'assinatura' && c.papel_idx === idx;
          });
          campos.forEach(function (c) {
            if (c.origem === 'assinatura' && c.papel_idx > idx) c.papel_idx--;
          });
          signatarios.splice(idx, 1);

          renderSignatarios();
          if (assinaturaAtiva()) { montarAssinatura(); renderCampos(); }
        });
        row.appendChild(btn);
      } else {
        row.appendChild(lockSpacer(ehClick
          ? 'A assinatura pela Clicksign tem sempre dois signatários'
          : 'É preciso ao menos um participante'));
      }

      signatariosList.appendChild(row);
    });

    if (window.lucide) window.lucide.createIcons();
  }

  var btnAddSignatario = document.getElementById('btn-add-signatario');
  if (btnAddSignatario && !readonly) {
    btnAddSignatario.addEventListener('click', function () {
      if (signatarios.length >= assinaturaMax) {
        btModalErro('Limite de participantes',
          'São no máximo ' + assinaturaMax + ' participantes por etapa de assinatura.', 'warning');
        return;
      }
      signatarios.push({ papel: '', natureza: 'assina' });
      renderSignatarios();
    });
  }

  function renderTudoAssinatura() {
    renderEtapas();
    renderCampos();
    renderAnexos();
  }

  if (chkAssinatura && !readonly) {
    chkAssinatura.addEventListener('change', function () {
      var painel = document.getElementById('assinatura-info');
      var self   = this;

      if (self.checked) {
        if (painel) painel.style.display = 'block';
        if (signatarios.length === 0) signatarios.push({ papel: 'Signatário', natureza: 'assina' });
        renderSignatarios();
        montarAssinatura();
        renderTudoAssinatura();
        return;
      }

      // Desligar apaga etapa, anexo e campos. Confirmar antes: o responsável
      // definido na etapa se perde junto, e um clique errado no toggle não
      // pode desmontar o fluxo sem avisar.
      //
      // btModalConfirm não avisa quando o usuário cancela (nem no botão, nem
      // no clique fora). Por isso o toggle volta a ligado JÁ, e só desliga
      // dentro do onConfirm — cancelar de qualquer jeito deixa tudo como está.
      self.checked = true;
      btModalConfirm(
        'Desligar assinatura eletrônica',
        'Isto remove a etapa "' + nomeEtapaDoProvedor() + '", o anexo "' + anexoAssinaturaNome +
        '" e os campos de signatário. O responsável já definido na etapa será perdido. Continuar?',
        function () {
          self.checked = false;
          if (painel) painel.style.display = 'none';
          desmontarAssinatura();
          renderTudoAssinatura();
        },
        { tipo: 'error', confirmLabel: 'Desligar e remover', confirmBg: '#B3261E' }
      );
    });
  }

  // Estado inicial da lista de papéis (inclusive em somente-leitura)
  if (signatarios.length === 0) signatarios.push({ papel: 'Signatário', natureza: 'assina' });
  renderSignatarios();


  // ════════════════════════════════════════════════════════════════════
  // CAMPOS
  // ════════════════════════════════════════════════════════════════════

  function renderCampos() {
    if (!camposList) return;
    clearEl(camposList);

    if (campos.length === 0) {
      var emptyMsg = document.createElement('div');
      emptyMsg.className = 'empty-state';
      emptyMsg.style.padding = '40px';
      var icon = document.createElement('i');
      icon.setAttribute('data-lucide', 'form-input');
      icon.setAttribute('width', '36');
      icon.setAttribute('height', '36');
      emptyMsg.appendChild(icon);
      var p = document.createElement('p');
      p.textContent = 'Nenhum campo extra adicionado.';
      emptyMsg.appendChild(p);
      var small = document.createElement('small');
      small.textContent = 'Os campos padrão (Fornecedor, Número NF, Valor, Data NF) são incluídos automaticamente.';
      emptyMsg.appendChild(small);
      camposList.appendChild(emptyMsg);
      if (window.lucide) window.lucide.createIcons();
      return;
    }

    campos.forEach(function (campo, idx) {
      camposList.appendChild(buildCampoRow(campo, idx));
    });

    if (window.lucide) window.lucide.createIcons();
    initDragDrop(camposList, campos, renderCampos);
  }

  function buildCampoRow(campo, idx) {
    var row = document.createElement('div');
    row.className = 'campo-row';
    row.draggable = !readonly;
    row.dataset.idx = idx;

    // Handle drag
    var handle = document.createElement('div');
    handle.className = 'etapa-drag-handle';
    var handleIcon = document.createElement('i');
    handleIcon.setAttribute('data-lucide', 'grip-vertical');
    handleIcon.setAttribute('width', '14');
    handleIcon.setAttribute('height', '14');
    handle.appendChild(handleIcon);
    row.appendChild(handle);

    // Nome do campo
    var inputNome = document.createElement('input');
    inputNome.type = 'text';
    inputNome.placeholder = 'Nome do campo';
    inputNome.value = campo.nome || '';
    inputNome.readOnly = readonly;
    inputNome.addEventListener('input', function () {
      campos[idx].nome = this.value;
    });
    row.appendChild(inputNome);

    // Tipo
    var selTipo = document.createElement('select');
    selTipo.className = 'campo-tipo-select';
    selTipo.disabled = readonly;
    Object.keys(tiposCampo).forEach(function (key) {
      var o = document.createElement('option');
      o.value = key;
      o.textContent = tiposCampo[key];
      if ((campo.tipo || 'texto') === key) o.selected = true;
      selTipo.appendChild(o);
    });
    selTipo.addEventListener('change', function () {
      campos[idx].tipo = this.value;
      if (this.value !== 'select') {
        delete campos[idx].lista_id;
      }
      renderCampos();
    });
    row.appendChild(selTipo);

    // Exigido em: abertura do processo (padrão) ou uma etapa específica.
    //
    // Mesma mecânica do anexo: campo preso a uma etapa NÃO bloqueia a abertura
    // — o solicitante abre o processo sem ele, e quem está na etapa preenche.
    // Serve para o dado que só existe (ou só interessa) no meio do fluxo, e que
    // uma etapa seguinte vai usar.
    var selEtapaCampo = document.createElement('select');
    selEtapaCampo.className = 'campo-etapa-select';
    selEtapaCampo.disabled = readonly;
    selEtapaCampo.title = 'Momento em que este campo passa a ser exigido';
    var optAberturaCampo = document.createElement('option');
    optAberturaCampo.value = '';
    optAberturaCampo.textContent = 'Exigido na abertura';
    selEtapaCampo.appendChild(optAberturaCampo);
    etapas.forEach(function (et, i) {
      var o = document.createElement('option');
      o.value = String(i + 1);
      o.textContent = 'Etapa ' + (i + 1) + (et.nome ? ' — ' + et.nome : '');
      if (parseInt(campo.etapa_ordem, 10) === i + 1) o.selected = true;
      selEtapaCampo.appendChild(o);
    });
    // Vínculo órfão (etapa removida): fica visível para o usuário corrigir
    if (campo.etapa_ordem && parseInt(campo.etapa_ordem, 10) > etapas.length) {
      var oOrfaCampo = document.createElement('option');
      oOrfaCampo.value = String(campo.etapa_ordem);
      oOrfaCampo.textContent = 'Etapa ' + campo.etapa_ordem + ' (não existe mais!)';
      oOrfaCampo.selected = true;
      oOrfaCampo.style.color = '#B3261E';
      selEtapaCampo.appendChild(oOrfaCampo);
    }
    selEtapaCampo.addEventListener('change', function () {
      campos[idx].etapa_ordem = this.value === '' ? null : parseInt(this.value, 10);
    });
    row.appendChild(selEtapaCampo);

    // Obrigatório (toggle switch)
    var labelToggle = document.createElement('label');
    labelToggle.className = 'campo-obrig-toggle';
    var chkObrig = document.createElement('input');
    chkObrig.type = 'checkbox';
    chkObrig.checked = !!campo.obrigatorio;
    chkObrig.disabled = readonly;
    chkObrig.addEventListener('change', function () {
      campos[idx].obrigatorio = this.checked;
    });
    var sliderObrig = document.createElement('span');
    sliderObrig.className = 'toggle-slider';
    var textObrig = document.createElement('span');
    textObrig.className = 'campo-obrig-label';
    textObrig.textContent = 'Obrigatório';
    labelToggle.appendChild(chkObrig);
    labelToggle.appendChild(sliderObrig);
    labelToggle.appendChild(textObrig);
    row.appendChild(labelToggle);

    // Botão remover — campos do signatário ficam travados enquanto a
    // assinatura estiver ligada (são eles que dizem para quem enviar).
    var campoTravado = assinaturaAtiva() && nomesCamposSignatario().indexOf(campo.nome) !== -1;
    if (campoTravado) {
      row.appendChild(lockSpacer(
        'Campo obrigatório da assinatura eletrônica — desligue o controle na aba Configuração para remover'
      ));
    }
    if (!readonly && !campoTravado) {
      var btnRem = document.createElement('button');
      btnRem.type = 'button';
      btnRem.className = 'btn-remove-item';
      btnRem.title = 'Remover campo';
      var remIcon = document.createElement('i');
      remIcon.setAttribute('data-lucide', 'x');
      remIcon.setAttribute('width', '14');
      remIcon.setAttribute('height', '14');
      btnRem.appendChild(remIcon);
      btnRem.addEventListener('click', function () {
        btModalConfirm('Remover campo', 'Remover o campo ' + JSON.stringify(campo.nome || ('Campo ' + (idx + 1))) + '?', function () {
          campos.splice(idx, 1);
          renderCampos();
        }, {tipo: 'error', confirmLabel: 'Remover', confirmBg: '#B3261E'});
      });
      row.appendChild(btnRem);
    }

    // Linha extra: seletor de fonte quando tipo = select/lista
    if ((campo.tipo === 'select' || campo.tipo === 'lista') && fontesLista.length > 0) {
      var wrapper = document.createElement('div');
      wrapper.appendChild(row);

      var fonteRow = document.createElement('div');
      fonteRow.style.cssText = 'display:flex; align-items:center; gap:8px; margin:0 0 8px 28px; padding:6px 12px; background:#EEF2FD; border:1px solid #FFD6D6; border-radius:6px;';
      var fonteLabel = document.createElement('span');
      fonteLabel.style.cssText = 'font-size:11px; font-weight:700; color:#2347C8; white-space:nowrap;';
      fonteLabel.textContent = 'Fonte dos dados:';
      fonteRow.appendChild(fonteLabel);

      var selFonte = document.createElement('select');
      selFonte.style.cssText = 'flex:1; height:28px; border:1px solid #FFD6D6; border-radius:4px; font-size:12px; color:#141922; background:#fff; outline:none; padding:0 8px;';
      selFonte.disabled = readonly;
      var optVazioFonte = document.createElement('option');
      optVazioFonte.value = '';
      optVazioFonte.textContent = 'Selecionar fonte de dados...';
      selFonte.appendChild(optVazioFonte);
      fontesLista.forEach(function (f) {
        var o = document.createElement('option');
        o.value = f.id;
        o.textContent = f.nome;
        if ((campo.lista_id || '') === f.id) o.selected = true;
        selFonte.appendChild(o);
      });
      var previewDiv = document.createElement('div');
      previewDiv.style.cssText = 'margin:0 0 8px 28px;';

      selFonte.addEventListener('change', function () {
        campos[idx].lista_id = this.value;
        carregarPreviewFonte(this.value, previewDiv);
      });
      fonteRow.appendChild(selFonte);
      wrapper.appendChild(fonteRow);
      wrapper.appendChild(previewDiv);

      if (campo.lista_id) {
        carregarPreviewFonte(campo.lista_id, previewDiv);
      }

      return wrapper;
    }

    return row;
  }

  function carregarPreviewFonte(fonteId, container) {
    container.innerHTML = '';
    if (!fonteId) return;

    container.innerHTML = '<span style="font-size:11px;color:#888;">Carregando itens...</span>';

    fetch('?module=templates&action=listar_fonte&fonte_id=' + encodeURIComponent(fonteId))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        container.innerHTML = '';
        if (!data.itens || data.itens.length === 0) {
          container.innerHTML = '<div style="font-size:11px;color:#888;padding:4px 12px;background:#F8F8F8;border-radius:4px;">Nenhum item cadastrado nesta lista.</div>';
          return;
        }

        var wrap = document.createElement('div');
        wrap.style.cssText = 'padding:8px 12px;background:#F8F8F8;border:1px solid #EAEAEA;border-radius:6px;';

        var header = document.createElement('div');
        header.style.cssText = 'display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;';
        var countText = document.createElement('span');
        countText.style.cssText = 'font-size:11px;font-weight:700;color:#606060;';
        countText.textContent = data.total + ' item(s) cadastrado(s)';
        header.appendChild(countText);

        if (data.editavel && data.edit_url) {
          var editLink = document.createElement('a');
          editLink.href = data.edit_url;
          editLink.target = '_blank';
          editLink.style.cssText = 'font-size:11px;font-weight:600;color:#2347C8;text-decoration:none;display:inline-flex;align-items:center;gap:3px;';
          editLink.textContent = 'Ver / Editar lista →';
          header.appendChild(editLink);
        }
        wrap.appendChild(header);

        var chips = document.createElement('div');
        chips.style.cssText = 'display:flex;flex-wrap:wrap;gap:4px;';
        data.itens.forEach(function (nome) {
          var chip = document.createElement('span');
          chip.style.cssText = 'display:inline-block;padding:2px 8px;background:#fff;border:1px solid #DFDFDF;border-radius:4px;font-size:11px;color:#141922;';
          chip.textContent = nome;
          chips.appendChild(chip);
        });
        wrap.appendChild(chips);
        container.appendChild(wrap);
      })
      .catch(function () {
        container.innerHTML = '<span style="font-size:11px;color:#B3261E;">Erro ao carregar itens.</span>';
      });
  }

  var btnAddCampo = document.getElementById('btn-add-campo');
  if (btnAddCampo) {
    btnAddCampo.addEventListener('click', function () {
      campos.push({ nome: '', tipo: 'texto', obrigatorio: false });
      renderCampos();
    });
  }

  // ════════════════════════════════════════════════════════════════════
  // ANEXOS
  // ════════════════════════════════════════════════════════════════════

  function renderAnexos() {
    if (!anexosList) return;
    clearEl(anexosList);

    if (tiposAnexo.length === 0) {
      var emptyMsg = document.createElement('div');
      emptyMsg.className = 'empty-state';
      emptyMsg.style.padding = '40px';
      var icon = document.createElement('i');
      icon.setAttribute('data-lucide', 'paperclip');
      icon.setAttribute('width', '36');
      icon.setAttribute('height', '36');
      emptyMsg.appendChild(icon);
      var p = document.createElement('p');
      p.textContent = 'Nenhum tipo de anexo definido.';
      emptyMsg.appendChild(p);
      anexosList.appendChild(emptyMsg);
      if (window.lucide) window.lucide.createIcons();
      return;
    }

    tiposAnexo.forEach(function (anexo, idx) {
      anexosList.appendChild(buildAnexoRow(anexo, idx));
    });

    if (window.lucide) window.lucide.createIcons();
    initDragDrop(anexosList, tiposAnexo, renderAnexos);
  }

  var tiposDoc = [
    { value: 'qualquer',   label: 'Qualquer formato' },
    { value: 'pdf',        label: 'PDF' },
    { value: 'imagem',     label: 'Imagem (JPG, PNG, WEBP)' },
    { value: 'pdf_imagem', label: 'PDF ou Imagem' },
    { value: 'excel',      label: 'Planilha (XLS, XLSX)' },
    { value: 'word',       label: 'Word (DOC, DOCX)' },
    { value: 'video',      label: 'Áudio/Vídeo (MP4, WEBM, MOV, OGG, MP3, M4A, WAV) — máx. 5 MB' },
  ];

  function buildAnexoRow(anexo, idx) {
    var row = document.createElement('div');
    row.className = 'anexo-row';
    row.draggable = !readonly;

    // Handle drag
    var handle = document.createElement('div');
    handle.className = 'etapa-drag-handle';
    var handleIcon = document.createElement('i');
    handleIcon.setAttribute('data-lucide', 'grip-vertical');
    handleIcon.setAttribute('width', '14');
    handleIcon.setAttribute('height', '14');
    handle.appendChild(handleIcon);
    row.appendChild(handle);

    // Nome do tipo de anexo
    var inputNome = document.createElement('input');
    inputNome.type = 'text';
    inputNome.placeholder = 'Ex: Nota Fiscal, Boleto, Contrato';
    inputNome.value = anexo.nome || '';
    inputNome.readOnly = readonly;
    inputNome.addEventListener('input', function () {
      tiposAnexo[idx].nome = this.value;
    });
    row.appendChild(inputNome);

    // Tipo de documento
    var selTipoDoc = document.createElement('select');
    selTipoDoc.disabled = readonly;
    selTipoDoc.className = 'anexo-tipo-doc-select';
    tiposDoc.forEach(function (td) {
      var o = document.createElement('option');
      o.value = td.value;
      o.textContent = td.label;
      if ((anexo.tipo_doc || 'qualquer') === td.value) o.selected = true;
      selTipoDoc.appendChild(o);
    });
    selTipoDoc.addEventListener('change', function () {
      tiposAnexo[idx].tipo_doc = this.value;
    });
    row.appendChild(selTipoDoc);

    // Exigido em: abertura do processo (padrão) ou etapa específica.
    // Anexo de etapa não bloqueia a abertura — bloqueia a aprovação da etapa.
    var selEtapa = document.createElement('select');
    selEtapa.disabled = readonly;
    selEtapa.title = 'Momento em que este anexo passa a ser exigido';
    var optAbertura = document.createElement('option');
    optAbertura.value = '';
    optAbertura.textContent = 'Abertura';
    selEtapa.appendChild(optAbertura);
    etapas.forEach(function (et, i) {
      var o = document.createElement('option');
      o.value = String(i + 1);
      o.textContent = 'Etapa ' + (i + 1) + (et.nome ? ' — ' + et.nome : '');
      if (parseInt(anexo.etapa_ordem, 10) === i + 1) o.selected = true;
      selEtapa.appendChild(o);
    });
    // Vínculo órfão (etapa removida): mantém visível para o usuário corrigir
    if (anexo.etapa_ordem && parseInt(anexo.etapa_ordem, 10) > etapas.length) {
      var oOrfa = document.createElement('option');
      oOrfa.value = String(anexo.etapa_ordem);
      oOrfa.textContent = 'Etapa ' + anexo.etapa_ordem + ' (não existe mais!)';
      oOrfa.selected = true;
      oOrfa.style.color = '#B3261E';
      selEtapa.appendChild(oOrfa);
    }
    selEtapa.addEventListener('change', function () {
      tiposAnexo[idx].etapa_ordem = this.value === '' ? null : parseInt(this.value, 10);
    });
    row.appendChild(selEtapa);

    // Obrigatório — toggle switch
    var labelToggle = document.createElement('label');
    labelToggle.className = 'campo-obrig-toggle';
    var chkObrig = document.createElement('input');
    chkObrig.type = 'checkbox';
    chkObrig.checked = !!anexo.obrigatorio;
    chkObrig.disabled = readonly;
    chkObrig.addEventListener('change', function () {
      tiposAnexo[idx].obrigatorio = this.checked;
    });
    var sliderObrig = document.createElement('span');
    sliderObrig.className = 'toggle-slider';
    var textObrig = document.createElement('span');
    textObrig.className = 'campo-obrig-label';
    textObrig.textContent = 'Obrigatório';
    labelToggle.appendChild(chkObrig);
    labelToggle.appendChild(sliderObrig);
    labelToggle.appendChild(textObrig);
    row.appendChild(labelToggle);

    // Remover — o documento que vai para assinatura fica travado.
    var anexoTravado = assinaturaAtiva() && anexo.nome === anexoAssinaturaNome;
    if (anexoTravado) {
      row.appendChild(lockSpacer(
        'Anexo obrigatório da assinatura eletrônica — é o arquivo enviado para assinar'
      ));
    }
    if (!readonly && !anexoTravado) {
      var btnRem = document.createElement('button');
      btnRem.type = 'button';
      btnRem.className = 'btn-remove-item';
      btnRem.title = 'Remover';
      var remIcon = document.createElement('i');
      remIcon.setAttribute('data-lucide', 'x');
      remIcon.setAttribute('width', '14');
      remIcon.setAttribute('height', '14');
      btnRem.appendChild(remIcon);
      btnRem.addEventListener('click', function () {
        btModalConfirm('Remover tipo de anexo', 'Remover ' + JSON.stringify(anexo.nome || 'este tipo de anexo') + '?', function () {
          tiposAnexo.splice(idx, 1);
          renderAnexos();
        }, {tipo: 'error', confirmLabel: 'Remover', confirmBg: '#B3261E'});
      });
      row.appendChild(btnRem);
    }

    return row;
  }

  var btnAddAnexo = document.getElementById('btn-add-anexo');
  if (btnAddAnexo) {
    btnAddAnexo.addEventListener('click', function () {
      tiposAnexo.push({ nome: '', obrigatorio: true, tipo_doc: 'qualquer' });
      renderAnexos();
    });
  }

  // ════════════════════════════════════════════════════════════════════
  // DRAG AND DROP (HTML5 nativo — sem dependências)
  // ════════════════════════════════════════════════════════════════════

  function initDragDrop(container, dataArray, renderFn) {
    if (readonly) return;
    var dragSrc = null;

    container.querySelectorAll('[draggable="true"]').forEach(function (row) {
      row.addEventListener('dragstart', function (e) {
        dragSrc = row;
        row.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', row.dataset.idx);
      });

      row.addEventListener('dragend', function () {
        row.classList.remove('dragging');
        container.querySelectorAll('.drag-over').forEach(function (el) {
          el.classList.remove('drag-over');
        });
      });

      row.addEventListener('dragover', function (e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (row !== dragSrc) row.classList.add('drag-over');
      });

      row.addEventListener('dragleave', function () {
        row.classList.remove('drag-over');
      });

      row.addEventListener('drop', function (e) {
        e.preventDefault();
        if (row === dragSrc) return;
        row.classList.remove('drag-over');

        var srcIdx  = parseInt(dragSrc.dataset.idx, 10);
        var destIdx = parseInt(row.dataset.idx, 10);
        if (isNaN(srcIdx) || isNaN(destIdx)) return;

        var moved = dataArray.splice(srcIdx, 1)[0];
        dataArray.splice(destIdx, 0, moved);
        renderFn();
      });
    });
  }

  // ── Renderização inicial ─────────────────────────────────────────────
  renderEtapas();
  renderCampos();
  renderAnexos();

  // Re-inicializar ícones Lucide após render
  if (window.lucide) window.lucide.createIcons();

}());

// ── Painel de seleção de usuários UAT ─────────────────────────────────
(function () {
  'use strict';

  var panel     = document.getElementById('uat-panel');
  var searchBox = document.getElementById('uat-user-search');
  if (!panel || !searchBox) return;

  var resultsDiv  = document.getElementById('uat-user-results');
  var selectedDiv = document.getElementById('uat-selected-users');
  var confirmBtn  = document.getElementById('uat-confirm-btn');
  var cancelBtn   = document.getElementById('uat-cancel-btn');

  var editorEl = document.getElementById('editor-data');
  var allUsers = JSON.parse(editorEl.dataset.todosUsuarios || '[]');
  var selectedIds = [];
  var uatForm = null;

  // Interceptar botão "Abrir para Usuários" → mostrar painel
  document.querySelectorAll('.btn-avancar-status').forEach(function (btn) {
    if (btn.dataset.novoStatus === 'uat') {
      btn.addEventListener('click', function (e) {
        e.stopImmediatePropagation();
        uatForm = this.closest('form');
        panel.style.display = 'block';
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        searchBox.focus();
      }, true);
    }
  });

  // Busca por nome
  searchBox.addEventListener('input', function () {
    var q = this.value.trim().toLowerCase();
    resultsDiv.innerHTML = '';
    if (q.length < 2) { resultsDiv.style.display = 'none'; return; }

    var matches = allUsers.filter(function (u) {
      return u.nome.toLowerCase().indexOf(q) !== -1 && selectedIds.indexOf(u.id) === -1;
    }).slice(0, 8);

    if (!matches.length) { resultsDiv.style.display = 'none'; return; }

    matches.forEach(function (u) {
      var opt = document.createElement('div');
      opt.style.cssText = 'padding:8px 14px;font-size:13px;color:#141922;cursor:pointer;';
      opt.textContent = u.nome;
      opt.addEventListener('mousedown', function (ev) {
        ev.preventDefault();
        addUser(u);
        searchBox.value = '';
        resultsDiv.style.display = 'none';
      });
      opt.addEventListener('mouseover', function () { this.style.background = '#FFF5F5'; });
      opt.addEventListener('mouseout',  function () { this.style.background = ''; });
      resultsDiv.appendChild(opt);
    });
    resultsDiv.style.display = 'block';
  });

  searchBox.addEventListener('blur', function () {
    setTimeout(function () { resultsDiv.style.display = 'none'; }, 200);
  });

  function addUser(u) {
    if (selectedIds.indexOf(u.id) !== -1) return;
    selectedIds.push(u.id);
    renderChips();
  }

  function removeUser(id) {
    selectedIds = selectedIds.filter(function (x) { return x !== id; });
    renderChips();
  }

  function renderChips() {
    selectedDiv.innerHTML = '';
    selectedIds.forEach(function (id) {
      var user = allUsers.find(function (u) { return u.id === id; });
      if (!user) return;
      var chip = document.createElement('span');
      chip.className = 'uat-chip';
      var nameSpan = document.createElement('span');
      nameSpan.textContent = user.nome;
      chip.appendChild(nameSpan);
      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.textContent = '×';
      removeBtn.addEventListener('click', function () { removeUser(id); });
      chip.appendChild(removeBtn);
      selectedDiv.appendChild(chip);
    });

    var hasUsers = selectedIds.length > 0;
    confirmBtn.disabled = !hasUsers;
    confirmBtn.style.opacity = hasUsers ? '1' : '0.5';
    confirmBtn.style.cursor  = hasUsers ? 'pointer' : 'not-allowed';
  }

  // Confirmar → injetar uat_usuario_ids e submeter form
  confirmBtn.addEventListener('click', function () {
    if (!selectedIds.length || !uatForm) return;
    var hidden = document.createElement('input');
    hidden.type  = 'hidden';
    hidden.name  = 'uat_usuario_ids';
    hidden.value = JSON.stringify(selectedIds);
    uatForm.appendChild(hidden);
    uatForm.submit();
  });

  // Cancelar
  cancelBtn.addEventListener('click', function () {
    panel.style.display = 'none';
    selectedIds = [];
    searchBox.value = '';
    renderChips();
  });
}());
