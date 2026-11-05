<?php
declare(strict_types=1);

// Uso: php run_cadastro.php <perfil|nenhum>
// Renderiza cadastro_conferentes.php (GET) com a sessão de um usuário do
// dashboard daquele perfil, e imprime o HTML.
chdir(__DIR__ . '/../..');
session_start();
$perfil = $argv[1] ?? 'admin';
if ($perfil !== 'nenhum') {
    $_SESSION['inventario_login'] = ['usuario' => 'teste', 'perfil' => $perfil];
}
// Grava e fecha: o session_start() da página retoma esta mesma sessão (o id
// continua definido) em vez de avisar que já há uma ativa.
session_write_close();
$_SERVER['REQUEST_METHOD'] = 'GET';
require 'cadastro_conferentes.php';
