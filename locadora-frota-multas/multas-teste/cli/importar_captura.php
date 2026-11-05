<?php
/**
 * Importa um .json do coletor sem tela (usado pelo coletor automático).
 *   php cli/importar_captura.php data/coletas/.../multas_detran_....json
 *
 * Captura completa (todos os CNPJs responderam) → cobre todas as lojas: finaliza as que saíram.
 * Captura incompleta → só entra novas/atualiza; nada é finalizado.
 * Imprime o resultado em JSON.
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/captura_db.php';

$arquivo = $argv[1] ?? '';
if (!is_file($arquivo)) { fwrite(STDERR, "arquivo não encontrado\n"); exit(1); }

$l = ler_captura_json($arquivo);
$completa = !array_filter($l['empresas'], fn($e) => !$e['ok']);
$lojas = $completa
    ? db()->query('SELECT DISTINCT l.nome FROM lojas l JOIN multas m ON m.loja_id = l.id')->fetchAll(PDO::FETCH_COLUMN)
    : [];
$r = processar_captura($l['cards'], $lojas, 'coletor automático · ' . basename($arquivo), true, 'api', $l['empresas']);

echo json_encode([
    'completa' => $completa,
    'capturaId' => $r['capturaId'],
    'lidas' => count($l['cards']),
    'novas' => count($r['novas']),
    'atualizadas' => count($r['atualizadas']),
    'sairam' => count($r['sairam']),
    'mudaramEtapa' => count($r['mudancasEtapa']),
], JSON_UNESCAPED_UNICODE);
