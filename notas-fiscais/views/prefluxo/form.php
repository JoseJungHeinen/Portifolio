<?php
// @versao Pre_02 V_05_20/07/2026
/**
 * views/prefluxo/form.php
 * Formulário de regra de pré-fluxo (criar / editar)
 *
 * Variáveis esperadas:
 *   $regra      array|null  regra existente (edição) ou null (nova)
 *   $templates  array       templates publicados (id, nome, campos JSON)
 *   $filiais    array       filiais ativas (id, nome, cnpj)
 */

$regra        = $regra        ?? null;
$templates    = $templates    ?? [];
$filiais      = $filiais      ?? [];
$duplicando   = $duplicando   ?? false;  // regra pré-preenchida, mas salva como NOVA
$duplicandoDe = $duplicandoDe ?? '';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$isEdicao     = $regra !== null && !$duplicando;
$camposAtuais = $regra !== null ? (json_decode($regra['campos_json'], true) ?: []) : [];

$templatesJson = json_encode(array_map(fn($t) => [
    'id'     => $t['id'],
    'nome'   => $t['nome'],
    'campos' => json_decode($t['campos'], true) ?: [],
], $templates), JSON_UNESCAPED_UNICODE);
?>
<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">

<?php include VIEWS . '/components/alert.php'; ?>

<!-- Page Header -->
<div style="margin-bottom:28px">
  <a href="?module=prefluxo&amp;action=index" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#606060; text-decoration:none; margin-bottom:8px">
    <i data-lucide="arrow-left" width="14" height="14"></i> Voltar para Pré-fluxo
  </a>
  <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
    <?= $isEdicao ? 'Editar' : ($duplicando ? 'Duplicar' : 'Nova') ?> <span style="color:#2347C8">Regra</span>
  </h1>
  <p style="font-size:14px; color:#888; margin:0">Defina os critérios de match e os campos que o processo receberá automaticamente</p>
  <?php if ($duplicando): ?>
  <div style="display:inline-flex; align-items:center; gap:8px; background:#FFF3E0; color:#FF8A2D; font-size:13px; font-weight:700; border-radius:8px; padding:8px 14px; margin-top:10px">
    <i data-lucide="copy" width="15" height="15"></i>
    Copiando de "<?= h($duplicandoDe) ?>" — ajuste o nome e a filial de destino antes de criar.
  </div>
  <?php endif; ?>
</div>

<form method="POST" action="?module=prefluxo&amp;action=<?= $isEdicao ? 'atualizar' : 'salvar' ?>" style="max-width:720px">
  <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
  <?php if ($isEdicao): ?><input type="hidden" name="id" value="<?= h($regra['id']) ?>"><?php endif; ?>

  <!-- Bloco 1: Identificação -->
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:24px; margin-bottom:20px">
    <h3 style="font-size:16px; font-weight:700; color:#141922; margin:0 0 16px; display:flex; align-items:center; gap:8px">
      <i data-lucide="tag" width="18" height="18" style="color:#2347C8"></i> Identificação
    </h3>
    <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Nome da regra <span style="color:#B3261E">*</span></label>
    <input type="text" name="nome" required maxlength="150" value="<?= h($regra['nome'] ?? '') ?>"
           placeholder="Ex.: Peças Montadora — Fabricante → Fiat Cidade A"
           style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box"
           onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
  </div>

  <!-- Bloco 2: Critérios de match -->
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:24px; margin-bottom:20px">
    <h3 style="font-size:16px; font-weight:700; color:#141922; margin:0 0 4px; display:flex; align-items:center; gap:8px">
      <i data-lucide="filter" width="18" height="18" style="color:#2347C8"></i> Critérios de match
    </h3>
    <p style="font-size:12px; color:#888; margin:0 0 16px">A regra dispara quando TODOS os critérios preenchidos casarem com a nota capturada.</p>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px">
      <div>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">CNPJ do fornecedor (origem)</label>
        <input type="text" name="fornecedor_cnpj" value="<?= h($regra['fornecedor_cnpj'] ?? '') ?>"
               placeholder="14 dígitos — vazio = qualquer fornecedor"
               style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <div style="font-size:11px; color:#888; margin-top:4px">Deixe vazio para casar com qualquer emitente (útil para regras de arquivamento).</div>
      </div>
      <div>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Filial de destino <span style="color:#B3261E">*</span></label>
        <select name="destinatario_cnpj" required
                style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff">
          <option value="">Selecione a filial...</option>
          <?php foreach ($filiais as $f): ?>
          <option value="<?= h($f['cnpj']) ?>" <?= (($regra['destinatario_cnpj'] ?? '') === $f['cnpj']) ? 'selected' : '' ?>>
            <?= h($f['nome']) ?> — <?= h($f['cnpj']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-top:16px">
      <div>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">CFOP (opcional)</label>
        <input type="text" name="cfop" value="<?= h($regra['cfop'] ?? '') ?>" placeholder="Ex.: 5102 — só compara se preenchido"
               style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <div style="font-size:11px; color:#888; margin-top:4px">Ex.: 5912 (demonstração), 5656 (combustível).</div>
      </div>
      <div>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Tipo de documento (opcional)</label>
        <select name="tipo_documento"
                style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff">
          <option value="">Qualquer tipo</option>
          <?php foreach (['NFe' => 'NF-e (DANFE)', 'CTe' => 'CT-e (DACTE)', 'NFSe' => 'NFS-e', 'CTOs' => 'CT-e OS'] as $td => $tdLabel): ?>
          <option value="<?= $td ?>" <?= (($regra['tipo_documento'] ?? '') === $td) ? 'selected' : '' ?>><?= $tdLabel ?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px; color:#888; margin-top:4px">Evita que regras de frete casem com notas de produto (e vice-versa).</div>
      </div>
      <div>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Tomador do frete (CT-e)</label>
        <select name="tomador"
                style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff">
          <option value="">Indiferente</option>
          <option value="filial" <?= (($regra['tomador'] ?? '') === 'filial') ? 'selected' : '' ?>>Filial paga o frete</option>
          <option value="terceiro" <?= (($regra['tomador'] ?? '') === 'terceiro') ? 'selected' : '' ?>>Terceiro paga o frete</option>
        </select>
        <div style="font-size:11px; color:#888; margin-top:4px">Só se aplica a CT-e: quem é o tomador (pagador) da prestação.</div>
      </div>
    </div>

    <div style="margin-top:16px; max-width:480px">
      <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Natureza da operação contém (opcional)</label>
      <input type="text" name="natureza_operacao" maxlength="60" value="<?= h($regra['natureza_operacao'] ?? '') ?>"
             placeholder="Ex.: venda · devolução · remessa"
             style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box"
             onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      <div style="font-size:11px; color:#888; margin-top:4px">Casa quando o texto estiver contido na natureza da operação (natOp) do XML — sem diferenciar acento ou maiúsculas. Ex.: "devolução" casa "DEVOLUCAO DE COMPRA".</div>
    </div>

    <?php
      $vMin = ($regra['valor_min'] ?? null) !== null ? number_format((float)$regra['valor_min'], 2, ',', '.') : '';
      $vMax = ($regra['valor_max'] ?? null) !== null ? number_format((float)$regra['valor_max'], 2, ',', '.') : '';
    ?>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:16px; max-width:480px">
      <div>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Valor da nota — mínimo (opcional)</label>
        <input type="text" name="valor_min" value="<?= h($vMin) ?>" placeholder="Ex.: 1.000,00"
               style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      </div>
      <div>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Valor da nota — máximo (opcional)</label>
        <input type="text" name="valor_max" value="<?= h($vMax) ?>" placeholder="Ex.: 50.000,00"
               style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      </div>
    </div>
    <div style="font-size:11px; color:#888; margin-top:4px">Compara com o valor total da nota. Preencha só um dos lados para "acima de" ou "até".</div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:16px">
      <div>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Código dos itens (NF-e) — condição</label>
        <select name="item_condicao"
                style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff">
          <option value="">Não usar este critério</option>
          <option value="todos_iniciam" <?= (($regra['item_condicao'] ?? '') === 'todos_iniciam') ? 'selected' : '' ?>>TODOS os itens iniciam com o(s) prefixo(s)</option>
          <option value="nenhum_inicia" <?= (($regra['item_condicao'] ?? '') === 'nenhum_inicia') ? 'selected' : '' ?>>NENHUM item inicia com o(s) prefixo(s)</option>
        </select>
      </div>
      <div>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Prefixo(s) do código do produto</label>
        <input type="text" name="item_prefixo" maxlength="50" value="<?= h($regra['item_prefixo'] ?? '') ?>"
               placeholder="Ex.: VN,VU — separe por vírgula"
               style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box; text-transform:uppercase"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      </div>
    </div>
    <div style="font-size:11px; color:#888; margin-top:4px">
      Separa veículos de peças na mesma rota fiscal: veículos usam códigos como <b>VN</b> (novo) e <b>VU</b> (usado).
      Regra de veículos → "TODOS os itens iniciam com" + <b>VN,VU</b> · Regra de peças → "NENHUM item inicia com" + <b>VN,VU</b>.
      Só se aplica a NF-e; nota sem itens no XML não casa com regra que usa este critério.
    </div>
  </div>

  <!-- Bloco 3: Fluxo + campos pré-configurados -->
  <div id="pf-bloco-fluxo" style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:24px; margin-bottom:20px">
    <h3 style="font-size:16px; font-weight:700; color:#141922; margin:0 0 4px; display:flex; align-items:center; gap:8px">
      <i data-lucide="git-branch" width="18" height="18" style="color:#2347C8"></i> Fluxo e campos pré-configurados
    </h3>
    <p style="font-size:12px; color:#888; margin:0 0 16px">
      Número, valor, data e chave vêm sempre do XML da nota. Aqui você fixa os demais campos do formulário do template.
    </p>

    <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Template de fluxo <span style="color:#B3261E">*</span></label>
    <select name="template_id" id="pf-template" required onchange="pfRenderCampos(this.value)"
            style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff; margin-bottom:16px">
      <option value="">Selecione o fluxo de aprovação...</option>
      <?php foreach ($templates as $t): ?>
      <option value="<?= h($t['id']) ?>" <?= (($regra['template_id'] ?? '') === $t['id']) ? 'selected' : '' ?>><?= h($t['nome']) ?></option>
      <?php endforeach; ?>
    </select>

    <div id="pf-campos">
      <div style="font-size:13px; color:#888; text-align:center; padding:16px; background:#F6F6F6; border-radius:8px">
        Selecione um template para configurar os campos.
      </div>
    </div>
  </div>

  <!-- Bloco 4: Modo de operação -->
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:24px; margin-bottom:20px">
    <h3 style="font-size:16px; font-weight:700; color:#141922; margin:0 0 16px; display:flex; align-items:center; gap:8px">
      <i data-lucide="settings-2" width="18" height="18" style="color:#2347C8"></i> Modo de operação
    </h3>
    <?php $modoAtual = $regra['modo'] ?? 'sugerir'; ?>
    <label style="display:flex; gap:12px; align-items:flex-start; padding:14px; border:1px solid <?= $modoAtual === 'sugerir' ? '#FF8A2D' : '#EAEAEA' ?>; border-radius:8px; cursor:pointer; margin-bottom:10px">
      <input type="radio" name="modo" value="sugerir" <?= $modoAtual === 'sugerir' ? 'checked' : '' ?> style="margin-top:3px; accent-color:#2347C8" onchange="pfModoMudou()">
      <div>
        <div style="font-size:14px; font-weight:700; color:#141922">Sugerir <span style="background:#FFF3E0; color:#FF8A2D; font-size:10px; font-weight:700; text-transform:uppercase; padding:2px 8px; border-radius:8px; margin-left:6px">Recomendado para regras novas</span></div>
        <div style="font-size:12px; color:#888; margin-top:2px">A nota fica destacada na inbox com o template e os campos já preenchidos. O operador confirma com um clique.</div>
      </div>
    </label>
    <label style="display:flex; gap:12px; align-items:flex-start; padding:14px; border:1px solid <?= $modoAtual === 'automatico' ? '#00A960' : '#EAEAEA' ?>; border-radius:8px; cursor:pointer; margin-bottom:10px">
      <input type="radio" name="modo" value="automatico" <?= $modoAtual === 'automatico' ? 'checked' : '' ?> style="margin-top:3px; accent-color:#2347C8" onchange="pfModoMudou()">
      <div>
        <div style="font-size:14px; font-weight:700; color:#141922">Automático</div>
        <div style="font-size:12px; color:#888; margin-top:2px">O processo é aberto sem intervenção humana (automação de hora em hora). O fluxo de aprovação normal continua valendo — nada é pago sem as etapas.</div>
      </div>
    </label>
    <label style="display:flex; gap:12px; align-items:flex-start; padding:14px; border:1px solid <?= $modoAtual === 'arquivar' ? '#606060' : '#EAEAEA' ?>; border-radius:8px; cursor:pointer">
      <input type="radio" name="modo" value="arquivar" <?= $modoAtual === 'arquivar' ? 'checked' : '' ?> style="margin-top:3px; accent-color:#2347C8" onchange="pfModoMudou()">
      <div>
        <div style="font-size:14px; font-weight:700; color:#141922">Arquivar <span style="background:#EAEAEA; color:#606060; font-size:10px; font-weight:700; text-transform:uppercase; padding:2px 8px; border-radius:8px; margin-left:6px">Não abre processo</span></div>
        <div style="font-size:12px; color:#888; margin-top:2px">A nota sai da fila automaticamente e fica guardada (XML preservado no storage). Continua aparecendo nas pesquisas da tela de capturas, com o nome da regra como motivo.</div>
      </div>
    </label>
  </div>

  <!-- Ações -->
  <div style="display:flex; gap:10px; justify-content:flex-end">
    <?php if ($isEdicao): ?>
    <button type="submit" form="pf-form-excluir"
            style="background:#B3261E; color:#fff; border:none; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; cursor:pointer"
            onclick="return confirm('Excluir esta regra? Sugestões pendentes voltarão para a fila manual.')">
      Excluir regra
    </button>
    <a href="?module=prefluxo&amp;action=duplicar&amp;id=<?= h($regra['id']) ?>"
       title="Abrir uma nova regra com todos os campos copiados desta"
       style="display:inline-flex; align-items:center; gap:6px; border:1px solid #2347C8; color:#2347C8; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; text-decoration:none; margin-right:auto">
      <i data-lucide="copy" width="15" height="15"></i> Duplicar
    </a>
    <?php endif; ?>
    <a href="?module=prefluxo&amp;action=index"
       style="display:inline-flex; align-items:center; background:#EAEAEA; color:#606060; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; text-decoration:none">Cancelar</a>
    <button type="submit"
            style="background:#2347C8; color:#fff; border:none; border-radius:4px; padding:10px 24px; font-size:14px; font-weight:700; cursor:pointer">
      <?= $isEdicao ? 'Salvar alterações' : 'Criar regra' ?>
    </button>
  </div>
</form>

<?php if ($isEdicao): ?>
<form id="pf-form-excluir" method="POST" action="?module=prefluxo&amp;action=excluir" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
  <input type="hidden" name="id" value="<?= h($regra['id']) ?>">
</form>
<?php endif; ?>

<script>
const PF_TEMPLATES = <?= $templatesJson ?>;
const PF_VALORES   = <?= json_encode($camposAtuais, JSON_UNESCAPED_UNICODE) ?: '{}' ?>;

function pfRenderCampos(templateId) {
  const cont = document.getElementById('pf-campos');
  const tpl  = PF_TEMPLATES.find(t => t.id === templateId);
  if (!tpl) {
    cont.innerHTML = '<div style="font-size:13px; color:#888; text-align:center; padding:16px; background:#F6F6F6; border-radius:8px">Selecione um template para configurar os campos.</div>';
    return;
  }
  if (!tpl.campos.length) {
    cont.innerHTML = '<div style="font-size:13px; color:#888; text-align:center; padding:16px; background:#F6F6F6; border-radius:8px">Este template não possui campos de formulário.</div>';
    return;
  }

  cont.innerHTML = '';
  tpl.campos.forEach(c => {
    const val = PF_VALORES[c.nome] || '';
    const req = c.obrigatorio
      ? ' <span style="background:#FFE8EE; color:#B3261E; font-size:9px; font-weight:700; text-transform:uppercase; padding:2px 6px; border-radius:6px">obrigatório no processo</span>'
      : '';
    let input;
    if (c.tipo === 'textarea') {
      input = `<textarea name="campo_${c.nome}" rows="2"
                style="width:100%; padding:10px 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box; font-family:inherit">${pfEsc(val)}</textarea>`;
    } else if (c.tipo === 'select' && Array.isArray(c.opcoes)) {
      const opts = c.opcoes.map(o => `<option value="${pfEsc(o)}" ${o === val ? 'selected' : ''}>${pfEsc(o)}</option>`).join('');
      input = `<select name="campo_${c.nome}"
                style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff">
                <option value="">— não pré-configurar —</option>${opts}</select>`;
    } else {
      input = `<input type="text" name="campo_${c.nome}" value="${pfEsc(val)}"
                style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box">`;
    }
    cont.insertAdjacentHTML('beforeend',
      `<div style="margin-bottom:14px">
         <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">${pfEsc(c.label || c.nome)}${req}</label>
         ${input}
       </div>`);
  });
}

function pfEsc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/* Modo arquivar não abre processo — dispensa o template */
function pfModoMudou() {
  const modo     = document.querySelector('input[name="modo"]:checked')?.value;
  const arquivar = modo === 'arquivar';
  const sel      = document.getElementById('pf-template');
  const bloco    = document.getElementById('pf-bloco-fluxo');
  if (arquivar) {
    sel.removeAttribute('required');
    bloco.style.opacity = '0.45';
  } else {
    sel.setAttribute('required', 'required');
    bloco.style.opacity = '1';
  }
}

// Edição: renderizar campos do template já selecionado + estados dependentes
document.addEventListener('DOMContentLoaded', () => {
  const sel = document.getElementById('pf-template');
  if (sel.value) pfRenderCampos(sel.value);
  pfModoMudou();
});
</script>
