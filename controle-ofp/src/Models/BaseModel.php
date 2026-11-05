<?php
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

abstract class BaseModel
{
    protected PDO $db;
    protected string $table;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function find(string $id): ?array
    {
        return $this->queryOne("SELECT * FROM {$this->table} WHERE id = ? LIMIT 1", [$id]);
    }

    public function all(array $condicoes = [], string $ordem = ''): array
    {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];
        if ($condicoes) {
            $partes = [];
            foreach ($condicoes as $coluna => $valor) {
                if ($valor === null) {
                    $partes[] = "{$coluna} IS NULL";
                } else {
                    $partes[] = "{$coluna} = ?";
                    $params[] = $valor;
                }
            }
            $sql .= ' WHERE ' . implode(' AND ', $partes);
        }
        if ($ordem !== '') {
            $sql .= " ORDER BY {$ordem}";
        }
        return $this->query($sql, $params);
    }

    public function create(array $dados): string
    {
        $dados['id'] ??= uuid();
        $colunas = implode(', ', array_keys($dados));
        $marcas = implode(', ', array_fill(0, count($dados), '?'));
        $this->execute("INSERT INTO {$this->table} ({$colunas}) VALUES ({$marcas})", array_values($dados));
        return $dados['id'];
    }

    public function update(string $id, array $dados): bool
    {
        if (!$dados) {
            return true;
        }
        $sets = implode(', ', array_map(fn(string $c) => "{$c} = ?", array_keys($dados)));
        return $this->execute("UPDATE {$this->table} SET {$sets} WHERE id = ?", [...array_values($dados), $id]);
    }

    /** UPDATE com condições extras além do id; devolve as linhas encontradas (0 = nada casou). */
    public function updateOnde(string $id, array $dados, array $condicoes): int
    {
        if (!$dados) {
            return 1;
        }
        $sets = implode(', ', array_map(fn(string $c) => "{$c} = ?", array_keys($dados)));
        $where = 'id = ?';
        $params = [...array_values($dados), $id];
        foreach ($condicoes as $coluna => $valor) {
            $where .= " AND {$coluna} = ?";
            $params[] = $valor;
        }
        $st = $this->db->prepare("UPDATE {$this->table} SET {$sets} WHERE {$where}");
        $st->execute($params);
        return $st->rowCount();
    }

    /**
     * Como updateOnde, e ainda exige que cada coluna de $antigos continue com o valor lido
     * (comparação NULL-safe, <=>): protege contra edição simultânea dos mesmos campos.
     */
    public function updateOndeIgual(string $id, array $dados, array $condicoes, array $antigos): int
    {
        if (!$dados) {
            return 1;
        }
        $sets = implode(', ', array_map(fn(string $c) => "{$c} = ?", array_keys($dados)));
        $where = 'id = ?';
        $params = [...array_values($dados), $id];
        foreach ($condicoes as $coluna => $valor) {
            $where .= " AND {$coluna} = ?";
            $params[] = $valor;
        }
        foreach ($antigos as $coluna => $valor) {
            $where .= " AND {$coluna} <=> ?";
            $params[] = $valor;
        }
        $st = $this->db->prepare("UPDATE {$this->table} SET {$sets} WHERE {$where}");
        $st->execute($params);
        return $st->rowCount();
    }

    protected function query(string $sql, array $params = []): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    protected function queryOne(string $sql, array $params = []): ?array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        $linha = $st->fetch();
        return $linha ?: null;
    }

    protected function queryScalar(string $sql, array $params = []): mixed
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    protected function execute(string $sql, array $params = []): bool
    {
        return $this->db->prepare($sql)->execute($params);
    }
}
