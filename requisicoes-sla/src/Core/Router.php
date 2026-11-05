<?php
// @versao Ver.002.281026

namespace App\Core;

use App\Middleware\AuthMiddleware;
use App\Middleware\PermissaoMiddleware;

class Router
{
    private array $routes = [];

    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware); // via _method hidden field
    }

    public function delete(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    private function addRoute(string $method, string $path, array $handler, array $middleware): void
    {
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[a-zA-Z0-9\-]+)', $path);
        $this->routes[] = compact('method', 'path', 'pattern', 'handler', 'middleware');
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Remove base path se necessário — apenas UMA vez.
        // SCRIPT_NAME e APP_URL normalmente resolvem para o mesmo prefixo
        // (ex.: /compras). Aplicar os dois removeria o prefixo da própria
        // rota /compras/* (módulo de compras), causando 404.
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        $stripped = false;
        if ($base && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
            $stripped = true;
        }

        if (!$stripped) {
            $appPath = parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH);
            $appBase = $appPath ? rtrim($appPath, '/') : '';
            if ($appBase && str_starts_with($uri, $appBase)) {
                $uri = substr($uri, strlen($appBase));
            }
        }

        $uri = '/' . ltrim($uri, '/');

        // Exemplo Requisições: sem Dashboard — a entrada é a etapa do fluxo do perfil —
        // e só as rotas do fluxo respondem (lista em rota_permitida_no_sistema).
        if (sistema() === 'requisicoes') {
            if ($uri === '/' || $uri === '/dashboard') {
                redirect(auth() ? rota_inicial(auth()['perfil'] ?? '') : '/login');
            }
            if (!rota_permitida_no_sistema($uri)) {
                $this->notFound();
                return;
            }
        }

        // Rotas isentas de CSRF: acesso público de fornecedores (protegido por token de URL)
        $csrfExempt = [
            '#^/cotacao/responder/#',
            // /login e /senha/* saíram da lista (auditoria B1): os formulários já
            // enviam o token, e a isenção permitia login CSRF.
        ];

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;

            if (preg_match('#^' . $route['pattern'] . '$#', $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Verificação CSRF para todas as mutações (POST/PUT/DELETE)
                if ($method === 'POST') {
                    $isExempt = false;
                    foreach ($csrfExempt as $exemptPattern) {
                        if (preg_match($exemptPattern, $uri)) {
                            $isExempt = true;
                            break;
                        }
                    }
                    if (!$isExempt) {
                        csrf_verify();
                    }
                }

                // Executa middlewares
                foreach ($route['middleware'] as $mw) {
                    (new $mw())->handle($params);
                }

                [$controllerClass, $action] = $route['handler'];
                $controller = new $controllerClass();
                $controller->$action(...array_values($params));
                return;
            }
        }

        $this->notFound();
    }

    private function notFound(): void
    {
        http_response_code(404);
        include ROOT . '/views/errors/404.php';
    }
}
