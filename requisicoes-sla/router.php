<?php
// @versao Ver.001.281026
/**
 * Router para PHP Built-in Server (php -S)
 * Equivalente ao .htaccess do Apache para desenvolvimento local.
 */
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    // Bloqueia acesso a pastas sensíveis
    if (preg_match('#^/(src|views|database|storage|logs|vendor|uploads|deploy|cli|docs)(/|$)#', $path)
        || preg_match('#(^|/)\.#', $path)
        || preg_match('#\.(md|json|lock|sql|docx|log|disabled|example|ps1|bak)$#i', $path)) {
        http_response_code(403);
        echo '<h1>403 Forbidden</h1>';
        exit;
    }

    // Serve arquivos estáticos da pasta public/ diretamente
    $publicFile = __DIR__ . '/public' . $path;
    if (is_file($publicFile)) {
        return false; // PHP built-in server serve o arquivo estático
    }

    // Serve arquivos estáticos da raiz (ex: logo.svg)
    $rootFile = __DIR__ . $path;
    if (is_file($rootFile) && $path !== '/') {
        return false;
    }
}

require __DIR__ . '/index.php';
