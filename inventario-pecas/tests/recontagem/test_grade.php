<?php
declare(strict_types=1);

require __DIR__ . '/fixture.php';

echo "test_grade\n";

rc_limpar($pdo);
rc_inventario($pdo);

// Fora de ordem na inserção: a grade tem que sair na ordem de caminhada.
$idR10 = rc_linha($pdo, ['pub' => 'R10', 'loc' => '1-10-1-A-1', 'qtd' => 5, 'c1' => 2, 'sit' => 'C', 'custo' => 3]);
$idR2a = rc_linha($pdo, ['pub' => 'R2A', 'loc' => '1-2-1-A-1',  'qtd' => 5, 'c1' => 8, 'sit' => 'C', 'custo' => 10]);
$idR2b = rc_linha($pdo, ['pub' => 'R2B', 'loc' => '1-2-1-A-1',  'qtd' => 4, 'c1' => 4, 'sit' => 'C']);
$idNC  = rc_linha($pdo, ['pub' => 'NC',  'loc' => '1-3-1-A-1',  'qtd' => 6, 'sit' => 'A']);
$idCT  = rc_linha($pdo, ['pub' => 'CT',  'loc' => '1-3-1-A-2',  'qtd' => 6, 'c1' => 1, 'sit' => 'C', 'rod' => 2, 'col' => 2]);
$idDS  = rc_linha($pdo, ['pub' => 'DS',  'loc' => '1-3-1-A-3',  'qtd' => 6, 'c1' => 1, 'sit' => 'D']);
$idBx  = rc_linha($pdo, ['pub' => 'BX',  'loc' => '1-4-1-A-1',  'qtd' => 4, 'c1' => 4, 'sit' => 'C', 'c2' => 7, 'c2em' => '2026-09-24 10:00:00', 'rod' => 2, 'col' => 1, 'custo' => 100]);

$ids = fn(array $g) => array_column($g['linhas'], 'id');

// ── Filtro padrão: recontar (só aguardando) ────────────────────────────────
$g = recontagem_grade($pdo, RC_INV, RC_EMP, RC_REV, ['situacao' => 'recontar']);
t_eq('recontar, em ordem de caminhada (rua 2 antes da 10)', [$idR2a, $idR10], $ids($g));
t_eq('total do filtro', 2, $g['total']);
t_eq('nao cortou', false, $g['cortado']);
t_eq('locacoes do filtro, em ordem de caminhada', ['1-2-1-A-1', '1-10-1-A-1'], $g['locacoes']);

// Valor em divergência: |8-5|*10 + |2-5|*3 = 30 + 9.
t_eq('valor em divergencia do filtro', 39.0, $g['valor_divergencia']);

// ── Formato da linha ───────────────────────────────────────────────────────
$l = $g['linhas'][0];
t_eq('traz o codigo', 'R2A', $l['item']);
t_eq('traz a locacao', '1-2-1-A-1', $l['locacao']);
t_eq('contagens: so a 1a feita', [1 => 8, 2 => null, 3 => null, 4 => null], $l['contagens']);
t_eq('situacao', 'aguardando', $l['situacao']);
t_true('aguardando e marcavel', $l['marcavel']);

// ── Outros filtros (novos grupos) ───────────────────────────────────────────
// a_contar = contando + nao_contado
$ac = recontagem_grade($pdo, RC_INV, RC_EMP, RC_REV, ['situacao' => 'a_contar']);
t_eq('a_contar junta contando e nao_contado', [$idNC, $idCT], $ids($ac));
t_eq('a_contar: locacoes em ordem de caminhada', ['1-3-1-A-1', '1-3-1-A-2'], $ac['locacoes']);

// contado = bateu + decisao
$co = recontagem_grade($pdo, RC_INV, RC_EMP, RC_REV, ['situacao' => 'contado']);
t_eq('contado junta bateu e decisao', [$idR2b, $idBx], $ids($co));
t_eq('contado nao entra no valor', 0.0, $co['valor_divergencia']);

$todos = recontagem_grade($pdo, RC_INV, RC_EMP, RC_REV, ['situacao' => 'todos']);
t_eq('todos, sem o descartado', 6, $todos['total']);
t_eq('bateu nao e marcavel', false,
     array_values(array_filter($todos['linhas'], fn($x) => $x['id'] === $idR2b))[0]['marcavel']);
t_eq('todos: locacoes em ordem de caminhada, uma por locacao', 5, count($todos['locacoes']));
t_eq('todos: locacoes comecam na rua 2', '1-2-1-A-1', $todos['locacoes'][0]);
t_eq('todos: locacoes terminam na rua 10', '1-10-1-A-1', $todos['locacoes'][4]);

t_eq('filtro por contagem atual', [$idCT, $idBx],
     $ids(recontagem_grade($pdo, RC_INV, RC_EMP, RC_REV, ['situacao' => 'todos', 'rodada' => 2])));

t_eq('busca por item', [$idR10],
     $ids(recontagem_grade($pdo, RC_INV, RC_EMP, RC_REV, ['situacao' => 'todos', 'busca' => 'r10'])));
t_eq('busca por locacao', [$idR2a, $idR2b],
     $ids(recontagem_grade($pdo, RC_INV, RC_EMP, RC_REV, ['situacao' => 'todos', 'busca' => '1-2-1'])));

// Situação desconhecida cai no padrão (recontar), não em SQL montado com texto do navegador.
t_eq('situacao invalida vira recontar', [$idR2a, $idR10],
     $ids(recontagem_grade($pdo, RC_INV, RC_EMP, RC_REV, ['situacao' => "x' OR 1=1 --"])));

// ── Limite ─────────────────────────────────────────────────────────────────
$corte = recontagem_grade($pdo, RC_INV, RC_EMP, RC_REV, ['situacao' => 'todos'], 2);
t_eq('limite corta as linhas', 2, count($corte['linhas']));
t_eq('mas o total e o do filtro', 6, $corte['total']);
t_true('e avisa que cortou', $corte['cortado']);
t_eq('locacoes nao sao cortadas pelo limite', 5, count($corte['locacoes']));

// ── Resumo por situação (dashboard e chips) ────────────────────────────────
$r = recontagem_resumo($pdo, RC_INV, RC_EMP, RC_REV);
t_eq('resumo', ['nao_contado' => 1, 'contando' => 1, 'aguardando' => 2, 'bateu' => 2, 'decisao' => 0], $r);

rc_limpar($pdo);

t_fim();
