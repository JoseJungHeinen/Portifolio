<?php
declare(strict_types=1);

use App\Services\PainelService;

fx_limpar();
$_SESSION['unidades'] = null;
fx_ofp(['status' => 'perdida_prazo', 'numero_ofp' => '880001', 'valor_reclamado' => 100, 'motivo_id' => fx_motivo('SEM_RESSALVA')]);
fx_ofp(['status' => 'perdida_prazo', 'numero_ofp' => '880002', 'valor_reclamado' => 50, 'motivo_id' => fx_motivo('SEM_RESSALVA')]);
fx_ofp(['status' => 'rejeitada_final', 'numero_ofp' => '880006', 'valor_reclamado' => 20, 'motivo_id' => fx_motivo('SEM_RESSALVA')]);
fx_ofp(['status' => 'cancelada', 'numero_ofp' => '880007', 'valor_reclamado' => 999, 'motivo_id' => fx_motivo('DUPLICADA')]);
fx_ofp(['status' => 'estornada', 'numero_ofp' => '880008', 'valor_reclamado' => 888]);
fx_ofp(['status' => 'peca_vendida', 'numero_ofp' => '880009', 'valor_reclamado' => 777, 'motivo_id' => fx_motivo('PECA_VENDIDA')]);
fx_ofp(['status' => 'encerrada', 'numero_ofp' => '880003', 'valor_reclamado' => 30]);
fx_ofp(['status' => 'registrada', 'numero_ofp' => '880004', 'valor_reclamado' => 70, 'perda_sugerida' => 'perdida_prazo']);
fx_ofp(['status' => 'creditada', 'numero_ofp' => '880005', 'valor_reclamado' => 900, 'valor_creditado_peca' => 900]);
$filtros = ['unidade_id' => '', 'causal_id' => '', 'ano' => ''];

T::teste('perdas por status e motivo: só perda real (sem cancelada, estornada, vendida)', fn() => T::igual(
    [['perdida_prazo', 'Sem ressalva no CTe/DACTE', 2, 150.0], ['encerrada', 'Sem motivo registrado', 1, 30.0], ['rejeitada_final', 'Sem ressalva no CTe/DACTE', 1, 20.0]],
    array_map(fn($r) => [$r['status'], $r['motivo'], (int)$r['qtd'], (float)$r['valor']], (new PainelService())->perdasPorMotivo($filtros))
));

T::teste('card de provável perda', fn() => T::igual(70.0, (new PainelService())->cards($filtros)['provavel_perda']));

T::teste('perdas e provável perda respeitam o escopo de unidades', function () use ($filtros) {
    $_SESSION['unidades'] = [fx_unidade('Eurovale - Outra')];
    $perdas = (new PainelService())->perdasPorMotivo($filtros);
    $provavel = (new PainelService())->cards($filtros)['provavel_perda'];
    $_SESSION['unidades'] = null;
    T::igual([], $perdas);
    T::igual(0.0, $provavel);
});
