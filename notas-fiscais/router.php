<?php
/**
 * Router para o servidor embutido do PHP (desenvolvimento local).
 * Emula o comportamento dos .htaccess (Apache):
 *   - arquivos existentes (ex.: /public/assets/...) são servidos direto
 *   - diretórios sensíveis retornam 403
 *   - todo o resto cai em public/index.php
 *
 * Uso: php -S localhost:8090 -t . router.php
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Bloquear diretórios sensíveis (igual ao .htaccess raiz)
if (preg_match('#^/(config|src|storage|database)(/|$)#', $uri)) {
    http_response_code(403);
    exit('403 Forbidden');
}

// Servir arquivos estáticos existentes (assets em /public/...)
if ($uri !== '/' && is_file(__DIR__ . $uri)) {
    return false;
}

// Todo o resto vai para o entry point
require __DIR__ . '/public/index.php';
