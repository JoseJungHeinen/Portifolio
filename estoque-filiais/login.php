<?php
require 'includes/db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $senha = $_POST['senha'];

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($senha, $user['senha'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['nome'] = $user['nome'];
        $_SESSION['cargo'] = $user['cargo'];
        $_SESSION['filial_id'] = $user['filial_id'];
        header("Location: index.php");
        exit;
    } else {
        $erro = "Credenciais inválidas.";
    }
}
?>
<?php include 'includes/header.php'; ?>
<div class="login-screen">
    <div class="login-card">
        <div class="card">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                            <img src="assets/img/logo.svg" alt="Logo do Sistema" 
                                    class="img-fluid" style="max-width: 120px; border-radius: 15px; box-shadow: 0 6px 14px rgba(0,0,0,0.1);">
                </div>
                <h3 class="card-title text-center">Login</h3>
                <p class="text-center text-muted small mb-4">SISTEMA DE GESTÃO DE ESTOQUE</p>
                
                <?php if (isset($erro)): ?>
                    <div class="alert alert-danger py-2 text-center small" style="border-radius: 10px;"><?php echo $erro; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="mb-3">
                        <label>E-mail</label>
                        <input type="email" name="email" class="form-control" placeholder="seu@email.com" required>
                    </div>
                    <div class="mb-4">
                        <label>Senha</label>
                        <input type="password" name="senha" class="form-control" placeholder="••••••••" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Entrar no Sistema</button>
                </form>
                
                <div class="mt-4 p-3 bg-light rounded-3 small text-muted">
                    <strong>Acesso rápido:</strong><br>
                    Admin: admin@sistema.com / 123456<br>
                    Filial: filial1@sistema.com / 123456
                </div>
            </div>
        </div>
        <div class="text-center mt-4 text-muted small">
            Versão 3.0.1 &copy; <?php echo date('Y'); ?>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
