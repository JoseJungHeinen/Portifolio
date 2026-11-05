<?php
// @versao Cap_10 V_01_16/07/2026
declare(strict_types=1);

/**
 * ════════════════════════════════════════════════════════════════════
 *  ENDPOINT PÚBLICO — NF-e XML por chave  (módulo à parte do sistema)
 * ════════════════════════════════════════════════════════════════════
 *
 *  Uso:
 *    GET  /Notas/api/nfe.php?chave=<44 dígitos>
 *    Header:  X-API-Key: <api_key>
 *
 *  Respostas:
 *    200  → devolve o XML (application/xml) como download
 *    400  → chave inválida (≠ 44 dígitos)
 *    401  → API key ausente/incorreta
 *    403  → IP não autorizado
 *    404  → XML não encontrado para a chave
 *    405  → método diferente de GET
 *
 *  NÃO usa sessão, login nem CSRF do app. Autenticação = API key.
 *  Os XML ficam em storage/nfe_xml/{chave}.xml (pasta privada).
 * ════════════════════════════════════════════════════════════════════
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

/** Resposta de erro padronizada (JSON) + log + encerra. */
function nfe_erro(int $code, string $msg, string $chave = ''): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    nfe_log($code, $chave);
    echo json_encode(['ok' => false, 'erro' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Log leve de auditoria (append em storage/logs/nfe_api.log). */
function nfe_log(int $status, string $chave): void
{
    $dir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '-';
    $linha = sprintf("%s\t%s\t%d\t%s\n", date('c'), $ip, $status, $chave !== '' ? $chave : '-');
    @file_put_contents($dir . '/nfe_api.log', $linha, FILE_APPEND | LOCK_EX);
}

// ── 1. Somente GET ────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    nfe_erro(405, 'Método não permitido. Use GET.');
}

// ── 2. Bootstrap: constantes + DB + Settings ──────────────────────
//     A API key e os IPs são administrados na tela Geral → aba API
//     (tabela nf_configuracoes, grupo 'api'). Sem sessão/login.
require_once dirname(__DIR__, 2) . '/config/constants.php';

$apiKey   = (string) Settings::get('api.nfe_key', '');
$xmlDir   = STORAGE . '/nfe_xml';
$allowRaw = (string) Settings::get('api.nfe_allow_ips', '');
$allowIps = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $allowRaw) ?: [])));

// ── 3. Restrição de IP (opcional) ─────────────────────────────────
if (!empty($allowIps)) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, $allowIps, true)) {
        nfe_erro(403, 'IP não autorizado.');
    }
}

// ── 4. Autenticação por API key (comparação em tempo constante) ───
$recebida = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($apiKey === '' || !is_string($recebida) || !hash_equals($apiKey, $recebida)) {
    nfe_erro(401, 'Não autorizado — API key ausente ou inválida.');
}

// ── 5. Validar a chave (44 dígitos; só números evita path traversal) ─
$chaveRaw = $_GET['chave'] ?? ($_GET['chave_nf'] ?? '');
$chave    = preg_replace('/\D/', '', (string)$chaveRaw);
if (strlen($chave) !== 44) {
    nfe_erro(400, 'Chave inválida — informe os 44 dígitos da NF-e.', $chave);
}

// ── 6. Localizar o arquivo com segurança ──────────────────────────
$baseReal = realpath($xmlDir);
if ($baseReal === false) {
    // Pasta ainda não existe (Brobot ainda não populou) → tratar como não encontrado
    nfe_erro(404, 'XML não encontrado para esta chave.', $chave);
}
$path = $baseReal . DIRECTORY_SEPARATOR . $chave . '.xml';
$real = realpath($path);
if ($real === false || strncmp($real, $baseReal . DIRECTORY_SEPARATOR, strlen($baseReal) + 1) !== 0 || !is_file($real)) {
    nfe_erro(404, 'XML não encontrado para esta chave.', $chave);
}

// ── 7. Stream do XML ──────────────────────────────────────────────
nfe_log(200, $chave);
header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $chave . '.xml"');
header('Content-Length: ' . (string)filesize($real));
header('Cache-Control: private, no-store');
readfile($real);
exit;
