<?php

namespace App\Models;

class Estoque extends BaseModel
{
    protected string $table = 'estoque';

    public function porFilial(string $filialId): array
    {
        return $this->query(
            "SELECT e.*, i.nome AS item_nome, i.unidade, i.curva_abc,
                    gp.nome AS grupo_nome,
                    CASE WHEN e.quantidade < e.estoque_minimo AND e.estoque_minimo > 0 THEN 1 ELSE 0 END AS abaixo_minimo
             FROM estoque e
             JOIN itens i ON i.id = e.item_id
             LEFT JOIN grupos_produtos gp ON gp.id = i.grupo_produto_id
             WHERE e.filial_id = ?
             ORDER BY i.curva_abc, i.nome",
            [$filialId]
        );
    }

    public function itemEmOutrasFiliais(string $itemId, string $filialIdExcluir): array
    {
        return $this->query(
            "SELECT e.*, f.nome AS filial_nome
             FROM estoque e
             JOIN filiais f ON f.id = e.filial_id
             WHERE e.item_id = ? AND e.filial_id != ? AND e.quantidade > 0",
            [$itemId, $filialIdExcluir]
        );
    }

    /**
     * Retorna a quantidade total disponível de um item em TODAS as filiais.
     */
    public function quantidadeDisponivel(string $itemId): float
    {
        $row = $this->queryOne(
            "SELECT COALESCE(SUM(quantidade), 0) AS total FROM estoque WHERE item_id = ?",
            [$itemId]
        );
        return (float)($row['total'] ?? 0);
    }

    /**
     * Usado pela Requisição Interna (fluxo direto/antigo, sem confirmação de
     * recebimento): busca a própria filial primeiro, depois a matriz, depois
     * qualquer outra filial ativa com saldo suficiente.
     */
    public function filialComEstoque(string $itemId, float $minQtd, string $filialPreferida): ?array
    {
        $propria = $this->queryOne(
            "SELECT e.filial_id, e.quantidade, f.nome AS filial_nome
             FROM estoque e JOIN filiais f ON f.id = e.filial_id
             WHERE e.item_id = ? AND e.filial_id = ? AND e.quantidade >= ? AND f.ativo = 1",
            [$itemId, $filialPreferida, $minQtd]
        );
        if ($propria) {
            return array_merge($propria, ['fonte' => 'propria']);
        }

        $matriz = $this->queryOne(
            "SELECT e.filial_id, e.quantidade, f.nome AS filial_nome
             FROM estoque e JOIN filiais f ON f.id = e.filial_id
             WHERE e.item_id = ? AND f.is_matriz = 1 AND e.filial_id != ? AND e.quantidade >= ? AND f.ativo = 1",
            [$itemId, $filialPreferida, $minQtd]
        );
        if ($matriz) {
            return array_merge($matriz, ['fonte' => 'matriz']);
        }

        $outra = $this->queryOne(
            "SELECT e.filial_id, e.quantidade, f.nome AS filial_nome
             FROM estoque e JOIN filiais f ON f.id = e.filial_id
             WHERE e.item_id = ? AND e.filial_id != ? AND e.quantidade >= ? AND f.ativo = 1
             ORDER BY e.quantidade DESC",
            [$itemId, $filialPreferida, $minQtd]
        );
        return $outra ? array_merge($outra, ['fonte' => 'outra']) : null;
    }

    /**
     * Usado pela Solicitação de Transferência (fluxo novo, com confirmação de
     * recebimento): só a matriz pode ser a origem — sem fallback para outras filiais.
     */
    public function matrizComEstoque(string $itemId, float $minQtd): ?array
    {
        $matriz = $this->queryOne(
            "SELECT e.filial_id, e.quantidade, f.nome AS filial_nome
             FROM estoque e JOIN filiais f ON f.id = e.filial_id
             WHERE e.item_id = ? AND f.is_matriz = 1 AND e.quantidade >= ? AND f.ativo = 1",
            [$itemId, $minQtd]
        );
        return $matriz ? array_merge($matriz, ['fonte' => 'matriz']) : null;
    }

    /**
     * Movimenta o estoque diretamente (Requisição Interna — sem etapa de
     * confirmação de recebimento). Usa GREATEST(0, ...) para nunca deixar
     * saldo negativo em caso de corrida.
     */
    public function registrarMovimento(
        string $itemId, string $filialId, float $quantidade,
        string $tipo, string $referenciaId, string $usuarioId
    ): void {
        $sinal = $tipo === 'entrada' ? '+' : '-';
        $this->execute(
            "INSERT INTO estoque (id, item_id, filial_id, quantidade)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantidade = GREATEST(0, quantidade {$sinal} ?)",
            [uuid(), $itemId, $filialId, $quantidade, $quantidade]
        );

        $this->execute(
            "INSERT INTO movimentos_estoque
             (id, item_id, filial_id, tipo, motivo, quantidade, referencia_tipo, referencia_id, usuario_id, criado_em)
             VALUES (?, ?, ?, ?, 'requisicao', ?, 'requisicao', ?, ?, NOW())",
            [uuid(), $itemId, $filialId, $tipo, $quantidade, $referenciaId, $usuarioId]
        );
    }

    /**
     * Espelha uma movimentação de estoque geral no brinde_estoque quando o
     * item estiver vinculado a um brinde (brindes.item_id) — mantém os dois
     * saldos sempre iguais, não importa por onde a movimentação entrou
     * (Estoque manual, Requisição, Transferência, Auditoria, NF).
     */
    public function espelharBrinde(
        string $itemId, string $filialId, float $quantidade,
        string $tipo, string $usuarioId, string $referenciaTipo = 'estoque_geral'
    ): void {
        if ($quantidade <= 0) return;

        $brindeId = $this->queryScalar("SELECT id FROM brindes WHERE item_id = ? LIMIT 1", [$itemId]);
        if (!$brindeId) return;

        $sinal = $tipo === 'entrada' ? '+' : '-';
        $this->execute(
            "INSERT INTO brinde_estoque (id, brinde_id, filial_id, quantidade)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantidade = GREATEST(0, quantidade {$sinal} ?)",
            [uuid(), $brindeId, $filialId, $quantidade, $quantidade]
        );

        $this->execute(
            "INSERT INTO movimentos_brinde (id, brinde_id, filial_id, tipo, quantidade, referencia_tipo, criado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [uuid(), $brindeId, $filialId, $tipo, $quantidade, $referenciaTipo, $usuarioId]
        );
    }

    public function itensAbaixoMinimo(?string $filialId = null): array
    {
        $where  = 'e.quantidade < e.estoque_minimo AND e.estoque_minimo > 0';
        $params = [];
        if ($filialId) { $where .= ' AND e.filial_id = ?'; $params[] = $filialId; }

        return $this->query(
            "SELECT e.*, i.nome AS item_nome, i.unidade, f.nome AS filial_nome
             FROM estoque e
             JOIN itens i ON i.id = e.item_id
             JOIN filiais f ON f.id = e.filial_id
             WHERE {$where}
             ORDER BY e.quantidade ASC",
            $params
        );
    }

    public function getFilialMatriz(): ?array
    {
        return $this->queryOne(
            "SELECT * FROM filiais WHERE is_matriz = 1 AND ativo = 1 LIMIT 1",
            []
        );
    }

    public function itemNaMatriz(string $itemId): ?array
    {
        return $this->queryOne(
            "SELECT e.*, f.nome AS filial_nome
             FROM estoque e
             JOIN filiais f ON f.id = e.filial_id
             WHERE e.item_id = ? AND f.is_matriz = 1 AND f.ativo = 1 AND e.quantidade > 0
             LIMIT 1",
            [$itemId]
        );
    }
}
