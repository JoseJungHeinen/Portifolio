<?php
declare(strict_types=1);

require __DIR__ . '/fixture.php';

echo "test_travas\n";

rc_limpar($pdo);
// rodada_atual = 2 de propósito: a trava por rodada do inventário não vale mais.
rc_inventario($pdo, 'aberto', 2);

$id1 = rc_linha($pdo, ['pub' => 'NA1', 'loc' => '1-1-1-A-1', 'qtd' => 5, 'sit' => 'A', 'rod' => 1]);
$id2 = rc_linha($pdo, ['pub' => 'NA2', 'loc' => '1-1-1-A-2', 'qtd' => 5, 'c1' => 1, 'sit' => 'C', 'rod' => 2, 'col' => 1]);

$tmp = sys_get_temp_dir();

// A 1ª contagem lê o corpo de php://input: sessão por arquivo, corpo por stdin.
$gravarComContagem = function (array $corpo, int $contagem) use ($tmp) {
    file_put_contents($tmp . '/rc_sessao.json', json_encode(['inventario' => [
        'id' => RC_INV, 'id_inventario' => RC_INV, 'empresa' => RC_EMP, 'revenda' => RC_REV,
        'coletor' => 3, 'contagem' => $contagem, 'dupla_label' => 'T',
    ]]));
    file_put_contents($tmp . '/rc_corpo.json', json_encode($corpo));

    return json_decode((string)shell_exec(
        'C:/xampp/php/php.exe -d display_errors=0 tests/contagem2/run_gravar.php '
        . escapeshellarg($tmp . '/rc_sessao.json') . ' < ' . escapeshellarg($tmp . '/rc_corpo.json')
    ), true);
};

$gravar = function (array $corpo) use ($gravarComContagem) {
    return $gravarComContagem($corpo, 1);
};

$sobra = function (array $post) use ($tmp) {
    file_put_contents($tmp . '/rc_sobras.json', json_encode([
        'session' => ['inventario_login' => ['usuario' => 'gestor.teste', 'perfil' => 'gestor']],
        'post'    => $post + ['gravar_sobra' => '1', 'inv' => RC_INV, 'empresa' => RC_EMP, 'revenda' => RC_REV],
    ]));

    return json_decode((string)shell_exec(
        'C:/xampp/php/php.exe -d display_errors=0 tests/contagem2/run_sobras.php ' . escapeshellarg($tmp . '/rc_sobras.json')
    ), true);
};

// ── 1ª contagem ────────────────────────────────────────────────────────────
$ok = $gravar(['modo' => 'contagem', 'locacao' => '1-1-1-A-1', 'item' => 'NA1', 'quantidade' => 2, 'forcar' => true]);
t_true('1a grava item que esta na 1a, mesmo com rodada_atual = 2', $ok['ok'] ?? false);
t_eq('cont_1 gravado', 2, (int)rc_ler($pdo, $id1)['cont_1']);

$nao = $gravar(['modo' => 'contagem', 'locacao' => '1-1-1-A-2', 'item' => 'NA2', 'quantidade' => 2, 'forcar' => true]);
t_eq('1a recusa item que ja foi para a 2a', false, $nao['ok'] ?? null);
t_eq('com a mensagem', 'Este item já está na 2ª contagem.', $nao['mensagem'] ?? null);
t_eq('cont_1 nao mudou', 1, (int)rc_ler($pdo, $id2)['cont_1']);

// ── Sobras ─────────────────────────────────────────────────────────────────
$s1 = $sobra(['item' => 'NA1', 'locacao' => '1-1-1-A-1', 'quantidade' => '1']);
t_true('sobra grava item na 1a', $s1['ok'] ?? false);

$s2 = $sobra(['item' => 'NA2', 'locacao' => '1-1-1-A-2', 'quantidade' => '1']);
t_eq('sobra recusa item na 2a', false, $s2['ok'] ?? null);
t_eq('sobra com a mensagem', 'Este item já está na 2ª contagem.', $s2['mensagem'] ?? null);

// ── Sessão de contagem 2 não escreve cont_2 aqui ────────────────────────────
// contagem_gravar.php é sempre a 1ª contagem; quem grava cont_2 é o
// contagem_2_ajax.php, com coletor designado e data.
$id3 = rc_linha($pdo, ['pub' => 'NA3', 'loc' => '1-1-1-A-3', 'qtd' => 5, 'sit' => 'A', 'rod' => 1]);

$comSessaoDeContagem2 = $gravarComContagem(
    ['modo' => 'contagem', 'locacao' => '1-1-1-A-3', 'item' => 'NA3', 'quantidade' => 3, 'forcar' => true],
    2
);
t_true('sessao de 2a contagem ainda assim grava (item esta na rodada 1)', $comSessaoDeContagem2['ok'] ?? false);
t_eq('cont_1 mudou', 3, (int)rc_ler($pdo, $id3)['cont_1']);
t_eq('cont_2 nao mudou', 0, (int)rc_ler($pdo, $id3)['cont_2']);

// ── Item encerrado: nenhuma porta grava mais, mesmo estando na rodada 1 ────
$id4 = rc_linha($pdo, ['pub' => 'NA4', 'loc' => '1-1-1-A-4', 'qtd' => 5, 'sit' => 'A', 'rod' => 1,
    'encerrada_em' => '2026-09-24 09:00:00', 'encerrada_por' => 'Gestor Teste']);

$naoEnc = $gravar(['modo' => 'contagem', 'locacao' => '1-1-1-A-4', 'item' => 'NA4', 'quantidade' => 2, 'forcar' => true]);
t_eq('1a recusa item encerrado', false, $naoEnc['ok'] ?? null);
t_eq('com a mensagem de encerrado', 'Este item já foi encerrado e está em decisão.', $naoEnc['mensagem'] ?? null);
t_eq('cont_1 nao mudou no encerrado', 0, (int)rc_ler($pdo, $id4)['cont_1']);

$sobraEnc = $sobra(['item' => 'NA4', 'locacao' => '1-1-1-A-4', 'quantidade' => '1']);
t_eq('sobra recusa item encerrado', false, $sobraEnc['ok'] ?? null);
t_eq('sobra com a mensagem de encerrado', 'Este item já foi encerrado e está em decisão.', $sobraEnc['mensagem'] ?? null);

rc_limpar($pdo);

t_fim();
