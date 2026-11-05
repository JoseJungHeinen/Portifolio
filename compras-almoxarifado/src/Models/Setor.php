<?php

namespace App\Models;

class Setor extends BaseModel
{
    protected string $table = 'setores';

    public function operacionais(): array { return $this->all(['tipo' => 'operacional', 'ativo' => 1], 'nome'); }
    public function administrativos(): array { return $this->all(['tipo' => 'administrativo', 'ativo' => 1], 'nome'); }
    public function allAtivos(): array { return $this->all(['ativo' => 1], 'tipo, nome'); }
}
