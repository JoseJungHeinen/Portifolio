<?php
declare(strict_types=1);

session_start();
require 'conexao_inventario.php';

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $senha   = trim($_POST['senha']   ?? '');

    if ($usuario === '' || $senha === '') {
    $erro = 'Informe usuário e senha.';
} else {
    $sql = "SELECT id, usuario, senha_hash, nome, perfil
            FROM inv_usuarios
            WHERE usuario = :usuario AND ativo = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':usuario' => $usuario,   // <-- parâmetro OBRIGATÓRIO
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify($senha, $row['senha_hash'])) {
        $erro = 'Usuário ou senha inválidos.';
    } else {
        $_SESSION['inventario_login'] = [
            'id'      => $row['id'],
            'usuario' => $row['usuario'],
            'nome'    => $row['nome'],
            'perfil'  => $row['perfil'],
        ];
        header('Location: dashboard_inventario.php');
        exit;
    }
}
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Inventário - Entrar no Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --red: #2347C8;
      --red-dark: #B71C1C;
      --gray-25: #FCFCFD;
      --gray-50: #F5F5F7;
      --gray-100: #ECEFF1;
      --gray-200: #E0E0E0;
      --gray-400: #B0BEC5;
      --gray-800: #263238;
      --white: #FFFFFF;
      --radius-card: 18px;
      --radius-input: 14px;
      --shadow-soft: 0 12px 25px rgba(15,23,42,0.25);
    }
    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      background:
        radial-gradient(circle at top, #FFFFFF 0, #E3F2FD 50%, #CFD8DC 100%);
      color: var(--gray-800);
      -webkit-font-smoothing: antialiased;
    }

    .screen {
      min-height: 100vh;
      padding: 48px 24px 16px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 32px;
    }

    .logo-box {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
    }
    .logo-box img {
      width: 90px;
      height: 90px;
      border-radius: 24px;
      object-fit: contain;
      background: rgba(255,255,255,0.96);
      box-shadow: 0 6px 14px rgba(0,0,0,0.25);
    }
    .title {
      font-size: 22px;
      font-weight: 700;
      letter-spacing: .28em;
      text-transform: uppercase;
      text-align: center;
    }

    .subtitle {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .16em;
      color: var(--gray-400);
    }

    .card {
      width: 100%;
      max-width: 420px;
      background: rgba(255,255,255,0.96);
      border-radius: var(--radius-card);
      padding: 24px 22px 20px;
      box-shadow: var(--shadow-soft);
      display: flex;
      flex-direction: column;
      gap: 16px;
      border: 1px solid rgba(148,163,184,0.25);
      backdrop-filter: blur(4px);
    }

    .field-label {
      font-size: 13px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .08em;
      margin-bottom: 4px;
      color: var(--gray-800);
    }

    .input {
      height: 52px;
      border-radius: var(--radius-input);
      border: 1px solid var(--gray-200);
      padding: 0 14px;
      font-size: 15px;
      background: #FAFAFA;
      width: 100%;
      transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }
    .input::placeholder { color: var(--gray-400); }
    .input:focus {
      outline: none;
      border-color: var(--red);
      box-shadow: 0 0 0 2px rgba(35,71,200,0.18);
      background: #FFFFFF;
    }

    .btn-primary {
      width: 100%;
      max-width: 420px;
      height: 54px;
      border-radius: 18px;
      border: none;
      background: linear-gradient(135deg, var(--red-dark), var(--red));
      color: var(--white);
      font-size: 15px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .12em;
      margin-top: 16px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      box-shadow: 0 12px 24px rgba(183,28,28,0.55);
      transition: box-shadow 0.15s ease, transform 0.05s ease, filter 0.1s;
    }
    .btn-primary:hover {
      box-shadow: 0 14px 28px rgba(183,28,28,0.65);
      transform: translateY(-1px);
      filter: brightness(1.03);
    }
    .btn-primary:active {
      transform: translateY(0);
      box-shadow: 0 8px 16px rgba(183,28,28,0.45);
    }

    .version {
      margin-top: auto;
      font-size: 12px;
      color: var(--gray-400);
      text-align: center;
    }

    .erro {
      color: #C62828;
      font-size: 13px;
      text-align: center;
      background: #FFEBEE;
      border-radius: 999px;
      padding: 6px 14px;
      border: 1px solid #FFCDD2;
    }

    @media (min-width: 768px) {
      .screen {
        padding-top: 72px;
      }
    }
    .voltar {
      display: inline-block;
      margin-top: 18px;
      color: var(--gray-400);
      font-size: 13px;
      text-decoration: none;
    }

    .voltar:hover { color: var(--gray-800); }
  </style>
</head>
<body>
  <div class="screen">
    <div class="logo-box">
      <img src="img/logo.svg" alt="Exemplo">
      <div class="title">INVENTÁRIO</div>
      <div class="subtitle">Dashboard · acesso restrito</div>
    </div>

    <?php if (!empty($erro)): ?>
      <div class="erro"><?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>

    <form class="card" id="login-form" method="post" autocomplete="off">
      <div>
        <div class="field-label">Usuário</div>
        <input
          class="input"
          id="usuario"
          name="usuario"
          type="text"
          required
          autofocus>
      </div>
      <div>
        <div class="field-label">Senha</div>
        <input
          class="input"
          id="senha"
          name="senha"
          type="password"
          required>
      </div>
    </form>

    <button class="btn-primary" type="submit" form="login-form">
      Entrar
    </button>

    <a class="voltar" href="index.php">← Voltar</a>

    <div class="version">v1.0.0</div>
  </div>
</body>
</html>
