<?php
declare(strict_types=1);

define('ROOT', __DIR__);
require ROOT . '/src/bootstrap.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
ini_set('session.cookie_secure', $https ? '1' : '0');
session_name($_ENV['SESSION_NAME'] ?? 'ofp_sess');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
session_start();

$debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);
if (!$debug) {
    ini_set('log_errors', '1');
    ini_set('error_log', ROOT . '/storage/logs/php-error.log');
}

set_exception_handler(function (Throwable $e) use ($debug): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(
        $dir . '/error.log',
        '[' . date('Y-m-d H:i:s') . '] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL,
        FILE_APPEND
    );
    http_response_code(500);
    $mensagem = $debug ? $e->getMessage() : null;
    include ROOT . '/views/errors/500.php';
});

$_POST = limpar_utf8($_POST);
$_GET = limpar_utf8($_GET);

$router = new App\Core\Router();
require ROOT . '/src/routes.php';
$router->dispatch();
