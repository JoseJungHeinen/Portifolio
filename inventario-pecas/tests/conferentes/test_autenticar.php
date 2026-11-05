<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'conferentes_lib.php';
require __DIR__ . '/assert.php';

echo "test_autenticar\n";

$PREFIXO = 'ZZTESTE ';
$pdo->prepare("DELETE FROM inv_conferentes WHERE nome LIKE ?")->execute([$PREFIXO . '%']);

$id = conferentes_cadastrar($pdo, $PREFIXO . 'Carla', '2468');

$tentativas = function () use ($pdo, $id): int {
    return (int)$pdo->query("SELECT tentativas_erradas FROM inv_conferentes WHERE id = " . $id)->fetchColumn();
};
// Joga o bloqueio para o passado, como se os 5 minutos tivessem passado.
$vencerBloqueio = function () use ($pdo, $id): void {
    $pdo->exec("UPDATE inv_conferentes SET bloqueado_ate = NOW() - INTERVAL 1 MINUTE WHERE id = " . $id);
};

// ── PIN certo ───────────────────────────────────────────────────────────────
t_eq('PIN certo entra', ['ok' => true, 'nome' => $PREFIXO . 'Carla'], conferentes_autenticar($pdo, $id, '2468'));

// ── PIN errado conta até bloquear ───────────────────────────────────────────
t_eq('1o erro, restam 4', ['ok' => false, 'motivo' => 'pin', 'restantes' => 4], conferentes_autenticar($pdo, $id, '0000'));
t_eq('2o erro, restam 3', ['ok' => false, 'motivo' => 'pin', 'restantes' => 3], conferentes_autenticar($pdo, $id, '0000'));

// PIN certo no meio zera a contagem.
t_eq('PIN certo depois de errar entra', true, conferentes_autenticar($pdo, $id, '2468')['ok']);
t_eq('PIN certo zera as tentativas', 0, $tentativas());

for ($i = 1; $i <= 3; $i++) {
    conferentes_autenticar($pdo, $id, '0000');
}
t_eq('4o erro, resta 1', ['ok' => false, 'motivo' => 'pin', 'restantes' => 1], conferentes_autenticar($pdo, $id, '0000'));
t_eq('4 erros seguidos ficam contados', 4, $tentativas());

$r = conferentes_autenticar($pdo, $id, '0000');
t_eq('5o erro bloqueia', 'bloqueado', $r['motivo'] ?? null);
t_true('bloqueio diz ate quando (H:i)', preg_match('/^\d{2}:\d{2}$/', (string)($r['ate'] ?? '')) === 1);
t_eq('bloqueio zera a contagem', 0, $tentativas());

// Durante o bloqueio nem o PIN certo entra, e não conta tentativa.
t_eq('PIN certo bloqueado nao entra', 'bloqueado', conferentes_autenticar($pdo, $id, '2468')['motivo'] ?? null);
t_eq('tentativa bloqueada nao conta', 0, $tentativas());

$lista = array_values(array_filter(conferentes_listar($pdo, false), function ($c) use ($id) { return $c['id'] === $id; }));
t_eq('listar mostra bloqueado', true, $lista[0]['bloqueado']);

// Passado o bloqueio, entra.
$vencerBloqueio();
t_eq('depois do bloqueio entra', true, conferentes_autenticar($pdo, $id, '2468')['ok']);

// ── Desbloquear e trocar PIN ────────────────────────────────────────────────
for ($i = 1; $i <= 5; $i++) {
    conferentes_autenticar($pdo, $id, '0000');
}
conferentes_desbloquear($pdo, $id);
t_eq('desbloquear libera na hora', true, conferentes_autenticar($pdo, $id, '2468')['ok']);

for ($i = 1; $i <= 5; $i++) {
    conferentes_autenticar($pdo, $id, '0000');
}
conferentes_trocar_pin($pdo, $id, '1357');
t_eq('trocar PIN zera tentativas', 0, $tentativas());
t_eq('trocar PIN tira o bloqueio', null, $pdo->query("SELECT bloqueado_ate FROM inv_conferentes WHERE id = " . $id)->fetchColumn());
t_eq('PIN antigo nao entra mais', 'pin', conferentes_autenticar($pdo, $id, '2468')['motivo'] ?? null);
t_eq('PIN novo entra', true, conferentes_autenticar($pdo, $id, '1357')['ok']);

$erro = '';
try {
    conferentes_trocar_pin($pdo, $id, 'abcd');
} catch (InvalidArgumentException $e) {
    $erro = $e->getMessage();
}
t_eq('trocar para PIN invalido recusado', 'O PIN tem 4 números.', $erro);

// ── Inativo e inexistente ───────────────────────────────────────────────────
conferentes_definir_ativo($pdo, $id, false);
t_eq('inativo nao entra', ['ok' => false, 'motivo' => 'inexistente'], conferentes_autenticar($pdo, $id, '1357'));
conferentes_autenticar($pdo, $id, '0000');
t_eq('inativo nao conta tentativa', 0, $tentativas());
t_eq('id inexistente', ['ok' => false, 'motivo' => 'inexistente'], conferentes_autenticar($pdo, 0, '1357'));

$pdo->prepare("DELETE FROM inv_conferentes WHERE nome LIKE ?")->execute([$PREFIXO . '%']);
t_fim();
