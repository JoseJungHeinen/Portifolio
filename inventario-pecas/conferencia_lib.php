<?php
declare(strict_types=1);

require_once __DIR__ . '/contagem_2_lib.php';

/**
 * conferencia_lib.php
 *
 * Funções da conferência de coletor. Nada aqui usa sessão, imprime, ou chama
 * header(): tudo recebe conexão + parâmetros e devolve dados. É o que torna
 * este arquivo testável pela linha de comando.
 */

/**
 * Lojas da cidade onde a contagem está sendo feita.
 *
 * A cidade não é escolhida pelo operador: ela sai de inv_lojas a partir do par
 * empresa/revenda da sessão (inv_lojas.codigo guarda "empresa.revenda", e
 * inv_lojas.empresa guarda o nome da cidade).
 *
 * @return array<int, array{revenda:int, nome:string, atual:bool}> ordenado por
 *         revenda; [] se o par empresa/revenda não existir em inv_lojas.
 */
function conferencia_lojas_da_cidade(PDO $pdo, int $empresa, int $revenda): array
{
    $stmt = $pdo->prepare("
        SELECT empresa
          FROM inv_lojas
         WHERE codigo = :cod
           AND ativo  = 1
         LIMIT 1
    ");
    $stmt->execute([':cod' => $empresa . '.' . $revenda]);
    $cidade = $stmt->fetchColumn();

    if ($cidade === false) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT revenda AS nome,
               CAST(SUBSTRING_INDEX(codigo, '.', -1) AS UNSIGNED) AS rev_num
          FROM inv_lojas
         WHERE empresa = :cidade
           AND ativo   = 1
           AND CAST(SUBSTRING_INDEX(codigo, '.', 1) AS UNSIGNED) = :empresa
         ORDER BY rev_num
    ");
    $stmt->execute([':cidade' => $cidade, ':empresa' => $empresa]);

    $lojas = [];
    foreach ($stmt as $row) {
        $num     = (int)$row['rev_num'];
        $lojas[] = [
            'revenda' => $num,
            'nome'    => (string)$row['nome'],
            'atual'   => $num === $revenda,
        ];
    }

    return $lojas;
}

/**
 * Monta a locação legível a partir dos cinco campos do Apollo.
 * Campos vazios são descartados, para não gerar "1---A-". Vale para os dois
 * campos de texto: no Apollo, rua, estante e número são NUMBER NOT NULL, então
 * "sem locação" neles seria 0, que conta como preenchido.
 */
function conferencia_montar_locacao(array $campos): string
{
    $partes = [];

    foreach (['locacao_zona', 'locacao_rua', 'locacao_estante', 'locacao_prateleira', 'locacao_numero'] as $campo) {
        $valor = trim((string)($campos[$campo] ?? ''));
        if ($valor !== '') {
            $partes[] = $valor;
        }
    }

    return implode('-', $partes);
}

/**
 * Roda uma consulta no Apollo e devolve todas as linhas.
 *
 * Existe para a checagem de erro do oci_execute() ficar num lugar só. Sem ela,
 * uma consulta que FALHA devolve as mesmas "zero linhas" de uma consulta que
 * não achou nada — e na consulta de saldos isso vira zero em todas as lojas,
 * apresentado ao operador como se fosse o saldo real.
 *
 * @param array<string, int|string> $binds
 * @return array<int, array<string, mixed>> chaves em MAIÚSCULAS, como o OCI8 devolve
 * @throws RuntimeException se o Apollo recusar a consulta.
 */
function conferencia_ora_consultar($ora, string $sql, array $binds): array
{
    $stmt = oci_parse($ora, $sql);
    if ($stmt === false) {
        throw new RuntimeException('Apollo: falha ao preparar a consulta');
    }

    foreach ($binds as $nome => $_valor) {
        // Bind é por referência: a variável tem que sobreviver até o
        // oci_execute(). Por isso $binds[$nome], o elemento do array, e nunca a
        // variável do foreach — com ela, todos os placeholders apontariam para
        // a mesma variável e receberiam o último valor.
        oci_bind_by_name($stmt, $nome, $binds[$nome]);
    }

    // O @ silencia o Warning que o OCI8 imprime por conta própria em caso de
    // erro. Não perde nada: o erro é lido logo abaixo com oci_error() e vira
    // exceção. E é necessário — esta função é chamada de um endpoint que
    // responde JSON, e o Warning sairia antes do corpo, corrompendo a resposta.
    if (!@oci_execute($stmt, OCI_DEFAULT)) {
        $erro = oci_error($stmt);
        oci_free_statement($stmt);
        throw new RuntimeException('Apollo: ' . ($erro['message'] ?? 'erro desconhecido'));
    }

    $linhas = [];
    while ($row = oci_fetch_assoc($stmt)) {
        $linhas[] = $row;
    }
    oci_free_statement($stmt);

    return $linhas;
}

/**
 * Resolve o código bipado em item_estoque (código interno do Apollo).
 *
 * Ordem:
 *   1. item_estoque_pub exato, por cada variante do bipe
 *      (contagem2_variantes_codigo: sufixo -01/-001/001, ponto antes do traço,
 *      regra do K) — o código inteiro é a primeira variante, então código que
 *      existe como está nunca é trocado por uma versão cortada;
 *   2. item_estoque_pub sem zero à esquerda dos dois lados, só quando o bipe
 *      começa com zero: há leitor que completa o código com zeros na frente
 *      (000063806 para o 063806, 000127003OF para o 127003OF);
 *   3. item_estoque exato, só quando o bipe é numérico.
 *
 * A ordem importa: códigos públicos com zero à esquerda, como "063806",
 * colidiriam com o item_estoque 63806 se a busca pelo código interno viesse
 * antes — e sem a etapa 2, o bipe 000063806 caía na 3 e mostrava o item 63806,
 * que é outra peça.
 *
 * Não há etapa por código alternativo: em pec_item_alternativo, todo
 * item_estoque_referenciado é um item que já existe em pec_item_estoque, então
 * seu código já é achado pela etapa 1 — e achá-lo é o certo, porque é a peça que
 * está na mão do operador, com o estoque e a locação dela.
 *
 * @return int|null item_estoque, ou null se o código não resolver.
 */
function conferencia_resolver_item($ora, int $empresa, string $codigo): ?int
{
    $variantes = contagem2_variantes_codigo($codigo);
    if ($variantes === []) {
        return null;
    }
    $inteiro = $variantes[0];

    // 1) código público exato, por variante
    $porCodigo = conferencia_ora_por_codigo_pub($ora, $empresa, $variantes, false);
    foreach ($variantes as $v) {
        if (isset($porCodigo[$v])) {
            return $porCodigo[$v];
        }
    }

    // 2) código público sem zero à esquerda, se o leitor completou com zeros
    if ($inteiro[0] === '0') {
        $chaves = array_values(array_unique(array_filter(
            array_map('contagem2_chave_sem_zeros', $variantes),
            function ($c) { return $c !== ''; }
        )));
        $porChave = $chaves === [] ? [] : conferencia_ora_por_codigo_pub($ora, $empresa, $chaves, true);
        foreach ($chaves as $c) {
            if (isset($porChave[$c])) {
                return $porChave[$c];
            }
        }
    }

    // 3) código interno exato, só se o bipado for numérico
    if (ctype_digit($inteiro)) {
        $linhas = conferencia_ora_consultar($ora, "
            SELECT item_estoque
              FROM pec_item_estoque
             WHERE empresa = :empresa
               AND item_estoque = :item
               AND ROWNUM = 1
        ", [':empresa' => $empresa, ':item' => (int)$inteiro]);

        if ($linhas !== []) {
            return (int)$linhas[0]['ITEM_ESTOQUE'];
        }
    }

    return null;
}

/**
 * O item_estoque_pub do item que o bipe representa (conferencia_resolver_item),
 * ou null se não resolver. Para telas que buscam pelo código público, como a
 * Tomada de Decisão.
 */
function conferencia_codigo_pub_resolvido($ora, int $empresa, string $codigo): ?string
{
    $itemEstoque = conferencia_resolver_item($ora, $empresa, $codigo);
    if ($itemEstoque === null) {
        return null;
    }

    $linhas = conferencia_ora_consultar($ora, "
        SELECT item_estoque_pub
          FROM pec_item_estoque
         WHERE empresa = :empresa
           AND item_estoque = :item
    ", [':empresa' => $empresa, ':item' => $itemEstoque]);

    return $linhas === [] ? null : (string)$linhas[0]['ITEM_ESTOQUE_PUB'];
}

/**
 * item_estoque de cada código público pedido que existe, por código.
 *
 * $semZeros compara sem zero à esquerda (LTRIM na coluna): os códigos pedidos
 * já vêm sem zero, e a chave devolvida é a do pedido. Essa forma não usa
 * índice, por isso fica para a etapa 2 do resolvedor.
 *
 * @param list<string> $codigos em maiúsculas
 * @return array<string, int>
 */
function conferencia_ora_por_codigo_pub($ora, int $empresa, array $codigos, bool $semZeros): array
{
    $coluna = $semZeros ? "LTRIM(UPPER(item_estoque_pub), '0')" : 'UPPER(item_estoque_pub)';

    $binds  = [':empresa' => $empresa];
    $marcas = [];
    foreach (array_values($codigos) as $i => $c) {
        $binds[":c$i"] = $c;
        $marcas[]      = ":c$i";
    }

    $linhas = conferencia_ora_consultar($ora, "
        SELECT $coluna AS chave, MIN(item_estoque) AS item_estoque
          FROM pec_item_estoque
         WHERE empresa = :empresa
           AND $coluna IN (" . implode(',', $marcas) . ")
         GROUP BY $coluna
    ", $binds);

    $saida = [];
    foreach ($linhas as $l) {
        $saida[(string)$l['CHAVE']] = (int)$l['ITEM_ESTOQUE'];
    }

    return $saida;
}

/**
 * Busca no Apollo o item bipado: cabeçalho, locação da loja atual e o saldo de
 * cada loja da cidade.
 *
 * O cabeçalho e os saldos vêm em duas consultas de propósito. Um INNER JOIN
 * entre pec_item_estoque e pec_item_revenda devolveria zero linhas para um item
 * que existe no cadastro mas não tem linha de estoque em nenhuma loja da cidade,
 * e a tela diria "item não encontrado" para um item que existe.
 *
 * @param array<int, array{revenda:int, nome:string, atual:bool}> $lojas
 * @return array{origem:string, item:array, lojas:array}|null null se o código
 *         não resolver.
 */
function conferencia_buscar_oracle($ora, int $empresa, int $revendaAtual, array $lojas, string $codigo): ?array
{
    $itemEstoque = conferencia_resolver_item($ora, $empresa, $codigo);
    if ($itemEstoque === null) {
        return null;
    }

    // Sem lista de lojas não há conferência possível. Em vez de devolver um
    // painel com item, locação e nenhuma quantidade, monta a loja da sessão —
    // que é a que o operador tem na frente.
    if ($lojas === []) {
        $lojas = [['revenda' => $revendaAtual, 'nome' => '(esta loja)', 'atual' => true]];
    }

    $cabecalho = conferencia_ora_consultar($ora, "
        SELECT item_estoque_pub, item_estoque, des_item_estoque
          FROM pec_item_estoque
         WHERE empresa = :empresa
           AND item_estoque = :item
           AND ROWNUM = 1
    ", [':empresa' => $empresa, ':item' => $itemEstoque]);

    // Inalcançável na prática: o resolver acabou de provar que a linha existe,
    // na mesma conexão. Fica como guarda, agora que falha de consulta estoura
    // em vez de chegar aqui disfarçada de "não encontrado".
    if ($cabecalho === []) {
        return null;
    }
    $cab = $cabecalho[0];

    $binds = [':empresa' => $empresa, ':item' => $itemEstoque];
    $ph    = [];
    foreach ($lojas as $i => $loja) {
        $nome         = ':rev' . $i;
        $ph[]         = $nome;
        $binds[$nome] = (int)$loja['revenda'];
    }
    $inLista = implode(',', $ph);

    $linhas = conferencia_ora_consultar($ora, "
        SELECT revenda,
               (qtd_contabil - qtd_alocada) AS saldo_oracle,
               locacao_zona, locacao_rua, locacao_estante,
               locacao_prateleira, locacao_numero
          FROM pec_item_revenda
         WHERE empresa = :empresa
           AND item_estoque = :item
           AND revenda IN ($inLista)
    ", $binds);

    $porRevenda = [];
    foreach ($linhas as $row) {
        $porRevenda[(int)$row['REVENDA']] = [
            'saldo'   => (int)$row['SALDO_ORACLE'],
            'locacao' => conferencia_montar_locacao([
                'locacao_zona'       => $row['LOCACAO_ZONA']       ?? '',
                'locacao_rua'        => $row['LOCACAO_RUA']        ?? '',
                'locacao_estante'    => $row['LOCACAO_ESTANTE']    ?? '',
                'locacao_prateleira' => $row['LOCACAO_PRATELEIRA'] ?? '',
                'locacao_numero'     => $row['LOCACAO_NUMERO']     ?? '',
            ]),
        ];
    }

    $lojasOut = [];
    foreach ($lojas as $loja) {
        $rev        = (int)$loja['revenda'];
        $lojasOut[] = [
            'revenda' => $rev,
            'nome'    => (string)$loja['nome'],
            'atual'   => (bool)$loja['atual'],
            'saldo'   => $porRevenda[$rev]['saldo'] ?? 0,
            // A locação de cada loja: a mesma peça fica em lugar diferente em
            // cada uma, e a tela mostra isso linha a linha. Vazia quando a loja
            // não tem linha de estoque do item.
            'locacao' => $porRevenda[$rev]['locacao'] ?? '',
        ];
    }

    return [
        'origem' => 'oracle',
        'item'   => [
            'item_estoque_pub' => (string)($cab['ITEM_ESTOQUE_PUB'] ?? ''),
            'cod_item_estoque' => (string)($cab['ITEM_ESTOQUE']     ?? ''),
            'descricao'        => (string)($cab['DES_ITEM_ESTOQUE'] ?? ''),
            // Continua sendo a locação da loja da sessão: é ela que vai para o
            // registro da conferência e para o ajuste.
            'locacao_geral'    => $porRevenda[$revendaAtual]['locacao'] ?? '',
        ],
        'lojas'  => $lojasOut,
    ];
}

/**
 * Fallback usado quando o Apollo não responde: lê o snapshot da tabela estoque.
 *
 * O snapshot só contém linhas da própria loja do inventário — verificado no
 * banco: o inventário #45, de Cidade A Fiat, não tem nenhuma linha de revenda 5.
 * Por isso as demais lojas da cidade vêm com saldo null (desconhecido), nunca 0:
 * na tela de quem está conferindo, zero e "não sei" não podem parecer a mesma
 * coisa.
 *
 * A busca aqui é só por item_estoque_pub — o snapshot não guarda o código
 * interno nem os equivalentes. O bipe passa antes pelo contagem2_resolver_codigo(),
 * o mesmo da 1ª contagem: sufixo de variante e zeros a mais do leitor.
 *
 * @param array<int, array{revenda:int, nome:string, atual:bool}> $lojas
 * @return array{origem:string, item:array, lojas:array}|null
 */
function conferencia_buscar_snapshot(
    PDO $pdo,
    int $idInventario,
    int $empresa,
    int $revendaAtual,
    array $lojas,
    string $codigo
): ?array {
    $stmt = $pdo->prepare("
        SELECT item_estoque_pub, cod_item_estoque, descricao_item_estoque,
               quantidade, locacao_geral
          FROM estoque
         WHERE id_inventario    = :inv
           AND empresa          = :emp
           AND revenda          = :rev
           AND item_estoque_pub = :cod
         LIMIT 1
    ");
    $stmt->execute([
        ':inv' => $idInventario,
        ':emp' => $empresa,
        ':rev' => $revendaAtual,
        ':cod' => contagem2_resolver_codigo($pdo, $idInventario, $codigo),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    if ($lojas === []) {
        $lojas = [['revenda' => $revendaAtual, 'nome' => '(esta loja)', 'atual' => true]];
    }

    $lojasOut = [];
    foreach ($lojas as $loja) {
        $rev        = (int)$loja['revenda'];
        $lojasOut[] = [
            'revenda' => $rev,
            'nome'    => (string)$loja['nome'],
            'atual'   => (bool)$loja['atual'],
            'saldo'   => $rev === $revendaAtual ? (int)$row['quantidade'] : null,
            // Mesmo raciocínio do saldo: o snapshot só tem a loja do inventário,
            // e null é "não sei", diferente de "não tem locação".
            'locacao' => $rev === $revendaAtual ? trim((string)($row['locacao_geral'] ?? '')) : null,
        ];
    }

    return [
        'origem' => 'snapshot',
        'item'   => [
            'item_estoque_pub' => (string)$row['item_estoque_pub'],
            'cod_item_estoque' => (string)($row['cod_item_estoque'] ?? ''),
            'descricao'        => (string)($row['descricao_item_estoque'] ?? ''),
            // locacao_geral é CHAR(20) e vem preenchido com espaços à direita.
            'locacao_geral'    => trim((string)($row['locacao_geral'] ?? '')),
        ],
        'lojas'  => $lojasOut,
    ];
}

/**
 * A locação escrita na etiqueta da prateleira, normalizada como
 * zona-rua-estante-prateleira-número em maiúsculas, ou null se o texto não é
 * uma etiqueta de locação — o caso comum é bipar o código do item por engano.
 *
 * Aceita travessão como separador: há leitor que troca o hífen.
 */
function conferencia_ler_etiqueta_locacao(string $texto): ?string
{
    $partes = preg_split('/\s*[-–—]\s*/u', trim($texto));

    if ($partes === false || count($partes) !== 5) {
        return null;
    }

    foreach ($partes as $i => $p) {
        $p = strtoupper(trim($p));
        if ($p === '' || !preg_match('/^[A-Z0-9]+$/', $p)) {
            return null;
        }
        $partes[$i] = $p;
    }

    return implode('-', $partes);
}

/**
 * Compara a locação bipada com a que o Apollo tem para o item nesta loja.
 *
 * Pela chave de ordenação, e não pelo texto: rua, estante e número são NUMBER
 * no Apollo, então "022" e "22" são a mesma rua, e a prateleira "a" é a "A".
 *
 * @return string 'confere', 'outra' (o Apollo diz outro lugar) ou
 *                'sem_locacao' (o item não tem locação nesta loja).
 */
function conferencia_situacao_locacao(string $bipada, string $cadastrada): string
{
    $cadastrada = trim($cadastrada);

    if ($cadastrada === '') {
        return 'sem_locacao';
    }

    $a = contagem2_chave_de_locacao($bipada);
    $b = contagem2_chave_de_locacao($cadastrada);

    if ($a !== null && $b !== null) {
        return $a === $b ? 'confere' : 'outra';
    }

    return strtoupper(trim($bipada)) === strtoupper($cadastrada) ? 'confere' : 'outra';
}

/**
 * Item já bipado nessa loja, nesse inventário, por qualquer dupla.
 *
 * Sem inventário (id 0) não há rodada que delimite: vale só o que foi
 * conferido hoje, senão todo item bipado uma vez ficaria "já conferido" para sempre.
 */
function conferencia_ja_conferido(PDO $pdo, int $idInventario, int $empresa, int $revenda, string $itemPub): bool
{
    $hoje = $idInventario === 0 ? 'AND conferido_em >= CURDATE()' : '';

    $stmt = $pdo->prepare("
        SELECT 1
          FROM inv_conferencias
         WHERE id_inventario    = :inv
           AND empresa          = :emp
           AND revenda          = :rev
           AND item_estoque_pub = :cod
           $hoje
         LIMIT 1
    ");
    $stmt->execute([
        ':inv' => $idInventario,
        ':emp' => $empresa,
        ':rev' => $revenda,
        ':cod' => $itemPub,
    ]);

    return $stmt->fetchColumn() !== false;
}

/** Decisões que a dupla pode registrar. Qualquer outra coisa é erro. */
const CONFERENCIA_STATUS = ['ok', 'troca'];

/**
 * Como o código chegou: pelo leitor, ou digitado depois do botão de etiqueta
 * ilegível. É o rastro que torna visível quem digita — a digitação existe para
 * etiqueta estragada, e sem registro ela serviria de atalho para erro proposital.
 */
const CONFERENCIA_ENTRADAS = ['bipado', 'digitado'];

/** @throws InvalidArgumentException se a entrada estiver ausente ou fora da lista. */
function conferencia_exigir_entrada($entrada): string
{
    $entrada = (string)($entrada ?? '');

    if (!in_array($entrada, CONFERENCIA_ENTRADAS, true)) {
        throw new InvalidArgumentException('Entrada inválida: ' . var_export($entrada, true));
    }

    return $entrada;
}

/**
 * Grava a decisão da dupla sobre um item. Uma linha por decisão: o histórico é
 * proposital, não é upsert. Um item marcado 'troca' às 9h e 'ok' às 11h, depois
 * de arrumado, deixa as duas linhas — é assim que se enxerga a correção.
 *
 * saldo_oracle preserva a diferença entre 0 (o Apollo disse que não tem) e null
 * (não foi possível saber, caso do fallback de snapshot para as outras lojas da
 * cidade). Um (int) cego transformaria desconhecido em zero.
 *
 * O status não tem padrão silencioso: sem decisão não há registro, porque uma
 * linha que caísse em 'ok' por omissão diria que a dupla aprovou um item que ela
 * talvez nem tenha olhado.
 *
 * @return int id da linha criada.
 * @throws InvalidArgumentException se o status estiver ausente ou fora da lista.
 */
function conferencia_registrar(PDO $pdo, array $d): int
{
    $status = (string)($d['status'] ?? '');

    if (!in_array($status, CONFERENCIA_STATUS, true)) {
        throw new InvalidArgumentException(
            'Status de conferência inválido: ' . var_export($d['status'] ?? null, true)
        );
    }

    // Nas linhas novas a entrada é obrigatória: nulo fica só para o histórico
    // de antes da trava de digitação, quando não havia como saber.
    $entrada = conferencia_exigir_entrada($d['entrada'] ?? null);

    $stmt = $pdo->prepare("
        INSERT INTO inv_conferencias
            (id_inventario, empresa, revenda, coletor, dupla_label,
             item_estoque_pub, cod_item_estoque, descricao, locacao_geral,
             saldo_oracle, origem, status, entrada)
        VALUES
            (:inv, :emp, :rev, :col, :dupla,
             :pub, :cod, :descricao, :loc,
             :saldo, :origem, :status, :entrada)
    ");
    $stmt->execute([
        ':inv'       => (int)$d['id_inventario'],
        ':emp'       => (int)$d['empresa'],
        ':rev'       => (int)$d['revenda'],
        ':col'       => (int)($d['coletor'] ?? 0),
        ':dupla'     => (string)($d['dupla_label'] ?? ''),
        ':pub'       => (string)$d['item_estoque_pub'],
        ':cod'       => (string)($d['cod_item_estoque'] ?? ''),
        ':descricao' => (string)($d['descricao'] ?? ''),
        ':loc'       => (string)($d['locacao_geral'] ?? ''),
        ':saldo'     => ($d['saldo_oracle'] ?? null) === null ? null : (int)$d['saldo_oracle'],
        ':origem'    => (string)($d['origem'] ?? 'oracle'),
        ':status'    => $status,
        ':entrada'   => $entrada,
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Registra um ajuste de locação feito pelo coletor. Antes disto, o ajuste
 * gravava no Apollo e não deixava rastro nenhum no inventário.
 *
 * Deve ser chamado DEPOIS de conferencia_gravar_locacao() dar certo: um registro
 * de ajuste que não aconteceu no Apollo seria pior que nenhum.
 *
 * @return int id da linha criada.
 * @throws InvalidArgumentException se faltar item, locação ou entrada válida.
 */
function conferencia_registrar_ajuste(PDO $pdo, array $d): int
{
    $entrada = conferencia_exigir_entrada($d['entrada'] ?? null);

    foreach (['item_estoque_pub', 'locacao_anterior', 'locacao_nova'] as $campo) {
        if (trim((string)($d[$campo] ?? '')) === '') {
            throw new InvalidArgumentException("Ajuste sem $campo");
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO inv_ajustes_locacao
            (id_inventario, empresa, revenda, coletor, dupla_label,
             item_estoque_pub, cod_item_estoque, locacao_anterior, locacao_nova,
             entrada)
        VALUES
            (:inv, :emp, :rev, :col, :dupla,
             :pub, :cod, :anterior, :nova,
             :entrada)
    ");
    $stmt->execute([
        ':inv'      => (int)$d['id_inventario'],
        ':emp'      => (int)$d['empresa'],
        ':rev'      => (int)$d['revenda'],
        ':col'      => (int)($d['coletor'] ?? 0),
        ':dupla'    => (string)($d['dupla_label'] ?? ''),
        ':pub'      => trim((string)$d['item_estoque_pub']),
        ':cod'      => (string)($d['cod_item_estoque'] ?? ''),
        ':anterior' => trim((string)$d['locacao_anterior']),
        ':nova'     => trim((string)$d['locacao_nova']),
        ':entrada'  => $entrada,
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Regras dos cinco campos de locação, tiradas das colunas de pec_item_revenda
 * no Apollo — todas NOT NULL. 'max' é o limite da coluna: tamanho do VARCHAR2
 * ou o maior valor que cabe no NUMBER.
 */
const CONFERENCIA_LOCACAO_CAMPOS = [
    'zona'       => ['rotulo' => 'Zona',       'artigo' => 'a', 'tipo' => 'texto',  'max' => 2],
    'rua'        => ['rotulo' => 'Rua',        'artigo' => 'a', 'tipo' => 'numero', 'max' => 99],
    'estante'    => ['rotulo' => 'Estante',    'artigo' => 'a', 'tipo' => 'numero', 'max' => 999],
    'prateleira' => ['rotulo' => 'Prateleira', 'artigo' => 'a', 'tipo' => 'texto',  'max' => 3],
    'numero'     => ['rotulo' => 'Número',     'artigo' => 'o', 'tipo' => 'numero', 'max' => 999],
];

/**
 * Valida e normaliza a locação digitada, antes de ela chegar ao Apollo.
 *
 * Texto vai para maiúscula: no Apollo existem 258 prateleiras em minúscula
 * ('c' x79, 'a' x59...) contra milhares da mesma letra em maiúscula — é erro de
 * digitação, não outra prateleira. Símbolo é recusado pelo mesmo motivo (hoje
 * existe uma 'F,' lá).
 *
 * Devolve todos os erros de uma vez, para a dupla não descobrir um por tentativa.
 *
 * @return array{valores: array<string, int|string>, erros: array<string, string>}
 */
function conferencia_validar_locacao(array $entrada): array
{
    $valores = [];
    $erros   = [];

    foreach (CONFERENCIA_LOCACAO_CAMPOS as $campo => $regra) {
        $bruto = trim((string)($entrada[$campo] ?? ''));

        if ($bruto === '') {
            $erros[$campo] = 'Preencha ' . $regra['artigo'] . ' ' . mb_strtolower($regra['rotulo']) . '.';
            continue;
        }

        if ($regra['tipo'] === 'numero') {
            if (!ctype_digit($bruto) || (int)$bruto > $regra['max']) {
                $erros[$campo] = $regra['rotulo'] . ' vai de 0 a ' . $regra['max'] . '.';
                continue;
            }
            $valores[$campo] = (int)$bruto;
            continue;
        }

        $texto = strtoupper($bruto);
        if (!preg_match('/^[A-Z0-9]+$/', $texto) || strlen($texto) > $regra['max']) {
            $erros[$campo] = $regra['rotulo'] . ': até ' . $regra['max'] . ' letras ou números.';
            continue;
        }
        $valores[$campo] = $texto;
    }

    return ['valores' => $valores, 'erros' => $erros];
}

/**
 * Locação atual de um item numa loja, lida do Apollo, nos mesmos tipos que
 * conferencia_validar_locacao() devolve — para as duas poderem ser comparadas.
 *
 * @return array<string, int|string>|null null se o item não tem linha nessa loja.
 */
function conferencia_locacao_da_loja($ora, int $empresa, int $revenda, int $itemEstoque): ?array
{
    $linhas = conferencia_ora_consultar($ora, "
        SELECT locacao_zona, locacao_rua, locacao_estante,
               locacao_prateleira, locacao_numero
          FROM pec_item_revenda
         WHERE empresa = :empresa
           AND revenda = :revenda
           AND item_estoque = :item
    ", [':empresa' => $empresa, ':revenda' => $revenda, ':item' => $itemEstoque]);

    if ($linhas === []) {
        return null;
    }

    $row = $linhas[0];

    return [
        'zona'       => trim((string)$row['LOCACAO_ZONA']),
        'rua'        => (int)$row['LOCACAO_RUA'],
        'estante'    => (int)$row['LOCACAO_ESTANTE'],
        'prateleira' => trim((string)$row['LOCACAO_PRATELEIRA']),
        'numero'     => (int)$row['LOCACAO_NUMERO'],
    ];
}

/**
 * Grava a locação de um item numa loja, no Apollo.
 *
 * Exige que exatamente uma linha seja atingida. O acerto_locacao.php antigo não
 * confere isso: se o item não tem linha naquela loja, o UPDATE atinge zero
 * linhas e a tela diz "Sucesso!" mesmo assim.
 *
 * $commit = false existe para o teste: grava dentro da transação, confere, e
 * desfaz com rollback — sem nunca comitar no Apollo de produção.
 *
 * @param array<string, int|string> $valores saída de conferencia_validar_locacao()
 * @throws RuntimeException se o Apollo recusar ou se não for exatamente uma linha.
 */
function conferencia_gravar_locacao(
    $ora,
    int $empresa,
    int $revenda,
    int $itemEstoque,
    array $valores,
    bool $commit = true
): void {
    $stmt = oci_parse($ora, "
        UPDATE pec_item_revenda
           SET locacao_zona       = :zona,
               locacao_rua        = :rua,
               locacao_estante    = :estante,
               locacao_prateleira = :prateleira,
               locacao_numero     = :numero,
               dta_ult_alteracao  = SYSDATE
         WHERE empresa      = :empresa
           AND revenda      = :revenda
           AND item_estoque = :item
    ");

    $binds = [
        ':zona'       => (string)$valores['zona'],
        ':rua'        => (int)$valores['rua'],
        ':estante'    => (int)$valores['estante'],
        ':prateleira' => (string)$valores['prateleira'],
        ':numero'     => (int)$valores['numero'],
        ':empresa'    => $empresa,
        ':revenda'    => $revenda,
        ':item'       => $itemEstoque,
    ];
    foreach ($binds as $nome => $_valor) {
        // Por referência ao elemento do array — ver conferencia_ora_consultar().
        oci_bind_by_name($stmt, $nome, $binds[$nome]);
    }

    // Sem auto-commit: a linha só é confirmada depois de conferida a contagem.
    if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $erro = oci_error($stmt);
        oci_free_statement($stmt);
        oci_rollback($ora);
        throw new RuntimeException('Apollo: ' . ($erro['message'] ?? 'erro desconhecido'));
    }

    $linhas = oci_num_rows($stmt);
    oci_free_statement($stmt);

    if ($linhas !== 1) {
        oci_rollback($ora);
        throw new RuntimeException(
            "Apollo: esperava gravar 1 linha, gravou $linhas (item $itemEstoque, revenda $revenda)"
        );
    }

    if ($commit) {
        oci_commit($ora);
    }
}

/**
 * Leva a locação nova para o inventário aberto da loja, na hora: os cinco
 * campos e a locacao_geral das linhas do item na estoque. Sem isto, a troca
 * ficava só no Apollo, e a contagem, a recontagem e o relatório continuavam na
 * locação antiga até gerar outro inventário.
 *
 * Chamar DEPOIS de o Apollo gravar: é ele quem manda na locação, e o
 * inventário só acompanha. A contagem já feita fica na linha — muda o lugar
 * do item, não o que foi contado.
 *
 * Todos os inventários abertos da loja, e não só o da sessão: o acerto do
 * gestor não tem inventário na mão. A linha se acha pelo código interno ou
 * pelo público — a que a Tomada de Decisão insere não tem o interno.
 *
 * locacao_geral no mesmo formato do gerador: os cinco campos com traço.
 *
 * @param array{zona:string, rua:int|string, estante:int|string, prateleira:string, numero:int|string} $valores
 * @return int linhas atualizadas
 */
function conferencia_atualizar_locacao_inventario(
    PDO $pdo,
    int $empresa,
    int $revenda,
    string $codItemEstoque,
    string $itemPub,
    array $valores
): int {
    $campos = [
        'zona'       => trim((string)$valores['zona']),
        'rua'        => trim((string)$valores['rua']),
        'estante'    => trim((string)$valores['estante']),
        'prateleira' => trim((string)$valores['prateleira']),
        'numero'     => trim((string)$valores['numero']),
    ];

    // Código vazio nunca entra na busca: '' casaria com toda linha sem código.
    $cod     = trim($codItemEstoque);
    $pub     = trim($itemPub);
    $achar   = [];
    $params  = [];
    if ($cod !== '') {
        $achar[]        = 'e.cod_item_estoque = :cod';
        $params[':cod'] = $cod;
    }
    if ($pub !== '') {
        $achar[]        = 'e.item_estoque_pub = :pub';
        $params[':pub'] = $pub;
    }
    if ($achar === []) {
        return 0;
    }

    $stmt = $pdo->prepare("
        UPDATE estoque e
          JOIN inv_inventarios i
            ON i.id      = e.id_inventario
           AND i.empresa = e.empresa
           AND i.revenda = e.revenda
           SET e.locacao_zona       = :zona,
               e.locacao_rua        = :rua,
               e.locacao_estante    = :estante,
               e.locacao_prateleira = :prateleira,
               e.locacao_numero     = :numero,
               e.locacao_geral      = :geral
         WHERE e.empresa = :emp
           AND e.revenda = :rev
           AND i.status  = 'aberto'
           AND (" . implode(' OR ', $achar) . ")
    ");
    $stmt->execute($params + [
        ':zona'       => $campos['zona'],
        ':rua'        => $campos['rua'],
        ':estante'    => $campos['estante'],
        ':prateleira' => $campos['prateleira'],
        ':numero'     => $campos['numero'],
        ':geral'      => implode('-', $campos),
        ':emp'        => $empresa,
        ':rev'        => $revenda,
    ]);

    return $stmt->rowCount();
}

/**
 * Executa um UPDATE no Apollo dentro da transação aberta e exige exatamente uma
 * linha atingida. Qualquer outra coisa desfaz a transação inteira.
 *
 * @throws RuntimeException
 */
function conferencia_ora_atualizar_uma($ora, string $sql, array $binds, string $contexto): void
{
    $stmt = oci_parse($ora, $sql);
    foreach ($binds as $nome => $_valor) {
        // Por referência ao elemento do array — ver conferencia_ora_consultar().
        oci_bind_by_name($stmt, $nome, $binds[$nome]);
    }

    if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $erro = oci_error($stmt);
        oci_free_statement($stmt);
        oci_rollback($ora);
        throw new RuntimeException('Apollo: ' . ($erro['message'] ?? 'erro desconhecido'));
    }

    $linhas = oci_num_rows($stmt);
    oci_free_statement($stmt);

    if ($linhas !== 1) {
        oci_rollback($ora);
        throw new RuntimeException("Apollo: esperava 1 linha, atingiu $linhas ($contexto)");
    }
}

/**
 * Transfere saldo de uma filial para outra da mesma empresa, no Apollo: tira
 * $qtd de qtd_contabil da origem, soma no destino e grava no destino a locação
 * bipada — onde a peça está.
 *
 * Só para o caso da conferência: a filial destino está zerada (qtd_contabil -
 * qtd_alocada <= 0) e a origem tem saldo para ceder. As duas linhas são lidas
 * com FOR UPDATE, então ninguém mexe nelas entre a checagem e a gravação, e as
 * duas alterações vão num commit só — ou nenhuma.
 *
 * Não emite nota nem movimentação no Apollo: muda só os saldos, por decisão do
 * usuário (2026-10-09).
 *
 * $commit = false existe para o teste: grava, confere, e desfaz com rollback.
 *
 * @param array{zona:string, rua:int, estante:int, prateleira:string, numero:int} $locacao
 * @return array{origem_antes:int, destino_antes:int}
 * @throws InvalidArgumentException regra do negócio (mensagem para a tela)
 * @throws RuntimeException         falha do Apollo
 */
function conferencia_transferir_saldo(
    $ora,
    int $empresa,
    int $itemEstoque,
    int $revOrigem,
    int $revDestino,
    int $qtd,
    array $locacao,
    bool $commit = true
): array {
    if ($qtd < 1) {
        throw new InvalidArgumentException('Informe uma quantidade maior que zero.');
    }
    if ($revOrigem === $revDestino) {
        throw new InvalidArgumentException('Origem e destino são a mesma filial.');
    }

    $linhas = conferencia_ora_consultar($ora, "
        SELECT revenda, qtd_contabil, qtd_alocada
          FROM pec_item_revenda
         WHERE empresa = :empresa
           AND item_estoque = :item
           AND revenda IN (:origem, :destino)
           FOR UPDATE
    ", [':empresa' => $empresa, ':item' => $itemEstoque, ':origem' => $revOrigem, ':destino' => $revDestino]);

    $saldo = [];
    foreach ($linhas as $l) {
        $saldo[(int)$l['REVENDA']] = (int)$l['QTD_CONTABIL'] - (int)$l['QTD_ALOCADA'];
    }

    $recusa = null;
    if (!isset($saldo[$revOrigem])) {
        $recusa = 'O item não tem estoque cadastrado na filial de origem.';
    } elseif (!isset($saldo[$revDestino])) {
        $recusa = 'O item não tem estoque cadastrado nesta filial. Peça o cadastro no Apollo.';
    } elseif ($saldo[$revDestino] > 0) {
        $recusa = 'Esta filial não está zerada no Apollo (saldo ' . $saldo[$revDestino] . ').';
    } elseif ($qtd > $saldo[$revOrigem]) {
        $recusa = 'A origem só tem ' . max(0, $saldo[$revOrigem]) . ' disponível.';
    }
    if ($recusa !== null) {
        // Solta o FOR UPDATE.
        oci_rollback($ora);
        throw new InvalidArgumentException($recusa);
    }

    // A guarda de saldo repete a checagem no próprio UPDATE: barata, e não
    // depende de o FOR UPDATE ter sido respeitado.
    conferencia_ora_atualizar_uma($ora, "
        UPDATE pec_item_revenda
           SET qtd_contabil      = qtd_contabil - :qtd,
               dta_ult_alteracao = SYSDATE
         WHERE empresa      = :empresa
           AND revenda      = :revenda
           AND item_estoque = :item
           AND qtd_contabil - qtd_alocada >= :qtd
    ", [':qtd' => $qtd, ':empresa' => $empresa, ':revenda' => $revOrigem, ':item' => $itemEstoque], "origem $revOrigem");

    conferencia_ora_atualizar_uma($ora, "
        UPDATE pec_item_revenda
           SET qtd_contabil       = qtd_contabil + :qtd,
               locacao_zona       = :zona,
               locacao_rua        = :rua,
               locacao_estante    = :estante,
               locacao_prateleira = :prateleira,
               locacao_numero     = :numero,
               dta_ult_alteracao  = SYSDATE
         WHERE empresa      = :empresa
           AND revenda      = :revenda
           AND item_estoque = :item
    ", [
        ':qtd'        => $qtd,
        ':zona'       => (string)$locacao['zona'],
        ':rua'        => (int)$locacao['rua'],
        ':estante'    => (int)$locacao['estante'],
        ':prateleira' => (string)$locacao['prateleira'],
        ':numero'     => (int)$locacao['numero'],
        ':empresa'    => $empresa,
        ':revenda'    => $revDestino,
        ':item'       => $itemEstoque,
    ], "destino $revDestino");

    if ($commit) {
        oci_commit($ora);
    }

    return ['origem_antes' => $saldo[$revOrigem], 'destino_antes' => $saldo[$revDestino]];
}

/**
 * Locação da etiqueta (1-22-13-A-1) nos cinco campos validados do Apollo.
 *
 * @return array{valores: array<string, int|string>, erros: array<string, string>}|null
 *         null se o texto não é etiqueta de locação.
 */
function conferencia_locacao_em_campos(string $texto): ?array
{
    $etiqueta = conferencia_ler_etiqueta_locacao($texto);
    if ($etiqueta === null) {
        return null;
    }

    return conferencia_validar_locacao(array_combine(
        array_keys(CONFERENCIA_LOCACAO_CAMPOS),
        explode('-', $etiqueta)
    ));
}

/**
 * Abre o registro da transferência como 'pendente'. Chamar ANTES do Apollo:
 * se o MySQL cair depois do commit lá, a linha pendente denuncia.
 *
 * @return int id da linha criada.
 */
function conferencia_registrar_transferencia(PDO $pdo, array $d): int
{
    $entrada = conferencia_exigir_entrada($d['entrada_locacao'] ?? null);

    $stmt = $pdo->prepare("
        INSERT INTO inv_transferencias
            (id_inventario, empresa, revenda_origem, revenda_destino, coletor, dupla_label,
             item_estoque_pub, cod_item_estoque, descricao, quantidade,
             locacao_destino, entrada_locacao, status)
        VALUES
            (:inv, :emp, :orig, :dest, :col, :dupla,
             :pub, :cod, :descricao, :qtd,
             :loc, :entrada, 'pendente')
    ");
    $stmt->execute([
        ':inv'       => (int)$d['id_inventario'],
        ':emp'       => (int)$d['empresa'],
        ':orig'      => (int)$d['revenda_origem'],
        ':dest'      => (int)$d['revenda_destino'],
        ':col'       => (int)($d['coletor'] ?? 0),
        ':dupla'     => (string)($d['dupla_label'] ?? ''),
        ':pub'       => trim((string)$d['item_estoque_pub']),
        ':cod'       => trim((string)$d['cod_item_estoque']),
        ':descricao' => mb_substr((string)($d['descricao'] ?? ''), 0, 255),
        ':qtd'       => (int)$d['quantidade'],
        ':loc'       => (string)$d['locacao_destino'],
        ':entrada'   => $entrada,
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Fecha o registro: 'gravado' com os saldos de antes, ou 'erro' com o motivo.
 *
 * @param array{origem_antes:int, destino_antes:int}|null $saldos null = erro
 */
function conferencia_concluir_transferencia(PDO $pdo, int $id, ?array $saldos, string $erro = ''): void
{
    $gravado = $saldos !== null;

    $stmt = $pdo->prepare("
        UPDATE inv_transferencias
           SET status              = :status,
               saldo_origem_antes  = :orig,
               saldo_destino_antes = :dest,
               erro                = :erro,
               gravado_em          = " . ($gravado ? 'NOW()' : 'NULL') . "
         WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $gravado ? 'gravado' : 'erro',
        ':orig'   => $saldos['origem_antes']  ?? null,
        ':dest'   => $saldos['destino_antes'] ?? null,
        ':erro'   => $gravado ? null : mb_substr($erro, 0, 255),
        ':id'     => $id,
    ]);
}
