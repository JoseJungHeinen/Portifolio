<?php
declare(strict_types=1);

use App\Services\CorrecaoService;
use App\Support\RegraException;
use App\Support\ValidacaoException;

T::teste('campos que cada perfil corrige', function () {
    T::verdadeiro(in_array('nf_devolucao', CorrecaoService::camposPermitidos('comprador'), true));
    T::verdadeiro(!in_array('titulo_apollo', CorrecaoService::camposPermitidos('comprador'), true));
    T::igual(['titulo_apollo', 'data_credito'], CorrecaoService::camposPermitidos('financeiro'));
    T::verdadeiro(!in_array('valor_creditado_peca', CorrecaoService::camposPermitidos('admin'), true));
    T::igual([], CorrecaoService::camposPermitidos('estoque'));
});

fx_limpar();
$_SESSION['unidades'] = null;
$comprador = fx_usuario('comprador');
$financeiro = fx_usuario('financeiro');
$estoque = fx_usuario('estoque');
$ofp = fn(string $n) => fx_ofp([
    'status' => 'aguardando_credito', 'numero_ofp' => $n, 'data_recebimento' => '2026-08-25', 'data_abertura' => '2026-09-01',
    'data_decisao' => '2026-09-05', 'nf_devolucao' => '512345', 'data_nf_devolucao' => '2026-09-10', 'valor_nf_devolucao' => 1250,
]);
$base = ['tipo_correcao' => 'reemissao', 'observacao' => 'NF 512345 cancelada e reemitida'];

T::teste('comprador corrige a NF e inclui o ICMS ST esquecido', function () use ($comprador, $ofp, $base) {
    $id = $ofp('870001');
    $n = (new CorrecaoService())->corrigir($id, ['nf_devolucao' => '512399', 'valor_icms_st' => '187,50', 'nf_icms_st' => '512400', 'data_nf_icms_st' => '2026-09-10'] + $base, $comprador);
    T::igual(4, $n);
    $o = fx_ofp_db($id);
    T::igual(['512399', '187.50'], [$o['nf_devolucao'], $o['valor_icms_st']]);
    $ev = fx_db()->query("SELECT observacao, alteracoes FROM ofp_eventos WHERE ofp_id = '{$id}' ORDER BY created_at DESC LIMIT 1")->fetch();
    T::verdadeiro(str_starts_with($ev['observacao'], 'Correção — NF cancelada e reemitida'));
    T::igual(['campo' => 'nf_devolucao', 'de' => '512345', 'para' => '512399'], json_decode($ev['alteracoes'], true)[0]);
});

T::teste('sem tipo ou sem descrição é recusado', function () use ($comprador, $ofp) {
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($ofp('870002'), ['nf_devolucao' => '1'], $comprador));
});

T::teste('nada mudou é recusado', function () use ($comprador, $ofp, $base) {
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($ofp('870003'), ['nf_devolucao' => '512345'] + $base, $comprador));
});

T::teste('estoque não corrige; financeiro não mexe na NF', function () use ($estoque, $financeiro, $ofp, $base) {
    T::lanca(RegraException::class, fn() => (new CorrecaoService())->corrigir($ofp('870004'), ['nf_devolucao' => '9'] + $base, $estoque));
    T::lanca(RegraException::class, fn() => (new CorrecaoService())->corrigir($ofp('870005'), ['nf_devolucao' => '9'] + $base, $financeiro)); // nada corrigível nesta etapa
});

T::teste('não deixa apagar campo obrigatório já preenchido', function () use ($comprador, $ofp, $base) {
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($ofp('870006'), ['nf_devolucao' => ''] + $base, $comprador));
});

T::teste('corrigir a data da NF recalcula o prazo do crédito', function () use ($comprador, $ofp, $base) {
    $id = $ofp('870007');
    (new CorrecaoService())->corrigir($id, ['data_nf_devolucao' => '2026-09-20'] + $base, $comprador);
    T::igual('2026-12-19', fx_ofp_db($id)['prazo_data']);
});

T::teste('NF já usada em outra OFP é barrada também na correção', function () use ($comprador, $ofp, $base) {
    $ofp('870008'); // usa a NF 512345
    $id = $ofp('870009');
    (new CorrecaoService())->corrigir($id, ['nf_devolucao' => '777777'] + $base, $comprador);
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($id, ['nf_devolucao' => '512345'] + $base, $comprador));
});
