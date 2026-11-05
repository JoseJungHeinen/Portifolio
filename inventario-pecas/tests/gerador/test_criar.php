<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'gerador_lib.php';
require __DIR__ . '/../contagem2/assert.php';

echo "test_criar\n";

// Empresa/revenda fictícios, fora da faixa real, só para os testes de criação.
const GC_EMPRESA = 999;
const GC_REVENDA = 999;

function gc_limpar_inventario(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM estoque WHERE id_inventario = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM inv_coletores WHERE id_inventario = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM inv_inventarios WHERE id = ?")->execute([$id]);
}

/** Helpers locais de leitura, para não depender de nenhuma outra página. */
function gc_fetch_one(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function gc_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── gerador_coletores_do_post ───────────────────────────────────────────────

$vazio = gerador_coletores_do_post([]);
t_eq('post vazio nao gera coletor', [], $vazio);

$post1 = [
    'coletor1_nome1' => ' João ',
    'coletor1_nome2' => '',
    'coletor2_nome1' => 'Maria',
    'coletor2_nome2' => ' Pedro ',
    'coletor5_nome1' => '',
    'coletor5_nome2' => '',
];
$esperado1 = [
    1 => ['João'],
    2 => ['Maria', 'Pedro'],
];
t_eq('monta grupos so com nomes preenchidos', $esperado1, gerador_coletores_do_post($post1));

// Campos de coletor 9+ (fora do range 1..8) devem ser ignorados.
$postFora = ['coletor9_nome1' => 'Alguém'];
t_eq('ignora coletor fora do range 1..8', [], gerador_coletores_do_post($postFora));

// ── gerador_validar_criacao ─────────────────────────────────────────────────

$coletoresOk = [1 => ['João']];

t_eq(
    'erro quando loja mudou depois da busca',
    'A loja mudou depois da busca. Clique em Buscar itens de novo.',
    gerador_validar_criacao(1, 2, 5, $coletoresOk)
);

t_eq(
    'erro quando nunca buscou itens (loja da busca nula)',
    'A loja mudou depois da busca. Clique em Buscar itens de novo.',
    gerador_validar_criacao(null, 2, 0, $coletoresOk)
);

t_eq(
    'erro quando nao ha itens',
    'Não há itens carregados. Clique em Buscar itens antes de criar o inventário.',
    gerador_validar_criacao(2, 2, 0, $coletoresOk)
);

t_eq(
    'erro quando nao ha coletor',
    'Informe ao menos um coletor.',
    gerador_validar_criacao(2, 2, 5, [])
);

t_null('sem erro quando tudo certo', gerador_validar_criacao(2, 2, 5, $coletoresOk));

// ── gerador_criar_inventario: criação ───────────────────────────────────────

$itensTeste = [
    [
        'ITEM_ESTOQUE'      => 'IT1',
        'ITEM_ESTOQUE_PUB'  => 'PUB1',
        'ITEM_PUB_REVENDA'  => null,
        'DES_ITEM_ESTOQUE'  => 'Peça de teste 1',
        'QUANTIDADE'        => 10,
        'LOCACAO_ZONA'      => 'A',
        'LOCACAO_RUA'       => '001',
        'LOCACAO_ESTANTE'   => '002',
        'LOCACAO_PRATELEIRA'=> '1',
        'LOCACAO_NUMERO'    => '003',
        'LOCACAO_GERAL'     => 'A-001-002-1-003',
        'CLASS_ABC'         => 'A',
        'CUS_ULT_ENTRADA'   => 12.5,
        'PRECO_ALTERNATIVO' => 15.0,
    ],
    [
        'ITEM_ESTOQUE'      => 'IT2',
        'ITEM_ESTOQUE_PUB'  => 'PUB2',
        'ITEM_PUB_REVENDA'  => 'PUBREV2',
        'DES_ITEM_ESTOQUE'  => 'Peça de teste 2',
        'QUANTIDADE'        => 3,
        'LOCACAO_ZONA'      => 'B',
        'LOCACAO_RUA'       => '004',
        'LOCACAO_ESTANTE'   => '005',
        'LOCACAO_PRATELEIRA'=> '2',
        'LOCACAO_NUMERO'    => '006',
        'LOCACAO_GERAL'     => 'B-004-005-2-006',
        'CLASS_ABC'         => 'C',
        'CUS_ULT_ENTRADA'   => 0,
        'PRECO_ALTERNATIVO' => 0,
    ],
];
$coletoresTeste = [
    1 => ['João', 'Pedro'],
    3 => ['Maria'],
];

$resultado = gerador_criar_inventario($pdo, GC_EMPRESA, GC_REVENDA, $itensTeste, $coletoresTeste);
$idCriado  = $resultado['id'];

t_true('retornou id positivo', $idCriado > 0);
t_eq('retornou quantidade de itens inseridos', 2, $resultado['itens']);

$header = gc_fetch_one($pdo, "SELECT * FROM inv_inventarios WHERE id = :id", [':id' => $idCriado]);
t_eq('header gravado com empresa correta', GC_EMPRESA, (int)$header['empresa']);
t_eq('header gravado com revenda correta', GC_REVENDA, (int)$header['revenda']);
t_eq('header gravado com status aberto', 'aberto', $header['status']);
t_eq(
    'coletores_json bate com o esperado',
    json_encode($coletoresTeste, JSON_UNESCAPED_UNICODE),
    $header['coletores_json']
);

$coletoresLinhas = gc_fetch_all(
    $pdo,
    "SELECT coletor_num, nome FROM inv_coletores WHERE id_inventario = :id ORDER BY coletor_num",
    [':id' => $idCriado]
);
t_eq('duas linhas em inv_coletores (grupos 1 e 3)', 2, count($coletoresLinhas));
t_eq('coletor 1 com dois nomes unidos por "e"', 'João e Pedro', $coletoresLinhas[0]['nome']);
t_eq('coletor 3 com um nome', 'Maria', $coletoresLinhas[1]['nome']);

$estoqueLinhas = gc_fetch_all(
    $pdo,
    "SELECT * FROM estoque WHERE id_inventario = :id ORDER BY cod_item_estoque",
    [':id' => $idCriado]
);
t_eq('duas linhas gravadas no estoque', 2, count($estoqueLinhas));
t_eq('cod_item_estoque do primeiro item', 'IT1', $estoqueLinhas[0]['cod_item_estoque']);
t_eq('locacao_geral gravada', 'A-001-002-1-003', $estoqueLinhas[0]['locacao_geral']);
t_eq('cont_1 default 0', 0, (int)$estoqueLinhas[0]['cont_1']);
t_eq('cont_2 default 0', 0, (int)$estoqueLinhas[0]['cont_2']);
t_eq('situacao default A', 'A', $estoqueLinhas[0]['situacao']);
t_eq('rodada_item default 1', 1, (int)$estoqueLinhas[0]['rodada_item']);
t_eq('coletor default 0', 0, (int)$estoqueLinhas[0]['coletor']);

gc_limpar_inventario($pdo, $idCriado);

// ── gerador_criar_inventario: rollback ──────────────────────────────────────

$contagemAntes = (int)gc_fetch_one($pdo, "SELECT COUNT(*) AS n FROM inv_inventarios")['n'];

$itensComFalha = [
    [
        'ITEM_ESTOQUE'      => 'ITX',
        'ITEM_ESTOQUE_PUB'  => 'PUBX',
        'DES_ITEM_ESTOQUE'  => 'Peça que vai falhar',
        'QUANTIDADE'        => 1,
        // locacao_zona é NOT NULL na tabela estoque: forçar null aqui faz o
        // INSERT estourar e testar o rollback.
        'LOCACAO_ZONA'      => null,
        'LOCACAO_RUA'       => '001',
        'LOCACAO_ESTANTE'   => '002',
        'LOCACAO_PRATELEIRA'=> '1',
        'LOCACAO_NUMERO'    => '003',
        'LOCACAO_GERAL'     => 'X-001-002-1-003',
        'CLASS_ABC'         => 'A',
        'CUS_ULT_ENTRADA'   => 1,
        'PRECO_ALTERNATIVO' => 1,
    ],
];

$lancou = false;
try {
    gerador_criar_inventario($pdo, GC_EMPRESA, GC_REVENDA, $itensComFalha, $coletoresTeste);
} catch (Exception $e) {
    $lancou = true;
}
t_true('lanca excecao quando o insert do estoque falha', $lancou);

$contagemDepois = (int)gc_fetch_one($pdo, "SELECT COUNT(*) AS n FROM inv_inventarios")['n'];
t_eq('nenhum inv_inventarios sobrou apos rollback', $contagemAntes, $contagemDepois);

$sobrouColetor = gc_fetch_all(
    $pdo,
    "SELECT * FROM inv_coletores WHERE empresa = :e AND revenda = :r",
    [':e' => GC_EMPRESA, ':r' => GC_REVENDA]
);
t_eq('nenhum inv_coletores orfao apos rollback', 0, count($sobrouColetor));

$sobrouEstoque = gc_fetch_all(
    $pdo,
    "SELECT * FROM estoque WHERE empresa = :e AND revenda = :r",
    [':e' => GC_EMPRESA, ':r' => GC_REVENDA]
);
t_eq('nenhum estoque orfao apos rollback', 0, count($sobrouEstoque));

t_fim();
