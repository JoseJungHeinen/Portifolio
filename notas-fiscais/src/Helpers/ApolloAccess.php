<?php
// @versao Apo_04 V_01_25/07/2026
declare(strict_types=1);

/**
 * Gate de acesso ao CONSOLE de consulta Apollo (Geral › Apollo).
 *
 * Diferente do RBAC padrão (Auth::can), aqui NÃO existe bypass de admin: até o
 * administrador precisa de liberação explícita em nf_apollo_acessos para operar
 * o console. O admin gerencia a lista (conceder/revogar). A CONFIGURAÇÃO da
 * conexão Apollo continua admin-only (tratada no GeneralController).
 */
class ApolloAccess
{
    /** O usuário tem acesso ativo ao console? (sem bypass de admin) */
    public static function pode(string $usuarioId): bool
    {
        if ($usuarioId === '') {
            return false;
        }
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT 1 FROM nf_apollo_acessos
                  WHERE usuario_id = ? AND revogado_em IS NULL LIMIT 1"
            );
            $stmt->execute([$usuarioId]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Exige acesso ao console; sem acesso registra e responde 403. */
    public static function autorizar(string $usuarioId): void
    {
        if (self::pode($usuarioId)) {
            return;
        }
        AuditLog::registrar('nf_apollo', $usuarioId, 'nf_apollo.acesso_negado');
        http_response_code(403);
        include VIEWS . '/errors/403.php';
        exit;
    }

    /**
     * Lista os usuários com acesso ativo (para a gestão na tela).
     * @return list<array{id:string,usuario_id:string,nome:?string,email:?string,concedido_em:string,concedido_por_nome:?string}>
     */
    public static function listarLiberados(): array
    {
        try {
            $stmt = Database::getInstance()->query(
                "SELECT a.id, a.usuario_id, a.concedido_em,
                        u.nome, u.email,
                        c.nome AS concedido_por_nome
                   FROM nf_apollo_acessos a
                   LEFT JOIN usuarios u ON u.id = a.usuario_id
                   LEFT JOIN usuarios c ON c.id = a.concedido_por
                  WHERE a.revogado_em IS NULL
                  ORDER BY u.nome ASC"
            );
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Concede acesso a um usuário (idempotente — ignora se já ativo). */
    public static function conceder(string $usuarioId, string $concedidoPor, ?string $motivo = null): void
    {
        if ($usuarioId === '' || self::pode($usuarioId)) {
            return;
        }
        $stmt = Database::getInstance()->prepare(
            "INSERT INTO nf_apollo_acessos (id, usuario_id, concedido_por, motivo)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([self::uuid(), $usuarioId, $concedidoPor, $motivo]);
        AuditLog::registrar('nf_apollo', $usuarioId, 'nf_apollo.acesso_concedido', null, ['por' => $concedidoPor]);
    }

    /** Revoga (soft) o acesso ativo de um usuário. */
    public static function revogar(string $usuarioId, string $revogadoPor): void
    {
        $stmt = Database::getInstance()->prepare(
            "UPDATE nf_apollo_acessos
                SET revogado_em = NOW(), revogado_por = ?
              WHERE usuario_id = ? AND revogado_em IS NULL"
        );
        $stmt->execute([$revogadoPor, $usuarioId]);
        AuditLog::registrar('nf_apollo', $usuarioId, 'nf_apollo.acesso_revogado', null, ['por' => $revogadoPor]);
    }

    private static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
