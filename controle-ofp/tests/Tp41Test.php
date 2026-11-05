<?php
declare(strict_types=1);

use App\Services\StatusService;
use App\Support\RegraException;

T::teste('TP41 só aparece para causal que exige', function () {
    T::igual(['aguardando_tp41', 'nf_emitida', 'encerrada', 'peca_vendida'], StatusService::transicoesPermitidas('aprovada', 'comprador', 'fisica', true));
    T::igual(['nf_emitida', 'encerrada', 'peca_vendida'], StatusService::transicoesPermitidas('aprovada', 'comprador', 'fisica', false));
});

fx_limpar();
$_SESSION['unidades'] = null;
$comprador = fx_usuario('comprador');

T::teste('causal AP não aceita TP41; TS aceita', function () use ($comprador) {
    $ap = fx_ofp(['status' => 'aprovada', 'numero_ofp' => '841001', 'data_abertura' => '2026-10-02', 'data_decisao' => '2026-10-05']);
    T::lanca(RegraException::class, fn() => (new StatusService())->transitar($ap, 'aguardando_tp41', [], $comprador));
    $ts = fx_ofp(['status' => 'aprovada', 'numero_ofp' => '841002', 'causal_id' => fx_causal('TS')['id'], 'data_abertura' => '2026-10-02', 'data_decisao' => '2026-10-05']);
    (new StatusService())->transitar($ts, 'aguardando_tp41', [], $comprador);
    T::igual('aguardando_tp41', fx_ofp_db($ts)['status']);
});
