<?php
declare(strict_types=1);

require __DIR__ . '/fixture.php';

echo "test_situacao\n";

rc_limpar($pdo);

$agora = '2026-09-24 10:00:00';

// Um caso por linha da tabela da seção 2 do spec. O nome é o esperado.
$casos = [
    'nao_contado'          => ['pub' => 'NC',   'qtd' => 5, 'c1' => 0, 'sit' => 'A', 'rod' => 1],
    'bateu na 1a'          => ['pub' => 'B1',   'qtd' => 5, 'c1' => 5, 'sit' => 'C', 'rod' => 1],
    'aguardando na 1a'     => ['pub' => 'AG1',  'qtd' => 5, 'c1' => 3, 'sit' => 'C', 'rod' => 1],
    'contando na 2a'       => ['pub' => 'CT2',  'qtd' => 5, 'c1' => 3, 'sit' => 'C', 'rod' => 2, 'col' => 1],
    'bateu na 2a'          => ['pub' => 'B2',   'qtd' => 7, 'c1' => 10, 'c2' => 7, 'c2em' => $agora, 'sit' => 'C', 'rod' => 2, 'col' => 1],
    // Só chega em decisão na 2ª com o teto antigo (RECONTAGEM_ULTIMA = 2); com
    // o teto padrão desta entrega (4), a mesma linha fica 'aguardando' — ver
    // abaixo, fora do laço genérico, com ultima = 2 explícito.
    'decisao na 2a'        => ['pub' => 'DE2',  'qtd' => 7, 'c1' => 10, 'c2' => 8, 'c2em' => $agora, 'sit' => 'C', 'rod' => 2, 'col' => 1],
    'descartado'           => ['pub' => 'DS',   'qtd' => 5, 'c1' => 3, 'sit' => 'D', 'rod' => 1],
    // Contagem não feita não bate, mesmo com o valor igual ao sistema: cont_2
    // nasce 0 no gerador, e sistema 0 com cont_2 = 0 não é "recontei e bateu".
    'zero nao feito'       => ['pub' => 'Z0',   'qtd' => 0, 'c1' => 3, 'sit' => 'C', 'rod' => 2, 'col' => 1],
    // Bate com qualquer contagem feita, não só a última.
    'bateu na 1a e 2a nao' => ['pub' => 'B1X',  'qtd' => 5, 'c1' => 5, 'c2' => 9, 'c2em' => $agora, 'sit' => 'C', 'rod' => 2, 'col' => 1],
    // Mandado para a 2ª sem nunca ter sido contado na 1ª.
    'contando sem 1a'      => ['pub' => 'CT0',  'qtd' => 5, 'c1' => 0, 'sit' => 'A', 'rod' => 2, 'col' => 2],
    'contando na 3a'       => ['pub' => 'CT3',  'qtd' => 5, 'c1' => 1, 'c2' => 2, 'c2em' => $agora, 'sit' => 'C', 'rod' => 3, 'col' => 1],
    'bateu na 3a'          => ['pub' => 'B3',   'qtd' => 5, 'c1' => 1, 'c2' => 2, 'c2em' => $agora, 'c3' => 5, 'c3em' => $agora, 'sit' => 'C', 'rod' => 3, 'col' => 1],
    'decisao na 4a'        => ['pub' => 'DE4',  'qtd' => 5, 'c1' => 1, 'c2' => 2, 'c2em' => $agora, 'c3' => 3, 'c3em' => $agora, 'c4' => 4, 'c4em' => $agora, 'sit' => 'C', 'rod' => 4, 'col' => 1],
    // Item com zero em estoque que ninguém contou ainda não pode "bater" via cont_1 = 0.
    'zero nao contado'     => ['pub' => 'Z00',  'qtd' => 0, 'c1' => 0, 'sit' => 'A', 'rod' => 1],
    // Ajuste 2026-09-24: o gestor decide quando parar. encerrada vem antes de
    // "contagem atual feita"/teto na ordem, mas depois de bateu.
    'aguardando encerrada' => ['pub' => 'EN1',  'qtd' => 5, 'c1' => 3, 'sit' => 'C', 'rod' => 1, 'encerrada_em' => $agora, 'encerrada_por' => 'Gestor'],
    'contando encerrada'   => ['pub' => 'EN2',  'qtd' => 5, 'c1' => 1, 'sit' => 'C', 'rod' => 2, 'col' => 1, 'encerrada_em' => $agora, 'encerrada_por' => 'Gestor'],
    // Encerrada, mas a contagem que já tinha bate com o sistema: bateu vence.
    'encerrada mas bateu'  => ['pub' => 'EN3',  'qtd' => 5, 'c1' => 5, 'sit' => 'C', 'rod' => 1, 'encerrada_em' => $agora, 'encerrada_por' => 'Gestor'],
];

$esperado = [
    'nao_contado' => 'nao_contado', 'bateu na 1a' => 'bateu', 'aguardando na 1a' => 'aguardando',
    'contando na 2a' => 'contando', 'bateu na 2a' => 'bateu', 'decisao na 2a' => 'aguardando',
    'descartado' => 'descartado', 'zero nao feito' => 'contando', 'bateu na 1a e 2a nao' => 'bateu',
    'contando sem 1a' => 'contando', 'contando na 3a' => 'contando', 'bateu na 3a' => 'bateu',
    'decisao na 4a' => 'decisao', 'zero nao contado' => 'nao_contado',
    'aguardando encerrada' => 'decisao', 'contando encerrada' => 'decisao', 'encerrada mas bateu' => 'bateu',
];

$ids = [];
foreach ($casos as $nome => $d) {
    $ids[$nome] = rc_linha($pdo, $d);
}

// ── Função PHP ─────────────────────────────────────────────────────────────
foreach ($ids as $nome => $id) {
    t_eq('php: ' . $nome, $esperado[$nome], recontagem_situacao(rc_ler($pdo, $id)));
}

// ── CASE SQL dá o mesmo resultado para as mesmas linhas ────────────────────
$stmt = $pdo->prepare("SELECT id_linha AS id, " . recontagem_sql_situacao() . " AS sit FROM estoque WHERE id_inventario = ?");
$stmt->execute([RC_INV]);
$sql = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'sit', 'id');

foreach ($ids as $nome => $id) {
    t_eq('sql: ' . $nome, $esperado[$nome], $sql[$id] ?? null);
}

// ── Teto: com o teto explícito em 2, a 2ª feita sem bater é "decisao" ──────
// (o antigo comportamento padrão desta linha, antes do ajuste que levou
// RECONTAGEM_ULTIMA a 4 — mantido passando ultima = 2 explícito.)
t_eq('teto 2: 2a feita sem bater decide', 'decisao', recontagem_situacao(rc_ler($pdo, $ids['decisao na 2a']), 2));
$stmt = $pdo->prepare("SELECT " . recontagem_sql_situacao(2) . " FROM estoque WHERE id_linha = ?");
$stmt->execute([$ids['decisao na 2a']]);
t_eq('teto 2 no sql', 'decisao', $stmt->fetchColumn());

// ── Teto: com a 4ª liberada (padrão desta entrega), a 2ª feita sem bater é "aguardando" ─
t_eq('teto 4: 2a feita sem bater aguarda', 'aguardando', recontagem_situacao(rc_ler($pdo, $ids['decisao na 2a']), 4));
$stmt = $pdo->prepare("SELECT " . recontagem_sql_situacao(4) . " FROM estoque WHERE id_linha = ?");
$stmt->execute([$ids['decisao na 2a']]);
t_eq('teto 4 no sql', 'aguardando', $stmt->fetchColumn());

t_eq('clamp: ultima 5 igual a 4', recontagem_situacao(rc_ler($pdo, $ids['decisao na 4a']), 4), recontagem_situacao(rc_ler($pdo, $ids['decisao na 4a']), 5));

// ── Valor que vale quando bateu ────────────────────────────────────────────
t_eq('valor da 2a quando bateu na 2a', 7, recontagem_valor_bateu(rc_ler($pdo, $ids['bateu na 2a'])));
t_eq('valor da 1a quando bateu na 1a', 5, recontagem_valor_bateu(rc_ler($pdo, $ids['bateu na 1a e 2a nao'])));
t_null('sem bater nao tem valor', recontagem_valor_bateu(rc_ler($pdo, $ids['decisao na 2a'])));

// ── Última contagem feita ──────────────────────────────────────────────────
t_eq('ultima feita e a 2a', 8, recontagem_ultima_feita(rc_ler($pdo, $ids['decisao na 2a'])));
t_eq('ultima feita e a 1a quando a 2a nao foi feita', 3, recontagem_ultima_feita(rc_ler($pdo, $ids['contando na 2a'])));
t_null('nao contado nao tem ultima', recontagem_ultima_feita(rc_ler($pdo, $ids['nao_contado'])));

rc_limpar($pdo);

t_fim();
