<?php
declare(strict_types=1);

use App\Support\Alteracoes;

T::teste('detecta só o que mudou e normaliza números', fn() => T::igual(
    [
        ['campo' => 'nf_devolucao', 'de' => '512345', 'para' => '512399'],
        ['campo' => 'data_coletada', 'de' => null, 'para' => '2026-10-23'],
    ],
    Alteracoes::calcular(
        ['nf_devolucao' => '512345', 'valor_nf_devolucao' => '1250.00', 'data_coletada' => null, 'codigo_rastreio' => 'X'],
        ['nf_devolucao' => '512399', 'valor_nf_devolucao' => 1250.0, 'data_coletada' => '2026-10-23', 'codigo_rastreio' => 'X'],
        ['nf_devolucao', 'valor_nf_devolucao', 'data_coletada', 'codigo_rastreio']
    )
));

T::teste('campo ausente no depois é ignorado', fn() => T::igual([], Alteracoes::calcular(['a' => '1'], [], ['a'])));

T::teste('apagar um valor é alteração', fn() => T::igual(
    [['campo' => 'nf_icms_st', 'de' => '512346', 'para' => null]],
    Alteracoes::calcular(['nf_icms_st' => '512346'], ['nf_icms_st' => null], ['nf_icms_st'])
));

T::teste('rótulo e formatação para exibir', function () {
    T::igual('Nº NF de devolução', rotulo_campo('nf_devolucao'));
    T::igual('Descrição da peça', rotulo_campo('descricao_peca'));
    T::igual('R$ 1.250,00', valor_campo('valor_nf_devolucao', '1250.00'));
    T::igual('23/10/2026', valor_campo('data_coletada', '2026-10-23'));
    T::igual('—', valor_campo('nf_icms_st', null));
});

T::teste('só campos numéricos normalizam decimais', function () {
    T::igual(
        [['campo' => 'desenho', 'de' => '1.10', 'para' => '1.1']],
        Alteracoes::calcular(['desenho' => '1.10'], ['desenho' => '1.1'], ['desenho'])
    );
    T::igual(
        [['campo' => 'nf_compartilhada', 'de' => '0', 'para' => '1']],
        Alteracoes::calcular(['nf_compartilhada' => '0'], ['nf_compartilhada' => 1], ['nf_compartilhada'])
    );
});

T::teste('valor_campo escapa HTML', fn() => T::igual('&lt;script&gt;', valor_campo('descricao_peca', '<script>')));
