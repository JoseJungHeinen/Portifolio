<?php
declare(strict_types=1);

/**
 * recontagem_lib.php
 *
 * A recontagem por item: em que contagem cada linha da estoque está, o que ela
 * precisa, quem a reconta e o que a recontagem grava.
 *
 * Nada aqui lê $_SESSION, $_GET ou $_POST, e nada aqui imprime — o mesmo
 * desenho do contagem_2_lib.php, de que esta depende (ordem de caminhada,
 * rotação, regra de sufixo, coletores).
 */

require_once __DIR__ . '/contagem_2_lib.php';

/**
 * A última contagem que o sistema conduz.
 *
 * 4: o gestor encerra a recontagem de um item quando quiser, pelo botão
 * "Encerrar recontagem" — não precisa mais esperar a 4ª contagem sozinha
 * fechar o item. A Tomada de Decisão, porém, ainda só lê cont_1 e cont_2 até
 * a entrega seguinte: um item em decisão por ter chegado na 3ª ou 4ª
 * contagem (ou por ter sido encerrado) só entra nela quando ela ler as
 * quatro colunas.
 */
const RECONTAGEM_ULTIMA = 4;

/** Colunas de cada contagem. A 1ª não tem data: "feita" é situacao = 'C'. */
const RECONTAGEM_COLUNAS = [
    1 => ['valor' => 'cont_1', 'em' => null,       'entrada' => null,            'dupla' => null],
    2 => ['valor' => 'cont_2', 'em' => 'cont2_em', 'entrada' => 'cont2_entrada', 'dupla' => 'dupla_cont2'],
    3 => ['valor' => 'cont_3', 'em' => 'cont3_em', 'entrada' => 'cont3_entrada', 'dupla' => 'dupla_cont3'],
    4 => ['valor' => 'cont_4', 'em' => 'cont4_em', 'entrada' => 'cont4_entrada', 'dupla' => 'dupla_cont4'],
];

/** A contagem $n desta linha foi feita. */
function recontagem_feita(array $linha, int $n): bool
{
    if ($n === 1) {
        return ($linha['situacao'] ?? '') === 'C';
    }

    $em = RECONTAGEM_COLUNAS[$n]['em'] ?? null;

    return $em !== null && ($linha[$em] ?? null) !== null;
}

/** O valor da contagem $n, ou null se ela não foi feita. */
function recontagem_contagem(array $linha, int $n): ?int
{
    if (!recontagem_feita($linha, $n)) {
        return null;
    }

    $valor = $linha[RECONTAGEM_COLUNAS[$n]['valor']] ?? null;

    return $valor === null ? null : (int)$valor;
}

/**
 * O número que vale quando alguma contagem feita bate com o sistema, ou null.
 *
 * Só bater com o sistema fecha o item — duas contagens iguais entre si não
 * fecham (decisão do spec). Qualquer contagem feita vale, não só a última.
 */
function recontagem_valor_bateu(array $linha): ?int
{
    if (($linha['quantidade'] ?? null) === null) {
        return null;
    }

    $sistema = (int)$linha['quantidade'];

    for ($n = 1; $n <= 4; $n++) {
        if (recontagem_contagem($linha, $n) === $sistema) {
            return $sistema;
        }
    }

    return null;
}

/** A última contagem feita, da 4ª para a 1ª, ou null se nenhuma foi feita. */
function recontagem_ultima_feita(array $linha): ?int
{
    for ($n = 4; $n >= 1; $n--) {
        $valor = recontagem_contagem($linha, $n);

        if ($valor !== null) {
            return $valor;
        }
    }

    return null;
}

/**
 * Qual contagem está valendo para esta linha: a última feita (4, 3 ou 2, pela
 * data), ou a 1ª quando não houve recontagem — inclusive quando nem a 1ª foi
 * feita, porque é nela que o ajuste de quantidades grava.
 */
function recontagem_numero_ultima_feita(array $linha): int
{
    for ($n = 4; $n >= 2; $n--) {
        if (recontagem_feita($linha, $n)) {
            return $n;
        }
    }

    return 1;
}

/**
 * Ajuste de quantidades do gestor: grava a quantidade na última contagem feita
 * do item, a mesma que o relatório e o dashboard leem. Antes a tela gravava
 * sempre na cont_1, e depois da 2ª contagem o ajuste não aparecia em lugar
 * nenhum.
 *
 * Só troca o valor: a data da contagem (contN_em) fica, porque a contagem
 * continua feita, e a 1ª não muda de situação, como antes.
 *
 * @return array{contagem: ?int, linhas: int} contagem ajustada (null se a linha não existe)
 * @throws InvalidArgumentException se a quantidade for negativa.
 */
function recontagem_ajustar_ultima(PDO $pdo, int $inv, int $emp, int $rev, string $item, string $locacao, int $qtd): array
{
    if ($qtd < 0) {
        throw new InvalidArgumentException('Quantidade negativa: ' . $qtd);
    }

    $stmt = $pdo->prepare("
        SELECT *
          FROM estoque
         WHERE id_inventario    = :inv
           AND empresa          = :emp
           AND revenda          = :rev
           AND item_estoque_pub = :item
           AND locacao_geral    = :loc
    ");
    $stmt->execute([':inv' => $inv, ':emp' => $emp, ':rev' => $rev, ':item' => $item, ':loc' => $locacao]);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($linhas === []) {
        return ['contagem' => null, 'linhas' => 0];
    }

    // Cada linha na sua contagem: com duas linhas do mesmo item na mesma
    // locação, uma pode ter sido recontada e a outra não.
    $contagem = null;
    $total    = 0;
    foreach ($linhas as $linha) {
        $n      = recontagem_numero_ultima_feita($linha);
        $coluna = RECONTAGEM_COLUNAS[$n]['valor'];

        $upd = $pdo->prepare("UPDATE estoque SET $coluna = ? WHERE id_linha = ?");
        $upd->execute([$qtd, (int)$linha['id_linha']]);

        $total   += 1;
        $contagem = max($contagem ?? 0, $n);
    }

    return ['contagem' => $contagem, 'linhas' => $total];
}

/**
 * A situação do item, calculada das colunas — nunca gravada, para não ficar
 * desatualizada. Mesma ordem do recontagem_sql_situacao(): a primeira regra que
 * casar vence.
 *
 * `bateu` vem antes de `encerrada`: um item que o gestor encerrou e que por
 * acaso bate com o sistema (ex.: a contagem que já tinha quando encerrou) é
 * `bateu`, não `decisao` — encerrar não pode esconder um item que já bateu.
 *
 * @return string descartado|bateu|decisao|aguardando|contando|nao_contado
 */
function recontagem_situacao(array $linha, int $ultima = RECONTAGEM_ULTIMA): string
{
    $ultima = max(1, min(4, $ultima));

    if (($linha['situacao'] ?? '') === 'D') {
        return 'descartado';
    }

    if (recontagem_valor_bateu($linha) !== null) {
        return 'bateu';
    }

    if (($linha['recontagem_encerrada_em'] ?? null) !== null) {
        return 'decisao';
    }

    $rodada = (int)($linha['rodada_item'] ?? 1);

    if (recontagem_feita($linha, $rodada)) {
        return $rodada >= $ultima ? 'decisao' : 'aguardando';
    }

    return $rodada >= 2 ? 'contando' : 'nao_contado';
}

/** "A contagem atual está feita", em SQL. Referencia as colunas sem apelido. */
const RECONTAGEM_SQL_FEITA_ATUAL = "(CASE rodada_item
    WHEN 1 THEN situacao = 'C'
    WHEN 2 THEN cont2_em IS NOT NULL
    WHEN 3 THEN cont3_em IS NOT NULL
    WHEN 4 THEN cont4_em IS NOT NULL
    ELSE 0 END)";

/**
 * A mesma regra do recontagem_situacao(), em SQL, para filtrar e contar na
 * grade sem trazer 18 mil linhas para o PHP. O teste compara as duas.
 */
function recontagem_sql_situacao(int $ultima = RECONTAGEM_ULTIMA): string
{
    $ultima = max(1, min(4, $ultima));

    return "(CASE
        WHEN situacao = 'D' THEN 'descartado'
        WHEN (situacao = 'C'             AND cont_1 = quantidade)
          OR (cont2_em IS NOT NULL       AND cont_2 = quantidade)
          OR (cont3_em IS NOT NULL       AND cont_3 = quantidade)
          OR (cont4_em IS NOT NULL       AND cont_4 = quantidade) THEN 'bateu'
        WHEN recontagem_encerrada_em IS NOT NULL THEN 'decisao'
        WHEN " . RECONTAGEM_SQL_FEITA_ATUAL . " AND rodada_item >= $ultima THEN 'decisao'
        WHEN " . RECONTAGEM_SQL_FEITA_ATUAL . " THEN 'aguardando'
        WHEN rodada_item >= 2 THEN 'contando'
        ELSE 'nao_contado'
    END)";
}

/** A última contagem feita, em SQL (null se nenhuma). */
const RECONTAGEM_SQL_ULTIMA_FEITA = "(CASE
    WHEN cont4_em IS NOT NULL THEN cont_4
    WHEN cont3_em IS NOT NULL THEN cont_3
    WHEN cont2_em IS NOT NULL THEN cont_2
    WHEN situacao = 'C'       THEN cont_1
    ELSE NULL END)";

/**
 * A quantidade que vale para o resultado do inventário (relatório e dashboard),
 * em SQL: se alguma contagem feita bateu, o sistema; senão a última contagem
 * feita, lida pela data (contN_em) porque o gerador
 * insere cont_2 = 0. Sem recontagem, é a cont_1 — inclusive o 0 do gerador,
 * para "não contado" e falta continuarem como antes.
 *
 * Decisão de ajuste da Tomada de Decisão vence a recontagem: ela grava a
 * quantidade decidida em cont_1 e deixa o registro em inv_decisoes. O UPDATE
 * de lá pega uma linha só do item (LIMIT 1); as outras locações do mesmo item
 * também caem na cont_1, que é o que o relatório já mostrava antes.
 *
 * $tabela qualifica as colunas: dentro do EXISTS, nome solto seria de
 * inv_decisoes. $comDecisoes = false quando a inv_decisoes não existe (ela só
 * nasce na primeira abertura da Tomada de Decisão) — ver recontagem_tem_decisoes().
 */
function recontagem_sql_contagem_final(bool $comDecisoes = true, string $tabela = 'estoque'): string
{
    $t = $tabela;

    $decisao = $comDecisoes
        ? "WHEN EXISTS (SELECT 1 FROM inv_decisoes d
                 WHERE d.id_inventario    = $t.id_inventario
                   AND d.empresa          = $t.empresa
                   AND d.revenda          = $t.revenda
                   AND d.item_estoque_pub = $t.item_estoque_pub
                   AND d.decisao IN ('ajuste_positivo','ajuste_negativo')) THEN $t.cont_1"
        : '';

    // Bateu com qualquer contagem feita: vale o sistema, como no
    // recontagem_valor_bateu() e na situação 'bateu' da grade.
    return "(CASE $decisao
        WHEN ($t.situacao = 'C'         AND $t.cont_1 = $t.quantidade)
          OR ($t.cont2_em IS NOT NULL   AND $t.cont_2 = $t.quantidade)
          OR ($t.cont3_em IS NOT NULL   AND $t.cont_3 = $t.quantidade)
          OR ($t.cont4_em IS NOT NULL   AND $t.cont_4 = $t.quantidade) THEN $t.quantidade
        WHEN $t.cont4_em IS NOT NULL THEN $t.cont_4
        WHEN $t.cont3_em IS NOT NULL THEN $t.cont_3
        WHEN $t.cont2_em IS NOT NULL THEN $t.cont_2
        ELSE $t.cont_1 END)";
}

/** A inv_decisoes existe neste banco. */
function recontagem_tem_decisoes(PDO $pdo): bool
{
    try {
        return $pdo->query("SHOW TABLES LIKE 'inv_decisoes'")->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Situações que a grade filtra. 'todos' tira só os descartados; os outros três
 * são grupos de situação (ver RECONTAGEM_GRUPOS) — não a situação detalhada,
 * que a coluna Situação de cada linha continua mostrando.
 */
const RECONTAGEM_FILTROS = ['recontar', 'a_contar', 'contado', 'todos'];

/** O que cada filtro de grupo reúne. 'todos' não está aqui: é tudo, menos descartado. */
const RECONTAGEM_GRUPOS = [
    'recontar' => ['aguardando'],
    'a_contar' => ['contando', 'nao_contado'],
    'contado'  => ['bateu', 'decisao'],
];

/** O que o gestor pode marcar: o que ainda não fechou. */
const RECONTAGEM_MARCAVEIS = ['nao_contado', 'aguardando', 'contando'];

/**
 * O WHERE e os parâmetros do filtro da grade e da seleção por faixa — extraído
 * para as duas nunca divergirem sobre o que é "o filtro atual".
 *
 * O filtro chega do navegador: a situação só entra se estiver na lista
 * fechada (mapeada para sit IN (...) por grupo), a contagem só se for 1..4, e
 * a busca vai por parâmetro. Nada dele é colado no SQL.
 *
 * @param array{situacao?:string, rodada?:int|string, busca?:string} $filtro
 * @return array{0:list<string>, 1:array<string,mixed>}
 */
function recontagem_filtro_sql(array $filtro): array
{
    $situacao = is_string($filtro['situacao'] ?? null) ? $filtro['situacao'] : 'recontar';
    $situacao = in_array($situacao, RECONTAGEM_FILTROS, true) ? $situacao : 'recontar';

    $rodada = (int)($filtro['rodada'] ?? 0);
    $busca  = is_string($filtro['busca'] ?? null) ? trim($filtro['busca']) : '';

    $onde   = ["sit <> 'descartado'"];
    $params = [];

    if ($situacao !== 'todos') {
        $grupo  = RECONTAGEM_GRUPOS[$situacao];
        $nomes  = [];
        foreach ($grupo as $i => $s) {
            $nome = ":sit$i";
            $nomes[] = $nome;
            $params[$nome] = $s;
        }
        $onde[] = 'sit IN (' . implode(',', $nomes) . ')';
    }

    if ($rodada >= 1 && $rodada <= 4) {
        $onde[] = 'rodada_item = :rod';
        $params[':rod'] = $rodada;
    }

    if ($busca !== '') {
        $onde[] = "(item_estoque_pub LIKE :busca OR locacao_geral LIKE :busca2)";
        $params[':busca']  = '%' . $busca . '%';
        $params[':busca2'] = '%' . $busca . '%';
    }

    return [$onde, $params];
}

/**
 * A subconsulta `t` (situação e última contagem calculadas em SQL) mais o
 * WHERE do filtro — a base que a grade, a lista de locações e a seleção por
 * faixa compartilham.
 *
 * @param array{situacao?:string, rodada?:int|string, busca?:string} $filtro
 * @return array{0:string, 1:array<string,mixed>}
 */
function recontagem_base_sql(int $inv, int $emp, int $rev, array $filtro): array
{
    [$onde, $params] = recontagem_filtro_sql($filtro);
    $params[':inv'] = $inv;
    $params[':emp'] = $emp;
    $params[':rev'] = $rev;

    $base = "
        FROM (
            SELECT id_linha, item_estoque_pub, descricao_item_estoque, locacao_geral,
                   locacao_zona, locacao_rua, locacao_estante, locacao_prateleira, locacao_numero,
                   quantidade, cont_1, cont_2, cont_3, cont_4, cont2_em, cont3_em, cont4_em,
                   situacao, rodada_item, coletor_recontagem, custo_entrada, recontagem_encerrada_em,
                   " . recontagem_sql_situacao() . " AS sit,
                   " . RECONTAGEM_SQL_ULTIMA_FEITA . " AS ultima
              FROM estoque
             WHERE id_inventario = :inv AND empresa = :emp AND revenda = :rev
        ) t
        WHERE " . implode(' AND ', $onde);

    return [$base, $params];
}

/**
 * As locações com item no filtro, na ordem de caminhada — todas, não só as do
 * limite de linhas. Mesmo padrão GROUP BY + ORDER BY MIN(...) do
 * recontagem_locacoes_pendentes().
 *
 * @return list<string>
 */
function recontagem_locacoes_do_filtro(PDO $pdo, string $base, array $params): array
{
    $stmt = $pdo->prepare("
        SELECT locacao_geral
        " . $base . "
        GROUP BY locacao_geral
        ORDER BY MIN(locacao_zona),
                 MIN(CAST(locacao_rua       AS UNSIGNED)),
                 MIN(CAST(locacao_estante   AS UNSIGNED)),
                 MIN(locacao_prateleira),
                 MIN(CAST(locacao_numero    AS UNSIGNED)),
                 locacao_geral
    ");
    $stmt->execute($params);

    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * As linhas da grade do gestor, na ordem de caminhada.
 *
 * A situação sai do CASE em SQL, numa subconsulta, para o filtro e o limite
 * rodarem no banco — trazer 18 mil linhas a cada 15 s travaria a tela.
 *
 * @param array{situacao?:string, rodada?:int|string, busca?:string} $filtro
 * @return array{linhas:list<array>, total:int, cortado:bool, valor_divergencia:float, locacoes:list<string>}
 */
function recontagem_grade(PDO $pdo, int $inv, int $emp, int $rev, array $filtro, int $limite = 500): array
{
    [$base, $params] = recontagem_base_sql($inv, $emp, $rev, $filtro);

    $tot = $pdo->prepare("
        SELECT COUNT(*) AS total,
               COALESCE(SUM(CASE WHEN sit <> 'bateu' AND ultima IS NOT NULL
                                 THEN ABS(ultima - quantidade) * custo_entrada ELSE 0 END), 0) AS valor
        " . $base);
    $tot->execute($params);
    $totais = $tot->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'valor' => 0];

    $locacoes = recontagem_locacoes_do_filtro($pdo, $base, $params);

    $limite = max(1, $limite);
    $stmt = $pdo->prepare("SELECT * " . $base . " ORDER BY " . CONTAGEM2_ORDEM . ", id_linha LIMIT " . ($limite + 1));
    $stmt->execute($params);
    $brutas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cortado = count($brutas) > $limite;
    $brutas  = array_slice($brutas, 0, $limite);

    $linhas = [];
    foreach ($brutas as $b) {
        $linhas[] = [
            'id'         => (int)$b['id_linha'],
            'item'       => (string)$b['item_estoque_pub'],
            'descricao'  => (string)$b['descricao_item_estoque'],
            'locacao'    => (string)$b['locacao_geral'],
            'sistema'    => $b['quantidade'] === null ? null : (int)$b['quantidade'],
            'contagens'  => [
                1 => recontagem_contagem($b, 1),
                2 => recontagem_contagem($b, 2),
                3 => recontagem_contagem($b, 3),
                4 => recontagem_contagem($b, 4),
            ],
            'rodada'     => (int)$b['rodada_item'],
            'coletor'    => $b['coletor_recontagem'] === null ? null : (int)$b['coletor_recontagem'],
            'situacao'   => (string)$b['sit'],
            'marcavel'   => in_array($b['sit'], RECONTAGEM_MARCAVEIS, true),
            'encerrada'  => $b['recontagem_encerrada_em'] !== null,
        ];
    }

    return [
        'linhas'            => $linhas,
        'total'             => (int)$totais['total'],
        'cortado'           => $cortado,
        'valor_divergencia' => round((float)$totais['valor'], 2),
        'locacoes'          => $locacoes,
    ];
}

/**
 * Os ids de linha marcáveis do filtro, cuja locação cai entre $de e $ate
 * (inclusive, em qualquer ordem) na lista de locações em ordem de caminhada
 * desse mesmo filtro, e cujo sinal bate com $sinal.
 *
 * Sem limite de linhas — ao contrário da grade, a seleção por faixa precisa
 * marcar tudo que estiver no intervalo, mesmo fora das 500 primeiras.
 *
 * 'mais' = última contagem feita > sistema; 'menos' = última < sistema;
 * 'todos' = qualquer uma, inclusive nunca contada. Um item nunca contado
 * (ultima null) só entra em 'todos'. Um $sinal desconhecido vale como 'todos'.
 * Se $de ou $ate não estiver na lista de locações do filtro, devolve [].
 *
 * @param array{situacao?:string, rodada?:int|string, busca?:string} $filtro
 * @return list<int>
 */
function recontagem_selecionar(PDO $pdo, int $inv, int $emp, int $rev, array $filtro, string $de, string $ate, string $sinal): array
{
    [$base, $params] = recontagem_base_sql($inv, $emp, $rev, $filtro);

    $locacoes = recontagem_locacoes_do_filtro($pdo, $base, $params);

    $iDe  = array_search($de, $locacoes, true);
    $iAte = array_search($ate, $locacoes, true);

    if ($iDe === false || $iAte === false) {
        return [];
    }

    if ($iDe > $iAte) {
        [$iDe, $iAte] = [$iAte, $iDe];
    }

    // $iDe e $iAte já são índices válidos de $locacoes, então a faixa nunca
    // vem vazia aqui.
    $faixa = array_slice($locacoes, $iDe, $iAte - $iDe + 1);

    foreach (RECONTAGEM_MARCAVEIS as $i => $s) {
        $params[":marc$i"] = $s;
    }
    $marcaveisSql = 'sit IN (' . implode(',', array_map(fn($i) => ":marc$i", array_keys(RECONTAGEM_MARCAVEIS))) . ')';

    foreach ($faixa as $i => $l) {
        $params[":loc$i"] = $l;
    }
    $locSql = 'locacao_geral IN (' . implode(',', array_map(fn($i) => ":loc$i", array_keys($faixa))) . ')';

    $sinalSql = '';
    if ($sinal === 'mais') {
        $sinalSql = 'AND ultima IS NOT NULL AND ultima > quantidade';
    } elseif ($sinal === 'menos') {
        $sinalSql = 'AND ultima IS NOT NULL AND ultima < quantidade';
    }
    // 'todos', ou qualquer valor desconhecido: sem filtro extra de sinal.

    $stmt = $pdo->prepare("
        SELECT id_linha
        " . $base . "
          AND $marcaveisSql
          AND $locSql
          $sinalSql
        ORDER BY " . CONTAGEM2_ORDEM . ", id_linha
    ");
    $stmt->execute($params);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Quantos itens em cada situação, no inventário todo (sem descartados).
 *
 * @return array{nao_contado:int, contando:int, aguardando:int, bateu:int, decisao:int}
 */
function recontagem_resumo(PDO $pdo, int $inv, int $emp, int $rev): array
{
    $stmt = $pdo->prepare("
        SELECT sit, COUNT(*) AS n
          FROM (SELECT " . recontagem_sql_situacao() . " AS sit
                  FROM estoque
                 WHERE id_inventario = :inv AND empresa = :emp AND revenda = :rev) t
         GROUP BY sit
    ");
    $stmt->execute([':inv' => $inv, ':emp' => $emp, ':rev' => $rev]);
    $contas = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'n', 'sit');

    $resumo = [];
    foreach (['nao_contado', 'contando', 'aguardando', 'bateu', 'decisao'] as $s) {
        $resumo[$s] = (int)($contas[$s] ?? 0);
    }

    return $resumo;
}

/**
 * Lê o resumo (recontagem_resumo) e diz se a recontagem está concluída: nada
 * mais aguardando, sendo contado ou nunca contado, e ao menos um item bateu
 * ou foi para decisão. Pura — sem banco — para o teste não precisar de fixture.
 *
 * Um inventário em que nada foi contado ainda (tudo em nao_contado, ou um
 * resumo vazio) não conta como concluído: falta contar, e "0 de 0" não é
 * "pronto".
 *
 * @param array{nao_contado?:int, contando?:int, aguardando?:int, bateu?:int, decisao?:int} $resumo
 * @return array{concluida:bool, contados:int, bateu:int, decisao:int,
 *               falta_recontar:int, falta_contando:int, falta_nunca_contados:int}
 */
function recontagem_conclusao(array $resumo): array
{
    $bateu      = (int)($resumo['bateu'] ?? 0);
    $decisao    = (int)($resumo['decisao'] ?? 0);
    $aguardando = (int)($resumo['aguardando'] ?? 0);
    $contando   = (int)($resumo['contando'] ?? 0);
    $naoContado = (int)($resumo['nao_contado'] ?? 0);

    $contados = $bateu + $decisao;

    return [
        'concluida'            => $aguardando === 0 && $contando === 0 && $naoContado === 0 && $contados > 0,
        'contados'             => $contados,
        'bateu'                => $bateu,
        'decisao'              => $decisao,
        'falta_recontar'       => $aguardando,
        'falta_contando'       => $contando,
        'falta_nunca_contados' => $naoContado,
    ];
}

/** @throws InvalidArgumentException se o coletor não for 1..8. */
function recontagem_exigir_coletor(int $coletor): int
{
    if ($coletor < 1 || $coletor > CONTAGEM2_MAX_COLETORES) {
        throw new InvalidArgumentException('Coletor inválido: ' . $coletor);
    }

    return $coletor;
}

/**
 * As linhas destes ids que são deste inventário, por id.
 *
 * FOR UPDATE: dentro de uma transação, mantém as linhas travadas da leitura
 * até a escrita — fecha a corrida entre dois gestores, tanto no mandar quanto
 * no trocar. Sem transação (os testes rodam em autocommit) a trava solta na
 * hora, então nada quebra. O chamador precisa envolver a chamada numa
 * transação; recontagem_ajax.php faz isso.
 *
 * $paraAtualizar = false tira o FOR UPDATE: usado pela prévia
 * (recontagem_previa_envio), que só lê para contar e não pode travar linha
 * nenhuma — ela roda fora de transação, enquanto o gestor ainda decide.
 *
 * @return array<int,array>
 */
function recontagem_linhas_por_id(PDO $pdo, int $inv, int $emp, int $rev, array $ids, bool $paraAtualizar = true): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));

    if ($ids === []) {
        return [];
    }

    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT * FROM estoque
         WHERE id_linha IN ($marcas) AND id_inventario = ? AND empresa = ? AND revenda = ?
         " . ($paraAtualizar ? 'FOR UPDATE' : '') . "
    ");
    $stmt->execute(array_merge($ids, [$inv, $emp, $rev]));

    $linhas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $linhas[(int)$l['id_linha']] = $l;
    }

    return $linhas;
}

/**
 * Manda os itens para a próxima contagem, com o coletor escolhido.
 *
 * A chave é estoque.id_linha, nunca item + locação. Cada item é conferido de novo
 * aqui — a tela pode estar 15 s atrasada. O UPDATE leva a rodada lida no WHERE:
 * dois cliques simultâneos não pulam duas contagens.
 *
 * O chamador precisa envolver a chamada numa transação (ver
 * recontagem_linhas_por_id) — recontagem_ajax.php faz isso.
 *
 * @param list<int> $ids
 * @return array{enviados:int, recusados:array<string,int>}
 * @throws InvalidArgumentException se o coletor não for 1..8
 */
function recontagem_mandar(PDO $pdo, int $inv, int $emp, int $rev, array $ids, int $coletor, int $ultima = RECONTAGEM_ULTIMA): array
{
    $ultima = max(1, min(4, $ultima));

    recontagem_exigir_coletor($coletor);

    $recusados = ['bateu' => 0, 'decisao' => 0, 'descartado' => 0, 'teto' => 0, 'nao_encontrado' => 0, 'mudou' => 0];
    $enviados  = 0;

    $pedidos = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    $linhas  = recontagem_linhas_por_id($pdo, $inv, $emp, $rev, $pedidos);

    $upd = $pdo->prepare("
        UPDATE estoque
           SET rodada_item = rodada_item + 1, coletor_recontagem = :col
         WHERE id_linha = :id AND rodada_item = :rod
         LIMIT 1
    ");

    foreach ($pedidos as $id) {
        if (!isset($linhas[$id])) {
            $recusados['nao_encontrado']++;
            continue;
        }

        $linha    = $linhas[$id];
        $situacao = recontagem_situacao($linha, $ultima);

        if (in_array($situacao, ['bateu', 'decisao', 'descartado'], true)) {
            $recusados[$situacao]++;
            continue;
        }

        if ((int)$linha['rodada_item'] >= $ultima) {
            $recusados['teto']++;
            continue;
        }

        $upd->execute([':col' => $coletor, ':id' => $id, ':rod' => (int)$linha['rodada_item']]);

        if ($upd->rowCount() > 0) {
            $enviados++;
        } else {
            // Outro gestor mandou este item entre a leitura e a escrita.
            $recusados['mudou']++;
        }
    }

    return ['enviados' => $enviados, 'recusados' => $recusados];
}

/**
 * Troca o coletor de itens que estão sendo contados, sem subir a contagem —
 * para quando alguém falta, ou para designar os que vieram sem coletor.
 *
 * O chamador precisa envolver a chamada numa transação (ver
 * recontagem_linhas_por_id) — recontagem_ajax.php faz isso.
 *
 * @param list<int> $ids
 * @return array{trocados:int, recusados:int}
 * @throws InvalidArgumentException se o coletor não for 1..8
 */
function recontagem_trocar_coletor(PDO $pdo, int $inv, int $emp, int $rev, array $ids, int $coletor): array
{
    recontagem_exigir_coletor($coletor);

    $linhas = recontagem_linhas_por_id($pdo, $inv, $emp, $rev, $ids);
    $upd    = $pdo->prepare("UPDATE estoque SET coletor_recontagem = ? WHERE id_linha = ? LIMIT 1");

    $trocados = 0;
    foreach ($linhas as $id => $linha) {
        if (recontagem_situacao($linha) === 'contando') {
            $upd->execute([$coletor, $id]);
            $trocados++;
        }
    }

    $pedidos = count(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));

    return ['trocados' => $trocados, 'recusados' => $pedidos - $trocados];
}

/**
 * Botão único do gestor: manda cada item marcado pelo caminho certo, conforme
 * a situação atual dele — 'aguardando'/'nao_contado' vão para a próxima
 * contagem (recontagem_mandar); 'contando' só troca de coletor, sem subir a
 * rodada (recontagem_trocar_coletor). As regras continuam só nessas duas
 * funções; esta apenas separa a lista e delega, para não haver uma terceira
 * cópia das mesmas checagens.
 *
 * O chamador precisa envolver a chamada numa transação (ver
 * recontagem_linhas_por_id) — recontagem_ajax.php faz isso.
 *
 * @param list<int> $ids
 * @return array{enviados:int, trocados:int, recusados:array<string,int>}
 * @throws InvalidArgumentException se o coletor não for 1..8 — antes de gravar
 *         qualquer coisa.
 */
function recontagem_enviar(PDO $pdo, int $inv, int $emp, int $rev, array $ids, int $coletor, int $ultima = RECONTAGEM_ULTIMA): array
{
    $ultima = max(1, min(4, $ultima));

    // Confere o coletor antes de tocar no banco: um coletor inválido não pode
    // trocar metade dos itens e recusar a outra metade.
    recontagem_exigir_coletor($coletor);

    $pedidos = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    $linhas  = recontagem_linhas_por_id($pdo, $inv, $emp, $rev, $pedidos);

    $contando = [];
    $outros   = [];
    foreach ($pedidos as $id) {
        $situacao = isset($linhas[$id]) ? recontagem_situacao($linhas[$id], $ultima) : null;
        if ($situacao === 'contando') {
            $contando[] = $id;
        } else {
            // Inclui os não encontrados: recontagem_mandar sabe recusá-los
            // como 'nao_encontrado', a mesma checagem que já existia.
            $outros[] = $id;
        }
    }

    $trocado = $contando === []
        ? ['trocados' => 0, 'recusados' => 0]
        : recontagem_trocar_coletor($pdo, $inv, $emp, $rev, $contando, $coletor);

    $mandado = $outros === []
        ? ['enviados' => 0, 'recusados' => ['bateu' => 0, 'decisao' => 0, 'descartado' => 0, 'teto' => 0, 'nao_encontrado' => 0, 'mudou' => 0]]
        : recontagem_mandar($pdo, $inv, $emp, $rev, $outros, $coletor, $ultima);

    return [
        'enviados'  => $mandado['enviados'],
        'trocados'  => $trocado['trocados'],
        'recusados' => $mandado['recusados'],
    ];
}

/**
 * A prévia do botão único: os mesmos números que recontagem_enviar produziria,
 * sem gravar nada e sem travar linha nenhuma (FOR UPDATE desligado — ver
 * recontagem_linhas_por_id). Existe porque os ids marcados podem estar fora
 * da tela (seleção por faixa cobre além das 500 linhas da grade), então o
 * navegador não tem como contar sozinho o que vai acontecer antes de o
 * gestor confirmar.
 *
 * A classificação segue a mesma ordem do recontagem_mandar (bateu/decisão/
 * descartado recusam, depois o teto da $ultima recusa, o resto avança), com
 * 'contando' tratado à parte como no recontagem_enviar. A única coisa que a
 * prévia não prevê é a corrida ('mudou'): outro gestor mexendo no mesmo item
 * entre a prévia e o envio de fato — por isso ela não trava nada.
 *
 * `encerraveis` conta, à parte, quantos ids poderiam ser fechados pelo botão
 * "Encerrar recontagem" (RECONTAGEM_MARCAVEIS) — para a tela confirmar a ação
 * mesmo com ids escondidos ou fora das 500 linhas mostradas (seleção por
 * faixa).
 *
 * @param list<int> $ids
 * @return array{para_proxima:int, trocar:int, recusados:int, encerraveis:int}
 */
function recontagem_previa_envio(PDO $pdo, int $inv, int $emp, int $rev, array $ids, int $ultima = RECONTAGEM_ULTIMA): array
{
    $ultima = max(1, min(4, $ultima));

    $pedidos = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    $linhas  = recontagem_linhas_por_id($pdo, $inv, $emp, $rev, $pedidos, false);

    $paraProxima = 0;
    $trocar      = 0;
    $recusados   = 0;
    $encerraveis = 0;

    foreach ($pedidos as $id) {
        if (!isset($linhas[$id])) {
            $recusados++;
            continue;
        }

        $situacao = recontagem_situacao($linhas[$id], $ultima);

        if (in_array($situacao, RECONTAGEM_MARCAVEIS, true)) {
            $encerraveis++;
        }

        if ($situacao === 'contando') {
            $trocar++;
            continue;
        }

        if (in_array($situacao, ['bateu', 'decisao', 'descartado'], true)) {
            $recusados++;
            continue;
        }

        // aguardando ou nao_contado: só avança se ainda não bateu no teto da
        // $ultima — a mesma checagem do recontagem_mandar.
        if ((int)$linhas[$id]['rodada_item'] >= $ultima) {
            $recusados++;
            continue;
        }

        $paraProxima++;
    }

    return ['para_proxima' => $paraProxima, 'trocar' => $trocar, 'recusados' => $recusados, 'encerraveis' => $encerraveis];
}

/** O WHERE da fila de um coletor: designado a ele, contagem atual pendente. */
const RECONTAGEM_SQL_FILA = "
        id_inventario = :inv AND empresa = :emp AND revenda = :rev
    AND situacao <> 'D'
    AND rodada_item BETWEEN 2 AND 4
    AND coletor_recontagem = :col
    AND recontagem_encerrada_em IS NULL
    AND NOT " . RECONTAGEM_SQL_FEITA_ATUAL;

/**
 * Os itens que este coletor tem a recontar, na ordem de caminhada. Mistura 2ª,
 * 3ª e 4ª contagem.
 *
 * Às cegas: não traz quantidade nem contagens anteriores. O coletor que vê o
 * sistema digita o sistema, e o item "bate" sem ter sido contado.
 *
 * @return list<array{id:int, item_estoque_pub:string, descricao_item_estoque:string,
 *                    locacao_geral:string, rodada:int, locacao_zona:string,
 *                    locacao_rua:string, locacao_estante:string,
 *                    locacao_prateleira:string, locacao_numero:string}>
 */
function recontagem_fila(PDO $pdo, int $inv, int $emp, int $rev, int $coletor): array
{
    $stmt = $pdo->prepare("
        SELECT id_linha, item_estoque_pub, descricao_item_estoque, locacao_geral, rodada_item,
               locacao_zona, locacao_rua, locacao_estante, locacao_prateleira, locacao_numero
          FROM estoque
         WHERE " . RECONTAGEM_SQL_FILA . "
         ORDER BY " . CONTAGEM2_ORDEM);
    $stmt->execute([':inv' => $inv, ':emp' => $emp, ':rev' => $rev, ':col' => $coletor]);

    $fila = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $fila[] = [
            'id'                     => (int)$l['id_linha'],
            'item_estoque_pub'       => (string)$l['item_estoque_pub'],
            'descricao_item_estoque' => (string)$l['descricao_item_estoque'],
            'locacao_geral'          => (string)$l['locacao_geral'],
            'rodada'                 => (int)$l['rodada_item'],
            // Os campos decompostos vão para a rotação pela locação inicial.
            'locacao_zona'           => (string)$l['locacao_zona'],
            'locacao_rua'            => (string)$l['locacao_rua'],
            'locacao_estante'        => (string)$l['locacao_estante'],
            'locacao_prateleira'     => (string)$l['locacao_prateleira'],
            'locacao_numero'         => (string)$l['locacao_numero'],
        ];
    }

    return $fila;
}

/**
 * "3 de 12" no topo da tela do coletor: tudo o que está designado a ele numa
 * contagem de recontagem, e quanto disso já foi feito.
 *
 * @return array{total:int, feitos:int}
 */
function recontagem_totais_coletor(PDO $pdo, int $inv, int $emp, int $rev, int $coletor): array
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               COALESCE(SUM(CASE WHEN " . RECONTAGEM_SQL_FEITA_ATUAL . " THEN 1 ELSE 0 END), 0) AS feitos
          FROM estoque
         WHERE id_inventario = :inv AND empresa = :emp AND revenda = :rev
           AND situacao <> 'D' AND rodada_item BETWEEN 2 AND 4 AND coletor_recontagem = :col
           AND recontagem_encerrada_em IS NULL
    ");
    $stmt->execute([':inv' => $inv, ':emp' => $emp, ':rev' => $rev, ':col' => $coletor]);
    $l = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return ['total' => (int)($l['total'] ?? 0), 'feitos' => (int)($l['feitos'] ?? 0)];
}

/**
 * As locações com item pendente para este coletor, na ordem de caminhada — o
 * que o login oferece como locação inicial.
 *
 * @return list<string>
 */
function recontagem_locacoes_pendentes(PDO $pdo, int $inv, int $emp, int $rev, int $coletor): array
{
    $stmt = $pdo->prepare("
        SELECT locacao_geral
          FROM estoque
         WHERE " . RECONTAGEM_SQL_FILA . "
         GROUP BY locacao_geral
         ORDER BY MIN(locacao_zona),
                  MIN(CAST(locacao_rua     AS UNSIGNED)),
                  MIN(CAST(locacao_estante AS UNSIGNED)),
                  MIN(locacao_prateleira),
                  MIN(CAST(locacao_numero  AS UNSIGNED))
    ");
    $stmt->execute([':inv' => $inv, ':emp' => $emp, ':rev' => $rev, ':col' => $coletor]);

    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Grava a recontagem de um item na coluna da contagem em que ele está.
 *
 * Sobrescreve, não soma: a fila manda o coletor uma vez a cada item. O WHERE
 * exige a contagem, o coletor e a data vazia lidos agora — se o gestor mandou o
 * item para outro coletor enquanto este caminhava, nada é gravado e o motivo
 * volta como 'redistribuido'.
 *
 * Não mexe em `situacao`: 'C' é a marca da 1ª contagem feita. Marcar aqui faria
 * um item nunca contado na 1ª parecer contado com zero.
 *
 * @param array{id_inventario:int, empresa:int, revenda:int, id:int, coletor:int,
 *              dupla:string, quantidade:int, entrada:string, rodada?:int} $d
 *              `rodada`, se presente, precisa bater com a contagem atual da linha no banco:
 *              o gestor pode ter mandado o item para a contagem seguinte enquanto o coletor
 *              digitava, e essa gravação não pode cair na coluna errada.
 * @return array{ok:bool, motivo:?string}
 * @throws InvalidArgumentException se a quantidade for negativa ou a entrada inválida
 */
function recontagem_gravar(PDO $pdo, array $d): array
{
    $entrada = contagem2_exigir_entrada($d['entrada'] ?? null);

    $quantidade = $d['quantidade'] ?? null;
    if (!is_int($quantidade) || $quantidade < 0) {
        throw new InvalidArgumentException('Quantidade inválida: ' . var_export($quantidade, true));
    }

    $stmt = $pdo->prepare("
        SELECT rodada_item FROM estoque
         WHERE id_linha = ? AND id_inventario = ? AND empresa = ? AND revenda = ?
    ");
    $stmt->execute([(int)$d['id'], (int)$d['id_inventario'], (int)$d['empresa'], (int)$d['revenda']]);
    $rodada = $stmt->fetchColumn();

    if ($rodada === false || (int)$rodada < 2) {
        return ['ok' => false, 'motivo' => 'redistribuido'];
    }

    if (isset($d['rodada']) && (int)$d['rodada'] !== (int)$rodada) {
        return ['ok' => false, 'motivo' => 'redistribuido'];
    }

    $col = RECONTAGEM_COLUNAS[(int)$rodada] ?? null;
    if ($col === null || $col['em'] === null) {
        return ['ok' => false, 'motivo' => 'redistribuido'];
    }

    // Nomes de coluna vêm da constante, nunca do pedido.
    $upd = $pdo->prepare("
        UPDATE estoque
           SET {$col['valor']}   = :qtd,
               {$col['em']}      = NOW(),
               {$col['entrada']} = :entrada,
               {$col['dupla']}   = :dupla,
               coletor           = :coletor
         WHERE id_linha           = :id
           AND rodada_item        = :rod
           AND coletor_recontagem = :coletor2
           AND situacao <> 'D'
           AND recontagem_encerrada_em IS NULL
           AND {$col['em']} IS NULL
         LIMIT 1
    ");
    $upd->execute([
        ':qtd'      => $quantidade,
        ':entrada'  => $entrada,
        ':dupla'    => mb_substr((string)($d['dupla'] ?? ''), 0, 120),
        ':coletor'  => (int)$d['coletor'],
        ':id'       => (int)$d['id'],
        ':rod'      => (int)$rodada,
        ':coletor2' => (int)$d['coletor'],
    ]);

    return $upd->rowCount() > 0
        ? ['ok' => true, 'motivo' => null]
        : ['ok' => false, 'motivo' => 'redistribuido'];
}

/**
 * Em que contagem está o item desta locação, ou null se ele não existe no
 * inventário. É a trava da 1ª contagem e das sobras: elas só gravam na 1ª.
 */
function recontagem_rodada_do_item(PDO $pdo, int $inv, string $item, string $locacao): ?int
{
    $stmt = $pdo->prepare("
        SELECT rodada_item FROM estoque
         WHERE id_inventario = ? AND item_estoque_pub = ? AND locacao_geral = ?
         LIMIT 1
    ");
    $stmt->execute([$inv, $item, $locacao]);
    $rodada = $stmt->fetchColumn();

    return $rodada === false ? null : (int)$rodada;
}

/**
 * O item desta locação já foi encerrado pelo gestor (recontagem_encerrada_em
 * gravado)? Usada junto de recontagem_rodada_do_item, pela mesma trava da 1ª
 * contagem e das sobras: um item encerrado está em decisão e não recebe mais
 * gravação de porta nenhuma, mesmo que a rodada gravada ainda seja 1.
 */
function recontagem_item_encerrado(PDO $pdo, int $inv, string $item, string $locacao): bool
{
    $stmt = $pdo->prepare("
        SELECT recontagem_encerrada_em FROM estoque
         WHERE id_inventario = ? AND item_estoque_pub = ? AND locacao_geral = ?
         LIMIT 1
    ");
    $stmt->execute([$inv, $item, $locacao]);
    $em = $stmt->fetchColumn();

    return $em !== false && $em !== null;
}

/**
 * Encerra a recontagem dos itens marcados: param de ser recontados e vão para
 * Decisão com as contagens que já têm. Grava recontagem_encerrada_em (NOW()) e
 * recontagem_encerrada_por (o usuário, cortado em 120 caracteres).
 *
 * Só encerra quem ainda pode ser recontado (RECONTAGEM_MARCAVEIS: nao_contado,
 * aguardando, contando) — bateu, decisao e descartado são recusados, cada um
 * pelo seu motivo, como em recontagem_mandar.
 *
 * O UPDATE leva `AND recontagem_encerrada_em IS NULL`: dois cliques
 * simultâneos não gravam duas vezes (o segundo não afeta linha nenhuma e não
 * conta como encerrado de novo).
 *
 * O chamador precisa envolver a chamada numa transação (ver
 * recontagem_linhas_por_id) — recontagem_ajax.php faz isso.
 *
 * @param list<int> $ids
 * @return array{encerrados:int, recusados:array<string,int>}
 */
function recontagem_encerrar(PDO $pdo, int $inv, int $emp, int $rev, array $ids, string $usuario): array
{
    $usuario = mb_substr(trim($usuario), 0, 120);

    $recusados = ['bateu' => 0, 'decisao' => 0, 'descartado' => 0, 'nao_encontrado' => 0];
    $encerrados = 0;

    $pedidos = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    $linhas  = recontagem_linhas_por_id($pdo, $inv, $emp, $rev, $pedidos);

    $upd = $pdo->prepare("
        UPDATE estoque
           SET recontagem_encerrada_em  = NOW(),
               recontagem_encerrada_por = :usuario
         WHERE id_linha = :id AND recontagem_encerrada_em IS NULL
         LIMIT 1
    ");

    foreach ($pedidos as $id) {
        if (!isset($linhas[$id])) {
            $recusados['nao_encontrado']++;
            continue;
        }

        $situacao = recontagem_situacao($linhas[$id]);

        if (!in_array($situacao, RECONTAGEM_MARCAVEIS, true)) {
            // bateu, decisao ou descartado: já não é mais recontável.
            $recusados[$situacao]++;
            continue;
        }

        $upd->execute([':usuario' => $usuario, ':id' => $id]);

        if ($upd->rowCount() > 0) {
            $encerrados++;
        }
    }

    return ['encerrados' => $encerrados, 'recusados' => $recusados];
}
