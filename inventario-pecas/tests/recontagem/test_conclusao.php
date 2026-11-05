<?php
declare(strict_types=1);

require __DIR__ . '/fixture.php';

echo "test_conclusao\n";

// Pura, sem banco: só monta o array que recontagem_resumo devolveria.
function rc_resumo(int $nc, int $ct, int $ag, int $ba, int $de): array
{
    return ['nao_contado' => $nc, 'contando' => $ct, 'aguardando' => $ag, 'bateu' => $ba, 'decisao' => $de];
}

// Tudo fechado, com item contado: concluída.
$c = recontagem_conclusao(rc_resumo(0, 0, 0, 3, 2));
t_true('concluida quando nada falta e algo foi contado', $c['concluida']);
t_eq('contados soma bateu + decisao', 5, $c['contados']);
t_eq('bateu', 3, $c['bateu']);
t_eq('decisao', 2, $c['decisao']);
t_eq('falta_recontar zerado', 0, $c['falta_recontar']);
t_eq('falta_contando zerado', 0, $c['falta_contando']);
t_eq('falta_nunca_contados zerado', 0, $c['falta_nunca_contados']);

// Falta recontar (aguardando > 0): não concluída.
$c = recontagem_conclusao(rc_resumo(0, 0, 1, 3, 2));
t_true('nao concluida com aguardando', !$c['concluida']);
t_eq('falta_recontar reflete o aguardando', 1, $c['falta_recontar']);

// Falta com coletor (contando > 0): não concluída.
$c = recontagem_conclusao(rc_resumo(0, 1, 0, 3, 2));
t_true('nao concluida com contando', !$c['concluida']);
t_eq('falta_contando reflete o contando', 1, $c['falta_contando']);

// Falta nunca contado (nao_contado > 0): não concluída.
$c = recontagem_conclusao(rc_resumo(1, 0, 0, 3, 2));
t_true('nao concluida com nao_contado', !$c['concluida']);
t_eq('falta_nunca_contados reflete o nao_contado', 1, $c['falta_nunca_contados']);

// Nada contado ainda: tudo zerado, ou só nao_contado — não é "concluída".
$c = recontagem_conclusao(rc_resumo(0, 0, 0, 0, 0));
t_true('resumo vazio nao e concluido', !$c['concluida']);
t_eq('contados zero', 0, $c['contados']);

$c = recontagem_conclusao(rc_resumo(10, 0, 0, 0, 0));
t_true('so nao_contado nao e concluido', !$c['concluida']);

t_fim();
