<?php
declare(strict_types=1);
/**
 * Config · Consulta de Placa (Peças) — ADMIN ONLY
 * Token da PuxaPlaca + controle de consumo (custo por consulta, limite diário,
 * consultas disponíveis pelo saldo e uso do dia). Só usuários is_admin acessam.
 */

require_once __DIR__ . '/config_database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['logado'])) {
    header('Location: index.php');
    exit;
}
if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:Segoe UI,Arial;padding:40px;color:#606060">'
       . '<h2 style="color:#2347C8">Acesso restrito</h2>'
       . '<p>Somente administradores podem configurar a Consulta de Placa.</p></body>';
    exit;
}

function cfg_get(mysqli $mysqli, string $chave, string $default = ''): string {
    $v = $default;
    $st = $mysqli->prepare("SELECT valor FROM config_sistema WHERE chave = ? LIMIT 1");
    if ($st) { $st->bind_param('s', $chave); $st->execute(); $st->bind_result($val);
        if ($st->fetch() && $val !== null && $val !== '') $v = (string)$val; $st->close(); }
    return $v;
}
function cfg_set(mysqli $mysqli, string $chave, string $valor, string $desc): void {
    $st = $mysqli->prepare(
        "INSERT INTO config_sistema (chave, valor, descricao) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
    );
    if ($st) { $st->bind_param('sss', $chave, $valor, $desc); $st->execute(); $st->close(); }
}
function usadas_hoje(mysqli $mysqli): int {
    $n = 0;
    $r = $mysqli->query("SELECT COUNT(*) AS c FROM pecas_consulta_log WHERE DATE(criado_em) = CURDATE()");
    if ($r && ($row = $r->fetch_assoc())) $n = (int)$row['c'];
    return $n;
}

// ── AJAX: ver saldo ────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'saldo') {
    header('Content-Type: application/json; charset=utf-8');
    $token = cfg_get($mysqli, 'pecas_puxaplaca_token', '');
    if ($token === '') { http_response_code(400); echo json_encode(['error'=>true,'message'=>'Salve o token primeiro.']); exit; }
    $ch = curl_init('https://api.puxaplaca.app/v2/saldo');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_HTTPHEADER=>['token: '.$token, 'Accept: application/json']]);
    $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($body === false) { http_response_code(502); echo json_encode(['error'=>true,'message'=>'Falha: '.$err]); exit; }
    http_response_code($status ?: 200); echo $body; exit;
}

// ── POST: salvar configurações ─────────────────────────────────────────────
$msg = ''; $msgOk = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['token']))  cfg_set($mysqli, 'pecas_puxaplaca_token', trim((string)$_POST['token']), 'Token da API PuxaPlaca para Consulta de Placa (Peças)');
    if (isset($_POST['custo'])) {
        $c = str_replace(',', '.', trim((string)$_POST['custo']));
        $c = is_numeric($c) ? number_format((float)$c, 2, '.', '') : '0.08';
        cfg_set($mysqli, 'pecas_custo_consulta', $c, 'Custo por consulta de placa (R$) - PuxaPlaca');
    }
    if (isset($_POST['limite'])) {
        cfg_set($mysqli, 'pecas_limite_diario', (string)max(0, (int)$_POST['limite']), 'Limite diário de consultas de placa (0 = sem limite)');
    }
    $msg = 'Configurações salvas com sucesso.'; $msgOk = true;
}

// ── Estado atual ────────────────────────────────────────────────────────────
$token   = cfg_get($mysqli, 'pecas_puxaplaca_token', '');
$custo   = (float)str_replace(',', '.', cfg_get($mysqli, 'pecas_custo_consulta', '0.08')); if ($custo <= 0) $custo = 0.08;
$limite  = (int)cfg_get($mysqli, 'pecas_limite_diario', '0');
$usadas  = usadas_hoje($mysqli);
$restam  = $limite > 0 ? max($limite - $usadas, 0) : null;

// saldo (best-effort)
$saldo = null;
if ($token !== '') {
    $ch = curl_init('https://api.puxaplaca.app/v2/saldo');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>12, CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_HTTPHEADER=>['token: '.$token, 'Accept: application/json']]);
    $b = curl_exec($ch); curl_close($ch);
    if ($b !== false) { $j = json_decode($b, true); if (is_array($j) && isset($j['saldo'])) $saldo = (float)$j['saldo']; }
}
$disponivel = ($saldo !== null) ? (int)floor($saldo / $custo) : null;

// histórico últimos 7 dias
$hist = [];
$rh = $mysqli->query(
    "SELECT DATE(criado_em) AS d, COUNT(*) AS c
     FROM pecas_consulta_log
     WHERE criado_em >= (CURDATE() - INTERVAL 6 DAY)
     GROUP BY DATE(criado_em) ORDER BY d DESC"
);
if ($rh) { while ($row = $rh->fetch_assoc()) $hist[$row['d']] = (int)$row['c']; }

$tokenMask = $token !== '' ? str_repeat('•', max(0, strlen($token) - 4)) . substr($token, -4) : '';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Config · Consulta de Placa</title>
<style>
  :root { --primary:#2347C8; --primary-hover:#1B38A0; --dark:#141922; --gray-dark:#606060; --gray-medium:#888; --gray-light:#EAEAEA; --surface:#FFF; --bg:#F6F6F6; --success:#00A960; --warning:#FF8A2D; --danger:#B3261E; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--dark); font-family:'Segoe UI', Arial, sans-serif; padding:24px; }
  .wrap { max-width:720px; margin:0 auto; }
  h1 { font-size:28px; font-weight:700; margin:0 0 6px; }
  h1 span { color:var(--primary); }
  .sub { color:var(--gray-medium); font-size:14px; margin-bottom:20px; }
  .card { background:var(--surface); border-radius:12px; padding:24px; box-shadow:rgba(0,0,0,.10) 0 2px 8px; margin-bottom:20px; }
  .card h2 { font-size:16px; margin:0 0 16px; }
  label { display:block; font-size:13px; font-weight:700; margin-bottom:6px; color:var(--gray-dark); }
  input { width:100%; height:44px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:15px; outline:none; }
  input:focus { border-color:var(--primary); }
  .row { display:flex; gap:16px; flex-wrap:wrap; }
  .row .col { flex:1; min-width:180px; }
  .hint { font-size:12px; color:var(--gray-medium); margin-top:6px; }
  .current { font-family:'Consolas',monospace; font-size:14px; background:#F8F8F8; border-radius:6px; padding:8px 12px; margin-bottom:16px; }
  .field { margin-bottom:16px; }
  button.primary { height:44px; border:none; border-radius:4px; background:var(--primary); color:#fff; font-weight:700; font-size:14px; padding:0 28px; cursor:pointer; }
  button.primary:hover { background:var(--primary-hover); }
  button.secondary { height:40px; border:1px solid var(--primary); border-radius:4px; background:transparent; color:var(--primary); font-weight:700; font-size:14px; padding:0 24px; cursor:pointer; }
  .ok { background:#D8F5E5; color:var(--success); padding:12px 16px; border-radius:8px; font-weight:700; margin-bottom:20px; }
  .stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:16px; }
  .stat { background:#F8F8F8; border-radius:10px; padding:16px; }
  .stat .n { font-size:26px; font-weight:700; }
  .stat .l { font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--gray-medium); margin-top:2px; }
  .stat.alert .n { color:var(--danger); }
  .bar { height:10px; background:var(--gray-light); border-radius:6px; overflow:hidden; margin-top:14px; }
  .bar > div { height:100%; background:var(--primary); }
  .hist { width:100%; border-collapse:collapse; margin-top:8px; }
  .hist th { text-align:left; font-size:11px; text-transform:uppercase; color:var(--gray-medium); padding:6px 8px; border-bottom:1px solid var(--gray-light); }
  .hist td { font-size:14px; padding:6px 8px; border-bottom:1px solid var(--gray-light); }
  .hist td.qtd { font-weight:700; }
  .err { display:none; background:#FFE8EE; color:var(--danger); padding:12px 16px; border-radius:8px; font-weight:700; margin-top:16px; }
  .err.show { display:block; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Config · <span>Consulta de Placa</span></h1>
  <div class="sub">Token, custo por consulta e limite diário. Acesso restrito a administradores.</div>

  <?php if ($msgOk): ?><div class="ok">✔ <?= $e($msg) ?></div><?php endif; ?>

  <!-- Consumo do dia -->
  <div class="card">
    <h2>Consumo</h2>
    <div class="stats">
      <div class="stat">
        <div class="n"><?= $disponivel !== null ? $disponivel : '—' ?></div>
        <div class="l">Consultas disponíveis<?= $saldo !== null ? ' (saldo R$ ' . number_format($saldo, 2, ',', '.') . ')' : '' ?></div>
      </div>
      <div class="stat">
        <div class="n"><?= $usadas ?></div>
        <div class="l">Usadas hoje</div>
      </div>
      <div class="stat <?= ($restam !== null && $restam === 0) ? 'alert' : '' ?>">
        <div class="n"><?= $restam !== null ? $restam : '∞' ?></div>
        <div class="l">Restam hoje<?= $limite > 0 ? ' (limite ' . $limite . ')' : ' (sem limite)' ?></div>
      </div>
    </div>
    <?php if ($limite > 0): ?>
      <div class="bar"><div style="width:<?= min(100, (int)round($usadas / max($limite,1) * 100)) ?>%"></div></div>
    <?php endif; ?>

    <?php if ($hist): ?>
      <h2 style="margin-top:24px">Últimos dias</h2>
      <table class="hist">
        <thead><tr><th>Data</th><th>Consultas</th><th>Custo estimado</th></tr></thead>
        <tbody>
        <?php foreach ($hist as $dia => $qtd): ?>
          <tr>
            <td><?= $e(date('d/m/Y', strtotime($dia))) ?><?= $dia === date('Y-m-d') ? ' (hoje)' : '' ?></td>
            <td class="qtd"><?= $qtd ?></td>
            <td>R$ <?= number_format($qtd * $custo, 2, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Configurações -->
  <form method="post">
    <div class="card">
      <h2>Token da API</h2>
      <?php if ($tokenMask !== ''): ?>
        <div class="current">Atual: <?= $e($tokenMask) ?></div>
      <?php else: ?>
        <div class="current" style="color:var(--danger)">Nenhum token configurado.</div>
      <?php endif; ?>
      <input id="token" name="token" placeholder="Cole o novo token para substituir" autocomplete="off" value="<?= $e($token) ?>">
      <div class="hint">Obtido em sistema.puxaplaca.app. Fica somente no servidor (banco), nunca é exposto ao navegador dos usuários.</div>
    </div>

    <div class="card">
      <h2>Custo e limite</h2>
      <div class="row">
        <div class="col field">
          <label for="custo">Custo por consulta (R$)</label>
          <input id="custo" name="custo" inputmode="decimal" value="<?= $e(number_format($custo, 2, ',', '')) ?>">
          <div class="hint">Usado para calcular quantas consultas o saldo comporta.</div>
        </div>
        <div class="col field">
          <label for="limite">Limite diário de consultas</label>
          <input id="limite" name="limite" inputmode="numeric" value="<?= $e($limite) ?>">
          <div class="hint">0 = sem limite. Ao atingir, novas consultas são bloqueadas até o dia seguinte.</div>
        </div>
      </div>
      <button type="submit" class="primary">Salvar configurações</button>
    </div>
  </form>

  <div class="card">
    <h2>Saldo</h2>
    <div class="hint" style="margin-bottom:12px">Consulta o saldo atual direto na PuxaPlaca.</div>
    <button type="button" class="secondary" id="btn-saldo">Ver saldo</button>
    <div id="saldo-out" style="margin-top:12px; font-size:15px;"></div>
    <div class="err" id="err"></div>
  </div>
</div>
<script>
document.getElementById('btn-saldo').addEventListener('click', async () => {
  const err = document.getElementById('err'), out = document.getElementById('saldo-out');
  err.classList.remove('show'); out.textContent = '…';
  try {
    const resp = await fetch('config_consulta_placa.php?action=saldo');
    const data = await resp.json();
    if (!resp.ok || data.error) throw new Error(data.message || ('Erro ' + resp.status));
    out.innerHTML = 'Saldo: <b>R$ ' + Number(data.saldo).toFixed(2).replace('.', ',') + '</b>';
  } catch (e) { out.textContent = ''; err.textContent = e.message; err.classList.add('show'); }
});
</script>
</body>
</html>
