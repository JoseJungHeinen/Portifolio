<?php
declare(strict_types=1);

require __DIR__ . '/fixture.php';

echo "test_enviar\n";

rc_limpar($pdo);
rc_inventario($pdo);

$agora = '2026-09-24 10:00:00';

// ── Lista mista ──────────────────────────────────────────────────────────
$idAg = rc_linha($pdo, ['pub' => 'AG', 'qtd' => 5, 'c1' => 3, 'sit' => 'C']);                    // aguardando
$idNc = rc_linha($pdo, ['pub' => 'NC', 'qtd' => 5, 'sit' => 'A']);                                // nao_contado
$idCt = rc_linha($pdo, ['pub' => 'CT', 'qtd' => 5, 'c1' => 3, 'sit' => 'C', 'rod' => 2, 'col' => 1]); // contando
$idBa = rc_linha($pdo, ['pub' => 'BA', 'qtd' => 5, 'c1' => 5, 'sit' => 'C']);                     // bateu
$idDe = rc_linha($pdo, ['pub' => 'DE', 'qtd' => 7, 'c1' => 1, 'c2' => 8, 'c2em' => $agora, 'sit' => 'C', 'rod' => 2, 'col' => 1]); // decisao

// ultima = 2 explícito: idDe só chega a 'decisao' com o teto antigo — ver
// nota equivalente em test_mandar.php.
$r = recontagem_enviar($pdo, RC_INV, RC_EMP, RC_REV, [$idAg, $idNc, $idCt, $idBa, $idDe, 123456789], 2, 2);

t_eq('enviou os dois abertos da 1a', 2, $r['enviados']);
t_eq('trocou o que estava contando', 1, $r['trocados']);
t_eq('recusou o que bateu', 1, $r['recusados']['bateu']);
t_eq('recusou o de decisao', 1, $r['recusados']['decisao']);
t_eq('recusou id de fora do inventario', 1, $r['recusados']['nao_encontrado']);
t_eq('nao ha recusa por descarte aqui', 0, $r['recusados']['descartado']);
t_eq('nao ha recusa por teto aqui', 0, $r['recusados']['teto']);
t_eq('ninguem mudou no meio do caminho', 0, $r['recusados']['mudou']);

t_eq('aguardando subiu para a 2a', 2, (int)rc_ler($pdo, $idAg)['rodada_item']);
t_eq('aguardando ganhou o coletor', 2, (int)rc_ler($pdo, $idAg)['coletor_recontagem']);
t_eq('nao contado subiu tambem', 2, (int)rc_ler($pdo, $idNc)['rodada_item']);
t_eq('nao contado ganhou o coletor', 2, (int)rc_ler($pdo, $idNc)['coletor_recontagem']);

t_eq('contando manteve a rodada', 2, (int)rc_ler($pdo, $idCt)['rodada_item']);
t_eq('contando trocou de coletor', 2, (int)rc_ler($pdo, $idCt)['coletor_recontagem']);

t_eq('o que bateu ficou parado', 1, (int)rc_ler($pdo, $idBa)['rodada_item']);
t_eq('o de decisao ficou parado', 2, (int)rc_ler($pdo, $idDe)['rodada_item']);

// ── Coletor inválido: nada é gravado, nem no item elegível ─────────────────
$idNovo = rc_linha($pdo, ['pub' => 'NOVO', 'qtd' => 5, 'c1' => 2, 'sit' => 'C']);
$idNovoCt = rc_linha($pdo, ['pub' => 'NOVOCT', 'qtd' => 5, 'c1' => 3, 'sit' => 'C', 'rod' => 2, 'col' => 1]);

$estourou = false;
try {
    recontagem_enviar($pdo, RC_INV, RC_EMP, RC_REV, [$idNovo, $idNovoCt], 9);
} catch (InvalidArgumentException $e) {
    $estourou = true;
}
t_true('coletor 9 estoura', $estourou);
t_eq('aguardando elegivel nao mudou de rodada', 1, (int)rc_ler($pdo, $idNovo)['rodada_item']);
t_null('aguardando elegivel nao ganhou coletor', rc_ler($pdo, $idNovo)['coletor_recontagem']);
t_eq('contando elegivel nao mudou de rodada', 2, (int)rc_ler($pdo, $idNovoCt)['rodada_item']);
t_eq('contando elegivel manteve o coletor antigo', 1, (int)rc_ler($pdo, $idNovoCt)['coletor_recontagem']);

// ── Prévia dá a mesma classificação e não grava nada ───────────────────────
$idAg2 = rc_linha($pdo, ['pub' => 'AG2', 'qtd' => 5, 'c1' => 3, 'sit' => 'C']);                   // aguardando
$idNc2 = rc_linha($pdo, ['pub' => 'NC2', 'qtd' => 5, 'sit' => 'A']);                               // nao_contado
$idCt2 = rc_linha($pdo, ['pub' => 'CT2', 'qtd' => 5, 'c1' => 3, 'sit' => 'C', 'rod' => 2, 'col' => 1]); // contando
$idBa2 = rc_linha($pdo, ['pub' => 'BA2', 'qtd' => 5, 'c1' => 5, 'sit' => 'C']);                    // bateu

$antesAg = rc_ler($pdo, $idAg2);
$antesNc = rc_ler($pdo, $idNc2);
$antesCt = rc_ler($pdo, $idCt2);
$antesBa = rc_ler($pdo, $idBa2);

$p = recontagem_previa_envio($pdo, RC_INV, RC_EMP, RC_REV, [$idAg2, $idNc2, $idCt2, $idBa2, 987654321]);

t_eq('previa: para a proxima', 2, $p['para_proxima']);
t_eq('previa: trocar', 1, $p['trocar']);
t_eq('previa: recusados (bateu + nao encontrado)', 2, $p['recusados']);

t_eq('previa nao mexeu no aguardando', $antesAg, rc_ler($pdo, $idAg2));
t_eq('previa nao mexeu no nao contado', $antesNc, rc_ler($pdo, $idNc2));
t_eq('previa nao mexeu no contando', $antesCt, rc_ler($pdo, $idCt2));
t_eq('previa nao mexeu no que bateu', $antesBa, rc_ler($pdo, $idBa2));

rc_limpar($pdo);

t_fim();
