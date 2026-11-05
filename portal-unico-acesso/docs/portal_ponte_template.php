<?php
/**
 * portal_ponte.php — TEMPLATE
 *
 * Coloque este arquivo dentro da pasta do sistema no servidor real (ex:
 * /var/www/html/Projeto/portal_ponte.php ou /var/www/html/agendamento/portal_ponte.php).
 * Ele permite que o Portal Único (rodando em outro servidor) consulte/atualize
 * o banco de dados LOCAL deste sistema sem precisar abrir o MySQL pra conexão
 * remota — a consulta chega por HTTP, roda aqui dentro (localhost) e o
 * resultado volta em JSON.
 *
 * ANTES DE SUBIR: troque TOKEN e os dados de $DB abaixo.
 * Cada sistema (Projeto, Locadora) deve ter seu PRÓPRIO token, diferente.
 *
 * SEGURANÇA: quem tiver o token consegue rodar qualquer SQL neste banco —
 * trate esse token como uma senha de banco (não versionar, não compartilhar).
 * Só responde pra requisições vindas do IP do servidor do portal.
 */

const TOKEN         = 'TROQUE_POR_TOKEN_GERADO';
const IP_PERMITIDO  = 'IP_DO_SERVIDOR_DO_PORTAL'; // IP do servidor onde roda o Portal Único

$DB = [
    'host' => 'localhost',
    'name' => 'TROQUE_O_NOME_DO_BANCO',
    'user' => 'root',
    'pass' => 'TROQUE_A_SENHA',
];

header('Content-Type: application/json');

$ipCliente = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ipCliente !== IP_PERMITIDO) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'IP não autorizado']);
    exit;
}

if (!hash_equals(TOKEN, $_POST['token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Token inválido']);
    exit;
}

$sql    = $_POST['sql'] ?? '';
$params = json_decode($_POST['params'] ?? '[]', true);
$params = is_array($params) ? $params : [];

if ($sql === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'sql vazio']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$DB['host']};dbname={$DB['name']};charset=utf8mb4",
        $DB['user'],
        $DB['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    try {
        $rows = $stmt->fetchAll();
    } catch (\Throwable $e) {
        // comandos como UPDATE/INSERT não têm linhas pra buscar — tudo bem
    }

    echo json_encode(['ok' => true, 'rows' => $rows, 'affected' => $stmt->rowCount()]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
