<?php
/**
 * Runner de um caso do ajuste_quantidades.php.
 *
 * Uso: php -d display_errors=0 tests/recontagem/run_ajuste.php <caso.json>
 * O arquivo tem as chaves session, get e post (com post, é POST). Vai por
 * arquivo porque no Windows o escapeshellarg() corrompe JSON.
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
$_SERVER['REQUEST_METHOD'] = isset($entrada['post']) ? 'POST' : 'GET';
$_SERVER['PHP_SELF']       = '/ajuste_quantidades.php';

require 'ajuste_quantidades.php';
