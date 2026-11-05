<?php
session_start();

header('Content-Type: application/json');

if (empty($_SESSION['portal_logado'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Não autenticado.']);
    exit;
}

require_once __DIR__ . '/includes/federated_auth.php';
require_once __DIR__ . '/includes/preferencias_usuario.php';

$corpo  = json_decode(file_get_contents('php://input'), true);
$ordem  = is_array($corpo['ordem'] ?? null) ? $corpo['ordem'] : [];

$sistemas   = carregar_todos_sistemas();
$idsValidos = array_keys($sistemas);

$ordemFiltrada = filtrar_ordem_valida($ordem, $idsValidos);

salvar_ordem_sistemas($_SESSION['portal_email'] ?? '', $ordemFiltrada);

echo json_encode(['ok' => true]);
