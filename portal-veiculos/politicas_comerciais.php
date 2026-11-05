<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';
session_start();

if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

$usuarioId    = (int)($_SESSION['usuario_id'] ?? 0);
$setorUsuario = $_SESSION['setor'] ?? '';              // ex.: 'VENDAS'
$isAdmin      = !empty($_SESSION['is_admin']);         // bool true/false
$setorBruto   = strtolower($setorUsuario);

// -----------------------------------------------------------------------
// Busca políticas ativas
// - Admin OU setor 'diretoria': vê TODAS as ativas (qualquer setor_permitido)
// - Demais: somente as do seu setor (ou sem setor definido)
// -----------------------------------------------------------------------
$politicas = [];

if ($isAdmin || $setorBruto === 'diretoria') {
    // Admin / diretoria -> todas as políticas ativas
    $sql = "
        SELECT id, titulo, arquivo_pdf, setor_permitido
          FROM politicas_comerciais
         WHERE ativo = 1
      ORDER BY titulo ASC
    ";
    $res = $mysqli->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $politicas[] = $row;
        }
        $res->close();
    }
} else {
    // Demais usuários -> filtrados por setor
    $stmt = $mysqli->prepare("
        SELECT id, titulo, arquivo_pdf, setor_permitido
          FROM politicas_comerciais
         WHERE ativo = 1
           AND (
                setor_permitido IS NULL
             OR setor_permitido = ''
             OR setor_permitido = ?
           )
      ORDER BY titulo ASC
    ");
    $stmt->bind_param('s', $setorUsuario);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $politicas[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Políticas Comerciais</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    background: #f3f4f6;
    color: #1a1a1a;
    min-height: 100vh;
    padding: 2rem 1rem 3rem;
}

.page-shell {
    max-width: 900px;
    margin: 0 auto;
}

/* cabeçalho */
.page-header {
    background: #fff;
    border: 1px solid #ebebeb;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.07);
    padding: 1.5rem 2rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 16px;
}
.page-header-icon {
    width: 48px; height: 48px; min-width: 48px;
    background: #cc0000;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
}
.page-header-icon svg { display: block; }
.page-header-text h1 {
    font-size: 1.2rem;
    font-weight: 700;
    color: #111;
    letter-spacing: -.01em;
}
.page-header-text p {
    font-size: .82rem;
    color: #999;
    margin-top: 3px;
}

/* lista */
.politicas-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.politica-card {
    background: #fff;
    border: 1px solid #ebebeb;
    border-radius: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    overflow: hidden;
    transition: box-shadow .15s, border-color .15s;
}
.politica-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.09);
}
.politica-card.ativo {
    border-color: #cc0000;
    box-shadow: 0 4px 20px rgba(204,0,0,0.12);
}

.politica-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    cursor: pointer;
    user-select: none;
    transition: background .12s;
}
.politica-header:hover { background: #fafafa; }
.politica-card.ativo .politica-header { background: #fff8f8; }

.politica-header-icon {
    width: 36px; height: 36px; min-width: 36px;
    border-radius: 10px;
    background: rgba(204,0,0,.08);
    border: 1px solid rgba(204,0,0,.15);
    display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.politica-card.ativo .politica-header-icon {
    background: rgba(204,0,0,.15);
}

.politica-header-text {
    flex: 1; min-width: 0;
}
.politica-header-text .titulo {
    font-weight: 600;
    font-size: .93rem;
    color: #111;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.politica-header-text .meta {
    font-size: .76rem;
    color: #aaa;
    margin-top: 2px;
}

.badge-setor {
    font-size: .72rem;
    padding: 2px 10px;
    border-radius: 999px;
    background: #fee2e2;
    color: #cc0000;
    font-weight: 600;
    white-space: nowrap;
}

.politica-toggle {
    width: 28px; height: 28px; min-width: 28px;
    border-radius: 999px;
    border: 1px solid #e5e5e5;
    background: #f5f5f5;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s, border-color .15s, transform .2s;
    cursor: pointer;
}
.politica-card.ativo .politica-toggle {
    background: #cc0000;
    border-color: #cc0000;
    transform: rotate(180deg);
}
.politica-toggle svg { display: block; }

/* viewer PDF */
.politica-viewer {
    display: none;
    border-top: 1px solid #f0f0f0;
    padding: 0;
}
.politica-viewer.aberto { display: block; }

.viewer-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 18px;
    background: #fafafa;
    border-bottom: 1px solid #f0f0f0;
    gap: 10px;
    flex-wrap: wrap;
}
.viewer-toolbar span {
    font-size: .8rem;
    color: #888;
}
.btn-abrir-externo {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px;
    border-radius: 999px;
    border: 1px solid #ddd;
    background: #fff;
    color: #444;
    font-size: .8rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: border-color .15s, background .15s;
    font-family: inherit;
}
.btn-abrir-externo:hover { background: #fafafa; border-color: #bbb; }

.politica-viewer iframe {
    width: 100%;
    height: calc(100vh - 220px);
    min-height: 500px;
    border: none;
    display: block;
}

/* vazio */
.empty-state {
    background: #fff;
    border: 1px solid #ebebeb;
    border-radius: 14px;
    padding: 3rem 2rem;
    text-align: center;
}
.empty-state svg { margin-bottom: 12px; opacity: .35; }
.empty-state p { font-size: .9rem; color: #999; }

@media (max-width: 640px) {
    body { padding: 0; }
    .page-header { border-radius: 0; margin-bottom: 1rem; padding: 1.2rem; }
    .politica-card { border-radius: 10px; }
}
</style>
</head>
<body>
<div class="page-shell">

    <div class="page-header">
        <div class="page-header-icon">
            <svg width="26" height="26" fill="none" viewBox="0 0 24 24">
                <path d="M9 12h6M9 16h4M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-5z" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M14 3v5h5" stroke="white" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
        </div>
        <div class="page-header-text">
            <h1>Políticas Comerciais</h1>
            <p>Selecione uma política para visualizar o documento.</p>
        </div>
    </div>

    <div class="politicas-list">
        <?php if (!$politicas): ?>
            <div class="empty-state">
                <svg width="48" height="48" fill="none" viewBox="0 0 24 24">
                    <path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-5z" stroke="#999" stroke-width="1.5"/>
                    <path d="M9 12h6M9 16h4" stroke="#999" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                <p>Nenhuma política disponível para o seu setor.</p>
            </div>
        <?php else: ?>
            <?php foreach ($politicas as $p): ?>
                <?php
                $id  = (int)$p['id'];
                $src = htmlspecialchars($p['arquivo_pdf'], ENT_QUOTES, 'UTF-8')
                       . '#toolbar=0&navpanes=0&scrollbar=1';
                $srcExterno = htmlspecialchars($p['arquivo_pdf'], ENT_QUOTES, 'UTF-8');
                ?>
                <div class="politica-card" id="item-<?= $id; ?>">
                    <div class="politica-header" onclick="togglePolitica(<?= $id; ?>)">
                        <div class="politica-header-icon">
                            <svg width="18" height="18" fill="none" viewBox="0 0 24 24">
                                <path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-5z" stroke="#cc0000" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M14 3v5h5M9 12h6M9 16h4" stroke="#cc0000" stroke-width="1.8" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="politica-header-text">
                            <div class="titulo"><?= htmlspecialchars($p['titulo'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="meta">
                                <?php if (!empty($p['setor_permitido'])): ?>
                                    <span class="badge-setor"><?= htmlspecialchars($p['setor_permitido'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php else: ?>
                                    Disponível para todos os setores
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="politica-toggle" aria-label="Abrir/fechar">
                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24">
                                <path d="M6 9l6 6 6-6" stroke="#666" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                    </div>
                    <div class="politica-viewer" id="politica-content-<?= $id; ?>">
                        <div class="viewer-toolbar">
                            <span>Visualizando: <?= htmlspecialchars($p['titulo'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <a href="<?= $srcExterno; ?>" target="_blank" class="btn-abrir-externo">
                                <svg width="13" height="13" fill="none" viewBox="0 0 24 24">
                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Abrir em nova aba
                            </a>
                        </div>
                        <iframe src="<?= $src; ?>" title="<?= htmlspecialchars($p['titulo'], ENT_QUOTES, 'UTF-8'); ?>"></iframe>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
let politicaAtivaId = null;

function togglePolitica(id) {
    const card    = document.getElementById('item-' + id);
    const viewer  = document.getElementById('politica-content-' + id);
    const jaAtiva = (politicaAtivaId === id);

    // fechar todos
    document.querySelectorAll('.politica-card').forEach(c => c.classList.remove('ativo'));
    document.querySelectorAll('.politica-viewer').forEach(v => v.classList.remove('aberto'));
    document.querySelectorAll('.politica-toggle svg path').forEach(p => p.setAttribute('stroke', '#666'));

    if (jaAtiva) {
        politicaAtivaId = null;
        return;
    }

    card.classList.add('ativo');
    viewer.classList.add('aberto');
    viewer.querySelector('.politica-toggle svg path');
    politicaAtivaId = id;

    // scroll suave até o card
    setTimeout(() => {
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 80);
}
</script>
</body>
</html>