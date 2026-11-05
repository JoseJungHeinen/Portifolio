<?php

namespace App\Middleware;

class PermissaoMiddleware
{
    private array $perfis;

    public function __construct(array $perfis = [])
    {
        $this->perfis = $perfis;
    }

    public function handle(array $params = []): void
    {
        (new AuthMiddleware())->handle($params);

        if (!empty($this->perfis) && !can($this->perfis)) {
            http_response_code(403);
            include ROOT . '/views/errors/403.php';
            exit;
        }
    }
}
