<?php

namespace App\Services;

use App\Config\Database;

class NotificacaoService
{
    public function criar(
        string  $usuarioId,
        string  $tipo,
        string  $titulo,
        string  $mensagem,
        ?string $entidadeTipo = null,
        ?string $entidadeId   = null
    ): void {
        $db = Database::getInstance();
        $db->prepare(
            "INSERT INTO notificacoes (id, usuario_id, tipo, titulo, mensagem, entidade_tipo, entidade_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([uuid(), $usuarioId, $tipo, $titulo, $mensagem, $entidadeTipo, $entidadeId]);
    }

    public function paraUsuario(string $usuarioId, int $limit = 20): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT * FROM notificacoes WHERE usuario_id = ?
             ORDER BY criado_em DESC LIMIT ?"
        );
        $stmt->execute([$usuarioId, $limit]);
        return $stmt->fetchAll();
    }

    public function naoLidas(string $usuarioId): int
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND lida = 0"
        );
        $stmt->execute([$usuarioId]);
        return (int)$stmt->fetchColumn();
    }

    public function marcarLida(string $id, string $usuarioId): void
    {
        // AND usuario_id = ? impede que um usuário marque como lida a
        // notificação de outro (IDOR / supressão de alertas alheios).
        Database::getInstance()->prepare(
            "UPDATE notificacoes SET lida = 1 WHERE id = ? AND usuario_id = ?"
        )->execute([$id, $usuarioId]);
    }

    public function marcarTodasLidas(string $usuarioId): void
    {
        Database::getInstance()->prepare(
            "UPDATE notificacoes SET lida = 1 WHERE usuario_id = ?"
        )->execute([$usuarioId]);
    }

    public function notificarDiretoresAumentoOrcamento(string $solId, string $setorId, string $grupoId): void
    {
        $db      = Database::getInstance();
        $stmt    = $db->prepare(
            "SELECT id FROM usuarios WHERE perfil IN ('diretor','admin') AND ativo = 1"
        );
        $stmt->execute();
        $diretores = $stmt->fetchAll();

        foreach ($diretores as $d) {
            $this->criar(
                $d['id'], 'aprovacao_pendente',
                'Solicitação de aumento de orçamento',
                'Uma nova solicitação de aumento de orçamento aguarda sua análise.',
                'solicitacao_orcamento', $solId
            );
        }
    }

    public function notificarSetorBudgetCritico(array $orc, float $pct): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT id FROM usuarios WHERE setor_id = ? AND filial_id = ? AND ativo = 1"
        );
        $stmt->execute([$orc['setor_id'], $orc['filial_id']]);
        $usuarios = $stmt->fetchAll();

        foreach ($usuarios as $u) {
            $this->criar(
                $u['id'], 'budget_critico',
                'Orçamento quase esgotado',
                sprintf('O orçamento do seu setor atingiu %.0f%% do limite mensal.', $pct),
                'orcamento', $orc['id']
            );
        }
    }

    public function notificarAprovacaoPendente(string $aprovadorId, string $entidadeTipo, string $entidadeId, string $descricao): void
    {
        $this->criar(
            $aprovadorId, 'aprovacao_pendente',
            'Aprovação pendente',
            $descricao,
            $entidadeTipo, $entidadeId
        );
    }
}
