<?php

$DB_HOST = 'localhost';
$DB_NAME = 'inventario';
$DB_USER = 'app_user';
$DB_PASS = 'TROQUE_NO_SERVIDOR';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    die('Erro de conexão com o banco: ' . htmlspecialchars($e->getMessage()));
}





