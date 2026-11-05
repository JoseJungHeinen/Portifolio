<?php
require_once '../config/session.php';
exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;

$id    = (int)($_POST['id']     ?? 0);
$index = (int)($_POST['status'] ?? 0);

// Indices validos do ENUM atual (1-5)
if (!$id || $index < 1 || $index > 5) {
    http_response_code(400);
    exit('Chave invalida: id=' . $id . ' status=' . $index);
}

try {
    $pdo  = db();
    // Usa o indice numerico diretamente, sem depender de texto com acento
    $stmt = $pdo->prepare("UPDATE projetos SET status = $index, atualizado_em = NOW() WHERE id = ?");
    $stmt->execute([$id]);
    echo 'ok';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erro: ' . $e->getMessage();
}
