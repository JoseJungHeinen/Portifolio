<?php
// @versao Sys_23 V_01_16/07/2026
declare(strict_types=1);

/**
 * Verificador de SLA — CLI (agendar A CADA 5 MINUTOS).
 *
 * Régua de alertas por etapa pendente que estourou o SLA do template:
 *   • 1º alerta: 5 minutos após o estouro do SLA (a qualquer hora)
 *   • Reenvios:  a cada 1 hora, apenas em horário útil (08:00–18:00),
 *                enquanto a etapa continuar pendente
 *
 * Destinatários:
 *   • aprovador USUÁRIO → e-mail para o próprio usuário
 *   • aprovador GRUPO   → e-mail para o GESTOR do grupo (nf_grupos.gestor_id);
 *                         sem gestor definido → notificação in-app para os membros
 *   • sempre: notificação in-app junto com o e-mail
 *
 * Uso:      php cli/verificar_sla.php
 * Agendar:  schtasks /Create /SC MINUTE /MO 5 /TN "NotasWorkflow-SLA"
 *           /TR "php C:\Projetos\php\notas\cli\verificar_sla.php"
 */

// Defaults — usados quando a config da tela Geral não existir
const ATRASO_MINIMO_MIN_DEF   = 5;    // 1º alerta: 5 min após estourar o SLA
const REENVIO_INTERVALO_MIN_DEF = 60; // reenvios: a cada 1 hora
const HORA_UTIL_INICIO_DEF    = 8;    // janela de reenvio: 08:00–18:00
const HORA_UTIL_FIM_DEF        = 18;

define('ROOT', dirname(__DIR__));
require ROOT . '/config/database.php';
require ROOT . '/src/Helpers/Settings.php';
require ROOT . '/src/Helpers/Mailer.php';

$baseUrl = rtrim(Settings::get('email.base_url', 'http://localhost:8090'), '/');

// Régua configurável na tela Geral → Notificações (fallback = defaults abaixo)
$ATRASO_MINIMO_SEG   = Settings::getInt('notif.sla_primeiro_alerta_min', ATRASO_MINIMO_MIN_DEF) * 60;
$REENVIO_INTERVALO_S = Settings::getInt('notif.sla_reenvio_min', REENVIO_INTERVALO_MIN_DEF) * 60;
$HORA_UTIL_INICIO    = Settings::getInt('notif.sla_hora_inicio', HORA_UTIL_INICIO_DEF);
$HORA_UTIL_FIM       = Settings::getInt('notif.sla_hora_fim', HORA_UTIL_FIM_DEF);

$pdo = Database::getInstance();

function uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff));
}

function notificarInApp(PDO $pdo, string $usuarioId, string $titulo, string $msg, string $processoId): void {
    $pdo->prepare(
        "INSERT INTO notificacoes (id, usuario_id, tipo, titulo, mensagem, entidade_tipo, entidade_id)
         VALUES (?, ?, 'nf_tarefa_atribuida', ?, ?, 'nf_processo', ?)"
    )->execute([uuid(), $usuarioId, $titulo, $msg, $processoId]);
}

// ── Etapas pendentes (com o último alerta enviado, se houver) ──
$rows = $pdo->query(
    "SELECT p.id AS processo_id, p.codigo, p.criado_em, p.etapa_atual, p.fornecedor_nome, p.valor_nf,
            e.nome_etapa, e.tipo_aprovador, e.aprovador_id,
            tv.etapas AS etapas_json,
            s.notificado_em AS ultimo_alerta, s.alertas_enviados,
            (SELECT MAX(e2.atuado_em) FROM nf_processo_etapas e2
              WHERE e2.processo_id = p.id AND e2.numero_etapa < p.etapa_atual) AS inicio_anterior
     FROM nf_processos p
     JOIN nf_processo_etapas e  ON e.processo_id = p.id AND e.numero_etapa = p.etapa_atual AND e.status = 'pendente'
     JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
     LEFT JOIN nf_sla_notificacoes s ON s.processo_id = p.id AND s.numero_etapa = p.etapa_atual
     WHERE p.status = 'aberto' AND p.deletado_em IS NULL"
)->fetchAll();

$alertas = 0; $semSla = 0;
$horaAgora = (int) date('G');
$emHorarioUtil = $horaAgora >= $HORA_UTIL_INICIO && $horaAgora < $HORA_UTIL_FIM;

foreach ($rows as $r) {
    // SLA da etapa atual, do snapshot do template
    $etapasTpl = json_decode($r['etapas_json'], true) ?: [];
    $slaHoras  = null;
    foreach ($etapasTpl as $i => $et) {
        $ordem = (int) ($et['ordem'] ?? ($i + 1));
        if ($ordem === (int) $r['etapa_atual']) {
            $slaHoras = isset($et['sla_horas']) && $et['sla_horas'] !== null ? (float) $et['sla_horas'] : null;
            break;
        }
    }
    if ($slaHoras === null || $slaHoras <= 0) { $semSla++; continue; }

    // Início da etapa: aprovação anterior, ou abertura do processo (etapa 1)
    $inicio = $r['inicio_anterior'] ?: $r['criado_em'];
    $limite = strtotime($inicio) + (int) round($slaHoras * 3600);

    // 1º alerta: só após 5 min de estouro (a qualquer hora)
    if (time() <= $limite + $ATRASO_MINIMO_SEG) continue;

    // Reenvio: só a cada 1h e dentro do horário útil (08:00–18:00)
    $ehReenvio = $r['ultimo_alerta'] !== null;
    if ($ehReenvio) {
        if (!$emHorarioUtil) continue;
        if (time() - strtotime($r['ultimo_alerta']) < $REENVIO_INTERVALO_S) continue;
    }

    $horasAtraso = round((time() - $limite) / 3600, 1);
    $linkProc    = "{$baseUrl}/?module=processos&action=detalhe&id={$r['processo_id']}";
    $assunto     = "[Workflow NF] SLA excedido — {$r['codigo']} · {$r['nome_etapa']}";
    $corpo       = "<p>A etapa <b>" . htmlspecialchars($r['nome_etapa']) . "</b> do processo "
                 . "<b>{$r['codigo']}</b> excedeu o SLA de {$slaHoras}h (atraso de {$horasAtraso}h).</p>"
                 . "<p>Fornecedor: " . htmlspecialchars($r['fornecedor_nome'])
                 . " · Valor: R$ " . number_format((float) $r['valor_nf'], 2, ',', '.') . "</p>"
                 . "<p><a href=\"{$linkProc}\">Abrir o processo</a></p>";
    $tituloApp   = 'SLA excedido — ' . $r['codigo'];
    $msgApp      = "A etapa \"{$r['nome_etapa']}\" está {$horasAtraso}h atrasada (SLA {$slaHoras}h).";

    $destinatario = null;

    if ($r['tipo_aprovador'] === 'usuario') {
        $stmt = $pdo->prepare("SELECT nome, email FROM usuarios WHERE id = ? AND ativo = 1");
        $stmt->execute([$r['aprovador_id']]);
        $u = $stmt->fetch();
        if ($u && $u['email']) {
            Mailer::enviar($u['email'], $assunto, $corpo);
            notificarInApp($pdo, $r['aprovador_id'], $tituloApp, $msgApp, $r['processo_id']);
            $destinatario = $u['email'];
        }
    } else { // grupo → gestor
        $stmt = $pdo->prepare(
            "SELECT g.nome AS grupo_nome, u.id AS gestor_id, u.nome AS gestor_nome, u.email AS gestor_email
             FROM nf_grupos g
             LEFT JOIN usuarios u ON u.id = g.gestor_id AND u.ativo = 1
             WHERE g.id = ?"
        );
        $stmt->execute([$r['aprovador_id']]);
        $g = $stmt->fetch();

        if ($g && $g['gestor_email']) {
            Mailer::enviar($g['gestor_email'], $assunto,
                "<p>Alerta para o gestor do grupo <b>" . htmlspecialchars($g['grupo_nome']) . "</b>:</p>" . $corpo);
            notificarInApp($pdo, $g['gestor_id'], $tituloApp, $msgApp, $r['processo_id']);
            $destinatario = $g['gestor_email'];
        } else {
            // Sem gestor definido: notificação in-app para todos os membros
            $membros = $pdo->prepare("SELECT usuario_id FROM nf_grupo_membros WHERE grupo_id = ?");
            $membros->execute([$r['aprovador_id']]);
            foreach ($membros->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                notificarInApp($pdo, $uid, $tituloApp, $msgApp . ' (grupo sem gestor definido)', $r['processo_id']);
            }
            $destinatario = 'sem-gestor:in-app-membros';
        }
    }

    if ($destinatario !== null) {
        $pdo->prepare(
            "INSERT INTO nf_sla_notificacoes (processo_id, numero_etapa, destinatario, notificado_em, alertas_enviados)
             VALUES (?, ?, ?, NOW(), 1)
             ON DUPLICATE KEY UPDATE notificado_em = NOW(), destinatario = VALUES(destinatario),
                                     alertas_enviados = alertas_enviados + 1"
        )->execute([$r['processo_id'], $r['etapa_atual'], $destinatario]);
        $alertas++;
        $seq = $ehReenvio ? ((int) $r['alertas_enviados'] + 1) : 1;
        echo "ALERTA #{$seq} {$r['codigo']} etapa {$r['etapa_atual']} ({$r['nome_etapa']}) → {$destinatario} · atraso {$horasAtraso}h\n";
    }
}

echo "Verificação concluída: {$alertas} alerta(s) enviado(s), " . count($rows) . " etapa(s) analisada(s), {$semSla} sem SLA.\n";
