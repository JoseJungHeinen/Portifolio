<?php
declare(strict_types=1);

require __DIR__ . '/fixture.php';

echo "test_ajax_coletor\n";

rc_limpar($pdo);
rc_inventario($pdo);

$idA = rc_linha($pdo, ['pub' => 'AT812',  'loc' => '1-2-1-A-1', 'qtd' => 24, 'c1' => 18, 'sit' => 'C', 'rod' => 2, 'col' => 1]);
$idB = rc_linha($pdo, ['pub' => 'IT212P', 'loc' => '1-3-1-A-1', 'qtd' => 10, 'c1' => 2,  'sit' => 'C', 'rod' => 2, 'col' => 1]);
$idO = rc_linha($pdo, ['pub' => 'OUTRO',  'loc' => '1-4-1-A-1', 'qtd' => 10, 'c1' => 2,  'sit' => 'C', 'rod' => 2, 'col' => 2]);

$SESSAO = ['inventario' => [
    'id' => RC_INV, 'empresa' => RC_EMP, 'revenda' => RC_REV,
    'coletor' => 1, 'contagem' => 2, 'dupla_label' => 'Jose', 'locacao_inicial' => '',
]];

$chamar = function (array $get, array $post = [], ?array $sessao = null) use ($SESSAO) {
    $arquivo = sys_get_temp_dir() . '/rc_coletor.json';
    file_put_contents($arquivo, json_encode([
        'endpoint' => 'contagem_2_ajax.php',
        'session'  => $sessao ?? $SESSAO,
        'get'      => $get,
        'post'     => $post,
    ]));

    return json_decode((string)shell_exec(
        'C:/xampp/php/php.exe -d display_errors=0 tests/recontagem/run_endpoint.php ' . escapeshellarg($arquivo)
    ), true);
};

// ── proximo ────────────────────────────────────────────────────────────────
$p = $chamar(['modo' => 'proximo']);
t_true('proximo ok', $p['ok'] ?? false);
t_eq('primeiro item da fila do coletor 1', $idA, $p['item']['id'] ?? null);
t_eq('traz a contagem', 2, $p['item']['rodada'] ?? null);
t_eq('totais do coletor', ['total' => 2, 'feitos' => 0], $p['totais'] ?? null);

// Às cegas, também para quem abre as ferramentas do navegador.
$bruto = json_encode($p);
t_eq('nao vaza quantidade', false, strpos($bruto, 'quantidade') !== false);
t_eq('nao vaza cont_1', false, strpos($bruto, 'cont_1') !== false);

// ── confirmar_item ─────────────────────────────────────────────────────────
$ok = $chamar(['modo' => 'confirmar_item'], ['item' => 'at812-01', 'parada' => (string)$idA, 'entrada' => 'bipado']);
t_true('bipe com sufixo confere', $ok['ok'] ?? false);

$errado = $chamar(['modo' => 'confirmar_item'], ['item' => 'IT212P', 'parada' => (string)$idA, 'entrada' => 'bipado']);
t_eq('bipe de outro item recusado', false, $errado['ok'] ?? null);
t_eq('nomeia o item bipado', 'Esse é o item IT212P. Esta parada é AT812.', $errado['mensagem'] ?? null);

$mudou = $chamar(['modo' => 'confirmar_item'], ['item' => 'IT212P', 'parada' => (string)$idB, 'entrada' => 'bipado']);
t_true('parada da tela diferente da do servidor', $mudou['parada_mudou'] ?? false);

// ── gravar ─────────────────────────────────────────────────────────────────
$g = $chamar(['modo' => 'gravar'], ['id' => (string)$idA, 'quantidade' => '20', 'entrada' => 'bipado']);
t_true('gravou', $g['ok'] ?? false);
t_true('responde registrado', $g['registrado'] ?? false);
t_eq('nao diz se bateu', false, array_key_exists('bateu', $g));
t_eq('cont_2 gravado', 20, (int)rc_ler($pdo, $idA)['cont_2']);
t_eq('proximo item ja vem na resposta', $idB, $g['item']['id'] ?? null);

$fora = $chamar(['modo' => 'gravar'], ['id' => (string)$idO, 'quantidade' => '3', 'entrada' => 'bipado']);
t_true('item de outro coletor nao grava', $fora['parada_mudou'] ?? false);
t_eq('e nada mudou', null, rc_ler($pdo, $idO)['cont2_em']);

$neg = $chamar(['modo' => 'gravar'], ['id' => (string)$idB, 'quantidade' => '-3', 'entrada' => 'bipado']);
t_eq('quantidade negativa recusada', false, $neg['ok'] ?? null);

// ── pular ──────────────────────────────────────────────────────────────────
$pdo->prepare("UPDATE estoque SET cont2_em = NULL WHERE id_linha = ?")->execute([$idA]);
$pu = $chamar(['modo' => 'pular'], ['id' => (string)$idA]);
t_eq('pular serve o proximo', $idB, $pu['item']['id'] ?? null);

// ── terminei ───────────────────────────────────────────────────────────────
$t = $chamar(['modo' => 'terminei'], ['x' => '1']);
t_true('terminei ok', $t['ok'] ?? false);
t_eq('registrou com 2 pendentes', 2, contagem2_terminaram($pdo, RC_INV, RC_EMP, RC_REV, 2)[1]['pendentes'] ?? null);

$chamar(['modo' => 'gravar'], ['id' => (string)$idA, 'quantidade' => '5', 'entrada' => 'bipado']);
t_eq('gravar desfaz o aviso', [], contagem2_terminaram($pdo, RC_INV, RC_EMP, RC_REV, 2));

// ── sessão ─────────────────────────────────────────────────────────────────
$sem = $chamar(['modo' => 'proximo'], [], []);
t_eq('sem sessao nao responde dado', false, $sem['ok'] ?? null);

rc_limpar($pdo);

t_fim();
