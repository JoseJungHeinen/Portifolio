<?php
declare(strict_types=1);
/**
 * Consulta de Placa (Peças) — PuxaPlaca API v2
 * Ferramenta carregada em iframe pelo menu.php.
 * Token fica no banco (config_sistema.chave='pecas_puxaplaca_token') e NUNCA é
 * enviado ao navegador — o proxy PHP abaixo faz a chamada server-side.
 * Doc: https://documenter.getpostman.com/view/5027543/2sB3BDLXLw
 */

require_once __DIR__ . '/config_database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['logado'])) {
    header('Location: index.php');
    exit;
}

// ── Permissão: admin OU menu_pecas_consulta_placa ──────────────────────────
$is_admin   = !empty($_SESSION['is_admin']);
$usuario_id = (int)($_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? 0);

$pode = $is_admin;
if (!$pode && $usuario_id > 0) {
    $stmt = $mysqli->prepare(
        "SELECT menu_pecas_consulta_placa FROM usuarios_permissoes WHERE usuario_id = ? LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('i', $usuario_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $pode = !empty($row['menu_pecas_consulta_placa']);
    }
}
if (!$pode) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:Segoe UI,Arial;padding:40px;color:#606060">'
       . '<h2 style="color:#2347C8">Acesso negado</h2>'
       . '<p>Você não tem permissão para usar a Consulta de Placa. Fale com o administrador.</p></body>';
    exit;
}

// ── Token do banco ─────────────────────────────────────────────────────────
function pecas_token(mysqli $mysqli): string {
    $t = '';
    $st = $mysqli->prepare("SELECT valor FROM config_sistema WHERE chave = 'pecas_puxaplaca_token' LIMIT 1");
    if ($st) {
        $st->execute();
        $st->bind_result($v);
        if ($st->fetch()) $t = (string)$v;
        $st->close();
    }
    return trim($t);
}

// ── Config / uso do dia ─────────────────────────────────────────────────────
function pecas_cfg(mysqli $mysqli, string $chave, string $default = ''): string {
    $v = $default;
    $st = $mysqli->prepare("SELECT valor FROM config_sistema WHERE chave = ? LIMIT 1");
    if ($st) {
        $st->bind_param('s', $chave); $st->execute(); $st->bind_result($val);
        if ($st->fetch() && $val !== null && $val !== '') $v = (string)$val;
        $st->close();
    }
    return $v;
}
function pecas_usadas_hoje(mysqli $mysqli): int {
    $n = 0;
    $r = $mysqli->query("SELECT COUNT(*) AS c FROM pecas_consulta_log WHERE DATE(criado_em) = CURDATE()");
    if ($r && ($row = $r->fetch_assoc())) $n = (int)$row['c'];
    return $n;
}
$action = $_GET['action'] ?? '';

// ── Proxy server-side (consulta / saldo) ───────────────────────────────────
if ($action === 'consulta' || $action === 'saldo') {
    header('Content-Type: application/json; charset=utf-8');

    $token = pecas_token($mysqli);
    if ($token === '') {
        http_response_code(400);
        echo json_encode(['error' => true, 'message' => 'Token da PuxaPlaca não configurado. Fale com o administrador (Peças → Config · Consulta de Placa).']);
        exit;
    }

    $placa = '';
    if ($action === 'saldo') {
        $url = 'https://api.puxaplaca.app/v2/saldo';
    } else {
        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['placa'] ?? ''));
        if ($placa === '') {
            http_response_code(400);
            echo json_encode(['error' => true, 'message' => 'Informe a placa.']);
            exit;
        }
        // Limite diário (0 = sem limite) — bloqueia ANTES de gastar
        $limite = (int)pecas_cfg($mysqli, 'pecas_limite_diario', '0');
        if ($limite > 0) {
            $usadas = pecas_usadas_hoje($mysqli);
            if ($usadas >= $limite) {
                http_response_code(429);
                echo json_encode(['error' => true, 'message' => "Limite diário de {$limite} consultas atingido ({$usadas} usadas hoje). Ajuste em Peças → Config · Consulta de Placa."]);
                exit;
            }
        }
        $url = 'https://api.puxaplaca.app/v2/consulta/' . rawurlencode($placa);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['token: ' . $token, 'Accept: application/json'],
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        http_response_code(502);
        echo json_encode(['error' => true, 'message' => 'Falha na requisição: ' . $err]);
        exit;
    }

    // Registra a consulta cobrada (sucesso) no log do dia
    if ($action === 'consulta' && ($status ?: 200) == 200) {
        $j = json_decode($body, true);
        if (is_array($j) && empty($j['error']) && isset($j['basico']['dados'])) {
            $log = $mysqli->prepare("INSERT INTO pecas_consulta_log (usuario_id, placa) VALUES (?, ?)");
            if ($log) { $uid = $usuario_id ?: null; $log->bind_param('is', $uid, $placa); $log->execute(); $log->close(); }
        }
    }

    http_response_code($status ?: 200);
    echo $body;
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Consulta de Placa</title>
<style>
  :root {
    --primary:#2347C8; --primary-hover:#1B38A0; --dark:#141922;
    --gray-dark:#606060; --gray-medium:#888; --gray-light:#EAEAEA;
    --surface:#FFF; --bg:#F6F6F6; --success:#00A960; --warning:#FF8A2D; --danger:#B3261E;
  }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--dark); font-family:'Segoe UI', Arial, sans-serif; padding:24px; }
  .wrap { max-width:1080px; margin:0 auto; }
  h1 { font-size:28px; font-weight:700; margin:0 0 20px; }
  h1 span { color:var(--primary); }
  .card { background:var(--surface); border-radius:12px; padding:20px; box-shadow:rgba(0,0,0,.10) 0 2px 8px; margin-bottom:24px; }
  .searchbar { display:flex; gap:20px; align-items:flex-end; flex-wrap:wrap; }
  .searchbar .bar-title { font-size:26px; font-weight:700; margin:0; white-space:nowrap; line-height:44px; }
  .searchbar .bar-title span { color:var(--primary); }
  .searchbar .field { flex:0 0 240px; margin:0; }
  label { display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--gray-dark); }
  input { width:100%; height:44px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:16px; outline:none; text-transform:uppercase; }
  input:focus { border-color:var(--primary); }
  .field { margin-bottom:16px; }
  button.primary { height:44px; border:none; border-radius:4px; background:var(--primary); color:#fff; font-weight:700; font-size:14px; padding:0 28px; cursor:pointer; }
  button.primary:hover { background:var(--primary-hover); }
  button.primary:disabled { opacity:.6; cursor:default; }
  .result { display:none; }
  .result.show { display:block; }
  .panel { background:var(--surface); border-radius:12px; overflow:hidden; box-shadow:rgba(0,0,0,.10) 0 2px 8px; margin-bottom:20px; }
  .panel-head { background:#141018; color:#fff; padding:14px 22px; display:flex; align-items:center; gap:12px; }
  .panel-head .ico { width:26px; height:26px; color:var(--primary); }
  .panel-head .ico svg { width:26px; height:26px; }
  .panel-head .lbl { font-size:10px; letter-spacing:1px; color:#9a939f; text-transform:uppercase; }
  .panel-head .ttl { font-size:18px; font-weight:700; line-height:1.1; }
  .panel-head .meta { margin-left:auto; display:flex; align-items:center; gap:10px; font-size:12px; color:#9a939f; }
  .panel-head .meta .ok { color:var(--success); font-weight:700; }
  .panel-head img.logo { height:34px; }
  .panel-body { padding:22px; }
  .plates { display:flex; flex-wrap:wrap; align-items:center; gap:18px; margin-bottom:24px; }
  .plate { border-radius:8px; overflow:hidden; width:190px; box-shadow:0 1px 3px rgba(0,0,0,.2); font-family:'Consolas','Courier New',monospace; }
  .plate.merco .top { background:#1e3a8a; color:#fff; display:flex; justify-content:space-between; align-items:center; padding:3px 8px; font-size:9px; letter-spacing:.5px; font-family:Arial; }
  .plate.merco .top b { color:#facc15; }
  .plate.merco .body { background:#fff; border:2px solid #111; border-top:none; text-align:center; font-size:30px; font-weight:700; letter-spacing:3px; padding:6px 0; color:#111; }
  .plate.old { border:2px solid #444; }
  .plate.old .cap { background:#5b5b5b; color:#fff; text-align:center; font-size:8px; letter-spacing:.5px; padding:2px 0; font-family:Arial; }
  .plate.old .body { background:linear-gradient(#c9c9c9,#a9a9a9); text-align:center; font-size:28px; font-weight:700; letter-spacing:3px; padding:6px 0; color:#1a1a1a; }
  .veh { display:flex; flex-direction:column; gap:4px; }
  .veh .sub { font-size:11px; letter-spacing:1px; color:var(--gray-medium); text-transform:uppercase; }
  .veh .name { font-size:20px; font-weight:700; color:var(--dark); }
  .veh .name .brand { color:var(--primary); }
  .uf-badge { display:inline-flex; align-items:center; gap:5px; background:var(--primary); color:#fff; font-size:11px; font-weight:700; border-radius:4px; padding:2px 7px; vertical-align:middle; margin-left:6px; }
  .uf-badge img { height:12px; border-radius:1px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px,1fr)); gap:20px 24px; }
  .fld { display:flex; gap:10px; align-items:flex-start; }
  .fld .fico { flex:0 0 auto; width:22px; height:22px; color:var(--warning); margin-top:2px; }
  .fld .fico svg { width:22px; height:22px; }
  .fld .fbody { min-width:0; }
  .fld .flabel { font-size:10px; letter-spacing:.8px; color:var(--gray-medium); text-transform:uppercase; }
  .fld .fvalue { font-size:15px; font-weight:700; color:var(--dark); word-break:break-word; }
  .fld.hl .fico { color:var(--primary); }
  .fld.chassi .fvalue { font-family:'Consolas',monospace; letter-spacing:1px; display:flex; align-items:center; gap:8px; white-space:nowrap; }
  .fld.chassi .copy-btn { margin-left:0; }
  .copy-btn { height:26px; padding:0 10px; margin-left:8px; font-size:11px; background:var(--gray-light); color:var(--gray-dark); border-radius:4px; border:none; cursor:pointer; font-weight:700; vertical-align:middle; }
  .copy-btn:hover { background:#DcDcDc; }
  .copy-btn.ok { background:#D8F5E5; color:var(--success); }
  .badge-roubo { display:inline-block; font-size:11px; font-weight:700; border-radius:6px; padding:2px 8px; }
  .badge-roubo.ok { background:#D8F5E5; color:var(--success); }
  .badge-roubo.bad { background:#FFE8EE; color:var(--danger); }
  .error { display:none; background:#FFE8EE; color:var(--danger); padding:12px 16px; border-radius:8px; font-size:14px; font-weight:700; margin-bottom:24px; }
  .error.show { display:block; }
  .loading { display:none; color:var(--gray-medium); font-size:14px; margin-bottom:24px; }
  .loading.show { display:block; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <form id="form" class="searchbar">
      <h1 class="bar-title">Consulta de <span>Placa</span></h1>
      <div class="field">
        <input id="placa" name="placa" placeholder="ABC1D23" maxlength="8" autocomplete="off" autofocus>
      </div>
      <button type="submit" class="primary" id="btn">Consultar</button>
    </form>
  </div>

  <div class="loading" id="loading">Consultando…</div>
  <div class="error" id="error"></div>

  <div class="result" id="result">
    <div class="panel">
      <div class="panel-head">
        <span class="ico">%ICON_TAG%</span>
        <div><div class="lbl">Seção</div><div class="ttl">Resumo</div></div>
        <div class="meta" id="resumo-meta"></div>
      </div>
      <div class="panel-body">
        <div class="plates" id="plates"></div>
        <div class="grid" id="resumo-grid"></div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head">
        <span class="ico">%ICON_LIST%</span>
        <div><div class="lbl">Seção</div><div class="ttl">Dados básicos</div></div>
      </div>
      <div class="panel-body"><div class="grid" id="basicos-grid"></div></div>
    </div>
  </div>
</div>

<script>
const $ = id => document.getElementById(id);
const ICONS = {
  tag:'<line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/>',
  list:'<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>',
  car:'<path d="M5 13l1.5-4.6A2 2 0 0 1 8.4 7h7.2a2 2 0 0 1 1.9 1.4L19 13"/><path d="M5 13h14v4H5z"/><circle cx="7.5" cy="17.5" r="1.2"/><circle cx="16.5" cy="17.5" r="1.2"/>',
  building:'<path d="M6 21V5a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v16"/><line x1="3" y1="21" x2="21" y2="21"/><line x1="10" y1="8" x2="14" y2="8"/><line x1="10" y1="12" x2="14" y2="12"/>',
  droplet:'<path d="M12 3l5 5a7 7 0 1 1-10 0z"/>',
  calendar:'<rect x="3" y="4" width="18" height="17" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
  fuel:'<path d="M14 20V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v14"/><line x1="3" y1="20" x2="15" y2="20"/><path d="M14 10h2a2 2 0 0 1 2 2v4a1.5 1.5 0 0 0 3 0V9l-3-3"/>',
  zap:'<polygon points="13 2 4 14 11 14 10 22 19 10 12 10 13 2"/>',
  pin:'<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
  hash:'<line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/>',
  globe:'<circle cx="12" cy="12" r="9"/><line x1="3" y1="12" x2="21" y2="12"/><path d="M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z"/>',
  info:'<circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="8" r=".6"/>'
};
function svg(name){ return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${ICONS[name]||ICONS.info}</svg>`; }
document.body.innerHTML = document.body.innerHTML.replace('%ICON_TAG%', svg('tag')).replace('%ICON_LIST%', svg('list'));

function esc(s){ return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

$('form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const placa = $('placa').value.trim();
  $('error').classList.remove('show');
  $('result').classList.remove('show');
  $('loading').classList.add('show');
  $('btn').disabled = true;
  try {
    const resp = await fetch('consulta_placa.php?action=consulta&placa=' + encodeURIComponent(placa));
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.message || ('Erro ' + resp.status));
    if (data.error) throw new Error(data.message || 'Erro na consulta');
    if (!data.basico || !data.basico.dados) throw new Error(data.message || 'A API não retornou dados para esta placa.');
    render(data);
  } catch (err) {
    $('error').textContent = err.message;
    $('error').classList.add('show');
  } finally {
    $('loading').classList.remove('show');
    $('btn').disabled = false;
  }
});

function render(data) {
  const d = data.basico.dados || {};
  const val = k => (d[k] != null && d[k] !== '') ? d[k] : '';
  const chassi  = (data.chassi   && data.chassi.dados   && data.chassi.dados.chassi)   || '';
  const renavam = (data.renavam  && data.renavam.dados  && data.renavam.dados.renavam) || '';
  const roubo   = (data.rouboFurto && data.rouboFurto.dados && data.rouboFurto.dados.rouboFurto) || d.rouboFurto || '';

  let meta = '';
  const msg = val('mensagemRetorno') || data.message;
  if (msg) meta += `<span class="ok">✔ ${esc(msg)}</span>`;
  if (val('logo')) meta += `<img class="logo" src="${esc(val('logo'))}" alt="">`;
  $('resumo-meta').innerHTML = meta;

  const placaNova = val('placa_modelo_novo'), placaAntiga = val('placa_modelo_antigo');
  const fmtOld = p => p ? (p.length >= 7 ? p.slice(0,3) + '-' + p.slice(3) : p) : '';
  let platesHtml = '';
  if (placaNova) platesHtml += `<div class="plate merco"><div class="top"><span>🇧🇷 BRASIL</span><b>MERCOSUL</b></div><div class="body">${esc(placaNova)}</div></div>`;
  if (placaAntiga && placaAntiga !== placaNova) platesHtml += `<div class="plate old"><div class="cap">BR · FORMATO ANTIGO</div><div class="body">${esc(fmtOld(placaAntiga))}</div></div>`;
  const brand = val('marca'), model = val('modelo'), ano = val('ano'), uf = val('uf'), ufFlag = val('uf_flag');
  platesHtml += `<div class="veh"><span class="sub">Veículo</span><span class="name"><span class="brand">${esc(brand)}</span> ${esc(model)} ${ano ? '· ' + esc(ano) : ''} ${uf ? `<span class="uf-badge">${ufFlag ? `<img src="${esc(ufFlag)}" alt="">` : ''}${esc(uf)}</span>` : ''}</span></div>`;
  $('plates').innerHTML = platesHtml;

  const fldHtml = (icon, label, value, extraClass = '', valueHtml = null) => {
    if ((value == null || value === '') && !valueHtml) return '';
    return `<div class="fld ${extraClass}"><span class="fico">${svg(icon)}</span><div class="fbody"><div class="flabel">${esc(label)}</div><div class="fvalue">${valueHtml != null ? valueHtml : esc(value)}</div></div></div>`;
  };

  const chassiValueHtml = `${esc(chassi) || '—'}` + (chassi ? `<button type="button" class="copy-btn" id="copy-chassi">Copiar</button>` : '');
  $('resumo-grid').innerHTML = [
    fldHtml('car','Modelo',model,'hl'),
    fldHtml('building','Marca',brand,'hl'),
    fldHtml('droplet','Cor',val('cor'),'hl'),
    fldHtml('calendar','Ano',ano,'hl'),
    fldHtml('fuel','Combustível',val('combustivel'),'hl'),
    fldHtml('zap','Potência',val('potencia'),'hl'),
    fldHtml('pin','Município',val('municipio'),'hl'),
    fldHtml('globe','Origem',val('origem'),'hl'),
    fldHtml('hash','Chassi',chassi,'hl chassi',chassiValueHtml),
  ].join('');

  const rouboHtml = roubo ? `<span class="badge-roubo ${/nada consta|sem|nao consta|não consta/i.test(roubo) ? 'ok' : 'bad'}">${esc(roubo)}</span>` : '';
  const BASICOS = [
    ['Marca/Modelo', val('marcamodelo')], ['Ano modelo', val('anoModelo')], ['UF', uf], ['Procedência', val('procedencia')],
    ['Placa (novo)', placaNova], ['Placa (antigo)', placaAntiga],
    ['Espécie', val('especie')], ['Tipo', val('tipo') || val('tipoVeiculo')], ['Tipo carroceria', val('tipoCarroceria')],
    ['Motor', val('motor')], ['Cilindradas', val('cilindradas')],
    ['CMC', val('cmc')], ['CMT', val('cmt')], ['PBT', val('pbt')], ['Lotação', val('lotacao')], ['Qtd. eixos', val('qtdEixos')],
    ['Segmento', val('segmento')], ['Sub-segmento', val('sub_segmento')], ['Renavam', renavam], ['Final do chassi', val('final_chassi')],
    ['Data', val('data')], ['Atualizado', (d.atualizado === true ? 'Sim' : (d.atualizado === false ? 'Não' : ''))], ['Retorno', val('mensagemRetorno')],
  ];
  let basicosHtml = BASICOS.map(([label, value]) => fldHtml('info', label, value)).join('');
  if (rouboHtml) basicosHtml += fldHtml('info', 'Roubo/Furto', 'x', '', rouboHtml);
  $('basicos-grid').innerHTML = basicosHtml;

  $('result').classList.add('show');

  const copyBtn = $('copy-chassi');
  if (copyBtn && chassi) {
    copyBtn.addEventListener('click', async () => {
      const fallback = () => {
        const ta = document.createElement('textarea');
        ta.value = chassi; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.focus(); ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
      };
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) await navigator.clipboard.writeText(chassi);
        else fallback();
      } catch (e) { fallback(); }
      copyBtn.textContent = 'Copiado ✓'; copyBtn.classList.add('ok');
      setTimeout(() => { copyBtn.textContent = 'Copiar'; copyBtn.classList.remove('ok'); }, 1500);
    });
  }
}
</script>
</body>
</html>
