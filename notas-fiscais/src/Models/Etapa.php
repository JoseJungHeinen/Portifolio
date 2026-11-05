<?php
// @versao Tab_07 V_06_01/10/2026
declare(strict_types=1);

/**
 * Model do catálogo de Etapas (nf_etapas) — aba Etapas do módulo Listas.
 *
 * Etapa padronizada, criada ANTES de usar nos templates. Atributos valem
 * para TODOS os fluxos que usam a etapa (herança em tempo de execução):
 *   - encerra_automaticamente: a etapa se conclui sozinha ao ser atingida
 *     (ex.: etapa "Arquivo" — o processo recebe os dados e arquiva no ato);
 *   - sla_horas: SLA padrão da etapa — sobrepõe o SLA do template;
 *   - tipo_aprovador: o que a etapa aceita (grupo | usuario | ambos);
 *   - visualizacao_restrita: com o processo parado nesta etapa, apenas os
 *     aprovadores da etapa (e admins) podem visualizar o processo.
 *   - somente_observacao: a etapa dá visibilidade ao responsável sem nunca
 *     pedir ação — o processo aparece em Processos, não aparece em Minhas
 *     Tarefas, e o fluxo passa direto por ela (nasce com status 'pulado').
 */
class Etapa
{
    /** @var array<string,array>|null cache por request: id => linha */
    private static ?array $cachePorId = null;
    /** @var array<string,array>|null cache por request: nome normalizado => linha */
    private static ?array $cachePorNome = null;

    // ─────────────────────────────────────────
    // Consulta
    // ─────────────────────────────────────────

    /** Etapas ativas — opções do editor de templates. */
    public static function listAtivas(): array
    {
        $pdo = Database::getInstance();
        return $pdo->query(
            // `*`: assinatura_externa/assinatura_provedor entram sem lista fixa de
            // colunas, e o editor continua abrindo mesmo antes da migration 070.
            "SELECT * FROM nf_etapas WHERE ativo = 1 ORDER BY nome ASC"
        )->fetchAll();
    }

    public static function findById(string $id): ?array
    {
        $mapa = self::mapaPorId();
        return $mapa[$id] ?? null;
    }

    /** Busca por nome exato (case-insensitive) — fallback para etapas legadas sem etapa_id. */
    public static function findByNome(string $nome): ?array
    {
        self::mapaPorId();
        return self::$cachePorNome[mb_strtolower(trim($nome), 'UTF-8')] ?? null;
    }

    /**
     * Resolve os atributos de catálogo de uma etapa de template/processo.
     * Aceita array com 'etapa_id' (ou 'etapa_catalogo_id') e/ou 'nome'/'nome_etapa'.
     * Retorna a linha do catálogo ou null se a etapa não estiver catalogada.
     */
    public static function resolver(array $etapa): ?array
    {
        $id = (string) ($etapa['etapa_id'] ?? ($etapa['etapa_catalogo_id'] ?? ''));
        if ($id !== '') {
            $row = self::findById($id);
            if ($row !== null) return $row;
        }
        $nome = (string) ($etapa['nome'] ?? ($etapa['nome_etapa'] ?? ''));
        return $nome !== '' ? self::findByNome($nome) : null;
    }

    /** A etapa se conclui sozinha (arquivamento)? */
    public static function encerraAutomaticamente(array $etapa): bool
    {
        $cat = self::resolver($etapa);
        return $cat !== null && (int) $cat['encerra_automaticamente'] === 1;
    }

    /**
     * A etapa dispara assinatura eletrônica e aguarda retorno externo?
     *
     * Terceiro comportamento especial do catálogo, ao lado de
     * encerra_automaticamente e somente_observacao: a etapa é atingida, dispara
     * o envio e fica parada até o provedor responder. Ninguém a aprova pela
     * tela — quem a conclui é o retorno da assinatura.
     */
    public static function ehAssinatura(array $etapa): bool
    {
        $cat = self::resolver($etapa);
        return $cat !== null && (int) ($cat['assinatura_externa'] ?? 0) === 1;
    }

    /** SLA padrão do catálogo (horas) — null quando a etapa não define SLA próprio. */
    public static function slaCatalogo(array $etapa): ?float
    {
        $cat = self::resolver($etapa);
        return ($cat !== null && $cat['sla_horas'] !== null) ? (float) $cat['sla_horas'] : null;
    }

    // ─────────────────────────────────────────
    // Uso (bloqueio de exclusão)
    // ─────────────────────────────────────────

    /** Em uso por templates (JSON de etapas) ou por processos existentes? */
    public static function emUso(string $id): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM nf_template_versoes WHERE etapas LIKE ?");
        $stmt->execute(['%' . $id . '%']);
        if ((int) $stmt->fetchColumn() > 0) return true;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM nf_processo_etapas WHERE etapa_catalogo_id = ?");
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ─────────────────────────────────────────
    // Cache interno
    // ─────────────────────────────────────────

    private static function mapaPorId(): array
    {
        if (self::$cachePorId !== null) {
            return self::$cachePorId;
        }
        self::$cachePorId   = [];
        self::$cachePorNome = [];
        try {
            $rows = Database::getInstance()->query("SELECT * FROM nf_etapas")->fetchAll();
            foreach ($rows as $r) {
                self::$cachePorId[(string) $r['id']] = $r;
                self::$cachePorNome[mb_strtolower(trim((string) $r['nome']), 'UTF-8')] = $r;
            }
        } catch (Throwable $e) {
            // Tabela ainda não migrada — segue sem catálogo (comportamento legado)
        }
        return self::$cachePorId;
    }

    /** Limpa o cache do request (após criar/editar etapa). */
    public static function limparCache(): void
    {
        self::$cachePorId   = null;
        self::$cachePorNome = null;
    }
}
