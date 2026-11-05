<?php
// @versao Grp_04 V_01_16/07/2026
declare(strict_types=1);

/**
 * Model do módulo Grupos de Aprovação.
 *
 * Gerencia `nf_grupos` e `nf_grupo_membros` no banco `compras`.
 * Sem ORM — apenas PDO com prepared statements.
 */
class Group
{
    // ─────────────────────────────────────────
    // Utilitário interno
    // ─────────────────────────────────────────

    /**
     * Gera um UUID v4 sem dependências externas.
     */
    private static function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // ─────────────────────────────────────────
    // Listagem e busca
    // ─────────────────────────────────────────

    /**
     * Lista grupos com contagem de membros e nome do setor.
     *
     * @param array{status?: string, busca?: string, pagina?: int, por_pagina?: int} $filters
     * @return array<int, array>
     */
    public static function list(array $filters = []): array
    {
        $pdo    = Database::getInstance();
        $where  = ['1=1'];
        $params = [];

        // Filtro de status
        $status = $filters['status'] ?? 'todos';
        if ($status === 'ativos') {
            $where[] = 'g.ativo = 1';
        } elseif ($status === 'inativos') {
            $where[] = 'g.ativo = 0';
        }

        // Filtro de busca por nome
        $busca = trim($filters['busca'] ?? '');
        if ($busca !== '') {
            $where[]  = 'g.nome LIKE ?';
            $params[] = '%' . $busca . '%';
        }

        $whereClause = implode(' AND ', $where);

        // Paginação
        $porPagina = max(1, min((int) ($filters['por_pagina'] ?? ITEMS_PER_PAGE), MAX_ITEMS_PER_PAGE));
        $pagina    = max(1, (int) ($filters['pagina'] ?? 1));
        $offset    = ($pagina - 1) * $porPagina;

        $sql = "
            SELECT
                g.id,
                g.nome,
                g.descricao,
                g.ativo,
                g.criado_em,
                g.atualizado_em,
                g.setor_id,
                s.nome AS setor_nome,
                COUNT(DISTINCT gm.usuario_id) AS total_membros
            FROM nf_grupos g
            LEFT JOIN setores s ON s.id = g.setor_id
            LEFT JOIN nf_grupo_membros gm ON gm.grupo_id = g.id
            WHERE {$whereClause}
            GROUP BY g.id, g.nome, g.descricao, g.ativo, g.criado_em, g.atualizado_em, g.setor_id, s.nome
            ORDER BY g.ativo DESC, g.nome ASC
            LIMIT ? OFFSET ?
        ";

        $params[] = $porPagina;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Conta grupos para paginação.
     *
     * @param array{status?: string, busca?: string} $filters
     */
    public static function count(array $filters = []): int
    {
        $pdo    = Database::getInstance();
        $where  = ['1=1'];
        $params = [];

        $status = $filters['status'] ?? 'todos';
        if ($status === 'ativos') {
            $where[] = 'g.ativo = 1';
        } elseif ($status === 'inativos') {
            $where[] = 'g.ativo = 0';
        }

        $busca = trim($filters['busca'] ?? '');
        if ($busca !== '') {
            $where[]  = 'g.nome LIKE ?';
            $params[] = '%' . $busca . '%';
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM nf_grupos g WHERE {$whereClause}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Busca grupo por ID com membros (JOIN usuarios).
     *
     * @return array|null  null se não encontrar
     */
    public static function findById(string $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT
                g.id, g.nome, g.descricao, g.ativo,
                g.setor_id, g.gestor_id, g.criado_em, g.atualizado_em,
                s.nome AS setor_nome
             FROM nf_grupos g
             LEFT JOIN setores s ON s.id = g.setor_id
             WHERE g.id = ?
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $grupo = $stmt->fetch();
        if (!$grupo) {
            return null;
        }

        $grupo['membros'] = self::getMembros($id);
        return $grupo;
    }

    // ─────────────────────────────────────────
    // Criação e atualização
    // ─────────────────────────────────────────

    /**
     * Cria um novo grupo.
     *
     * @param array{nome: string, setor_id?: string, descricao?: string} $data
     * @return string UUID do novo grupo
     */
    public static function create(array $data): string
    {
        $pdo = Database::getInstance();
        $id  = self::uuid();

        $stmt = $pdo->prepare(
            "INSERT INTO nf_grupos (id, nome, setor_id, descricao, gestor_id, ativo)
             VALUES (?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([
            $id,
            $data['nome'],
            $data['setor_id'] ?: null,
            $data['descricao'] ?: null,
            $data['gestor_id'] ?? null,
        ]);

        return $id;
    }

    /**
     * Atualiza dados do grupo.
     *
     * @param array{nome: string, setor_id?: string, descricao?: string} $data
     */
    public static function update(string $id, array $data): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "UPDATE nf_grupos
             SET nome = ?, setor_id = ?, descricao = ?, gestor_id = ?
             WHERE id = ?"
        );
        $stmt->execute([
            $data['nome'],
            $data['setor_id'] ?: null,
            $data['descricao'] ?: null,
            $data['gestor_id'] ?? null,
            $id,
        ]);

        return $stmt->rowCount() > 0 || true; // rowCount 0 é válido se não mudou nada
    }

    // ─────────────────────────────────────────
    // Toggle de status
    // ─────────────────────────────────────────

    /**
     * Ativa ou desativa um grupo.
     * Bloqueia desativação se houver processos ativos.
     *
     * @return array{success: bool, message: string, processos_ativos: int, novo_status?: int}
     */
    public static function toggleStatus(string $id): array
    {
        $pdo  = Database::getInstance();

        // Buscar status atual
        $stmt = $pdo->prepare("SELECT ativo, nome FROM nf_grupos WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $grupo = $stmt->fetch();

        if (!$grupo) {
            return ['success' => false, 'message' => 'Grupo não encontrado.', 'processos_ativos' => 0];
        }

        $estaAtivo = (bool) $grupo['ativo'];

        // Verificar processos ativos antes de desativar
        if ($estaAtivo) {
            $ativos = self::hasProcessosAtivos($id);
            if ($ativos > 0) {
                return [
                    'success'          => false,
                    'message'          => "Não é possível desativar este grupo — possui {$ativos} processo(s) ativo(s).",
                    'processos_ativos' => $ativos,
                ];
            }
        }

        $novoStatus = $estaAtivo ? 0 : 1;
        $stmt = $pdo->prepare("UPDATE nf_grupos SET ativo = ? WHERE id = ?");
        $stmt->execute([$novoStatus, $id]);

        $acao = $novoStatus === 1 ? 'ativado' : 'desativado';

        return [
            'success'          => true,
            'message'          => "Grupo {$acao} com sucesso.",
            'processos_ativos' => 0,
            'novo_status'      => $novoStatus,
            'ativo'            => (bool) $novoStatus,
        ];
    }

    // ─────────────────────────────────────────
    // Membros
    // ─────────────────────────────────────────

    /**
     * Retorna lista de membros do grupo com dados do usuário.
     *
     * @return array<int, array{usuario_id: string, nome: string, email: string, perfil: string, filial_nome: string, adicionado_em: string}>
     */
    public static function getMembros(string $grupoId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT
                gm.usuario_id,
                gm.adicionado_em,
                u.nome,
                u.email,
                u.perfil,
                f.nome AS filial_nome
             FROM nf_grupo_membros gm
             JOIN usuarios u ON u.id = gm.usuario_id
             LEFT JOIN filiais f ON f.id = u.filial_id
             WHERE gm.grupo_id = ?
             ORDER BY u.nome ASC"
        );
        $stmt->execute([$grupoId]);
        return $stmt->fetchAll();
    }

    /**
     * Retorna os grupos de aprovação de que um usuário é membro.
     *
     * @return array<int, array{id:string,nome:string,ativo:int,setor_nome:?string}>
     */
    public static function gruposDoUsuario(string $usuarioId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT g.id, g.nome, g.ativo, s.nome AS setor_nome
             FROM nf_grupo_membros gm
             JOIN nf_grupos g ON g.id = gm.grupo_id
             LEFT JOIN setores s ON s.id = g.setor_id
             WHERE gm.usuario_id = ?
             ORDER BY g.ativo DESC, g.nome ASC"
        );
        $stmt->execute([$usuarioId]);
        return $stmt->fetchAll();
    }

    /**
     * Adiciona membro ao grupo. Verifica duplicidade.
     */
    public static function addMembro(string $grupoId, string $usuarioId): bool
    {
        $pdo = Database::getInstance();

        // Verificar duplicidade
        $stmt = $pdo->prepare(
            "SELECT 1 FROM nf_grupo_membros WHERE grupo_id = ? AND usuario_id = ? LIMIT 1"
        );
        $stmt->execute([$grupoId, $usuarioId]);
        if ($stmt->fetchColumn()) {
            return false; // já é membro
        }

        $stmt = $pdo->prepare(
            "INSERT INTO nf_grupo_membros (grupo_id, usuario_id) VALUES (?, ?)"
        );
        $stmt->execute([$grupoId, $usuarioId]);
        return true;
    }

    /**
     * Remove membro do grupo.
     */
    public static function removeMembro(string $grupoId, string $usuarioId): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "DELETE FROM nf_grupo_membros WHERE grupo_id = ? AND usuario_id = ?"
        );
        $stmt->execute([$grupoId, $usuarioId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Busca usuários por nome ou e-mail para autocomplete,
     * excluindo os que já são membros do grupo.
     *
     * @return array<int, array{id: string, nome: string, perfil: string, filial: string}>
     */
    public static function searchUsuarios(string $query, string $grupoId): array
    {
        $pdo  = Database::getInstance();
        $like = '%' . $query . '%';

        $stmt = $pdo->prepare(
            "SELECT
                u.id,
                u.nome,
                u.email,
                u.perfil,
                f.nome AS filial
             FROM usuarios u
             LEFT JOIN filiais f ON f.id = u.filial_id
             WHERE u.ativo = 1
               AND (u.nome LIKE ? OR u.email LIKE ?)
               AND u.id NOT IN (
                   SELECT usuario_id FROM nf_grupo_membros WHERE grupo_id = ?
               )
             ORDER BY u.nome ASC
             LIMIT 10"
        );
        $stmt->execute([$like, $like, $grupoId]);
        return $stmt->fetchAll();
    }

    // ─────────────────────────────────────────
    // Processos ativos
    // ─────────────────────────────────────────

    /**
     * Verifica quantos processos ativos têm etapas pendentes atribuídas ao grupo.
     */
    public static function hasProcessosAtivos(string $grupoId): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total
             FROM nf_processo_etapas pe
             JOIN nf_processos p ON p.id = pe.processo_id
             WHERE pe.aprovador_id = ?
               AND pe.tipo_aprovador = 'grupo'
               AND pe.status = 'pendente'
               AND p.status = 'aberto'
               AND p.deletado_em IS NULL"
        );
        $stmt->execute([$grupoId]);
        return (int) $stmt->fetchColumn();
    }

    // ─────────────────────────────────────────
    // Dados auxiliares
    // ─────────────────────────────────────────

    /**
     * Retorna todos os setores ativos para o select do formulário.
     */
    public static function getSetores(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT id, nome, tipo
             FROM setores
             WHERE ativo = 1
             ORDER BY nome ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Retorna totais para o rodapé da listagem.
     *
     * @return array{total: int, ativos: int, inativos: int}
     */
    public static function getTotais(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(ativo = 1) AS ativos,
                SUM(ativo = 0) AS inativos
             FROM nf_grupos"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        return [
            'total'    => (int) $row['total'],
            'ativos'   => (int) $row['ativos'],
            'inativos' => (int) $row['inativos'],
        ];
    }
}
