<?php
/**
 * Runner de um caso de teste do conferencia_ajax.php.
 *
 * Uso: php -d display_errors=0 tests/conferencia/run_ajax.php <arquivo.json>
 * O arquivo tem as chaves session, get e post.
 *
 * O caso vai por ARQUIVO, e não por argv, porque no Windows o escapeshellarg()
 * do PHP troca aspa dupla por espaço — passar JSON na linha de comando chega
 * corrompido do outro lado, e todo caso viraria "sessão expirada".
 *
 * session_start() aqui é de propósito: o endpoint chama session_start() de
 * novo, vê que já há sessão ativa, e o $_SESSION montado abaixo sobrevive.
 */
chdir(__DIR__ . '/../..');
session_start();

$arquivo = (string)($argv[1] ?? '');
$entrada = ($arquivo !== '' && is_file($arquivo))
    ? (json_decode((string)file_get_contents($arquivo), true) ?: [])
    : [];

$_SESSION = $entrada['session'] ?? [];
$_GET     = $entrada['get']     ?? [];
$_POST    = $entrada['post']    ?? [];

require 'contagem_2_ajax.php';
