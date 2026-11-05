<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'conferencia_lib.php';
require __DIR__ . '/assert.php';

echo "test_snapshot\n";

$lojas = conferencia_lojas_da_cidade($pdo, 1, 1); // Cidade A, pela Fiat

// Valor esperado lido por um caminho independente.
$stmt = $pdo->prepare("
    SELECT quantidade, locacao_geral, descricao_item_estoque
      FROM estoque
     WHERE id_inventario = 45 AND empresa = 1 AND revenda = 1 AND item_estoque_pub = '063806'
     LIMIT 1
");
$stmt->execute();
$esperado = $stmt->fetch(PDO::FETCH_ASSOC);
t_true('fixture do snapshot existe no inventario 45', $esperado !== false);

$r = conferencia_buscar_snapshot($pdo, 45, 1, 1, $lojas, '063806');

t_eq('origem e snapshot', 'snapshot', $r['origem']);
t_eq('codigo publico', '063806', $r['item']['item_estoque_pub']);
t_eq('descricao vem do snapshot', (string)$esperado['descricao_item_estoque'], $r['item']['descricao']);
t_eq('locacao vem do snapshot, sem espacos de CHAR', trim((string)$esperado['locacao_geral']), $r['item']['locacao_geral']);

t_eq('as duas lojas da cidade continuam listadas', 2, count($r['lojas']));
t_eq('loja atual traz a quantidade do snapshot', (int)$esperado['quantidade'], $r['lojas'][0]['saldo']);
t_null('a outra loja da cidade fica desconhecida, nao zero', $r['lojas'][1]['saldo']);

// Locacao por loja: o snapshot so conhece a da loja do inventario.
t_eq('loja atual traz a locacao do snapshot', trim((string)$esperado['locacao_geral']), $r['lojas'][0]['locacao']);
t_null('a locacao da outra loja e desconhecida, nao vazia', $r['lojas'][1]['locacao']);

// Sufixo de variante e zeros a mais do leitor (2026-09-28).
foreach (['063806-01', '063806001', '000063806', '000063806-01'] as $bipe) {
    $rb = conferencia_buscar_snapshot($pdo, 45, 1, 1, $lojas, $bipe);
    t_eq("bipe $bipe acha 063806 no snapshot", '063806', $rb['item']['item_estoque_pub'] ?? null);
}

// Item fora do snapshot desse inventario.
t_null('item fora do snapshot devolve null', conferencia_buscar_snapshot($pdo, 45, 1, 1, $lojas, 'ZZZNAOEXISTE'));

// Inventario que nao existe.
t_null('inventario inexistente devolve null', conferencia_buscar_snapshot($pdo, 999999, 1, 1, $lojas, '063806'));

// O snapshot nao conhece codigo alternativo: bipar o equivalente nao acha.
t_null('codigo equivalente nao esta no snapshot', conferencia_buscar_snapshot($pdo, 45, 1, 1, $lojas, '7091115'));

t_fim();
