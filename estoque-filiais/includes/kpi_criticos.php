<?php
// kpi_criticos.php
// Usa a MESMA regra de crítico do estoque.php.

// 1) Saldos atuais por produto e filial
$saldos_raw = $pdo->query("
    SELECT produto_id, filial_id, quantidade 
      FROM estoque
")->fetchAll(PDO::FETCH_ASSOC);

$saldos = []; // [produto_id][filial_id] => quantidade
foreach ($saldos_raw as $s) {
    $pid = (int)$s['produto_id'];
    $fid = (int)$s['filial_id'];
    $saldos[$pid][$fid] = (int)$s['quantidade'];
}

// 2) Média mensal de CONSUMO (saídas) por produto E FILIAL nos últimos 3 meses
$medias_raw = $pdo->query("
    SELECT
        produto_id,
        filial_id,
        AVG(consumo_mes) AS media_mensal
    FROM (
        SELECT 
            produto_id,
            filial_id,
            YEAR(data_movimento)  AS ano,
            MONTH(data_movimento) AS mes,
            SUM(quantidade)       AS consumo_mes
        FROM movimentacoes_estoque
        WHERE tipo = 'S'
          AND data_movimento >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH)
        GROUP BY 
            produto_id,
            filial_id,
            YEAR(data_movimento),
            MONTH(data_movimento)
    ) t
    GROUP BY 
        produto_id,
        filial_id
")->fetchAll(PDO::FETCH_ASSOC);

$medias = []; // [produto_id][filial_id] => media_mensal
foreach ($medias_raw as $m) {
    $pid = (int)$m['produto_id'];
    $fid = (int)$m['filial_id'];
    $medias[$pid][$fid] = (float)$m['media_mensal'];
}

// 3) Percorre produtos e filiais usando a mesma condição de crítico do estoque.php
$produtos_raw = $pdo->query("SELECT id FROM produtos")->fetchAll(PDO::FETCH_ASSOC);
$filiais_raw  = $pdo->query("SELECT id FROM filiais")->fetchAll(PDO::FETCH_ASSOC);

$total_itens_criticos = 0;

foreach ($produtos_raw as $p) {
    $pid = (int)$p['id'];
    $criticoProduto = false;

    foreach ($filiais_raw as $f) {
        $fid = (int)$f['id'];

        $qtd = $saldos[$pid][$fid] ?? 0;
        $mediaFilial = $medias[$pid][$fid] ?? 0;

        // MESMA regra: crítico se saldo da filial <= média mensal da filial + 1
        if ($mediaFilial > 0 && $qtd <= $mediaFilial + 1) {
            $criticoProduto = true;
            break; // já conta esse produto uma vez
        }
    }

    if ($criticoProduto) {
        $total_itens_criticos++;
    }
}
