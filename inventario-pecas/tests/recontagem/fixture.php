<?php
declare(strict_types=1);

/**
 * Inventário fictício dos testes da recontagem: id 999999, empresa 1, revenda 1.
 * Fora da faixa real, e apagado no começo e no fim de cada teste.
 */

chdir(__DIR__ . '/../..');
require_once 'conexao_inventario.php';
require_once 'recontagem_lib.php';
require_once __DIR__ . '/../contagem2/assert.php';

const RC_INV = 999999;
const RC_EMP = 1;
const RC_REV = 1;

function rc_limpar(PDO $pdo): void
{
    $pdo->prepare("DELETE FROM estoque WHERE id_inventario = ?")->execute([RC_INV]);
    $pdo->prepare("DELETE FROM inv_inventarios WHERE id = ?")->execute([RC_INV]);
    $pdo->prepare("DELETE FROM inv_coletor_terminou WHERE id_inventario = ?")->execute([RC_INV]);
    $pdo->prepare("DELETE FROM inv_cont2_locacoes WHERE id_inventario = ?")->execute([RC_INV]);
}

/** Cria o cabeçalho do inventário de teste. */
function rc_inventario(PDO $pdo, string $status = 'aberto', int $rodadaAtual = 1): void
{
    $pdo->prepare("
        INSERT INTO inv_inventarios (id, empresa, revenda, descricao, data_inicio, status, rodada_atual, coletores_json)
        VALUES (?, ?, ?, 'TESTE RECONTAGEM', NOW(), ?, ?, ?)
    ")->execute([
        RC_INV, RC_EMP, RC_REV, $status, $rodadaAtual,
        json_encode(['1' => ['Jose'], '2' => ['Roberto']]),
    ]);
}

/**
 * Insere uma linha na estoque do inventário de teste e devolve o id.
 *
 * `loc` é "zona-rua-estante-prateleira-numero"; os campos decompostos saem dele.
 */
function rc_linha(PDO $pdo, array $d): int
{
    $loc    = $d['loc'] ?? '1-1-1-A-1';
    $partes = explode('-', $loc);

    $pdo->prepare("
        INSERT INTO estoque
            (id_inventario, empresa, revenda, item_estoque_pub, descricao_item_estoque,
             quantidade, cont_1, cont_2, cont_3, cont_4, cont2_em, cont3_em, cont4_em,
             locacao_zona, locacao_rua, locacao_estante, locacao_prateleira, locacao_numero,
             locacao_geral, situacao, coletor, rodada_item, coletor_recontagem, custo_entrada,
             recontagem_encerrada_em, recontagem_encerrada_por)
        VALUES
            (:inv, :emp, :rev, :pub, :des,
             :qtd, :c1, :c2, :c3, :c4, :c2em, :c3em, :c4em,
             :z, :r, :e, :p, :n,
             :loc, :sit, 0, :rod, :col, :custo,
             :enc_em, :enc_por)
    ")->execute([
        ':inv'   => RC_INV, ':emp' => RC_EMP, ':rev' => RC_REV,
        ':pub'   => $d['pub'],
        ':des'   => $d['des'] ?? 'DESCRICAO ' . $d['pub'],
        ':qtd'   => $d['qtd'] ?? 5,
        ':c1'    => $d['c1'] ?? 0,
        ':c2'    => $d['c2'] ?? 0,
        ':c3'    => $d['c3'] ?? null,
        ':c4'    => $d['c4'] ?? null,
        ':c2em'  => $d['c2em'] ?? null,
        ':c3em'  => $d['c3em'] ?? null,
        ':c4em'  => $d['c4em'] ?? null,
        ':z'     => $partes[0] ?? '', ':r' => $partes[1] ?? '', ':e' => $partes[2] ?? '',
        ':p'     => $partes[3] ?? '', ':n' => $partes[4] ?? '',
        ':loc'   => $loc,
        ':sit'   => $d['sit'] ?? 'A',
        ':rod'   => $d['rod'] ?? 1,
        ':col'   => $d['col'] ?? null,
        ':custo' => $d['custo'] ?? 10,
        ':enc_em'  => $d['encerrada_em'] ?? null,
        ':enc_por' => $d['encerrada_por'] ?? null,
    ]);

    return (int)$pdo->lastInsertId();
}

/** A linha inteira, como a estoque guarda. */
function rc_ler(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("SELECT * FROM estoque WHERE id_linha = ?");
    $stmt->execute([$id]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
