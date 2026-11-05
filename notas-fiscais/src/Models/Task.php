<?php
// @versao Tar_03 V_14_29/09/2026
declare(strict_types=1);

/**
 * Model do módulo Minhas Tarefas.
 *
 * Gerencia a fila de aprovação do usuário logado.
 * Sem ORM — apenas PDO com prepared statements.
 */
class Task
{
    // ─────────────────────────────────────────
    // Listagem
    // ─────────────────────────────────────────

    /**
     * Retorna todas as tarefas pendentes de um usuário
     * (pessoais + de grupos nos quais é membro).
     *
     * @return array<int, array>
     */
    /**
     * Recorte por loja da fila de um usuário.
     *
     * O escopo é o DAQUELE usuário (não o da sessão): a mesma consulta serve
     * "Minhas Tarefas" e a "Fila por pessoa", onde um gestor olha a fila de
     * outra pessoa — e ali o que vale é o que ELA enxerga.
     *
     * Devolve trecho pronto para concatenar (sem placeholders) ou string vazia
     * quando não há restrição. Em CLI, sem o helper carregado, não recorta.
     */
    private static function escopoFilialSql(string $usuarioId, string $alias = 'p'): string
    {
        if (!class_exists('FilialScope')) {
            return '';
        }
        [$cond] = FilialScope::sqlRestringe("{$alias}.filial_doc", $usuarioId);
        return $cond !== '' ? " AND {$cond}" : '';
    }

    public static function listByUsuario(string $usuarioId): array
    {
        $pdo = Database::getInstance();

        // Escopo por loja: a fila de aprovação seguia mostrando processo de loja
        // fora do escopo quando a pessoa era membro do grupo aprovador. Ser
        // aprovador não amplia o que se enxerga — o recorte vale aqui também.
        $escopo = self::escopoFilialSql($usuarioId);

        $sql = "
            SELECT
                p.id            AS processo_id,
                p.codigo,
                p.fornecedor_nome,
                p.fornecedor_doc,
                p.numero_nf,
                p.valor_nf,
                p.data_nf,
                p.etapa_atual,
                p.status,
                p.is_teste,
                p.criado_em,
                pe.id           AS etapa_id,
                pe.nome_etapa,
                pe.tipo_aprovador,
                pe.aprovador_id,
                f.nome          AS filial_nome,
                tv.id           AS versao_id,
                tv.setor_id,
                tv.etapas       AS etapas_json,
                s.nome          AS setor_nome,
                t.nome          AS template_nome,
                g.nome          AS grupo_nome,
                tn.valor        AS tipo_nota_nome,
                (SELECT MIN(pa.vencimento)
                 FROM nf_parcelas pa
                 WHERE pa.processo_id = p.id
                   AND pa.deletado_em IS NULL) AS primeiro_vencimento,
                -- Entrada na etapa: atuado_em da etapa anterior (ou criado_em se etapa 1)
                COALESCE(
                    (SELECT pe2.atuado_em
                     FROM nf_processo_etapas pe2
                     WHERE pe2.processo_id = p.id
                       AND pe2.numero_etapa = p.etapa_atual - 1
                     LIMIT 1),
                    p.criado_em
                ) AS entrada_etapa
            FROM nf_processos p
            JOIN nf_processo_etapas pe
                ON pe.processo_id    = p.id
                AND pe.numero_etapa  = p.etapa_atual
                AND pe.status        = 'pendente'
            JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
            JOIN nf_templates t         ON t.id  = tv.template_id
            LEFT JOIN filiais f         ON f.id  = p.filial_id
            LEFT JOIN setores s         ON s.id  = tv.setor_id
            LEFT JOIN nf_grupos g       ON g.id  = pe.aprovador_id AND pe.tipo_aprovador = 'grupo'
            LEFT JOIN nf_lista_itens tn ON tn.id  = tv.tipo_nota_id
            WHERE p.deletado_em IS NULL
              AND p.status IN ('aberto', 'devolvido')
              AND (
                  (pe.tipo_aprovador = 'usuario' AND pe.aprovador_id = :usuario_id)
                  OR
                  (pe.tipo_aprovador = 'grupo' AND pe.aprovador_id IN (
                      SELECT gm.grupo_id
                      FROM nf_grupo_membros gm
                      WHERE gm.usuario_id = :usuario_id2
                  ))
              )
              {$escopo}
            ORDER BY (primeiro_vencimento IS NULL), primeiro_vencimento ASC, p.criado_em ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':usuario_id'  => $usuarioId,
            ':usuario_id2' => $usuarioId,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enriquecer cada tarefa com dados de SLA
        require_once __DIR__ . '/../Helpers/SlaCalculator.php';
        foreach ($rows as &$row) {
            $slaHoras = SlaCalculator::extrairSlaHoras(
                $row['etapas_json'] ?? '[]',
                (int) $row['etapa_atual']
            );
            $row['sla'] = SlaCalculator::classificar(
                $row['entrada_etapa'],
                $slaHoras
            );
        }

        return $rows;
    }

    /**
     * Calcula estatísticas a partir da lista de tarefas já carregada.
     *
     * @param array $tarefas Resultado de listByUsuario()
     * @return array{total: int, pessoais: int, grupo: int, aguardando_muito: int}
     */
    public static function getStats(array $tarefas): array
    {
        $stats = [
            'total'       => 0,
            'pessoais'    => 0,
            'grupo'       => 0,
            'devolvidos'  => 0,
            'sla_ok'      => 0,
            'sla_atencao' => 0,
            'sla_vencido' => 0,
            'sem_sla'     => 0,
        ];

        foreach ($tarefas as $t) {
            $stats['total']++;

            if ($t['tipo_aprovador'] === 'usuario') {
                $stats['pessoais']++;
            } else {
                $stats['grupo']++;
            }

            // Devolvidos atravessa as duas filas de propósito: o card soma o que
            // voltou, esteja a tarefa comigo ou no grupo.
            if (($t['status'] ?? '') === 'devolvido') {
                $stats['devolvidos']++;
            }

            $situacao = $t['sla']['situacao'] ?? SlaCalculator::SEM_SLA;
            match ($situacao) {
                SlaCalculator::OK      => $stats['sla_ok']++,
                SlaCalculator::ATENCAO => $stats['sla_atencao']++,
                SlaCalculator::VENCIDO => $stats['sla_vencido']++,
                default                => $stats['sem_sla']++,
            };
        }

        return $stats;
    }

    // ─────────────────────────────────────────
    // Segurança
    // ─────────────────────────────────────────

    /**
     * Retorna a etapa somente se o usuário é o aprovador autorizado
     * (pessoal ou via grupo). Usado para autorizar aprovar/devolver.
     *
     * @return array|null
     */
    public static function findEtapaAutorizada(string $etapaId, string $usuarioId): ?array
    {
        $pdo = Database::getInstance();

        // O mesmo recorte da listagem — senão a pessoa não veria o processo na
        // fila mas ainda conseguiria aprová-lo por um POST direto.
        $escopo = self::escopoFilialSql($usuarioId);

        $sql = "
            SELECT pe.*
            FROM nf_processo_etapas pe
            JOIN nf_processos p ON p.id = pe.processo_id
            WHERE pe.id     = :etapa_id
              AND pe.status = 'pendente'
              AND p.status  IN ('aberto', 'devolvido')
              AND p.deletado_em IS NULL
              AND pe.numero_etapa = p.etapa_atual
              AND (
                  (pe.tipo_aprovador = 'usuario' AND pe.aprovador_id = :usuario_id)
                  OR
                  (pe.tipo_aprovador = 'grupo' AND pe.aprovador_id IN (
                      SELECT gm.grupo_id
                      FROM nf_grupo_membros gm
                      WHERE gm.usuario_id = :usuario_id2
                  ))
              )
              {$escopo}
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':etapa_id'    => $etapaId,
            ':usuario_id'  => $usuarioId,
            ':usuario_id2' => $usuarioId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ─────────────────────────────────────────
    // Ações
    // ─────────────────────────────────────────

    /**
     * Motivo da última reprovação de aprovarEtapa() — o controller usa para
     * mostrar ao usuário POR QUE a etapa não avançou (falta anexo, falta campo
     * obrigatório da etapa). Sem isso a fila só diria "tente novamente", e o
     * aprovador não teria como descobrir o que falta.
     */
    public static ?string $ultimoErro = null;

    /**
     * Aprova a etapa e avança o processo para a próxima etapa (ou finaliza).
     */
    public static function aprovarEtapa(
        string  $etapaId,
        string  $processoId,
        string  $usuarioId,
        ?string $observacao = null
    ): bool {
        $pdo = Database::getInstance();
        self::$ultimoErro = null;

        require_once __DIR__ . '/Process.php';

        // Gates do template — os mesmos de Process::aprovar(). Aprovar pela tela
        // do processo e aprovar pela fila são dois caminhos, e os dois têm de
        // cobrar as mesmas exigências: senão a fila vira o atalho por onde o
        // processo avança sem o anexo ou sem o campo que a etapa pedia.
        $stmtNum = $pdo->prepare("SELECT etapa_atual FROM nf_processos WHERE id = ? LIMIT 1");
        $stmtNum->execute([$processoId]);
        $numAtual = (int) ($stmtNum->fetchColumn() ?: 1);

        $faltaAnexo = Process::anexosPendentesDaEtapa($processoId, $numAtual);
        if (!empty($faltaAnexo)) {
            self::$ultimoErro = 'Esta etapa exige o(s) anexo(s): "' . implode('", "', $faltaAnexo)
                . '". Anexe o(s) documento(s) na tela do processo antes de aprovar.';
            return false;
        }

        $faltaCampo = Process::camposPendentesDaEtapa($processoId, $numAtual);
        if (!empty($faltaCampo)) {
            self::$ultimoErro = 'Esta etapa exige o preenchimento de: "' . implode('", "', $faltaCampo)
                . '". Use "Preencher dados desta etapa" na tela do processo antes de aprovar.';
            return false;
        }

        if ($numAtual === 1) {
            $faltaAbertura = Process::dadosAberturaPendentes($processoId);
            if (!empty($faltaAbertura)) {
                self::$ultimoErro = 'Complete os dados do processo antes de avançar: '
                    . implode(' · ', $faltaAbertura) . '.';
                return false;
            }
        }

        // Fluxo com etapa de DECISÃO ou de arquivamento à frente: o avanço daqui
        // é linear (pega a próxima etapa não pulada e para nela) e não sabe
        // resolver salto nem concluir etapa automática — o processo empacaria
        // numa etapa que ninguém aprova. Process::aprovar() sabe, então é ele
        // quem conduz. Template sem essas etapas segue pelo caminho de sempre.
        if (self::exigeAvancoCompleto($pdo, $processoId, $numAtual)) {
            $stmtNome = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ? LIMIT 1");
            $stmtNome->execute([$usuarioId]);
            $usuarioNome = (string) ($stmtNome->fetchColumn() ?: '—');

            try {
                return Process::aprovar($processoId, $etapaId, $usuarioId, $usuarioNome);
            } catch (\Throwable $e) {
                self::$ultimoErro = $e->getMessage();
                error_log('[Task::aprovarEtapa/delegado] ' . $e->getMessage());
                return false;
            }
        }

        try {
            $pdo->beginTransaction();

            $agora = date('Y-m-d H:i:s');

            // 1. Marca etapa atual como aprovada
            $stmt = $pdo->prepare("
                UPDATE nf_processo_etapas
                SET status      = 'aprovado',
                    atuado_por  = :usuario_id,
                    atuado_em   = :agora
                WHERE id = :etapa_id
                  AND status = 'pendente'
            ");
            $stmt->execute([
                ':usuario_id' => $usuarioId,
                ':agora'      => $agora,
                ':etapa_id'   => $etapaId,
            ]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                return false;
            }

            // 2. Captura dados do processo para histórico e notificações
            $stmtProc = $pdo->prepare("SELECT criado_por, codigo, etapa_atual FROM nf_processos WHERE id = ? LIMIT 1");
            $stmtProc->execute([$processoId]);
            $proc       = $stmtProc->fetch(PDO::FETCH_ASSOC);
            $criadoPor  = $proc['criado_por']  ?? null;
            $codigo     = $proc['codigo']      ?? '';
            $etapaAtual = (int)($proc['etapa_atual'] ?? 1);

            // 2b. Buscar nome do usuário para histórico
            $stmtNome = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ? LIMIT 1");
            $stmtNome->execute([$usuarioId]);
            $usuarioNome = $stmtNome->fetchColumn() ?: '—';

            // 2c. Registrar histórico (dentro da transação)
            $stmtHist = $pdo->prepare(
                "INSERT INTO nf_processo_historico
                    (id, processo_id, acao, numero_etapa, ator_id, ator_nome, detalhe, criado_em)
                 VALUES (?, ?, 'etapa_aprovada', ?, ?, ?, ?, NOW())"
            );
            $detalheAprov = "Etapa {$etapaAtual} aprovada por {$usuarioNome}.";
            $stmtHist->execute([self::gerarUuid(), $processoId, $etapaAtual, $usuarioId, $usuarioNome, $detalheAprov]);

            // 3. Verifica se existe próxima etapa.
            //    O caminho de volta de uma devolução tem de passar por TODAS as
            //    etapas de novo, na ordem — inclusive a que devolveu. Por isso a
            //    busca é por número de etapa, não por status: filtrar
            //    `status = 'pendente'` fazia o fluxo saltar por cima da etapa
            //    devolvida (que fica com status 'devolvido') e o processo seguia
            //    sem nunca voltar para quem pediu a correção.
            //    Só a etapa de observação ('pulado') continua sendo ultrapassada:
            //    quem só observa não aprova.
            $stmtProx = $pdo->prepare("
                SELECT pe.id, pe.numero_etapa, pe.tipo_aprovador, pe.aprovador_id, pe.nome_etapa, pe.status
                FROM nf_processo_etapas pe
                JOIN nf_processos p ON p.id = pe.processo_id
                WHERE pe.processo_id  = :processo_id
                  AND pe.numero_etapa > p.etapa_atual
                  AND pe.status      <> 'pulado'
                ORDER BY pe.numero_etapa ASC
                LIMIT 1
            ");
            $stmtProx->execute([':processo_id' => $processoId]);
            $proxEtapa = $stmtProx->fetch(PDO::FETCH_ASSOC);

            // Mesmo gancho de Process::aprovar(): aprovar pela tela do processo
            // e aprovar pela fila de tarefas são DOIS caminhos diferentes, e os
            // dois têm de concluir igual. Só grava aqui — o HTTP sai depois do
            // commit, no fim deste método.
            $assinaturaId = null;
            if ($proxEtapa !== false) {
                require_once __DIR__ . '/Etapa.php';
                if (Etapa::ehAssinatura($proxEtapa)) {
                    require_once __DIR__ . '/Assinatura.php';
                    $assinaturaId = Assinatura::registrarPendente(
                        $pdo, $processoId, (int) $proxEtapa['numero_etapa'], $usuarioId
                    );
                }
            }

            if ($proxEtapa !== false) {
                // Etapa que havia devolvido volta a ser acionável ao ser
                // alcançada de novo — senão ninguém a veria na fila.
                if (($proxEtapa['status'] ?? '') !== 'pendente') {
                    // Só o status volta — quem devolveu continua registrado.
                    $stmtResetProx = $pdo->prepare(
                        "UPDATE nf_processo_etapas
                            SET status = 'pendente'
                          WHERE id = ?"
                    );
                    $stmtResetProx->execute([$proxEtapa['id']]);
                }

                // `status = 'aberto'` junto com o avanço: sem isso um processo
                // que passou por devolução seguia o fluxo inteiro ainda
                // carimbado como "devolvido".
                $stmtUpd = $pdo->prepare("
                    UPDATE nf_processos
                    SET etapa_atual = :prox_etapa, status = 'aberto'
                    WHERE id = :processo_id
                ");
                $stmtUpd->execute([
                    ':prox_etapa'  => (int) $proxEtapa['numero_etapa'],
                    ':processo_id' => $processoId,
                ]);

                // Etapa de assinatura fica em estado próprio: não é tarefa de
                // ninguém, quem a conclui é o retorno do provedor.
                if ($assinaturaId !== null) {
                    $pdo->prepare(
                        "UPDATE nf_processo_etapas SET status = 'aguardando_assinatura' WHERE id = ?"
                    )->execute([$proxEtapa['id']]);
                }

                // Histórico — avanço
                $nomeProx = $proxEtapa['nome_etapa'] ?? ('Etapa ' . $proxEtapa['numero_etapa']);
                $stmtHistAv = $pdo->prepare(
                    "INSERT INTO nf_processo_historico (id, processo_id, acao, numero_etapa, ator_id, ator_nome, detalhe, criado_em)
                     VALUES (?, ?, 'etapa_avancada', ?, ?, ?, ?, NOW())"
                );
                $stmtHistAv->execute([
                    self::gerarUuid(), $processoId, (int) $proxEtapa['numero_etapa'], $usuarioId, $usuarioNome,
                    $assinaturaId !== null
                        ? "Encaminhado para {$nomeProx} — aguardando os signatários."
                        : "Encaminhado para {$nomeProx}.",
                ]);

            } else {
                $stmtFim = $pdo->prepare("
                    UPDATE nf_processos
                    SET status = 'aprovado', concluido_em = NOW()
                    WHERE id = :processo_id
                ");
                $stmtFim->execute([':processo_id' => $processoId]);

                // Histórico — conclusão
                $stmtHistConc = $pdo->prepare(
                    "INSERT INTO nf_processo_historico (id, processo_id, acao, numero_etapa, ator_id, ator_nome, detalhe, criado_em)
                     VALUES (?, ?, 'processo_aprovado', ?, ?, ?, 'Processo concluído — todas as etapas aprovadas.', NOW())"
                );
                $stmtHistConc->execute([self::gerarUuid(), $processoId, $etapaAtual, $usuarioId, $usuarioNome]);

                // Fluxo de "Solicitação de Acesso Fora do Horário": aprovado no
                // fim, a liberação é gravada sozinha (migration 043). Só age em
                // template com o flag ligado — nenhum outro fluxo sente nada.
                require_once __DIR__ . '/AccessSchedule.php';
                $excecaoId = AccessSchedule::registrarDeProcesso($processoId);

                if ($excecaoId !== null) {
                    // Registra no histórico: sem isso a liberação apareceria do
                    // nada, e ninguém saberia dizer de onde veio.
                    $stmtHistExc = $pdo->prepare(
                        "INSERT INTO nf_processo_historico (id, processo_id, acao, numero_etapa, ator_id, ator_nome, detalhe, criado_em)
                         VALUES (?, ?, 'acesso_liberado', ?, ?, ?, ?, NOW())"
                    );
                    $stmtHistExc->execute([
                        self::gerarUuid(), $processoId, $etapaAtual, $usuarioId, $usuarioNome,
                        'Acesso fora do horário liberado automaticamente para o solicitante, conforme aprovado neste processo.',
                    ]);
                }
            }

            $pdo->commit();

            // Envio da assinatura — pós-commit, como em Process::aprovar().
            // Falhando aqui, a linha fica em 'a_enviar' e o worker reenvia; a
            // aprovação já está gravada e não se perde.
            if ($assinaturaId !== null) {
                try {
                    Assinatura::dispararPendentes(1, $assinaturaId);
                } catch (\Throwable $e) {
                    error_log('[assinatura] disparo pós-commit (tarefa) falhou: ' . $e->getMessage());
                }
            }

            // Notificações (fora da transação — nunca revertidas)
            require_once __DIR__ . '/Notification.php';
            if ($proxEtapa !== false) {
                // Etapa de assinatura não notifica aprovador: quem a conclui é
                // o provedor, não um usuário. Mandar "aguarda sua aprovação"
                // faria o responsável procurar um botão que não existe.
                //
                // A checagem fica AQUI dentro, e não no `if` de cima: movida
                // para a condição externa, uma etapa de assinatura cairia no
                // `elseif ($criadoPor)` e avisaria o solicitante de que o
                // processo foi aprovado — com ele ainda esperando assinatura.
                $tipoAp = $assinaturaId === null ? ($proxEtapa['tipo_aprovador'] ?? 'grupo') : '';
                $apId   = $proxEtapa['aprovador_id'] ?? '';
                if ($tipoAp === 'usuario') {
                    Notification::create($apId, 'nf_tarefa_atribuida',
                        "Processo {$codigo} aguarda sua aprovação",
                        "Um novo processo foi enviado para sua aprovação.",
                        'nf_processo', $processoId
                    );
                } elseif ($tipoAp === 'grupo') {
                    $stmtMem = $pdo->prepare("SELECT usuario_id FROM nf_grupo_membros WHERE grupo_id = ?");
                    $stmtMem->execute([$apId]);
                    foreach ($stmtMem->fetchAll(PDO::FETCH_COLUMN) as $memId) {
                        if ($memId !== $usuarioId) {
                            Notification::create($memId, 'nf_tarefa_atribuida',
                                "Processo {$codigo} aguarda aprovação do seu grupo",
                                "Um novo processo foi enviado para aprovação do seu grupo.",
                                'nf_processo', $processoId
                            );
                        }
                    }
                }
            } elseif ($criadoPor) {
                Notification::create($criadoPor, 'nf_processo_aprovado',
                    "Processo {$codigo} aprovado",
                    "Seu processo foi totalmente aprovado.",
                    'nf_processo', $processoId
                );
            }

            return true;

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('[Task::aprovarEtapa] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Há etapa de decisão, salto, abertura de fluxo ou arquivamento adiante?
     *
     * São as que o avanço simples desta classe não sabe tratar: decisão e
     * "Ir para" precisam saltar, "Abrir Fluxo" precisa criar outro processo, e
     * a de arquivamento precisa concluir em vez de virar tarefa de alguém.
     * Encontrando qualquer uma, quem conduz é Process::aprovar().
     */
    private static function exigeAvancoCompleto(PDO $pdo, string $processoId, int $numAtual): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM nf_processo_etapas pe
          LEFT JOIN nf_etapas ec ON ec.id = pe.etapa_catalogo_id
              WHERE pe.processo_id = ?
                AND pe.numero_etapa > ?
                AND pe.status <> 'pulado'
                AND (pe.decisao_saltos IS NOT NULL
                     OR pe.abre_template_id IS NOT NULL
                     OR pe.tipo_aprovador IN ('decisao', 'arquivo', 'abre_fluxo')
                     OR ec.encerra_automaticamente = 1
                     OR ec.decisao_lista_id IS NOT NULL
                     OR ec.salto_incondicional = 1
                     OR ec.abre_fluxo = 1)"
        );
        $stmt->execute([$processoId, $numAtual]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Devolve a etapa e marca o processo como "devolvido".
     */
    public static function devolverEtapa(
        string $etapaId,
        string $processoId,
        string $usuarioId,
        string $motivo,
        ?string $usuarioDestino = null
    ): bool {
        require_once SRC . '/Models/Process.php';
        $pdo = Database::getInstance();

        try {
            $pdo->beginTransaction();

            $agora = date('Y-m-d H:i:s');

            // 1. Captura dados do processo para histórico e notificações
            $stmtProc = $pdo->prepare("SELECT criado_por, codigo, etapa_atual FROM nf_processos WHERE id = ? LIMIT 1");
            $stmtProc->execute([$processoId]);
            $proc       = $stmtProc->fetch(PDO::FETCH_ASSOC);
            $criadoPor  = $proc['criado_por']  ?? null;
            $codigo     = $proc['codigo']      ?? '';
            $etapaAtualNum = (int)($proc['etapa_atual'] ?? 1);

            // 2. Marca etapa como devolvida
            $stmt = $pdo->prepare("
                UPDATE nf_processo_etapas
                SET status            = 'devolvido',
                    atuado_por        = :usuario_id,
                    atuado_em         = :agora,
                    motivo_devolucao  = :motivo
                WHERE id     = :etapa_id
                  AND status = 'pendente'
            ");
            $stmt->execute([
                ':usuario_id' => $usuarioId,
                ':agora'      => $agora,
                ':motivo'     => $motivo,
                ':etapa_id'   => $etapaId,
            ]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                return false;
            }

            // 3. Buscar etapa_atual do processo
            $stmtEtapa = $pdo->prepare("SELECT etapa_atual FROM nf_processos WHERE id = ? LIMIT 1");
            $stmtEtapa->execute([$processoId]);
            $etapaAtual = (int) $stmtEtapa->fetchColumn();

            // 4. Regredir etapa_atual e marcar processo como devolvido.
            //    Etapa de observação ('pulado') não recebe devolução — quem só
            //    observa não tem como aprovar de volta, o processo travaria ali.
            $stmtAnterior = $pdo->prepare(
                "SELECT numero_etapa FROM nf_processo_etapas
                  WHERE processo_id = ? AND numero_etapa < ? AND status <> 'pulado'
                  ORDER BY numero_etapa DESC LIMIT 1"
            );
            $stmtAnterior->execute([$processoId, $etapaAtual]);
            $anterior = $stmtAnterior->fetchColumn();
            if ($anterior === false) {
                // Sem etapa acionável antes: volta para a primeira que exige
                // ação (mesmo efeito do antigo max(1, etapa - 1) na etapa 1).
                $stmtPrimeira = $pdo->prepare(
                    "SELECT numero_etapa FROM nf_processo_etapas
                      WHERE processo_id = ? AND status <> 'pulado'
                      ORDER BY numero_etapa ASC LIMIT 1"
                );
                $stmtPrimeira->execute([$processoId]);
                $anterior = $stmtPrimeira->fetchColumn() ?: 1;
            }
            $novaEtapa = max(1, (int) $anterior);

            // Destino por PESSOA tem precedência sobre "a etapa anterior".
            // O número vem de pessoasParaDevolucao(), nunca do formulário.
            if ($usuarioDestino !== null && $usuarioDestino !== '') {
                $novaEtapa = Process::etapaDaPessoa($processoId, $usuarioDestino);
            }
            $stmtUpd = $pdo->prepare("
                UPDATE nf_processos
                SET status = 'devolvido', etapa_atual = :nova_etapa
                WHERE id = :processo_id
            ");
            $stmtUpd->execute([':nova_etapa' => $novaEtapa, ':processo_id' => $processoId]);

            // 5. Resetar destino E intermediárias como pendentes — devolver para
            //    uma pessoa lá atrás faz o processo refazer o caminho por todas.
            //    `atuado_por` fica: quem já agiu ali continua registrado.
            //    As de observação ('pulado') ficam como estão.
            $stmtReset = $pdo->prepare("
                UPDATE nf_processo_etapas
                SET status = 'pendente'
                WHERE processo_id = ? AND numero_etapa >= ? AND numero_etapa < ?
                  AND status <> 'pulado'
            ");
            $stmtReset->execute([$processoId, $novaEtapa, $etapaAtual]);

            // Destino é a PRÓPRIA etapa atual (sem etapa anterior acionável —
            // ex.: devolução na etapa 1 pra si mesmo). A faixa do reset acima
            // fica vazia nesse caso — sem isto a etapa ficava presa em
            // 'devolvido' pra sempre, nunca aparecendo em Minhas Tarefas (que
            // só lista pe.status='pendente'). Achado real, PROC-2026-13030,
            // 18/09/2026 — mesmo fix do caminho da tela de detalhe (Process::devolver()).
            if ((int) $novaEtapa === (int) $etapaAtual) {
                $stmtReabrir = $pdo->prepare(
                    "UPDATE nf_processo_etapas SET status = 'pendente' WHERE processo_id = ? AND numero_etapa = ?"
                );
                $stmtReabrir->execute([$processoId, $novaEtapa]);
            }

            // 5b. A etapa de destino passa a ser da pessoa escolhida (grupo → usuário)
            if ($usuarioDestino !== null && $usuarioDestino !== '') {
                $stmtNomeAtor = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ? LIMIT 1");
                $stmtNomeAtor->execute([$usuarioId]);
                Process::direcionarEtapaParaPessoa(
                    $pdo, $processoId, $novaEtapa, $usuarioDestino,
                    $usuarioId, (string) ($stmtNomeAtor->fetchColumn() ?: '—')
                );
            }

            // 6. Registrar histórico (dentro da transação)
            $stmtNome = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ? LIMIT 1");
            $stmtNome->execute([$usuarioId]);
            $usuarioNome = $stmtNome->fetchColumn() ?: '—';

            // Buscar nome da etapa de origem (a que está devolvendo)
            $stmtOrigNome = $pdo->prepare("SELECT nome_etapa FROM nf_processo_etapas WHERE processo_id = ? AND numero_etapa = ? LIMIT 1");
            $stmtOrigNome->execute([$processoId, $etapaAtualNum]);
            $nomeEtapaOrigem = $stmtOrigNome->fetchColumn() ?: ('Etapa ' . $etapaAtualNum);

            $stmtHist = $pdo->prepare(
                "INSERT INTO nf_processo_historico
                    (id, processo_id, acao, numero_etapa, ator_id, ator_nome, detalhe, criado_em)
                 VALUES (?, ?, 'processo_devolvido', ?, ?, ?, ?, NOW())"
            );
            $detalheDevol = "Processo devolvido por {$usuarioNome} na etapa {$nomeEtapaOrigem}. Motivo: {$motivo}";
            $stmtHist->execute([self::gerarUuid(), $processoId, $etapaAtualNum, $usuarioId, $usuarioNome, $detalheDevol]);

            // 7. Histórico — retorno para etapa anterior (com referência à origem e motivo herdado)
            $stmtEtapaNome = $pdo->prepare("SELECT nome_etapa FROM nf_processo_etapas WHERE processo_id = ? AND numero_etapa = ? LIMIT 1");
            $stmtEtapaNome->execute([$processoId, $novaEtapa]);
            $nomeEtapaRetorno = $stmtEtapaNome->fetchColumn() ?: ('Etapa ' . $novaEtapa);

            $stmtHistRet = $pdo->prepare(
                "INSERT INTO nf_processo_historico
                    (id, processo_id, acao, numero_etapa, ator_id, ator_nome, detalhe, criado_em)
                 VALUES (?, ?, 'etapa_reaberta', ?, ?, ?, ?, NOW())"
            );
            $stmtHistRet->execute([self::gerarUuid(), $processoId, $novaEtapa, $usuarioId, $usuarioNome, "Retornou para {$nomeEtapaRetorno} após devolução da etapa {$nomeEtapaOrigem}. Motivo: {$motivo}"]);

            // 8. Motivo também na aba Observações — é lá que a conversa do
            //    processo acontece. Preso só no card da etapa e no histórico,
            //    o pedido de correção passava despercebido por quem recebeu.
            $stmtObs = $pdo->prepare(
                "INSERT INTO nf_processo_observacoes (id, processo_id, autor_id, autor_nome, texto, criado_em)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $stmtObs->execute([
                self::gerarUuid(), $processoId, $usuarioId, $usuarioNome,
                "Devolvido na etapa {$nomeEtapaOrigem}. Motivo: {$motivo}",
            ]);

            $pdo->commit();

            // Notifica solicitante (fora da transação)
            if ($criadoPor) {
                require_once __DIR__ . '/Notification.php';
                Notification::create($criadoPor, 'nf_processo_devolvido',
                    "Processo {$codigo} devolvido",
                    "Seu processo foi devolvido. Motivo: {$motivo}",
                    'nf_processo', $processoId
                );
            }

            return true;

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('[Task::devolverEtapa] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * "Assumir tarefa": converte a etapa atual (de grupo) para o usuário.
     * A etapa deixa de pertencer ao grupo e passa a ser do usuário logado —
     * sai automaticamente da fila dos demais membros. Sobrescrita direta,
     * mesmo mecanismo do Reatribuir manual (o grupo de origem NÃO é guardado).
     *
     * Operação atômica: só converte se a etapa ainda for do grupo e pendente
     * (WHERE tipo_aprovador='grupo' + rowCount), evitando corrida entre dois
     * usuários que cliquem "Assumir" ao mesmo tempo.
     *
     * @return array{ok:bool, motivo?:string}
     */
    public static function assumir(string $etapaId, string $processoId, string $usuarioId): array
    {
        $pdo = Database::getInstance();

        // 1. A etapa precisa ser a etapa atual, pendente, de GRUPO, e o usuário
        //    precisa ser membro desse grupo.
        $stmt = $pdo->prepare("
            SELECT pe.aprovador_id AS grupo_id, pe.numero_etapa, pe.nome_etapa
            FROM nf_processo_etapas pe
            JOIN nf_processos p ON p.id = pe.processo_id
            WHERE pe.id          = :etapa_id
              AND pe.processo_id = :processo_id
              AND pe.status      = 'pendente'
              AND pe.tipo_aprovador = 'grupo'
              AND pe.numero_etapa = p.etapa_atual
              AND p.status IN ('aberto', 'devolvido')
              AND p.deletado_em IS NULL
              AND pe.aprovador_id IN (
                  SELECT gm.grupo_id FROM nf_grupo_membros gm WHERE gm.usuario_id = :usuario_id
              )
            LIMIT 1
        ");
        $stmt->execute([
            ':etapa_id'    => $etapaId,
            ':processo_id' => $processoId,
            ':usuario_id'  => $usuarioId,
        ]);
        $etapa = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$etapa) {
            // Já não é de grupo (outro assumiu), já não é a etapa atual,
            // ou o usuário não pertence ao grupo.
            return ['ok' => false, 'motivo' => 'Esta tarefa não está mais disponível para você assumir (pode já ter sido assumida por outra pessoa).'];
        }

        try {
            $pdo->beginTransaction();

            // 2. Conversão atômica grupo → usuário
            $stmtUpd = $pdo->prepare("
                UPDATE nf_processo_etapas
                SET tipo_aprovador = 'usuario',
                    aprovador_id   = :usuario_id
                WHERE id = :etapa_id
                  AND status = 'pendente'
                  AND tipo_aprovador = 'grupo'
            ");
            $stmtUpd->execute([
                ':usuario_id' => $usuarioId,
                ':etapa_id'   => $etapaId,
            ]);

            if ($stmtUpd->rowCount() === 0) {
                // Alguém assumiu entre o SELECT e o UPDATE
                $pdo->rollBack();
                return ['ok' => false, 'motivo' => 'Esta tarefa acabou de ser assumida por outra pessoa.'];
            }

            // 3. Nome do usuário e do grupo para histórico
            $stmtNome = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ? LIMIT 1");
            $stmtNome->execute([$usuarioId]);
            $usuarioNome = $stmtNome->fetchColumn() ?: '—';

            $stmtGrp = $pdo->prepare("SELECT nome FROM nf_grupos WHERE id = ? LIMIT 1");
            $stmtGrp->execute([$etapa['grupo_id']]);
            $grupoNome = $stmtGrp->fetchColumn() ?: '—';

            // 4. Histórico
            $stmtHist = $pdo->prepare(
                "INSERT INTO nf_processo_historico
                    (id, processo_id, acao, numero_etapa, ator_id, ator_nome, detalhe, criado_em)
                 VALUES (?, ?, 'tarefa_assumida', ?, ?, ?, ?, NOW())"
            );
            $detalhe = "Tarefa assumida por {$usuarioNome} (antes na fila do grupo {$grupoNome}).";
            $stmtHist->execute([
                self::gerarUuid(), $processoId, (int) $etapa['numero_etapa'],
                $usuarioId, $usuarioNome, $detalhe,
            ]);

            $pdo->commit();
            return ['ok' => true];

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('[Task::assumir] ' . $e->getMessage());
            return ['ok' => false, 'motivo' => 'Erro ao assumir a tarefa.'];
        }
    }

    private static function gerarUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}
