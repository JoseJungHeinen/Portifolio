<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/database.php';

function usuarioLogado(): bool {
    return isset($_SESSION['usuario']);
}
function usuario(): array {
    return $_SESSION['usuario'] ?? [];
}
function exigirLogin(string $base = '..'): void {
    if (!usuarioLogado()) {
        header("Location: {$base}/login.php");
        exit;
    }
}
function logout(): void {
    $_SESSION = [];
    session_destroy();
    header('Location: ../login.php');
    exit;
}
