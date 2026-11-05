<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ROOT', dirname(__DIR__));
require ROOT . '/src/bootstrap.php';

$o = getopt('', ['v4:', 'status:']);
if (empty($o['v4'])) {
    fwrite(STDERR, "Uso: php cli/importar_planilha.php --v4=storage/import/eurovale.csv [--status=storage/import/ofp_status.csv]\n"
        . "Os caminhos são relativos à pasta atual (rode a partir da raiz do projeto).\n");
    exit(1);
}
$_SESSION = ['unidades' => null];
$svc = new App\Services\ImportService();
$pasta = ROOT . '/storage/import';
if (!is_dir($pasta)) {
    mkdir($pasta, 0775, true);
}
$arquivo = $pasta . '/rejeitados.csv';

try {
    $r = $svc->importarV4($o['v4']);
    echo "Planilha v4: inseridas={$r['inseridas']} atualizadas={$r['atualizadas']} ignoradas={$r['ignoradas']} rejeitadas={$r['rejeitadas']}\n";

    if (!empty($o['status'])) {
        $s = $svc->importarStatus($o['status']);
        echo "OFP-STATUS: atualizadas={$s['atualizadas']} anotadas={$s['anotadas']} rejeitadas={$s['rejeitadas']}\n";
    }
} finally {
    echo $svc->salvarRejeitados($arquivo) . " linha(s) rejeitada(s) gravadas em {$arquivo}\n";
}
