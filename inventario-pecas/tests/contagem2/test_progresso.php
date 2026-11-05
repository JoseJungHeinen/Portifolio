<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'contagem_2_lib.php';
require __DIR__ . '/assert.php';

echo "test_progresso\n";

$INV = 999999;
$EMP = 1;
$REV = 1;

$pdo->prepare("DELETE FROM estoque WHERE id_inventario = ?")->execute([$INV]);

$inserir = function (string $pub, string $situacao) use ($pdo, $INV, $EMP, $REV) {
    $pdo->prepare("
        INSERT INTO estoque
            (id_inventario, empresa, revenda, item_estoque_pub, descricao_item_estoque,
             quantidade, cont_1, cont_2, locacao_zona, locacao_rua, locacao_estante,
             locacao_prateleira, locacao_numero, locacao_geral, situacao, coletor)
        VALUES (?, ?, ?, ?, 'ITEM DE TESTE', 5, 0, 0, '1', '1', '1', 'A', '1', '1-1-1-A-1', ?, 0)
    ")->execute([$INV, $EMP, $REV, $pub, $situacao]);
};

// Inventario vazio: nao pode dar divisao por zero.
$p = contagem2_progresso_primeira($pdo, $INV, $EMP, $REV);
t_eq('inventario sem linha: total zero', 0, $p['total']);
t_eq('inventario sem linha: 0%',       0.0, $p['pct']);

// 4 itens, 3 registrados.
$inserir('A1', 'C');
$inserir('A2', 'C');
$inserir('A3', 'C');
$inserir('A4', 'A');

$p = contagem2_progresso_primeira($pdo, $INV, $EMP, $REV);
t_eq('total conta todas as linhas',      4, $p['total']);
t_eq('registrados conta as situacao C',  3, $p['registrados']);
t_eq('nunca tocados conta as situacao A', 1, $p['nunca_tocados']);
t_eq('pct com uma casa decimal',      75.0, $p['pct']);

// Tudo registrado.
$pdo->prepare("UPDATE estoque SET situacao = 'C' WHERE id_inventario = ?")->execute([$INV]);
$p = contagem2_progresso_primeira($pdo, $INV, $EMP, $REV);
t_eq('tudo contado da 100%', 100.0, $p['pct']);
t_eq('e nenhum nunca tocado',     0, $p['nunca_tocados']);

// Nada registrado.
$pdo->prepare("UPDATE estoque SET situacao = 'A' WHERE id_inventario = ?")->execute([$INV]);
$p = contagem2_progresso_primeira($pdo, $INV, $EMP, $REV);
t_eq('nada contado da 0%', 0.0, $p['pct']);
t_eq('e todos nunca tocados', 4, $p['nunca_tocados']);

// Descartado nao e "nunca tocado" nem "registrado", mas conta no total: a linha
// existe e alguem decidiu sobre ela.
$pdo->prepare("UPDATE estoque SET situacao = 'D' WHERE id_inventario = ? AND item_estoque_pub = 'A4'")
    ->execute([$INV]);
$p = contagem2_progresso_primeira($pdo, $INV, $EMP, $REV);
t_eq('descartado nao entra em nunca tocados', 3, $p['nunca_tocados']);
t_eq('mas continua no total',                 4, $p['total']);

$pdo->prepare("DELETE FROM estoque WHERE id_inventario = ?")->execute([$INV]);

t_fim();
