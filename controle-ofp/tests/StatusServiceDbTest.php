<?php
declare(strict_types=1);

use App\Services\StatusService;
use App\Support\RegraException;
use App\Support\ValidacaoException;

fx_limpar();
$_SESSION['unidades'] = null;
$comprador = fx_usuario('comprador');
$financeiro = fx_usuario('financeiro');

T::teste('transição grava status, campos, prazo e evento', function () use ($comprador) {
    $id = fx_ofp(['status' => 'em_analise', 'numero_ofp' => '800001', 'data_abertura' => '2026-10-02']);
    (new StatusService())->transitar($id, 'aprovada', ['data_decisao' => '2026-10-05', 'observacao' => 'Aprovada no CSPS'], $comprador);
    $o = fx_ofp_db($id);
    T::igual('aprovada', $o['status']);
    T::igual('2026-10-05', $o['data_decisao']);
    T::igual('nf_devolucao', $o['prazo_tipo']);
    T::igual('2026-11-04', $o['prazo_data']);
    $evento = fx_db()->query("SELECT status_de, status_para, observacao, usuario_id FROM ofp_eventos WHERE ofp_id = '{$id}'")->fetch();
    T::igual(['status_de' => 'em_analise', 'status_para' => 'aprovada', 'observacao' => 'Aprovada no CSPS', 'usuario_id' => $comprador['id']], $evento);
});

T::teste('perfil sem permissão é barrado', function () use ($financeiro) {
    $id = fx_ofp(['status' => 'em_analise', 'numero_ofp' => '800002', 'data_abertura' => '2026-10-02']);
    T::lanca(RegraException::class, fn() => (new StatusService())->transitar($id, 'aprovada', ['data_decisao' => '2026-10-05'], $financeiro));
    T::igual('em_analise', fx_ofp_db($id)['status']);
});

T::teste('dados obrigatórios ausentes lançam ValidacaoException', function () use ($comprador) {
    $id = fx_ofp(['status' => 'em_analise', 'numero_ofp' => '800003', 'data_abertura' => '2026-10-02']);
    T::lanca(ValidacaoException::class, fn() => (new StatusService())->transitar($id, 'aprovada', [], $comprador));
});

T::teste('campos fora da transição são ignorados', function () use ($comprador) {
    $id = fx_ofp(['status' => 'em_analise', 'numero_ofp' => '800004', 'data_abertura' => '2026-10-02']);
    (new StatusService())->transitar($id, 'aprovada', ['data_decisao' => '2026-10-05', 'valor_reclamado' => '1,00'], $comprador);
    T::igual('546.73', fx_ofp_db($id)['valor_reclamado']);
});

T::teste('OFP de unidade fora do escopo é barrada', function () use ($comprador) {
    $id = fx_ofp(['status' => 'em_analise', 'numero_ofp' => '800005', 'data_abertura' => '2026-10-02']);
    $_SESSION['unidades'] = [fx_unidade('Eurovale - Jeep')];
    T::lanca(RegraException::class, fn() => (new StatusService())->transitar($id, 'aprovada', ['data_decisao' => '2026-10-05'], $comprador));
    $_SESSION['unidades'] = null;
});

T::teste('aplicar com leitura desatualizada é recusado', function () use ($comprador) {
    $id = fx_ofp(['status' => 'em_analise', 'numero_ofp' => '800006', 'data_abertura' => '2026-10-02']);
    $velho = (new App\Models\Ofp())->findComCausal($id);
    fx_db()->exec("UPDATE ofps SET status = 'cancelada' WHERE id = '{$id}'");
    T::lanca(RegraException::class, fn() => (new StatusService())->aplicar($velho, 'aprovada', ['data_decisao' => '2026-10-05'], $comprador['id'], null));
    T::igual('cancelada', fx_ofp_db($id)['status']);
});

T::teste('nº OFP duplicado é recusado', function () use ($comprador) {
    fx_ofp(['status' => 'em_analise', 'numero_ofp' => '800007', 'data_abertura' => '2026-10-02']);
    $id = fx_ofp(['status' => 'registrada']);
    T::lanca(ValidacaoException::class, fn() => (new StatusService())->transitar($id, 'em_analise', ['numero_ofp' => '800007', 'data_abertura' => '2026-10-02'], $comprador));
    T::igual('registrada', fx_ofp_db($id)['status']);
});

T::teste('filtro de semáforo "sem_prazo" lista só ativas sem prazo', function () {
    fx_limpar();
    $_SESSION['unidades'] = null;
    $a = fx_ofp(['status' => 'em_analise', 'prazo_data' => null]);
    fx_ofp(['status' => 'creditada', 'prazo_data' => null]);
    fx_ofp(['status' => 'em_analise', 'prazo_data' => date('Y-m-d')]);
    $r = (new App\Models\Ofp())->buscar(['semaforo' => 'sem_prazo']);
    T::igual([$a], array_column($r, 'id'));
});
