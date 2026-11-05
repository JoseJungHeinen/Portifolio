<?php
declare(strict_types=1);
if (function_exists('opcache_invalidate')) opcache_invalidate(__FILE__, true);

// Captura erros fatais e retorna como JSON
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'mensagem' => 'Erro fatal: ' . $err['message'] . ' em ' . $err['file'] . ':' . $err['line']]);
    }
});
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'mensagem' => 'Exceção: ' . $e->getMessage()]);
});

session_start();
header('Content-Type: application/json');

if (empty($_SESSION['inventario_login'])) {
    echo json_encode(['ok' => false, 'mensagem' => 'Sessão expirada']);
    exit;
}

require 'conexao_inventario.php';
require 'conexao_oracle.php';
require_once 'conferencia_lib.php'; // resolução do código bipado (sufixo, zeros do leitor)

$modo    = trim($_GET['modo'] ?? $_POST['modo'] ?? '');
$usuario = (string)($_SESSION['inventario_login']['usuario'] ?? 'sistema');

// -------------------------------------------------------
// Garante tabela inv_decisoes
// -------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inv_decisoes (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            id_inventario   INT NOT NULL,
            empresa         INT NOT NULL,
            revenda         INT NOT NULL,
            item_estoque_pub VARCHAR(50) NOT NULL,
            decisao         ENUM('ajuste_positivo','ajuste_negativo','autorizar','ajuste_final','desconsiderar') NOT NULL,
            qtd_ajuste      INT DEFAULT 0,
            observacao      TEXT,
            usuario         VARCHAR(100),
            criado_em       DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_inv_item (id_inventario, item_estoque_pub)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {
    // Ignora — tabela pode já existir ou usuário sem permissão de CREATE
}

// Garante que coluna decisao tem o valor ajuste_negativo (ALTER para tabelas existentes)
try {
    $pdo->exec("
        ALTER TABLE inv_decisoes
        MODIFY COLUMN decisao ENUM('ajuste_positivo','ajuste_negativo','autorizar','ajuste_final','desconsiderar') NOT NULL
    ");
} catch (Throwable $e) {
    // Ignora se já estiver atualizado ou sem permissão
}

// Garante coluna item_pub_revenda na tabela estoque
try {
    $pdo->exec("ALTER TABLE estoque ADD COLUMN item_pub_revenda VARCHAR(50) NULL DEFAULT NULL");
} catch (Throwable $e) {
    // Ignora se já existir
}

// -------------------------------------------------------
// MODO: lojas — lista todas as lojas ativas
// -------------------------------------------------------
if ($modo === 'lojas') {
    try {
        $stmt = $pdo->query("
            SELECT
                l.id,
                l.empresa AS nome_empresa,
                l.revenda AS nome_revenda,
                l.codigo,
                CAST(SUBSTRING_INDEX(l.codigo, '.', 1)  AS UNSIGNED) AS emp_num,
                CAST(SUBSTRING_INDEX(l.codigo, '.', -1) AS UNSIGNED) AS rev_num
            FROM inv_lojas l
            WHERE l.ativo = 1
            ORDER BY l.codigo
        ");
        $lojas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'lojas' => $lojas]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => 'Erro ao buscar lojas: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------
// MODO: inventarios — lista inventários de uma loja
//   GET: empresa, revenda
// -------------------------------------------------------
if ($modo === 'inventarios') {
    $empresa = (int)($_GET['empresa'] ?? 0);
    $revenda = (int)($_GET['revenda'] ?? 0);
    if ($empresa <= 0 || $revenda <= 0) {
        echo json_encode(['ok' => false, 'mensagem' => 'Parâmetros inválidos']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT id, descricao, data_inicio, status
            FROM inv_inventarios
            WHERE empresa = :empresa AND revenda = :revenda
            ORDER BY data_inicio DESC
        ");
        $stmt->execute([':empresa' => $empresa, ':revenda' => $revenda]);
        $inventarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'inventarios' => $inventarios]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => 'Erro ao buscar inventários']);
    }
    exit;
}

// -------------------------------------------------------
// MODO: itens — itens problemáticos (saldo=0 ou não contado)
//   GET: empresa, revenda, tipo (todos|saldo_zero|nao_contado), id_inventario
// -------------------------------------------------------
if ($modo === 'itens') {
    $empresa = (int)($_GET['empresa'] ?? 0);
    $revenda = (int)($_GET['revenda'] ?? 0);
    $tipo    = trim($_GET['tipo'] ?? 'todos');
    $idInv   = (int)($_GET['id_inventario'] ?? 0);

    if ($empresa <= 0 || $revenda <= 0 || $idInv <= 0) {
        echo json_encode(['ok' => false, 'mensagem' => 'Parâmetros inválidos']);
        exit;
    }

    try {
        if ($tipo === 'saldo_zero') {
            $condicaoTipo = 'e.quantidade = 0';
        } elseif ($tipo === 'nao_contado') {
            $condicaoTipo = "(e.situacao = 'A' AND e.cont_1 = 0 AND e.cont_2 = 0)";
        } else {
            $condicaoTipo = "(e.quantidade = 0 OR (e.situacao = 'A' AND e.cont_1 = 0 AND e.cont_2 = 0) OR d.decisao IN ('ajuste_positivo', 'ajuste_negativo'))";
        }

        $stmt = $pdo->prepare("
            SELECT
                e.item_estoque_pub,
                e.item_pub_revenda,
                e.cod_item_estoque,
                e.descricao_item_estoque,
                e.quantidade         AS saldo_estoque,
                e.class_abc,
                e.locacao_geral,
                e.situacao,
                e.custo_entrada,
                e.cont_1,
                e.cont_2,
                e.empresa,
                e.revenda,
                e.id_inventario,
                CASE
                    WHEN e.quantidade = 0                                    THEN 'saldo_zero'
                    WHEN e.situacao = 'A' AND e.cont_1 = 0 AND e.cont_2 = 0 THEN 'nao_contado'
                    ELSE 'outro'
                END AS tipo_item,
                d.decisao AS decisao_registrada,
                d.qtd_ajuste AS qtd_ajuste_existente,
                d.observacao AS obs_existente,
                CASE
                    WHEN d.decisao = 'ajuste_positivo' THEN ABS(COALESCE(d.qtd_ajuste, 0))
                    WHEN d.decisao = 'ajuste_negativo' THEN -ABS(COALESCE(d.qtd_ajuste, 0))
                    ELSE 0
                END AS ajuste_saldo,
                e.quantidade + CASE
                    WHEN d.decisao = 'ajuste_positivo' THEN ABS(COALESCE(d.qtd_ajuste, 0))
                    WHEN d.decisao = 'ajuste_negativo' THEN -ABS(COALESCE(d.qtd_ajuste, 0))
                    ELSE 0
                END AS saldo_ajustado
            FROM estoque e
            LEFT JOIN inv_decisoes d
                ON d.id_inventario    = e.id_inventario
               AND d.empresa          = e.empresa
               AND d.revenda          = e.revenda
               AND d.item_estoque_pub = e.item_estoque_pub
            WHERE e.empresa       = :empresa
              AND e.revenda       = :revenda
              AND e.id_inventario = :id_inv
              AND $condicaoTipo
            ORDER BY e.locacao_geral, e.item_estoque_pub
            LIMIT 300
        ");
        $stmt->execute([':empresa' => $empresa, ':revenda' => $revenda, ':id_inv' => $idInv]);
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totSaldoZero  = 0;
        $totNaoContado = 0;
        foreach ($itens as $i) {
            if ($i['tipo_item'] === 'saldo_zero')  $totSaldoZero++;
            if ($i['tipo_item'] === 'nao_contado') $totNaoContado++;
        }

        // Enriquece item_pub_revenda via Oracle para itens que ainda não têm
        $semOem = array_filter($itens, fn($it) => empty($it['item_pub_revenda']) && !empty($it['cod_item_estoque']));
        if (!empty($semOem)) {
            try {
                $ora = oracle_conectar();
                if ($ora) {
                    $ids = array_unique(array_column(array_values($semOem), 'cod_item_estoque'));
                    $inLit = implode(',', array_map('intval', $ids));
                    $sqlOem = "
                        SELECT e.item_estoque,
                               (SELECT e2.item_estoque_pub
                                FROM pec_item_alternativo a2
                                INNER JOIN pec_item_estoque e2
                                        ON e2.empresa = a2.empresa
                                       AND e2.item_estoque = a2.item_estoque_referenciado
                                WHERE a2.empresa = e.empresa
                                  AND a2.item_estoque = e.item_estoque
                                  AND ROWNUM = 1) AS item_pub_revenda
                        FROM pec_item_estoque e
                        WHERE e.empresa = :empresa
                          AND e.item_estoque IN ($inLit)
                    ";
                    $stmtOem = oci_parse($ora, $sqlOem);
                    oci_bind_by_name($stmtOem, ':empresa', $empresa);
                    oci_execute($stmtOem, OCI_DEFAULT);
                    $oemMap = [];
                    while ($row = oci_fetch_assoc($stmtOem)) {
                        if (!empty($row['ITEM_PUB_REVENDA'])) {
                            $oemMap[(int)$row['ITEM_ESTOQUE']] = (string)$row['ITEM_PUB_REVENDA'];
                        }
                    }
                    oci_free_statement($stmtOem);
                    oci_close($ora);

                    // Aplica e persiste no MySQL
                    $stmtUpd = $pdo->prepare(
                        "UPDATE estoque SET item_pub_revenda = ? WHERE empresa = ? AND revenda = ? AND id_inventario = ? AND cod_item_estoque = ?"
                    );
                    foreach ($itens as &$item) {
                        if (empty($item['item_pub_revenda']) && !empty($item['cod_item_estoque'])) {
                            $oem = $oemMap[(int)$item['cod_item_estoque']] ?? null;
                            if ($oem) {
                                $item['item_pub_revenda'] = $oem;
                                $stmtUpd->execute([$oem, $empresa, $revenda, $idInv, $item['cod_item_estoque']]);
                            }
                        }
                    }
                    unset($item);
                }
            } catch (Throwable $ignored) {}
        }

        echo json_encode([
            'ok'              => true,
            'itens'           => $itens,
            'total'           => count($itens),
            'tot_saldo_zero'  => $totSaldoZero,
            'tot_nao_contado' => $totNaoContado,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => 'Erro ao buscar itens: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------
// MODO: oracle — dados completos do item no Oracle
//   GET: empresa, revenda, item
// -------------------------------------------------------
if ($modo === 'oracle') {
    $empresa = (int)($_GET['empresa'] ?? 0);
    $revenda = (int)($_GET['revenda'] ?? 0);
    $item    = trim($_GET['item']    ?? '');

    if ($empresa <= 0 || $revenda <= 0 || $item === '') {
        echo json_encode(['ok' => false, 'mensagem' => 'Parâmetros inválidos']);
        exit;
    }

    $ora = oracle_conectar();
    if (!$ora) {
        echo json_encode(['ok' => false, 'mensagem' => 'Oracle indisponível', 'fallback' => true]);
        exit;
    }

    try {
            $sql = "
        SELECT
            e.item_estoque_pub,
            (SELECT e2.item_estoque_pub FROM pec_item_alternativo a2 INNER JOIN pec_item_estoque e2 ON e2.empresa = a2.empresa AND e2.item_estoque = a2.item_estoque_referenciado WHERE a2.empresa = e.empresa AND a2.item_estoque = e.item_estoque AND ROWNUM = 1) AS item_pub_revenda,
            e.item_estoque           AS cod_item_estoque,
            e.des_item_estoque       AS descricao,
            (r.qtd_contabil - r.qtd_alocada) AS quantidade,
            r.cus_ult_entrada        AS custo_entrada,
            e.preco_alternativo,
            r.class_abc,
            r.qtd_contabil,
            r.qtd_alocada
        FROM pec_item_estoque e
        INNER JOIN pec_item_revenda r
                ON e.empresa      = r.empresa
               AND e.item_estoque = r.item_estoque
        WHERE e.empresa          = :empresa
          AND r.revenda          = :revenda
          AND e.item_estoque_pub = :item
          AND ROWNUM             = 1
    ";
        $stmt = oci_parse($ora, $sql);
        oci_bind_by_name($stmt, ':empresa', $empresa);
        oci_bind_by_name($stmt, ':revenda', $revenda);
        oci_bind_by_name($stmt, ':item',    $item);
        oci_execute($stmt, OCI_DEFAULT);
        $row = oci_fetch_assoc($stmt);
        oci_free_statement($stmt);
        oci_close($ora);

        if (!$row) {
            echo json_encode(['ok' => false, 'mensagem' => 'Item não encontrado no Oracle']);
            exit;
        }

        echo json_encode([
            'ok'                => true,
            'item_estoque_pub'  => (string)($row['ITEM_ESTOQUE_PUB']    ?? $item),
            'item_pub_revenda'  => (string)($row['ITEM_PUB_REVENDA']    ?? ''),
            'cod_item_estoque'  => (string)($row['COD_ITEM_ESTOQUE']    ?? ''),
            'descricao'         => (string)($row['DESCRICAO']           ?? ''),
            'quantidade'        => (int)(float)($row['QUANTIDADE']      ?? 0),
            'custo_entrada'     => (float)($row['CUSTO_ENTRADA']        ?? 0),
            'preco_alternativo' => (float)($row['PRECO_ALTERNATIVO']    ?? 0),
            'class_abc'         => (string)($row['CLASS_ABC']           ?? ''),
            'qtd_contabil'      => (float)($row['QTD_CONTABIL']         ?? 0),
            'qtd_alocada'       => (float)($row['QTD_ALOCADA']          ?? 0),
        ]);
    } catch (Throwable $e) {
        if (isset($ora) && $ora) @oci_close($ora);
        echo json_encode(['ok' => false, 'mensagem' => 'Erro Oracle: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------
// MODO: decisao — registrar decisão sobre um item
//   POST JSON: id_inventario, empresa, revenda, item, decisao, qtd_ajuste, observacao
// -------------------------------------------------------
if ($modo === 'decisao') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];

    $idInv   = (int)($data['id_inventario'] ?? 0);
    $empresa = (int)($data['empresa']       ?? 0);
    $revenda = (int)($data['revenda']       ?? 0);
    $item    = trim((string)($data['item']      ?? ''));
    $decisao = trim((string)($data['decisao']   ?? ''));
    $qtd     = (int)($data['qtd_ajuste']        ?? 0);
    $obs     = trim((string)($data['observacao'] ?? ''));
    $locacao = trim((string)($data['locacao'] ?? '00.000.000.00.000'));
    if ($locacao === '') $locacao = '00.000.000.00.000';

    $decisoesValidas = ['ajuste_positivo', 'ajuste_negativo', 'desconsiderar', 'autorizar', 'ajuste_final'];
    if (!in_array($decisao, $decisoesValidas) || !$item || !$idInv || !$empresa || !$revenda) {
        echo json_encode(['ok' => false, 'mensagem' => 'Dados inválidos']);
        exit;
    }

    try {
        // Upsert: apaga decisão anterior e insere nova
        $del = $pdo->prepare("
            DELETE FROM inv_decisoes
            WHERE id_inventario = :inv AND empresa = :emp AND revenda = :rev AND item_estoque_pub = :item
        ");
        $del->execute([':inv' => $idInv, ':emp' => $empresa, ':rev' => $revenda, ':item' => $item]);

        $ins = $pdo->prepare("
            INSERT INTO inv_decisoes (id_inventario, empresa, revenda, item_estoque_pub, decisao, qtd_ajuste, observacao, usuario)
            VALUES (:inv, :emp, :rev, :item, :decisao, :qtd, :obs, :usuario)
        ");
        $ins->execute([
            ':inv'     => $idInv,
            ':emp'     => $empresa,
            ':rev'     => $revenda,
            ':item'    => $item,
            ':decisao' => $decisao,
            ':qtd'     => $qtd,
            ':obs'     => $obs,
            ':usuario' => $usuario,
        ]);

        // Se o item não está no inventário, insere antes de aplicar a decisão
        $chk = $pdo->prepare("
            SELECT COUNT(*) FROM estoque
             WHERE id_inventario = :inv AND empresa = :emp AND revenda = :rev AND item_estoque_pub = :item
        ");
        $chk->execute([':inv' => $idInv, ':emp' => $empresa, ':rev' => $revenda, ':item' => $item]);
        if ((int)$chk->fetchColumn() === 0) {
            $descricao = trim((string)($data['descricao']     ?? ''));
            $quantidade = (int)($data['quantidade_oracle']    ?? 0);
            $custo     = (float)($data['custo_entrada']       ?? 0);
            $abc       = trim((string)($data['class_abc']     ?? ''));
            $ins2 = $pdo->prepare("
                INSERT INTO estoque
                    (empresa, revenda, id_inventario, item_estoque_pub, descricao_item_estoque,
                     quantidade, cont_1, cont_2, custo_entrada, class_abc,
                     locacao_geral, locacao_zona, situacao, coletor)
                VALUES
                    (:emp, :rev, :inv, :item, :desc,
                     :qtd, 0, 0, :custo, :abc,
                     :loc, '', 'A', 0)
            ");
            $ins2->execute([
                ':emp'  => $empresa,
                ':rev'  => $revenda,
                ':inv'  => $idInv,
                ':item' => $item,
                ':desc' => $descricao,
                ':qtd'  => $quantidade,
                ':custo'=> $custo,
                ':abc'  => $abc,
                ':loc'  => $locacao,
            ]);
        }

        // Ajuste +: grava cont_1 = qtd, situacao = 'C'
        if ($decisao === 'ajuste_positivo' && $qtd > 0) {
            $upd = $pdo->prepare("
                UPDATE estoque SET cont_1 = :qtd, situacao = 'C', locacao_geral = :loc
                 WHERE id_inventario = :inv AND empresa = :emp AND revenda = :rev AND item_estoque_pub = :item
                 LIMIT 1
            ");
            $upd->execute([':qtd' => $qtd, ':loc' => $locacao, ':inv' => $idInv, ':emp' => $empresa, ':rev' => $revenda, ':item' => $item]);
        }

        // Ajuste −: grava cont_1 = valor negativo, situacao = 'C'
        if ($decisao === 'ajuste_negativo' && $qtd > 0) {
            $upd = $pdo->prepare("
                UPDATE estoque SET cont_1 = :qtd, situacao = 'C', locacao_geral = :loc
                 WHERE id_inventario = :inv AND empresa = :emp AND revenda = :rev AND item_estoque_pub = :item
                 LIMIT 1
            ");
            $upd->execute([':qtd' => -$qtd, ':loc' => $locacao, ':inv' => $idInv, ':emp' => $empresa, ':rev' => $revenda, ':item' => $item]);
        }

        // Descarte: marca situacao = 'D'
        if ($decisao === 'desconsiderar') {
            $upd = $pdo->prepare("
                UPDATE estoque SET situacao = 'D'
                 WHERE id_inventario = :inv AND empresa = :emp AND revenda = :rev AND item_estoque_pub = :item
                 LIMIT 1
            ");
            $upd->execute([':inv' => $idInv, ':emp' => $empresa, ':rev' => $revenda, ':item' => $item]);
        }

        echo json_encode(['ok' => true, 'mensagem' => 'Decisão registrada com sucesso']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => 'Erro ao registrar decisão']);
    }
    exit;
}

// -------------------------------------------------------
// MODO: buscar_item_oracle – busca item no Oracle (LIKE) e enriquece com MySQL
//   GET: codigo, empresa, revenda, id_inventario
// -------------------------------------------------------
if ($modo === 'buscar_item_oracle') {
    $codigo  = trim($_GET['codigo']         ?? '');
    $empresa = (int)($_GET['empresa']       ?? 0);
    $revenda = (int)($_GET['revenda']       ?? 0);
    $idInv   = (int)($_GET['id_inventario'] ?? 0);

    if ($codigo === '' || $empresa <= 0 || $revenda <= 0) {
        echo json_encode(['ok' => false, 'mensagem' => 'Parâmetros inválidos']);
        exit;
    }

    $ora = oracle_conectar();
    if (!$ora) {
        echo json_encode(['ok' => false, 'mensagem' => 'Oracle indisponível', 'fallback' => true]);
        exit;
    }

    try {
        // TOP-N ordenado: subquery ordena, ROWNUM limita (padrão Oracle)
        $sql = "
            SELECT item_estoque_pub, item_pub_revenda, cod_item_estoque, descricao, class_abc, custo_entrada,
                   preco_alternativo, qtd_contabil, qtd_alocada, saldo_oracle
            FROM (
                SELECT
                    e.item_estoque_pub,
                    (SELECT e2.item_estoque_pub FROM pec_item_alternativo a2 INNER JOIN pec_item_estoque e2 ON e2.empresa = a2.empresa AND e2.item_estoque = a2.item_estoque_referenciado WHERE a2.empresa = e.empresa AND a2.item_estoque = e.item_estoque AND ROWNUM = 1) AS item_pub_revenda,
                    e.item_estoque                    AS cod_item_estoque,
                    e.des_item_estoque                AS descricao,
                    r.class_abc,
                    r.cus_ult_entrada                 AS custo_entrada,
                    e.preco_alternativo,
                    r.qtd_contabil,
                    r.qtd_alocada,
                    (r.qtd_contabil - r.qtd_alocada)  AS saldo_oracle
                FROM pec_item_estoque e
                INNER JOIN pec_item_revenda r
                        ON e.empresa      = r.empresa
                       AND e.item_estoque = r.item_estoque
                WHERE e.empresa = :empresa
              AND r.revenda = :revenda
              AND (UPPER(e.item_estoque_pub) LIKE UPPER(:codigo)
                   OR UPPER(TO_CHAR(e.item_estoque)) LIKE UPPER(:codigo2)
                   OR UPPER(e.des_item_estoque) LIKE UPPER(:codigo3)
                   OR e.item_estoque IN (
                       SELECT a3.item_estoque FROM pec_item_alternativo a3
                       INNER JOIN pec_item_estoque e3 ON e3.empresa = a3.empresa AND e3.item_estoque = a3.item_estoque_referenciado
                       WHERE a3.empresa = e.empresa AND UPPER(e3.item_estoque_pub) LIKE UPPER(:codigo4)
                   ))
            ORDER BY e.item_estoque_pub
        ) WHERE ROWNUM <= 20
    ";

        $busca = '%' . $codigo . '%';
        $stmt  = oci_parse($ora, $sql);
        oci_bind_by_name($stmt, ':empresa', $empresa);
        oci_bind_by_name($stmt, ':revenda', $revenda);
        oci_bind_by_name($stmt, ':codigo',  $busca, 200);
        oci_bind_by_name($stmt, ':codigo2', $busca, 200);
        oci_bind_by_name($stmt, ':codigo3', $busca, 200);
        oci_bind_by_name($stmt, ':codigo4', $busca, 200);
        oci_execute($stmt, OCI_DEFAULT);

        $lerItens = function ($stmt): array {
            $itens = [];
            while ($row = oci_fetch_assoc($stmt)) {
                $itens[] = [
                    'item_estoque_pub'       => (string)($row['ITEM_ESTOQUE_PUB']   ?? ''),
                    'item_pub_revenda'       => (string)($row['ITEM_PUB_REVENDA']   ?? ''),
                    'cod_item_estoque'       => (string)($row['COD_ITEM_ESTOQUE']   ?? ''),
                    'descricao_item_estoque' => (string)($row['DESCRICAO']          ?? ''),
                    'class_abc'              => (string)($row['CLASS_ABC']          ?? ''),
                    'custo_entrada'          => (float)($row['CUSTO_ENTRADA']       ?? 0),
                    'preco_alternativo'      => (float)($row['PRECO_ALTERNATIVO']   ?? 0),
                    'qtd_contabil'           => (float)($row['QTD_CONTABIL']        ?? 0),
                    'qtd_alocada'            => (float)($row['QTD_ALOCADA']         ?? 0),
                    'saldo_oracle'           => (float)($row['SALDO_ORACLE']        ?? 0),
                    'saldo_estoque'          => null,
                    'locacao_geral'          => null,
                    'decisao_registrada'     => null,
                    'qtd_ajuste_existente'   => null,
                    'obs_existente'          => null,
                    'ajuste_saldo'           => 0,
                    'saldo_ajustado'         => null,
                    'tipo_item'              => 'oracle_only',
                    'no_inventario'          => false,
                ];
            }
            return $itens;
        };

        $itens = $lerItens($stmt);

        // Nada achado com o texto como veio: pode ser bipe com sufixo de
        // variante (-01, 001) ou com zeros que o leitor pôs na frente. Resolve
        // como a conferência e busca de novo pelo código público — os binds
        // são por referência, então basta trocar $busca e executar outra vez.
        if ($itens === [] && preg_match('/^\S+$/u', $codigo)) {
            // (int): o oci_bind_by_name acima liga $empresa por referência e a
            // devolve como string.
            $pub = conferencia_codigo_pub_resolvido($ora, (int)$empresa, $codigo);
            if ($pub !== null && strcasecmp($pub, $codigo) !== 0) {
                $busca = '%' . $pub . '%';
                oci_execute($stmt, OCI_DEFAULT);
                $itens = $lerItens($stmt);
            }
        }
                // Deduplica: remove entradas repetidas pelo mesmo item_estoque_pub
        $visto      = [];
        $itensFiltrados = [];
        foreach ($itens as $it) {
            $key = (string)$it['item_estoque_pub'];
            if (!isset($visto[$key])) {
                $visto[$key]      = true;
                $itensFiltrados[] = $it;
            }
        }
        $itens = $itensFiltrados;
        oci_free_statement($stmt);

        // Segunda consulta Oracle: saldo de TODAS as lojas para os itens encontrados
        $lojasPorItem = [];
        if (!empty($itens)) {
            $codes = array_column($itens, 'item_estoque_pub');
            // Literal IN com valores vindos do Oracle (escape de aspas simples)
            $inLit = implode("','", array_map(function($c) {
                return str_replace("'", "''", $c);
            }, $codes));
            $sql2 = "
                SELECT e.item_estoque_pub, r.revenda,
                       r.qtd_contabil, r.qtd_alocada,
                       (r.qtd_contabil - r.qtd_alocada) AS saldo
                FROM pec_item_estoque e
                INNER JOIN pec_item_revenda r
                        ON e.empresa      = r.empresa
                       AND e.item_estoque = r.item_estoque
                WHERE e.empresa = :empresa
                  AND e.item_estoque_pub IN ('$inLit')
                ORDER BY e.item_estoque_pub, r.revenda
            ";
            $stmt2 = oci_parse($ora, $sql2);
            oci_bind_by_name($stmt2, ':empresa', $empresa);
            oci_execute($stmt2, OCI_DEFAULT);
            while ($orow = oci_fetch_assoc($stmt2)) {
                $lojasPorItem[(string)$orow['ITEM_ESTOQUE_PUB']][] = [
                    'revenda'  => (int)$orow['REVENDA'],
                    'saldo'    => (float)($orow['SALDO']        ?? 0),
                    'qtd_cont' => (float)($orow['QTD_CONTABIL'] ?? 0),
                    'qtd_aloc' => (float)($orow['QTD_ALOCADA']  ?? 0),
                ];
            }
            oci_free_statement($stmt2);
        }
        oci_close($ora);

        // Fallback de custo: quando Oracle retorna custo=0, busca do MySQL (último inventário com custo > 0)
        $refsSemCusto = [];
        foreach ($itens as &$it) {
            if ((float)$it['custo_entrada'] === 0.0) {
                $refsSemCusto[] = $it['item_estoque_pub'];
            }
        }
        unset($it);
        if ($refsSemCusto !== []) {
            $refsSemCusto = array_values(array_unique($refsSemCusto));
            $phCusto = [];
            $paramsCusto = [':e1' => $empresa, ':r1' => $revenda, ':e2' => $empresa, ':r2' => $revenda];
            foreach ($refsSemCusto as $idx => $ref) {
                $ph = ':rc_' . $idx;
                $phCusto[] = $ph;
                $paramsCusto[$ph] = $ref;
            }
            try {
                $stmtCusto = $pdo->prepare("
                    SELECT t.item_estoque_pub, t.custo_entrada
                      FROM estoque t
                     INNER JOIN (
                        SELECT item_estoque_pub, MAX(id_inventario) AS ult_inv
                          FROM estoque
                         WHERE empresa = :e1 AND revenda = :r1
                           AND custo_entrada > 0
                           AND item_estoque_pub IN (" . implode(', ', $phCusto) . ")
                         GROUP BY item_estoque_pub
                     ) ult ON ult.item_estoque_pub = t.item_estoque_pub
                           AND ult.ult_inv = t.id_inventario
                     WHERE t.empresa = :e2 AND t.revenda = :r2
                ");
                $stmtCusto->execute($paramsCusto);
                $custosMysql = [];
                foreach ($stmtCusto->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $custosMysql[$row['item_estoque_pub']] = (float)$row['custo_entrada'];
                }
                foreach ($itens as &$it) {
                    if ((float)$it['custo_entrada'] === 0.0 && isset($custosMysql[$it['item_estoque_pub']])) {
                        $it['custo_entrada'] = $custosMysql[$it['item_estoque_pub']];
                    }
                }
                unset($it);
            } catch (Throwable $ignored) {}
        }

        // Última locação conhecida de cada ref no MySQL (inventário anterior)
        $refsParaLoc = array_column($itens, 'item_estoque_pub');
        if ($refsParaLoc) {
            $phLoc = [];
            $paramsLoc = [':e1' => $empresa, ':r1' => $revenda, ':e2' => $empresa, ':r2' => $revenda];
            foreach ($refsParaLoc as $idx => $ref) {
                $ph = ':rl_' . $idx;
                $phLoc[] = $ph;
                $paramsLoc[$ph] = $ref;
            }
            try {
                $stmtLoc = $pdo->prepare("
                    SELECT t.item_estoque_pub, t.locacao_geral
                      FROM estoque t
                     INNER JOIN (
                        SELECT item_estoque_pub, MAX(id_inventario) AS ult_inv
                          FROM estoque
                         WHERE empresa = :e1 AND revenda = :r1
                           AND item_estoque_pub IN (" . implode(', ', $phLoc) . ")
                         GROUP BY item_estoque_pub
                     ) ult ON ult.item_estoque_pub = t.item_estoque_pub
                           AND ult.ult_inv = t.id_inventario
                     WHERE t.empresa = :e2 AND t.revenda = :r2
                ");
                $stmtLoc->execute($paramsLoc);
                $locacoesPorRef = [];
                foreach ($stmtLoc->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $locacoesPorRef[$row['item_estoque_pub']] = $row['locacao_geral'];
                }
                foreach ($itens as &$it) {
                    // MySQL (inventário anterior) tem prioridade; mantém null se não houver
                    if (isset($locacoesPorRef[$it['item_estoque_pub']])) {
                        $it['locacao_geral'] = $locacoesPorRef[$it['item_estoque_pub']];
                    }
                }
                unset($it);
            } catch (Throwable $ignored) {}
        }

        // Nomes das lojas (MySQL inv_lojas) para exibição
        $lojasNomes = [];
        try {
            $stmtL = $pdo->prepare("
                SELECT
                    CAST(SUBSTRING_INDEX(codigo, '.', -1) AS UNSIGNED) AS rev_num,
                    empresa AS nome_empresa,
                    revenda AS nome_revenda
                FROM inv_lojas
                WHERE CAST(SUBSTRING_INDEX(codigo, '.', 1) AS UNSIGNED) = ?
                  AND ativo = 1
            ");
            $stmtL->execute([$empresa]);
            foreach ($stmtL->fetchAll(PDO::FETCH_ASSOC) as $lj) {
                $lojasNomes[(int)$lj['rev_num']] = [
                    'nome_empresa' => $lj['nome_empresa'],
                    'nome_revenda' => $lj['nome_revenda'],
                ];
            }
        } catch (Throwable $ignored) {}

        // Locação por item + loja (MySQL – último inventário de cada revenda)
        $locPorItemLoja = [];
        if (!empty($itens)) {
            $codes = array_column($itens, 'item_estoque_pub');
            $phLj  = [];
            $paramsLj = [':elj1' => $empresa, ':elj2' => $empresa];
            foreach ($codes as $idx => $code) {
                $p = ':ljc_' . $idx;
                $phLj[] = $p;
                $paramsLj[$p] = $code;
            }
            try {
                $stmtLj = $pdo->prepare("
                    SELECT t.item_estoque_pub, t.revenda, t.locacao_geral
                      FROM estoque t
                     INNER JOIN (
                        SELECT item_estoque_pub, revenda, MAX(id_inventario) AS ult_inv
                          FROM estoque
                         WHERE empresa = :elj1
                           AND item_estoque_pub IN (" . implode(',', $phLj) . ")
                         GROUP BY item_estoque_pub, revenda
                     ) ult ON ult.item_estoque_pub = t.item_estoque_pub
                           AND ult.revenda          = t.revenda
                           AND ult.ult_inv          = t.id_inventario
                     WHERE t.empresa = :elj2
                ");
                $stmtLj->execute($paramsLj);
                foreach ($stmtLj->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $locPorItemLoja[$row['item_estoque_pub']][(int)$row['revenda']] = $row['locacao_geral'];
                }
            } catch (Throwable $ignored) {}
        }

        // Anexa saldo por loja a cada item
        foreach ($itens as &$item) {
            $rows    = $lojasPorItem[$item['item_estoque_pub']] ?? [];
            $locLoja = $locPorItemLoja[$item['item_estoque_pub']] ?? [];
            $item['lojas'] = array_values(array_map(function($l) use ($lojasNomes, $locLoja) {
                $n = $lojasNomes[$l['revenda']] ?? [];
                return [
                    'rev_num'      => $l['revenda'],
                    'nome_empresa' => $n['nome_empresa'] ?? '',
                    'nome_revenda' => $n['nome_revenda'] ?? ('Loja ' . $l['revenda']),
                    'saldo'        => $l['saldo'],
                    'qtd_cont'     => $l['qtd_cont'],
                    'qtd_aloc'     => $l['qtd_aloc'],
                    'locacao'      => $locLoja[$l['revenda']] ?? '',
                    'cod_loja'     => null,
                ];
            }, $rows));
        }
        unset($item);

        // Descobre qual código cada loja usa no MySQL (pode diferir entre lojas)
        if (!empty($itens)) {
            $codeToMain = [];
            foreach ($itens as $it) {
                $main = $it['item_estoque_pub'];
                $codeToMain[$main] = $main;
                if (!empty($it['item_pub_revenda'])) {
                    $codeToMain[$it['item_pub_revenda']] = $main;
                }
            }
            $allCodes = array_values(array_keys($codeToMain));
            $phCodes  = implode(',', array_fill(0, count($allCodes), '?'));
            try {
                $stmtLC = $pdo->prepare("
                    SELECT revenda, item_estoque_pub
                    FROM estoque
                    WHERE empresa = ?
                    AND item_estoque_pub IN ($phCodes)
                ");
                $stmtLC->execute(array_merge([$empresa], $allCodes));
                $codePorLoja = [];
                foreach ($stmtLC->fetchAll(PDO::FETCH_ASSOC) as $lc) {
                    $mainKey = $codeToMain[$lc['item_estoque_pub']] ?? null;
                    if ($mainKey !== null) {
                        $codePorLoja[$mainKey][(int)$lc['revenda']] = $lc['item_estoque_pub'];
                    }
                }
                foreach ($itens as &$item) {
                    $main = $item['item_estoque_pub'];
                    foreach ($item['lojas'] as &$loja) {
                        $loja['cod_loja'] = $codePorLoja[$main][$loja['rev_num']] ?? null;
                    }
                    unset($loja);
                }
                unset($item);
            } catch (Throwable $ignored) {}
        }

        // Enriquece com dados MySQL do inventário selecionado (saldo local + decisão)
        if (!empty($itens) && $idInv > 0) {
            $codigos      = array_column($itens, 'item_estoque_pub');
            $placeholders = implode(',', array_fill(0, count($codigos), '?'));
            $stmt3 = $pdo->prepare("
                SELECT
                    e.item_estoque_pub,
                    e.item_pub_revenda,
                    e.cod_item_estoque,
                    e.quantidade    AS saldo_estoque,
                    e.locacao_geral,
                    e.situacao,
                    e.cont_1,
                    e.cont_2,
                    d.decisao       AS decisao_registrada,
                    d.qtd_ajuste    AS qtd_ajuste_existente,
                    d.observacao    AS obs_existente
                FROM estoque e
                LEFT JOIN inv_decisoes d
                    ON  d.id_inventario    = e.id_inventario
                    AND d.empresa          = e.empresa
                    AND d.revenda          = e.revenda
                    AND d.item_estoque_pub = e.item_estoque_pub
                WHERE e.empresa       = ?
                  AND e.revenda       = ?
                  AND e.id_inventario = ?
                  AND e.item_estoque_pub IN ($placeholders)
            ");
            $stmt3->execute(array_merge([$empresa, $revenda, $idInv], $codigos));
            $mysqlMap = [];
            foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $mysqlMap[$row['item_estoque_pub']] = $row;
            }

            foreach ($itens as &$item) {
                $m = $mysqlMap[$item['item_estoque_pub']] ?? null;
                if ($m) {
                    if (!empty($m['item_pub_revenda'])) $item['item_pub_revenda'] = $m['item_pub_revenda'];
                    $item['cod_item_estoque']     = $m['cod_item_estoque'] ?: ($item['cod_item_estoque'] ?? null);
                    $item['saldo_estoque']        = $m['saldo_estoque'];
                    $item['locacao_geral']        = $m['locacao_geral'];
                    $item['decisao_registrada']   = $m['decisao_registrada'];
                    $item['qtd_ajuste_existente'] = $m['qtd_ajuste_existente'];
                    $item['obs_existente']        = $m['obs_existente'];
                    $qtdAjuste = abs((int)($m['qtd_ajuste_existente'] ?? 0));
                    if ($m['decisao_registrada'] === 'ajuste_positivo') {
                        $item['ajuste_saldo'] = $qtdAjuste;
                    } elseif ($m['decisao_registrada'] === 'ajuste_negativo') {
                        $item['ajuste_saldo'] = -$qtdAjuste;
                    } else {
                        $item['ajuste_saldo'] = 0;
                    }
                    $item['saldo_ajustado'] = (float)($m['saldo_estoque'] ?? 0) + (float)$item['ajuste_saldo'];
                    $item['no_inventario']        = true;
                    $saldo = (float)($m['saldo_estoque'] ?? 0);
                    $c1    = (float)($m['cont_1'] ?? 0);
                    $c2    = (float)($m['cont_2'] ?? 0);
                    $item['tipo_item'] = $saldo == 0 ? 'saldo_zero'
                        : ($m['situacao'] === 'A' && $c1 == 0 && $c2 == 0 ? 'nao_contado' : 'normal');
                }
            }
            unset($item);
        }

        echo json_encode(['ok' => true, 'itens' => $itens, 'total' => count($itens), 'fonte' => 'oracle']);
    } catch (Throwable $e) {
        if (isset($ora) && $ora) @oci_close($ora);
        echo json_encode(['ok' => false, 'mensagem' => 'Erro Oracle: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------
// MODO: buscar_item – busca item por código/descrição para pesquisa manual
//   GET: codigo, empresa, revenda, id_inventario
// -------------------------------------------------------
if ($modo === 'buscar_item') {
    $codigo  = trim($_GET['codigo']         ?? '');
    $empresa = (int)($_GET['empresa']       ?? 0);
    $revenda = (int)($_GET['revenda']       ?? 0);
    $idInv   = (int)($_GET['id_inventario'] ?? 0);

    if ($codigo === '' || $empresa <= 0 || $revenda <= 0 || $idInv <= 0) {
        echo json_encode(['ok' => false, 'mensagem' => 'Parâmetros inválidos']);
        exit;
    }

    $busca = '%' . $codigo . '%';

    try {
        $stmt = $pdo->prepare("
            SELECT
                e.item_estoque_pub,
                e.item_pub_revenda,
                e.cod_item_estoque,
                e.descricao_item_estoque,
                e.quantidade         AS saldo_estoque,
                e.class_abc,
                e.locacao_geral,
                e.custo_entrada,
                e.situacao,
                e.cont_1,
                e.cont_2,
                CASE
                    WHEN e.quantidade = 0                                      THEN 'saldo_zero'
                    WHEN e.situacao = 'A' AND e.cont_1 = 0 AND e.cont_2 = 0    THEN 'nao_contado'
                    ELSE 'normal'
                END AS tipo_item,
                d.decisao       AS decisao_registrada,
                d.qtd_ajuste    AS qtd_ajuste_existente,
                d.observacao    AS obs_existente,
                CASE
                    WHEN d.decisao = 'ajuste_positivo' THEN ABS(COALESCE(d.qtd_ajuste, 0))
                    WHEN d.decisao = 'ajuste_negativo' THEN -ABS(COALESCE(d.qtd_ajuste, 0))
                    ELSE 0
                END AS ajuste_saldo,
                e.quantidade + CASE
                    WHEN d.decisao = 'ajuste_positivo' THEN ABS(COALESCE(d.qtd_ajuste, 0))
                    WHEN d.decisao = 'ajuste_negativo' THEN -ABS(COALESCE(d.qtd_ajuste, 0))
                    ELSE 0
                END AS saldo_ajustado
            FROM estoque e
            LEFT JOIN inv_decisoes d
                ON d.id_inventario    = e.id_inventario
               AND d.empresa          = e.empresa
               AND d.revenda          = e.revenda
               AND d.item_estoque_pub = e.item_estoque_pub
            WHERE e.empresa       = ?
              AND e.revenda       = ?
              AND e.id_inventario = ?
               AND (
                    e.item_estoque_pub       LIKE ?
                 OR e.item_pub_revenda       LIKE ?
                 OR e.cod_item_estoque       LIKE ?
                 OR e.descricao_item_estoque LIKE ?
               )
            ORDER BY e.item_estoque_pub
            LIMIT 20
        ");
        $stmt->execute([$empresa, $revenda, $idInv, $busca, $busca, $busca, $busca]);
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Nada achado com o texto como veio: resolve o bipe (sufixo de
        // variante, zeros do leitor) contra os itens do inventário, como a
        // 1ª contagem, e busca de novo pelo código que existe.
        if ($itens === [] && preg_match('/^\S+$/u', $codigo)) {
            $variantes = contagem2_variantes_codigo($codigo);
            $resolvido = contagem2_resolver_codigo($pdo, $idInv, $codigo);
            // Sem item, o resolvedor devolve o próprio bipe limpo (variante 0).
            if ($variantes !== [] && $resolvido !== $variantes[0]) {
                $busca = '%' . $resolvido . '%';
                $stmt->execute([$empresa, $revenda, $idInv, $busca, $busca, $busca, $busca]);
                $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        echo json_encode(['ok' => true, 'itens' => $itens, 'total' => count($itens)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => 'Erro ao buscar item: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'mensagem' => 'Modo inválido']);
