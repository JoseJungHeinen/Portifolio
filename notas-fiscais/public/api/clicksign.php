<?php
// @versao Clk_01 V_01_01/10/2026
declare(strict_types=1);

/**
 * ════════════════════════════════════════════════════════════════════
 *  ENDPOINT PÚBLICO — retorno da assinatura eletrônica (Clicksign)
 * ════════════════════════════════════════════════════════════════════
 *
 *  ⚠ ESTE ENDPOINT É UMA CAMPAINHA, NÃO UMA FONTE DE VERDADE. ⚠
 *
 *  Mesmo princípio de public/api/assinafy.php: NADA do corpo recebido decide
 *  alguma coisa. Só extraímos o ID do envelope, conferimos que é de um processo
 *  NOSSO (provedor 'clicksign') e reconsultamos a Clicksign COM A NOSSA CHAVE
 *  (Assinatura::sincronizar). Um POST forjado no máximo causa uma consulta a mais.
 *
 *  A validação HMAC nativa da Clicksign NÃO foi implementada (a Locadora também
 *  não — a página de segurança de webhooks não estava acessível na leitura da
 *  doc), e o formato exato do payload ainda não foi visto em produção: por isso
 *  o ID do envelope é procurado em vários caminhos e o corpo bruto vai para o
 *  log quando nenhum serve. O worker (cli/assinafy_sync.php) continua sendo a
 *  rede de segurança — este endpoint só reduz a latência.
 *
 *  Camadas: token secreto na querystring (clicksign.webhook_token), allowlist de
 *  IP opcional (clicksign.allow_ips) e log em storage/logs/clicksign_api.log.
 *
 *  URL a cadastrar na Clicksign (Configurações → Webhooks):
 *    https://app.exemplo.local/<segredo>
 *
 *  Respostas: 200 ok · 400 corpo sem envelope · 401 token · 403 IP ·
 *             404 envelope desconhecido · 405 método · 500 erro interno
 * ════════════════════════════════════════════════════════════════════
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function clk_log(int $status, string $evento, string $detalhe): void
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
    @file_put_contents($dir . '/clicksign_api.log', $linha, FILE_APPEND | LOCK_EX);
}

function clk_json(int $code, array $payload, string $evento = '-', string $detalhe = ''): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    clk_log($code, $evento, $detalhe);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once dirname(__DIR__, 2) . '/config/constants.php';
require_once ROOT . '/src/Models/Assinatura.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    clk_json(405, ['ok' => false, 'erro' => 'Use POST.']);
}

$allowRaw = (string) Settings::get('clicksign.allow_ips', '');
$allowIps = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $allowRaw) ?: [])));
if (!empty($allowIps) && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowIps, true)) {
    clk_json(403, ['ok' => false, 'erro' => 'IP não autorizado.']);
}

$token    = (string) Settings::get('clicksign.webhook_token', '');
$recebido = (string) ($_GET['token'] ?? ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? ''));
if ($token === '' || !hash_equals($token, $recebido)) {
    clk_json(401, ['ok' => false, 'erro' => 'Token ausente ou inválido.']);
}

$bruto = file_get_contents('php://input') ?: '';
$corpo = json_decode($bruto, true);
if (!is_array($corpo)) {
    $corpo = $_POST ?: [];
}

$evento = (string) ($corpo['event']['name'] ?? ($corpo['event'] ?? ($corpo['event_type'] ?? '')));
if (!is_string($evento)) { $evento = ''; }

/** Procura o id do envelope nos formatos que a Clicksign pode usar (v2 e v3). */
$envId = '';
foreach ([
    $corpo['envelope']['id']                  ?? null,
    $corpo['envelope']['key']                 ?? null,
    $corpo['document']['envelope_id']         ?? null,
    $corpo['data']['envelope']['id']          ?? null,
    $corpo['data']['relationships']['envelope']['data']['id'] ?? null,
    $corpo['data']['attributes']['envelope_id'] ?? null,
    $corpo['data']['id']                      ?? null,
    $corpo['envelope_id']                     ?? null,
] as $cand) {
    if (is_string($cand) && trim($cand) !== '') { $envId = trim($cand); break; }
}

if ($envId === '') {
    clk_json(400, ['ok' => false, 'erro' => 'Corpo sem identificador de envelope.'],
        $evento, mb_substr($bruto, 0, 500, 'UTF-8'));
}

$assinatura = Assinatura::porDocumentoExterno($envId);
if ($assinatura === null || ($assinatura['provedor'] ?? '') !== 'clicksign') {
    // Pode ser envelope criado direto no painel da Clicksign (ou pela Locadora,
    // que usa a mesma conta) — não é erro do remetente.
    clk_json(404, ['ok' => false, 'erro' => 'Envelope não vinculado a nenhum processo.'], $evento, $envId);
}

try {
    $status = Assinatura::sincronizar((string) $assinatura['id']);
} catch (\Throwable $e) {
    clk_json(500, ['ok' => false, 'erro' => 'Falha ao sincronizar.'], $evento, $envId . ' :: ' . $e->getMessage());
}

clk_json(200, ['ok' => true, 'processo' => $assinatura['processo_id'], 'status' => $status],
    $evento, $envId . ' -> ' . $status);
