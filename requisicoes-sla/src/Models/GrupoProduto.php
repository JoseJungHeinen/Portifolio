<?php

namespace App\Models;

class GrupoProduto extends BaseModel
{
    protected string $table = 'grupos_produtos';
    public function allAtivos(): array { return $this->all(['ativo' => 1], 'nome'); }
}
