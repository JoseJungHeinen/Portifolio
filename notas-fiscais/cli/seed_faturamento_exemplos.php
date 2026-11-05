<?php
// @versao Fat_06 V_01_30/09/2026
declare(strict_types=1);

/**
 * 15 pedidos de exemplo do Faturamento — SÓ no banco local.
 *
 * Uso: php cli/seed_faturamento_exemplos.php
 * Idempotente: cada exemplo leva apollo_ref 'EXEMPLO-NN' e não é recriado.
 */

require_once __DIR__ . '/../config/constants.php';
require_once CONFIG . '/database.php';
require_once SRC . '/Models/Faturamento.php';

$pdo = Database::getInstance();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'compras') {
    fwrite(STDERR, "Só roda no banco local 'compras'.\n");
    exit(1);
}

$user   = static fn(string $email) => $pdo->query('SELECT id FROM usuarios WHERE email = ' . $pdo->quote($email))->fetchColumn();
$vend   = [$user('fabio.compras@exemplo.com'), $user('suporte1@exemplo.com')];
$admin  = $user('gerente.ti@exemplo.com');
$filial = $pdo->query('SELECT id FROM filiais WHERE ativo = 1 ORDER BY nome LIMIT 1')->fetchColumn() ?: null;
$fases  = Faturamento::fases();

// [tipo, cliente, doc, cidade, veículo, cód, placa, ano fab/mod, combustível, valor, opcionais, frete, fase, pagamentos]
$ex = [
    ['Novos',        'CARLOS EDUARDO MENDES',  '111.444.777-35',      'Erechim',         'Chevrolet Onix 1.0 Turbo', 'ABC1D23', 'ABC1D23', '2025/2026', 'Flex',   89990,  1490, 0,    0, ['Financiamento' => 60000, 'Entrada' => 31480]],
    ['Novos',        'TRANSPORTES SERRA LTDA', '11.222.333/0001-81',  'Passo Fundo',     'Fiat Toro Freedom',        'ABC1D23', null,       '2025/2025', 'Diesel', 169900, 4900, 1200, 1, ['Financiamento' => 120000, 'Entrada' => 56000]],
    ['Novos',        'ANA PAULA RIBEIRO',      '111.444.777-35',      'Getúlio Vargas',  'VW T-Cross Comfortline',   'ABC1D23', null,       '2025/2026', 'Flex',   149990, 0,    900,  2, ['Consórcio' => 150890]],
    ['Seminovos',    'LUCAS HENRIQUE DIAS',    '111.444.777-35',      'Erechim',         'Honda Civic EXL 2019',     'ABC1D23', 'ABC1D23', '2018/2019', 'Flex',   98500,  0,    0,    0, ['Troca' => 45000, 'Entrada' => 53500]],
    ['Seminovos',    'MARIANA COSTA',          '111.444.777-35',      'Aratiba',         'Jeep Renegade 2020',       'ABC1D23', 'ABC1D23', '2019/2020', 'Flex',   79900,  0,    0,    1, ['Financiamento' => 50000, 'Entrada' => 29900]],
    ['Seminovos',    'PEDRO AUGUSTO LIMA',     '111.444.777-35',      'Erechim',         'Toyota Corolla XEi 2021',  'ABC1D23', 'ABC1D23', '2020/2021', 'Flex',   119900, 0,    0,    3, ['Boleto' => 119900]],
    ['Repasse',      'AUTO CENTER LTDA',       '11.222.333/0001-81',  'Erechim',         'Fiat Cronos 2023',         'ABC1D23', 'ABC1D23', '2022/2023', 'Flex',   62000,  0,    0,    2, ['Boleto' => 62000]],
    ['Repasse',      'GARAGEM DO VALE EIRELI', '11.222.333/0001-81',  'Sarandi',         'Renault Sandero 2018',     'ABC1D23', 'ABC1D23', '2017/2018', 'Flex',   38900,  0,    0,    1, ['Pagamento de terceiro' => 38900]],
    ['Venda Direta', 'MARIA SOUZA',            '111.444.777-35',      'Erechim',         'Hyundai HB20 2024',        'HB2-9931', 'ABC1D23', '2023/2024', 'Flex',   77990,  990,  0,    1, ['Financiamento' => 50000, 'Entrada' => 28980]],
    ['Venda Direta', 'FERNANDO ALVES',         '111.444.777-35',      'Erechim',         'Hyundai Creta Limited',    'ABC1D23', null,       '2025/2026', 'Flex',   168990, 6500, 1500, 0, ['Financiamento' => 130000, 'Entrada' => 46990]],
    ['Venda Direta', 'JULIANA MOREIRA',        '111.444.777-35',      'Áurea',           'Nissan Kicks SV 2022',     'ABC1D23', 'ABC1D23', '2021/2022', 'Flex',   94900,  0,    0,    3, ['Troca' => 30000, 'Entrada' => 64900]],
    ['Corsia',       'JOÃO PEREIRA',           '111.444.777-35',      'Erechim',         'Fiat Argo 2022',           'ABC1D23', 'ABC1D23', '2021/2022', 'Flex',   67900,  0,    0,    0, ['Entrada' => 20000, 'Financiamento' => 47900]],
    ['Corsia',       'SILVIO ROBERTO BASSO',   '111.444.777-35',      'Gaurama',         'Citroën C4 Cactus 2020',   'ABC1D23', 'ABC1D23', '2019/2020', 'Flex',   71500,  0,    0,    2, ['Consórcio' => 71500]],
    ['Novos',        'AGROPECUÁRIA BOM PASTO', '11.222.333/0001-81',  'Campinas do Sul', 'Ford Ranger XLS',          'ABC1D23', null,       '2025/2026', 'Diesel', 259900, 8900, 2500, 1, ['Financiamento' => 200000, 'Entrada' => 71300]],
    ['Seminovos',    'PATRÍCIA NUNES',         '111.444.777-35',      'Erechim',         'VW Polo Highline 2021',    'ABC1D23', 'ABC1D23', '2020/2021', 'Flex',   84900,  0,    0,    3, ['Financiamento' => 55000, 'Entrada' => 29900]],
];

$chk = $pdo->prepare('SELECT 1 FROM nf_fat_pedidos WHERE apollo_ref = ?');
$pag = $pdo->prepare('INSERT INTO nf_fat_pagamentos (id, pedido_id, fat_numero, ordem, tipo, valor, data) VALUES (?,?,?,?,?,?,?)');
$criados = 0;

foreach ($ex as $i => $e) {
    [$tipo, $cli, $doc, $cid, $vei, $cod, $placa, $ano, $comb, $valor, $opc, $frete, $fase, $pagos] = $e;
    $ref = sprintf('EXEMPLO-%02d', $i + 1);
    $chk->execute([$ref]);
    if ($chk->fetchColumn()) {
        continue;
    }

    $dias = 2 + $i * 2;
    $data = date('Y-m-d', strtotime("-$dias days"));
    $id = Faturamento::criar([
        'vendedor_id' => $vend[$i % 2], 'tipo_venda' => $tipo,
        'filial_id'   => in_array($tipo, ['Novos', 'Seminovos', 'Repasse'], true) ? $filial : null,
        'apollo_ref'  => $ref,
        'cliente_nome' => $cli, 'cliente_doc' => $doc, 'cliente_cidade' => $cid, 'cliente_uf' => 'RS',
        'cliente_nacionalidade' => 'Brasileira',
        'veiculo_codigo' => $cod, 'veiculo_desc' => $vei, 'veiculo_placa' => $placa,
        'veiculo_ano_fab_mod' => $ano, 'veiculo_combustivel' => $comb,
        'valor_veiculo' => $valor, 'valor_opcionais' => $opc, 'valor_frete' => $frete,
        'valor_total' => $valor + $opc + $frete,
        'data_inicio' => $data,
    ], $admin);

    $num = $pdo->query('SELECT numero FROM nf_fat_pedidos WHERE id = ' . $pdo->quote($id))->fetchColumn();
    if ($fase > 0 && isset($fases[$fase])) {
        $pdo->prepare('UPDATE nf_fat_pedidos SET fase_id = ? WHERE id = ?')->execute([$fases[$fase]['id'], $id]);
    }
    $ordem = 1;
    foreach ($pagos as $t => $v) {
        $pag->execute([Faturamento::uuid(), $id, $num, $ordem++, $t, $v, $data]);
    }
    $criados++;
}
echo "Exemplos criados: $criados (de " . count($ex) . ").\n";
