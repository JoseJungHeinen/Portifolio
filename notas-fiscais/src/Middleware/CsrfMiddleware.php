<?php
// @versao Sys_22 V_02_27/07/2026
declare(strict_types=1);

/**
 * Middleware de proteção CSRF.
 * Valida o token CSRF em todas as requisições POST, PUT, DELETE, PATCH.
 * Chamado no index.php após AuthMiddleware.
 */
class CsrfMiddleware
{
    /** Métodos HTTP que exigem validação de token CSRF */
    private const METODOS_PROTEGIDOS = ['POST', 'PUT', 'DELETE', 'PATCH'];

    /**
     * Valida o token CSRF se o método HTTP for protegido.
     * Encerra a execução com erro 403 se o token for inválido.
     */
    public static function handle(): void
    {
        $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if (!in_array($metodo, self::METODOS_PROTEGIDOS, true)) {
            return; // GET e HEAD não precisam de CSRF
        }

        // Envio maior que post_max_size: o PHP descarta o corpo INTEIRO antes de
        // chegar aqui — $_POST e $_FILES vêm vazios, inclusive o token. Sem esta
        // checagem o usuário levaria um "CSRF inválido" por ter anexado arquivos
        // demais, que é a pista errada. Acontece na importação de PDFs em lote.
        self::abortarSeEnvioExcedeuLimite();

        $token = $_POST['csrf_token'] ?? '';

        try {
            Csrf::validate($token);
        } catch (\RuntimeException $e) {
            // Registrar tentativa suspeita no log de erros
            error_log(sprintf(
                '[CSRF] Token inválido — IP: %s | URL: %s | Usuário: %s',
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['REQUEST_URI'] ?? 'unknown',
                $_SESSION['usuario_id'] ?? 'não autenticado'
            ));

            http_response_code(403);

            if (
                !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
            ) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'sucesso'  => false,
                    'mensagem' => 'Sessão expirada ou requisição inválida. Recarregue a página e tente novamente.',
                ]);
            } else {
                echo '<p>Sessão expirada ou requisição inválida. <a href="javascript:history.back()">Voltar</a></p>';
            }

            exit;
        }
    }

    /**
     * Corpo descartado por exceder `post_max_size`: avisa o que realmente
     * aconteceu, com os limites do servidor, em vez de acusar CSRF.
     */
    private static function abortarSeEnvioExcedeuLimite(): void
    {
        $enviado = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $limite  = self::emBytes((string) ini_get('post_max_size'));

        // Só vale quando o corpo sumiu: POST e FILES vazios com bytes enviados.
        if ($enviado <= 0 || $limite <= 0 || $enviado <= $limite) {
            return;
        }
        if (!empty($_POST) || !empty($_FILES)) {
            return;
        }

        $mb  = static fn(int $b): string => number_format($b / 1048576, 1, ',', '.') . ' MB';
        $msg = sprintf(
            'O envio (%s) passou do limite do servidor (%s por requisição). '
            . 'Envie menos arquivos por vez — o limite também vale para a soma de todos.',
            $mb($enviado), $mb($limite)
        );

        error_log('[UPLOAD] post_max_size excedido: ' . $enviado . ' bytes (limite ' . $limite . ')');
        http_response_code(413);   // Payload Too Large

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['sucesso' => false, 'mensagem' => $msg]);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            printf(
                '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:60px auto;'
                . 'background:#fff;border-left:4px solid #B3261E;border-radius:8px;padding:20px 24px">'
                . '<p style="font-size:16px;font-weight:700;color:#141922;margin:0 0 8px">Envio grande demais</p>'
                . '<p style="font-size:14px;color:#606060;margin:0 0 16px">%s</p>'
                . '<a href="javascript:history.back()" style="display:inline-block;background:#2347C8;color:#fff;'
                . 'border-radius:4px;padding:9px 18px;font-size:14px;font-weight:700;text-decoration:none">Voltar</a>'
                . '</div>',
                htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
            );
        }
        exit;
    }

    /** Converte "8M", "512K", "1G" de php.ini para bytes. */
    private static function emBytes(string $valor): int
    {
        $valor = trim($valor);
        if ($valor === '') {
            return 0;
        }
        $num = (int) $valor;
        return match (strtolower(substr($valor, -1))) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }
}
