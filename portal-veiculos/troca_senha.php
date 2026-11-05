<?php
session_start();
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';

if (empty($_SESSION['logado'])) {
    header('Location: index.php');
    exit;
}

$cpf         = $_SESSION['cpf'] ?? '';
$userId      = (int)($_SESSION['usuario_id'] ?? $_SESSION['user_id'] ?? 0);
$nomeUsuario = $_SESSION['nome'] ?? 'Usuário';
$erro        = '';
$debug       = '';

// Mostra banco e usuário MySQL conectado (só em GET também)
$resDB = $mysqli->query("SELECT DATABASE() as db, USER() as usr");
$rowDB = $resDB ? $resDB->fetch_assoc() : [];
$debug .= "Banco conectado: " . ($rowDB['db'] ?? '?') . "<br>";
$debug .= "Usuário MySQL: "   . ($rowDB['usr'] ?? '?') . "<br>";
$debug .= "CPF sessão: '{$cpf}' | userId sessão: {$userId}<br>";

// Busca o registro atual
$where = $cpf !== '' ? "cpf = '" . $mysqli->real_escape_string($cpf) . "'" : "id = {$userId}";
$resU  = $mysqli->query("SELECT id, cpf, primeiro_acesso FROM usuarios WHERE {$where} LIMIT 1");
$rowU  = $resU ? $resU->fetch_assoc() : null;
$debug .= "Registro encontrado: " . ($rowU ? "id={$rowU['id']} cpf={$rowU['cpf']} primeiro_acesso={$rowU['primeiro_acesso']}" : "NÃO ENCONTROU") . "<br>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nova     = $_POST['nova_senha']     ?? '';
    $confirma = $_POST['confirma_senha'] ?? '';

    if ($nova === '' || $confirma === '') {
        $erro = 'Preencha os dois campos.';
    } elseif (strlen($nova) < 6) {
        $erro = 'A senha deve ter no mínimo 6 caracteres.';
    } elseif ($nova !== $confirma) {
        $erro = 'As senhas não coincidem.';
    } else {
        $hash    = password_hash($nova, PASSWORD_DEFAULT);
        $hashEsc = $mysqli->real_escape_string($hash);

        if ($rowU) {
            $idReal  = (int)$rowU['id'];
            $sql     = "UPDATE usuarios SET senha_hash = '{$hashEsc}', primeiro_acesso = 0 WHERE id = {$idReal}";
            $ok      = $mysqli->query($sql);
            $afetados = $mysqli->affected_rows;
            $erroSQL  = $mysqli->error;
            $debug   .= "UPDATE por id={$idReal}: ok=" . ($ok ? 'SIM' : 'NÃO') . " affected={$afetados} erro='{$erroSQL}'<br>";

            if ($ok && $afetados >= 0) {
                // Confirma no banco
                $res2 = $mysqli->query("SELECT primeiro_acesso FROM usuarios WHERE id = {$idReal} LIMIT 1");
                $row2 = $res2 ? $res2->fetch_assoc() : null;
                $debug .= "primeiro_acesso após UPDATE: " . ($row2['primeiro_acesso'] ?? '?') . "<br>";

                if ($row2 && (int)$row2['primeiro_acesso'] === 0) {
                    $_SESSION['primeiro_acesso'] = 0;
                    header('Location: menu.php');
                    exit;
                } else {
                    $erro = 'UPDATE executou mas valor não mudou no banco.';
                }
            } else {
                $erro = 'UPDATE falhou: ' . $erroSQL;
            }
        } else {
            $erro = 'Usuário não encontrado no banco com CPF/ID da sessão.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Trocar Senha</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:system-ui,sans-serif;background:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;color:#333}
        .page-container{background:#f4f4f4;padding:2.5rem 3rem;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,.12);width:100%;max-width:460px}
        .logo{text-align:center;margin-bottom:1.2rem}
        .logo img{max-width:140px;height:auto}
        .page-title{text-align:center;margin-bottom:.4rem;font-size:1.25rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#444}
        .page-sub{text-align:center;font-size:.85rem;color:#888;margin-bottom:1.5rem}
        .info-box{background:#fff3cd;padding:12px 14px;border-radius:8px;border-left:4px solid #ffc107;font-size:.83rem;color:#856404;margin-bottom:1.5rem;line-height:1.5}
        .form-group{margin-bottom:1.1rem}
        label{display:block;margin-bottom:.35rem;color:#555;font-weight:600;font-size:.88rem}
        input[type="password"]{width:100%;padding:10px 14px;border:1px solid #c0c0c0;border-radius:6px;font-size:.95rem;background:#fff;color:#333;transition:border-color .15s}
        input[type="password"]:focus{outline:none;border-color:#2347c8;box-shadow:0 0 0 3px rgba(35,71,200,.12)}
        .requisitos{font-size:.75rem;color:#888;margin-top:.3rem}
        .btn-primary{width:100%;padding:11px;background:#2347c8;color:#fff;border:none;border-radius:999px;font-size:.95rem;font-weight:700;cursor:pointer;letter-spacing:.06em;text-transform:uppercase;box-shadow:0 6px 16px rgba(35,71,200,.4);transition:all .15s}
        .btn-primary:hover{background:#d40000;transform:translateY(-1px)}
        .message{margin-top:1rem;padding:.9rem;border-radius:8px;font-size:.88rem;text-align:center}
        .error{background:#ffd6d6;color:#b00000;border:1px solid #2347c8}
        .debug-box{margin-top:1rem;padding:10px 14px;background:#1e1e1e;color:#00ff88;border-radius:6px;font-size:.78rem;font-family:monospace;line-height:1.8}
        .app-version-label{position:fixed;bottom:4px;right:8px;font-size:10px;color:#888;opacity:.6;pointer-events:none;z-index:9999}
    </style>
</head>
<body>
<div class="page-container">
    <div class="logo"><img src="img/logo.svg" alt="Logo Exemplo"></div>
    <div class="page-title">Criar nova senha</div>
    <div class="page-sub">Olá, <?php echo htmlspecialchars($nomeUsuario, ENT_QUOTES, 'UTF-8'); ?></div>
    <div class="info-box">⚠️ Este é seu <strong>primeiro acesso</strong>. Por segurança, você precisa criar uma senha pessoal antes de continuar.</div>
    <form method="post" action="trocar_senha.php">
        <div class="form-group">
            <label>Nova senha</label>
            <input type="password" name="nova_senha" required autofocus>
            <div class="requisitos">Mínimo de 6 caracteres.</div>
        </div>
        <div class="form-group">
            <label>Confirmar nova senha</label>
            <input type="password" name="confirma_senha" required>
        </div>
        <button type="submit" class="btn-primary">Salvar e entrar</button>
        <?php if ($erro !== ''): ?>
            <div class="message error"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div class="debug-box"><?php echo $debug; ?></div>
    </form>
</div>
<div class="app-version-label"><?php echo defined('VERSAO_TROCA_SENHA') ? VERSAO_TROCA_SENHA : ''; ?></div>
</body>
</html>