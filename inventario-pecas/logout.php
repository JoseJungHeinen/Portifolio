<?php
session_start();

// Conferência sem inventário: pode ter sido aberta por um gestor logado no
// dashboard, então sai só da conferência e não derruba o login dele.
if (($_GET['de'] ?? '') === 'sem_inventario') {
    unset($_SESSION['inventario']);
    header('Location: login.php');
    exit;
}

session_unset();
session_destroy();
header('Location: login.php');
exit;
