<?php
require_once __DIR__ . '/config_database.php';
session_start();

if (empty($_SESSION['logado'])) { exit('Acesso negado'); }
$is_admin = !empty($_SESSION['is_admin']);
$canEdit  = false;
if ($is_admin) {
    $canEdit = true;
} elseif (!empty($_SESSION['usuario_id'])) {
    $stmtAcc = $mysqli->prepare("SELECT can_edit_acessorios FROM usuarios WHERE id = ? LIMIT 1");
    if ($stmtAcc) {
        $stmtAcc->bind_param('i', $_SESSION['usuario_id']);
        $stmtAcc->execute();
        $rowAcc  = $stmtAcc->get_result()->fetch_assoc();
        $stmtAcc->close();
        $canEdit = !empty($rowAcc['can_edit_acessorios']);
    }
}
if (!$canEdit) exit('Acesso restrito a administradores e usuários autorizados.');

$hasCriadoEm = false;
$col = $mysqli->query("SHOW COLUMNS FROM acessorios_marcas LIKE 'criado_em'");
if ($col && $col->num_rows) {
    $hasCriadoEm = true;
} else {
    // Tenta criar a coluna automaticamente se existir permissão
    $mysqli->query("ALTER TABLE acessorios_marcas ADD COLUMN criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    $col2 = $mysqli->query("SHOW COLUMNS FROM acessorios_marcas LIKE 'criado_em'");
    if ($col2 && $col2->num_rows) {
        $hasCriadoEm = true;
    }
}

require_once __DIR__ . '/acessorios_promocao.php';
garantirSchemaPromocaoAcessorios($mysqli);

function br_to_float(string $v): float {
    $v = str_replace(['R$',' '], '', trim($v));
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return (float)$v;
}

// ── SALVAR LINHA INLINE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_gestor') {
    $id    = (int)($_POST['id'] ?? 0);
    $marca = trim($_POST['marca'] ?? '');
    $acc   = trim($_POST['acessorio'] ?? '');
    $mod   = trim($_POST['modelo'] ?? '');
    $val   = trim($_POST['valor'] ?? '');
    $cod   = trim($_POST['codigo_item'] ?? '');
    $ativo = (int)($_POST['ativo'] ?? 1);
    $vs    = number_format(br_to_float($val), 2, '.', '');

    if ($id > 0) {
        $s = $mysqli->prepare("UPDATE acessorios_marcas SET marca=?,acessorio=?,modelo=?,valor=?,codigo_item=?,ativo=? WHERE id=?");
        if ($s) { $s->bind_param('sssssii',$marca,$acc,$mod,$vs,$cod,$ativo,$id); $s->execute(); $s->close(); }
    } else {
        $s = $mysqli->prepare("INSERT INTO acessorios_marcas (marca,acessorio,modelo,valor,codigo_item,ativo) VALUES(?,?,?,?,?,?)");
        if ($s) { $s->bind_param('sssssi',$marca,$acc,$mod,$vs,$cod,$ativo); $s->execute(); $s->close(); }
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'acessorios_gestor.php')); exit;
}

// ── SALVAR / REMOVER PROMOÇÃO ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_promocao') {
    $id = (int)($_POST['id'] ?? 0);
    $valorPromoRaw = trim($_POST['valor_promocional'] ?? '');
    $valorVendRaw  = trim($_POST['valor_vendedor'] ?? '');
    $inicio = trim($_POST['promocao_inicio'] ?? '');
    $fim    = trim($_POST['promocao_fim'] ?? '');

    if ($id > 0) {
        if ($valorPromoRaw === '') {
            $s = $mysqli->prepare("UPDATE acessorios_marcas SET valor_promocional=NULL, valor_vendedor=NULL, promocao_inicio=NULL, promocao_fim=NULL WHERE id=?");
            if ($s) { $s->bind_param('i', $id); $s->execute(); $s->close(); }
        } else {
            $vp = number_format(br_to_float($valorPromoRaw), 2, '.', '');
            $vv = $valorVendRaw !== '' ? number_format(br_to_float($valorVendRaw), 2, '.', '') : null;
            $inicioVal = $inicio !== '' ? $inicio : null;
            $fimVal    = $fim !== '' ? $fim : null;
            $s = $mysqli->prepare("UPDATE acessorios_marcas SET valor_promocional=?, valor_vendedor=?, promocao_inicio=?, promocao_fim=? WHERE id=?");
            if ($s) { $s->bind_param('ssssi', $vp, $vv, $inicioVal, $fimVal, $id); $s->execute(); $s->close(); }
        }
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'acessorios_gestor.php')); exit;
}

// ── TOGGLE ATIVO ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'toggle_ativo') {
    $id = (int)($_POST['id'] ?? 0);
    $st = (int)($_POST['status'] ?? 0);
    if ($id > 0) {
        $s = $mysqli->prepare("UPDATE acessorios_marcas SET ativo=? WHERE id=?");
        if ($s) { $s->bind_param('ii',$st,$id); $s->execute(); $s->close(); }
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'acessorios_gestor.php')); exit;
}

// ── EXCLUIR ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_gestor') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmtF = $mysqli->prepare("SELECT foto_path FROM acessorios_marcas WHERE id=? LIMIT 1");
        if ($stmtF) {
            $stmtF->bind_param('i', $id); $stmtF->execute();
            $rowF = $stmtF->get_result()->fetch_assoc(); $stmtF->close();
            if (!empty($rowF['foto_path']) && file_exists(__DIR__.'/'.$rowF['foto_path']))
                unlink(__DIR__.'/'.$rowF['foto_path']);
        }
        $s = $mysqli->prepare("DELETE FROM acessorios_marcas WHERE id=?");
        if ($s) { $s->bind_param('i',$id); $s->execute(); $s->close(); }
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'acessorios_gestor.php')); exit;
}

// ── UPLOAD FOTO INLINE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'upload_foto') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        if (in_array($ext,['jpg','jpeg','png','gif','webp'],true)) {
            $dir = __DIR__.'/uploads_acessorios';
            if (!is_dir($dir)) mkdir($dir,0777,true);
            $nm = 'acc_'.$id.'_'.time().'.'.$ext;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $dir.'/'.$nm)) {
                $s = $mysqli->prepare("UPDATE acessorios_marcas SET foto_path=? WHERE id=?");
                if ($s) { $fp='uploads_acessorios/'.$nm; $s->bind_param('si',$fp,$id); $s->execute(); $s->close(); }
            }
        }
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'acessorios_gestor.php')); exit;
}

// ── DUPLICAR ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'duplicar_gestor') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $s = $mysqli->prepare("INSERT INTO acessorios_marcas (marca, acessorio, modelo, valor, codigo_item, ativo)
            SELECT marca, acessorio, CONCAT(modelo, ' (cópia)'), valor, codigo_item, ativo
            FROM acessorios_marcas WHERE id=? LIMIT 1");
        if ($s) { $s->bind_param('i',$id); $s->execute(); $s->close(); }
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'acessorios_gestor.php')); exit;
}

// ── CARREGA ──
$filtMarca = $_GET['marca'] ?? '';
$filtBusca = trim($_GET['busca'] ?? '');
$filtPromo = !empty($_GET['promo']);
$ordenar   = $_GET['ord'] ?? 'marca';
$ordir     = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

$cols = ['marca','acessorio','modelo','valor','codigo_item'];
if ($hasCriadoEm) {
    $cols[] = 'criado_em';
}
if (!in_array($ordenar, $cols)) $ordenar = 'marca';

$where = '1=1';
$params = []; $types = '';
if ($filtMarca && in_array($filtMarca,['FIAT','JEEP','RAM','CAR CARE'])) { $where .= ' AND marca=?'; $params[] = $filtMarca; $types .= 's'; }
if ($filtBusca) { $like = '%'.$filtBusca.'%'; $where .= ' AND (acessorio LIKE ? OR modelo LIKE ? OR codigo_item LIKE ?)'; $params[] = $like; $params[] = $like; $params[] = $like; $types .= 'sss'; }

$extraCriado = $hasCriadoEm ? ", COALESCE(criado_em,'') AS criado_em" : '';
$sql = "SELECT id,marca,acessorio,modelo,valor,foto_path,COALESCE(codigo_item,'') AS codigo_item, COALESCE(ativo,1) as ativo,
        valor_promocional, valor_vendedor, promocao_inicio, promocao_fim$extraCriado
        FROM acessorios_marcas WHERE $where ORDER BY $ordenar $ordir";

$itens = [];
if ($params) {
    $s = $mysqli->prepare($sql);
    if ($s) { 
        $s->bind_param($types,...$params); 
        $s->execute(); 
        $res=$s->get_result(); 
        while($r=$res->fetch_assoc()) $itens[]=$r; 
        $s->close(); 
    }
} else {
    $res = $mysqli->query($sql);
    if ($res) while($r=$res->fetch_assoc()) $itens[]=$r;
}

if ($filtPromo) {
    $itens = array_values(array_filter($itens, 'acessorioEstaEmPromocao'));
}

// Totais por marca (usando todos os itens carregados)
$totMarca=['FIAT'=>0,'JEEP'=>0,'RAM'=>0,'CAR CARE'=>0];
$totVal=['FIAT'=>0,'JEEP'=>0,'RAM'=>0,'CAR CARE'=>0];
foreach ($itens as $it) { 
    $m = $it['marca'];
    if(isset($totMarca[$m])) {
        $totMarca[$m]++;
        $totVal[$m] += (float)$it['valor'];
    }
}

// Ranking de vendedores com valor arrecadado em promoções
$vendedores = [];
$rv = $mysqli->query(
    "SELECT u.id, u.nome, SUM(c.valor_vendedor) AS total, COUNT(*) AS qtd_itens
     FROM acessorios_promocao_creditos c
     JOIN usuarios u ON u.id = c.user_id
     GROUP BY u.id, u.nome
     HAVING total > 0
     ORDER BY total DESC"
);
if ($rv) while ($rowV = $rv->fetch_assoc()) $vendedores[] = $rowV;

function sortUrl($col, $atual, $dir, $marca, $busca, $promo = false) {
    $newDir = ($col===$atual && $dir==='ASC') ? 'DESC' : 'ASC';
    return '?ord='.$col.'&dir='.$newDir.'&marca='.urlencode($marca).'&busca='.urlencode($busca).'&promo='.($promo ? '1' : '');
}
function sortArrow($col,$atual,$dir){
    if ($col !== $atual) return '<span style="opacity:.25">↕</span>';
    return $dir==='ASC' ? '↑' : '↓';
}
function marcaCss($m){return str_replace(' ','-',$m);}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gestor de Acessórios — Exemplo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --r:#2347C8;--rd:#c0000f;
    --bg:#f2f3f5;--bg2:#ffffff;--bg3:#efefef;
    --bdr:#d8d8d8;--txt:#2a2a2a;--mut:#888;
    --fiat:#2347C8;--jeep:#3a8c3e;--ram:#b8972a;--carcare:#1a6ba0;
    --green:#1b7a1b;
}
html{scroll-behavior:smooth}
body{font-family:'Roboto',system-ui,sans-serif;background:var(--bg);color:var(--txt);min-height:100vh}

/* TOPBAR */
.topbar{position:sticky;top:0;z-index:60;background:#fff;border-bottom:2px solid var(--bdr);box-shadow:0 2px 8px rgba(0,0,0,.07);display:flex;align-items:center;padding:0 20px;height:52px;gap:14px}
.tb-brand{font-size:.9rem;font-weight:700;display:flex;align-items:center;gap:6px;white-space:nowrap}
.tb-brand em{color:var(--r);font-style:normal}
.tb-barra{width:3px;height:20px;background:var(--r);border-radius:2px}
.tb-back{font-size:.7rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase;padding:5px 12px;border-radius:4px;border:1.5px solid var(--bdr);background:#fff;color:var(--mut);text-decoration:none;transition:all .12s;display:inline-flex;align-items:center;gap:5px}
.tb-back:hover{color:var(--txt);border-color:#aaa}
.tb-tab{font-size:.72rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:6px 14px;border-radius:4px;border:1.5px solid transparent;background:none;color:var(--mut);cursor:pointer;transition:all .12s}
.tb-tab:hover{color:var(--txt)}
.tb-tab.active{background:var(--bg3);color:var(--txt);border-color:var(--bdr)}
.tb-gap{flex:1}
.btn-add-row{font-size:.72rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:6px 16px;border-radius:4px;border:none;background:var(--r);color:#fff;cursor:pointer;transition:background .12s;display:flex;align-items:center;gap:5px}
.btn-add-row:hover{background:var(--rd)}

/* FILTROS */
.filtros-bar{background:#fff;border-bottom:1px solid var(--bdr);padding:10px 20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.fb-label{font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--mut)}
.filt-marca-btn{font-size:.7rem;font-weight:600;padding:5px 14px;border-radius:4px;border:1.5px solid var(--bdr);background:#fff;color:var(--mut);cursor:pointer;transition:all .12s;text-decoration:none}
.filt-marca-btn:hover{border-color:#aaa}
.filt-marca-btn.ativa-TODAS{background:#5a5a5a;color:#fff;border-color:#5a5a5a}
.filt-marca-btn.ativa-FIAT{background:var(--fiat);color:#fff;border-color:var(--fiat)}
.filt-marca-btn.ativa-JEEP{background:var(--jeep);color:#fff;border-color:var(--jeep)}
.filt-marca-btn.ativa-RAM{background:var(--ram);color:#fff;border-color:var(--ram)}
.filt-marca-btn.ativa-CAR-CARE{background:var(--carcare);color:#fff;border-color:var(--carcare)}
.filt-promo-btn{margin-left:6px}
.filt-marca-btn.ativa-promo{background:#a86b00;color:#fff;border-color:#a86b00}
.filt-busca{display:flex;align-items:center;gap:6px;margin-left:auto}
.filt-busca input{border:1.5px solid var(--bdr);border-radius:4px;padding:6px 10px;font-size:.8rem;color:var(--txt);outline:none;width:200px;transition:all .14s}
.filt-busca input:focus{border-color:#aaa;width:260px;box-shadow:0 0 0 3px rgba(35,71,200,.08)}
.btn-xb{background:none;border:none;color:var(--mut);cursor:pointer;font-size:.7rem}
.btn-xb:hover{color:var(--r)}

/* RESUMO */
.resumo-bar{display:flex;gap:10px;padding:12px 20px;flex-wrap:wrap}
.resumo-card{background:#fff;border:1px solid var(--bdr);border-radius:8px;padding:10px 16px;display:flex;align-items:center;gap:10px;min-width:160px}
.rc-cor{width:4px;height:32px;border-radius:2px}
.rc-FIAT{background:var(--fiat)}.rc-JEEP{background:var(--jeep)}.rc-RAM{background:var(--ram)}.rc-TOT{background:#5a5a5a}.rc-CAR-CARE{background:var(--carcare)}
.rc-info{display:flex;flex-direction:column}
.rc-qtd{font-size:1.2rem;font-weight:700;color:var(--txt);line-height:1}
.rc-lbl{font-size:.62rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--mut);margin-top:2px}
.rc-val{font-size:.72rem;color:var(--r);font-weight:600;margin-top:2px}

/* TABELA */
.table-wrap{padding:0 20px 40px;overflow-x:auto}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.07);min-width:900px}
thead{background:var(--bg3)}
th{padding:10px 12px;font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--mut);text-align:left;border-bottom:2px solid var(--bdr);white-space:nowrap}
th a{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:5px}
th.th-acao{text-align:center;width:150px}
th.th-num{text-align:right}
td{padding:9px 12px;font-size:.82rem;color:var(--txt);border-bottom:1px solid var(--bg3);vertical-align:middle}
td.td-num{text-align:right;font-variant-numeric:tabular-nums}
tr:last-child td{border-bottom:none}
tr:hover td{background:#fafafa}
tr.row-nova td{background:#fffde7}
tr.row-desativada td{opacity:0.6;background:#f9f9f9}

/* BADGES */
.badge-marca{display:inline-block;font-size:.58rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:2px 7px;border-radius:3px;color:#fff}
.bg-FIAT{background:var(--fiat)}.bg-JEEP{background:var(--jeep)}.bg-RAM{background:var(--ram)}.bg-CAR-CARE{background:var(--carcare)}

/* EDIT INLINE */
.td-edit{display:none}
.td-view{display:block}
tr.editando .td-edit{display:block}
tr.editando .td-view{display:none}
.ie{width:100%;border:1.5px solid #ccc;border-radius:4px;padding:5px 7px;font-size:.82rem;color:var(--txt);font-family:inherit;outline:none;background:#fff;transition:border-color .14s}
.ie:focus{border-color:var(--r);box-shadow:0 0 0 2px rgba(35,71,200,.12)}
.ie-sel{cursor:pointer}
.ie-num{text-align:right}
.td-foto-thumb{width:36px;height:36px;object-fit:cover;border-radius:4px;border:1px solid var(--bdr);cursor:pointer;transition:transform .15s}
.td-foto-thumb:hover{transform:scale(1.08)}
.td-foto-ph{width:36px;height:36px;background:var(--bg3);border-radius:4px;display:inline-flex;align-items:center;justify-content:center;color:var(--mut);font-size:.75rem;cursor:pointer}
.td-cod{font-size:.72rem;color:var(--mut);font-family:monospace}
.td-val{font-weight:700;color:var(--r)}
.badge-promo{font-size:.85rem;margin-left:3px;cursor:help}

/* AÇÕES */
.act-btns{display:flex;gap:5px;justify-content:center;align-items:center}
.btn-act{font-size:.62rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:5px 9px;border-radius:4px;border:1.5px solid var(--bdr);background:#fff;color:var(--mut);cursor:pointer;transition:all .12s;white-space:nowrap}
.btn-act:hover{color:var(--txt);border-color:#aaa;background:#f0f0f0}
.btn-edit{color:var(--r);border-color:rgba(35,71,200,.3)}
.btn-edit:hover{background:var(--r);color:#fff;border-color:var(--r)}
.btn-save-row{background:var(--r);color:#fff;border-color:var(--r)}
.btn-save-row:hover{background:var(--rd)}
.btn-cancel-row{color:var(--mut)}
.btn-dup{color:#1e64c8;border-color:rgba(30,100,200,.3)}
.btn-dup:hover{background:#1e64c8;color:#fff;border-color:#1e64c8}
.btn-promo{color:#a86b00;border-color:rgba(168,107,0,.3)}
.btn-promo:hover{background:#a86b00;color:#fff;border-color:#a86b00}
.btn-del-row{color:#c0392b;border-color:rgba(192,57,43,.3)}
.btn-del-row:hover{background:#c0392b;color:#fff;border-color:#c0392b}
.btn-foto-up{color:var(--green);border-color:rgba(27,122,27,.3)}
.btn-foto-up:hover{background:var(--green);color:#fff;border-color:var(--green)}

/* STATUS TOGGLE */
.btn-status{font-size:.6rem;padding:3px 6px;border-radius:3px;font-weight:700;text-transform:uppercase;cursor:pointer;border:1px solid transparent}
.status-ativo{background:#e6f4ea;color:#1e7e34;border-color:#c3e6cb}
.status-ativo:hover{background:#d4edda}
.status-desativado{background:#f8d7da;color:#721c24;border-color:#f5c6cb}
.status-desativado:hover{background:#f1b0b7}

/* MODAIS */
.mbk{position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;z-index:200;backdrop-filter:blur(4px)}
.mbk.open{display:flex}
.mbox{background:#fff;border-radius:10px;padding:22px 24px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.m-ttl{font-size:1rem;font-weight:700;text-transform:uppercase;letter-spacing:.01em;margin-bottom:14px;color:var(--txt)}
.mfoot{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}
.btn-mc{font-size:.73rem;font-weight:600;padding:7px 14px;border-radius:4px;border:1.5px solid var(--bdr);background:#fff;color:var(--mut);cursor:pointer;transition:all .12s}
.btn-mc:hover{color:var(--txt);border-color:#aaa}
.btn-ms{font-size:.73rem;font-weight:700;padding:7px 18px;border-radius:4px;border:none;background:var(--r);color:#fff;cursor:pointer}
.btn-ms:hover{background:var(--rd)}
.mrow{display:flex;gap:10px;margin-bottom:10px}
.mf{display:flex;flex-direction:column;gap:4px;font-size:.75rem;color:var(--mut)}
.mf label{font-weight:600}

@media(max-width:640px){.topbar{flex-wrap:wrap;height:auto;padding:8px 12px;gap:6px}.filtros-bar{padding:8px 12px}.table-wrap{padding:0 8px 30px}.resumo-bar{padding:8px 12px}}
</style>
</head>
<body>

<nav class="topbar">
    <span class="tb-barra"></span>
    <span class="tb-brand">Exemplo <em>|</em> Gestor de Acessórios</span>
    <a href="acessorios.php" class="tb-back">← Voltar ao Catálogo</a>
    <button class="tb-tab active" id="tab-btn-itens" onclick="mostrarAba('itens')">Itens</button>
    <button class="tb-tab" id="tab-btn-vendedores" onclick="mostrarAba('vendedores')">Vendedores</button>
    <span class="tb-gap"></span>
    <button class="btn-add-row" id="btn-nova-linha" onclick="adicionarLinha()">+ Nova Linha</button>
</nav>

<div id="aba-itens">
<div class="filtros-bar">
    <span class="fb-label">Marca:</span>
    <?php foreach([''=>'TODAS','FIAT'=>'FIAT','JEEP'=>'JEEP','RAM'=>'RAM','CAR CARE'=>'CAR CARE'] as $v=>$l): ?>
    <a href="?marca=<?=urlencode($v)?>&busca=<?=urlencode($filtBusca)?>&ord=<?=$ordenar?>&dir=<?=$ordir?>&promo=<?=$filtPromo?'1':''?>"
       class="filt-marca-btn <?= ($filtMarca===$v) ? 'ativa-'.marcaCss($v?:('TODAS')) : '' ?>">
       <?=$l?></a>
    <?php endforeach; ?>
    <a href="?marca=<?=urlencode($filtMarca)?>&busca=<?=urlencode($filtBusca)?>&ord=<?=$ordenar?>&dir=<?=$ordir?>&promo=<?=$filtPromo?'':'1'?>"
       class="filt-marca-btn filt-promo-btn <?= $filtPromo ? 'ativa-promo' : '' ?>">
       🏷️ Promoção</a>
    <div class="filt-busca">
        <form method="get" action="" style="display:flex;align-items:center;gap:5px">
            <input type="hidden" name="marca" value="<?=htmlspecialchars($filtMarca)?>">
            <input type="hidden" name="ord" value="<?=$ordenar?>">
            <input type="hidden" name="dir" value="<?=$ordir?>">
            <input type="hidden" name="promo" value="<?=$filtPromo?'1':''?>">
            <input type="text" name="busca" value="<?=htmlspecialchars($filtBusca)?>" placeholder="Buscar…" id="busca-inp">
            <button type="button" class="btn-xb" onclick="document.getElementById('busca-inp').value='';this.form.submit()">✕</button>
        </form>
        <span style="font-size:.72rem;color:var(--mut);font-weight:600;margin-left:4px"><?=count($itens)?> registros</span>
    </div>
</div>

<!-- RESUMO -->
<div class="resumo-bar">
    <?php
    $totGeral = count($itens);
    $valGeral  = array_sum(array_column($itens,'valor'));
    foreach(['FIAT','JEEP','RAM','CAR CARE'] as $m):
        $q = $totMarca[$m];
        $v = $totVal[$m];
    ?>
    <div class="resumo-card">
        <div class="rc-cor rc-<?=marcaCss($m)?>"></div>
        <div class="rc-info">
            <span class="rc-qtd"><?=$q?></span>
            <span class="rc-lbl"><?=$m?></span>
            <span class="rc-val"><?=number_format($v,2,',','.')?></span>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="resumo-card" style="margin-left:auto">
        <div class="rc-cor rc-TOT"></div>
        <div class="rc-info">
            <span class="rc-qtd"><?=$totGeral?></span>
            <span class="rc-lbl">TOTAL</span>
            <span class="rc-val" style="color:#555">R$ <?=number_format($valGeral,2,',','.')?></span>
        </div>
    </div>
</div>

<div class="table-wrap">
<table id="tabela-gestor">
<thead>
<tr>
    <th style="width:36px">#</th>
    <th style="width:40px">Foto</th>
    <th><a href="<?=sortUrl('marca',$ordenar,$ordir,$filtMarca,$filtBusca,$filtPromo)?>">Marca <?=sortArrow('marca',$ordenar,$ordir)?></a></th>
    <th><a href="<?=sortUrl('acessorio',$ordenar,$ordir,$filtMarca,$filtBusca,$filtPromo)?>">Acessório <?=sortArrow('acessorio',$ordenar,$ordir)?></a></th>
    <th><a href="<?=sortUrl('modelo',$ordenar,$ordir,$filtMarca,$filtBusca,$filtPromo)?>">Modelo / Aplicação <?=sortArrow('modelo',$ordenar,$ordir)?></a></th>
    <th class="th-num"><a href="<?=sortUrl('valor',$ordenar,$ordir,$filtMarca,$filtBusca,$filtPromo)?>">Valor <?=sortArrow('valor',$ordenar,$ordir)?></a></th>
    <th><a href="<?=sortUrl('codigo_item',$ordenar,$ordir,$filtMarca,$filtBusca,$filtPromo)?>">Cód. Item <?=sortArrow('codigo_item',$ordenar,$ordir)?></a></th>
    <th style="width:80px">Status</th>
    <?php if ($hasCriadoEm): ?>
    <th><a href="<?=sortUrl('criado_em',$ordenar,$ordir,$filtMarca,$filtBusca,$filtPromo)?>">Criado em <?=sortArrow('criado_em',$ordenar,$ordir)?></a></th>
    <?php endif; ?>
    <th class="th-acao">Ações</th>
</tr>
</thead>
<tbody id="tbody">
<?php if (!$itens): ?>
<tr class="empty-row"><td colspan="<?= $hasCriadoEm ? 10 : 9 ?>">Nenhum acessório encontrado</td></tr>
<?php endif; ?>
<?php foreach($itens as $i => $it): ?>
<tr id="tr-<?=$it['id']?>" data-id="<?=$it['id']?>" class="<?= $it['ativo'] ? '' : 'row-desativada' ?>">
    <td style="color:var(--mut);font-size:.72rem"><?=$i+1?></td>

    <!-- FOTO -->
    <td>
        <span class="td-view">
            <?php if($it['foto_path']): ?>
                <img src="<?=htmlspecialchars($it['foto_path'])?>" class="td-foto-thumb" onclick="verFoto('<?=htmlspecialchars($it['foto_path'])?>')" alt="">
            <?php else: ?>
                <span class="td-foto-ph" title="Sem foto">📷</span>
            <?php endif; ?>
        </span>
        <span class="td-edit">
            <form method="post" action="" enctype="multipart/form-data" id="fform-<?=$it['id']?>" style="display:inline">
                <input type="hidden" name="acao" value="upload_foto">
                <input type="hidden" name="id" value="<?=$it['id']?>">
                <input type="file" name="foto" accept="image/*" style="display:none" id="finp-<?=$it['id']?>" onchange="document.getElementById('fform-<?=$it['id']?>').submit()">
                <button type="button" class="btn-act btn-foto-up" onclick="document.getElementById('finp-<?=$it['id']?>').click()" title="Upload foto">📷</button>
            </form>
        </span>
    </td>

    <!-- MARCA -->
    <td>
        <span class="td-view"><span class="badge-marca bg-<?=marcaCss($it['marca'])?>"><?=htmlspecialchars($it['marca'])?></span></span>
        <span class="td-edit">
            <select class="ie ie-sel" name="marca" data-field="marca">
                <?php foreach(['FIAT','JEEP','RAM','CAR CARE'] as $m): ?>
                <option value="<?=$m?>" <?=$it['marca']===$m?'selected':''?>><?=$m?></option>
                <?php endforeach; ?>
            </select>
        </span>
    </td>

    <!-- ACESSÓRIO -->
    <td>
        <span class="td-view"><?=htmlspecialchars($it['acessorio'])?></span>
        <span class="td-edit"><input type="text" class="ie" name="acessorio" data-field="acessorio" value="<?=htmlspecialchars($it['acessorio'])?>"></span>
    </td>

    <!-- MODELO -->
    <td style="max-width:260px">
        <span class="td-view" style="font-size:.78rem;line-height:1.4"><?=htmlspecialchars($it['modelo'])?></span>
        <span class="td-edit"><input type="text" class="ie" name="modelo" data-field="modelo" value="<?=htmlspecialchars($it['modelo'])?>"></span>
    </td>

    <!-- VALOR -->
    <td class="td-num">
        <span class="td-view td-val">
            R$ <?=number_format((float)$it['valor'],2,',','.')?>
            <?php if (!empty($it['valor_promocional'])):
                $temPeriodo = !empty($it['promocao_inicio']) && !empty($it['promocao_fim']);
                $tituloPromo = 'Promoção: R$ ' . number_format((float)$it['valor_promocional'],2,',','.') . ($temPeriodo ? ' de ' . date('d/m/Y', strtotime($it['promocao_inicio'])) . ' até ' . date('d/m/Y', strtotime($it['promocao_fim'])) : ''); ?>
                <span class="badge-promo" title="<?=htmlspecialchars($tituloPromo)?>">🏷️</span>
            <?php endif; ?>
        </span>
        <span class="td-edit"><input type="text" class="ie ie-num" name="valor" data-field="valor" value="<?=number_format((float)$it['valor'],2,',','.')?>"></span>
    </td>

    <!-- CÓDIGO ITEM -->
    <td>
        <span class="td-view td-cod"><?=htmlspecialchars($it['codigo_item'])?:'—'?></span>
        <span class="td-edit"><input type="text" class="ie" name="codigo_item" data-field="codigo_item" value="<?=htmlspecialchars($it['codigo_item'])?>"></span>
    </td>

    <?php if ($hasCriadoEm): ?>
    <td class="td-num"><span class="td-view"><?= $it['criado_em'] ? date('d/m/Y H:i', strtotime($it['criado_em'])) : '—' ?></span><span class="td-edit">—</span></td>
    <?php endif; ?>

    <!-- STATUS -->
    <td>
        <span class="td-view">
            <form method="post" action="" style="display:inline">
                <input type="hidden" name="acao" value="toggle_ativo">
                <input type="hidden" name="id" value="<?=$it['id']?>">
                <input type="hidden" name="status" value="<?=$it['ativo']?0:1?>">
                <button type="submit" class="btn-status <?=$it['ativo']?'status-ativo':'status-desativado'?>">
                    <?=$it['ativo']?'Ativo':'Inativo'?>
                </button>
            </form>
        </span>
        <span class="td-edit">
            <select class="ie ie-sel" name="ativo" data-field="ativo">
                <option value="1" <?=$it['ativo']?'selected':''?>>Ativo</option>
                <option value="0" <?=!$it['ativo']?'selected':''?>>Inativo</option>
            </select>
        </span>
    </td>

    <!-- AÇÕES -->
    <td class="th-acao">
        <div class="act-btns view-acts">
            <button class="btn-act btn-edit" onclick="editarLinha(<?=$it['id']?>)">✏️</button>
            <button class="btn-act btn-promo" onclick="abrirPromocao(<?=$it['id']?>,'<?=addslashes((string)$it['valor_promocional'])?>','<?=addslashes((string)$it['valor_vendedor'])?>','<?=addslashes((string)$it['promocao_inicio'])?>','<?=addslashes((string)$it['promocao_fim'])?>')">🏷️</button>
            <button class="btn-act btn-dup" onclick="duplicarLinha(<?=$it['id']?>)">⧉</button>
            <button class="btn-act btn-del-row" onclick="confirmarExcluir(<?=$it['id']?>,'<?=addslashes($it['acessorio'])?>')">🗑</button>
        </div>
        <div class="act-btns edit-acts" style="display:none">
            <button class="btn-act btn-save-row" onclick="salvarLinha(<?=$it['id']?>)">💾</button>
            <button class="btn-act btn-cancel-row" onclick="cancelarEdicao(<?=$it['id']?>)">✕</button>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<div id="aba-vendedores" style="display:none">
    <div class="table-wrap" style="padding-top:20px">
        <table>
            <thead>
                <tr>
                    <th style="width:36px">#</th>
                    <th>Vendedor</th>
                    <th class="th-num">Itens em promoção vendidos</th>
                    <th class="th-num">Total arrecadado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$vendedores): ?>
                <tr><td colspan="4">Nenhum vendedor com valor arrecadado ainda.</td></tr>
                <?php endif; ?>
                <?php foreach ($vendedores as $i => $v): ?>
                <tr>
                    <td style="color:var(--mut);font-size:.72rem"><?=$i+1?></td>
                    <td><?=htmlspecialchars($v['nome'])?></td>
                    <td class="td-num"><?=(int)$v['qtd_itens']?></td>
                    <td class="td-num td-val">R$ <?=number_format((float)$v['total'],2,',','.')?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL FOTO -->
<div class="mbk" id="m-foto">
    <div class="mbox" style="max-width:500px;text-align:center">
        <div class="m-ttl">Visualizar <span>Foto</span></div>
        <img id="foto-view" src="" style="max-width:100%;max-height:400px;border-radius:8px;border:1px solid var(--bdr)">
        <div class="mfoot">
            <button class="btn-mc" onclick="fm('m-foto')">Fechar</button>
        </div>
    </div>
</div>

<!-- MODAL EXCLUIR -->
<div class="mbk" id="m-del">
    <div class="mbox" style="max-width:360px;text-align:center">
        <div class="del-icon">🗑️</div>
        <div class="m-ttl">Excluir <span>Acessório</span></div>
        <p id="del-msg" style="font-size:.85rem;color:var(--mut);line-height:1.5"></p>
        <div class="del-aviso">⚠ Esta ação é permanente e removerá a foto do servidor.</div>
        <form method="post" action="">
            <input type="hidden" name="acao" value="excluir_gestor">
            <input type="hidden" name="id" id="del-id">
            <div class="mfoot" style="justify-content:center;margin-top:20px">
                <button type="button" class="btn-mc" onclick="fm('m-del')">Cancelar</button>
                <button type="submit" class="btn-ms" style="background:#c0392b">Sim, Excluir</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL PROMOÇÃO -->
<div class="mbk" id="m-promo">
    <div class="mbox" style="max-width:420px">
        <div class="m-ttl">Configurar <span>Promoção</span></div>
        <form method="post" action="">
            <input type="hidden" name="acao" value="salvar_promocao">
            <input type="hidden" name="id" id="promo-id">
            <div class="mrow">
                <div class="mf" style="flex:1"><label>Valor promocional (R$)</label>
                    <input type="text" class="ie ie-num" name="valor_promocional" id="promo-valor" placeholder="0,00">
                </div>
                <div class="mf" style="flex:1"><label>Valor do vendedor (R$)</label>
                    <input type="text" class="ie ie-num" name="valor_vendedor" id="promo-vendedor" placeholder="0,00">
                </div>
            </div>
            <div class="mrow">
                <div class="mf" style="flex:1"><label>Início</label>
                    <input type="date" class="ie" name="promocao_inicio" id="promo-inicio">
                </div>
                <div class="mf" style="flex:1"><label>Fim</label>
                    <input type="date" class="ie" name="promocao_fim" id="promo-fim">
                </div>
            </div>
            <p style="font-size:.72rem;color:var(--mut);margin-top:6px">Deixe "Valor promocional" em branco e salve para remover a promoção do item.</p>
            <div class="mfoot">
                <button type="button" class="btn-mc" onclick="fm('m-promo')">Cancelar</button>
                <button type="submit" class="btn-ms">Salvar</button>
            </div>
        </form>
    </div>
</div>

<template id="tpl-nova-linha">
    <tr id="tr-nova" class="row-nova">
        <td>*</td>
        <td><span class="td-foto-ph">📷</span></td>
        <td>
            <select class="ie ie-sel" id="nova-marca">
                <option value="FIAT">FIAT</option>
                <option value="JEEP">JEEP</option>
                <option value="RAM">RAM</option>
                <option value="CAR CARE">CAR CARE</option>
            </select>
        </td>
        <td><input type="text" class="ie" id="nova-acessorio" placeholder="Nome do acessório"></td>
        <td><input type="text" class="ie" id="nova-modelo" placeholder="Modelo / Aplicação"></td>
        <td><input type="text" class="ie ie-num" id="nova-valor" placeholder="0,00"></td>
        <td><input type="text" class="ie" id="nova-codigo" placeholder="Cód. Item"></td>
        <td>
            <select class="ie ie-sel" id="nova-ativo">
                <option value="1">Ativo</option>
                <option value="0">Inativo</option>
            </select>
        </td>
        <td class="th-acao">
            <div class="act-btns">
                <button class="btn-act btn-save-row" onclick="salvarNovaLinha()">💾 Salvar</button>
                <button class="btn-act btn-cancel-row" onclick="cancelarNovaLinha()">✕</button>
            </div>
        </td>
    </tr>
</template>

<script>
function am(id){ document.getElementById(id).classList.add('open'); }
function fm(id){ document.getElementById(id).classList.remove('open'); }

function mostrarAba(aba) {
    document.getElementById('aba-itens').style.display = aba === 'itens' ? '' : 'none';
    document.getElementById('aba-vendedores').style.display = aba === 'vendedores' ? '' : 'none';
    document.getElementById('tab-btn-itens').classList.toggle('active', aba === 'itens');
    document.getElementById('tab-btn-vendedores').classList.toggle('active', aba === 'vendedores');
    document.getElementById('btn-nova-linha').style.display = aba === 'itens' ? '' : 'none';
}

function verFoto(url){
    document.getElementById('foto-view').src = url;
    am('m-foto');
}

function editarLinha(id){
    const tr = document.getElementById('tr-'+id);
    tr.classList.add('editando');
    tr.querySelector('.view-acts').style.display='none';
    tr.querySelector('.edit-acts').style.display='flex';
}
function cancelarEdicao(id){
    const tr = document.getElementById('tr-'+id);
    tr.classList.remove('editando');
    tr.querySelector('.view-acts').style.display='flex';
    tr.querySelector('.edit-acts').style.display='none';
}
function salvarLinha(id){
    const tr=document.getElementById('tr-'+id);
    const get=n=>tr.querySelector(`[data-field="${n}"]`)?.value||'';
    const f=document.createElement('form'); f.method='post'; f.action='';
    f.innerHTML=`<input type="hidden" name="acao" value="salvar_gestor">
        <input type="hidden" name="id" value="${id}">
        <input type="hidden" name="marca" value="${esc(get('marca'))}">
        <input type="hidden" name="acessorio" value="${esc(get('acessorio'))}">
        <input type="hidden" name="modelo" value="${esc(get('modelo'))}">
        <input type="hidden" name="valor" value="${esc(get('valor'))}">
        <input type="hidden" name="codigo_item" value="${esc(get('codigo_item'))}">
        <input type="hidden" name="ativo" value="${esc(get('ativo'))}">`;
    document.body.appendChild(f); f.submit();
}
function esc(s){ const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

// ── NOVA LINHA ──
function adicionarLinha(){
    if(document.getElementById('tr-nova')) return; // já existe
    const tpl=document.getElementById('tpl-nova-linha');
    const clone=tpl.content.cloneNode(true);
    document.getElementById('tbody').prepend(clone);
    document.getElementById('nova-acessorio').focus();
}
function salvarNovaLinha(){
    const get=id=>document.getElementById(id)?.value||'';
    const f=document.createElement('form'); f.method='post'; f.action='';
    f.innerHTML=`<input type="hidden" name="acao" value="salvar_gestor">
        <input type="hidden" name="id" value="0">
        <input type="hidden" name="marca" value="${esc(get('nova-marca'))}">
        <input type="hidden" name="acessorio" value="${esc(get('nova-acessorio'))}">
        <input type="hidden" name="modelo" value="${esc(get('nova-modelo'))}">
        <input type="hidden" name="valor" value="${esc(get('nova-valor'))}">
        <input type="hidden" name="codigo_item" value="${esc(get('nova-codigo'))}">
        <input type="hidden" name="ativo" value="${esc(get('nova-ativo'))}">`;
    document.body.appendChild(f); f.submit();
}
function cancelarNovaLinha(){
    const tr=document.getElementById('tr-nova'); if(tr) tr.remove();
}

// ── DUPLICAR ──
function duplicarLinha(id){
    const f=document.createElement('form'); f.method='post'; f.action='';
    f.innerHTML=`<input type="hidden" name="acao" value="duplicar_gestor"><input type="hidden" name="id" value="${id}">`;
    document.body.appendChild(f); f.submit();
}

// ── EXCLUIR ──
function confirmarExcluir(id, nome){
    document.getElementById('del-id').value=id;
    document.getElementById('del-msg').innerHTML=`Tem certeza que deseja excluir:<br><strong style="font-size:.95rem;color:#333;display:inline-block;margin-top:6px">${nome}</strong>`;
    am('m-del');
}

// ── PROMOÇÃO ──
function abrirPromocao(id, valorPromo, valorVendedor, inicio, fim) {
    document.getElementById('promo-id').value = id;
    document.getElementById('promo-valor').value = (valorPromo && valorPromo !== '' && valorPromo !== '0.00') ? valorPromo.replace('.', ',') : '';
    document.getElementById('promo-vendedor').value = (valorVendedor && valorVendedor !== '' && valorVendedor !== '0.00') ? valorVendedor.replace('.', ',') : '';
    document.getElementById('promo-inicio').value = (inicio && inicio !== '') ? inicio.substring(0, 10) : '';
    document.getElementById('promo-fim').value = (fim && fim !== '') ? fim.substring(0, 10) : '';
    am('m-promo');
}

// Busca auto-submit com debounce
let _bt; document.querySelector('.filt-busca input')?.addEventListener('input',function(){clearTimeout(_bt);_bt=setTimeout(()=>this.closest('form').submit(),500);});
</script>
</body>
</html>
