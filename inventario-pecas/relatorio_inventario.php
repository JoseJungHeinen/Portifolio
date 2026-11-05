<?php
session_start();
require 'conexao_inventario.php';
require_once 'recontagem_lib.php';

if (empty($_SESSION['inventario_login'])) {
    header('Location: login.php');
    exit;
}

$coletoresGrupos = $_SESSION['coletores_grupos'] ?? [];

$codigo = $_GET['codigo'] ?? '';
$inv    = $_GET['inv']    ?? '';

function labelColetor(int $num, array $grupos): string {
    if (!empty($grupos[$num])) {
        return implode(' e ', $grupos[$num]);
    }
    return 'Coletor ' . $num;
}

function getStrParam(string $key): ?string {
    if (!isset($_GET[$key]) || $_GET[$key] === '') return null;
    $val = preg_replace('/\D/', '', $_GET[$key]);
    return $val === '' ? null : $val;
}

function getIntParam(string $key): ?int {
    if (!isset($_GET[$key]) || $_GET[$key] === '') return null;
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

function carregarColetoresInventario(PDO $pdo, ?int $idInventario): array {
    if (!$idInventario) return [];
    $row = fetchOnePrepared($pdo,
        "SELECT coletores_json FROM inv_inventarios WHERE id = :id LIMIT 1",
        [':id' => $idInventario]
    );
    if (empty($row['coletores_json'])) return [];
    $grupos = json_decode((string)$row['coletores_json'], true);
    return is_array($grupos) ? $grupos : [];
}

// LOCAÇÃO
$zi = getStrParam('zi'); $ri = getStrParam('ri'); $ei = getStrParam('ei');
$pi = getStrParam('pi'); $ni = getStrParam('ni');
$zf = getStrParam('zf'); $rf = getStrParam('rf'); $ef = getStrParam('ef');
$pf = getStrParam('pf'); $nf = getStrParam('nf');

$locIni = '';
$locFim = '';

if ($zi !== null) {
    $locIni =
        str_pad($zi, 2, '0', STR_PAD_LEFT) . '.' .
        str_pad($ri ?? '0',   3, '0', STR_PAD_LEFT) . '.' .
        str_pad($ei ?? '0',   3, '0', STR_PAD_LEFT) . '.' .
        str_pad($pi ?? '0',   2, '0', STR_PAD_LEFT) . '.' .
        str_pad($ni ?? '0',   3, '0', STR_PAD_LEFT);
}
if ($zf !== null) {
    $locFim =
        str_pad($zf, 2, '0', STR_PAD_LEFT) . '.' .
        str_pad($rf ?? '999', 3, '0', STR_PAD_LEFT) . '.' .
        str_pad($ef ?? '999', 3, '0', STR_PAD_LEFT) . '.' .
        str_pad($pf ?? '99',  2, '0', STR_PAD_LEFT) . '.' .
        str_pad($nf ?? '999', 3, '0', STR_PAD_LEFT);
}

$idInventario = getIntParam('id_inventario') ?? getIntParam('inv');

$coletoresDoInventario = carregarColetoresInventario($pdo, $idInventario);
if ($coletoresDoInventario !== []) {
    $coletoresGrupos = $coletoresDoInventario;
}

$condLoc = '';
$paramsEstoque = [];
if ($locIni !== '' && $locFim !== '') {
    $condLoc = " AND locacao_geral BETWEEN :loc_ini AND :loc_fim ";
    $paramsEstoque[':loc_ini'] = $locIni;
    $paramsEstoque[':loc_fim'] = $locFim;
}

$condIdInventario = '';
if ($idInventario !== null) {
    $condIdInventario = " AND id_inventario = :id_inventario ";
    $paramsEstoque[':id_inventario'] = $idInventario;
}

// Quantidade que vale: a última contagem feita (recontagem inclusa), ou a
// decisão da Tomada de Decisão. Ver recontagem_sql_contagem_final().
$cf = recontagem_sql_contagem_final(recontagem_tem_decisoes($pdo));

$inventarioCab = [];
if ($idInventario !== null) {
    $inventarioCab = fetchOnePrepared($pdo,
        "SELECT id, empresa, revenda, descricao, data_inicio, data_fim, status
         FROM inv_inventarios WHERE id = :id",
        [':id' => $idInventario]
    );
}

// ── QUERIES ──────────────────────────────────────────────────────────────────

// Itens a menor (para tabela de detalhes)
$itensFalta = fetchAllPrepared($pdo, "
    SELECT item_estoque_pub AS ref,
           descricao_item_estoque AS descricao,
           locacao_geral AS locacao,
           class_abc,
           quantidade AS qtd_estoque,
           {$cf} AS qtd_inv,
           ({$cf} - quantidade) AS dif,
           custo_entrada AS valor_uni
    FROM estoque
    WHERE {$cf} IS NOT NULL
      AND {$cf} < quantidade
      $condLoc
      $condIdInventario
    ORDER BY ABS({$cf} - quantidade) * custo_entrada DESC
", $paramsEstoque);

// Itens com sobra (para tabela de detalhes)
$itensSobra = fetchAllPrepared($pdo, "
    SELECT item_estoque_pub AS ref,
           descricao_item_estoque AS descricao,
           locacao_geral AS locacao,
           class_abc,
           quantidade AS qtd_estoque,
           {$cf} AS qtd_inv,
           ({$cf} - quantidade) AS dif,
           custo_entrada AS valor_uni
    FROM estoque
    WHERE {$cf} IS NOT NULL
      AND {$cf} > quantidade
      $condLoc
      $condIdInventario
    ORDER BY ({$cf} - quantidade) * custo_entrada DESC
", $paramsEstoque);

// Entrada total (estoque)
$rowEntrada = fetchOnePrepared($pdo, "
    SELECT COALESCE(SUM(quantidade),0) AS total_qtd,
           COALESCE(SUM(quantidade * custo_entrada),0) AS total_valor
    FROM estoque
    WHERE 1=1
      $condLoc
      $condIdInventario
", $paramsEstoque);

$entradaTotalQtd   = (int)$rowEntrada['total_qtd'];
$entradaTotalValor = (float)$rowEntrada['total_valor'];

// Peças com sobra = soma apenas dos itens com sobra
$rowSobra = fetchOnePrepared($pdo, "
    SELECT COALESCE(SUM({$cf} - quantidade),0) AS qtd,
           COALESCE(SUM(({$cf} - quantidade) * custo_entrada),0) AS valor
    FROM estoque
    WHERE {$cf} IS NOT NULL
      AND {$cf} > quantidade
      $condLoc
      $condIdInventario
", $paramsEstoque);

$pecasSobraQtd   = (int)$rowSobra['qtd'];
$pecasSobraValor = (float)$rowSobra['valor'];

// Peças com Diferença = soma apenas dos itens a menor
$rowFalta = fetchOnePrepared($pdo, "
    SELECT COALESCE(SUM(quantidade - {$cf}),0) AS qtd,
           COALESCE(SUM((quantidade - {$cf}) * custo_entrada),0) AS valor
    FROM estoque
    WHERE {$cf} IS NOT NULL
      AND {$cf} < quantidade
      $condLoc
      $condIdInventario
", $paramsEstoque);

$faltantesQtd   = (int)$rowFalta['qtd'];
$faltantesValor = (float)$rowFalta['valor'] * -1;

// Peças contadas e valor contado
$rowContadas = fetchOnePrepared($pdo, "
    SELECT COALESCE(SUM(COALESCE({$cf},0)),0) AS qtd_contada,
           COALESCE(SUM(COALESCE({$cf},0) * custo_entrada),0) AS valor_contado
    FROM estoque
    WHERE 1=1
      $condLoc
      $condIdInventario
", $paramsEstoque);

$pecasContadasQtd  = (int)$rowContadas['qtd_contada'];
$valorContadoTotal = (float)$rowContadas['valor_contado'];

// ── NOVO: Peças não contadas (tem estoque mas inventário = 0 ou NULL) ────────
$rowNaoContadas = fetchOnePrepared($pdo, "
    SELECT COALESCE(SUM(quantidade),0) AS qtd,
           COALESCE(SUM(quantidade * custo_entrada),0) AS valor,
           COUNT(*) AS itens
    FROM estoque
    WHERE quantidade > 0
      AND ({$cf} IS NULL OR {$cf} = 0)
      $condLoc
      $condIdInventario
", $paramsEstoque);

$naoContadasQtd    = (int)$rowNaoContadas['qtd'];
$naoContadasValor  = (float)$rowNaoContadas['valor'];
$naoContadasItens  = (int)$rowNaoContadas['itens'];

// ── Contadores por itens (registros) ─────────────────────────────────────────
$rowItensDistintos = fetchOnePrepared($pdo, "
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN {$cf} IS NOT NULL AND ({$cf} > 0 OR quantidade = 0) THEN 1 ELSE 0 END) AS contados,
           SUM(CASE WHEN quantidade > 0 AND ({$cf} IS NULL OR {$cf} = 0) THEN 1 ELSE 0 END) AS nao_contados,
           SUM(CASE WHEN {$cf} IS NOT NULL AND {$cf} = quantidade THEN 1 ELSE 0 END) AS corretos,
           SUM(CASE WHEN {$cf} IS NOT NULL AND {$cf} <> quantidade THEN 1 ELSE 0 END) AS divergentes
    FROM estoque
    WHERE 1=1
      $condLoc
      $condIdInventario
", $paramsEstoque);

$itensTotal       = (int)$rowItensDistintos['total'];
$itensContados    = (int)$rowItensDistintos['contados'];
$itensNaoContados = (int)$rowItensDistintos['nao_contados'];
$itensCorretos    = (int)$rowItensDistintos['corretos'];
$itensDivergentes = (int)$rowItensDistintos['divergentes'];

// ── Acuracidade por itens (corretos / contados) ─────────────────────────────
$acuracidade = 0;
if ($itensContados > 0) {
    $acuracidade = round(($itensCorretos / $itensContados) * 100, 2);
}

// ── Percentual de itens contados vs total ────────────────────────────────────
$percentualItensContados = 0;
if ($itensTotal > 0) {
    $percentualItensContados = round(($itensContados / $itensTotal) * 100, 2);
}

// ── NOVO: Lista detalhada dos itens não contados ─────────────────────────────
$itensNaoContadosLista = fetchAllPrepared($pdo, "
    SELECT item_estoque_pub AS ref,
           descricao_item_estoque AS descricao,
           locacao_geral AS locacao,
           class_abc,
           quantidade AS qtd_estoque,
           custo_entrada AS valor_uni,
           (quantidade * custo_entrada) AS valor_total
    FROM estoque
    WHERE quantidade > 0
      AND ({$cf} IS NULL OR {$cf} = 0)
      $condLoc
      $condIdInventario
    ORDER BY (quantidade * custo_entrada) DESC
", $paramsEstoque);

// Resultado = sobra - falta
$resultadoQtd   = $pecasSobraQtd - $faltantesQtd;
$resultadoValor = $pecasSobraValor + $faltantesValor;

// Percentual concluído por VALOR (mesmo do dashboard)
$percentualConcluido = 0;
if ($entradaTotalValor > 0) {
    $percentualConcluido = min(100, max(0, (int)round(($valorContadoTotal / $entradaTotalValor) * 100)));
}

$rowLocExt  = fetchOnePrepared($pdo, "SELECT MIN(locacao_geral) AS loc_ini_real, MAX(locacao_geral) AS loc_fim_real FROM estoque WHERE 1=1 $condLoc $condIdInventario", $paramsEstoque);
$locIniReal = $rowLocExt['loc_ini_real'] ?? '';
$locFimReal = $rowLocExt['loc_fim_real'] ?? '';

$contagensColetor = [];
foreach (fetchAllPrepared($pdo, "SELECT coletor, COUNT(*) AS qtd FROM estoque WHERE 1=1 $condLoc $condIdInventario GROUP BY coletor", $paramsEstoque) as $r) {
    $contagensColetor[(int)$r['coletor']] = (int)$r['qtd'];
}

$tituloRelatorio = 'Relatório de Inventário';
$dataAtual       = date('d/m/Y H:i');
$usuarioLogado   = $_SESSION['inventario_login']['usuario'] ?? '';

$hrefSair = 'dashboard_inventario.php'
    . '?codigo=' . urlencode($codigo)
    . '&idinventario=' . urlencode($idInventario ?? 0)
    . '&zi=' . urlencode($zi ?? '') . '&ri=' . urlencode($ri ?? '')
    . '&ei=' . urlencode($ei ?? '') . '&pi=' . urlencode($pi ?? '')
    . '&ni=' . urlencode($ni ?? '') . '&zf=' . urlencode($zf ?? '')
    . '&rf=' . urlencode($rf ?? '') . '&ef=' . urlencode($ef ?? '')
    . '&pf=' . urlencode($pf ?? '') . '&nf=' . urlencode($nf ?? '');

// Itens faltando no resultado cruzado
$itensResultadoCruzado = fetchAllPrepared($pdo, "
    SELECT
        item_estoque_pub AS ref,
        MAX(descricao_item_estoque) AS descricao,
        MAX(class_abc) AS class_abc,
        SUM(quantidade) AS qtd_estoque,
        SUM(COALESCE({$cf}, 0)) AS qtd_inv,
        SUM(COALESCE({$cf}, 0) - quantidade) AS dif,
        MAX(custo_entrada) AS valor_uni,
        ABS(SUM((COALESCE({$cf}, 0) - quantidade) * custo_entrada)) AS valor_total
    FROM estoque
    WHERE 1=1
      $condLoc
      $condIdInventario
    GROUP BY item_estoque_pub
    HAVING SUM(COALESCE({$cf}, 0) - quantidade) < 0
    ORDER BY ABS(SUM((COALESCE({$cf}, 0) - quantidade) * custo_entrada)) DESC
", $paramsEstoque);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($tituloRelatorio); ?></title>
    <style>
        :root {
            --primary: #2347C8;
            --gray-50: #FAFAFA;
            --gray-100: #F5F5F5;
            --gray-200: #E0E0E0;
            --gray-400: #9E9E9E;
            --gray-700: #424242;
            --border: #DDDDDD;
            --green: #2E7D32;
            --amber: #F57F17;
        }
        * { box-sizing: border-box; }
        body {
            font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            font-size: 11px; margin: 0; padding: 24px;
            background: white; color: var(--gray-700);
        }
        .page { max-width: 1024px; margin: 0 auto; }
        .orc-header-top {
            display: flex; align-items: flex-start;
            justify-content: space-between; gap: 32px; margin-bottom: 4px;
        }
        .orc-logo-bloco img { width: 100px; height: auto; display: block; margin-bottom: 6px; }
        .orc-header-center {
            flex: 1; text-align: center;
            font-family: Arial, Helvetica, sans-serif; margin-left: -80px;
        }
        .orc-header-title { font-size: 16pt; font-weight: bold; margin-bottom: 2px; }
        .orc-header-line { height: 1px; background: #cccccc; margin: 6px 0 10px 0; width: 100%; }
        .orc-header-right { line-height: 1.5; font-size: 8pt; }
        h2 { font-size: 13px; margin: 18px 0 8px; font-weight: 600; }
        .filtros {
            margin: 10px 0 4px; padding: 6px 10px; border-radius: 4px;
            background: var(--gray-50); border: 1px solid var(--gray-200); font-size: 10px;
        }
        .filtros span { margin-right: 18px; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 14px; }
        th, td { border: 1px solid var(--border); padding: 4px 6px; }
        th { background: var(--gray-100); font-weight: 600; text-align: center; }
        .sort-head { position: relative; padding: 0 !important; }
        .sort-btn {
            width: 100%; display: flex; align-items: center; justify-content: center;
            gap: 4px; border: 0; background: transparent; color: inherit;
            font: inherit; font-weight: 600; cursor: pointer; padding: 5px 6px; white-space: nowrap;
        }
        .sort-btn:hover { background: #fce4e4; color: var(--primary); }
        .sort-head.is-active { background: #fff1f2; color: var(--primary); }
        .sort-head.is-active .sort-btn { color: var(--primary); }
        .sort-arrow { font-size: 10px; opacity: 0.35; line-height: 1; transition: opacity 0.15s; }
        .sort-head.is-active .sort-arrow, .sort-btn:hover .sort-arrow { opacity: 1; }
        .sort-menu {
            display: none; position: absolute; top: calc(100% + 2px);
            left: 50%; transform: translateX(-50%); min-width: 160px;
            background: #fff; border: 1px solid #ccc; border-radius: 4px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.18); z-index: 100;
            padding: 2px 0; font-weight: normal;
        }
        .sort-head.is-open .sort-menu { display: block; }
        .sort-menu-item {
            display: flex; align-items: center; gap: 8px; width: 100%;
            border: 0; background: transparent; text-align: left;
            font-size: 11px; color: #333; padding: 6px 12px; cursor: pointer; white-space: nowrap;
        }
        .sort-menu-item:hover { background: #f0f0f0; }
        .sort-menu-item.active { background: #fff1f2; color: var(--primary); font-weight: 600; }
        .sort-menu-item .menu-icon { font-size: 12px; width: 14px; text-align: center; }
        .sort-menu-separator { height: 1px; background: #e0e0e0; margin: 2px 0; }
        .sort-col-active { background: #fff8f8; }
        tfoot th.text-left { text-align: left; }
        tbody tr:nth-child(odd) { background: #FCFCFC; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        .badge-percent {
            display: inline-block; padding: 2px 6px; border-radius: 12px;
            background: var(--primary); color: #fff; font-weight: 600; font-size: 10px;
        }
        .badge-green {
            display: inline-block; padding: 2px 6px; border-radius: 12px;
            background: var(--green); color: #fff; font-weight: 600; font-size: 10px;
        }
        .badge-amber {
            display: inline-block; padding: 2px 6px; border-radius: 12px;
            background: var(--amber); color: #fff; font-weight: 600; font-size: 10px;
        }
        .print-btn {
            padding: 4px 10px; border-radius: 12px;
            border: 1px solid var(--primary); background: #fff;
            color: var(--primary); font-size: 10px; font-weight: 600;
            text-transform: uppercase; letter-spacing: .08em;
            cursor: pointer; margin-right: 6px; margin-top: 4px;
        }
        .section-toggle { display: none; }

        /* ── Cards de indicadores ── */
        .indicadores-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin: 10px 0 14px;
        }
        .indicador-card {
            border: 1px solid var(--gray-200);
            border-radius: 6px;
            padding: 10px 12px;
            background: var(--gray-50);
            text-align: center;
        }
        .indicador-card .ic-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--gray-400);
            margin-bottom: 4px;
        }
        .indicador-card .ic-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-700);
            line-height: 1.2;
        }
        .indicador-card .ic-sub {
            font-size: 9px;
            color: var(--gray-400);
            margin-top: 2px;
        }
        .ic-value.red { color: var(--primary); }
        .ic-value.green { color: var(--green); }
        .ic-value.amber { color: var(--amber); }

        @page {
            size: A4;
            margin: 12mm 10mm;
        }

        @media print {
            :root {
                --border: #999999;
                --gray-100: #efefef;
                --gray-700: #000000;
            }

            body {
                font-family: Georgia, "Times New Roman", Times, serif;
                font-size: 11pt;
                line-height: 1.3;
                margin: 0;
                padding: 0;
                background: #fff !important;
                color: #000 !important;
            }

            .page {
                max-width: 100%;
                margin: 0;
            }

            .print-btn,
            .sort-arrow,
            .sort-menu {
                display: none !important;
            }

            .section-toggle {
                display: none !important;
            }

            .section-toggle.is-open {
                display: block !important;
            }

            .orc-header-top {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 16px;
                margin-bottom: 6px;
            }

            .orc-logo-bloco img {
                width: 85px;
                margin-bottom: 2px;
            }

            .orc-header-center {
                margin-left: 0;
                text-align: center;
                font-family: Georgia, "Times New Roman", Times, serif;
            }

            .orc-header-title {
                font-size: 15pt;
                font-weight: 700;
                margin-bottom: 1px;
                color: #000;
            }

            .orc-header-line {
                background: #000;
                height: 1px;
                margin: 6px 0 8px 0;
            }

            .orc-header-right {
                font-size: 9pt;
                line-height: 1.35;
                color: #000;
            }

            h2 {
                font-size: 12pt;
                margin: 14px 0 6px;
                color: #000;
                page-break-after: avoid;
            }

            .filtros {
                margin: 8px 0 6px;
                padding: 5px 8px;
                border-radius: 0;
                background: #fff;
                border: 1px solid #000;
                font-size: 9pt;
                color: #000;
            }

            .filtros span {
                margin-right: 12px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 9pt;
                margin-bottom: 10px;
                page-break-inside: auto;
            }

            thead {
                display: table-header-group;
            }

            tfoot {
                display: table-row-group;
            }

            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            th,
            td {
                border: 1px solid #777;
                padding: 5px 6px;
                color: #000 !important;
                vertical-align: middle;
            }

            th {
                background: #efefef !important;
                font-weight: 700;
                text-align: center;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            tbody tr:nth-child(odd) {
                background: #fff !important;
            }

            .sort-head,
            .sort-col-active,
            .sort-head.is-active {
                background: transparent !important;
                color: #000 !important;
            }

            .sort-btn {
                display: block;
                width: 100%;
                padding: 4px 6px;
                text-align: center;
                background: transparent !important;
                color: #000 !important;
                cursor: default;
            }

            .badge-percent,
            .badge-green,
            .badge-amber {
                display: inline-block;
                padding: 1px 5px;
                border-radius: 0;
                background: #000 !important;
                color: #fff !important;
                font-size: 8pt;
                font-weight: 700;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .indicadores-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 8px;
                margin: 8px 0 10px;
            }

            .indicador-card {
                border: 1px solid #999;
                border-radius: 0;
                padding: 6px 8px;
                background: #fff;
            }

            .indicador-card .ic-value {
                font-size: 14pt;
                color: #000 !important;
            }

            .indicador-card .ic-value.red,
            .indicador-card .ic-value.green,
            .indicador-card .ic-value.amber {
                color: #000 !important;
            }

            .indicador-card .ic-label,
            .indicador-card .ic-sub {
                color: #333 !important;
            }

            .text-right { text-align: right; }
            .text-left { text-align: left; }
            .text-center { text-align: center; }

            h2,
            .filtros,
            table {
                break-inside: avoid;
            }

            .indicadores-grid {
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
<div class="page">

    <div class="orc-header-top">
        <div class="orc-logo-bloco">
            <img src="img/logo.svg" alt="Logo">
        </div>
        <div class="orc-header-center">
            <div class="orc-header-title"><?php echo htmlspecialchars($tituloRelatorio); ?></div>
        </div>
        <div class="orc-header-right">
            <div>Emissão: <?php echo $dataAtual; ?></div>
            <?php if ($usuarioLogado): ?>
                <div>Usuário: <?php echo htmlspecialchars($usuarioLogado); ?></div>
            <?php endif; ?>
            <button class="print-btn" onclick="window.print()">Imprimir</button>
        </div>
    </div>
    <div class="orc-header-line"></div>

    <div class="filtros">
        <span><strong>Locação inicial real:</strong> <?php echo htmlspecialchars($locIniReal); ?></span>
        <span><strong>Locação final real:</strong>   <?php echo htmlspecialchars($locFimReal); ?></span>
        <span><strong>Concluído:</strong> <span class="badge-percent"><?php echo (int)$percentualConcluido; ?>%</span></span>
        <?php if (!empty($inventarioCab)): ?>
            <span><strong>Inventário ID:</strong> <?php echo (int)$inventarioCab['id']; ?></span>
            <span><strong>Status:</strong> <?php echo htmlspecialchars($inventarioCab['status']); ?></span>
        <?php elseif ($idInventario !== null): ?>
            <span><strong>Inventário ID:</strong> <?php echo (int)$idInventario; ?></span>
        <?php endif; ?>
    </div>

    <?php if (!empty($inventarioCab)): ?>
        <h2>Dados do inventário</h2>
        <table id="tabela-inventario">
            <thead>
            <tr>
                <th>ID</th><th>Empresa</th><th>Revenda</th><th>Descrição</th>
                <th>Início</th><th>Fim</th><th>Status</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td class="text-center"><?php echo (int)$inventarioCab['id']; ?></td>
                <td><?php echo htmlspecialchars($inventarioCab['empresa']); ?></td>
                <td><?php echo htmlspecialchars($inventarioCab['revenda']); ?></td>
                <td><?php echo htmlspecialchars($inventarioCab['descricao']); ?></td>
                <td class="text-center"><?php echo htmlspecialchars($inventarioCab['data_inicio']); ?></td>
                <td class="text-center"><?php echo htmlspecialchars($inventarioCab['data_fim'] ?? ''); ?></td>
                <td class="text-center"><?php echo htmlspecialchars($inventarioCab['status']); ?></td>
            </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <div style="margin: 10px 0;">
        <button type="button" class="print-btn" onclick="toggleSection('sec-coletor')">Contagens por coletor</button>
        <button type="button" class="print-btn" onclick="toggleSection('sec-falta')">Itens Faltantes</button>
        <button type="button" class="print-btn" onclick="toggleSection('sec-sobra')">Itens com sobra</button>
        <button type="button" class="print-btn" onclick="toggleSection('sec-nao-contados')">Peças não contadas</button>
        <button type="button" class="print-btn"
                onclick="window.location.href='<?= htmlspecialchars($hrefSair, ENT_QUOTES, 'UTF-8') ?>'">
            Voltar
        </button>
    </div>

    <!-- ── INDICADORES RÁPIDOS ── -->
    <h2>Indicadores</h2>
    <div class="indicadores-grid">
        <div class="indicador-card">
            <div class="ic-label">Total de itens</div>
            <div class="ic-value"><?php echo number_format($itensTotal, 0, ',', '.'); ?></div>
            <div class="ic-sub">registros no estoque</div>
        </div>
        <div class="indicador-card">
            <div class="ic-label">Itens contados</div>
            <div class="ic-value green"><?php echo number_format($itensContados, 0, ',', '.'); ?></div>
            <div class="ic-sub"><?php echo $percentualItensContados; ?>% do total</div>
        </div>
        <div class="indicador-card">
            <div class="ic-label">Itens não contados</div>
            <div class="ic-value <?php echo $itensNaoContados > 0 ? 'amber' : ''; ?>"><?php echo number_format($itensNaoContados, 0, ',', '.'); ?></div>
            <div class="ic-sub"><?php echo $itensTotal > 0 ? number_format(round(($itensNaoContados / $itensTotal) * 100, 1), 1, ',', '.') : 0; ?>% pendente</div>
        </div>
        <div class="indicador-card">
            <div class="ic-label">Acuracidade</div>
            <div class="ic-value <?php echo $acuracidade >= 95 ? 'green' : ($acuracidade >= 80 ? 'amber' : 'red'); ?>"><?php echo number_format($acuracidade, 1, ',', '.'); ?>%</div>
            <div class="ic-sub"><?php echo number_format($itensCorretos, 0, ',', '.'); ?> corretos / <?php echo number_format($itensDivergentes, 0, ',', '.'); ?> divergentes</div>
        </div>
    </div>

    <!-- RESUMO GERAL -->
    <h2>Resumo geral</h2>
    <table id="tabela-resumo">
        <thead>
        <tr>
            <th>Tipo</th>
            <th>Quantidade</th>
            <th>Valor total (R$)</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td>Estoque total</td>
            <td class="text-right"><?php echo number_format($entradaTotalQtd, 0, ',', '.'); ?></td>
            <td class="text-right"><?php echo number_format($entradaTotalValor, 2, ',', '.'); ?></td>
        </tr>
        <tr>
            <td>Peças contadas</td>
            <td class="text-right"><?php echo number_format($pecasContadasQtd, 0, ',', '.'); ?></td>
            <td class="text-right"><?php echo number_format($valorContadoTotal, 2, ',', '.'); ?></td>
        </tr>
        <tr style="background: #FFF8E1;">
            <td>Peças não contadas</td>
            <td class="text-right" style="<?php echo $naoContadasQtd > 0 ? 'color:var(--amber); font-weight:600;' : ''; ?>">
                <?php echo number_format($naoContadasQtd, 0, ',', '.'); ?>
            </td>
            <td class="text-right" style="<?php echo $naoContadasValor > 0 ? 'color:var(--amber); font-weight:600;' : ''; ?>">
                <?php echo number_format($naoContadasValor, 2, ',', '.'); ?>
            </td>
        </tr>
        <tr>
            <td>Peças Faltantes</td>
            <td class="text-right" style="<?php echo $faltantesQtd > 0 ? 'color:var(--primary); font-weight:600;' : ''; ?>">
                <?php echo number_format($faltantesQtd, 0, ',', '.'); ?>
            </td>
            <td class="text-right" style="<?php echo $faltantesValor < 0 ? 'color:var(--primary); font-weight:600;' : ''; ?>">
                <?php echo number_format($faltantesValor, 2, ',', '.'); ?>
            </td>
        </tr>
        <tr>
            <td>Peças com sobra</td>
            <td class="text-right"><?php echo number_format($pecasSobraQtd, 0, ',', '.'); ?></td>
            <td class="text-right"><?php echo number_format($pecasSobraValor, 2, ',', '.'); ?></td>
        </tr>
        </tbody>
        <tfoot>
        <tr style="border-top:2px solid var(--gray-400);">
            <th class="text-left">Resultado</th>
            <th class="text-right" style="<?php echo $resultadoQtd < 0 ? 'color:var(--primary);' : ''; ?>">
                <?php echo number_format($resultadoQtd, 0, ',', '.'); ?>
            </th>
            <th class="text-right" style="<?php echo $resultadoValor < 0 ? 'color:var(--primary);' : ''; ?>">
                <?php echo number_format($resultadoValor, 2, ',', '.'); ?>
            </th>
        </tr>
        </tfoot>
    </table>

    <div id="sec-coletor" class="section-toggle">
        <h2>Contagens por coletor</h2>
        <table id="tabela-coletor">
            <thead>
            <tr><th>Coletor</th><th>Qtde</th></tr>
            </thead>
            <tbody>
            <?php foreach ($contagensColetor as $num => $qt): ?>
                <tr>
                    <td><?php echo htmlspecialchars(labelColetor((int)$num, $coletoresGrupos)); ?></td>
                    <td class="text-right"><?php echo (int)$qt; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="sec-falta" class="section-toggle">
        <h2>Itens com quantidade a menor</h2>
        <table id="tabela-falta">
            <thead>
            <tr>
                <th>Referência</th><th>Descrição</th><th>Locação</th>
                <th>Classificação ABC</th><th>Qtd estoque</th><th>Qtd inventário</th>
                <th>Diferença</th><th>Valor uni. (R$)</th><th>Valor total (R$)</th>
            </tr>
            </thead>
            <?php $totalQtdFalta = 0; $totalValorFalta = 0.0; ?>
            <tbody>
            <?php foreach ($itensFalta as $linha): ?>
                <?php
                    $valorTotal       = $linha['dif'] * $linha['valor_uni'];
                    $totalQtdFalta   += (int)$linha['dif'];
                    $totalValorFalta += (float)$valorTotal;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($linha['ref']); ?></td>
                    <td><?php echo htmlspecialchars($linha['descricao']); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($linha['locacao']); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($linha['class_abc']); ?></td>
                    <td class="text-right"><?php echo (int)$linha['qtd_estoque']; ?></td>
                    <td class="text-right"><?php echo (int)$linha['qtd_inv']; ?></td>
                    <td class="text-right"><?php echo (int)$linha['dif']; ?></td>
                    <td class="text-right"><?php echo number_format($linha['valor_uni'], 2, ',', '.'); ?></td>
                    <td class="text-right"><?php echo number_format($valorTotal, 2, ',', '.'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <th colspan="4" class="text-left">Totais:</th>
                <th></th><th></th>
                <th class="text-right"><?php echo (int)$totalQtdFalta; ?></th>
                <th></th>
                <th class="text-right"><?php echo number_format($totalValorFalta, 2, ',', '.'); ?></th>
            </tr>
            </tfoot>
        </table>
    </div>

    <div id="sec-sobra" class="section-toggle">
        <h2>Itens com sobra</h2>
        <table id="tabela-sobra">
            <thead>
            <tr>
                <th>Referência</th><th>Descrição</th><th>Locação</th>
                <th>Classificação ABC</th><th>Qtd estoque</th><th>Qtd inventário</th>
                <th>Diferença</th><th>Valor uni. (R$)</th><th>Valor total (R$)</th>
            </tr>
            </thead>
            <?php $totalQtdSobra = 0; $totalValorSobra = 0.0; ?>
            <tbody>
            <?php foreach ($itensSobra as $linha): ?>
                <?php
                    $valorTotal        = $linha['dif'] * $linha['valor_uni'];
                    $totalQtdSobra    += (int)$linha['dif'];
                    $totalValorSobra  += (float)$valorTotal;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($linha['ref']); ?></td>
                    <td><?php echo htmlspecialchars($linha['descricao']); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($linha['locacao']); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($linha['class_abc']); ?></td>
                    <td class="text-right"><?php echo (int)$linha['qtd_estoque']; ?></td>
                    <td class="text-right"><?php echo (int)$linha['qtd_inv']; ?></td>
                    <td class="text-right"><?php echo (int)$linha['dif']; ?></td>
                    <td class="text-right"><?php echo number_format($linha['valor_uni'], 2, ',', '.'); ?></td>
                    <td class="text-right"><?php echo number_format($valorTotal, 2, ',', '.'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <th colspan="4" class="text-left">Totais:</th>
                <th></th><th></th>
                <th class="text-right"><?php echo (int)$totalQtdSobra; ?></th>
                <th></th>
                <th class="text-right"><?php echo number_format($totalValorSobra, 2, ',', '.'); ?></th>
            </tr>
            </tfoot>
        </table>
    </div>

    <!-- ── NOVO: Seção de itens não contados ── -->
    <div id="sec-nao-contados" class="section-toggle">
        <h2>Peças não contadas</h2>
        <p style="font-size:10px; color:var(--gray-400); margin:0 0 6px;">
            <?php echo number_format($naoContadasQtd, 0, ',', '.'); ?> peças pendentes de contagem
            — valor em estoque: R$ <?php echo number_format($naoContadasValor, 2, ',', '.'); ?>
        </p>
        <table id="tabela-nao-contados">
            <thead>
            <tr>
                <th>Referência</th><th>Descrição</th><th>Locação</th>
                <th>Classificação ABC</th><th>Qtd estoque</th>
                <th>Valor uni. (R$)</th><th>Valor total (R$)</th>
            </tr>
            </thead>
            <?php $totalQtdNaoContados = 0; $totalValorNaoContados = 0.0; ?>
            <tbody>
            <?php foreach ($itensNaoContadosLista as $linha): ?>
                <?php
                    $totalQtdNaoContados   += (int)$linha['qtd_estoque'];
                    $totalValorNaoContados += (float)$linha['valor_total'];
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($linha['ref']); ?></td>
                    <td><?php echo htmlspecialchars($linha['descricao']); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($linha['locacao']); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($linha['class_abc']); ?></td>
                    <td class="text-right"><?php echo (int)$linha['qtd_estoque']; ?></td>
                    <td class="text-right"><?php echo number_format($linha['valor_uni'], 2, ',', '.'); ?></td>
                    <td class="text-right"><?php echo number_format($linha['valor_total'], 2, ',', '.'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <th colspan="3" class="text-left">Totais:</th>
                <th></th>
                <th class="text-right"><?php echo number_format($totalQtdNaoContados, 0, ',', '.'); ?></th>
                <th></th>
                <th class="text-right"><?php echo number_format($totalValorNaoContados, 2, ',', '.'); ?></th>
            </tr>
            </tfoot>
        </table>
    </div>

</div>
<script>
function toggleSection(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const shouldOpen = el.style.display === 'none' || el.style.display === '';
    el.style.display = shouldOpen ? 'block' : 'none';
    el.classList.toggle('is-open', shouldOpen);
}

const SORT_KEY = 'relatorioInventarioSort';
function loadSortState() { try { return JSON.parse(localStorage.getItem(SORT_KEY) || '{}'); } catch { return {}; } }
function saveSortState(tableId, state) { const all = loadSortState(); if (!state) delete all[tableId]; else all[tableId] = state; localStorage.setItem(SORT_KEY, JSON.stringify(all)); }
function getCellValue(row, index) { return row.children[index] ? row.children[index].textContent.trim() : ''; }
function toSortVal(value, type) { if (type === 'number') { const n = parseFloat(value.replace(/\./g, '').replace(',', '.').replace(/[^\d.-]/g, '')); return isNaN(n) ? -Infinity : n; } return value.toLocaleLowerCase('pt-BR'); }
function resetOrder(table) { const tbody = table.querySelector('tbody'); if (!tbody) return; Array.from(tbody.querySelectorAll('tr')).sort((a, b) => +a.dataset.originalIndex - +b.dataset.originalIndex).forEach(r => tbody.appendChild(r)); }

function applySort(tableId, colIndex, type, direction) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    table.querySelectorAll('thead th').forEach(th => { th.classList.remove('is-active'); const arrow = th.querySelector('.sort-arrow'); if (arrow) arrow.textContent = '▼'; });
    table.querySelectorAll('tbody td').forEach(td => td.classList.remove('sort-col-active'));
    if (!direction) { resetOrder(table); saveSortState(tableId, null); return; }
    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a, b) => { const va = toSortVal(getCellValue(a, colIndex), type); const vb = toSortVal(getCellValue(b, colIndex), type); if (va < vb) return direction === 'asc' ? -1 : 1; if (va > vb) return direction === 'asc' ? 1 : -1; return +a.dataset.originalIndex - +b.dataset.originalIndex; });
    rows.forEach(r => tbody.appendChild(r));
    const headers = table.querySelectorAll('thead th');
    if (headers[colIndex]) { headers[colIndex].classList.add('is-active'); const arrow = headers[colIndex].querySelector('.sort-arrow'); if (arrow) arrow.textContent = direction === 'asc' ? '▲' : '▼'; }
    table.querySelectorAll('tbody tr').forEach(row => { if (row.children[colIndex]) row.children[colIndex].classList.add('sort-col-active'); });
    saveSortState(tableId, { colIndex, type, direction });
}

function initSortableTable(tableId, columnTypes) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (tbody) Array.from(tbody.querySelectorAll('tr')).forEach((r, i) => r.dataset.originalIndex = i);
    table.querySelectorAll('thead th').forEach((th, colIndex) => {
        const type = columnTypes[colIndex]; if (!type) return;
        const label = th.textContent.trim(); th.innerHTML = ''; th.classList.add('sort-head');
        const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'sort-btn';
        btn.innerHTML = `<span>${label}</span><span class="sort-arrow">▼</span>`;
        btn.addEventListener('click', e => { e.stopPropagation(); document.querySelectorAll('.sort-head.is-open').forEach(h => { if (h !== th) h.classList.remove('is-open'); }); th.classList.toggle('is-open'); });
        th.appendChild(btn);
        const menu = document.createElement('div'); menu.className = 'sort-menu';
        const isNum = type === 'number';
        const options = isNum ? [{ dir:'asc', icon:'↑', label:'Classificar do menor para o maior' },{ dir:'desc', icon:'↓', label:'Classificar do maior para o menor' }] : [{ dir:'asc', icon:'↑', label:'Classificar de A a Z' },{ dir:'desc', icon:'↓', label:'Classificar de Z a A' }];
        options.forEach(opt => { const item = document.createElement('button'); item.type = 'button'; item.className = 'sort-menu-item'; item.innerHTML = `<span class="menu-icon">${opt.icon}</span>${opt.label}`; item.addEventListener('click', e => { e.stopPropagation(); th.classList.remove('is-open'); applySort(tableId, colIndex, type, opt.dir); menu.querySelectorAll('.sort-menu-item').forEach(i => i.classList.remove('active')); item.classList.add('active'); }); menu.appendChild(item); });
        const sep = document.createElement('div'); sep.className = 'sort-menu-separator'; menu.appendChild(sep);
        const clearBtn = document.createElement('button'); clearBtn.type = 'button'; clearBtn.className = 'sort-menu-item'; clearBtn.innerHTML = `<span class="menu-icon">✕</span>Limpar classificação`; clearBtn.addEventListener('click', e => { e.stopPropagation(); th.classList.remove('is-open'); menu.querySelectorAll('.sort-menu-item').forEach(i => i.classList.remove('active')); applySort(tableId, colIndex, type, ''); }); menu.appendChild(clearBtn);
        th.appendChild(menu);
    });
    const saved = loadSortState()[tableId];
    if (saved && Number.isInteger(saved.colIndex) && columnTypes[saved.colIndex]) { applySort(tableId, saved.colIndex, saved.type, saved.direction); }
}

initSortableTable('tabela-inventario',    ['number','text','text','text','text','text','text']);
initSortableTable('tabela-resumo',        ['text','number','number']);
initSortableTable('tabela-coletor',       ['text','number']);
initSortableTable('tabela-falta',         ['text','text','text','text','number','number','number','number','number']);
initSortableTable('tabela-sobra',         ['text','text','text','text','number','number','number','number','number']);
initSortableTable('tabela-nao-contados',  ['text','text','text','text','number','number','number']);
document.addEventListener('click', () => { document.querySelectorAll('.sort-head.is-open').forEach(h => h.classList.remove('is-open')); });
</script>
</body>
</html>
