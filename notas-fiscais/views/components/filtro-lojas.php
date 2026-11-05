<?php
// @versao Sys_30 V_04_13/08/2026
/**
 * views/components/filtro-lojas.php
 * Filtro de lojas (filiais/empresas) com MULTI-SELEÇÃO por checkbox.
 *
 * Usado em Processos, Capturas, Dashboard e Relatórios — o mesmo componente
 * nas quatro telas para o comportamento não divergir de uma para outra.
 *
 * As opções já chegam recortadas pelo escopo do usuário (FilialScope), e o
 * controller revalida a escolha (FilialScope::idsDoFiltro): marcar no HTML uma
 * loja que não é sua não amplia nada — o id é descartado no servidor.
 *
 * Variáveis esperadas:
 *   $flSufixo   string  id único do dropdown NA TELA (ex.: 'proc', 'loja-fi')
 *   $flOpcoes   array   [{id, nome}] — lojas que o usuário pode escolher
 *   $flSel      array   ids marcados (ou string única, de link antigo)
 *   $flCampo    string  nome do parâmetro GET — default 'filial_id'
 *   $flVariante string  'pill' (dashboard/relatórios, usa dashboard.css)
 *                       | 'inline' (processos/capturas, estilo do próprio filtro)
 *   $flLabel    string  rótulo do botão — default 'Lojas'
 *   $flOnChange string  JS chamado a cada marcação. Use nas telas que filtram no
 *                       CLIENTE (Minhas Tarefas): sem formulário para submeter,
 *                       o botão "Aplicar" não faz sentido e some.
 *   $flBtnExtra string  CSS extra no botão da variante 'inline', para casar com
 *                       a barra de filtros da tela (altura, raio, peso).
 *   $flRodape   bool    default true. Passe false quando a TELA já tem os botões
 *                       de aplicar/limpar (Contas a Pagar) — duplicar a ação
 *                       dentro do dropdown só confunde quem usa.
 */

$flSufixo   = $flSufixo   ?? 'lojas';
$flOpcoes   = $flOpcoes   ?? [];
$flSel      = array_values(array_filter(array_map('strval', (array) ($flSel ?? []))));
$flCampo    = $flCampo    ?? 'filial_id';
$flVariante = $flVariante ?? 'inline';
$flLabel    = $flLabel    ?? 'Lojas';
$flOnChange = $flOnChange ?? '';
$flBtnExtra = $flBtnExtra ?? '';
$flRodape   = $flRodape   ?? true;

$flQtd   = count($flSel);
$flAtivo = $flQtd > 0;

// Uma loja só: mostra o nome dela no botão — é a informação que o usuário quer
// ler de relance. Duas ou mais viram contagem, senão o botão estoura a linha.
$flTexto = $flLabel;
if ($flQtd === 1) {
    foreach ($flOpcoes as $flO) {
        if ((string) $flO['id'] === $flSel[0]) { $flTexto = (string) $flO['nome']; break; }
    }
} elseif ($flQtd > 1) {
    $flTexto = $flLabel . ' (' . $flQtd . ')';
}

// CSS e JS entram uma vez só por página, mesmo com vários filtros na tela
// (Relatórios repete o componente em cada aba).
if (!defined('FLT_LOJAS_ASSETS')) {
    define('FLT_LOJAS_ASSETS', true);
    ?>
<style>
.flt-lojas { position: relative; }
/* `fixed`, e não `absolute`: em Minhas Tarefas a barra de filtros tem
   overflow-x:auto, e container com overflow numa direção recorta na outra
   também — o dropdown abria cortado, invisível. Fora do fluxo ele não é
   recortado por ninguém; a posição é calculada no clique (fltLojasToggle). */
.flt-lojas-dd {
  display: none; position: fixed; top: 0; left: 0;
  min-width: 250px; background: #fff; border: 1px solid #DFDFDF;
  border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 1200; overflow: hidden;
}
.flt-lojas.open .flt-lojas-dd { display: block; }
.flt-lojas.open .fp-chevron { transform: rotate(180deg); }
.flt-lojas-item {
  display: flex; align-items: center; gap: 10px; padding: 10px 14px;
  font-size: 14px; color: #141922; cursor: pointer;
}
.flt-lojas-item:hover { background: #EEF2FD; }
.flt-lojas-item input { width: 16px; height: 16px; accent-color: #2347C8; cursor: pointer; }
.flt-lojas-rodape { display: flex; gap: 8px; border-top: 1px solid #EAEAEA; padding: 10px 14px; }
</style>
<script>
// Abre/fecha o dropdown e fecha os outros — inclusive os filter-pills do
// dashboard, que compartilham a mesma linha de filtros.
function fltLojasToggle(id) {
  var wrap = document.getElementById('flt-lojas-' + id);
  if (!wrap) return;
  var aberto = wrap.classList.toggle('open');
  if (aberto) {
    document.querySelectorAll('.flt-lojas.open, .fp-wrap.open').forEach(function (el) {
      if (el !== wrap) el.classList.remove('open');
    });
    fltLojasPosicionar(wrap);
  }
}

// Com o dropdown em `position:fixed`, a posição vem do botão. Alinha à direita
// quando não couber à esquerda, e abre para cima quando não couber embaixo.
function fltLojasPosicionar(wrap) {
  var btn = wrap.querySelector('button');
  var dd  = wrap.querySelector('.flt-lojas-dd');
  if (!btn || !dd) return;

  var r = btn.getBoundingClientRect();
  dd.style.minWidth = Math.max(250, r.width) + 'px';

  var larg = dd.offsetWidth || 250;
  var alt  = dd.offsetHeight || 0;
  var esq  = r.left;
  if (esq + larg > window.innerWidth - 8) esq = Math.max(8, r.right - larg);

  var topo = r.bottom + 5;
  if (alt && topo + alt > window.innerHeight - 8) {
    topo = Math.max(8, r.top - alt - 5);
  }
  dd.style.left = esq + 'px';
  dd.style.top  = topo + 'px';
}

// A página rola por baixo de um elemento fixo: reposiciona enquanto estiver
// aberto, senão o dropdown "descola" do botão.
window.addEventListener('scroll', function () {
  document.querySelectorAll('.flt-lojas.open').forEach(fltLojasPosicionar);
}, true);
window.addEventListener('resize', function () {
  document.querySelectorAll('.flt-lojas.open').forEach(fltLojasPosicionar);
});

function fltLojasLimpar(id) {
  var wrap = document.getElementById('flt-lojas-' + id);
  if (!wrap) return;
  wrap.querySelectorAll('input[type=checkbox]').forEach(function (cb) { cb.checked = false; });
  fltLojasAtualizarRotulo(id);
}

// Rótulo e destaque do botão acompanham a marcação sem recarregar a página —
// nas telas que filtram no cliente não há submit para redesenhar isso.
function fltLojasAtualizarRotulo(id) {
  var wrap = document.getElementById('flt-lojas-' + id);
  if (!wrap) return;
  var marcados = wrap.querySelectorAll('input[type=checkbox]:checked');
  var btn      = wrap.querySelector('button');
  var span     = btn ? btn.querySelector('span') : null;
  if (!span) return;

  var base = wrap.getAttribute('data-label') || 'Lojas';
  if (marcados.length === 0) {
    span.textContent = base;
  } else if (marcados.length === 1) {
    var nome = marcados[0].parentNode.querySelector('span');
    span.textContent = nome ? nome.textContent.trim() : base;
  } else {
    span.textContent = base + ' (' + marcados.length + ')';
  }

  var ativo = marcados.length > 0;
  if (wrap.getAttribute('data-variante') === 'pill') {
    btn.classList.toggle('active', ativo);
  } else {
    btn.style.background  = ativo ? '#FFF5F5' : '#fff';
    btn.style.borderColor = ativo ? '#2347C8' : '#DFDFDF';
    btn.style.color       = ativo ? '#2347C8' : '#141922';
    btn.style.fontWeight  = ativo ? '700' : '400';
  }
}

document.addEventListener('click', function (e) {
  if (e.target.closest('.flt-lojas')) return;
  document.querySelectorAll('.flt-lojas.open').forEach(function (el) { el.classList.remove('open'); });
});
</script>
    <?php
}

$flBtnStyle = $flVariante === 'pill' ? '' :
    'height:40px; display:flex; align-items:center; gap:8px; padding:0 14px; border-radius:4px; cursor:pointer;'
    . ' white-space:nowrap; font-size:14px; background:' . ($flAtivo ? '#FFF5F5' : '#fff')
    . '; border:1px solid ' . ($flAtivo ? '#2347C8' : '#DFDFDF')
    . '; color:' . ($flAtivo ? '#2347C8' : '#141922')
    . '; font-weight:' . ($flAtivo ? '700' : '400')
    . ';' . $flBtnExtra;
?>
<div class="flt-lojas" id="flt-lojas-<?= h($flSufixo) ?>"
     data-label="<?= h($flLabel) ?>" data-variante="<?= h($flVariante) ?>">
  <button type="button"
          class="<?= $flVariante === 'pill' ? 'filter-pill' . ($flAtivo ? ' active' : '') : '' ?>"
          style="<?= $flBtnStyle ?>"
          onclick="fltLojasToggle('<?= h($flSufixo) ?>')">
    <span><?= h($flTexto) ?></span>
    <i data-lucide="chevron-down" width="<?= $flVariante === 'pill' ? '11' : '13' ?>"
       height="<?= $flVariante === 'pill' ? '11' : '13' ?>" class="fp-chevron"></i>
  </button>

  <div class="flt-lojas-dd">
    <div style="max-height:260px; overflow-y:auto">
      <?php if (empty($flOpcoes)): ?>
      <div style="padding:14px 16px; font-size:13px; color:#888">Nenhuma loja liberada para você.</div>
      <?php else: foreach ($flOpcoes as $flO): ?>
      <label class="flt-lojas-item">
        <input type="checkbox" name="<?= h($flCampo) ?>[]" value="<?= h($flO['id']) ?>"
               <?= in_array((string) $flO['id'], $flSel, true) ? 'checked' : '' ?>
               onchange="fltLojasAtualizarRotulo('<?= h($flSufixo) ?>');<?= $flOnChange ?>">
        <span style="flex:1"><?= h($flO['nome']) ?></span>
      </label>
      <?php endforeach; endif; ?>
    </div>
    <?php if ($flRodape): ?>
    <div class="flt-lojas-rodape">
      <button type="button" onclick="fltLojasLimpar('<?= h($flSufixo) ?>');<?= $flOnChange ?>"
              style="<?= $flOnChange !== '' ? 'flex:1; ' : '' ?>padding:8px 12px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; font-size:13px; font-weight:700; cursor:pointer">
        Limpar
      </button>
      <?php // Filtro no cliente já aplicou a marcação — não há o que submeter. ?>
      <?php if ($flOnChange === ''): ?>
      <button type="submit"
              style="flex:1; padding:8px 0; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:13px; font-weight:700; cursor:pointer">
        Aplicar
      </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php
unset($flSufixo, $flOpcoes, $flSel, $flCampo, $flVariante, $flLabel, $flOnChange,
      $flBtnExtra, $flRodape, $flQtd, $flAtivo, $flTexto, $flBtnStyle, $flO);
