<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require __DIR__ . '/assert.php';

echo "test_tabela\n";

$colunas = [];
foreach ($pdo->query("SHOW COLUMNS FROM inv_conferencias") as $c) {
    $colunas[$c['Field']] = $c['Type'];
}

foreach ([
    'id', 'id_inventario', 'empresa', 'revenda', 'coletor', 'dupla_label',
    'item_estoque_pub', 'cod_item_estoque', 'descricao', 'locacao_geral',
    'saldo_oracle', 'origem', 'status', 'entrada', 'conferido_em',
] as $col) {
    t_true("coluna $col existe", isset($colunas[$col]));
}

// entrada aceita nulo: as linhas de antes da trava nao tem como saber.
$stmt = $pdo->query("SHOW COLUMNS FROM inv_conferencias WHERE Field = 'entrada'");
$entrada = $stmt->fetch(PDO::FETCH_ASSOC);
t_eq('entrada aceita nulo para o historico', 'YES', $entrada['Null'] ?? null);

// Rastro dos ajustes de locacao.
$colAjuste = [];
foreach ($pdo->query("SHOW COLUMNS FROM inv_ajustes_locacao") as $c) {
    $colAjuste[$c['Field']] = $c;
}
foreach ([
    'id', 'id_inventario', 'empresa', 'revenda', 'coletor', 'dupla_label',
    'item_estoque_pub', 'cod_item_estoque', 'locacao_anterior', 'locacao_nova',
    'entrada', 'ajustado_em',
] as $col) {
    t_true("inv_ajustes_locacao.$col existe", isset($colAjuste[$col]));
}
// No ajuste, entrada e obrigatoria: a tabela nasceu junto com a trava.
t_eq('entrada do ajuste e obrigatoria', 'NO', $colAjuste['entrada']['Null'] ?? null);

$indices = [];
foreach ($pdo->query("SHOW INDEX FROM inv_conferencias") as $i) {
    $indices[$i['Key_name']] = true;
}
t_true('indice idx_inv_item existe', isset($indices['idx_inv_item']));
t_true('indice idx_inv_data existe', isset($indices['idx_inv_data']));
t_true('indice idx_inv_status existe', isset($indices['idx_inv_status']));
t_true('indice idx_inv_entrada existe', isset($indices['idx_inv_entrada']));

t_fim();
