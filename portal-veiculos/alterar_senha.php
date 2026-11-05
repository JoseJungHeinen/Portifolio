<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';
session_start();
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php');
    exit;
}


$erro = '';
$sucesso = '';
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($user_id <= 0) {
    header('Location: gerenciar_usuarios.php');
    exit;
}

// busca usuário
$stmt = $mysqli->prepare('SELECT id, cpf, nome, email FROM usuarios WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$resultado = $stmt->get_result();
$usuario = $resultado->fetch_assoc();
$stmt->close();

if (!$usuario) {
    header('Location: gerenciar_usuarios.php');
    exit;
}

// processar alteração de senha
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nova_senha      = isset($_POST['nova_senha']) ? trim($_POST['nova_senha']) : '';
    $confirmar_senha = isset($_POST['confirmar_senha']) ? trim($_POST['confirmar_senha']) : '';

    if ($nova_senha === '' || $confirmar_senha === '') {
        $erro = 'Preencha os dois campos de senha.';
    } elseif ($nova_senha !== $confirmar_senha) {
        $erro = 'As senhas não conferem.';
    } elseif (strlen($nova_senha) < 6) {
        $erro = 'A nova senha deve ter pelo menos 6 caracteres.';
    } else {
        $hash = password_hash($nova_senha, PASSWORD_DEFAULT);

        $stmt = $mysqli->prepare('UPDATE usuarios SET senha_hash = ?, primeiro_acesso = 0 WHERE id = ?');
        $stmt->bind_param('si', $hash, $user_id);
        if ($stmt->execute()) {
            $sucesso = 'Senha alterada com sucesso.';
        } else {
            $erro = 'Erro ao atualizar a senha.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Alterar senha</title>
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{
            font-family:system-ui,-apple-system,sans-serif;
            background:#f5f5f5;min-height:100vh;
            display:flex;align-items:center;justify-content:center;
        }
        .card{
            background:#ffffff;border-radius:14px;
            box-shadow:0 10px 25px rgba(0,0,0,0.10);
            padding:20px 25px;max-width:420px;width:100%;
        }
        .card h1{
            font-size:1.3rem;color:#2347c8;margin-bottom:10px;
        }
        .card p.info{
            font-size:.9rem;color:#666;margin-bottom:12px;
        }
        .campo{
            margin-bottom:10px;
        }
        .campo label{
            display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;
        }
        .campo input{
            width:100%;padding:8px 10px;border:1px solid #ccc;
            border-radius:6px;font-size:.9rem;
        }
        .mensagem-erro{
            color:#c0392b;font-size:.9rem;margin-bottom:8px;
        }
        .mensagem-ok{
            color:#27ae60;font-size:.9rem;margin-bottom:8px;
        }
        .btn-salvar{
            width:100%;padding:10px 14px;border:none;
            border-radius:999px;background:#2347c8;
            color:#fff;font-weight:700;cursor:pointer;
        }
        .btn-salvar:hover{background:#d40000;}
        .link-voltar{
            display:inline-block;margin-top:10px;font-size:.85rem;
            text-decoration:none;color:#555;
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
<div class="card">
    <h1><i class="fas fa-key"></i> Alterar senha</h1>
    <p class="info">
        Usuário: <strong><?php echo htmlspecialchars($usuario['nome'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
        CPF: <?php echo htmlspecialchars($usuario['cpf'], ENT_QUOTES, 'UTF-8'); ?>
    </p>

    <?php if ($erro): ?>
        <div class="mensagem-erro"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($sucesso): ?>
        <div class="mensagem-ok"><?php echo htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="campo">
            <label for="nova_senha">Nova senha</label>
            <input type="password" name="nova_senha" id="nova_senha" required>
        </div>
        <div class="campo">
            <label for="confirmar_senha">Confirmar senha</label>
            <input type="password" name="confirmar_senha" id="confirmar_senha" required>
        </div>
        <button type="submit" class="btn-salvar">Salvar nova senha</button>
    </form>

    <a href="gerenciar_usuarios.php" class="link-voltar">&larr; Voltar para usuários</a>
</div>
<div class="app-version-label">
    <?php echo VERSAO_ALTERAR_SENHA; ?>
</div>
</body>
</html>
