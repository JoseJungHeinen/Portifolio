<?php
require_once 'config/session.php';

if (usuarioLogado()) {
    header('Location: pages/dashboard.php');
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = trim($_POST['senha'] ?? '');

    if ($email === '' || $senha === '') {
        $erro = 'Preencha o e-mail e a senha.';
    } else {
        try {
            $pdo = db();

            $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = ? AND ativo = 1 LIMIT 1');
            $stmt->execute([$email]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($u && password_verify($senha, $u['senha'])) {
                unset($u['senha']);
                $_SESSION['usuario'] = $u;
                header('Location: pages/dashboard.php');
                exit;
            } else {
                $erro = 'E-mail ou senha incorretos.';
            }

        } catch (PDOException $e) {
            $erro = 'Erro no banco: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | Exemplo DevSystems</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Epilogue:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css">
  
</head>
<body class="bg-surface font-body text-on-surface antialiased overflow-hidden">
<main class="relative min-h-screen w-full flex items-center justify-center p-6 precision-bg">
  <div class="absolute inset-0 z-0 overflow-hidden">
    <div class="absolute -top-1/4 -right-1/4 w-[600px] h-[600px] bg-primary/5 rounded-full blur-[120px]"></div>
    <div class="absolute top-1/2 left-0 w-full h-[1px] bg-gradient-to-r from-transparent via-outline-variant/20 to-transparent"></div>
  </div>
  <div class="relative z-10 w-full max-w-[440px]">
    <div class="glass-panel border-l-2 border-primary p-8 md:p-12 shadow-2xl">
      <div class="mb-12">
        <div class="flex items-center gap-2 mb-2">
          <span class="w-8 h-[2px] bg-primary inline-block"></span>
          <span class="text-primary font-headline font-extrabold tracking-widest text-[10px] uppercase">Exemplo DevSystems</span>
        </div>
        <h1 class="text-4xl font-headline font-extrabold text-on-surface tracking-tight leading-none mb-4">Gestão de<br>Projetos Web</h1>
        <p class="text-secondary font-body text-sm leading-relaxed">Acesse seu painel de projetos e métricas de desempenho.</p>
      </div>
      <?php if ($erro): ?>
      <div class="mb-6 flex items-center gap-2 bg-red-50 border-l-2 border-primary px-4 py-3 text-sm text-red-700">
        <span class="material-symbols-outlined text-sm">error</span> <?= htmlspecialchars($erro) ?>
      </div>
      <?php endif; ?>
      <form method="POST" class="space-y-6">
        <div class="space-y-1.5">
          <label class="text-[10px] font-label font-bold uppercase tracking-wider text-secondary" for="email">E-mail de Trabalho</label>
          <input id="email" name="email" type="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="nome@empresa.com" class="w-full bg-surface-container border-none focus:ring-0 focus:bg-surface-container-high px-4 py-3 text-on-surface transition-colors">
        </div>
        <div class="space-y-1.5">
          <div class="flex justify-between items-center">
            <label class="text-[10px] font-label font-bold uppercase tracking-wider text-secondary" for="senha">Senha</label>
            <a href="#" class="text-[11px] font-medium text-secondary hover:text-primary transition-colors">Esqueceu a senha?</a>
          </div>
          <input id="senha" name="senha" type="password" required placeholder="••••••••" class="w-full bg-surface-container border-none focus:ring-0 focus:bg-surface-container-high px-4 py-3 text-on-surface transition-colors">
        </div>
        <div class="pt-4">
          <button type="submit" class="group relative w-full overflow-hidden bg-gradient-to-b from-primary-container to-primary py-4 text-on-primary font-headline font-bold uppercase tracking-widest text-sm transition-all active:scale-[0.98]">
            <span class="relative z-10">Entrar</span>
            <div class="absolute inset-0 bg-white/10 opacity-0 group-hover:opacity-100 transition-opacity"></div>
          </button>
        </div>
      </form>
    </div>
  </div>
</main>
<script src="assets/js/app.js"></script>
</body>
</html>
