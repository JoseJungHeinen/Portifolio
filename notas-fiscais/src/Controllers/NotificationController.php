<?php
// @versao Not_02 V_01_16/07/2026
declare(strict_types=1);

/**
 * Controller do módulo Notificações.
 *
 * Roteamento: ?module=notificacoes&action=X
 *
 * Actions:
 *   index        — GET: página completa de notificações
 *   marcar       — POST: marca uma notificação como lida
 *   marcar_todas — POST: marca todas como lidas
 */

Auth::check();
Permissions::require('notificacoes', 'ver');

require_once SRC . '/Models/Notification.php';

$currentUser = Auth::user();
$usuarioId   = $currentUser['id'];
$action      = Sanitize::fromGet(['action'])['action'];
$action      = $action !== '' ? $action : 'index';

// ── POST: marcar uma como lida ───────────────────────────
if ($action === 'marcar') {
    Csrf::validate(Sanitize::fromPost(['csrf_token'])['csrf_token'] ?? '');

    $id = Sanitize::fromPost(['id'])['id'];
    try {
        $id = Sanitize::uuid($id);
        Notification::markRead($id, $usuarioId);
    } catch (\InvalidArgumentException) {}

    // Só aceita URL interna (começa com '?') — previne open redirect
    $redir = Sanitize::fromPost(['redirect'])['redirect'];
    if (!str_starts_with($redir, '?')) {
        $redir = '?module=notificacoes&action=index';
    }
    header('Location: ' . $redir);
    exit;
}

// ── POST: marcar todas como lidas ───────────────────────
if ($action === 'marcar_todas') {
    Csrf::validate(Sanitize::fromPost(['csrf_token'])['csrf_token'] ?? '');
    Notification::markAllRead($usuarioId);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Todas as notificações foram marcadas como lidas.'];
    header('Location: ?module=notificacoes&action=index');
    exit;
}

// ── GET: index ───────────────────────────────────────────
if ($action === 'index') {
    $page   = max(1, (int)(Sanitize::fromGet(['pag'])['pag'] ?: 1));
    $result = Notification::listPaginated($usuarioId, $page);

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    $pageTitle    = 'Notificações';
    $activeModule = 'notificacoes';
    $unreadCount  = Notification::unreadCount($usuarioId);

    ob_start();
    include VIEWS . '/notificacoes/index.php';
    $content = ob_get_clean();
    include VIEWS . '/layout/base.php';
    exit;
}

http_response_code(404);
include VIEWS . '/errors/404.php';
