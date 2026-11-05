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
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --red: #2347c8;
            --dark: #1a1a1a;
            --mid: #444;
            --light: #f2f2f2;
            --white: #ffffff;
            --border: #e0e0e0;
            --card-shadow: 0 4px 24px rgba(0,0,0,0.07);
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--light);
            color: var(--dark);
            min-height: 100vh;
        }

        /* ── HEADER ── */
        .header {
            background: var(--white);
            border-bottom: 1px solid var(--border);
            padding: 0 40px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-brand {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 1.1rem;
            letter-spacing: .04em;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header-brand span {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: var(--red);
            border-radius: 50%;
        }
        .header-user {
            font-size: .85rem;
            color: #888;
        }
        .header-user strong {
            color: var(--dark);
            font-weight: 500;
        }

        /* ── HERO ── */
        .hero {
            background: var(--dark);
            color: var(--white);
            padding: 56px 40px 48px;
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 320px; height: 320px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(35,71,200,0.18) 0%, transparent 70%);
            pointer-events: none;
        }
        .hero::after {
            content: '';
            position: absolute;
            bottom: -40px; left: 30%;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(35,71,200,0.08) 0%, transparent 70%);
            pointer-events: none;
        }
        .hero-inner {
            max-width: 960px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }
        .hero-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: .75rem;
            font-weight: 500;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--red);
            background: rgba(35,71,200,0.1);
            border: 1px solid rgba(35,71,200,0.25);
            border-radius: 999px;
            padding: 4px 12px;
            margin-bottom: 16px;
        }
        .hero h1 {
            font-family: 'Syne', sans-serif;
            font-size: clamp(1.8rem, 4vw, 2.6rem);
            font-weight: 800;
            line-height: 1.15;
            margin-bottom: 12px;
        }
        .hero h1 em {
            font-style: normal;
            color: var(--red);
        }
        .hero p {
            font-size: 1rem;
            color: #aaa;
            font-weight: 300;
            max-width: 520px;
            line-height: 1.6;
        }

        /* ── GRID ── */
        .main {
            max-width: 960px;
            margin: 0 auto;
            padding: 40px 40px 60px;
        }
        .section-label {
            font-family: 'Syne', sans-serif;
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: #999;
            margin-bottom: 20px;
        }
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
        }

        /* ── CARD ── */
        .card {
            background: var(--white);
            border-radius: 14px;
            border: 1px solid var(--border);
            padding: 24px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            gap: 14px;
            transition: transform .2s, box-shadow .2s;
            animation: fadeUp .4s ease both;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 36px rgba(0,0,0,0.11);
        }
        .card:nth-child(1) { animation-delay: .05s; }
        .card:nth-child(2) { animation-delay: .10s; }
        .card:nth-child(3) { animation-delay: .15s; }
        .card:nth-child(4) { animation-delay: .20s; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .card-icon {
            width: 42px; height: 42px;
            border-radius: 10px;
            background: #f5f5f5;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            color: var(--red);
            flex-shrink: 0;
        }
        .card-admin .card-icon {
            background: #fff8e1;
            color: #e6a800;
        }

        .card-head {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .card-title {
            font-family: 'Syne', sans-serif;
            font-size: .95rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.3;
        }
        .badge-admin {
            display: inline-block;
            font-size: .68rem;
            padding: 2px 8px;
            border-radius: 999px;
            background: #fff3cd;
            color: #856404;
            font-weight: 600;
            letter-spacing: .04em;
            margin-left: 4px;
        }
        .card-desc {
            font-size: .875rem;
            color: #777;
            line-height: 1.55;
        }

        /* ── LINKS ── */
        .lista-links {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: auto;
        }
        .lista-links li a {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .85rem;
            font-weight: 500;
            color: var(--dark);
            text-decoration: none;
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: #fafafa;
            transition: background .15s, border-color .15s, color .15s;
        }
        .lista-links li a:hover {
            background: #eef2fd;
            border-color: rgba(35,71,200,0.3);
            color: var(--red);
        }
        .lista-links li a i {
            font-size: .8rem;
            color: var(--red);
            width: 16px;
            text-align: center;
            flex-shrink: 0;
        }
        .lista-links li a.mailto i { color: #555; }
        .lista-links li a.mailto:hover { color: #333; background: #f5f5f5; border-color: #ccc; }
        .lista-links li a.mailto:hover i { color: #333; }

        /* ── VERSÃO ── */
        .app-version-label {
            position: fixed;
            bottom: 6px; right: 10px;
            font-size: 10px;
            color: #bbb;
            pointer-events: none;
            z-index: 9999;
        }

        @media (max-width: 600px) {
            .hero { padding: 36px 20px 32px; }
            .main { padding: 24px 20px 40px; }
            .header { padding: 0 20px; }
        }
    </style>
</head>
<body>

    <!-- HEADER -->
    <header class="header">
        <div class="header-brand">
            <span></span> Empresa Exemplo
        </div>
        <div class="header-user">
            Olá, <strong><?php echo htmlspecialchars($nome, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </header>

    <!-- HERO -->
    <section class="hero">
        <div class="hero-inner">
            <div class="hero-label">
                <i class="fas fa-graduation-cap"></i> Central de treinamentos
            </div>
            <h1>Aprenda a usar o<br><em>sistema com facilidade</em></h1>
            <p>Vídeos, manuais e materiais de apoio para dominar todas as ferramentas do sistema Exemplo.</p>
        </div>
    </section>

    <!-- CARDS -->
    <main class="main">
        <div class="section-label">Materiais disponíveis</div>
        <div class="cards">

            <div class="card">
                <div class="card-head">
                    <div class="card-icon"><i class="fas fa-video"></i></div>
                    <div class="card-title">Vídeos de utilização</div>
                </div>
                <p class="card-desc">Passo a passo em vídeo das principais rotinas do sistema.</p>
                <ul class="lista-links">
                    <li><a href="https://seu-servidor/videos/transferencia.mp4" target="_blank"><i class="fas fa-play-circle"></i> Treinamento Transferência</a></li>
                    <li><a href="https://seu-servidor/videos/emplacamento.mp4" target="_blank"><i class="fas fa-play-circle"></i> Treinamento Emplacamento</a></li>
                    <li><a href="https://seu-servidor/videos/cartao.mp4" target="_blank"><i class="fas fa-play-circle"></i> Treinamento Cartão</a></li>
                    <li><a href="https://seu-servidor/videos/orcamento.mp4" target="_blank"><i class="fas fa-play-circle"></i> Treinamento Orçamento</a></li>
                </ul>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="card-icon"><i class="fas fa-file-pdf"></i></div>
                    <div class="card-title">Manuais e PDFs</div>
                </div>
                <p class="card-desc">Documentos detalhados, exemplos de propostas e checklists de conferência.</p>
                <ul class="lista-links">
                    <li><a href="https://seu-servidor/materiais/manual_sistema.pdf" target="_blank"><i class="fas fa-file-alt"></i> Manual do Sistema</a></li>
                    <li><a href="https://seu-servidor/materiais/checklist_transferencia.pdf" target="_blank"><i class="fas fa-file-alt"></i> Checklist Transferência</a></li>
                    <li><a href="https://seu-servidor/materiais/checklist_emplacamento.pdf" target="_blank"><i class="fas fa-file-alt"></i> Checklist Emplacamento</a></li>
                </ul>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="card-icon"><i class="fas fa-question-circle"></i></div>
                    <div class="card-title">Dúvidas frequentes</div>
                </div>
                <p class="card-desc">Respostas rápidas para as dúvidas mais comuns sobre o sistema.</p>
                <ul class="lista-links">
                    <li><a href="https://seu-servidor/materiais/faq_sistema.pdf" target="_blank"><i class="fas fa-file-alt"></i> FAQ do Sistema</a></li>
                    <li><a href="mailto:suporte@exemplo.com" class="mailto"><i class="fas fa-envelope"></i> Contato suporte interno</a></li>
                </ul>
            </div>

            <?php if ($is_admin): ?>
            <div class="card card-admin">
                <div class="card-head">
                    <div class="card-icon"><i class="fas fa-user-shield"></i></div>
                    <div class="card-title">
                        Administradores
                        <span class="badge-admin">Admin</span>
                    </div>
                </div>
                <p class="card-desc">Configurações, permissões, reajustes anuais e boas práticas de administração.</p>
                <ul class="lista-links">
                    <li><a href="https://seu-servidor/materiais/treinamento_admin_usuarios.pdf" target="_blank"><i class="fas fa-file-alt"></i> Gerenciamento de usuários</a></li>
                    <li><a href="https://seu-servidor/materiais/treinamento_reajuste_valores.pdf" target="_blank"><i class="fas fa-file-alt"></i> Reajuste anual de valores</a></li>
                </ul>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <div class="app-version-label">
        <?php echo VERSAO_TREINAMENTO; ?>
    </div>

</body>
</html>