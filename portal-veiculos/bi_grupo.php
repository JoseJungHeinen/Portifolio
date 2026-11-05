<?php
// @versao Ver.001.261026
/**
 * bi_grupo.php — Página-grupo de BIs (barra lateral "Análises" no estilo do BI Contabilidade).
 *
 * Uso no index.php da pasta do grupo:
 *     $grupo = [
 *         'titulo'   => 'BI Oficina',
 *         'icone'    => 'bi-wrench',
 *         'analises' => [
 *             'os' => ['nome' => 'Oficina', 'icone' => 'bi-tools', 'url' => '../Metas/os_oficina.php',
 *                      'perms' => ['menu_bi_oficina']],
 *         ],
 *     ];
 *     require __DIR__ . '/../../bi_grupo.php';
 *
 * Cada análise aparece só para quem tem ao menos uma das permissões em 'perms' (admin vê tudo).
 * A análise aberta fica em ?a=<chave>, então recarregar a página mantém a mesma tela.
 * As URLs das análises são relativas à pasta do grupo (BI/<Grupo>/).
 */

// Só funciona incluído pelo index.php de um grupo; acesso direto pela URL não tem $grupo
if (!isset($grupo['analises'])) {
    http_response_code(404);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado'])) {
    header('Location: /Veiculos/index.php');
    exit;
}

$is_admin = !empty($_SESSION['is_admin']);
$perms    = (isset($_SESSION['perms']) && is_array($_SESSION['perms'])) ? $_SESSION['perms'] : [];

// Mesma carga de permissões do bi_menu.php, para quem abrir o grupo direto pela URL
if (empty($perms)) {
    require_once __DIR__ . '/config_database.php';
    $uid = (int)($_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0);
    if ($uid > 0) {
        $st = $mysqli->prepare('SELECT * FROM usuarios_permissoes WHERE usuario_id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $uid);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if (is_array($row)) {
                $perms             = $row;
                $_SESSION['perms'] = $row;
            }
        }
    }
}

$visiveis = [];
foreach ($grupo['analises'] as $chave => $analise) {
    $pode = $is_admin;
    foreach ($analise['perms'] as $p) {
        if ((int)($perms[$p] ?? 0) === 1) {
            $pode = true;
        }
    }
    if ($pode) {
        $visiveis[$chave] = $analise;
    }
}

if (empty($visiveis)) {
    http_response_code(403);
}

$atual = (string)($_GET['a'] ?? '');
if (!isset($visiveis[$atual])) {
    $atual = (string)array_key_first($visiveis);
}

$h = function ($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $h($grupo['titulo']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; height: 100%; }
body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; }

#wrapper { display: flex; height: 100vh; }

/* Barra lateral — mesmos valores do BI Contabilidade (Contabilidade/public/assets/css/app.css) */
#sidebar-wrapper {
    width: 240px; min-width: 240px;
    background: #ffffff; border-right: 1px solid #e2e8f0;
    display: flex; flex-direction: column;
    transition: width .25s ease, min-width .25s ease;
}
#wrapper.sidebar-collapsed #sidebar-wrapper { width: 0; min-width: 0; overflow: hidden; }

.sidebar-brand {
    display: flex; align-items: center; gap: .5rem;
    padding: 1rem; font-size: .95rem; font-weight: 700; white-space: nowrap;
    border-bottom: 1px solid #e2e8f0;
}
.sidebar-brand i { color: #0d6efd; }
.sidebar-nav { flex: 1; overflow-y: auto; padding: .5rem .5rem 1rem; }
.sidebar-section {
    font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .08em;
    color: #94a3b8; padding: .9rem .5rem .3rem;
}
.sidebar-item {
    display: flex; align-items: center; gap: .5rem;
    padding: .5rem .75rem; margin: .15rem 0;
    font-size: .875rem; color: #1e293b; text-decoration: none; white-space: nowrap;
    border: 1px solid transparent; border-radius: 8px;
    transition: background .15s, border-color .15s, color .15s;
}
.sidebar-item:hover { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
.sidebar-item.active { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; font-weight: 600; }

#page-content-wrapper { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.topbar {
    display: flex; align-items: center; gap: .75rem;
    padding: .5rem 1rem; background: #fff; border-bottom: 1px solid #e2e8f0;
    box-shadow: 0 1px 2px rgba(0,0,0,.04);
}
.topbar button {
    border: 1px solid #cbd5e1; background: #fff; color: #475569;
    border-radius: 6px; padding: .2rem .55rem; cursor: pointer; font-size: 1rem;
}
.topbar button:hover { background: #f1f5f9; }
.topbar .titulo { font-weight: 600; }
#analise-frame { flex: 1; width: 100%; border: 0; background: #fff; }

.vazio { margin: auto; text-align: center; color: #475569; }
.vazio h1 { color: #dc2626; font-size: 1.3rem; }
</style>
</head>
<body>

<?php if (empty($visiveis)): ?>
<div id="wrapper"><div class="vazio">
    <h1><i class="bi bi-lock"></i> Acesso negado</h1>
    <p>Você não tem permissão para nenhuma análise de <?= $h($grupo['titulo']) ?>.</p>
</div></div>
<?php else: ?>
<div id="wrapper">
    <div id="sidebar-wrapper">
        <div class="sidebar-brand"><i class="bi <?= $h($grupo['icone']) ?>"></i><?= $h($grupo['titulo']) ?></div>
        <nav class="sidebar-nav">
            <div class="sidebar-section">Análises</div>
            <?php foreach ($visiveis as $chave => $analise): ?>
            <a href="?a=<?= $h(rawurlencode($chave)) ?>"
               class="sidebar-item<?= $chave === $atual ? ' active' : '' ?>"
               data-chave="<?= $h($chave) ?>" data-url="<?= $h($analise['url']) ?>" data-nome="<?= $h($analise['nome']) ?>">
                <i class="bi <?= $h($analise['icone']) ?>"></i><?= $h($analise['nome']) ?>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div id="page-content-wrapper">
        <div class="topbar">
            <button type="button" id="sidebarToggle" title="Mostrar/ocultar análises"><i class="bi bi-list"></i></button>
            <span class="titulo" id="analise-titulo"><?= $h($visiveis[$atual]['nome']) ?></span>
        </div>
        <iframe id="analise-frame" src="<?= $h($visiveis[$atual]['url']) ?>"></iframe>
    </div>
</div>

<script>
document.getElementById('sidebarToggle').addEventListener('click', () => {
    document.getElementById('wrapper').classList.toggle('sidebar-collapsed');
});

document.querySelectorAll('.sidebar-item[data-chave]').forEach(link => {
    link.addEventListener('click', e => {
        e.preventDefault();
        document.querySelectorAll('.sidebar-item.active').forEach(a => a.classList.remove('active'));
        link.classList.add('active');
        document.getElementById('analise-frame').src = link.dataset.url;
        document.getElementById('analise-titulo').textContent = link.dataset.nome;
        history.replaceState(null, '', '?a=' + encodeURIComponent(link.dataset.chave));
    });
});
</script>
<?php endif; ?>

</body>
</html>
