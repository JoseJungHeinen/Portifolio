<?php
declare(strict_types=1);

session_start();
require 'conexao_inventario.php';

if (empty($_SESSION['inventario'])) {
    header('Location: login.php');
    exit;
}

// Recontagem por item: sessão de 2ª contagem vai para a tela própria, que tem
// coletor designado e data.
if ((int)($_SESSION['inventario']['contagem'] ?? 1) === 2) {
    header('Location: contagem_2.php');
    exit;
}

$idInventario = (int)$_SESSION['inventario']['id'];
$empresa      = (int)$_SESSION['inventario']['empresa'];
$revenda      = (int)$_SESSION['inventario']['revenda'];
$coletor      = (int)($_SESSION['inventario']['coletor']  ?? 0);
$contagem     = (int)($_SESSION['inventario']['contagem'] ?? 1);
$idInvSess    = (int)($_SESSION['inventario']['id']       ?? 0);

$lojaNome = '';
if (!empty($_SESSION['inventario']['loja_nome'])) {
    $lojaNome = (string)$_SESSION['inventario']['loja_nome'];
} else {
    $lojaNome = 'Inventário #' . $idInvSess;
}

$_SESSION['inventario']['id_inventario'] = $idInvSess;
// Busca nome do coletor cadastrado
$nomeColetorDisplay = 'Coletor ' . $coletor;
if ($coletor > 0 && $idInventario > 0) {
    $stmtNome = $pdo->prepare("
        SELECT nome FROM inv_coletores
        WHERE empresa = :e AND revenda = :r AND id_inventario = :inv AND coletor_num = :num
        LIMIT 1
    ");
    $stmtNome->execute([':e' => $empresa, ':r' => $revenda, ':inv' => $idInventario, ':num' => $coletor]);
    $rowNome = $stmtNome->fetch(PDO::FETCH_ASSOC);
    if ($rowNome && $rowNome['nome'] !== '') {
        $nomeColetorDisplay = $rowNome['nome'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Inventário - Contagem</title>
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
  </style>
</head>
<body>
  <div class="app">
    <header class="header">
      <div class="header-left">
        <div class="header-title">
          <span class="header-badge"><?php echo htmlspecialchars($lojaNome); ?></span>
          <span class="header-badge"><?php echo htmlspecialchars($nomeColetorDisplay); ?></span>
          <span class="header-badge">Contagem <?php echo htmlspecialchars((string)$contagem); ?></span>
        </div>
      </div>
      <a href="logout.php" class="logout-btn">
        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <path d="M7 4a1 1 0 0 1 1-1h6a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8a1 1 0 1 1 0-2h6V5H8a1 1 0 0 1-1-1z"/>
          <path d="M4.293 9.293a1 1 0 0 1 1.414 0L7 10.586 5.707 11.88a1 1 0 1 1-1.414-1.414L4.586 10 4.293 9.707a1 1 0 0 1 0-1.414z"/>
        </svg>
        Sair
      </a>
    </header>

    <main class="content">
      <div class="card">
        <div class="card-header">
          <span style="font-size:12px;color:var(--gray-600);"></span>
        </div>
        <div>
          <div class="field-label">Locação</div>
          <input class="input" id="locacao" type="text" autocomplete="off" autofocus>
        </div>
        <div>
          <div class="field-label">Item</div>
          <input class="input" id="item" type="text" autocomplete="off">
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <span class="field-label" style="margin-bottom:0;">Quantidade</span>
        </div>
        <div class="qty-wrapper">
          <button type="button" class="qty-btn" id="btn-menor">−</button>
          <div class="qty-display" id="qty-display">0</div>
          <button type="button" class="qty-btn" id="btn-maior">+</button>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <span style="font-size:13px;font-weight:600;color:var(--gray-600);">Teclado rápido</span>
        </div>
        <div class="keypad" id="keypad">
          <button type="button" class="key">1</button>
          <button type="button" class="key">2</button>
          <button type="button" class="key">3</button>
          <button type="button" class="key">4</button>
          <button type="button" class="key">5</button>
          <button type="button" class="key">6</button>
          <button type="button" class="key">7</button>
          <button type="button" class="key">8</button>
          <button type="button" class="key">9</button>
          <button type="button" class="key key-backspace">⌫</button>
          <button type="button" class="key">0</button>
          <button type="button" class="key key-ok">✓</button>
        </div>
      </div>
    </main>

    <button class="btn-confirm" id="btn-confirmar">
      <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path d="M16.707 5.293a1 1 0 0 0-1.414 0L8 12.586 4.707 9.293A1 1 0 0 0 3.293 10.7l4 4a1 1 0 0 0 1.414 0l8-8a1 1 0 0 0 0-1.414z"/>
      </svg>
      Confirmar
    </button>
  </div>

  <div id="msg-erro"></div>
  <div id="msg-aviso"></div>

  <!-- Modal customizado -->
  <div class="dlg-backdrop" id="dlg-backdrop">
    <div class="dlg-box">
      <div class="dlg-icon" id="dlg-icon">⚠️</div>
      <div class="dlg-msg" id="dlg-msg"></div>
      <div class="dlg-btns" id="dlg-btns"></div>
    </div>
  </div>

  <script>
    const qtyDisplay   = document.getElementById('qty-display');
    const keypad       = document.getElementById('keypad');
    const btnMenor     = document.getElementById('btn-menor');
    const btnMaior     = document.getElementById('btn-maior');
    const btnConfirmar = document.getElementById('btn-confirmar');
    const inputLocacao = document.getElementById('locacao');
    const inputItem    = document.getElementById('item');
    const msgErro      = document.getElementById('msg-erro');
    const msgAviso     = document.getElementById('msg-aviso');
    const dlgBackdrop  = document.getElementById('dlg-backdrop');
    const dlgMsg       = document.getElementById('dlg-msg');
    const dlgIcon      = document.getElementById('dlg-icon');
    const dlgBtns      = document.getElementById('dlg-btns');

    /* ── Helpers de modal ── */

    // substitui confirm() nativo — retorna Promise<boolean>
    function customConfirm(texto, { icone = '⚠️', corOk = 'red', labelOk = 'Sim', labelCancel = 'Cancelar' } = {}) {
      // Se já existe um dialog pendente, resolve-o como false (cancelar)
      if (_dlgReject) { _dlgReject(false); _dlgReject = null; }

      return new Promise(resolve => {
        _dlgReject = resolve;  // guarda referência para resolver externamente

        dlgIcon.textContent = icone;
        dlgMsg.textContent  = texto;
        dlgBtns.innerHTML   = '';

        const btnC = document.createElement('button');
        btnC.className   = 'dlg-btn dlg-btn-cancel';
        btnC.textContent = labelCancel;
        btnC.onclick = () => { _dlgReject = null; fecharDlg(); resolve(false); };

        const btnO = document.createElement('button');
        btnO.className   = 'dlg-btn dlg-btn-ok' + (corOk === 'amber' ? ' amber' : '');
        btnO.textContent = labelOk;
        btnO.onclick = () => { _dlgReject = null; fecharDlg(); resolve(true); };

        dlgBtns.appendChild(btnC);
        dlgBtns.appendChild(btnO);

        dlgBackdrop.classList.add('show');
        setTimeout(() => btnO.focus(), 50);
      });
    }

    // substitui alert() nativo — retorna Promise<void>
    function customAlert(texto, { icone = 'ℹ️' } = {}) {
      if (_dlgReject) { _dlgReject(false); _dlgReject = null; }

      return new Promise(resolve => {
        _dlgReject = () => resolve();  // backdrop click resolve como void

        dlgIcon.textContent = icone;
        dlgMsg.textContent  = texto;
        dlgBtns.innerHTML   = '';

        const btnO = document.createElement('button');
        btnO.className   = 'dlg-btn dlg-btn-ok solo';
        btnO.textContent = 'OK';
        btnO.onclick = () => { _dlgReject = null; fecharDlg(); resolve(); };

        dlgBtns.appendChild(btnO);
        dlgBackdrop.classList.add('show');
        setTimeout(() => btnO.focus(), 50);
      });
    }

    function fecharDlg() {
      dlgBackdrop.classList.remove('show');
    }

    // fecha ao clicar fora — E resolve a promise pendente
    dlgBackdrop.addEventListener('click', e => {
      if (e.target === dlgBackdrop) {
        if (_dlgReject) { const fn = _dlgReject; _dlgReject = null; fn(false); }
        fecharDlg();
      }
    });

    /* ── Controle de concorrência (evita dupla execução pelo scanner) ── */
    let _processandoLocacao = false;
    let _processandoItem    = false;
    let _dlgReject          = null;   // referência para rejeitar/resolver dialog pendente

    /* ── Lógica principal ── */

    function parseQty() {
      return parseInt(qtyDisplay.textContent || '0', 10) || 0;
    }

    function setQty(value) {
      const v = Math.max(0, value);
      qtyDisplay.textContent = String(v).slice(0, 6);
    }

    function mostrarErro(texto) {
      msgAviso.style.display = 'none';
      msgErro.textContent = texto;
      msgErro.style.display = 'block';
      clearTimeout(mostrarErro._timer);
      mostrarErro._timer = setTimeout(() => { msgErro.style.display = 'none'; }, 4000);
    }

    function mostrarAviso(texto) {
      msgErro.style.display = 'none';
      msgAviso.textContent = texto;
      msgAviso.style.display = 'block';
      clearTimeout(mostrarAviso._timer);
      mostrarAviso._timer = setTimeout(() => {
        msgAviso.style.display = 'none';
        inputLocacao.classList.remove('ja-contada');
      }, 5000);
    }

    btnMenor.onclick = () => setQty(parseQty() - 1);
    btnMaior.onclick = () => setQty(parseQty() + 1);

    // Limpa caracteres de controle enviados por leitores de código de barras
    // (GS \x1D, NULL \x00, EOT \x04, CR, LF, etc.)
    function limparScanner(valor) {
      return (valor || '').replace(/[\x00-\x1F\x7F-\x9F]/g, '').trim();
    }

    // Só limpa: o sufixo de variante (-01, 001), os zeros à esquerda e a regra
    // do K são resolvidos no contagem_gravar.php, contra os itens que existem
    // no inventário. Cortar aqui, às cegas, transformava MK5001 em MK5.
    function normalizarCodigoItem(bruto) {
      return limparScanner(bruto).replace(/\s+/g, '').toUpperCase();
    }

    async function enviarItem(forcar) {
      forcar = forcar || false;
      const loc  = limparScanner(inputLocacao.value);
      const item = normalizarCodigoItem(inputItem.value);
      inputItem.value = item;
      const qtd = parseQty();

      if (!loc || !item || qtd <= 0) {
        mostrarErro('Preencha locação, item e quantidade.');
        return;
      }

      let res;
      try {
        const r = await fetch('contagem_gravar.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ modo: 'contagem', locacao: loc, item, quantidade: qtd, forcar })
        });
        res = await r.json();
      } catch {
        mostrarErro('Falha na comunicação com o servidor.');
        return;
      }

      if (!res.ok && res.ja_contado) {
        const continuar = await customConfirm(
          'Este item já foi contado nesta locação.\n\nDeseja contar novamente mesmo assim?',
          { icone: '⚠️', labelOk: 'Sim, contar', labelCancel: 'Cancelar' }
        );
        if (continuar) enviarItem(true);
        return;
      }

      if (!res.ok) {
        if (Array.isArray(res.locacoes_corretas) && res.locacoes_corretas.length > 0) {
          await customAlert(
            (res.mensagem || 'Item não pertence a esta locação.') +
            '\n\nLocação(s) correta(s):\n' + res.locacoes_corretas.join('\n'),
            { icone: '📍' }
          );
        } else {
          await customAlert(res.mensagem || 'Erro ao gravar.', { icone: '❌' });
        }
        return;
      }

      inputLocacao.value = '';
      inputItem.value    = '';
      inputLocacao.classList.remove('ja-contada');
      setQty(0);
      inputLocacao.focus();
    }

    async function consultarSobra() {
      const loc  = limparScanner(inputLocacao.value);
      const item = normalizarCodigoItem(inputItem.value);
      inputItem.value = item;

      if (!loc || !item) { mostrarErro('Preencha locação e item para consultar sobra.'); return; }

      let res;
      try {
        const r = await fetch('contagem_gravar.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ modo: 'consulta_sobra', locacao: loc, item })
        });
        res = await r.json();
      } catch {
        mostrarErro('Falha na comunicação ao consultar sobra.');
        return;
      }

      if (!res.ok || !res.encontrado) {
        mostrarErro(res.mensagem || 'Item não encontrado para sobras.');
        return;
      }

      const ok = await customConfirm(
        `Locação: ${res.locacao}\nSaldo em estoque: ${res.saldo_estoque}\n\nAdicionar sobra?`,
        { icone: '📦', labelOk: 'Adicionar', labelCancel: 'Cancelar' }
      );
      if (!ok) return;

      // prompt customizado para quantidade
      const qtdInt = await customPrompt('Informe a quantidade de sobra:', '1');
      const qtd = parseInt(qtdInt || '0', 10);
      if (!qtd || qtd <= 0) { mostrarErro('Quantidade de sobra inválida.'); return; }

      let res2;
      try {
        const r2 = await fetch('contagem_gravar.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ modo: 'gravar_sobra', locacao: loc, item, quantidade: qtd })
        });
        res2 = await r2.json();
      } catch {
        mostrarErro('Falha na comunicação ao gravar sobra.');
        return;
      }

      if (!res2.ok) { mostrarErro(res2.mensagem || 'Erro ao gravar sobra.'); return; }

      inputLocacao.value = '';
      inputItem.value    = '';
      inputLocacao.classList.remove('ja-contada');
      setQty(0);
      inputLocacao.focus();
    }

    // prompt customizado para quantidade de sobra
    function customPrompt(label, valorInicial) {
      if (_dlgReject) { _dlgReject(false); _dlgReject = null; }

      return new Promise(resolve => {
        _dlgReject = () => resolve(null);  // backdrop click = cancelar

        dlgIcon.textContent = '🔢';
        dlgMsg.innerHTML    = `<span style="display:block;margin-bottom:10px">${label}</span>
          <input id="dlg-prompt-input" type="number" min="1"
            style="width:100%;height:46px;border-radius:10px;border:1.5px solid var(--gray-200);
                   padding:0 12px;font-size:18px;font-weight:700;text-align:center;
                   outline:none;color:var(--gray-800);background:#fafafa;"
            value="${valorInicial}">`;
        dlgBtns.innerHTML = '';

        const btnC = document.createElement('button');
        btnC.className   = 'dlg-btn dlg-btn-cancel';
        btnC.textContent = 'Cancelar';
        btnC.onclick = () => { _dlgReject = null; fecharDlg(); resolve(null); };

        const btnO = document.createElement('button');
        btnO.className   = 'dlg-btn dlg-btn-ok';
        btnO.textContent = 'Confirmar';
        btnO.onclick = () => {
          const v = document.getElementById('dlg-prompt-input')?.value || '0';
          _dlgReject = null;
          fecharDlg();
          resolve(v);
        };

        dlgBtns.appendChild(btnC);
        dlgBtns.appendChild(btnO);
        dlgBackdrop.classList.add('show');

        setTimeout(() => {
          const inp = document.getElementById('dlg-prompt-input');
          if (inp) { inp.focus(); inp.select(); }
        }, 80);
      });
    }

    keypad.addEventListener('click', e => {
      const target = e.target;
      if (!(target instanceof HTMLButtonElement)) return;
      const txt = target.textContent.trim();
      if (txt === '⌫') {
        const atual = String(parseQty());
        const novo  = atual.slice(0, -1);
        setQty(novo === '' ? 0 : parseInt(novo, 10));
      } else if (txt === '✓') {
        parseQty() === 0 ? consultarSobra() : enviarItem();
      } else if (/^\d$/.test(txt)) {
        const atual = qtyDisplay.textContent === '0' ? '' : qtyDisplay.textContent;
        setQty(parseInt((atual + txt).slice(0, 6) || '0', 10));
      }
    });

    btnConfirmar.onclick = () => parseQty() === 0 ? consultarSobra() : enviarItem();

    // ao sair da locação: verifica se já foi contada
    async function aoSairLocacao() {
      if (_processandoLocacao) return;  // evita dupla execução (Enter + change)
      const loc = limparScanner(inputLocacao.value);
      if (!loc) return;

      _processandoLocacao = true;
      try {
        let res;
        try {
          const r = await fetch('contagem_gravar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ modo: 'verificar_locacao', locacao: loc })
          });
          res = await r.json();
        } catch {
          inputItem.focus();
          inputItem.select();
          return;
        }

        if (res.ok && res.ja_contada) {
          const continuar = await customConfirm(
            `Locação ${loc} já foi contada anteriormente.\n\nDeseja continuar mesmo assim?`,
            { icone: '⚠️', corOk: 'amber', labelOk: 'Continuar', labelCancel: 'Cancelar' }
          );
          if (!continuar) {
            inputLocacao.value = '';
            inputLocacao.classList.remove('ja-contada');
            inputLocacao.focus();
            return;
          }
          inputLocacao.classList.add('ja-contada');
        } else {
          inputLocacao.classList.remove('ja-contada');
        }

        inputItem.focus();
        inputItem.select();
      } finally {
        _processandoLocacao = false;
      }
    }

    inputLocacao.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); aoSairLocacao(); }
    });
    inputLocacao.addEventListener('change', aoSairLocacao);

    // verifica item+locação já contado
    async function verificarEAvancar() {
      if (_processandoItem) return;  // evita dupla execução (Enter + change)
      const loc  = limparScanner(inputLocacao.value);
      const item = normalizarCodigoItem(inputItem.value);
      inputItem.value = item;

      if (!loc || !item) { setQty(1); btnMaior.focus(); return; }

      _processandoItem = true;
      try {
        let res;
        try {
          const r = await fetch('contagem_gravar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ modo: 'verificar_item', locacao: loc, item })
          });
          res = await r.json();
        } catch {
          setQty(1); btnMaior.focus(); return;
        }

        if (res.ok && res.ja_contado) {
          const continuar = await customConfirm(
            'Este item já foi contado nesta locação.\n\nDeseja contar novamente mesmo assim?',
            { icone: '⚠️', labelOk: 'Sim, contar', labelCancel: 'Cancelar' }
          );
          if (!continuar) {
            inputItem.value = '';
            inputItem.focus();
            return;
          }
        }

        setQty(1);
        btnMaior.focus();
      } finally {
        _processandoItem = false;
      }
    }

    inputItem.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); verificarEAvancar(); }
    });
    inputItem.addEventListener('change', function () {
      verificarEAvancar();
    });
  </script>
</body>
</html>