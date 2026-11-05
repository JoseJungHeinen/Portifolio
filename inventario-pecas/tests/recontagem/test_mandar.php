<?php
declare(strict_types=1);

require __DIR__ . '/fixture.php';

echo "test_mandar\n";

rc_limpar($pdo);
rc_inventario($pdo);

$agora = '2026-09-24 10:00:00';

$idAg  = rc_linha($pdo, ['pub' => 'AG', 'qtd' => 5, 'c1' => 3, 'sit' => 'C']);
$idNc  = rc_linha($pdo, ['pub' => 'NC', 'qtd' => 5, 'sit' => 'A']);
$idBa  = rc_linha($pdo, ['pub' => 'BA', 'qtd' => 5, 'c1' => 5, 'sit' => 'C']);
$idDe  = rc_linha($pdo, ['pub' => 'DE', 'qtd' => 7, 'c1' => 1, 'c2' => 8, 'c2em' => $agora, 'sit' => 'C', 'rod' => 2, 'col' => 1]);
$idDs  = rc_linha($pdo, ['pub' => 'DS', 'qtd' => 5, 'c1' => 3, 'sit' => 'D']);
$idCt  = rc_linha($pdo, ['pub' => 'CT', 'qtd' => 5, 'c1' => 3, 'sit' => 'C', 'rod' => 2, 'col' => 1]);

// ── Mandar ─────────────────────────────────────────────────────────────────
// ultima = 2 explícito: este bloco é sobre o teto em si (idDe chega a
// 'decisao' e idCt é recusado por 'teto' na 2ª) — com o padrão desta entrega
// (RECONTAGEM_ULTIMA = 4) os dois ainda estariam abertos.
$r = recontagem_mandar($pdo, RC_INV, RC_EMP, RC_REV, [$idAg, $idNc, $idBa, $idDe, $idDs, $idCt, 123456789], 2, 2);

t_eq('mandou os dois abertos da 1a', 2, $r['enviados']);
t_eq('recusou o que bateu', 1, $r['recusados']['bateu']);
t_eq('recusou o de decisao', 1, $r['recusados']['decisao']);
t_eq('recusou o descartado', 1, $r['recusados']['descartado']);
t_eq('recusou quem ja esta no teto', 1, $r['recusados']['teto']);
t_eq('recusou id de fora do inventario', 1, $r['recusados']['nao_encontrado']);

$ag = rc_ler($pdo, $idAg);
t_eq('aguardando subiu para a 2a', 2, (int)$ag['rodada_item']);
t_eq('com o coletor escolhido', 2, (int)$ag['coletor_recontagem']);
t_eq('nao contado subiu tambem', 2, (int)rc_ler($pdo, $idNc)['rodada_item']);
t_eq('o que bateu ficou', 1, (int)rc_ler($pdo, $idBa)['rodada_item']);
t_eq('nenhum mudou no meio do caminho', 0, $r['recusados']['mudou']);

// Coletor fora de 1..8 não muda nada.
$idNovo = rc_linha($pdo, ['pub' => 'NOVO', 'qtd' => 5, 'c1' => 2, 'sit' => 'C']);
$estourou = false;
try {
    recontagem_mandar($pdo, RC_INV, RC_EMP, RC_REV, [$idNovo], 9);
} catch (InvalidArgumentException $e) {
    $estourou = true;
}
t_true('coletor 9 estoura', $estourou);
t_eq('coletor invalido nao mexe na rodada', 1, (int)rc_ler($pdo, $idNovo)['rodada_item']);
t_null('nem no coletor', rc_ler($pdo, $idNovo)['coletor_recontagem']);

// Com a 4ª liberada, a 2ª feita sem bater sobe para a 3ª.
$r4 = recontagem_mandar($pdo, RC_INV, RC_EMP, RC_REV, [$idDe], 1, 4);
t_eq('teto 4: 2a feita sem bater sobe', 1, $r4['enviados']);
t_eq('para a 3a', 3, (int)rc_ler($pdo, $idDe)['rodada_item']);

// Inventário de outra revenda não é tocado.
$outra = recontagem_mandar($pdo, RC_INV, RC_EMP, 5, [$idCt], 1, 4);
t_eq('outra revenda nao acha a linha', 1, $outra['recusados']['nao_encontrado']);

// ── Trocar coletor ─────────────────────────────────────────────────────────
$t = recontagem_trocar_coletor($pdo, RC_INV, RC_EMP, RC_REV, [$idCt, $idBa], 2);
t_eq('trocou o que esta contando', 1, $t['trocados']);
t_eq('recusou o que nao esta contando', 1, $t['recusados']);
t_eq('coletor novo', 2, (int)rc_ler($pdo, $idCt)['coletor_recontagem']);
t_eq('trocar nao sobe a contagem', 2, (int)rc_ler($pdo, $idCt)['rodada_item']);

rc_limpar($pdo);

t_fim();
