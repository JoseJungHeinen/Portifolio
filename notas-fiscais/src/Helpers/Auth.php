<?php
// @versao Log_03 V_05_01/10/2026
declare(strict_types=1);

/**
 * Helper de autenticação do módulo NF.
 *
 * Login nativo (tela própria em ?module=auth) que autentica contra a
 * tabela `usuarios` do banco `compras` (bcrypt via password_hash/verify).
 */
class Auth
{
    /** Cache do usuário para evitar múltiplas queries por request */
    private static ?array $cache = null;

    /**
     * Cache do override granular (nf_usuario_permissoes) do usuário atual.
     * false = já verificado e não existe override (segue o perfil).
     */
    private static array|false|null $permsCache = null;

    /**
     * Verifica se há um usuário autenticado na sessão.
     * Redireciona para a tela de login nativa se não houver sessão válida,
     * preservando a URL de destino em "next" para retomar após o login.
     */
    public static function check(): void
    {
        if (empty($_SESSION['usuario_id'])) {
            self::redirecionarParaLogin('necessario');
        }

        // Verificar timeout de inatividade
        if (isset($_SESSION['ultimo_acesso'])) {
            $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 1800;
            if ((time() - $_SESSION['ultimo_acesso']) > $timeout) {
                session_unset();
                session_destroy();
                self::redirecionarParaLogin('timeout');
            }
        }

        // Atualizar timestamp do último acesso
        $_SESSION['ultimo_acesso'] = time();
    }

    /**
     * Monta e executa o redirecionamento para a tela de login, anexando
     * o motivo (exibido como aviso) e a URL atual em "next" para retomar
     * o destino original após autenticar — nunca um redirect externo.
     */
    private static function redirecionarParaLogin(string $motivo): void
    {
        // APP_PUBLIC_URL já resolve a diferença entre Server_novo (Alias na
        // raiz do app, precisa de "/public") e Server-APP (Alias já mira em
        // .../public) — ver config/constants.php.
        $url  = APP_PUBLIC_URL . '/index.php?module=auth&action=index&motivo=' . urlencode($motivo);
        $next = $_SERVER['REQUEST_URI'] ?? '';

        if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_contains($next, 'module=auth')) {
            $url .= '&next=' . urlencode($next);
        }

        header('Location: ' . $url);
        exit;
    }

    /**
     * Retorna os dados do usuário autenticado.
     * Faz uma query ao banco na primeira chamada e cacheia o resultado.
     *
     * @return array{id: string, nome: string, email: string, perfil: string, filial_id: string|null, setor_id: string|null}
     * @throws RuntimeException se o usuário não for encontrado ou estiver inativo
     */
    public static function user(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        if (empty($_SESSION['usuario_id'])) {
            self::redirecionarParaLogin('necessario');
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, nome, email, perfil, filial_id, setor_id
               FROM usuarios
              WHERE id = ?
                AND ativo = 1
              LIMIT 1'
        );
        $stmt->execute([$_SESSION['usuario_id']]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            session_unset();
            session_destroy();
            self::redirecionarParaLogin('acesso_negado');
        }

        self::$cache = $usuario;
        return self::$cache;
    }

    /**
     * Retorna o ID (UUID) do usuário autenticado.
     */
    public static function id(): string
    {
        return self::user()['id'];
    }

    /**
     * Retorna o perfil do usuário autenticado (admin, gestor, comprador, etc.).
     */
    public static function perfil(): string
    {
        return self::user()['perfil'];
    }

    /**
     * Verifica se o usuário tem permissão para determinado módulo e ação.
     *
     * Ordem de resolução:
     *   1. Admin sempre tem acesso total (bypass).
     *   2. Se o usuário tem override granular (nf_usuario_permissoes),
     *      esse override manda — inclusive para negar algo que o perfil
     *      permitiria.
     *   3. Sem override, cai no mapa estático por perfil (config/permissions.php)
     *      — comportamento histórico, preservado para quem nunca foi
     *      configurado na tela de Controle de Acesso.
     *
     * @param string $modulo  Ex: 'processos', 'templates', 'financeiro'
     * @param string $acao    Ex: 'ver', 'criar', 'aprovar', 'exportar'
     */
    public static function can(string $modulo, string $acao): bool
    {
        // Bypass total é exclusivo do super_admin. O perfil `admin` virou
        // administrador de CADASTROS (funcionários, grupos, templates) e
        // passa pelo mapa de permissões como qualquer outro perfil —
        // sem acesso a processos, capturas, financeiro ou relatórios.
        if (self::perfil() === 'super_admin') {
            return true;
        }

        // Permissions::completarPadroes() fornece os padrões das chaves novas (menu.*, importar_pdfs).
        require_once __DIR__ . '/Permissions.php';

        $override = self::overridePermissoes();
        if ($override !== null) {
            return (bool) (Permissions::completarPadroes($override)[$modulo][$acao] ?? false);
        }

        // Padrão do perfil: customização gravada no banco (nf_perfil_permissoes)
        // tem precedência sobre o arquivo config/permissions.php
        if (self::$perfilPermsCache === null) {
            $pdo  = Database::getInstance();
            $stmt = $pdo->prepare('SELECT permissoes FROM nf_perfil_permissoes WHERE perfil = ? LIMIT 1');
            $stmt->execute([self::perfil()]);
            $row     = $stmt->fetchColumn();
            $decoded = $row ? json_decode($row, true) : null;
            self::$perfilPermsCache = is_array($decoded) ? $decoded : false;
        }
        if (self::$perfilPermsCache !== false) {
            return (bool) (Permissions::completarPadroes(self::$perfilPermsCache)[$modulo][$acao] ?? false);
        }

        $mapa = require CONFIG . '/permissions.php';
        return (bool) (Permissions::completarPadroes($mapa[self::perfil()] ?? [])[$modulo][$acao] ?? false);
    }

    /**
     * Verifica se o usuário tem PELO MENOS UMA das ações informadas no módulo.
     * Útil para gates "grosseiros" que decidem só se algo deve aparecer
     * (ex: item de menu), quando o módulo tem várias ações granulares e
     * qualquer uma delas já justifica mostrar o link.
     *
     * @param string   $modulo Ex: 'dashboard'
     * @param string[] $acoes  Ex: ['ver_visao_geral', 'ver_meus_processos']
     */
    public static function canAny(string $modulo, array $acoes): bool
    {
        foreach ($acoes as $acao) {
            if (self::can($modulo, $acao)) {
                return true;
            }
        }
        return false;
    }

    /** Cache por request das permissões customizadas do perfil (false = sem customização) */
    private static $perfilPermsCache = null;

    /**
     * Busca (com cache por request) o override granular do usuário atual
     * na tabela nf_usuario_permissoes. Retorna null se não existir linha.
     */
    private static function overridePermissoes(): ?array
    {
        if (self::$permsCache !== null) {
            return self::$permsCache === false ? null : self::$permsCache;
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT permissoes FROM nf_usuario_permissoes WHERE usuario_id = ? LIMIT 1');
        $stmt->execute([self::id()]);
        $row = $stmt->fetch();

        if (!$row) {
            self::$permsCache = false;
            return null;
        }

        $decoded = json_decode($row['permissoes'], true);
        self::$permsCache = is_array($decoded) ? $decoded : [];
        return self::$permsCache;
    }

    /**
     * Limpa o cache estático do usuário.
     * Útil em testes e após atualização de dados do usuário.
     */
    public static function limparCache(): void
    {
        self::$cache      = null;
        self::$permsCache = null;
    }
}
