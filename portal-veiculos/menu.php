<?php
declare(strict_types=1);

require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';

if (session_status() == PHP_SESSION_NONE) {
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

$nome        = $_SESSION['nome'] ?? 'Usuário';
$is_admin    = !empty($_SESSION['is_admin']);
$is_gestor   = isset($_SESSION['is_gestor']) && (int)$_SESSION['is_gestor'] === 1;
$setor       = isset($_SESSION['setor']) ? strtolower(trim($_SESSION['setor'])) : '';
$id_setor    = isset($_SESSION['id_setor']) ? (int)$_SESSION['id_setor'] : 0;
$usuario_id  = (int)($_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? 0);

$is_locadora    = ($setor === 'locadora'      || $id_setor === 4);
$is_financeiro  = ($setor === 'financeiro'    || $id_setor === 5);
$is_oficina     = ($setor === 'oficina'       || $id_setor === 6);
$is_pecas       = ($setor === 'pecas'         || $id_setor === 7);
$is_vendas      = ($setor === 'vendas'        || $id_setor === 1);
$is_vendadireta = ($setor === 'venda direta'  || $id_setor === 8);
$is_documental  = ($setor === 'documental'    || $id_setor === 9);
$is_rh          = ($setor === 'rh'             || $id_setor === 10);


$perms = [];
if (!$is_admin && $usuario_id > 0) {
    $stmtP = $mysqli->prepare("SELECT * FROM usuarios_permissoes WHERE usuario_id = ? LIMIT 1");
    if ($stmtP) {
        $stmtP->bind_param('i', $usuario_id);
        $stmtP->execute();
        $rowP = $stmtP->get_result()->fetch_assoc();
        $stmtP->close();
        if ($rowP) {
            $perms = $rowP;
        }
    }
}

function temPermissao(string $chave): bool {
    global $is_admin, $perms;
    if ($is_admin) return true;
    return !empty($perms[$chave]);
}

$abrir          = $_GET['abrir'] ?? '';
$autoFerramenta = '';
if ($abrir === 'documentos') {
    $autoFerramenta = 'index.blade.documentos.php';
}

$hoje   = new DateTime();
$ano    = (int)$hoje->format('Y');
$inicio = new DateTime($ano . '-02-01');
$fim    = new DateTime($ano . '-03-01');

if (!isset($_SESSION['reajuste_' . $ano])) {
    $_SESSION['reajuste_' . $ano] = false;
    if ($usuario_id > 0) {
        $stmt = $mysqli->prepare("SELECT confirmado FROM reajuste_confirmado WHERE usuario_id = ? AND ano = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $usuario_id, $ano);
            $stmt->execute();
            $stmt->bind_result($confirmado);
            if ($stmt->fetch()) {
                $_SESSION['reajuste_' . $ano] = (bool)$confirmado;
            }
            $stmt->close();
        }
    }
}

if (isset($_POST['reajuste_ok'])) {
    $_SESSION['reajuste_' . $ano] = true;
    if ($usuario_id > 0) {
        $stmt = $mysqli->prepare(
            "INSERT INTO reajuste_confirmado (usuario_id, ano, confirmado)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE confirmado = VALUES(confirmado)"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $usuario_id, $ano);
            $stmt->execute();
            $stmt->close();
        }
    }
    header('Location: menu.php');
    exit;
}

$itens_menu = [];
$res = $mysqli->query("SELECT id, rotulo, chave, icone, ordem FROM menu_config ORDER BY ordem ASC");
while ($row = $res->fetch_assoc()) {
    $itens_menu[] = $row;
}

$mostrarAviso = $is_admin && $hoje >= $inicio && $hoje <= $fim && empty($_SESSION['reajuste_' . $ano]);

$muralImagens = [];
$resB = $mysqli->query("SELECT caminho, COALESCE(titulo,'') AS titulo FROM menu_banners ORDER BY criado_em DESC, id DESC LIMIT 3");
if ($resB) {
    while ($row = $resB->fetch_assoc()) {
        $muralImagens[] = [
            'src' => $row['caminho'],
            'alt' => $row['titulo'] ?: 'Comunicado interno'
        ];
    }
}

$basePath = '/Veiculos';
if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] === 'veiculos.test') {
    $basePath = '';
}

// ── Passa permissões para o bi_menu.php via sessão ──────────────────────────
$_SESSION['perms'] = $perms;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sistema de Ferramentas Exemplo</title>
<link rel="icon" type="image/png" href="/img/logo.svg?v=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

:root {
    --sidebar-w: 250px;
    --sidebar-collapsed: 62px;
    --red: #2347C8;
    --gray-dark: #5A5A5A;
    --transition: 0.28s cubic-bezier(.4,0,.2,1);
}

body{
    font-family: system-ui, sans-serif;
    background:#fff;
    min-height:100vh;
    color:#333;
    display:flex;
}

.sidebar{
    width:var(--sidebar-w);
    background:var(--gray-dark);
    min-height:100vh;
    display:flex;
    flex-direction:column;
    padding:0;
    box-shadow:2px 0 10px rgba(0,0,0,.15);
    position:fixed;
    left:0; top:0; bottom:0;
    z-index:1000;
    transition:width var(--transition);
    overflow:hidden;
}
.sidebar.collapsed{ width:var(--sidebar-collapsed); }

.sidebar-toggle{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    padding:10px 14px;
    cursor:pointer;
    border:none;
    background:none;
    color:rgba(255,255,255,.7);
    font-size:1.1rem;
    width:100%;
    transition:color .2s;
    flex-shrink:0;
}
.sidebar-toggle:hover{ color:#fff; }
.sidebar-toggle .toggle-icon{ transition:transform var(--transition); }
.sidebar.collapsed .sidebar-toggle .toggle-icon{ transform:rotate(180deg); }

.sidebar-logo{
    display:flex;
    align-items:center;
    justify-content:center;
    padding:0 14px 1rem;
    flex-shrink:0;
    overflow:hidden;
}
.sidebar-logo img{
    width:120px;
    height:auto;
    transition:width var(--transition), opacity var(--transition);
}
.sidebar.collapsed .sidebar-logo img{
    width:34px;
    opacity:.85;
}

.sidebar-user-name{
    text-align:center;
    color:rgba(255,255,255,.85);
    font-size:.95rem;
    font-weight:600;
    padding:0 14px .9rem;
    white-space:normal;
    overflow:hidden;
    opacity:1;
    transition:opacity var(--transition), max-height var(--transition);
    max-height:60px;
}
.sidebar.collapsed .sidebar-user-name{
    opacity:0;
    max-height:0;
    padding-bottom:0;
}

.sidebar-menu{
    list-style:none;
    width:100%;
    flex:1;
    overflow-y:auto;
    overflow-x:hidden;
    padding:0 5px;
}
.sidebar-menu::-webkit-scrollbar{ width:4px; }
.sidebar-menu::-webkit-scrollbar-thumb{
    background:var(--red);
    border-radius:10px;
}

.sidebar-item{ width:100%; margin-bottom:.5rem; }

.sidebar-link{
    width:100%;
    height:44px;
    display:flex;
    align-items:center;
    padding:0 12px;
    color:rgba(255,255,255,.85);
    text-decoration:none;
    transition:background .2s,color .2s;
    cursor:pointer;
    border-radius:6px;
    border:none;
    background:none;
    font-family:inherit;
    white-space:nowrap;
    overflow:hidden;
}
.sidebar-link:hover{ background:var(--red); color:#fff; }
.sidebar-link.active{ background:var(--red); color:#fff; }

.sidebar-link i{
    font-size:1.1rem;
    min-width:36px;
    text-align:center;
    flex-shrink:0;
}
.sidebar-link span{
    font-size:.86rem;
    font-weight:600;
    opacity:1;
    transition:opacity var(--transition);
    overflow:hidden;
    text-overflow:ellipsis;
}
.sidebar.collapsed .sidebar-link span{
    opacity:0;
    width:0;
}

.sidebar.collapsed .sidebar-item{ position:relative; }
.sidebar.collapsed .sidebar-link:hover::after{
    content:attr(data-tooltip);
    position:absolute;
    left:calc(var(--sidebar-collapsed) + 6px);
    top:50%;
    transform:translateY(-50%);
    background:#222;
    color:#fff;
    padding:4px 10px;
    border-radius:5px;
    font-size:.78rem;
    white-space:nowrap;
    pointer-events:none;
    z-index:2000;
    box-shadow:0 2px 8px rgba(0,0,0,.25);
}

.sidebar-submenu{
    max-height:0;
    overflow:hidden;
    transition:max-height .4s,opacity .3s;
    background:rgba(0,0,0,.15);
    border-radius:6px;
    margin:.2rem 0 0;
    opacity:0;
}
.sidebar-submenu.open{
    max-height:1200px;
    padding:.3rem 0;
    opacity:1;
}
.sidebar.collapsed .sidebar-submenu{ display:none; }

.sidebar-submenu a{
    height:38px;
    font-size:.8rem;
    padding-left:46px;
    text-decoration:none;
    border-radius:4px;
    display:flex;
    align-items:center;
    color:rgba(255,255,255,.8);
    transition:background .2s;
    white-space:nowrap;
}
.sidebar-submenu a:hover{ background:var(--red); color:#fff; }
.sidebar-submenu a i{ font-size:.9rem; min-width:26px; text-align:center; }

.sidebar-footer{
    padding:.8rem 5px;
    border-top:1px solid rgba(255,255,255,.15);
    flex-shrink:0;
}

.main-content{
    margin-left:var(--sidebar-w);
    flex:1;
    width:calc(100% - var(--sidebar-w));
    display:flex;
    align-items:flex-start;
    justify-content:stretch;
    padding:20px;
    transition:margin-left var(--transition), width var(--transition);
}
body.sidebar-collapsed .main-content{
    margin-left:var(--sidebar-collapsed);
    width:calc(100% - var(--sidebar-collapsed));
}

.welcome{ max-width:100%; width:100%; text-align:center; }
.welcome h2{
    color:#5A5A5A;
    font-size:2.2rem;
    margin-bottom:1rem;
    font-weight:700;
}
.welcome p{
    color:#666;
    font-size:1.1rem;
    line-height:1.8;
    margin-bottom:1.5rem;
}

.slider-container{
    position:relative;
    max-width:750px;
    margin:0 auto 20px;
    overflow:hidden;
    border-radius:8px;
    box-shadow:0 2px 8px rgba(0,0,0,.15);
}
.slider{
    position:relative;
    width:100%;
    height:650px;
    background:#f4f4f4;
}
.slide{
    position:absolute;
    inset:0;
    opacity:0;
    transition:opacity .4s ease;
}
.slide img{
    width:100%;
    height:100%;
    object-fit:contain;
    background:#f4f4f4;
}
.slide.active{ opacity:1; }
.slider-btn{
    position:absolute;
    top:50%;
    transform:translateY(-50%);
    background:rgba(0,0,0,.4);
    color:#fff;
    border:none;
    padding:4px 10px;
    cursor:pointer;
    font-size:18px;
    border-radius:4px;
}
.slider-btn.prev{ left:8px; }
.slider-btn.next{ right:8px; }

.app-version-label{
    position:fixed;
    bottom:4px;
    right:8px;
    font-size:10px;
    color:#888;
    opacity:.6;
    font-family:system-ui,sans-serif;
    pointer-events:none;
    z-index:9999;
}

.reajuste-box{
    background:#fff3cd;
    border:1px solid #ffeeba;
    color:#856404;
    padding:10px 15px;
    margin-bottom:15px;
    border-radius:4px;
    font-size:.78rem;
}
.reajuste-box strong{ font-weight:700; }

@media(max-width:900px){
    .main-content{ padding:14px; }
}
</style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <button class="sidebar-toggle" onclick="toggleSidebar()" title="Expandir/Recolher menu">
        <i class="fas fa-chevron-left toggle-icon"></i>
    </button>

    <div class="sidebar-logo">
        <img src="/Veiculos/img/logo.svg" alt="Empresa Exemplo">
    </div>

   <div class="sidebar-user-name">
    <?php
      $partes = explode(' ', trim(htmlspecialchars($nome, ENT_QUOTES, 'UTF-8')));
      $meio   = (int)ceil(count($partes) / 2);
      $linha1 = implode(' ', array_slice($partes, 0, $meio));
      $linha2 = implode(' ', array_slice($partes, $meio));
    ?>
    <span style="display:block;line-height:1.3;"><?= $linha1 ?></span>
    <?php if ($linha2): ?>
    <span style="display:block;line-height:1.3;"><?= $linha2 ?></span>
    <?php endif; ?>
</div>

    <ul class="sidebar-menu">
        <li class="sidebar-item">
            <a href="menu.php" class="sidebar-link active" data-tooltip="Início">
                <i class="fas fa-home"></i><span>Início</span>
            </a>
        </li>

        <?php if ($mostrarAviso): ?>
        <li class="sidebar-item">
            <div class="reajuste-box">
                <strong>Atenção:</strong> reajuste de valores obrigatório entre 01/02 e 01/03.<br>
                <form method="post" style="display:inline;margin-top:6px;">
                    <button type="submit" name="reajuste_ok" value="1"
                            style="padding:4px 10px;border:0;border-radius:3px;background:#28a745;color:#fff;cursor:pointer;margin-top:6px;">
                        Já fiz o reajuste
                    </button>
                </form>
            </div>
        </li>
        <?php endif; ?>

        <?php
        /* ── PASSO 1: pré-calcula visibilidade dos itens "filhos", que não têm
           linha própria no sidebar e podem estar em qualquer posição de ordem
           em relação ao item "pai" (Peças, Veiculos) que os agrupa. ── */
        $show_apresentacao_carros = false;
        $show_bancos              = false;
        $show_acerto_locacao      = false;
        $show_inventario_pecas    = false;
        $temLinhaBi               = false;

        foreach ($itens_menu as $item) {
            $chaveP = $item['chave'];
            if ($chaveP === 'bi') { $temLinhaBi = true; continue; }

            if ($is_admin) {
                $pv = true;
            } else {
                switch ($chaveP) {
                    case 'apresentacao_carros': $pv = temPermissao('menu_apresentacao_carros'); break;
                    case 'bancos':               $pv = temPermissao('menu_bancos'); break;
                    case 'acerto_locacao':       $pv = temPermissao('menu_pecas_acerto_locacao'); break;
                    case 'inventario_pecas':     $pv = temPermissao('menu_pecas_inventario'); break;
                    default:                     $pv = false;
                }
            }
            if ($chaveP === 'apresentacao_carros') $show_apresentacao_carros = $pv;
            if ($chaveP === 'bancos')              $show_bancos = $pv;
            if ($chaveP === 'acerto_locacao')      $show_acerto_locacao = $pv;
            if ($chaveP === 'inventario_pecas')    $show_inventario_pecas = $pv;
        }

        $show_ferramenta_transferencia = $is_admin || temPermissao('menu_ferramenta_transferencia');
        $show_ferramenta_emplacamento  = $is_admin || temPermissao('menu_ferramenta_emplacamento');
        $show_ferramenta_cartao        = $is_admin || temPermissao('menu_ferramenta_cartao');
        $show_ferramenta_documentos    = $is_admin || temPermissao('menu_ferramenta_documentos');
        $show_ferramenta_orcamento     = $is_admin || temPermissao('menu_ferramenta_orcamento');

        $show_veiculos = $show_apresentacao_carros || $show_bancos
            || $show_ferramenta_transferencia || $show_ferramenta_emplacamento
            || $show_ferramenta_cartao || $show_ferramenta_documentos || $show_ferramenta_orcamento;
        ?>

        <?php if (!$temLinhaBi): ?>
        <!-- ── BI DINÂMICO (sem linha 'bi' no menu_config — mantém posição fixa como fallback) ── -->
        <li class="sidebar-item" id="bi-menu-item" style="display:none;">
            <div class="sidebar-link" onclick="toggleSubmenu(this)" data-tooltip="Administrativo">
                <i class="fas fa-chart-bar"></i><span>Administrativo</span>
            </div>
            <div class="sidebar-submenu" id="bi-submenu">
                <div style="padding:8px 46px;color:rgba(255,255,255,.5);font-size:.78rem;" id="bi-loading">
                    <i class="fas fa-spinner fa-spin"></i> Carregando…
                </div>
            </div>
        </li>
        <?php endif; ?>

        <?php
        /* ── PASSO 2: desenha cada item na posição de 'ordem' definida no banco. ── */
        foreach ($itens_menu as $item):
            $chave  = $item['chave'];
            $rotulo = htmlspecialchars($item['rotulo'], ENT_QUOTES, 'UTF-8');
            $icone  = htmlspecialchars($item['icone'],  ENT_QUOTES, 'UTF-8');

            if ($chave === 'bi') {
                $pode_ver = true; // visibilidade real decidida via JS/bi_menu.php
            } elseif ($is_admin) {
                $pode_ver = true;
            } else {
                switch ($chave) {
                    case 'buscar':               $pode_ver = temPermissao('menu_buscar'); break;
                    case 'links':                $pode_ver = temPermissao('menu_links'); break;
                    case 'ramais':               $pode_ver = temPermissao('menu_ramais'); break;
                    case 'bancos':               $pode_ver = temPermissao('menu_bancos'); break;
                    case 'treinamentos':         $pode_ver = temPermissao('menu_treinamentos'); break;
                    case 'politicas':            $pode_ver = temPermissao('menu_politicas'); break;
                    case 'ferramentas':          $pode_ver = true; break; // visibilidade real decidida por $show_veiculos
                    case 'apresentacao_carros':  $pode_ver = temPermissao('menu_apresentacao_carros'); break;
                    case 'locadora':             $pode_ver = temPermissao('menu_locadora'); break;
                    case 'acessorios':           $pode_ver = temPermissao('menu_acessorios'); break;
                    case 'inventario':           $pode_ver = temPermissao('menu_inventario'); break;
                    case 'pecas':                $pode_ver = temPermissao('menu_pecas'); break;
                    case 'acerto_locacao':       $pode_ver = temPermissao('menu_pecas_acerto_locacao'); break;
                    case 'inventario_pecas':     $pode_ver = temPermissao('menu_pecas_inventario'); break;
                    case 'renave':               $pode_ver = temPermissao('menu_renave'); break;
                    case 'planos_acao':          $pode_ver = temPermissao('menu_planos_acao'); break;
                    // BI agora é dinâmico — ignora entradas antigas da tabela
                    case 'bi_locadora':
                    case 'bi_financeiro':
                    case 'bi_vendas':
                        $pode_ver = false;
                        break;
                    case 'usuarios':             $pode_ver = false; break;
                    default:                     $pode_ver = false;
                }
            }

            if (!$pode_ver && $chave !== 'usuarios') continue;

            // Essas chaves só alimentam um item "pai" (Peças/Veiculos), já
            // tratado pelas flags do passo 1 — não têm linha própria aqui.
            if (in_array($chave, ['apresentacao_carros', 'bancos', 'acerto_locacao', 'inventario_pecas'], true)) {
                continue;
            }
            if ($chave === 'ferramentas' && !$show_veiculos) { continue; }
        ?>

        <?php if ($chave === 'bi'): ?>
            <li class="sidebar-item" id="bi-menu-item" style="display:none;">
                <div class="sidebar-link" onclick="toggleSubmenu(this)" data-tooltip="Administrativo">
                    <i class="fas fa-chart-bar"></i><span>Administrativo</span>
                </div>
                <div class="sidebar-submenu" id="bi-submenu">
                    <div style="padding:8px 46px;color:rgba(255,255,255,.5);font-size:.78rem;" id="bi-loading">
                        <i class="fas fa-spinner fa-spin"></i> Carregando…
                    </div>
                </div>
            </li>

        <?php elseif ($chave === 'buscar'): ?>
            <li class="sidebar-item">
                <a href="#" class="sidebar-link" id="btn-buscar" data-tooltip="Buscar">
                    <i class="<?= $icone ?>"></i><span><?= $rotulo ?></span>
                </a>
            </li>

        <?php elseif ($chave === 'usuarios' && $is_admin): ?>
            <li class="sidebar-item">
                <div class="sidebar-link" onclick="toggleSubmenu(this)" data-tooltip="Configurações">
                    <i class="<?= $icone ?>"></i><span>Configurações</span>
                </div>
                <div class="sidebar-submenu">
                    <a href="#" class="sidebar-link" onclick="carregarFerramenta('gerenciar_usuarios.php'); return false;">
                        <i class="fas fa-list"></i><span>Gerenciar usuários</span>
                    </a>
                    <a href="#" class="sidebar-link" onclick="carregarFerramenta('BI/Metas/carol_gestao.php'); return false;">
                        <i class="fas fa-file-alt"></i><span>Gestão de Relatórios</span>
                    </a>
                    <a href="#" class="sidebar-link" id="btn-config-treinamentos">
                        <i class="fas fa-graduation-cap"></i><span>Configurar Treinamentos</span>
                    </a>
                    <a href="<?= $basePath ?>/dashboard_veiculos.php" target="_blank" class="sidebar-link">
                        <i class="fas fa-chart-line"></i><span>Dashboard das Ferramentas</span>
                    </a>
                    <a href="#" class="sidebar-link" id="btn-politicas-admin-cfg">
                        <i class="fas fa-file-pdf"></i><span>Config. Políticas</span>
                    </a>
                    <a href="#" class="sidebar-link" id="btn-config-carros-apresentacao">
                        <i class="fas fa-video"></i><span>Vídeos apresentação</span>
                    </a>
                    <a href="<?= $basePath ?>/config_links.php" target="_blank" class="sidebar-link">
                        <i class="fas fa-external-link-alt"></i><span>Layout dos links</span>
                    </a>
                    <a href="<?= $basePath ?>/config_menu.php" target="_blank" class="sidebar-link">
                        <i class="fas fa-list-ol"></i><span>Layout do menu</span>
                    </a>
                    <a href="<?= $basePath ?>/config_ramais.php" target="_blank" class="sidebar-link">
                        <i class="fas fa-phone"></i><span>Configurar ramais</span>
                    </a>
                    <a href="<?= $basePath ?>/config_texto_legal.php" target="_blank" class="sidebar-link">
                        <i class="fas fa-file-contract"></i><span>Texto Legal</span>
                    </a>
                    <a href="<?= $basePath ?>/config_banners.php" target="_blank" class="sidebar-link">
                        <i class="fas fa-images"></i><span>Banners do menu</span>
                    </a>
                    <a href="#" class="sidebar-link" onclick="carregarFerramenta('<?= $basePath ?>/config_documentos.php'); return false;">
                        <i class="fas fa-file-word"></i><span>Config. Documentos</span>
                    </a>
                    <a href="<?= $basePath ?>/config_transferencia.php" target="_blank" class="sidebar-link">
                        <i class="fas fa-exchange-alt"></i><span>Config. Transferência</span>
                    </a>
                    <a href="<?= $basePath ?>/config_emplacamento.php" target="_blank" class="sidebar-link">
                        <i class="fas fa-car-side"></i><span>Config. Emplacamento</span>
                    </a>
                    <a href="<?= $basePath ?>/config_cartao.php" target="_blank" class="sidebar-link">
                        <i class="fas fa-credit-card"></i><span>Config. Cartão</span>
                    </a>
                    <a href="<?= $basePath ?>/config_consulta_placa.php" target="_blank" class="sidebar-link">
                        <i class="fas fa-search"></i><span>Config. Consulta de Placa</span>
                    </a>
                </div>
            </li>

        <?php elseif ($chave === 'planos_acao'): ?>
            <li class="sidebar-item">
                <a href="#" class="sidebar-link" id="btn-planos-acao" data-tooltip="<?= $rotulo ?>">
                    <i class="<?= $icone ?>"></i><span><?= $rotulo ?></span>
                </a>
            </li>

        <?php elseif ($chave === 'links'): ?>
            <li class="sidebar-item">
                <a href="#" class="sidebar-link" id="btn-links-uteis" data-tooltip="<?= $rotulo ?>">
                    <i class="<?= $icone ?>"></i><span><?= $rotulo ?></span>
                </a>
            </li>

        <?php elseif ($chave === 'inventario'): ?>
            <li class="sidebar-item">
                <a href="#" class="sidebar-link" id="btn-inventario" data-tooltip="<?= $rotulo ?>">
                    <i class="<?= $icone ?>"></i><span><?= $rotulo ?></span>
                </a>
            </li>

        <?php elseif ($chave === 'treinamentos'): ?>
            <li class="sidebar-item">
                <div class="sidebar-link" onclick="toggleSubmenu(this)" data-tooltip="Treinamentos">
                    <i class="<?= $icone ?>"></i><span><?= $rotulo ?></span>
                </div>
                <div class="sidebar-submenu">
                    <a href="#" class="sidebar-link" id="btn-treinamentos">
                        <i class="fas fa-play-circle"></i><span>Meus treinamentos</span>
                    </a>
                    <?php if ($is_gestor || $is_admin || temPermissao('menu_treinamentos_gestor')): ?>
                    <a href="#" class="sidebar-link" id="btn-treinamentos-gestor">
                        <i class="fas fa-table"></i><span>Gestão de Equipe</span>
                    </a>
                    <?php endif; ?>
                </div>
            </li>

        <?php elseif ($chave === 'ramais'): ?>
            <li class="sidebar-item">
                <a href="#" class="sidebar-link" id="btn-ramais-uteis" data-tooltip="<?= $rotulo ?>">
                    <i class="<?= $icone ?>"></i><span><?= $rotulo ?></span>
                </a>
            </li>

        <?php elseif ($chave === 'locadora'): ?>
            <li class="sidebar-item">
                <div class="sidebar-link" onclick="toggleSubmenu(this)" data-tooltip="Locadora">
                    <i class="<?= $icone ?>"></i><span><?= $rotulo ?></span>
                </div>
                <div class="sidebar-submenu">
                    <a href="#" class="sidebar-link" id="btn-orcamento-locacao">
                        <i class="fas fa-file-invoice-dollar"></i><span>Orçamento de locação</span>
                    </a>
                    <a href="#" class="sidebar-link" id="btn-orcamento-avarias">
                        <i class="fas fa-car-crash"></i><span>Orçamento de avarias</span>
                    </a>
                </div>
            </li>

        <?php elseif ($chave === 'pecas'): ?>
            <li class="sidebar-item">
                <div class="sidebar-link" onclick="toggleSubmenu(this)" data-tooltip="Peças">
                    <i class="<?= $icone ?>"></i><span><?= $rotulo ?></span>
                </div>
                <div class="sidebar-submenu">
                    <?php if ($show_inventario_pecas): ?>
                    <a href="http://app.exemplo.local/inventario/index.php" class="sidebar-link" target="_blank">
                        <i class="fas fa-clipboard-list"></i><span>Inventário</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($show_acerto_locacao): ?>
                    <a href="http://app.exemplo.local/inventario/acerto_locacao.php" class="sidebar-link" target="_blank">
                        <i class="fas fa-map-marker-alt"></i><span>Acerto de locação</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($is_admin || temPermissao('menu_pecas_consulta_placa')): ?>
                    <a href="#" class="sidebar-link" onclick="carregarFerramenta('consulta_placa.php'); return false;">
                        <i class="fas fa-search"></i><span>Consulta de Placa</span>
                    </a>
                    <?php endif; ?>
                </div>
            </li>

        <?php elseif ($chave === 'renave'): ?>
            <li class="sidebar-item">
                <a href="http://portal.exemplo.local/Renave/index.php" class="sidebar-link" target="_blank" data-tooltip="<?= $rotulo ?>">
                    <i class="<?= $icone ?>"></i><span><?= $rotulo ?></span>
                </a>
            </li>

        <?php elseif ($chave === 'ferramentas'): ?>
            <li class="sidebar-item">
                <div class="sidebar-link" onclick="toggleSubmenu(this)" data-tooltip="Veiculos">
                    <i class="fas fa-car"></i><span>Veiculos</span>
                </div>
                <div class="sidebar-submenu">
                    <?php if ($show_apresentacao_carros): ?>
                    <a href="#" class="sidebar-link" id="btn-apresentacao-carros">
                        <i class="fas fa-video"></i><span>Vídeos dos carros</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($show_bancos): ?>
                    <a href="#" class="sidebar-link" id="btn-bancos">
                        <i class="fas fa-university"></i><span>Bancos</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($show_ferramenta_transferencia): ?>
                    <a href="#" class="sidebar-link" onclick="carregarFerramenta('index.blade.transferencia.php'); return false;">
                        <i class="fas fa-exchange-alt"></i><span>Transferência</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($show_ferramenta_emplacamento): ?>
                    <a href="#" class="sidebar-link" onclick="carregarFerramenta('index.blade.emplacamento.php'); return false;">
                        <i class="fas fa-id-card"></i><span>Emplacamento</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($show_ferramenta_cartao): ?>
                    <a href="#" class="sidebar-link" onclick="carregarFerramenta('index.blade.cartao.php'); return false;">
                        <i class="fas fa-credit-card"></i><span>Cartão</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($show_ferramenta_documentos): ?>
                    <a href="#" class="sidebar-link" onclick="carregarFerramenta('index.blade.documentos.php'); return false;">
                        <i class="fas fa-file-contract"></i><span>Documentos</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($show_ferramenta_orcamento): ?>
                    <a href="#" class="sidebar-link" onclick="carregarFerramenta('index_orcamento.php'); return false;">
                        <i class="fas fa-calculator"></i><span>Orçamento</span>
                    </a>
                    <?php endif; ?>
                </div>
            </li>

        <?php elseif ($chave === 'politicas'): ?>
            <li class="sidebar-item">
                <div class="sidebar-link" onclick="toggleSubmenu(this)" data-tooltip="Políticas comerciais">
                    <i class="fas fa-file-pdf"></i><span>Políticas comerciais</span>
                </div>
                <div class="sidebar-submenu">
                    <a href="#" class="sidebar-link" id="btn-politicas-ver">
                        <i class="fas fa-file-pdf"></i><span>Políticas comerciais</span>
                    </a>
                    <?php if ($is_admin || temPermissao('menu_politicas_admin')): ?>
                    <a href="#" class="sidebar-link" id="btn-politicas-admin">
                        <i class="fas fa-tools"></i><span>Admin. políticas</span>
                    </a>
                    <?php endif; ?>
                </div>
            </li>

        <?php elseif ($chave === 'acessorios'): ?>
            <li class="sidebar-item">
                <div class="sidebar-link" onclick="toggleSubmenu(this)" data-tooltip="Oficina">
                    <i class="fas fa-wrench"></i><span>Oficina</span>
                </div>
                <div class="sidebar-submenu">
                    <a href="#" class="sidebar-link" id="btn-acessorios">
                        <i class="fas fa-cogs"></i><span>Acessórios</span>
                    </a>
                </div>
            </li>

        <?php endif; ?>
        <?php endforeach; ?>
    </ul>

    <div class="sidebar-footer">
        <form method="post" action="logout.php">
            <button type="submit" class="sidebar-link" data-tooltip="Sair">
                <i class="fas fa-sign-out-alt"></i><span>Sair</span>
            </button>
        </form>
    </div>
</aside>

<main class="main-content" id="main-wrapper">
    <div class="welcome" id="main-content">
        <h2>Sistema de Ferramentas Exemplo</h2>
        <p>Use o menu lateral para acessar as ferramentas disponíveis.</p>

        <?php if ($muralImagens): ?>
        <div class="slider-container">
            <div class="slider">
                <?php foreach ($muralImagens as $idx => $img): ?>
                <div class="slide <?= $idx === 0 ? 'active' : '' ?>">
                    <img src="<?= htmlspecialchars($img['src'], ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($img['alt'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <?php endforeach; ?>
            </div>
            <button class="slider-btn prev">&lt;</button>
            <button class="slider-btn next">&gt;</button>
        </div>
        <?php endif; ?>
    </div>
</main>

<div class="app-version-label"><?= VERSAO_MENU ?></div>

<script>
const sidebar        = document.getElementById('sidebar');
let sidebarCollapsed = false;

function aplicarEstadoSidebar(){
    sidebar.classList.toggle('collapsed', sidebarCollapsed);
    document.body.classList.toggle('sidebar-collapsed', sidebarCollapsed);
}
aplicarEstadoSidebar();

function toggleSidebar(){
    sidebarCollapsed = !sidebarCollapsed;
    aplicarEstadoSidebar();
    if (sidebarCollapsed) {
        document.querySelectorAll('.sidebar-submenu.open')
            .forEach(s => s.classList.remove('open'));
    }
}

function toggleSubmenu(el){
    const submenu = el.nextElementSibling;
    if (!submenu) return;
    if (sidebarCollapsed) {
        sidebarCollapsed = false;
        aplicarEstadoSidebar();
        setTimeout(() => submenu.classList.toggle('open'), 50);
    } else {
        submenu.classList.toggle('open');
    }
}

function carregarFerramenta(url){
    const main = document.getElementById('main-content');
    if (!main) return;
    main.innerHTML =
        `<iframe src="${url}" style="width:100%;height:calc(100vh - 40px);border:none;background:#fff;display:block;"></iframe>`;
}

const _bind = (id, url) => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', e => { e.preventDefault(); carregarFerramenta(url); });
};

_bind('btn-buscar',                     'buscar_servidor.php');
_bind('btn-links-uteis',                'links_uteis_servidor.php');
_bind('btn-inventario',                 'inventario_dashboard.php');
_bind('btn-bancos',                     'links_bancos.php');
_bind('btn-planos-acao',                'planos_acao.php');
_bind('btn-treinamentos',               'treinamentos_servidor.php');
_bind('btn-treinamentos-gestor',        'treinamentos_gestor.php');
_bind('btn-ramais-uteis',               'ramais_uteis_servidor.php');
_bind('btn-acessorios',                 'acessorios.php');
_bind('btn-orcamento-locacao',          'orcamento_locacao.php');
_bind('btn-orcamento-avarias',          'orcamento_avarias.php');
_bind('btn-apresentacao-carros',        'apresentacao_carros.php');
_bind('btn-config-treinamentos',        'config_treinamento.php');
_bind('btn-config-carros-apresentacao', 'config_carros_apresentacao.php');
_bind('btn-politicas-ver',              'politicas_comerciais.php');
_bind('btn-politicas-admin',            'politicas_comerciais_admin.php');
_bind('btn-politicas-admin-cfg',        'politicas_comerciais_admin.php');
_bind('btn-dashboard-veiculos',         'dashboard_exemplo.php');

// ── BI Dinâmico ──────────────────────────────────────────────────────────────
(function () {
    const menuItem = document.getElementById('bi-menu-item');
    const submenu  = document.getElementById('bi-submenu');
    const loading  = document.getElementById('bi-loading');

    // Mapa de ícones por palavra-chave no nome da pasta
    const icones = {
        locadora  : 'fas fa-car',
        financeiro: 'fas fa-dollar-sign',
        vendas    : 'fas fa-handshake',
        pecas     : 'fas fa-cogs',
        oficina   : 'fas fa-wrench',
        rh        : 'fas fa-users',
        estoque   : 'fas fa-boxes',
        metas     : 'fas fa-bullseye',
    };

    function iconeParaLabel(label) {
        const l = label.toLowerCase();
        for (const [k, v] of Object.entries(icones)) {
            if (l.includes(k)) return v;
        }
        return 'fas fa-chart-line';
    }

    fetch('bi_menu.php')
        .then(r => r.json())
        .then(data => {
            if (!data.itens || data.itens.length === 0) {
                menuItem.style.display = 'none';
                return;
            }

            // Exibe o item BI no sidebar
            menuItem.style.display = '';

            // Remove spinner
            if (loading) loading.remove();

            data.itens.forEach(item => {
                const a       = document.createElement('a');
                a.href        = '#';
                a.className   = 'sidebar-link';
                a.innerHTML   = `<i class="${iconeParaLabel(item.label)}"></i><span>${item.label}</span>`;
                a.addEventListener('click', e => {
                    e.preventDefault();
                    carregarFerramenta(item.url);
                });
                submenu.appendChild(a);
            });
        })
        .catch(err => {
            console.warn('BI menu: erro ao carregar pastas', err);
            menuItem.style.display = 'none';
        });
})();
// ── Fim BI Dinâmico ──────────────────────────────────────────────────────────

(function () {
    const slides = document.querySelectorAll('.slider .slide');
    if (!slides.length) return;
    let index = 0, timer = null;
    const show  = i => { slides.forEach((s,j)=>s.classList.toggle('active', j===i)); index = i; };
    const next  = () => show((index + 1) % slides.length);
    const prev  = () => show((index - 1 + slides.length) % slides.length);
    const start = () => { stopAuto(); timer = setInterval(next, 8000); };
    const stopAuto = () => { if (timer){ clearInterval(timer); timer = null; } };
    const nb = document.querySelector('.slider-btn.next');
    const pb = document.querySelector('.slider-btn.prev');
    if (nb) nb.addEventListener('click', () => { next(); start(); });
    if (pb) pb.addEventListener('click', () => { prev(); start(); });
    const sc = document.querySelector('.slider-container');
    if (sc) { sc.addEventListener('mouseenter', stopAuto); sc.addEventListener('mouseleave', start); }
    show(0); start();
})();

const AUTO_FERRAMENTA = <?= json_encode($autoFerramenta, JSON_UNESCAPED_SLASHES) ?>;
if (AUTO_FERRAMENTA) carregarFerramenta(AUTO_FERRAMENTA);
</script>
</body>
</html>