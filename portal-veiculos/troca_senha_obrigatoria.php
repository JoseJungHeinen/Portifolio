<?php
require_once __DIR__ . '/config_database.php';
require_once __DIR__ . '/config_versao.php';
session_start();

// só entra aqui se estiver logado e marcado como primeiro acesso
if (empty($_SESSION['logado']) || empty($_SESSION['primeiro_acesso'])) {
    header('Location: index.php');
    exit;
}


$erro = '';
$nome = $_SESSION['nome'] ?? 'Usuário';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nova_senha      = isset($_POST['nova_senha']) ? trim($_POST['nova_senha']) : '';
    $confirmar_senha = isset($_POST['confirmar_senha']) ? trim($_POST['confirmar_senha']) : '';

    if ($nova_senha === '' || $confirmar_senha === '') {
        $erro = 'Preencha todos os campos';
    } elseif ($nova_senha !== $confirmar_senha) {
        $erro = 'As senhas não coincidem';
    } elseif (strlen($nova_senha) < 6) {
        $erro = 'A senha deve ter no mínimo 6 caracteres';
    } else {
        $user_id    = (int)$_SESSION['user_id'];
        $senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);

        $stmt = $mysqli->prepare('UPDATE usuarios SET senha_hash = ?, primeiro_acesso = 0 WHERE id = ?');
        $stmt->bind_param('si', $senha_hash, $user_id);

        if ($stmt->execute()) {
            $stmt->close();
            unset($_SESSION['primeiro_acesso']);
            header('Location: menu.php');
            exit;
        } else {
            $erro = 'Erro ao alterar senha: ' . $stmt->error;
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trocar Senha - Obrigatório</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{
            font-family:system-ui,-apple-system,sans-serif;
            background:#ffffff;min-height:100vh;display:flex;
            align-items:center;justify-content:center;color:#333333;
        }
        .container{
            background:#f4f4f4;padding:2.5rem 3rem;border-radius:12px;
            box-shadow:0 10px 25px rgba(0,0,0,0.12);width:100%;max-width:500px;
        }
        h1{
            text-align:center;margin-bottom:1rem;color:#2347c8;
            text-transform:uppercase;font-size:1.3rem;
        }
        .aviso{
            background:#fff3cd;padding:14px;border-radius:6px;
            margin-bottom:1.5rem;border-left:4px solid #ffc107;
            font-size:.9rem;color:#856404;text-align:center;
            line-height:1.6;
        }
        .aviso strong{color:#2347c8;}
        .form-group{
            margin-bottom:1.2rem;
        }
        label{
            display:block;margin-bottom:.4rem;color:#555;
            font-weight:600;font-size:.9rem;
        }
        input{
            width:100%;padding:10px 14px;border:1px solid #c0c0c0;
            border-radius:6px;font-size:.95rem;background:#fff;
        }
        .btn{
            width:100%;padding:11px;border-radius:999px;border:none;
            font-size:.95rem;font-weight:700;cursor:pointer;
            text-transform:uppercase;transition:all .15s;
        }
        .btn-primary{
            background:#2347c8;color:#fff;
            box-shadow:0 6px 16px rgba(35,71,200,0.45);
        }
        .btn-primary:hover{
            background:#d40000;transform:translateY(-1px);
        }
        .message{
            margin-top:1rem;padding:.8rem;border-radius:6px;
            text-align:center;font-size:.85rem;
        }
        .error{background:#ffd6d6;color:#b00000;border:1px solid #2347c8;}
        .bloqueio{
            margin-top:1.5rem;padding:10px;background:#ffe5e5;
            border-radius:6px;text-align:center;font-size:.8rem;
            color:#dc3545;font-weight:600;
        }
        .app-version-label{
    position:fixed;
    bottom:4px;
    right:8px;
    font-size:10px;
    color:#888;
    opacity:0.6;
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    pointer-events:none;
    z-index:9999;
}
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Primeiro Acesso</h1>
        
        <div class="aviso">
            <strong>Olá, <?php echo htmlspecialchars($nome, ENT_QUOTES, 'UTF-8'); ?>!</strong><br><br>
            Este é seu primeiro acesso. <strong>Por segurança, você DEVE criar uma nova senha antes de continuar.</strong><br>
            Você não poderá acessar o sistema até alterar sua senha temporária.
        </div>

        <form method="post" action="">
            <div class="form-group">
                <label>Nova Senha (mínimo 6 caracteres)</label>
                <input type="password" name="nova_senha" required minlength="6" autofocus>
            </div>
            
            <div class="form-group">
                <label>Confirmar Nova Senha</label>
                <input type="password" name="confirmar_senha" required minlength="6">
            </div>

            <button type="submit" class="btn btn-primary">Confirmar e Continuar</button>

            <?php if ($erro): ?>
                <div class="message error"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </form>

        <div class="bloqueio">
            ⚠️ Não é possível sair desta tela até trocar a senha
        </div>
    </div>

    <script>
        // Bloqueia botão voltar do navegador
        history.pushState(null, null, location.href);
        window.onpopstate = function () {
            history.go(1);
        };
    </script>
    <div class="app-version-label">
    <?php echo VERSAO_TROCA_SENHA_OBRIG; ?>
</div>
</body>
</html>
