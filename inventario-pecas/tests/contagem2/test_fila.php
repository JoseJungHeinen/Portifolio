<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'contagem_2_lib.php';
require __DIR__ . '/assert.php';

echo "test_fila\n";

// Inventário fictício, fora da faixa real, para não sujar dado de produção.
$INV = 999999;
$EMP = 1;
$REV = 1;

$pdo->prepare("DELETE FROM estoque WHERE id_inventario = ?")->execute([$INV]);

$inserir = function (array $d) use ($pdo, $INV, $EMP, $REV) {
    $stmt = $pdo->prepare("
        INSERT INTO estoque
            (id_inventario, empresa, revenda, item_estoque_pub, descricao_item_estoque,
             quantidade, cont_1, cont_2, cont2_em,
             locacao_zona, locacao_rua, locacao_estante, locacao_prateleira, locacao_numero,
             locacao_geral, situacao, coletor)
        VALUES
            (:inv, :emp, :rev, :pub, :des,
             :qtd, :c1, :c2, :c2em,
             :zona, :rua, :est, :prat, :num,
             :geral, 'A', 0)
    ");
    $stmt->execute([
        ':inv'  => $INV, ':emp' => $EMP, ':rev' => $REV,
        ':pub'  => $d['pub'],
        ':des'  => $d['des'] ?? 'DESCRICAO DE TESTE',
        ':qtd'  => $d['qtd'],
        ':c1'   => $d['c1'],
        ':c2'   => $d['c2']   ?? 0,
        ':c2em' => $d['c2em'] ?? null,
        ':zona' => $d['zona'], ':rua'  => $d['rua'], ':est' => $d['est'],
        ':prat' => $d['prat'], ':num'  => $d['num'],
        ':geral' => $d['zona'] . '-' . $d['rua'] . '-' . $d['est'] . '-' . $d['prat'] . '-' . $d['num'],
    ]);
};

// Divergentes, propositalmente fora de ordem na inserção.
$inserir(['pub' => 'RUA10', 'qtd' => 5,  'c1' => 3,    'zona' => '2', 'rua' => '10', 'est' => '1', 'prat' => 'A', 'num' => '1']);
$inserir(['pub' => 'RUA2',  'qtd' => 24, 'c1' => 12,   'zona' => '2', 'rua' => '2',  'est' => '1', 'prat' => 'A', 'num' => '1']);
$inserir(['pub' => 'SOBRA', 'qtd' => 1,  'c1' => 5,    'zona' => '2', 'rua' => '2',  'est' => '1', 'prat' => 'A', 'num' => '2']);
// Nunca contado. estoque.cont_1 e int(11) NOT NULL e o gerador insere 0, entao
// "nunca contado" no banco e zero, nao nulo — e cai em Menor.
$inserir(['pub' => 'NUNCACONT', 'qtd' => 7, 'c1' => 0, 'zona' => '2', 'rua' => '3', 'est' => '1', 'prat' => 'A', 'num' => '1']);

// Não entram.
$inserir(['pub' => 'BATEU', 'qtd' => 9, 'c1' => 9, 'zona' => '2', 'rua' => '1', 'est' => '1', 'prat' => 'A', 'num' => '1']);
$inserir(['pub' => 'ZEROZERO', 'qtd' => 0, 'c1' => 0, 'zona' => '2', 'rua' => '1', 'est' => '1', 'prat' => 'A', 'num' => '2']);

// Divergente, mas já recontado — inclusive com cont_2 = 0, que é o caso que o
// cont2_em existe para distinguir de "não recontado".
$inserir(['pub' => 'JAFEITO', 'qtd' => 4, 'c1' => 1, 'c2' => 0, 'c2em' => '2026-09-21 10:00:00',
          'zona' => '2', 'rua' => '1', 'est' => '1', 'prat' => 'A', 'num' => '3']);

// Linha em descarte (situacao 'D', decidida na Tomada de Decisao). Divergente,
// nunca recontada — mas mandar a dupla ate ela e gravar faria o 'C' apagar a
// decisao em silencio. Nao pode entrar na fila nem contar nos totais.
$pdo->prepare("UPDATE estoque SET situacao = 'D' WHERE id_inventario = ? AND item_estoque_pub = 'RUA10'")
    ->execute([$INV]);

$fila = contagem2_fila($pdo, $INV, $EMP, $REV);
$codigos = array_column($fila, 'item_estoque_pub');

t_eq('fila tem so os divergentes nao recontados e nao descartados', 3, count($fila));
t_eq('linha em descarte fica de fora', false, in_array('RUA10', $codigos, true));
t_true('item a menor entra',  in_array('RUA2', $codigos, true));
t_true('item com sobra entra', in_array('SOBRA', $codigos, true));
t_true('item nunca contado entra', in_array('NUNCACONT', $codigos, true));
t_eq('item que bateu fica de fora', false, in_array('BATEU', $codigos, true));
t_eq('zero contra zero fica de fora', false, in_array('ZEROZERO', $codigos, true));
t_eq('item com cont2_em fica de fora, mesmo com cont_2 = 0', false, in_array('JAFEITO', $codigos, true));

// Ordem de caminhada: A-1-2 antes de A-1-10. Ordenar pela string de locacao_geral
// inverteria os dois, e a dupla andaria o corredor de tras para frente.
t_eq('ordem segue o caminho fisico, nao a string', ['RUA2', 'SOBRA', 'NUNCACONT'], $codigos);

// A etiqueta vem pronta do servidor: a tela so pinta.
$porCodigo = array_column($fila, null, 'item_estoque_pub');
t_eq('etiqueta de falta', 'menor', $porCodigo['RUA2']['etiqueta']);
t_eq('etiqueta de sobra', 'maior', $porCodigo['SOBRA']['etiqueta']);
t_eq('nunca contado e Menor, nao uma terceira etiqueta', 'menor', $porCodigo['NUNCACONT']['etiqueta']);

t_eq('a fila carrega o saldo do sistema', 24, $porCodigo['RUA2']['quantidade']);
t_eq('a fila carrega a 1a contagem',      12, $porCodigo['RUA2']['cont_1']);
t_eq('nunca contado chega como zero',      0, $porCodigo['NUNCACONT']['cont_1']);

// ── Totais: o que o header do coletor e o card do dashboard mostram ────────
$tot = contagem2_totais($pdo, $INV, $EMP, $REV);
t_eq('divergentes conta os recontados, nao os descartados', 4, $tot['divergentes']);
t_eq('recontados conta so quem tem cont2_em',  1, $tot['recontados']);
t_eq('menor', 3, $tot['menor']);
t_eq('maior', 1, $tot['maior']);
t_eq('com item pendente nao esta finalizada', false, $tot['finalizada']);
t_eq('ultima recontagem e a do JAFEITO', '2026-09-21 10:00:00', $tot['ultima_em']);

// Tudo recontado: finalizada, e ultima_em é a recontagem mais recente.
$pdo->prepare("UPDATE estoque SET cont2_em = '2026-09-22 15:30:00' WHERE id_inventario = ? AND cont2_em IS NULL")
    ->execute([$INV]);
$fim = contagem2_totais($pdo, $INV, $EMP, $REV);
t_true('tudo recontado esta finalizada', $fim['finalizada']);
t_eq('ultima_em marca o fim', '2026-09-22 15:30:00', $fim['ultima_em']);
$pdo->prepare("UPDATE estoque SET cont2_em = NULL WHERE id_inventario = ? AND cont2_em = '2026-09-22 15:30:00'")
    ->execute([$INV]);

// Nada a recontar não é contagem finalizada.
$vazio = contagem2_totais($pdo, 999998, $EMP, $REV);
t_eq('zero de zero nao esta finalizada', false, $vazio['finalizada']);
t_null('sem recontagem, ultima_em nulo', $vazio['ultima_em']);

// Outra loja do mesmo inventário não herda a fila.
t_eq('outra revenda tem fila propria', 0, count(contagem2_fila($pdo, $INV, $EMP, 5)));

// ── Locacoes oferecidas no login ───────────────────────────────────────────
// O dropdown "Locacao da Dupla" tem que oferecer as locacoes DA FILA, na mesma
// ordem de caminhada. Antes ele rodava a regra da tela antiga (cont_2 < cont_1),
// que nunca casa com item nunca contado — ou seja, escondia justamente o que
// mais aparece depois de uma 1a contagem parcial.
$locs = contagem2_locacoes_pendentes($pdo, $INV, $EMP, $REV);

t_eq('so as locacoes da fila, sem repetir', ['2-2-1-A-1', '2-2-1-A-2', '2-3-1-A-1'], $locs);
t_eq('locacao de item nunca contado esta entre elas', true, in_array('2-3-1-A-1', $locs, true));
t_eq('locacao so de item descartado nao aparece', false, in_array('2-10-1-A-1', $locs, true));

$pdo->prepare("DELETE FROM estoque WHERE id_inventario = ?")->execute([$INV]);

t_fim();
