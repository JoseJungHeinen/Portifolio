<?php
header('Content-Type: application/json');

session_start();
if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    http_response_code(401);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Não autorizado']);
    exit;
}

$mysqli = new mysqli('localhost', 'app_user', 'TROQUE_NO_SERVIDOR','Veiculos');
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Erro de conexão']);
    exit;
}

$body  = file_get_contents('php://input');
$dados = json_decode($body, true);

if (!is_array($dados) || empty($dados['ordem'])) {
    http_response_code(400);
    echo json_encode(['status' => 'erro', 'mensagem' => 'Payload inválido']);
    exit;
}

$ordens = $dados['ordem']; // ex: { "1":1, "2":2, "3":3 }

$mysqli->begin_transaction();
try {
    $stmt = $mysqli->prepare("
        UPDATE menu_config
           SET ordem = ?
         WHERE id = ?
    ");
    if (!$stmt) {
        throw new Exception('Erro no prepare: '.$mysqli->error);
    }

    foreach ($ordens as $idMenu => $ordemMenu) {
        $id  = (int)$idMenu;
        $ord = (int)$ordemMenu;
        $stmt->bind_param('ii', $ord, $id);
        $stmt->execute();
    }
    $stmt->close();

    $mysqli->commit();
    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    $mysqli->rollback();
    http_response_code(500);
    echo json_encode(['status' => 'erro', 'mensagem' => $e->getMessage()]);
}
