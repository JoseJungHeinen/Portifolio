<?php
declare(strict_types=1);

require_once __DIR__ . '/config_database.php';
session_start();

if (empty($_SESSION['logado'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/documentos_campos_resolver.php';

// template_ids pode vir como:
// ?template_ids=1
// ?template_ids=1,2,3
// ?template_ids[]=1&template_ids[]=2
$templateIdsRaw = $_GET['template_ids'] ?? [];

$ids = [];
if (is_array($templateIdsRaw)) {
    foreach ($templateIdsRaw as $v) {
        foreach (explode(',', (string)$v) as $p) {
            $p = (int)trim($p);
            if ($p > 0) $ids[$p] = true;
        }
    }
} else {
    foreach (explode(',', (string)$templateIdsRaw) as $p) {
        $p = (int)trim($p);
        if ($p > 0) $ids[$p] = true;
    }
}

$templateIds = array_keys($ids);

header('Content-Type: application/json; charset=utf-8');

try {
    $data = documentos_resolver_campos($mysqli, $templateIds);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Falha ao resolver campos (multi).',
        'detail' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
