<?php
// tests/test_federated_auth.php
// Rodar com: php tests/test_federated_auth.php
require __DIR__ . '/../includes/federated_auth.php';

$falhas = 0;

function checar(string $descricao, bool $condicao): void
{
    global $falhas;
    if ($condicao) {
        echo "OK  - $descricao\n";
    } else {
        echo "FAIL - $descricao\n";
        $falhas++;
    }
}

$hashBcrypt = password_hash('minhaSenha123', PASSWORD_DEFAULT);

checar(
    'bcrypt: senha correta valida',
    verificar_senha('minhaSenha123', $hashBcrypt, 'bcrypt') === true
);
checar(
    'bcrypt: senha errada não valida',
    verificar_senha('senhaErrada', $hashBcrypt, 'bcrypt') === false
);
checar(
    'plaintext: comparação direta correta',
    verificar_senha('abc123', 'abc123', 'plaintext') === true
);
checar(
    'plaintext: comparação direta incorreta',
    verificar_senha('abc123', 'outraCoisa', 'plaintext') === false
);
checar(
    'hash vazio/nulo nunca valida',
    verificar_senha('qualquer', null, 'bcrypt') === false
);

if ($falhas > 0) {
    echo "\n$falhas teste(s) falharam.\n";
    exit(1);
}
echo "\nTodos os testes passaram.\n";
