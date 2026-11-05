<?php
/**
 * Runner de um caso do sobras.php: o POST de gravação ou o GET da consulta.
 *
 * Uso: php -d display_errors=0 tests/contagem2/run_sobras.php <caso.json>
 * O arquivo tem as chaves session e post (gravação) ou get (consulta).
 *
 * O sobras.php lê $_POST, não php://input — por isso o caso inteiro vai por
 * arquivo, como no run_ajax.php da conferência.
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

require 'sobras.php';
