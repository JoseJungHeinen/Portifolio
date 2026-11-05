<?php
// @versao Cap_11 V_01_16/07/2026
declare(strict_types=1);

/**
 * ════════════════════════════════════════════════════════════════════
 *  ENDPOINT PÚBLICO — Integração RPA (lançamento de notas no ERP)
 * ════════════════════════════════════════════════════════════════════
 *
 *  Fluxo: o RPA consulta as notas paradas na etapa dele, baixa os XML
 *  (api/nfe.php), lança no ERP e confirma — a confirmação aprova a
 *  etapa do processo e o fluxo segue.
 *
 *  Ações:
 *    GET  /Notas/public/api/rpa.php?acao=pendentes
 *         → JSON com os processos cuja etapa atual é do usuário RPA:
 *           [{chave, processo, numero_nf, fornecedor, valor, emissao, etapa}]
 *
 *    POST /Notas/public/api/rpa.php?acao=confirmar
 *         Body (JSON ou form): { "chave": "<44 dígitos>" }
 *         → aprova a etapa do RPA no processo daquela chave.
 *           Idempotente: já confirmada devolve ok=true, status=ja_confirmada.
 *
 *  Header:  X-API-Key: <api.rpa_key>   (Geral → aba API)
 *  Etapa do RPA = etapa do processo atribuída ao usuário robô
 *  (api.rpa_usuario_email). Sem sessão/login/CSRF.
 *
 *  Respostas de erro: 400 chave/ação inválida · 401 key · 403 IP ·
 *  404 processo não encontrado · 409 etapa não está com o RPA ·
 *  422 gate de aprovação (ex.: anexo obrigatório pendente).
 * ════════════════════════════════════════════════════════════════════
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function rpa_log(int $status, string $acao, string $detalhe): void
{
    $dir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '-';
    $linha = sprintf("%s\t%s\t%d\t%s\t%s\n", date('c'), $ip, $status, $acao, $detalhe !== '' ? $detalhe : '-');
    @file_put_contents($dir . '/rpa_api.log', $linha, FILE_APPEND | LOCK_EX);
}

function rpa_json(int $code, array $payload, string $acao = '-', string $detalhe = ''): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    rpa_log($code, $acao, $detalhe);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function rpa_erro(int $code, string $msg, string $acao = '-', string $detalhe = ''): void
{
    rpa_json($code, ['ok' => false, 'erro' => $msg], $acao, $detalhe);
}

// ── Bootstrap ──────────────────────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/constants.php';

$acao = strtolower(trim((string) ($_GET['acao'] ?? '')));

// ── Restrição de IP (opcional) ─────────────────────────────────────
$allowRaw = (string) Settings::get('api.rpa_allow_ips', '');
$allowIps = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $allowRaw) ?: [])));
if (!empty($allowIps) && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowIps, true)) {
    rpa_erro(403, 'IP não autorizado.', $acao);
}

// ── Autenticação por API key ───────────────────────────────────────
$apiKey   = (string) Settings::get('api.rpa_key', '');
$recebida = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($apiKey === '' || !is_string($recebida) || !hash_equals($apiKey, $recebida)) {
    rpa_erro(401, 'Não autorizado — API key ausente ou inválida.', $acao);
}

// ── Usuário robô (dono da etapa RPA nos templates) ─────────────────
$rpaEmail = trim((string) Settings::get('api.rpa_usuario_email', ''));
if ($rpaEmail === '') {
    rpa_erro(500, 'Usuário RPA não configurado (Geral → API → e-mail do usuário RPA).', $acao);
}
$pdo  = Database::getInstance();
$stmt = $pdo->prepare("SELECT id, nome FROM usuarios WHERE email = ? AND ativo = 1 LIMIT 1");
$stmt->execute([$rpaEmail]);
$rpa = $stmt->fetch();
if (!$rpa) {
    rpa_erro(500, "Usuário RPA '{$rpaEmail}' não encontrado ou inativo.", $acao);
}

switch ($acao) {

    // ── GET: notas paradas na etapa do RPA ─────────────────────────
    case 'pendentes':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            rpa_erro(405, 'Use GET para acao=pendentes.', $acao);
        }

        $stmt = $pdo->prepare(
            "SELECT p.codigo, p.chave_nf, p.numero_nf, p.valor_nf, p.data_nf,
                    p.etapa_atual, pe.nome_etapa,
                    f.razao_social AS fornecedor_nome, f.cnpj AS fornecedor_cnpj
             FROM nf_processos p
             JOIN nf_processo_etapas pe
               ON pe.processo_id = p.id AND pe.numero_etapa = p.etapa_atual
             LEFT JOIN fornecedores f ON f.id = p.fornecedor_id
             WHERE pe.aprovador_id = ?
               AND pe.status = 'pendente'
               AND p.status IN ('aberto', 'devolvido')
               AND p.is_teste = 0
               AND p.chave_nf IS NOT NULL AND p.chave_nf <> ''
             ORDER BY p.criado_em ASC"
        );
        $stmt->execute([$rpa['id']]);

        $itens = [];
        foreach ($stmt->fetchAll() as $r) {
            $itens[] = [
                'chave'      => preg_replace('/\D+/', '', (string) $r['chave_nf']),
                'processo'   => $r['codigo'],
                'numero_nf'  => $r['numero_nf'],
                'fornecedor' => $r['fornecedor_nome'],
                'cnpj'       => $r['fornecedor_cnpj'],
                'valor'      => (float) $r['valor_nf'],
                'emissao'    => $r['data_nf'],
                'etapa'      => $r['nome_etapa'],
            ];
        }
        rpa_json(200, ['ok' => true, 'total' => count($itens), 'notas' => $itens], $acao, 'total=' . count($itens));
        break;

    // ── POST: confirmar lançamento (aprova a etapa do RPA) ─────────
    case 'confirmar':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            rpa_erro(405, 'Use POST para acao=confirmar.', $acao);
        }

        // chave via JSON ({"chave": "..."}) ou form (chave=...)
        $body  = json_decode((string) file_get_contents('php://input'), true);
        $chave = preg_replace('/\D+/', '', (string) ($body['chave'] ?? $_POST['chave'] ?? ''));
        if (strlen($chave) !== 44) {
            rpa_erro(400, 'Chave inválida — informe os 44 dígitos.', $acao, $chave);
        }

        // Processo da chave (o mais recente não cancelado)
        $stmt = $pdo->prepare(
            "SELECT id, codigo, status, etapa_atual FROM nf_processos
             WHERE REPLACE(REPLACE(REPLACE(chave_nf, '.', ''), ' ', ''), '-', '') = ?
               AND status NOT IN ('cancelado')
             ORDER BY criado_em DESC LIMIT 1"
        );
        $stmt->execute([$chave]);
        $proc = $stmt->fetch();
        if (!$proc) {
            rpa_erro(404, 'Nenhum processo encontrado para esta chave.', $acao, $chave);
        }

        // Etapa atribuída ao RPA neste processo
        $stmt = $pdo->prepare(
            "SELECT id, numero_etapa, nome_etapa, status FROM nf_processo_etapas
             WHERE processo_id = ? AND aprovador_id = ?
             ORDER BY numero_etapa ASC LIMIT 1"
        );
        $stmt->execute([$proc['id'], $rpa['id']]);
        $etapa = $stmt->fetch();
        if (!$etapa) {
            rpa_erro(409, "O processo {$proc['codigo']} não possui etapa atribuída ao RPA.", $acao, $chave);
        }

        // Idempotência: já aprovada → ok sem repetir
        if ($etapa['status'] === 'aprovado') {
            rpa_json(200, ['ok' => true, 'status' => 'ja_confirmada',
                'processo' => $proc['codigo'],
                'mensagem' => 'Lançamento já havia sido confirmado — nada a fazer.'], $acao, $chave);
        }

        if ((int) $proc['etapa_atual'] !== (int) $etapa['numero_etapa']) {
            rpa_erro(409, "O processo {$proc['codigo']} está na etapa {$proc['etapa_atual']} — "
                . "a etapa do RPA ({$etapa['numero_etapa']} - {$etapa['nome_etapa']}) ainda não está liberada.",
                $acao, $chave);
        }

        require_once SRC . '/Models/Process.php';
        try {
            Process::aprovar($proc['id'], $etapa['id'], $rpa['id'], $rpa['nome']);
        } catch (Throwable $e) {
            rpa_erro(422, 'Não foi possível confirmar: ' . $e->getMessage(), $acao, $chave);
        }

        // Registro explícito da confirmação (quem: RPA · quando: data/hora)
        Process::registrarHistorico(
            $proc['id'], 'rpa_lancamento_confirmado', $rpa['id'], $rpa['nome'],
            'Lançamento no ERP confirmado via API do RPA em ' . date('d/m/Y H:i:s') . ' — chave ' . $chave . '.',
            (int) $etapa['numero_etapa']
        );

        rpa_json(200, ['ok' => true, 'status' => 'confirmada',
            'processo'    => $proc['codigo'],
            'etapa'       => $etapa['nome_etapa'],
            'confirmado_em' => date('Y-m-d H:i:s'),
            'mensagem'    => "Etapa \"{$etapa['nome_etapa']}\" aprovada — processo seguiu no fluxo."], $acao, $chave);
        break;

    default:
        rpa_erro(400, "Ação inválida. Use acao=pendentes (GET) ou acao=confirmar (POST).", $acao);
}
