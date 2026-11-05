<?php
// @versao Cap_03 V_01_16/07/2026
declare(strict_types=1);

/**
 * Classificação automática (pré-fluxo) — CLI (agendar A CADA 30 MINUTOS).
 *
 * Aplica as regras ativas sobre as notas capturadas pendentes:
 *   modo automático → abre o processo e vincula a nota;
 *   modo sugerir    → marca a nota como sugerida (operador confirma).
 *
 * Uso:     php cli/brobot_classificar.php
 * Agendar: schtasks /Create /SC MINUTE /MO 30 /TN "Brobot-Classificar"
 *          /TR "php C:\Projetos\php\notas\cli\brobot_classificar.php"
 */

define('ROOT', dirname(__DIR__));
define('SRC', ROOT . '/src');
define('STORAGE', ROOT . '/storage');
date_default_timezone_set('America/Sao_Paulo');

require ROOT . '/config/database.php';
require SRC . '/Helpers/Settings.php';
require SRC . '/Models/BrobotSync.php';
require SRC . '/Models/CapturedNote.php';

// ── Lock ──
$lockDir = STORAGE . '/locks';
if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
$lock = fopen($lockDir . '/brobot_classificar.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Classificação anterior ainda em execução — abortando.\n";
    exit(0);
}

$inicio = date('Y-m-d H:i:s');

try {
    $res = CapturedNote::aplicarRegras(); // ['sugeridas','vinculadas','ambiguas']
    $r = [
        'status'     => 'ok',
        'importadas' => $res['vinculadas'],
        'ignoradas'  => $res['sugeridas'] + $res['ambiguas'],
        'detalhe'    => "{$res['vinculadas']} vinculada(s), {$res['sugeridas']} sugerida(s), {$res['ambiguas']} ambígua(s).",
    ];
} catch (Throwable $e) {
    $r = ['status' => 'erro', 'importadas' => 0, 'ignoradas' => 0, 'detalhe' => 'Falha: ' . $e->getMessage()];
}

BrobotSync::registrarLog('classificacao', $inicio, $r, null);
echo "[" . date('H:i:s') . "] Brobot classificação: {$r['status']} — {$r['detalhe']}\n";

flock($lock, LOCK_UN);
fclose($lock);
