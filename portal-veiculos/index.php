<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';

session_start();

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cpf   = isset($_POST['cpf']) ? trim($_POST['cpf']) : '';
    $senha = isset($_POST['senha']) ? $_POST['senha'] : '';

    if ($cpf === '' || $senha === '') {
        $erro = 'Informe CPF e senha.';
    } else {
        $stmt = $mysqli->prepare("
    SELECT id, cpf, nome, telefone, senha_hash, is_admin, is_gestor, primeiro_acesso, id_loja, setor
      FROM usuarios
     WHERE cpf = ?
     LIMIT 1
");

        $stmt->bind_param("s", $cpf);
        $stmt->execute();
        $res  = $stmt->get_result();
        $user = $res->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $erro = 'CPF ou senha inválidos.';
        } else {
            // Aceita o hash atual (password_hash) e, só para migração, senhas
            // antigas em texto puro ou MD5 — que são regravadas com password_hash
            // no primeiro login bem-sucedido.
            $senhaOk     = password_verify($senha, (string)$user['senha_hash']);
            $senhaLegada = false;
            if (!$senhaOk && (hash_equals((string)$user['senha_hash'], $senha) || hash_equals((string)$user['senha_hash'], md5($senha)))) {
                $senhaOk     = true;
                $senhaLegada = true;
            }
            if (!$senhaOk) {
                $erro = 'CPF ou senha inválidos.';
            } else {
                if ($senhaLegada) {
                    $novoHash = password_hash($senha, PASSWORD_DEFAULT);
                    $idUp = (int)$user['id'];
                    $up = $mysqli->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ? LIMIT 1');
                    $up->bind_param('si', $novoHash, $idUp);
                    $up->execute();
                    $up->close();
                }
                session_regenerate_id(true);
                $_SESSION['logado']          = true;
$_SESSION['usuario_id']      = (int)$user['id'];
$_SESSION['cpf']             = $user['cpf'];
$_SESSION['nome']            = $user['nome'];
$_SESSION['is_admin']        = (bool)$user['is_admin'];
$_SESSION['primeiro_acesso'] = (int)$user['primeiro_acesso'];
$_SESSION['id_loja']         = (int)$user['id_loja'];
$_SESSION['setor']           = $user['setor'];
$_SESSION['is_gestor']       = (int)$user['is_gestor'];

// estes dois são os que o ver_transferencia espera
$_SESSION['nome_usuario']    = $user['nome'];
$_SESSION['tel_usuario']     = $user['telefone'] ?? '';


                // BUSCA O NOME DA LOJA
                $id_loja  = (int)$user['id_loja'];
                $nomeLoja = null;

                if ($id_loja > 0) {
                    $stmtLoja = $mysqli->prepare("
                        SELECT nome 
                          FROM lojas 
                         WHERE id = ? 
                         LIMIT 1
                    ");
                    $stmtLoja->bind_param('i', $id_loja);
                    $stmtLoja->execute();
                    $resLoja = $stmtLoja->get_result();
                    $rowLoja = $resLoja->fetch_assoc();
                    $stmtLoja->close();

                    if ($rowLoja) {
                        $nomeLoja = $rowLoja['nome'];
                    }
                }

                $_SESSION['loja'] = $nomeLoja;

                if (!empty($user['primeiro_acesso'])) {
                    header('Location: trocar_senha.php');
                    exit;
                }

                header('Location: menu.php');
                exit;
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Login - Ferramentas de Vendas</title>
    <link rel="icon" type="image/png" href="/img/logo.svg?v=1">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{
            font-family:system-ui,-apple-system,BlinkMacSystemFont,"Futura","Segoe UI",sans-serif;
            background:#ffffff;min-height:100vh;display:flex;
            align-items:center;justify-content:center;color:#333333;
        }
        .page-container{
            background:#f4f4f4;padding:2.5rem 3rem;border-radius:12px;
            box-shadow:0 10px 25px rgba(0,0,0,0.12);width:100%;max-width:420px;
        }
        .logo{text-align:center;margin-bottom:1.5rem;}
        .logo img{max-width:160px;height:auto;margin-bottom:.4rem;}
        .page-title{
            text-align:center;margin-bottom:1.5rem;
            font-size:1.3rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
            color:#444;
        }
        .form-group{margin-bottom:1.1rem;}
        label{display:block;margin-bottom:.35rem;color:#555;font-weight:600;font-size:.9rem;letter-spacing:.03em;}
        input{
            width:100%;padding:10px 14px;border:1px solid #c0c0c0;border-radius:6px;
            font-size:.95rem;background:#fff;color:#333;
        }
        .btn-primary-custom{
            width:100%;padding:11px;background:#2347c8;color:#ffffff;border:none;
            border-radius:999px;font-size:.95rem;font-weight:700;cursor:pointer;
            letter-spacing:.06em;text-transform:uppercase;
            box-shadow:0 6px 16px rgba(35,71,200,0.45);
        }
        .message{margin-top:.9rem;padding:.8rem;border-radius:6px;font-size:.85rem;}
        .error{background:#ffd6d6;color:#b00000;border:1px solid #2347c8;}

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

    </style>
</head>
<body>
<div class="page-container">
    <div class="logo">
        <img src="img/logo.svg" alt="Logo Exemplo">
    </div>
    <div class="page-title">Acessar sistema</div>

    <?php if ($erro !== ''): ?>
        <div class="message error">
            <?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <div class="form-group">
            <label>CPF</label>
            <input type="text" name="cpf"
                   value="<?php echo isset($_POST['cpf']) ? htmlspecialchars($_POST['cpf'], ENT_QUOTES, 'UTF-8') : ''; ?>">
        </div>

        <div class="form-group">
            <label>Senha</label>
            <input type="password" name="senha">
        </div>

        <button type="submit" class="btn-primary-custom">
            Entrar
        </button>
    </form>
</div>
<div class="app-version-label">
    <?php echo VERSAO_INDEX; ?>
</div>

</body>
</html>

</body>
</html>
