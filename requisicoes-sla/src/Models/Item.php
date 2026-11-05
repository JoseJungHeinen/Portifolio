<?php

namespace App\Models;

class Item extends BaseModel
{
    protected string $table = 'itens';

    public function estatisticas(): array
    {
        $ativos   = (int)$this->queryScalar("SELECT COUNT(*) FROM itens WHERE ativo = 1");
        $inativos = (int)$this->queryScalar("SELECT COUNT(*) FROM itens WHERE ativo = 0");

        $porGrupo = $this->query(
            "SELECT gp.nome AS grupo, gp.id AS grupo_id,
                    SUM(i.ativo = 1) AS ativos,
                    SUM(i.ativo = 0) AS inativos
             FROM grupos_produtos gp
             LEFT JOIN itens i ON i.grupo_produto_id = gp.id
             WHERE gp.ativo = 1
             GROUP BY gp.id, gp.nome
             HAVING (ativos + inativos) > 0
             ORDER BY gp.nome"
        );

        $porSetor = $this->query(
            "SELECT s.nome AS setor, s.id AS setor_id,
                    SUM(i.ativo = 1) AS ativos,
                    SUM(i.ativo = 0) AS inativos
             FROM setores s
             INNER JOIN itens_setores ist ON ist.setor_id = s.id
             INNER JOIN itens i           ON i.id = ist.item_id
             WHERE s.ativo = 1
             GROUP BY s.id, s.nome
             ORDER BY s.nome"
        );

        return compact('ativos', 'inativos', 'porGrupo', 'porSetor');
    }

    public function allComGrupo(array $filtros = []): array
    {
        $whereParams = [];

        $where = '1=1';
        if (!empty($filtros['grupo_produto_id'])) { $where .= ' AND i.grupo_produto_id = ?'; $whereParams[] = $filtros['grupo_produto_id']; }
        if (isset($filtros['ativo']) && $filtros['ativo'] !== '') { $where .= ' AND i.ativo = ?'; $whereParams[] = $filtros['ativo']; }
        if (!empty($filtros['busca']))             { $where .= ' AND (i.nome LIKE ? OR i.codigo LIKE ?)'; $whereParams[] = '%'.$filtros['busca'].'%'; $whereParams[] = '%'.$filtros['busca'].'%'; }
        if (!empty($filtros['col_codigo']))        { $where .= ' AND i.codigo LIKE ?'; $whereParams[] = '%'.$filtros['col_codigo'].'%'; }
        if (!empty($filtros['col_nome']))          { $where .= ' AND i.nome LIKE ?';   $whereParams[] = '%'.$filtros['col_nome'].'%'; }
        if (!empty($filtros['curva']))             { $where .= ' AND i.curva_abc = ?'; $whereParams[] = $filtros['curva']; }
        if (!empty($filtros['setor_id']))          { $where .= ' AND EXISTS (SELECT 1 FROM itens_setores iset WHERE iset.item_id = i.id AND iset.setor_id = ?)'; $whereParams[] = $filtros['setor_id']; }

        // Ordenação segura por whitelist de colunas
        $colunasPermitidas = [
            'codigo'      => 'i.codigo',
            'nome'        => 'i.nome',
            'grupo'       => 'gp.nome',
            'unidade'     => 'i.unidade',
            'curva'       => 'i.curva_abc',
            'estoque'     => 'saldo_total',
            'valor_ref'   => 'i.valor_referencia',
            'status'      => 'i.ativo',
        ];
        $sortCol = $colunasPermitidas[$filtros['sort'] ?? ''] ?? null;
        $sortDir = strtoupper($filtros['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        $orderBy = $sortCol
            ? "{$sortCol} {$sortDir}, i.nome ASC"
            : 'abaixo_minimo DESC, i.nome ASC';

        return $this->query(
            "SELECT i.*, gp.nome AS grupo_nome, c.nome AS categoria_nome,
                    COALESCE(et.saldo_total, 0) AS saldo_total,
                    CASE WHEN COALESCE(et.saldo_total, 0) <= i.estoque_minimo THEN 1 ELSE 0 END AS abaixo_minimo,
                    (SELECT hp.valor_unitario FROM historico_precos hp
                     WHERE hp.item_id = i.id
                     ORDER BY hp.registrado_em DESC, hp.id DESC
                     LIMIT 1 OFFSET 1) AS valor_referencia_anterior
             FROM itens i
             LEFT JOIN grupos_produtos gp ON gp.id = i.grupo_produto_id
             LEFT JOIN categorias c       ON c.id  = i.categoria_id
             LEFT JOIN (SELECT item_id, SUM(quantidade) AS saldo_total FROM estoque GROUP BY item_id) et ON et.item_id = i.id
             WHERE {$where} ORDER BY {$orderBy}",
            $whereParams
        );
    }
}
