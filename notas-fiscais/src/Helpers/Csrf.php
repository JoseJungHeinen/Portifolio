<?php
// @versao Sys_15 V_01_16/07/2026
declare(strict_types=1);

/**
 * Helper de proteção CSRF (Cross-Site Request Forgery).
 *
 * Todo formulário POST deve incluir o campo csrf_token.
 * O controller valida o token antes de qualquer processamento.
 *
 * Fluxo:
 *   1. View chama Csrf::field() para incluir o input hidden no formulário
 *   2. Controller chama Csrf::validate($_POST['csrf_token']) ao receber o POST
 *   3. Após ação destrutiva (delete, cancelamento), chamar Csrf::regenerate()
 */
class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    /**
     * Retorna o token CSRF da sessão.
     * Gera um novo token criptograficamente seguro se não existir.
     */
    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            self::regenerate();
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Valida o token CSRF recebido no formulário.
     * Usa hash_equals para comparação em tempo constante (previne timing attacks).
     *
     * @param string $token Token recebido via $_POST['csrf_token']
     * @throws RuntimeException se o token for inválido ou ausente
     */
    public static function validate(string $token): void
    {
        $esperado = self::token();

        if (empty($token) || !hash_equals($esperado, $token)) {
            http_response_code(403);
            throw new RuntimeException('Token CSRF inválido. A ação foi bloqueada por segurança.');
        }
    }

    /**
     * Retorna o HTML do campo hidden com o token CSRF.
     * Usar dentro de todo formulário POST:
     *   <?= Csrf::field() ?>
     */
    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    /**
     * Regenera o token CSRF com novo valor aleatório.
     * Chamar após ações destrutivas (delete, cancelamento, logout).
     */
    public static function regenerate(): void
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }
}
