<?php

namespace App\Models;

use App\Config\Database;
use PDO;

abstract class BaseModel
{
    protected PDO $db;
    protected string $table;
    protected string $primaryKey = 'id';

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function find(string $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findBy(array $conditions, string $orderBy = ''): ?array
    {
        [$where, $values] = $this->buildWhere($conditions);
        $order = $orderBy ? " ORDER BY {$orderBy}" : '';
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$where}{$order} LIMIT 1");
        $stmt->execute($values);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function all(array $conditions = [], string $orderBy = '', int $limit = 0, int $offset = 0): array
    {
        if (empty($conditions)) {
            $sql = "SELECT * FROM {$this->table}";
            $values = [];
        } else {
            [$where, $values] = $this->buildWhere($conditions);
            $sql = "SELECT * FROM {$this->table} WHERE {$where}";
        }

        if ($orderBy) $sql .= " ORDER BY {$orderBy}";
        if ($limit)   $sql .= " LIMIT {$limit}";
        if ($offset)  $sql .= " OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        return $stmt->fetchAll();
    }

    public function count(array $conditions = []): int
    {
        if (empty($conditions)) {
            $stmt = $this->db->query("SELECT COUNT(*) FROM {$this->table}");
            return (int)$stmt->fetchColumn();
        }
        [$where, $values] = $this->buildWhere($conditions);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE {$where}");
        $stmt->execute($values);
        return (int)$stmt->fetchColumn();
    }

    public function create(array $data): string
    {
        if (!isset($data[$this->primaryKey])) {
            $data[$this->primaryKey] = uuid();
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})"
        );
        $stmt->execute(array_values($data));
        return $data[$this->primaryKey];
    }

    public function update(string $id, array $data): bool
    {
        $sets = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET {$sets} WHERE {$this->primaryKey} = ?"
        );
        return $stmt->execute([...array_values($data), $id]);
    }

    public function delete(string $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET ativo = 0 WHERE {$this->primaryKey} = ?"
        );
        return $stmt->execute([$id]);
    }

    public function hardDelete(string $id): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?"
        );
        return $stmt->execute([$id]);
    }

    protected function query(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    protected function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    protected function queryScalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    protected function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    private function buildWhere(array $conditions): array
    {
        $parts  = [];
        $values = [];
        foreach ($conditions as $col => $val) {
            if (is_null($val)) {
                $parts[] = "{$col} IS NULL";
            } else {
                $parts[] = "{$col} = ?";
                $values[] = $val;
            }
        }
        return [implode(' AND ', $parts), $values];
    }

    public function beginTransaction(): void { $this->db->beginTransaction(); }
    public function commit(): void           { $this->db->commit(); }
    public function rollback(): void         { $this->db->rollBack(); }
}
