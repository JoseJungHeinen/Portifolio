<?php
declare(strict_types=1);

use App\Services\StatusService;

$outro = ['id' => 'm1', 'ativo' => 1, 'aplica_a' => 'cancelada,rejeitada', 'exige_observacao' => 1];
$semFoto = ['id' => 'm2', 'ativo' => 1, 'aplica_a' => 'rejeitada', 'exige_observacao' => 0];

T::teste('quais transições exigem motivo', function () {
    foreach (['rejeitada', 'rejeitada_final', 'cancelada', 'perdida_prazo', 'encerrada', 'peca_vendida', 'estornada'] as $s) {
        T::verdadeiro(StatusService::exigeMotivo($s), $s);
    }
    T::verdadeiro(!StatusService::exigeMotivo('aprovada'));
});

T::teste('sem motivo', fn() => T::igual(['motivo_id' => 'Informe o motivo.'], StatusService::validarMotivo(null, 'cancelada', null)));
T::teste('motivo de outra situação', fn() => T::igual(['motivo_id' => 'Este motivo não se aplica a "Cancelada".'], StatusService::validarMotivo($semFoto, 'cancelada', null)));
T::teste('motivo inativo', fn() => T::igual(['motivo_id' => 'Motivo inativo.'], StatusService::validarMotivo(['ativo' => 0] + $semFoto, 'rejeitada', null)));

T::teste('motivo que exige observação', function () use ($outro) {
    T::igual(['observacao' => 'Descreva o motivo na observação.'], StatusService::validarMotivo($outro, 'cancelada', ' '));
    T::igual([], StatusService::validarMotivo($outro, 'cancelada', 'Peça usada na oficina'));
});

T::teste('transição sem motivo não valida motivo', fn() => T::igual([], StatusService::validarMotivo(null, 'aprovada', null)));
