<?php
require_once __DIR__ . '/includes/federated_auth.php';
session_start();

if (!empty($_SESSION['portal_logado'])) {
    header('Location: index.php');
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if ($email === '' || $senha === '') {
        $erro = 'Preencha o e-mail e a senha.';
    } else {
        $sistemas  = carregar_todos_sistemas();
        $resultado = autenticar_federado($email, $senha, $sistemas, $instanciasCorrespondentes);

        $temAlgumaCorrespondencia = in_array(true, $resultado, true);

        if ($temAlgumaCorrespondencia) {
            $resultado = sincronizar_senha_federada($email, $senha, $sistemas, $resultado);

            // Sistemas que passaram a bater só depois da sincronização ainda não têm
            // instância registrada — usa a primeira instância de cada um como padrão.
            foreach ($resultado as $idSistema => $status) {
                if ($status === true && !isset($instanciasCorrespondentes[$idSistema])) {
                    $instanciasCorrespondentes[$idSistema] = $sistemas[$idSistema]['instancias'][0]['id'] ?? 'principal';
                }
            }

            session_regenerate_id(true);
            $_SESSION['portal_logado']    = true;
            $_SESSION['portal_email']     = $email;
            $_SESSION['portal_senha']     = $senha;
            $_SESSION['portal_sistemas']  = $resultado;
            $_SESSION['portal_instancias'] = $instanciasCorrespondentes;
            $_SESSION['portal_is_admin']  = verificar_admin_federado($email, $senha, $sistemas);

            header('Location: index.php');
            exit;
        }

        $erro = 'E-mail ou senha não conferem em nenhum sistema.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Entrar — Portal Único</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/design-system.css">
<style>
body{display:flex;align-items:center;justify-content:center;padding:24px}

.auth-card{width:100%;max-width:420px;background:var(--color-surface);border-radius:16px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.16)}

.auth-header{background:var(--color-dark);padding:var(--space-7) var(--space-6) var(--space-6);text-align:center}
.auth-logo{width:64px;height:64px;border-radius:16px;margin:0 auto var(--space-4);display:flex;align-items:center;justify-content:center;overflow:hidden;box-shadow:0 6px 20px rgba(35,71,200,.35)}
.auth-logo img{width:100%;height:100%;object-fit:cover}
.auth-title{font-family:var(--font-bold);font-size:var(--text-h2);color:#fff}
.auth-title .accent{color:var(--color-primary)}
.auth-tagline{font-family:var(--font-regular);font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.5);margin-top:var(--space-2)}

.auth-body{padding:var(--space-6)}
.field-icon-group{position:relative;margin-bottom:var(--space-4)}
.field-icon-group .form-input{padding-left:48px}
.field-icon{position:absolute;left:0;top:26px;width:40px;height:40px;display:flex;align-items:center;justify-content:center;color:var(--text-muted);border-right:1px solid var(--color-gray-light)}
.field-icon svg{width:18px;height:18px}
.toggle-senha{position:absolute;right:1px;top:25px;width:42px;height:38px;display:flex;align-items:center;justify-content:center;background:var(--color-bg);border:none;border-radius:0 4px 4px 0;color:var(--text-muted);cursor:pointer}
.toggle-senha svg{width:17px;height:17px}

.btn-entrar{width:100%;height:46px;justify-content:center;margin-top:var(--space-2);font-size:var(--text-body-sm);text-transform:uppercase;letter-spacing:.04em}

.auth-footer{background:var(--color-bg);border-top:1px solid var(--color-gray-light);padding:var(--space-4);text-align:center;font-family:var(--font-regular);font-size:11px;color:var(--text-muted)}
</style>
</head>
<body>
<div class="auth-card">
    <div class="auth-header">
        <div class="auth-logo"><img src="assets/img/logo.svg" alt="Portal Único"></div>
        <h1 class="auth-title">Portal <span class="accent">Único</span></h1>
        <div class="auth-tagline">Central de acessos</div>
    </div>

    <div class="auth-body">
        <?php if ($erro): ?>
        <div class="alert alert--danger" style="margin-bottom:var(--space-5)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= htmlspecialchars($erro) ?></span>
        </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="field-icon-group">
                <label class="form-label" for="email">E-mail</label>
                <span class="field-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </span>
                <input class="form-input" type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="seu@email.com" autocomplete="username" required>
            </div>

            <div class="field-icon-group">
                <label class="form-label" for="senha">Senha</label>
                <span class="field-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </span>
                <input class="form-input" type="password" id="senha" name="senha" placeholder="••••••••" autocomplete="current-password" required>
                <button type="button" class="toggle-senha" onclick="alternarSenha()" aria-label="Mostrar senha">
                    <svg id="icone-olho" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>

            <button type="submit" class="btn btn-primary btn-entrar">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                Entrar
            </button>
        </form>
    </div>

    <div class="auth-footer">Portal Único © <?= date('Y') ?> — Todos os direitos reservados</div>
</div>

<script>
function alternarSenha() {
    const campo = document.getElementById('senha');
    const mostrando = campo.type === 'text';
    campo.type = mostrando ? 'password' : 'text';
}
</script>
</body>
</html>
