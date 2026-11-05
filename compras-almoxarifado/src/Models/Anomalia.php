<?php
// @versao Ver.001.090926

namespace App\Models;

class Anomalia extends BaseModel
{
    protected string $table = 'anomalias';

    /** @param array<int, string>|null $filiais null = todas; [] = nenhuma */
    public function ativas(?array $filiais = null): array
    {
        [$filialCond, $params] = sql_escopo_in('filial_id', $filiais);

        return $this->query(
            "SELECT a.*, s.nome AS setor_nome
             FROM anomalias a
             LEFT JOIN setores s ON s.id = a.setor_id
             WHERE status = 'ativa'{$filialCond}
             ORDER BY a.valor_impacto DESC, a.criado_em DESC",
            $params
        );
    }

    /** @param array<int, string>|null $filiais null = todas; [] = nenhuma */
    public function totalImpacto(?array $filiais = null): float
    {
        [$filialCond, $params] = sql_escopo_in('filial_id', $filiais);

        return (float)$this->queryScalar(
            "SELECT COALESCE(SUM(valor_impacto),0) FROM anomalias WHERE status = 'ativa'{$filialCond}",
            $params
        );
    }

    public function registrar(
        string $tipo,
        string $titulo,
        string $descricao,
        float  $valorImpacto,
        ?string $entidadeTipo = null,
        ?string $entidadeId   = null,
        ?string $filialId     = null,
        ?string $setorId      = null
    ): void {
        // Evita duplicata do mesmo tipo/entidade ativa
        $existe = $this->queryOne(
            "SELECT id FROM anomalias
             WHERE tipo = ? AND entidade_id = ? AND status = 'ativa' LIMIT 1",
            [$tipo, $entidadeId]
        );
        if ($existe) return;

        $this->create([
            'tipo'          => $tipo,
            'titulo'        => $titulo,
            'descricao'     => $descricao,
            'valor_impacto' => $valorImpacto,
            'entidade_tipo' => $entidadeTipo,
            'entidade_id'   => $entidadeId,
            'filial_id'     => $filialId,
            'setor_id'      => $setorId,
        ]);
    }
}
