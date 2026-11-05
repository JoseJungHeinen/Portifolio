<?php
declare(strict_types=1);

/**
 * contagem_2_lib.php
 *
 * As regras da 2ª contagem: quem é divergente, em que ordem a dupla caminha,
 * o que vale como bipe e o que a recontagem grava.
 *
 * Nada aqui lê $_SESSION, $_GET ou $_POST, e nada aqui imprime. Quem traduz
 * HTTP é o contagem_2_ajax.php. É o que torna a regra testável sem subir tela.
 */

/** Como o código chegou: pelo leitor, ou digitado depois do botão de ilegível. */
const CONTAGEM2_ENTRADAS = ['bipado', 'digitado'];

/**
 * Menor, Maior, não contado ou ok — comparando a 1ª contagem com o saldo do
 * sistema. São as mesmas duas faixas que o dashboard já mostra em "Itens com
 * quantidade a menor" e "Itens com sobra".
 */
function contagem2_classificar(?int $quantidade, ?int $cont1): string
{
    if ($cont1 === null) {
        return 'nao_contado';
    }

    $quantidade = (int)$quantidade;

    if ($cont1 < $quantidade) return 'menor';
    if ($cont1 > $quantidade) return 'maior';

    return 'ok';
}

/** Entra na fila da 2ª contagem. */
function contagem2_e_divergente(?int $quantidade, ?int $cont1): bool
{
    return contagem2_classificar($quantidade, $cont1) !== 'ok';
}

/**
 * Mesma limpeza que a contagem.php faz no campo de bipe: alguns leitores mandam
 * caracteres de controle antes ou depois do código.
 */
function contagem2_limpar_codigo($bruto): string
{
    $texto = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$bruto);

    return mb_strtoupper(trim((string)$texto));
}

/**
 * Zeros à esquerda só saem de código puramente numérico, como na
 * contagem.php: em código alfanumérico (0ABC) o zero faz parte do código.
 */
function contagem2_sem_zeros_esquerda(string $codigo): string
{
    if (preg_match('/^\d+$/', $codigo)) {
        return ltrim($codigo, '0') !== '' ? ltrim($codigo, '0') : '0';
    }

    return $codigo;
}

/**
 * O código sem nenhum zero à esquerda, numérico ou não — só para comparar,
 * nunca para gravar.
 *
 * Há leitor que completa o código com zeros na frente (000063806, 000127003OF).
 * contagem2_sem_zeros_esquerda() não resolve: ela não mexe em alfanumérico, e
 * em numérico tira todos os zeros, inclusive o que o cadastro tem (063806).
 * Comparando as duas pontas por esta chave, o zero a mais some dos dois lados.
 * Quem usa confere contra item que existe, e o código exato vem antes.
 */
function contagem2_chave_sem_zeros(string $codigo): string
{
    return ltrim($codigo, '0');
}

/**
 * Os códigos que um bipe pode estar representando, pela mesma regra de sufixo
 * que a contagem.php aplica no campo de item.
 *
 * A etiqueta traz o código do item com um sufixo de variante: `-01`, `-001`,
 * ou `001` sem traço. A 1ª contagem já cortava isso; a 2ª comparava o código
 * inteiro e recusava a peça certa.
 *
 * Devolve variantes em vez de um código só porque aqui se sabe qual item a
 * parada espera, e cortar às cegas erra em código que termina legitimamente em
 * `001` (MK5001, 60973001 existem no cadastro). O código inteiro continua
 * sendo candidato.
 *
 * Ponto ou espaço entre o código e o traço (`060921.-01`, `060921 -01`) também
 * sai: há itens cadastrados com ponto no fim, e o bipe deles trazia o ponto
 * colado no traço.
 *
 * @return list<string>
 */
function contagem2_variantes_codigo($bruto): array
{
    $inteiro = preg_replace('/\s+/u', '', contagem2_limpar_codigo($bruto));

    if ($inteiro === '') {
        return [];
    }

    $base = [$inteiro];

    $semTraco = preg_replace('/[-–—]0*01$/u', '', $inteiro);
    if ($semTraco !== $inteiro) {
        $base[] = $semTraco;
        $base[] = rtrim($semTraco, '.');
    }

    // Sufixo sem traço: `001`, `0001`... Não dá para saber onde o código
    // termina quando ele mesmo acaba em zero — no bipe 100175350001 o item é
    // 100175350 e o sufixo é 001, mas `0+01$` comia os três zeros e deixava
    // 10017535. Então cada corte possível vira candidato, do sufixo mais curto
    // (001) para o mais longo; quem decide é o item que existe.
    if (preg_match('/(0+)01$/', $inteiro, $m)) {
        for ($zeros = 1; $zeros <= strlen($m[1]); $zeros++) {
            $corte = substr($inteiro, 0, -($zeros + 2));
            if ($corte !== '') {
                $base[] = $corte;
            }
        }
    }

    $variantes = [];

    foreach ($base as $codigo) {
        if ($codigo === '') {
            continue;
        }

        // Com e sem zero à esquerda, nessa ordem: há itens cadastrados com o
        // zero (063806, 035595), e tirá-lo sempre fazia o item sumir.
        foreach ([$codigo, contagem2_sem_zeros_esquerda($codigo)] as $forma) {
            $variantes[] = $forma;

            // Regra do K da contagem.php: terminado em AA/AB/AC ganha K na
            // frente, exceto 1BP... e terminado em P. Em regex, e não com
            // str_starts_with: produção roda PHP 7, onde ela não existe.
            if (!preg_match('/^1BP/', $forma) && substr($forma, -1) !== 'P'
                && preg_match('/(AA|AB|AC)$/', $forma) && !preg_match('/^K/', $forma)) {
                $variantes[] = 'K' . $forma;
            }
        }
    }

    return array_values(array_unique($variantes));
}

/**
 * O bipe confere com o item que a fila mandou procurar.
 *
 * Comparar depois de limpar e subir a caixa, porque o leitor pode mandar o
 * código em minúscula ou com um \r no fim, e isso não é item diferente. O
 * sufixo de variante da etiqueta também não: ver contagem2_variantes_codigo.
 */
function contagem2_bipe_confere(string $esperado, $lido): bool
{
    $esperado = preg_replace('/\s+/u', '', contagem2_limpar_codigo($esperado));

    if ($esperado === '') {
        return false;
    }

    $variantes = contagem2_variantes_codigo($lido);

    if (in_array($esperado, $variantes, true)
        || in_array(contagem2_sem_zeros_esquerda($esperado), $variantes, true)) {
        return true;
    }

    // Zeros a mais na frente, que o leitor acrescenta (ver contagem2_chave_sem_zeros).
    $chave = contagem2_chave_sem_zeros($esperado);

    return $chave !== ''
        && in_array($chave, array_map('contagem2_chave_sem_zeros', $variantes), true);
}

/** @throws InvalidArgumentException se a entrada estiver ausente ou fora da lista. */
function contagem2_exigir_entrada($entrada): string
{
    $entrada = (string)($entrada ?? '');

    if (!in_array($entrada, CONTAGEM2_ENTRADAS, true)) {
        throw new InvalidArgumentException('Entrada inválida: ' . var_export($entrada, true));
    }

    return $entrada;
}

/**
 * A chave de ordenação de uma locação, na ordem de caminhada.
 *
 * Os campos numéricos entram como int e os de texto como string, para a
 * comparação seguir o corredor e não o alfabeto: na string, a rua 10 vem antes
 * da rua 2. É a mesma ordem do CONTAGEM2_ORDEM, só que em PHP.
 *
 * @return array{0:string,1:int,2:int,3:string,4:int}
 */
function contagem2_chave_ordem(string $zona, string $rua, string $estante, string $prateleira, string $numero): array
{
    return [
        strtoupper(trim($zona)),
        (int)$rua,
        (int)$estante,
        strtoupper(trim($prateleira)),
        (int)$numero,
    ];
}

/**
 * A chave de ordenação de um `locacao_geral` escrito como
 * `zona-rua-estante-prateleira-numero`, ou null se não tiver as cinco partes.
 *
 * O formato é o que o gerador_inventario.php monta ao criar a linha.
 */
function contagem2_chave_de_locacao(string $locacaoGeral): ?array
{
    $partes = explode('-', trim($locacaoGeral));

    if (count($partes) !== 5) {
        return null;
    }

    return contagem2_chave_ordem($partes[0], $partes[1], $partes[2], $partes[3], $partes[4]);
}

/**
 * Gira a fila para começar na locação que a dupla informou no login, sem perder
 * item: o que ficou para trás vai para o fim.
 *
 * Começa na primeira locação **igual ou posterior** à informada, na ordem de
 * caminhada. Exigir igualdade exata mandava a dupla em silêncio para o topo do
 * depósito sempre que o corredor escolhido já não tivesse divergência pendente
 * — e a fila muda enquanto ela anda, então isso acontece sozinho.
 *
 * Locação depois da última da fila, ou malformada, devolve a fila inteira: a
 * dupla começa do início, que é melhor do que não receber nada.
 */
function contagem2_rotacionar(array $fila, string $locacaoInicial): array
{
    $locacaoInicial = trim($locacaoInicial);

    if ($locacaoInicial === '' || $fila === []) {
        return $fila;
    }

    $alvo = contagem2_chave_de_locacao($locacaoInicial);

    if ($alvo === null) {
        return $fila;
    }

    foreach (array_values($fila) as $i => $linha) {
        $chave = contagem2_chave_ordem(
            (string)($linha['locacao_zona']       ?? ''),
            (string)($linha['locacao_rua']        ?? ''),
            (string)($linha['locacao_estante']    ?? ''),
            (string)($linha['locacao_prateleira'] ?? ''),
            (string)($linha['locacao_numero']     ?? '')
        );

        if ($chave >= $alvo) {
            return array_merge(array_slice($fila, $i), array_slice($fila, 0, $i));
        }
    }

    return $fila;
}

/**
 * O WHERE da 2ª contagem, em um lugar só.
 *
 * Só `cont_1 <> quantidade`, sem tratar nulo: `estoque.cont_1` é int(11) NOT
 * NULL e o gerador_inventario.php insere 0 junto com a linha, então "nunca
 * contado" no banco é zero, e com saldo cai em Menor — que é onde o dashboard
 * já mostra o item hoje. É a mesma faixa das duas tabelas do dashboard, de
 * propósito: os números do gestor e a fila da dupla têm que bater.
 *
 * cont2_em, e não cont_2, porque o gerador também insere cont_2 = 0: sem o
 * carimbo de data não dá para separar "ainda não recontei" de "recontei e não
 * tem nenhum".
 *
 * `situacao <> 'D'` tira as linhas descartadas na Tomada de Decisão. Sem isso a
 * fila manda a dupla até uma linha que alguém já decidiu descartar, e a
 * gravação — que escreve `situacao = 'C'` — apaga a decisão em silêncio.
 */
const CONTAGEM2_WHERE_DIVERGENTE_TODAS = "cont_1 <> quantidade AND situacao <> 'D'";

/**
 * Só as locações que o gestor escolheu ao encerrar a 1ª.
 *
 * Inventário sem nenhuma escolha gravada recebe todas: é o caso dos que já
 * estavam na 2ª antes da escolha existir, e da 1ª ainda aberta, em que o card
 * do dashboard mostra o total de divergentes. Os EXISTS se referem a
 * `estoque` pelo nome, então quem usa a constante não pode apelidar a tabela.
 */
const CONTAGEM2_WHERE_ESCOPO = "(
    NOT EXISTS (SELECT 1 FROM inv_cont2_locacoes esc
                 WHERE esc.id_inventario = estoque.id_inventario
                   AND esc.empresa       = estoque.empresa
                   AND esc.revenda       = estoque.revenda)
    OR EXISTS (SELECT 1 FROM inv_cont2_locacoes esc
                WHERE esc.id_inventario = estoque.id_inventario
                  AND esc.empresa       = estoque.empresa
                  AND esc.revenda       = estoque.revenda
                  AND esc.locacao_geral = estoque.locacao_geral)
)";

/**
 * Divergente e dentro das locações escolhidas, de qualquer coletor. É o que o
 * card do dashboard usa para o total geral.
 */
const CONTAGEM2_WHERE_DIVERGENTE = CONTAGEM2_WHERE_DIVERGENTE_TODAS . ' AND ' . CONTAGEM2_WHERE_ESCOPO;

/**
 * Divergente e dentro das locações que o gestor designou para este coletor.
 * Sem coletor, é o CONTAGEM2_WHERE_DIVERGENTE.
 *
 * Inventário sem escolha gravada continua levando tudo para qualquer coletor.
 * O número entra no SQL como literal porque já chega como int — não há texto
 * de usuário aqui, e poupa cada chamador de repassar mais um parâmetro.
 */
function contagem2_where_divergente(?int $coletor): string
{
    if ($coletor === null) {
        return CONTAGEM2_WHERE_DIVERGENTE;
    }

    return CONTAGEM2_WHERE_DIVERGENTE_TODAS . " AND (
        NOT EXISTS (SELECT 1 FROM inv_cont2_locacoes esc
                     WHERE esc.id_inventario = estoque.id_inventario
                       AND esc.empresa       = estoque.empresa
                       AND esc.revenda       = estoque.revenda)
        OR EXISTS (SELECT 1 FROM inv_cont2_locacoes esc
                    WHERE esc.id_inventario = estoque.id_inventario
                      AND esc.empresa       = estoque.empresa
                      AND esc.revenda       = estoque.revenda
                      AND esc.locacao_geral = estoque.locacao_geral
                      AND esc.coletor       = " . (int)$coletor . ")
    )";
}
const CONTAGEM2_WHERE_PENDENTE   = 'cont2_em IS NULL';

/**
 * A ordem em que a dupla caminha.
 *
 * Pelos campos decompostos, e não por locacao_geral: ordenar a string colocaria
 * a rua 10 antes da rua 2, e a dupla andaria o corredor de trás para frente.
 * O CAST é o que faz a ordem numérica valer mesmo se a coluna for texto — o
 * gerador copia esses campos do Apollo sem converter.
 */
const CONTAGEM2_ORDEM = '
    locacao_zona,
    CAST(locacao_rua       AS UNSIGNED),
    CAST(locacao_estante   AS UNSIGNED),
    locacao_prateleira,
    CAST(locacao_numero    AS UNSIGNED),
    item_estoque_pub
';

/**
 * Os itens que faltam recontar, na ordem de caminhada, com a etiqueta pronta.
 *
 * @return list<array{item_estoque_pub:string, descricao_item_estoque:string,
 *                    locacao_geral:string, quantidade:int, cont_1:?int, etiqueta:string}>
 */
function contagem2_fila(PDO $pdo, int $idInventario, int $empresa, int $revenda, ?int $coletor = null): array
{
    $sql = "
        SELECT item_estoque_pub, descricao_item_estoque, locacao_geral,
               locacao_zona, locacao_rua, locacao_estante,
               locacao_prateleira, locacao_numero,
               quantidade, cont_1
          FROM estoque
         WHERE id_inventario = :inv
           AND empresa       = :emp
           AND revenda       = :rev
           AND " . contagem2_where_divergente($coletor) . "
           AND " . CONTAGEM2_WHERE_PENDENTE . "
         ORDER BY " . CONTAGEM2_ORDEM;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':inv' => $idInventario, ':emp' => $empresa, ':rev' => $revenda]);

    $fila = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $quantidade = (int)$linha['quantidade'];
        $cont1      = $linha['cont_1'] === null ? null : (int)$linha['cont_1'];

        $fila[] = [
            'item_estoque_pub'       => (string)$linha['item_estoque_pub'],
            'descricao_item_estoque' => (string)$linha['descricao_item_estoque'],
            'locacao_geral'          => (string)$linha['locacao_geral'],
            // Os campos decompostos vão junto porque a rotação compara na ordem
            // de caminhada, e a string de locacao_geral não serve para isso.
            'locacao_zona'           => (string)$linha['locacao_zona'],
            'locacao_rua'            => (string)$linha['locacao_rua'],
            'locacao_estante'        => (string)$linha['locacao_estante'],
            'locacao_prateleira'     => (string)$linha['locacao_prateleira'],
            'locacao_numero'         => (string)$linha['locacao_numero'],
            'quantidade'             => $quantidade,
            'cont_1'                 => $cont1,
            'etiqueta'               => contagem2_classificar($quantidade, $cont1),
        ];
    }

    return $fila;
}

/**
 * Os números do header do coletor e do card do dashboard.
 *
 * 'divergentes' conta os recontados também: é o denominador do "7 de 19", e ele
 * não pode encolher enquanto a dupla trabalha.
 *
 * 'finalizada' é tudo recontado, e só quando havia o que recontar: zero de zero
 * não é contagem terminada, é contagem que não tinha nada. 'ultima_em' é a
 * última recontagem gravada — com a contagem finalizada, é quando ela acabou.
 *
 * @return array{divergentes:int, recontados:int, menor:int, maior:int,
 *               finalizada:bool, ultima_em:?string}
 */
function contagem2_totais(PDO $pdo, int $idInventario, int $empresa, int $revenda, ?int $coletor = null): array
{
    $sql = "
        SELECT
            COUNT(*)                                                 AS divergentes,
            SUM(CASE WHEN cont2_em IS NOT NULL  THEN 1 ELSE 0 END)   AS recontados,
            SUM(CASE WHEN cont_1 <  quantidade  THEN 1 ELSE 0 END)   AS menor,
            SUM(CASE WHEN cont_1 >  quantidade  THEN 1 ELSE 0 END)   AS maior,
            MAX(cont2_em)                                            AS ultima_em
          FROM estoque
         WHERE id_inventario = :inv
           AND empresa       = :emp
           AND revenda       = :rev
           AND " . contagem2_where_divergente($coletor);

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':inv' => $idInventario, ':emp' => $empresa, ':rev' => $revenda]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $divergentes = (int)($linha['divergentes'] ?? 0);
    $recontados  = (int)($linha['recontados']  ?? 0);

    return [
        'divergentes' => $divergentes,
        'recontados'  => $recontados,
        'menor'       => (int)($linha['menor']       ?? 0),
        'maior'       => (int)($linha['maior']       ?? 0),
        'finalizada'  => $divergentes > 0 && $recontados === $divergentes,
        'ultima_em'   => isset($linha['ultima_em']) ? (string)$linha['ultima_em'] : null,
    ];
}

/**
 * Grava a recontagem de um item.
 *
 * Sobrescreve cont_2, não soma. O contagem_gravar.php soma porque na 1ª
 * contagem a mesma prateleira é bipada várias vezes; aqui a fila manda a dupla
 * a uma parada por item, e somar transformaria uma correção em dobro.
 *
 * A chave da escrita é item + locação, nunca o item sozinho: o mesmo código pode
 * estar em duas locações do mesmo inventário, e a fila manda a dupla a cada uma
 * delas como uma parada própria. Sem a locação no WHERE, o LIMIT 1 grava numa
 * linha arbitrária — a dupla conta um corredor e o número vai parar em outro,
 * enquanto a linha certa volta pra fila. É o mesmo escopo que o
 * contagem_gravar.php usa desde sempre.
 *
 * @param array{id_inventario:int, empresa:int, revenda:int, item_estoque_pub:string,
 *              locacao_geral:string, quantidade:int, coletor:int, dupla_label:string,
 *              entrada:string} $d
 * @return array{ok:bool, bateu:bool, motivo:?string, recontado_antes_por:?string,
 *                quantidade_sistema:?int}
 * @throws InvalidArgumentException se a quantidade for negativa, a entrada
 *         inválida, ou a locação vier vazia.
 */
function contagem2_gravar(PDO $pdo, array $d): array
{
    $entrada = contagem2_exigir_entrada($d['entrada'] ?? null);

    $quantidade = $d['quantidade'] ?? null;

    if (!is_int($quantidade) || $quantidade < 0) {
        throw new InvalidArgumentException('Quantidade inválida: ' . var_export($quantidade, true));
    }

    $idInventario = (int)$d['id_inventario'];
    $empresa      = (int)$d['empresa'];
    $revenda      = (int)$d['revenda'];
    $item         = contagem2_limpar_codigo($d['item_estoque_pub'] ?? '');
    $locacao      = trim((string)($d['locacao_geral'] ?? ''));

    // Locação vazia é erro de quem chamou, não entrada de usuário: quem chama
    // tira a locação da própria fila. Estourar aqui impede que a ausência dela
    // degrade silenciosamente para "grava em qualquer linha com esse código".
    if ($locacao === '') {
        throw new InvalidArgumentException('Locação obrigatória para gravar a 2ª contagem.');
    }

    $vazio = [
        'ok' => false, 'bateu' => false, 'motivo' => null,
        'recontado_antes_por' => null, 'quantidade_sistema' => null,
    ];

    // Lê antes de gravar: é daqui que sai o "bateu" e o aviso de que outra dupla
    // já tinha passado por este item.
    $stmt = $pdo->prepare("
        SELECT quantidade, cont2_em, dupla_cont2
          FROM estoque
         WHERE id_inventario    = :inv
           AND empresa          = :emp
           AND revenda          = :rev
           AND item_estoque_pub = :item
           AND locacao_geral    = :loc
         LIMIT 1
    ");
    $stmt->execute([
        ':inv'  => $idInventario,
        ':emp'  => $empresa,
        ':rev'  => $revenda,
        ':item' => $item,
        ':loc'  => $locacao,
    ]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$linha) {
        return array_merge($vazio, ['motivo' => 'item_nao_encontrado']);
    }

    $quantidadeSistema = (int)$linha['quantidade'];

    // Outra dupla pode ter recontado entre o 'proximo' e o 'gravar'. A marca mais
    // recente vale, mas a tela avisa — sumir com o fato esconderia um retrabalho.
    $antesPor = $linha['cont2_em'] !== null ? (string)($linha['dupla_cont2'] ?? '') : null;

    $upd = $pdo->prepare("
        UPDATE estoque
           SET cont_2        = :qtd,
               cont2_em      = NOW(),
               cont2_entrada = :entrada,
               dupla_cont2   = :dupla,
               coletor       = :coletor,
               -- 'C' marca a linha como tocada, igual ao contagem_gravar.php.
               -- Mas nunca por cima de um 'D': descarte é decisão de gestão, e
               -- uma recontagem não tem autoridade para desfazê-la.
               situacao      = CASE WHEN situacao = 'D' THEN situacao ELSE 'C' END
         WHERE id_inventario    = :inv
           AND empresa          = :emp
           AND revenda          = :rev
           AND item_estoque_pub = :item
           AND locacao_geral    = :loc
         LIMIT 1
    ");
    $upd->execute([
        ':loc'     => $locacao,
        ':qtd'     => $quantidade,
        ':entrada' => $entrada,
        ':dupla'   => (string)($d['dupla_label'] ?? ''),
        ':coletor' => (int)($d['coletor'] ?? 0),
        ':inv'     => $idInventario,
        ':emp'     => $empresa,
        ':rev'     => $revenda,
        ':item'    => $item,
    ]);

    return [
        'ok'                  => true,
        'bateu'               => $quantidade === $quantidadeSistema,
        'motivo'              => null,
        'recontado_antes_por' => $antesPor,
        'quantidade_sistema'  => $quantidadeSistema,
    ];
}

/** O inventário ainda aceita recontagem. */
function contagem2_inventario_aberto(PDO $pdo, int $idInventario, int $empresa, int $revenda): bool
{
    $stmt = $pdo->prepare("
        SELECT 1 FROM inv_inventarios
         WHERE id = :inv AND empresa = :emp AND revenda = :rev AND status = 'aberto'
         LIMIT 1
    ");
    $stmt->execute([':inv' => $idInventario, ':emp' => $empresa, ':rev' => $revenda]);

    return (bool)$stmt->fetchColumn();
}

/**
 * A linha (item, locação) que outra dupla já recontou.
 *
 * É o caso da tabela de Erros do spec: a dupla andou até a prateleira e contou,
 * e enquanto digitava a quantidade outra dupla gravou o mesmo item. A linha saiu
 * da fila, então a revalidação da parada não a reconhece mais — e recusar ali
 * jogaria fora a contagem de quem fez o trabalho.
 *
 * Devolve a linha para o chamador deixar passar, ou null quando não é esse caso.
 * Só abre exceção para linha JÁ RECONTADA: item pendente que não é a parada
 * continua recusado, senão a revalidação deixaria de valer para qualquer
 * divergente do inventário.
 *
 * @return array{locacao_geral:string, dupla_cont2:?string}|null
 */
function contagem2_recontada_por_outra(
    PDO $pdo,
    int $idInventario,
    int $empresa,
    int $revenda,
    string $item,
    string $locacao,
    ?int $coletor = null
): ?array {
    $item    = contagem2_limpar_codigo($item);
    $locacao = trim($locacao);

    if ($item === '' || $locacao === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT locacao_geral, dupla_cont2
          FROM estoque
         WHERE id_inventario    = :inv
           AND empresa          = :emp
           AND revenda          = :rev
           AND item_estoque_pub = :item
           AND locacao_geral    = :loc
           AND " . contagem2_where_divergente($coletor) . "
           AND cont2_em IS NOT NULL
         LIMIT 1
    ");
    $stmt->execute([
        ':inv'  => $idInventario,
        ':emp'  => $empresa,
        ':rev'  => $revenda,
        ':item' => $item,
        ':loc'  => $locacao,
    ]);

    $linha = $stmt->fetch(PDO::FETCH_ASSOC);

    return $linha ?: null;
}

/**
 * As locações que ainda têm item para recontar, na ordem de caminhada.
 *
 * É o que o login oferece no campo "Locação da Dupla", que passou a significar
 * onde a fila começa. Sai da mesma definição de fila que o coletor recebe —
 * antes esta lista rodava a regra da tela antiga (`cont_2 < cont_1`), que nunca
 * casa com item nunca contado e por isso escondia justamente o que mais aparece
 * depois de uma 1ª contagem parcial.
 *
 * DISTINCT por GROUP BY, e os campos de ordenação dentro de MIN(), porque
 * ordenar por coluna que não está no SELECT quebra sob ONLY_FULL_GROUP_BY.
 *
 * @return list<string>
 */
function contagem2_locacoes_pendentes(PDO $pdo, int $idInventario, int $empresa, int $revenda, ?int $coletor = null): array
{
    $sql = "
        SELECT locacao_geral
          FROM estoque
         WHERE id_inventario = :inv
           AND empresa       = :emp
           AND revenda       = :rev
           AND " . contagem2_where_divergente($coletor) . "
           AND " . CONTAGEM2_WHERE_PENDENTE . "
         GROUP BY locacao_geral
         ORDER BY MIN(locacao_zona),
                  MIN(CAST(locacao_rua     AS UNSIGNED)),
                  MIN(CAST(locacao_estante AS UNSIGNED)),
                  MIN(locacao_prateleira),
                  MIN(CAST(locacao_numero  AS UNSIGNED))
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':inv' => $idInventario, ':emp' => $empresa, ':rev' => $revenda]);

    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * A última rodada de contagem que o sistema sabe conduzir.
 *
 * Enquanto não existirem as colunas `cont_3` a `cont_5`, encerrar a 2ª não
 * levaria a lugar nenhum: a 3ª não teria onde gravar. O teto está aqui, em um
 * lugar só, para a generalização ser uma mudança de número.
 */
const CONTAGEM2_ULTIMA_RODADA = 2;

/**
 * A rodada de contagem aberta neste inventário.
 *
 * Inventário que não existe devolve 1, e não 0 ou erro: 1 é o estado seguro —
 * nenhuma gravação de recontagem passa por ele, e quem chama não precisa tratar
 * um caso que só acontece com parâmetro errado.
 */
function contagem2_rodada_atual(PDO $pdo, int $idInventario, int $empresa, int $revenda): int
{
    $stmt = $pdo->prepare("
        SELECT rodada_atual
          FROM inv_inventarios
         WHERE id = :inv AND empresa = :emp AND revenda = :rev
         LIMIT 1
    ");
    $stmt->execute([':inv' => $idInventario, ':emp' => $empresa, ':rev' => $revenda]);

    $rodada = $stmt->fetchColumn();

    return $rodada === false ? 1 : max(1, (int)$rodada);
}

/**
 * Quando e por quem a rodada anterior foi encerrada, ou null se nenhuma foi.
 *
 * @return array{em:string, por:string}|null
 */
function contagem2_encerramento(PDO $pdo, int $idInventario, int $empresa, int $revenda): ?array
{
    $stmt = $pdo->prepare("
        SELECT rodada_encerrada_em, rodada_encerrada_por
          FROM inv_inventarios
         WHERE id = :inv AND empresa = :emp AND revenda = :rev
         LIMIT 1
    ");
    $stmt->execute([':inv' => $idInventario, ':emp' => $empresa, ':rev' => $revenda]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$linha || $linha['rodada_encerrada_em'] === null) {
        return null;
    }

    return [
        'em'  => (string)$linha['rodada_encerrada_em'],
        'por' => (string)($linha['rodada_encerrada_por'] ?? ''),
    ];
}

/**
 * Encerra a rodada $rodada e abre a seguinte.
 *
 * Devolve false quando o inventário não existe, está encerrado, já passou dessa
 * rodada, ou quando a rodada seguinte não teria onde gravar.
 *
 * O `rodada_atual = :rodada` no WHERE é o que faz o duplo clique ser inofensivo:
 * o segundo não encontra linha, não empurra para a rodada 3, e não apaga o nome
 * de quem encerrou primeiro.
 */
function contagem2_encerrar_rodada(
    PDO $pdo,
    int $idInventario,
    int $empresa,
    int $revenda,
    int $rodada,
    string $usuario
): bool {
    if ($rodada < 1 || $rodada >= CONTAGEM2_ULTIMA_RODADA) {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE inv_inventarios
           SET rodada_atual         = :proxima,
               rodada_encerrada_em  = NOW(),
               rodada_encerrada_por = :usuario
         WHERE id           = :inv
           AND empresa      = :emp
           AND revenda      = :rev
           AND status       = 'aberto'
           AND rodada_atual = :rodada
         LIMIT 1
    ");
    $stmt->execute([
        ':proxima' => $rodada + 1,
        ':usuario' => mb_substr(trim($usuario), 0, 120),
        ':inv'     => $idInventario,
        ':emp'     => $empresa,
        ':rev'     => $revenda,
        ':rodada'  => $rodada,
    ]);

    return $stmt->rowCount() > 0;
}

/**
 * Esta rodada de contagem pode gravar neste inventário.
 *
 * A Conferência (3) não é rodada de contagem e passa sempre — ela não escreve
 * em `cont_1` nem em `cont_2`.
 *
 * Rodada corrente que o gestor encerrou (a última, que não abre outra) não
 * grava mais.
 */
function contagem2_pode_gravar(PDO $pdo, int $idInventario, int $empresa, int $revenda, int $rodada): bool
{
    if ($rodada === 3) {
        return true;
    }

    return $rodada === contagem2_rodada_atual($pdo, $idInventario, $empresa, $revenda)
        && contagem2_fechamento($pdo, $idInventario, $empresa, $revenda) === null;
}

/**
 * Quando e por quem a rodada corrente foi encerrada sem abrir outra, ou null
 * se ela está aberta.
 *
 * É diferente do contagem2_encerramento: aquele é a passagem da 1ª para a 2ª;
 * este é o fim da última rodada que o sistema conduz.
 *
 * @return array{em:string, por:string}|null
 */
function contagem2_fechamento(PDO $pdo, int $idInventario, int $empresa, int $revenda): ?array
{
    $stmt = $pdo->prepare("
        SELECT rodada_fechada_em, rodada_fechada_por
          FROM inv_inventarios
         WHERE id = :inv AND empresa = :emp AND revenda = :rev
         LIMIT 1
    ");
    $stmt->execute([':inv' => $idInventario, ':emp' => $empresa, ':rev' => $revenda]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$linha || $linha['rodada_fechada_em'] === null) {
        return null;
    }

    return [
        'em'  => (string)$linha['rodada_fechada_em'],
        'por' => (string)($linha['rodada_fechada_por'] ?? ''),
    ];
}

/**
 * Encerra a última rodada (hoje, a 2ª) sem abrir outra.
 *
 * Devolve false quando a rodada não é a corrente, não é a última, o inventário
 * está fechado, ou ela já foi encerrada — o `rodada_fechada_em IS NULL` no
 * WHERE faz o duplo clique não apagar o nome de quem encerrou primeiro.
 */
function contagem2_fechar_ultima_rodada(
    PDO $pdo,
    int $idInventario,
    int $empresa,
    int $revenda,
    int $rodada,
    string $usuario
): bool {
    if ($rodada !== CONTAGEM2_ULTIMA_RODADA) {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE inv_inventarios
           SET rodada_fechada_em  = NOW(),
               rodada_fechada_por = :usuario
         WHERE id                = :inv
           AND empresa           = :emp
           AND revenda           = :rev
           AND status            = 'aberto'
           AND rodada_atual      = :rodada
           AND rodada_fechada_em IS NULL
         LIMIT 1
    ");
    $stmt->execute([
        ':usuario' => mb_substr(trim($usuario), 0, 120),
        ':inv'     => $idInventario,
        ':emp'     => $empresa,
        ':rev'     => $revenda,
        ':rodada'  => $rodada,
    ]);

    return $stmt->rowCount() > 0;
}

/**
 * O coletor avisa que terminou a parte dele nesta rodada — pelo botão
 * "Terminei", ou sozinho quando a fila acaba. Guarda quantos itens ainda
 * faltavam, para o gestor ver se terminou mesmo ou se desistiu do resto.
 * Avisar de novo sobrescreve.
 */
function contagem2_registrar_terminou(
    PDO $pdo,
    int $idInventario,
    int $empresa,
    int $revenda,
    int $rodada,
    int $coletor,
    string $dupla,
    int $pendentes
): void {
    $pdo->prepare("
        REPLACE INTO inv_coletor_terminou
            (id_inventario, empresa, revenda, rodada, coletor, dupla, pendentes, terminou_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $idInventario, $empresa, $revenda, $rodada, $coletor,
        mb_substr($dupla, 0, 120), max(0, $pendentes),
    ]);
}

/**
 * O coletor voltou a gravar depois de avisar que terminou: o aviso não vale
 * mais.
 */
function contagem2_desfazer_terminou(PDO $pdo, int $idInventario, int $empresa, int $revenda, int $rodada, int $coletor): void
{
    $pdo->prepare("
        DELETE FROM inv_coletor_terminou
         WHERE id_inventario = ? AND empresa = ? AND revenda = ? AND rodada = ? AND coletor = ?
    ")->execute([$idInventario, $empresa, $revenda, $rodada, $coletor]);
}

/**
 * Quem avisou que terminou nesta rodada, por número de coletor.
 *
 * @return array<int, array{dupla:string, pendentes:int, terminou_em:string}>
 */
function contagem2_terminaram(PDO $pdo, int $idInventario, int $empresa, int $revenda, int $rodada): array
{
    $stmt = $pdo->prepare("
        SELECT coletor, dupla, pendentes, terminou_em
          FROM inv_coletor_terminou
         WHERE id_inventario = ? AND empresa = ? AND revenda = ? AND rodada = ?
         ORDER BY coletor
    ");
    $stmt->execute([$idInventario, $empresa, $revenda, $rodada]);

    $terminaram = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $terminaram[(int)$linha['coletor']] = [
            'dupla'       => (string)($linha['dupla'] ?? ''),
            'pendentes'   => (int)$linha['pendentes'],
            'terminou_em' => (string)$linha['terminou_em'],
        ];
    }

    return $terminaram;
}

/**
 * Quanto da 1ª contagem já foi percorrido.
 *
 * `situacao` é a marca: o gerador_inventario.php cria toda linha como 'A' e o
 * contagem_gravar.php grava 'C' em cada item registrado. Não dá para usar
 * `cont_1 > 0`, porque item contado e não encontrado fica em zero.
 *
 * É o número que falta ao supervisor na hora de encerrar a 1ª: a quantidade de
 * itens a recontar, sozinha, não diz se a contagem acabou ou se mal começou.
 *
 * @return array{total:int, registrados:int, nunca_tocados:int, pct:float}
 */
function contagem2_progresso_primeira(PDO $pdo, int $idInventario, int $empresa, int $revenda): array
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)                                      AS total,
               SUM(CASE WHEN situacao = 'C' THEN 1 ELSE 0 END) AS registrados,
               SUM(CASE WHEN situacao = 'A' THEN 1 ELSE 0 END) AS nunca_tocados
          FROM estoque
         WHERE id_inventario = :inv AND empresa = :emp AND revenda = :rev
    ");
    $stmt->execute([':inv' => $idInventario, ':emp' => $empresa, ':rev' => $revenda]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total       = (int)($linha['total']       ?? 0);
    $registrados = (int)($linha['registrados'] ?? 0);

    return [
        'total'         => $total,
        'registrados'   => $registrados,
        'nunca_tocados' => (int)($linha['nunca_tocados'] ?? 0),
        'pct'           => $total > 0 ? round(100 * $registrados / $total, 1) : 0.0,
    ];
}

/**
 * Todas as locações com item divergente, com a contagem de cada uma, na ordem
 * de caminhada. É a lista que o gestor marca ao encerrar a 1ª.
 *
 * Ignora a escolha já gravada (usa a regra sem escopo): é a lista de onde a
 * escolha sai, não o resultado dela.
 *
 * @return list<array{locacao_geral:string, zona:string, rua:string, itens:int, menor:int, maior:int}>
 */
function contagem2_locacoes_divergentes(PDO $pdo, int $idInventario, int $empresa, int $revenda): array
{
    $sql = "
        SELECT locacao_geral,
               MIN(locacao_zona)                                     AS zona,
               MIN(locacao_rua)                                      AS rua,
               COUNT(*)                                              AS itens,
               SUM(CASE WHEN cont_1 < quantidade THEN 1 ELSE 0 END)  AS menor,
               SUM(CASE WHEN cont_1 > quantidade THEN 1 ELSE 0 END)  AS maior
          FROM estoque
         WHERE id_inventario = :inv
           AND empresa       = :emp
           AND revenda       = :rev
           AND " . CONTAGEM2_WHERE_DIVERGENTE_TODAS . "
         GROUP BY locacao_geral
         ORDER BY MIN(locacao_zona),
                  MIN(CAST(locacao_rua     AS UNSIGNED)),
                  MIN(CAST(locacao_estante AS UNSIGNED)),
                  MIN(locacao_prateleira),
                  MIN(CAST(locacao_numero  AS UNSIGNED))
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':inv' => $idInventario, ':emp' => $empresa, ':rev' => $revenda]);

    return array_map(static fn(array $l): array => [
        'locacao_geral' => (string)$l['locacao_geral'],
        'zona'          => (string)$l['zona'],
        'rua'           => (string)$l['rua'],
        'itens'         => (int)$l['itens'],
        'menor'         => (int)$l['menor'],
        'maior'         => (int)$l['maior'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * As locações que o gestor escolheu para a 2ª, com o coletor de cada uma, na
 * ordem de locacao_geral. Lista vazia quer dizer que nenhuma escolha foi
 * gravada — e aí a fila leva todas, para qualquer coletor.
 *
 * @return list<array{locacao_geral:string, coletor:int}>
 */
function contagem2_locacoes_escolhidas(PDO $pdo, int $idInventario, int $empresa, int $revenda): array
{
    $stmt = $pdo->prepare("
        SELECT locacao_geral, coletor FROM inv_cont2_locacoes
         WHERE id_inventario = :inv AND empresa = :emp AND revenda = :rev
         ORDER BY coletor, locacao_geral
    ");
    $stmt->execute([':inv' => $idInventario, ':emp' => $empresa, ':rev' => $revenda]);

    return array_map(static fn(array $l): array => [
        'locacao_geral' => (string)$l['locacao_geral'],
        'coletor'       => (int)$l['coletor'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** O login aceita coletores de 1 a 8. */
const CONTAGEM2_MAX_COLETORES = 8;

/**
 * Os coletores do inventário, número => rótulo, como o login os mostra.
 *
 * Sai do coletores_json que o gerador grava. Inventário sem esse cadastro
 * oferece os oito números, que é o que o login também oferece nesse caso.
 *
 * @return array<int,string>
 */
function contagem2_coletores(PDO $pdo, int $idInventario, int $empresa, int $revenda): array
{
    $stmt = $pdo->prepare("
        SELECT coletores_json FROM inv_inventarios
         WHERE id = :inv AND empresa = :emp AND revenda = :rev
         LIMIT 1
    ");
    $stmt->execute([':inv' => $idInventario, ':emp' => $empresa, ':rev' => $revenda]);
    $grupos = json_decode((string)$stmt->fetchColumn(), true);

    $coletores = [];

    for ($num = 1; $num <= CONTAGEM2_MAX_COLETORES; $num++) {
        $nomes = is_array($grupos) ? ($grupos[$num] ?? $grupos[(string)$num] ?? []) : [];
        $nomes = array_filter(array_map('strval', (array)$nomes), static fn($n) => trim($n) !== '');

        if (is_array($grupos) && $grupos !== [] && $nomes === []) {
            continue;
        }

        $coletores[$num] = 'Coletor ' . $num . ($nomes !== [] ? ' - ' . implode(' e ', $nomes) : '');
    }

    return $coletores;
}

/**
 * Grava as locações da 2ª e o coletor de cada uma, substituindo a escolha
 * anterior.
 *
 * Só entra locação que tem item divergente neste inventário, com coletor de 1
 * a 8: o POST vem do navegador, e uma locação inventada deixaria a fila vazia
 * sem ninguém entender por quê. Devolve quantas foram gravadas — zero quer
 * dizer que nada válido veio, e quem chama deve desfazer.
 *
 * Não abre transação: quem chama junta isto com o encerramento da rodada numa
 * só.
 *
 * @param array<string,int> $locacoes locacao_geral => número do coletor
 */
function contagem2_definir_locacoes(PDO $pdo, int $idInventario, int $empresa, int $revenda, array $locacoes): int
{
    $validas = array_flip(array_column(
        contagem2_locacoes_divergentes($pdo, $idInventario, $empresa, $revenda),
        'locacao_geral'
    ));

    $escolha = [];

    foreach ($locacoes as $locacao => $coletor) {
        $locacao = trim((string)$locacao);
        $coletor = filter_var($coletor, FILTER_VALIDATE_INT);

        if (isset($validas[$locacao]) && $coletor !== false
            && $coletor >= 1 && $coletor <= CONTAGEM2_MAX_COLETORES) {
            $escolha[$locacao] = $coletor;
        }
    }

    $pdo->prepare("DELETE FROM inv_cont2_locacoes WHERE id_inventario = ? AND empresa = ? AND revenda = ?")
        ->execute([$idInventario, $empresa, $revenda]);

    $ins = $pdo->prepare("
        INSERT INTO inv_cont2_locacoes (id_inventario, empresa, revenda, locacao_geral, coletor)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($escolha as $locacao => $coletor) {
        $ins->execute([$idInventario, $empresa, $revenda, (string)$locacao, $coletor]);
    }

    return count($escolha);
}

/**
 * O coletor tem locação designada na 2ª — ou o inventário não tem escolha
 * gravada, e aí todos recebem tudo.
 */
function contagem2_coletor_tem_locacao(PDO $pdo, int $idInventario, int $empresa, int $revenda, int $coletor): bool
{
    $escolhas = contagem2_locacoes_escolhidas($pdo, $idInventario, $empresa, $revenda);

    return $escolhas === [] || in_array($coletor, array_column($escolhas, 'coletor'), true);
}

/**
 * O código de item que um bipe representa neste inventário, para a 1ª
 * contagem e as sobras — onde, ao contrário da 2ª, não se sabe de antemão qual
 * item a dupla tem na mão.
 *
 * A tela cortava o sufixo às cegas: `0+01$` transformava MK5001 em MK5 e
 * 60973001 em 60973, que não existem, e a peça certa era recusada. Aqui as
 * variantes (contagem2_variantes_codigo) são conferidas contra o que existe:
 *
 *   1. a primeira variante que está NESTA locação;
 *   2. senão, a primeira que existe em qualquer locação do inventário
 *      (para a mensagem "pertence à locação X" continuar certa);
 *   3. senão, o código inteiro limpo.
 *
 * A ordem das variantes põe o código inteiro na frente, então um código que
 * existe como está nunca é trocado por uma versão cortada.
 */
function contagem2_resolver_codigo(PDO $pdo, int $idInventario, $bruto, string $locacao = ''): string
{
    $variantes = contagem2_variantes_codigo($bruto);

    if ($variantes === []) {
        return '';
    }

    $marcas = implode(',', array_fill(0, count($variantes), '?'));

    $stmt = $pdo->prepare("
        SELECT item_estoque_pub, MAX(locacao_geral = ?) AS aqui
          FROM estoque
         WHERE id_inventario = ?
           AND item_estoque_pub IN ($marcas)
         GROUP BY item_estoque_pub
    ");
    $stmt->execute(array_merge([trim($locacao), $idInventario], $variantes));

    $existe = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $existe[mb_strtoupper((string)$linha['item_estoque_pub'])] = [
            'codigo' => (string)$linha['item_estoque_pub'],
            'aqui'   => (int)$linha['aqui'] === 1,
        ];
    }

    foreach ($variantes as $v) {
        if (($existe[$v]['aqui'] ?? false) === true) {
            return $existe[$v]['codigo'];
        }
    }

    foreach ($variantes as $v) {
        if (isset($existe[$v])) {
            return $existe[$v]['codigo'];
        }
    }

    // Nenhuma variante existe como está: o leitor pode ter completado o código
    // com zeros na frente. Compara sem zero à esquerda dos dois lados, com a
    // mesma preferência — primeiro esta locação, depois qualquer uma. Fica por
    // último porque o TRIM na coluna varre o inventário, sem índice.
    $chaves = array_values(array_unique(array_filter(
        array_map('contagem2_chave_sem_zeros', $variantes),
        function ($c) { return $c !== ''; }
    )));

    if ($chaves !== []) {
        $marcas = implode(',', array_fill(0, count($chaves), '?'));

        $stmt = $pdo->prepare("
            SELECT item_estoque_pub, MAX(locacao_geral = ?) AS aqui
              FROM estoque
             WHERE id_inventario = ?
               AND TRIM(LEADING '0' FROM item_estoque_pub) IN ($marcas)
             GROUP BY item_estoque_pub
        ");
        $stmt->execute(array_merge([trim($locacao), $idInventario], $chaves));

        $porChave = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $codigo = (string)$linha['item_estoque_pub'];
            $chave  = contagem2_chave_sem_zeros(mb_strtoupper($codigo));
            // Item desta locação na frente, se dois itens derem a mesma chave.
            if (!isset($porChave[$chave]) || ((int)$linha['aqui'] === 1 && !$porChave[$chave]['aqui'])) {
                $porChave[$chave] = ['codigo' => $codigo, 'aqui' => (int)$linha['aqui'] === 1];
            }
        }

        foreach ($chaves as $c) {
            if (($porChave[$c]['aqui'] ?? false) === true) {
                return $porChave[$c]['codigo'];
            }
        }

        foreach ($chaves as $c) {
            if (isset($porChave[$c])) {
                return $porChave[$c]['codigo'];
            }
        }
    }

    return $variantes[0];
}

/**
 * Quantas locações ainda têm item a recontar e nenhum coletor designado.
 *
 * Locação sem nada pendente não conta: não há mais o que mandar alguém fazer
 * lá, então não há o que distribuir.
 */
function contagem2_locacoes_sem_coletor(PDO $pdo, int $idInventario, int $empresa, int $revenda): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT locacao_geral)
          FROM estoque
         WHERE id_inventario = :inv
           AND empresa       = :emp
           AND revenda       = :rev
           AND " . CONTAGEM2_WHERE_DIVERGENTE_TODAS . "
           AND " . CONTAGEM2_WHERE_PENDENTE . "
           AND NOT EXISTS (SELECT 1 FROM inv_cont2_locacoes esc
                            WHERE esc.id_inventario = estoque.id_inventario
                              AND esc.empresa       = estoque.empresa
                              AND esc.revenda       = estoque.revenda
                              AND esc.locacao_geral = estoque.locacao_geral)
    ");
    $stmt->execute([':inv' => $idInventario, ':emp' => $empresa, ':rev' => $revenda]);

    return (int)$stmt->fetchColumn();
}

/**
 * A divisão das locações da contagem em andamento está fechada: já existe uma
 * divisão gravada e não sobrou locação pendente sem coletor.
 *
 * A divisão se faz no começo de cada contagem. Com tudo distribuído, o gestor
 * não precisa mais mexer, e a tela esconde a divisão até a próxima contagem.
 * Só vale da 2ª em diante — na 1ª a divisão vai junto com o encerramento.
 */
function contagem2_designacao_fechada(PDO $pdo, int $idInventario, int $empresa, int $revenda): bool
{
    if (contagem2_rodada_atual($pdo, $idInventario, $empresa, $revenda) < 2) {
        return false;
    }

    // Contagem encerrada: não há mais o que distribuir.
    if (contagem2_fechamento($pdo, $idInventario, $empresa, $revenda) !== null) {
        return true;
    }

    if (contagem2_locacoes_escolhidas($pdo, $idInventario, $empresa, $revenda) === []) {
        return false;
    }

    return contagem2_locacoes_sem_coletor($pdo, $idInventario, $empresa, $revenda) === 0;
}
