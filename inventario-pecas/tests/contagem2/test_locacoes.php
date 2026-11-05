<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'contagem_2_lib.php';
require __DIR__ . '/assert.php';

echo "test_locacoes\n";

// Inventário fictício, fora da faixa real, para não sujar dado de produção.
$INV = 999999;
$EMP = 1;
$REV = 1;

$limpar = function () use ($pdo, $INV) {
    $pdo->prepare("DELETE FROM estoque WHERE id_inventario = ?")->execute([$INV]);
    $pdo->prepare("DELETE FROM inv_cont2_locacoes WHERE id_inventario = ?")->execute([$INV]);
};
$limpar();

$inserir = function (string $pub, int $qtd, int $c1, string $rua, string $num) use ($pdo, $INV, $EMP, $REV) {
    $pdo->prepare("
        INSERT INTO estoque
            (id_inventario, empresa, revenda, item_estoque_pub, descricao_item_estoque,
             quantidade, cont_1, cont_2, cont2_em,
             locacao_zona, locacao_rua, locacao_estante, locacao_prateleira, locacao_numero,
             locacao_geral, situacao, coletor)
        VALUES (?, ?, ?, ?, 'DESCRICAO DE TESTE', ?, ?, 0, NULL, '2', ?, '1', 'A', ?, ?, 'A', 0)
    ")->execute([$INV, $EMP, $REV, $pub, $qtd, $c1, $rua, $num, "2-$rua-1-A-$num"]);
};

$inserir('R1A', 5, 3, '1', '1');   // 2-1-1-A-1  menor
$inserir('R1B', 1, 4, '1', '1');   // 2-1-1-A-1  sobra
$inserir('R1C', 2, 0, '1', '2');   // 2-1-1-A-2  menor
$inserir('R2A', 9, 1, '2', '1');   // 2-2-1-A-1  menor
$inserir('OK',  3, 3, '3', '1');   // 2-3-1-A-1  bateu, não é divergente

// ── A lista que o gestor marca ─────────────────────────────────────────────
$lista = contagem2_locacoes_divergentes($pdo, $INV, $EMP, $REV);
t_eq('so locacoes divergentes, na ordem de caminhada',
     ['2-1-1-A-1', '2-1-1-A-2', '2-2-1-A-1'], array_column($lista, 'locacao_geral'));
t_eq('conta os itens da locacao', 2, $lista[0]['itens']);
t_eq('separa menor e sobra', [1, 1], [$lista[0]['menor'], $lista[0]['maior']]);
t_eq('traz a rua para agrupar', '1', $lista[0]['rua']);

// ── Sem escolha gravada, a fila leva todas ─────────────────────────────────
t_eq('sem escolha, a fila tem todos os divergentes', 4, count(contagem2_fila($pdo, $INV, $EMP, $REV)));
t_eq('sem escolha, nada escolhido', [], contagem2_locacoes_escolhidas($pdo, $INV, $EMP, $REV));

// ── Com escolha, a fila e os totais ficam só nela ──────────────────────────
t_eq('grava so as locacoes e coletores validos', 3,
     contagem2_definir_locacoes($pdo, $INV, $EMP, $REV, [
         '2-1-1-A-1' => 1,
         '2-1-1-A-2' => 2,
         '2-2-1-A-1' => '2',     // chega como texto do JSON do navegador
         '9-9-9-Z-9' => 1,       // locacao inventada
         '2-3-1-A-1' => 1,       // sem divergencia
     ]));
t_eq('coletor fora de 1..8 nao entra', 0,
     contagem2_definir_locacoes($pdo, $INV, $EMP, $REV, ['2-1-1-A-1' => 9, '2-1-1-A-2' => 0]));

contagem2_definir_locacoes($pdo, $INV, $EMP, $REV, ['2-1-1-A-2' => 1, '2-2-1-A-1' => 2]);
t_eq('escolhidas trazem o coletor',
     [['locacao_geral' => '2-1-1-A-2', 'coletor' => 1], ['locacao_geral' => '2-2-1-A-1', 'coletor' => 2]],
     contagem2_locacoes_escolhidas($pdo, $INV, $EMP, $REV));

$fila = array_column(contagem2_fila($pdo, $INV, $EMP, $REV), 'item_estoque_pub');
t_eq('sem coletor, fila com todas as locacoes escolhidas', ['R1C', 'R2A'], $fila);
t_eq('coletor 1 so recebe a locacao dele', ['R1C'],
     array_column(contagem2_fila($pdo, $INV, $EMP, $REV, 1), 'item_estoque_pub'));
t_eq('coletor 2 so recebe a locacao dele', ['R2A'],
     array_column(contagem2_fila($pdo, $INV, $EMP, $REV, 2), 'item_estoque_pub'));
t_eq('coletor sem locacao recebe fila vazia', [], contagem2_fila($pdo, $INV, $EMP, $REV, 3));

t_eq('totais gerais somam os coletores', 2, contagem2_totais($pdo, $INV, $EMP, $REV)['divergentes']);
t_eq('totais do coletor 1 so com as dele', 1, contagem2_totais($pdo, $INV, $EMP, $REV, 1)['divergentes']);
t_eq('login oferece ao coletor 2 so as dele', ['2-2-1-A-1'],
     contagem2_locacoes_pendentes($pdo, $INV, $EMP, $REV, 2));

t_true('coletor 1 tem locacao',  contagem2_coletor_tem_locacao($pdo, $INV, $EMP, $REV, 1));
t_eq('coletor 3 nao tem locacao', false, contagem2_coletor_tem_locacao($pdo, $INV, $EMP, $REV, 3));

// A lista do gestor ignora a escolha: é de onde ela sai.
t_eq('lista do gestor continua com todas', 3, count(contagem2_locacoes_divergentes($pdo, $INV, $EMP, $REV)));

// Regravar substitui, não acumula.
contagem2_definir_locacoes($pdo, $INV, $EMP, $REV, ['2-1-1-A-1' => 4]);
t_eq('regravar substitui a escolha', [['locacao_geral' => '2-1-1-A-1', 'coletor' => 4]],
     contagem2_locacoes_escolhidas($pdo, $INV, $EMP, $REV));

t_eq('nada valido grava zero', 0, contagem2_definir_locacoes($pdo, $INV, $EMP, $REV, ['X-X' => 1]));

// Sem escolha gravada, todo coletor recebe tudo.
t_true('sem escolha, qualquer coletor tem locacao', contagem2_coletor_tem_locacao($pdo, $INV, $EMP, $REV, 7));
t_eq('sem escolha, coletor recebe todos', 4, count(contagem2_fila($pdo, $INV, $EMP, $REV, 7)));

// ── Rótulos dos coletores (mesma fonte do login) ───────────────────────────
$pdo->prepare("DELETE FROM inv_inventarios WHERE id = ?")->execute([$INV]);
$pdo->prepare("
    INSERT INTO inv_inventarios (id, empresa, revenda, descricao, data_inicio, status, coletores_json)
    VALUES (?, ?, ?, 'TESTE', NOW(), 'aberto', ?)
")->execute([$INV, $EMP, $REV, json_encode(['1' => ['José', 'Ana'], '3' => ['Marcos']])]);
t_eq('so os coletores cadastrados, com nomes',
     [1 => 'Coletor 1 - José e Ana', 3 => 'Coletor 3 - Marcos'],
     contagem2_coletores($pdo, $INV, $EMP, $REV));

$pdo->prepare("UPDATE inv_inventarios SET coletores_json = NULL WHERE id = ?")->execute([$INV]);
t_eq('sem cadastro, os oito numeros', 8, count(contagem2_coletores($pdo, $INV, $EMP, $REV)));
$pdo->prepare("DELETE FROM inv_inventarios WHERE id = ?")->execute([$INV]);

// ── Divisão fechada: tudo distribuído ──────────────────────────────────────
$pdo->prepare("
    INSERT INTO inv_inventarios (id, empresa, revenda, descricao, data_inicio, status, rodada_atual)
    VALUES (?, ?, ?, 'TESTE', NOW(), 'aberto', 2)
")->execute([$INV, $EMP, $REV]);

$pdo->prepare("DELETE FROM inv_cont2_locacoes WHERE id_inventario = ?")->execute([$INV]);
t_eq('sem divisao gravada, nao esta fechada', false, contagem2_designacao_fechada($pdo, $INV, $EMP, $REV));

contagem2_definir_locacoes($pdo, $INV, $EMP, $REV, ['2-1-1-A-1' => 1, '2-1-1-A-2' => 2]);
t_eq('uma locacao pendente sem coletor', 1, contagem2_locacoes_sem_coletor($pdo, $INV, $EMP, $REV));
t_eq('com locacao sem coletor, continua aberta', false, contagem2_designacao_fechada($pdo, $INV, $EMP, $REV));

contagem2_definir_locacoes($pdo, $INV, $EMP, $REV, ['2-1-1-A-1' => 1, '2-1-1-A-2' => 2, '2-2-1-A-1' => 1]);
t_true('tudo distribuido fecha a divisao', contagem2_designacao_fechada($pdo, $INV, $EMP, $REV));

// Locação fora da divisão mas já sem nada pendente não impede o fechamento.
contagem2_definir_locacoes($pdo, $INV, $EMP, $REV, ['2-1-1-A-1' => 1, '2-1-1-A-2' => 2]);
$pdo->prepare("UPDATE estoque SET cont2_em = NOW() WHERE id_inventario = ? AND locacao_geral = '2-2-1-A-1'")->execute([$INV]);
t_true('locacao ja recontada nao precisa de coletor', contagem2_designacao_fechada($pdo, $INV, $EMP, $REV));

// Na 1ª a divisão vai junto com o encerramento: nunca está fechada.
$pdo->prepare("UPDATE inv_inventarios SET rodada_atual = 1 WHERE id = ?")->execute([$INV]);
t_eq('na 1a nunca esta fechada', false, contagem2_designacao_fechada($pdo, $INV, $EMP, $REV));

$pdo->prepare("DELETE FROM inv_inventarios WHERE id = ?")->execute([$INV]);

$limpar();

t_fim();
