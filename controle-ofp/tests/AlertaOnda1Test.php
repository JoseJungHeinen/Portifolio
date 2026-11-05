<?php
declare(strict_types=1);

use App\Services\AlertaService;
use App\Services\StatusService;

T::teste('quem age em cada etapa', function () {
    T::igual(['comprador'], StatusService::perfisQueAgem('em_analise'));
    T::igual(['comprador', 'estoque'], StatusService::perfisQueAgem('aguardando_coleta'));
    T::igual(['financeiro'], StatusService::perfisQueAgem('aguardando_credito'));
    T::igual([], StatusService::perfisQueAgem('creditada'));
    T::igual([], StatusService::perfisQueAgem('estornada'));
});

T::teste('alerta vai para quem age na unidade', function () {
    $ofps = [
        ['id' => 'o1', 'unidade_id' => 'u1', 'status' => 'em_analise', 'responsavel_email' => null, 'responsavel_nome' => null],
        ['id' => 'o2', 'unidade_id' => 'u2', 'status' => 'aguardando_credito', 'responsavel_email' => null, 'responsavel_nome' => null],
    ];
    $atores = [
        ['email' => 'comp@x', 'nome' => 'Comp', 'perfil' => 'comprador', 'unidades' => ['u1']],
        ['email' => 'comp2@x', 'nome' => 'Comp2', 'perfil' => 'comprador', 'unidades' => ['u2']],
        ['email' => 'fin@x', 'nome' => 'Fin', 'perfil' => 'financeiro', 'unidades' => null],
    ];
    $g = AlertaService::agrupar($ofps, [], $atores);
    T::igual(['comp@x', 'fin@x'], array_keys($g));
    T::igual(['o2'], array_column($g['fin@x']['ofps'], 'id'));
});

T::teste('situação: prazo ou parada', function () {
    $hoje = new DateTimeImmutable('2026-10-23');
    T::igual('2 dia(s) atrasado', AlertaService::situacao(['prazo_data' => '2026-10-21', 'dias_parada' => 1], $hoje));
    T::igual('vence hoje', AlertaService::situacao(['prazo_data' => '2026-10-23', 'dias_parada' => 1], $hoje));
    T::igual('sem movimento há 20 dias', AlertaService::situacao(['prazo_data' => null, 'dias_parada' => 20], $hoje));
    T::igual('sem movimento há 20 dias', AlertaService::situacao(['prazo_data' => '2026-11-30', 'dias_parada' => 20], $hoje));
});

T::teste('responsável só recebe OFP de unidade que ainda enxerga', function () {
    $ofps = [
        ['id' => 'o1', 'unidade_id' => 'u1', 'status' => 'em_analise', 'responsavel_email' => 'r@x', 'responsavel_nome' => 'R', 'responsavel_unidades' => ['u1']],
        ['id' => 'o2', 'unidade_id' => 'u2', 'status' => 'em_analise', 'responsavel_email' => 'r@x', 'responsavel_nome' => 'R', 'responsavel_unidades' => ['u1']],
        ['id' => 'o3', 'unidade_id' => 'u2', 'status' => 'em_analise', 'responsavel_email' => 't@x', 'responsavel_nome' => 'T', 'responsavel_unidades' => null],
    ];
    $g = AlertaService::agrupar($ofps, []);
    T::igual(['o1'], array_column($g['r@x']['ofps'], 'id'));
    T::igual(['o3'], array_column($g['t@x']['ofps'], 'id'));
});
