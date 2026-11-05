<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'conferencia_lib.php';
require __DIR__ . '/assert.php';

echo "test_ajuste_registro\n";

// Inventario ficticio, fora da faixa real.
$INV = 999999;
$pdo->prepare("DELETE FROM inv_ajustes_locacao WHERE id_inventario = ?")->execute([$INV]);

$base = [
    'id_inventario'    => $INV,
    'empresa'          => 1,
    'revenda'          => 1,
    'coletor'          => 3,
    'dupla_label'      => 'Joao e Maria',
    'item_estoque_pub' => '063806',
    'cod_item_estoque' => '322208',
    'locacao_anterior' => '1-11-3-A-1',
    'locacao_nova'     => '1-22-13-A-1',
    'entrada'          => 'bipado',
];

$id = conferencia_registrar_ajuste($pdo, $base);
t_true('registro do ajuste devolve id', $id > 0);

$stmt = $pdo->prepare("SELECT * FROM inv_ajustes_locacao WHERE id = ?");
$stmt->execute([$id]);
$l = $stmt->fetch(PDO::FETCH_ASSOC);
t_eq('inventario gravado', $INV, (int)$l['id_inventario']);
t_eq('dupla gravada', 'Joao e Maria', $l['dupla_label']);
t_eq('item gravado', '063806', $l['item_estoque_pub']);
t_eq('locacao anterior gravada', '1-11-3-A-1', $l['locacao_anterior']);
t_eq('locacao nova gravada', '1-22-13-A-1', $l['locacao_nova']);
t_eq('entrada gravada', 'bipado', $l['entrada']);
t_true('horario preenchido', !empty($l['ajustado_em']));

// Digitado pelo botao "Etiqueta ilegivel": e o caso que o rastro existe para mostrar.
$idDig = conferencia_registrar_ajuste($pdo, array_merge($base, ['entrada' => 'digitado']));
$stmt->execute([$idDig]);
t_eq('entrada digitado gravada', 'digitado', $stmt->fetch(PDO::FETCH_ASSOC)['entrada']);

// Recusas.
$recusa = function (array $troca) use ($pdo, $base): bool {
    try {
        conferencia_registrar_ajuste($pdo, array_merge($base, $troca));
        return false;
    } catch (InvalidArgumentException $e) {
        return true;
    }
};
t_true('entrada fora da lista e recusada', $recusa(['entrada' => 'teclado']));
t_true('entrada ausente e recusada',       $recusa(['entrada' => '']));
t_true('locacao nova vazia e recusada',    $recusa(['locacao_nova' => '']));
t_true('locacao anterior vazia e recusada', $recusa(['locacao_anterior' => '']));
t_true('item vazio e recusado',            $recusa(['item_estoque_pub' => '']));

$stmt = $pdo->prepare("SELECT COUNT(*) FROM inv_ajustes_locacao WHERE id_inventario = ?");
$stmt->execute([$INV]);
t_eq('so as duas validas foram gravadas', 2, (int)$stmt->fetchColumn());

// Limpeza.
$pdo->prepare("DELETE FROM inv_ajustes_locacao WHERE id_inventario = ?")->execute([$INV]);
$stmt->execute([$INV]);
t_eq('teste limpou o que criou', 0, (int)$stmt->fetchColumn());

t_fim();
