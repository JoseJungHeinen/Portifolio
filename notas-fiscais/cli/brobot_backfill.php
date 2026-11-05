<?php
// @versao Cap_13 V_02_18/07/2026
declare(strict_types=1);

/**
 * Backfill Brobot — CLI (agendar A CADA 5 MINUTOS, em paralelo ao poller).
 *
 * Processa a fila de backfill por filial (nf_brobot_backfill), criada quando
 * uma filial é ligada em Geral > Brobot. Varre a janela histórica no Brobot e
 * importa as notas daquela filial que estavam atrás do cursor incremental.
 *
 * Uso:     php cli/brobot_backfill.php [max_paginas_por_lote]
 * Cron:    a cada 5 min, como www-data (ver /etc/cron.d/brobot-notas no servidor).
 */

define('ROOT', dirname(__DIR__));
define('STORAGE', ROOT . '/storage');
date_default_timezone_set('America/Sao_Paulo');

require ROOT . '/config/database.php';
require ROOT . '/src/Helpers/Settings.php';
require ROOT . '/src/Helpers/BrobotClient.php';
require ROOT . '/src/Helpers/XmlDfeParser.php';
require ROOT . '/src/Models/BrobotSync.php';
require ROOT . '/src/Models/BrobotBackfill.php';

if (Settings::get('brobot.ativo', '0') !== '1') {
    echo '[' . date('H:i:s') . "] Brobot inativo (brobot.ativo=0) — backfill não roda.\n";
    exit(0);
}

// Lock próprio: não sobrepõe execuções nem colide com o poller incremental.
$lockDir = STORAGE . '/locks';
if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
$lock = fopen($lockDir . '/brobot_backfill.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo '[' . date('H:i:s') . "] Backfill anterior ainda em execução — abortando.\n";
    exit(0);
}

$maxPaginas = isset($argv[1]) ? max(1, (int) $argv[1]) : 40;

// Processa lotes até esvaziar a fila OU atingir o teto de tempo (folga antes do
// próximo tick de 5 min). Cada lote avança o cursor do job em andamento.
$limite = time() + 240;
do {
    $r = BrobotBackfill::run($maxPaginas);
    if ($r['status'] !== 'idle') {
        echo '[' . date('H:i:s') . '] Backfill (' . ($r['filiais'] ?? 0) . " filial/is): {$r['status']} — "
           . "+{$r['importadas']} importada(s) / {$r['varridos']} varrida(s). {$r['detalhe']}\n";
    }
} while ($r['status'] === 'rodando' && time() < $limite);

if ($r['status'] === 'idle') {
    echo '[' . date('H:i:s') . "] Nenhum backfill pendente.\n";
}

flock($lock, LOCK_UN);
fclose($lock);
