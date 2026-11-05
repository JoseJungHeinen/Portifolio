<?php
declare(strict_types=1);

use App\Services\PerdaService;

$hoje = new DateTimeImmutable('2026-10-23');

T::teste('registrada com abertura vencida há mais de 5 dias', function () use ($hoje) {
    T::igual('perdida_prazo', PerdaService::sugerir(['status' => 'registrada', 'prazo_data' => '2026-10-17'], $hoje));
    T::igual(null, PerdaService::sugerir(['status' => 'registrada', 'prazo_data' => '2026-10-18'], $hoje));
});

T::teste('aprovada sem NF depois do prazo de 30 dias', function () use ($hoje) {
    T::igual('encerrada', PerdaService::sugerir(['status' => 'aprovada', 'prazo_data' => '2026-10-22'], $hoje));
    T::igual('encerrada', PerdaService::sugerir(['status' => 'aguardando_tp41', 'prazo_data' => '2026-10-22'], $hoje));
    T::igual(null, PerdaService::sugerir(['status' => 'aprovada', 'prazo_data' => '2026-10-23'], $hoje));
});

T::teste('outras etapas ou sem prazo não sugerem', function () use ($hoje) {
    T::igual(null, PerdaService::sugerir(['status' => 'em_analise', 'prazo_data' => '2026-01-01'], $hoje));
    T::igual(null, PerdaService::sugerir(['status' => 'registrada', 'prazo_data' => null], $hoje));
    T::igual(null, PerdaService::sugerir(['status' => 'cancelada', 'prazo_data' => '2026-01-01'], $hoje));
});

T::teste('lista única de status de perda real', function () {
    T::igual(['rejeitada_final', 'perdida_prazo', 'encerrada'], PerdaService::STATUS_PERDA);
    T::igual("'rejeitada_final','perdida_prazo','encerrada'", PerdaService::sqlStatusPerda());
});
