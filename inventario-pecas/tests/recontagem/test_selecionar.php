<?php
declare(strict_types=1);

require __DIR__ . '/fixture.php';

echo "test_selecionar\n";

rc_limpar($pdo);
rc_inventario($pdo);

// Faixa de locações, rua 1 a 6, uma linha marcável (ou não) em cada.
$idMais      = rc_linha($pdo, ['pub' => 'MAIS', 'loc' => '1-1-1-A-1', 'qtd' => 5, 'c1' => 8, 'sit' => 'C']);               // aguardando, ultima 8 > 5
$idMenos     = rc_linha($pdo, ['pub' => 'MENOS', 'loc' => '1-2-1-A-1', 'qtd' => 5, 'c1' => 2, 'sit' => 'C']);              // aguardando, ultima 2 < 5
$idNulo      = rc_linha($pdo, ['pub' => 'NULO', 'loc' => '1-3-1-A-1', 'qtd' => 5, 'sit' => 'A']);                          // nao_contado, ultima null
$idBateu     = rc_linha($pdo, ['pub' => 'BATEU', 'loc' => '1-4-1-A-1', 'qtd' => 5, 'c1' => 5, 'sit' => 'C']);              // bateu, nao marcavel
// decisao (encerrada pelo gestor), nao marcavel — o padrão desta entrega
// (RECONTAGEM_ULTIMA = 4) não deixaria uma linha na 2ª virar decisão
// sozinha, então usamos o encerramento manual para obter o mesmo caso.
$idDecisao   = rc_linha($pdo, ['pub' => 'DECI', 'loc' => '1-5-1-A-1', 'qtd' => 5, 'c1' => 9, 'sit' => 'C', 'c2' => 9, 'c2em' => '2026-09-24 10:00:00', 'rod' => 2, 'encerrada_em' => '2026-09-24 10:05:00', 'encerrada_por' => 'Gestor']);
$idDescartado = rc_linha($pdo, ['pub' => 'DESC', 'loc' => '1-6-1-A-1', 'qtd' => 5, 'c1' => 1, 'sit' => 'D']);              // descartado, fora de "todos"

$filtro = ['situacao' => 'todos'];

// ── Faixa simples, sinal 'todos' ────────────────────────────────────────────
t_eq('de..ate cobre so os marcaveis da faixa, em ordem de caminhada',
     [$idMais, $idMenos, $idNulo],
     recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, $filtro, '1-1-1-A-1', '1-3-1-A-1', 'todos'));

// ── de/ate em ordem trocada ─────────────────────────────────────────────────
t_eq('de e ate aceitos em qualquer ordem',
     [$idMais, $idMenos, $idNulo],
     recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, $filtro, '1-3-1-A-1', '1-1-1-A-1', 'todos'));

// ── Sinal 'mais' / 'menos' ───────────────────────────────────────────────────
t_eq('sinal mais: so ultima > sistema',
     [$idMais],
     recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, $filtro, '1-1-1-A-1', '1-3-1-A-1', 'mais'));

t_eq('sinal menos: so ultima < sistema',
     [$idMenos],
     recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, $filtro, '1-1-1-A-1', '1-3-1-A-1', 'menos'));

// ── Item nunca contado só entra em 'todos' ──────────────────────────────────
t_eq('nunca contado nao entra em mais',
     [],
     array_values(array_intersect(
         [$idNulo],
         recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, $filtro, '1-1-1-A-1', '1-3-1-A-1', 'mais')
     )));
t_eq('nunca contado nao entra em menos',
     [],
     array_values(array_intersect(
         [$idNulo],
         recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, $filtro, '1-1-1-A-1', '1-3-1-A-1', 'menos')
     )));
t_true('nunca contado entra em todos',
     in_array($idNulo, recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, $filtro, '1-1-1-A-1', '1-3-1-A-1', 'todos'), true));

// ── Sinal desconhecido se comporta como 'todos' ─────────────────────────────
t_eq('sinal desconhecido vale como todos',
     [$idMais, $idMenos, $idNulo],
     recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, $filtro, '1-1-1-A-1', '1-3-1-A-1', 'xyz'));

// ── Não marcáveis nunca voltam, mesmo dentro da faixa ───────────────────────
// A locação do descartado (rua 6) nem entra na lista de locações de 'todos' —
// ele é filtrado antes disso. A faixa vai só até a rua 5 (decisão).
$faixaToda = recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, $filtro, '1-1-1-A-1', '1-5-1-A-1', 'todos');
t_eq('faixa toda: so os marcaveis', [$idMais, $idMenos, $idNulo], $faixaToda);
t_true('bateu nunca entra', !in_array($idBateu, $faixaToda, true));
t_true('decisao nunca entra', !in_array($idDecisao, $faixaToda, true));
t_true('descartado nunca entra', !in_array($idDescartado, $faixaToda, true));

// ── de ou ate fora da lista de locações do filtro ───────────────────────────
t_eq('de inexistente devolve vazio',
     [],
     recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, $filtro, '9-9-9-A-9', '1-3-1-A-1', 'todos'));
t_eq('ate inexistente devolve vazio',
     [],
     recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, $filtro, '1-1-1-A-1', '9-9-9-A-9', 'todos'));

// ── A busca é parte do mesmo filtro que recontagem_grade usa (extraído em
//    recontagem_filtro_sql): uma busca que só bate com uma locação estreita a
//    seleção a ela, mesmo pedindo uma faixa maior. ─────────────────────────
$filtroBusca = ['situacao' => 'todos', 'busca' => 'MENOS'];
t_eq('busca estreita a selecao a so a locacao que bate',
     [$idMenos],
     recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, $filtroBusca, '1-2-1-A-1', '1-2-1-A-1', 'todos'));
t_eq('busca tira as outras locacoes da lista: a mesma faixa de antes agora nao acha de/ate',
     [],
     recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, $filtroBusca, '1-1-1-A-1', '1-3-1-A-1', 'todos'));

rc_limpar($pdo);

// ── O filtro de situação é o mesmo da grade: um nao_contado dentro da faixa
//    do filtro 'recontar' nunca entra, porque nem chega a aparecer na lista
//    de locações desse filtro — só o que é 'aguardando' aparece. ───────────
rc_inventario($pdo);
$idAg7 = rc_linha($pdo, ['pub' => 'AG7', 'loc' => '1-7-1-A-1', 'qtd' => 5, 'c1' => 8, 'sit' => 'C']); // aguardando
$idAg9 = rc_linha($pdo, ['pub' => 'AG9', 'loc' => '1-9-1-A-1', 'qtd' => 5, 'c1' => 8, 'sit' => 'C']); // aguardando
$idNC9 = rc_linha($pdo, ['pub' => 'NC9', 'loc' => '1-9-1-A-1', 'qtd' => 5, 'sit' => 'A']);            // nao_contado, mesma locacao do AG9

t_eq('filtro recontar: nao_contado da mesma faixa fica fora, aguardando entra',
     [$idAg7, $idAg9],
     recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, ['situacao' => 'recontar'], '1-7-1-A-1', '1-9-1-A-1', 'todos'));
t_true('o nao_contado nunca aparece no filtro recontar',
     !in_array($idNC9, recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, ['situacao' => 'recontar'], '1-7-1-A-1', '1-9-1-A-1', 'todos'), true));

rc_limpar($pdo);

// ── Sem limite: mais linhas do que o limite pequeno da grade ────────────────
rc_inventario($pdo);
for ($i = 1; $i <= 10; $i++) {
    rc_linha($pdo, ['pub' => 'M' . $i, 'loc' => '1-1-1-A-' . $i, 'qtd' => 5, 'c1' => 9, 'sit' => 'C']);
}

$grade = recontagem_grade($pdo, RC_INV, RC_EMP, RC_REV, ['situacao' => 'todos'], 3);
t_true('a grade com limite pequeno corta', $grade['cortado']);
t_eq('a grade com limite pequeno traz so 3', 3, count($grade['linhas']));

$sel = recontagem_selecionar($pdo, RC_INV, RC_EMP, RC_REV, ['situacao' => 'todos'], '1-1-1-A-1', '1-1-1-A-10', 'todos');
t_eq('a selecao nao tem limite: traz as 10', 10, count($sel));

rc_limpar($pdo);

t_fim();
