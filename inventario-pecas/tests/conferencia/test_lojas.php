<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'conferencia_lib.php';
require __DIR__ . '/assert.php';

echo "test_lojas\n";

// Cidade A tem duas lojas: Fiat (revenda 1) e Jeep (revenda 5), nessa ordem.
$lajeadoFiat = conferencia_lojas_da_cidade($pdo, 1, 1);
t_eq('lajeado tem 2 lojas', 2, count($lajeadoFiat));
t_eq('primeira loja e a revenda 1', 1, $lajeadoFiat[0]['revenda']);
t_eq('primeira loja e Fiat', 'Fiat', $lajeadoFiat[0]['nome']);
t_true('primeira loja e a atual', $lajeadoFiat[0]['atual']);
t_eq('segunda loja e a revenda 5', 5, $lajeadoFiat[1]['revenda']);
t_eq('segunda loja e Jeep', 'Jeep', $lajeadoFiat[1]['nome']);
t_eq('segunda loja nao e a atual', false, $lajeadoFiat[1]['atual']);

// A mesma cidade, entrando pela outra loja: muda quem e "atual", nao a lista.
$lajeadoJeep = conferencia_lojas_da_cidade($pdo, 1, 5);
t_eq('lajeado pelo jeep tambem tem 2 lojas', 2, count($lajeadoJeep));
t_eq('pelo jeep, a revenda 1 nao e a atual', false, $lajeadoJeep[0]['atual']);
t_true('pelo jeep, a revenda 5 e a atual', $lajeadoJeep[1]['atual']);

// Cidade C tem uma loja so.
$montenegro = conferencia_lojas_da_cidade($pdo, 1, 4);
t_eq('montenegro tem 1 loja', 1, count($montenegro));
t_eq('montenegro e a revenda 4', 4, $montenegro[0]['revenda']);

// Santa Cruz do Sul tem uma loja so.
$santaCruz = conferencia_lojas_da_cidade($pdo, 1, 8);
t_eq('santa cruz tem 1 loja', 1, count($santaCruz));
t_eq('santa cruz e a revenda 8', 8, $santaCruz[0]['revenda']);

// Loja que nao existe em inv_lojas nao inventa cidade.
t_eq('revenda inexistente devolve lista vazia', [], conferencia_lojas_da_cidade($pdo, 1, 99));

// Montagem da locacao.
t_eq(
    'locacao completa',
    '1-11-3-A-1',
    conferencia_montar_locacao([
        'locacao_zona'       => '1',
        'locacao_rua'        => '11',
        'locacao_estante'    => '3',
        'locacao_prateleira' => 'A',
        'locacao_numero'     => '1',
    ])
);
t_eq(
    'campos vazios sao descartados',
    '1-11',
    conferencia_montar_locacao([
        'locacao_zona'       => '1',
        'locacao_rua'        => ' 11 ',
        'locacao_estante'    => '',
        'locacao_prateleira' => '   ',
        'locacao_numero'     => null,
    ])
);
t_eq('locacao toda vazia vira string vazia', '', conferencia_montar_locacao([]));

t_fim();
