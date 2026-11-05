<?php
declare(strict_types=1);

namespace App\Models;

class Causal extends BaseModel
{
    protected string $table = 'causais';

    public function ativos(): array
    {
        return $this->all(['ativo' => 1], 'codigo');
    }

    public function todos(): array
    {
        return $this->all([], 'legado, codigo');
    }

    public function findByCodigo(string $codigo): ?array
    {
        return $this->queryOne('SELECT * FROM causais WHERE codigo = ?', [strtoupper($codigo)]);
    }
}
