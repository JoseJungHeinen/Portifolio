<?php
// @versao Cap_17 V_03_18/09/2026
declare(strict_types=1);

/**
 * Poller ADN — CLI (captura direta de NFSe Nacional, sem Brobot).
 *
 * Prova de conceito: valida o desenho de cursor por filial (NSU) contra o
 * Portal Nacional/ADN. Certificado único da matriz (Geral > NF-e / NFS-e),
 * reaproveitado para toda filial com adn_nfse_ativo=1 dentro da janela.
 * Ainda não agendado — rodar manualmente.
 *
 * Uso: php cli/adn_sync.php
 */

define('ROOT', dirname(__DIR__));
define('STORAGE', ROOT . '/storage');
date_default_timezone_set('America/Sao_Paulo');

require ROOT . '/config/database.php';
require ROOT . '/src/Helpers/Settings.php';
require ROOT . '/src/Helpers/AdnClient.php';
require ROOT . '/src/Helpers/XmlDfeParser.php';
require ROOT . '/src/Models/AdnSync.php';
require ROOT . '/src/Models/CertificadoMatriz.php';

// ── Lock: não sobrepor execuções ──
$lockDir = STORAGE . '/locks';
if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
$lock = fopen($lockDir . '/adn_sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Sync anterior ainda em execução — abortando.\n";
    exit(0);
}

$r = AdnSync::run();
echo "[" . date('H:i:s') . "] ADN sync: {$r['status']} — {$r['detalhe']}\n";

flock($lock, LOCK_UN);
fclose($lock);
