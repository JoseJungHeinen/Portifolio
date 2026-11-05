<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoEmplacamento extends Model
{
    protected $table = 'tipos_emplacamento';

    protected $fillable = [
        'nome',
        'taxas',
        'ipva_percentual',
        'valor_placa',
        'valor_escolha_placa',
        'margem',
        'ativo',
    ];

    protected $casts = [
        'taxas' => 'decimal:2',
        'ipva_percentual' => 'decimal:4',
        'valor_placa' => 'decimal:2',
        'valor_escolha_placa' => 'decimal:2',
        'margem' => 'decimal:2',
    ];
}
