<?php
declare(strict_types=1);

use App\Services\AlertaService;

T::teste('agrupa por responsável e pelos gerentes das unidades', function () {
    $ofps = [
        ['id' => 'o1', 'unidade_id' => 'u1', 'responsavel_email' => 'ana@x', 'responsavel_nome' => 'Ana'],
        ['id' => 'o2', 'unidade_id' => 'u2', 'responsavel_email' => null, 'responsavel_nome' => null],
    ];
    $gerentes = [
        ['email' => 'ger1@x', 'nome' => 'Ger1', 'unidades' => ['u1']],
        ['email' => 'geral@x', 'nome' => 'Geral', 'unidades' => null],
        ['email' => 'ana@x', 'nome' => 'Ana', 'unidades' => ['u1']],
    ];
    $g = AlertaService::agrupar($ofps, $gerentes);
    T::igual(['ana@x', 'ger1@x', 'geral@x'], array_keys($g));
    T::igual(['o1'], array_column($g['ana@x']['ofps'], 'id'));
    T::igual(['o1'], array_column($g['ger1@x']['ofps'], 'id'));
    T::igual(['o1', 'o2'], array_column($g['geral@x']['ofps'], 'id'));
});

T::teste('sem OFPs não gera e-mail', fn() => T::igual([], AlertaService::agrupar([], [['email' => 'g@x', 'nome' => 'G', 'unidades' => null]])));

T::teste('executar sem OFPs devolve contadores zerados', function () {
    fx_limpar();
    T::igual(['enviados' => 0, 'falhas' => 0], (new AlertaService())->executar());
});
