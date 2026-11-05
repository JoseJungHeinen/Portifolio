<?php
/**
 * conexao_oracle.php
 * Retorna uma conexão OCI com o Apollo.
 * Uso: $ora = oracle_conectar();
 *      if ($ora) { ... oci_close($ora); }
 *      O texto vem em UTF-8 (AL32UTF8). Sem charset, os acentos do Apollo
 *      (WE8MSWIN1252) chegavam como "?" — BUJÃO virava BUJ?O.
 */

define('ORACLE_USER',    'app_user');
define('ORACLE_PASS',    'TROQUE_NO_SERVIDOR');
define('ORACLE_HOST',    'servidor.exemplo.local');
define('ORACLE_PORT',    '1521');
define('ORACLE_SERVICE', 'servico.exemplo.local');

function oracle_conectar($charset = 'AL32UTF8') {
    $dsn = sprintf(
        '(DESCRIPTION=(ADDRESS_LIST=(ADDRESS=(PROTOCOL=TCP)(HOST=%s)(PORT=%s)))(CONNECT_DATA=(SERVICE_NAME=%s)))',
        ORACLE_HOST, ORACLE_PORT, ORACLE_SERVICE
    );
    $conn = @oci_connect(ORACLE_USER, ORACLE_PASS, $dsn, $charset);
    if (!$conn) {
        return null; // null se falhar (fallback para MySQL)
    }
    // O servidor novo (servidor.exemplo.local) não tem os sinônimos públicos das PEC_*:
    // sem isto, consulta sem o prefixo Exemplo. dá ORA-00942.
    $stid = oci_parse($conn, 'ALTER SESSION SET CURRENT_SCHEMA = EXEMPLO');
    if (!$stid || !@oci_execute($stid)) {
        oci_close($conn);
        return null;
    }
    oci_free_statement($stid);
    return $conn;
}

/**
 * Busca Qtd_Estoque, Valor (cus_ult_entrada) e ABC (class_abc) de um item
 * no Oracle para uma empresa/revenda específica.
 *
 * Retorna array ['quantidade' => int, 'custo_entrada' => float, 'class_abc' => string]
 * ou null se não encontrar.
 */
function oracle_buscar_item(
    $ora,
    int $empresa,
    int $revenda,
    string $itemPub
): ?array {
    $sql = "
        SELECT (r.qtd_contabil - r.qtd_alocada) AS quantidade,
               r.cus_ult_entrada                AS custo_entrada,
               r.class_abc
          FROM Exemplo.pec_item_estoque e
         INNER JOIN Exemplo.pec_item_revenda r
                ON e.empresa      = r.empresa
               AND e.item_estoque = r.item_estoque
         WHERE e.empresa          = :empresa
           AND r.revenda          = :revenda
           AND e.item_estoque_pub = :item
           AND ROWNUM             = 1
    ";

    $stmt = oci_parse($ora, $sql);
    if (!$stmt) return null;

    oci_bind_by_name($stmt, ':empresa', $empresa);
    oci_bind_by_name($stmt, ':revenda', $revenda);
    oci_bind_by_name($stmt, ':item',    $itemPub);

    if (!oci_execute($stmt, OCI_DEFAULT)) {
        oci_free_statement($stmt);
        return null;
    }

    $row = oci_fetch_assoc($stmt);
    oci_free_statement($stmt);

    if (!$row) return null;

    return [
        'quantidade'    => (int)(float)$row['QUANTIDADE'],
        'custo_entrada' => (float)$row['CUSTO_ENTRADA'],
        'class_abc'     => (string)($row['CLASS_ABC'] ?? ''),
    ];
}

/**
 * Busca varios itens em lote no Oracle e devolve o resultado indexado pela referencia.
 */
function oracle_buscar_itens_por_refs(
    $ora,
    int $empresa,
    int $revenda,
    array $refs
): array {
    $refs = array_values(array_unique(array_filter(array_map('trim', $refs), static fn ($ref) => $ref !== '')));
    if ($refs === []) {
        return [];
    }

    $placeholders = [];
    $binds = [];
    foreach ($refs as $idx => $ref) {
        $placeholder = ':item_' . $idx;
        $placeholders[] = $placeholder;
        $binds[$placeholder] = $ref;
    }

    $sql = "
        SELECT e.item_estoque_pub,
               (r.qtd_contabil - r.qtd_alocada) AS quantidade,
               r.cus_ult_entrada                AS custo_entrada,
               r.class_abc
          FROM Exemplo.pec_item_estoque e
         INNER JOIN Exemplo.pec_item_revenda r
                 ON e.empresa      = r.empresa
                AND e.item_estoque = r.item_estoque
         WHERE e.empresa          = :empresa
           AND r.revenda          = :revenda
           AND e.item_estoque_pub IN (" . implode(', ', $placeholders) . ")
    ";

    $stmt = oci_parse($ora, $sql);
    if (!$stmt) {
        return [];
    }

    oci_bind_by_name($stmt, ':empresa', $empresa);
    oci_bind_by_name($stmt, ':revenda', $revenda);
    foreach ($binds as $placeholder => &$valor) {
        oci_bind_by_name($stmt, $placeholder, $valor);
    }
    unset($valor);

    if (!oci_execute($stmt, OCI_DEFAULT)) {
        oci_free_statement($stmt);
        return [];
    }

    $itens = [];
    while ($row = oci_fetch_assoc($stmt)) {
        $ref = (string)($row['ITEM_ESTOQUE_PUB'] ?? '');
        if ($ref === '') {
            continue;
        }

        $itens[$ref] = [
            'quantidade'    => (int)(float)($row['QUANTIDADE'] ?? 0),
            'custo_entrada' => (float)($row['CUSTO_ENTRADA'] ?? 0),
            'class_abc'     => (string)($row['CLASS_ABC'] ?? ''),
        ];
    }
    oci_free_statement($stmt);

    return $itens;
}

/**
 * Busca itens no Oracle por referência (item_estoque_pub) ou descrição,
 * para uma empresa/revenda específica.
 *
 * Retorna array de itens com:
 *   ['item_estoque_pub', 'descricao', 'quantidade', 'custo_entrada', 'class_abc']
 * ou array vazio se não encontrar nada.
 *
 * @param resource $ora      Conexão OCI ativa
 * @param int      $empresa
 * @param int      $revenda
 * @param string   $texto    Texto para busca (referência ou descrição)
 * @param int      $limite   Máximo de resultados (default 30)
 */
function oracle_buscar_itens_texto(
    $ora,
    int $empresa,
    int $revenda,
    string $texto,
    int $limite = 30
): array {
    $textoBusca = '%' . strtoupper(trim($texto)) . '%';

    $sql = "
        SELECT *
          FROM (
            SELECT e.item_estoque_pub,
                   e.des_item_estoque               AS descricao,
                   (r.qtd_contabil - r.qtd_alocada) AS quantidade,
                   r.cus_ult_entrada,
                   r.class_abc
              FROM Exemplo.pec_item_estoque e
             INNER JOIN Exemplo.pec_item_revenda r
                     ON e.empresa      = r.empresa
                    AND e.item_estoque = r.item_estoque
             WHERE e.empresa  = :empresa
               AND r.revenda  = :revenda
               AND (
                     UPPER(e.item_estoque_pub) LIKE :busca
                  OR UPPER(e.des_item_estoque) LIKE :busca2
               )
             ORDER BY e.item_estoque_pub
          )
         WHERE ROWNUM <= :limite
    ";

    $stmt = oci_parse($ora, $sql);
    if (!$stmt) return [];

    oci_bind_by_name($stmt, ':empresa', $empresa);
    oci_bind_by_name($stmt, ':revenda', $revenda);
    oci_bind_by_name($stmt, ':busca',   $textoBusca);
    oci_bind_by_name($stmt, ':busca2',  $textoBusca);
    oci_bind_by_name($stmt, ':limite',  $limite);

    if (!@oci_execute($stmt, OCI_DEFAULT)) {
        // Sem isto a falha some: a tela só vê "nenhum item".
        error_log('oracle_buscar_itens_texto: ' . (oci_error($stmt)['message'] ?? 'erro desconhecido'));
        oci_free_statement($stmt);
        return [];
    }

    $itens = [];
    while ($row = oci_fetch_assoc($stmt)) {
        $custo = 0;
        foreach (['CUS_ULT_ENTRADA', 'CUSTO_ENTRADA', 'CUSTO'] as $col) {
            if (isset($row[$col])) { $custo = (float)$row[$col]; break; }
        }

        $itens[] = [
            'item_estoque_pub' => (string)($row['ITEM_ESTOQUE_PUB'] ?? ''),
            'descricao'        => (string)($row['DESCRICAO'] ?? ''),
            'quantidade'       => (int)(float)($row['QUANTIDADE'] ?? 0),
            'custo_entrada'    => $custo,
            'class_abc'        => (string)($row['CLASS_ABC'] ?? ''),
        ];
    }
    oci_free_statement($stmt);

    return $itens;
}
