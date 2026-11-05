<?php
// @versao Tab_09 V_05_26/08/2026
declare(strict_types=1);

/**
 * Model do cadastro de fornecedores (tela Listas → Fornecedores).
 *
 * A tabela `fornecedores` é compartilhada com o sistema de compras — a
 * verificação de uso antes de excluir cobre TODAS as tabelas que referenciam
 * fornecedor_id, não só as deste módulo.
 */
class Supplier
{
    /** Tabelas que referenciam fornecedores.fornecedor_id (sistema inteiro). */
    private const TABELAS_USO = [
        'nf_processos', 'contratos', 'ordens_compra', 'cotacao_fornecedores',
        'historico_precos', 'avaliacoes_fornecedor', 'certificados_fornecedor',
        'creditos_fornecedor', 'documentos_fornecedor',
    ];

    /** WHERE + params compartilhados entre list() e count(). */
    private static function sqlFiltros(array $filters, array &$params): string
    {
        $where = [];
        if (($filters['status'] ?? '') === 'ativo')   $where[] = 'ativo = 1';
        if (($filters['status'] ?? '') === 'inativo') $where[] = 'ativo = 0';
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(razao_social LIKE ? OR nome_fantasia LIKE ? OR cnpj LIKE ? OR cidade LIKE ?)';
            $like    = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, preg_replace('/\D/', '', $filters['q']) !== ''
                ? '%' . preg_replace('/\D/', '', $filters['q']) . '%' : $like, $like);
        }
        // Pendência de PIS/NIT — MEI ou pessoa física sem o número preenchido
        if (($filters['pis'] ?? '') === 'pendente') {
            $where[] = "(pis_nit IS NULL OR pis_nit = '')
                        AND (optante_mei = 1 OR CHAR_LENGTH(TRIM(cnpj)) = 11)";
        }
        // Situação na Receita — '__sem__' filtra os sem informação (NULL/vazio)
        $situacao = $filters['situacao'] ?? '';
        if ($situacao === '__sem__') {
            $where[] = "(situacao_cadastral IS NULL OR situacao_cadastral = '')";
        } elseif ($situacao !== '') {
            $where[]  = 'situacao_cadastral = ?';
            $params[] = $situacao;
        }
        return $where ? implode(' AND ', $where) : '1';
    }

    public static function list(array $filters, int $offset, int $limit): array
    {
        $pdo    = Database::getInstance();
        $params = [];
        $sqlW   = self::sqlFiltros($filters, $params);
        $stmt   = $pdo->prepare(
            "SELECT id, razao_social, nome_fantasia, cnpj, email, telefone,
                    endereco, cidade, estado, data_abertura, situacao_cadastral,
                    optante_mei, optante_simples, pis_nit, ativo, criado_em
             FROM fornecedores WHERE {$sqlW}
             ORDER BY ativo DESC, razao_social ASC
             LIMIT ? OFFSET ?"
        );
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function count(array $filters): int
    {
        $pdo    = Database::getInstance();
        $params = [];
        $sqlW   = self::sqlFiltros($filters, $params);
        $stmt   = $pdo->prepare("SELECT COUNT(*) FROM fornecedores WHERE {$sqlW}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * CNPJ do cadastro ainda sem situação na Receita — a fila do botão "WS".
     *
     * Só CNPJ: pessoa física (11 dígitos) não tem consulta na API, e ficaria
     * eternamente na fila. Quem já falhou antes é pulado, porque a 20s por
     * consulta insistir num CNPJ que a Receita não devolve custa caro — o
     * registro do erro vive em `nf_fiscal_cnpj`, a mesma tabela que a tela
     * Fiscal usa. `$comErro = true` traz os falhados de volta.
     *
     * @return list<string>
     */
    public static function pendentesReceita(bool $comErro = false): array
    {
        $sql = "SELECT cnpj FROM fornecedores
                 WHERE (situacao_cadastral IS NULL OR situacao_cadastral = '')
                   AND CHAR_LENGTH(TRIM(cnpj)) = 14";

        // A tabela de erros só existe depois da migration 050 do módulo Fiscal.
        if (!$comErro && self::temRegistroDeErro()) {
            $sql .= " AND cnpj NOT IN (SELECT cnpj FROM nf_fiscal_cnpj WHERE erro IS NOT NULL)";
        }
        $sql .= ' ORDER BY razao_social';

        return Database::getInstance()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Quantos CNPJ estão na fila da consulta. */
    public static function contarPendentesReceita(): int
    {
        return count(self::pendentesReceita());
    }

    private static function temRegistroDeErro(): bool
    {
        static $existe = null;
        if ($existe === null) {
            $existe = (bool) Database::getInstance()
                ->query("SHOW TABLES LIKE 'nf_fiscal_cnpj'")->fetchColumn();
        }
        return $existe;
    }

    public static function findById(string $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM fornecedores WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Busca pelo CNPJ/CPF ignorando máscara — usado quando o registro de
     * origem guarda só o documento (processo sem fornecedor_id).
     */
    public static function findByDoc(string $doc): ?array
    {
        $doc = preg_replace('/\D/', '', $doc) ?? '';
        if ($doc === '') return null;
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT * FROM fornecedores
             WHERE REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', '') = ?
             LIMIT 1"
        );
        $stmt->execute([$doc]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Outro fornecedor (id diferente) já usa este CNPJ? */
    public static function cnpjEmUsoPorOutro(string $cnpj, string $ignorarId): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT id, razao_social FROM fornecedores WHERE cnpj = ? AND id <> ? LIMIT 1"
        );
        $stmt->execute([$cnpj, $ignorarId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Outro fornecedor (id diferente) já usa este PIS/NIT? O número é individual. */
    public static function pisEmUsoPorOutro(string $pis, string $ignorarId): ?array
    {
        if ($pis === '') return null;
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT id, razao_social FROM fornecedores WHERE pis_nit = ? AND id <> ? LIMIT 1"
        );
        $stmt->execute([$pis, $ignorarId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private const CAMPOS = ['razao_social', 'nome_fantasia', 'cnpj', 'email', 'telefone',
                            'endereco', 'cidade', 'estado', 'data_abertura',
                            'situacao_cadastral', 'optante_mei', 'optante_simples', 'pis_nit'];

    public static function create(array $d): string
    {
        $pdo = Database::getInstance();
        $id  = self::uuid();
        $stmt = $pdo->prepare(
            "INSERT INTO fornecedores (id, " . implode(', ', self::CAMPOS) . ", ativo, criado_em)
             VALUES (?, " . rtrim(str_repeat('?, ', count(self::CAMPOS)), ', ') . ", 1, NOW())"
        );
        $vals = [$id];
        foreach (self::CAMPOS as $c) $vals[] = $d[$c];
        $stmt->execute($vals);
        return $id;
    }

    public static function update(string $id, array $d): void
    {
        $pdo  = Database::getInstance();
        $sets = implode(' = ?, ', self::CAMPOS) . ' = ?';
        $stmt = $pdo->prepare("UPDATE fornecedores SET {$sets} WHERE id = ?");
        $vals = [];
        foreach (self::CAMPOS as $c) $vals[] = $d[$c];
        $vals[] = $id;
        $stmt->execute($vals);
    }

    /** @return bool|null novo status, ou null se não achou */
    public static function toggleAtivo(string $id): ?bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE fornecedores SET ativo = IF(ativo = 1, 0, 1) WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) return null;
        $stmt = $pdo->prepare("SELECT ativo FROM fornecedores WHERE id = ?");
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Registros em qualquer módulo que apontam para o fornecedor.
     * Tabela que não exista neste banco é ignorada.
     *
     * @return array{total:int, tabelas:array<int,string>}
     */
    public static function emUso(string $id): array
    {
        $pdo   = Database::getInstance();
        $total = 0;
        $onde  = [];
        foreach (self::TABELAS_USO as $t) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE fornecedor_id = ?");
                $stmt->execute([$id]);
                $n = (int) $stmt->fetchColumn();
                if ($n > 0) {
                    $total  += $n;
                    $onde[]  = "{$t} ({$n})";
                }
            } catch (\PDOException $e) {
                // tabela inexistente neste banco — ignora
            }
        }
        return ['total' => $total, 'tabelas' => $onde];
    }

    public static function delete(string $id): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("DELETE FROM fornecedores WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    private static function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}
