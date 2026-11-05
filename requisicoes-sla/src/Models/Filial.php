<?php

namespace App\Models;

class Filial extends BaseModel
{
    protected string $table = 'filiais';

    public function allAtivas(): array
    {
        return $this->all(['ativo' => 1], 'nome');
    }
}
