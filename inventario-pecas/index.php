<?php
declare(strict_types=1);

/**
 * A raiz do sistema: só a escolha entre os dois públicos.
 *
 * Sem session_start(), sem conexão com o banco, sem lógica. A raiz fazia login
 * de gestor e escondia o caminho dos coletores, que são a maioria dos acessos —
 * agora ela não pertence a nenhum dos dois.
 *
 * A escolha aparece sempre, com ou sem sessão ativa: o mesmo endereço faz
 * sempre a mesma coisa. É por isso que os dez arquivos que redirecionam para cá
 * quando falta sessão não precisaram ser tocados.
 */
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Inventário Exemplo</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require __DIR__ . '/includes/icones.php'; ?>
  <style>
    :root {
      --red: #2347C8;
      --red-dark: #B71C1C;
      --gray-25: #FCFCFD;
      --gray-50: #F5F5F7;
      --gray-100: #ECEFF1;
      --gray-200: #E0E0E0;
      --gray-400: #B0BEC5;
      --gray-600: #546E7A;
      --gray-800: #263238;
      --white: #FFFFFF;
      --radius-card: 18px;
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
      justify-content: center;
    }

    .logo-box { text-align: center; margin-bottom: 36px; }

    .logo-box img {
      width: 96px;
      height: 96px;
      object-fit: contain;
      border-radius: 22px;
    }

    .title {
      margin-top: 14px;
      font-size: 26px;
      font-weight: 800;
      letter-spacing: .22em;
      text-transform: uppercase;
    }

    .subtitle {
      margin-top: 4px;
      font-size: 12px;
      letter-spacing: .16em;
      text-transform: uppercase;
      color: var(--gray-400);
    }

    .escolhas {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      width: 100%;
      max-width: 680px;
    }

    .escolha {
      display: block;
      background: var(--white);
      border-radius: var(--radius-card);
      box-shadow: var(--shadow-soft);
      padding: 28px 24px;
      text-decoration: none;
      color: inherit;
      border-top: 5px solid var(--red);
      transition: transform .15s ease, box-shadow .15s ease;
    }

    .escolha:hover {
      transform: translateY(-3px);
      box-shadow: 0 18px 34px rgba(15,23,42,0.28);
    }

    .escolha:active { transform: translateY(0); }

    .escolha-titulo {
      font-size: 21px;
      font-weight: 800;
      letter-spacing: .04em;
    }

    .escolha-texto {
      margin-top: 8px;
      font-size: 13px;
      line-height: 1.5;
      color: var(--gray-600);
    }

    .escolha-nota {
      margin-top: 14px;
      font-size: 11px;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: var(--gray-400);
    }

    .version {
      margin-top: 32px;
      font-size: 11px;
      color: var(--gray-400);
    }

    @media (max-width: 640px) {
      .escolhas { grid-template-columns: 1fr; }
      .screen   { padding: 32px 18px 16px; }
    }
  </style>
</head>
<body>
  <div class="screen">
    <div class="logo-box">
      <img src="img/logo.svg" alt="Exemplo">
      <div class="title">INVENTÁRIO</div>
      <div class="subtitle">Escolha por onde entrar</div>
    </div>

    <div class="escolhas">
      <a class="escolha" href="login_gestor.php">
        <div class="escolha-titulo">Dashboard</div>
        <div class="escolha-texto">
          Gerar inventário, relatórios, tomada de decisão e encerramento de rodada.
        </div>
        <div class="escolha-nota">Pede usuário e senha</div>
      </a>

      <a class="escolha" href="login.php">
        <div class="escolha-titulo">Coletores</div>
        <div class="escolha-texto">
          Contagem, recontagem e conferência no chão do depósito.
        </div>
        <div class="escolha-nota">Escolha o inventário e o coletor</div>
      </a>
    </div>

    <div class="version">v1.0.0</div>
  </div>
</body>
</html>
