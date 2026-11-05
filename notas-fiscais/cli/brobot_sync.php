<?php
// @versao Cap_02 V_01_16/07/2026
declare(strict_types=1);

/**
 * Poller Brobot — CLI (agendar A CADA 5 MINUTOS).
 *
 * Busca DF-e novos na Brobot e grava na staging (nf_notas_capturadas).
 * Não classifica — isso é feito por brobot_classificar.php (a cada 30 min).
 *
 * Uso:     php cli/brobot_sync.php
 * Agendar: schtasks /Create /SC MINUTE /MO 5 /TN "Brobot-Sync"
 *          /TR "php C:\Projetos\php\notas\cli\brobot_sync.php"
 */

define('ROOT', dirname(__DIR__));
define('STORAGE', ROOT . '/storage');
date_default_timezone_set('America/Sao_Paulo');

require ROOT . '/config/database.php';
require ROOT . '/src/Helpers/Settings.php';
require ROOT . '/src/Helpers/BrobotClient.php';
require ROOT . '/src/Helpers/XmlDfeParser.php';
require ROOT . '/src/Models/BrobotSync.php';

// ── Lock: não sobrepor execuções (um run pode passar de 5 min) ──
$lockDir = STORAGE . '/locks';
if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
$lock = fopen($lockDir . '/brobot_sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Sync anterior ainda em execução — abortando.\n";
    exit(0);
}

$r = BrobotSync::run();
echo "[" . date('H:i:s') . "] Brobot sync: {$r['status']} — {$r['detalhe']}\n";

flock($lock, LOCK_UN);
fclose($lock);
