<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
// Testes SEMPRE no banco ofp_test (env_load não sobrescreve chaves já definidas)
$_ENV['DB_DATABASE'] = getenv('DB_DATABASE_TEST') ?: 'ofp_test';
$_ENV['APP_URL'] = 'http://localhost:8091';
$_SESSION = [];

require ROOT . '/src/bootstrap.php';
require __DIR__ . '/TestCase.php';
require __DIR__ . '/fixtures.php';

$filtro = $argv[1] ?? '';
foreach (glob(__DIR__ . '/*Test.php') as $arquivo) {
    if ($filtro !== '' && !str_contains(basename($arquivo), $filtro)) {
        continue;
    }
    require $arquivo;
}

echo T::$ok . " asserções OK\n";
foreach (T::$falhas as $falha) {
    echo "FALHA: {$falha}\n";
}
exit(T::$falhas ? 1 : 0);
