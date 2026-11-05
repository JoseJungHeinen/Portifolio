<?php
declare(strict_types=1);
require __DIR__ . '/conexao_inventario.php';
session_start();

if (empty($_SESSION['inventario_login'])) {
    header('Location: login.php');
    exit;
}

$usuarioLogado = $_SESSION['inventario_login']['usuario'] ?? '';

// ORACLE
$oracle_user     = 'app_user';
$oracle_password = 'TROQUE_NO_SERVIDOR';
$oracle_host     = 'servidor.exemplo.local';
$oracle_port     = '1521';
$oracle_service  = 'apollo.privatesubnet.natvcn.oraclevcn.com';

// MySQL
$mysql_host = 'localhost';
$mysql_db   = 'inventario';
$mysql_user = 'app_user';
$mysql_pass = 'TROQUE_NO_SERVIDOR';

$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_pass, $mysql_db);
if ($mysqli->connect_error) {
    die('Erro de conexão com o banco: ' . $mysqli->connect_error);
}

function conectar_mysql($h, $db, $u, $p) {
    try {
        return new PDO("mysql:host={$h};dbname={$db};charset=utf8", $u, $p, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (PDOException $e) {
        die('MySQL: ' . htmlspecialchars($e->getMessage()));
    }
}

function conectar_oracle($u, $p, $h, $port, $svc) {
    $dsn  = "(DESCRIPTION=(ADDRESS_LIST=(ADDRESS=(PROTOCOL=TCP)(HOST={$h})(PORT={$port})))(CONNECT_DATA=(SERVICE_NAME={$svc})))";
    $conn = oci_connect($u, $p, $dsn);
    if (!$conn) {
        $e = oci_error();
        die('Oracle: ' . htmlentities($e['message'], ENT_QUOTES));
    }
    return $conn;
}

function fetchAllPDO(PDO $pdo, string $sql, array $p = []): array {
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function buscarOracle($ora, int $emp, int $rev, string $tipo, string $condLoc = '', ?string $locIni = null, ?string $locFim = null): array {
    $col  = $tipo === 'terceiros' ? 'r.qtd_pertence_terceiros' : '(r.qtd_contabil - r.qtd_alocada)';
    $cond = $tipo === 'terceiros' ? 'AND r.qtd_pertence_terceiros > 0' : 'AND (r.qtd_contabil - r.qtd_alocada) > 0';

    $sql  = "SELECT e.empresa,
                    r.revenda,
                    e.item_estoque,
                    e.item_estoque_pub,
                    e.des_item_estoque,
                    {$col} AS quantidade,
                    r.locacao_zona,
                    r.locacao_rua,
                    r.locacao_estante,
                    r.locacao_prateleira,
                    r.locacao_numero,
                    (r.locacao_zona||'-'||r.locacao_rua||'-'||r.locacao_estante||'-'||r.locacao_prateleira||'-'||r.locacao_numero) AS locacao_geral,
                    r.class_abc,
                    r.cus_ult_entrada,
                    e.preco_alternativo
             FROM pec_item_estoque e
             INNER JOIN pec_item_revenda r
                 ON e.empresa = r.empresa
                AND e.item_estoque = r.item_estoque
             WHERE e.empresa = :empresa
               AND r.revenda = :revenda
               {$cond} {$condLoc}";

    $stid = oci_parse($ora, $sql);
    oci_bind_by_name($stid, ':empresa', $emp);
    oci_bind_by_name($stid, ':revenda', $rev);

    if ($condLoc !== '' && $locIni !== null) {
        oci_bind_by_name($stid, ':loc_ini', $locIni);
        oci_bind_by_name($stid, ':loc_fim',  $locFim);
    }

    oci_execute($stid);
    $rows = [];
    while ($r = oci_fetch_assoc($stid)) {
        $rows[] = $r;
    }
    oci_free_statement($stid);
    return $rows;
}

function salvarColetores(PDO $pdo, int $emp, int $rev, int $inv, array $grupos): void {
    $pdo->prepare(
        "DELETE FROM inv_coletores
         WHERE empresa = :e AND revenda = :r AND id_inventario = :inv"
    )->execute([':e' => $emp, ':r' => $rev, ':inv' => $inv]);

    $ins = $pdo->prepare(
        "INSERT INTO inv_coletores (empresa,revenda,id_inventario,coletor_num,nome)
         VALUES (:e,:r,:inv,:num,:nome)"
    );

    foreach ($grupos as $num => $nomes) {
        $n = is_array($nomes) ? implode(' e ', $nomes) : (string)$nomes;
        if ($n !== '') {
            $ins->execute([
                ':e'    => $emp,
                ':r'    => $rev,
                ':inv'  => $inv,
                ':num'  => (int)$num,
                ':nome' => $n
            ]);
        }
    }
}

// lojas
$pdoLojas = conectar_mysql($mysql_host, $mysql_db, $mysql_user, $mysql_pass);
$lojas    = [];

foreach (fetchAllPDO($pdoLojas, "SELECT id, empresa, revenda, codigo FROM inv_lojas WHERE ativo = 1 ORDER BY empresa, revenda") as $row) {
    $p       = explode('.', (string)$row['codigo']);
    $lojas[] = [
        'id'      => (int)$row['id'],
        'empresa' => (int)($p[0] ?? 0),
        'revenda' => (int)($p[1] ?? 0),
        'label'   => $row['empresa'] . ' — ' . $row['revenda'],
    ];
}

function getLoja($id, $lojas) {
    foreach ($lojas as $l) {
        if ((int)$l['id'] === (int)$id) {
            return [$l['empresa'], $l['revenda']];
        }
    }
    return [null, null];
}

// sessão
$itensEstoque    = $_SESSION['itensEstoque'] ?? [];
$coletoresGrupos = $_SESSION['coletores_grupos'] ?? [];
$erro            = '';
$sucesso         = '';
$stepAtual       = 1;

$postLoja  = (int)($_POST['loja'] ?? $_SESSION['ultima_loja'] ?? 0);
$postTodas = !empty($_POST['todas_locacoes']);
$postTerc  = !empty($_POST['pecas_terceiros']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao   = $_POST['acao'] ?? '';
    $idLoja = (int)($_POST['loja'] ?? 0);

    if ($idLoja > 0) {
        $_SESSION['ultima_loja'] = $idLoja;
    }

    $postLoja = $idLoja ?: $postLoja;

    if ($acao === 'gerar') {
        $stepAtual = 1;

        if ($idLoja <= 0) {
            $erro = 'Selecione uma loja.';
        } else {
            [$emp, $rev] = getLoja($idLoja, $lojas);

            if ($emp === null) {
                $erro = 'Loja não mapeada.';
            } else {
                $ora     = conectar_oracle($oracle_user, $oracle_password, $oracle_host, $oracle_port, $oracle_service);
                $condLoc = '';
                $locIni  = $locFim = null;

                if (!$postTodas) {
                    $iz = $_POST['ini_zona'] ?? '';
                    $ir = $_POST['ini_rua'] ?? '';
                    $ie = $_POST['ini_estante'] ?? '';
                    $ip = $_POST['ini_prateleira'] ?? '';
                    $in = $_POST['ini_numero'] ?? '';

                    $fz = $_POST['fim_zona'] ?? '';
                    $fr = $_POST['fim_rua'] ?? '';
                    $fe = $_POST['fim_estante'] ?? '';
                    $fp = $_POST['fim_prateleira'] ?? '';
                    $fn = $_POST['fim_numero'] ?? '';

                    if (!$iz || !$ir || !$ie || !$ip || !$in || !$fz || !$fr || !$fe || !$fp || !$fn) {
                        $erro = 'Preencha todos os campos de locação ou marque "Todas as locações".';
                    } else {
                        $locIni = $iz . '.' .
                                  str_pad($ir, 3, '0', STR_PAD_LEFT) . '.' .
                                  str_pad($ie, 3, '0', STR_PAD_LEFT) . '.' .
                                  $ip . '.' .
                                  str_pad($in, 3, '0', STR_PAD_LEFT);

                        $locFim = $fz . '.' .
                                  str_pad($fr, 3, '0', STR_PAD_LEFT) . '.' .
                                  str_pad($fe, 3, '0', STR_PAD_LEFT) . '.' .
                                  $fp . '.' .
                                  str_pad($fn, 3, '0', STR_PAD_LEFT);

                        $condLoc = "AND ((r.locacao_zona||'.'||LPAD(r.locacao_rua,3,'0')||'.'||LPAD(r.locacao_estante,3,'0')||'.'||r.locacao_prateleira||'.'||LPAD(r.locacao_numero,3,'0'))
                                     BETWEEN :loc_ini AND :loc_fim)";
                    }
                }

                if ($erro === '') {
                    $itensEstoque = buscarOracle(
                        $ora,
                        (int)$emp,
                        (int)$rev,
                        $postTerc ? 'terceiros' : 'proprio',
                        $condLoc,
                        $condLoc !== '' ? $locIni : null,
                        $condLoc !== '' ? $locFim : null
                    );
                    oci_close($ora);
                    $_SESSION['itensEstoque'] = $itensEstoque;
                    $sucesso = number_format(count($itensEstoque), 0, ',', '.') . ' itens carregados. Avance para cadastrar os coletores.';
                }
            }
        }
    } elseif ($acao === 'limpar') {
        unset($_SESSION['itensEstoque']);
        $itensEstoque = [];
        $stepAtual    = 1;
        $sucesso      = 'Lista limpa.';
    } elseif ($acao === 'salvar_coletores') {
        $stepAtual = 2;
        $tmp       = [];

        for ($i = 1; $i <= 8; $i++) {
            $n1 = trim($_POST["coletor{$i}_nome1"] ?? '');
            $n2 = trim($_POST["coletor{$i}_nome2"] ?? '');
            $ns = [];
            if ($n1 !== '') $ns[] = $n1;
            if ($n2 !== '') $ns[] = $n2;
            if (!empty($ns)) $tmp[$i] = $ns;
        }

        if (empty($tmp)) {
            $erro = 'Informe ao menos um nome de coletor.';
        } else {
            $coletoresGrupos              = $tmp;
            $_SESSION['coletores_grupos'] = $coletoresGrupos;
            $stepAtual                    = 3;
            $sucesso                      = 'Coletores salvos. Revise e confirme.';
        }
    } elseif ($acao === 'criar_e_salvar') {
        $stepAtual = 3;
        $ipSess    = (array)($_SESSION['itensEstoque'] ?? []);

        if (empty($ipSess)) {
            $erro = 'Volte e carregue os itens.';
        } elseif (empty($coletoresGrupos)) {
            $erro = 'Volte e cadastre os coletores.';
        } else {
            [$empI, $revI] = getLoja($postLoja, $lojas);

            if ($empI === null) {
                $erro = 'Loja não mapeada.';
            } else {
                $pdo2 = conectar_mysql($mysql_host, $mysql_db, $mysql_user, $mysql_pass);
                $pdo2->beginTransaction();

                try {
                    $cj = json_encode($coletoresGrupos, JSON_UNESCAPED_UNICODE);
                    $st = $pdo2->prepare(
                        "INSERT INTO inv_inventarios
                         (empresa,revenda,descricao,data_inicio,data_fim,status,coletores_json)
                         VALUES (:e,:r,:d,NOW(),NULL,'aberto',:cj)"
                    );
                    $st->execute([
                        ':e'  => $empI,
                        ':r'  => $revI,
                        ':d'  => 'Inventário gerado via sistema',
                        ':cj' => $cj
                    ]);

                    $idInv = (int)$pdo2->lastInsertId();

                    salvarColetores($pdo2, (int)$empI, (int)$revI, $idInv, $coletoresGrupos);

                    $se = $pdo2->prepare(
                        "INSERT INTO estoque
                         (id_inventario,empresa,revenda,cod_item_estoque,item_estoque_pub,descricao_item_estoque,quantidade,
                          locacao_zona,locacao_rua,locacao_estante,locacao_prateleira,locacao_numero,locacao_geral,
                          class_abc,custo_entrada,preco_alternativo,cont_1,cont_2,situacao,coletor)
                         VALUES
                         (:id_inventario,:empresa,:revenda,:cod,:pub,:des,:qtd,:zona,:rua,:est,:prat,:num,:geral,:abc,:custo,:preco,0,0,'A',0)"
                    );

                    $qt = 0;
                    foreach ($ipSess as $it) {
                        $se->execute([
                            ':id_inventario' => $idInv,
                            ':empresa'       => $empI,
                            ':revenda'       => $revI,
                            ':cod'           => $it['ITEM_ESTOQUE'] ?? null,
                            ':pub'           => $it['ITEM_ESTOQUE_PUB'] ?? null,
                            ':des'           => $it['DES_ITEM_ESTOQUE'] ?? null,
                            ':qtd'           => $it['QUANTIDADE'] ?? 0,
                            ':zona'          => $it['LOCACAO_ZONA'] ?? null,
                            ':rua'           => $it['LOCACAO_RUA'] ?? null,
                            ':est'           => $it['LOCACAO_ESTANTE'] ?? null,
                            ':prat'          => $it['LOCACAO_PRATELEIRA'] ?? null,
                            ':num'           => $it['LOCACAO_NUMERO'] ?? null,
                            ':geral'         => $it['LOCACAO_GERAL'] ?? null,
                            ':abc'           => $it['CLASS_ABC'] ?? null,
                            ':custo'         => $it['CUS_ULT_ENTRADA'] ?? 0,
                            ':preco'         => $it['PRECO_ALTERNATIVO'] ?? 0,
                        ]);
                        $qt++;
                    }

                    $pdo2->commit();
                    $sucesso = "Inventário <strong>#$idInv</strong> criado com <strong>" . number_format($qt, 0, ',', '.') . " itens</strong>.";
                    unset($_SESSION['itensEstoque'], $_SESSION['coletores_grupos'], $_SESSION['ultima_loja']);
                    $itensEstoque    = [];
                    $coletoresGrupos = [];
                    $stepAtual       = 4;
                } catch (Exception $e) {
                    $pdo2->rollBack();
                    $erro = 'Erro: ' . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
}

$qtItens    = count($itensEstoque);
$stepLabels = ['Loja & Itens', 'Coletores', 'Revisar', 'Concluído'];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?php
    $pageTitle = 'Novo Inventário';
    require __DIR__ . '/includes/head.php';
    ?>
    <style>
    /* Ajuste de layout para página sem sidebar */
    body .topbar {
        margin-left: 0 !important;
    }
    body .main-content {
        margin-left: 0 !important;
        margin-top: 0 !important;
        padding: var(--space-6) var(--space-5) !important;
        background: var(--color-bg);
        min-height: calc(100vh - var(--topbar-height));
    }
    .wiz-page {
        max-width: 960px;
        margin: 0 auto;
        padding: 0 0 80px;
    }
    .card.step-panel {
        padding: 0;
    }

    /* Topbar interna (mantém design system) */
    .topbar-inner {
        width: 100%;
        max-width: var(--content-max-width);
        margin: 0 auto;
        min-height: var(--topbar-height);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 0 24px;
    }
    .topbar__left {
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 2px;
    }
    .topbar__title {
        font-family: var(--font-bold);
        font-size: 20px;
        color: var(--text-primary);
    }
    .topbar__title span {
        color: var(--color-primary);
    }
    .topbar__breadcrumb {
        font-family: var(--font-regular);
        font-size: 13px;
        color: var(--text-muted);
    }
    .topbar__right {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-left: auto;
    }
    .topbar__user-name {
        display: inline-flex;
        align-items: center;
        height: 38px;
        padding: 0 12px;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #f9fafb;
        color: #374151;
        font-size: 14px;
        font-family: var(--font-bold);
        white-space: nowrap;
    }

    /* Wizard específico */

    .panel-head {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 18px 24px;
        border-bottom: 1px solid var(--color-gray-light);
        background: #FAFAFA;
    }
    .panel-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        flex-shrink: 0;
    }
    .panel-title {
        font-family: var(--font-bold);
        font-size: 16px;
        color: var(--text-primary);
    }
    .panel-sub {
        font-family: var(--font-regular);
        font-size: 13px;
        color: var(--text-muted);
        margin-top: 2px;
    }
    .panel-body {
        padding: var(--space-5);
    }
    .panel-foot {
        padding: 14px 24px;
        border-top: 1px solid var(--color-gray-light);
        background: #FAFAFA;
        display: flex;
        align-items: center;
        gap: var(--space-3);
        flex-wrap: wrap;
    }

    .loc-wrap {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: var(--space-4);
    }
    .loc-box {
        background: var(--color-bg);
        border: 1px solid var(--color-gray-light);
        border-radius: 8px;
        padding: 14px;
    }
    .loc-box-lbl {
        font-family: var(--font-bold);
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: var(--text-muted);
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .loc-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .loc-fields {
        display: grid;
        grid-template-columns: repeat(5,1fr);
        gap: 6px;
    }
    .loc-col {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }
    .loc-col-lbl {
        font-family: var(--font-bold);
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: .1em;
        color: var(--text-muted);
        text-align: center;
    }
    .loc-input {
        height: 38px;
        width: 100%;
        border: 1px solid #DFDFDF;
        border-radius: var(--radius-input);
        padding: 0 6px;
        text-align: center;
        font-family: 'Courier New', monospace;
        font-size: 14px;
        font-weight: 700;
        background: var(--color-surface);
        color: var(--text-primary);
        transition: border-color .15s;
    }
    .loc-input::placeholder {
        font-family: var(--font-regular);
        font-size: 10px;
        font-weight: 400;
        color: var(--text-muted);
    }
    .loc-input:focus {
        border-color: var(--color-primary);
        outline: none;
    }
    .loc-input:disabled {
        background: var(--color-bg);
        color: var(--text-muted);
        cursor: not-allowed;
    }

    .toggle-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-top: var(--space-4);
    }
    .toggle-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        border: 1px solid var(--color-gray-light);
        border-radius: var(--radius-input);
        background: var(--color-surface);
        cursor: pointer;
        transition: border-color .15s,background .15s;
        user-select: none;
    }
    .toggle-item:hover {
        border-color: var(--color-primary);
        background: rgba(35,71,200,.03);
    }
    .toggle-item input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: var(--color-primary);
        flex-shrink: 0;
    }
    .toggle-item span {
        font-family: var(--font-regular);
        font-size: 14px;
    }

    .coletores-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill,minmax(260px,1fr));
        gap: var(--space-3);
    }
    .coletor-card {
        border: 1px solid var(--color-gray-light);
        border-radius: 8px;
        overflow: hidden;
        transition: border-color .15s;
    }
    .coletor-card:focus-within {
        border-color: var(--color-primary);
    }
    .coletor-head {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        background: #F8F8F8;
        border-bottom: 1px solid var(--color-gray-light);
    }
    .coletor-num {
        width: 26px;
        height: 26px;
        border-radius: 6px;
        background: var(--color-primary);
        color: #fff;
        font-family: var(--font-bold);
        font-size: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .coletor-lbl {
        font-family: var(--font-bold);
        font-size: 13px;
        color: var(--text-secondary);
    }
    .coletor-prev {
        margin-left: auto;
        font-family: var(--font-regular);
        font-size: 11px;
        color: var(--text-muted);
        font-style: italic;
        max-width: 110px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .coletor-inputs {
        padding: 12px 14px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .coletor-input {
        height: 38px;
        width: 100%;
        border: 1px solid #DFDFDF;
        border-radius: var(--radius-input);
        padding: 0 12px;
        font-family: var(--font-regular);
        font-size: 13px;
        background: var(--color-surface);
        color: var(--text-primary);
        transition: border-color .15s;
    }
    .coletor-input::placeholder {
        color: var(--text-muted);
    }
    .coletor-input:focus {
        border-color: var(--color-primary);
        outline: none;
    }
    .coletor-input.filled {
        border-color: #A3E4C4;
        background: #F0FDF7;
    }

    .review-wrap {
        max-height: 360px;
        overflow-y: auto;
        border: 1px solid var(--color-gray-light);
        border-radius: 8px;
    }

    .success-screen {
        text-align: center;
        padding: 48px 20px;
    }
    .success-ico {
        font-size: 72px;
        margin-bottom: 16px;
    }
    .success-title {
        font-family: var(--font-bold);
        font-size: 28px;
        color: var(--text-primary);
        margin-bottom: 8px;
    }
    .success-title span {
        color: var(--color-primary);
    }
    .success-msg {
        font-family: var(--font-regular);
        font-size: 16px;
        color: var(--text-secondary);
        margin-bottom: 32px;
    }

    @media(max-width:700px){
      .loc-wrap { grid-template-columns:1fr; }
      .loc-fields { grid-template-columns:repeat(3,1fr); }
    }
    @media (max-width: 768px) {
        .topbar-inner {
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            padding: 12px 16px;
        }
        .topbar__right {
            width: 100%;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }
        body .main-content {
            padding: var(--space-4) !important;
        }
    }
    @media (max-width: 480px) {
        .topbar__user-name { display: none; }
    }
    </style>
</head>
<body>

<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="topbar__left">
      <div class="topbar__title">Novo <span>Inventário</span></div>
      <div class="topbar__breadcrumb">Gerador</div>
    </div>
    <div class="topbar__right">
      <span class="topbar__user-name">👤 <?php echo htmlspecialchars($usuarioLogado); ?></span>
      <a href="dashboard_inventario.php" class="btn btn-tertiary btn-sm">← Voltar</a>
    </div>
  </div>
</header>

<main class="main-content">
  <div class="wiz-page">

  <!-- STEPPER -->
  <div class="stepper mb-4" id="stepper-bar">
    <?php for ($s = 1; $s <= 4; $s++):
      $cls = $s < $stepAtual ? 'done' : ($s === $stepAtual ? 'active' : '');
    ?>
      <div class="step <?php echo $cls; ?>" data-step="<?php echo $s; ?>">
        <div class="step-circle"><?php echo $s < $stepAtual ? '✓' : $s; ?></div>
        <div class="step-label"><?php echo $stepLabels[$s-1]; ?></div>
      </div>
    <?php endfor; ?>
  </div>

    <!-- ALERTAS -->
    <?php if (!empty($erro)): ?>
      <div class="alert alert--danger mb-4">
        <span class="alert-icon">⚠️</span>
        <span><?php echo htmlspecialchars($erro); ?></span>
      </div>
    <?php elseif (!empty($sucesso) && $stepAtual < 4): ?>
      <div class="alert alert--success mb-4">
        <span class="alert-icon">✅</span>
        <span><?php echo $sucesso; ?></span>
      </div>
    <?php endif; ?>

    <form method="post" id="wiz-form">
      <input type="hidden" name="step_atual" id="step_atual" value="<?php echo $stepAtual; ?>">
      <input type="hidden" name="loja" id="loja-hidden" value="<?php echo $postLoja; ?>">

      <!-- ETAPA 1 -->
      <div class="card step-panel <?php echo $stepAtual === 1 ? 'visible' : 'hidden'; ?>" id="panel-1">
        <div class="panel-head">
          <div class="panel-icon icon-red">🏪</div>
          <div>
            <div class="panel-title">Etapa 1 — Loja e Locações</div>
            <div class="panel-sub">Selecione a loja e defina o intervalo de locações</div>
          </div>
        </div>
        <div class="panel-body">

          <div class="field mb-4" style="margin-bottom:20px;">
            <label class="form-label">Loja</label>
            <select name="loja" class="form-select form-select-lg" id="loja-select" required>
              <option value="">Selecione a loja...</option>
              <?php foreach ($lojas as $l): ?>
              <option value="<?php echo $l['id']; ?>" <?php echo $postLoja === $l['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($l['label']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div id="loc-block">
            <div class="loc-wrap">
              <div class="loc-box">
                <div class="loc-box-lbl">
                  <div class="loc-dot" style="background:#2563EB;"></div> Locação Inicial
                </div>
                <div class="loc-fields">
                  <?php foreach ([['ini_zona','Zona'],['ini_rua','Rua'],['ini_estante','Est.'],['ini_prateleira','Prat.'],['ini_numero','Nº']] as [$n,$l]): ?>
                  <div class="loc-col">
                    <div class="loc-col-lbl"><?php echo $l; ?></div>
                    <input type="text" name="<?php echo $n; ?>" class="loc-input" placeholder="—"
                           value="<?php echo htmlspecialchars($_POST[$n] ?? ''); ?>">
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="loc-box">
                <div class="loc-box-lbl">
                  <div class="loc-dot" style="background:var(--color-primary);"></div> Locação Final
                </div>
                <div class="loc-fields">
                  <?php foreach ([['fim_zona','Zona'],['fim_rua','Rua'],['fim_estante','Est.'],['fim_prateleira','Prat.'],['fim_numero','Nº']] as [$n,$l]): ?>
                  <div class="loc-col">
                    <div class="loc-col-lbl"><?php echo $l; ?></div>
                    <input type="text" name="<?php echo $n; ?>" class="loc-input" placeholder="—"
                           value="<?php echo htmlspecialchars($_POST[$n] ?? ''); ?>">
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <div class="toggle-group">
              <label class="toggle-item">
                <input type="checkbox" id="cb-todas" name="todas_locacoes" value="1" <?php echo $postTodas ? 'checked' : ''; ?>>
                <span>Todas as locações — ignora o intervalo acima</span>
                <span class="badge badge--warning">Atenção</span>
              </label>
              <label class="toggle-item">
                <input type="checkbox" id="cb-terc" name="pecas_terceiros" value="1" <?php echo $postTerc ? 'checked' : ''; ?>>
                <span>Incluir peças pertencentes a terceiros</span>
              </label>
            </div>
          </div>

          <?php if ($qtItens > 0): ?>
          <div class="alert alert--success" style="margin-top:16px;">
            <span class="alert-icon">📦</span>
            <span>
              <strong><?php echo number_format($qtItens,0,',','.'); ?> itens</strong> carregados e prontos para o inventário.
            </span>
          </div>
          <?php endif; ?>

        </div>
        <div class="panel-foot">
          <button type="submit" name="acao" value="gerar" class="btn btn-secondary">🔍 Buscar Itens</button>
          <?php if ($qtItens > 0): ?>
          <button type="submit" name="acao" value="limpar" class="btn btn-danger-outline">🗑 Limpar</button>
          <?php endif; ?>
          <div class="spacer"></div>
          <button type="button" class="btn btn-primary"
                  onclick="goStep(2)"
                  <?php echo $qtItens === 0 ? 'disabled title="Carregue os itens primeiro"' : ''; ?>>
            Próximo: Coletores →
          </button>
        </div>
      </div>

      <!-- ETAPA 2 -->
      <div class="card step-panel <?php echo $stepAtual === 2 ? 'visible' : 'hidden'; ?>" id="panel-2">
        <div class="panel-head">
          <div class="panel-icon icon-blue">👥</div>
          <div>
            <div class="panel-title">Etapa 2 — Coletores</div>
            <div class="panel-sub">Cadastre os nomes. Duplas: preencha os dois campos.</div>
          </div>
        </div>
        <div class="panel-body">
          <div class="coletores-grid">
            <?php for ($i = 1; $i <= 8; $i++):
              $n1 = $coletoresGrupos[$i][0] ?? '';
              $n2 = $coletoresGrupos[$i][1] ?? '';
            ?>
            <div class="coletor-card">
              <div class="coletor-head">
                <div class="coletor-num"><?php echo $i; ?></div>
                <div class="coletor-lbl">Coletor <?php echo $i; ?></div>
                <div class="coletor-prev" id="prev-<?php echo $i; ?>">
                  <?php echo $n1 ? htmlspecialchars($n1 . ($n2 ? ' & ' . $n2 : '')) : 'Não configurado'; ?>
                </div>
              </div>
              <div class="coletor-inputs">
                <input type="text" name="coletor<?php echo $i; ?>_nome1"
                       class="coletor-input <?php echo $n1 ? 'filled' : ''; ?>"
                       id="c<?php echo $i; ?>n1" placeholder="Nome do coletor"
                       value="<?php echo htmlspecialchars($n1); ?>"
                       oninput="prevCol(<?php echo $i; ?>)">
                <input type="text" name="coletor<?php echo $i; ?>_nome2"
                       class="coletor-input <?php echo $n2 ? 'filled' : ''; ?>"
                       id="c<?php echo $i; ?>n2" placeholder="Parceiro (dupla — opcional)"
                       value="<?php echo htmlspecialchars($n2); ?>"
                       oninput="prevCol(<?php echo $i; ?>)">
              </div>
            </div>
            <?php endfor; ?>
          </div>
        </div>
        <div class="panel-foot">
          <button type="button" class="btn btn-tertiary" onclick="goStep(1,true)">← Voltar</button>
          <div class="spacer"></div>
          <button type="submit" name="acao" value="salvar_coletores" class="btn btn-primary">Salvar e Revisar →</button>
        </div>
      </div>

      <!-- ETAPA 3 -->
      <div class="card step-panel <?php echo $stepAtual === 3 ? 'visible' : 'hidden'; ?>" id="panel-3">
        <div class="panel-head">
          <div class="panel-icon icon-green">📋</div>
          <div>
            <div class="panel-title">Etapa 3 — Revisar e Confirmar</div>
            <div class="panel-sub">Confira o resumo antes de criar o inventário</div>
          </div>
        </div>
        <div class="panel-body">

          <div class="status-bar" style="margin-bottom:20px;">
            <div class="status-chip">
              <div class="status-dot <?php echo $qtItens>0?'dot-success':'dot-idle'; ?>"></div>
              <div>
                <div class="status-lbl">Itens</div>
                <div class="status-val"><?php echo number_format($qtItens,0,',','.'); ?></div>
              </div>
            </div>
            <div class="status-chip">
              <div class="status-dot <?php echo !empty($coletoresGrupos)?'dot-success':'dot-idle'; ?>"></div>
              <div>
                <div class="status-lbl">Coletores</div>
                <div class="status-val"><?php echo count($coletoresGrupos); ?></div>
              </div>
            </div>
            <div class="status-chip">
              <div class="status-dot dot-idle"></div>
              <div>
                <div class="status-lbl">Loja selecionada</div>
                <div class="status-val" style="font-size:12px;">
                  <?php
                    $lojaLabel = '—';
                    foreach($lojas as $l) {
                      if($l['id'] === $postLoja) { $lojaLabel = $l['label']; break; }
                    }
                    echo htmlspecialchars($lojaLabel);
                  ?>
                </div>
              </div>
            </div>
          </div>

          <?php if (!empty($coletoresGrupos)): ?>
          <div style="margin-bottom:16px;">
            <div class="form-label" style="margin-bottom:8px;">Coletores configurados</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
              <?php foreach($coletoresGrupos as $num=>$nomes): ?>
              <span class="badge badge--success">
                <?php echo htmlspecialchars(is_array($nomes)?implode(' & ',$nomes):$nomes); ?>
              </span>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($qtItens > 0): ?>
          <div class="form-label" style="margin-bottom:8px;">
            Prévia dos itens <span class="badge badge--primary"><?php echo number_format($qtItens,0,',','.'); ?></span>
          </div>
          <div class="review-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Referência</th>
                  <th>Descrição</th>
                  <th>Qtd</th>
                  <th>Locação</th>
                  <th>ABC</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach(array_slice($itensEstoque,0,50) as $it):
                  $abc = strtoupper($it['CLASS_ABC'] ?? '');
                  $cls = ['A'=>'abc-a','B'=>'abc-b','C'=>'abc-c'][$abc] ?? 'abc-x';
                ?>
                <tr>
                  <td class="text-mono"><?php echo htmlspecialchars($it['ITEM_ESTOQUE_PUB'] ?? ''); ?></td>
                  <td><?php echo htmlspecialchars($it['DES_ITEM_ESTOQUE'] ?? ''); ?></td>
                  <td class="text-mono fw-bold"><?php echo $it['QUANTIDADE'] ?? ''; ?></td>
                  <td class="text-mono"><?php echo htmlspecialchars($it['LOCACAO_GERAL'] ?? ''); ?></td>
                  <td><span class="<?php echo $cls; ?>"><?php echo $abc ?: '—'; ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if($qtItens > 50): ?>
                <tr>
                  <td colspan="5" style="text-align:center;color:var(--text-muted);font-size:12px;padding:12px;">
                    + <?php echo number_format($qtItens-50,0,',','.'); ?> itens não exibidos
                  </td>
                </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>

        </div>
        <div class="panel-foot">
          <button type="button" class="btn btn-tertiary" onclick="goStep(2,true)">← Voltar</button>
          <div class="spacer"></div>
          <button type="submit" name="acao" value="criar_e_salvar" class="btn btn-success"
                  <?php echo ($qtItens===0 || empty($coletoresGrupos)) ? 'disabled' : ''; ?>>
            💾 Criar Inventário com <?php echo number_format($qtItens,0,',','.'); ?> itens
          </button>
        </div>
      </div>

      <!-- ETAPA 4 -->
      <div class="card step-panel <?php echo $stepAtual === 4 ? 'visible' : 'hidden'; ?>" id="panel-4">
        <div class="panel-body">
          <div class="success-screen">
            <div class="success-ico">🎉</div>
            <div class="success-title">Inventário <span>criado!</span></div>
            <div class="success-msg"><?php echo $sucesso ?? 'Tudo certo!'; ?></div>
            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
              <a href="dashboard_inventario.php" class="btn btn-primary">Ir para o Dashboard →</a>
              <a href="gerador_inventario.php" class="btn btn-secondary">+ Criar outro</a>
            </div>
          </div>
        </div>
      </div>

    </form>
  </div>
</main>

<script>
let current = <?php echo $stepAtual; ?>;

function goStep(target, back) {
  back = back || false;
  const from = document.getElementById('panel-' + current);
  const to   = document.getElementById('panel-' + target);
  if (!to) return;
  if (from) from.classList.add('hidden');
  to.classList.remove('hidden');
  current = target;
  document.getElementById('step_atual').value = target;
  const sel = document.getElementById('loja-select');
  if (sel) document.getElementById('loja-hidden').value = sel.value;
  syncStepper(target);
  window.scrollTo({top:0, behavior:'smooth'});
}

function syncStepper(active) {
  document.querySelectorAll('.step').forEach(el => {
    const s = +el.dataset.step;
    el.classList.remove('done','active');
    if (s < active) el.classList.add('done');
    else if (s === active) el.classList.add('active');
    el.querySelector('.step-circle').textContent = s < active ? '✓' : s;
  });
}

const cbTodas = document.getElementById('cb-todas');
function syncLoc() {
  document.querySelectorAll('.loc-input').forEach(i => {
    i.disabled = cbTodas && cbTodas.checked;
    i.style.opacity = cbTodas && cbTodas.checked ? '.4' : '1';
  });
}
if (cbTodas) {
  cbTodas.addEventListener('change', syncLoc);
  syncLoc();
}

document.querySelectorAll('.toggle-item').forEach(r => {
  r.addEventListener('click', e => {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SPAN') return;
    const cb = r.querySelector('input[type="checkbox"]');
    if (cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change')); }
  });
});

function prevCol(n) {
  const n1 = document.getElementById('c'+n+'n1'),
        n2 = document.getElementById('c'+n+'n2'),
        p  = document.getElementById('prev-'+n);
  if (!n1 || !p) return;
  const v1 = n1.value.trim(),
        v2 = n2 ? n2.value.trim() : '';
  p.textContent = v1 ? (v2 ? v1 + ' & ' + v2 : v1) : 'Não configurado';
  n1.classList.toggle('filled', v1 !== '');
  if (n2) n2.classList.toggle('filled', v2 !== '');
}

document.getElementById('wiz-form').addEventListener('submit', e => {
  const btn = e.submitter;
  if(!btn) return;
  btn.style.opacity = '.6';
  btn.style.pointerEvents = 'none';
  setTimeout(() => {
    btn.style.opacity = '';
    btn.style.pointerEvents = '';
  }, 5000);
});
</script>
</body>
</html>