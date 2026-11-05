<?php
declare(strict_types=1);

use App\Services\CorrecaoService;
use App\Services\OfpService;
use App\Services\PerdaService;
use App\Services\StatusService;
use App\Support\RegraException;
use App\Support\ValidacaoException;

fx_limpar();
$_SESSION['unidades'] = null;
$comprador = fx_usuario('comprador');
$gerente = fx_usuario('gerente');
$dias = fn(int $n) => date('Y-m-d', strtotime("{$n} days"));
$form = fn(string $id, array $extra = []) => $extra + [
    'unidade_id' => fx_ofp_db($id)['unidade_id'], 'causal_id' => fx_ofp_db($id)['causal_id'], 'desenho' => '51901276',
    'quantidade' => '1', 'valor_reclamado' => '546,73', 'data_recebimento' => '2026-10-01', 'observacao' => 'ajuste',
];

T::teste('I1: unidade não muda depois que a NF de devolução foi lançada', function () use ($comprador, $form) {
    $id = fx_ofp(['status' => 'aprovada', 'numero_ofp' => '880001', 'data_abertura' => '2026-10-02', 'data_decisao' => '2026-10-05',
        'nf_devolucao' => '880001', 'data_nf_devolucao' => '2026-10-06', 'valor_nf_devolucao' => 546.73]);
    T::lanca(RegraException::class, fn() => (new OfpService())->atualizar($id, $form($id, ['unidade_id' => fx_unidade('Eurovale - Jeep')]), $comprador));
    T::igual(fx_unidade(), fx_ofp_db($id)['unidade_id']);
});

T::teste('I1: causal não muda em aguardando_coleta', function () use ($comprador, $form) {
    $id = fx_ofp(['status' => 'aguardando_coleta', 'numero_ofp' => '880002', 'data_abertura' => '2026-10-02', 'data_decisao' => '2026-10-05']);
    $outro = fx_causal('AT')['id'];
    T::lanca(RegraException::class, fn() => (new OfpService())->atualizar($id, $form($id, ['causal_id' => $outro]), $comprador));
    T::verdadeiro(fx_ofp_db($id)['causal_id'] !== $outro);
});

T::teste('I1: unidade pode mudar em registrada', function () use ($comprador, $form) {
    $id = fx_ofp(['status' => 'registrada']);
    (new OfpService())->atualizar($id, $form($id, ['unidade_id' => fx_unidade('Eurovale - Jeep')]), $comprador);
    T::igual(fx_unidade('Eurovale - Jeep'), fx_ofp_db($id)['unidade_id']);
});

T::teste('I1: valores iguais passam mesmo com a OFP travada', function () use ($comprador, $form) {
    $id = fx_ofp(['status' => 'aguardando_coleta', 'numero_ofp' => '880003', 'data_abertura' => '2026-10-02', 'data_decisao' => '2026-10-05', 'nf_devolucao' => '880003']);
    (new OfpService())->atualizar($id, $form($id, ['observacao' => 'nova obs']), $comprador);
    T::igual('nova obs', fx_ofp_db($id)['observacao']);
});

T::teste('M1: aprovar com decisão antiga já nasce com sugestão encerrada', function () use ($comprador, $dias) {
    $id = fx_ofp(['status' => 'em_analise', 'numero_ofp' => '880004', 'data_recebimento' => $dias(-80), 'data_abertura' => $dias(-70)]);
    (new StatusService())->transitar($id, 'aprovada', ['data_decisao' => $dias(-60)], $comprador);
    T::igual('encerrada', fx_ofp_db($id)['perda_sugerida']);
});

T::teste('M1: mudar de etapa sem prazo vencido não deixa sugestão', function () use ($comprador, $dias) {
    $id = fx_ofp(['status' => 'em_analise', 'numero_ofp' => '880005', 'data_recebimento' => $dias(-5), 'data_abertura' => $dias(-3), 'perda_sugerida' => 'encerrada']);
    (new StatusService())->transitar($id, 'aprovada', ['data_decisao' => $dias(-1)], $comprador);
    T::igual(null, fx_ofp_db($id)['perda_sugerida']);
});

T::teste('M4: OFP em reanálise sem data_rejeicao aceita corrigir o GROW', function () use ($comprador) {
    $id = fx_ofp(['status' => 'em_reanalise', 'numero_ofp' => '880006', 'data_abertura' => '2026-10-02', 'data_decisao' => '2026-10-05']);
    (new CorrecaoService())->corrigir($id, ['protocolo_grow' => 'G77', 'tipo_correcao' => 'digitacao', 'observacao' => 'faltou'], $comprador);
    T::igual('G77', fx_ofp_db($id)['protocolo_grow']);
});

T::teste('M8: motivo inválido no lote gera um erro só', function () use ($gerente, $dias) {
    $ids = [];
    foreach (['880010', '880011', '880012'] as $n) {
        $ids[] = fx_ofp(['status' => 'registrada', 'numero_ofp' => $n, 'prazo_data' => $dias(-10), 'perda_sugerida' => 'perdida_prazo']);
    }
    $r = (new PerdaService())->confirmar($ids, fx_motivo('NF_FORA_PRAZO'), '', $gerente);
    T::igual(0, $r['ok']);
    T::igual(1, count($r['erros']));
    $r = (new PerdaService())->confirmar($ids, '', '', $gerente);
    T::igual(1, count($r['erros']));
    T::igual('registrada', fx_ofp_db($ids[0])['status']);
});
