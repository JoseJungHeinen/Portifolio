<?php
require_once __DIR__ . '/config_versao.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado'])) {
    header('Location: index.php');
    exit;
}

if (!empty($_SESSION['primeiro_acesso'])) {
    header('Location: trocar_senha_obrigatoria.php');
    exit;
}

$nome     = $_SESSION['nome'] ?? 'Usuário';
$is_admin = !empty($_SESSION['is_admin']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Treinamentos - Sistema de Ferramentas Exemplo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body{
            margin:0;
            font-family:system-ui,sans-serif;
            background:#f7f7f7;
            color:#333;
        }
        .container{
            max-width:1000px;
            margin:0 auto;
            padding:20px 25px 30px;
        }
        h1{
            font-size:1.8rem;
            color:#5A5A5A;
            margin-bottom:.5rem;
        }
        .subtitulo{
            font-size:.95rem;
            color:#666;
            margin-bottom:1.5rem;
        }
        .cards{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
            gap:15px;
        }
        .card{
            background:#fff;
            border-radius:10px;
            border:1px solid #ddd;
            padding:15px 18px;
            box-shadow:0 2px 6px rgba(0,0,0,0.05);
        }
        .card h2{
            font-size:1.05rem;
            margin-bottom:.4rem;
            color:#444;
        }
        .card p{
            font-size:.9rem;
            color:#666;
            margin-bottom:.8rem;
        }
        .card a{
            display:inline-flex;
            align-items:center;
            gap:6px;
            text-decoration:none;
            font-size:.88rem;
            color:#2347c8;
            font-weight:600;
        }
        .card a i{
            font-size:.95rem;
        }
        .badge-admin{
            display:inline-block;
            font-size:.75rem;
            padding:2px 8px;
            border-radius:999px;
            background:#ffeeba;
            color:#856404;
            margin-left:6px;
        }
        .lista-links{
            list-style:none;
            padding-left:0;
            margin:0;
        }
        .lista-links li{
            margin-bottom:.4rem;
            font-size:.9rem;
        }
        .lista-links a{
            color:#007bff;
            text-decoration:none;
        }
        .lista-links a:hover{
            text-decoration:underline;
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
    <h1>Treinamentos do Sistema</h1>
    <p class="subtitulo">
        Bem-vindo, <?php echo htmlspecialchars($nome, ENT_QUOTES, 'UTF-8'); ?>.
        Aqui você encontra materiais para aprender e revisar o uso das ferramentas do sistema.
    </p>

    <div class="cards">
        <div class="card">
            <h2><i class="fas fa-video"></i> Vídeos de utilização</h2>
            <p>
                Assista aos vídeos com passo a passo das principais rotinas: Transferência, Emplacamento,
                Cartão e Orçamento.
            </p>
            <ul class="lista-links">
                <li><a href="https://seu-servidor/videos/transferencia.mp4" target="_blank">Treinamento Transferência</a></li>
                <li><a href="https://seu-servidor/videos/emplacamento.mp4" target="_blank">Treinamento Emplacamento</a></li>
                <li><a href="https://seu-servidor/videos/cartao.mp4" target="_blank">Treinamento Cartão</a></li>
                <li><a href="https://seu-servidor/videos/orcamento.mp4" target="_blank">Treinamento Orçamento</a></li>
            </ul>
        </div>

        <div class="card">
            <h2><i class="fas fa-file-pdf"></i> Manuais e PDFs</h2>
            <p>
                Documentos com orientações detalhadas, exemplos de propostas e checklists de conferência.
            </p>
            <ul class="lista-links">
                <li><a href="https://seu-servidor/materiais/manual_sistema.pdf" target="_blank">Manual do Sistema</a></li>
                <li><a href="https://seu-servidor/materiais/checklist_transferencia.pdf" target="_blank">Checklist Transferência</a></li>
                <li><a href="https://seu-servidor/materiais/checklist_emplacamento.pdf" target="_blank">Checklist Emplacamento</a></li>
            </ul>
        </div>

        <div class="card">
            <h2><i class="fas fa-question-circle"></i> Dúvidas frequentes</h2>
            <p>
                Respostas rápidas para as dúvidas mais comuns sobre o uso do sistema e procedimentos internos.
            </p>
            <ul class="lista-links">
                <li><a href="https://seu-servidor/materiais/faq_sistema.pdf" target="_blank">FAQ do Sistema</a></li>
                <li><a href="mailto:suporte@exemplo.com">Contato suporte interno</a></li>
            </ul>
        </div>

        <?php if ($is_admin): ?>
        <div class="card">
            <h2>
                <i class="fas fa-user-shield"></i> Materiais para administradores
                <span class="badge-admin">Admin</span>
            </h2>
            <p>
                Conteúdos focados em configurações, permissões, reajustes anuais e boas práticas de administração.
            </p>
            <ul class="lista-links">
                <li><a href="https://seu-servidor/materiais/treinamento_admin_usuarios.pdf" target="_blank">Gerenciamento de usuários</a></li>
                <li><a href="https://seu-servidor/materiais/treinamento_reajuste_valores.pdf" target="_blank">Reajuste anual de valores</a></li>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
<div class="app-version-label">
    <?php echo VERSAO_TREINAMENTO; ?>
</div>
</body>
</html>
