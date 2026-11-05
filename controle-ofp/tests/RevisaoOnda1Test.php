<?php
declare(strict_types=1);

use App\Models\Ofp;
use App\Services\ConciliacaoService;
use App\Services\PrazoService;
use App\Services\StatusService;
use App\Support\ValidacaoException;

fx_limpar();
$_SESSION['unidades'] = null;
$comprador = fx_usuario('comprador');
$financeiro = fx_usuario('financeiro');
$aprovada = fn(string $n) => fx_ofp(['status' => 'aprovada', 'numero_ofp' => $n, 'data_abertura' => '2026-10-02', 'data_decisao' => '2026-10-05']);
$nf = ['nf_devolucao' => '700001', 'data_nf_devolucao' => '2026-10-06', 'valor_nf_devolucao' => '546,73'];
$creditada = fn(string $n, array $extra = []) => fx_ofp($extra + [
    'status' => 'creditada', 'numero_ofp' => $n, 'data_abertura' => '2026-09-01', 'data_decisao' => '2026-09-05',
    'nf_devolucao' => '29713' . substr($n, -1), 'data_nf_devolucao' => '2026-09-10', 'valor_nf_devolucao' => 546.73,
    'valor_creditado_peca' => 546.73, 'valor_creditado_st' => 0, 'titulo_apollo' => '41828', 'data_credito' => '2026-10-01',
]);

T::teste('I1: checkbox com valor não escalar grava 0 e não quebra', function () use ($comprador, $aprovada, $nf) {
    $id = $aprovada('870001');
    (new StatusService())->transitar($id, 'nf_emitida', $nf + ['nf_compartilhada' => ['x']], $comprador);
    T::igual('0', (string)fx_ofp_db($id)['nf_compartilhada']);
});

T::teste('I2: crédito reaberto tem prazo novo a partir da reabertura', function () use ($creditada, $financeiro) {
    $id = $creditada('870002');
    $s = new StatusService();
    $s->transitar($id, 'estornada', ['motivo_id' => fx_motivo('ESTORNO_FABRICANTE')], $financeiro);
    $s->transitar($id, 'aguardando_credito', ['observacao' => 'repagamento'], $financeiro);
    $o = fx_ofp_db($id);
    T::igual(date('Y-m-d'), $o['data_reabertura_credito']);
    $dias = (int)($_ENV['PRAZO_CREDITO_DIAS'] ?? 90) ?: 90;
    $esperado = (new PrazoService($dias, 10))->somarDias(date('Y-m-d'), $dias);
    T::igual($esperado, $o['prazo_data']);
});

T::teste('I2: base do prazo de crédito é a mais recente entre NF e reabertura', function () {
    $p = new PrazoService(90, 10);
    T::igual('2027-01-10', $p->calcular(['status' => 'aguardando_credito', 'data_nf_devolucao' => '2026-01-01', 'data_reabertura_credito' => '2026-10-12'], null)['data']);
    T::igual('2026-04-01', $p->calcular(['status' => 'aguardando_credito', 'data_nf_devolucao' => '2026-01-01', 'data_reabertura_credito' => null], null)['data']);
});

T::teste('I3: em análise usa a mais recente entre abertura e reanálise', function () {
    $p = new PrazoService(90, 10);
    T::igual('2026-10-16', $p->calcular(['status' => 'em_analise', 'data_abertura' => '2026-10-02', 'data_reanalise' => '2026-10-06'], 14)['data']);
    T::igual('2026-10-12', $p->calcular(['status' => 'em_analise', 'data_abertura' => '2026-10-02', 'data_reanalise' => null], 14)['data']);
});

T::teste('I4: estorno limpa título e data do crédito; nova baixa exige título', function () use ($creditada, $financeiro) {
    $id = $creditada('870003');
    $s = new StatusService();
    $s->transitar($id, 'estornada', ['motivo_id' => fx_motivo('ESTORNO_FABRICANTE')], $financeiro);
    $o = fx_ofp_db($id);
    T::igual([null, null], [$o['titulo_apollo'], $o['data_credito']]);
    $s->transitar($id, 'aguardando_credito', ['observacao' => 'repagamento'], $financeiro);
    T::lanca(ValidacaoException::class, fn() => (new ConciliacaoService())->baixar($id, ['data_credito' => date('Y-m-d'), 'valor_creditado_peca' => '546,73'], $financeiro));
});

T::teste('I5: NF só com dígitos', function () use ($comprador, $aprovada, $nf) {
    $id = $aprovada('870004');
    T::lanca(ValidacaoException::class, fn() => (new StatusService())->transitar($id, 'nf_emitida', ['nf_devolucao' => '12A34'] + $nf, $comprador));
});

T::teste('I5: zeros à esquerda não escapam da NF única', function () use ($comprador, $aprovada, $nf) {
    (new StatusService())->transitar($aprovada('870005'), 'nf_emitida', ['nf_devolucao' => '29713'] + $nf, $comprador);
    T::lanca(ValidacaoException::class, fn() => (new StatusService())->transitar($aprovada('870006'), 'nf_emitida', ['nf_devolucao' => '029713'] + $nf, $comprador));
});

T::teste('I5: marcar compartilhada sem duplicidade grava 0', function () use ($comprador, $aprovada, $nf) {
    $id = $aprovada('870007');
    (new StatusService())->transitar($id, 'nf_emitida', ['nf_devolucao' => '880001', 'nf_compartilhada' => '1', 'observacao' => 'x'] + $nf, $comprador);
    T::igual('0', (string)fx_ofp_db($id)['nf_compartilhada']);
});

T::teste('M1: data do caso GROW não pode ser anterior à rejeição', function () use ($comprador) {
    $id = fx_ofp(['status' => 'rejeitada', 'numero_ofp' => '870008', 'data_abertura' => '2026-10-02', 'data_decisao' => '2026-10-05', 'data_rejeicao' => '2026-10-05']);
    T::lanca(ValidacaoException::class, fn() => (new StatusService())->transitar($id, 'em_reanalise', ['protocolo_grow' => 'G1', 'data_reanalise' => '2026-10-03'], $comprador));
    (new StatusService())->transitar($id, 'em_reanalise', ['protocolo_grow' => 'G1', 'data_reanalise' => '2026-10-06'], $comprador);
    T::igual('em_reanalise', fx_ofp_db($id)['status']);
});

T::teste('M4: estorno não transforma NULL em 0.00', function () use ($creditada, $financeiro) {
    $id = $creditada('870009', ['valor_creditado_st' => null]);
    (new StatusService())->transitar($id, 'estornada', ['motivo_id' => fx_motivo('ESTORNO_FABRICANTE')], $financeiro);
    $o = fx_ofp_db($id);
    T::igual(['0.00', null], [$o['valor_creditado_peca'], $o['valor_creditado_st']]);
});

T::teste('N1: campo em branco na transição não apaga o valor gravado', function () use ($comprador) {
    $id = fx_ofp(['status' => 'em_reanalise', 'numero_ofp' => '870010', 'data_abertura' => '2026-10-02', 'data_decisao' => '2026-10-05', 'protocolo_grow' => 'G9', 'data_reanalise' => '2026-10-06']);
    (new StatusService())->transitar($id, 'em_analise', ['numero_ofp' => '', 'data_abertura' => ''], $comprador);
    $o = fx_ofp_db($id);
    T::igual(['em_analise', '870010', '2026-10-02'], [$o['status'], $o['numero_ofp'], $o['data_abertura']]);
});

T::teste('N1: NF gravada some só por correção, não por campo em branco', function () use ($comprador) {
    $id = fx_ofp(['status' => 'aprovada', 'numero_ofp' => '870011', 'data_abertura' => '2026-10-02', 'data_decisao' => '2026-10-05', 'nf_devolucao' => '910001']);
    (new StatusService())->transitar($id, 'nf_emitida', ['nf_devolucao' => '', 'data_nf_devolucao' => '2026-10-06', 'valor_nf_devolucao' => '546,73', 'nf_compartilhada' => '1', 'observacao' => 'x'], $comprador);
    $o = fx_ofp_db($id);
    T::igual(['910001', '0'], [$o['nf_devolucao'], (string)$o['nf_compartilhada']]);
});
