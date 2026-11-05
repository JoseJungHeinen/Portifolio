<?php
declare(strict_types=1);

require __DIR__ . '/fixture.php';

echo "test_contagem_final\n";

/** Apaga também as decisões do inventário de teste (o rc_limpar não mexe nelas). */
function cf_limpar(PDO $pdo): void
{
    rc_limpar($pdo);
    if (recontagem_tem_decisoes($pdo)) {
        $pdo->prepare("DELETE FROM inv_decisoes WHERE id_inventario = ?")->execute([RC_INV]);
    }
}

/** A contagem final da linha, pela expressão que o relatório e o dashboard usam. */
function cf_valor(PDO $pdo, int $id, bool $comDecisoes = true): ?int
{
    $cf   = recontagem_sql_contagem_final($comDecisoes);
    $stmt = $pdo->prepare("SELECT $cf FROM estoque WHERE id_linha = ?");
    $stmt->execute([$id]);
    $v = $stmt->fetchColumn();

    return $v === null ? null : (int)$v;
}

cf_limpar($pdo);

$agora = '2026-09-26 10:00:00';

// [linha, esperado]
$casos = [
    'nao contado: 0 do gerador'       => [['pub' => 'NC',  'qtd' => 5, 'c1' => 0, 'sit' => 'A'], 0],
    'so 1a contagem'                  => [['pub' => 'C1',  'qtd' => 5, 'c1' => 3, 'sit' => 'C'], 3],
    // cont_2 = 0 do gerador sem cont2_em não é recontagem.
    'mandado p/ 2a, ainda nao contou' => [['pub' => 'CT2', 'qtd' => 5, 'c1' => 3, 'sit' => 'C', 'rod' => 2, 'col' => 1], 3],
    '2a feita vale sobre a 1a'        => [['pub' => 'R2',  'qtd' => 7, 'c1' => 10, 'c2' => 8, 'c2em' => $agora, 'sit' => 'C', 'rod' => 2], 8],
    '2a feita com zero'               => [['pub' => 'R2Z', 'qtd' => 7, 'c1' => 10, 'c2' => 0, 'c2em' => $agora, 'sit' => 'C', 'rod' => 2], 0],
    '3a feita vale sobre a 2a'        => [['pub' => 'R3',  'qtd' => 5, 'c1' => 1, 'c2' => 2, 'c2em' => $agora, 'c3' => 3, 'c3em' => $agora, 'sit' => 'C', 'rod' => 3], 3],
    '4a feita vale sobre a 3a'        => [['pub' => 'R4',  'qtd' => 5, 'c1' => 1, 'c2' => 2, 'c2em' => $agora, 'c3' => 3, 'c3em' => $agora, 'c4' => 4, 'c4em' => $agora, 'sit' => 'C', 'rod' => 4], 4],
    'bateu na 2a'                     => [['pub' => 'B2',  'qtd' => 7, 'c1' => 10, 'c2' => 7, 'c2em' => $agora, 'sit' => 'C', 'rod' => 2], 7],
    // Bateu vence a última contagem, como na situação da grade.
    'bateu na 1a, 2a diferente'       => [['pub' => 'B1X', 'qtd' => 5, 'c1' => 5, 'c2' => 9, 'c2em' => $agora, 'sit' => 'C', 'rod' => 2], 5],
    'encerrada na 2a'                 => [['pub' => 'EN',  'qtd' => 5, 'c1' => 1, 'c2' => 2, 'c2em' => $agora, 'sit' => 'C', 'rod' => 2, 'encerrada_em' => $agora, 'encerrada_por' => 'Gestor'], 2],
    // Decisão da Tomada de Decisão grava em cont_1 e vence a recontagem.
    'decidido na Tomada de Decisao'   => [['pub' => 'DEC', 'qtd' => 5, 'c1' => 6, 'c2' => 2, 'c2em' => $agora, 'sit' => 'C', 'rod' => 2], 6],
];

$ids = [];
foreach ($casos as $nome => [$linha, $esperado]) {
    $ids[$nome] = rc_linha($pdo, $linha);
}

$temDecisoes = recontagem_tem_decisoes($pdo);
t_true('inv_decisoes existe neste banco', $temDecisoes);

if ($temDecisoes) {
    $pdo->prepare("
        INSERT INTO inv_decisoes (id_inventario, empresa, revenda, item_estoque_pub, decisao, qtd_ajuste, usuario)
        VALUES (?, ?, ?, 'DEC', 'ajuste_positivo', 6, 'teste')
    ")->execute([RC_INV, RC_EMP, RC_REV]);
    // Decisão que não grava cont_1 não conta.
    $pdo->prepare("
        INSERT INTO inv_decisoes (id_inventario, empresa, revenda, item_estoque_pub, decisao, qtd_ajuste, usuario)
        VALUES (?, ?, ?, 'R2', 'autorizar', 0, 'teste')
    ")->execute([RC_INV, RC_EMP, RC_REV]);
}

foreach ($casos as $nome => [, $esperado]) {
    t_eq($nome, $esperado, cf_valor($pdo, $ids[$nome]));
}

// Sem a inv_decisoes, a regra roda sem o EXISTS e a recontagem vale.
t_eq('sem inv_decisoes: recontagem vale', 2, cf_valor($pdo, $ids['decidido na Tomada de Decisao'], false));

// Alias: as colunas saem qualificadas pela tabela pedida.
$cfAlias = recontagem_sql_contagem_final(true, 'e');
$stmt = $pdo->prepare("SELECT $cfAlias FROM estoque e WHERE e.id_linha = ?");
$stmt->execute([$ids['2a feita vale sobre a 1a']]);
t_eq('com alias', 8, (int)$stmt->fetchColumn());

// Soma do jeito do dashboard (peças contadas) sobre o inventário de teste.
$cf  = recontagem_sql_contagem_final($temDecisoes);
$tot = $pdo->prepare("SELECT COALESCE(SUM(COALESCE($cf,0)),0) FROM estoque WHERE id_inventario = ?");
$tot->execute([RC_INV]);
t_eq('soma das pecas contadas', array_sum(array_column($casos, 1)), (int)$tot->fetchColumn());

cf_limpar($pdo);

t_fim();
