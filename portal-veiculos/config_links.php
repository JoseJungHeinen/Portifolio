<?php
require_once __DIR__ . '/config_database.php';
require_once __DIR__ . '/config_versao.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

/* ========= EXCLUIR LINK ========= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['acao'])
    && $_POST['acao'] === 'excluir'
    && !empty($_POST['id_excluir'])
) {
    $idExc = (int)$_POST['id_excluir'];

    $stmtDel = $mysqli->prepare("DELETE FROM links_config WHERE id = ?");
    $stmtDel->bind_param('i', $idExc);
    $stmtDel->execute();
    $stmtDel->close();

    header('Location: config_links.php');
    exit;
}

/* ========= NOVO LINK ========= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['acao'])
    && $_POST['acao'] === 'novo'
) {
    $grupo      = trim($_POST['grupo'] ?? 'interno');
    $tituloNovo = trim($_POST['novo_titulo'] ?? 'Novo link');
    $urlNova    = trim($_POST['novo_url'] ?? '#');
    $descNova   = trim($_POST['novo_descricao'] ?? '');

    $stmtOrd = $mysqli->prepare(
        "SELECT COALESCE(MAX(ordem),0)+1 AS prox
         FROM links_config
         WHERE grupo = ?"
    );
    $stmtOrd->bind_param('s', $grupo);
    $stmtOrd->execute();
    $stmtOrd->bind_result($proxOrdem);
    $stmtOrd->fetch();
    $stmtOrd->close();

    $stmt = $mysqli->prepare(
        "INSERT INTO links_config (titulo, url, descricao, grupo, ordem, ativo)
         VALUES (?, ?, ?, ?, ?, 1)"
    );
    $stmt->bind_param('ssssi', $tituloNovo, $urlNova, $descNova, $grupo, $proxOrdem);
    $stmt->execute();
    $stmt->close();

    header('Location: config_links.php');
    exit;
}

/* ========= SALVAR ORDEM ========= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['acao'])
    && $_POST['acao'] === 'salvar_ordem'
    && !empty($_POST['ordem'])
) {
    $ordem = $_POST['ordem'];

    if (!is_array($ordem)) {
        die('Erro: ordem não é array.');
    }

    $stmt = $mysqli->prepare(
        "UPDATE links_config
         SET ordem = ?
         WHERE id = ?"
    );
    if (!$stmt) {
        die('Erro prepare: ' . $mysqli->error);
    }

    $pos = 1;
    foreach ($ordem as $id) {
        $id_int = (int)$id;
        $stmt->bind_param('ii', $pos, $id_int);
        if (!$stmt->execute()) {
            die('Erro execute id ' . $id_int . ': ' . $stmt->error);
        }
        $pos++;
    }
    $stmt->close();

    header('Location: config_links.php');
    exit;
}

/* ========= CARREGAR LINKS ========= */
$sql = "SELECT id, titulo, url, grupo, ativo, descricao, ordem
        FROM links_config
        ORDER BY grupo ASC, ordem ASC, id ASC";

$res = $mysqli->query($sql);
$links = [];
while ($row = $res->fetch_assoc()) {
    $links[] = $row;
}

$grupos = [];
foreach ($links as $lk) {
    $grupos[$lk['grupo']][] = $lk;
}

$nomesGrupos = [
    'venda_veiculo'   => 'Venda Veículo',
    'cadastro'        => 'Cadastro',
    'interno'         => 'Interno',
    'monte_seu_carro' => 'Monte seu carro'
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Configuração de Links Úteis</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    background: #f3f4f6;
    color: #1a1a1a;
    min-height: 100vh;
    padding: 1.8rem 1rem 3rem;
}

.page-shell { max-width: 1280px; margin: 0 auto; }

/* ---- cabeçalho ---- */
.page-header {
    background: #fff;
    border: 1px solid #ebebeb;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,.06);
    padding: 1.2rem 1.8rem;
    margin-bottom: 1.4rem;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.page-header-icon {
    width: 48px; height: 48px; min-width: 48px;
    background: #cc0000;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
}
.page-header-text h1 { font-size: 1.15rem; font-weight: 700; color: #111; }
.page-header-text p  { font-size: .8rem; color: #999; margin-top: 3px; }
.page-header-actions { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }

/* ---- card padrão ---- */
.card {
    background: #fff;
    border: 1px solid #ebebeb;
    border-radius: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,.05);
}
.card-head {
    padding: .9rem 1.4rem;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.card-head h2 {
    font-size: .9rem;
    font-weight: 700;
    color: #111;
    display: flex;
    align-items: center;
    gap: 8px;
}
.card-head h2::before {
    content: '';
    display: inline-block;
    width: 3px; height: 16px;
    background: #cc0000;
    border-radius: 999px;
    flex-shrink: 0;
}
.card-body { padding: 1.2rem 1.4rem; }

/* ---- formulário de novo link ---- */
.novo-link-form {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}
.novo-link-form .field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.novo-link-form .field label { font-size: .76rem; font-weight: 600; color: #666; }
.novo-link-form .field input,
.novo-link-form .field select {
    padding: 8px 14px;
    border: 1px solid #ddd;
    border-radius: 999px;
    font-size: .85rem;
    font-family: inherit;
    background: #fafafa;
    color: #1a1a1a;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.novo-link-form .field input:focus,
.novo-link-form .field select:focus {
    border-color: #cc0000;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(204,0,0,.08);
}
.field-grupo   { flex: 0 0 160px; }
.field-titulo  { flex: 1 1 180px; }
.field-url     { flex: 2 1 240px; }
.field-desc    { flex: 2 1 220px; }

/* ---- botões ---- */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 18px;
    border-radius: 999px;
    border: 1px solid transparent;
    font-size: .85rem;
    font-weight: 700;
    cursor: pointer;
    font-family: inherit;
    white-space: nowrap;
    transition: background .13s, transform .1s;
    text-decoration: none;
}
.btn:active { transform: translateY(1px); }
.btn-primary { background: #cc0000; color: #fff; box-shadow: 0 3px 10px rgba(204,0,0,.25); }
.btn-primary:hover { background: #aa0000; }
.btn-outline { background: #fff; color: #555; border-color: #ddd; }
.btn-outline:hover { background: #f5f5f5; }
.btn-sm { padding: 5px 12px; font-size: .76rem; }

/* ---- grade de grupos ---- */
.grupos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
}

/* ---- coluna de grupo ---- */
.grupo-col {
    background: #fff;
    border: 1px solid #ebebeb;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
    display: flex;
    flex-direction: column;
}
.grupo-head {
    padding: .75rem 1.1rem;
    background: #fafafa;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.grupo-head-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: .85rem;
    font-weight: 700;
    color: #111;
}
.grupo-icon {
    width: 30px; height: 30px;
    background: #cc0000;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.grupo-count {
    font-size: .72rem;
    background: #fee2e2;
    color: #cc0000;
    padding: 2px 8px;
    border-radius: 999px;
    font-weight: 700;
}
.grupo-body {
    padding: .75rem;
    flex: 1;
}

/* ---- item de link ---- */
.lista-links { list-style: none; display: flex; flex-direction: column; gap: 6px; }

.link-item {
    background: #fafafa;
    border: 1px solid #ebebeb;
    border-radius: 10px;
    padding: 7px 9px;
    cursor: grab;
    transition: box-shadow .15s, border-color .15s, background .15s;
    user-select: none;
}
.link-item:active { cursor: grabbing; }
.link-item.dragging {
    opacity: .5;
    box-shadow: 0 8px 24px rgba(0,0,0,.14);
}
.link-item.drag-over {
    border-color: #cc0000;
    background: #fff8f8;
}
.link-item.is-collapsed .link-item-body { display: none; }
.link-item.is-collapsed { padding-bottom: 7px; }

/* linha superior compacta */
.link-item-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    min-height: 34px;
}
.link-item-top-left {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    flex: 1;
}
.link-item-top-right {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}
.drag-handle {
    color: #ccc;
    cursor: grab;
    font-size: 1rem;
    line-height: 1;
    flex-shrink: 0;
}
.card-toggle-btn {
    width: 28px;
    height: 28px;
    border: 1px solid #e5e7eb;
    background: #fff;
    color: #555;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: .95rem;
    transition: background .12s, border-color .12s, transform .12s;
}
.card-toggle-btn:hover {
    background: #f5f5f5;
    border-color: #d1d5db;
}
.link-item:not(.is-collapsed) .card-toggle-btn { transform: rotate(180deg); }

.link-item-title {
    font-size: .8rem;
    font-weight: 700;
    color: #1a1a1a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
}
.badge-ativo,
.badge-inativo {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: .68rem;
    font-weight: 700;
    padding: 2px 9px;
    border-radius: 999px;
    white-space: nowrap;
}
.badge-ativo { background: #dcfce7; color: #16a34a; }
.badge-inativo { background: #fee2e2; color: #b91c1c; }

.link-item-body {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #efefef;
}
.link-item-actions {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 8px;
}
.btn-excluir {
    border: 1px solid #fecaca;
    background: #fff8f8;
    color: #b91c1c;
    border-radius: 999px;
    padding: 2px 10px;
    font-size: .7rem;
    font-weight: 700;
    cursor: pointer;
    font-family: inherit;
    transition: background .12s;
}
.btn-excluir:hover { background: #fee2e2; }

/* campos do item */
.link-fields { display: flex; flex-direction: column; gap: 4px; }
.link-fields input[type="text"] {
    width: 100%;
    height: 30px;
    min-height: 0 !important;
    max-height: 30px;
    padding: 0 11px;
    border: 1px solid #eee;
    border-radius: 8px;
    font-size: .8rem;
    font-family: inherit;
    background: #fff;
    color: #1a1a1a;
    box-sizing: border-box;
    display: block;
    line-height: 30px;
    transition: border-color .13s;
}
.link-fields input[type="text"]:focus {
    outline: none;
    border-color: #cc0000;
    box-shadow: 0 0 0 2px rgba(204,0,0,.07);
}
.link-fields input[type="text"]::placeholder { color: #ccc; }
.link-fields .field-url {
    font-family: ui-monospace, 'Courier New', monospace;
    font-size: .74rem;
    color: #555;
    background: #f8f8f8;
}

.link-check {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: .76rem;
    color: #666;
    font-weight: 600;
    cursor: pointer;
    margin-top: 2px;
}
.link-check input[type="checkbox"] { accent-color: #cc0000; width: 14px; height: 14px; cursor: pointer; }

/* ---- barra de salvar fixa ---- */
.save-bar {
    position: sticky;
    bottom: 1.2rem;
    display: flex;
    justify-content: center;
    pointer-events: none;
    margin-top: 1.4rem;
}
.save-bar-inner {
    background: #fff;
    border: 1px solid #ebebeb;
    border-radius: 999px;
    padding: 10px 20px;
    box-shadow: 0 8px 30px rgba(0,0,0,.12);
    display: flex;
    align-items: center;
    gap: 12px;
    pointer-events: all;
}
.save-bar-inner span { font-size: .82rem; color: #888; }

/* ---- versão ---- */
.app-version-label {
    position: fixed; bottom: 6px; right: 10px;
    font-size: .7rem; color: #bbb;
    pointer-events: none; z-index: 999;
}

/* ---- responsivo ---- */
@media (max-width: 768px) {
    body { padding: 0 0 3rem; }
    .page-header { border-radius: 0; }
    .card { border-radius: 0; }
    .grupos-grid { grid-template-columns: 1fr; gap: 12px; }
    .grupo-col { border-radius: 10px; }
    .novo-link-form .field { flex: 1 1 100%; }
    .field-grupo { flex: 0 0 100%; }
    .link-item-top { align-items: flex-start; }
    .link-item-top-right { margin-left: auto; }
    .link-item-title { white-space: normal; }
}
</style>
</head>
<body>
<div class="page-shell">

    <!-- Cabeçalho -->
    <div class="page-header">
        <div class="page-header-icon">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24">
                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <div class="page-header-text">
            <h1>Configuração de Links Úteis</h1>
            <p>Adicione, edite, ordene e organize os links exibidos no sistema</p>
        </div>
        <div class="page-header-actions">
            <a href="menu.php" class="btn btn-outline btn-sm">← Voltar ao Menu</a>
        </div>
    </div>

    <!-- Formulário: adicionar novo link -->
    <div class="card" style="margin-bottom:1.4rem;">
        <div class="card-head"><h2>Novo link</h2></div>
        <div class="card-body">
            <form method="post" class="novo-link-form">
                <input type="hidden" name="acao" value="novo">

                <div class="field field-grupo">
                    <label>Grupo</label>
                    <select name="grupo">
                        <option value="monte_seu_carro">Monte seu carro</option>
                        <option value="venda_veiculo">Venda Veículo</option>
                        <option value="cadastro">Cadastro</option>
                        <option value="interno">Interno</option>
                    </select>
                </div>

                <div class="field field-titulo">
                    <label>Título</label>
                    <input type="text" name="novo_titulo" placeholder="Ex: Portal Banco X" required>
                </div>

                <div class="field field-url">
                    <label>URL</label>
                    <input type="text" name="novo_url" placeholder="https://..." required>
                </div>

                <div class="field field-desc">
                    <label>Descrição</label>
                    <input type="text" name="novo_descricao" placeholder="Descrição exibida no menu">
                </div>

                <div style="flex:0 0 auto;align-self:flex-end;">
                    <button type="submit" class="btn btn-primary">+ Adicionar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Form principal: salva ordem, títulos, urls, descrições e ativo -->
    <form id="form-ordem" method="post">
        <input type="hidden" name="acao" value="salvar_ordem">

        <div class="grupos-grid">
            <?php foreach ($grupos as $grupo => $itens):
                if ($grupo === '' || $grupo === null) continue;
                $tituloG = $nomesGrupos[$grupo] ?? $grupo;
                $totalG  = count($itens);
            ?>
            <div class="grupo-col">
                <div class="grupo-head">
                    <div class="grupo-head-title">
                        <div class="grupo-icon">
                            <svg width="15" height="15" fill="none" viewBox="0 0 24 24">
                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <?= htmlspecialchars($tituloG, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <span class="grupo-count"><?= $totalG ?> link<?= $totalG !== 1 ? 's' : '' ?></span>
                </div>
                <div class="grupo-body">
                    <ul class="lista-links" data-grupo="<?= htmlspecialchars($grupo, ENT_QUOTES, 'UTF-8') ?>">
                        <?php foreach ($itens as $lk): ?>
                        <li class="link-item is-collapsed" draggable="true" data-id="<?= (int)$lk['id'] ?>">
                            <div class="link-item-top">
                                <div class="link-item-top-left">
                                    <span class="drag-handle" title="Arrastar para reordenar">⠿</span>
                                    <button type="button" class="card-toggle-btn" title="Expandir ou recolher card" aria-expanded="false">⌄</button>
                                    <span class="link-item-title"><?= htmlspecialchars($lk['titulo'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>

                                <div class="link-item-top-right">
                                    <?php if ($lk['ativo']): ?>
                                        <span class="badge-ativo">● Ativo</span>
                                    <?php else: ?>
                                        <span class="badge-inativo">● Inativo</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="link-item-body">
                                <div class="link-item-actions">
                                    <button type="button" class="btn-excluir" onclick="excluirLink(<?= (int)$lk['id'] ?>)">
                                        Excluir
                                    </button>
                                </div>

                                <div class="link-fields">
                                    <input type="text"
                                           name="titulo[<?= (int)$lk['id'] ?>]"
                                           value="<?= htmlspecialchars($lk['titulo'], ENT_QUOTES, 'UTF-8') ?>"
                                           placeholder="Título do link">

                                    <input type="text"
                                           class="field-url"
                                           name="url[<?= (int)$lk['id'] ?>]"
                                           value="<?= htmlspecialchars($lk['url'], ENT_QUOTES, 'UTF-8') ?>"
                                           placeholder="https://...">

                                    <input type="text"
                                           name="descricao[<?= (int)$lk['id'] ?>]"
                                           value="<?= htmlspecialchars($lk['descricao'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                           placeholder="Descrição para aparecer no menu">

                                    <label class="link-check">
                                        <input type="checkbox"
                                               name="ativo[<?= (int)$lk['id'] ?>]"
                                               value="1" <?= $lk['ativo'] ? 'checked' : '' ?>>
                                        Exibir link no menu
                                    </label>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div id="hidden-ordem"></div>

        <!-- Barra de salvar fixa -->
        <div class="save-bar">
            <div class="save-bar-inner">
                <span>Arraste os cards para reordenar os links dentro de cada grupo</span>
                <button type="submit" class="btn btn-primary">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" stroke="white" stroke-width="2.2" stroke-linecap="round"/>
                        <polyline points="17 21 17 13 7 13 7 21" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="7 3 7 8 15 8" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Salvar alterações
                </button>
            </div>
        </div>
    </form>

    <!-- Form oculto para exclusão -->
    <form id="form-excluir" method="post" style="display:none;">
        <input type="hidden" name="acao" value="excluir">
        <input type="hidden" name="id_excluir" id="id_excluir_hidden">
    </form>

</div><!-- /page-shell -->

<div class="app-version-label">
    <?php echo VERSAO_CONFIG_LINKS; ?>
</div>

<script>
// ===== DRAG & DROP =====
document.querySelectorAll('.lista-links').forEach(lista => {
    let dragged = null;

    lista.addEventListener('dragstart', e => {
        dragged = e.target.closest('li');
        if (!dragged) return;
        requestAnimationFrame(() => dragged.classList.add('dragging'));
        e.dataTransfer.effectAllowed = 'move';
    });

    lista.addEventListener('dragend', () => {
        if (dragged) dragged.classList.remove('dragging');
        lista.querySelectorAll('li').forEach(li => li.classList.remove('drag-over'));
        dragged = null;
    });

    lista.addEventListener('dragover', e => {
        e.preventDefault();
        const target = e.target.closest('li');
        if (!target || target === dragged) return;

        lista.querySelectorAll('li').forEach(li => li.classList.remove('drag-over'));
        target.classList.add('drag-over');

        const rect = target.getBoundingClientRect();
        if (e.clientY - rect.top > rect.height / 2) {
            target.after(dragged);
        } else {
            target.before(dragged);
        }
    });

    lista.addEventListener('dragleave', e => {
        const target = e.target.closest('li');
        if (target) target.classList.remove('drag-over');
    });

    lista.addEventListener('drop', e => {
        e.preventDefault();
        lista.querySelectorAll('li').forEach(li => li.classList.remove('drag-over'));
    });
});

// ===== MONTAR ordem[] antes de enviar =====
document.getElementById('form-ordem').addEventListener('submit', function () {
    const hidden = document.getElementById('hidden-ordem');
    hidden.innerHTML = '';
    document.querySelectorAll('.lista-links li').forEach(li => {
        const id = li.getAttribute('data-id');
        if (!id) return;
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'ordem[]';
        inp.value = id;
        hidden.appendChild(inp);
    });
});

// ===== EXCLUSÃO via form separado =====
function excluirLink(id) {
    if (!confirm('Excluir este link?')) return;
    document.getElementById('id_excluir_hidden').value = id;
    document.getElementById('form-excluir').submit();
}

// ===== EXPANDIR / RECOLHER cards =====
document.querySelectorAll('.card-toggle-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        const item = this.closest('.link-item');
        if (!item) return;
        item.classList.toggle('is-collapsed');
        this.setAttribute('aria-expanded', item.classList.contains('is-collapsed') ? 'false' : 'true');
    });
});

// ===== ESPELHAR título do primeiro campo no cabeçalho do card =====
document.querySelectorAll('.link-item').forEach(item => {
    const inputTitulo = item.querySelector('input[name^="titulo["]');
    const tituloLabel = item.querySelector('.link-item-title');
    if (!inputTitulo || !tituloLabel) return;

    const atualizarTitulo = () => {
        const valor = inputTitulo.value.trim();
        tituloLabel.textContent = valor !== '' ? valor : 'Sem título';
    };

    inputTitulo.addEventListener('input', atualizarTitulo);
    atualizarTitulo();
});

// ===== ATUALIZAR BADGE ao clicar no checkbox ativo =====
document.querySelectorAll('.link-check input[type="checkbox"]').forEach(cb => {
    cb.addEventListener('change', function () {
        const item  = this.closest('.link-item');
        const badge = item.querySelector('.badge-ativo, .badge-inativo');
        if (!badge) return;
        if (this.checked) {
            badge.className = 'badge-ativo';
            badge.textContent = '● Ativo';
        } else {
            badge.className = 'badge-inativo';
            badge.textContent = '● Inativo';
        }
    });
});
</script>
</body>
</html>