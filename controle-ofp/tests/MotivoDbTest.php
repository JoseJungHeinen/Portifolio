<?php
declare(strict_types=1);

use App\Models\Motivo;
use App\Services\StatusService;
use App\Support\ValidacaoException;

fx_limpar();
$_SESSION['unidades'] = null;
$comprador = fx_usuario('comprador');

T::teste('cancelar sem motivo é recusado', function () use ($comprador) {
    $id = fx_ofp(['status' => 'em_analise', 'numero_ofp' => '810101', 'data_abertura' => '2026-10-02']);
    T::lanca(ValidacaoException::class, fn() => (new StatusService())->transitar($id, 'cancelada', ['observacao' => 'x'], $comprador));
});

T::teste('motivo fica na OFP e no evento', function () use ($comprador) {
    $id = fx_ofp(['status' => 'em_analise', 'numero_ofp' => '810102', 'data_abertura' => '2026-10-02']);
    $motivo = fx_motivo('DUPLICADA');
    (new StatusService())->transitar($id, 'cancelada', ['motivo_id' => $motivo], $comprador);
    T::igual($motivo, fx_ofp_db($id)['motivo_id']);
    T::igual($motivo, fx_db()->query("SELECT motivo_id FROM ofp_eventos WHERE ofp_id = '{$id}'")->fetchColumn());
});

T::teste('motivos agrupados por situação', function () {
    $mapa = (new Motivo())->ativosPorStatus();
    T::igual(['ESTORNO_FABRICANTE', 'ERRO_LANCAMENTO', 'OUTRO'], array_column($mapa['estornada'], 'codigo'));
});

T::teste('motivo deixa de valer na OFP ao sair da situação, mas fica no evento', function () use ($comprador) {
    $id = fx_ofp(['status' => 'em_analise', 'numero_ofp' => '810103', 'data_abertura' => '2026-10-02']);
    $motivo = fx_motivo('OUTRO');
    $svc = new StatusService();
    $svc->transitar($id, 'rejeitada', ['data_decisao' => '2026-10-05', 'motivo_id' => $motivo, 'observacao' => 'x'], $comprador);
    T::igual($motivo, fx_ofp_db($id)['motivo_id']);
    $svc->transitar($id, 'em_reanalise', ['protocolo_grow' => 'G1', 'data_reanalise' => '2026-10-06'], $comprador);
    T::igual(null, fx_ofp_db($id)['motivo_id']);
    T::igual($motivo, fx_db()->query("SELECT motivo_id FROM ofp_eventos WHERE ofp_id = '{$id}' AND status_para = 'rejeitada'")->fetchColumn());
});
