<?php
/**
 * includes/preferencias_usuario.php
 *
 * Ordem escolhida pelo usuário pra exibição dos cards de sistemas em
 * index.php — persistida por e-mail em data/ordens/<email-sanitizado>.json,
 * não em localStorage, pra acompanhar o usuário em qualquer navegador.
 */

function caminho_arquivo_ordem(string $email): string
{
    $nomeArquivo = preg_replace('/[^a-zA-Z0-9]+/', '_', $email);
    return __DIR__ . '/../data/ordens/' . $nomeArquivo . '.json';
}

/**
 * @return string[] lista de ids de sistema, na ordem salva (vazia se nunca salvou)
 */
function carregar_ordem_sistemas(string $email): array
{
    $arquivo = caminho_arquivo_ordem($email);
    if (!file_exists($arquivo)) {
        return [];
    }

    $conteudo = file_get_contents($arquivo);
    $ordem    = json_decode($conteudo, true);

    return is_array($ordem) ? $ordem : [];
}

/**
 * @param string[] $ordemIds
 */
function salvar_ordem_sistemas(string $email, array $ordemIds): void
{
    $arquivo = caminho_arquivo_ordem($email);
    $pasta   = dirname($arquivo);

    if (!is_dir($pasta)) {
        mkdir($pasta, 0777, true);
    }

    file_put_contents($arquivo, json_encode(array_values($ordemIds)));
}

/**
 * Mantém, na ordem recebida, só os ids que realmente existem em $idsValidos —
 * proteção simples contra ids inventados vindos do cliente.
 *
 * @param string[] $ordemRecebida
 * @param string[] $idsValidos
 * @return string[]
 */
function filtrar_ordem_valida(array $ordemRecebida, array $idsValidos): array
{
    return array_values(array_filter(
        $ordemRecebida,
        fn($id) => in_array($id, $idsValidos, true)
    ));
}

/**
 * Reordena $sistemas (array associativo id => config) de acordo com a ordem
 * salva: ids salvos primeiro (nessa ordem), depois qualquer id de $sistemas
 * que não estava na lista salva, na ordem original entre eles.
 *
 * @param array<string,array> $sistemas
 * @param string[] $ordemSalva
 * @return array<string,array>
 */
function aplicar_ordem_sistemas(array $sistemas, array $ordemSalva): array
{
    $resultado = [];

    foreach ($ordemSalva as $id) {
        if (isset($sistemas[$id])) {
            $resultado[$id] = $sistemas[$id];
        }
    }

    foreach ($sistemas as $id => $config) {
        if (!isset($resultado[$id])) {
            $resultado[$id] = $config;
        }
    }

    return $resultado;
}
