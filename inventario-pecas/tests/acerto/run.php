<?php
/**
 * Runner de um caso do acerto_locacao.php.
 *
 * Uso: php -d display_errors=0 tests/acerto/run.php <caso.json>
 * O arquivo tem as chaves session e post (sem post, é GET). Vai por arquivo
 * porque no Windows o escapeshellarg() corrompe JSON.
 */
chdir(__DIR__ . '/../..');
session_start();

$arquivo = (string)($argv[1] ?? '');
$entrada = ($arquivo !== '' && is_file($arquivo))
    ? (json_decode((string)file_get_contents($arquivo), true) ?: [])
    : [];

$_SESSION = $entrada['session'] ?? [];
$_POST    = $entrada['post']    ?? [];
$_SERVER['REQUEST_METHOD'] = isset($entrada['post']) ? 'POST' : 'GET';

require 'acerto_locacao.php';
