<?php
declare(strict_types=1);

require __DIR__ . '/fixture.php';

echo "test_ajax_gestor\n";

rc_limpar($pdo);
rc_inventario($pdo);

$idAg = rc_linha($pdo, ['pub' => 'AG', 'loc' => '1-1-1-A-1', 'qtd' => 5, 'c1' => 8, 'sit' => 'C']); // ultima 8 > 5: sinal 'mais'
$idCt = rc_linha($pdo, ['pub' => 'CT', 'loc' => '1-1-1-A-2', 'qtd' => 5, 'c1' => 3, 'sit' => 'C', 'rod' => 2, 'col' => 1]);
$idNv = rc_linha($pdo, ['pub' => 'NV', 'loc' => '1-1-1-A-3', 'qtd' => 5, 'c1' => 2, 'sit' => 'C']);

$GESTOR = ['inventario_login' => ['usuario' => 'gestor.teste', 'perfil' => 'gestor']];

$chamar = function (array $get, array $post = [], ?array $sessao = null) use ($GESTOR) {
    $arquivo = sys_get_temp_dir() . '/rc_gestor.json';
    file_put_contents($arquivo, json_encode([
        'endpoint' => 'recontagem_ajax.php',
        'session'  => $sessao ?? $GESTOR,
        'get'      => $get,
        'post'     => $post,
    ]));

    return json_decode((string)shell_exec(
        'C:/xampp/php/php.exe -d display_errors=0 tests/recontagem/run_endpoint.php ' . escapeshellarg($arquivo)
    ), true);
};

$loja = ['codigo' => RC_EMP . '.' . RC_REV, 'inv' => (string)RC_INV];

// ── Grade ──────────────────────────────────────────────────────────────────
$g = $chamar($loja + ['modo' => 'grade', 'situacao' => 'recontar']);
t_true('grade ok', $g['ok'] ?? false);
t_eq('grade traz o recontar (aguardando)', [$idAg, $idNv], array_column($g['linhas'] ?? [], 'id'));
t_eq('grade traz o resumo', 1, $g['resumo']['contando'] ?? null);
t_eq('grade traz os coletores', 'Coletor 1 - Jose', $g['coletores'][1] ?? null);
t_true('grade traz os avisos de terminei', array_key_exists('terminaram', $g));
t_eq('grade traz as locacoes do filtro', ['1-1-1-A-1', '1-1-1-A-3'], $g['locacoes'] ?? null);
t_true('grade traz a conclusao', array_key_exists('conclusao', $g));
t_true('conclusao ainda nao concluida (tem contando/aguardando)', array_key_exists('concluida', $g['conclusao'] ?? []) && $g['conclusao']['concluida'] === false);

// ── Selecionar ─────────────────────────────────────────────────────────────
$s = $chamar($loja + [
    'modo' => 'selecionar', 'situacao' => 'recontar',
    'de' => '1-1-1-A-1', 'ate' => '1-1-1-A-3', 'sinal' => 'todos',
]);
t_true('selecionar ok', $s['ok'] ?? false);
t_eq('selecionar traz os ids da faixa', [$idAg, $idNv], $s['ids'] ?? null);
t_eq('selecionar traz o total', 2, $s['total'] ?? null);

$sMais = $chamar($loja + [
    'modo' => 'selecionar', 'situacao' => 'recontar',
    'de' => '1-1-1-A-1', 'ate' => '1-1-1-A-3', 'sinal' => 'mais',
]);
t_true('selecionar com sinal mais ok', $sMais['ok'] ?? false);
t_eq('selecionar com sinal mais: so o que subiu (ultima > sistema)', [$idAg], $sMais['ids'] ?? null);

// ── Mandar ─────────────────────────────────────────────────────────────────
$m = $chamar($loja + ['modo' => 'mandar'], ['ids' => json_encode([$idAg]), 'coletor' => '2']);
t_true('mandar ok', $m['ok'] ?? false);
t_eq('mandou 1', 1, $m['enviados'] ?? null);
t_eq('subiu', 2, (int)rc_ler($pdo, $idAg)['rodada_item']);

$inv = $chamar($loja + ['modo' => 'mandar'], ['ids' => json_encode([$idNv]), 'coletor' => '99']);
t_eq('coletor invalido recusado', false, $inv['ok'] ?? null);
t_eq('nao mudou rodada', 1, (int)rc_ler($pdo, $idNv)['rodada_item']);
t_eq('nao ganhou coletor', null, rc_ler($pdo, $idNv)['coletor_recontagem']);

$semIds = $chamar($loja + ['modo' => 'mandar'], ['ids' => '["12abc", [1]]', 'coletor' => '1']);
t_eq('ids invalidos recusados', false, $semIds['ok'] ?? null);
t_eq('mensagem de nenhum item', 'Nenhum item marcado.', $semIds['mensagem'] ?? null);

// ── Trocar coletor ─────────────────────────────────────────────────────────
$t = $chamar($loja + ['modo' => 'trocar_coletor'], ['ids' => json_encode([$idCt]), 'coletor' => '2']);
t_eq('trocou 1', 1, $t['trocados'] ?? null);
t_eq('coletor novo', 2, (int)rc_ler($pdo, $idCt)['coletor_recontagem']);

// ── Enviar (botão único) e prévia ───────────────────────────────────────────
$idAg2 = rc_linha($pdo, ['pub' => 'AG2', 'loc' => '1-1-1-A-4', 'qtd' => 5, 'c1' => 3, 'sit' => 'C']); // aguardando
$idCt2 = rc_linha($pdo, ['pub' => 'CT2', 'loc' => '1-1-1-A-5', 'qtd' => 5, 'c1' => 3, 'sit' => 'C', 'rod' => 2, 'col' => 1]); // contando

$prev = $chamar($loja + ['modo' => 'previa'], ['ids' => json_encode([$idAg2, $idCt2])]);
t_true('previa ok', $prev['ok'] ?? false);
t_eq('previa: um vai para a proxima', 1, $prev['para_proxima'] ?? null);
t_eq('previa: um troca de coletor', 1, $prev['trocar'] ?? null);
t_eq('previa: nenhum recusado', 0, $prev['recusados'] ?? null);
t_eq('previa: os dois sao encerraveis', 2, $prev['encerraveis'] ?? null);

$env = $chamar($loja + ['modo' => 'enviar'], ['ids' => json_encode([$idAg2, $idCt2]), 'coletor' => '2']);
t_true('enviar ok', $env['ok'] ?? false);
t_eq('enviar: enviados = 1', 1, $env['enviados'] ?? null);
t_eq('enviar: trocados = 1', 1, $env['trocados'] ?? null);
t_eq('aguardando subiu de rodada', 2, (int)rc_ler($pdo, $idAg2)['rodada_item']);
t_eq('contando manteve a rodada', 2, (int)rc_ler($pdo, $idCt2)['rodada_item']);
t_eq('contando trocou de coletor', 2, (int)rc_ler($pdo, $idCt2)['coletor_recontagem']);

// ── Permissão ──────────────────────────────────────────────────────────────
$sem = $chamar($loja + ['modo' => 'grade'], [], []);
t_eq('sem sessao recusa', false, $sem['ok'] ?? null);

$coletor = $chamar($loja + ['modo' => 'mandar'], ['ids' => json_encode([$idCt]), 'coletor' => '1'],
    ['inventario_login' => ['usuario' => 'x', 'perfil' => 'coletor']]);
t_eq('perfil sem permissao recusa', false, $coletor['ok'] ?? null);

// login.php grava o coletor sem 'perfil': coletor_num/coletor_nomes só.
$sessaoColetor = $chamar($loja + ['modo' => 'grade'], [], [
    'inventario_login' => ['usuario' => 'x', 'coletor_num' => 1, 'coletor_nomes' => ['x']],
]);
t_eq('sessao de coletor sem perfil recusa', false, $sessaoColetor['ok'] ?? null);

// ── Encerrar recontagem ──────────────────────────────────────────────────
$idAg3 = rc_linha($pdo, ['pub' => 'AG3', 'loc' => '1-1-1-A-6', 'qtd' => 5, 'c1' => 3, 'sit' => 'C']); // aguardando

$enc = $chamar($loja + ['modo' => 'encerrar'], ['ids' => json_encode([$idAg3])]);
t_true('encerrar ok', $enc['ok'] ?? false);
t_eq('encerrou 1', 1, $enc['encerrados'] ?? null);

$ag3 = rc_ler($pdo, $idAg3);
t_true('encerrada_em gravado', $ag3['recontagem_encerrada_em'] !== null);
t_eq('encerrada_por vem do nome da sessao', 'gestor.teste', $ag3['recontagem_encerrada_por']);
t_eq('vai para decisao', 'decisao', recontagem_situacao($ag3));

// ── Inventário fechado não aceita mandar ───────────────────────────────────
$pdo->prepare("UPDATE inv_inventarios SET status = 'concluido' WHERE id = ?")->execute([RC_INV]);
$f = $chamar($loja + ['modo' => 'mandar'], ['ids' => json_encode([$idCt]), 'coletor' => '1']);
t_eq('inventario fechado recusa', false, $f['ok'] ?? null);

rc_limpar($pdo);

t_fim();
