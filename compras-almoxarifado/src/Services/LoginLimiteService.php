<?php
// @versao Ver.002.281026

namespace App\Services;

use App\Config\Database;

/**
 * Limite de tentativas por IP no login e na recuperação de senha (auditoria A5).
 * Complementa o bloqueio por conta, que sozinho não impede testar uma senha
 * comum em todas as contas.
 *
 * Na nuvem, atrás de balanceador, REMOTE_ADDR é o IP do balanceador: ajustar
 * ipCliente() para o cabeçalho confiável do provedor antes de publicar.
 */
class LoginLimiteService
{
    private const JANELA_MIN = 15;
    private const LIMITES    = ['login' => 20, 'reset' => 5];

    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function ipCliente(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function bloqueado(string $tipo): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM login_falhas
                 WHERE ip = ? AND tipo = ? AND criado_em > NOW() - INTERVAL " . self::JANELA_MIN . " MINUTE"
            );
            $stmt->execute([self::ipCliente(), $tipo]);
            return (int)$stmt->fetchColumn() >= self::LIMITES[$tipo];
        } catch (\Throwable $e) {
            error_log('[LoginLimiteService] ' . $e->getMessage());
            return false; // sem a tabela, não trava o login
        }
    }

    public function registrar(string $tipo, string $login): void
    {
        try {
            $this->db->prepare("INSERT INTO login_falhas (ip, login, tipo) VALUES (?, ?, ?)")
                ->execute([self::ipCliente(), mb_substr($login, 0, 150), $tipo]);
            // Limpeza ocasional (1 em 50) para a tabela não crescer sem fim.
            if (random_int(1, 50) === 1) {
                $this->db->exec("DELETE FROM login_falhas WHERE criado_em < NOW() - INTERVAL 1 DAY");
            }
        } catch (\Throwable $e) {
            error_log('[LoginLimiteService] ' . $e->getMessage());
        }
    }

    public static function minutosJanela(): int
    {
        return self::JANELA_MIN;
    }
}
