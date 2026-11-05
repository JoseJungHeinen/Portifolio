<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoTransferencia extends Model
{
    protected $table = 'tipos_transferencia';

    protected $fillable = [
        'nome',
        'valor_placa',
        'valor_intermunicipal',
        'valor_transferencia',
        'ativo',
    ];

    protected $casts = [
        'valor_placa' => 'decimal:2',
        'valor_intermunicipal' => 'decimal:2',
        'valor_transferencia' => 'decimal:2',
    ];
}
