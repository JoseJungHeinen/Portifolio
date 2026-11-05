<?php
declare(strict_types=1);

use App\Models\Ofp;

fx_limpar();
$_SESSION['unidades'] = null;

T::teste('creditadas podem ser listadas da mais recente para a mais antiga', function () {
    // A criada por último tem o crédito mais antigo: só a ordenação por data_credito acerta.
    fx_ofp(['status' => 'creditada', 'numero_ofp' => '830002', 'data_credito' => '2026-10-20', 'valor_creditado_peca' => 20, 'created_at' => '2026-01-01 08:00:00']);
    fx_ofp(['status' => 'creditada', 'numero_ofp' => '830001', 'data_credito' => '2026-09-01', 'valor_creditado_peca' => 10, 'created_at' => '2026-02-01 08:00:00']);
    fx_ofp(['status' => 'aguardando_credito', 'numero_ofp' => '830003']);
    $lista = (new Ofp())->buscar(['status' => 'creditada', 'ordem' => 'credito']);
    T::igual(['830002', '830001'], array_column($lista, 'numero_ofp'));
});
