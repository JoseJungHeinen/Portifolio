<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

if (empty($_SESSION['inventario'])) {
    echo json_encode(['ok' => false, 'mensagem' => 'Sessão expirada']);
    exit;
}

require 'conexao_inventario.php'; // $pdo (MySQL)
require 'conexao_oracle.php';     // oracle_conectar(), oracle_buscar_item()

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$modo       = trim((string)($data['modo']       ?? 'contagem'));
$locacao    = trim((string)($data['locacao']    ?? ''));
$item       = trim((string)($data['item']       ?? ''));
$quantidade = (int)($data['quantidade'] ?? 0);
$forcar     = (bool)($data['forcar']    ?? false);

$coletor     = (int)($_SESSION['inventario']['coletor']       ?? 0);
$contagem    = (int)($_SESSION['inventario']['contagem']      ?? 1);
$idInv       = (int)($_SESSION['inventario']['id_inventario'] ?? 0);
$empresa     = (int)($_SESSION['inventario']['empresa']       ?? 0);
$revenda     = (int)($_SESSION['inventario']['revenda']       ?? 0);
$dupla_label = (string)($_SESSION['inventario']['dupla_label'] ?? ('Dupla ' . $coletor));

// Garante coluna dupla_cont2 no estoque (para rastrear quem fez a 2ª contagem)
try {
    $pdo->exec("ALTER TABLE estoque ADD COLUMN dupla_cont2 VARCHAR(120) DEFAULT NULL");
} catch (Throwable $e) { /* coluna já existe */ }

require_once 'contagem_2_lib.php';

/**
 * A tela manda o código como o leitor leu, com sufixo de variante e tudo. Qual
 * item ele representa se decide aqui, contra o que existe no inventário: a tela
 * cortava `001` às cegas e transformava MK5001 em MK5.
 */
if ($item !== '' && $idInv > 0) {
    $item = contagem2_resolver_codigo($pdo, $idInv, $item, $locacao);
}

require_once 'recontagem_lib.php';

/**
 * Trava por item. A recontagem anda por item: a 1ª contagem só grava em linha
 * que ainda está na 1ª. Vale só para os modos que escrevem; `consulta_sobra` é
 * leitura.
 *
 * Fica depois da resolução do código (acima), porque a trava precisa do código
 * que existe no inventário, não do bipe cru.
 */
if (in_array($modo, ['contagem', 'gravar_sobra'], true) && $item !== '' && $locacao !== '') {
    if (recontagem_item_encerrado($pdo, $idInv, $item, $locacao)) {
        echo json_encode([
            'ok'       => false,
            'mensagem' => 'Este item já foi encerrado e está em decisão.',
        ]);
        exit;
    }

    $rodadaItem = recontagem_rodada_do_item($pdo, $idInv, $item, $locacao);

    if ($rodadaItem !== null && $rodadaItem > 1) {
        echo json_encode([
            'ok'       => false,
            'mensagem' => 'Este item já está na ' . $rodadaItem . 'ª contagem.',
        ]);
        exit;
    }
}

/**
 * Busca dados SEMPRE do Oracle.
 * Só usa MySQL como fallback se a conexão Oracle falhar completamente.
 */
function enriquecerComOracle(array $dadosMySQL, int $empresa, int $revenda, string $item): array {
    if ($empresa <= 0 || $revenda <= 0 || $item === '') {
        $dadosMySQL['fonte'] = 'mysql';
        return $dadosMySQL;
    }

    $ora = oracle_conectar();

    // Oracle indisponível → fallback MySQL
    if (!$ora) {
        $dadosMySQL['fonte'] = 'mysql_fallback';
        return $dadosMySQL;
    }

    try {
        $dadosOracle = oracle_buscar_item($ora, $empresa, $revenda, $item);
        oci_close($ora);

        if ($dadosOracle) {
            // Sempre sobrescreve com Oracle
            $dadosMySQL['quantidade']    = $dadosOracle['quantidade'];
            $dadosMySQL['custo_entrada'] = $dadosOracle['custo_entrada'];
            $dadosMySQL['class_abc']     = $dadosOracle['class_abc'];
            $dadosMySQL['fonte']         = 'oracle';
        } else {
            // Item não encontrado no Oracle → mantém MySQL
            $dadosMySQL['fonte'] = 'mysql_fallback';
        }
    } catch (Throwable $e) {
        if (isset($ora) && $ora) @oci_close($ora);
        $dadosMySQL['fonte'] = 'mysql_fallback';
    }

    return $dadosMySQL;
}

// -------------------------------------------------------
// MODO: consulta_sobra
// -------------------------------------------------------
if ($modo === 'consulta_sobra') {
    if ($item === '') { echo json_encode(['ok' => false, 'mensagem' => 'Item vazio']); exit; }
    if ($idInv <= 0)  { echo json_encode(['ok' => false, 'mensagem' => 'Inventário não definido']); exit; }

    try {
        $stmt = $pdo->prepare("
            SELECT locacao_geral,
                   quantidade         AS saldo_estoque,
                   custo_entrada      AS valor_uni,
                   class_abc          AS abc,
                   COALESCE(cont_1,0) AS cont_1
              FROM estoque
             WHERE item_estoque_pub = :item
               AND id_inventario    = :id_inv
             ORDER BY locacao_geral
        ");
        $stmt->execute([':item' => $item, ':id_inv' => $idInv]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            echo json_encode(['ok' => true, 'encontrado' => false,
                'mensagem' => 'Item não encontrado no inventário']);
            exit;
        }

        // Enriquece com Oracle (usa dados da primeira linha como base)
        $dadosBase = [
            'quantidade'    => (int)$rows[0]['saldo_estoque'],
            'custo_entrada' => (float)$rows[0]['valor_uni'],
            'class_abc'     => $rows[0]['abc'],
        ];
        $dadosBase = enriquecerComOracle($dadosBase, $empresa, $revenda, $item);

        $r = $rows[0];
        echo json_encode([
            'ok'             => true,
            'encontrado'     => true,
            'locacao'        => $r['locacao_geral'],
            'saldo_estoque'  => $dadosBase['quantidade'],
            'valor_uni'      => $dadosBase['custo_entrada'],
            'abc'            => $dadosBase['class_abc'],
            'cont_1'         => (int)$r['cont_1'],
            'todas_locacoes' => array_column($rows, 'locacao_geral'),
            'fonte'          => $dadosBase['fonte'] ?? 'mysql',
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => 'Erro ao consultar sobra']);
    }
    exit;
}

// -------------------------------------------------------
// MODO: gravar_sobra
// -------------------------------------------------------
if ($modo === 'gravar_sobra') {
    if ($item === '' || $locacao === '' || $quantidade <= 0) {
        echo json_encode(['ok' => false, 'mensagem' => 'Dados inválidos']); exit;
    }
    if ($idInv <= 0) { echo json_encode(['ok' => false, 'mensagem' => 'Inventário não definido']); exit; }

    try {
        // Recontagem por item: quem grava cont_2 é o contagem_2_ajax.php, com
        // coletor designado e data. Este endpoint é sempre da 1ª contagem.
        $campoContagem = 'cont_1';
        $stmt = $pdo->prepare("
            UPDATE estoque
               SET $campoContagem = COALESCE($campoContagem, 0) + :qtd,
                   coletor        = :coletor,
                   situacao       = 'C'
             WHERE item_estoque_pub = :item
               AND locacao_geral    = :loc
               AND id_inventario    = :id_inv
               AND rodada_item      = 1
               AND recontagem_encerrada_em IS NULL
             LIMIT 1
        ");
        $stmt->execute([
            ':qtd'     => $quantidade,
            ':coletor' => $coletor,
            ':item'    => $item,
            ':loc'     => $locacao,
            ':id_inv'  => $idInv,
        ]);
        echo json_encode([
            'ok'       => $stmt->rowCount() > 0,
            'mensagem' => $stmt->rowCount() > 0 ? 'Sobra gravada com sucesso.' : 'Nenhuma linha atualizada.',
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => 'Erro ao gravar sobra']);
    }
    exit;
}

// -------------------------------------------------------
// MODO: contagem (padrão)
// -------------------------------------------------------
if ($locacao === '') { echo json_encode(['ok' => false, 'mensagem' => 'Locação vazia']); exit; }
if ($item === '')    { echo json_encode(['ok' => false, 'mensagem' => 'Item vazio']); exit; }
if ($quantidade <= 0){ echo json_encode(['ok' => false, 'mensagem' => 'Quantidade inválida']); exit; }
if ($idInv <= 0)     { echo json_encode(['ok' => false, 'mensagem' => 'Inventário não definido']); exit; }

try {
    // 1) Valida item na locação
    $stmt = $pdo->prepare("
        SELECT item_estoque_pub, locacao_geral, cont_1, cont_2,
               quantidade AS saldo_estoque, custo_entrada, class_abc
          FROM estoque
         WHERE locacao_geral    = :loc
           AND item_estoque_pub = :item
           AND id_inventario    = :id_inv
         LIMIT 1
    ");
    $stmt->execute([':loc' => $locacao, ':item' => $item, ':id_inv' => $idInv]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $stmt2 = $pdo->prepare("
            SELECT DISTINCT locacao_geral
              FROM estoque
             WHERE item_estoque_pub = :item
               AND id_inventario    = :id_inv
             ORDER BY locacao_geral
        ");
        $stmt2->execute([':item' => $item, ':id_inv' => $idInv]);
        $locs = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode([
            'ok'                => false,
            'mensagem'          => $locs ? 'ITEM NÃO PERTENCE A ESTA LOCAÇÃO.' : 'ITEM NÃO PERTENCE AO INVENTÁRIO.',
            'locacoes_corretas' => $locs ?: [],
        ]);
        exit;
    }

    // 2) Verifica se já foi contado
    // Recontagem por item: quem grava cont_2 é o contagem_2_ajax.php, com
    // coletor designado e data. Este endpoint é sempre da 1ª contagem.
    $campoContagem = 'cont_1';
    $qtdJaContada  = (int)($row[$campoContagem] ?? 0);

    if ($qtdJaContada > 0 && !$forcar) {
        // Enriquece com Oracle antes de retornar
        $dadosBase = [
            'quantidade'    => (int)$row['saldo_estoque'],
            'custo_entrada' => (float)$row['custo_entrada'],
            'class_abc'     => $row['class_abc'],
        ];
        $dadosBase = enriquecerComOracle($dadosBase, $empresa, $revenda, $item);

        echo json_encode([
            'ok'            => false,
            'ja_contado'    => true,
            'qtd_anterior'  => $qtdJaContada,
            'saldo_estoque' => $dadosBase['quantidade'],
            'valor_uni'     => $dadosBase['custo_entrada'],
            'abc'           => $dadosBase['class_abc'],
            'fonte'         => $dadosBase['fonte'] ?? 'mysql',
            'mensagem'      => "Item já contado ({$qtdJaContada} un). Confirme para recontar.",
        ]);
        exit;
    }

    // 3) Grava
    $stmt = $pdo->prepare("
        UPDATE estoque
           SET $campoContagem = COALESCE($campoContagem, 0) + :qtd,
               coletor        = :coletor,
               situacao       = 'C'
         WHERE locacao_geral    = :loc
           AND item_estoque_pub = :item
           AND id_inventario    = :id_inv
           AND rodada_item      = 1
           AND recontagem_encerrada_em IS NULL
         LIMIT 1
    ");
    $params = [
        ':qtd'     => $quantidade,
        ':coletor' => $coletor,
        ':loc'     => $locacao,
        ':item'    => $item,
        ':id_inv'  => $idInv,
    ];
    $stmt->execute($params);

    echo json_encode([
        'ok'       => $stmt->rowCount() > 0,
        'mensagem' => $stmt->rowCount() > 0 ? 'gravado' : 'nenhuma linha atualizada',
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensagem' => 'Erro ao salvar a contagem']);
}