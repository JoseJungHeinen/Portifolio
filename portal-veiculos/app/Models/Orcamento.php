<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Orcamento extends Model
{
    public $timestamps = false;

    protected $table = 'orcamentos';

    protected $fillable = [
        'tipo',
        'subtipo_id',
        'texto_detalhe',
        'total',
        'dados_entrada',
        'criado_em',
        'deletar_em',
    ];

    protected $casts = [
        'dados_entrada' => 'array',
        'total' => 'decimal:2',
        'criado_em' => 'datetime',
        'deletar_em' => 'datetime',
    ];
}
