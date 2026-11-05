<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) != 'login.php') {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestão de Estoque</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php if (isset($_SESSION['user_id'])): ?>
        <nav class="col-md-2 sidebar d-none d-md-block">
            <div class="text-center mt-3 mb-4">
                <div class="text-center mb-4">
                    <img src="assets/img/logo.svg" alt="Logo do Sistema"
                             class="img-fluid" style="max-width: 120px; border-radius: 15px; box-shadow: 0 6px 14px rgba(0,0,0,0.1);">
            </div>
                <h4 class="mt-2">Sistema</h4>
            </div>
            <hr>
            <a href="index.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a>
            <a href="estoque.php"><i class="fas fa-boxes me-2"></i> Estoque</a>
            <a href="solicitacoes.php"><i class="fas fa-clipboard-list me-2"></i> Solicitações</a>
            <?php if ($_SESSION['cargo'] != 'filial'): ?>
            <a href="compras.php"><i class="fas fa-shopping-cart me-2"></i> Ordens de Compra</a>
            <a href="produtos.php"><i class="fas fa-cube me-2"></i> Produtos</a>
            <a href="fornecedores.php"><i class="fas fa-truck me-2"></i> Fornecedores</a>
            <?php if ($_SESSION['cargo'] == 'admin'): ?>
            <a href="usuarios.php"><i class="fas fa-users me-2"></i> Usuários</a>
            <a href="auditoria.php"><i class="fas fa-history me-2"></i> Auditoria</a>
            <?php endif; ?>
            <?php endif; ?>
            <hr>
            <a href="logout.php" class="text-danger"><i class="fas fa-sign-out-alt me-2"></i> Sair (<?php echo $_SESSION['nome']; ?>)</a>
        </nav>
        <main class="col-md-10 content">
        <?php else: ?>
        <main class="col-12">
        <?php endif; ?>
