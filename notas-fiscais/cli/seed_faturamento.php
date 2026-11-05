<?php
// @versao Fat_05 V_01_29/09/2026
declare(strict_types=1);

/**
 * Seed de TESTE do módulo Faturamento — só roda no banco local.
 *
 * Uso: php cli/seed_faturamento.php
 *
 * Monta: 2 vendedores (grupo Vendedores), 1 equipe com gerente (grupo
 * "Equipe Teste", gestor = gerente), e 5 pedidos em fases/situações
 * diferentes para exercitar visibilidade e travas. Idempotente: se já
 * existem pedidos, não faz nada.
 */

require_once __DIR__ . '/../config/constants.php';
require_once CONFIG . '/database.php';
require_once SRC . '/Models/Faturamento.php';

$pdo = Database::getInstance();

if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'compras'
    || !in_array($_SERVER['SERVER_NAME'] ?? 'localhost', ['localhost', '127.0.0.1', ''], true)) {
    fwrite(STDERR, "Seed só roda no banco local 'compras'.\n");
    exit(1);
}
if ((int) $pdo->query('SELECT COUNT(*) FROM nf_fat_pedidos')->fetchColumn() > 0) {
    echo "Já existem pedidos; nada a fazer.\n";
    exit(0);
}

$user  = static fn(string $email) => $pdo->query("SELECT id FROM usuarios WHERE email = " . $pdo->quote($email))->fetchColumn();
$grupo = static fn(string $nome) => $pdo->query("SELECT id FROM nf_grupos WHERE nome = " . $pdo->quote($nome))->fetchColumn();

$vend1   = $user('fabio.compras@exemplo.com');
$vend2   = $user('suporte1@exemplo.com');
$gerente = $user('compras@exemplo.com');
$secret  = $user('vendadireta@exemplo.com');
$admin   = $user('gerente.ti@exemplo.com');

$add = $pdo->prepare('INSERT IGNORE INTO nf_grupo_membros (grupo_id, usuario_id) VALUES (?, ?)');
foreach ([[$vend1, 'Vendedores'], [$vend2, 'Vendedores'], [$gerente, 'Gerentes de Vendas'], [$secret, 'Secretárias']] as [$u, $g]) {
    $add->execute([$grupo($g), $u]);
}

// Equipe do gerente: grupo com gestor_id = gerente, com o vendedor 1 dentro
$equipe = $grupo('Equipe Teste');
if (!$equipe) {
    $equipe = Faturamento::uuid();
    $pdo->prepare('INSERT INTO nf_grupos (id, nome, descricao, ativo, gestor_id) VALUES (?, ?, ?, 1, ?)')
        ->execute([$equipe, 'Equipe Teste', 'Seed de teste do Faturamento', $gerente]);
}
$add->execute([$equipe, $vend1]);

$filial = $pdo->query("SELECT id FROM filiais WHERE ativo = 1 ORDER BY nome LIMIT 1")->fetchColumn();

$pedidos = [
    ['v' => $vend1, 'tipo' => 'Novos',        'cli' => 'GABRIEL DE OLIVEIRA', 'doc' => '111.444.777-35', 'cod' => null,     'vei' => null,                   'fil' => $filial, 'fase' => 0],
    ['v' => $vend1, 'tipo' => 'Seminovos',    'cli' => 'GABRIEL DE OLIVEIRA', 'doc' => '111.444.777-35', 'cod' => 'ABC1D23', 'vei' => 'Ford EcoSport 2019',   'fil' => null,    'fase' => 0],
    ['v' => $vend1, 'tipo' => 'Venda Direta', 'cli' => 'MARIA SOUZA',         'doc' => '111.444.777-35', 'cod' => 'HB2-9931', 'vei' => 'Hyundai HB20 2024',    'fil' => null,    'fase' => 1],
    ['v' => $vend2, 'tipo' => 'Repasse',      'cli' => 'AUTO CENTER LTDA',    'doc' => '11.222.333/0001-81', 'cod' => 'ABC1D23', 'vei' => 'Fiat Cronos 2023', 'fil' => $filial, 'fase' => 2],
    ['v' => $vend2, 'tipo' => 'Corsia',       'cli' => 'JOÃO PEREIRA',        'doc' => '111.444.777-35', 'cod' => 'ABC1D23', 'vei' => 'Fiat Argo 2022',       'fil' => null,    'fase' => 0],
];

$fases = Faturamento::fases();
foreach ($pedidos as $p) {
    $id = Faturamento::criar([
        'vendedor_id' => $p['v'], 'tipo_venda' => $p['tipo'], 'filial_id' => $p['fil'],
        'cliente_nome' => $p['cli'], 'cliente_doc' => $p['doc'],
        'veiculo_codigo' => $p['cod'], 'veiculo_desc' => $p['vei'],
        'data_inicio' => date('Y-m-d', strtotime('-' . random_int(1, 20) . ' days')),
    ], $admin);
    if ($p['fase'] > 0) {
        $pdo->prepare('UPDATE nf_fat_pedidos SET fase_id = ? WHERE id = ?')->execute([$fases[$p['fase']]['id'], $id]);
    }
}
echo "Seed concluído: " . count($pedidos) . " pedidos.\n";
