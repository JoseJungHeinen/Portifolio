<?php
declare(strict_types=1);

/**
 * Parte "de banco" do gerador de inventário, separada do arquivo principal
 * para poder ser testada sem $_SESSION, sem echo e sem depender do Oracle.
 */

/**
 * Salva os nomes dos coletores na tabela inv_coletores.
 * Movida de gerador_inventario.php: só este arquivo a define agora.
 */
function salvarColetoresNaTabela(PDO $pdo, int $empresa, int $revenda, int $idInventario, array $coletoresGrupos): void {
    // Remove registros antigos deste inventário
    $pdo->prepare("
        DELETE FROM inv_coletores
         WHERE empresa = :e AND revenda = :r AND id_inventario = :inv
    ")->execute([':e' => $empresa, ':r' => $revenda, ':inv' => $idInventario]);

    // Insere os novos
    $stmtIns = $pdo->prepare("
        INSERT INTO inv_coletores (empresa, revenda, id_inventario, coletor_num, nome)
        VALUES (:e, :r, :inv, :num, :nome)
    ");

    foreach ($coletoresGrupos as $num => $nomes) {
        $nomeStr = is_array($nomes) ? implode(' e ', $nomes) : (string)$nomes;
        if ($nomeStr !== '') {
            $stmtIns->execute([
                ':e'    => $empresa,
                ':r'    => $revenda,
                ':inv'  => $idInventario,
                ':num'  => (int)$num,
                ':nome' => $nomeStr,
            ]);
        }
    }
}

/**
 * Monta [num => [nome1, nome2?]] a partir do POST, do mesmo jeito que o
 * código antigo fazia inline em criar_inventario/salvar_tudo.
 */
function gerador_coletores_do_post(array $post): array {
    $coletores = [];
    for ($i = 1; $i <= 8; $i++) {
        $n1 = trim((string)($post["coletor{$i}_nome1"] ?? ''));
        $n2 = trim((string)($post["coletor{$i}_nome2"] ?? ''));
        $nomes = [];
        if ($n1 !== '') { $nomes[] = $n1; }
        if ($n2 !== '') { $nomes[] = $n2; }
        if (!empty($nomes)) {
            $coletores[$i] = $nomes;
        }
    }
    return $coletores;
}

/**
 * Valida se é seguro criar o inventário agora. Retorna a mensagem de erro,
 * ou null se estiver tudo certo.
 *
 * A ordem dos checks importa: loja mudou > sem itens > sem coletor, igual
 * ao fluxo descrito para a tela.
 */
function gerador_validar_criacao(?int $lojaDaBusca, int $lojaAtual, int $qtdItens, array $coletores): ?string {
    // Trava principal: os itens em sessão pertencem a outra loja (ou nenhuma
    // busca foi feita ainda), então não podem ser gravados nesta loja.
    if ($lojaDaBusca === null || $lojaDaBusca !== $lojaAtual) {
        return 'A loja mudou depois da busca. Clique em Buscar itens de novo.';
    }
    if ($qtdItens <= 0) {
        return 'Não há itens carregados. Clique em Buscar itens antes de criar o inventário.';
    }
    if (empty($coletores)) {
        return 'Informe ao menos um coletor.';
    }
    return null;
}

/**
 * Cria o inventário inteiro (cabeçalho + coletores + estoque) em uma única
 * transação. Lança exceção e desfaz tudo se qualquer INSERT falhar, para
 * nunca deixar um inventário pela metade.
 *
 * @return array{id:int, itens:int}
 */
function gerador_criar_inventario(PDO $pdo, int $empresa, int $revenda, array $itens, array $coletores): array {
    $pdo->beginTransaction();
    try {
        $coletoresJson = json_encode($coletores, JSON_UNESCAPED_UNICODE);

        $stmtInv = $pdo->prepare("INSERT INTO inv_inventarios (empresa,revenda,descricao,data_inicio,data_fim,status,coletores_json) VALUES (:empresa,:revenda,:descricao,NOW(),NULL,:status,:coletores_json)");
        $stmtInv->execute([
            ':empresa'       => $empresa,
            ':revenda'       => $revenda,
            ':descricao'     => 'Inventário gerado via sistema',
            ':status'        => 'aberto',
            ':coletores_json'=> $coletoresJson,
        ]);
        $idInventario = (int)$pdo->lastInsertId();

        salvarColetoresNaTabela($pdo, $empresa, $revenda, $idInventario, $coletores);

        // Mesmo INSERT e mapeamento de campos do antigo salvar_tudo.
        $stmtE = $pdo->prepare("INSERT INTO estoque (id_inventario,empresa,revenda,cod_item_estoque,item_estoque_pub,item_pub_revenda,descricao_item_estoque,quantidade,locacao_zona,locacao_rua,locacao_estante,locacao_prateleira,locacao_numero,locacao_geral,class_abc,custo_entrada,preco_alternativo,cont_1,cont_2,situacao,coletor) VALUES (:id_inventario,:empresa,:revenda,:cod,:pub,:pubrev,:des,:qtd,:zona,:rua,:est,:prat,:num,:geral,:abc,:custo,:preco,0,0,'A',0)");
        $qtInseridos = 0;
        foreach ($itens as $it) {
            $stmtE->execute([
                ':id_inventario' => $idInventario,
                ':empresa'       => $empresa,
                ':revenda'       => $revenda,
                ':cod'           => $it['ITEM_ESTOQUE'] ?? null,
                ':pub'           => $it['ITEM_ESTOQUE_PUB'] ?? null,
                ':pubrev'        => $it['ITEM_PUB_REVENDA'] ?? null,
                ':des'           => $it['DES_ITEM_ESTOQUE'] ?? null,
                ':qtd'           => $it['QUANTIDADE'] ?? 0,
                ':zona'          => $it['LOCACAO_ZONA'] ?? null,
                ':rua'           => $it['LOCACAO_RUA'] ?? null,
                ':est'           => $it['LOCACAO_ESTANTE'] ?? null,
                ':prat'          => $it['LOCACAO_PRATELEIRA'] ?? null,
                ':num'           => $it['LOCACAO_NUMERO'] ?? null,
                ':geral'         => $it['LOCACAO_GERAL'] ?? null,
                ':abc'           => $it['CLASS_ABC'] ?? null,
                ':custo'         => $it['CUS_ULT_ENTRADA'] ?? 0,
                ':preco'         => $it['PRECO_ALTERNATIVO'] ?? 0,
            ]);
            $qtInseridos++;
        }

        $pdo->commit();
        return ['id' => $idInventario, 'itens' => $qtInseridos];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
