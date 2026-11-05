<?php
// @versao Cap_20 V_03_18/09/2026
declare(strict_types=1);

/**
 * Poller de distribuição de NF-e (SEFAZ/AN) — CLI. Prova de conceito, mesmo
 * molde do cli/adn_sync.php (NFSe). Ainda não agendado.
 *
 * ⚠️ Diferente do ADN: aqui a SEFAZ pune NSU errado com bloqueio de 1h
 * (cStat 656) — nunca rodar em loop apertado tentando "acertar" o cursor.
 *
 * Uso: php cli/nfe_dist_sync.php
 */

define('ROOT', dirname(__DIR__));
define('STORAGE', ROOT . '/storage');
date_default_timezone_set('America/Sao_Paulo');

require ROOT . '/config/database.php';
require ROOT . '/src/Helpers/Settings.php';
require ROOT . '/src/Helpers/NfeDistClient.php';
require ROOT . '/src/Helpers/XmlDfeParser.php';
require ROOT . '/src/Models/NfeDistSync.php';
require ROOT . '/src/Models/CertificadoMatriz.php';

$lockDir = STORAGE . '/locks';
if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
$lock = fopen($lockDir . '/nfe_dist_sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Sync anterior ainda em execução — abortando.\n";
    exit(0);
}

$r = NfeDistSync::run();
echo "[" . date('H:i:s') . "] NF-e dist sync: {$r['status']} — {$r['detalhe']}\n";

flock($lock, LOCK_UN);
fclose($lock);
