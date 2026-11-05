<?php
declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $rotas = [];

    /** Rotas isentas de CSRF: o login recebe POST do SSO do portal Exemplo. */
    private const SEM_CSRF = ['#^/login$#'];

    /** @param string[] $perfis vazio = qualquer usuário logado */
    public function get(string $caminho, array $handler, array $perfis = [], bool $publico = false): void
    {
        $this->adicionar('GET', $caminho, $handler, $perfis, $publico);
    }

    public function post(string $caminho, array $handler, array $perfis = [], bool $publico = false): void
    {
        $this->adicionar('POST', $caminho, $handler, $perfis, $publico);
    }

    private function adicionar(string $metodo, string $caminho, array $handler, array $perfis, bool $publico): void
    {
        $padrao = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[a-zA-Z0-9\-]+)', $caminho);
        $this->rotas[] = compact('metodo', 'padrao', 'handler', 'perfis', 'publico');
    }

    public function dispatch(): void
    {
        $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = rota_atual();

        foreach ($this->rotas as $rota) {
            if ($rota['metodo'] !== $metodo || !preg_match('#^' . $rota['padrao'] . '$#', $uri, $m)) {
                continue;
            }
            if ($metodo === 'POST' && !$this->semCsrf($uri)) {
                csrf_verify();
            }
            if (!$rota['publico']) {
                if (!auth()) {
                    session_flash('error', 'Faça login para continuar.');
                    redirect('/login');
                }
                if (!(new \App\Services\AuthService())->revalidarSessao()) {
                    session_flash('error', 'Sua sessão foi encerrada.');
                    redirect('/login');
                }
                if ($rota['perfis'] && !can($rota['perfis'])) {
                    http_response_code(403);
                    include ROOT . '/views/errors/403.php';
                    return;
                }
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            [$classe, $acao] = $rota['handler'];
            (new $classe())->$acao(...array_values($params));
            return;
        }

        http_response_code(404);
        include ROOT . '/views/errors/404.php';
    }

    private function semCsrf(string $uri): bool
    {
        foreach (self::SEM_CSRF as $padrao) {
            if (preg_match($padrao, $uri)) {
                return true;
            }
        }
        return false;
    }
}
