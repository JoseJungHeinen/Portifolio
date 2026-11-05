<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_oracle.php';
require 'conferencia_lib.php';
require __DIR__ . '/assert.php';

echo "test_locacao\n";

// ── Validacao: pura, sem banco ──────────────────────────────────────────────
// As regras sao as das colunas do Apollo (pec_item_revenda), todas NOT NULL:
// zona VARCHAR2(2), rua NUMBER(2), estante NUMBER(3), prateleira VARCHAR2(3),
// numero NUMBER(3).

$ok = conferencia_validar_locacao([
    'zona' => '1', 'rua' => '11', 'estante' => '3', 'prateleira' => 'A', 'numero' => '1',
]);
t_eq('locacao valida nao tem erro', [], $ok['erros']);
t_eq('numericos viram inteiro', 11, $ok['valores']['rua']);
t_eq('texto fica texto', 'A', $ok['valores']['prateleira']);

// No Apollo existem 258 prateleiras em minuscula ('c' x79, 'a' x59...), contra
// milhares da mesma letra em maiuscula: e erro de digitacao, nao outra prateleira.
$min = conferencia_validar_locacao([
    'zona' => 'zz', 'rua' => '1', 'estante' => '1', 'prateleira' => 'c', 'numero' => '1',
]);
t_eq('prateleira minuscula vira maiuscula', 'C', $min['valores']['prateleira']);
t_eq('zona minuscula vira maiuscula', 'ZZ', $min['valores']['zona']);

$esp = conferencia_validar_locacao([
    'zona' => ' 1 ', 'rua' => ' 01 ', 'estante' => '003', 'prateleira' => ' f ', 'numero' => '4',
]);
t_eq('espacos e zeros a esquerda nao atrapalham', [], $esp['erros']);
t_eq('01 vira 1', 1, $esp['valores']['rua']);
t_eq('003 vira 3', 3, $esp['valores']['estante']);

// Cada regra, isolada.
$base = ['zona' => '1', 'rua' => '1', 'estante' => '1', 'prateleira' => 'A', 'numero' => '1'];
$erro = fn(array $troca) => conferencia_validar_locacao(array_merge($base, $troca))['erros'];

t_true('zona vazia e erro',              isset($erro(['zona' => ''])['zona']));
t_true('zona com 3 letras e erro',       isset($erro(['zona' => 'ABC'])['zona']));
t_true('rua vazia e erro',               isset($erro(['rua' => ''])['rua']));
t_true('rua com letra e erro',           isset($erro(['rua' => 'A'])['rua']));
t_true('rua 100 passa de NUMBER(2)',     isset($erro(['rua' => '100'])['rua']));
t_eq('rua 99 cabe', [], $erro(['rua' => '99']));
t_true('estante 1000 passa de NUMBER(3)', isset($erro(['estante' => '1000'])['estante']));
t_eq('estante 999 cabe', [], $erro(['estante' => '999']));
t_true('numero negativo e erro',         isset($erro(['numero' => '-1'])['numero']));
t_true('prateleira com 4 letras e erro', isset($erro(['prateleira' => 'ABCD'])['prateleira']));
// 'F,' existe no Apollo hoje: tecla escapando na digitacao.
t_true('prateleira com simbolo e erro',  isset($erro(['prateleira' => 'F,'])['prateleira']));

$varios = conferencia_validar_locacao(['zona' => '', 'rua' => 'X', 'estante' => '1', 'prateleira' => 'A', 'numero' => '']);
t_eq('todos os erros vem juntos, nao um por vez', ['zona', 'rua', 'numero'], array_keys($varios['erros']));

// A mensagem aparece na tela da dupla: tem que estar em portugues certo.
t_eq('zona e feminino', 'Preencha a zona.',   $varios['erros']['zona']);
t_eq('numero e masculino', 'Preencha o número.', $varios['erros']['numero']);

// ── Leitura e gravacao no Apollo ────────────────────────────────────────────
$ora = oracle_conectar();
if (!$ora) {
    echo "  SKIP (Apollo): indisponivel\n";
    t_fim();
}

// Fixture: 063806 = item 322208, com linha em Cidade A Fiat (revenda 1).
$ITEM = 322208;

$atual = conferencia_locacao_da_loja($ora, 1, 1, $ITEM);
t_true('le a locacao da loja', is_array($atual));
t_true('le os cinco campos', $atual !== null && count($atual) === 5);

// 5945181 = item 54594, sem linha na Jeep (revenda 5).
t_null('item sem linha na loja devolve null', conferencia_locacao_da_loja($ora, 1, 5, 54594));

// Gravacao SEM commit, desfeita com rollback: este e o Apollo de producao.
// Nao ha trigger em PEC_ITEM_REVENDA, entao o rollback desfaz tudo; e se o
// script cair no meio, o OCI8 desfaz o que nao foi comitado ao fechar a conexao.
$novo = $atual;
$novo['numero'] = ($atual['numero'] === 999) ? 998 : $atual['numero'] + 1;

try {
    conferencia_gravar_locacao($ora, 1, 1, $ITEM, $novo, false);
    $durante = conferencia_locacao_da_loja($ora, 1, 1, $ITEM);
    t_eq('dentro da transacao, a locacao mudou', $novo['numero'], $durante['numero']);
} finally {
    oci_rollback($ora);
}

$depois = conferencia_locacao_da_loja($ora, 1, 1, $ITEM);
t_eq('depois do rollback, o Apollo ficou como estava', $atual, $depois);

// Gravar numa loja onde o item nao tem linha: o UPDATE atinge zero linhas, e
// isso tem que ser erro — nao o "Sucesso!" que o acerto_locacao.php mostra.
$estourou = false;
try {
    conferencia_gravar_locacao($ora, 1, 5, 54594, $novo, false);
} catch (RuntimeException $e) {
    $estourou = true;
} finally {
    oci_rollback($ora);
}
t_true('gravar onde nao ha linha estoura, nao finge sucesso', $estourou);

t_fim();
