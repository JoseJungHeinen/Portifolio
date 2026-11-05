<?php
declare(strict_types=1);

/**
 * sobras.php: o bipe (sufixo de variante, zeros que o leitor põe na frente)
 * resolve contra os itens do inventário, como na 1ª contagem.
 */

require __DIR__ . '/../recontagem/fixture.php';

echo "test_sobras_codigo\n";

$tmp = sys_get_temp_dir();

$sobras = function (array $caso) use ($tmp) {
    $caso['session'] = ['inventario_login' => ['usuario' => 'gestor.teste', 'perfil' => 'gestor']];
    file_put_contents($tmp . '/sobras_codigo.json', json_encode($caso));

    return json_decode((string)shell_exec(
        'C:/xampp/php/php.exe -d display_errors=0 tests/contagem2/run_sobras.php ' . escapeshellarg($tmp . '/sobras_codigo.json')
    ), true);
};

$consultar = function (string $bipe) use ($sobras) {
    return $sobras(['get' => [
        'ajax' => 'consultar_item', 'item' => $bipe,
        'empresa' => RC_EMP, 'revenda' => RC_REV, 'inv' => RC_INV,
    ]]);
};

rc_limpar($pdo);

$id063 = rc_linha($pdo, ['pub' => '063806',   'loc' => '1-1-1-A-1', 'qtd' => 5, 'c1' => 5, 'sit' => 'C']);
rc_linha($pdo,          ['pub' => '127003OF', 'loc' => '1-1-1-A-2', 'qtd' => 5, 'c1' => 5, 'sit' => 'C']);
rc_linha($pdo,          ['pub' => 'MK5001',   'loc' => '1-1-1-A-3', 'qtd' => 5, 'c1' => 5, 'sit' => 'C']);
rc_linha($pdo,          ['pub' => 'K123AA',   'loc' => '1-1-1-A-3', 'qtd' => 5, 'c1' => 5, 'sit' => 'C']);

// ── Consulta ───────────────────────────────────────────────────────────────
$casos = [
    '063806'         => '063806',
    '063806-01'      => '063806',
    '063806001'      => '063806',
    '000063806'      => '063806',
    '000063806-01'   => '063806',
    '000127003OF-01' => '127003OF',
    'MK5001'         => 'MK5001',   // termina em 001 de verdade: não corta
    'MK5001-01'      => 'MK5001',
    '123AA-01'       => 'K123AA',   // regra do K
];

foreach ($casos as $bipe => $esperado) {
    $r = $consultar($bipe);
    t_true("consulta $bipe acha", $r['ok'] ?? false);
    t_eq("consulta $bipe devolve o codigo do inventario", $esperado, $r['item'] ?? null);
    t_eq("consulta $bipe traz a locacao do item", $esperado, $r['locacoes'][0]['item_estoque_pub'] ?? null);
}

$nao = $consultar('000ZZZNAOEXISTE-01');
t_eq('inexistente continua nao encontrado', false, $nao['ok'] ?? null);

// ── Gravação ───────────────────────────────────────────────────────────────
$g = $sobras(['post' => [
    'gravar_sobra' => '1', 'item' => '000063806-01', 'locacao' => '1-1-1-A-1', 'quantidade' => '2',
    'inv' => RC_INV, 'empresa' => RC_EMP, 'revenda' => RC_REV,
]]);
t_true('grava sobra com o bipe cru', $g['ok'] ?? false);
t_eq('somou na linha do 063806', 7, (int)rc_ler($pdo, $id063)['cont_1']);

rc_limpar($pdo);

t_fim();
