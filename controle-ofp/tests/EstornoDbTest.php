<?php
declare(strict_types=1);

use App\Models\Ofp;
use App\Services\StatusService;
use App\Support\RegraException;
use App\Support\ValidacaoException;

fx_limpar();
$_SESSION['unidades'] = null;
$financeiro = fx_usuario('financeiro');

T::teste('estorno zera o creditado e pode voltar a aguardar crédito', function () use ($financeiro) {
    $id = fx_ofp([
        'status' => 'creditada', 'numero_ofp' => '860001', 'data_recebimento' => '2026-08-25', 'data_abertura' => '2026-09-01',
        'data_decisao' => '2026-09-05', 'nf_devolucao' => '29713', 'data_nf_devolucao' => '2026-09-10', 'valor_nf_devolucao' => 546.73,
        'valor_creditado_peca' => 546.73, 'valor_creditado_st' => 0, 'titulo_apollo' => '41828', 'data_credito' => '2026-10-01',
    ]);
    $s = new StatusService();
    $s->transitar($id, 'estornada', ['motivo_id' => fx_motivo('ESTORNO_FABRICANTE')], $financeiro);
    $o = fx_ofp_db($id);
    T::igual(['estornada', '0.00', '0.00'], [$o['status'], $o['valor_creditado_peca'], $o['valor_creditado_st']]);

    T::lanca(ValidacaoException::class, fn() => $s->transitar($id, 'aguardando_credito', [], $financeiro));
    $s->transitar($id, 'aguardando_credito', ['observacao' => 'Fabricante vai repagar no próximo lote'], $financeiro);
    $o = (new Ofp())->findComCausal($id);
    T::igual(['aguardando_credito', 546.73], [$o['status'], (float)$o['aberto_peca']]);
});

T::teste('só financeiro e supervisores reabrem estorno', function () {
    T::igual(['aguardando_credito'], StatusService::transicoesPermitidas('estornada', 'financeiro', 'fisica'));
    T::igual([], StatusService::transicoesPermitidas('estornada', 'comprador', 'fisica'));
});

T::teste('comprador não reabre', function () {
    $id = fx_ofp(['status' => 'estornada', 'numero_ofp' => '860002']);
    T::lanca(RegraException::class, fn() => (new StatusService())->transitar($id, 'aguardando_credito', ['observacao' => 'x'], fx_usuario('comprador')));
});
