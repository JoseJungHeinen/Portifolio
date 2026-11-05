<?php
/**
 * Arquivo de configuração de conexão com o banco de dados
 * Inclua em TODAS as telas PHP que precisam de acesso ao banco com:
 *   require_once __DIR__ . '/config_database.php';
 */

// Conexão com o banco de dados
$mysqli = new mysqli('localhost', 'app_user', 'TROQUE_NO_SERVIDOR', 'Veiculos');

if ($mysqli->connect_errno) {
    die('Erro de conexão com o banco.');
}
?>
