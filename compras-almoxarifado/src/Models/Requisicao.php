<?php
// @versao Ver.002.281026

namespace App\Models;

class Requisicao extends BaseModel
{
    protected string $table = 'requisicoes';

    /** Finalidade fiscal do item (Política de Compras §7) — define o crédito de IBS/CBS. */
    public const FINALIDADES = [
        'revenda'     => 'Revenda',
        'insumo'      => 'Insumo / uso na operação',
        'uso_consumo' => 'Uso e consumo administrativo',
        'imobilizado' => 'Ativo imobilizado',
        'servico'     => 'Serviço',
    ];

    /** Tipos de requisição que são compra e seguem a política (finalidade e prazo obrigatórios). */
    public const TIPOS_COMPRA = ['compra', 'ordem_compra'];

    public function allComDetalhes(array $filtros = []): array
    {
        $where  = '1=1';
        $params = [];

        if (!empty($filtros['filial_id']))     { $where .= ' AND r.filial_id = ?';     $params[] = $filtros['filial_id']; }
        if (!empty($filtros['setor_id']))      { $where .= ' AND r.setor_id = ?';      $params[] = $filtros['setor_id']; }
        if (!empty($filtros['status']))        { $where .= ' AND r.status = ?';        $params[] = $filtros['status']; }
        if (!empty($filtros['usuario_id']))    { $where .= ' AND r.usuario_id = ?';    $params[] = $filtros['usuario_id']; }
        if (!empty($filtros['tipo_despesa'])) { $where .= ' AND r.tipo_despesa = ?'; $params[] = $filtros['tipo_despesa']; }
        if (!empty($filtros['tipo']))         { $where .= ' AND r.tipo = ?';         $params[] = $filtros['tipo']; }

        // Escopo do gestor: OR de pares (filial, setor). Lista vazia = sem escopo,
        // vira 1=0 — nunca "IN ()", que é erro de sintaxe.
        if (isset($filtros['pares'])) {
            if (empty($filtros['pares'])) {
                $where .= ' AND 1=0';
            } else {
                $ors = [];
                foreach ($filtros['pares'] as $par) {
                    $ors[]    = '(r.filial_id = ? AND r.setor_id = ?)';
                    $params[] = $par['filial_id'];
                    $params[] = $par['setor_id'];
                }
                $where .= ' AND (' . implode(' OR ', $ors) . ')';
            }
        }

        if (isset($filtros['filiais'])) {
            [$clausula, $paramsFiliais] = sql_escopo_in('r.filial_id', $filtros['filiais']);
            $where .= $clausula;
            $params = [...$params, ...$paramsFiliais];
        }

        return $this->query(
            "SELECT r.id,
                    r.numero,
                    r.criado_em,
                    r.filial_id,
                    r.setor_id,
                    r.usuario_id,
                    r.status,
                    r.tipo,
                    r.tipo_despesa,
                    r.grupo_produto_id,
                    r.filial_destino_id,
                    r.valor_total,
                    u.nome  AS solicitante_nome,
                    f.nome  AS filial_nome,
                    s.nome  AS setor_nome,
                    COALESCE((
                        SELECT GROUP_CONCAT(DISTINCT gp2.nome ORDER BY gp2.nome SEPARATOR ', ')
                        FROM requisicao_itens ri2
                        JOIN itens i2 ON i2.id = ri2.item_id
                        JOIN grupos_produtos gp2 ON gp2.id = i2.grupo_produto_id
                        WHERE ri2.requisicao_id = r.id
                    ), gp.nome) AS grupo_nome,
                    fd.nome AS filial_destino_nome,
                    oc.id         AS oc_id,
                    oc.numero_oc  AS oc_numero,
                    EXISTS (
                        SELECT 1 FROM transferencias t
                        JOIN transferencia_itens ti ON ti.transferencia_id = t.id
                        WHERE t.requisicao_id = r.id
                          AND ti.quantidade_recebida IS NOT NULL
                          AND ti.quantidade_recebida <> ti.quantidade
                    ) AS tem_divergencia
         FROM requisicoes r
         JOIN  usuarios u              ON u.id  = r.usuario_id
         JOIN  filiais f               ON f.id  = r.filial_id
         JOIN  setores s               ON s.id  = r.setor_id
         LEFT JOIN grupos_produtos gp  ON gp.id = r.grupo_produto_id
         LEFT JOIN filiais fd          ON fd.id = r.filial_destino_id
         LEFT JOIN ordens_compra oc    ON oc.requisicao_id = r.id
         WHERE {$where}
         ORDER BY r.criado_em DESC",
            $params
        );
    }

    public function itens(string $requisicaoId): array
    {
        return $this->query(
            "SELECT ri.*,
                    i.nome AS item_nome, i.unidade, i.grupo_produto_id, i.valor_referencia,
                    gp.nome AS grupo_nome
             FROM requisicao_itens ri
             LEFT JOIN itens i ON i.id = ri.item_id
             LEFT JOIN grupos_produtos gp ON gp.id = i.grupo_produto_id
             WHERE ri.requisicao_id = ?",
            [$requisicaoId]
        );
    }

    public function valoresPorGrupo(string $requisicaoId): array
    {
        return $this->query(
            "SELECT i.grupo_produto_id, gp.nome AS grupo_nome,
                    SUM(ri.quantidade * ri.valor_unitario) AS valor_total
             FROM requisicao_itens ri
             LEFT JOIN itens i ON i.id = ri.item_id
             LEFT JOIN grupos_produtos gp ON gp.id = i.grupo_produto_id
             WHERE ri.requisicao_id = ?
               AND ri.valor_unitario > 0
             GROUP BY i.grupo_produto_id, gp.nome
             ORDER BY gp.nome",
            [$requisicaoId]
        );
    }

    public function addItem(string $requisicaoId, ?string $itemId, float $quantidade, float $valorUnitario, ?string $obs = null, ?string $descricaoLivre = null, ?string $anexo = null): void
    {
        $this->execute(
            "INSERT INTO requisicao_itens (id, requisicao_id, item_id, descricao_livre, anexo, quantidade, valor_unitario, observacao)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [uuid(), $requisicaoId, $itemId ?: null, $descricaoLivre, $anexo, $quantidade, $valorUnitario, $obs]
        );
        $this->recalcularTotal($requisicaoId);
    }

    public function recalcularTotal(string $id): void
    {
        $this->execute(
            "UPDATE requisicoes r
             SET valor_total = (
                SELECT COALESCE(SUM(
                    ri.quantidade * COALESCE(
                        NULLIF(ri.valor_unitario, 0),
                        e.valor_unitario,
                        (SELECT hp.valor_unitario FROM historico_precos hp WHERE hp.item_id = i.id ORDER BY hp.registrado_em DESC LIMIT 1),
                        i.valor_referencia, 0
                    )
                ), 0)
                FROM requisicao_itens ri
                LEFT JOIN itens i ON i.id = ri.item_id
                LEFT JOIN estoque e ON e.item_id = ri.item_id AND e.filial_id = r.filial_id
                WHERE ri.requisicao_id = r.id
             )
             WHERE r.id = ?",
            [$id]
        );
    }

    public function gestorDaFilial(string $filialId): ?array
    {
        return $this->queryOne(
            "SELECT id, nome FROM usuarios WHERE perfil = 'gestor' AND filial_id = ? AND ativo = 1",
            [$filialId]
        );
    }

    /**
     * @param array<int, string>|null $filiais null = todas; [] = nenhuma
     * @param array<int, array{filial_id: string, setor_id: string}>|null $pares
     *        Quando informado (gestor), tem precedência sobre $filiais: o escopo
     *        vira o par unidade+setor, o mesmo de /requisicoes e /aprovacoes.
     */
    public function dashboardResumo(int $mes, int $ano, ?array $filiais = null, ?array $pares = null): array
    {
        [$filialClause, $filialParams] = $pares !== null
            ? sql_escopo_pares('filial_id', 'setor_id', $pares)
            : sql_escopo_in('filial_id', $filiais);

        // Contadores de status — acumulado do ano inteiro
        $paramsAno = [$ano, ...$filialParams];

        $contadores = $this->queryOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status='pendente'     THEN 1 ELSE 0 END) AS pendentes,
                SUM(CASE WHEN status='em_aprovacao' THEN 1 ELSE 0 END) AS em_aprovacao,
                SUM(CASE WHEN status='aprovado'     THEN 1 ELSE 0 END) AS aprovadas,
                SUM(CASE WHEN status='rejeitado'    THEN 1 ELSE 0 END) AS rejeitadas
             FROM requisicoes
             WHERE YEAR(criado_em) = ?{$filialClause}",
            $paramsAno
        ) ?? [];

        // Valores financeiros — mês atual
        $paramsMes = [$mes, $ano, ...$filialParams];

        $valores = $this->queryOne(
            "SELECT
                SUM(CASE WHEN status='aprovado'       THEN valor_total ELSE 0 END) AS valor_aprovado,
                SUM(CASE WHEN tipo_despesa='eventual' AND status != 'cancelado' THEN valor_total ELSE 0 END) AS valor_eventual
             FROM requisicoes
             WHERE MONTH(criado_em) = ? AND YEAR(criado_em) = ?{$filialClause}",
            $paramsMes
        ) ?? [];

        return array_merge($contadores, $valores);
    }
}
