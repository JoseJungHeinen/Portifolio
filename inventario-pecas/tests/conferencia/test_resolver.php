<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_oracle.php';
require 'conferencia_lib.php';
require __DIR__ . '/assert.php';

echo "test_resolver\n";

$ora = oracle_conectar();
if (!$ora) {
    echo "  SKIP: Apollo indisponivel\n";
    exit(0);
}

// Codigo publico exato.
t_eq('codigo publico resolve', 322208, conferencia_resolver_item($ora, 1, '063806'));

// Outro codigo publico, de item que existe em uma loja so.
t_eq('outro codigo publico resolve', 3, conferencia_resolver_item($ora, 1, '002405'));

// Codigo interno exato.
t_eq('codigo interno resolve', 322208, conferencia_resolver_item($ora, 1, '322208'));

// Um "codigo alternativo" e, ele mesmo, o codigo publico de um item real:
// bipar 7091115 mostra o item 64857, que e a peca na mao do operador.
t_eq('codigo alternativo e um item por si so', 64857, conferencia_resolver_item($ora, 1, '7091115'));

// Espacos em volta nao atrapalham o leitor.
t_eq('codigo com espacos resolve', 322208, conferencia_resolver_item($ora, 1, '  063806  '));

// Etiqueta com sufixo de variante e leitor que completa com zeros (2026-09-28).
// Antes, 000063806 caia na busca por codigo interno (63806), que e outro item.
t_eq('sufixo -01 sai',                     322208, conferencia_resolver_item($ora, 1, '063806-01'));
t_eq('sufixo 001 sem traco sai',           322208, conferencia_resolver_item($ora, 1, '063806001'));
t_eq('000 a mais nao vira codigo interno', 322208, conferencia_resolver_item($ora, 1, '000063806'));
t_eq('000 a mais com sufixo',              322208, conferencia_resolver_item($ora, 1, '000063806-01'));
t_eq('minuscula e \\r do leitor',          322208, conferencia_resolver_item($ora, 1, " 063806-01\r"));

$alfa = conferencia_resolver_item($ora, 1, '127003OF');
t_true('alfanumerico existe no Apollo',    $alfa !== null);
t_eq('000 a mais em alfanumerico',         $alfa, conferencia_resolver_item($ora, 1, '000127003OF'));
t_eq('000 a mais em alfanumerico com sufixo', $alfa, conferencia_resolver_item($ora, 1, '000127003OF-01'));

// Codigo publico do item resolvido (a busca da Tomada de Decisao usa este).
t_eq('pub: 000 a mais com sufixo',         '063806', conferencia_codigo_pub_resolvido($ora, 1, '000063806-01'));
t_eq('pub: codigo interno vira o publico', '063806', conferencia_codigo_pub_resolvido($ora, 1, '322208'));
t_null('pub: inexistente',                 conferencia_codigo_pub_resolvido($ora, 1, '000ZZZNAOEXISTE-01'));

// Nao existe.
t_null('codigo inexistente', conferencia_resolver_item($ora, 1, 'ZZZNAOEXISTE'));
t_null('inexistente com 000 e sufixo', conferencia_resolver_item($ora, 1, '000ZZZNAOEXISTE-01'));
t_null('codigo vazio', conferencia_resolver_item($ora, 1, '   '));

t_fim();
