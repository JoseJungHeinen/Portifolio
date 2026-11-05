<?php
require_once __DIR__ . '/config_database.php';
session_start();
if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    exit('Acesso negado');
}
if (empty($_POST['ordem']) || !is_array($_POST['ordem'])) {
    exit('Dados inválidos');
}

$ordem = $_POST['ordem'];

// atualiza ordem (10,20,30...)
$pos = 10;
$stmt = $mysqli->prepare("UPDATE menu_config SET ordem = ? WHERE id = ?");
foreach ($ordem as $id) {
    $id = (int)$id;
    $stmt->bind_param('ii', $pos, $id);
    $stmt->execute();
    $pos += 10;
}
$stmt->close();

echo 'Ordem salva com sucesso.';
