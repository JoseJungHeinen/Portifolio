<?php
declare(strict_types=1);

// Cron diário (ex.: 07:00 seg–sex): php /var/www/.../cli/alertas_diarios.php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('ROOT', dirname(__DIR__));
require ROOT . '/src/bootstrap.php';

$dirLogs = ROOT . '/storage/logs';
if (!is_dir($dirLogs)) {
    mkdir($dirLogs, 0775, true);
}
$trava = fopen($dirLogs . '/alertas.lock', 'c');
if (!$trava || !flock($trava, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " — outra execução em andamento; nada a fazer.\n";
    exit(0);
}

$_SESSION = ['unidades' => null];
try {
    $sugeridas = (new App\Services\PerdaService())->atualizarSugestoes();
    echo date('Y-m-d H:i:s') . " — {$sugeridas} OFP(s) com perda sugerida.\n";
    $r = (new App\Services\AlertaService())->executar();
    echo date('Y-m-d H:i:s') . " — {$r['enviados']} enviado(s), {$r['falhas']} falha(s).\n";
    exit($r['falhas'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' — falha nos alertas: ' . $e->getMessage() . "\n");
    exit(1);
}
