<?php
declare(strict_types=1);

/** Mini framework de asserções — sem dependências. */
final class T
{
    public static int $ok = 0;
    public static array $falhas = [];
    private static string $atual = '';

    public static function teste(string $nome, callable $fn): void
    {
        self::$atual = $nome;
        try {
            $fn();
        } catch (Throwable $e) {
            self::$falhas[] = "{$nome}: exceção " . get_class($e) . ' — ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        }
    }

    public static function igual(mixed $esperado, mixed $obtido, string $msg = ''): void
    {
        if ($esperado === $obtido) {
            self::$ok++;
            return;
        }
        self::$falhas[] = self::$atual . ($msg !== '' ? " ({$msg})" : '') . ': esperado ' . var_export($esperado, true) . ', obtido ' . var_export($obtido, true);
    }

    public static function verdadeiro(bool $condicao, string $msg = ''): void
    {
        self::igual(true, $condicao, $msg);
    }

    public static function lanca(string $classe, callable $fn, string $msg = ''): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            self::igual($classe, get_class($e), $msg !== '' ? $msg : $e->getMessage());
            return;
        }
        self::$falhas[] = self::$atual . ": esperava exceção {$classe}";
    }
}
