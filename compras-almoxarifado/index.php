<?php
// @versao Ver.002.281026
declare(strict_types=1);

define('ROOT', __DIR__);
define('START_TIME', microtime(true));

// Autoload via Composer
require_once ROOT . '/vendor/autoload.php';

// Carrega variáveis de ambiente. O Exemplo Requisições (pasta irmã requisicoes/) define
// APP_ENV_DIR e inclui este arquivo: o .env de lá troca nome, URL, sessão e menu.
// Pasta sem .env → o Dotenv lança erro (não cai no .env do Compras por engano).
$dotenv = Dotenv\Dotenv::createImmutable(defined('APP_ENV_DIR') ? APP_ENV_DIR : ROOT);
$dotenv->load();

// Configurações de sessão segura
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');

// cookie_secure só sob HTTPS — em produção o cookie nunca trafega em texto claro;
// em dev local (http://compras.test) permanece desligado para não quebrar a sessão.
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) == 443)
    || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
ini_set('session.cookie_secure', $isHttps ? '1' : '0');

session_name($_ENV['SESSION_NAME'] ?? 'compras_sess');
session_start();

// Cabeçalhos de segurança (auditoria M4). CSP fica para uma etapa própria: as
// telas usam muito script inline e de CDN, e uma política estrita quebraria.
header('X-Frame-Options: SAMEORIGIN');                 // sem embutir em site de terceiros (clickjacking)
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header_remove('X-Powered-By');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000');
}

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// Tratamento de erros
if ($_ENV['APP_DEBUG'] === 'true') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// Handler global de exceções — exibe página amigável em vez de fatal error
set_exception_handler(function (Throwable $e) {
    $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    $msg   = $e->getMessage();

    // Sempre grava o erro real em log para diagnóstico
    $logDir  = ROOT . '/storage/logs';
    $logFile = $logDir . '/error.log';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents($logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . get_class($e) . ': ' . $msg .
        ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL,
        FILE_APPEND
    );

    // Erro real de CONEXÃO: servidor fora, credenciais erradas, driver ausente
    $isConexao = str_contains($msg, 'banco de dados')
              || str_contains($msg, 'could not find driver')
              || str_contains($msg, 'Connection refused')
              || str_contains($msg, "Can't connect")
              || str_contains($msg, 'Access denied for user')
              || preg_match('/SQLSTATE\[HY000\] \[200[23]\]/', $msg);

    // Erro SQL: coluna inexistente, tabela ausente, constraint violada, etc.
    $isSqlError = !$isConexao && str_contains($msg, 'SQLSTATE');

    http_response_code(500);
    ?>
    <!DOCTYPE html><html lang="pt-BR"><head>
    <meta charset="UTF-8"><title>Erro — Exemplo Compras</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    </head><body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
    <div class="text-center" style="max-width:520px">
        <?php if ($isConexao): ?>
            <div class="display-1 mb-2">🔌</div>
            <h4 class="fw-bold">Banco de dados indisponível</h4>
            <p class="text-muted">O serviço MySQL não está respondendo.<br>
            <strong>Verifique o serviço de banco de dados no servidor e confirme que está em execução.</strong></p>
            <div class="alert alert-warning py-2 text-start small">
                <strong>Como resolver:</strong><br>
                1. Verifique se o serviço MySQL/MariaDB está rodando no servidor<br>
                2. Confirme as variáveis <code>DB_HOST</code>, <code>DB_PORT</code>, <code>DB_DATABASE</code>,
                   <code>DB_USERNAME</code> e <code>DB_PASSWORD</code> no arquivo .env<br>
                3. Recarregue esta página após corrigir
            </div>
        <?php elseif ($isSqlError): ?>
            <div class="display-1 mb-2">⚙️</div>
            <h4 class="fw-bold">Erro interno do sistema</h4>
            <p class="text-muted">Ocorreu um erro ao processar sua solicitação.<br>
            Isso pode indicar uma <strong>migração de banco de dados pendente</strong>.</p>
            <div class="alert alert-warning py-2 text-start small">
                <strong>Como resolver:</strong><br>
                1. Verifique se todas as migrations foram aplicadas no servidor<br>
                2. Execute os arquivos em <code>database/migrations/</code> em ordem<br>
                3. Entre em contato com o suporte se o problema persistir
            </div>
            <?php if ($debug): ?>
            <pre class="text-start bg-dark text-light p-3 rounded small mt-2" style="font-size:.75rem;text-align:left"><?= htmlspecialchars($msg) ?></pre>
            <?php endif; ?>
        <?php else: ?>
            <div class="display-1 mb-2">⚠️</div>
            <h4 class="fw-bold">Erro inesperado</h4>
            <p class="text-muted">Ocorreu um erro inesperado. Tente novamente ou entre em contato com o suporte.</p>
            <?php if ($debug): ?>
            <pre class="text-start bg-dark text-light p-3 rounded small mt-2" style="font-size:.75rem;text-align:left"><?= htmlspecialchars($msg) ?></pre>
            <?php endif; ?>
        <?php endif; ?>
        <a href="/" class="btn btn-primary mt-2">Tentar novamente</a>
    </div></body></html>
    <?php
    exit;
});

// Inicializa o router e despacha a requisição
use App\Core\Router;

$router = new Router();
require_once ROOT . '/src/routes.php';
$router->dispatch();
