<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';
session_start();

// Mostrar erros para depuração (remova em produção)
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

// Só admin acessa
if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php');
    exit;
}


// Carrega itens do menu_config
$itens = [];
$sql   = "SELECT * FROM menu_config ORDER BY ordem ASC";
$res   = $mysqli->query($sql);
if (!$res) {
    die('Erro na consulta: '.$mysqli->error);
}
while ($row = $res->fetch_assoc()) {
    $itens[] = $row;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Configurações de Layout</title>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.3/jquery-ui.min.js"></script>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.3/themes/base/jquery-ui.css">
<style>
body{
    font-family:system-ui,sans-serif;
    padding:20px;
    background:#f4f4f4;
}
h1{font-size:1.4rem;margin-bottom:1rem;}
#lista-menu{
    list-style:none;
    padding:0;
    margin:0;
    max-width:400px;
}
#lista-menu li{
    padding:8px 10px;
    margin-bottom:6px;
    background:#fff;
    border:1px solid #ccc;
    border-radius:6px;
    cursor:move;
}
#lista-menu li i{margin-right:8px;}
.btn-salvar{
    margin-top:10px;
    padding:8px 14px;
    border:none;
    border-radius:999px;
    background:#2347c8;
    color:#fff;
    font-weight:600;
    cursor:pointer;
}
.info{
    font-size:.85rem;
    color:#555;
    margin-bottom:1rem;
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
<h1>Configurações de layout do menu</h1>
<p class="info">
    Arraste os itens abaixo para mudar a ordem em que aparecem no sidebar do sistema.
</p>

<ul id="lista-menu">
    <?php foreach ($itens as $item): ?>
        <li data-id="<?php echo (int)$item['id']; ?>">
            <i class="<?php echo htmlspecialchars($item['icone'], ENT_QUOTES, 'UTF-8'); ?>"></i>
            <?php echo htmlspecialchars($item['rotulo'], ENT_QUOTES, 'UTF-8'); ?>
            (<?php echo htmlspecialchars($item['chave'], ENT_QUOTES, 'UTF-8'); ?>)
        </li>
    <?php endforeach; ?>
</ul>

<button class="btn-salvar" id="btn-salvar">Salvar ordem</button>

<script>
$(function(){
    // habilita drag-and-drop
    $("#lista-menu").sortable();

    $("#btn-salvar").on("click", function(){
        var ordem = [];
        $("#lista-menu li").each(function(){
            ordem.push($(this).data("id"));
        });

        $.post("salvar_ordem_menu.php", { ordem: ordem }, function(resp){
            alert(resp);
        }).fail(function(){
            alert("Erro ao salvar ordem.");
        });
    });
});
</script>
<div class="app-version-label">
    <?php echo VERSAO_CONFIGURACOES; ?>
</div>
</body>
</html>
