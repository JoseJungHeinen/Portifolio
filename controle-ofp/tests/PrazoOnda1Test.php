<?php
declare(strict_types=1);

use App\Services\PrazoService;
use App\Services\StatusService;

$p = new PrazoService(90, 10);

T::teste('em análise: SLA a partir da abertura', fn() => T::igual(
    ['tipo' => 'analise', 'data' => '2026-10-12'],
    $p->calcular(['status' => 'em_analise', 'data_abertura' => '2026-10-02'], 14)
));

T::teste('em reanálise: retorno do GROW em 6 dias', fn() => T::igual(
    ['tipo' => 'grow', 'data' => '2026-10-12'],
    $p->calcular(['status' => 'em_reanalise', 'data_reanalise' => '2026-10-06'], 14)
));

T::teste('SLA vem do .env', function () {
    $antes = $_ENV['SLA_ANALISE_DIAS'] ?? null;
    $_ENV['SLA_ANALISE_DIAS'] = '7';
    T::igual('2026-10-09', PrazoService::padrao()->calcular(['status' => 'em_analise', 'data_abertura' => '2026-10-02'], 14)['data']);
    $_ENV['SLA_ANALISE_DIAS'] = '0';
    T::igual('2026-10-12', PrazoService::padrao()->calcular(['status' => 'em_analise', 'data_abertura' => '2026-10-02'], 14)['data']);
    if ($antes === null) {
        unset($_ENV['SLA_ANALISE_DIAS']);
    } else {
        $_ENV['SLA_ANALISE_DIAS'] = $antes;
    }
});

T::teste('reanálise exige a data do caso GROW', fn() => T::igual(
    ['protocolo_grow', 'data_reanalise'],
    StatusService::camposObrigatorios('em_reanalise')
));
