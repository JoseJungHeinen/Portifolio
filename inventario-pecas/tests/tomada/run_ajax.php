<?php
/**
 * Runner de um caso do tomada_decisao_ajax.php.
 *
 * Uso: php -d display_errors=0 tests/tomada/run_ajax.php <caso.json>
 * O arquivo tem as chaves session e get. Vai por arquivo, e não por argv,
 * porque no Windows o escapeshellarg() corrompe JSON (ver run_ajax.php da
 * conferência).
 */
chdir(__DIR__ . '/../..');
session_start();

$arquivo = (string)($argv[1] ?? '');
$entrada = ($arquivo !== '' && is_file($arquivo))
    ? (json_decode((string)file_get_contents($arquivo), true) ?: [])
    : [];

$_SESSION = $entrada['session'] ?? [];
$_GET     = $entrada['get']     ?? [];
$_SERVER['REQUEST_METHOD'] = 'GET';

require 'tomada_decisao_ajax.php';
