<?php
declare(strict_types=1);

use App\Services\StatusService;

T::teste('estoque registra e abre', fn() => T::igual(['em_analise', 'perdida_prazo', 'cancelada'], StatusService::transicoesPermitidas('registrada', 'estoque', 'fisica')));
T::teste('financeiro não age em OFP registrada', fn() => T::igual([], StatusService::transicoesPermitidas('registrada', 'financeiro', 'fisica')));
T::teste('diretor só consulta', fn() => T::igual([], StatusService::transicoesPermitidas('em_analise', 'diretor', 'fisica')));
T::teste('gerente faz tudo e ainda cancela/vende', fn() => T::igual(['aprovada', 'rejeitada', 'cancelada', 'peca_vendida'], StatusService::transicoesPermitidas('em_analise', 'gerente', 'fisica')));
T::teste('NF emitida física vai para coleta', fn() => T::igual(['aguardando_coleta'], StatusService::transicoesPermitidas('nf_emitida', 'comprador', 'fisica')));
T::teste('NF emitida simbólica vai direto ao crédito', fn() => T::igual(['aguardando_credito'], StatusService::transicoesPermitidas('nf_emitida', 'comprador', 'simbolica')));
T::teste('creditada só pode ser estornada', fn() => T::igual(['estornada'], StatusService::transicoesPermitidas('creditada', 'gerente', 'fisica')));
T::teste('cancelada não tem saída', fn() => T::igual([], StatusService::transicoesPermitidas('cancelada', 'admin', 'fisica')));

T::teste('status em que cada perfil atua', function () {
    T::igual(['aguardando_credito'], StatusService::statusOndeAtua('financeiro'));
    T::igual(['registrada', 'aguardando_coleta', 'coletada'], StatusService::statusOndeAtua('estoque'));
    T::igual([], StatusService::statusOndeAtua('diretor'));
});

T::teste('normalizar dados do formulário', fn() => T::igual(
    ['valor_nf_devolucao' => 1234.56, 'nf_devolucao' => '27817', 'data_coletada' => null],
    StatusService::normalizarDados(['valor_nf_devolucao' => '1.234,56', 'nf_devolucao' => ' 27817 ', 'data_coletada' => '', 'status' => 'x'])
));

T::teste('em análise exige nº OFP', fn() => T::igual(
    ['numero_ofp'],
    array_keys(StatusService::validar(['status' => 'registrada', 'data_recebimento' => '2026-10-01'], 'em_analise', ['data_abertura' => '2026-10-02']))
));

T::teste('nº já gravado na OFP satisfaz a obrigatoriedade', fn() => T::igual(
    [],
    StatusService::validar(['numero_ofp' => '800008', 'data_recebimento' => '2026-10-01'], 'em_analise', ['data_abertura' => '2026-10-02'])
));

T::teste('datas fora de ordem', fn() => T::igual(
    ['data_nf_devolucao'],
    array_keys(StatusService::validar(
        ['data_recebimento' => '2026-10-01', 'data_abertura' => '2026-10-02', 'data_decisao' => '2026-10-10'],
        'nf_emitida',
        ['nf_devolucao' => '1', 'data_nf_devolucao' => '2026-10-05', 'valor_nf_devolucao' => 10.0]
    ))
));

T::teste('data inválida', fn() => T::igual(['data_decisao'], array_keys(StatusService::validar([], 'aprovada', ['data_decisao' => '2026-02-30']))));

T::teste('cancelar não exige observação (o motivo é validado à parte)', fn() => T::igual([], StatusService::validar([], 'cancelada', [])));

T::teste('campos do formulário de transição', function () {
    T::igual(['nf_devolucao', 'data_nf_devolucao', 'valor_nf_devolucao'], StatusService::camposObrigatorios('nf_emitida'));
    T::igual(['nf_icms_st', 'data_nf_icms_st', 'valor_icms_st', 'nf_compartilhada', 'observacao'], StatusService::camposOpcionais('nf_emitida'));
    T::igual(['observacao'], StatusService::camposOpcionais('cancelada'));
});

T::teste('supervisor não atua em status inativos', function () {
    T::igual([], array_intersect(StatusService::INATIVOS, StatusService::statusOndeAtua('gerente')));
});

T::teste('data da decisão precisa vir nesta transição', fn() => T::igual(
    ['data_decisao'],
    array_keys(StatusService::validar(['data_decisao' => '2026-10-03', 'data_recebimento' => '2026-10-01'], 'aprovada', []))
));

T::teste('datas só gravadas (legado) fora de ordem não bloqueiam', fn() => T::igual(
    [],
    StatusService::validar(['data_recebimento' => '2026-10-05', 'data_abertura' => '2026-10-01'], 'aprovada', ['data_decisao' => '2026-10-10'])
));

T::teste('valor monetário inválido é recusado', function () {
    $d = StatusService::normalizarDados(['valor_nf_devolucao' => 'abc', 'nf_devolucao' => ['x']]);
    T::igual(null, $d['nf_devolucao']);
    T::igual(['valor_nf_devolucao'], array_keys(StatusService::validar([], 'nf_emitida', ['nf_devolucao' => '1', 'data_nf_devolucao' => '2026-10-05', 'valor_nf_devolucao' => $d['valor_nf_devolucao']])));
});

T::teste('tamanhos máximos e formato do nº OFP', function () {
    $v = fn(string $para, array $d) => array_keys(StatusService::validar(['data_recebimento' => '2026-10-01'], $para, $d));
    T::igual(['protocolo_grow'], $v('em_reanalise', ['protocolo_grow' => str_repeat('a', 31), 'data_reanalise' => '2026-10-06']));
    T::igual([], $v('em_reanalise', ['protocolo_grow' => str_repeat('a', 30), 'data_reanalise' => '2026-10-06']));
    T::igual(['observacao'], $v('cancelada', ['observacao' => str_repeat('a', 2001)]));
    T::igual(['numero_ofp'], $v('em_analise', ['numero_ofp' => '12a45', 'data_abertura' => '2026-10-02']));
    T::igual(['numero_ofp'], $v('em_analise', ['numero_ofp' => '123', 'data_abertura' => '2026-10-02']));
    T::igual([], $v('em_analise', ['numero_ofp' => '810001', 'data_abertura' => '2026-10-02']));
    T::igual(['valor_nf_devolucao'], $v('nf_emitida', ['nf_devolucao' => '1', 'data_nf_devolucao' => '2026-10-05', 'valor_nf_devolucao' => 1e10]));
});
