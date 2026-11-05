<?php
// @versao Proc_09 V_01_16/07/2026
declare(strict_types=1);

/**
 * Model de Observações de Processos.
 *
 * Tabela: nf_processo_observacoes
 * Comunicação entre participantes — separado do histórico automático.
 */
class Observation
{
    /**
     * Lista observações de um processo (mais antigas primeiro).
     */
    public static function listar(string $processoId): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT o.*, u.nome AS autor_nome_real
             FROM nf_processo_observacoes o
             LEFT JOIN usuarios u ON u.id = o.autor_id
             WHERE o.processo_id = ? AND o.deletado_em IS NULL
             ORDER BY o.criado_em ASC"
        );
        $stmt->execute([$processoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Conta observações de um processo.
     */
    public static function contar(string $processoId): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_processo_observacoes
             WHERE processo_id = ? AND deletado_em IS NULL"
        );
        $stmt->execute([$processoId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Cria uma nova observação.
     */
    public static function criar(string $processoId, string $autorId, string $autorNome, string $texto): string
    {
        $pdo = Database::getInstance();
        $id = self::uuid();

        $stmt = $pdo->prepare(
            "INSERT INTO nf_processo_observacoes
                (id, processo_id, autor_id, autor_nome, texto, criado_em)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$id, $processoId, $autorId, $autorNome, $texto]);

        return $id;
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
