<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function view(string $view, array $dados = [], ?string $layout = 'main'): void
    {
        extract($dados, EXTR_SKIP);
        ob_start();
        try {
            include ROOT . '/views/' . $view . '.php';
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $content = ob_get_clean();
        if ($layout === null) {
            echo $content;
            return;
        }
        include ROOT . '/views/layouts/' . $layout . '.php';
    }

    protected function input(string $chave, mixed $padrao = null): mixed
    {
        return $_POST[$chave] ?? $_GET[$chave] ?? $padrao;
    }

    protected function abort(int $codigo): never
    {
        http_response_code($codigo);
        include ROOT . "/views/errors/{$codigo}.php";
        exit;
    }
}
