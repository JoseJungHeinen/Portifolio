<?php
// @versao Sys_18 V_05_05/10/2026
declare(strict_types=1);

/**
 * Helper de verificação de permissões RBAC.
 * Delega para Auth::can() e encapsula as respostas de negação de acesso.
 */
class Permissions
{
    /**
     * Itens do menu lateral controláveis em Controle de Acesso (módulo 'menu').
     * chave => rótulo. Marcar = o item aparece no menu (a permissão da tela continua
     * valendo à parte). Controle de Acesso e Geral ficam de fora: são gates próprios
     * e desligá-los poderia trancar o administrador fora da tela que religa.
     */
    public const MENU_ITENS = [
        'dashboard' => 'Dashboard', 'pesquisar_processos' => 'Pesquisar Processos',
        'notas_capturadas' => 'Notas Capturadas', 'processos' => 'Processos', 'entrega_futura' => 'Entrega Futura',
        'minhas_tarefas' => 'Minhas Tarefas', 'controle_financeiro' => 'Controle Financeiro',
        'pagamentos' => 'Pagamentos', 'fiscal' => 'Fiscal', 'faturamento' => 'Faturamento',
        'relatorios' => 'Relatórios', 'importar_pdfs' => 'Importar PDFs', 'novo_processo' => 'Novo Processo',
        'templates' => 'Templates', 'grupos' => 'Grupos', 'prefluxo' => 'Pré-fluxo', 'listas' => 'Listas',
    ];

    /** Itens do menu que começam desmarcados para todos (módulo em construção). */
    public const MENU_DESLIGADOS_POR_PADRAO = ['faturamento', 'entrega_futura'];

    /**
     * Preenche as chaves que ainda não foram gravadas, sem mudar o que o usuário já
     * enxerga hoje: itens de menu ausentes = visíveis (exceto MENU_DESLIGADOS_POR_PADRAO); processos.importar_pdfs ausente
     * segue processos.criar (regra antiga do Importar PDFs). Usado por Auth::can() e
     * pela tela de Controle de Acesso, para os dois concordarem.
     */
    public static function completarPadroes(array $perms): array
    {
        foreach (array_keys(self::MENU_ITENS) as $item) {
            if (!isset($perms['menu'][$item])) {
                // Faturamento (Beta) está no catálogo mas nasce DESLIGADO para todos:
                // só aparece no menu de quem for marcado explicitamente.
                $perms['menu'][$item] = !in_array($item, self::MENU_DESLIGADOS_POR_PADRAO, true);
            }
        }
        if (!isset($perms['processos']['importar_pdfs'])) {
            $perms['processos']['importar_pdfs'] = (bool) ($perms['processos']['criar'] ?? false);
        }
        return $perms;
    }

    /**
     * Exige que o usuário tenha a permissão indicada.
     * Responde com HTTP 403 e encerra a execução se não tiver.
     *
     * @param string $modulo  Ex: 'processos', 'financeiro'
     * @param string $acao    Ex: 'criar', 'aprovar', 'exportar'
     */
    public static function require(string $modulo, string $acao): void
    {
        if (!self::check($modulo, $acao)) {
            http_response_code(403);

            // Se for uma requisição AJAX, retorna JSON
            if (
                !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
            ) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'sucesso' => false,
                    'mensagem' => 'Acesso negado. Você não tem permissão para esta ação.',
                ]);
                exit;
            }

            // Para requisições normais, exibe página de erro
            include VIEWS . '/errors/403.php';
            exit;
        }
    }

    /**
     * Verifica se o usuário tem a permissão indicada sem encerrar a execução.
     *
     * @param string $modulo  Ex: 'processos', 'financeiro'
     * @param string $acao    Ex: 'criar', 'aprovar', 'exportar'
     * @return bool true se tiver permissão, false caso contrário
     */
    public static function check(string $modulo, string $acao): bool
    {
        return Auth::can($modulo, $acao);
    }

    /**
     * Administrador pleno (bypass total). Desde a migration 030 é o
     * `super_admin` — perfil atribuível só por SQL, nunca pela tela.
     *
     * Mantém o nome isAdmin() de propósito: todas as checagens espalhadas pelo
     * sistema usam isso como "pode tudo", e é exatamente essa a semântica que
     * precisa continuar valendo só para o super_admin.
     */
    public static function isAdmin(): bool
    {
        return Auth::perfil() === 'super_admin';
    }

    /** Alias explícito, para quem lê o código novo. */
    public static function isSuperAdmin(): bool
    {
        return Auth::perfil() === 'super_admin';
    }

    /**
     * Administrador de CADASTROS — funcionários (Controle de Acesso), grupos e
     * templates. Vale para `admin` e `super_admin`. NÃO dá acesso a processos,
     * capturas, financeiro, relatórios ou dashboard: isso continua saindo do
     * mapa de permissões de cada perfil.
     */
    public static function isAdminCadastros(): bool
    {
        return in_array(Auth::perfil(), ['admin', 'super_admin'], true);
    }

    /**
     * Verifica se o usuário pode aprovar processos (admin, gestor ou diretor).
     */
    public static function podeAprovar(): bool
    {
        return self::check('processos', 'aprovar');
    }

    /**
     * Alias de check() para compatibilidade.
     *
     * @param string $modulo  Ex: 'processos', 'financeiro'
     * @param string $acao    Ex: 'criar', 'aprovar', 'exportar'
     * @return bool
     */
    public static function can(string $modulo, string $acao): bool
    {
        return self::check($modulo, $acao);
    }
}
