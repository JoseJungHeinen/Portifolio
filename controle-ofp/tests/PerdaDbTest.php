<?php
declare(strict_types=1);

use App\Services\PerdaService;
use App\Services\StatusService;

fx_limpar();
$_SESSION['unidades'] = null;
$gerente = fx_usuario('gerente');
$comprador = fx_usuario('comprador');
$dias = fn(int $n) => date('Y-m-d', strtotime("{$n} days"));

T::teste('marca, confirma e limpa sugestões', function () use ($gerente, $comprador, $dias) {
    $perdida = fx_ofp(['status' => 'registrada', 'numero_ofp' => '890001', 'prazo_tipo' => 'abertura', 'prazo_data' => $dias(-10)]);
    $encerrar = fx_ofp(['status' => 'aprovada', 'numero_ofp' => '890002', 'data_abertura' => $dias(-60), 'data_decisao' => $dias(-40), 'data_recebimento' => $dias(-70), 'prazo_tipo' => 'nf_devolucao', 'prazo_data' => $dias(-10)]);
    $emDia = fx_ofp(['status' => 'registrada', 'numero_ofp' => '890003', 'prazo_tipo' => 'abertura', 'prazo_data' => $dias(5)]);

    T::igual(2, (new PerdaService())->atualizarSugestoes());
    T::igual(['perdida_prazo', 'encerrada', null], [fx_ofp_db($perdida)['perda_sugerida'], fx_ofp_db($encerrar)['perda_sugerida'], fx_ofp_db($emDia)['perda_sugerida']]);

    $r = (new PerdaService())->confirmar([$perdida], fx_motivo('FORA_PRAZO'), '', $gerente);
    T::igual(['ok' => 1, 'erros' => []], $r);
    T::igual(['perdida_prazo', null], [fx_ofp_db($perdida)['status'], fx_ofp_db($perdida)['perda_sugerida']]);

    // Mudar de etapa limpa a sugestão
    (new StatusService())->transitar($encerrar, 'nf_emitida', ['nf_devolucao' => '777001', 'data_nf_devolucao' => $dias(0), 'valor_nf_devolucao' => '100'], $comprador);
    T::igual(null, fx_ofp_db($encerrar)['perda_sugerida']);
});

T::teste('sugestão que deixou de valer é removida', function () use ($dias) {
    $id = fx_ofp(['status' => 'registrada', 'numero_ofp' => '890004', 'prazo_data' => $dias(5), 'perda_sugerida' => 'perdida_prazo']);
    (new PerdaService())->atualizarSugestoes();
    T::igual(null, fx_ofp_db($id)['perda_sugerida']);
});

T::teste('OFP sem sugestão não é confirmada', function () use ($gerente) {
    $id = fx_ofp(['status' => 'registrada', 'numero_ofp' => '890010']);
    T::igual(0, (new PerdaService())->confirmar([$id], fx_motivo('FORA_PRAZO'), '', $gerente)['ok']);
});

T::teste('confirmar respeita o escopo de unidades', function () use ($gerente, $dias) {
    $id = fx_ofp(['status' => 'registrada', 'numero_ofp' => '890011', 'prazo_data' => $dias(-10), 'perda_sugerida' => 'perdida_prazo']);
    $_SESSION['unidades'] = [fx_unidade('Eurovale - Outra')];
    $r = (new PerdaService())->confirmar([$id], fx_motivo('FORA_PRAZO'), '', $gerente);
    $_SESSION['unidades'] = null;
    T::igual(0, $r['ok']);
    T::igual('registrada', fx_ofp_db($id)['status']);
});

T::teste('motivo que não se aplica gera erro e a OFP continua', function () use ($gerente, $dias) {
    $id = fx_ofp(['status' => 'registrada', 'numero_ofp' => '890012', 'prazo_data' => $dias(-10), 'perda_sugerida' => 'perdida_prazo']);
    $r = (new PerdaService())->confirmar([$id], fx_motivo('NF_FORA_PRAZO'), '', $gerente);
    T::igual(0, $r['ok']);
    T::igual(1, count($r['erros']));
    T::igual('registrada', fx_ofp_db($id)['status']);
});

T::teste('sugestão velha: prazo corrigido depois da sugestão, confirmar recusa e limpa', function () use ($gerente, $dias) {
    $id = fx_ofp(['status' => 'registrada', 'numero_ofp' => '890020', 'prazo_tipo' => 'abertura', 'prazo_data' => $dias(5), 'perda_sugerida' => 'perdida_prazo']);
    $r = (new PerdaService())->confirmar([$id], fx_motivo('FORA_PRAZO'), '', $gerente);
    T::igual(0, $r['ok']);
    T::igual(['OFP 890020: prazo mudou; sugestão não vale mais.'], $r['erros']);
    T::igual(['registrada', null], [fx_ofp_db($id)['status'], fx_ofp_db($id)['perda_sugerida']]);
});

T::teste('corrigir data que move o prazo recalcula a sugestão', function () use ($dias) {
    $id = fx_ofp(['status' => 'aprovada', 'numero_ofp' => '890021', 'data_recebimento' => $dias(-70), 'data_abertura' => $dias(-60), 'data_decisao' => $dias(-40),
        'prazo_tipo' => 'nf_devolucao', 'prazo_data' => $dias(-10), 'perda_sugerida' => 'encerrada']);
    (new App\Services\CorrecaoService())->corrigir($id, ['data_decisao' => $dias(-5), 'tipo_correcao' => 'digitacao', 'observacao' => 'Data errada'], fx_usuario('comprador'));
    T::verdadeiro(fx_ofp_db($id)['prazo_data'] >= $dias(0));
    T::igual(null, fx_ofp_db($id)['perda_sugerida']);
});

T::teste('mais de 200 OFPs por vez é recusado', function () use ($gerente) {
    $ids = array_map(fn($i) => "id-{$i}", range(1, 201));
    $r = (new PerdaService())->confirmar($ids, fx_motivo('FORA_PRAZO'), '', $gerente);
    T::igual(['ok' => 0, 'erros' => ['Selecione no máximo 200 OFPs por vez.']], $r);
});

T::teste('ids repetidos contam uma vez só no limite do lote', function () use ($gerente) {
    $id = fx_ofp(['status' => 'registrada', 'numero_ofp' => '890030']);
    $r = (new PerdaService())->confirmar(array_fill(0, 250, $id), fx_motivo('FORA_PRAZO'), '', $gerente);
    T::verdadeiro(!in_array('Selecione no máximo 200 OFPs por vez.', $r['erros'], true));
});
