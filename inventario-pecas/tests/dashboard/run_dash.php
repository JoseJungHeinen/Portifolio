<?php
/**
 * Runner de um caso do dashboard_inventario.php.
 * Uso: php run_dash.php <arquivo.json> — com {"session": {...}, "get": {...}}.
 *
 * O caso vem por arquivo porque, no Windows, escapeshellarg() destrói as aspas
 * do JSON; e o CLI não popula $_GET, então é preenchido aqui.
 */

chdir(__DIR__ . '/../..');

$caso = json_decode((string)file_get_contents($argv[1]), true);

session_id('testedash' . getmypid());
@session_start();
$_SESSION = $caso['session'] ?? [];
session_write_close();

$_GET                      = $caso['get'] ?? [];
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
require 'dashboard_inventario.php';
