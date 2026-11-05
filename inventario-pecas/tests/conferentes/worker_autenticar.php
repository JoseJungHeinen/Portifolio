<?php
declare(strict_types=1);

/**
 * Um pedido de login do teste paralelo.
 *
 * Uso: php worker_autenticar.php <id> <pin> <t0>
 * Só números no argv: no Windows o escapeshellarg() destrói aspas.
 * Conecta antes e espera até t0 (microtime), para todos baterem juntos.
 */
chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'conferentes_lib.php';

$id  = (int)($argv[1] ?? 0);
$pin = (string)($argv[2] ?? '');
$t0  = (float)($argv[3] ?? 0);

while (microtime(true) < $t0) {
    usleep(1000);
}

echo json_encode(conferentes_autenticar($pdo, $id, $pin));
