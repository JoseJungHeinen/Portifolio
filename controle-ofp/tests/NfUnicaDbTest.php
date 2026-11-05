<?php
declare(strict_types=1);

use App\Services\StatusService;
use App\Support\ValidacaoException;

fx_limpar();
$_SESSION['unidades'] = null;
$comprador = fx_usuario('comprador');
$aprovada = fn(string $n) => fx_ofp(['status' => 'aprovada', 'numero_ofp' => $n, 'data_abertura' => '2026-10-02', 'data_decisao' => '2026-10-05']);
$nf = ['nf_devolucao' => '600001', 'data_nf_devolucao' => '2026-10-06', 'valor_nf_devolucao' => '546,73'];

T::teste('mesma NF em outra OFP da unidade é bloqueada', function () use ($comprador, $aprovada, $nf) {
    (new StatusService())->transitar($aprovada('850001'), 'nf_emitida', $nf, $comprador);
    T::lanca(ValidacaoException::class, fn() => (new StatusService())->transitar($aprovada('850002'), 'nf_emitida', $nf, $comprador));
});

T::teste('NF compartilhada exige justificativa e então é aceita', function () use ($comprador, $aprovada, $nf) {
    $id = $aprovada('850003');
    T::lanca(ValidacaoException::class, fn() => (new StatusService())->transitar($id, 'nf_emitida', $nf + ['nf_compartilhada' => '1'], $comprador));
    (new StatusService())->transitar($id, 'nf_emitida', $nf + ['nf_compartilhada' => '1', 'observacao' => 'NF única para 2 peças do mesmo volume'], $comprador);
    T::igual('1', (string)fx_ofp_db($id)['nf_compartilhada']);
});

T::teste('mesma NF em outra unidade é permitida', function () use ($comprador, $nf) {
    $id = fx_ofp(['status' => 'aprovada', 'numero_ofp' => '850004', 'unidade_id' => fx_unidade('Eurovale - Jeep'), 'data_abertura' => '2026-10-02', 'data_decisao' => '2026-10-05']);
    (new StatusService())->transitar($id, 'nf_emitida', $nf, $comprador);
    T::igual('nf_emitida', fx_ofp_db($id)['status']);
});
