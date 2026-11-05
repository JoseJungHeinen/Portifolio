<?php
declare(strict_types=1);

require_once __DIR__ . '/conferencia_lib.php';

/**
 * realocacao_lib.php
 *
 * Regras da Realocação: dar locação a item que chegou sem nenhuma. Bipa o item,
 * bipa a etiqueta da prateleira, grava no Apollo.
 *
 * Só item sem locação: trocar a locação de quem já tem é o ajuste da
 * conferência, que mostra a locação atual e pede a etiqueta. Aqui não há o que
 * conferir antes, então aceitar troca abriria um atalho para mudar locação sem
 * olhar onde a peça estava.
 *
 * Mesma regra do conferencia_lib.php: nada de sessão, saída ou header().
 */

/**
 * Locação indefinida no Apollo. As cinco colunas são NOT NULL, então item sem
 * locação não fica em branco: fica num marcador. Dois tipos:
 *
 * - zona ZZ, com qualquer resto: o padrão é ZZ-99-99-ZZ-99 (~217 mil linhas
 *   nas quatro lojas em 17/10/2026), e há variações como ZZ-99-999-ZZZ-999,
 *   ZZ-1-1-1-1 e ZZ-11-11-ZZ-11. A zona tem 2 letras no Apollo, então "começa
 *   com ZZ" é a zona inteira;
 * - A-1-1-A-1, a locação de cadastro (~1.900 linhas).
 *
 * Nenhuma linha vazia ou zerada.
 */
const REALOCACAO_ZONA_INDEFINIDA    = 'ZZ';
const REALOCACAO_LOCACAO_INDEFINIDA = ['zona' => 'A', 'rua' => 1, 'estante' => 1, 'prateleira' => 'A', 'numero' => 1];

/** A locação (no formato de conferencia_locacao_da_loja) é indefinida. */
function realocacao_eh_sem_locacao(array $loc): bool
{
    $zona = strtoupper(trim((string)($loc['zona'] ?? '')));
    if ($zona === REALOCACAO_ZONA_INDEFINIDA) {
        return true;
    }

    $ind = REALOCACAO_LOCACAO_INDEFINIDA;
    return $zona === $ind['zona']
        && (int)($loc['rua'] ?? -1) === $ind['rua']
        && (int)($loc['estante'] ?? -1) === $ind['estante']
        && strtoupper(trim((string)($loc['prateleira'] ?? ''))) === $ind['prateleira']
        && (int)($loc['numero'] ?? -1) === $ind['numero'];
}

function realocacao_juntar(array $v): string
{
    return implode('-', [$v['zona'], $v['rua'], $v['estante'], $v['prateleira'], $v['numero']]);
}

/**
 * O item bipado pode ser realocado nesta loja?
 *
 * Devolve ok + item quando sim; senão, o motivo em texto para a tela. O item é
 * sempre resolvido aqui, pelo código bipado: o servidor nunca aceita o código
 * interno pronto do formulário.
 *
 * @return array{ok:bool, erro:string, item:?array, item_estoque:?int, atual:?array}
 * @throws RuntimeException se o Apollo recusar a consulta.
 */
function realocacao_checar_item($ora, int $empresa, int $revenda, string $codigo): array
{
    $saida = ['ok' => false, 'erro' => '', 'item' => null, 'item_estoque' => null, 'atual' => null];
    $codigo = trim($codigo);

    if ($codigo === '') {
        $saida['erro'] = 'Bipe o código do item.';
        return $saida;
    }

    $itemEstoque = conferencia_resolver_item($ora, $empresa, $codigo);
    if ($itemEstoque === null) {
        // O engano comum: bipar a prateleira antes da peça.
        $saida['erro'] = conferencia_ler_etiqueta_locacao($codigo) !== null
            ? '"' . $codigo . '" é uma etiqueta de locação. Bipe primeiro o item.'
            : 'Item ' . $codigo . ' não encontrado no Apollo.';
        return $saida;
    }

    $busca = conferencia_buscar_oracle($ora, $empresa, $revenda, [], $codigo);
    $saida['item']         = $busca['item'] ?? null;
    $saida['item_estoque'] = $itemEstoque;

    $atual = conferencia_locacao_da_loja($ora, $empresa, $revenda, $itemEstoque);
    $saida['atual'] = $atual;

    if ($atual === null) {
        // Sem linha em pec_item_revenda o UPDATE não teria onde gravar, e criar
        // a linha é cadastro de estoque, não locação.
        $saida['erro'] = 'Este item não tem cadastro de estoque nesta loja. Peça o cadastro à gestão.';
        return $saida;
    }

    if (!realocacao_eh_sem_locacao($atual)) {
        $saida['erro'] = 'Este item já tem locação: ' . realocacao_juntar($atual)
            . '. A Realocação é só para item sem locação — para trocar, use o ajuste da Conferência.';
        return $saida;
    }

    $saida['ok'] = true;
    return $saida;
}

/**
 * Valida a locação nova: as regras das colunas do Apollo, e não pode ser
 * uma locação indefinida (gravaria "nada" e o rastro diria que houve
 * realocação).
 *
 * @return array{valores: array<string, int|string>, erros: array<string, string>}
 */
function realocacao_validar_locacao(array $entrada): array
{
    $validacao = conferencia_validar_locacao($entrada);

    if ($validacao['erros'] === [] && realocacao_eh_sem_locacao($validacao['valores'])) {
        $validacao['erros']['zona'] = realocacao_juntar($validacao['valores'])
            . ' é locação indefinida (zona ZZ ou A-1-1-A-1). Bipe a etiqueta da prateleira.';
    }

    return $validacao;
}

/**
 * Como a entrada vai para o rastro: um registro só por realocação, então se o
 * item OU a locação foram digitados, fica 'digitado'. Digitação é a exceção que
 * o rastro existe para mostrar; dizer 'bipado' esconderia metade dela.
 */
function realocacao_entrada(string $entradaItem, string $entradaLocacao): string
{
    $item = conferencia_exigir_entrada($entradaItem);
    $loc  = conferencia_exigir_entrada($entradaLocacao);

    return ($item === 'digitado' || $loc === 'digitado') ? 'digitado' : 'bipado';
}
