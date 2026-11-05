<?php
// @versao Ver.002.281026

namespace App\Core;

abstract class Controller
{
    protected function view(string $view, array $data = [], string $layout = 'main'): void
    {
        extract($data, EXTR_SKIP);

        ob_start();
        include ROOT . '/views/' . $view . '.php';
        $content = ob_get_clean();

        if ($layout) {
            include ROOT . '/views/layouts/' . $layout . '.php';
        } else {
            echo $content;
        }
    }

    protected function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function redirect(string $path): void
    {
        redirect($path);
    }

    protected function back(): void
    {
        // Só volta para página do próprio sistema (auditoria B2): o Referer vem do
        // navegador e podia mandar o usuário para um site externo.
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host    = parse_url($referer, PHP_URL_HOST);
        if ($referer === '' || ($host !== null && $host !== ($_SERVER['HTTP_HOST'] ?? ''))) {
            redirect('/dashboard');
        }
        header('Location: ' . $referer);
        exit;
    }

    protected function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    protected function inputs(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->input($key);
        }
        return $result;
    }

    protected function requireAuth(): void
    {
        if (!auth()) {
            session_flash('error', 'Faça login para continuar.');
            redirect('/login');
        }
    }

    protected function requirePerfil(string|array $perfis): void
    {
        $this->requireAuth();
        if (!can($perfis)) {
            http_response_code(403);
            $this->view('errors/403', [], 'main');
            exit;
        }
    }

    /**
     * Mensagem de erro segura para a tela (auditoria M5). Regra de negócio
     * (RuntimeException/DomainException/InvalidArgumentException) aparece como
     * veio; erro de banco ou de programação vira texto genérico e o detalhe —
     * que pode conter SQL, tabela, coluna — vai só para o log.
     */
    protected function mensagemErro(\Throwable $e): string
    {
        $deNegocio = !($e instanceof \PDOException)
            && ($e instanceof \RuntimeException || $e instanceof \DomainException || $e instanceof \InvalidArgumentException);
        if ($deNegocio) {
            return $e->getMessage();
        }
        error_log('[' . static::class . '] ' . get_class($e) . ': ' . $e->getMessage()
            . ' em ' . $e->getFile() . ':' . $e->getLine());
        return 'erro interno — o detalhe foi registrado no log do sistema.';
    }

    /** Barra a rota sem a chave do catálogo de permissões (403). */
    protected function requirePermissao(string $chave): void
    {
        $this->requireAuth();
        if (!pode($chave)) {
            http_response_code(403);
            $this->view('errors/403', [], 'main');
            exit;
        }
    }
}
