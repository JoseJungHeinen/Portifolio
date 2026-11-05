<?php
// @versao Ver.003.061026
/**
 * Comunicado de tarefas pendentes.
 *
 * Rodado por cron nos dias úteis de manhã:
 *   0 8 * * 1-5 cd /var/www/html/compras && php cli/enviar-pendencias.php >> storage/logs/pendencias.log 2>&1
 *
 * Modos:
 *   php cli/enviar-pendencias.php                    envia
 *   php cli/enviar-pendencias.php --dry-run          mostra o que enviaria
 *   php cli/enviar-pendencias.php --usuario=a@b.com  só para esse endereço
 *   php cli/enviar-pendencias.php --consolidado      também envia o panorama geral a admin/diretor
 *
 * Por padrão cada pessoa recebe SÓ o que está sob sua responsabilidade; o
 * consolidado (tudo do sistema para admin/diretor) só sai com --consolidado.
 *
 * Sai com 0 se tudo enviou, 1 se houve alguma falha — para o cron poder alertar.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Bootstrap próprio: igual ao index.php, MENOS session_start() — não há sessão no
// CLI, e nenhuma função usada aqui depende de auth()/$_SESSION.
define('ROOT', dirname(__DIR__));
require_once ROOT . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(ROOT)->load();
date_default_timezone_set('America/Sao_Paulo');

use App\Config\Database;
use App\Services\{PendenciaService, ComunicadoPendenciaService};

$opts     = getopt('', ['dry-run', 'usuario::', 'consolidado']);
$dryRun   = array_key_exists('dry-run', $opts);
$soPara   = $opts['usuario'] ?? null;
$comConsolidado = array_key_exists('consolidado', $opts);
$hoje     = new DateTimeImmutable('today');
$dataRef  = $hoje->format('Y-m-d');

$log = static function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log('Iniciando' . ($dryRun ? ' (DRY-RUN)' : '') . ($soPara ? " (só para {$soPara})" : ''));

$pend      = new PendenciaService($hoje);
$comunicado = new ComunicadoPendenciaService();
$db        = Database::getInstance();

$log('Corte de antiguidade: ' . $pend->corte()->format('Y-m-d H:i'));

$porResponsavel = $pend->porResponsavel();
$log(count($porResponsavel) . ' usuário(s) com pendência.');

$jaEnviado = static function (string $usuarioId, string $tipo) use ($db, $dataRef): bool {
    $stmt = $db->prepare(
        "SELECT 1 FROM comunicados_pendencia
         WHERE usuario_id = ? AND data_referencia = ? AND tipo = ? LIMIT 1"
    );
    $stmt->execute([$usuarioId, $dataRef, $tipo]);
    return (bool)$stmt->fetchColumn();
};

$registrar = static function (string $usuarioId, string $tipo, int $total) use ($db, $dataRef): void {
    $db->prepare(
        "INSERT IGNORE INTO comunicados_pendencia (id, usuario_id, data_referencia, tipo, total_itens)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([uuid(), $usuarioId, $dataRef, $tipo, $total]);
};

$enviados = 0;
$falhas   = 0;
$pulados  = 0;

// ── Individuais ───────────────────────────────────────────────────────────────
foreach ($porResponsavel as $bloco) {
    $u     = $bloco['usuario'];
    $itens = $bloco['itens'];

    if ($soPara !== null && $u['email'] !== $soPara) { continue; }

    if (empty($u['email'])) {
        $log("  PULADO {$u['nome']}: sem e-mail cadastrado");
        $pulados++;
        continue;
    }

    if (!$dryRun && $jaEnviado($u['id'], 'individual')) {
        $log("  PULADO {$u['nome']}: comunicado de hoje já foi enviado");
        $pulados++;
        continue;
    }

    if ($dryRun) {
        $log("  [dry-run] {$u['nome']} <{$u['email']}>: " . count($itens) . ' item(ns)');
        foreach ($itens as $i) {
            $log("      - [{$i['tipo']}] {$i['titulo']} ({$i['dias_parado']}d) — {$i['detalhe']}");
        }
        $enviados++;
        continue;
    }

    try {
        if ($comunicado->enviarIndividual($u, $itens)) {
            $registrar($u['id'], 'individual', count($itens));
            $log("  OK {$u['nome']} <{$u['email']}>: " . count($itens) . ' item(ns)');
            $enviados++;
        } else {
            $log("  FALHA {$u['nome']} <{$u['email']}>: MailService retornou false");
            $falhas++;
        }
    } catch (Throwable $e) {
        // Um destinatário problemático não pode impedir os outros
        $log("  FALHA {$u['nome']} <{$u['email']}>: " . $e->getMessage());
        $falhas++;
    }
}

// ── Consolidado para admin/diretor ────────────────────────────────────────────
$itensConsolidado = $comConsolidado ? $pend->consolidado($porResponsavel) : [];

if (!$comConsolidado) {
    $log('Consolidado: desligado (use --consolidado para enviar).');
} elseif ($itensConsolidado === []) {
    $log('Consolidado: nada parado no sistema, nenhum e-mail de gestão.');
} else {
    foreach ($pend->destinatariosConsolidado() as $u) {
        if ($soPara !== null && $u['email'] !== $soPara) { continue; }

        if (!$dryRun && $jaEnviado($u['id'], 'consolidado')) {
            $log("  PULADO consolidado {$u['nome']}: já enviado hoje");
            $pulados++;
            continue;
        }

        if ($dryRun) {
            $log("  [dry-run] consolidado -> {$u['nome']} <{$u['email']}>: " . count($itensConsolidado) . ' item(ns)');
            $enviados++;
            continue;
        }

        try {
            if ($comunicado->enviarConsolidado($u, $itensConsolidado)) {
                $registrar($u['id'], 'consolidado', count($itensConsolidado));
                $log("  OK consolidado {$u['nome']} <{$u['email']}>");
                $enviados++;
            } else {
                $log("  FALHA consolidado {$u['nome']}: MailService retornou false");
                $falhas++;
            }
        } catch (Throwable $e) {
            $log("  FALHA consolidado {$u['nome']}: " . $e->getMessage());
            $falhas++;
        }
    }
}

$log("Fim. Enviados: {$enviados} | Falhas: {$falhas} | Pulados: {$pulados}");
exit($falhas > 0 ? 1 : 0);
