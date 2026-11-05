<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'contagem_2_lib.php';
require __DIR__ . '/assert.php';

echo "test_resolver\n";

// Inventário fictício, fora da faixa real, para não sujar dado de produção.
$INV = 999999;

$pdo->prepare("DELETE FROM estoque WHERE id_inventario = ?")->execute([$INV]);

$inserir = function (string $pub, string $loc) use ($pdo, $INV) {
    $pdo->prepare("
        INSERT INTO estoque
            (id_inventario, empresa, revenda, item_estoque_pub, descricao_item_estoque,
             quantidade, cont_1, cont_2, locacao_zona, locacao_rua, locacao_estante,
             locacao_prateleira, locacao_numero, locacao_geral, situacao, coletor)
        VALUES (?, 1, 1, ?, 'TESTE', 1, 0, 0, '', '', '', '', '', ?, 'A', 0)
    ")->execute([$INV, $pub, $loc]);
};

$inserir('MK5001',   '1-1-1-A-1');   // termina legitimamente em 001
$inserir('60973001', '1-1-1-A-1');
$inserir('063806',   '1-1-1-A-1');   // numérico com zero à esquerda
$inserir('AT812',    '1-1-1-A-2');
$inserir('060921.',  '1-1-1-A-2');   // ponto no fim
$inserir('K123AA',   '1-1-1-A-2');
$inserir('52043',    '1-1-1-A-3');
$inserir('52043',    '1-1-1-A-4');   // mesmo item em duas locações

$r = fn($bipe, $loc = '1-1-1-A-1') => contagem2_resolver_codigo($pdo, $INV, $bipe, $loc);

// ── O bug: a 1ª contagem cortava 001 às cegas ─────────────────────────────
t_eq('MK5001 continua MK5001',                 'MK5001',   $r('MK5001'));
t_eq('60973001 continua 60973001',             '60973001', $r('60973001'));
t_eq('MK5001 com sufixo -01',                  'MK5001',   $r('MK5001-01'));

// ── Zero à esquerda cadastrado ─────────────────────────────────────────────
t_eq('063806 mantem o zero',                   '063806',   $r('063806'));
t_eq('063806 com sufixo',                      '063806',   $r('063806-01'));

// ── A regra de sempre continua valendo ─────────────────────────────────────
t_eq('sufixo -01 sai',                         'AT812',    $r('AT812-01', '1-1-1-A-2'));
t_eq('sufixo 001 sem traco sai',               '52043',    $r('52043001', '1-1-1-A-3'));
t_eq('ponto antes do traco',                   '060921.',  $r('060921.-01', '1-1-1-A-2'));
t_eq('regra do K',                             'K123AA',   $r('123AA-01', '1-1-1-A-2'));
t_eq('minuscula e espaco do leitor',           'AT812',    $r(" at812-01\r", '1-1-1-A-2'));

// ── Item que termina em zero, com sufixo 001 sem traço ─────────────────────
$inserir('100175350', '1-1-1-A-5');
$inserir('10017535',  '1-1-1-A-6');   // o corte errado também existe, em outra locação
t_eq('zero antes do sufixo 001 nao some',      '100175350', $r('100175350001', '1-1-1-A-5'));
t_eq('zero antes do sufixo, com traco',        '100175350', $r('100175350-01', '1-1-1-A-5'));
t_eq('corte mais longo so se for o da locacao', '10017535', $r('100175350001', '1-1-1-A-6'));
t_true('2a: zero antes do sufixo 001',         contagem2_bipe_confere('100175350', '100175350001'));

// ── Locação ────────────────────────────────────────────────────────────────
// Item de outra locação resolve mesmo assim, para a tela poder dizer onde ele fica.
t_eq('item de outra locacao ainda resolve',    'AT812',    $r('AT812-01', '1-1-1-A-1'));

// ── Não existe ─────────────────────────────────────────────────────────────
t_eq('inexistente volta o codigo limpo',       'XYZ999-01', $r('xyz999-01'));
t_eq('vazio volta vazio',                      '',          $r(''));

// ── Bipe da 2ª com zero à esquerda (mesmas variantes) ─────────────────────
t_true('2a: 063806 confere com 063806',        contagem2_bipe_confere('063806', '063806-01'));
t_true('2a: 063806 confere sem o zero',        contagem2_bipe_confere('063806', '63806'));

// ── Leitor que completa o código com zeros à esquerda (2026-09-28) ─────────
// O bipe trazia 000 na frente de código que já tem zero (063806) ou que tem
// letra (127003OF): as variantes só tiravam zero de código numérico, e só para
// chegar em nenhum zero — 000063806 virava 63806, que não é o cadastrado.
$inserir('127003OF', '1-1-1-A-7');
$inserir('0ABC',     '1-1-1-A-8');   // zero que faz parte do código
$inserir('ABC',      '1-1-1-A-8');
t_eq('000 a mais em codigo com zero',          '063806',   $r('000063806'));
t_eq('000 a mais com sufixo -01',              '063806',   $r('000063806-01'));
t_eq('000 a mais com sufixo 001',              '063806',   $r('000063806001'));
t_eq('000 a mais em codigo sem zero',          'AT812',    $r('000AT812', '1-1-1-A-2'));
t_eq('000 a mais em alfanumerico',             '127003OF', $r('000127003OF', '1-1-1-A-7'));
t_eq('000 a mais em alfanumerico com sufixo',  '127003OF', $r('000127003OF-01', '1-1-1-A-7'));
t_eq('000 a mais com ponto e sufixo',          '060921.',  $r('000060921.-01', '1-1-1-A-2'));
t_eq('existe exato: o zero nao e cortado',     '0ABC',     $r('0ABC', '1-1-1-A-8'));
t_eq('existe exato: sem zero continua',        'ABC',      $r('ABC', '1-1-1-A-8'));
t_eq('inexistente com 000 volta o codigo limpo', '000XYZ999', $r('000XYZ999'));

t_true('2a: 000 a mais em codigo com zero',    contagem2_bipe_confere('063806', '000063806'));
t_true('2a: 000 a mais com sufixo',            contagem2_bipe_confere('063806', '000063806-01'));
t_true('2a: 000 a mais em alfanumerico',       contagem2_bipe_confere('127003OF', '000127003OF-01'));
t_eq('2a: zero nao faz outro item passar', false, contagem2_bipe_confere('063806', '000063807'));

$pdo->prepare("DELETE FROM estoque WHERE id_inventario = ?")->execute([$INV]);

t_fim();
