<?php
declare(strict_types=1);

// Recalcula prazo_tipo/prazo_data das OFPs ativas (depois de mudar regras de prazo ou causais).
// O prazo é dado derivado: por isso nenhum evento é gravado no histórico.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('ROOT', dirname(__DIR__));
require ROOT . '/src/bootstrap.php';

use App\Config\Database;
use App\Models\Ofp;
use App\Services\PrazoService;

$_SESSION = ['unidades' => null];
$ofps = new Ofp();
$prazo = PrazoService::padrao();
$alteradas = 0;
foreach (Database::getInstance()->query('SELECT o.id FROM ofps o WHERE o.status NOT IN (' . Ofp::sqlInativos() . ')')->fetchAll() as $r) {
    $o = $ofps->findComCausal($r['id']);
    if (!$o) {
        continue;
    }
    $novo = $prazo->colunas($o, $o['prazo_abertura_dias'] !== null ? (int)$o['prazo_abertura_dias'] : null);
    if ($novo['prazo_tipo'] !== $o['prazo_tipo'] || $novo['prazo_data'] !== $o['prazo_data']) {
        if ($ofps->updateOnde($o['id'], $novo, ['status' => $o['status']]) > 0) {
            $alteradas++;
        }
    }
}
echo "{$alteradas} OFP(s) com prazo recalculado.\n";
