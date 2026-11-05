<?php
declare(strict_types=1);

require __DIR__ . '/fixture.php';

echo "test_coletor\n";

rc_limpar($pdo);
rc_inventario($pdo);

$agora = '2026-09-24 10:00:00';

$idB = rc_linha($pdo, ['pub' => 'B', 'loc' => '1-10-1-A-1', 'qtd' => 5, 'c1' => 1, 'sit' => 'C', 'rod' => 2, 'col' => 1]);
$idA = rc_linha($pdo, ['pub' => 'A', 'loc' => '1-2-1-A-1',  'qtd' => 5, 'c1' => 1, 'sit' => 'C', 'rod' => 2, 'col' => 1]);
$idF = rc_linha($pdo, ['pub' => 'F', 'loc' => '1-3-1-A-1',  'qtd' => 5, 'c1' => 1, 'sit' => 'C', 'rod' => 2, 'col' => 1, 'c2' => 4, 'c2em' => $agora]);
$idO = rc_linha($pdo, ['pub' => 'O', 'loc' => '1-4-1-A-1',  'qtd' => 5, 'c1' => 1, 'sit' => 'C', 'rod' => 2, 'col' => 2]);
$id1 = rc_linha($pdo, ['pub' => 'P', 'loc' => '1-5-1-A-1',  'qtd' => 5, 'c1' => 1, 'sit' => 'C', 'rod' => 1]);
$idD = rc_linha($pdo, ['pub' => 'D', 'loc' => '1-6-1-A-1',  'qtd' => 5, 'c1' => 1, 'sit' => 'D', 'rod' => 2, 'col' => 1]);

// ── Fila ───────────────────────────────────────────────────────────────────
$fila = recontagem_fila($pdo, RC_INV, RC_EMP, RC_REV, 1);
t_eq('so os do coletor, pendentes, sem descartado, em ordem', [$idA, $idB], array_column($fila, 'id'));
t_eq('traz a contagem da linha', 2, $fila[0]['rodada']);
// Às cegas: a fila não carrega o sistema nem as contagens.
t_eq('fila nao traz quantidade', false, array_key_exists('quantidade', $fila[0]));
t_eq('fila nao traz cont_1', false, array_key_exists('cont_1', $fila[0]));
t_eq('coletor 2 so ve o dele', [$idO], array_column(recontagem_fila($pdo, RC_INV, RC_EMP, RC_REV, 2), 'id'));

$tot = recontagem_totais_coletor($pdo, RC_INV, RC_EMP, RC_REV, 1);
t_eq('totais do coletor: 3 designados, 1 feito', ['total' => 3, 'feitos' => 1], $tot);

t_eq('locacoes pendentes do coletor', ['1-2-1-A-1', '1-10-1-A-1'],
     recontagem_locacoes_pendentes($pdo, RC_INV, RC_EMP, RC_REV, 1));

// Move B para a 3ª antes de qualquer gravação: a fila deve misturar as duas contagens.
$pdo->prepare("UPDATE estoque SET rodada_item = 3, cont2_em = NOW() WHERE id_linha = ?")->execute([$idB]);
$filaMista = recontagem_fila($pdo, RC_INV, RC_EMP, RC_REV, 1);
t_eq('fila mistura 2a e 3a: ids', [$idA, $idB], array_column($filaMista, 'id'));
t_eq('fila mistura 2a e 3a: rodadas', [2, 3],
     array_values(array_map(fn($l) => $l['rodada'], array_filter($filaMista, fn($l) => in_array($l['id'], [$idA, $idB], true)))));

// ── Gravar ─────────────────────────────────────────────────────────────────
$base = ['id_inventario' => RC_INV, 'empresa' => RC_EMP, 'revenda' => RC_REV,
         'coletor' => 1, 'dupla' => 'Jose', 'entrada' => 'bipado'];

t_true('grava a 2a', recontagem_gravar($pdo, array_merge($base, ['id' => $idA, 'quantidade' => 0]))['ok']);
$a = rc_ler($pdo, $idA);
t_eq('cont_2 = 0 gravado', 0, (int)$a['cont_2']);
t_true('marcou cont2_em', $a['cont2_em'] !== null);
t_eq('rastro de entrada', 'bipado', $a['cont2_entrada']);
t_eq('rastro da dupla', 'Jose', $a['dupla_cont2']);
// A recontagem não mexe em situacao: 'C' é a marca da 1ª feita.
t_eq('nao mexe em situacao', 'C', $a['situacao']);

t_eq('nao grava de novo a mesma contagem', 'redistribuido',
     recontagem_gravar($pdo, array_merge($base, ['id' => $idA, 'quantidade' => 3]))['motivo']);
t_eq('e o valor ficou', 0, (int)rc_ler($pdo, $idA)['cont_2']);

t_eq('nao grava item de outro coletor', 'redistribuido',
     recontagem_gravar($pdo, array_merge($base, ['id' => $idO, 'quantidade' => 3]))['motivo']);
t_eq('nao grava item na 1a', 'redistribuido',
     recontagem_gravar($pdo, array_merge($base, ['id' => $id1, 'quantidade' => 3]))['motivo']);

t_eq('rodada esperada divergente nao grava', 'redistribuido',
     recontagem_gravar($pdo, array_merge($base, ['id' => $idB, 'quantidade' => 9, 'rodada' => 2]))['motivo']);
t_null('e cont_3 continua vazio', rc_ler($pdo, $idB)['cont3_em']);

// Grava na coluna da contagem em que a linha está.
t_true('grava a 3a', recontagem_gravar($pdo, array_merge($base, ['id' => $idB, 'quantidade' => 7, 'entrada' => 'digitado', 'rodada' => 3]))['ok']);
$b = rc_ler($pdo, $idB);
t_eq('cont_3', 7, (int)$b['cont_3']);
t_eq('cont3_entrada', 'digitado', $b['cont3_entrada']);
t_eq('dupla_cont3', 'Jose', $b['dupla_cont3']);

$estourou = false;
try { recontagem_gravar($pdo, array_merge($base, ['id' => $idO, 'quantidade' => -1])); } catch (InvalidArgumentException $e) { $estourou = true; }
t_true('quantidade negativa estoura', $estourou);

$estourou = false;
try { recontagem_gravar($pdo, array_merge($base, ['id' => $idO, 'quantidade' => 1, 'entrada' => 'x'])); } catch (InvalidArgumentException $e) { $estourou = true; }
t_true('entrada invalida estoura', $estourou);

// A exceção estoura antes de qualquer escrita, mesmo num item que o coletor possui.
$idC = rc_linha($pdo, ['pub' => 'H', 'loc' => '1-8-1-A-1', 'qtd' => 5, 'c1' => 1, 'sit' => 'C', 'rod' => 2, 'col' => 1]);

$estourou = false;
try { recontagem_gravar($pdo, array_merge($base, ['id' => $idC, 'quantidade' => -1])); } catch (InvalidArgumentException $e) { $estourou = true; }
t_true('quantidade negativa estoura antes de gravar', $estourou);

$estourou = false;
try { recontagem_gravar($pdo, array_merge($base, ['id' => $idC, 'quantidade' => 1, 'entrada' => 'x'])); } catch (InvalidArgumentException $e) { $estourou = true; }
t_true('entrada invalida estoura antes de gravar', $estourou);

t_null('nada foi gravado', rc_ler($pdo, $idC)['cont2_em']);

// Rodada fora de 2..4 falha seguro, sem estourar warning/PDOException.
$idE = rc_linha($pdo, ['pub' => 'E', 'loc' => '1-9-1-A-1', 'qtd' => 5, 'c1' => 1, 'sit' => 'C', 'rod' => 5, 'col' => 1]);
t_eq('rodada fora de 2..4 nao grava', 'redistribuido',
     recontagem_gravar($pdo, array_merge($base, ['id' => $idE, 'quantidade' => 3]))['motivo']);
t_eq('e nao aparece na fila', false,
     in_array($idE, array_column(recontagem_fila($pdo, RC_INV, RC_EMP, RC_REV, 1), 'id'), true));

// Nunca escreve numa linha descartada.
t_eq('nao grava em linha descartada', 'redistribuido',
     recontagem_gravar($pdo, array_merge($base, ['id' => $idD, 'quantidade' => 3]))['motivo']);
t_null('cont2_em continua vazio na descartada', rc_ler($pdo, $idD)['cont2_em']);

// ── Trava da 1ª contagem ───────────────────────────────────────────────────
t_eq('rodada do item na 1a', 1, recontagem_rodada_do_item($pdo, RC_INV, 'P', '1-5-1-A-1'));
t_eq('rodada do item na 2a', 2, recontagem_rodada_do_item($pdo, RC_INV, 'O', '1-4-1-A-1'));
t_null('item inexistente', recontagem_rodada_do_item($pdo, RC_INV, 'NAO', '1-1-1-A-1'));

rc_limpar($pdo);

t_fim();
