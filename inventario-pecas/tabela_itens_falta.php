<?php
declare(strict_types=1);

session_start();
require 'conexao_inventario.php';

if (empty($_SESSION['inventario_login'])) {
    http_response_code(401);
    exit;
}

// mesma lógica de filtros do dashboard, mas versão simplificada
function getParam(string $key): ?string {
    return isset($_GET[$key]) && $_GET[$key] !== '' ? $_GET[$key] : null;
}

// LOCAÇÃO INICIAL
$zi = getParam('zi');
$ri = getParam('ri');
$ei = getParam('ei');
$pi = getParam('pi');
$ni = getParam('ni');

// LOCAÇÃO FINAL
$zf = getParam('zf');
$rf = getParam('rf');
$ef = getParam('ef');
$pf = getParam('pf');
$nf = getParam('nf');

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

$condLoc = '';
$params  = [];
if ($locIni !== '' && $locFim !== '') {
    $condLoc            = " AND locacao_geral BETWEEN :loc_ini AND :loc_fim ";
    $params[':loc_ini'] = $locIni;
    $params[':loc_fim'] = $locFim;
}

function fetchAllPrepared(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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
";
$itensFalta = fetchAllPrepared($pdo, $sqlFalta, $params);

// devolve apenas as linhas <tr> para o <tbody id="tbody-itens-falta">
foreach ($itensFalta as $linha) {
    $valorTotal = $linha['dif'] * $linha['valor_uni'];
    ?>
    <tr>
      <td><?= htmlspecialchars($linha['ref']) ?></td>
      <td><?= htmlspecialchars($linha['descricao']) ?></td>
      <td style="text-align:right;"><?= (int)$linha['qtd_estoque'] ?></td>
      <td style="text-align:right;"><?= (int)$linha['qtd_inv'] ?></td>
      <td style="text-align:right;"><?= (int)$linha['dif'] ?></td>
      <td style="text-align:right;"><?= number_format((float)$linha['valor_uni'], 2, ',', '.') ?></td>
      <td style="text-align:right;"><?= number_format((float)$valorTotal, 2, ',', '.') ?></td>
    </tr>
    <?php
}
