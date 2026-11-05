<?php
declare(strict_types=1);

use App\Models\Motivo;
use App\Models\Ofp;
use App\Services\CorrecaoService;
use App\Support\RegraException;
use App\Support\ValidacaoException;

fx_limpar();
$_SESSION['unidades'] = null;
$comprador = fx_usuario('comprador');
$financeiro = fx_usuario('financeiro');
$base = ['tipo_correcao' => 'reemissao', 'observacao' => 'NF cancelada e reemitida'];
$ofpEm = fn(string $status, string $n, array $extra = []) => fx_ofp($extra + [
    'status' => $status, 'numero_ofp' => $n, 'data_recebimento' => '2026-08-25', 'data_abertura' => '2026-09-01', 'data_decisao' => '2026-09-05',
]);
$aguardando = fn(string $n, array $extra = []) => $ofpEm('aguardando_credito', $n, $extra + [
    'nf_devolucao' => '512345', 'data_nf_devolucao' => '2026-09-10', 'valor_nf_devolucao' => 1250,
]);
$creditada = fn(string $n, array $extra = []) => $ofpEm('creditada', $n, $extra + [
    'nf_devolucao' => '512345', 'data_nf_devolucao' => '2026-09-10', 'valor_nf_devolucao' => 1250,
    'valor_creditado_peca' => 1250, 'titulo_apollo' => '41828', 'data_credito' => '2026-10-01',
]);
$ultimasCampos = fn(string $id) => array_column(json_decode((string)fx_db()->query("SELECT alteracoes FROM ofp_eventos WHERE ofp_id = '{$id}' ORDER BY created_at DESC LIMIT 1")->fetchColumn(), true) ?: [], 'campo');

T::teste('creditada: valor monetário é recusado, NF e título são aceitos', function () use ($creditada, $comprador, $financeiro, $base) {
    $id = $creditada('871001');
    $svc = new CorrecaoService();
    T::lanca(ValidacaoException::class, fn() => $svc->corrigir($id, ['valor_nf_devolucao' => '1300,00'] + $base, $comprador));
    T::lanca(ValidacaoException::class, fn() => $svc->corrigir($id, ['valor_icms_st' => '10,00', 'nf_icms_st' => '9'] + $base, $comprador));
    T::lanca(ValidacaoException::class, fn() => $svc->corrigir($id, ['quantidade' => '2'] + $base, $comprador));
    $svc->corrigir($id, ['nf_devolucao' => '512399'] + $base, $comprador);
    T::igual('512399', fx_ofp_db($id)['nf_devolucao']);
    $svc->corrigir($id, ['titulo_apollo' => '41829', 'data_credito' => '2026-10-02'] + $base, $financeiro);
    T::igual(['41829', '2026-10-02'], [fx_ofp_db($id)['titulo_apollo'], fx_ofp_db($id)['data_credito']]);
});

T::teste('título e data do crédito só em creditada', function () use ($aguardando, $financeiro, $base) {
    T::igual([], CorrecaoService::camposPermitidos('financeiro', ['status' => 'aguardando_credito']));
    T::verdadeiro(!in_array('titulo_apollo', CorrecaoService::camposPermitidos('gerente', ['status' => 'aguardando_credito']), true));
    T::verdadeiro(in_array('titulo_apollo', CorrecaoService::camposPermitidos('gerente', ['status' => 'creditada']), true));
    T::lanca(RegraException::class, fn() => (new CorrecaoService())->corrigir($aguardando('871002'), ['titulo_apollo' => '1'] + $base, $financeiro));
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($aguardando('871003'), ['titulo_apollo' => '1'] + $base, fx_usuario('gerente')));
});

T::teste('valor creditado nunca é corrigível', function () use ($creditada, $base) {
    $id = $creditada('871004');
    foreach (['valor_creditado_peca', 'valor_creditado_st'] as $campo) {
        T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($id, [$campo => '1,00'] + $base, fx_usuario('admin')));
    }
    T::igual('1250.00', fx_ofp_db($id)['valor_creditado_peca']);
});

T::teste('OFP inativa (exceto creditada) não aceita correção', function () use ($ofpEm, $comprador, $base) {
    foreach (['estornada', 'cancelada', 'encerrada', 'peca_vendida'] as $i => $status) {
        $id = $ofpEm($status, '87101' . $i, ['nf_devolucao' => '512345']);
        T::lanca(RegraException::class, fn() => (new CorrecaoService())->corrigir($id, ['nf_devolucao' => '1'] + $base, $comprador));
        T::igual([], CorrecaoService::camposPermitidos('comprador', ['status' => $status]));
    }
});

T::teste('campo vazio só é preenchido depois da etapa que o coleta', function () use ($ofpEm, $comprador, $base) {
    $id = $ofpEm('em_analise', '871020');
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($id, ['nf_devolucao' => '777001'] + $base, $comprador));
    $id2 = $ofpEm('nf_emitida', '871021', ['nf_devolucao' => '777002', 'data_nf_devolucao' => '2026-09-10', 'valor_nf_devolucao' => 10]);
    (new CorrecaoService())->corrigir($id2, ['nf_icms_st' => '777003', 'valor_icms_st' => '5,00'] + $base, $comprador);
    T::igual('777003', fx_ofp_db($id2)['nf_icms_st']);
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($id2, ['data_coletada' => '2026-09-20'] + $base, $comprador));
});

T::teste('data da decisão acompanha só a data de aprovação/rejeição que era igual', function () use ($ofpEm, $comprador, $base, $ultimasCampos) {
    $id = $ofpEm('aprovada', '871030', ['data_aprovacao' => '2026-09-05', 'data_rejeicao' => '2026-09-03']);
    (new CorrecaoService())->corrigir($id, ['data_decisao' => '2026-09-06'] + $base, $comprador);
    $o = fx_ofp_db($id);
    T::igual(['2026-09-06', '2026-09-06', '2026-09-03'], [$o['data_decisao'], $o['data_aprovacao'], $o['data_rejeicao']]);
    T::verdadeiro(in_array('data_aprovacao', $ultimasCampos($id), true));
    $id2 = $ofpEm('aprovada', '871031', ['data_aprovacao' => '2026-09-04']);
    (new CorrecaoService())->corrigir($id2, ['data_decisao' => '2026-09-06'] + $base, $comprador);
    T::igual('2026-09-04', fx_ofp_db($id2)['data_aprovacao']);
    $id3 = $ofpEm('registrada', '871032', ['data_decisao' => null]);
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($id3, ['data_decisao' => '2026-09-06'] + $base, $comprador));
});

T::teste('prazo que mudou entra no histórico', function () use ($aguardando, $comprador, $base, $ultimasCampos) {
    $id = $aguardando('871040');
    (new CorrecaoService())->corrigir($id, ['data_nf_devolucao' => '2026-09-20'] + $base, $comprador);
    T::verdadeiro(in_array('prazo_data', $ultimasCampos($id), true));
});

T::teste('perda de atualização: UPDATE exige os valores antigos (NULL-safe)', function () use ($aguardando) {
    $id = $aguardando('871050');
    $m = new Ofp();
    T::igual(0, $m->updateOndeIgual($id, ['nf_devolucao' => '1'], ['status' => 'aguardando_credito'], ['nf_devolucao' => '999', 'nf_icms_st' => null]));
    T::igual(1, $m->updateOndeIgual($id, ['nf_devolucao' => '2'], ['status' => 'aguardando_credito'], ['nf_devolucao' => '512345', 'nf_icms_st' => null]));
    T::igual('2', fx_ofp_db($id)['nf_devolucao']);
});

T::teste('NF compartilhada marcada aceita NF que já está em outra OFP', function () use ($aguardando, $comprador, $base, $ultimasCampos) {
    $aguardando('871060');
    $id = $aguardando('871061');
    $svc = new CorrecaoService();
    $svc->corrigir($id, ['nf_devolucao' => '777060'] + $base, $comprador);
    T::lanca(ValidacaoException::class, fn() => $svc->corrigir($id, ['nf_devolucao' => '512345'] + $base, $comprador));
    $svc->corrigir($id, ['nf_devolucao' => '512345', 'nf_compartilhada' => '1'] + $base, $comprador);
    $o = fx_ofp_db($id);
    T::igual(['512345', '1'], [$o['nf_devolucao'], (string)$o['nf_compartilhada']]);
    T::verdadeiro(in_array('nf_compartilhada', $ultimasCampos($id), true));
});

T::teste('ICMS ST: NF e valor juntos ou nenhum', function () use ($aguardando, $comprador, $base) {
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($aguardando('871070'), ['nf_icms_st' => '555'] + $base, $comprador));
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($aguardando('871071'), ['valor_icms_st' => '5,00'] + $base, $comprador));
});

T::teste('financeiro corrige título de OFP creditada', function () use ($creditada, $financeiro, $base) {
    $id = $creditada('871080');
    T::igual(1, (new CorrecaoService())->corrigir($id, ['titulo_apollo' => '99999'] + $base, $financeiro));
    T::igual('99999', fx_ofp_db($id)['titulo_apollo']);
});

T::teste('correção respeita a unidade do usuário', function () use ($aguardando, $base) {
    $id = $aguardando('871090');
    $_SESSION['unidades'] = ['outra-unidade'];
    try {
        T::lanca(RegraException::class, fn() => (new CorrecaoService())->corrigir($id, ['nf_devolucao' => '1'] + $base, fx_usuario('comprador')));
    } finally {
        $_SESSION['unidades'] = null;
    }
});

T::teste('numero_ofp duplicado na correção é recusado', function () use ($aguardando, $comprador, $base) {
    $aguardando('871091');
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($aguardando('871092'), ['numero_ofp' => '871091'] + $base, $comprador));
});

T::teste('M2: status sem motivo ativo', function () {
    $m = fn(string $aplica, int $ativo = 1) => ['ativo' => $ativo, 'aplica_a' => $aplica];
    $sem = Motivo::statusSemMotivoAtivo([$m('rejeitada'), $m('cancelada', 0)]);
    T::verdadeiro(in_array('cancelada', $sem, true));
    T::verdadeiro(!in_array('rejeitada', $sem, true));
    T::verdadeiro(!in_array('cancelada', Motivo::statusSemMotivoAtivo([$m('cancelada,rejeitada')]), true));
});

T::teste('GROW só entra em OFP que foi rejeitada', function () use ($ofpEm, $comprador, $base) {
    $semRejeicao = $ofpEm('aprovada', '872001');
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($semRejeicao, ['protocolo_grow' => 'G123'] + $base, $comprador));
    $rejeitada = $ofpEm('aprovada', '872002', ['data_rejeicao' => '2026-09-03']);
    (new CorrecaoService())->corrigir($rejeitada, ['protocolo_grow' => 'G123'] + $base, $comprador);
    T::igual('G123', fx_ofp_db($rejeitada)['protocolo_grow']);
});

T::teste('coleta não se aplica a devolução simbólica', function () use ($comprador, $base) {
    $simbolica = fx_ofp(['causal_id' => fx_causal('AF')['id'], 'status' => 'aguardando_credito', 'numero_ofp' => '872003', 'data_abertura' => '2026-09-01',
        'data_decisao' => '2026-09-05', 'nf_devolucao' => '872003', 'data_nf_devolucao' => '2026-09-10', 'valor_nf_devolucao' => 10]);
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($simbolica, ['codigo_rastreio' => 'AB123'] + $base, $comprador));
    $fisica = fx_ofp(['status' => 'coletada', 'numero_ofp' => '872004', 'data_abertura' => '2026-09-01',
        'data_decisao' => '2026-09-05', 'nf_devolucao' => '872004', 'data_nf_devolucao' => '2026-09-10', 'valor_nf_devolucao' => 10]);
    (new CorrecaoService())->corrigir($fisica, ['codigo_rastreio' => 'AB123'] + $base, $comprador);
    T::igual('AB123', fx_ofp_db($fisica)['codigo_rastreio']);
});

T::teste('marca de NF compartilhada sem outra OFP: mensagem própria', function () use ($aguardando, $comprador, $base) {
    $id = $aguardando('872005', ['nf_devolucao' => '872005']);
    try {
        (new CorrecaoService())->corrigir($id, ['nf_compartilhada' => '1'] + $base, $comprador);
        T::verdadeiro(false, 'deveria recusar');
    } catch (ValidacaoException $e) {
        T::verdadeiro(str_contains(implode(' ', $e->erros), 'Nenhuma outra OFP usa esta NF'), implode(' ', $e->erros));
    }
});

T::teste('data da NF não pode passar da data do crédito em OFP creditada', function () use ($creditada, $comprador, $financeiro, $base) {
    $id = $creditada('872006'); // NF 2026-09-10, crédito 2026-10-01
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($id, ['data_nf_devolucao' => '2026-10-05'] + $base, $comprador));
    T::lanca(ValidacaoException::class, fn() => (new CorrecaoService())->corrigir($id, ['data_credito' => '2026-09-01'] + $base, $financeiro));
    (new CorrecaoService())->corrigir($id, ['data_nf_devolucao' => '2026-10-01'] + $base, $comprador);
    T::igual('2026-10-01', fx_ofp_db($id)['data_nf_devolucao']);
});
