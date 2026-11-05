<?php
declare(strict_types=1);

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}

// Autoload PSR-4: App\Foo\Bar → src/Foo/Bar.php
spl_autoload_register(function (string $classe): void {
    $prefixo = 'App\\';
    if (!str_starts_with($classe, $prefixo)) {
        return;
    }
    $arquivo = ROOT . '/src/' . str_replace('\\', '/', substr($classe, strlen($prefixo))) . '.php';
    if (is_file($arquivo)) {
        require $arquivo;
    }
});

/** Carrega .env sem sobrescrever chaves já definidas (os testes forçam DB_DATABASE). */
function env_load(string $arquivo): void
{
    if (!is_file($arquivo)) {
        return;
    }
    foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        $linha = trim($linha);
        if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) {
            continue;
        }
        [$chave, $valor] = array_map('trim', explode('=', $linha, 2));
        if (strlen($valor) >= 2 && $valor[0] === '"' && str_ends_with($valor, '"')) {
            $valor = substr($valor, 1, -1);
        }
        if (!array_key_exists($chave, $_ENV)) {
            $_ENV[$chave] = $valor;
        }
    }
}

env_load(ROOT . '/.env');
date_default_timezone_set('America/Sao_Paulo');
require_once ROOT . '/src/helpers.php';
