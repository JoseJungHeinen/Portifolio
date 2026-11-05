<?php
declare(strict_types=1);

use App\Services\OfpService;
use App\Services\StatusService;

fx_limpar();
$_SESSION['unidades'] = null;
$comprador = fx_usuario('comprador');
$estoque = fx_usuario('estoque');
$ultimoEvento = fn(string $id) => fx_db()->query("SELECT * FROM ofp_eventos WHERE ofp_id = '{$id}' ORDER BY created_at DESC LIMIT 1")->fetch();

T::teste('transição grava os campos informados no evento', function () use ($comprador, $ultimoEvento) {
    $id = fx_ofp(['status' => 'aprovada', 'numero_ofp' => '800010', 'data_abertura' => '2026-10-02', 'data_decisao' => '2026-10-05']);
    (new StatusService())->transitar($id, 'nf_emitida', ['nf_devolucao' => '512345', 'data_nf_devolucao' => '2026-10-06', 'valor_nf_devolucao' => '546,73'], $comprador);
    $alt = json_decode((string)$ultimoEvento($id)['alteracoes'], true);
    T::igual(['nf_devolucao', 'data_nf_devolucao', 'valor_nf_devolucao'], array_column($alt, 'campo'));
});

T::teste('edição grava o antes e o depois', function () use ($estoque, $ultimoEvento) {
    $entrada = [
        'unidade_id' => fx_unidade(), 'causal_id' => fx_causal('AP')['id'], 'desenho' => 'ABC123', 'descricao_peca' => 'Farol',
        'quantidade' => '1', 'valor_reclamado' => '546,73', 'data_recebimento' => date('Y-m-d', strtotime('-2 days')),
    ];
    $svc = new OfpService();
    $id = $svc->registrar($entrada, $estoque)['id'];
    $svc->atualizar($id, ['descricao_peca' => 'Farol direito', 'valor_reclamado' => '600,00'] + $entrada, $estoque);
    T::igual(
        [['campo' => 'descricao_peca', 'de' => 'Farol', 'para' => 'Farol direito'], ['campo' => 'valor_reclamado', 'de' => '546.73', 'para' => '600.00']],
        json_decode((string)$ultimoEvento($id)['alteracoes'], true)
    );
});
