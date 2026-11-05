<?php
// @versao Asg_03 V_01_17/08/2026
declare(strict_types=1);

/**
 * ════════════════════════════════════════════════════════════════════
 *  ENDPOINT PÚBLICO — retorno da assinatura eletrônica (Assinafy)
 * ════════════════════════════════════════════════════════════════════
 *
 *  ⚠ ESTE ENDPOINT É UMA CAMPAINHA, NÃO UMA FONTE DE VERDADE. ⚠
 *
 *  A API da Assinafy NÃO assina os webhooks: não há HMAC, header de
 *  assinatura nem segredo compartilhado (levantado contra a spec e a API real
 *  em 17/08/2026). Ou seja, qualquer um que descubra a URL pode postar aqui o
 *  que quiser. Por isso NADA do corpo recebido é usado para decidir:
 *
 *    1. extraímos apenas o ID do documento;
 *    2. conferimos que esse documento é de um processo NOSSO;
 *    3. reconsultamos a Assinafy COM A NOSSA CHAVE (Assinatura::sincronizar);
 *    4. é a resposta autenticada — e só ela — que aprova, devolve ou ignora.
 *
 *  Um POST forjado no máximo provoca uma reconsulta a mais. Não move processo.
 *
 *  Camadas extras, já que o corpo não é confiável:
 *    · token secreto obrigatório na querystring (assinafy.webhook_token);
 *    · allowlist de IP opcional (assinafy.allow_ips);
 *    · log de tudo em storage/logs/assinafy_api.log.
 *
 *  Mesmo assim o endpoint é OPCIONAL: cli/assinafy_sync.php faz o mesmo
 *  trabalho por polling. O webhook só reduz a latência.
 *
 *  URL a cadastrar na Assinafy (Minha Conta → Webhooks):
 *    https://app.exemplo.local/<segredo>
 *    ⚠ No Server-APP a aplicação responde em /Notas/, NÃO na raiz.
 *
 *  Respostas: 200 ok · 400 corpo sem documento · 401 token · 403 IP ·
 *             404 documento desconhecido · 500 erro interno
 * ════════════════════════════════════════════════════════════════════
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function asg_log(int $status, string $evento, string $detalhe): void
{
    $dir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $linha = sprintf(
        "%s\t%s\t%d\t%s\t%s\n",
        date('c'),
        $_SERVER['REMOTE_ADDR'] ?? '-',
        $status,
        $evento !== '' ? $evento : '-',
        $detalhe !== '' ? $detalhe : '-'
    );
    @file_put_contents($dir . '/assinafy_api.log', $linha, FILE_APPEND | LOCK_EX);
}

function asg_json(int $code, array $payload, string $evento = '-', string $detalhe = ''): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    asg_log($code, $evento, $detalhe);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Bootstrap ──────────────────────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/constants.php';
require_once ROOT . '/src/Models/Assinatura.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    asg_json(405, ['ok' => false, 'erro' => 'Use POST.']);
}

// ── Restrição de IP (opcional) ─────────────────────────────────────
$allowRaw = (string) Settings::get('assinafy.allow_ips', '');
$allowIps = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $allowRaw) ?: [])));
if (!empty($allowIps) && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowIps, true)) {
    asg_json(403, ['ok' => false, 'erro' => 'IP não autorizado.']);
}

// ── Token secreto ──────────────────────────────────────────────────
// Não substitui a reconsulta autenticada — é só para o endpoint não ficar
// aberto ao mundo. hash_equals evita comparação em tempo variável.
$token    = (string) Settings::get('assinafy.webhook_token', '');
$recebido = (string) ($_GET['token'] ?? ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? ''));
if ($token === '' || !hash_equals($token, $recebido)) {
    asg_json(401, ['ok' => false, 'erro' => 'Token ausente ou inválido.']);
}

// ── Corpo: só o ID do documento nos interessa ──────────────────────
$bruto = file_get_contents('php://input') ?: '';
$corpo = json_decode($bruto, true);
if (!is_array($corpo)) {
    $corpo = $_POST ?: [];
}

$evento = (string) ($corpo['event'] ?? ($corpo['event_type'] ?? ''));

/** Procura o id do documento nos formatos que o provedor pode usar. */
$docId = '';
foreach ([
    $corpo['document_id']       ?? null,
    $corpo['document']['id']    ?? null,
    $corpo['data']['document_id'] ?? null,
    $corpo['data']['document']['id'] ?? null,
    $corpo['data']['id']        ?? null,
    $corpo['id']                ?? null,
] as $cand) {
    if (is_string($cand) && trim($cand) !== '') { $docId = trim($cand); break; }
}

if ($docId === '') {
    asg_json(400, ['ok' => false, 'erro' => 'Corpo sem identificador de documento.'],
        $evento, mb_substr($bruto, 0, 300, 'UTF-8'));
}

// ── O documento é de um processo nosso? ────────────────────────────
$assinatura = Assinatura::porDocumentoExterno($docId);
if ($assinatura === null) {
    // Não é erro do remetente: pode ser documento criado direto no painel da
    // Assinafy, fora do Notas. Respondemos 404 e seguimos a vida.
    asg_json(404, ['ok' => false, 'erro' => 'Documento não vinculado a nenhum processo.'],
        $evento, $docId);
}

// ── A decisão: reconsulta autenticada ──────────────────────────────
try {
    $status = Assinatura::sincronizar((string) $assinatura['id']);
} catch (\Throwable $e) {
    asg_json(500, ['ok' => false, 'erro' => 'Falha ao sincronizar.'],
        $evento, $docId . ' :: ' . $e->getMessage());
}

asg_json(200, [
    'ok'       => true,
    'processo' => $assinatura['processo_id'],
    'status'   => $status,
], $evento, $docId . ' -> ' . $status);
