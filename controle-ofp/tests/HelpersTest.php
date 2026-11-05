<?php
declare(strict_types=1);

T::teste('parse_decimal aceita formato BR e US', function () {
    T::igual(1500.0, parse_decimal('1.500,00'));
    T::igual(1500.5, parse_decimal('1500,50'));
    T::igual(12.3, parse_decimal('12.3'));
    T::igual(0.0, parse_decimal(''));
});

T::teste('money formata em reais', fn() => T::igual('R$ 1.234,50', money(1234.5)));

T::teste('escopo de unidades vira SQL', function () {
    $_SESSION['unidades'] = null;
    T::igual(['1=1', []], sql_unidades('o.unidade_id'));
    $_SESSION['unidades'] = [];
    T::igual(['1=0', []], sql_unidades('o.unidade_id'));
    $_SESSION['unidades'] = ['a', 'b'];
    T::igual(['o.unidade_id IN (?,?)', ['a', 'b']], sql_unidades('o.unidade_id'));
    T::verdadeiro(unidade_permitida('a'));
    T::verdadeiro(!unidade_permitida('c'));
    $_SESSION['unidades'] = null;
    T::verdadeiro(unidade_permitida('qualquer'));
});

T::teste('rota inicial por perfil', function () {
    T::igual('/pendencias', rota_inicial('estoque'));
    T::igual('/pendencias', rota_inicial('comprador'));
    T::igual('/painel', rota_inicial('diretor'));
    T::igual('/painel', rota_inicial('financeiro'));
});

T::teste('parse_decimal trata pontos de milhar BR', function () {
    T::igual(1234.0, parse_decimal('1.234'));
    T::igual(1500000.0, parse_decimal('1.500.000'));
    T::igual(12.3, parse_decimal('12.3'));
    T::igual(-1234.0, parse_decimal('-1.234'));
});

T::teste('bcrypt_cost limita o custo entre 10 e 14', function () {
    $antes = $_ENV['BCRYPT_COST'] ?? null;
    foreach ([['4', 10], ['12', 12], ['31', 14], ['abc', 10]] as [$in, $out]) {
        $_ENV['BCRYPT_COST'] = $in;
        T::igual($out, bcrypt_cost());
    }
    if ($antes === null) {
        unset($_ENV['BCRYPT_COST']);
    } else {
        $_ENV['BCRYPT_COST'] = $antes;
    }
});

T::teste('limpar_utf8 troca bytes inválidos, recursivo, e preserva senhas', function () {
    $r = limpar_utf8(['nome' => "Jos\xE9", 'ok' => 'José', 'obs' => ['x' => "a\xFFb"], 'senha' => "p\xE9", 'nova_password' => "q\xE9", 'n' => 5]);
    T::verdadeiro(preg_match('//u', $r['nome']) === 1);
    T::igual('José', $r['ok']);
    T::verdadeiro(preg_match('//u', $r['obs']['x']) === 1);
    T::igual("p\xE9", $r['senha']);
    T::igual("q\xE9", $r['nova_password']);
    T::igual(5, $r['n']);
});
