<?php
// @versao Not_03 V_01_16/07/2026
declare(strict_types=1);

/**
 * Model de Notificações.
 *
 * Lê e gerencia a tabela `notificacoes` do sistema maior.
 * Tipos NF válidos no ENUM: nf_tarefa_atribuida | nf_processo_devolvido |
 *                           nf_processo_aprovado | nf_uat_convite | nf_parcela_vencendo
 */
class Notification
{
    public static function unreadCount(string $usuarioId): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM notificacoes
             WHERE usuario_id = ? AND lida = 0"
        );
        $stmt->execute([$usuarioId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lista as N notificações mais recentes para o dropdown.
     */
    public static function listRecent(string $usuarioId, int $limit = 8): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT id, tipo, titulo, mensagem, lida, entidade_tipo, entidade_id, criado_em
             FROM notificacoes
             WHERE usuario_id = ?
             ORDER BY lida ASC, criado_em DESC
             LIMIT ?"
        );
        $stmt->execute([$usuarioId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista paginada para a página completa de notificações.
     */
    public static function listPaginated(string $usuarioId, int $page = 1, int $perPage = 20): array
    {
        $pdo    = Database::getInstance();
        $offset = ($page - 1) * $perPage;

        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ?");
        $stmtTotal->execute([$usuarioId]);
        $total = (int) $stmtTotal->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT id, tipo, titulo, mensagem, lida, entidade_tipo, entidade_id, criado_em
             FROM notificacoes
             WHERE usuario_id = ?
             ORDER BY lida ASC, criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$usuarioId, $perPage, $offset]);

        return [
            'itens'    => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total'    => $total,
            'pagina'   => $page,
            'por_pag'  => $perPage,
            'paginas'  => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public static function markRead(string $id, string $usuarioId): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "UPDATE notificacoes SET lida = 1 WHERE id = ? AND usuario_id = ?"
        );
        $stmt->execute([$id, $usuarioId]);
    }

    public static function markAllRead(string $usuarioId): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "UPDATE notificacoes SET lida = 1 WHERE usuario_id = ? AND lida = 0"
        );
        $stmt->execute([$usuarioId]);
    }

    /**
     * Cria uma notificação. Silencioso em caso de falha.
     */
    public static function create(
        string $usuarioId,
        string $tipo,
        string $titulo,
        string $mensagem,
        string $entidadeTipo = 'nf_processo',
        string $entidadeId   = ''
    ): void {
        try {
            $pdo  = Database::getInstance();
            $stmt = $pdo->prepare(
                "INSERT INTO notificacoes
                    (id, usuario_id, tipo, titulo, mensagem, lida, entidade_tipo, entidade_id, criado_em)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?, NOW())"
            );
            $stmt->execute([
                self::uuid(),
                $usuarioId,
                $tipo,
                mb_substr($titulo,   0, 200),
                mb_substr($mensagem, 0, 1000),
                $entidadeTipo,
                $entidadeId,
            ]);
        } catch (\PDOException) {
            // Notificações nunca quebram o fluxo principal
        }
    }

    private static function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Retorna a URL de destino de uma notificação com base no tipo e entidade.
     */
    public static function url(array $notif): string
    {
        $entidade = $notif['entidade_tipo'] ?? '';
        $id       = $notif['entidade_id']   ?? '';

        if ($entidade === 'nf_processo' && $id) {
            return '?module=processos&action=detalhe&id=' . urlencode($id);
        }
        if ($entidade === 'nf_parcela' && $id) {
            return '?module=financeiro&action=index';
        }
        return '?module=notificacoes&action=index';
    }

    /**
     * Ícone Lucide e cor para cada tipo de notificação.
     */
    public static function meta(string $tipo): array
    {
        return match($tipo) {
            'nf_tarefa_atribuida'   => ['icon' => 'clipboard-list',  'color' => 'blue'],
            'nf_processo_devolvido' => ['icon' => 'corner-down-left', 'color' => 'warning'],
            'nf_processo_aprovado'  => ['icon' => 'check-circle',     'color' => 'success'],
            'nf_uat_convite'        => ['icon' => 'flask-conical',    'color' => 'primary'],
            'nf_parcela_vencendo'   => ['icon' => 'alert-triangle',   'color' => 'danger'],
            default                 => ['icon' => 'bell',             'color' => 'muted'],
        };
    }
}
