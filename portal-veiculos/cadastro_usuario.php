<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';
session_start();

// Segurança: só administrador logado cria usuários (antes a página era pública
// e aceitava o campo is_admin vindo do formulário).
if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    exit('Acesso restrito a administradores.');
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cpf       = isset($_POST['cpf']) ? trim($_POST['cpf']) : '';
    $nome      = isset($_POST['nome']) ? trim($_POST['nome']) : '';
    $email     = isset($_POST['email']) ? trim($_POST['email']) : '';
    $telefone  = isset($_POST['telefone']) ? trim($_POST['telefone']) : '';
    $is_admin  = isset($_POST['is_admin']) ? 1 : 0;
    $id_loja   = isset($_POST['id_loja']) ? (int)$_POST['id_loja'] : 0;
    $is_gestor = isset($_POST['is_gestor']) ? 1 : 0;

    // setores (pode vir array)
    $setorPost = $_POST['setor'] ?? [];
    if (!is_array($setorPost)) {
        $setorPost = [$setorPost];
    }
    // remove vazios e duplicados
    $setorPost = array_values(array_unique(array_filter($setorPost, 'strlen')));
    // string que será salva no BD, ex: "TI,VENDAS"
    $setor = implode(',', $setorPost);

    if ($cpf === '') {
        $erro = 'CPF é obrigatório';
    } elseif (strlen($cpf) !== 11) {
        $erro = 'CPF deve ter 11 dígitos';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Email válido é obrigatório';
    } elseif ($id_loja === 0) {
        $erro = 'Selecione a loja';
    } elseif ($telefone === '') {
        $erro = 'Telefone é obrigatório';
    } elseif (empty($setorPost)) {
        $erro = 'Setor é obrigatório';
    } else {
        // Verifica CPF duplicado
        $stmt = $mysqli->prepare('SELECT id FROM usuarios WHERE cpf = ? LIMIT 1');
        $stmt->bind_param('s', $cpf);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $existe = $resultado->fetch_assoc();
        $stmt->close();

        if ($existe) {
            $erro = 'CPF já cadastrado';
        } else {
            // Verifica EMAIL duplicado
            $stmt = $mysqli->prepare('SELECT id FROM usuarios WHERE email = ? LIMIT 1');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $existeEmail = $resultado->fetch_assoc();
            $stmt->close();

            if ($existeEmail) {
                $erro = 'E-mail já cadastrado para outro usuário.';
            } else {
                // Gera senha temporária de 10 caracteres com gerador criptográfico
                // (o usuário é obrigado a trocá-la no primeiro acesso).
                $alfabeto = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
                $senha_temporaria = '';
                for ($i = 0; $i < 10; $i++) {
                    $senha_temporaria .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
                }
                $senha_hash = password_hash($senha_temporaria, PASSWORD_DEFAULT);

                $stmt = $mysqli->prepare('INSERT INTO usuarios 
                    (cpf, nome, email, senha_hash, is_admin, is_gestor, id_loja, telefone, setor, is_ativo, primeiro_acesso) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)');

                if (!$stmt) {
                    $erro = 'Erro ao preparar: ' . $mysqli->error;
                } else {
                    // cpf(s), nome(s), email(s), senha_hash(s), is_admin(i), is_gestor(i), id_loja(i), telefone(s), setor(s)
                    $stmt->bind_param(
                        'ssssiiiss',
                        $cpf,
                        $nome,
                        $email,
                        $senha_hash,
                        $is_admin,
                        $is_gestor,
                        $id_loja,
                        $telefone,
                        $setor
                    );

                    if ($stmt->execute()) {
                        $sucesso  = "✓ Usuário cadastrado com sucesso!<br><br>";
                        $sucesso .= "<strong style='font-size:1.1rem;'>Senha temporária:</strong><br>";
                        $sucesso .= "<span style='font-size:2rem;color:#2347c8;font-weight:700;letter-spacing:4px;'>{$senha_temporaria}</span><br><br>";
                        $sucesso .= "⚠️ <strong>Importante:</strong> Anote esta senha e entregue ao usuário.<br>";
                        $sucesso .= "No primeiro acesso ele será obrigado a trocar a senha.";

                        $cpf = $nome = $email = $telefone = '';
                        $setorPost = [];
                        $_POST = [];
                    } else {
                        $erro = 'Erro ao cadastrar: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        }
    }
}

$nome_usuario = $_SESSION['nome'] ?? 'Usuário';
$is_admin = !empty($_SESSION['is_admin']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastrar Usuário</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{
            font-family:system-ui,-apple-system,sans-serif;
            background:#ffffff;min-height:100vh;color:#333333;display:flex;
        }
        .main-content{
            margin-left:0;flex:1;padding:2rem;width:100%;
            display:flex;align-items:center;justify-content:center;
        }
        .container{
            background:#f4f4f4;padding:2.5rem 3rem;border-radius:12px;
            box-shadow:0 10px 25px rgba(0,0,0,0.12);width:100%;max-width:650px;
        }
        h1{text-align:center;margin-bottom:1.5rem;color:#2347c8;text-transform:uppercase;font-size:1.4rem;}
        .info-box{
            background:#fff3cd;padding:12px;border-radius:6px;margin-bottom:1.5rem;
            border-left:4px solid #ffc107;font-size:.85rem;color:#856404;
        }
        .info-box strong{color:#2347c8;}
        .form-group{margin-bottom:1.2rem;}
        label{display:block;margin-bottom:.4rem;color:#555;font-weight:600;font-size:.9rem;}
        input[type="text"],input[type="email"],select{
            width:100%;padding:10px 14px;border:1px solid #c0c0c0;
            border-radius:999px;font-size:.95rem;background:#fff;
        }
        .linha{display:flex;gap:12px;}
        .linha .form-group{flex:1;}
        .form-check{display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem;}
        .form-check input[type="checkbox"]{width:auto;cursor:pointer;}
        .form-check label{margin:0;cursor:pointer;}
        .btn{
            width:100%;padding:11px;border-radius:999px;border:none;
            font-size:.95rem;font-weight:700;cursor:pointer;
            text-transform:uppercase;transition:all .15s;
        }
        .btn-primary{background:#2347c8;color:#fff;box-shadow:0 6px 16px rgba(35,71,200,0.45);}
        .btn-primary:hover{background:#d40000;transform:translateY(-1px);}
        .btn-secondary{
            background:#fff;color:#2347c8;border:1px solid #2347c8;
            margin-top:.5rem;text-align:center;text-decoration:none;display:block;
        }
        .btn-secondary:hover{background:#ffe5e5;}
        .message{
            margin-top:1rem;padding:1.2rem;border-radius:8px;
            text-align:center;font-size:.9rem;line-height:1.8;
        }
        .error{background:#ffd6d6;color:#b00000;border:1px solid #2347c8;}
        .success{background:#d4edda;color:#155724;border:1px solid #28a745;}
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

        /* chips de setor */
        .setores-wrapper{
            display:flex;
            flex-wrap:wrap;
            gap:8px;
        }
        .setor-chip{
            display:inline-flex;
            align-items:center;
            padding:6px 12px;
            border-radius:999px;
            border:1px solid #c0c0c0;
            background:#ffffff;
            cursor:pointer;
            font-size:0.85rem;
            color:#444;
            transition:all .15s ease;
        }
        .setor-chip input[type="checkbox"]{
            display:none;
        }
        .setor-chip span{
            pointer-events:none;
        }
        .setor-chip:hover{
            background:#ffecec;
            border-color:#2347c8;
        }
        .setor-chip:has(input[type="checkbox"]:checked){
            background:#2347c8;
            border-color:#2347c8;
            color:#ffffff;
            box-shadow:0 0 0 1px rgba(35,71,200,0.2);
        }

        /* chips de loja */
        .lojas-wrapper{
            display:flex;
            flex-wrap:wrap;
            gap:8px;
        }
        .loja-chip{
            display:inline-flex;
            align-items:center;
            padding:6px 12px;
            border-radius:10px;
            border:1px solid #c0c0c0;
            background:#ffffff;
            cursor:pointer;
            font-size:0.85rem;
            color:#444;
            transition:all .15s ease;
        }
        .loja-chip input[type="radio"]{
            display:none;
        }
        .loja-chip span{
            pointer-events:none;
        }
        .loja-chip:hover{
            background:#e9f4ff;
            border-color:#1e88e5;
        }
        .loja-chip:has(input[type="radio"]:checked){
            background:#1e88e5;
            border-color:#1e88e5;
            color:#ffffff;
            box-shadow:0 0 0 1px rgba(30,136,229,0.2);
        }
    </style>
</head>
<body>
    <main class="main-content">
        <div class="container">
            <h1><i class="fas fa-user-plus"></i> Cadastrar Usuário</h1>
            <div class="info-box">
                <strong>Atenção:</strong> Uma senha temporária de 5 números será gerada automaticamente. No primeiro acesso, o usuário será obrigado a trocar a senha.
            </div>
            <form method="post" action="">
                <div class="form-group">
                    <label>CPF (somente números)</label>
                    <input type="text" name="cpf" maxlength="11" required
                           value="<?php echo isset($_POST['cpf']) ? htmlspecialchars($_POST['cpf'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Nome completo</label>
                    <input type="text" name="nome" required
                           value="<?php echo isset($_POST['nome']) ? htmlspecialchars($_POST['nome'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>

                <div class="linha">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" required
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Telefone</label>
                        <input type="text" name="telefone" maxlength="20" required
                               value="<?php echo isset($_POST['telefone']) ? htmlspecialchars($_POST['telefone'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Setor</label>
                    <div class="setores-wrapper">
                        <?php
                        $setoresSelecionados = $_POST['setor'] ?? [];
                        if (!is_array($setoresSelecionados)) {
                            $setoresSelecionados = [$setoresSelecionados];
                        }

                        $opcoesSetor = [
                            'VENDAS'        => 'Vendas',
                            'TI'            => 'TI',
                            'DIRETORIA'     => 'Diretoria',
                            'FINANCEIRO'    => 'Financeiro',
                            'OFICINA'       => 'Oficina',
                            'PECAS'         => 'Peças',
                            'VENDA DIRETA'  => 'Venda Direta',
                            'LOCADORA'      => 'Locadora',
                            'DOCUMENTAL'    => 'Documental',
                            'RH'            => 'RH',
                            'COMPRAS'       => 'Compras',
                            'CONTROLADORIA' => 'Controladoria',
                        ];

                        foreach ($opcoesSetor as $valor => $rotulo) {
                            $checked = in_array($valor, $setoresSelecionados, true);
                            ?>
                            <label class="setor-chip">
                                <input type="checkbox"
                                       name="setor[]"
                                       value="<?php echo htmlspecialchars($valor, ENT_QUOTES, 'UTF-8'); ?>"
                                       <?php echo $checked ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                        <?php } ?>
                    </div>
                    <small style="font-size:0.8rem;color:#555;">Clique para selecionar um ou vários setores.</small>
                </div>

                <div class="form-group">
                    <label>Loja</label>
                    <div class="lojas-wrapper">
                        <?php
                        $lojaSelecionada = $_POST['id_loja'] ?? '';
                        $sqlLojas = "SELECT id, nome FROM lojas ORDER BY nome";
                        if ($resLojas = $mysqli->query($sqlLojas)) {
                            while ($loja = $resLojas->fetch_assoc()) {
                                $idLoja   = (int)$loja['id'];
                                $nomeLoja = $loja['nome'] ?? '';
                                $checked  = ((string)$lojaSelecionada === (string)$idLoja);
                                ?>
                                <label class="loja-chip">
                                    <input type="radio"
                                           name="id_loja"
                                           value="<?php echo $idLoja; ?>"
                                           <?php echo $checked ? 'checked' : ''; ?>>
                                    <span><?php echo htmlspecialchars($nomeLoja, ENT_QUOTES, 'UTF-8'); ?></span>
                                </label>
                            <?php
                            }
                            $resLojas->free();
                        }
                        ?>
                    </div>
                    <small style="font-size:0.8rem;color:#555;">Clique para selecionar a loja.</small>
                </div>

                <div class="form-group" style="margin-top:10px;">
                    <div class="form-check">
                        <input type="checkbox" id="is_admin" name="is_admin" value="1"
                            <?php echo !empty($_POST['is_admin']) ? 'checked' : ''; ?>>
                        <label for="is_admin">Usuário administrador</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" id="is_gestor" name="is_gestor" value="1"
                            <?php echo !empty($_POST['is_gestor']) ? 'checked' : ''; ?>>
                        <label for="is_gestor">Gestor de área</label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Cadastrar</button>
                <a href="gerenciar_usuarios.php" class="btn btn-secondary">Cancelar</a>

                <?php if ($erro): ?>
                    <div class="message error"><?php echo $erro; ?></div>
                <?php endif; ?>
                <?php if ($sucesso): ?>
                    <div class="message success"><?php echo $sucesso; ?></div>
                <?php endif; ?>
            </form>
            <div class="app-version-label">
                <?php echo VERSAO_CADASTRO_USUARIO; ?>
            </div>
        </div>
    </main>
</body>
</html>
