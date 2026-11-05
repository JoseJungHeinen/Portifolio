<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'conferentes_lib.php';
require __DIR__ . '/assert.php';

echo "test_autenticar_paralelo\n";

// Só o nome deste teste: outros ZZTESTE (do navegador, por exemplo) ficam.
$NOME = 'ZZTESTE Paralelo';
$apagar = function () use ($pdo, $NOME): void {
    $pdo->prepare("DELETE FROM inv_conferentes WHERE nome = ?")->execute([$NOME]);
};

/**
 * Dispara todos os PINs ao mesmo tempo, um processo cada, e devolve os
 * resultados. Ninguém espera ninguém até todos estarem no ar; a barreira de
 * t0 faz os pedidos chegarem juntos no banco.
 */
function rajada(int $id, array $pins): array
{
    $t0    = sprintf('%.3F', microtime(true) + 1.5);
    $procs = [];
    foreach ($pins as $pin) {
        $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 '
             . escapeshellarg(__DIR__ . '/worker_autenticar.php') . ' '
             . $id . ' ' . $pin . ' ' . $t0;
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }

    $resultados = [];
    foreach ($procs as [$p, $pipes]) {
        $saida = (string)stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        $ini = strpos($saida, '{');
        $resultados[] = $ini === false ? ['erro' => $saida] : (json_decode(substr($saida, $ini), true) ?? ['erro' => $saida]);
    }
    return $resultados;
}

function contar(array $resultados): array
{
    $n = ['pin' => 0, 'bloqueado' => 0, 'ok' => 0, 'outro' => 0];
    foreach ($resultados as $r) {
        if (($r['ok'] ?? false) === true) {
            $n['ok']++;
        } elseif (isset($r['motivo'], $n[$r['motivo']])) {
            $n[$r['motivo']]++;
        } else {
            $n['outro']++;
        }
    }
    return $n;
}

$apagar();

// ── 12 PINs errados juntos: só 5 conferidos ─────────────────────────────────
// Em fila: 4 voltam 'pin', o 5º bloqueia e zera o contador, os outros 7 nem
// conferem. Sem a fila, mais de 5 conferem e o contador volta a subir depois
// do bloqueio.
$id = conferentes_cadastrar($pdo, $NOME, '2468');
$n  = contar(rajada($id, array_fill(0, 12, '0000')));
echo "  rajada errada: " . json_encode($n) . "\n";

t_eq('nenhum processo falhou', 0, $n['outro']);
t_true('no maximo 4 respostas pin', $n['pin'] <= 4);
t_true('pelo menos um bloqueado', $n['bloqueado'] >= 1);
$stmt = $pdo->prepare("SELECT bloqueado_ate > NOW() AS bloq, tentativas_erradas FROM inv_conferentes WHERE id = ?");
$stmt->execute([$id]);
$linha = $stmt->fetch(PDO::FETCH_ASSOC);
t_eq('ficou bloqueado', 1, (int)$linha['bloq']);
t_eq('nenhum erro contado depois do bloqueio', 0, (int)$linha['tentativas_erradas']);

// ── Já com 4 erros, 11 errados + o certo juntos ─────────────────────────────
// Só o primeiro da fila confere. Se for o certo, entra e os 11 recomeçam
// do zero (4 'pin' e bloqueia); se for um errado, bloqueia e o certo não
// entra. Ou seja: pin = 4 * ok. Sem a fila, o certo entra mesmo depois do
// 5º erro.
conferentes_desbloquear($pdo, $id);
for ($i = 0; $i < 4; $i++) {
    conferentes_autenticar($pdo, $id, '0000');
}
$pins = array_fill(0, 11, '0000');
array_splice($pins, 6, 0, ['2468']);
$n = contar(rajada($id, $pins));
echo "  rajada com o certo: " . json_encode($n) . "\n";

t_eq('nenhum processo falhou (2)', 0, $n['outro']);
t_true('pin + ok no maximo 5', $n['pin'] + $n['ok'] <= 5);
t_eq('o certo so entra se foi o primeiro da fila', 4 * $n['ok'], $n['pin']);

$apagar();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM inv_conferentes WHERE nome = ?");
$stmt->execute([$NOME]);
t_eq('teste limpou o que criou', 0, (int)$stmt->fetchColumn());

t_fim();
