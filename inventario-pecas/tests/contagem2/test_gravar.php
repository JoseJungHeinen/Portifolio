<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'contagem_2_lib.php';
require __DIR__ . '/assert.php';

echo "test_gravar\n";

$INV = 999999;
$EMP = 1;
$REV = 1;

$pdo->prepare("DELETE FROM estoque WHERE id_inventario = ?")->execute([$INV]);

$pdo->prepare("
    INSERT INTO estoque
        (id_inventario, empresa, revenda, item_estoque_pub, descricao_item_estoque,
         quantidade, cont_1, cont_2, locacao_zona, locacao_rua, locacao_estante,
         locacao_prateleira, locacao_numero, locacao_geral, situacao, coletor)
    VALUES
        (?, ?, ?, 'AT812', 'LIMPA FREIOS 500M',
         24, 12, 0, '2', '2', '1', 'A', '1', '2-2-1-A-1', 'A', 0)
")->execute([$INV, $EMP, $REV]);

$base = [
    'id_inventario'    => $INV,
    'empresa'          => $EMP,
    'revenda'          => $REV,
    'item_estoque_pub' => 'AT812',
    'locacao_geral'    => '2-2-1-A-1',
    'coletor'          => 3,
    'dupla_label'      => 'Joao e Maria',
    'entrada'          => 'bipado',
];

// ── Ainda diverge ──────────────────────────────────────────────────────────
$r = contagem2_gravar($pdo, $base + ['quantidade' => 18]);
t_true('gravou', $r['ok']);
t_eq('18 contra 24 ainda diverge', false, $r['bateu']);
t_null('primeira recontagem nao acusa dupla anterior', $r['recontado_antes_por']);

$linha = $pdo->query("SELECT cont_2, cont2_em, cont2_entrada, dupla_cont2, coletor
                        FROM estoque WHERE id_inventario = $INV AND item_estoque_pub = 'AT812'")->fetch();
t_eq('cont_2 guardou o valor digitado', 18, (int)$linha['cont_2']);
t_true('cont2_em foi carimbado', $linha['cont2_em'] !== null);
t_eq('entrada foi registrada', 'bipado', $linha['cont2_entrada']);
t_eq('a dupla ficou registrada', 'Joao e Maria', $linha['dupla_cont2']);
t_eq('o coletor ficou registrado', 3, (int)$linha['coletor']);

// ── Sobrescreve, nao soma ──────────────────────────────────────────────────
// O contagem_gravar.php soma (COALESCE(cont_2,0) + :qtd) porque a 1a contagem
// bipa a mesma prateleira varias vezes. Aqui e uma parada so por item.
$r2 = contagem2_gravar($pdo, $base + ['quantidade' => 24]);
t_true('24 contra 24 bateu', $r2['bateu']);
t_eq('recontou por cima, nao somou', 24, (int)$pdo->query(
    "SELECT cont_2 FROM estoque WHERE id_inventario = $INV AND item_estoque_pub = 'AT812'")->fetchColumn());
t_eq('a segunda recontagem sabe quem fez a primeira', 'Joao e Maria', $r2['recontado_antes_por']);

// ── Zero e resposta valida ─────────────────────────────────────────────────
$r3 = contagem2_gravar($pdo, array_merge($base, ['quantidade' => 0, 'entrada' => 'digitado']));
t_true('zero grava', $r3['ok']);
t_eq('zero foi para o banco', 0, (int)$pdo->query(
    "SELECT cont_2 FROM estoque WHERE id_inventario = $INV AND item_estoque_pub = 'AT812'")->fetchColumn());
t_true('zero continua marcado como recontado', $pdo->query(
    "SELECT cont2_em FROM estoque WHERE id_inventario = $INV AND item_estoque_pub = 'AT812'")->fetchColumn() !== null);
t_eq('codigo ilegivel deixa rastro', 'digitado', $pdo->query(
    "SELECT cont2_entrada FROM estoque WHERE id_inventario = $INV AND item_estoque_pub = 'AT812'")->fetchColumn());

// ── Recusas ────────────────────────────────────────────────────────────────
$neg = false;
try { contagem2_gravar($pdo, $base + ['quantidade' => -1]); } catch (InvalidArgumentException $e) { $neg = true; }
t_true('quantidade negativa e recusada', $neg);

$ent = false;
try { contagem2_gravar($pdo, array_merge($base, ['quantidade' => 5, 'entrada' => 'chutado'])); } catch (InvalidArgumentException $e) { $ent = true; }
t_true('entrada fora da lista e recusada', $ent);

$semItem = contagem2_gravar($pdo, array_merge($base, ['quantidade' => 5, 'item_estoque_pub' => 'NAOEXISTE']));
t_eq('item fora do inventario nao grava', false, $semItem['ok']);
t_eq('item fora do inventario diz por que', 'item_nao_encontrado', $semItem['motivo']);

// ── A locacao faz parte da chave ───────────────────────────────────────────
// O mesmo item pode estar em duas locacoes do mesmo inventario. O
// contagem_gravar.php sempre escopa por locacao_geral (linhas 147, 182, 242);
// sem isso, o LIMIT 1 grava numa linha arbitraria — a dupla conta o corredor 1
// e o numero vai parar no corredor 9, e a linha certa volta pra fila.
$pdo->prepare("DELETE FROM estoque WHERE id_inventario = ?")->execute([$INV]);

$ins = $pdo->prepare("
    INSERT INTO estoque
        (id_inventario, empresa, revenda, item_estoque_pub, descricao_item_estoque,
         quantidade, cont_1, cont_2, locacao_zona, locacao_rua, locacao_estante,
         locacao_prateleira, locacao_numero, locacao_geral, situacao, coletor)
    VALUES (?, ?, ?, 'DUPLO', 'ITEM EM DUAS LOCACOES', ?, 0, 0, '2', ?, '1', 'A', '1', ?, 'A', 0)
");
$ins->execute([$INV, $EMP, $REV, 10, '1', '2-1-1-A-1']);
$ins->execute([$INV, $EMP, $REV, 20, '9', '2-9-1-A-1']);

$dupl = [
    'id_inventario'    => $INV,
    'empresa'          => $EMP,
    'revenda'          => $REV,
    'item_estoque_pub' => 'DUPLO',
    'coletor'          => 3,
    'dupla_label'      => 'Joao e Maria',
    'entrada'          => 'bipado',
];

$rd = contagem2_gravar($pdo, array_merge($dupl, ['locacao_geral' => '2-9-1-A-1', 'quantidade' => 20]));
t_true('gravou na locacao pedida', $rd['ok']);
t_eq('comparou com o saldo DAQUELA locacao', 20, $rd['quantidade_sistema']);
t_true('20 contra 20 bateu naquela locacao', $rd['bateu']);

$outra = $pdo->query("SELECT cont_2, cont2_em FROM estoque
                       WHERE id_inventario = $INV AND locacao_geral = '2-1-1-A-1'")->fetch();
$certa = $pdo->query("SELECT cont_2, cont2_em FROM estoque
                       WHERE id_inventario = $INV AND locacao_geral = '2-9-1-A-1'")->fetch();

t_null('a outra locacao nao foi carimbada', $outra['cont2_em']);
t_eq('a outra locacao continua com cont_2 zerado', 0, (int)$outra['cont_2']);
t_eq('a locacao certa recebeu a quantidade', 20, (int)$certa['cont_2']);
t_true('a locacao certa foi carimbada', $certa['cont2_em'] !== null);

// Locacao que nao existe para esse item nao grava nada.
$semLoc = contagem2_gravar($pdo, array_merge($dupl, ['locacao_geral' => '9-9-9-Z-9', 'quantidade' => 5]));
t_eq('locacao errada nao grava', false, $semLoc['ok']);
t_eq('locacao errada diz por que', 'item_nao_encontrado', $semLoc['motivo']);

// Locacao ausente e erro de programacao, nao input do usuario: estoura.
$semNada = false;
try {
    contagem2_gravar($pdo, array_merge($dupl, ['quantidade' => 1]));
} catch (InvalidArgumentException $e) { $semNada = true; }
t_true('gravar sem locacao estoura', $semNada);

$pdo->prepare("DELETE FROM estoque WHERE id_inventario = ?")->execute([$INV]);

t_fim();
