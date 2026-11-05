<?php
// @versao Asg_04 V_02_01/10/2026
declare(strict_types=1);

/**
 * Worker de assinatura eletrônica — CLI (agendar A CADA 10 MINUTOS).
 * Atende os dois provedores (Assinafy e Clicksign): o provedor de cada
 * assinatura está em nf_processo_assinaturas.provedor.
 *
 * Faz duas coisas, nesta ordem:
 *   1. envia o que ficou em 'a_enviar' (o pós-commit da aprovação falhou, ou o
 *      provedor estava fora do ar);
 *   2. reconsulta as assinaturas em aberto e aplica o desfecho — assinado,
 *      recusado, cancelado, vencido.
 *
 * POR QUE ISTO NÃO É OPCIONAL
 *   O webhook da Assinafy não é assinado (sem HMAC), então ele nunca decide
 *   nada — é só uma campainha que antecipa esta mesma checagem. Se o webhook
 *   falhar, não for cadastrado, ou o servidor estiver fora do ar na hora, é
 *   este worker que impede o processo de ficar preso para sempre na etapa de
 *   assinatura.
 *
 *   Ele também cobre o inverso: `GET /documents/{id}/activities` usa o MESMO
 *   vocabulário de eventos do webhook, então nada se perde por polling.
 *
 * CADÊNCIA
 *   Medido em 17/08/2026: da assinatura ao documento pronto levou 34s, e um
 *   poll de 30s pegou a virada 16s depois. Dez minutos é folgado de sobra para
 *   um fluxo em que o gargalo é a pessoa assinar, não a nossa checagem.
 *
 * Uso:     php cli/assinafy_sync.php [--verboso]
 * Agendar: schtasks /Create /SC MINUTE /MO 10 /TN "Assinafy-Sync"
 *          /TR "php C:\Projetos\php\notas\cli\assinafy_sync.php"
 * Linux:   crontab a cada 10 min (o padrão "asterisco-barra-10" no campo de
 *           minutos) apontando para
 *           php /var/www/exemplo/notas/cli/assinafy_sync.php
 */

// constants.php é a fonte única: define ROOT, SRC, VIEWS, STORAGE e carrega
// database + Settings. Os outros scripts de cli/ definem ROOT/STORAGE à mão e
// funcionam porque nunca tocam código que dependa de SRC — este toca:
// concluir uma assinatura chama Process::aprovar(), que lá no fundo faz
// `require SRC . '/...'`. Foi o que quebrou a primeira execução real
// ("Undefined constant SRC") depois de os quatro signatários já terem assinado.
require dirname(__DIR__) . '/config/constants.php';
date_default_timezone_set('America/Sao_Paulo');

require_once SRC . '/Helpers/AssinafyClient.php';
require_once SRC . '/Helpers/ClicksignClient.php';
require_once SRC . '/Models/Etapa.php';
require_once SRC . '/Models/Template.php';
require_once SRC . '/Models/Process.php';
require_once SRC . '/Models/Assinatura.php';

$verboso = in_array('--verboso', $argv ?? [], true);

function asg_out(string $msg, bool $sempre = true): void
{
    global $verboso;
    if ($sempre || $verboso) {
        echo '[' . date('H:i:s') . '] ' . $msg . "\n";
    }
}

// ── Lock: não sobrepor execuções ───────────────────────────────────
// Sem isto, duas rodadas simultâneas poderiam disparar o mesmo documento duas
// vezes — cobrança dobrada e dois convites na caixa do signatário. A trava
// otimista de `dispararPendentes` já protege, mas o lock evita o desperdício.
$lockDir = STORAGE . '/locks';
if (!is_dir($lockDir)) { @mkdir($lockDir, 0775, true); }
$lock = fopen($lockDir . '/assinafy_sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    asg_out('Execução anterior ainda em curso — abortando.');
    exit(0);
}

// Dois provedores convivem (Assinafy e Clicksign): só encerra se NENHUM estiver ligado.
if (!AssinafyClient::fromSettings()->ativo() && !ClicksignClient::fromSettings()->ativo()) {
    asg_out('Nenhuma integração de assinatura ligada (Geral → API → Assinafy / Clicksign). Nada a fazer.');
    flock($lock, LOCK_UN);
    exit(0);
}

$t0 = microtime(true);

// ── 1. Reenvio do que não saiu ─────────────────────────────────────
$env = Assinatura::dispararPendentes(20);
if ($env['enviadas'] > 0 || $env['falhas'] > 0) {
    asg_out("Envios: {$env['enviadas']} ok, {$env['falhas']} falha(s).");
    foreach ($env['mensagens'] as $m) {
        asg_out('  ! ' . $m);
    }
} else {
    asg_out('Nenhum envio pendente.', false);
}

// ── 2. Acompanhamento das assinaturas em aberto ────────────────────
$abertas   = Assinatura::abertas(100);
$mudancas  = 0;
$erros     = 0;

asg_out(count($abertas) . ' assinatura(s) em aberto.', false);

foreach ($abertas as $id) {
    try {
        $antes  = null;
        $pdo    = Database::getInstance();
        $stmt   = $pdo->prepare("SELECT status FROM nf_processo_assinaturas WHERE id = ?");
        $stmt->execute([$id]);
        $antes  = (string) $stmt->fetchColumn();

        $depois = Assinatura::sincronizar($id);

        if ($depois !== $antes) {
            $mudancas++;
            asg_out("  {$id}: {$antes} → {$depois}");
        }
    } catch (\Throwable $e) {
        $erros++;
        asg_out("  ! {$id}: " . $e->getMessage());
    }
}

$seg = round(microtime(true) - $t0, 1);
asg_out("Concluído em {$seg}s — {$mudancas} mudança(s), {$erros} erro(s).");

flock($lock, LOCK_UN);
exit($erros > 0 ? 1 : 0);
