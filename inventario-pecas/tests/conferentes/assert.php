<?php
declare(strict_types=1);

/**
 * Mini-assert sem dependência externa, para os testes da conferência.
 * Uso: require dos testes, chamar t_eq/t_true/t_null e terminar com t_fim().
 */

$GLOBALS['T_OK']   = 0;
$GLOBALS['T_FAIL'] = 0;

function t_ok(string $nome): void
{
    $GLOBALS['T_OK']++;
    echo "  ok   $nome\n";
}

function t_falhou(string $nome, string $detalhe): void
{
    $GLOBALS['T_FAIL']++;
    echo "  FAIL $nome\n       $detalhe\n";
}

function t_eq(string $nome, $esperado, $obtido): void
{
    if ($esperado === $obtido) {
        t_ok($nome);
        return;
    }
    t_falhou($nome, 'esperado ' . var_export($esperado, true) . ', obtido ' . var_export($obtido, true));
}

function t_true(string $nome, $valor): void
{
    $valor === true ? t_ok($nome) : t_falhou($nome, 'esperado true, obtido ' . var_export($valor, true));
}

function t_null(string $nome, $valor): void
{
    $valor === null ? t_ok($nome) : t_falhou($nome, 'esperado null, obtido ' . var_export($valor, true));
}

function t_fim(): void
{
    echo "\n{$GLOBALS['T_OK']} ok, {$GLOBALS['T_FAIL']} falhas\n";
    exit($GLOBALS['T_FAIL'] > 0 ? 1 : 0);
}
