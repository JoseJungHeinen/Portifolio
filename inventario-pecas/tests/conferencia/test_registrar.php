<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'conferencia_lib.php';
require __DIR__ . '/assert.php';

echo "test_registrar\n";

// Inventario ficticio, fora da faixa real, para o teste nao sujar dado de producao.
$INV = 999999;
$pdo->prepare("DELETE FROM inv_conferencias WHERE id_inventario = ?")->execute([$INV]);

t_eq('comeca sem registro', false, conferencia_ja_conferido($pdo, $INV, 1, 1, '063806'));

$id1 = conferencia_registrar($pdo, [
    'id_inventario'    => $INV,
    'empresa'          => 1,
    'revenda'          => 1,
    'coletor'          => 3,
    'dupla_label'      => 'Joao e Maria',
    'item_estoque_pub' => '063806',
    'cod_item_estoque' => '322208',
    'descricao'        => 'BATERIA ELETR 72AH TORO 2.4 START STOP',
    'locacao_geral'    => '1-11-3-A-1',
    'saldo_oracle'     => 6,
    'origem'           => 'oracle',
    'status'           => 'ok',
    'entrada'          => 'bipado',
]);
t_true('primeiro registro devolve id', $id1 > 0);
t_true('agora esta conferido', conferencia_ja_conferido($pdo, $INV, 1, 1, '063806'));

// Outra loja do mesmo inventario nao herda o "ja conferido".
t_eq('outra revenda nao herda', false, conferencia_ja_conferido($pdo, $INV, 1, 5, '063806'));
// Outro item tambem nao.
t_eq('outro item nao herda', false, conferencia_ja_conferido($pdo, $INV, 1, 1, '002405'));
// Outro inventario tambem nao.
t_eq('outro inventario nao herda', false, conferencia_ja_conferido($pdo, 999998, 1, 1, '063806'));

// Segundo bipe do mesmo item gera uma segunda linha: o historico e por bipe.
$id2 = conferencia_registrar($pdo, [
    'id_inventario'    => $INV,
    'empresa'          => 1,
    'revenda'          => 1,
    'coletor'          => 3,
    'dupla_label'      => 'Joao e Maria',
    'item_estoque_pub' => '063806',
    'cod_item_estoque' => '322208',
    'descricao'        => 'BATERIA ELETR 72AH TORO 2.4 START STOP',
    'locacao_geral'    => '1-11-3-A-1',
    'saldo_oracle'     => 6,
    'origem'           => 'oracle',
    'status'           => 'ok',
    'entrada'          => 'bipado',
]);
t_true('segundo registro tem id diferente', $id2 > $id1);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM inv_conferencias WHERE id_inventario = ? AND item_estoque_pub = ?");
$stmt->execute([$INV, '063806']);
t_eq('duas linhas para dois bipes', 2, (int)$stmt->fetchColumn());

// Campos gravados.
$stmt = $pdo->prepare("SELECT * FROM inv_conferencias WHERE id = ?");
$stmt->execute([$id1]);
$linha = $stmt->fetch(PDO::FETCH_ASSOC);
t_eq('dupla gravada', 'Joao e Maria', $linha['dupla_label']);
t_eq('coletor gravado', 3, (int)$linha['coletor']);
t_eq('saldo gravado', 6, (int)$linha['saldo_oracle']);
t_eq('origem gravada', 'oracle', $linha['origem']);
t_eq('locacao gravada', '1-11-3-A-1', $linha['locacao_geral']);
t_true('conferido_em preenchido', !empty($linha['conferido_em']));

// Saldo desconhecido (fallback de snapshot) grava null, nao zero.
$id3 = conferencia_registrar($pdo, [
    'id_inventario'    => $INV,
    'empresa'          => 1,
    'revenda'          => 1,
    'coletor'          => 3,
    'dupla_label'      => 'Joao e Maria',
    'item_estoque_pub' => '999999',
    'cod_item_estoque' => '',
    'descricao'        => 'ITEM DE TESTE',
    'locacao_geral'    => '',
    'saldo_oracle'     => null,
    'origem'           => 'snapshot',
    'status'           => 'ok',
    'entrada'          => 'bipado',
]);
$stmt = $pdo->prepare("SELECT saldo_oracle, origem FROM inv_conferencias WHERE id = ?");
$stmt->execute([$id3]);
$linha3 = $stmt->fetch(PDO::FETCH_ASSOC);
t_null('saldo desconhecido grava null, nao zero', $linha3['saldo_oracle']);
t_eq('origem snapshot gravada', 'snapshot', $linha3['origem']);

// Saldo zero de verdade grava zero, nao null: zero e "nao sei" sao diferentes.
$id4 = conferencia_registrar($pdo, [
    'id_inventario'    => $INV,
    'empresa'          => 1,
    'revenda'          => 1,
    'coletor'          => 3,
    'dupla_label'      => 'Joao e Maria',
    'item_estoque_pub' => '888888',
    'cod_item_estoque' => '',
    'descricao'        => 'ITEM SEM SALDO',
    'locacao_geral'    => '',
    'saldo_oracle'     => 0,
    'origem'           => 'oracle',
    'status'           => 'ok',
    'entrada'          => 'bipado',
]);
$stmt = $pdo->prepare("SELECT saldo_oracle FROM inv_conferencias WHERE id = ?");
$stmt->execute([$id4]);
t_eq('saldo zero grava zero, nao null', 0, (int)$stmt->fetchColumn());

// ── Status: a decisao da dupla e o que da sentido ao registro ───────────────
$stmt = $pdo->prepare("SELECT status FROM inv_conferencias WHERE id = ?");
$stmt->execute([$id1]);
t_eq('status ok gravado', 'ok', $stmt->fetchColumn());

$idTroca = conferencia_registrar($pdo, [
    'id_inventario'    => $INV,
    'empresa'          => 1,
    'revenda'          => 1,
    'coletor'          => 3,
    'dupla_label'      => 'Joao e Maria',
    'item_estoque_pub' => '063806',
    'cod_item_estoque' => '322208',
    'descricao'        => 'BATERIA ELETR 72AH TORO 2.4 START STOP',
    'locacao_geral'    => '1-11-3-A-1',
    'saldo_oracle'     => 6,
    'origem'           => 'oracle',
    'status'           => 'troca',
    'entrada'          => 'bipado',
]);
$stmt->execute([$idTroca]);
t_eq('status troca gravado', 'troca', $stmt->fetchColumn());

// O mesmo item marcado troca e depois ok deixa as duas linhas: e assim que se
// enxerga que a locacao foi arrumada entre um bipe e outro.
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT status) FROM inv_conferencias WHERE id_inventario = ? AND item_estoque_pub = ?");
$stmt->execute([$INV, '063806']);
t_eq('o mesmo item guarda as duas decisoes', 2, (int)$stmt->fetchColumn());

// Status fora da lista nao entra no banco, mesmo que o endpoint falhe em filtrar.
$recusou = false;
try {
    conferencia_registrar($pdo, [
        'id_inventario'    => $INV,
        'empresa'          => 1,
        'revenda'          => 1,
        'item_estoque_pub' => '555555',
        'saldo_oracle'     => 1,
        'origem'           => 'oracle',
        'status'           => 'invencao',
        'entrada'          => 'bipado',
    ]);
} catch (InvalidArgumentException $e) {
    $recusou = true;
}
t_true('status fora da lista e recusado', $recusou);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM inv_conferencias WHERE item_estoque_pub = '555555'");
$stmt->execute();
t_eq('e nada foi gravado', 0, (int)$stmt->fetchColumn());

// Status ausente tambem e recusado: a decisao nao tem padrao silencioso.
$recusouVazio = false;
try {
    conferencia_registrar($pdo, [
        'id_inventario'    => $INV,
        'empresa'          => 1,
        'revenda'          => 1,
        'item_estoque_pub' => '444444',
        'saldo_oracle'     => 1,
        'origem'           => 'oracle',
        'entrada'          => 'bipado',
    ]);
} catch (InvalidArgumentException $e) {
    $recusouVazio = true;
}
t_true('status ausente e recusado', $recusouVazio);

// ── Entrada: como o codigo chegou, bipado ou digitado ───────────────────────
// E o rastro do botao "Codigo ilegivel": sem ele, digitar de proposito seria
// invisivel.
$stmt = $pdo->prepare("SELECT entrada FROM inv_conferencias WHERE id = ?");
$stmt->execute([$id1]);
t_eq('entrada bipado gravada', 'bipado', $stmt->fetchColumn());

$idDig = conferencia_registrar($pdo, [
    'id_inventario'    => $INV,
    'empresa'          => 1,
    'revenda'          => 1,
    'item_estoque_pub' => '063806',
    'saldo_oracle'     => 6,
    'origem'           => 'oracle',
    'status'           => 'ok',
    'entrada'          => 'digitado',
]);
$stmt->execute([$idDig]);
t_eq('entrada digitado gravada', 'digitado', $stmt->fetchColumn());

$recusouEntrada = false;
try {
    conferencia_registrar($pdo, [
        'id_inventario'    => $INV,
        'empresa'          => 1,
        'revenda'          => 1,
        'item_estoque_pub' => '333333',
        'origem'           => 'oracle',
        'status'           => 'ok',
        'entrada'          => 'teclado',
    ]);
} catch (InvalidArgumentException $e) {
    $recusouEntrada = true;
}
t_true('entrada fora da lista e recusada', $recusouEntrada);

// Sem entrada tambem e recusado: nas linhas novas, nulo seria esconder o rastro.
$recusouSemEntrada = false;
try {
    conferencia_registrar($pdo, [
        'id_inventario'    => $INV,
        'empresa'          => 1,
        'revenda'          => 1,
        'item_estoque_pub' => '222222',
        'origem'           => 'oracle',
        'status'           => 'ok',
    ]);
} catch (InvalidArgumentException $e) {
    $recusouSemEntrada = true;
}
t_true('entrada ausente e recusada', $recusouSemEntrada);

$stmt = $pdo->query("SELECT COUNT(*) FROM inv_conferencias WHERE item_estoque_pub IN ('333333','222222')");
t_eq('nenhuma das duas foi gravada', 0, (int)$stmt->fetchColumn());

// Limpeza.
$pdo->prepare("DELETE FROM inv_conferencias WHERE id_inventario = ?")->execute([$INV]);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM inv_conferencias WHERE id_inventario = ?");
$stmt->execute([$INV]);
t_eq('teste limpou o que criou', 0, (int)$stmt->fetchColumn());

t_fim();
