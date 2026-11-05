<?php
session_start();
require 'conexao_inventario.php';

// obriga login
if (empty($_SESSION['inventario_login'])) {
    header('Location: login.php');
    exit;
}

// grupos de coletores (configurados no gerador / usados no login)
$coletoresGrupos = $_SESSION['coletores_grupos'] ?? [];

// *** CAPTURA codigo e inv para o botão Sair ***
$codigo = $_GET['codigo'] ?? '';
$inv    = $_GET['inv']    ?? '';

function labelColetor(int $num, array $grupos): string {
    $base = 'Coletor ' . $num;
    if (!empty($grupos[$num])) {
        $base .= ' - ' . implode(' e ', $grupos[$num]);
    }
    return $base;
}

/**
 * Lê um parâmetro numérico (string) e mantém como string.
 */
function getStrParam(string $key): ?string {
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }
    $val = preg_replace('/\D/', '', $_GET[$key]);
    return $val === '' ? null : $val;
}

/**
 * Lê um parâmetro numérico simples (int).
 */
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

// --------- FILTROS DE LOCAÇÃO (iguais ao dashboard) ---------

// LOCAÇÃO INICIAL
$zi = getStrParam('zi');
$ri = getStrParam('ri');
$ei = getStrParam('ei');
$pi = getStrParam('pi');
$ni = getStrParam('ni');

// LOCAÇÃO FINAL
$zf = getStrParam('zf');
$rf = getStrParam('rf');
$ef = getStrParam('ef');
$pf = getStrParam('pf');
$nf = getStrParam('nf');

$locIni = '';
$locFim = '';

if ($zi !== null) {
    $locIni =
        str_pad($zi, 2, '0', STR_PAD_LEFT) . '.' .
        str_pad($ri !== null ? $ri : '0',   3, '0', STR_PAD_LEFT) . '.' .
        str_pad($ei !== null ? $ei : '0',   3, '0', STR_PAD_LEFT) . '.' .
        str_pad($pi !== null ? $pi : '0',   2, '0', STR_PAD_LEFT) . '.' .
        str_pad($ni !== null ? $ni : '0',   3, '0', STR_PAD_LEFT);
}
if ($zf !== null) {
    $locFim =
        str_pad($zf, 2, '0', STR_PAD_LEFT) . '.' .
        str_pad($rf !== null ? $rf : '999', 3, '0', STR_PAD_LEFT) . '.' .
        str_pad($ef !== null ? $ef : '999', 3, '0', STR_PAD_LEFT) . '.' .
        str_pad($pf !== null ? $pf : '99',  2, '0', STR_PAD_LEFT) . '.' .
        str_pad($nf !== null ? $nf : '999', 3, '0', STR_PAD_LEFT);
}

// --------- FILTRO POR ID DA CONTAGEM (inv_inventarios.id) ---------

// ID escolhido no dashboard (vem pela URL)
$idInventario = getIntParam('id_inventario');
if ($idInventario === null) {
    $idInventario = getIntParam('inv');
}

// condição de locação usada nas queries de estoque
$condLoc = '';
$paramsEstoque = [];
if ($locIni !== '' && $locFim !== '') {
    $condLoc                   = " AND locacao_geral BETWEEN :loc_ini AND :loc_fim ";
    $paramsEstoque[':loc_ini'] = $locIni;
    $paramsEstoque[':loc_fim'] = $locFim;
}

// condição por id_inventario na tabela ESTOQUE
$condIdInventario = '';
if ($idInventario !== null) {
    $condIdInventario = " AND id_inventario = :id_inventario ";
    $paramsEstoque[':id_inventario'] = $idInventario;
}

// cabeçalho do inventário (opcional)
$inventarioCab = [];
if ($idInventario !== null) {
    $sqlCab = "
        SELECT id, empresa, revenda, descricao, data_inicio, data_fim, status
        FROM inv_inventarios 
        WHERE id = :id
    ";
    $inventarioCab = fetchOnePrepared($pdo, $sqlCab, [':id' => $idInventario]);
}

// =============================
// DADOS REAIS DA TABELA ESTOQUE
// =============================

// 1) Itens a menor
$sqlFalta = "
    SELECT
        cod_item_estoque        AS ref,
        descricao_item_estoque  AS descricao,
        quantidade              AS qtd_estoque,
        cont_1                  AS qtd_inv,
        (cont_1 - quantidade)   AS dif,
        custo_entrada           AS valor_uni
    FROM estoque
    WHERE cont_1 IS NOT NULL
      AND cont_1 < quantidade
      $condLoc
      $condIdInventario
";
$itensFalta = fetchAllPrepared($pdo, $sqlFalta, $paramsEstoque);

// 2) Itens em sobra
$sqlSobra = "
    SELECT
        cod_item_estoque        AS ref,
        descricao_item_estoque  AS descricao,
        quantidade              AS qtd_estoque,
        cont_1                  AS qtd_inv,
        (cont_1 - quantidade)   AS dif,
        custo_entrada           AS valor_uni
    FROM estoque
    WHERE cont_1 IS NOT NULL
      AND cont_1 > quantidade
      $condLoc
      $condIdInventario
";
$itensSobra = fetchAllPrepared($pdo, $sqlSobra, $paramsEstoque);

// 3) Totais gerais
$sqlEntrada = "
    SELECT
        COALESCE(SUM(quantidade), 0)                 AS total_qtd,
        COALESCE(SUM(quantidade * custo_entrada),0)  AS total_valor
    FROM estoque
    WHERE 1=1
      $condLoc
      $condIdInventario
";
$rowEntrada        = fetchOnePrepared($pdo, $sqlEntrada, $paramsEstoque);
$entradaTotalQtd   = (int)$rowEntrada['total_qtd'];
$entradaTotalValor = (float)$rowEntrada['total_valor'];

// 4) Totais faltas
$sqlTotFalta = "
    SELECT
        COALESCE(SUM(ABS(cont_1 - quantidade)), 0)               AS qtd,
        COALESCE(SUM(ABS(cont_1 - quantidade)*custo_entrada), 0) AS valor
    FROM estoque
    WHERE cont_1 IS NOT NULL
      AND cont_1 < quantidade
      $condLoc
      $condIdInventario
";
$rowFalta        = fetchOnePrepared($pdo, $sqlTotFalta, $paramsEstoque);
$pecasFaltaQtd   = (int)$rowFalta['qtd'];
$pecasFaltaValor = (float)$rowFalta['valor'];

// 5) Totais sobras
$sqlTotSobra = "
    SELECT
        COALESCE(SUM(cont_1 - quantidade), 0)                     AS qtd,
        COALESCE(SUM((cont_1 - quantidade)*custo_entrada), 0)     AS valor
    FROM estoque
    WHERE cont_1 IS NOT NULL
      AND cont_1 > quantidade
      $condLoc
      $condIdInventario
";
$rowSobra        = fetchOnePrepared($pdo, $sqlTotSobra, $paramsEstoque);
$pecasSobraQtd   = (int)$rowSobra['qtd'];
$pecasSobraValor = (float)$rowSobra['valor'];

// 6) Percentual concluído
$sqlPerc = "
    SELECT
        COALESCE(SUM(quantidade), 0) AS qtd_estoque,
        COALESCE(SUM(cont_1),    0) AS qtd_invent
    FROM estoque
    WHERE 1=1
      $condLoc
      $condIdInventario
";
$rowPerc           = fetchOnePrepared($pdo, $sqlPerc, $paramsEstoque);
$totalEstoque      = (int)$rowPerc['qtd_estoque'];
$totalInventariado = (int)$rowPerc['qtd_invent'];

$percentualConcluido = 0;
if ($totalEstoque > 0) {
    $percentualConcluido = round(($totalInventariado / $totalEstoque) * 100);
    if ($percentualConcluido < 0) {
        $percentualConcluido = 0;
    } elseif ($percentualConcluido > 100) {
        $percentualConcluido = 100;
    }
}

// 6.1) Locação inicial/final efetivamente inventariadas
$sqlLocExtremos = "
    SELECT
        MIN(locacao_geral) AS loc_ini_real,
        MAX(locacao_geral) AS loc_fim_real
    FROM estoque
    WHERE 1=1
      $condLoc
      $condIdInventario
";
$rowLocExt  = fetchOnePrepared($pdo, $sqlLocExtremos, $paramsEstoque);
$locIniReal = $rowLocExt['loc_ini_real'] ?? '';
$locFimReal = $rowLocExt['loc_fim_real'] ?? '';

// 7) Contagens por coletor
$sqlColetor = "
    SELECT coletor, COUNT(*) AS qtd
    FROM estoque
    WHERE 1=1
      $condLoc
      $condIdInventario
    GROUP BY coletor
";
$contagensColetor = [];
foreach (fetchAllPrepared($pdo, $sqlColetor, $paramsEstoque) as $r) {
    $contagensColetor[(int)$r['coletor']] = (int)$r['qtd'];
}

// cabeçalho
$tituloRelatorio = 'Relatório de Inventário';

if (!empty($inventarioCab)) {
    $subtituloRelatorio = $inventarioCab['descricao'];
} else {
    $subtituloRelatorio = 'Locação de ' . $locIni . ' até ' . $locFim;
    if ($idInventario !== null) {
        $subtituloRelatorio .= ' | Inventário ID: ' . $idInventario;
    }
}

$dataAtual     = date('d/m/Y H:i');
$usuarioLogado = $_SESSION['inventario_login']['usuario'] ?? '';

// monta URL do botão Sair
$hrefSair = 'dashboard_inventario.php'
    . '?codigo=' . urlencode($codigo)
    . '&idinventario=' . urlencode($idInventario ?? 0)  // <-- sem underline
    . '&zi=' . urlencode($zi ?? '')
    . '&ri=' . urlencode($ri ?? '')
    . '&ei=' . urlencode($ei ?? '')
    . '&pi=' . urlencode($pi ?? '')
    . '&ni=' . urlencode($ni ?? '')
    . '&zf=' . urlencode($zf ?? '')
    . '&rf=' . urlencode($rf ?? '')
    . '&ef=' . urlencode($ef ?? '')
    . '&pf=' . urlencode($pf ?? '')
    . '&nf=' . urlencode($nf ?? '');

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
        }
        * { box-sizing: border-box; }
        body {
            font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            font-size: 11px;
            margin: 0;
            padding: 24px;
            background: white;
            color: var(--gray-700);
        }
        .page { max-width: 1024px; margin: 0 auto; }

        .orc-header-top{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:32px;
            margin-bottom:4px;
        }
        .orc-logo-bloco img{
            width:100px;
            height:auto;
            display:block;
            margin-bottom:6px;
        }
        .orc-header-center{
            flex:1;
            text-align:center;
            font-family:Arial,Helvetica,sans-serif;
            margin-left:-80px;
        }
        .orc-header-title{
            font-size:16pt;
            font-weight:bold;
            margin-bottom:2px;
        }
        .orc-header-sub{
            font-size:9pt;
            margin-top:2px;
            margin-bottom:2px;
        }
        .orc-header-line{
            height:1px;
            background:#cccccc;
            margin:6px 0 10px 0;
            width:100%;
        }
        .orc-header-right{
            line-height:1.5;
            font-size:8pt;
        }

        h2 {
            font-size:13px;
            margin:18px 0 8px;
            font-weight:600;
        }
        .filtros {
            margin:10px 0 4px;
            padding:6px 10px;
            border-radius:4px;
            background: var(--gray-50);
            border:1px solid var(--gray-200);
            font-size:10px;
        }
        .filtros span { margin-right:18px; }

        table {
            width:100%;
            border-collapse:collapse;
            font-size:10px;
            margin-bottom:14px;
        }
        th, td {
            border:1px solid var(--border);
            padding:4px 6px;
        }
        th {
            background:var(--gray-100);
            font-weight:600;
        }
        tbody tr:nth-child(odd) { background:#FCFCFC; }
        .text-right { text-align:right; }

        .badge-percent {
            display:inline-block;
            padding:2px 6px;
            border-radius:12px;
            background:var(--primary);
            color:#fff;
            font-weight:600;
            font-size:10px;
        }
        .print-btn {
            padding: 4px 10px;
            border-radius: 12px;
            border: 1px solid var(--primary);
            background: #fff;
            color: var(--primary);
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .08em;
            cursor: pointer;
            margin-right: 6px;
            margin-top: 4px;
        }
        .section-toggle { display: none; }
        @media print {
            .print-btn { display: none; }
        }
    </style>
</head>
<body>
<div class="page">

    <div class="orc-header-top">
        <div class="orc-logo-bloco">
            <img src="/inventario/img/logo.svg" alt="Logo">
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
        <span><strong>Locação final real:</strong> <?php echo htmlspecialchars($locFimReal); ?></span>
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
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Empresa</th>
                <th>Revenda</th>
                <th>Descrição</th>
                <th>Início</th>
                <th>Fim</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td><?php echo (int)$inventarioCab['id']; ?></td>
                <td><?php echo htmlspecialchars($inventarioCab['empresa']); ?></td>
                <td><?php echo htmlspecialchars($inventarioCab['revenda']); ?></td>
                <td><?php echo htmlspecialchars($inventarioCab['descricao']); ?></td>
                <td><?php echo htmlspecialchars($inventarioCab['data_inicio']); ?></td>
                <td><?php echo htmlspecialchars($inventarioCab['data_fim'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($inventarioCab['status']); ?></td>
            </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <div style="margin: 10px 0;">
        <button type="button" class="print-btn" onclick="toggleSection('sec-coletor')">
            Contagens por coletor
        </button>
        <button type="button" class="print-btn" onclick="toggleSection('sec-falta')">
            Itens a menor
        </button>
        <button type="button" class="print-btn" onclick="toggleSection('sec-sobra')">
            Itens com sobra
        </button>

        <!-- botão SAIR: volta ao dashboard com loja + inventário + filtros de locação -->
        <button type="button" class="print-btn"
                onclick="window.location.href='<?=
                    htmlspecialchars($hrefSair, ENT_QUOTES, 'UTF-8')
                ?>'">
            Sair
        </button>
    </div>

    <h2>Resumo geral</h2>
    <table>
        <thead>
        <tr>
            <th>Tipo</th>
            <th class="text-right">Quantidade</th>
            <th class="text-right">Valor total (R$)</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td>Entrada total (estoque)</td>
            <td class="text-right"><?php echo (int)$entradaTotalQtd; ?></td>
            <td class="text-right"><?php echo number_format($entradaTotalValor, 2, ',', '.'); ?></td>
        </tr>
        <tr>
            <td>Peças faltantes</td>
            <td class="text-right"><?php echo (int)$pecasFaltaQtd; ?></td>
            <td class="text-right"><?php echo number_format($pecasFaltaValor, 2, ',', '.'); ?></td>
        </tr>
        <tr>
            <td>Peças sobrando</td>
            <td class="text-right"><?php echo (int)$pecasSobraQtd; ?></td>
            <td class="text-right"><?php echo number_format($pecasSobraValor, 2, ',', '.'); ?></td>
        </tr>
        </tbody>
    </table>

    <div id="sec-coletor" class="section-toggle">
        <h2>Contagens por coletor</h2>
        <table>
            <thead>
            <tr>
                <th>Coletor</th>
                <th class="text-right">Qtde</th>
            </tr>
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
        <table>
            <thead>
            <tr>
                <th>Referência</th>
                <th>Descrição</th>
                <th class="text-right">Qtd estoque</th>
                <th class="text-right">Qtd inventário</th>
                <th class="text-right">Diferença</th>
                <th class="text-right">Valor uni. (R$)</th>
                <th class="text-right">Valor total (R$)</th>
            </tr>
            </thead>
            <?php
            $totalQtdFalta   = 0;
            $totalValorFalta = 0.0;
            ?>
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
                <th colspan="4" class="text-right">Totais:</th>
                <th class="text-right"><?php echo (int)$totalQtdFalta; ?></th>
                <th></th>
                <th class="text-right"><?php echo number_format($totalValorFalta, 2, ',', '.'); ?></th>
            </tr>
            </tfoot>
        </table>
    </div>

    <div id="sec-sobra" class="section-toggle">
        <h2>Itens com sobra</h2>
        <table>
            <thead>
            <tr>
                <th>Referência</th>
                <th>Descrição</th>
                <th class="text-right">Qtd estoque</th>
                <th class="text-right">Qtd inventário</th>
                <th class="text-right">Diferença</th>
                <th class="text-right">Valor uni. (R$)</th>
                <th class="text-right">Valor total (R$)</th>
            </tr>
            </thead>
            <?php
            $totalQtdSobra   = 0;
            $totalValorSobra = 0.0;
            ?>
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
                <th colspan="4" class="text-right">Totais:</th>
                <th class="text-right"><?php echo (int)$totalQtdSobra; ?></th>
                <th></th>
                <th class="text-right"><?php echo number_format($totalValorSobra, 2, ',', '.'); ?></th>
            </tr>
            </tfoot>
        </table>
    </div>

</div>

<script>
function toggleSection(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'block' : 'none';
}
</script>
</body>
</html>
