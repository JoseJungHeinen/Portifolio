<?php
declare(strict_types=1);

use App\Services\StatusService;

fx_limpar();
$_SESSION['unidades'] = null;
$comprador = fx_usuario('comprador');

T::teste('rejeição e aprovação guardam datas separadas', function () use ($comprador) {
    $id = fx_ofp(['status' => 'em_analise', 'numero_ofp' => '840001', 'data_abertura' => '2026-10-02']);
    $s = new StatusService();
    $s->transitar($id, 'rejeitada', ['data_decisao' => '2026-10-05', 'motivo_id' => fx_motivo('SEM_FOTO')], $comprador);
    $s->transitar($id, 'em_reanalise', ['protocolo_grow' => '17305169', 'data_reanalise' => '2026-10-06'], $comprador);
    $s->transitar($id, 'aprovada', ['data_decisao' => '2026-10-09'], $comprador);
    $o = fx_ofp_db($id);
    T::igual(['2026-10-05', '2026-10-09', '2026-10-09'], [$o['data_rejeicao'], $o['data_aprovacao'], $o['data_decisao']]);
});

T::teste('regra pura das datas de decisão', function () {
    T::igual(['data_rejeicao' => '2026-10-05'], StatusService::datasDecisao('rejeitada', '2026-10-05'));
    T::igual(['data_aprovacao' => '2026-10-09'], StatusService::datasDecisao('aprovada', '2026-10-09'));
    T::igual([], StatusService::datasDecisao('nf_emitida', '2026-10-09'));
    T::igual([], StatusService::datasDecisao('aprovada', null));
});
