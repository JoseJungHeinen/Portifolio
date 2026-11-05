<?php
// @versao Proc_10 V_06_21/10/2026
declare(strict_types=1);

/**
 * Gate central de autorização por PROCESSO específico.
 *
 * Composição, em ordem:
 *   0. Sigilo por template (TemplateSigilo) — template marcado como
 *      confidencial (RH, Jurídico) sem liberação para o usuário derruba o
 *      bypass de admin e o ver_todos; sobra só a participação de fato.
 *   1. Admin — bypass total (Permissions::isAdmin()).
 *   2. Permissão global processos.ver_todos — RBAC já existente
 *      (nf_usuario_permissoes / nf_perfil_permissoes / config/permissions.php),
 *      só que aplicada aqui como "vê qualquer processo".
 *   3. Participação de fato — Process::usuarioParticipaDoProcesso()
 *      (criador OU aprovador direto/por grupo de alguma etapa).
 *   4. Liberação granular pontual — Process::temAcessoGranular()
 *      (nf_template_acessos, ativa — vale pra todos os processos do
 *      mesmo TEMPLATE, não só a instância específica).
 *
 * Se nenhuma condição bater, o acesso é negado e a tentativa é registrada
 * em logs_auditoria via AuditLog — não existe "ocultar na tela" aqui, isso
 * é decidido sempre no backend, antes de qualquer dado do processo ser
 * carregado ou devolvido.
 */
class ProcessAccess
{
    /**
     * Verifica se o usuário autenticado pode acessar o processo — não
     * encerra a execução, apenas retorna o booleano. Use quando precisar
     * decidir algo sem interromper a página (ex.: mostrar/ocultar um botão
     * — mas isso é usabilidade, a checagem de segurança real é autorizar()).
     */
    public static function pode(string $processoId, string $usuarioId): bool
    {
        // Este gate depende do Model Process, que o index.php NÃO carrega.
        // Sem isto, quem chamava pode() antes de incluir o Process (detalhe_ajax,
        // pessoas_devolucao) quebrava com "Class Process not found" — só para
        // quem não é admin nem tem ver_todos, que são os que chegam lá embaixo.
        require_once SRC . '/Models/Process.php';

        // Sigilo por template (RH, Jurídico): sem bypass de admin nem de
        // ver_todos. Quem não tem liberação explícita (usuário ou grupo) só
        // enxerga se participar de fato — senão o aprovador da etapa de RH não
        // conseguiria abrir o processo que ele mesmo precisa aprovar.
        $sigiloOk = self::templateLiberado($processoId, $usuarioId);

        if ($sigiloOk && Permissions::isAdmin()) {
            return true;
        }

        // Etapa atual com visualização restrita (atributo do catálogo de Etapas):
        // enquanto o processo estiver parado nela, SOMENTE os aprovadores da
        // etapa (e admins, acima) podem visualizar — sobrepõe ver_todos,
        // participação e liberações granulares.
        $etapaRestrita = self::etapaAtualRestrita($processoId);
        if ($etapaRestrita !== null) {
            return self::usuarioAtuaNaEtapa($etapaRestrita, $usuarioId);
        }

        // Template confidencial sem liberação: participação de fato é o único
        // caminho — ver_todos e acesso granular por template não valem aqui.
        if (!$sigiloOk) {
            return Process::usuarioParticipaDoProcesso($processoId, $usuarioId);
        }

        if (Permissions::can('processos', 'ver_todos')) {
            return true;
        }
        if (Process::usuarioParticipaDoProcesso($processoId, $usuarioId)) {
            return true;
        }
        return Process::temAcessoGranular($processoId, $usuarioId);
    }

    /**
     * Condição SQL "o usuário logado participa deste processo" — criador,
     * aprovador direto, membro do grupo aprovador, ou com liberação de template
     * (usuário ou grupo). Devolve string vazia quando NÃO há restrição:
     *
     *   • fora do contexto web (CLI de sync/SLA precisa varrer tudo);
     *   • super admin;
     *   • quem tem a permissão explícita `processos.ver_todos`.
     *
     * Sai sem placeholders (id do usuário escapado por PDO::quote) para poder
     * ser concatenada em qualquer query legada sem mexer na ordem dos
     * parâmetros do execute().
     *
     * Esta é a regra geral de visibilidade desde 27/07/2026: processo só
     * aparece para quem está no fluxo. Um processo de Frete não circula pela
     * empresa inteira só porque alguém tem acesso a Relatórios.
     */
    public static function sqlParticipaSessao(string $aliasProcesso): string
    {
        $uid = (string) ($_SESSION['usuario_id'] ?? '');
        if ($uid === '') {
            return '';
        }

        try {
            if (Permissions::isSuperAdmin() || Permissions::can('processos', 'ver_todos')) {
                return '';
            }
        } catch (Throwable $e) {
            return '';
        }

        return self::sqlParticipa($aliasProcesso, $uid);
    }

    /**
     * A regra de participação, sem o atalho de sessão — fonte única para toda
     * consulta que precisa recortar processos por envolvimento.
     *
     * Participa quem: abriu o processo; é aprovador designado de QUALQUER etapa
     * (direto ou pelo grupo); **atuou** em alguma etapa; **registrou ação no
     * histórico**; ou tem liberação do template (usuário ou grupo).
     *
     * Os dois do meio são o que mantém o processo visível do começo ao fim: sem
     * eles, quem aprovou uma etapa e depois teve a etapa reatribuída — ou saiu
     * do grupo aprovador — perdia de vista um processo que ele mesmo autorizou,
     * justamente enquanto ele seguia para o financeiro.
     *
     * Sai sem placeholders (id escapado por PDO::quote) para encaixar em query
     * legada sem mexer na ordem dos parâmetros do execute().
     */
    public static function sqlParticipa(string $aliasProcesso, string $usuarioId): string
    {
        if ($usuarioId === '') {
            return '1 = 0';
        }

        try {
            $pdo = Database::getInstance();
        } catch (Throwable $e) {
            return '1 = 0';
        }

        $u = $pdo->quote($usuarioId);
        $a = $aliasProcesso;

        return "(
            {$a}.criado_por = {$u}
            OR EXISTS (
                SELECT 1 FROM nf_processo_etapas pe_part
                 WHERE pe_part.processo_id = {$a}.id
                   AND (
                       (pe_part.tipo_aprovador = 'usuario' AND pe_part.aprovador_id = {$u})
                    OR (pe_part.tipo_aprovador = 'grupo'   AND pe_part.aprovador_id IN (
                            SELECT gm_part.grupo_id FROM nf_grupo_membros gm_part
                             WHERE gm_part.usuario_id = {$u}))
                    OR pe_part.atuado_por = {$u}
                   )
            )
            OR EXISTS (
                SELECT 1 FROM nf_processo_historico ph_part
                 WHERE ph_part.processo_id = {$a}.id AND ph_part.ator_id = {$u}
            )
            OR EXISTS (
                SELECT 1 FROM nf_template_versoes tv_part
                  JOIN nf_template_acessos ta_part ON ta_part.template_id = tv_part.template_id
                 WHERE tv_part.id = {$a}.template_versao_id
                   AND ta_part.usuario_id = {$u} AND ta_part.revogado_em IS NULL
            )
            OR EXISTS (
                SELECT 1 FROM nf_template_versoes tv_pg
                  JOIN nf_template_acesso_grupos tg_pg ON tg_pg.template_id = tv_pg.template_id
                  JOIN nf_grupo_membros gm_pg ON gm_pg.grupo_id = tg_pg.grupo_id
                 WHERE tv_pg.id = {$a}.template_versao_id
                   AND gm_pg.usuario_id = {$u} AND tg_pg.revogado_em IS NULL
            )
        )";
    }

    /**
     * Mesma condição, aplicada a uma NOTA capturada: nota ainda sem processo é
     * visível para todos (a inbox é coletiva por natureza); depois de vinculada,
     * só para quem participa do processo.
     */
    public static function sqlParticipaNotaSessao(string $aliasNota): string
    {
        $cond = self::sqlParticipaSessao('p_nota');
        if ($cond === '') {
            return '';
        }
        return "(
            {$aliasNota}.processo_id IS NULL
            OR EXISTS (
                SELECT 1 FROM nf_processos p_nota
                 WHERE p_nota.id = {$aliasNota}.processo_id
                   AND {$cond}
            )
        )";
    }

    /**
     * O template do processo está liberado para o usuário? true quando o
     * template não é confidencial (caso normal) ou quando há liberação ativa.
     */
    private static function templateLiberado(string $processoId, string $usuarioId): bool
    {
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT tv.template_id
                   FROM nf_processos p
                   JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
                  WHERE p.id = ? LIMIT 1"
            );
            $stmt->execute([$processoId]);
            $templateId = (string) ($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            return true;   // sem como resolver o template: mantém a regra antiga
        }

        return $templateId === '' || TemplateSigilo::podeVerTemplate($templateId, $usuarioId);
    }

    /**
     * Etapa atual do processo quando ela é de visualização restrita e o
     * processo ainda está em andamento — null nos demais casos.
     * Resolve o catálogo por etapa_catalogo_id e, para etapas legadas,
     * pelo nome da etapa.
     */
    private static function etapaAtualRestrita(string $processoId): ?array
    {
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT pe.tipo_aprovador, pe.aprovador_id
                 FROM nf_processos p
                 JOIN nf_processo_etapas pe
                   ON pe.processo_id = p.id AND pe.numero_etapa = p.etapa_atual
                 LEFT JOIN nf_etapas e1 ON e1.id = pe.etapa_catalogo_id
                 LEFT JOIN nf_etapas e2 ON e2.nome = pe.nome_etapa
                 WHERE p.id = ?
                   AND p.status IN ('aberto', 'devolvido')
                   AND COALESCE(e1.visualizacao_restrita, e2.visualizacao_restrita, 0) = 1
                 LIMIT 1"
            );
            $stmt->execute([$processoId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Throwable $e) {
            return null; // catálogo indisponível — sem restrição extra
        }
    }

    /** O usuário é o aprovador da etapa (direto ou por pertencer ao grupo)? */
    private static function usuarioAtuaNaEtapa(array $etapa, string $usuarioId): bool
    {
        $aprovadorId = (string) ($etapa['aprovador_id'] ?? '');
        if ($aprovadorId === '') {
            return false;
        }
        if (($etapa['tipo_aprovador'] ?? '') === 'usuario') {
            return $aprovadorId === $usuarioId;
        }
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT 1 FROM nf_grupo_membros WHERE grupo_id = ? AND usuario_id = ? LIMIT 1"
            );
            $stmt->execute([$aprovadorId, $usuarioId]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Exige acesso ao processo. Sem acesso: registra a tentativa negada em
     * logs_auditoria, responde 403 (JSON se for XHR) e encerra a execução —
     * mesmo padrão de Permissions::require().
     */
    public static function autorizar(string $processoId, string $usuarioId): void
    {
        if (self::pode($processoId, $usuarioId)) {
            return;
        }

        AuditLog::registrar('nf_processo', $processoId, 'nf_processo.acesso_negado');

        http_response_code(403);

        if (
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'sucesso'  => false,
                'mensagem' => 'Acesso negado. Você não participa deste processo.',
            ]);
            exit;
        }

        include VIEWS . '/errors/403.php';
        exit;
    }
}
