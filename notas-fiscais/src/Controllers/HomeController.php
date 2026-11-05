<?php
// @versao Ini_02 V_01_18/07/2026
declare(strict_types=1);

/**
 * Controller da Tela Inicial (boas-vindas Notas).
 *
 * Roteamento: ?module=home&action=index
 *
 * Tela standalone (sem sidebar/topbar), exibida logo após o login.
 * Não tem gate de permissão — qualquer usuário autenticado a vê; o
 * botão "Começar" segue para a tela inicial do sistema (Minhas Tarefas).
 */

Auth::check();

$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

if ($action === 'index') {
    $usuario = Auth::user();
    // Primeiro nome para a saudação (fallback vazio se indisponível)
    $primeiroNome = '';
    if (!empty($usuario['nome'])) {
        $primeiroNome = explode(' ', trim((string) $usuario['nome']))[0];
    }

    include VIEWS . '/home/bemvindo.php';
    exit;
}

// Ação desconhecida
http_response_code(404);
include VIEWS . '/errors/404.php';
