<?php
// Roteador para o servidor embutido (php -S localhost:8091 router.php) — equivale ao .htaccess.
if (php_sapi_name() === 'cli-server') {
    $caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (preg_match('#^/(src|views|database|storage|tests|cli|docs)(/|$)#', $caminho) || str_starts_with($caminho, '/.')) {
        http_response_code(403);
        echo '<h1>403 Forbidden</h1>';
        return true;
    }
    if ($caminho !== '/' && is_file(__DIR__ . $caminho) && str_starts_with($caminho, '/public/')) {
        return false;
    }
}
require __DIR__ . '/index.php';
