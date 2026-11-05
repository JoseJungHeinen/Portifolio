<?php
declare(strict_types=1);

use App\Services\OfpService;
use App\Services\PrazoService;
use App\Support\ValidacaoException;

fx_limpar();
$_SESSION['unidades'] = null;
$estoque = fx_usuario('estoque');
$entrada = [
    'unidade_id' => fx_unidade(), 'causal_id' => fx_causal('AP')['id'], 'desenho' => 'abc123', 'descricao_peca' => 'Farol',
    'quantidade' => '1', 'valor_reclamado' => '546,73', 'data_recebimento' => date('Y-m-d', strtotime('-2 days')), 'transportadora' => 'azul',
];
$contarEventos = fn(string $id) => (int)fx_db()->query("SELECT COUNT(*) FROM ofp_eventos WHERE ofp_id = '{$id}'")->fetchColumn();

T::teste('ocorrência sem nº entra como registrada com prazo de abertura', function () use ($entrada, $estoque, $contarEventos) {
    $r = (new OfpService())->registrar($entrada, $estoque);
    $o = fx_ofp_db($r['id']);
    T::igual('registrada', $o['status']);
    T::igual('ABC123', $o['desenho']);
    T::igual('546.73', $o['valor_reclamado']);
    T::igual('abertura', $o['prazo_tipo']);
    T::igual(date('Y-m-d', strtotime('+12 days')), $o['prazo_data']);
    T::igual($estoque['id'], $o['responsavel_id']);
    T::igual(1, $contarEventos($r['id']));
});

T::teste('com nº do CSPS já entra em análise', function () use ($entrada, $estoque) {
    $o = fx_ofp_db((new OfpService())->registrar(['numero_ofp' => '810001'] + $entrada, $estoque)['id']);
    T::igual('em_analise', $o['status']);
    T::igual(date('Y-m-d'), $o['data_abertura']);
    T::igual(PrazoService::padrao()->somarDias(date('Y-m-d'), (int)($_ENV['SLA_ANALISE_DIAS'] ?? 10) > 0 ? (int)($_ENV['SLA_ANALISE_DIAS'] ?? 10) : 10), $o['prazo_data']);
});

T::teste('nº duplicado é recusado', fn() => T::lanca(ValidacaoException::class, fn() => (new OfpService())->registrar(['numero_ofp' => '810001'] + $entrada, $estoque)));

T::teste('unidade fora do escopo é recusada', function () use ($entrada, $estoque) {
    $_SESSION['unidades'] = [fx_unidade('Eurovale - Jeep')];
    T::lanca(ValidacaoException::class, fn() => (new OfpService())->registrar($entrada, $estoque));
    $_SESSION['unidades'] = null;
});

T::teste('atualizar recalcula prazo e registra evento', function () use ($entrada, $estoque, $contarEventos) {
    $svc = new OfpService();
    $id = $svc->registrar($entrada, $estoque)['id'];
    $svc->atualizar($id, ['data_recebimento' => date('Y-m-d', strtotime('-5 days'))] + $entrada, $estoque);
    T::igual(date('Y-m-d', strtotime('+9 days')), fx_ofp_db($id)['prazo_data']);
    T::igual(2, $contarEventos($id));
});

T::teste('OFP importada com causal legado e sem recebimento pode ser editada', function () use ($estoque) {
    $id = fx_ofp(['causal_id' => fx_causal('AT')['id'], 'data_recebimento' => null, 'status' => 'aguardando_coleta', 'numero_ofp' => '606376', 'origem_importacao' => 'planilha_v4:linha 2']);
    (new OfpService())->atualizar($id, ['unidade_id' => fx_unidade(), 'causal_id' => fx_causal('AT')['id'], 'desenho' => '100208682', 'quantidade' => '1', 'valor_reclamado' => '20,39', 'observacao' => 'Coleta agendada'], $estoque);
    T::igual('Coleta agendada', fx_ofp_db($id)['observacao']);
});

T::teste('responsável inválido e unidade inexistente são recusados', function () use ($entrada, $estoque) {
    $fin = fx_usuario('financeiro');
    foreach ([['responsavel_id' => $fin['id']], ['responsavel_id' => uuid()]] as $extra) {
        try {
            (new OfpService())->registrar($extra + $entrada, $estoque);
            T::igual(true, false);
        } catch (ValidacaoException $e) {
            T::igual(['responsavel_id'], array_keys($e->erros));
        }
    }
    try {
        (new OfpService())->registrar(['unidade_id' => uuid()] + $entrada, $estoque);
        T::igual(true, false);
    } catch (ValidacaoException $e) {
        T::igual(['unidade_id'], array_keys($e->erros));
    }
    $comp = fx_usuario('comprador');
    $r = (new OfpService())->registrar(['responsavel_id' => $comp['id']] + $entrada, $estoque);
    T::igual($comp['id'], fx_ofp_db($r['id'])['responsavel_id']);
});

T::teste('valores não escalares viram nulo', function () use ($entrada, $estoque) {
    $r = (new OfpService())->registrar(['observacao' => ['x'], 'descricao_peca' => ['y']] + $entrada, $estoque);
    T::igual(null, fx_ofp_db($r['id'])['observacao']);
});

T::teste('edição mantém responsável atual mesmo inativo (OFP legada)', function () use ($entrada, $estoque) {
    $resp = fx_usuario('comprador');
    fx_db()->prepare('UPDATE usuarios SET ativo = 0 WHERE id = ?')->execute([$resp['id']]);
    $id = fx_ofp(['responsavel_id' => $resp['id']]);
    (new OfpService())->atualizar($id, ['responsavel_id' => $resp['id'], 'observacao' => 'ok'] + $entrada, $estoque);
    T::igual('ok', fx_ofp_db($id)['observacao']);
    T::igual($resp['id'], fx_ofp_db($id)['responsavel_id']);
});

T::teste('quebras de linha são removidas dos campos de uma linha (menos observação)', function () use ($entrada, $estoque) {
    $r = (new OfpService())->registrar(['descricao_peca' => "Farol\r\nBcc: x", 'observacao' => "a\nb"] + $entrada, $estoque);
    $o = fx_ofp_db($r['id']);
    T::igual('Farol Bcc: x', $o['descricao_peca']);
    T::igual("a\nb", $o['observacao']);
});

T::teste('aguardando_coleta: data de solicitação e rastreio editáveis e recalculam o prazo', function () use ($entrada, $estoque) {
    $id = fx_ofp(['status' => 'aguardando_coleta', 'numero_ofp' => '820001', 'data_nf_devolucao' => date('Y-m-d', strtotime('-10 days')), 'valor_nf_devolucao' => 100]);
    $dia = date('Y-m-d', strtotime('-3 days'));
    (new OfpService())->atualizar($id, ['data_coleta_solicitada' => $dia, 'codigo_rastreio' => 'BR123'] + $entrada, $estoque);
    $o = fx_ofp_db($id);
    T::igual('coleta', $o['prazo_tipo']);
    T::igual($dia, $o['data_coleta_solicitada']);
    T::igual('BR123', $o['codigo_rastreio']);
    T::lanca(ValidacaoException::class, fn() => (new OfpService())->atualizar($id, ['data_coleta_solicitada' => date('Y-m-d', strtotime('+1 day'))] + $entrada, $estoque));
    T::lanca(ValidacaoException::class, fn() => (new OfpService())->atualizar($id, ['data_coleta_solicitada' => date('Y-m-d', strtotime('-20 days'))] + $entrada, $estoque));
});

T::teste('fora de aguardando_coleta a data de coleta é ignorada', function () use ($entrada, $estoque) {
    $id = fx_ofp(['status' => 'em_analise', 'numero_ofp' => '820002']);
    (new OfpService())->atualizar($id, ['data_coleta_solicitada' => date('Y-m-d'), 'codigo_rastreio' => 'X'] + $entrada, $estoque);
    T::igual(null, fx_ofp_db($id)['data_coleta_solicitada']);
    T::igual(null, fx_ofp_db($id)['codigo_rastreio']);
});

T::teste('código de rastreio: quebras de linha viram espaço', function () use ($entrada, $estoque) {
    $id = fx_ofp(['status' => 'aguardando_coleta', 'numero_ofp' => '820003', 'data_nf_devolucao' => date('Y-m-d', strtotime('-10 days')), 'valor_nf_devolucao' => 100]);
    (new OfpService())->atualizar($id, ['data_coleta_solicitada' => date('Y-m-d', strtotime('-3 days')), 'codigo_rastreio' => "AB\r\nC"] + $entrada, $estoque);
    T::igual('AB C', fx_ofp_db($id)['codigo_rastreio']);
});
