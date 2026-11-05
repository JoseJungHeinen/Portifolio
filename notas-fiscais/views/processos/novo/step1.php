<?php
// @versao Proc_03 V_08_08/08/2026
/**
 * views/processos/novo/step1.php
 * Wizard — Etapa 1: Seleção de Template
 *
 * Variáveis esperadas:
 *   $filiais    array  filiais ativas
 *   $setores    array  setores ativos
 *   $tiposNota  array  tipos de nota da lista
 *   $flash      array|null
 */

$filiais   = $filiais   ?? [];
$setores   = $setores   ?? [];
$tiposNota = $tiposNota ?? [];

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>

<?php if (!empty($flash)): ?>
<?php
$alertType    = $flash['type']    ?? 'info';
$alertMessage = $flash['message'] ?? '';
include VIEWS . '/components/alert.php';
?>
<?php endif; ?>

<!-- Cabeçalho + indicador de progresso (mesma linha) -->
<div style="margin-bottom:28px; display:flex; align-items:flex-end; justify-content:space-between; gap:24px; flex-wrap:wrap">
  <div>
    <div style="display:flex; align-items:center; gap:12px; margin-bottom:8px">
      <a href="?module=processos&amp;action=index"
         style="display:inline-flex; align-items:center; gap:6px; color:#606060; text-decoration:none; font-size:14px"
         onmouseover="this.style.color='#2347C8'" onmouseout="this.style.color='#606060'">
        <i data-lucide="arrow-left" width="16" height="16"></i>
        Voltar
      </a>
    </div>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
      Novo <span style="color:#2347C8">Processo</span>
    </h1>
    <p style="font-size:14px; color:#888; margin:0">Selecione o template de aprovação adequado para a nota fiscal</p>
  </div>

  <div class="wizard-steps" style="padding-bottom:4px">
    <div class="wizard-step is-active">
      <div class="wizard-step-number">1</div>
      <div class="wizard-step-label">Selecionar Template</div>
    </div>
    <div class="wizard-step-connector"></div>
    <div class="wizard-step">
      <div class="wizard-step-number">2</div>
      <div class="wizard-step-label">Dados da NF</div>
    </div>
    <div class="wizard-step-connector"></div>
    <div class="wizard-step">
      <div class="wizard-step-number">3</div>
      <div class="wizard-step-label">Revisão</div>
    </div>
  </div>
</div>

<!-- Layout: formulário à esquerda, sugestões de template à direita -->
<div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap">

<!-- Formulário de contexto -->
<div style="flex:0 0 680px; max-width:680px; background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:28px">
  <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 20px">Contexto da Nota Fiscal</h2>

  <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px">

    <!-- Filial -->
    <div class="campo-dinamico">
      <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
        Filial <span style="color:#B3261E">*</span>
      </label>
      <select id="filial_id" name="filial_id" required
              style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; outline:none"
              onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <option value="">Selecione a filial...</option>
        <?php foreach ($filiais as $f): ?>
        <option value="<?= h($f['id']) ?>"><?= h($f['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Departamento (filtra os setores) -->
    <div class="campo-dinamico">
      <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
        Departamento <span style="color:#B3261E">*</span>
      </label>
      <select id="departamento_id" required onchange="filtrarSetores(this.value); filtrarTiposNota(this.value)"
              style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; outline:none"
              onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <option value="">Selecione o departamento...</option>
        <?php $departamentos = $departamentos ?? []; ?>
        <?php foreach ($departamentos as $d): ?>
        <option value="<?= h($d['id']) ?>"><?= h($d['valor']) ?></option>
        <?php endforeach; ?>
        <option value="__sem__">— Setores sem departamento —</option>
      </select>
    </div>

    <!-- Setor (conforme o departamento) -->
    <div class="campo-dinamico">
      <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
        Setor <span style="color:#B3261E">*</span>
      </label>
      <select id="setor_id" name="setor_id" required disabled
              style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; outline:none; background:#fff"
              onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <option value="">Selecione o departamento primeiro...</option>
      </select>
    </div>

    <script>
    // Cascata Departamento → Setor (vínculo configurado em Listas → Setores)
    var SETORES_TODOS = <?= json_encode(array_map(fn($s) => [
        'id'     => $s['id'],
        'nome'   => $s['nome'],
        'depto'  => $s['departamento_id'] ?? null,
    ], $setores), JSON_UNESCAPED_UNICODE) ?>;

    // Tipo de Nota segue o departamento: tipo marcado para outros
    // departamentos some da lista. Sem marcação = vale para todos.
    var TIPOS_NOTA_HTML = null;
    function filtrarTiposNota(deptoId) {
        var sel = document.getElementById('tipo_nota_id');
        if (!sel) return;
        if (TIPOS_NOTA_HTML === null) TIPOS_NOTA_HTML = sel.innerHTML;
        var escolhido = sel.value;
        sel.innerHTML = TIPOS_NOTA_HTML;
        Array.prototype.slice.call(sel.options).forEach(function (o) {
            if (!o.value) return;
            var marcados = (o.dataset.departamentos || '').split(',').filter(Boolean);
            if (marcados.length && deptoId && marcados.indexOf(deptoId) === -1) o.remove();
        });
        sel.value = escolhido;
        if (sel.value !== escolhido) sel.value = '';
    }

    function filtrarSetores(deptoId) {
        var sel = document.getElementById('setor_id');
        sel.innerHTML = '';
        if (!deptoId) {
            sel.disabled = true;
            sel.appendChild(new Option('Selecione o departamento primeiro...', ''));
            return;
        }
        sel.disabled = false;
        sel.appendChild(new Option('Selecione o setor...', ''));
        SETORES_TODOS.forEach(function (s) {
            var pertence = deptoId === '__sem__' ? !s.depto : s.depto === deptoId;
            if (pertence) sel.appendChild(new Option(s.nome, s.id));
        });
        if (sel.options.length === 1) {
            sel.appendChild(new Option('(nenhum setor neste departamento)', ''));
        }
    }
    </script>

    <!-- Tipo de Nota -->
    <div class="campo-dinamico">
      <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
        Tipo de Nota <span style="color:#B3261E">*</span>
      </label>
      <select id="tipo_nota_id" name="tipo_nota_id" required onchange="tipoNotaMudou()"
              style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; outline:none"
              onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <option value="">Selecione o tipo...</option>
        <?php foreach ($tiposNota as $t): ?>
        <option value="<?= h($t['id']) ?>"
                data-exige-valor="<?= !empty($t['exige_valor']) ? '1' : '0' ?>"
                data-departamentos="<?= h(implode(',', ($tipoNotaDeptos ?? [])[$t['id']] ?? [])) ?>"><?= h($t['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Valor aproximado — obrigatório só nos tipos de nota que exigem valor -->
    <div class="campo-dinamico">
      <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
        Valor Total <span id="valor-obrigatorio" style="color:#B3261E">*</span>
      </label>
      <input type="text" id="valor_aprox" name="valor_aprox" required
             placeholder="0,00"
             style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
             onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'" oninput="formatarValor(this)">
      <div id="valor-ajuda" style="font-size:11px; color:#888; margin-top:4px">Seleciona o template pela faixa de valor e segue travado nas próximas etapas</div>
    </div>

    <script>
    /* Tipo de nota sem valor a informar (Cancelamento, Devolução, Remessa) solta
       a obrigatoriedade do Valor Total. A busca de template também ignora a
       faixa de valor nesse caso — quem decide é o servidor, isto aqui é a tela
       acompanhando. */
    function tipoNotaMudou() {
        var sel   = document.getElementById('tipo_nota_id');
        var opt   = sel.options[sel.selectedIndex];
        var exige = !opt || opt.dataset.exigeValor !== '0';

        var campo = document.getElementById('valor_aprox');
        var aster = document.getElementById('valor-obrigatorio');
        var ajuda = document.getElementById('valor-ajuda');

        campo.required = exige;
        aster.style.display = exige ? '' : 'none';
        ajuda.textContent = exige
            ? 'Seleciona o template pela faixa de valor e segue travado nas próximas etapas'
            : 'Este tipo de nota não exige valor — pode deixar em branco. A faixa de valor do template não será usada na busca.';
        if (!exige) { campo.value = ''; }
    }
    document.addEventListener('DOMContentLoaded', tipoNotaMudou);
    </script>

  </div>

  <div style="margin-top:24px">
    <button type="button" id="btn-buscar-template"
            onclick="buscarTemplate()"
            style="display:inline-flex; align-items:center; gap:8px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:11px 24px; font-size:14px; font-weight:700; cursor:pointer; transition:background 0.15s"
            onmouseover="this.style.background='#1B38A0'" onmouseout="this.style.background='#2347C8'">
      <i data-lucide="search" width="16" height="16"></i>
      Buscar Template
    </button>
  </div>
</div>

<!-- Resultado da busca de template (coluna à direita do formulário) -->
<div id="resultado-template" style="flex:1; min-width:320px; display:none">
</div>

</div><!-- /layout duas colunas -->

<script>
/**
 * Busca templates compatíveis via AJAX
 */
function buscarTemplate() {
    var filialId   = document.getElementById('filial_id').value;
    var setorId    = document.getElementById('setor_id').value;
    var tipoNotaId = document.getElementById('tipo_nota_id').value;
    // Departamento é o primeiro recorte do funil — vai junto para o servidor
    // não devolver template de outro departamento.
    var deptoEl    = document.getElementById('departamento_id');
    var deptoId    = deptoEl ? deptoEl.value : '';
    var valor      = document.getElementById('valor_aprox').value.replace(/\./g,'').replace(',','.');

    if (!filialId || !setorId || !tipoNotaId) {
        mostrarMensagemTemplate('warning', 'Selecione a filial, o setor e o tipo de nota antes de buscar.');
        return;
    }

    // O Valor Total só é exigido quando o TIPO DE NOTA pede (Tabelas → Tipos de
    // nota → "exige valor"). Tipo que não exige — Cadastro, Ajuste — abre o
    // processo sem valor, e o servidor já ignora a faixa do template nesse caso
    // (Process::buscarTemplatesCompatíveis). Exigir aqui travava a abertura:
    // tipoNotaMudou() limpa o campo e avisa "pode deixar em branco", e logo
    // depois a busca recusava por ele estar vazio — impasse sem saída na tela.
    var optTipo   = document.getElementById('tipo_nota_id').selectedOptions[0];
    var exigeValor = !optTipo || optTipo.dataset.exigeValor !== '0';

    if (exigeValor && (!valor || parseFloat(valor) <= 0)) {
        mostrarMensagemTemplate('warning', 'Informe o Valor Total da nota antes de buscar o template.');
        return;
    }

    var btn = document.getElementById('btn-buscar-template');
    btn.disabled = true;
    btn.textContent = 'Buscando...';

    var params = new URLSearchParams({
        module: 'processos',
        action: 'buscar_template',
        filial_id: filialId,
        setor_id: setorId,
        tipo_nota_id: tipoNotaId,
        departamento_id: deptoId,
        valor: valor || '0'
    });

    fetch('?' + params.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.textContent = 'Buscar Template';

            if (!data.success || !data.templates || data.templates.length === 0) {
                mostrarMensagemTemplate('danger', 'Nenhum template encontrado para este contexto. Verifique se há um template publicado para esta combinação de filial, departamento e tipo de nota.');
                return;
            }

            exibirTemplatesEncontrados(data.templates);
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = 'Buscar Template';
            mostrarMensagemTemplate('danger', 'Erro ao buscar templates. Tente novamente.');
        });
}

function mostrarMensagemTemplate(tipo, msg) {
    var cores = {
        warning: {bg:'#FFF3CD', border:'#FFC107', color:'#856404'},
        danger:  {bg:'#FFE8EE', border:'#B3261E', color:'#B3261E'},
        success: {bg:'#D8F5E5', border:'#00A960', color:'#00A960'}
    };
    var c = cores[tipo] || cores['warning'];
    var div = document.getElementById('resultado-template');
    div.style.display = 'block';

    // Limpar e recriar sem innerHTML
    while (div.firstChild) { div.removeChild(div.firstChild); }

    var alerta = document.createElement('div');
    alerta.setAttribute('style',
        'background:' + c.bg + '; border:1px solid ' + c.border + '; border-radius:8px; padding:16px; color:' + c.color + '; font-size:14px'
    );
    // msg é uma string literal definida no código (não dados do servidor)
    alerta.textContent = msg;
    div.appendChild(alerta);
}

function criarElStep1(tag, style, text) {
    var el = document.createElement(tag);
    if (style) el.setAttribute('style', style);
    if (text !== undefined && text !== null) el.textContent = String(text);
    return el;
}

function exibirTemplatesEncontrados(templates) {
    var div = document.getElementById('resultado-template');
    div.style.display = 'block';

    // Limpar conteúdo anterior de forma segura
    while (div.firstChild) { div.removeChild(div.firstChild); }

    // Banner de sucesso (sem dados do servidor no HTML)
    var banner = criarElStep1('div',
        'background:#D8F5E5; border:1px solid #00A960; border-radius:8px; padding:16px; margin-bottom:16px; color:#00A960; font-size:14px; font-weight:700',
        templates.length + ' template(s) encontrado(s) para este contexto'
    );
    div.appendChild(banner);

    // Cards empilhados — um embaixo do outro (coluna única)
    var grade = criarElStep1('div',
        'display:flex; flex-direction:column; gap:16px'
    );
    div.appendChild(grade);

    templates.forEach(function(t, idx) {
        var isFirst = idx === 0;

        var card = criarElStep1('div', null);
        card.setAttribute('style',
            'display:flex; flex-direction:column; gap:16px; background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:20px; border:2px solid ' +
            (isFirst ? '#2347C8' : '#EAEAEA')
        );

        var infoBox = criarElStep1('div', 'flex:1');

        // Nome do template
        var nomeRow = criarElStep1('div', 'display:flex; align-items:center; flex-wrap:wrap; gap:8px');
        var nomeSpan = criarElStep1('span', 'font-size:16px; font-weight:700; color:#141922', t.nome);
        nomeRow.appendChild(nomeSpan);
        if (t.is_teste) {
            var badgeTeste = criarElStep1('span',
                'font-size:10px; background:#FFF3E0; color:#FF8A2D; padding:2px 8px; border-radius:8px; font-weight:700',
                'TESTE'
            );
            nomeRow.appendChild(badgeTeste);
        }
        if (isFirst && !t.is_teste) {
            var badge = criarElStep1('span',
                'font-size:10px; background:#FFE8EE; color:#B3261E; padding:2px 8px; border-radius:8px; font-weight:700',
                'Mais específico'
            );
            nomeRow.appendChild(badge);
        }
        infoBox.appendChild(nomeRow);

        card.appendChild(infoBox);

        // Botão "Usar este template" em TODOS os resultados —
        // o primeiro (mais específico) em destaque, os demais em estilo secundário
        var btnLink = document.createElement('a');
        btnLink.href = '?module=processos&action=selecionar_template&id=' + encodeURIComponent(t.id);
        btnLink.setAttribute('style', isFirst
            ? 'display:inline-flex; align-items:center; justify-content:center; gap:8px; background:#2347C8; color:#fff; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; text-decoration:none; white-space:nowrap'
            : 'display:inline-flex; align-items:center; justify-content:center; gap:8px; background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:9px 20px; font-size:14px; font-weight:700; text-decoration:none; white-space:nowrap'
        );
        btnLink.textContent = 'Usar este template';
        card.appendChild(btnLink);

        grade.appendChild(card);
    });

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function formatarValor(input) {
    var val = input.value.replace(/\D/g, '');
    if (val === '') { input.value = ''; return; }
    // Dígitos são centavos: "1234" → 12,34 (senão cada reformatação multiplica por 100)
    var num = parseInt(val, 10) / 100;
    input.value = num.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
}

// ── Validação client-side — Step 1 ───────────────────────────────────────────
(function () {
    'use strict';

    function showFieldError(input, msg) {
        input.style.borderColor = '#B3261E';
        var err = input.parentNode.querySelector('.field-error');
        if (!err) {
            err = document.createElement('span');
            err.className = 'field-error';
            err.style.cssText = 'display:block;color:#B3261E;font-size:12px;margin-top:4px;';
            input.parentNode.appendChild(err);
        }
        err.textContent = msg;
    }

    function clearFieldError(input) {
        input.style.borderColor = '';
        var err = input.parentNode.querySelector('.field-error');
        if (err) err.remove();
    }

    var selects = [
        document.getElementById('filial_id'),
        document.getElementById('setor_id'),
        document.getElementById('tipo_nota_id')
    ].filter(Boolean);

    selects.forEach(function (el) {
        el.addEventListener('change', function () { clearFieldError(this); });
    });

    var btnBuscar = document.getElementById('btn-buscar-template');
    if (btnBuscar) {
        var origBuscar = window.buscarTemplate;
        window.buscarTemplate = function () {
            var filialId   = document.getElementById('filial_id');
            var setorId    = document.getElementById('setor_id');
            var tipoNotaId = document.getElementById('tipo_nota_id');
            var valid = true;

            [filialId, setorId, tipoNotaId].forEach(function (sel) {
                if (!sel) return;
                if (!sel.value) {
                    var labels = {
                        'filial_id':    'Selecione a filial.',
                        'setor_id':     'Selecione o setor.',
                        'tipo_nota_id': 'Selecione o tipo de nota.'
                    };
                    showFieldError(sel, labels[sel.id] || 'Campo obrigatório.');
                    valid = false;
                } else {
                    clearFieldError(sel);
                }
            });

            if (!valid) {
                // Foca no primeiro select inválido
                var primeiro = [filialId, setorId, tipoNotaId].find(function(s){ return s && !s.value; });
                if (primeiro) primeiro.focus();
                return;
            }

            origBuscar();
        };
    }
}());
</script>
