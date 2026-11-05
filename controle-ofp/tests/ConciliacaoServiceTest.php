<?php
declare(strict_types=1);

use App\Services\ConciliacaoService;
use App\Support\RegraException;

T::teste('em aberto separa peça e ICMS ST', function () {
    T::igual(['peca' => 546.73, 'st' => 0.0], ConciliacaoService::emAberto(['valor_reclamado' => '546.73', 'valor_nf_devolucao' => null, 'valor_creditado_peca' => null, 'valor_icms_st' => null, 'valor_creditado_st' => null]));
    T::igual(['peca' => 0.0, 'st' => 2.06], ConciliacaoService::emAberto(['valor_reclamado' => '14.44', 'valor_nf_devolucao' => '14.44', 'valor_creditado_peca' => '14.44', 'valor_icms_st' => '2.06', 'valor_creditado_st' => null]));
    T::igual(['peca' => 0.0, 'st' => 0.0], ConciliacaoService::emAberto(['valor_reclamado' => '10', 'valor_nf_devolucao' => '10', 'valor_creditado_peca' => '12', 'valor_icms_st' => null, 'valor_creditado_st' => null]));
});

fx_limpar();
$_SESSION['unidades'] = null;
$fin = fx_usuario('financeiro');
$aguardando = fn(string $numero) => fx_ofp([
    'status' => 'aguardando_credito', 'numero_ofp' => $numero, 'data_recebimento' => '2026-08-25', 'data_abertura' => '2026-09-01',
    'data_decisao' => '2026-09-05', 'nf_devolucao' => '29713', 'data_nf_devolucao' => '2026-09-10', 'valor_nf_devolucao' => 14.44, 'valor_icms_st' => 2.06,
]);

T::teste('baixa parcial mantém aguardando crédito', function () use ($fin, $aguardando) {
    $id = $aguardando('820001');
    T::igual('parcial', (new ConciliacaoService())->baixar($id, ['titulo_apollo' => '41828', 'data_credito' => '2026-10-10', 'valor_creditado_peca' => '14,44', 'valor_creditado_st' => ''], $fin));
    $o = fx_ofp_db($id);
    T::igual('aguardando_credito', $o['status']);
    T::igual('14.44', $o['valor_creditado_peca']);
});

T::teste('baixa integral credita e zera prazo', function () use ($fin, $aguardando) {
    $id = $aguardando('820002');
    T::igual('creditada', (new ConciliacaoService())->baixar($id, ['titulo_apollo' => '41829', 'data_credito' => '2026-10-10', 'valor_creditado_peca' => '14,44', 'valor_creditado_st' => '2,06'], $fin));
    $o = fx_ofp_db($id);
    T::igual('creditada', $o['status']);
    T::igual(null, $o['prazo_data']);
});

T::teste('baixa em lote pelo valor integral', function () use ($fin, $aguardando) {
    $r = (new ConciliacaoService())->baixarLote([$aguardando('820003'), $aguardando('820004')], '50001', '2026-10-11', $fin);
    T::igual(['ok' => 2, 'erros' => []], $r);
});

T::teste('não baixa OFP fora de aguardando crédito', function () use ($fin) {
    $id = fx_ofp(['status' => 'em_analise', 'numero_ofp' => '820005', 'data_abertura' => '2026-10-02']);
    T::lanca(RegraException::class, fn() => (new ConciliacaoService())->baixar($id, ['titulo_apollo' => '1', 'data_credito' => '2026-10-10', 'valor_creditado_peca' => '1'], $fin));
});

T::teste('comprador não dá baixa', function () use ($aguardando) {
    $id = $aguardando('820006');
    T::lanca(RegraException::class, fn() => (new ConciliacaoService())->baixar($id, ['titulo_apollo' => '1', 'data_credito' => '2026-10-10', 'valor_creditado_peca' => '1'], fx_usuario('comprador')));
});

T::teste('conciliação por unidade e ano', function () {
    $linhas = (new ConciliacaoService())->conciliacao();
    T::igual(1, count($linhas));
    T::igual('2026', (string)$linhas[0]['ano']);
    T::igual(5, (int)$linhas[0]['qtd']);
});

// --- revisão: baixa parcial cumulativa ---
fx_limpar();
$_SESSION['unidades'] = null;
$fin = fx_usuario('financeiro');
$aguardando = fn(string $numero) => fx_ofp([
    'status' => 'aguardando_credito', 'numero_ofp' => $numero, 'data_recebimento' => '2026-08-25', 'data_abertura' => '2026-09-01',
    'data_decisao' => '2026-09-05', 'nf_devolucao' => '29713', 'data_nf_devolucao' => '2026-09-10', 'valor_nf_devolucao' => 14.44, 'valor_icms_st' => 2.06,
]);

T::teste('campo em branco mantém o acumulado: ST primeiro, depois só peça', function () use ($fin, $aguardando) {
    $id = $aguardando('830001');
    $s = new ConciliacaoService();
    T::igual('parcial', $s->baixar($id, ['titulo_apollo' => '777', 'data_credito' => '2026-10-10', 'valor_creditado_peca' => '', 'valor_creditado_st' => '2,06'], $fin));
    T::igual('2.06', fx_ofp_db($id)['valor_creditado_st']);
    T::igual('creditada', $s->baixar($id, ['titulo_apollo' => '778', 'data_credito' => '2026-10-11', 'valor_creditado_peca' => '14,44', 'valor_creditado_st' => ''], $fin));
    $o = fx_ofp_db($id);
    T::igual('2.06', $o['valor_creditado_st']);
    T::igual('creditada', $o['status']);
    $obs = fx_db()->prepare('SELECT observacao FROM ofp_eventos WHERE ofp_id = ? ORDER BY created_at DESC LIMIT 1');
    $obs->execute([$id]);
    T::verdadeiro(str_starts_with((string)$obs->fetchColumn(), 'Título 778: '));
});

T::teste('total creditado menor que o registrado é rejeitado', function () use ($fin, $aguardando) {
    $id = $aguardando('830002');
    $s = new ConciliacaoService();
    $s->baixar($id, ['titulo_apollo' => '1', 'data_credito' => '2026-10-10', 'valor_creditado_peca' => '10,00', 'valor_creditado_st' => ''], $fin);
    T::lanca(App\Support\ValidacaoException::class, fn() => $s->baixar($id, ['titulo_apollo' => '1', 'data_credito' => '2026-10-10', 'valor_creditado_peca' => '5,00'], $fin));
});

T::teste('data do crédito não pode ser anterior à NF de devolução', function () use ($fin, $aguardando) {
    $id = $aguardando('830003');
    T::lanca(App\Support\ValidacaoException::class, fn() => (new ConciliacaoService())->baixar($id, ['titulo_apollo' => '1', 'data_credito' => '2026-09-01', 'valor_creditado_peca' => '14,44', 'valor_creditado_st' => '2,06'], $fin));
});

T::teste('lote: limite de 200 e OFP inexistente vira erro', function () use ($fin) {
    T::lanca(App\Support\ValidacaoException::class, fn() => (new ConciliacaoService())->baixarLote(array_fill(0, 201, 'x'), '1', '2026-10-11', $fin));
    $r = (new ConciliacaoService())->baixarLote(['nao-existe'], '1', '2026-10-11', $fin);
    T::igual(['ok' => 0, 'erros' => ['OFP não encontrada.']], $r);
});
