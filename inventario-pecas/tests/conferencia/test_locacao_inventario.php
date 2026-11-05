<?php
declare(strict_types=1);

/**
 * A locação nova, gravada no Apollo, entra na hora no inventário aberto da
 * loja (estoque): os cinco campos e a locacao_geral.
 */

require __DIR__ . '/../recontagem/fixture.php';
require_once 'conferencia_lib.php';

echo "test_locacao_inventario\n";

const LI_INV_FECHADO = 999998;

$limpar = function () use ($pdo) {
    rc_limpar($pdo);
    $pdo->prepare("DELETE FROM estoque WHERE id_inventario = ?")->execute([LI_INV_FECHADO]);
    $pdo->prepare("DELETE FROM inv_inventarios WHERE id = ?")->execute([LI_INV_FECHADO]);
};

$limpar();

rc_inventario($pdo);   // RC_INV, aberto
$pdo->prepare("
    INSERT INTO inv_inventarios (id, empresa, revenda, descricao, data_inicio, status, rodada_atual, coletores_json)
    VALUES (?, ?, ?, 'TESTE FECHADO', NOW(), 'concluido', 1, '{}')
")->execute([LI_INV_FECHADO, RC_EMP, RC_REV]);

$idItem  = rc_linha($pdo, ['pub' => 'LOCTESTE1', 'loc' => '1-1-1-A-1', 'c1' => 3, 'sit' => 'C']);
$idOutro = rc_linha($pdo, ['pub' => 'LOCTESTE2', 'loc' => '1-1-1-A-1']);
// Linha que a Tomada de Decisão inseriu: sem cod_item_estoque, acha pelo código público.
$idSemCod = rc_linha($pdo, ['pub' => 'LOCTESTE3', 'loc' => '1-1-1-A-2']);
$pdo->prepare("UPDATE estoque SET cod_item_estoque = ? WHERE id_linha = ?")->execute(['900001', $idItem]);
$pdo->prepare("UPDATE estoque SET cod_item_estoque = ? WHERE id_linha = ?")->execute(['900002', $idOutro]);
$pdo->prepare("UPDATE estoque SET cod_item_estoque = NULL WHERE id_linha = ?")->execute([$idSemCod]);

// O mesmo item num inventário concluído e em outra loja: não mexe.
$pdo->prepare("
    INSERT INTO estoque (id_inventario, empresa, revenda, item_estoque_pub, cod_item_estoque, descricao_item_estoque,
                         quantidade, cont_1, cont_2, locacao_zona, locacao_geral, situacao, coletor)
    VALUES (?, ?, ?, 'LOCTESTE1', '900001', 'X', 1, 0, 0, '1', '1-1-1-A-1', 'A', 0)
")->execute([LI_INV_FECHADO, RC_EMP, RC_REV]);
$idFechado = (int)$pdo->lastInsertId();
$pdo->prepare("
    INSERT INTO estoque (id_inventario, empresa, revenda, item_estoque_pub, cod_item_estoque, descricao_item_estoque,
                         quantidade, cont_1, cont_2, locacao_zona, locacao_geral, situacao, coletor)
    VALUES (?, ?, 99, 'LOCTESTE1', '900001', 'X', 1, 0, 0, '1', '1-1-1-A-1', 'A', 0)
")->execute([RC_INV, RC_EMP]);
$idOutraLoja = (int)$pdo->lastInsertId();

$nova = ['zona' => '2', 'rua' => 15, 'estante' => 3, 'prateleira' => 'C', 'numero' => 4];

$n = conferencia_atualizar_locacao_inventario($pdo, RC_EMP, RC_REV, '900001', 'LOCTESTE1', $nova);
t_eq('uma linha atualizada', 1, $n);

$l = rc_ler($pdo, $idItem);
t_eq('locacao_geral nova', '2-15-3-C-4', trim((string)$l['locacao_geral']));
t_eq('zona',       '2', (string)$l['locacao_zona']);
t_eq('rua',        '15', (string)$l['locacao_rua']);
t_eq('estante',    '3', (string)$l['locacao_estante']);
t_eq('prateleira', 'C', (string)$l['locacao_prateleira']);
t_eq('numero',     '4', (string)$l['locacao_numero']);
t_eq('contagem continua na linha', 3, (int)$l['cont_1']);

t_eq('outro item nao muda',       '1-1-1-A-1', trim((string)rc_ler($pdo, $idOutro)['locacao_geral']));
t_eq('inventario concluido nao muda', '1-1-1-A-1', trim((string)rc_ler($pdo, $idFechado)['locacao_geral']));
t_eq('outra loja nao muda',       '1-1-1-A-1', trim((string)rc_ler($pdo, $idOutraLoja)['locacao_geral']));

// Sem cod_item_estoque na linha: acha pelo código público.
$n = conferencia_atualizar_locacao_inventario($pdo, RC_EMP, RC_REV, '900003', 'LOCTESTE3', $nova);
t_eq('linha sem codigo interno, pelo publico', 1, $n);
t_eq('locacao da linha sem codigo interno', '2-15-3-C-4', trim((string)rc_ler($pdo, $idSemCod)['locacao_geral']));

// Item que não está no inventário: nada a fazer, sem erro.
t_eq('item fora do inventario', 0, conferencia_atualizar_locacao_inventario($pdo, RC_EMP, RC_REV, '900009', 'NAOESTA', $nova));

// Código interno vazio não pode casar com toda linha sem código interno.
$pdo->prepare("UPDATE estoque SET locacao_geral = '1-1-1-A-2' WHERE id_linha = ?")->execute([$idSemCod]);
t_eq('codigo vazio e publico inexistente: nada', 0, conferencia_atualizar_locacao_inventario($pdo, RC_EMP, RC_REV, '', 'NAOESTA', $nova));
t_eq('linha sem codigo interno nao foi tocada', '1-1-1-A-2', trim((string)rc_ler($pdo, $idSemCod)['locacao_geral']));
t_eq('os dois codigos vazios: nada', 0, conferencia_atualizar_locacao_inventario($pdo, RC_EMP, RC_REV, '', '', $nova));

$pdo->prepare("DELETE FROM estoque WHERE id_linha = ?")->execute([$idOutraLoja]);
$limpar();

t_fim();
