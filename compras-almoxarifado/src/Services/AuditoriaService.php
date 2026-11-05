<?php

namespace App\Services;

use App\Config\Database;

class AuditoriaService
{
    public function log(
        ?string $usuarioId,
        string  $entidadeTipo,
        ?string $entidadeId,
        string  $acao,
        mixed   $payloadAntes  = null,
        mixed   $payloadDepois = null,
        ?string $ip            = null,
        ?string $userAgent     = null
    ): void {
        try {
            $db   = Database::getInstance();
            $stmt = $db->prepare(
                "INSERT INTO logs_auditoria
                 (id, usuario_id, entidade_tipo, entidade_id, acao,
                  payload_antes, payload_depois, ip_address, user_agent, criado_em)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                uuid(),
                $usuarioId,
                $entidadeTipo,
                $entidadeId,
                $acao,
                $payloadAntes  ? json_encode($payloadAntes,  JSON_UNESCAPED_UNICODE) : null,
                $payloadDepois ? json_encode($payloadDepois, JSON_UNESCAPED_UNICODE) : null,
                $ip        ?? ($_SERVER['REMOTE_ADDR']     ?? null),
                $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
            ]);
        } catch (\Throwable $e) {
            // Log de auditoria nunca deve quebrar a aplicação
            error_log('[AUDITORIA ERROR] ' . $e->getMessage());
        }
    }

    public function buscar(array $filtros = [], int $limit = 100, int $offset = 0): array
    {
        $db     = Database::getInstance();
        $where  = '1=1';
        $params = [];

        if (!empty($filtros['usuario_id']))   { $where .= ' AND la.usuario_id = ?';    $params[] = $filtros['usuario_id']; }
        if (!empty($filtros['entidade_tipo'])){ $where .= ' AND la.entidade_tipo = ?'; $params[] = $filtros['entidade_tipo']; }
        if (!empty($filtros['acao']))         { $where .= ' AND la.acao LIKE ?';       $params[] = '%' . $filtros['acao'] . '%'; }
        if (!empty($filtros['data_inicio']))  { $where .= ' AND la.criado_em >= ?';    $params[] = $filtros['data_inicio'] . ' 00:00:00'; }
        if (!empty($filtros['data_fim']))     { $where .= ' AND la.criado_em <= ?';    $params[] = $filtros['data_fim']    . ' 23:59:59'; }

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare(
            "SELECT la.*, u.nome AS usuario_nome
             FROM logs_auditoria la
             LEFT JOIN usuarios u ON u.id = la.usuario_id
             WHERE {$where}
             ORDER BY la.criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
