<?php
declare(strict_types=1);

use App\Services\PrazoService;

$p = new PrazoService(90);

T::teste('abertura = recebimento + prazo do causal', function () use ($p) {
    T::igual(['tipo' => 'abertura', 'data' => '2026-10-15'], $p->calcular(['status' => 'registrada', 'data_recebimento' => '2026-10-01'], 14));
    T::igual('2026-10-30', $p->calcular(['status' => 'registrada', 'data_recebimento' => '2026-10-01'], 29)['data']);
});

T::teste('sinistro não tem prazo de abertura', fn() => T::igual(null, $p->calcular(['status' => 'registrada', 'data_recebimento' => '2026-10-01'], null)));

T::teste('reanálise = rejeição + 20', fn() => T::igual(['tipo' => 'reanalise', 'data' => '2026-10-21'], $p->calcular(['status' => 'rejeitada', 'data_decisao' => '2026-10-01'], 14)));

T::teste('NF de devolução = aprovação + 30', function () use ($p) {
    T::igual(['tipo' => 'nf_devolucao', 'data' => '2026-10-31'], $p->calcular(['status' => 'aprovada', 'data_decisao' => '2026-10-01'], 14));
    T::igual('2026-10-31', $p->calcular(['status' => 'aguardando_tp41', 'data_decisao' => '2026-10-01'], 14)['data']);
});

T::teste('coleta = solicitação + 7 dias úteis', fn() => T::igual(['tipo' => 'coleta', 'data' => '2026-10-27'], $p->calcular(['status' => 'aguardando_coleta', 'data_coleta_solicitada' => '2026-10-16'], 14)));

T::teste('crédito = NF + 90', function () use ($p) {
    foreach (['nf_emitida', 'coletada', 'aguardando_credito'] as $status) {
        T::igual(['tipo' => 'credito', 'data' => '2026-12-30'], $p->calcular(['status' => $status, 'data_nf_devolucao' => '2026-10-01'], 14), $status);
    }
});

T::teste('status sem prazo', function () use ($p) {
    foreach (['em_analise', 'em_reanalise', 'creditada', 'cancelada'] as $status) {
        T::igual(null, $p->calcular(['status' => $status, 'data_recebimento' => '2026-10-01'], 14), $status);
    }
});

T::teste('sem a data de referência não há prazo', fn() => T::igual(null, $p->calcular(['status' => 'aprovada', 'data_decisao' => null], 14)));

T::teste('colunas prontas para gravar', fn() => T::igual(['prazo_tipo' => 'abertura', 'prazo_data' => '2026-10-15'], $p->colunas(['status' => 'registrada', 'data_recebimento' => '2026-10-01'], 14)));

T::teste('semáforo', function () {
    $hoje = new DateTimeImmutable('2026-10-17 15:30');
    T::igual('vermelho', PrazoService::semaforo('2026-10-16', $hoje));
    T::igual('amarelo', PrazoService::semaforo('2026-10-17', $hoje));
    T::igual('amarelo', PrazoService::semaforo('2026-10-20', $hoje));
    T::igual('verde', PrazoService::semaforo('2026-10-21', $hoje));
    T::igual(null, PrazoService::semaforo(null, $hoje));
    T::igual(-1, PrazoService::diasRestantes('2026-10-16', $hoje));
});
