<?php
declare(strict_types=1);

use App\Services\OfpValidator;

$hoje = new DateTimeImmutable('2026-10-17');
$ap = ['codigo' => 'AP', 'ativo' => 1, 'valor_minimo' => null, 'transportadora' => null, 'prazo_abertura_dias' => 14];
$ie = ['codigo' => 'IE', 'ativo' => 1, 'valor_minimo' => '700.00', 'transportadora' => null, 'prazo_abertura_dias' => 14];
$ta = ['codigo' => 'TA', 'ativo' => 1, 'valor_minimo' => null, 'transportadora' => 'azul', 'prazo_abertura_dias' => 14];
$base = ['unidade_id' => 'u1', 'causal_id' => 'c1', 'desenho' => '51901276', 'quantidade' => 1.0, 'valor_reclamado' => 800.0, 'data_recebimento' => '2026-10-10', 'transportadora' => null];

T::teste('OFP válida', fn() => T::igual(['erros' => [], 'avisos' => []], OfpValidator::validarNova($base, $ap, $hoje)));

T::teste('campos obrigatórios', fn() => T::igual(
    ['unidade_id', 'desenho', 'quantidade', 'valor_reclamado', 'data_recebimento'],
    array_keys(OfpValidator::validarNova(['quantidade' => 0, 'valor_reclamado' => 0], $ap, $hoje)['erros'])
));

T::teste('sem causal', fn() => T::igual(['causal_id'], array_keys(OfpValidator::validarNova($base, null, $hoje)['erros'])));
T::teste('causal inativo', fn() => T::igual(['causal_id'], array_keys(OfpValidator::validarNova($base, ['ativo' => 0] + $ap, $hoje)['erros'])));
T::teste('recebimento no futuro', fn() => T::igual(['data_recebimento'], array_keys(OfpValidator::validarNova(['data_recebimento' => '2026-10-18'] + $base, $ap, $hoje)['erros'])));

T::teste('IE abaixo do mínimo', function () use ($base, $ie, $hoje) {
    T::igual(['valor_reclamado' => 'Causal IE exige valor mínimo de R$ 700,00.'], OfpValidator::validarNova(['valor_reclamado' => 699.99] + $base, $ie, $hoje)['erros']);
    T::igual([], OfpValidator::validarNova(['valor_reclamado' => 700.0] + $base, $ie, $hoje)['erros']);
});

T::teste('transportadora divergente gera aviso, não erro', function () use ($base, $ta, $hoje) {
    $r = OfpValidator::validarNova(['transportadora' => 'jat'] + $base, $ta, $hoje);
    T::igual([], $r['erros']);
    T::igual(1, count($r['avisos']));
});

T::teste('prazo de abertura vencido gera aviso', function () use ($base, $ap, $hoje) {
    T::igual(['Prazo de abertura do causal AP venceu em 16/10/2026. A Fabricante pode rejeitar a OFP.'], OfpValidator::validarNova(['data_recebimento' => '2026-10-02'] + $base, $ap, $hoje)['avisos']);
    T::igual([], OfpValidator::validarNova(['data_recebimento' => '2026-10-03'] + $base, $ap, $hoje)['avisos']);
});

T::teste('nº OFP só com números', fn() => T::igual(['numero_ofp'], array_keys(OfpValidator::validarNova(['numero_ofp' => 'ABC'] + $base, $ap, $hoje)['erros'])));

T::teste('limites de tamanho e faixa', function () use ($base, $ap, $hoje) {
    $erros = fn(array $x) => array_keys(OfpValidator::validarNova($x + $base, $ap, $hoje)['erros']);
    T::igual(['desenho'], $erros(['desenho' => str_repeat('A', 31)]));
    T::igual(['descricao_peca'], $erros(['descricao_peca' => str_repeat('A', 151)]));
    T::igual(['nf_origem'], $erros(['nf_origem' => str_repeat('1', 21)]));
    T::igual(['serie_origem'], $erros(['serie_origem' => '123456']));
    T::igual(['numero_volume'], $erros(['numero_volume' => str_repeat('1', 31)]));
    T::igual(['observacao'], $erros(['observacao' => str_repeat('a', 2001)]));
    T::igual(['quantidade'], $erros(['quantidade' => 100000000.0]));
    T::igual(['valor_reclamado'], $erros(['valor_reclamado' => 10000000000.0]));
    T::igual([], $erros(['desenho' => str_repeat('A', 30), 'observacao' => str_repeat('á', 2000)]));
});

T::teste('numero_ofp "0" é validado', fn() => T::igual(['numero_ofp'], array_keys(OfpValidator::validarNova(['numero_ofp' => '0'] + $base, $ap, $hoje)['erros'])));
