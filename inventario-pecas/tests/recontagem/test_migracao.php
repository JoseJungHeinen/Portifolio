<?php
declare(strict_types=1);

require __DIR__ . '/fixture.php';

echo "test_migracao\n";

/**
 * Roda só o UPDATE de passagem do .sql (os ALTER já estão aplicados), contra um
 * inventário de teste montado no estado do modelo antigo.
 */
$sql = file_get_contents('sql/2026-09-24-recontagem-por-item.sql');
preg_match('/UPDATE estoque e JOIN inv_inventarios.*?;/s', $sql, $m);
$update = $m[0] ?? '';
t_true('achou o UPDATE no .sql', $update !== '');

// ── Inventário na 2ª COM divisão ───────────────────────────────────────────
rc_limpar($pdo);
rc_inventario($pdo, 'aberto', 2);
$idDiv  = rc_linha($pdo, ['pub' => 'DIV',  'loc' => '1-1-1-A-1', 'qtd' => 5, 'c1' => 2, 'sit' => 'C']);
$idFora = rc_linha($pdo, ['pub' => 'FORA', 'loc' => '1-1-1-A-2', 'qtd' => 5, 'c1' => 2, 'sit' => 'C']);
$idOk   = rc_linha($pdo, ['pub' => 'OK',   'loc' => '1-1-1-A-1', 'qtd' => 5, 'c1' => 5, 'sit' => 'C']);
$pdo->prepare("INSERT INTO inv_cont2_locacoes VALUES (?, ?, ?, '1-1-1-A-1', 2)")->execute([RC_INV, RC_EMP, RC_REV]);

$pdo->exec($update);

t_eq('divergente na divisao vai para a 2a', 2, (int)rc_ler($pdo, $idDiv)['rodada_item']);
t_eq('com o coletor da divisao', 2, (int)rc_ler($pdo, $idDiv)['coletor_recontagem']);
t_eq('divergente fora da divisao fica na 1a', 1, (int)rc_ler($pdo, $idFora)['rodada_item']);
t_eq('o que bateu fica na 1a', 1, (int)rc_ler($pdo, $idOk)['rodada_item']);

// Rodar de novo não muda nada.
$pdo->exec($update);
t_eq('idempotente', 2, (int)rc_ler($pdo, $idDiv)['rodada_item']);

// ── Inventário na 2ª SEM divisão ───────────────────────────────────────────
rc_limpar($pdo);
rc_inventario($pdo, 'aberto', 2);
$idA = rc_linha($pdo, ['pub' => 'A', 'loc' => '1-1-1-A-1', 'qtd' => 5, 'c1' => 2, 'sit' => 'C']);
$idD = rc_linha($pdo, ['pub' => 'D', 'loc' => '1-1-1-A-2', 'qtd' => 5, 'c1' => 2, 'sit' => 'D']);

$pdo->exec($update);

t_eq('sem divisao, divergente vai para a 2a', 2, (int)rc_ler($pdo, $idA)['rodada_item']);
t_null('sem coletor', rc_ler($pdo, $idA)['coletor_recontagem']);
t_eq('descartado fica', 1, (int)rc_ler($pdo, $idD)['rodada_item']);
t_eq('aparece como contando', 'contando', recontagem_situacao(rc_ler($pdo, $idA)));

// ── Inventário na 1ª não é tocado ──────────────────────────────────────────
rc_limpar($pdo);
rc_inventario($pdo, 'aberto', 1);
$idP = rc_linha($pdo, ['pub' => 'P', 'loc' => '1-1-1-A-1', 'qtd' => 5, 'c1' => 2, 'sit' => 'C']);
$pdo->exec($update);
t_eq('inventario na 1a fica na 1a', 1, (int)rc_ler($pdo, $idP)['rodada_item']);

rc_limpar($pdo);

t_fim();
