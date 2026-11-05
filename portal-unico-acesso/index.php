<?php
session_start();

if (empty($_SESSION['portal_logado'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/federated_auth.php';
require_once __DIR__ . '/includes/preferencias_usuario.php';
$sistemas = carregar_todos_sistemas();
$email    = $_SESSION['portal_email'] ?? '';
$sistemas = aplicar_ordem_sistemas($sistemas, carregar_ordem_sistemas($email));

/*
 * Reconfere ao vivo em vez de confiar só na "foto" tirada no momento do login —
 * assim, se o admin mudar o acesso de alguém (ou um sistema novo for integrado)
 * enquanto essa pessoa já está logada, o dashboard reflete na hora, sem precisar
 * sair e entrar de novo.
 */
$statusPorId = autenticar_federado($email, $_SESSION['portal_senha'] ?? '', $sistemas, $instanciasCorrespondentes);
$statusPorId = sincronizar_senha_federada($email, $_SESSION['portal_senha'] ?? '', $sistemas, $statusPorId);

foreach ($statusPorId as $idSistema => $status) {
    if ($status === true && !isset($instanciasCorrespondentes[$idSistema])) {
        $instanciasCorrespondentes[$idSistema] = $sistemas[$idSistema]['instancias'][0]['id'] ?? 'principal';
    }
}

$_SESSION['portal_sistemas']   = $statusPorId;
$_SESSION['portal_instancias'] = $instanciasCorrespondentes;
$_SESSION['portal_is_admin']   = verificar_admin_federado($email, $_SESSION['portal_senha'] ?? '', $sistemas);

$isAdmin        = !empty($_SESSION['portal_is_admin']);
$iniciais       = mb_strtoupper(mb_substr($email, 0, 1, 'UTF-8'), 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Portal Único</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/design-system.css">
<style>
.hero{margin-bottom:var(--space-6)}
.hero-title{font-family:var(--font-bold);font-size:var(--text-display);line-height:1.15;color:var(--text-primary)}

.section-lbl{font-family:var(--font-regular);font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);margin-bottom:var(--space-4);display:block}

.sys-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:var(--space-4)}
.sys-card{text-decoration:none;color:inherit;display:block;position:relative;overflow:hidden;isolation:isolate;transition:transform .16s,box-shadow .16s}
.sys-card::before{content:"";position:absolute;inset:0;z-index:-1;
    background-image:linear-gradient(180deg,rgba(20,25,34,.45) 0%,rgba(20,25,34,.9) 100%),var(--hover-img,none);
    background-size:cover;background-position:left center;background-repeat:no-repeat;
    opacity:0;transform:scale(1.06);transition:opacity .3s ease,transform .4s ease;}
.sys-card:hover{transform:translateY(-3px);box-shadow:0 14px 34px rgba(0,0,0,.35)}
.sys-card:hover::before{opacity:1;transform:scale(1)}
.sys-card:hover .sys-arrow{color:#fff !important}
.sys-card.has-hover-img:hover .sys-nome{opacity:0}
.sys-card:not(.has-hover-img):hover .sys-nome{color:#fff !important}
.sys-card:hover .sys-icon{background:rgba(255,255,255,.15) !important;color:#fff !important}
.sys-card:hover .sys-footer{border-top-color:rgba(255,255,255,.25)}
.sys-card:hover .badge--success{background:rgba(255,255,255,.18);color:#fff}
.sys-card:hover .badge--warning{background:rgba(255,255,255,.18);color:#fff}
.sys-card.disabled{opacity:.6}
.sys-card.dragging{opacity:.4}
.sys-card.disabled:hover{transform:none;box-shadow:none}
.sys-card.disabled:hover::before{opacity:0}
.sys-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:var(--space-4);transition:background .28s ease,color .28s ease}
.sys-icon svg{width:22px;height:22px}
.sys-nome{font-family:var(--font-bold);font-size:var(--text-h3);margin-bottom:var(--space-2);transition:color .28s ease,opacity .2s ease}
.sys-footer{display:flex;align-items:center;justify-content:space-between;margin-top:var(--space-4);padding-top:var(--space-3);border-top:1px solid var(--color-gray-light);transition:border-color .28s ease}
.sys-arrow{color:var(--text-muted);transition:transform .16s,color .28s ease}
.sys-card:hover .sys-arrow{transform:translateX(4px)}

.empty-state{text-align:center;padding:var(--space-7) var(--space-5);color:var(--text-muted);font-family:var(--font-regular);font-size:var(--text-body-sm)}
</style>
</head>
<body>

<nav class="topbar">
    <div class="topbar__brand">
        <img src="assets/img/logo.svg" alt="Portal Único" style="height:36px;border-radius:8px">
        <div class="topbar__title">Portal Único<span>Central de acessos</span></div>
    </div>
    <?php if ($isAdmin): ?>
    <div class="topbar__nav">
        <a href="admin/usuarios.php">Administração</a>
    </div>
    <?php endif; ?>
    <div class="topbar__right">
        <div class="avatar-menu" tabindex="0">
            <button class="avatar" type="button"><?= htmlspecialchars($iniciais) ?></button>
            <div class="avatar-dropdown">
                <div class="avatar-dropdown__email"><?= htmlspecialchars($email) ?></div>
                <?php if ($isAdmin): ?>
                <a href="admin/usuarios.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Administração
                </a>
                <a href="admin/sistemas_sem_manifesto.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    Sistemas configurados
                </a>
                <?php endif; ?>
                <a href="logout.php" class="danger">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Sair do portal
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="main-content">
    <div class="hero">
        <h1 class="hero-title">Seus <span class="brand-highlight">sistemas</span></h1>
    </div>

    <span class="section-lbl">Sistemas disponíveis</span>
    <div class="sys-grid">
    <?php
    // Ícone (Feather) por sistema — sistema não listado aqui cai no quadrado genérico.
    $iconesPorSistema = [
        'estoque'    => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.73Z"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/>',
        'compras'    => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
        'projeto'    => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'veiculos'   => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'locadora'   => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
        'notas'       => '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'inventario' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="m9 14 2 2 4-4"/>',
    ];
    $iconeGenerico = '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>';

    // Imagem de fundo do hover, por sistema — sistema sem imagem própria cai só no gradiente escuro.
    $imagensPorSistema = []; // ex.: 'compras' => 'assets/img/compras.png' (imagem de fundo do hover, opcional)

    $temAlgum = false;
    foreach ($sistemas as $id => $s):
        $status = $statusPorId[$id] ?? null;
        if ($status === null) continue; // e-mail não encontrado nesse sistema, não mostra
        $temAlgum = true;
        $conectado = $status === true;
    ?>
        <a href="<?= $conectado ? 'sso.php?sistema=' . urlencode($id) : '#' ?>"
           <?= $conectado ? 'target="_blank" rel="noopener"' : '' ?>
           draggable="true"
           data-sistema-id="<?= htmlspecialchars($id) ?>"
           class="sys-card card card--primary<?= $conectado ? '' : ' disabled' ?><?= isset($imagensPorSistema[$id]) ? ' has-hover-img' : '' ?>"
           style="border-left-color:<?= htmlspecialchars($s['cor']) ?><?= isset($imagensPorSistema[$id]) ? ';--hover-img:url(\'' . htmlspecialchars($imagensPorSistema[$id]) . '\')' : '' ?>">
            <div class="sys-icon" style="background:<?= htmlspecialchars($s['cor_bg']) ?>;color:<?= htmlspecialchars($s['cor']) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?= $iconesPorSistema[$id] ?? $iconeGenerico ?></svg>
            </div>
            <div class="sys-nome" style="color:<?= htmlspecialchars($s['cor']) ?>"><?= htmlspecialchars($s['nome']) ?></div>
            <div class="sys-footer">
                <?php if ($conectado): ?>
                    <span class="badge badge--success">Acesso liberado</span>
                    <svg class="sys-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                <?php else: ?>
                    <span class="badge badge--warning">Senha não confere</span>
                <?php endif; ?>
            </div>
        </a>
    <?php endforeach; ?>
    </div>

    <?php if (!$temAlgum): ?>
    <div class="card empty-state" style="margin-top:var(--space-4)">
        Nenhum sistema encontrado para o e-mail <?= htmlspecialchars($email) ?>.<br>
        Fale com um administrador para solicitar acesso.
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var grade = document.querySelector('.sys-grid');
    if (!grade) return;

    var arrastando = null;

    grade.querySelectorAll('.sys-card').forEach(function (card) {
        card.addEventListener('dragstart', function (evento) {
            arrastando = card;
            card.classList.add('dragging');
            evento.dataTransfer.effectAllowed = 'move';
            evento.dataTransfer.setData('text/plain', card.dataset.sistemaId);
        });

        card.addEventListener('dragend', function () {
            card.classList.remove('dragging');
            arrastando = null;

            var ordem = Array.prototype.map.call(
                grade.querySelectorAll('.sys-card'),
                function (c) { return c.dataset.sistemaId; }
            );

            fetch('salvar_ordem.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ordem: ordem })
            }).catch(function () { /* falha ao salvar não bloqueia a UI */ });
        });

        card.addEventListener('dragover', function (evento) {
            evento.preventDefault();
            if (!arrastando || arrastando === card) return;

            var todos = Array.prototype.slice.call(grade.querySelectorAll('.sys-card'));
            var indiceArrastado = todos.indexOf(arrastando);
            var indiceAlvo = todos.indexOf(card);

            if (indiceArrastado < indiceAlvo) {
                card.after(arrastando);
            } else {
                card.before(arrastando);
            }
        });

        card.addEventListener('drop', function (evento) {
            evento.preventDefault();
        });

        card.addEventListener('click', function (evento) {
            if (card.classList.contains('disabled')) {
                evento.preventDefault();
            }
        });
    });
})();
</script>

</body>
</html>
