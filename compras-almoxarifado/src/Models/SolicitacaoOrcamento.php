<?php

namespace App\Models;

class SolicitacaoOrcamento extends BaseModel
{
    protected string $table = 'solicitacoes_orcamento';

    public function allComDetalhes(array $filtros = []): array
    {
        $where  = '1=1';
        $params = [];

        if (!empty($filtros['status']))   { $where .= ' AND so.status = ?';    $params[] = $filtros['status']; }
        if (!empty($filtros['filial_id'])){ $where .= ' AND so.filial_id = ?'; $params[] = $filtros['filial_id']; }

        return $this->query(
            "SELECT so.*,
                    u.nome   AS solicitante_nome,
                    s.nome   AS setor_nome,
                    gp.nome  AS grupo_nome,
                    f.nome   AS filial_nome
             FROM solicitacoes_orcamento so
             LEFT JOIN usuarios u         ON u.id  = so.solicitado_por
             LEFT JOIN setores s          ON s.id  = so.setor_id
             LEFT JOIN grupos_produtos gp ON gp.id = so.grupo_produto_id
             LEFT JOIN filiais f          ON f.id  = so.filial_id
             WHERE {$where}
             ORDER BY so.criado_em DESC",
            $params
        );
    }

    public function pendentesParaDiretor(): array
    {
        return $this->allComDetalhes(['status' => 'pendente']);
    }

    public function aprovar(string $id, string $aprovadoPor, string $obs): void
    {
        $sol = $this->find($id);
        if (!$sol) return;

        $this->beginTransaction();
        try {
            // Atualiza solicitação
            $this->update($id, [
                'status'            => 'aprovado',
                'analisado_por'     => $aprovadoPor,
                'observacao_direcao'=> $obs,
                'resolvido_em'      => date('Y-m-d H:i:s'),
            ]);

            // Incrementa valor aprovado no orçamento
            $orc = new Orcamento();
            $orc->execute(
                "UPDATE orcamentos SET valor_aprovado = valor_aprovado + ?, status = 'ativo'
                 WHERE id = ?",
                [$sol['valor_solicitado'], $sol['orcamento_id']]
            );

            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function rejeitar(string $id, string $analisadoPor, string $obs): void
    {
        $this->update($id, [
            'status'             => 'rejeitado',
            'analisado_por'      => $analisadoPor,
            'observacao_direcao' => $obs,
            'resolvido_em'       => date('Y-m-d H:i:s'),
        ]);
    }
}
