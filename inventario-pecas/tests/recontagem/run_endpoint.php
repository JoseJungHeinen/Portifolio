<?php
/**
 * Runner de um caso de endpoint que lê $_GET/$_POST (não php://input).
 *
 * Uso: php -d display_errors=0 tests/recontagem/run_endpoint.php <arquivo.json>
 * O arquivo tem as chaves endpoint, session, get e post.
 *
 * O caso vai por ARQUIVO porque no Windows o escapeshellarg() estraga JSON na
 * linha de comando. session_start() aqui é de propósito: o endpoint chama de
 * novo, vê a sessão ativa, e o $_SESSION montado abaixo sobrevive.
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
$_SERVER['REQUEST_METHOD'] = $_POST === [] ? 'GET' : 'POST';

$endpoint = basename((string)($entrada['endpoint'] ?? ''));
require $endpoint;
