<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';
session_start();
if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    exit('Acesso negado');
}


/* ===== SALVAR: ATUALIZAR COLUNA ORDEM ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar') {
    $ordens = $_POST['ordem'] ?? [];

    if (is_array($ordens) && !empty($ordens)) {
        $mysqli->begin_transaction();
        try {
            $stmt = $mysqli->prepare("
                UPDATE menu_config
                   SET ordem = ?
                 WHERE id = ?
            ");
            if (!$stmt) {
                throw new Exception('Erro no prepare: '.$mysqli->error);
            }

            foreach ($ordens as $idMenu => $ordemMenu) {
                $id  = (int)$idMenu;
                $ord = (int)$ordemMenu;

                $stmt->bind_param('ii', $ord, $id);
                $stmt->execute();
            }
            $stmt->close();

            $mysqli->commit();
            echo 'OK';
        } catch (Throwable $e) {
            $mysqli->rollback();
            echo 'Erro MySQL: '.$e->getMessage();
        }
    } else {
        echo 'Nenhum dado de ordem recebido.';
    }
    exit;
}

/* ===== CARREGAR ITENS DO MENU =====
   Só entram aqui itens que são, de fato, um menu próprio na barra lateral.
   Chaves que só existem para alimentar um sub-item dentro de outro menu
   (ex.: acerto_locacao/inventario_pecas dentro de Peças, apresentacao_carros/
   bancos dentro de Veiculos) ou que são legado sem efeito no menu.php
   (bi_locadora/bi_financeiro/bi_vendas, ferramentas) ficam de fora, pois
   reordená-las aqui não muda nada na barra lateral. */
$chavesNaoTopo = [
    'apresentacao_carros', 'bancos', 'acerto_locacao', 'inventario_pecas',
    'bi_locadora', 'bi_financeiro', 'bi_vendas',
];
$placeholders = implode(',', array_fill(0, count($chavesNaoTopo), '?'));
$sql = "SELECT id, rotulo, chave, icone, ordem
        FROM menu_config
        WHERE chave NOT IN ($placeholders)
        ORDER BY ordem ASC, id ASC";
$menus = [];
$stmtMenu = $mysqli->prepare($sql);
if ($stmtMenu) {
    $stmtMenu->bind_param(str_repeat('s', count($chavesNaoTopo)), ...$chavesNaoTopo);
    $stmtMenu->execute();
    $res = $stmtMenu->get_result();
    while ($row = $res->fetch_assoc()) {
        $menus[] = $row;
    }
    $stmtMenu->close();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Configurar menu lateral</title>
<style>
.menu-config-container{
    max-width:700px;
    margin:0 auto;
    font-family:system-ui,sans-serif;
    font-size:.9rem;
}
.card-menu{
    background:#fff;
    border-radius:14px;
    box-shadow:0 6px 15px rgba(0,0,0,0.08);
    padding:14px 18px;
    border:1px solid #eee;
}
.menu-config-lista{
    list-style:none;
    margin:0;
    padding:0;
}
.item-menu{
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:6px;
    font-size:.88rem;
    padding:4px 6px;
    border-radius:6px;
    border:1px solid #eee;
    background:#fafafa;
}
.drag-handle{
    cursor:grab;
    user-select:none;
}
.item-menu-icone{
    width:22px;
    text-align:center;
}
.btn-salvar-menu{
    margin-top:12px;
    padding:8px 14px;
    border:none;
    border-radius:999px;
    background:#2347c8;
    color:#fff;
    font-weight:600;
    cursor:pointer;
}
.menu-msg{
    margin-top:6px;
    font-size:.85rem;
}
.drag-ghost{
    opacity:.4;
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
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
</head>
<body>

<div class="menu-config-container">
    <div class="card-menu">
        <form id="form-menu" method="post">
            <ul class="menu-config-lista" id="lista-menu">
                <?php foreach ($menus as $m): ?>
                    <li class="item-menu" data-id="<?= (int)$m['id']; ?>">
                        <span class="drag-handle">☰</span>
                        <span class="item-menu-icone">
                            <i class="<?= htmlspecialchars($m['icone'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                        </span>
                        <strong><?= htmlspecialchars($m['rotulo'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span style="color:#888;font-size:.8rem;">(<?= htmlspecialchars($m['chave'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <input type="hidden" name="acao" value="salvar">
            <button type="submit" class="btn-salvar-menu">Salvar ordem do menu</button>
            <div class="menu-msg" id="menu-msg"></div>
        </form>
    </div>
</div>

<script>
// deixar lista ordenável
new Sortable(document.getElementById('lista-menu'), {
    animation: 150,
    handle: '.drag-handle',
    ghostClass: 'drag-ghost'
});

var formMenu = document.getElementById('form-menu');

if (formMenu) {
    formMenu.addEventListener('submit', function(e){
        e.preventDefault();

        // remove ordens antigas se existirem
        Array.from(formMenu.querySelectorAll('input[name^="ordem["]')).forEach(function(el){
            el.parentNode.removeChild(el);
        });

        // gera ordem[id] pela posição dos <li>
        var itens = document.querySelectorAll('#lista-menu .item-menu');
        itens.forEach(function(li, index){
            var id = li.getAttribute('data-id');
            if (!id) return;
            var hidden = document.createElement('input');
            hidden.type  = 'hidden';
            hidden.name  = 'ordem['+id+']';
            hidden.value = index + 1; // começa em 1
            formMenu.appendChild(hidden);
        });

        var fd = new FormData(formMenu);
        fd.set('acao','salvar');

        fetch('config_menu.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.text())
        .then(txt => {
            const msg = document.getElementById('menu-msg');
            if (txt.trim() === 'OK') {
                msg.textContent = 'Menu salvo com sucesso. Recarregue o sistema.';
            } else {
                msg.textContent = 'Erro ao salvar: ' + txt;
            }
        })
        .catch(err => {
            document.getElementById('menu-msg').textContent = 'Erro: ' + err;
        });
    });
}
</script>
<div class="app-version-label">
    <?php echo VERSAO_CONFIG_MENU; ?>
</div>
</body>
</html>
