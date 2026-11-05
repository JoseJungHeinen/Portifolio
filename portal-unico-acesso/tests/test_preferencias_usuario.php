<?php
// tests/test_preferencias_usuario.php
// Rodar com: php tests/test_preferencias_usuario.php
require __DIR__ . '/../includes/preferencias_usuario.php';

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

$emailTeste = 'teste.ordem+especial@exemplo.com';
$arquivoEsperado = caminho_arquivo_ordem($emailTeste);
@unlink($arquivoEsperado);

checar(
    'sem arquivo salvo ainda, retorna lista vazia',
    carregar_ordem_sistemas($emailTeste) === []
);

salvar_ordem_sistemas($emailTeste, ['compras', 'estoque', 'projeto']);

checar(
    'arquivo sanitizado foi criado no caminho esperado',
    file_exists($arquivoEsperado)
);

checar(
    'ordem salva é lida de volta corretamente',
    carregar_ordem_sistemas($emailTeste) === ['compras', 'estoque', 'projeto']
);

checar(
    'filtrar_ordem_valida remove ids que não existem na lista de válidos',
    filtrar_ordem_valida(['compras', 'lixo', 'estoque', 'outro_lixo'], ['compras', 'estoque', 'projeto'])
        === ['compras', 'estoque']
);

checar(
    'filtrar_ordem_valida com lista vazia retorna vazio',
    filtrar_ordem_valida([], ['compras', 'estoque']) === []
);

$sistemas = ['a' => ['x'=>1], 'b' => ['x'=>2], 'c' => ['x'=>3], 'd' => ['x'=>4]];

checar(
    'aplicar_ordem_sistemas: ordem salva parcial, restantes vão pro final na ordem original',
    array_keys(aplicar_ordem_sistemas($sistemas, ['c', 'a'])) === ['c', 'a', 'b', 'd']
);

checar(
    'aplicar_ordem_sistemas: id órfão na ordem salva é ignorado',
    array_keys(aplicar_ordem_sistemas($sistemas, ['c', 'sistema_removido', 'a'])) === ['c', 'a', 'b', 'd']
);

checar(
    'aplicar_ordem_sistemas: ordem salva vazia mantém ordem original',
    array_keys(aplicar_ordem_sistemas($sistemas, [])) === ['a', 'b', 'c', 'd']
);

@unlink($arquivoEsperado);

if ($falhas > 0) {
    echo "\n$falhas teste(s) falharam.\n";
    exit(1);
}
echo "\nTodos os testes passaram.\n";
