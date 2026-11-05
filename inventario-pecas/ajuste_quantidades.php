<?php
declare(strict_types=1);
session_start();
require 'conexao_inventario.php';
require 'conexao_oracle.php';
require_once 'recontagem_lib.php'; // o ajuste cai na última contagem feita


if (empty($_SESSION['inventario_login'])) {
    header('Location: index.php');
    exit;
}


$usuarioLogado = $_SESSION['inventario_login'];
$msg = '';
$msgTipo = '';


// helpers
function fetchAllPrepared(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchOnePrepared(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function escapeLike(string $value, string $escapeChar = '\\'): string {
    return strtr($value, [
        $escapeChar => $escapeChar . $escapeChar,
        '%' => $escapeChar . '%',
        '_' => $escapeChar . '_',
    ]);
}


// Filtros de contexto (loja/inventário)
$codSel     = $_GET['codigo'] ?? '';
$invSel     = isset($_GET['inv']) && $_GET['inv'] !== '' ? (int)$_GET['inv'] : null;
$empresaSel = null;
$revendaSel = null;


if ($codSel !== '' && strpos($codSel, '.') !== false) {
    [$emp, $rev] = array_map('intval', explode('.', $codSel));
    $empresaSel  = $emp;
    $revendaSel  = $rev;
}


// Paginação
$porPaginaOpcoes = [10, 25, 50, 100];
$porPagina = isset($_GET['por_pagina']) ? (int)$_GET['por_pagina'] : 25;
if (!in_array($porPagina, $porPaginaOpcoes)) $porPagina = 25;

$pagina = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;


// lojas com inventário
$codigosLoja = fetchAllPrepared($pdo, "
    SELECT DISTINCT l.codigo, l.empresa AS nome_empresa, l.revenda AS nome_revenda
      FROM inv_inventarios i
     INNER JOIN inv_lojas l
        ON l.ativo = 1
       AND CAST(SUBSTRING_INDEX(l.codigo,'.',1) AS UNSIGNED) = i.empresa
       AND CAST(SUBSTRING_INDEX(l.codigo,'.',-1) AS UNSIGNED) = i.revenda
     ORDER BY l.codigo
");


$inventarios = [];
if ($empresaSel !== null && $revendaSel !== null) {
    $inventarios = fetchAllPrepared($pdo, "
        SELECT id, data_inicio FROM inv_inventarios
         WHERE empresa = :e AND revenda = :r
         ORDER BY data_inicio DESC
    ", [':e' => $empresaSel, ':r' => $revendaSel]);
}


// Descarta invSel se não pertencer à loja atual
if ($invSel !== null && !empty($inventarios)) {
    $idsValidos = array_column($inventarios, 'id');
    if (!in_array($invSel, array_map('intval', $idsValidos))) {
        $invSel = null;
    }
} elseif ($invSel !== null && empty($inventarios)) {
    $invSel = null;
}


// busca item
$busca       = trim($_GET['busca'] ?? '');
$itens       = [];
$itemAjuste  = null;
$totalItens  = 0;
$totalPaginas = 0;


// Carrega itens sempre que tiver loja + inventário; busca é filtro opcional
if ($empresaSel !== null && $invSel !== null) {

    // Query base para WHERE compartilhado
    $whereBase = " WHERE id_inventario = :inv AND empresa = :e AND revenda = :r";
    $params    = [':inv' => $invSel, ':e' => $empresaSel, ':r' => $revendaSel];

    if ($busca !== '') {
        $buscaEscapada = escapeLike($busca);
        $termoEhRef = (bool)preg_match('/^[\pL\pN._\-\/]+$/u', $busca);

        if ($termoEhRef) {
            $whereBase .= "
                AND (
                       item_estoque_pub = :busca_exata
                    OR item_estoque_pub LIKE :busca_prefix
                    OR descricao_item_estoque LIKE :busca_desc
                )
            ";
            $params[':busca_exata']  = $busca;
            $params[':busca_prefix'] = $buscaEscapada . '%';
            $params[':busca_desc']   = '%' . $buscaEscapada . '%';
        } else {
            $whereBase .= " AND descricao_item_estoque LIKE :busca_desc";
            $params[':busca_desc'] = '%' . $buscaEscapada . '%';
        }
    }

    // COUNT total para paginação
    $totalItens   = (int)fetchOnePrepared($pdo, "SELECT COUNT(*) FROM estoque" . $whereBase, $params);
    $totalPaginas = max(1, (int)ceil($totalItens / $porPagina));

    // Ajusta página se excedeu
    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
        $offset = ($pagina - 1) * $porPagina;
    }

    $sql = "
        SELECT empresa, revenda, id_inventario,
               item_estoque_pub, descricao_item_estoque,
               quantidade, cont_1, cont_2, cont_3, cont_4, cont2_em, cont3_em, cont4_em,
               situacao, locacao_geral, custo_entrada, class_abc
          FROM estoque
    " . $whereBase . " ORDER BY locacao_geral LIMIT :limit OFFSET :offset";

    // PDO com bindValue para LIMIT/OFFSET (requer int)
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit',  $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,    PDO::PARAM_INT);
    $stmt->execute();
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // A contagem que o ajuste vai mexer: a última feita (ver recontagem_ajustar_ultima).
    foreach ($itens as &$it) {
        $it['ajusta_n']     = recontagem_numero_ultima_feita($it);
        $it['ajusta_valor'] = (int)$it[RECONTAGEM_COLUNAS[$it['ajusta_n']]['valor']];
    }
    unset($it);


    // Sempre busca do Oracle; só usa MySQL se Oracle estiver indisponível
    if (!empty($itens) && $empresaSel !== null) {
        $ora = oracle_conectar();
        if ($ora) {
            $dadosOraclePorRef = oracle_buscar_itens_por_refs(
                $ora,
                $empresaSel,
                $revendaSel,
                array_column($itens, 'item_estoque_pub')
            );
            foreach ($itens as &$it) {
                $dadosOracle = $dadosOraclePorRef[$it['item_estoque_pub']] ?? null;
                if ($dadosOracle) {
                    $it['quantidade']    = $dadosOracle['quantidade'];
                    $it['custo_entrada'] = $dadosOracle['custo_entrada'];
                    $it['class_abc']     = $dadosOracle['class_abc'];
                }
            }
            unset($it);
            oci_close($ora);
        }
    }
}


// AJAX: busca itens no Oracle para adicionar ao inventário
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar_oracle') {
    // Limpa qualquer saída anterior (warnings, notices, etc.)
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');


    $texto   = trim($_GET['texto'] ?? '');
    $ajaxEmp = (int)($_GET['empresa'] ?? 0);
    $ajaxRev = (int)($_GET['revenda'] ?? 0);
    $ajaxInv = (int)($_GET['inv']     ?? 0);


    if ($texto === '' || $ajaxEmp <= 0 || $ajaxRev <= 0 || $ajaxInv <= 0) {
        echo json_encode(['ok' => false, 'itens' => [], 'mensagem' => 'Dados inválidos.']);
        exit;
    }


    // Verifica quais refs já estão no inventário
    try {
        $stmtExist = $pdo->prepare("
            SELECT item_estoque_pub FROM estoque
             WHERE empresa = :e AND revenda = :r AND id_inventario = :inv
        ");
        $stmtExist->execute([':e' => $ajaxEmp, ':r' => $ajaxRev, ':inv' => $ajaxInv]);
        $jaNoInv = array_flip($stmtExist->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'itens' => [], 'mensagem' => 'Erro ao consultar inventário: ' . $e->getMessage()]);
        exit;
    }


    try {
        $ora   = oracle_conectar();
        $itens = [];
        if (!$ora) {
            echo json_encode(['ok' => false, 'itens' => [], 'mensagem' => 'Oracle indisponível no momento.']);
            exit;
        }
        $itens = oracle_buscar_itens_texto($ora, $ajaxEmp, $ajaxRev, $texto, 30);
        oci_close($ora);
        $refsSemCusto = [];
        foreach ($itens as &$it) {
            $it['ja_no_inventario'] = isset($jaNoInv[$it['item_estoque_pub']]);


            // Se custo veio zerado do Oracle, busca do MySQL como fallback
            if ($it['custo_entrada'] == 0) {
                $refsSemCusto[] = $it['item_estoque_pub'];
            }
        }
        unset($it);

        if ($refsSemCusto !== []) {
            $refsSemCusto = array_values(array_unique($refsSemCusto));
            $placeholders = [];
            $paramsCusto = [
                ':e1' => $ajaxEmp, ':r1' => $ajaxRev,
                ':e2' => $ajaxEmp, ':r2' => $ajaxRev,
            ];

            foreach ($refsSemCusto as $idx => $ref) {
                $ph = ':ref_' . $idx;
                $placeholders[] = $ph;
                $paramsCusto[$ph] = $ref;
            }

            $stmtCusto = $pdo->prepare("
                SELECT t.item_estoque_pub, t.custo_entrada
                  FROM estoque t
                 INNER JOIN (
                    SELECT item_estoque_pub, MAX(id_inventario) AS ult_inv
                      FROM estoque
                     WHERE empresa = :e1
                       AND revenda = :r1
                       AND custo_entrada > 0
                       AND item_estoque_pub IN (" . implode(', ', $placeholders) . ")
                     GROUP BY item_estoque_pub
                 ) ult
                    ON ult.item_estoque_pub = t.item_estoque_pub
                   AND ult.ult_inv = t.id_inventario
                 WHERE t.empresa = :e2
                   AND t.revenda = :r2
            ");
            $stmtCusto->execute($paramsCusto);

            $custosMysql = [];
            foreach ($stmtCusto->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $custosMysql[$row['item_estoque_pub']] = (float)$row['custo_entrada'];
            }

            foreach ($itens as &$it) {
                if ((float)$it['custo_entrada'] === 0.0 && isset($custosMysql[$it['item_estoque_pub']])) {
                    $it['custo_entrada'] = $custosMysql[$it['item_estoque_pub']];
                }
            }
            unset($it);
        }

        // Busca última locação conhecida de cada ref no MySQL
        $refsParaLoc = array_column($itens, 'item_estoque_pub');
        $locacoesPorRef = [];
        if ($refsParaLoc) {
            $phLoc = [];
            $paramsLoc2 = [':e1' => $ajaxEmp, ':r1' => $ajaxRev, ':e2' => $ajaxEmp, ':r2' => $ajaxRev];
            foreach ($refsParaLoc as $idx => $ref) {
                $ph = ':lr_' . $idx;
                $phLoc[] = $ph;
                $paramsLoc2[$ph] = $ref;
            }
            $stmtLoc = $pdo->prepare("
                SELECT t.item_estoque_pub, t.locacao_geral
                  FROM estoque t
                 INNER JOIN (
                    SELECT item_estoque_pub, MAX(id_inventario) AS ult_inv
                      FROM estoque
                     WHERE empresa = :e1 AND revenda = :r1
                       AND item_estoque_pub IN (" . implode(',', $phLoc) . ")
                     GROUP BY item_estoque_pub
                 ) ult ON ult.item_estoque_pub = t.item_estoque_pub
                      AND ult.ult_inv = t.id_inventario
                 WHERE t.empresa = :e2 AND t.revenda = :r2
            ");
            $stmtLoc->execute($paramsLoc2);
            foreach ($stmtLoc->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $locacoesPorRef[$row['item_estoque_pub']] = $row['locacao_geral'];
            }

            foreach ($itens as &$it) {
                // Usa locação do MySQL (inventário anterior) se existir;
                // caso contrário preserva a locação que veio do Oracle
                $it['locacao_geral'] = $locacoesPorRef[$it['item_estoque_pub']]
                    ?? ($it['locacao_geral'] ?? '');
            }
            unset($it);
        }

        echo json_encode(['ok' => true, 'itens' => $itens]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'itens' => [], 'mensagem' => 'Erro Oracle: ' . $e->getMessage()]);
    }
    exit;
}


// AJAX: inserir item no inventário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inserir_item'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');


    $iEmp    = (int)($_POST['empresa']       ?? 0);
    $iRev    = (int)($_POST['revenda']       ?? 0);
    $iInv    = (int)($_POST['id_inventario'] ?? 0);
    $iRef    = trim($_POST['item_estoque_pub'] ?? '');
    $iDesc   = trim($_POST['descricao']      ?? '');
    $iLoc    = trim($_POST['locacao']        ?? '00.000.000.00.000');
    $iCont1  = (int)($_POST['cont_1']        ?? 0);
    $iQtdEst = (int)($_POST['quantidade']    ?? 0);
    $iCusto  = (float)($_POST['custo_entrada'] ?? 0);
    $iAbc    = strtoupper(trim($_POST['class_abc'] ?? ''));


    if ($iEmp <= 0 || $iRev <= 0 || $iInv <= 0 || $iRef === '') {
        echo json_encode(['ok' => false, 'mensagem' => 'Dados inválidos.']);
        exit;
    }


    // Verifica se já existe
    try {
        $stmtChk = $pdo->prepare("
            SELECT COUNT(*) FROM estoque
             WHERE empresa = :e AND revenda = :r AND id_inventario = :inv
               AND item_estoque_pub = :ref AND locacao_geral = :loc
        ");
        $stmtChk->execute([':e'=>$iEmp,':r'=>$iRev,':inv'=>$iInv,':ref'=>$iRef,':loc'=>$iLoc]);
        if ($stmtChk->fetchColumn() > 0) {
            echo json_encode(['ok' => false, 'mensagem' => 'Item já existe nesta locação no inventário.']);
            exit;
        }


        $pdo->prepare("
            INSERT INTO estoque
                (empresa, revenda, id_inventario, item_estoque_pub, descricao_item_estoque,
                 quantidade, cont_1, cont_2, custo_entrada, class_abc,
                 locacao_geral, locacao_zona, situacao, coletor)
            VALUES
                (:e, :r, :inv, :ref, :desc,
                 :qtd, :c1, 0, :custo, :abc,
                 :loc, 0, 'A', 0)
        ")->execute([
            ':e'    => $iEmp,   ':r'    => $iRev,   ':inv'  => $iInv,
            ':ref'  => $iRef,   ':desc' => $iDesc,  ':qtd'  => $iQtdEst,
            ':c1'   => $iCont1, ':custo'=> $iCusto, ':abc'  => $iAbc,
            ':loc'  => $iLoc,
        ]);
        echo json_encode(['ok' => true, 'mensagem' => 'Item inserido com sucesso!']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => 'Erro: ' . $e->getMessage()]);
    }
    exit;
}


// POST: salvar ajuste
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_ajuste'])) {
    $postCodigo = $_POST['codigo'] ?? '';
    $postInv    = isset($_POST['inv']) && $_POST['inv'] !== '' ? (int)$_POST['inv'] : 0;
    $postBusca  = trim($_POST['busca'] ?? '');
    $postPagina = (int)($_POST['pagina'] ?? 1);
    $postPorPag = (int)($_POST['por_pagina'] ?? 25);


    $postEmpresa  = (int)($_POST['p_empresa']       ?? 0);
    $postRevenda  = (int)($_POST['p_revenda']       ?? 0);
    $postInvLinha = (int)($_POST['p_id_inventario'] ?? 0);
    $postItem     = trim($_POST['p_item']           ?? '');
    $postLocacao  = trim($_POST['p_locacao']        ?? '');
    $rawQtd       = trim((string)($_POST['nova_qtd'] ?? ''));
    $novaQtd      = ctype_digit($rawQtd) ? (int)$rawQtd : -1;


    $chaveValida = $postEmpresa > 0 && $postRevenda > 0 && $postInvLinha > 0 && $postItem !== '';


    if ($chaveValida && $novaQtd >= 0) {
        try {
            // Grava na última contagem feita do item — a 2ª, depois da 2ª
            // contagem —, que é a que o relatório e o dashboard leem.
            $r = recontagem_ajustar_ultima($pdo, $postInvLinha, $postEmpresa, $postRevenda, $postItem, $postLocacao, $novaQtd);

            $msg     = $r['linhas'] > 0
                ? $r['contagem'] . "ª contagem ajustada para $novaQtd."
                : "Nenhuma linha atualizada.";
            $msgTipo = $r['linhas'] > 0 ? 'sucesso' : 'erro';
        } catch (Throwable $e) {
            $msg     = "Erro ao ajustar: " . $e->getMessage();
            $msgTipo = 'erro';
        }
    } else {
        $msg     = "Dados inválidos para ajuste.";
        $msgTipo = 'erro';
    }


    $url = $_SERVER['PHP_SELF'] . '?' . http_build_query([
        'codigo'    => $postCodigo,
        'inv'       => $postInv,
        'busca'     => $postBusca,
        'pagina'    => $postPagina,
        'por_pagina'=> $postPorPag,
        'msg'       => $msg,
        'tipo'      => $msgTipo,
    ]);
    header('Location: ' . $url);
    exit;
}


if (isset($_GET['msg'])) { $msg = $_GET['msg']; $msgTipo = $_GET['tipo'] ?? 'sucesso'; }
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Ajuste de Quantidades</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --red: #2347C8; --green: #16a34a;
      --bg: #f3f4f6; --white: #fff;
      --gray-100: #f9fafb; --gray-200: #e5e7eb;
      --gray-400: #9ca3af; --gray-600: #4b5563; --gray-800: #111827;
      --shadow: 0 2px 8px rgba(15,23,42,.07);
      --radius: 10px;
    }
    * { box-sizing: border-box; }
    body { margin:0; font-family:system-ui,-apple-system,sans-serif; background:var(--bg); color:var(--gray-800); }
    header { background:#fff; border-bottom:1px solid var(--gray-200); padding:10px 20px;
             display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:20;
             box-shadow:0 1px 4px rgba(15,23,42,.04); }
    header h1 { margin:0; font-size:16px; font-weight:700; }

    .btn-back-group {
      display:inline-flex;
      align-items:center;
      gap:8px;
    }

    .btn-back { font-size:13px; color:var(--gray-600); text-decoration:none; padding:5px 12px;
                border:1px solid var(--gray-200); border-radius:999px; background:#fff; }
    .btn-back:hover { border-color:var(--red); color:var(--red); }

    .btn-sobras-header {
      font-size:13px;
      text-decoration:none;
      padding:5px 14px;
      border-radius:999px;
      border:1px solid var(--gray-200);
      background:#f9fafb;
      color:var(--gray-600);
      display:inline-flex;
      align-items:center;
      gap:6px;
      font-weight:500;
      cursor:pointer;
      transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .btn-sobras-header::before {
      content:"";
      width:6px;
      height:6px;
      border-radius:999px;
      background:var(--red);
    }
    .btn-sobras-header:hover {
      background:#fff;
      color:var(--red);
      border-color:rgba(35,71,200,.45);
      box-shadow:0 0 0 1px rgba(35,71,200,.08);
    }

    main { padding:16px 20px 32px; max-width:1100px; margin:0 auto; display:flex; flex-direction:column; gap:14px; }
    .card { background:#fff; border-radius:var(--radius); padding:14px 16px; box-shadow:var(--shadow); border:1px solid rgba(15,23,42,.04); }
    .card-title { font-size:13px; font-weight:700; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
    .card-title::before { content:""; width:3px; height:14px; border-radius:999px; background:var(--red); }
    .filters { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
    .filters select, .filters input, .filters button {
      height:34px; border-radius:999px; border:1px solid var(--gray-200);
      padding:0 12px; font-size:13px; background:var(--gray-100);
    }
    .filters button { background:linear-gradient(135deg,var(--red),#FF1744); color:#fff; border:none; cursor:pointer; font-weight:600; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    thead tr { background:var(--gray-100); }
    th,td { border-bottom:1px solid var(--gray-200); padding:8px 6px; text-align:left; }
    th { font-weight:600; color:var(--gray-600); font-size:12px; }
    tbody tr:hover { background:#FFF3F4; }
    .badge { display:inline-block; padding:2px 7px; border-radius:999px; font-size:11px; font-weight:700; }
    .badge-a { background:#dcfce7; color:#166534; }
    .badge-b { background:#dbeafe; color:#1e40af; }
    .badge-c { background:#fef9c3; color:#854d0e; }
    .btn-edit { padding:4px 10px; font-size:12px; border-radius:6px; border:1px solid var(--gray-200);
                background:#fff; cursor:pointer; color:var(--gray-600); }
    .btn-edit:hover { border-color:var(--red); color:var(--red); }
    .msg-ok { padding:8px 12px; border-radius:8px; background:#e8f5e9; color:#166534; border:1px solid #bbf7d0; font-size:13px; }
    .msg-err { padding:8px 12px; border-radius:8px; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; font-size:13px; }

    /* Paginação */
    .pagination-bar {
      display:flex;
      align-items:center;
      justify-content:space-between;
      flex-wrap:wrap;
      gap:10px;
      margin-top:10px;
      padding-top:10px;
      border-top:1px solid var(--gray-200);
    }
    .pagination-info {
      font-size:12px;
      color:var(--gray-600);
    }
    .pagination-controls {
      display:flex;
      align-items:center;
      gap:4px;
    }
    .pagination-controls a,
    .pagination-controls span {
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:32px;
      height:32px;
      padding:0 8px;
      border-radius:8px;
      font-size:13px;
      font-weight:500;
      text-decoration:none;
      border:1px solid var(--gray-200);
      color:var(--gray-600);
      background:#fff;
      transition:all .15s ease;
    }
    .pagination-controls a:hover {
      border-color:var(--red);
      color:var(--red);
      background:#FFF3F4;
    }
    .pagination-controls .pg-active {
      background:linear-gradient(135deg,var(--red),#FF1744);
      color:#fff;
      border-color:transparent;
      font-weight:700;
    }
    .pagination-controls .pg-disabled {
      opacity:.4;
      pointer-events:none;
    }
    .pagination-controls .pg-ellipsis {
      border:none;
      background:transparent;
      color:var(--gray-400);
      min-width:24px;
      padding:0;
    }
    .por-pagina-group {
      display:flex;
      align-items:center;
      gap:6px;
      font-size:12px;
      color:var(--gray-600);
    }
    .por-pagina-group select {
      height:30px;
      border-radius:6px;
      border:1px solid var(--gray-200);
      padding:0 6px;
      font-size:12px;
      background:#fff;
      cursor:pointer;
    }

    /* modal */
    .overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:50; align-items:center; justify-content:center; }
    .overlay.open { display:flex; }
    .modal { background:#fff; border-radius:14px; padding:22px 24px; width:100%; max-width:420px; box-shadow:0 20px 40px rgba(0,0,0,.25); }
    .modal h2 { margin:0 0 14px; font-size:16px; }
    .modal label { display:block; font-size:12px; font-weight:600; color:var(--gray-600); margin-bottom:4px; margin-top:10px; }
    .modal input[type=number], .modal input[type=text] {
      width:100%; height:38px; border-radius:8px; border:1px solid var(--gray-200);
      padding:0 10px; font-size:14px;
    }
    .modal input:focus { outline:none; border-color:var(--red); box-shadow:0 0 0 2px rgba(35,71,200,.15); }
    .modal-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:16px; flex-wrap:wrap; }
    .btn-save { padding:8px 18px; border-radius:8px; border:none; background:linear-gradient(135deg,var(--red),#FF1744); color:#fff; font-weight:700; cursor:pointer; }
    .btn-cancel { padding:8px 18px; border-radius:8px; border:1px solid var(--gray-200); background:#fff; cursor:pointer; }
    .info-item { font-size:13px; color:var(--gray-600); line-height:1.8; margin-bottom:6px; }
    .info-item strong { color:var(--gray-800); }
  </style>
</head>
<body>
<header>
  <h1>Ajuste de Quantidades</h1>
  <div class="btn-back-group">
    <a href="dashboard_inventario.php?codigo=<?php echo urlencode($codSel); ?>&inv=<?php echo (int)$invSel; ?>" class="btn-back">
      ← Dashboard
    </a>

    <?php if ($empresaSel !== null && $invSel !== null): ?>
      <button
        type="button"
        class="btn-sobras-header"
        onclick="abrirSobrasComContexto()"
      >
        Sobras
      </button>
    <?php endif; ?>
  </div>
</header>
<main>
  <?php if ($msg): ?>
    <div class="<?php echo $msgTipo === 'sucesso' ? 'msg-ok' : 'msg-err'; ?>"><?php echo htmlspecialchars($msg); ?></div>
  <?php endif; ?>


  <!-- Filtros -->
  <div class="card">
    <div class="card-title">Selecionar inventário e buscar item</div>
    <form method="get" class="filters" id="form-filtros">
      <select name="codigo" onchange="resetPagina(); this.form.submit()">
        <option value="">Loja...</option>
        <?php foreach ($codigosLoja as $row):
          $c = $row['codigo'];
          $txt = $row['nome_empresa'] . ' ' . $row['nome_revenda'];
        ?>
          <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $codSel === $c ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($txt); ?>
          </option>
        <?php endforeach; ?>
      </select>


      <select name="inv" onchange="resetPagina(); this.form.submit()" <?php echo empty($inventarios) ? 'disabled' : ''; ?>>
        <option value="">
          <?php echo empty($inventarios) ? 'Selecione uma loja primeiro' : 'Inventário...'; ?>
        </option>
        <?php foreach ($inventarios as $inv): ?>
          <option value="<?php echo (int)$inv['id']; ?>"
                  <?php echo ($invSel !== null && $invSel === (int)$inv['id']) ? 'selected' : ''; ?>>
            #<?php echo (int)$inv['id']; ?> — <?php echo htmlspecialchars($inv['data_inicio']); ?>
          </option>
        <?php endforeach; ?>
      </select>


      <input type="text" name="busca" placeholder="Referência ou descrição..." value="<?php echo htmlspecialchars($busca); ?>" style="width:240px; border-radius:999px;"
             onkeydown="if(event.key==='Enter') resetPagina()">

      <input type="hidden" name="pagina" id="input-pagina" value="<?php echo (int)$pagina; ?>">
      <input type="hidden" name="por_pagina" id="input-por-pagina" value="<?php echo (int)$porPagina; ?>">

      <button type="submit" onclick="resetPagina()">Buscar</button>
    </form>
  </div>


  <!-- Adicionar item ao inventário -->
  <?php if ($empresaSel !== null && $invSel !== null): ?>
  <div class="card">
    <div class="card-title">Adicionar item ao inventário</div>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
      <input type="text" id="ref-nova"
             placeholder="Digite a referência do item..."
             style="width:240px; height:34px; border-radius:999px;
                    border:1px solid var(--gray-200); padding:0 14px; font-size:13px;"
             onkeydown="if(event.key==='Enter') buscarRefOracle()">
      <button onclick="buscarRefOracle()"
              style="height:34px; padding:0 18px; border-radius:999px; border:none;
                     background:linear-gradient(135deg,var(--red),#FF1744);
                     color:#fff; font-weight:600; cursor:pointer; font-size:13px;">
        Buscar
      </button>
      <span id="oracle-status" style="font-size:12px; color:var(--gray-400);"></span>
    </div>
  </div>
  <?php endif; ?>


  <!-- Modal inserir item -->
  <div class="overlay" id="overlay-add">
    <div class="modal">
      <h2>Adicionar ao Inventário</h2>
      <div class="info-item" id="add-info"></div>


      <label>Locação</label>
      <input type="text" id="add-locacao" placeholder="Ex: 01.001.001.01.001"
             style="width:100%; height:38px; border-radius:8px; border:1px solid var(--gray-200); padding:0 10px; font-size:14px;">


      <label>Quantidade contada (Cont. 1)</label>
      <input type="number" id="add-cont1" min="0" max="99999" value="0"
             style="width:100%; height:38px; border-radius:8px; border:1px solid var(--gray-200); padding:0 10px; font-size:14px;">


      <div id="add-erro" style="display:none; margin-top:8px; padding:6px 10px;
           border-radius:6px; background:#fef2f2; color:#b91c1c;
           border:1px solid #fecaca; font-size:13px;"></div>


      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="fecharModalAdd()">Cancelar</button>
        <button type="button" class="btn-save" onclick="confirmarAdicao()">Adicionar</button>
      </div>
    </div>
  </div>


  <!-- Resultados -->
  <?php if (!empty($itens)): ?>
  <div class="card">
    <div class="card-title">Resultados (<?php echo number_format($totalItens, 0, ',', '.'); ?> itens)</div>
    <div style="overflow:auto;">
      <table>
        <thead>
          <tr>
            <th>Referência</th>
            <th>Descrição</th>
            <th>Locação</th>
            <th style="text-align:right;">Qtd Estoque</th>
            <th style="text-align:right;">Cont. 1</th>
            <th style="text-align:right;">Cont. 2</th>
            <th style="text-align:right;">Vale</th>
            <th style="text-align:right;">Valor Unit.</th>
            <th>ABC</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($itens as $it): ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($it['item_estoque_pub']); ?></strong></td>
            <td><?php echo htmlspecialchars($it['descricao_item_estoque']); ?></td>
            <td><?php echo htmlspecialchars($it['locacao_geral']); ?></td>
            <td style="text-align:right;"><?php echo (int)$it['quantidade']; ?></td>
            <td style="text-align:right;"><?php echo (int)$it['cont_1']; ?></td>
            <td style="text-align:right;"><?php echo $it['cont2_em'] !== null ? (int)$it['cont_2'] : '–'; ?></td>
            <td style="text-align:right;" title="Contagem que o ajuste altera"><?php echo (int)$it['ajusta_n']; ?>ª: <strong><?php echo (int)$it['ajusta_valor']; ?></strong></td>
            <td style="text-align:right;">R$ <?php echo number_format((float)$it['custo_entrada'], 2, ',', '.'); ?></td>
            <td>
              <?php
                $abc = strtoupper($it['class_abc'] ?? '');
                $cls = $abc === 'A' ? 'badge-a' : ($abc === 'B' ? 'badge-b' : 'badge-c');
              ?>
              <span class="badge <?php echo $cls; ?>"><?php echo htmlspecialchars($abc ?: '-'); ?></span>
            </td>
            <td>
              <button class="btn-edit" onclick="abrirModal(
                '<?php echo addslashes($it['item_estoque_pub']); ?>',
                '<?php echo addslashes($it['descricao_item_estoque']); ?>',
                '<?php echo addslashes($it['locacao_geral']); ?>',
                <?php echo (int)$it['quantidade']; ?>,
                <?php echo (int)$it['ajusta_n']; ?>,
                <?php echo (int)$it['ajusta_valor']; ?>,
                <?php echo number_format((float)$it['custo_entrada'], 2, '.', ''); ?>,
                '<?php echo addslashes($abc); ?>',
                <?php echo (int)$it['empresa']; ?>,
                <?php echo (int)$it['revenda']; ?>,
                <?php echo (int)$it['id_inventario']; ?>
              )">Ajustar</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Barra de paginação -->
    <?php if ($totalPaginas > 1 || $totalItens > 10): ?>
    <div class="pagination-bar">
      <div class="pagination-info">
        Mostrando <?php echo number_format($offset + 1, 0, ',', '.'); ?>–<?php echo number_format(min($offset + $porPagina, $totalItens), 0, ',', '.'); ?>
        de <?php echo number_format($totalItens, 0, ',', '.'); ?> itens
        &nbsp;·&nbsp; Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?>
      </div>

      <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
        <!-- Seletor de linhas por página -->
        <div class="por-pagina-group">
          <span>Linhas:</span>
          <select onchange="mudarPorPagina(this.value)">
            <?php foreach ($porPaginaOpcoes as $op): ?>
              <option value="<?php echo $op; ?>" <?php echo $porPagina === $op ? 'selected' : ''; ?>>
                <?php echo $op; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Controles de página -->
        <div class="pagination-controls">
          <?php
            // Monta URL base para paginação
            $pgParams = [
              'codigo'    => $codSel,
              'inv'       => (int)$invSel,
              'busca'     => $busca,
              'por_pagina'=> $porPagina,
            ];

            function pgUrl(array $base, int $pg): string {
              $base['pagina'] = $pg;
              return '?' . http_build_query($base);
            }

            // Primeira / Anterior
            $prevDisabled = $pagina <= 1 ? ' pg-disabled' : '';
          ?>
          <a href="<?php echo pgUrl($pgParams, 1); ?>" class="<?php echo $prevDisabled; ?>" title="Primeira">«</a>
          <a href="<?php echo pgUrl($pgParams, max(1, $pagina - 1)); ?>" class="<?php echo $prevDisabled; ?>" title="Anterior">‹</a>

          <?php
            // Calcula range de páginas visíveis (max 7 botões)
            $maxBotoes = 7;
            $inicio = max(1, $pagina - (int)floor($maxBotoes / 2));
            $fim    = min($totalPaginas, $inicio + $maxBotoes - 1);
            if ($fim - $inicio + 1 < $maxBotoes) {
              $inicio = max(1, $fim - $maxBotoes + 1);
            }

            if ($inicio > 1) {
              echo '<a href="' . pgUrl($pgParams, 1) . '">1</a>';
              if ($inicio > 2) echo '<span class="pg-ellipsis">…</span>';
            }

            for ($p = $inicio; $p <= $fim; $p++) {
              $activeClass = $p === $pagina ? 'pg-active' : '';
              echo '<a href="' . pgUrl($pgParams, $p) . '" class="' . $activeClass . '">' . $p . '</a>';
            }

            if ($fim < $totalPaginas) {
              if ($fim < $totalPaginas - 1) echo '<span class="pg-ellipsis">…</span>';
              echo '<a href="' . pgUrl($pgParams, $totalPaginas) . '">' . $totalPaginas . '</a>';
            }

            // Próxima / Última
            $nextDisabled = $pagina >= $totalPaginas ? ' pg-disabled' : '';
          ?>
          <a href="<?php echo pgUrl($pgParams, min($totalPaginas, $pagina + 1)); ?>" class="<?php echo $nextDisabled; ?>" title="Próxima">›</a>
          <a href="<?php echo pgUrl($pgParams, $totalPaginas); ?>" class="<?php echo $nextDisabled; ?>" title="Última">»</a>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php elseif ($invSel !== null): ?>
    <div class="card" style="color:var(--gray-600); font-size:13px;">
      <?php if ($busca !== ''): ?>
        Nenhum item encontrado para "<strong><?php echo htmlspecialchars($busca); ?></strong>".
      <?php else: ?>
        Nenhum item encontrado neste inventário.
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>


<!-- Modal de ajuste -->
<div class="overlay" id="overlay">
  <div class="modal">
    <h2>Ajustar Quantidade</h2>
    <div class="info-item" id="modal-info"></div>
    <form method="post">
      <input type="hidden" name="salvar_ajuste" value="1">
      <input type="hidden" name="p_empresa"       id="modal-empresa">
      <input type="hidden" name="p_revenda"       id="modal-revenda">
      <input type="hidden" name="p_id_inventario" id="modal-inv">
      <input type="hidden" name="p_item"          id="modal-item">
      <input type="hidden" name="p_locacao"       id="modal-locacao">
      <!-- preserva contexto -->
      <input type="hidden" name="codigo" value="<?php echo htmlspecialchars($codSel); ?>">
      <input type="hidden" name="inv" value="<?php echo (int)$invSel; ?>">
      <input type="hidden" name="busca" value="<?php echo htmlspecialchars($busca); ?>">
      <input type="hidden" name="pagina" value="<?php echo (int)$pagina; ?>">
      <input type="hidden" name="por_pagina" value="<?php echo (int)$porPagina; ?>">


      <label id="modal-qtd-label">Nova quantidade</label>
      <input type="number" name="nova_qtd" id="modal-qtd" min="0" max="99999" required>


      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="fecharModal()">Cancelar</button>
        <button type="submit" class="btn-save">Salvar Ajuste</button>
      </div>
    </form>
  </div>
</div>


<script>
// Funções de paginação
function resetPagina() {
  document.getElementById('input-pagina').value = '1';
}

function mudarPorPagina(valor) {
  document.getElementById('input-por-pagina').value = valor;
  resetPagina();
  document.getElementById('form-filtros').submit();
}


// contagem: a última feita (1ª a 4ª), que é a que o ajuste altera; qtdAtual: o valor dela.
function abrirModal(ref, desc, loc, qtdEst, contagem, qtdAtual, valor, abc, emp, rev, inv) {
  document.getElementById('modal-empresa').value  = emp;
  document.getElementById('modal-revenda').value  = rev;
  document.getElementById('modal-inv').value      = inv;
  document.getElementById('modal-item').value     = ref;
  document.getElementById('modal-locacao').value  = loc;
  document.getElementById('modal-qtd').value      = qtdAtual;
  document.getElementById('modal-qtd-label').textContent =
    'Nova quantidade da ' + contagem + 'ª contagem' + (contagem > 1 ? ' (a que vale no relatório)' : '');
  document.getElementById('modal-info').innerHTML =
    '<strong>' + ref + '</strong> — ' + desc + '<br>' +
    'Locação: <strong>' + loc + '</strong> | ' +
    'Estoque: <strong>' + qtdEst + '</strong> | ' +
    'Valor: <strong>R$ ' + valor.toFixed(2).replace('.', ',') + '</strong> | ' +
    'ABC: <strong>' + (abc || '-') + '</strong>';
  document.getElementById('overlay').classList.add('open');
  setTimeout(() => document.getElementById('modal-qtd').focus(), 50);
}
function fecharModal() {
  document.getElementById('overlay').classList.remove('open');
}
document.getElementById('overlay').addEventListener('click', function(e) {
  if (e.target === this) fecharModal();
});

// ---- Abrir sobras com contexto (tela + item do modal, se existir) ----
function abrirSobrasComContexto() {
  const overlay = document.getElementById('overlay');
  const modalAberto = overlay.classList.contains('open');

  let empresa, revenda, inv, item, locacao;

  if (modalAberto) {
    empresa = document.getElementById('modal-empresa').value || CTX_EMP;
    revenda = document.getElementById('modal-revenda').value || CTX_REV;
    inv     = document.getElementById('modal-inv').value || CTX_INV;
    item    = document.getElementById('modal-item').value || '';
    locacao = document.getElementById('modal-locacao').value || '';
  } else {
    empresa = CTX_EMP;
    revenda = CTX_REV;
    inv     = CTX_INV;
    item    = '';
    locacao = '';
  }

  const params = new URLSearchParams({
    empresa: empresa,
    revenda: revenda,
    inv: inv
  });

  if (item) params.append('item', item);
  if (locacao) params.append('locacao', locacao);

  window.open('sobras.php?' + params.toString(), '_blank');
}


// ---- Adicionar item ao inventário ----
const CTX_EMP = <?php echo (int)($empresaSel ?? 0); ?>;
const CTX_REV = <?php echo (int)($revendaSel ?? 0); ?>;
const CTX_INV = <?php echo (int)($invSel ?? 0); ?>;


let itemSelecionado = null;


async function buscarRefOracle() {
  const ref    = document.getElementById('ref-nova').value.trim();
  const status = document.getElementById('oracle-status');


  if (ref === '') { status.textContent = 'Digite a referência.'; return; }


  status.textContent = 'Buscando...';


  const url = `?ajax=buscar_oracle&texto=${encodeURIComponent(ref)}&empresa=${CTX_EMP}&revenda=${CTX_REV}&inv=${CTX_INV}`;


  try {
    const res   = await fetch(url);
    const texto = await res.text();


    let data;
    try {
      data = JSON.parse(texto);
    } catch(e) {
      console.error('Resposta inválida do servidor (não é JSON):', texto);
      status.textContent = 'Erro interno do servidor. Veja o console (F12) para detalhes.';
      return;
    }


    status.textContent = '';


    if (!data.ok || !data.itens || data.itens.length === 0) {
      status.textContent = data.mensagem || 'Item não encontrado no Oracle.';
      return;
    }


    const exato = data.itens.find(i => i.item_estoque_pub.toUpperCase() === ref.toUpperCase());
    const item  = exato || data.itens[0];


    if (item.ja_no_inventario) {
      status.textContent = '⚠ Item já existe neste inventário.';
      return;
    }


        abrirModalAdd(
      item.item_estoque_pub,
      item.descricao || '',
      item.quantidade,
      item.custo_entrada,
      (item.class_abc || '').toUpperCase(),
      item.locacao_geral || ''
    );


  } catch(err) {
    status.textContent = 'Falha na comunicação com o servidor.';
    console.error('Erro fetch:', err);
  }
}


function abrirModalAdd(ref, desc, qtd, custo, abc, locacao) {
  itemSelecionado = { ref, desc, qtd, custo, abc };
  document.getElementById('add-info').innerHTML =
    '<strong>' + ref + '</strong> — ' + (desc || '<em style="color:#9ca3af">sem descrição</em>') +
    '<br>Estoque Oracle: <strong>' + qtd + '</strong> | ' +
    'Custo: <strong>R$ ' + parseFloat(custo||0).toFixed(2).replace('.', ',') + '</strong> | ' +
    'ABC: <strong>' + (abc || '-') + '</strong>';
  document.getElementById('add-locacao').value = locacao || '';
  document.getElementById('add-cont1').value   = 0;
  document.getElementById('add-erro').style.display = 'none';
  document.getElementById('overlay-add').classList.add('open');
  setTimeout(() => document.getElementById('add-locacao').focus(), 50);
}


function fecharModalAdd() {
  document.getElementById('overlay-add').classList.remove('open');
  itemSelecionado = null;
}


async function confirmarAdicao() {
  if (!itemSelecionado) return;


  const locacao = document.getElementById('add-locacao').value.trim();
  const cont1   = parseInt(document.getElementById('add-cont1').value) || 0;
  const erro    = document.getElementById('add-erro');


  const body = new URLSearchParams({
    inserir_item:     '1',
    empresa:          CTX_EMP,
    revenda:          CTX_REV,
    id_inventario:    CTX_INV,
    item_estoque_pub: itemSelecionado.ref,
    descricao:        itemSelecionado.desc,
    locacao:          locacao || '00.000.000.00.000',
    cont_1:           cont1,
    quantidade:       itemSelecionado.qtd,
    custo_entrada:    itemSelecionado.custo,
    class_abc:        itemSelecionado.abc,
  });


  try {
    const res   = await fetch('', { method: 'POST', body });
    const texto = await res.text();


    let data;
    try {
      data = JSON.parse(texto);
    } catch(e) {
      console.error('Resposta inválida ao inserir item:', texto);
      erro.textContent = 'Erro interno do servidor. Veja o console (F12).';
      erro.style.display = 'block';
      return;
    }


    if (data.ok) {
      fecharModalAdd();
      document.getElementById('ref-nova').value = '';
      location.reload();
    } else {
      erro.textContent = data.mensagem;
      erro.style.display = 'block';
    }
  } catch(err) {
    console.error('Erro ao inserir item:', err);
    erro.textContent = 'Falha na comunicação com o servidor.';
    erro.style.display = 'block';
  }
}


document.getElementById('overlay-add').addEventListener('click', function(e) {
  if (e.target === this) fecharModalAdd();
});
</script>
</body>
</html>
