<?php
declare(strict_types=1);
require __DIR__ . '/conexao_inventario.php';
require __DIR__ . '/gerador_lib.php';
require __DIR__ . '/conexao_oracle.php';
session_start();

if (empty($_SESSION['inventario_login'])) {
    header('Location: login.php');
    exit;
}

$usuarioLogado = $_SESSION['inventario_login']['usuario'] ?? '';

// ---------- CONFIG MYSQL ----------
$mysql_host = 'localhost';
$mysql_db   = 'inventario';
$mysql_user = 'app_user';
$mysql_pass = 'TROQUE_NO_SERVIDOR';

$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_pass, $mysql_db);
if ($mysqli->connect_error) {
    die('Erro de conexão com o banco: ' . $mysqli->connect_error);
}

function fetchAllPrepared(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchOnePrepared(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function conectar_mysql($mysql_host, $mysql_db, $mysql_user, $mysql_pass) {
    $dsn = "mysql:host={$mysql_host};dbname={$mysql_db};charset=utf8";
    try {
        $pdo = new PDO($dsn, $mysql_user, $mysql_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (PDOException $e) {
        die('Erro ao conectar no MySQL: ' . htmlspecialchars($e->getMessage()));
    }
    return $pdo;
}

// LOJAS
$pdoLojas = conectar_mysql($mysql_host, $mysql_db, $mysql_user, $mysql_pass);
$sqlLojas = "SELECT id, empresa, revenda, codigo, ativo FROM inv_lojas WHERE ativo = 1 ORDER BY empresa, revenda";
$stmtLojas = $pdoLojas->query($sqlLojas);
$lojas = [];
while ($row = $stmtLojas->fetch(PDO::FETCH_ASSOC)) {
    $partes    = explode('.', (string)$row['codigo']);
    $empresaId = (int)($partes[0] ?? 0);
    $revendaId = (int)($partes[1] ?? 0);
    $lojas[] = [
        'id'         => (int)$row['id'],
        'empresa'    => $empresaId,
        'revenda'    => $revendaId,
        'loja_label' => $row['empresa'] . ' — ' . $row['revenda'],
    ];
}

function obterEmpresaRevendaPorIdLoja($idLoja, $lojas) {
    foreach ($lojas as $l) {
        if ((int)$l['id'] === (int)$idLoja) {
            return [$l['empresa'], $l['revenda']];
        }
    }
    return [null, null];
}

function buscarItensInventarioOracle($ora, int $empresa, int $revenda, string $tipo, string $condLoc = '', ?string $locIni = null, ?string $locFim = null): array {
    if ($tipo === 'terceiros') {
        $colQuantidade = 'r.qtd_pertence_terceiros';
        $condTipo      = 'AND r.qtd_pertence_terceiros > 0';
    } else {
        $colQuantidade = '(r.qtd_contabil - r.qtd_alocada)';
        $condTipo      = 'AND (r.qtd_contabil - r.qtd_alocada) > 0';
    }
    $sql = "
        SELECT e.empresa, r.revenda, e.item_estoque, e.item_estoque_pub,
               (SELECT e2.item_estoque_pub FROM pec_item_alternativo a2 INNER JOIN pec_item_estoque e2 ON e2.empresa = a2.empresa AND e2.item_estoque = a2.item_estoque_referenciado WHERE a2.empresa = e.empresa AND a2.item_estoque = e.item_estoque AND ROWNUM = 1) AS item_pub_revenda,
               e.des_item_estoque,
               {$colQuantidade} AS quantidade, r.locacao_zona, r.locacao_rua, r.locacao_estante,
               r.locacao_prateleira, r.locacao_numero,
               (r.locacao_zona||'-'||r.locacao_rua||'-'||r.locacao_estante||'-'||r.locacao_prateleira||'-'||r.locacao_numero) AS locacao_geral,
               r.class_abc, r.cus_ult_entrada, e.preco_alternativo
        FROM pec_item_estoque e
        INNER JOIN pec_item_revenda r ON e.empresa = r.empresa AND e.item_estoque = r.item_estoque
        WHERE e.empresa = :empresa AND r.revenda = :revenda {$condTipo} {$condLoc}
    ";
    $stid = oci_parse($ora, $sql);
    oci_bind_by_name($stid, ':empresa', $empresa);
    oci_bind_by_name($stid, ':revenda', $revenda);
    if ($condLoc !== '' && $locIni !== null && $locFim !== null) {
        oci_bind_by_name($stid, ':loc_ini', $locIni);
        oci_bind_by_name($stid, ':loc_fim', $locFim);
    }
    oci_execute($stid);
    $itens = [];
    while ($row = oci_fetch_assoc($stid)) { $itens[] = $row; }
    oci_free_statement($stid);
    return $itens;
}

$itensEstoque      = $_SESSION['itensEstoque'] ?? [];
$coletoresGrupos   = $_SESSION['coletores_grupos'] ?? [];
$erro              = '';
$sucesso           = '';
$lojaDaBusca       = isset($_SESSION['itens_loja']) ? (int)$_SESSION['itens_loja'] : null;
// Preenchidos só quando um inventário é criado nesta própria requisição
// (não há mais estado "inventário atual" persistente em sessão).
$idCriado          = null;
$itensCriados      = null;
$codigoDashboard   = null;

// Preserva valores do form após POST
$postLoja      = (int)($_POST['loja'] ?? 0);
$postTodas     = !empty($_POST['todas_locacoes']);
$postTerceiros = !empty($_POST['pecas_terceiros']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao   = $_POST['acao'] ?? 'gerar';
    $idLoja = (int)($_POST['loja'] ?? 0);

    if ($acao === 'gerar') {
        $iniZona=$_POST['ini_zona']??''; $iniRua=$_POST['ini_rua']??''; $iniEst=$_POST['ini_estante']??'';
        $iniPrat=$_POST['ini_prateleira']??''; $iniNum=$_POST['ini_numero']??'';
        $fimZona=$_POST['fim_zona']??''; $fimRua=$_POST['fim_rua']??''; $fimEst=$_POST['fim_estante']??'';
        $fimPrat=$_POST['fim_prateleira']??''; $fimNum=$_POST['fim_numero']??'';
        $todas=$postTodas; $incluiTerceiros=$postTerceiros;

        if ($idLoja <= 0) { $erro='Selecione uma loja.'; }
        else {
            list($empresa, $revenda) = obterEmpresaRevendaPorIdLoja($idLoja, $lojas);
            if ($empresa===null||$revenda===null) { $erro='Loja não mapeada.'; }
            else {
                $ora = oracle_conectar();
                if (!$ora) { $erro = 'Erro ao conectar no Oracle (Apollo). Tente de novo.'; }
                $condLoc=''; $locIni=null; $locFim=null;
                if ($erro==='' && !$todas) {
                    if (!$iniZona||!$iniRua||!$iniEst||!$iniPrat||!$iniNum||!$fimZona||!$fimRua||!$fimEst||!$fimPrat||!$fimNum) {
                        $erro='Preencha todos os campos de locação inicial e final, ou marque "Todas as locações".';
                    } else {
                        $locIni=$iniZona.'.'.str_pad($iniRua,3,'0',STR_PAD_LEFT).'.'.str_pad($iniEst,3,'0',STR_PAD_LEFT).'.'.$iniPrat.'.'.str_pad($iniNum,3,'0',STR_PAD_LEFT);
                        $locFim=$fimZona.'.'.str_pad($fimRua,3,'0',STR_PAD_LEFT).'.'.str_pad($fimEst,3,'0',STR_PAD_LEFT).'.'.$fimPrat.'.'.str_pad($fimNum,3,'0',STR_PAD_LEFT);
                        $condLoc="AND ((r.locacao_zona||'.'||LPAD(r.locacao_rua,3,'0')||'.'||LPAD(r.locacao_estante,3,'0')||'.'||r.locacao_prateleira||'.'||LPAD(r.locacao_numero,3,'0')) BETWEEN :loc_ini AND :loc_fim)";
                    }
                }
                if ($ora && $erro!=='') { oci_close($ora); }
                if ($erro==='') {
                    $itensEstoque = buscarItensInventarioOracle($ora,(int)$empresa,(int)$revenda,$incluiTerceiros?'terceiros':'proprio',$condLoc,$condLoc!==''?$locIni:null,$condLoc!==''?$locFim:null);
                    oci_close($ora);
                    $_SESSION['itensEstoque'] = $itensEstoque;
                    $_SESSION['itens_loja']   = $idLoja; // trava: liga os itens à loja em que foram buscados
                    $sucesso = count($itensEstoque).' itens carregados do Oracle com sucesso.';
                }
            }
        }
    } elseif ($acao === 'limpar') {
        unset($_SESSION['itensEstoque'], $_SESSION['itens_loja']); $itensEstoque=[];
        $sucesso='Lista de itens limpa.';
    } elseif ($acao === 'criar') {
        $coletoresPost = gerador_coletores_do_post($_POST);
        $erro = gerador_validar_criacao($lojaDaBusca, $idLoja, count($itensEstoque), $coletoresPost);

        if ($erro === null) {
            list($empresaInv, $revendaInv) = obterEmpresaRevendaPorIdLoja($idLoja, $lojas);
            if ($empresaInv === null || $revendaInv === null) {
                $erro = 'Loja não mapeada.';
            } else {
                try {
                    $resultado = gerador_criar_inventario($pdoLojas, (int)$empresaInv, (int)$revendaInv, $itensEstoque, $coletoresPost);

                    $coletoresGrupos = $coletoresPost;
                    $_SESSION['coletores_grupos'] = $coletoresGrupos;
                    unset($_SESSION['itensEstoque'], $_SESSION['itens_loja']);
                    // Não usamos mais estado de "inventário atual" persistente em sessão.
                    unset($_SESSION['id_inventario_atual'], $_SESSION['empresa_inventario'], $_SESSION['revenda_inventario']);
                    $itensEstoque = [];
                    $lojaDaBusca  = null;

                    $idCriado        = $resultado['id'];
                    $itensCriados    = $resultado['itens'];
                    $codigoDashboard = $empresaInv . '.' . $revendaInv;
                    $sucesso = "Inventário #{$idCriado} criado com {$itensCriados} itens.";
                } catch (Exception $e) {
                    $erro = 'Erro ao criar inventário: ' . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
}

$qtItens = count($itensEstoque);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Gerador de Inventário</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --red: #2347C8;
      --red-dark: #b8000f;
      --red-soft: #fff0f1;
      --surface: #F7F7F8;
      --card: #FFFFFF;
      --border: #E5E5E8;
      --border-strong: #CFCFD6;
      --txt: #1A1A2E;
      --txt2: #4A4A6A;
      --txt3: #8888AA;
      --green: #0f9d58;
      --green-soft: #f0fdf4;
      --amber: #f59e0b;
      --amber-soft: #fffbeb;
      --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
      --shadow-md: 0 4px 12px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.04);
      --shadow-lg: 0 12px 32px rgba(0,0,0,.10), 0 4px 8px rgba(0,0,0,.06);
      --r: 12px;
      --r-sm: 8px;
      --r-lg: 16px;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body {
      font-family: 'DM Sans', system-ui, sans-serif;
      background: var(--surface);
      color: var(--txt);
      min-height: 100vh;
      font-size: 14px;
      line-height: 1.5;
    }

    /* ── TOPBAR ── */
    .topbar {
      position: sticky; top: 0; z-index: 100;
      background: rgba(255,255,255,0.92);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--border);
      padding: 0 24px;
      height: 56px;
      display: flex; align-items: center; justify-content: space-between;
      box-shadow: var(--shadow-sm);
    }
    .topbar-brand {
      display: flex; align-items: center; gap: 10px;
    }
    .topbar-logo {
      width: 32px; height: 32px;
      background: var(--red);
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      color: #fff; font-size: 14px; font-weight: 700;
    }
    .topbar-title {
      font-size: 15px; font-weight: 700; color: var(--txt);
      letter-spacing: -.01em;
    }
    .topbar-title span { color: var(--red); }
    .topbar-right { display: flex; align-items: center; gap: 12px; }
    .topbar-user {
      font-size: 12px; color: var(--txt2); font-weight: 500;
      padding: 5px 10px; background: var(--surface); border-radius: 999px;
      border: 1px solid var(--border);
    }
    .topbar-back {
      font-size: 12px; font-weight: 600; color: var(--txt2);
      text-decoration: none;
      padding: 6px 14px; border-radius: 999px;
      border: 1px solid var(--border);
      background: #fff;
      transition: all .15s;
      display: flex; align-items: center; gap: 5px;
    }
    .topbar-back:hover { border-color: var(--red); color: var(--red); }

    /* ── LAYOUT ── */
    .page {
      max-width: 1100px;
      margin: 0 auto;
      padding: 28px 20px 60px;
      display: flex; flex-direction: column; gap: 20px;
    }

    /* ── STATUS BAR ── */
    .status-bar {
      display: flex; align-items: stretch; gap: 12px; flex-wrap: wrap;
    }
    .status-chip {
      display: flex; align-items: center; gap: 8px;
      padding: 10px 16px;
      background: var(--card); border-radius: var(--r);
      border: 1px solid var(--border);
      box-shadow: var(--shadow-sm);
      flex: 1; min-width: 160px;
    }
    .status-chip-dot {
      width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
    }
    .dot-green { background: var(--green); box-shadow: 0 0 0 3px rgba(15,157,88,.15); }
    .dot-amber { background: var(--amber); box-shadow: 0 0 0 3px rgba(245,158,11,.15); }
    .dot-gray  { background: #ccc; }
    .status-chip-body { display: flex; flex-direction: column; gap: 1px; }
    .status-chip-label { font-size: 10px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: var(--txt3); }
    .status-chip-value { font-size: 14px; font-weight: 700; color: var(--txt); font-family: 'DM Mono', monospace; }

    /* ── NOTIFICAÇÕES ── */
    .notif {
      padding: 12px 16px;
      border-radius: var(--r-sm);
      font-size: 13px;
      font-weight: 500;
      display: flex; align-items: flex-start; gap: 10px;
      animation: slideIn .2s ease;
    }
    @keyframes slideIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
    .notif-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
    .notif-erro    { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .notif-sucesso { background: var(--green-soft); border: 1px solid #bbf7d0; color: #166534; }

    /* ── CARD ── */
    .card {
      background: var(--card);
      border-radius: var(--r-lg);
      border: 1px solid var(--border);
      box-shadow: var(--shadow-sm);
      overflow: hidden;
    }
    .card-header {
      padding: 16px 20px;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      background: #fafafa;
    }
    .card-header-left { display: flex; align-items: center; gap: 10px; }
    .card-icon {
      width: 32px; height: 32px; border-radius: var(--r-sm);
      display: flex; align-items: center; justify-content: center;
      font-size: 15px; flex-shrink: 0;
    }
    .card-icon-red   { background: var(--red-soft); }
    .card-icon-blue  { background: #eff6ff; }
    .card-icon-green { background: var(--green-soft); }
    .card-title { font-size: 13px; font-weight: 700; color: var(--txt); letter-spacing: -.01em; }
    .card-sub   { font-size: 11px; color: var(--txt3); margin-top: 1px; }
    .card-body  { padding: 20px; }

    /* ── FORM FIELDS ── */
    .field { display: flex; flex-direction: column; gap: 5px; }
    .field-label {
      font-size: 11px; font-weight: 700; letter-spacing: .1em;
      text-transform: uppercase; color: var(--txt2);
    }
    .field-input, .field-select {
      height: 40px;
      border-radius: var(--r-sm);
      border: 1.5px solid var(--border);
      padding: 0 12px;
      font-size: 13px;
      font-family: 'DM Sans', sans-serif;
      background: #fff;
      color: var(--txt);
      transition: border-color .15s, box-shadow .15s;
      width: 100%;
    }
    .field-input::placeholder { color: var(--txt3); }
    .field-input:focus, .field-select:focus {
      outline: none;
      border-color: var(--red);
      box-shadow: 0 0 0 3px rgba(35,71,200,.1);
    }
    .field-select { cursor: pointer; }

    /* ── FORM GRID ── */
    .form-row { display: flex; gap: 12px; flex-wrap: wrap; }
    .form-row .field { flex: 1; min-width: 120px; }
    .form-row .field-wide { flex: 2; }

    /* ── LOC GRID ── */
    .loc-section { margin-bottom: 16px; }
    .loc-section-title {
      font-size: 11px; font-weight: 700; letter-spacing: .1em;
      text-transform: uppercase; color: var(--txt3);
      margin-bottom: 8px;
      display: flex; align-items: center; gap: 6px;
    }
    .loc-section-title::after {
      content: ''; flex: 1; height: 1px; background: var(--border);
    }
    .loc-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 8px;
    }
    .loc-input {
      height: 38px;
      border-radius: var(--r-sm);
      border: 1.5px solid var(--border);
      padding: 0 10px;
      font-size: 13px;
      font-family: 'DM Mono', monospace;
      background: #fff;
      color: var(--txt);
      text-align: center;
      transition: border-color .15s, box-shadow .15s;
      width: 100%;
    }
    .loc-input::placeholder { color: var(--txt3); font-size: 11px; font-family: 'DM Sans', sans-serif; }
    .loc-input:focus {
      outline: none; border-color: var(--red);
      box-shadow: 0 0 0 3px rgba(35,71,200,.1);
    }
    .loc-input:disabled {
      background: var(--surface); color: var(--txt3); cursor: not-allowed;
    }
    .loc-labels {
      display: grid; grid-template-columns: repeat(5, 1fr);
      gap: 8px; margin-bottom: 4px;
    }
    .loc-label-item {
      font-size: 10px; font-weight: 600; letter-spacing: .08em;
      text-transform: uppercase; color: var(--txt3); text-align: center;
    }

    /* ── TOGGLES ── */
    .toggle-row {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 14px;
      background: var(--surface);
      border-radius: var(--r-sm);
      border: 1px solid var(--border);
      cursor: pointer;
      transition: background .15s, border-color .15s;
    }
    .toggle-row:hover { border-color: var(--red); background: var(--red-soft); }
    .toggle-row input[type="checkbox"] {
      width: 16px; height: 16px; accent-color: var(--red); cursor: pointer; flex-shrink: 0;
    }
    .toggle-row label { font-size: 13px; font-weight: 500; cursor: pointer; flex: 1; }
    .toggle-row .toggle-badge {
      font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 999px;
      background: var(--red); color: #fff; letter-spacing: .06em; text-transform: uppercase;
    }
    .toggles-group { display: flex; flex-direction: column; gap: 8px; }

    /* ── COLETORES ── */
    .coletores-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 10px;
    }
    .coletor-card {
      background: var(--surface);
      border: 1.5px solid var(--border);
      border-radius: var(--r);
      padding: 12px 14px;
      display: flex; flex-direction: column; gap: 8px;
      transition: border-color .15s;
    }
    .coletor-card:focus-within { border-color: var(--red); }
    .coletor-num {
      display: flex; align-items: center; gap: 8px;
      font-size: 11px; font-weight: 700; letter-spacing: .1em;
      text-transform: uppercase; color: var(--txt3);
    }
    .coletor-num-badge {
      width: 22px; height: 22px; border-radius: 6px;
      background: var(--red); color: #fff;
      font-size: 11px; font-weight: 700;
      display: flex; align-items: center; justify-content: center;
    }
    .coletor-inputs { display: flex; gap: 6px; }
    .coletor-inputs .field-input { flex: 1; height: 36px; font-size: 12px; }

    /* ── BOTÕES ── */
    .actions {
      display: flex; gap: 8px; flex-wrap: wrap;
      padding: 16px 20px;
      background: #fafafa;
      border-top: 1px solid var(--border);
    }
    .btn {
      border: none; border-radius: 999px;
      padding: 9px 20px;
      font-size: 12px; font-weight: 700; font-family: 'DM Sans', sans-serif;
      letter-spacing: .06em; text-transform: uppercase;
      cursor: pointer; transition: all .15s;
      display: inline-flex; align-items: center; gap: 6px;
      white-space: nowrap;
    }
    .btn-primary {
      background: var(--red); color: #fff;
      box-shadow: 0 4px 12px rgba(35,71,200,.3);
    }
    .btn-primary:hover { background: var(--red-dark); box-shadow: 0 6px 16px rgba(35,71,200,.4); transform: translateY(-1px); }
    .btn-primary:active { transform: translateY(0); }
    .btn-success {
      background: var(--green); color: #fff;
      box-shadow: 0 4px 12px rgba(15,157,88,.25);
    }
    .btn-success:hover { filter: brightness(.92); transform: translateY(-1px); }
    .btn-ghost {
      background: var(--card); color: var(--txt2);
      border: 1.5px solid var(--border);
    }
    .btn-ghost:hover { border-color: var(--red); color: var(--red); }
    .btn-danger-ghost {
      background: transparent; color: #991b1b;
      border: 1.5px solid #fecaca;
    }
    .btn-danger-ghost:hover { background: #fef2f2; border-color: var(--red); }
    .btn-icon { font-size: 15px; }
    .btn-spacer { flex: 1; }

    /* ── TABELA ── */
    .table-wrap {
      overflow-x: auto;
      border-radius: var(--r);
      border: 1px solid var(--border);
      margin-top: 4px;
    }
    .table-header {
      padding: 12px 16px;
      display: flex; align-items: center; justify-content: space-between;
      border-bottom: 1px solid var(--border);
      background: #fafafa;
    }
    .table-title {
      font-size: 12px; font-weight: 700; letter-spacing: .08em;
      text-transform: uppercase; color: var(--txt2);
      display: flex; align-items: center; gap: 8px;
    }
    .table-count {
      background: var(--red); color: #fff;
      font-size: 11px; font-weight: 700;
      padding: 2px 8px; border-radius: 999px;
    }
    .table-search {
      position: relative;
    }
    .table-search input {
      height: 32px; border-radius: 999px;
      border: 1.5px solid var(--border);
      padding: 0 12px 0 30px;
      font-size: 12px; font-family: 'DM Sans', sans-serif;
      background: #fff; color: var(--txt);
      outline: none; width: 180px;
      transition: border-color .15s, width .2s;
    }
    .table-search input:focus { border-color: var(--red); width: 220px; }
    .table-search-icon {
      position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
      font-size: 12px; color: var(--txt3); pointer-events: none;
    }
    table {
      width: 100%; border-collapse: collapse;
      font-size: 12px; background: var(--card);
    }
    thead { background: var(--surface); }
    th {
      padding: 8px 12px; text-align: left;
      font-size: 10px; font-weight: 700; letter-spacing: .12em;
      text-transform: uppercase; color: var(--txt3);
      border-bottom: 1px solid var(--border);
      white-space: nowrap;
    }
    td {
      padding: 8px 12px; border-bottom: 1px solid var(--border);
      color: var(--txt);
    }
    tbody tr:last-child td { border-bottom: none; }
    tbody tr:hover td { background: var(--red-soft); }
    .td-mono { font-family: 'DM Mono', monospace; font-size: 11px; }
    .td-badge {
      display: inline-flex; align-items: center;
      padding: 2px 7px; border-radius: 4px;
      font-size: 10px; font-weight: 700; letter-spacing: .08em;
    }
    .badge-a { background: #d1fae5; color: #065f46; }
    .badge-b { background: #dbeafe; color: #1e40af; }
    .badge-c { background: #fef3c7; color: #92400e; }
    .badge-default { background: var(--border); color: var(--txt3); }
    .td-qty { font-weight: 700; font-family: 'DM Mono', monospace; color: var(--txt); }

    /* ── EMPTY STATE ── */
    .empty-state {
      padding: 48px 20px;
      text-align: center;
      color: var(--txt3);
    }
    .empty-state-icon { font-size: 40px; margin-bottom: 12px; opacity: .4; }
    .empty-state-title { font-size: 14px; font-weight: 600; color: var(--txt2); margin-bottom: 4px; }
    .empty-state-sub { font-size: 12px; }

    /* ── DIVIDER ── */
    .divider { height: 1px; background: var(--border); margin: 16px 0; }

    /* ── RESPONSIVE ── */
    @media (max-width: 720px) {
      .loc-grid { grid-template-columns: repeat(3,1fr); }
      .loc-labels { grid-template-columns: repeat(3,1fr); }
      .topbar { padding: 0 16px; }
      .page { padding: 16px 12px 60px; }
      .card-body { padding: 14px; }
      .actions { padding: 12px 14px; }
      .status-bar { gap: 8px; }
      .status-chip { min-width: 130px; }
    }
    @media (max-width: 480px) {
      .loc-grid { grid-template-columns: repeat(2,1fr); }
      .loc-labels { grid-template-columns: repeat(2,1fr); }
      .topbar-user { display: none; }
      .btn-spacer { display: none; }
    }
  </style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <div class="topbar-brand">
    <div class="topbar-logo">INV</div>
    <div class="topbar-title">Gerador de <span>Inventário</span></div>
  </div>
  <div class="topbar-right">
    <div class="topbar-user">👤 <?php echo htmlspecialchars($usuarioLogado); ?></div>
    <a href="dashboard_inventario.php" class="topbar-back">
      ← Sair
    </a>
  </div>
</div>

<div class="page">

  <!-- STATUS BAR -->
  <div class="status-bar">
    <div class="status-chip">
      <div class="status-chip-dot <?php echo $idCriado !== null ? 'dot-green' : 'dot-gray'; ?>"></div>
      <div class="status-chip-body">
        <div class="status-chip-label">Último Inventário Criado</div>
        <div class="status-chip-value"><?php echo $idCriado !== null ? '#'.$idCriado : '—'; ?></div>
      </div>
    </div>
    <div class="status-chip">
      <div class="status-chip-dot <?php echo $qtItens > 0 ? 'dot-green' : 'dot-gray'; ?>"></div>
      <div class="status-chip-body">
        <div class="status-chip-label">Itens Carregados</div>
        <div class="status-chip-value"><?php echo number_format($qtItens, 0, ',', '.'); ?></div>
      </div>
    </div>
    <div class="status-chip">
      <div class="status-chip-dot <?php echo !empty($coletoresGrupos) ? 'dot-green' : 'dot-gray'; ?>"></div>
      <div class="status-chip-body">
        <div class="status-chip-label">Coletores</div>
        <div class="status-chip-value"><?php echo count($coletoresGrupos); ?> configurados</div>
      </div>
    </div>
  </div>

  <!-- NOTIFICAÇÕES -->
  <?php if (!empty($erro)): ?>
  <div class="notif notif-erro">
    <span class="notif-icon">⚠️</span>
    <span><?php echo htmlspecialchars($erro); ?></span>
  </div>
  <?php elseif (!empty($sucesso)): ?>
  <div class="notif notif-sucesso">
    <span class="notif-icon">✅</span>
    <span><?php echo htmlspecialchars($sucesso); ?></span>
    <?php if ($idCriado !== null && $codigoDashboard !== null): ?>
      <a href="dashboard_inventario.php?codigo=<?php echo urlencode($codigoDashboard); ?>&inv=<?php echo (int)$idCriado; ?>"
         class="btn btn-primary" style="margin-left:auto;">
        <span class="btn-icon">📊</span> Abrir no dashboard
      </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <form method="post" id="main-form">

    <!-- CARD: PARÂMETROS -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <div class="card-icon card-icon-red">⚙️</div>
          <div>
            <div class="card-title">Parâmetros do Inventário</div>
            <div class="card-sub">Selecione a loja e o intervalo de locações</div>
          </div>
        </div>
      </div>
      <div class="card-body">

        <!-- LOJA -->
        <div class="field" style="margin-bottom:16px;">
          <div class="field-label">Loja</div>
          <select name="loja" class="field-select" required id="select-loja">
            <option value="">Selecione a loja...</option>
            <?php foreach ($lojas as $loja): ?>
              <option value="<?php echo (int)$loja['id']; ?>"
                <?php echo $postLoja === (int)$loja['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($loja['loja_label']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- LOCAÇÕES -->
        <div id="loc-block">
          <div class="loc-labels">
            <div class="loc-label-item">Zona</div>
            <div class="loc-label-item">Rua</div>
            <div class="loc-label-item">Estante</div>
            <div class="loc-label-item">Prat.</div>
            <div class="loc-label-item">Nº</div>
          </div>

          <div class="loc-section">
            <div class="loc-section-title">Locação Inicial</div>
            <div class="loc-grid">
              <input type="text" name="ini_zona"       class="loc-input" placeholder="Zona"  id="li-zona"  value="<?php echo htmlspecialchars($_POST['ini_zona']??''); ?>">
              <input type="text" name="ini_rua"        class="loc-input" placeholder="Rua"   id="li-rua"   value="<?php echo htmlspecialchars($_POST['ini_rua']??''); ?>">
              <input type="text" name="ini_estante"    class="loc-input" placeholder="Est."  id="li-est"   value="<?php echo htmlspecialchars($_POST['ini_estante']??''); ?>">
              <input type="text" name="ini_prateleira" class="loc-input" placeholder="Prat." id="li-prat"  value="<?php echo htmlspecialchars($_POST['ini_prateleira']??''); ?>">
              <input type="text" name="ini_numero"     class="loc-input" placeholder="Nº"    id="li-num"   value="<?php echo htmlspecialchars($_POST['ini_numero']??''); ?>">
            </div>
          </div>

          <div class="loc-section">
            <div class="loc-section-title">Locação Final</div>
            <div class="loc-grid">
              <input type="text" name="fim_zona"       class="loc-input" placeholder="Zona"  id="lf-zona"  value="<?php echo htmlspecialchars($_POST['fim_zona']??''); ?>">
              <input type="text" name="fim_rua"        class="loc-input" placeholder="Rua"   id="lf-rua"   value="<?php echo htmlspecialchars($_POST['fim_rua']??''); ?>">
              <input type="text" name="fim_estante"    class="loc-input" placeholder="Est."  id="lf-est"   value="<?php echo htmlspecialchars($_POST['fim_estante']??''); ?>">
              <input type="text" name="fim_prateleira" class="loc-input" placeholder="Prat." id="lf-prat"  value="<?php echo htmlspecialchars($_POST['fim_prateleira']??''); ?>">
              <input type="text" name="fim_numero"     class="loc-input" placeholder="Nº"    id="lf-num"   value="<?php echo htmlspecialchars($_POST['fim_numero']??''); ?>">
            </div>
          </div>
        </div>

        <!-- TOGGLES -->
        <div class="toggles-group">
          <div class="toggle-row">
            <input type="checkbox" id="cb-todas" name="todas_locacoes" value="1" <?php echo $postTodas ? 'checked' : ''; ?>>
            <label for="cb-todas">Todas as locações (ignora intervalo)</label>
            <span class="toggle-badge">ATENÇÃO</span>
          </div>
          <div class="toggle-row">
            <input type="checkbox" id="cb-terceiros" name="pecas_terceiros" value="1" <?php echo $postTerceiros ? 'checked' : ''; ?>>
            <label for="cb-terceiros">Peças pertencentes a terceiros</label>
          </div>
        </div>

      </div>
    </div>

    <!-- CARD: COLETORES -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <div class="card-icon card-icon-blue">👥</div>
          <div>
            <div class="card-title">Configuração de Coletores</div>
            <div class="card-sub">Digite os nomes exatamente como serão usados no login</div>
          </div>
        </div>
      </div>
      <div class="card-body">
        <div class="coletores-grid">
          <?php for ($i = 1; $i <= 8; $i++):
            $nome1 = $coletoresGrupos[$i][0] ?? '';
            $nome2 = $coletoresGrupos[$i][1] ?? '';
          ?>
          <div class="coletor-card">
            <div class="coletor-num">
              <div class="coletor-num-badge"><?php echo $i; ?></div>
              Coletor <?php echo $i; ?>
            </div>
            <div class="coletor-inputs">
              <input type="text" name="coletor<?php echo $i; ?>_nome1" class="field-input"
                     placeholder="Nome 1" value="<?php echo htmlspecialchars($nome1); ?>">
              <input type="text" name="coletor<?php echo $i; ?>_nome2" class="field-input"
                     placeholder="Nome 2 (opcional)" value="<?php echo htmlspecialchars($nome2); ?>">
            </div>
          </div>
          <?php endfor; ?>
        </div>
      </div>
      <div class="actions">
        <button type="submit" name="acao" value="gerar" class="btn btn-primary">
          <span class="btn-icon">🔍</span> Buscar Itens
        </button>
        <div class="btn-spacer"></div>
        <?php if ($qtItens > 0): ?>
        <button type="submit" name="acao" value="limpar" class="btn btn-danger-ghost">
          <span class="btn-icon">🗑</span> Limpar Itens
        </button>
        <?php endif; ?>
      </div>
    </div>

  <!-- CARD: TABELA DE ITENS -->
  <?php if ($qtItens > 0): ?>
  <div class="card">
    <div class="card-header">
      <div class="card-header-left">
        <div class="card-icon card-icon-green">📦</div>
        <div>
          <div class="card-title">Itens do Estoque</div>
          <div class="card-sub">Carregados do Oracle — prontos para salvar</div>
        </div>
      </div>
    </div>
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <div class="table-title">
        Total
        <span class="table-count"><?php echo number_format($qtItens,0,',','.'); ?></span>
      </div>
      <div class="table-search">
        <span class="table-search-icon">🔍</span>
        <input type="text" id="tbl-search" placeholder="Filtrar itens…" oninput="filtrarTabela(this.value)">
      </div>
    </div>
    <div class="table-wrap" style="border:none;border-radius:0;max-height:420px;overflow-y:auto;">
      <table id="tbl-itens">
        <thead>
          <tr>
            <th>Item Pub.</th>
            <th>Descrição</th>
            <th>Qtd</th>
            <th>Locação</th>
            <th>ABC</th>
            <th>Empresa</th>
            <th>Revenda</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($itensEstoque as $item): ?>
          <tr>
            <td class="td-mono"><?php echo htmlspecialchars($item['ITEM_ESTOQUE_PUB'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($item['DES_ITEM_ESTOQUE'] ?? ''); ?></td>
            <td class="td-qty"><?php echo htmlspecialchars($item['QUANTIDADE'] ?? ''); ?></td>
            <td class="td-mono"><?php echo htmlspecialchars($item['LOCACAO_GERAL'] ?? ''); ?></td>
            <td>
              <?php
                $abc = strtoupper($item['CLASS_ABC'] ?? '');
               $classes = ['A' => 'badge-a', 'B' => 'badge-b', 'C' => 'badge-c'];
$cls = $classes[$abc] ?? 'badge-default';
              ?>
              <span class="td-badge <?php echo $cls; ?>"><?php echo $abc ?: '—'; ?></span>
            </td>
            <td class="td-mono"><?php echo htmlspecialchars($item['EMPRESA'] ?? ''); ?></td>
            <td class="td-mono"><?php echo htmlspecialchars($item['REVENDA'] ?? ''); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php else: ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon">📭</div>
      <div class="empty-state-title">Nenhum item carregado</div>
      <div class="empty-state-sub">Selecione a loja e os coletores acima e clique em "Buscar Itens"</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- CARD: CRIAR INVENTÁRIO (último passo, só aparece com itens carregados) -->
  <?php if ($qtItens > 0): ?>
  <div class="card">
    <div class="actions" style="border-top:none;">
      <div class="btn-spacer"></div>
      <button type="submit" name="acao" value="criar" class="btn btn-success" id="btn-criar">
        <span class="btn-icon">✅</span> Criar Inventário
        <span style="background:rgba(255,255,255,.25);border-radius:999px;padding:1px 8px;font-size:10px;">
          <?php echo number_format($qtItens,0,',','.'); ?> itens
        </span>
      </button>
      <div class="btn-spacer"></div>
    </div>
  </div>
  <?php endif; ?>

  </form>

</div><!-- /page -->

<script>
// Desabilita campos de locação quando "Todas" estiver marcado
const cbTodas = document.getElementById('cb-todas');
const locInputs = document.querySelectorAll('.loc-input');

function atualizarLocInputs() {
    const disabled = cbTodas.checked;
    locInputs.forEach(inp => {
        inp.disabled = disabled;
        inp.style.opacity = disabled ? '.4' : '1';
    });
}
cbTodas.addEventListener('change', atualizarLocInputs);
atualizarLocInputs();

// Filtro de tabela
function filtrarTabela(q) {
    const termo = q.toLowerCase();
    document.querySelectorAll('#tbl-itens tbody tr').forEach(tr => {
        const txt = tr.textContent.toLowerCase();
        tr.style.display = txt.includes(termo) ? '' : 'none';
    });
}

// Clique no toggle-row não dispara 2x
document.querySelectorAll('.toggle-row').forEach(row => {
    row.addEventListener('click', e => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'LABEL') return;
        const cb = row.querySelector('input[type="checkbox"]');
        if (cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change')); }
    });
});

// Confirmação antes de criar o inventário: mostra loja, itens e coletores preenchidos
const btnCriar = document.getElementById('btn-criar');
if (btnCriar) {
    btnCriar.addEventListener('click', function(e) {
        const sel = document.getElementById('select-loja');
        const opt = sel.options[sel.selectedIndex];
        const lojaLabel = opt ? opt.text.trim() : '';
        const qtdItens = <?php echo (int)$qtItens; ?>;

        let qtdColetores = 0;
        for (let i = 1; i <= 8; i++) {
            const n1 = document.querySelector('[name="coletor' + i + '_nome1"]');
            const n2 = document.querySelector('[name="coletor' + i + '_nome2"]');
            if ((n1 && n1.value.trim() !== '') || (n2 && n2.value.trim() !== '')) {
                qtdColetores++;
            }
        }

        const msg = 'Criar inventário da loja ' + lojaLabel + ' com ' + qtdItens + ' itens e ' + qtdColetores + ' coletores?';
        if (!confirm(msg)) {
            e.preventDefault();
        }
    });
}

// Feedback visual no botão salvar
document.getElementById('main-form').addEventListener('submit', function(e) {
    const btn = e.submitter;
    if (!btn) return;
    btn.style.opacity = '.7';
    btn.style.pointerEvents = 'none';
    setTimeout(() => { btn.style.opacity = ''; btn.style.pointerEvents = ''; }, 3000);
});
</script>
</body>
</html>