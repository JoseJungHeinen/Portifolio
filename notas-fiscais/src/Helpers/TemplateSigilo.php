<?php
// @versao Ace_05 V_01_27/07/2026
declare(strict_types=1);

/**
 * Gate de SIGILO por template (RH, Jurídico e afins).
 *
 * Um template marcado como `confidencial` só é visível para quem tem liberação
 * explícita — por USUÁRIO (nf_template_acessos) ou por GRUPO
 * (nf_template_acesso_grupos). NÃO existe bypass de admin: o administrador
 * gerencia a lista, mas para enxergar o conteúdo precisa se conceder acesso,
 * e a concessão fica registrada em logs_auditoria. Mesmo princípio do
 * ApolloAccess (migration 028).
 *
 * Duas intensidades, de propósito:
 *
 *   • Telas de PROCESSO (lista/detalhe) — liberação OU participação de fato
 *     (criador/aprovador). Sem isso o fluxo trava: o aprovador da etapa de RH
 *     não conseguiria abrir o processo que precisa aprovar.
 *
 *   • Telas AGREGADAS (dashboard, financeiro, relatórios, inbox de capturas) —
 *     só liberação. Ali o dado aparece somado/tabulado com o resto da empresa,
 *     então participar de UM processo de RH não pode revelar o total da folha.
 */
class TemplateSigilo
{
    /** Cache por request: usuario_id => lista de template_id bloqueados. */
    private static array $cacheBloqueados = [];

    /**
     * IDs dos templates confidenciais que este usuário NÃO pode ver.
     * Lista vazia = nada a esconder (caso normal — nenhum template sigiloso,
     * ou o usuário tem liberação para todos eles).
     *
     * @return list<string>
     */
    public static function templatesBloqueados(string $usuarioId): array
    {
        if ($usuarioId === '') {
            return self::todosConfidenciais();
        }
        if (isset(self::$cacheBloqueados[$usuarioId])) {
            return self::$cacheBloqueados[$usuarioId];
        }

        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT t.id
                   FROM nf_templates t
                  WHERE t.confidencial = 1
                    AND NOT EXISTS (
                        SELECT 1 FROM nf_template_acessos ta
                         WHERE ta.template_id = t.id
                           AND ta.usuario_id  = ?
                           AND ta.revogado_em IS NULL
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM nf_template_acesso_grupos tg
                          JOIN nf_grupo_membros gm ON gm.grupo_id = tg.grupo_id
                         WHERE tg.template_id = t.id
                           AND gm.usuario_id  = ?
                           AND tg.revogado_em IS NULL
                    )"
            );
            $stmt->execute([$usuarioId, $usuarioId]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            // Tabela/coluna ainda não migrada: falha FECHADA para o que é
            // sigiloso conhecido, aberta quando não há sigilo nenhum.
            $ids = self::todosConfidenciais();
        }

        return self::$cacheBloqueados[$usuarioId] = array_map('strval', $ids);
    }

    /** Todos os templates confidenciais, sem considerar liberação. */
    private static function todosConfidenciais(): array
    {
        try {
            $ids = Database::getInstance()
                ->query("SELECT id FROM nf_templates WHERE confidencial = 1")
                ->fetchAll(PDO::FETCH_COLUMN) ?: [];
            return array_map('strval', $ids);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** O usuário tem liberação para este template? (sem bypass de admin) */
    public static function podeVerTemplate(string $templateId, string $usuarioId): bool
    {
        return !in_array($templateId, self::templatesBloqueados($usuarioId), true);
    }

    /**
     * Condição SQL que exclui processos de templates bloqueados.
     * Devolve ['', []] quando não há nada a esconder para este usuário.
     *
     * A condição sai SEM placeholders de propósito: os ids são interpolados
     * já escapados por PDO::quote(). Isso permite encaixar o recorte em
     * qualquer query legada sem mexer na ordem dos parâmetros do execute() —
     * a lista dos IN() vem do próprio banco (uuid de nf_templates), nunca de
     * entrada do usuário. O segundo item do retorno existe só para manter a
     * assinatura [condicao, params] usada pelos chamadores.
     *
     * @param string $aliasProcesso alias da tabela nf_processos na query
     * @return array{0:string,1:list<string>} [condicao, params]
     */
    public static function sqlExcluiProcessos(string $aliasProcesso, string $usuarioId): array
    {
        $lista = self::listaQuotada($usuarioId);
        if ($lista === '') {
            return ['', []];
        }
        $sql = "NOT EXISTS (
                    SELECT 1 FROM nf_template_versoes tv_sig
                     WHERE tv_sig.id = {$aliasProcesso}.template_versao_id
                       AND tv_sig.template_id IN ({$lista})
                )";
        return [$sql, []];
    }

    /**
     * Condição SQL que exclui NOTAS CAPTURADAS já vinculadas a processo de
     * template bloqueado (a nota carrega valor e fornecedor do processo).
     *
     * @return array{0:string,1:list<string>} [condicao, params]
     */
    public static function sqlExcluiNotas(string $aliasNota, string $usuarioId): array
    {
        $lista = self::listaQuotada($usuarioId);
        if ($lista === '') {
            return ['', []];
        }
        $sql = "NOT EXISTS (
                    SELECT 1 FROM nf_processos p_sig
                      JOIN nf_template_versoes tv_sig ON tv_sig.id = p_sig.template_versao_id
                     WHERE p_sig.id = {$aliasNota}.processo_id
                       AND tv_sig.template_id IN ({$lista})
                )";
        return [$sql, []];
    }

    /** Lista de ids bloqueados pronta para um IN(), escapada por PDO::quote(). */
    private static function listaQuotada(string $usuarioId): string
    {
        $ids = self::templatesBloqueados($usuarioId);
        if (!$ids) {
            return '';
        }
        $pdo = Database::getInstance();
        return implode(',', array_map(static fn(string $id): string => $pdo->quote($id), $ids));
    }

    /**
     * Versões "de sessão" das condições acima: aplicam o sigilo ao usuário
     * logado e não fazem nada fora do contexto web (CLI: brobot, SLA, crons —
     * que precisam enxergar todos os processos para notificar os aprovadores).
     *
     * @return array{0:string,1:list<string>}
     */
    public static function sqlExcluiProcessosSessao(string $aliasProcesso): array
    {
        $uid = (string) ($_SESSION['usuario_id'] ?? '');
        return $uid === '' ? ['', []] : self::sqlExcluiProcessos($aliasProcesso, $uid);
    }

    /** @return array{0:string,1:list<string>} */
    public static function sqlExcluiNotasSessao(string $aliasNota): array
    {
        $uid = (string) ($_SESSION['usuario_id'] ?? '');
        return $uid === '' ? ['', []] : self::sqlExcluiNotas($aliasNota, $uid);
    }

    /** Existe algum template confidencial configurado? (para atalhos na UI) */
    public static function existeAlgumConfidencial(): bool
    {
        return self::todosConfidenciais() !== [];
    }

    // ─────────────────────────────────────────
    // Gestão (tela de Controle de Acesso)
    // ─────────────────────────────────────────

    /** Marca/desmarca um template como confidencial. */
    public static function definirConfidencial(string $templateId, bool $confidencial, string $porUsuarioId): void
    {
        $stmt = Database::getInstance()->prepare(
            "UPDATE nf_templates SET confidencial = ? WHERE id = ?"
        );
        $stmt->execute([$confidencial ? 1 : 0, $templateId]);
        self::$cacheBloqueados = [];

        AuditLog::registrar('nf_template', $templateId,
            $confidencial ? 'nf_template.marcado_confidencial' : 'nf_template.desmarcado_confidencial',
            null, ['por' => $porUsuarioId]);
    }

    /** Templates confidenciais com a contagem de liberações ativas. */
    public static function listarConfidenciais(): array
    {
        try {
            return Database::getInstance()->query(
                "SELECT t.id, t.nome,
                        (SELECT COUNT(*) FROM nf_template_acessos ta
                          WHERE ta.template_id = t.id AND ta.revogado_em IS NULL)       AS usuarios_liberados,
                        (SELECT COUNT(*) FROM nf_template_acesso_grupos tg
                          WHERE tg.template_id = t.id AND tg.revogado_em IS NULL)       AS grupos_liberados
                   FROM nf_templates t
                  WHERE t.confidencial = 1
                  ORDER BY t.nome"
            )->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Quem enxerga um template confidencial — usuários e grupos liberados. */
    public static function liberados(string $templateId): array
    {
        $pdo = Database::getInstance();

        $stmtU = $pdo->prepare(
            "SELECT ta.id, ta.usuario_id, u.nome, u.email, ta.concedido_em, ta.motivo,
                    c.nome AS concedido_por_nome
               FROM nf_template_acessos ta
               LEFT JOIN usuarios u ON u.id = ta.usuario_id
               LEFT JOIN usuarios c ON c.id = ta.concedido_por
              WHERE ta.template_id = ? AND ta.revogado_em IS NULL
              ORDER BY u.nome"
        );
        $stmtU->execute([$templateId]);

        $stmtG = $pdo->prepare(
            "SELECT tg.id, tg.grupo_id, g.nome, tg.concedido_em, tg.motivo,
                    c.nome AS concedido_por_nome,
                    (SELECT COUNT(*) FROM nf_grupo_membros gm WHERE gm.grupo_id = tg.grupo_id) AS membros
               FROM nf_template_acesso_grupos tg
               LEFT JOIN nf_grupos g  ON g.id = tg.grupo_id
               LEFT JOIN usuarios c   ON c.id = tg.concedido_por
              WHERE tg.template_id = ? AND tg.revogado_em IS NULL
              ORDER BY g.nome"
        );
        $stmtG->execute([$templateId]);

        return ['usuarios' => $stmtU->fetchAll(), 'grupos' => $stmtG->fetchAll()];
    }

    /** Concede acesso a um GRUPO (idempotente — reativa liberação revogada). */
    public static function concederGrupo(string $templateId, string $grupoId, string $concedidoPor, ?string $motivo = null): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT id FROM nf_template_acesso_grupos
              WHERE template_id = ? AND grupo_id = ? LIMIT 1"
        );
        $stmt->execute([$templateId, $grupoId]);
        $id = $stmt->fetchColumn();

        if ($id) {
            $pdo->prepare(
                "UPDATE nf_template_acesso_grupos
                    SET revogado_em = NULL, revogado_por = NULL,
                        concedido_por = ?, concedido_em = NOW(), motivo = ?
                  WHERE id = ?"
            )->execute([$concedidoPor, $motivo, $id]);
        } else {
            $pdo->prepare(
                "INSERT INTO nf_template_acesso_grupos (id, template_id, grupo_id, concedido_por, motivo)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([self::uuid(), $templateId, $grupoId, $concedidoPor, $motivo]);
        }

        self::$cacheBloqueados = [];
        AuditLog::registrar('nf_template', $templateId, 'nf_template.acesso_grupo_concedido',
            null, ['grupo' => $grupoId, 'por' => $concedidoPor]);
    }

    /** Revoga (soft) a liberação de um grupo. */
    public static function revogarGrupo(string $templateId, string $grupoId, string $revogadoPor): void
    {
        Database::getInstance()->prepare(
            "UPDATE nf_template_acesso_grupos
                SET revogado_em = NOW(), revogado_por = ?
              WHERE template_id = ? AND grupo_id = ? AND revogado_em IS NULL"
        )->execute([$revogadoPor, $templateId, $grupoId]);

        self::$cacheBloqueados = [];
        AuditLog::registrar('nf_template', $templateId, 'nf_template.acesso_grupo_revogado',
            null, ['grupo' => $grupoId, 'por' => $revogadoPor]);
    }

    /** Zera o cache por request — usado após alterar liberações/vínculos. */
    public static function limparCache(): void
    {
        self::$cacheBloqueados = [];
    }

    private static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
