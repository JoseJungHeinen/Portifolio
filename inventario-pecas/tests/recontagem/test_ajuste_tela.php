<?php
declare(strict_types=1);

/**
 * ajuste_quantidades.php de ponta a ponta: o POST do modal grava na última
 * contagem feita, e a lista mostra qual contagem o ajuste altera.
 */

require __DIR__ . '/fixture.php';

echo "test_ajuste_tela\n";

$tmp = sys_get_temp_dir();

$tela = function (array $caso) use ($tmp): string {
    $caso['session'] = ['inventario_login' => ['usuario' => 'gestor.teste', 'perfil' => 'gestor']];
    file_put_contents($tmp . '/ajuste_caso.json', json_encode($caso));

    return (string)shell_exec(
        'C:/xampp/php/php.exe -d display_errors=0 tests/recontagem/run_ajuste.php ' . escapeshellarg($tmp . '/ajuste_caso.json')
    );
};

$salvar = function (string $pub, string $loc, string $qtd) use ($tela) {
    $tela(['post' => [
        'salvar_ajuste' => '1', 'p_empresa' => RC_EMP, 'p_revenda' => RC_REV, 'p_id_inventario' => RC_INV,
        'p_item' => $pub, 'p_locacao' => $loc, 'nova_qtd' => $qtd,
        'codigo' => RC_EMP . '.' . RC_REV, 'inv' => RC_INV,
    ]]);
};

rc_limpar($pdo);
rc_inventario($pdo);

$agora = '2026-09-28 10:00:00';
$id1 = rc_linha($pdo, ['pub' => 'AJT1', 'loc' => '1-1-1-A-1', 'qtd' => 5, 'c1' => 3, 'sit' => 'C']);
$id2 = rc_linha($pdo, ['pub' => 'AJT2', 'loc' => '1-1-1-A-2', 'qtd' => 5, 'c1' => 3, 'c2' => 4, 'c2em' => $agora, 'sit' => 'C', 'rod' => 2]);

// ── Lista ──────────────────────────────────────────────────────────────────
$html = $tela(['get' => ['codigo' => RC_EMP . '.' . RC_REV, 'inv' => RC_INV]]);
t_true('lista abre', strpos($html, 'AJT2') !== false);
t_true('coluna Vale', strpos($html, '>Vale</th>') !== false);
t_true('item com 2a mostra 2a: 4', strpos($html, '2ª: <strong>4</strong>') !== false);
t_true('item sem recontagem mostra 1a: 3', strpos($html, '1ª: <strong>3</strong>') !== false);
t_true('modal com um campo so', strpos($html, 'name="nova_qtd"') !== false && strpos($html, 'nova_cont') === false);

// ── Gravação ───────────────────────────────────────────────────────────────
$salvar('AJT2', '1-1-1-A-2', '7');
t_eq('depois da 2a, o ajuste vai na cont_2', 7, (int)rc_ler($pdo, $id2)['cont_2']);
t_eq('cont_1 intacto', 3, (int)rc_ler($pdo, $id2)['cont_1']);

$salvar('AJT1', '1-1-1-A-1', '5');
t_eq('sem recontagem, o ajuste vai na cont_1', 5, (int)rc_ler($pdo, $id1)['cont_1']);

$salvar('AJT1', '1-1-1-A-1', 'abc');
t_eq('quantidade invalida nao grava', 5, (int)rc_ler($pdo, $id1)['cont_1']);

rc_limpar($pdo);

t_fim();
