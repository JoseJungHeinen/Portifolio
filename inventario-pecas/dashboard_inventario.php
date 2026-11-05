<?php
declare(strict_types=1);

session_start();
require 'conexao_inventario.php';
require 'recontagem_lib.php';
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (empty($_SESSION['inventario_login'])) {
    header('Location: index.php');
    exit;
}

$usuarioLogado   = $_SESSION['inventario_login'];
$coletoresGrupos = [];

// helpers
function getIntParam(string $key): ?int {
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }
    $val = preg_replace('/\D/', '', $_GET[$key]);
    return $val === '' ? null : (int)$val;
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

// label para usar em "Contagens por coletor"
function labelColetor(int $num, array $grupos): string {
    if (!empty($grupos[$num])) {
        return implode(' e ', $grupos[$num]);
    }
    return 'Coletor ' . $num;
}

/* ------------------------------
 * AJAX: salvar nomes dos coletores
 * ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_salvar_coletores'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $ajaxEmp = (int)($_POST['empresa'] ?? 0);
    $ajaxRev = (int)($_POST['revenda'] ?? 0);
    $ajaxInv = (int)($_POST['inv']     ?? 0);

    if ($ajaxEmp <= 0 || $ajaxRev <= 0 || $ajaxInv <= 0) {
        echo json_encode(['ok' => false, 'mensagem' => 'Dados inválidos.']);
        exit;
    }

    try {
        $stmtDel = $pdo->prepare("
            DELETE FROM inv_coletores
             WHERE empresa = :e AND revenda = :r AND id_inventario = :inv
        ");
        $stmtDel->execute([':e' => $ajaxEmp, ':r' => $ajaxRev, ':inv' => $ajaxInv]);

        $stmtIns = $pdo->prepare("
            INSERT INTO inv_coletores (empresa, revenda, id_inventario, coletor_num, nome)
            VALUES (:e, :r, :inv, :num, :nome)
        ");

        $grupos = [];

        for ($i = 1; $i <= 8; $i++) {
            $nome = trim($_POST['coletor_' . $i] ?? '');
            if ($nome !== '') {
                $stmtIns->execute([
                    ':e'    => $ajaxEmp,
                    ':r'    => $ajaxRev,
                    ':inv'  => $ajaxInv,
                    ':num'  => $i,
                    ':nome' => $nome,
                ]);
                $grupos[$i] = array_values(array_filter(array_map('trim', explode(' e ', $nome)), 'strlen'));
            }
        }

        // As duplas podem mudar no começo de cada contagem. O login e a divisão
        // de locações da 2ª leem o coletores_json, não a inv_coletores — sem
        // isto o nome novo aparecia só aqui no dashboard.
        if ($grupos !== []) {
            $pdo->prepare("
                UPDATE inv_inventarios SET coletores_json = :cj
                 WHERE id = :inv AND empresa = :e AND revenda = :r
            ")->execute([
                ':cj'  => json_encode($grupos, JSON_UNESCAPED_UNICODE),
                ':inv' => $ajaxInv,
                ':e'   => $ajaxEmp,
                ':r'   => $ajaxRev,
            ]);
        }

        echo json_encode(['ok' => true, 'mensagem' => 'Nomes salvos!']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => 'Erro: ' . $e->getMessage()]);
    }
    exit;
}

/* ------------------------------
 * FILTRO POR CÓDIGO DA LOJA + INVENTÁRIO
 * ------------------------------ */

// códigos de loja que já possuem inventário, com nomes
$sqlCodigos = "
    SELECT DISTINCT
        l.codigo,
        l.empresa AS nome_empresa,
        l.revenda AS nome_revenda
    FROM inv_inventarios i
    INNER JOIN inv_lojas l
      ON l.ativo = 1
     AND CAST(SUBSTRING_INDEX(l.codigo, '.', 1) AS UNSIGNED) = i.empresa
     AND CAST(SUBSTRING_INDEX(l.codigo, '.', -1) AS UNSIGNED) = i.revenda
    ORDER BY l.codigo
";
$codigosLoja = fetchAllPrepared($pdo, $sqlCodigos);

$codSel = $_GET['codigo'] ?? '';

$empresaSel = null;
$revendaSel = null;

if ($codSel !== '' && strpos($codSel, '.') !== false) {
    [$emp, $rev] = array_map('intval', explode('.', $codSel));
    $empresaSel  = $emp;
    $revendaSel  = $rev;
}

$invSel = isset($_GET['inv']) && $_GET['inv'] !== '' ? (int)$_GET['inv'] : null;

// inventários da loja selecionada
$inventarios = [];
if ($empresaSel !== null && $revendaSel !== null) {
    $sqlInv = "
        SELECT id, data_inicio
        FROM inv_inventarios
        WHERE empresa = :empresa
          AND revenda = :revenda
        ORDER BY data_inicio DESC
    ";
    $inventarios = fetchAllPrepared($pdo, $sqlInv, [
        ':empresa' => $empresaSel,
        ':revenda' => $revendaSel,
    ]);
}

// status do inventário selecionado
$statusInventario  = null;
$dataFimInventario = null;

if ($empresaSel !== null && $revendaSel !== null && $invSel !== null) {
    $sqlInvSel = "
        SELECT status, data_fim
          FROM inv_inventarios
         WHERE empresa = :empresa
           AND revenda = :revenda
           AND id      = :id
         LIMIT 1
    ";
    $rowInvSel = fetchOnePrepared($pdo, $sqlInvSel, [
        ':empresa' => $empresaSel,
        ':revenda' => $revendaSel,
        ':id'      => $invSel,
    ]);

    if ($rowInvSel) {
        $statusInventario  = $rowInvSel['status'];
        $dataFimInventario = $rowInvSel['data_fim'];
    }
}

/* ------------------------------
 * FILTROS DE LOCAÇÃO
 * ------------------------------ */

$zi = getIntParam('zi');
$ri = getIntParam('ri');
$ei = getIntParam('ei');
$pi = getIntParam('pi');
$ni = getIntParam('ni');

$zf = getIntParam('zf');
$rf = getIntParam('rf');
$ef = getIntParam('ef');
$pf = getIntParam('pf');
$nf = getIntParam('nf');

$locIni = '';
$locFim = '';

if ($zi !== null) {
    $locIni =
        str_pad((string)$zi, 2, '0', STR_PAD_LEFT) . '.' .
        str_pad($ri !== null ? (string)$ri : '0',   3, '0', STR_PAD_LEFT) . '.' .
        str_pad($ei !== null ? (string)$ei : '0',   3, '0', STR_PAD_LEFT) . '.' .
        str_pad($pi !== null ? (string)$pi : '0',   2, '0', STR_PAD_LEFT) . '.' .
        str_pad($ni !== null ? (string)$ni : '0',   3, '0', STR_PAD_LEFT);
}
if ($zf !== null) {
    $locFim =
        str_pad((string)$zf, 2, '0', STR_PAD_LEFT) . '.' .
        str_pad($rf !== null ? (string)$rf : '999', 3, '0', STR_PAD_LEFT) . '.' .
        str_pad($ef !== null ? (string)$ef : '999', 3, '0', STR_PAD_LEFT) . '.' .
        str_pad($pf !== null ? (string)$pf : '99',  2, '0', STR_PAD_LEFT) . '.' .
        str_pad($nf !== null ? (string)$nf : '999', 3, '0', STR_PAD_LEFT);
}

$condLoc   = '';
$paramsLoc = [];
if ($locIni !== '' && $locFim !== '') {
    $condLoc               = " AND locacao_geral BETWEEN :loc_ini AND :loc_fim ";
    $paramsLoc[':loc_ini'] = $locIni;
    $paramsLoc[':loc_fim'] = $locFim;
}

// valores para manter nos inputs hidden do relatório
$ziVal = $_GET['zi'] ?? '';
$riVal = $_GET['ri'] ?? '';
$eiVal = $_GET['ei'] ?? '';
$piVal = $_GET['pi'] ?? '';
$niVal = $_GET['ni'] ?? '';

$zfVal = $_GET['zf'] ?? '';
$rfVal = $_GET['rf'] ?? '';
$efVal = $_GET['ef'] ?? '';
$pfVal = $_GET['pf'] ?? '';
$nfVal = $_GET['nf'] ?? '';

/* ------------------------------
 * INICIALIZA DASHBOARD
 * ------------------------------ */

$itensFalta = $itensSobra = [];
$entradaTotalQtd = $entradaTotalValor = 0;
$pecasFaltaQtd = $pecasFaltaValor = 0;
$pecasSobraQtd = $pecasSobraValor = 0;
$pecasContadasQtd = $valorContadoTotal = 0;
$percentualConcluido = 0.0;
$contagensColetor = [];
$busca = trim($_GET['busca'] ?? '');
$resultBusca = [];

$recResumo    = ['nao_contado' => 0, 'contando' => 0, 'aguardando' => 0, 'bateu' => 0, 'decisao' => 0];
$recConclusao = recontagem_conclusao($recResumo);

// carrega dados somente se tiver loja (empresa/revenda) e inventário selecionados
if ($empresaSel !== null && $revendaSel !== null && $invSel !== null) {

    // estoque guarda empresa + revenda (INT) + id_inventario
    $condInv = " empresa = :empresa AND revenda = :revenda AND id_inventario = :inv ";
    $paramsBase = [
        ':empresa' => $empresaSel,
        ':revenda' => $revendaSel,
        ':inv'     => $invSel,
    ];

    // Quantidade que vale: a última contagem feita (recontagem inclusa), ou a
    // decisão da Tomada de Decisão. Ver recontagem_sql_contagem_final().
    $cf = recontagem_sql_contagem_final(recontagem_tem_decisoes($pdo));

    // 3) Totais gerais (entrada)
    $sqlEntrada = "
        SELECT
            COALESCE(SUM(quantidade), 0)                 AS total_qtd,
            COALESCE(SUM(quantidade * custo_entrada), 0) AS total_valor
        FROM estoque
        WHERE $condInv
          $condLoc
    ";
    $rowEntrada         = fetchOnePrepared($pdo, $sqlEntrada, array_merge($paramsBase, $paramsLoc));
    $entradaTotalQtd    = (int)$rowEntrada['total_qtd'];
    $entradaTotalValor  = (float)$rowEntrada['total_valor'];

    // 7) Valor total contado
    $sqlValorContado = "
        SELECT COALESCE(SUM(COALESCE({$cf},0) * custo_entrada), 0) AS valor_contado
        FROM estoque
        WHERE $condInv
          $condLoc
    ";
    $rowValorContado   = fetchOnePrepared($pdo, $sqlValorContado, array_merge($paramsBase, $paramsLoc));
    $valorContadoTotal = (float)$rowValorContado['valor_contado'];

    // Percentual concluído por VALOR (agora depois do passo 7)
    if ($entradaTotalValor > 0) {
        // Uma casa decimal: por valor, 1% inteiro são centenas de itens, e o
        // número parado dava a impressão de que o dashboard não atualizava.
        $percentualConcluido = min(100.0, round(($valorContadoTotal / $entradaTotalValor) * 100, 1));
    } else {
        $percentualConcluido = 0.0;
    }

    // Percentual ao vivo: a tela pergunta de tempos em tempos, com os mesmos
    // filtros da URL, e sai aqui — antes das listas de falta/sobra, que são
    // o que pesa.
    if (isset($_GET['ajax_percentual'])) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'ok'         => true,
            'percentual' => $percentualConcluido,
            'texto'      => number_format($percentualConcluido, 1, ',', '.') . '%',
        ]);
        exit;
    }

    // 1) Itens com quantidade a menor
    $sqlFalta = "
        SELECT
            item_estoque_pub       AS ref,
            descricao_item_estoque AS descricao,
            locacao_geral          AS locacao,
            quantidade             AS qtd_estoque,
            {$cf}                 AS qtd_inv,
            ({$cf} - quantidade)  AS dif,
            custo_entrada          AS valor_uni
        FROM estoque
        WHERE $condInv
          AND {$cf} IS NOT NULL
          AND {$cf} < quantidade
          $condLoc
        ORDER BY ABS({$cf} - quantidade) * custo_entrada DESC
    ";
    $itensFalta = fetchAllPrepared(
        $pdo,
        $sqlFalta,
        array_merge($paramsBase, $paramsLoc)
    );

    // 2) Itens em sobra
    $sqlSobra = "
        SELECT
            item_estoque_pub       AS ref,
            descricao_item_estoque AS descricao,
            locacao_geral          AS locacao,
            quantidade             AS qtd_estoque,
            {$cf}                 AS qtd_inv,
            ({$cf} - quantidade)  AS dif,
            custo_entrada          AS valor_uni
        FROM estoque
        WHERE $condInv
          AND {$cf} IS NOT NULL
          AND {$cf} > quantidade
          $condLoc
        ORDER BY ({$cf} - quantidade) * custo_entrada DESC
    ";
    $itensSobra = fetchAllPrepared(
        $pdo,
        $sqlSobra,
        array_merge($paramsBase, $paramsLoc)
    );

    // Recontagem por item: quantos itens em cada situação.
    $recResumo    = recontagem_resumo($pdo, $invSel, $empresaSel, $revendaSel);
    $recConclusao = recontagem_conclusao($recResumo);

    // 4) Totais de peças faltantes
    $sqlTotFalta = "
        SELECT
            COALESCE(SUM(ABS({$cf} - quantidade)), 0)                 AS qtd,
            COALESCE(SUM(ABS({$cf} - quantidade) * custo_entrada), 0) AS valor
        FROM estoque
        WHERE $condInv
          AND {$cf} IS NOT NULL
          AND {$cf} < quantidade
          $condLoc
    ";
    $rowFalta        = fetchOnePrepared($pdo, $sqlTotFalta, array_merge($paramsBase, $paramsLoc));
    $pecasFaltaQtd   = (int)$rowFalta['qtd'];
    $pecasFaltaValor = (float)$rowFalta['valor'];

    // 5) Totais de peças em sobra
    $sqlTotSobra = "
        SELECT
            COALESCE(SUM({$cf} - quantidade), 0)                       AS qtd,
            COALESCE(SUM(({$cf} - quantidade) * custo_entrada), 0)     AS valor
        FROM estoque
        WHERE $condInv
          AND {$cf} IS NOT NULL
          AND {$cf} > quantidade
          $condLoc
    ";
    $rowSobra        = fetchOnePrepared($pdo, $sqlTotSobra, array_merge($paramsBase, $paramsLoc));
    $pecasSobraQtd   = (int)$rowSobra['qtd'];
    $pecasSobraValor = (float)$rowSobra['valor'];

    // 6) Percentual concluído - REMOVER o cálculo daqui
    $sqlTotais = "
        SELECT
            COALESCE(SUM(COALESCE({$cf},0)), 0) AS qtd_contada
        FROM estoque
        WHERE $condInv
          $condLoc
    ";
    $rowTotais        = fetchOnePrepared($pdo, $sqlTotais, array_merge($paramsBase, $paramsLoc));
    $pecasContadasQtd = (int)$rowTotais['qtd_contada'];

    // 8) Contagens por coletor
    $sqlColetor = "
        SELECT coletor, COUNT(*) AS qtd
        FROM estoque
        WHERE $condInv
          $condLoc
        GROUP BY coletor
    ";
    foreach (fetchAllPrepared($pdo, $sqlColetor, array_merge($paramsBase, $paramsLoc)) as $r) {
        $contagensColetor[(int)$r['coletor']] = (int)$r['qtd'];
    }

    // Carrega nomes dos coletores salvos no banco para este inventário
    $sqlColetoresNomes = "
        SELECT coletor_num, nome
          FROM inv_coletores
         WHERE empresa = :empresa
           AND revenda = :revenda
           AND id_inventario = :inv
    ";
    foreach (fetchAllPrepared($pdo, $sqlColetoresNomes, $paramsBase) as $r) {
        $num = (int)$r['coletor_num'];
        if ($r['nome'] !== '') {
            $coletoresGrupos[$num] = explode(' e ', $r['nome']);
        }
    }

    // 9) Busca livre de itens (nome ou código)
    if ($busca !== '') {
        $sqlBusca = "
            SELECT
                item_estoque_pub       AS ref,
                descricao_item_estoque AS descricao,
                locacao_geral          AS locacao,
                quantidade             AS qtd_estoque,
                {$cf}                 AS qtd_inv,
                custo_entrada          AS valor_uni
            FROM estoque
            WHERE $condInv
              AND (item_estoque_pub LIKE :b OR descricao_item_estoque LIKE :b2)
              $condLoc
            ORDER BY locacao_geral
            LIMIT 50
        ";
        $resultBusca = fetchAllPrepared(
            $pdo,
            $sqlBusca,
            array_merge($paramsBase, $paramsLoc, [
                ':b'  => '%'.$busca.'%',
                ':b2' => '%'.$busca.'%',
            ])
        );
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Dashboard Inventário</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    :root {
      --red: #2347C8;
      --green: #07be01;
      --red-soft: #fbe9eb;

      --bg-body: #f3f4f6;
      --bg-header: #ffffff;
      --bg-card: #ffffff;

      --gray-25: #FCFCFD;
      --gray-50: #F9FAFB;
      --gray-100: #e5e7eb;
      --gray-200: #e5e7eb;
      --gray-300: #d1d5db;
      --gray-400: #9ca3af;
      --gray-600: #4b5563;
      --gray-800: #111827;
      --white: #ffffff;

      --radius-card: 8px;
      --shadow-soft: 0 2px 6px rgba(15, 23, 42, 0.06);
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: var(--bg-body);
      color: var(--gray-800);
    }

    .page {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    header {
      padding: 10px 20px;
      border-bottom: 1px solid var(--gray-100);
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: var(--bg-header);
      position: sticky;
      top: 0;
      z-index: 20;
      box-shadow: 0 1px 3px rgba(15,23,42,0.04);
    }

    .top-actions {
      display: flex;
      gap: 6px;
      align-items: center;
      flex-wrap: wrap;
    }

    .btn-header {
      height: 32px;
      padding: 0 14px;
      border-radius: 20px;
      font-size: 13px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.05s ease;
      font-weight: 600;
    }

    .btn-outline-red {
      border: 1px solid var(--red);
      background: var(--white);
      color: var(--red);
    }

    .btn-outline-red:hover {
      background: var(--red-soft);
      box-shadow: 0 0 0 1px rgba(35,71,200, 0.15);
    }

    .btn-red {
      border: none;
      background: linear-gradient(135deg, var(--red), #FF1744);
      color: var(--white);
      box-shadow: 0 6px 12px rgba(35,71,200, 0.4);
    }

    .btn-red:hover {
      box-shadow: 0 8px 16px rgba(35,71,200, 0.45);
      transform: translateY(-1px);
    }

    .btn-header:active {
      transform: translateY(0);
      box-shadow: none;
    }

    .logout-link {
      font-size: 13px;
      color: var(--gray-600);
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 10px;
      border-radius: 999px;
      background: transparent;
      transition: background 0.15s ease, color 0.15s ease;
    }

    .logout-link:hover {
      background: var(--gray-50);
      color: var(--gray-800);
    }

    main {
      flex: 1;
      padding: 16px 20px 24px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .card {
      background: var(--bg-card);
      border-radius: var(--radius-card);
      padding: 14px 16px;
      box-shadow: var(--shadow-soft);
      height: 100%;
      border: 1px solid rgba(15,23,42,0.03);
    }

    .card-title {
      font-size: 14px;
      font-weight: 600;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 6px;
      color: var(--gray-800);
    }

    .card-title::before {
      content: "";
      width: 3px;
      height: 14px;
      border-radius: 999px;
      background: var(--red);
    }

    .filtros-inventario {
      display: inline-flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
      justify-content: center;
      padding: 6px 10px;
      border-radius: 999px;
      background: var(--white);
      box-shadow: 0 2px 6px rgba(15,23,42,0.05);
      border: 1px solid var(--gray-100);
    }

    .filtros-inventario select,
    .filtros-inventario button {
      height: 30px;
      border-radius: 999px;
      border: 1px solid var(--gray-200);
      padding: 0 10px;
      font-size: 12px;
    }

    .filtros-inventario select {
      background: var(--gray-50);
      color: var(--gray-800);
    }

    .filtros-inventario button {
      border: none;
      background: linear-gradient(135deg, var(--red), #FF1744);
      color: #fff;
      font-weight: 500;
      cursor: pointer;
    }

    .lists-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      align-items: stretch;
    }

    .top-row {
      display: grid;
      grid-template-columns: 1.4fr 1fr;
      gap: 12px;
      align-items: stretch;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 12px;
    }

    thead tr { background-color: var(--gray-50); }
    th, td {
      border-bottom: 1px solid rgba(209,213,219,0.8);
      padding: 6px 4px;
      text-align: left;
    }

    th {
      font-weight: 600;
      color: #374151;
      position: sticky;
      top: 0;
      z-index: 5;
      background-color: var(--gray-50);
    }

    tbody tr:nth-child(odd) { background: #f9fafb; }
    tbody tr:hover { background: #FFF3F4; }

    .circle-wrapper {
      display: flex;
      align-items: center;
      justify-content: center;
      margin-top: 12px;
    }

    .circle {
      width: 140px;
      height: 140px;
      border-radius: 50%;
      background:
        radial-gradient(circle at 50% 50%, #ffffff 0, #ffffff 56%, transparent 57%),
        conic-gradient(#16a34a calc(var(--perc, 0) * 1%), #e5e7eb 0);
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 6px 14px rgba(15,23,42,0.12);
    }

    .circle-inner {
      width: 110px;
      height: 110px;
      border-radius: 50%;
      background: radial-gradient(circle at 30% 20%, #ffffff, #f3f4f6);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      gap: 4px;
      box-shadow: inset 0 0 0 1px rgba(148,163,184,0.25);
    }

    .circle-inner span {
      font-size: 20px;
      font-weight: 700;
      color: var(--gray-800);
    }

    .circle-inner small {
      font-size: 11px;
      color: var(--gray-600);
      letter-spacing: .08em;
      text-transform: uppercase;
    }

    .coletor-card-inner {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }

    .coletor-table th,
    .coletor-table td { padding: 4px 2px; }

    @media (max-width: 1024px) {
      main { padding: 12px; }
      .lists-row,
      .top-row,
      .coletor-card-inner { grid-template-columns: 1fr; }
      header { padding: 8px 12px; }
    }
  </style>
</head>
<body>
<div class="page">
  <header>
    <div class="top-actions">
      <button type="button"
              class="btn-header btn-outline-red"
              onclick="window.location.href='gerador_inventario.php';">
        Gerar inventário
      </button>

      <form method="get" action="relatorio_inventario.php" style="margin:0;">
        <input type="hidden" name="zi" value="<?php echo htmlspecialchars($ziVal); ?>">
        <input type="hidden" name="ri" value="<?php echo htmlspecialchars($riVal); ?>">
        <input type="hidden" name="ei" value="<?php echo htmlspecialchars($eiVal); ?>">
        <input type="hidden" name="pi" value="<?php echo htmlspecialchars($piVal); ?>">
        <input type="hidden" name="ni" value="<?php echo htmlspecialchars($niVal); ?>">

        <input type="hidden" name="zf" value="<?php echo htmlspecialchars($zfVal); ?>">
        <input type="hidden" name="rf" value="<?php echo htmlspecialchars($rfVal); ?>">
        <input type="hidden" name="ef" value="<?php echo htmlspecialchars($efVal); ?>">
        <input type="hidden" name="pf" value="<?php echo htmlspecialchars($pfVal); ?>">
        <input type="hidden" name="nf" value="<?php echo htmlspecialchars($nfVal); ?>">

        <input type="hidden" name="id_inventario" value="<?php echo (int)($invSel ?? 0); ?>">

        <button type="submit" class="btn-header btn-red">
            Relatório
        </button>
      </form>

      <!-- Botão Ajuste de Quantidades -->
      <form method="get" action="ajuste_quantidades.php" style="margin:0;">
        <input type="hidden" name="codigo" value="<?php echo htmlspecialchars($codSel); ?>">
        <input type="hidden" name="inv"    value="<?php echo (int)($invSel ?? 0); ?>">

        <input type="hidden" name="zi" value="<?php echo htmlspecialchars($ziVal); ?>">
        <input type="hidden" name="ri" value="<?php echo htmlspecialchars($riVal); ?>">
        <input type="hidden" name="ei" value="<?php echo htmlspecialchars($eiVal); ?>">
        <input type="hidden" name="pi" value="<?php echo htmlspecialchars($piVal); ?>">
        <input type="hidden" name="ni" value="<?php echo htmlspecialchars($niVal); ?>">

        <input type="hidden" name="zf" value="<?php echo htmlspecialchars($zfVal); ?>">
        <input type="hidden" name="rf" value="<?php echo htmlspecialchars($rfVal); ?>">
        <input type="hidden" name="ef" value="<?php echo htmlspecialchars($efVal); ?>">
        <input type="hidden" name="pf" value="<?php echo htmlspecialchars($pfVal); ?>">
        <input type="hidden" name="nf" value="<?php echo htmlspecialchars($nfVal); ?>">

        <button type="submit" class="btn-header btn-outline-red">
          Ajuste de quantidades
        </button>
      </form>
      <!-- Fim botão Ajuste -->

      <?php if ($empresaSel !== null && $revendaSel !== null && $invSel !== null && ($usuarioLogado['perfil'] ?? '') === 'admin'): ?>
        <button type="button"
                class="btn-header btn-outline-red"
                onclick="if (confirm('Encerrar o inventário #<?php echo (int)$invSel; ?>?\n\nDepois disso não é possível registrar contagem nem recontagem.')) {
                    window.location.href='encerrar_inventario.php?codigo=<?php
                        echo urlencode($codSel); ?>&inv=<?php echo (int)$invSel; ?>';
                }">
          Encerrar inventário
        </button>
      <?php endif; ?>

      <?php if (in_array($usuarioLogado['perfil'] ?? '', ['admin', 'gestor'])): ?>
        <button type="button"
                class="btn-header btn-outline-red"
                onclick="window.location.href='tomada_decisao.php';">
          Tomada de Decisão
        </button>
        <?php if (in_array($usuarioLogado['perfil'] ?? '', ['admin','gestor'], true) && $invSel !== null): ?>
          <button type="button" class="btn-header btn-outline-red"
                  onclick="window.location.href='recontagem.php?codigo=<?php echo urlencode($codSel); ?>&inv=<?php echo (int)$invSel; ?>';">
            Recontagem
          </button>
        <?php endif; ?>
        <button type="button"
                class="btn-header btn-outline-red"
                onclick="window.location.href='login.php?contagem=3';">
          Ajuste de Locação
        </button>
        <button type="button"
                class="btn-header btn-outline-red"
                onclick="window.location.href='relatorio_transferencias.php';">
          Transferências
        </button>
        <button type="button"
                class="btn-header btn-outline-red"
                onclick="window.location.href='relatorio_conferencias.php';">
          Conferências
        </button>
        <button type="button"
                class="btn-header btn-outline-red"
                onclick="window.location.href='comparar.php';">
          Relatório de Comparação
        </button>
      <?php endif; ?>

      <?php if (($usuarioLogado['perfil'] ?? '') === 'admin'): ?>
        <button type="button"
                class="btn-header btn-outline-red"
                onclick="window.location.href='cadastro_usuario.php';">
          Cadastro de usuário
        </button>
        <button type="button"
                class="btn-header btn-outline-red"
                onclick="window.location.href='cadastro_conferentes.php';">
          Conferentes
        </button>
      <?php endif; ?>
    </div>

    <a href="logout_dashboard.php" class="logout-link">
      Sair
    </a>
  </header>
  <main>
    <?php if ($statusInventario === 'concluido'): ?>
      <div style="margin: 0 0 10px 0; padding: 8px 12px; border-radius: 6px;
                  background:#E8F5E9; color:#1B5E20; border:1px solid #C8E6C9;
                  font-size:13px; text-align:center;">
        Esta contagem de inventário está <strong>encerrada</strong>
        <?php if (!empty($dataFimInventario)): ?>
          (data de conclusão: <?php echo htmlspecialchars($dataFimInventario); ?>).
        <?php endif; ?>
      </div>
    <?php elseif ($statusInventario === 'cancelado'): ?>
      <div style="margin: 0 0 10px 0; padding: 8px 12px; border-radius: 6px;
                  background:#FFEBEE; color:#B71C1C; border:1px solid #FFCDD2;
                  font-size:13px; text-align:center;">
        Esta contagem de inventário foi <strong>cancelada</strong>.
      </div>
    <?php endif; ?>

    <!-- FILTROS LOJA / INVENTÁRIO -->
    <form method="get" style="text-align:center; margin-bottom:10px;">
      <div class="filtros-inventario">
        <select name="codigo" onchange="this.form.submit()">
          <option value="">Loja (código)</option>
          <?php foreach ($codigosLoja as $row): ?>
            <?php
              $c      = $row['codigo'];
              $cidade = $row['nome_empresa'];
              $rev    = $row['nome_revenda'];
              $texto  = $cidade . ' ' . $rev;
            ?>
            <option value="<?php echo htmlspecialchars($c); ?>"
              <?php echo ($codSel === $c) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($texto); ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select name="inv">
          <option value="">Inventário (id - data)</option>
          <?php foreach ($inventarios as $inv): ?>
            <option value="<?php echo (int)$inv['id']; ?>"
              <?php echo ($invSel !== null && $invSel === (int)$inv['id']) ? 'selected' : ''; ?>>
              #<?php echo (int)$inv['id']; ?> - <?php echo htmlspecialchars($inv['data_inicio']); ?>
            </option>
          <?php endforeach; ?>
        </select>

        <button type="submit">Carregar</button>
      </div>

      <div class="top-row" style="margin-top:12px;">
        <div class="card">
          <div class="card-title">Resumo das contagens</div>
          <?php
            // Peças faltantes = contadas - entrada
            $faltantesQtd   = $pecasContadasQtd - $entradaTotalQtd;
            $faltantesValor = $valorContadoTotal - $entradaTotalValor;

            // Resultado = Peças com sobra - Peças faltantes (valores exibidos na tabela)
            $resultadoQtd   = $pecasSobraQtd   + $faltantesQtd;
$resultadoValor = $pecasSobraValor  + $faltantesValor;

            // Percentual contado
            $pctContado = $entradaTotalQtd > 0
                ? min(100, round(($pecasContadasQtd / $entradaTotalQtd) * 100, 1))
                : 0;
          ?>
          <div style="max-height:210px; overflow:auto;">
            <table>
              <thead>
                <tr>
                  <th>Tipo</th>
                  <th style="text-align:right;">Quantidade</th>
                  <th style="text-align:right;">Valor total</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Entrada total (estoque)</td>
                  <td id="qtd-entrada" style="text-align:right;"><?php echo $entradaTotalQtd; ?></td>
                  <td style="text-align:right;">
                    R$ <?php echo number_format($entradaTotalValor, 2, ',', '.'); ?>
                  </td>
                </tr>
                <tr>
                  <td>Peças contadas</td>
                  <td id="qtd-contadas" style="text-align:right;"><?php echo $pecasContadasQtd; ?></td>
                  <td id="valor-contado" style="text-align:right;">
                    R$ <?php echo number_format($valorContadoTotal, 2, ',', '.'); ?>
                  </td>
                </tr>
                <tr>
                  <td>Peças com Diferença</td>
                  <td style="text-align:right;<?php echo $faltantesQtd < 0 ? ' color:var(--red); font-weight:600;' : ''; ?>">
                    <?php echo $faltantesQtd; ?>
                  </td>
                  <td style="text-align:right;<?php echo $faltantesValor < 0 ? ' color:var(--red);' : ''; ?>">
                    R$ <?php echo number_format($faltantesValor, 2, ',', '.'); ?>
                  </td>
                </tr>
                <tr>
                  <td>Peças com sobra</td>
                  <td style="text-align:right;"><?php echo $pecasSobraQtd; ?></td>
                  <td style="text-align:right;">
                    R$ <?php echo number_format($pecasSobraValor, 2, ',', '.'); ?>
                  </td>
                </tr>
                <tr style="border-top:2px solid var(--gray-300); font-weight:600;">
                  <td>Resultado</td>
                  <td style="text-align:right;<?php echo $resultadoQtd < 0 ? ' color:var(--red);' : ''; ?>">
                    <?php echo $resultadoQtd; ?>
                  </td>
                  <td style="text-align:right;<?php echo $resultadoValor < 0 ? ' color:var(--red);' : ''; ?>">
                    R$ <?php echo number_format($resultadoValor, 2, ',', '.'); ?>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-title">Concluído do estoque</div>
          <div class="circle-wrapper">
            <div class="circle" id="perc-circle" style="--perc: <?php echo number_format($percentualConcluido, 1, '.', ''); ?>;">
              <div class="circle-inner">
                <span id="perc-text"><?php echo number_format($percentualConcluido, 1, ',', '.'); ?>%</span>
                <small>concluído</small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-title">Contagens por coletor</div>
        <div class="coletor-card-inner">
          <table class="coletor-table">
            <thead>
              <tr>
                <th>Coletor</th>
                <th>Qtde</th>
              </tr>
            </thead>
            <tbody>
              <?php for ($i = 1; $i <= 4; $i++): ?>
                <tr>
                  <td><?php echo htmlspecialchars(labelColetor($i, $coletoresGrupos)); ?></td>
                  <td id="coletor-<?php echo $i; ?>">
                    <?php echo (int)($contagensColetor[$i] ?? 0); ?>
                  </td>
                </tr>
              <?php endfor; ?>
            </tbody>
          </table>

          <table class="coletor-table">
            <thead>
              <tr>
                <th>Coletor</th>
                <th>Qtde</th>
              </tr>
            </thead>
            <tbody>
              <?php for ($i = 5; $i <= 8; $i++): ?>
                <tr>
                  <td><?php echo htmlspecialchars(labelColetor($i, $coletoresGrupos)); ?></td>
                  <td id="coletor-<?php echo $i; ?>">
                    <?php echo (int)($contagensColetor[$i] ?? 0); ?>
                  </td>
                </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Card de pesquisa rápida de itens -->
      <div class="card">
        <div class="card-title">Pesquisar item</div>

        <div style="margin-bottom:10px; text-align:left;">
          <div style="display:inline-flex; align-items:center;">
            <input type="text"
                   name="busca"
                   value="<?php echo htmlspecialchars($busca ?? ''); ?>"
                   placeholder="Digite código ou descrição do item..."
                   style="width:260px; max-width:100%; padding:7px 10px;
                          border-radius:999px; border:1px solid #d1d5db; font-size:13px;">
            <button type="submit"
                    style="margin-left:6px; padding:7px 14px; border-radius:999px;
                           border:none; background:linear-gradient(135deg,var(--red),#FF1744);
                           color:#fff; font-size:13px; cursor:pointer;">
              Buscar
            </button>
          </div>
        </div>

        <?php if (!empty($resultBusca)): ?>
          <div style="max-height:220px; overflow:auto;">
            <table>
              <thead>
                <tr>
                  <th>Referência</th>
                  <th>Descrição</th>
                  <th>Locação</th>
                  <th style="text-align:right;">Qtd estoque</th>
                  <th style="text-align:right;">Qtd inventário</th>
                  <th style="text-align:right;">Valor uni.</th>
                  <th style="text-align:right;">Valor total</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($resultBusca as $linha): ?>
                  <?php
                    $qtdInv     = (int)($linha['qtd_inv'] ?? 0);
                    $valorTotal = $qtdInv * (float)$linha['valor_uni'];
                  ?>
                  <tr>
                    <td><?php echo htmlspecialchars($linha['ref']); ?></td>
                    <td><?php echo htmlspecialchars($linha['descricao']); ?></td>
                    <td><?php echo htmlspecialchars($linha['locacao']); ?></td>
                    <td style="text-align:right;"><?php echo (int)$linha['qtd_estoque']; ?></td>
                    <td style="text-align:right;"><?php echo $qtdInv; ?></td>
                    <td style="text-align:right;"><?php echo number_format((float)$linha['valor_uni'], 2, ',', '.'); ?></td>
                    <td style="text-align:right;"><?php echo number_format($valorTotal, 2, ',', '.'); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php elseif (!empty($busca)): ?>
          <div style="font-size:12px; color:#4b5563; text-align:left;">
            Nenhum item encontrado para "<strong><?php echo htmlspecialchars($busca); ?></strong>".
          </div>
        <?php endif; ?>
      </div>

      <?php if (in_array($usuarioLogado['perfil'] ?? '', ['admin','gestor'], true)
                && $invSel !== null && $statusInventario === 'aberto'): ?>
      <div class="card">
        <div class="card-title">Recontagem</div>
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
          <div style="font-size:13px; color:#4b5563;">
            <strong><?php echo (int)$recResumo['aguardando']; ?></strong> a recontar ·
            <?php echo (int)$recResumo['contando'] + (int)$recResumo['nao_contado']; ?> a contar ·
            <?php echo (int)$recResumo['bateu'] + (int)$recResumo['decisao']; ?> contados
          </div>
          <a href="recontagem.php?codigo=<?php echo urlencode($codSel); ?>&inv=<?php echo (int)$invSel; ?>"
             style="padding:8px 18px; border-radius:999px; background:linear-gradient(135deg,var(--red),#FF1744);
                    color:#fff; font-size:13px; text-decoration:none;">
            Abrir recontagem
          </a>
        </div>
        <?php if ($recConclusao['concluida']): ?>
          <div style="margin-top:10px; background:#E8F5E9; color:#1B5E20; border:1px solid #A5D6A7;
                      border-radius:10px; padding:10px 14px; font-size:13px; font-weight:600;">
            ✓ Recontagem concluída — <?php echo (int)$recConclusao['bateu']; ?> bateram ·
            <?php echo (int)$recConclusao['decisao']; ?> em decisão. Próximo passo: Tomada de Decisão.
          </div>
        <?php else: $faltaPartes = [];
          if ($recConclusao['falta_recontar'])       $faltaPartes[] = $recConclusao['falta_recontar'] . ' a recontar';
          if ($recConclusao['falta_contando'])       $faltaPartes[] = $recConclusao['falta_contando'] . ' com coletor';
          if ($recConclusao['falta_nunca_contados']) $faltaPartes[] = $recConclusao['falta_nunca_contados'] . ' nunca contados';
          if ($faltaPartes !== []): ?>
          <div style="margin-top:10px; font-size:12px; color:#6b7280;">
            Falta: <?php echo implode(' · ', $faltaPartes); ?>
          </div>
        <?php endif; endif; ?>
      </div>
      <?php endif; ?>

      <div class="lists-row">
        <div class="card">
          <div class="card-title">Itens com quantidade a menor</div>

          <div style="max-height:320px; overflow:auto;">
            <table>
              <thead>
                <tr>
                  <th>Referência</th>
                  <th>Descrição</th>
                  <th>Locação geral</th>
                  <th style="text-align:right;">Qtd estoque</th>
                  <th style="text-align:right;">Qtd inventário</th>
                  <th style="text-align:right;">Diferença</th>
                  <th style="text-align:right;">Valor uni.</th>
                  <th style="text-align:right;">Valor total</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($itensFalta as $linha): ?>
                  <?php $valorTotal = $linha['dif'] * $linha['valor_uni']; ?>
                  <tr>
                    <td><?php echo htmlspecialchars($linha['ref']); ?></td>
                    <td><?php echo htmlspecialchars($linha['descricao']); ?></td>
                    <td><?php echo htmlspecialchars($linha['locacao']); ?></td>
                    <td style="text-align:right;"><?php echo (int)$linha['qtd_estoque']; ?></td>
                    <td style="text-align:right;"><?php echo (int)$linha['qtd_inv']; ?></td>
                    <td style="text-align:right;"><?php echo (int)$linha['dif']; ?></td>
                    <td style="text-align:right;"><?php echo number_format((float)$linha['valor_uni'], 2, ',', '.'); ?></td>
                    <td style="text-align:right;"><?php echo number_format((float)$valorTotal, 2, ',', '.'); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-title">Itens com sobra</div>

          <div style="max-height:320px; overflow:auto;">
            <table>
              <thead>
                <tr>
                  <th>Referência</th>
                  <th>Descrição</th>
                  <th>Locação geral</th>
                  <th style="text-align:right;">Qtd estoque</th>
                  <th style="text-align:right;">Qtd inventário</th>
                  <th style="text-align:right;">Diferença</th>
                  <th style="text-align:right;">Valor uni.</th>
                  <th style="text-align:right;">Valor total</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($itensSobra as $linha): ?>
                  <?php $valorTotal = $linha['dif'] * $linha['valor_uni']; ?>
                  <tr>
                    <td><?php echo htmlspecialchars($linha['ref']); ?></td>
                    <td><?php echo htmlspecialchars($linha['descricao']); ?></td>
                    <td><?php echo htmlspecialchars($linha['locacao']); ?></td>
                    <td style="text-align:right;"><?php echo (int)$linha['qtd_estoque']; ?></td>
                    <td style="text-align:right;"><?php echo (int)$linha['qtd_inv']; ?></td>
                    <td style="text-align:right;"><?php echo (int)$linha['dif']; ?></td>
                    <td style="text-align:right;"><?php echo number_format((float)$linha['valor_uni'], 2, ',', '.'); ?></td>
                    <td style="text-align:right;"><?php echo number_format((float)$valorTotal, 2, ',', '.'); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </form>
  </main>
</div>

<script>
async function salvarNomesColetores() {
  const nomes = {};
  for (let i = 1; i <= 8; i++) {
    nomes[i] = document.getElementById('nome-coletor-' + i).value.trim();
  }

  const body = new URLSearchParams({
    ajax_salvar_coletores: '1',
    empresa: '<?php echo (int)($empresaSel ?? 0); ?>',
    revenda: '<?php echo (int)($revendaSel ?? 0); ?>',
    inv: '<?php echo (int)($invSel ?? 0); ?>'
  });

  for (let i = 1; i <= 8; i++) {
    body.append('coletor_' + i, nomes[i]);
  }

  const msgEl = document.getElementById('msg-coletores');
  msgEl.textContent = 'Salvando...';
  msgEl.style.color = 'var(--gray-400)';

  try {
    const res = await fetch('', { method: 'POST', body });
    const data = await res.json();
    msgEl.textContent = data.mensagem;
    msgEl.style.color = data.ok ? 'var(--green)' : 'var(--red)';

    if (data.ok) {
      // Atualiza os labels na tabela de contagens
      for (let i = 1; i <= 8; i++) {
       const label = nomes[i] ? nomes[i] : 'Coletor ' + i;
        const cells = document.querySelectorAll('.coletor-table td');
        cells.forEach(td => {
          if (td.textContent.trim().match(new RegExp('^Coletor\\s+' + i + '(\\s|$)'))) {
            td.textContent = label;
          }
        });
      }

      setTimeout(() => { msgEl.textContent = ''; }, 3000);
    }
  } catch(e) {
    msgEl.textContent = 'Erro ao salvar.';
    msgEl.style.color = 'var(--red)';
  }
}
</script>

<?php if ($invSel !== null): ?>
<script>
  // Percentual ao vivo: sobe sozinho enquanto os coletores bipam.
  (function () {
    const circulo = document.getElementById('perc-circle');
    const texto   = document.getElementById('perc-text');
    if (!circulo || !texto) return;

    const url = new URL(window.location.href);
    url.searchParams.set('ajax_percentual', '1');

    async function atualizar() {
      if (document.hidden) return;   // aba escondida não consulta o banco
      try {
        const resp  = await fetch(url, { cache: 'no-store' });
        const dados = await resp.json();
        if (!dados.ok) return;
        circulo.style.setProperty('--perc', dados.percentual);
        texto.textContent = dados.texto;
      } catch (e) {
        // Falha de rede: fica o último valor, tenta de novo no próximo ciclo.
      }
    }

    setInterval(atualizar, 5000);
    document.addEventListener('visibilitychange', atualizar);
  })();
</script>
<?php endif; ?>
</body>
</html>
