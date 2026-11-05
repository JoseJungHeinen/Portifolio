<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'contagem_2_lib.php';
require __DIR__ . '/assert.php';

echo "test_regras\n";

// ── Classificação ──────────────────────────────────────────────────────────
t_eq('contou menos que o sistema e Menor',  'menor',       contagem2_classificar(24, 12));
t_eq('contou mais que o sistema e Maior',   'maior',       contagem2_classificar(24, 30));
t_eq('bateu nao e divergente',              'ok',          contagem2_classificar(24, 24));
t_eq('cont_1 nulo e nao contado',           'nao_contado', contagem2_classificar(24, null));

// O gerador insere cont_1 = 0, entao item nunca contado com saldo cai em Menor,
// que e onde o dashboard ja o mostra hoje.
t_eq('nunca contado com saldo cai em Menor', 'menor', contagem2_classificar(24, 0));

// Zero contra zero nao e divergencia: nao tinha e nao achou.
t_eq('zero contra zero e ok', 'ok', contagem2_classificar(0, 0));

t_true('menor e divergente',       contagem2_e_divergente(24, 12));
t_true('maior e divergente',       contagem2_e_divergente(24, 30));
t_true('nao contado e divergente', contagem2_e_divergente(24, null));
t_eq('ok nao e divergente', false, contagem2_e_divergente(24, 24));
t_eq('zero contra zero nao e divergente', false, contagem2_e_divergente(0, 0));

// ── Limpeza do código do leitor ────────────────────────────────────────────
// Alguns leitores mandam caracteres de controle antes ou depois do código.
t_eq('tira espaco e controle', 'AT812', contagem2_limpar_codigo("  AT812\r\n"));
t_eq('sobe para maiuscula',    'AT812', contagem2_limpar_codigo('at812'));

// ── Bipe ───────────────────────────────────────────────────────────────────
t_true('bipe igual passa',                  contagem2_bipe_confere('AT812', 'AT812'));
t_true('bipe em minuscula passa',           contagem2_bipe_confere('AT812', 'at812'));
t_true('bipe com espaco do leitor passa',   contagem2_bipe_confere('AT812', ' AT812 '));
t_eq('bipe de outro item nao passa', false, contagem2_bipe_confere('AT812', '60986337'));
t_eq('bipe vazio nao passa',         false, contagem2_bipe_confere('AT812', ''));

// ── Entrada (rastro de como o código chegou) ───────────────────────────────
t_eq('bipado passa',   'bipado',   contagem2_exigir_entrada('bipado'));
t_eq('digitado passa', 'digitado', contagem2_exigir_entrada('digitado'));

$estourou = false;
try { contagem2_exigir_entrada('inventado'); } catch (InvalidArgumentException $e) { $estourou = true; }
t_true('entrada fora da lista estoura', $estourou);

$estourouNulo = false;
try { contagem2_exigir_entrada(null); } catch (InvalidArgumentException $e) { $estourouNulo = true; }
t_true('entrada ausente estoura', $estourouNulo);

// ── Rotação da fila pela locação inicial ───────────────────────────────────
// A fila chega na ordem de caminhada e carrega os campos decompostos: comparar
// a string de locacao_geral poria a rua 10 antes da rua 2.
$linha = fn(string $pub, string $z, string $r, string $e, string $p, string $n) => [
    'item_estoque_pub'   => $pub,
    'locacao_geral'      => "$z-$r-$e-$p-$n",
    'locacao_zona'       => $z,
    'locacao_rua'        => $r,
    'locacao_estante'    => $e,
    'locacao_prateleira' => $p,
    'locacao_numero'     => $n,
];

$fila = [
    $linha('A', '2', '1',  '1', 'A', '1'),
    $linha('B', '2', '4',  '4', 'B', '1'),
    $linha('C', '2', '10', '6', 'E', '1'),
];

$rot = contagem2_rotacionar($fila, '2-4-4-B-1');
t_eq('rotacao comeca na locacao pedida', 'B', $rot[0]['item_estoque_pub']);
t_eq('rotacao da a volta',               'A', $rot[2]['item_estoque_pub']);
t_eq('rotacao nao perde item',            3,  count($rot));

t_eq('locacao inicial vazia nao mexe na fila', 'A', contagem2_rotacionar($fila, '')[0]['item_estoque_pub']);
t_eq('fila vazia continua vazia', [], contagem2_rotacionar([], '2-4-4-B-1'));

// "Igual OU POSTERIOR": a dupla escolheu um corredor que ja nao tem divergencia
// pendente. Antes, isso a mandava em silencio para o topo do deposito.
t_eq('sem match exato, comeca na proxima locacao adiante', 'B',
     contagem2_rotacionar($fila, '2-3-9-Z-9')[0]['item_estoque_pub']);

// Ordem de caminhada, nao alfabetica: a rua 10 vem DEPOIS da rua 4, entao uma
// locacao na rua 5 tem que cair no item da rua 10, e nao voltar para o comeco.
t_eq('posterior segue o corredor, nao o alfabeto', 'C',
     contagem2_rotacionar($fila, '2-5-1-A-1')[0]['item_estoque_pub']);

// Depois da ultima locacao da fila: da a volta e comeca do inicio.
t_eq('depois da ultima, volta para o inicio', 'A',
     contagem2_rotacionar($fila, '9-9-9-Z-9')[0]['item_estoque_pub']);

// Locacao malformada nao pode derrubar a tela: devolve a fila como esta.
t_eq('locacao sem as cinco partes nao mexe na fila', 'A',
     contagem2_rotacionar($fila, 'abacaxi')[0]['item_estoque_pub']);

// ── Sufixo de variante na etiqueta (mesma regra da contagem.php) ────────────
t_true('sufixo -01 passa',                 contagem2_bipe_confere('AT812', 'AT812-01'));
t_true('sufixo -001 passa',                contagem2_bipe_confere('AT812', 'AT812-001'));
t_true('sufixo 001 sem traco passa',       contagem2_bipe_confere('52043', '52043001'));
t_true('traco longo (en dash) passa',      contagem2_bipe_confere('AT812', 'AT812–01'));
t_true('ponto antes do traco, item com ponto',   contagem2_bipe_confere('060921.', '060921.-01'));
t_true('ponto antes do traco, item sem ponto',   contagem2_bipe_confere('060921', '060921.-01'));
t_true('espaco antes do traco passa',      contagem2_bipe_confere('060921', '060921 -01'));
t_true('zeros a esquerda em numerico',     contagem2_bipe_confere('60986337', '060986337-01'));
t_true('regra do K: AA ganha K',           contagem2_bipe_confere('K123AA', '123AA-01'));
// Codigo que termina legitimamente em 001 continua passando inteiro.
t_true('MK5001 inteiro passa',             contagem2_bipe_confere('MK5001', 'MK5001'));
t_true('MK5001 com sufixo passa',          contagem2_bipe_confere('MK5001', 'MK5001-01'));
t_eq('sufixo nao faz outro item passar', false, contagem2_bipe_confere('AT812', 'AT813-01'));
t_eq('so o sufixo nao passa',            false, contagem2_bipe_confere('AT812', '-01'));

t_fim();
