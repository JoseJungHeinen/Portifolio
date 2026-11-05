<?php
// @versao Ver.001.090926

namespace App\Models;

class GestorSetor extends BaseModel
{
    protected string $table = 'gestores_setor';

    /** Vínculos com os nomes já resolvidos, para a tela de configuração. */
    public function listar(?string $filialId = null, ?string $setorId = null): array
    {
        $where  = '1=1';
        $params = [];
        if ($filialId) { $where .= ' AND gs.filial_id = ?'; $params[] = $filialId; }
        if ($setorId)  { $where .= ' AND gs.setor_id = ?';  $params[] = $setorId; }

        return $this->query(
            "SELECT gs.*,
                    f.nome   AS filial_nome,
                    s.nome   AS setor_nome,
                    u.nome   AS gestor_nome,
                    u.perfil AS gestor_perfil,
                    u.ativo  AS gestor_ativo
             FROM gestores_setor gs
             JOIN filiais  f ON f.id = gs.filial_id
             JOIN setores  s ON s.id = gs.setor_id
             JOIN usuarios u ON u.id = gs.usuario_id
             WHERE {$where}
             ORDER BY f.nome, s.nome, u.nome",
            $params
        );
    }

    /** Vínculo existente para a trinca, ativo ou não — usado para reativar em vez de recusar. */
    public function existente(string $filialId, string $setorId, string $usuarioId): ?array
    {
        return $this->queryOne(
            "SELECT * FROM gestores_setor
             WHERE filial_id = ? AND setor_id = ? AND usuario_id = ?
             LIMIT 1",
            [$filialId, $setorId, $usuarioId]
        );
    }
}
