<?php
declare(strict_types=1);

/**
 * Ajuste de quantidades: o ajuste cai na última contagem feita do item — a
 * mesma que o relatório e o dashboard leem (recontagem_sql_contagem_final).
 */

require __DIR__ . '/fixture.php';

echo "test_ajuste\n";

rc_limpar($pdo);

$agora = '2026-09-28 10:00:00';

$id1  = rc_linha($pdo, ['pub' => 'AJ1', 'loc' => '1-1-1-A-1', 'qtd' => 5, 'c1' => 3, 'sit' => 'C']);
$id2  = rc_linha($pdo, ['pub' => 'AJ2', 'loc' => '1-1-1-A-2', 'qtd' => 5, 'c1' => 3, 'c2' => 4, 'c2em' => $agora, 'sit' => 'C', 'rod' => 2]);
$id3  = rc_linha($pdo, ['pub' => 'AJ3', 'loc' => '1-1-1-A-3', 'qtd' => 5, 'c1' => 1, 'c2' => 2, 'c2em' => $agora, 'c3' => 3, 'c3em' => $agora, 'sit' => 'C', 'rod' => 3]);
// Mandado para a 2ª, mas a 2ª ainda não foi feita: o cont_2 = 0 do gerador não conta.
$id2p = rc_linha($pdo, ['pub' => 'AJ2P', 'loc' => '1-1-1-A-4', 'qtd' => 5, 'c1' => 3, 'sit' => 'C', 'rod' => 2, 'col' => 1]);
$idNc = rc_linha($pdo, ['pub' => 'AJNC', 'loc' => '1-1-1-A-5', 'qtd' => 5, 'c1' => 0, 'sit' => 'A']);

// ── Qual contagem ──────────────────────────────────────────────────────────
t_eq('so a 1a feita: ajusta a 1a',        1, recontagem_numero_ultima_feita(rc_ler($pdo, $id1)));
t_eq('2a feita: ajusta a 2a',             2, recontagem_numero_ultima_feita(rc_ler($pdo, $id2)));
t_eq('3a feita: ajusta a 3a',             3, recontagem_numero_ultima_feita(rc_ler($pdo, $id3)));
t_eq('2a pendente: ajusta a 1a',          1, recontagem_numero_ultima_feita(rc_ler($pdo, $id2p)));
t_eq('nao contado: ajusta a 1a',          1, recontagem_numero_ultima_feita(rc_ler($pdo, $idNc)));

$ajustar = function (string $pub, string $loc, int $qtd) use ($pdo) {
    return recontagem_ajustar_ultima($pdo, RC_INV, RC_EMP, RC_REV, $pub, $loc, $qtd);
};

$cf = recontagem_sql_contagem_final(false);
$final = function (int $id) use ($pdo, $cf): int {
    $stmt = $pdo->prepare("SELECT $cf FROM estoque WHERE id_linha = ?");
    $stmt->execute([$id]);
    return (int)$stmt->fetchColumn();
};

// ── Depois da 2ª: ajusta a 2ª ──────────────────────────────────────────────
$r = $ajustar('AJ2', '1-1-1-A-2', 6);
t_eq('ajuste na 2a devolve n', 2, $r['contagem']);
t_eq('uma linha', 1, $r['linhas']);
$l = rc_ler($pdo, $id2);
t_eq('cont_2 ajustado', 6, (int)$l['cont_2']);
t_eq('cont_1 intacto', 3, (int)$l['cont_1']);
t_eq('cont2_em continua (a 2a continua feita)', $agora, (string)$l['cont2_em']);
t_eq('relatorio le o ajuste', 6, $final($id2));

// ── Depois da 3ª: ajusta a 3ª ──────────────────────────────────────────────
$r = $ajustar('AJ3', '1-1-1-A-3', 9);
t_eq('ajuste na 3a devolve n', 3, $r['contagem']);
t_eq('cont_3 ajustado', 9, (int)rc_ler($pdo, $id3)['cont_3']);
t_eq('cont_2 intacto', 2, (int)rc_ler($pdo, $id3)['cont_2']);
t_eq('relatorio le o ajuste da 3a', 9, $final($id3));

// ── Sem recontagem: continua ajustando a 1ª ────────────────────────────────
$r = $ajustar('AJ1', '1-1-1-A-1', 4);
t_eq('ajuste na 1a devolve n', 1, $r['contagem']);
t_eq('cont_1 ajustado', 4, (int)rc_ler($pdo, $id1)['cont_1']);

$r = $ajustar('AJ2P', '1-1-1-A-4', 2);
t_eq('2a pendente: ajusta a 1a', 1, $r['contagem']);
t_eq('cont_1 ajustado', 2, (int)rc_ler($pdo, $id2p)['cont_1']);
t_eq('cont_2 do gerador intacto', 0, (int)rc_ler($pdo, $id2p)['cont_2']);
t_null('cont2_em continua nulo', rc_ler($pdo, $id2p)['cont2_em']);

// ── Linha que não existe ───────────────────────────────────────────────────
$r = $ajustar('NAOEXISTE', '1-1-1-A-1', 1);
t_eq('inexistente: nenhuma linha', 0, $r['linhas']);
t_null('inexistente: sem contagem', $r['contagem']);

$ok = false;
try {
    $ajustar('AJ1', '1-1-1-A-1', -1);
} catch (InvalidArgumentException $e) {
    $ok = true;
}
t_true('quantidade negativa recusada', $ok);

rc_limpar($pdo);

t_fim();
