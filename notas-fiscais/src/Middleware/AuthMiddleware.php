<?php
// @versao Log_04 V_02_12/08/2026
declare(strict_types=1);

/**
 * Middleware de autenticação.
 * Bloqueia acesso a qualquer rota sem sessão válida.
 * Chamado automaticamente pelo index.php antes de despachar para controllers.
 */
class AuthMiddleware
{
    /**
     * Verifica autenticação e encerra se não houver sessão válida.
     * Delega para Auth::check() que gerencia redirect e timeout.
     *
     * O módulo 'auth' (login/logout) é a única rota pública — sem essa
     * exceção, a própria tela de login ficaria bloqueada pelo check.
     */
    public static function handle(): void
    {
        $modulo = $_GET['module'] ?? '';
        if ($modulo === 'auth') {
            return;
        }

        Auth::check();
        self::horarioDeAcesso();
    }

    /**
     * Trava de horário de acesso (migration 043).
     *
     * Roda em TODA requisição de propósito: barrar só no login não limita nada,
     * bastaria entrar 11:55 para ficar a tarde inteira.
     *
     * Quem passa direto: super_admin (sem isso, um erro de cadastro tranca todo
     * mundo para fora, inclusive quem consertaria) e quem não tem horário
     * atribuído. O download de arquivo também passa — cortar no meio de um
     * download já autorizado só corrompe o arquivo.
     */
    private static function horarioDeAcesso(): void
    {
        if (Permissions::isSuperAdmin()) {
            return;
        }
        if (($_GET['module'] ?? '') === 'arquivos') {
            return;
        }

        $r = AccessSchedule::avaliarSessao();
        if ($r['liberado']) {
            return;
        }

        $_SESSION['bloqueio_horario'] = $r;
        http_response_code(403);
        include VIEWS . '/errors/fora-horario.php';
        exit;
    }
}
