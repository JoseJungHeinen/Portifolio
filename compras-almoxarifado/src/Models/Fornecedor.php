<?php

namespace App\Models;

class Fornecedor extends BaseModel
{
    protected string $table = 'fornecedores';

    public function allOrdenados(): array
    {
        return $this->all([], 'ativo DESC, razao_social');
    }

    public function buscar(string $termo): array
    {
        $like   = '%' . $termo . '%';
        $digits = preg_replace('/\D/', '', $termo);

        if ($digits !== '') {
            return $this->query(
                "SELECT * FROM fornecedores
                  WHERE razao_social LIKE ? OR nome_fantasia LIKE ? OR cnpj LIKE ?
                  ORDER BY ativo DESC, razao_social",
                [$like, $like, '%' . $digits . '%']
            );
        }

        return $this->query(
            "SELECT * FROM fornecedores
              WHERE razao_social LIKE ? OR nome_fantasia LIKE ?
              ORDER BY ativo DESC, razao_social",
            [$like, $like]
        );
    }

    public function allAtivos(): array
    {
        return $this->all(['ativo' => 1], 'razao_social');
    }

    public function allParaContratos(): array
    {
        return $this->query(
            "SELECT * FROM fornecedores WHERE ativo = 1 AND para_contratos = 1 ORDER BY razao_social"
        );
    }

    public function contarPorStatus(int $ativo): int
    {
        return (int)$this->queryScalar(
            "SELECT COUNT(*) FROM fornecedores WHERE ativo = ?",
            [$ativo]
        );
    }

    public function historicoPrecosItem(string $itemId, int $limit = 12): array
    {
        return $this->query(
            "SELECT hp.*, i.nome AS item_nome, f.razao_social AS fornecedor_nome
             FROM historico_precos hp
             JOIN itens i             ON i.id = hp.item_id
             LEFT JOIN fornecedores f ON f.id = hp.fornecedor_id
             WHERE hp.item_id = ?
             ORDER BY hp.registrado_em DESC
             LIMIT ?",
            [$itemId, $limit]
        );
    }

    public function historicoCompras(string $fornecedorId, int $limit = 20): array
    {
        return $this->query(
            "SELECT hp.*, i.nome AS item_nome
             FROM historico_precos hp
             JOIN itens i ON i.id = hp.item_id
             WHERE hp.fornecedor_id = ?
             ORDER BY hp.registrado_em DESC
             LIMIT ?",
            [$fornecedorId, $limit]
        );
    }

    public function precoMedioItem(string $itemId, int $meses = 3): float
    {
        $val = $this->queryScalar(
            "SELECT AVG(valor_unitario)
             FROM historico_precos
             WHERE item_id = ?
               AND registrado_em >= DATE_SUB(NOW(), INTERVAL ? MONTH)",
            [$itemId, $meses]
        );
        return (float)$val;
    }

    public function topPorValor(int $limit = 10, ?string $filialId = null): array
    {
        return $this->query(
            "SELECT f.razao_social, SUM(oc.valor_total) AS total_comprado, COUNT(oc.id) AS qtd_oc
             FROM ordens_compra oc
             JOIN fornecedores f ON f.id = oc.fornecedor_id
             WHERE oc.status NOT IN ('cancelado','rascunho')
             GROUP BY oc.fornecedor_id, f.razao_social
             ORDER BY total_comprado DESC
             LIMIT ?",
            [$limit]
        );
    }

    // ============================================
    // MÉTODOS DE AVALIAÇÃO
    // ============================================

    /**
     * Retorna a média de avaliações de um fornecedor
     */
    public function mediaAvaliacoes(string $fornecedorId): float
    {
        $val = $this->queryScalar(
            "SELECT AVG(nota) FROM avaliacoes_fornecedor WHERE fornecedor_id = ?",
            [$fornecedorId]
        );
        return $val ? round((float)$val, 1) : 0.0;
    }

    /**
     * Retorna o total de avaliações de um fornecedor
     */
    public function totalAvaliacoes(string $fornecedorId): int
    {
        return (int)$this->queryScalar(
            "SELECT COUNT(*) FROM avaliacoes_fornecedor WHERE fornecedor_id = ?",
            [$fornecedorId]
        );
    }

    /**
     * Retorna todas as avaliações de um fornecedor
     */
    public function avaliacoes(string $fornecedorId): array
    {
        return $this->query(
            "SELECT av.*, u.nome AS usuario_nome, u.perfil
             FROM avaliacoes_fornecedor av
             JOIN usuarios u ON u.id = av.usuario_id
             WHERE av.fornecedor_id = ?
             ORDER BY av.criado_em DESC",
            [$fornecedorId]
        );
    }

    /**
     * Verifica se um usuário já avaliou um fornecedor
     */
    public function jaAvaliou(string $fornecedorId, string $usuarioId): ?array
    {
        $result = $this->query(
            "SELECT * FROM avaliacoes_fornecedor 
             WHERE fornecedor_id = ? AND usuario_id = ?",
            [$fornecedorId, $usuarioId]
        );
        return $result[0] ?? null;
    }

    /**
     * Atualiza a média de avaliação do fornecedor
     */
    public function atualizarMediaAvaliacao(string $fornecedorId): void
    {
        $media = $this->mediaAvaliacoes($fornecedorId);
        $this->update($fornecedorId, ['avaliacao' => $media]);
    }

    /**
     * Distribuição de notas (quantas avaliações de cada nota)
     */
    public function distribuicaoNotas(string $fornecedorId): array
    {
        $result = $this->query(
            "SELECT nota, COUNT(*) as qtd
             FROM avaliacoes_fornecedor
             WHERE fornecedor_id = ?
             GROUP BY nota
             ORDER BY nota DESC",
            [$fornecedorId]
        );
        
        // Garantir que todas as notas de 1 a 5 apareçam
        $distribuicao = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        foreach ($result as $row) {
            $distribuicao[(int)$row['nota']] = (int)$row['qtd'];
        }
        
        return $distribuicao;
    }

    /**
     * Salva ou atualiza uma avaliação
     */
    public function salvarAvaliacao(string $fornecedorId, string $usuarioId, int $nota, string $comentario = ''): bool
    {
        $jaAvaliou = $this->jaAvaliou($fornecedorId, $usuarioId);
        
        if ($jaAvaliou) {
            // Atualizar
            $this->query(
                "UPDATE avaliacoes_fornecedor 
                 SET nota = ?, comentario = ?, atualizado_em = NOW()
                 WHERE id = ?",
                [$nota, $comentario, $jaAvaliou['id']]
            );
            return true; // atualizado
        } else {
            // Inserir
            $avaliacaoId = uuid();
            $this->query(
                "INSERT INTO avaliacoes_fornecedor 
                 (id, fornecedor_id, usuario_id, nota, comentario, criado_em)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [$avaliacaoId, $fornecedorId, $usuarioId, $nota, $comentario]
            );
            return false; // novo
        }
    }

    /**
     * Remove uma avaliação
     */
    public function removerAvaliacaoUsuario(string $fornecedorId, string $usuarioId): bool
    {
        $avaliacao = $this->jaAvaliou($fornecedorId, $usuarioId);
        
        if (!$avaliacao) {
            return false;
        }
        
        $this->query(
            "DELETE FROM avaliacoes_fornecedor WHERE id = ?",
            [$avaliacao['id']]
        );
        
        return true;
    }
}
