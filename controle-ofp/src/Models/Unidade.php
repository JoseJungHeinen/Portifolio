<?php
declare(strict_types=1);

namespace App\Models;

class Unidade extends BaseModel
{
    protected string $table = 'unidades';

    /** Unidades ativas dentro do escopo do usuário logado. */
    public function permitidas(): array
    {
        [$sql, $params] = sql_unidades('id');
        return $this->query("SELECT * FROM unidades WHERE ativo = 1 AND {$sql} ORDER BY nome", $params);
    }

    public function todas(): array
    {
        return $this->all([], 'nome');
    }

    public function findByNome(string $nome): ?array
    {
        return $this->queryOne('SELECT * FROM unidades WHERE nome = ?', [$nome]);
    }
}
