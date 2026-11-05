<?php
// @versao Ver.001.061026

namespace App\Models;

class Orcamento extends BaseModel
{
    protected string $table = 'orcamentos';

    public function findPeriodo(string $filialId, string $setorId, string $grupoProdutoId, int $mes, int $ano): ?array
    {
        return $this->queryOne(
            "SELECT o.*, s.nome AS setor_nome, gp.nome AS grupo_nome, f.nome AS filial_nome
             FROM orcamentos o
             JOIN setores s        ON s.id  = o.setor_id
             JOIN grupos_produtos gp ON gp.id = o.grupo_produto_id
             JOIN filiais f        ON f.id  = o.filial_id
             WHERE o.filial_id = ? AND o.setor_id = ? AND o.grupo_produto_id = ?
               AND o.mes = ? AND o.ano = ?
             LIMIT 1",
            [$filialId, $setorId, $grupoProdutoId, $mes, $ano]
        );
    }

    /**
     * Saldo total do setor (soma de todos os grupos) num período.
     * Usado pela camada 1 da verificação orçamentária.
     */
    public function saldoSetor(string $filialId, string $setorId, int $mes, int $ano): float
    {
        $row = $this->queryOne(
            "SELECT SUM(valor_aprovado) AS aprovado, SUM(valor_consumido) AS consumido
             FROM orcamentos
             WHERE filial_id = ? AND setor_id = ? AND mes = ? AND ano = ?",
            [$filialId, $setorId, $mes, $ano]
        );
        if (!$row || $row['aprovado'] === null) return 0.0;
        return max(0.0, (float)$row['aprovado'] - (float)$row['consumido']);
    }

    /**
     * Primeiro registro de orçamento do setor no período,
     * priorizando o grupo específico, senão qualquer do setor.
     */
    public function findMelhorParaSetor(
        string $filialId,
        string $setorId,
        string $grupoProdutoId,
        int    $mes,
        int    $ano
    ): ?array {
        // Tenta match exato setor + grupo
        $orc = $this->findPeriodo($filialId, $setorId, $grupoProdutoId, $mes, $ano);
        if ($orc) return $orc;

        // Fallback: qualquer orçamento do setor neste período
        return $this->findDoSetor($filialId, $setorId, $mes, $ano);
    }

    /**
     * Orçamento do setor no período, sem depender de grupo — o de maior valor
     * aprovado. Usado pela Ordem de Compra, que cota por valor total e não
     * pergunta o grupo de produto ao solicitante.
     */
    public function findDoSetor(string $filialId, string $setorId, int $mes, int $ano): ?array
    {
        return $this->queryOne(
            "SELECT o.*, s.nome AS setor_nome, gp.nome AS grupo_nome, f.nome AS filial_nome
             FROM orcamentos o
             JOIN setores s          ON s.id  = o.setor_id
             JOIN grupos_produtos gp ON gp.id = o.grupo_produto_id
             JOIN filiais f          ON f.id  = o.filial_id
             WHERE o.filial_id = ? AND o.setor_id = ? AND o.mes = ? AND o.ano = ?
             ORDER BY o.valor_aprovado DESC
             LIMIT 1",
            [$filialId, $setorId, $mes, $ano]
        );
    }

    public function saldoDisponivel(string $filialId, string $setorId, string $grupoProdutoId): float
    {
        $mes = (int)date('n');
        $ano = (int)date('Y');
        $orc = $this->findPeriodo($filialId, $setorId, $grupoProdutoId, $mes, $ano);
        if (!$orc) return 0.0;
        return max(0.0, (float)$orc['valor_aprovado'] - (float)$orc['valor_consumido']);
    }

    public function consumir(string $id, float $valor): void
    {
        $this->execute(
            "UPDATE orcamentos
             SET valor_consumido = valor_consumido + ?,
                 status = CASE
                    WHEN (valor_consumido + ?) >= valor_aprovado THEN 'esgotado'
                    ELSE 'ativo'
                 END
             WHERE id = ?",
            [$valor, $valor, $id]
        );
    }

    public function devolver(string $id, float $valor): void
    {
        $this->execute(
            "UPDATE orcamentos
             SET valor_consumido = GREATEST(0, valor_consumido - ?),
                 status = 'ativo'
             WHERE id = ?",
            [$valor, $id]
        );
    }

    public function dashboardEmpresa(int $mes, int $ano, ?string $filialId = null): array
    {
        $where  = 'mes = ? AND ano = ?';
        $params = [$mes, $ano];
        if ($filialId) {
            $where .= ' AND filial_id = ?';
            $params[] = $filialId;
        }

        return $this->query(
            "SELECT
                SUM(valor_aprovado)  AS total_orcado,
                SUM(valor_consumido) AS total_realizado,
                SUM(valor_aprovado - valor_consumido) AS total_saldo,
                ROUND(SUM(valor_consumido)/NULLIF(SUM(valor_aprovado),0)*100,1) AS pct_consumido
             FROM orcamentos
             WHERE {$where}",
            $params
        );
    }

    public function dashboardPorFilial(int $mes, int $ano): array
    {
        return $this->query(
            "SELECT
                f.id AS filial_id,
                f.nome AS filial,
                COALESCE(SUM(o.valor_aprovado),0)  AS orcado,
                COALESCE(SUM(o.valor_consumido),0) AS realizado,
                COALESCE(SUM(o.valor_aprovado - o.valor_consumido),0) AS saldo,
                COALESCE(ROUND(SUM(o.valor_consumido)/NULLIF(SUM(o.valor_aprovado),0)*100,1),0) AS pct
             FROM filiais f
             LEFT JOIN orcamentos o
                    ON o.filial_id = f.id
                   AND o.mes = ?
                   AND o.ano = ?
             WHERE f.ativo = 1
             GROUP BY f.id, f.nome
             ORDER BY f.nome",
            [$mes, $ano]
        );
    }

    public function dashboardPorSetor(int $mes, int $ano, ?string $filialId = null): array
    {
        $where  = 'o.mes = ? AND o.ano = ?';
        $params = [$mes, $ano];
        if ($filialId) {
            $where .= ' AND o.filial_id = ?';
            $params[] = $filialId;
        }

        return $this->query(
            "SELECT
                o.setor_id,
                s.nome AS setor,
                s.tipo,
                SUM(o.valor_aprovado)  AS orcado,
                SUM(o.valor_consumido) AS realizado,
                SUM(o.valor_aprovado - o.valor_consumido) AS saldo,
                ROUND(SUM(o.valor_consumido)/NULLIF(SUM(o.valor_aprovado),0)*100,1) AS pct
             FROM orcamentos o
             JOIN setores s ON s.id = o.setor_id
             WHERE {$where}
             GROUP BY o.setor_id, s.nome, s.tipo
             ORDER BY pct DESC",
            $params
        );
    }

    public function historico12Meses(?string $filialId = null): array
    {
        $where  = '1=1';
        $params = [];
        if ($filialId) {
            $where .= ' AND filial_id = ?';
            $params[] = $filialId;
        }

        return $this->query(
            "SELECT
                mes, ano,
                SUM(valor_aprovado)  AS orcado,
                SUM(valor_consumido) AS realizado
             FROM orcamentos
             WHERE {$where}
               AND STR_TO_DATE(CONCAT(ano,'-',LPAD(mes,2,'0'),'-01'),'%Y-%m-%d')
                   >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
             GROUP BY ano, mes
             ORDER BY ano, mes",
            $params
        );
    }

    public function dashboardPorSetorRange(int $mesIni, int $anoIni, int $mesFim, int $anoFim, ?string $filialId = null): array
    {
        $where  = '(o.ano * 12 + o.mes) BETWEEN (? * 12 + ?) AND (? * 12 + ?)';
        $params = [$anoIni, $mesIni, $anoFim, $mesFim];
        if ($filialId) {
            $where .= ' AND o.filial_id = ?';
            $params[] = $filialId;
        }

        return $this->query(
            "SELECT
                o.setor_id,
                s.nome AS setor,
                s.tipo,
                SUM(o.valor_aprovado)  AS orcado,
                SUM(o.valor_consumido) AS realizado,
                SUM(o.valor_aprovado - o.valor_consumido) AS saldo,
                ROUND(SUM(o.valor_consumido)/NULLIF(SUM(o.valor_aprovado),0)*100,1) AS pct
             FROM orcamentos o
             JOIN setores s ON s.id = o.setor_id
             WHERE {$where}
             GROUP BY o.setor_id, s.nome, s.tipo
             ORDER BY pct DESC",
            $params
        );
    }

    public function historicoRange(int $mesIni, int $anoIni, int $mesFim, int $anoFim, ?string $filialId = null): array
    {
        $where  = '(ano * 12 + mes) BETWEEN (? * 12 + ?) AND (? * 12 + ?)';
        $params = [$anoIni, $mesIni, $anoFim, $mesFim];
        if ($filialId) {
            $where .= ' AND filial_id = ?';
            $params[] = $filialId;
        }

        return $this->query(
            "SELECT mes, ano,
                SUM(valor_aprovado)  AS orcado,
                SUM(valor_consumido) AS realizado
             FROM orcamentos
             WHERE {$where}
             GROUP BY ano, mes
             ORDER BY ano, mes",
            $params
        );
    }
}
