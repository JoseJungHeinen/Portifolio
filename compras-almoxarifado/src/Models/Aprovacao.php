<?php
// @versao Ver.002.281026

namespace App\Models;

class Aprovacao extends BaseModel
{
    protected string $table = 'aprovacoes';

    /**
     * Cláusula de escopo do gestor: só vê aprovação cuja unidade+setor esteja em
     * gestores_setor. Sem vínculo, não vê nada — a aprovação fica para admin/diretor.
     *
     * Cobre os três tipos de entidade da fila:
     *   - requisicao        → casa filial_id + setor_id da própria requisição
     *   - ordem_compra      → casa pela requisição de origem (ordens_compra.requisicao_id)
     *   - requisicao_brinde → casa só a unidade; requisicoes_brinde não tem setor_id
     *
     * Os EXISTS são autocontidos (dependem apenas do alias "a"), então a mesma
     * cláusula serve nos quatro métodos, independente dos joins que cada um faz.
     *
     * @return array{0: string, 1: array<int, string>} [cláusula, parâmetros]
     */
    private function escopoGestor(string $perfil, string $usuarioId): array
    {
        if ($perfil !== 'gestor') {
            return ['', []];
        }

        $clausula = " AND (
             EXISTS (
                SELECT 1
                FROM requisicoes rr
                JOIN gestores_setor gs
                  ON gs.filial_id = rr.filial_id AND gs.setor_id = rr.setor_id
                WHERE gs.usuario_id = ?
                  AND gs.ativo = 1
                  AND rr.id = CASE a.entidade_tipo
                                WHEN 'requisicao'   THEN a.entidade_id
                                WHEN 'ordem_compra' THEN (SELECT oc2.requisicao_id
                                                          FROM ordens_compra oc2
                                                          WHERE oc2.id = a.entidade_id)
                              END
             )
          OR EXISTS (
                SELECT 1
                FROM requisicoes_brinde rrb
                JOIN gestores_setor gsb ON gsb.filial_id = rrb.filial_id
                WHERE gsb.usuario_id = ?
                  AND gsb.ativo = 1
                  AND a.entidade_tipo = 'requisicao_brinde'
                  AND rrb.id = a.entidade_id
             )
        )";

        return [$clausula, [$usuarioId, $usuarioId]];
    }

    /** Valor da entidade aprovada, autocontido (só depende do alias "a"). */
    private function valorEntidadeSql(): string
    {
        return "(CASE a.entidade_tipo
                    WHEN 'requisicao'        THEN (SELECT rv.valor_total FROM requisicoes rv WHERE rv.id = a.entidade_id)
                    WHEN 'requisicao_brinde' THEN (SELECT bv.valor_total FROM requisicoes_brinde bv WHERE bv.id = a.entidade_id)
                    WHEN 'ordem_compra'      THEN (SELECT ov.valor_total FROM ordens_compra ov WHERE ov.id = a.entidade_id)
                    ELSE 0
                 END)";
    }

    /**
     * Existe alçada (do perfil indicado, ou de qualquer perfil se $perfilSql for
     * null) para ESTE nível e para o valor da entidade. Antes só se comparava o
     * nível: com gestor no nível 1 até R$ 999,99, ele via o nível 1 de uma
     * requisição de R$ 5.000 — que é o passo da diretoria.
     */
    private function alcadaDoNivelSql(?string $perfilSql): string
    {
        $v = $this->valorEntidadeSql();
        $perfil = $perfilSql === null ? '' : " AND al.perfil = {$perfilSql}";
        return "EXISTS (SELECT 1 FROM alcadas al
                        WHERE al.ativo = 1 AND al.nivel = a.nivel{$perfil}
                          AND al.valor_min <= {$v}
                          AND (al.valor_max IS NULL OR al.valor_max >= {$v}))";
    }

    /**
     * Só o nível corrente: um nível aparece (e pode ser decidido) depois que
     * todos os anteriores da mesma entidade foram aprovados. Sem isto o nível 2
     * podia ser aprovado antes do 1 e virava aprovação final.
     */
    private function nivelAtualSql(): string
    {
        return " AND NOT EXISTS (SELECT 1 FROM aprovacoes ant
                                 WHERE ant.entidade_tipo = a.entidade_tipo
                                   AND ant.entidade_id   = a.entidade_id
                                   AND ant.status        = 'pendente'
                                   AND ant.nivel         < a.nivel)";
    }

    /**
     * Filtro do diretor: vê os níveis da alçada de diretor, os níveis sem alçada
     * configurada (fallback) e — como substituto — os níveis de gestor quando a
     * unidade/setor não tem gestor ativo cadastrado. Assim a "verificação do
     * gestor" depois da diretoria fica com o gestor. Admin não passa por aqui.
     */
    private function escopoDiretorSql(): string
    {
        return " AND (
                    {$this->alcadaDoNivelSql("'diretor'")}
                 OR NOT {$this->alcadaDoNivelSql(null)}
                 OR NOT EXISTS (
                        SELECT 1 FROM gestores_setor gx
                        JOIN usuarios ux ON ux.id = gx.usuario_id AND ux.ativo = 1
                        WHERE gx.ativo = 1 AND (
                             (a.entidade_tipo = 'requisicao' AND (gx.filial_id, gx.setor_id) =
                                 (SELECT rq.filial_id, rq.setor_id FROM requisicoes rq WHERE rq.id = a.entidade_id))
                          OR (a.entidade_tipo = 'ordem_compra' AND (gx.filial_id, gx.setor_id) =
                                 (SELECT rq.filial_id, rq.setor_id FROM ordens_compra oq
                                  JOIN requisicoes rq ON rq.id = oq.requisicao_id WHERE oq.id = a.entidade_id))
                          OR (a.entidade_tipo = 'requisicao_brinde' AND gx.filial_id =
                                 (SELECT bq.filial_id FROM requisicoes_brinde bq WHERE bq.id = a.entidade_id))
                        )
                    )
                 )";
    }

    /** A aprovação está na fila deste usuário agora? (mesma regra da listagem) */
    public function podeDecidir(string $aprovacaoId, string $usuarioId, string $perfil): bool
    {
        foreach ($this->pendentesParaUsuario($usuarioId, $perfil) as $p) {
            if ($p['id'] === $aprovacaoId) return true;
        }
        return false;
    }

    public function pendentesParaUsuario(string $usuarioId, string $perfil): array
    {
        $perfisAdmin = ['admin', 'diretor'];

        if ($perfil === 'almoxarife') {
            return $this->query(
                "SELECT a.*,
                        CASE a.entidade_tipo
                            WHEN 'requisicao'        THEN r.valor_total
                            WHEN 'requisicao_brinde' THEN rb.valor_total
                            ELSE 0
                        END AS valor,
                        CASE a.entidade_tipo
                            WHEN 'requisicao'        THEN u.nome
                            WHEN 'requisicao_brinde' THEN u3.nome
                            ELSE '—'
                        END AS solicitante_nome
                 FROM aprovacoes a
                 LEFT JOIN requisicoes r         ON r.id  = a.entidade_id AND a.entidade_tipo = 'requisicao'
                 LEFT JOIN usuarios u            ON u.id  = r.usuario_id
                 LEFT JOIN requisicoes_brinde rb ON rb.id = a.entidade_id AND a.entidade_tipo = 'requisicao_brinde'
                 LEFT JOIN usuarios u3           ON u3.id = rb.criado_por
                 WHERE a.status = 'pendente'
                   AND (a.atribuido_para IS NULL OR a.atribuido_para = ?)
                   AND (
                        (a.entidade_tipo = 'requisicao'        AND r.tipo = 'interna' AND r.usuario_id = ?)
                     OR (a.entidade_tipo = 'requisicao'        AND r.tipo = 'interna' AND u.perfil = 'gestor'
                         AND r.filial_id = (SELECT filial_id FROM usuarios WHERE id = ?))
                     OR (a.entidade_tipo = 'requisicao_brinde' AND rb.criado_por = ?)
                   ){$this->nivelAtualSql()}
                 ORDER BY a.criado_em ASC",
                [$usuarioId, $usuarioId, $usuarioId, $usuarioId]
            );
        }

        if (in_array($perfil, $perfisAdmin)) {
            // Diretor não vê a verificação do gestor (salvo setor sem gestor); admin vê tudo.
            $escopoDiretor = $perfil === 'diretor' ? $this->escopoDiretorSql() : '';
            return $this->query(
                "SELECT a.*,
                        CASE a.entidade_tipo
                            WHEN 'requisicao'   THEN r.valor_total
                            WHEN 'ordem_compra' THEN oc.valor_total
                            ELSE 0
                        END AS valor,
                        CASE a.entidade_tipo
                            WHEN 'requisicao'   THEN u.nome
                            WHEN 'ordem_compra' THEN u2.nome
                            ELSE '—'
                        END AS solicitante_nome
                 FROM aprovacoes a
                 LEFT JOIN requisicoes r         ON r.id  = a.entidade_id AND a.entidade_tipo = 'requisicao'
                 LEFT JOIN usuarios u            ON u.id  = r.usuario_id
                 LEFT JOIN ordens_compra oc      ON oc.id = a.entidade_id AND a.entidade_tipo = 'ordem_compra'
                 LEFT JOIN usuarios u2           ON u2.id = oc.criado_por
                 LEFT JOIN requisicoes_brinde rb ON rb.id = a.entidade_id AND a.entidade_tipo = 'requisicao_brinde'
                 LEFT JOIN usuarios u3           ON u3.id = rb.criado_por
                 WHERE a.status = 'pendente'
                   AND (a.atribuido_para IS NULL OR a.atribuido_para = ?)
                   AND NOT (a.entidade_tipo = 'requisicao'        AND r.tipo = 'interna' AND u.perfil  = 'almoxarife')
                   AND NOT (a.entidade_tipo = 'requisicao'        AND r.tipo = 'interna' AND u.perfil  = 'gestor')
                   AND NOT (a.entidade_tipo = 'requisicao_brinde' AND u3.perfil = 'almoxarife'){$this->nivelAtualSql()}{$escopoDiretor}
                 ORDER BY a.criado_em ASC",
                [$usuarioId]
            );
        }

        [$escopoSql, $escopoParams] = $this->escopoGestor($perfil, $usuarioId);

        return $this->query(
            "SELECT a.*,
                    CASE a.entidade_tipo
                        WHEN 'requisicao'   THEN r.valor_total
                        WHEN 'ordem_compra' THEN oc.valor_total
                        ELSE 0
                    END AS valor,
                    CASE a.entidade_tipo
                        WHEN 'requisicao'   THEN u.nome
                        WHEN 'ordem_compra' THEN u2.nome
                        ELSE '—'
                    END AS solicitante_nome
             FROM aprovacoes a
             LEFT JOIN requisicoes r         ON r.id  = a.entidade_id AND a.entidade_tipo = 'requisicao'
             LEFT JOIN usuarios u            ON u.id  = r.usuario_id
             LEFT JOIN ordens_compra oc      ON oc.id = a.entidade_id AND a.entidade_tipo = 'ordem_compra'
             LEFT JOIN usuarios u2           ON u2.id = oc.criado_por
             LEFT JOIN requisicoes_brinde rb ON rb.id = a.entidade_id AND a.entidade_tipo = 'requisicao_brinde'
             LEFT JOIN usuarios u3           ON u3.id = rb.criado_por
             WHERE a.status = 'pendente'
               AND (a.atribuido_para IS NULL OR a.atribuido_para = ?)
               AND (
                    {$this->alcadaDoNivelSql('?')}
                 OR (a.entidade_tipo = 'requisicao' AND r.tipo = 'interna' AND u.perfil = 'gestor' AND r.usuario_id = ?)
               ){$escopoSql}
               AND NOT (a.entidade_tipo = 'requisicao'        AND r.tipo = 'interna' AND u.perfil  = 'almoxarife')
               AND NOT (a.entidade_tipo = 'requisicao_brinde' AND u3.perfil = 'almoxarife'){$this->nivelAtualSql()}
             ORDER BY a.criado_em ASC",
            [$usuarioId, $perfil, $usuarioId, ...$escopoParams]
        );
    }

    public function totalPendentes(string $usuarioId, string $perfil): int
    {
        $perfisAdmin = ['admin', 'diretor'];

        if ($perfil === 'almoxarife') {
            $row = $this->query(
                "SELECT COUNT(*) AS total
                 FROM aprovacoes a
                 LEFT JOIN requisicoes r         ON r.id  = a.entidade_id AND a.entidade_tipo = 'requisicao'
                 LEFT JOIN usuarios u            ON u.id  = r.usuario_id
                 LEFT JOIN requisicoes_brinde rb ON rb.id = a.entidade_id AND a.entidade_tipo = 'requisicao_brinde'
                 WHERE a.status = 'pendente'
                   AND (a.atribuido_para IS NULL OR a.atribuido_para = ?)
                   AND (
                        (a.entidade_tipo = 'requisicao'        AND r.tipo = 'interna' AND r.usuario_id = ?)
                     OR (a.entidade_tipo = 'requisicao'        AND r.tipo = 'interna' AND u.perfil = 'gestor'
                         AND r.filial_id = (SELECT filial_id FROM usuarios WHERE id = ?))
                     OR (a.entidade_tipo = 'requisicao_brinde' AND rb.criado_por = ?)
                   ){$this->nivelAtualSql()}",
                [$usuarioId, $usuarioId, $usuarioId, $usuarioId]
            );

            return (int)($row[0]['total'] ?? 0);
        }

        if (in_array($perfil, $perfisAdmin)) {
            // Diretor não vê a verificação do gestor (salvo setor sem gestor); admin vê tudo.
            $escopoDiretor = $perfil === 'diretor' ? $this->escopoDiretorSql() : '';
            $row = $this->query(
                "SELECT COUNT(*) AS total
                 FROM aprovacoes a
                 LEFT JOIN requisicoes r         ON r.id  = a.entidade_id AND a.entidade_tipo = 'requisicao'
                 LEFT JOIN usuarios u            ON u.id  = r.usuario_id
                 LEFT JOIN requisicoes_brinde rb ON rb.id = a.entidade_id AND a.entidade_tipo = 'requisicao_brinde'
                 LEFT JOIN usuarios u3           ON u3.id = rb.criado_por
                 WHERE a.status = 'pendente'
                   AND (a.atribuido_para IS NULL OR a.atribuido_para = ?)
                   AND NOT (a.entidade_tipo = 'requisicao'        AND r.tipo = 'interna' AND u.perfil  = 'almoxarife')
                   AND NOT (a.entidade_tipo = 'requisicao'        AND r.tipo = 'interna' AND u.perfil  = 'gestor')
                   AND NOT (a.entidade_tipo = 'requisicao_brinde' AND u3.perfil = 'almoxarife'){$this->nivelAtualSql()}{$escopoDiretor}",
                [$usuarioId]
            );
        } else {
            [$escopoSql, $escopoParams] = $this->escopoGestor($perfil, $usuarioId);

            $row = $this->query(
                "SELECT COUNT(*) AS total
                 FROM aprovacoes a
                 LEFT JOIN requisicoes r         ON r.id  = a.entidade_id AND a.entidade_tipo = 'requisicao'
                 LEFT JOIN usuarios u            ON u.id  = r.usuario_id
                 LEFT JOIN requisicoes_brinde rb ON rb.id = a.entidade_id AND a.entidade_tipo = 'requisicao_brinde'
                 LEFT JOIN usuarios u3           ON u3.id = rb.criado_por
                 WHERE a.status = 'pendente'
                   AND (a.atribuido_para IS NULL OR a.atribuido_para = ?)
                   AND (
                        {$this->alcadaDoNivelSql('?')}
                     OR (a.entidade_tipo = 'requisicao' AND r.tipo = 'interna' AND u.perfil = 'gestor' AND r.usuario_id = ?)
                   ){$escopoSql}
                   AND NOT (a.entidade_tipo = 'requisicao'        AND r.tipo = 'interna' AND u.perfil  = 'almoxarife')
                   AND NOT (a.entidade_tipo = 'requisicao_brinde' AND u3.perfil = 'almoxarife'){$this->nivelAtualSql()}",
                [$usuarioId, $perfil, $usuarioId, ...$escopoParams]
            );
        }

        return (int)($row[0]['total'] ?? 0);
    }

    public function criarParaRequisicao(string $reqId, float $valor): void
    {
        // Evita duplicatas caso seja chamado mais de uma vez para a mesma requisição
        $jaExiste = $this->query(
            "SELECT id FROM aprovacoes WHERE entidade_tipo = 'requisicao' AND entidade_id = ? LIMIT 1",
            [$reqId]
        );
        if (!empty($jaExiste)) {
            return;
        }

        // Requisição Interna (movimentação de material, não compra) criada por um
        // Gestor: pula a cadeia de alçada por valor (feita para compras) e cria uma
        // única aprovação de nível 1, resolvida pelo próprio Gestor ou pelo
        // Almoxarife da mesma filial — ver pendentesParaUsuario().
        $req = $this->queryOne(
            "SELECT r.tipo, u.perfil AS solicitante_perfil
             FROM requisicoes r JOIN usuarios u ON u.id = r.usuario_id
             WHERE r.id = ?",
            [$reqId]
        );
        if ($req && $req['tipo'] === 'interna' && $req['solicitante_perfil'] === 'gestor') {
            $this->create([
                'entidade_tipo'  => 'requisicao',
                'entidade_id'    => $reqId,
                'nivel'          => 1,
                'status'         => 'pendente',
                'atribuido_para' => null,
            ]);
            return;
        }

        $alcadas = $this->query(
            "SELECT * FROM alcadas
             WHERE ativo = 1 AND valor_min <= ? AND (valor_max IS NULL OR valor_max >= ?)
             ORDER BY nivel",
            [$valor, $valor]
        );

        if (!empty($alcadas)) {
            foreach ($alcadas as $alcada) {
                $this->create([
                    'entidade_tipo'  => 'requisicao',
                    'entidade_id'    => $reqId,
                    'nivel'          => $alcada['nivel'],
                    'status'         => 'pendente',
                    'atribuido_para' => null,
                ]);
            }
        } else {
            // Nenhuma alçada configurada para este valor — cria aprovação de nível 1
            // visível para qualquer admin/diretor (atribuido_para = NULL)
            $this->create([
                'entidade_tipo'  => 'requisicao',
                'entidade_id'    => $reqId,
                'nivel'          => 1,
                'status'         => 'pendente',
                'atribuido_para' => null,
            ]);
        }
    }

    /** @return string[] ids das alçadas ativas que valem para o valor (ordenados) */
    private function alcadasPara(float $valor): array
    {
        $ids = array_column($this->query(
            "SELECT id FROM alcadas WHERE ativo = 1 AND valor_min <= ? AND (valor_max IS NULL OR valor_max >= ?)",
            [$valor, $valor]
        ), 'id');
        sort($ids);
        return $ids;
    }

    /**
     * Política COMP-001 + decisão de 27/10/2026: a alçada vale sobre o valor
     * estimado da requisição, mas se o valor cotado subir para uma faixa com
     * outras alçadas (ex.: estimado R$ 900, cotado R$ 1.400), a OC volta para
     * aprovação antes de valer. Cotação mais barata não reaprova.
     */
    public function exigeReaprovacao(float $estimado, float $cotado): bool
    {
        return $cotado > $estimado && $this->alcadasPara($cotado) !== $this->alcadasPara($estimado);
    }

    public function criarParaOrdemCompra(string $ocId, float $valor): void
    {
        $jaExiste = $this->query(
            "SELECT id FROM aprovacoes WHERE entidade_tipo = 'ordem_compra' AND entidade_id = ? LIMIT 1",
            [$ocId]
        );
        if (!empty($jaExiste)) return;

        $niveis = array_unique(array_column($this->query(
            "SELECT nivel FROM alcadas
             WHERE ativo = 1 AND valor_min <= ? AND (valor_max IS NULL OR valor_max >= ?)
             ORDER BY nivel",
            [$valor, $valor]
        ), 'nivel')) ?: [1];

        foreach ($niveis as $nivel) {
            $this->create([
                'entidade_tipo'  => 'ordem_compra',
                'entidade_id'    => $ocId,
                'nivel'          => $nivel,
                'status'         => 'pendente',
                'atribuido_para' => null,
            ]);
        }
    }

    public function criarParaRequisicaoBrinde(string $reqId, float $valor): void
    {
        $jaExiste = $this->query(
            "SELECT id FROM aprovacoes WHERE entidade_tipo = 'requisicao_brinde' AND entidade_id = ? LIMIT 1",
            [$reqId]
        );
        if (!empty($jaExiste)) {
            return;
        }

        $alcadas = $this->query(
            "SELECT * FROM alcadas
             WHERE ativo = 1 AND valor_min <= ? AND (valor_max IS NULL OR valor_max >= ?)
             ORDER BY nivel",
            [$valor, $valor]
        );

        if (!empty($alcadas)) {
            foreach ($alcadas as $alcada) {
                $this->create([
                    'entidade_tipo'  => 'requisicao_brinde',
                    'entidade_id'    => $reqId,
                    'nivel'          => $alcada['nivel'],
                    'status'         => 'pendente',
                    'atribuido_para' => null,
                ]);
            }
        } else {
            $this->create([
                'entidade_tipo'  => 'requisicao_brinde',
                'entidade_id'    => $reqId,
                'nivel'          => 1,
                'status'         => 'pendente',
                'atribuido_para' => null,
            ]);
        }
    }

    public function aprovadasParaUsuario(string $usuarioId, string $perfil): array
    {
        $perfisAdmin = ['admin', 'diretor'];

        if (in_array($perfil, $perfisAdmin)) {
            return $this->query(
                "SELECT a.*,
                        CASE a.entidade_tipo
                            WHEN 'requisicao'   THEN r.valor_total
                            WHEN 'ordem_compra' THEN oc.valor_total
                            ELSE 0
                        END AS valor,
                        CASE a.entidade_tipo
                            WHEN 'requisicao'   THEN u.nome
                            WHEN 'ordem_compra' THEN u2.nome
                            ELSE '—'
                        END AS solicitante_nome,
                        ua.nome AS aprovado_por_nome
                 FROM aprovacoes a
                 LEFT JOIN requisicoes r    ON r.id  = a.entidade_id AND a.entidade_tipo = 'requisicao'
                 LEFT JOIN usuarios u       ON u.id  = r.usuario_id
                 LEFT JOIN ordens_compra oc ON oc.id = a.entidade_id AND a.entidade_tipo = 'ordem_compra'
                 LEFT JOIN usuarios u2      ON u2.id = oc.criado_por
                 LEFT JOIN usuarios ua      ON ua.id = a.aprovado_por
                 WHERE a.status = 'aprovado'
                   AND (a.atribuido_para IS NULL OR a.atribuido_para = ?)
                 ORDER BY a.resolvido_em DESC
                 LIMIT 50",
                [$usuarioId]
            );
        }

        [$escopoSql, $escopoParams] = $this->escopoGestor($perfil, $usuarioId);

        return $this->query(
            "SELECT a.*,
                    CASE a.entidade_tipo
                        WHEN 'requisicao'   THEN r.valor_total
                        WHEN 'ordem_compra' THEN oc.valor_total
                        ELSE 0
                    END AS valor,
                    CASE a.entidade_tipo
                        WHEN 'requisicao'   THEN u.nome
                        WHEN 'ordem_compra' THEN u2.nome
                        ELSE '—'
                    END AS solicitante_nome,
                    ua.nome AS aprovado_por_nome
             FROM aprovacoes a
             LEFT JOIN requisicoes r    ON r.id  = a.entidade_id AND a.entidade_tipo = 'requisicao'
             LEFT JOIN usuarios u       ON u.id  = r.usuario_id
             LEFT JOIN ordens_compra oc ON oc.id = a.entidade_id AND a.entidade_tipo = 'ordem_compra'
             LEFT JOIN usuarios u2      ON u2.id = oc.criado_por
             LEFT JOIN usuarios ua      ON ua.id = a.aprovado_por
             WHERE a.status = 'aprovado'
               AND (a.atribuido_para IS NULL OR a.atribuido_para = ?)
               AND EXISTS (SELECT 1 FROM alcadas al
                           WHERE al.perfil = ? AND al.ativo = 1 AND al.nivel = a.nivel){$escopoSql}
             ORDER BY a.resolvido_em DESC
             LIMIT 50",
            [$usuarioId, $perfil, ...$escopoParams]
        );
    }

    public function historicoParaUsuario(string $usuarioId, string $perfil): array
    {
        $perfisAdmin   = ['admin', 'diretor'];
        $perfisUnidade = ['gestor', 'almoxarife'];

        // Gestor e Almoxarife veem o histórico de aprovações (requisições) de
        // toda a própria filial+setor — mesmo escopo já usado na listagem de
        // Requisições (RequisicaoController::escopoFiltrosUsuario). Requisição
        // de brinde continua restrita ao que o próprio usuário criou.
        if (in_array($perfil, $perfisUnidade, true)) {
            // Gestor: escopo vem de gestores_setor, e passa a valer também para o
            // histórico de brindes (por unidade). Almoxarife: continua na própria
            // lotação, vendo só os brindes que ele mesmo criou.
            if ($perfil === 'gestor') {
                [$escopoSql, $paramsEscopo] = $this->escopoGestor($perfil, $usuarioId);
                $condEscopo = '1=1' . $escopoSql;
            } else {
                $condEscopo = "(
                        (a.entidade_tipo = 'requisicao'
                         AND r.filial_id = (SELECT filial_id FROM usuarios WHERE id = ?)
                         AND r.setor_id  = (SELECT setor_id  FROM usuarios WHERE id = ?))
                     OR (a.entidade_tipo = 'requisicao_brinde' AND rb.criado_por = ?)
                    )";
                $paramsEscopo = [$usuarioId, $usuarioId, $usuarioId];
            }

            return $this->query(
                "SELECT a.*,
                        CASE a.entidade_tipo
                            WHEN 'requisicao'        THEN r.valor_total
                            WHEN 'requisicao_brinde' THEN rb.valor_total
                            ELSE 0
                        END AS valor,
                        CASE a.entidade_tipo
                            WHEN 'requisicao'        THEN u.nome
                            WHEN 'requisicao_brinde' THEN u3.nome
                            ELSE '—'
                        END AS solicitante_nome,
                        CASE a.entidade_tipo
                            WHEN 'requisicao' THEN r.numero
                            ELSE NULL
                        END AS req_numero,
                        r.criado_em AS req_criado_em,
                        r.tipo AS req_tipo,
                        rb.numero AS brinde_numero,
                        rb.criado_em AS brinde_criado_em,
                        ua.nome AS aprovado_por_nome
                 FROM aprovacoes a
                 LEFT JOIN requisicoes r         ON r.id  = a.entidade_id AND a.entidade_tipo = 'requisicao'
                 LEFT JOIN usuarios u            ON u.id  = r.usuario_id
                 LEFT JOIN requisicoes_brinde rb ON rb.id = a.entidade_id AND a.entidade_tipo = 'requisicao_brinde'
                 LEFT JOIN usuarios u3           ON u3.id = rb.criado_por
                 LEFT JOIN usuarios ua           ON ua.id = a.aprovado_por
                 WHERE a.status IN ('aprovado', 'rejeitado')
                   AND {$condEscopo}
                 ORDER BY a.resolvido_em DESC
                 LIMIT 100",
                $paramsEscopo
            );
        }

        if (in_array($perfil, $perfisAdmin)) {
            return $this->query(
                "SELECT a.*,
                        CASE a.entidade_tipo
                            WHEN 'requisicao'        THEN r.valor_total
                            WHEN 'ordem_compra'      THEN oc.valor_total
                            WHEN 'requisicao_brinde' THEN rb.valor_total
                            ELSE 0
                        END AS valor,
                        CASE a.entidade_tipo
                            WHEN 'requisicao'        THEN u.nome
                            WHEN 'ordem_compra'      THEN u2.nome
                            WHEN 'requisicao_brinde' THEN u3.nome
                            ELSE '—'
                        END AS solicitante_nome,
                        CASE a.entidade_tipo
                            WHEN 'requisicao' THEN r.numero
                            ELSE NULL
                        END AS req_numero,
                        oc.numero_oc AS oc_numero,
                        r.criado_em AS req_criado_em,
                        r.tipo AS req_tipo,
                        rb.numero AS brinde_numero,
                        rb.criado_em AS brinde_criado_em,
                        ua.nome AS aprovado_por_nome
                 FROM aprovacoes a
                 LEFT JOIN requisicoes r        ON r.id  = a.entidade_id AND a.entidade_tipo = 'requisicao'
                 LEFT JOIN usuarios u           ON u.id  = r.usuario_id
                 LEFT JOIN ordens_compra oc     ON oc.id = a.entidade_id AND a.entidade_tipo = 'ordem_compra'
                 LEFT JOIN usuarios u2          ON u2.id = oc.criado_por
                 LEFT JOIN requisicoes_brinde rb ON rb.id = a.entidade_id AND a.entidade_tipo = 'requisicao_brinde'
                 LEFT JOIN usuarios u3          ON u3.id = rb.criado_por
                 LEFT JOIN usuarios ua          ON ua.id = a.aprovado_por
                 WHERE a.status IN ('aprovado', 'rejeitado')
                   AND (a.atribuido_para IS NULL OR a.atribuido_para = ?)
                 ORDER BY a.resolvido_em DESC
                 LIMIT 100",
                [$usuarioId]
            );
        }

        return $this->query(
            "SELECT a.*,
                    CASE a.entidade_tipo
                        WHEN 'requisicao'        THEN r.valor_total
                        WHEN 'ordem_compra'      THEN oc.valor_total
                        WHEN 'requisicao_brinde' THEN rb.valor_total
                        ELSE 0
                    END AS valor,
                    CASE a.entidade_tipo
                        WHEN 'requisicao'        THEN u.nome
                        WHEN 'ordem_compra'      THEN u2.nome
                        WHEN 'requisicao_brinde' THEN u3.nome
                        ELSE '—'
                    END AS solicitante_nome,
                    CASE a.entidade_tipo
                        WHEN 'requisicao' THEN r.numero
                        ELSE NULL
                    END AS req_numero,
                    oc.numero_oc AS oc_numero,
                    r.criado_em AS req_criado_em,
                    r.tipo AS req_tipo,
                    rb.numero AS brinde_numero,
                    rb.criado_em AS brinde_criado_em,
                    ua.nome AS aprovado_por_nome
             FROM aprovacoes a
             LEFT JOIN requisicoes r        ON r.id  = a.entidade_id AND a.entidade_tipo = 'requisicao'
             LEFT JOIN usuarios u           ON u.id  = r.usuario_id
             LEFT JOIN ordens_compra oc     ON oc.id = a.entidade_id AND a.entidade_tipo = 'ordem_compra'
             LEFT JOIN usuarios u2          ON u2.id = oc.criado_por
             LEFT JOIN requisicoes_brinde rb ON rb.id = a.entidade_id AND a.entidade_tipo = 'requisicao_brinde'
             LEFT JOIN usuarios u3          ON u3.id = rb.criado_por
             LEFT JOIN usuarios ua          ON ua.id = a.aprovado_por
             WHERE a.status IN ('aprovado', 'rejeitado')
               AND (a.atribuido_para IS NULL OR a.atribuido_para = ?)
               AND EXISTS (SELECT 1 FROM alcadas al
                           WHERE al.perfil = ? AND al.ativo = 1 AND al.nivel = a.nivel)
             ORDER BY a.resolvido_em DESC
             LIMIT 100",
            [$usuarioId, $perfil]
        );
    }
}
