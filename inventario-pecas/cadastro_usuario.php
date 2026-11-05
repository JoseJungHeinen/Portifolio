<?php
declare(strict_types=1);

session_start();
require 'conexao_inventario.php';

if (empty($_SESSION['inventario_login'])) {
    header('Location: login.php');
    exit;
}

$usuarioLogado = $_SESSION['inventario_login'];

if (($usuarioLogado['perfil'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$erro = '';
$msg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $nome    = trim($_POST['nome']    ?? '');
    $senha   = $_POST['senha']        ?? '';
    $perfil  = $_POST['perfil']       ?? 'gestor'; // admin | gestor
    $ativo   = isset($_POST['ativo']) ? 1 : 0;

    if ($usuario === '' || $nome === '' || $senha === '') {
        $erro = 'Preencha usuário, nome e senha.';
    } else {
        $hash = password_hash($senha, PASSWORD_DEFAULT);

        $sql = "INSERT INTO inv_usuarios (usuario, senha_hash, nome, perfil, ativo, criado_em)
                VALUES (:usuario, :senha_hash, :nome, :perfil, :ativo, NOW())";
        $stmt = $pdo->prepare($sql);
        try {
            $stmt->execute([
                ':usuario'    => $usuario,
                ':senha_hash' => $hash,
                ':nome'       => $nome,
                ':perfil'     => $perfil,
                ':ativo'      => $ativo,
            ]);
            $msg = 'Usuário cadastrado com sucesso.';
        } catch (PDOException $e) {
            $erro = 'Erro ao cadastrar usuário (talvez usuário já exista).';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Cadastro de usuário - Inventário</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body {
      margin:0;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:#F5F5F7;
    }
    .page {
      min-height:100vh;
      display:flex;
      flex-direction:column;
    }
    header {
      padding:16px 32px;
      border-bottom:1px solid #E0E0E0;
      background:#FFF;
      display:flex;
      align-items:center;
      justify-content:space-between;
    }
    main {
      flex:1;
      padding:24px 32px;
    }
    .card {
      max-width:480px;
      background:#FFF;
      border-radius:12px;
      padding:20px;
      box-shadow:0 8px 16px rgba(15,23,42,0.08);
    }
    .card-title {
      font-size:16px;
      font-weight:600;
      margin-bottom:16px;
    }
    label {
      font-size:14px;
      display:block;
      margin-bottom:4px;
    }
    .input, select {
      width:100%;
      height:36px;
      border-radius:8px;
      border:1px solid #E0E0E0;
      padding:0 10px;
      font-size:14px;
      margin-bottom:10px;
    }
    .btn {
      height:36px;
      padding:0 18px;
      border-radius:999px;
      border:none;
      background:linear-gradient(135deg,#2347C8,#FF1744);
      color:#FFF;
      font-size:14px;
      cursor:pointer;
      font-weight:600;
    }
    .msg-erro { color:#2347C8; margin-bottom:10px; }
    .msg-ok   { color:#07be01; margin-bottom:10px; }
    a.voltar {
      font-size:14px;
      text-decoration:none;
      color:#546E7A;
    }
  </style>
</head>
<body>
<div class="page">
  <header>
    <div>Cadastro de usuário - Inventário</div>
    <a href="dashboard_inventario.php" class="voltar">Voltar ao dashboard</a>
  </header>
  <main>
    <div class="card">
      <div class="card-title">Novo usuário</div>

      <?php if ($erro): ?>
        <div class="msg-erro"><?php echo htmlspecialchars($erro); ?></div>
      <?php endif; ?>

      <?php if ($msg): ?>
        <div class="msg-ok"><?php echo htmlspecialchars($msg); ?></div>
      <?php endif; ?>

      <form method="post">
        <label for="usuario">Usuário*</label>
        <input type="text" id="usuario" name="usuario" class="input" required>

        <label for="nome">Nome*</label>
        <input type="text" id="nome" name="nome" class="input" required>

        <label for="senha">Senha*</label>
        <input type="password" id="senha" name="senha" class="input" required>

        <label for="perfil">Perfil*</label>
        <select id="perfil" name="perfil">
          <option value="gestor">Gestor</option>
          <option value="admin">Admin</option>
        </select>

        <label style="display:flex;align-items:center;gap:6px;margin:8px 0 16px;">
          <input type="checkbox" name="ativo" value="1" checked> Ativo
        </label>

        <button type="submit" class="btn">Salvar</button>
      </form>
    </div>
  </main>
</div>
</body>
</html>
