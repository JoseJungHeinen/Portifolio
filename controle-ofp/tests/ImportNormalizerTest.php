<?php
declare(strict_types=1);

use App\Services\ImportNormalizer as N;

T::teste('texto normalizado para comparar cabeçalhos e status', function () {
    T::igual('n ofp', N::texto('Nº OFP'));
    T::igual('dt inicio processo', N::texto('DT INÍCIO PROCESSO'));
    T::igual('recebto pendente pecas', N::texto('Recebto Pendente - Peças'));
});

T::teste('unidade com as variações de digitação da planilha', function () {
    foreach (['Eurovale - Matriz', 'eurovale - matriz', 'Eurvale - Matriz', 'Eurovale-Matriz', 'Eurovale - MAtriz', 'Eurovale- Matriz '] as $v) {
        T::igual('Eurovale - Matriz', N::unidade($v), $v);
    }
    T::igual('Eurovale - Jeep', N::unidade('eurovale -jeep '));
    T::igual('Eurovale - Santa Cruz', N::unidade('Eurovale - Santa Cruz'));
    T::igual('Eurovale - Cidade C', N::unidade('Cidade C'));
    T::igual(null, N::unidade('Outra loja'));
});

T::teste('código do causal', function () {
    T::igual('AT', N::codigoCausal('AT - Peça avariada no transporte com retorno'));
    T::igual('DG', N::codigoCausal('DG - Recebida a menor'));
    T::igual('VF', N::codigoCausal('VF'));
    T::igual(null, N::codigoCausal(''));
});

T::teste('status da planilha v4', function () {
    T::igual('creditada', N::statusV4('Recebida'));
    T::igual('aguardando_coleta', N::statusV4('AGUARDANDO COLETA'));
    T::igual('aguardando_credito', N::statusV4('Recebto Pendente - Tributo'));
    T::igual('aguardando_credito', N::statusV4('Recebimento Tributo'));
    T::igual('estornada', N::statusV4('Estornada'));
    T::igual(null, N::statusV4('xyz'));
});

T::teste('status da planilha OFP-STATUS', function () {
    T::igual('aguardando_coleta', N::statusOperacional('aguardando coleta'));
    T::igual('peca_vendida', N::statusOperacional('peça vendida - Rodrigo'));
    T::igual('em_reanalise', N::statusOperacional(' reanalise'));
    T::igual('em_analise', N::statusOperacional('em análise'));
    T::igual('cancelada', N::statusOperacional('OFP cancelada'));
    T::igual(null, N::statusOperacional('recebida'));
    T::igual(null, N::statusOperacional('cancelar'));
});

T::teste('datas em ISO, BR e serial do Excel', function () {
    T::igual('2017-01-23', N::data('2017-01-23'));
    T::igual('2017-01-23', N::data('2017-01-23 00:00:00'));
    T::igual('2021-10-14', N::data('14/10/2021'));
    T::igual('2023-03-15', N::data('45000'));
    T::igual('2023-03-15', N::data(45000));
    T::igual(null, N::data(''));
    T::igual(null, N::data('sem etiqueta data'));
});

T::teste('números e valores', function () {
    T::igual('332436', N::numero('332436'));
    T::igual('332436', N::numero('332436.0'));
    T::igual(null, N::numero('abc'));
    T::igual('0123', N::numero('0123'));
    T::igual(null, N::numero('0'));
    T::igual(null, N::numero('123456', 5));
    T::igual('12345', N::numero('12345', 5));
    T::igual(546.73, N::decimal('546.73'));
    T::igual(1500.0, N::decimal('1.500,00'));
    T::igual(null, N::decimal(''));
});
