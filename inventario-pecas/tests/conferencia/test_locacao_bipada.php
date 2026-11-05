<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conferencia_lib.php';
require __DIR__ . '/assert.php';

echo "test_locacao_bipada\n";

// ── Leitura da etiqueta de locação ──────────────────────────────────────────

t_eq('etiqueta normal', '1-22-13-A-1', conferencia_ler_etiqueta_locacao('1-22-13-A-1'));
t_eq('minuscula vira maiuscula', '1-22-13-A-1', conferencia_ler_etiqueta_locacao('1-22-13-a-1'));
t_eq('espacos em volta somem', '1-22-13-A-1', conferencia_ler_etiqueta_locacao('  1 - 22-13-A -1 '));
t_eq('travessao do leitor conta como traco', '1-22-13-A-1', conferencia_ler_etiqueta_locacao('1–22–13–A–1'));
t_null('codigo de item nao e locacao', conferencia_ler_etiqueta_locacao('AT812'));
t_null('quatro partes nao e locacao', conferencia_ler_etiqueta_locacao('1-22-13-A'));
t_null('parte vazia nao e locacao', conferencia_ler_etiqueta_locacao('1--13-A-1'));
t_null('simbolo nao e locacao', conferencia_ler_etiqueta_locacao('1-22-13-F,-1'));
t_null('vazio nao e locacao', conferencia_ler_etiqueta_locacao(''));

// ── Comparação com a locação do Apollo ──────────────────────────────────────

t_eq('mesma locacao confere', 'confere', conferencia_situacao_locacao('1-22-13-A-1', '1-22-13-A-1'));
// O Apollo guarda rua/estante/numero como NUMBER: zero a esquerda na etiqueta
// e minuscula na prateleira nao fazem outra locacao.
t_eq('zero a esquerda confere', 'confere', conferencia_situacao_locacao('1-022-013-A-01', '1-22-13-A-1'));
t_eq('prateleira minuscula no Apollo confere', 'confere', conferencia_situacao_locacao('1-22-13-A-1', '1-22-13-a-1'));
t_eq('outra prateleira e outra locacao', 'outra', conferencia_situacao_locacao('1-22-13-A-1', '1-22-13-B-1'));
t_eq('outra rua e outra locacao', 'outra', conferencia_situacao_locacao('1-2-13-A-1', '1-22-13-A-1'));
t_eq('item sem locacao no Apollo', 'sem_locacao', conferencia_situacao_locacao('1-22-13-A-1', ''));
t_eq('locacao do Apollo so com espacos', 'sem_locacao', conferencia_situacao_locacao('1-22-13-A-1', '   '));
// Locacao fora do formato de cinco partes: compara o texto, e diferente e aviso.
t_eq('Apollo fora do formato e diferente', 'outra', conferencia_situacao_locacao('1-22-13-A-1', '1-22-13'));

t_fim();
