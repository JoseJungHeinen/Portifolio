<?php
// @versao Ver.001.280826
require_once __DIR__ . '/config_database.php';
session_start();

if (empty($_SESSION['logado'])) {
    http_response_code(401);
    exit;
}

$dados = json_decode(file_get_contents('php://input'), true);

$treinamento_id = (int)($dados['treinamento_id'] ?? 0);
$progresso      = (int)($dados['progresso'] ?? 0);
$status         = $dados['status'] ?? 'em_andamento';
$usuario_id     = (int)($_SESSION['usuario_id'] ?? 0); // mesma chave gravada no login (index.php) e lida em treinamentos_servidor.php

if ($treinamento_id <= 0 || $usuario_id <= 0) {
    http_response_code(400);
    exit;
}

$sql = "
INSERT INTO treinamento_progresso (usuario_id, treinamento_id, status, progresso, data_inicio, data_conclusao)
VALUES (?, ?, ?, ?, NOW(), IF(? = 'concluido', NOW(), NULL))
ON DUPLICATE KEY UPDATE
    status = VALUES(status),
    progresso = VALUES(progresso),
    data_conclusao = IF(VALUES(status) = 'concluido', NOW(), data_conclusao);
";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    file_put_contents('debug_trein.log', "ERRO PREPARE: " . $mysqli->error . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    exit;
}

$stmt->bind_param('iisis', $usuario_id, $treinamento_id, $status, $progresso, $status);

if (!$stmt->execute()) {
    file_put_contents('debug_trein.log', "ERRO EXECUTE: " . $stmt->error . PHP_EOL, FILE_APPEND);
} else {
    file_put_contents('debug_trein.log', print_r([
        'OK_INSERT'     => true,
        'usuario_id'    => $usuario_id,
        'treinamento_id'=> $treinamento_id,
        'status'        => $status,
        'progresso'     => $progresso,
    ], true), FILE_APPEND);
}

$stmt->close();

echo 'ok';
