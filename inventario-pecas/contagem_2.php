<?php
declare(strict_types=1);

session_start();
require 'conexao_inventario.php';
require 'contagem_2_lib.php';

if (empty($_SESSION['inventario'])) {
    header('Location: login.php');
    exit;
}

$contagem = (int)($_SESSION['inventario']['contagem'] ?? 0);

if ($contagem !== 2) {
    header('Location: contagem.php');
    exit;
}

$idInv   = (int)($_SESSION['inventario']['id']      ?? 0);
$empresa = (int)($_SESSION['inventario']['empresa'] ?? 0);
$revenda = (int)($_SESSION['inventario']['revenda'] ?? 0);
$dupla   = (string)($_SESSION['inventario']['dupla_label'] ?? 'Dupla');

// A caminhada começa do zero a cada entrada na tela.
unset($_SESSION['recontagem_pulados']);

$lojaNome = (string)($_SESSION['inventario']['loja_nome'] ?? ('Inventário #' . $idInv));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Inventário - Recontagem</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --red: #2347C8;
      --red-light: #FFEBEE;
      --red-soft: #FFE5E7;
      --amber: #F59E0B;
      --amber-light: #FFFBEB;
      --gray-25: #FCFCFD;
      --gray-50: #F5F5F7;
      --gray-100: #ECEFF1;
      --gray-200: #E0E0E0;
      --gray-400: #B0BEC5;
      --gray-600: #546E7A;
      --gray-800: #263238;
      --white: #FFFFFF;
      --orange: #FF9800;
      --radius-card: 18px;
      --radius-input: 14px;
      --shadow-soft: 0 10px 20px rgba(15,23,42,0.18);
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      background: radial-gradient(circle at top, #FFFFFF 0, #E3F2FD 55%, #CFD8DC 100%);
      color: var(--gray-800);
      -webkit-font-smoothing: antialiased;
    }

    .app {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    .header {
      padding: 10px 16px;
      background: linear-gradient(135deg, #B71C1C, var(--red));
      color: var(--white);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      box-shadow: 0 4px 10px rgba(183,28,28,0.45);
    }

    .header-left {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 0;
    }

    .header-title {
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 8px;
      min-width: 0;
    }

    .header-badge {
      padding: 2px 8px;
      border-radius: 999px;
      background: rgba(255,255,255,0.16);
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .08em;
      white-space: nowrap;
    }

    .logout-btn {
      margin-left: auto;
      padding: 6px 12px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.75);
      background: rgba(244,67,54,0.15);
      color: #FFFFFF;
      font-size: 13px;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      backdrop-filter: blur(4px);
      transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.05s ease;
      white-space: nowrap;
    }

    .logout-btn:hover {
      background: rgba(255,255,255,0.18);
      box-shadow: 0 2px 6px rgba(0,0,0,0.35);
      transform: translateY(-1px);
    }

    .logout-btn:active { transform: translateY(0); box-shadow: none; }
    .logout-btn svg { width: 14px; height: 14px; }

    .content {
      flex: 1;
      padding: 16px 16px 80px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .card {
      background: rgba(255,255,255,0.98);
      border-radius: var(--radius-card);
      padding: 14px 16px 16px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      box-shadow: var(--shadow-soft);
      border: 1px solid rgba(148,163,184,0.25);
    }

    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 12px;
      color: var(--gray-600);
    }

    .field-label {
      font-size: 13px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .08em;
      color: var(--gray-600);
      margin-bottom: 4px;
    }

    .input {
      height: 52px;
      border-radius: var(--radius-input);
      border: 1px solid var(--gray-200);
      padding: 0 14px;
      font-size: 16px;
      background: #FAFAFA;
      color: var(--gray-800);
      width: 100%;
      transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }

    .input::placeholder { color: var(--gray-400); }

    .input:focus {
      outline: none;
      border-color: var(--red);
      box-shadow: 0 0 0 2px rgba(35,71,200,0.16);
      background: #FFFFFF;
    }

    .input.ja-contada {
      border-color: var(--amber);
      box-shadow: 0 0 0 2px rgba(245,158,11,0.22);
      background: var(--amber-light);
    }

    .qty-wrapper {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .qty-btn {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      border: none;
      background: #ECEFF1;
      font-size: 26px;
      color: var(--gray-800);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      box-shadow: 0 2px 6px rgba(0,0,0,0.15);
      transition: background 0.15s ease, transform 0.05s ease, box-shadow 0.15s ease;
    }

    .qty-btn:hover { background: #E0E0E0; transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,0.25); }
    .qty-btn:active { transform: translateY(0); box-shadow: none; }

    .qty-display {
      flex: 1;
      min-height: 48px;
      border-radius: 16px;
      background: radial-gradient(circle at 20% 0%, #FFFFFF, var(--red-soft));
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 44px;
      font-weight: 700;
      color: var(--red);
      box-shadow: inset 0 0 0 1px rgba(244,67,54,0.18);
      font-variant-numeric: tabular-nums;
    }

    .keypad {
      margin-top: 4px;
      display: grid;
      grid-template-columns: repeat(3,1fr);
      gap: 10px;
      max-width: 360px;
      width: 100%;
      align-self: center;
    }

    .key {
      width: 70px;
      height: 70px;
      border-radius: 50%;
      border: none;
      background: #ECEFF1;
      font-size: 24px;
      font-weight: 600;
      color: var(--gray-800);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      box-shadow: 0 3px 8px rgba(0,0,0,0.2);
      transition: background 0.15s ease, transform 0.05s ease, box-shadow 0.15s ease;
    }

    .key:hover { background: #E0E0E0; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.25); }
    .key:active { transform: translateY(0); box-shadow: none; }

    .key-backspace { background: var(--orange); color: var(--white); }
    .key-backspace:hover { background: #FB8C00; }

    .key-ok {
      background: linear-gradient(135deg, var(--red), #FF1744);
      color: var(--white);
    }
    .key-ok:hover { background: linear-gradient(135deg, #C62828, #FF1744); }

    .btn-confirm {
      position: fixed;
      left: 16px;
      right: 16px;
      bottom: 16px;
      height: 54px;
      border-radius: 16px;
      border: none;
      background: linear-gradient(135deg, var(--red), #FF1744);
      color: var(--white);
      font-size: 15px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .10em;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      cursor: pointer;
      box-shadow: 0 10px 20px rgba(35,71,200,0.55);
      z-index: 30;
      transition: box-shadow 0.15s ease, transform 0.05s ease, filter 0.1s;
    }

    .btn-confirm:hover { box-shadow: 0 12px 24px rgba(35,71,200,0.65); transform: translateY(-1px); filter: brightness(1.02); }
    .btn-confirm:active { transform: translateY(0); box-shadow: 0 6px 12px rgba(35,71,200,0.45); }
    .btn-confirm svg { width: 18px; height: 18px; }

    /* toast de erro */
    #msg-erro {
      position: fixed;
      left: 16px;
      right: 16px;
      bottom: 80px;
      padding: 12px 14px;
      border-radius: 14px;
      background: #FFEBEE;
      color: #C62828;
      font-size: 13px;
      box-shadow: 0 6px 14px rgba(0,0,0,0.25);
      border: 1px solid #FFCDD2;
      display: none;
      white-space: pre-line;
      z-index: 40;
    }

    /* toast de aviso (amarelo) */
    #msg-aviso {
      position: fixed;
      left: 16px;
      right: 16px;
      bottom: 80px;
      padding: 12px 14px;
      border-radius: 14px;
      background: var(--amber-light);
      color: #92400E;
      font-size: 13px;
      font-weight: 600;
      box-shadow: 0 6px 14px rgba(0,0,0,0.2);
      border: 1px solid #FCD34D;
      display: none;
      white-space: pre-line;
      z-index: 41;
    }

    /* ── Modal customizado (substitui confirm/alert nativos) ── */
    .dlg-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.45);
      backdrop-filter: blur(3px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 200;
      padding: 20px;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.18s ease;
    }

    .dlg-backdrop.show {
      opacity: 1;
      pointer-events: all;
    }

    .dlg-box {
      background: #fff;
      border-radius: 20px;
      padding: 24px 22px 20px;
      width: 100%;
      max-width: 340px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.25);
      transform: scale(0.92) translateY(10px);
      transition: transform 0.2s cubic-bezier(0.34,1.56,0.64,1);
    }

    .dlg-backdrop.show .dlg-box {
      transform: scale(1) translateY(0);
    }

    .dlg-icon {
      font-size: 2rem;
      text-align: center;
      margin-bottom: 10px;
    }

    .dlg-msg {
      font-size: 14px;
      color: var(--gray-800);
      line-height: 1.6;
      white-space: pre-line;
      text-align: center;
      margin-bottom: 20px;
    }

    .dlg-btns {
      display: flex;
      gap: 10px;
    }

    .dlg-btn {
      flex: 1;
      height: 46px;
      border-radius: 12px;
      border: none;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      transition: filter 0.12s ease, transform 0.05s ease;
    }

    .dlg-btn:active { transform: scale(0.97); }

    .dlg-btn-cancel {
      background: var(--gray-100);
      color: var(--gray-600);
    }

    .dlg-btn-cancel:hover { filter: brightness(0.95); }

    .dlg-btn-ok {
      background: linear-gradient(135deg, var(--red), #FF1744);
      color: #fff;
    }

    .dlg-btn-ok:hover { filter: brightness(1.08); }

    .dlg-btn-ok.amber {
      background: linear-gradient(135deg, #F59E0B, #FBBF24);
      color: #fff;
    }

    /* quando é só alert (um botão) */
    .dlg-btn-ok.solo { flex: 1; }

    @media (min-width: 768px) {
      .content { max-width: 540px; margin: 0 auto; }
    }

    /* ── 2ª CONTAGEM ──────────────────────────────────────────────────────
       O que esta tela tem e a contagem normal nao: o contador de progresso na
       barra, a locacao em corpo grande, a etiqueta Menor/Maior, os dois
       numeros lado a lado e o aviso de resultado. */

    .header-contador {
      padding: 3px 10px;
      border-radius: 999px;
      background: rgba(255,255,255,0.18);
      color: var(--white);
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
      margin-left: auto;
      margin-right: 10px;
    }

    .progresso { height: 5px; background: rgba(255,255,255,0.55); }
    .progresso-barra {
      height: 100%;
      width: 0;
      background: linear-gradient(90deg, #2E7D32, #66BB6A);
      transition: width .25s ease;
    }

    .va-ate {
      font-size: 34px;
      font-weight: 800;
      letter-spacing: .02em;
      color: var(--gray-800);
      word-break: break-all;
      line-height: 1.1;
    }

    .item-linha { display: flex; align-items: center; justify-content: space-between; gap: 10px; }

    .item-codigo {
      font-size: 22px;
      font-weight: 800;
      color: var(--gray-800);
      word-break: break-all;
    }

    .item-desc { font-size: 13px; color: var(--gray-600); margin-top: 3px; }

    .etiqueta {
      padding: 4px 11px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .05em;
      white-space: nowrap;
    }

    .etiqueta-menor       { background: var(--red-light);   color: #B71C1C; }
    .etiqueta-maior       { background: var(--amber-light); color: #E65100; }
    .etiqueta-nao_contado { background: var(--gray-100);    color: var(--gray-600); }

    .numeros { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

    .numero {
      background: var(--gray-50);
      border-radius: var(--radius-input);
      padding: 10px 12px;
      text-align: center;
    }

    .numero-rotulo {
      font-size: 11px;
      color: var(--gray-600);
      text-transform: uppercase;
      letter-spacing: .06em;
    }

    .numero-valor { font-size: 28px; font-weight: 800; color: var(--gray-800); }

    .erro {
      margin-top: 8px;
      padding: 9px 12px;
      border-radius: var(--radius-input);
      background: var(--red-soft);
      color: #B71C1C;
      font-size: 13px;
      font-weight: 600;
    }

    .aviso-ok {
      padding: 9px 12px;
      border-radius: var(--radius-input);
      background: #E8F5E9;
      color: #1B5E20;
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 12px;
    }

    .linha-entre {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-top: 10px;
    }

    .hint { font-size: 12px; color: var(--gray-600); }

    .btn-ilegivel {
      border: 1px solid var(--gray-200);
      background: var(--white);
      color: var(--gray-600);
      border-radius: 999px;
      padding: 7px 14px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
    }

    .btn-ilegivel:hover { background: var(--gray-50); }

    .resultado {
      padding: 14px;
      border-radius: var(--radius-input);
      font-size: 15px;
      font-weight: 700;
      text-align: center;
    }

    .resultado-ok      { background: #E8F5E9;          color: #1B5E20; }
    .resultado-diverge { background: var(--red-soft);  color: #B71C1C; }
    .resultado-aviso   { background: var(--amber-light); color: #E65100; }

    .btn-pular {
      display: block;
      width: 100%;
      margin-top: 4px;
      padding: 12px;
      border: none;
      border-radius: var(--radius-input);
      background: transparent;
      color: var(--gray-600);
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
    }

    .btn-pular:hover { background: rgba(255,255,255,0.6); }

    .hidden { display: none !important; }
  </style>
</head>
<body>
<div class="app">
  <header class="header">
    <div class="header-left">
      <div class="header-title">
        <span class="header-badge"><?php echo htmlspecialchars($lojaNome); ?></span>
        <span class="header-badge"><?php echo htmlspecialchars($dupla); ?></span>
        <span class="header-badge">Recontagem</span>
      </div>
    </div>
    <span class="header-contador" id="contador">—</span>
    <a href="logout.php" class="logout-btn">
      <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path d="M7 4a1 1 0 0 1 1-1h6a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8a1 1 0 1 1 0-2h6V5H8a1 1 0 0 1-1-1z"/>
        <path d="M4.293 9.293a1 1 0 0 1 1.414 0L7 10.586 5.707 11.88a1 1 0 1 1-1.414-1.414L4.586 10 4.293 9.707a1 1 0 0 1 0-1.414z"/>
      </svg>
      Sair
    </a>
  </header>

  <div class="progresso"><div class="progresso-barra" id="barra"></div></div>

  <main class="content">

    <div class="card hidden" id="painel-fim">
      <div class="card-header" id="fim-titulo">Recontagem concluída</div>
      <div class="hint" id="fim-sub"></div>
      <a href="logout.php" class="btn-confirm" style="margin-top:14px;">Sair</a>
    </div>

    <div id="painel-item">
      <div class="card">
        <div class="field-label">Vá até</div>
        <div class="va-ate" id="locacao">—</div>
      </div>

      <div class="card">
        <div class="item-linha">
          <div class="item-codigo" id="codigo">—</div>
          <span class="etiqueta" id="etiqueta"></span>
        </div>
        <div class="item-desc" id="descricao"></div>
      </div>

      <div class="card" id="card-bipe">
        <div class="field-label">Bipe a peça</div>
        <input class="input" id="bipe" type="text" autocomplete="off" autocapitalize="off" autofocus>
        <div class="erro hidden" id="bipe-erro" role="alert"></div>
        <div class="linha-entre">
          <span class="hint" id="bipe-modo">Só leitor</span>
          <button type="button" class="btn-ilegivel" id="btn-ilegivel">Código ilegível</button>
        </div>
      </div>

      <div class="card hidden" id="card-qtd">
        <div class="aviso-ok" id="confirmado"></div>
        <div class="field-label">Quantidade contada</div>
        <div class="qty-display" id="qtd">0</div>
        <div class="card-header" style="margin-top:14px;">Teclado rápido</div>
        <div class="keypad" id="teclado">
          <button type="button" class="key" data-t="1">1</button>
          <button type="button" class="key" data-t="2">2</button>
          <button type="button" class="key" data-t="3">3</button>
          <button type="button" class="key" data-t="4">4</button>
          <button type="button" class="key" data-t="5">5</button>
          <button type="button" class="key" data-t="6">6</button>
          <button type="button" class="key" data-t="7">7</button>
          <button type="button" class="key" data-t="8">8</button>
          <button type="button" class="key" data-t="9">9</button>
          <button type="button" class="key key-backspace" data-t="apagar">⌫</button>
          <button type="button" class="key" data-t="0">0</button>
          <button type="button" class="key key-ok" data-t="ok">✓</button>
        </div>
        <div class="hint" style="text-align:center;margin-top:12px;">0 é válido — significa que não tem nenhum.</div>
      </div>

      <div class="card hidden" id="card-resultado">
        <div class="resultado" id="resultado"><span id="resultado-texto"></span></div>
      </div>

      <button type="button" class="btn-pular" id="btn-pular">Pular este item</button>
      <button type="button" class="btn-pular" id="btn-terminei" style="margin-top:10px;">Terminei — avisar o gestor</button>
    </div>

  </main>
</div>

<script src="includes/coletor-scanner.js"></script>
<script>
  const el = (id) => document.getElementById(id);

  // Às cegas: a etiqueta diz só em que contagem o item está.

  let itemAtual = null;
  let ultimosTotais = null;
  let entrada   = 'bipado';

  const trava = ColetorScanner.travarCampoDeBipe(el('bipe'), {
    aoLer:     (texto) => confirmarItem(texto),
    aoRecusar: (motivo) => mostrarErroBipe(ColetorScanner.mensagemDeRecusa(motivo, 'Código ilegível')),
  });

  el('btn-ilegivel').addEventListener('click', () => {
    trava.liberar();
    entrada = 'digitado';
    el('bipe-modo').textContent = 'Digitação liberada — fica registrado';
  });

  el('bipe').addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter' && trava.estaLiberado()) confirmarItem(el('bipe').value);
  });

  function mostrar(id, visivel) { el(id).classList.toggle('hidden', !visivel); }

  // mensagemDeRecusa devolve o texto; quem mostra na tela e esta funcao. O
  // segundo argumento e o ROTULO do botao de escape, que entra na frase.
  function mostrarErroBipe(texto) {
    el('bipe-erro').textContent = texto;
    el('bipe-erro').classList.remove('hidden');
  }

  function pintarItem(item, totais) {
    el('contador').textContent = totais.feitos + ' de ' + totais.total;
    el('barra').style.width = (totais.total > 0
      ? Math.round(totais.feitos / totais.total * 100) : 0) + '%';

    if (!item) {
      itemAtual = null;
      mostrar('painel-item', false);
      mostrar('painel-fim', true);
      el('fim-titulo').textContent = totais.total === 0 ? 'Nada a recontar' : 'Recontagem concluída';
      el('fim-sub').textContent = totais.feitos + ' de ' + totais.total
        + ' itens. Voltando para a seleção de inventário…';
      // Acabou a última locação: fica registrado para o gestor que esta dupla
      // terminou, e ela volta sozinha para escolher o próximo inventário, em
      // vez de ficar parada numa tela sem nada a fazer.
      const sair = () => { window.location.href = 'logout.php'; };
      const avisar = totais.total > 0 ? chamar('terminei', {}).catch(() => null) : Promise.resolve();
      setTimeout(() => { avisar.finally(sair); }, 3000);
      return;
    }

    ultimosTotais = totais;
    itemAtual = item;
    mostrar('painel-fim', false);
    mostrar('painel-item', true);

    el('locacao').textContent    = item.locacao_geral;
    el('codigo').textContent     = item.item_estoque_pub;
    el('descricao').textContent  = item.descricao_item_estoque;
    el('etiqueta').textContent = item.rodada + 'ª contagem';
    el('etiqueta').className = 'etiqueta etiqueta-nao_contado';

    // Cada parada recomeça travada: o botão de ilegível vale para um item só.
    entrada = 'bipado';
    trava.travar();
    el('bipe-modo').textContent = 'Só leitor';
    el('bipe').value = '';
    el('bipe-erro').classList.add('hidden');
    el('qtd').textContent = '0';
    mostrar('card-bipe', true);
    mostrar('card-qtd', false);
    mostrar('card-resultado', false);
    el('bipe').focus();
  }

  async function chamar(modo, corpo) {
    const opcoes = corpo
      ? { method: 'POST', body: new URLSearchParams(corpo) }
      : { method: 'GET' };
    const r = await fetch('contagem_2_ajax.php?modo=' + modo, opcoes);
    return r.json();
  }

  async function carregar() {
    const d = await chamar('proximo');
    if (!d.ok) { window.location.href = 'login.php'; return; }
    pintarItem(d.item, d.totais);
  }

  // A parada mudou embaixo do coletor: o gestor passou este item para outro
  // coletor enquanto ele caminhava. Repintar com o que o servidor tem agora,
  // senão a tela fica presa mostrando um item que ele já passou, e todo
  // rebipe é recusado de novo.
  function paradaMudou(d, motivo) {
    pintarItem(d.item, d.totais);
    mostrarErroBipe(d.item
      ? motivo + ' Vá até ' + d.item.locacao_geral + '.'
      : motivo + ' A fila acabou.');
  }

  async function confirmarItem(texto) {
    const d = await chamar('confirmar_item', {
      item: texto,
      parada: itemAtual ? itemAtual.id : '',
      entrada,
    });
    if (!d.ok) {
      if (d.parada_mudou) { paradaMudou(d, 'Este item foi passado para outro coletor.'); return; }
      mostrarErroBipe(d.mensagem);
      el('bipe').value = '';
      return;
    }
    el('bipe-erro').classList.add('hidden');
    el('confirmado').textContent = itemAtual.item_estoque_pub + ' confirmado em ' + itemAtual.locacao_geral;
    mostrar('card-bipe', false);
    mostrar('card-qtd', true);
  }

  el('teclado').addEventListener('click', (ev) => {
    const botao = ev.target.closest('[data-t]');
    if (!botao) return;
    const tecla = botao.dataset.t;

    if (tecla === 'ok')     { gravar(); return; }
    if (tecla === 'apagar') { el('qtd').textContent = el('qtd').textContent.slice(0, -1) || '0'; return; }

    const atual = el('qtd').textContent === '0' ? '' : el('qtd').textContent;
    el('qtd').textContent = (atual + tecla).slice(0, 6);
  });

  async function gravar() {
    // O id é da parada que a tela mostra; o servidor só grava se ela ainda for
    // a parada dele.
    const d = await chamar('gravar', {
      id: itemAtual.id,
      quantidade: el('qtd').textContent,
      entrada,
    });

    if (!d.ok) {
      // Concorrencia nao cai mais aqui — o servidor grava e avisa quem passou
      // antes. Chegar aqui quer dizer que esta nem e uma parada valida.
      if (d.parada_mudou) { paradaMudou(d, 'Este item foi passado para outro coletor.'); return; }
      mostrarErroBipe(d.mensagem);
      mostrar('card-qtd', false);
      mostrar('card-bipe', true);
      return;
    }

    mostrar('card-qtd', false);
    mostrar('card-resultado', true);

    // Às cegas: só confirma que gravou. Dizer "bateu" entregaria o sistema.
    el('resultado').className = 'resultado resultado-ok';
    el('resultado-texto').textContent = 'Registrado';

    setTimeout(() => pintarItem(d.item, d.totais), 900);
  }

  el('btn-pular').addEventListener('click', async () => {
    if (!itemAtual) return;
    const d = await chamar('pular', { id: itemAtual.id });
    if (d.ok) pintarItem(d.item, d.totais);
  });

  // A dupla encerra a parte dela antes da fila acabar — faltou tempo, o resto
  // não dá para contar. O gestor vê o aviso no dashboard com quantos faltaram.
  el('btn-terminei').addEventListener('click', async () => {
    const faltam = ultimosTotais ? ultimosTotais.total - ultimosTotais.feitos : 0;
    const ok = confirm('Avisar o gestor que vocês terminaram?\n\n'
      + (faltam > 0 ? 'Ainda faltam ' + faltam + ' item(ns) da sua parte.\n' : '')
      + 'Você volta para a seleção de inventário.');
    if (!ok) return;

    const d = await chamar('terminei', {});
    if (!d.ok) { mostrarErroBipe(d.mensagem || 'Não foi possível avisar o gestor.'); return; }
    window.location.href = 'logout.php';
  });

  carregar();
</script>
</body>
</html>
