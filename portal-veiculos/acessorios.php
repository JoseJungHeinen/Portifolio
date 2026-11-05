<?php
require_once __DIR__ . '/config_database.php';
require_once __DIR__ . '/acessorios_promocao.php';
garantirSchemaPromocaoAcessorios($mysqli);
session_start();

// Apenas verifica se está logado - qualquer setor pode acessar
if (empty($_SESSION['logado'])) { 
    exit('Acesso negado'); 
}

// Determina se pode editar (admin ou permissão específica)
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

function br_to_float(string $v): float {
    $v = str_replace(['R$',' '], '', trim($v));
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return (float)$v;
}

/* CADASTRO */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastrar_acessorio') {
    if (!$canEdit) exit('Acesso negado.');
    $marca = trim($_POST['marca'] ?? ''); $acc = trim($_POST['acessorio'] ?? '');
    $mod   = trim($_POST['modelo'] ?? ''); $val = trim($_POST['valor'] ?? '');
    $cod   = trim($_POST['codigo_item'] ?? '');
    if ($marca && $acc && $mod && $val) {
        $vs = number_format(br_to_float($val), 2, '.', '');
        $s = $mysqli->prepare("INSERT INTO acessorios_marcas (marca,acessorio,modelo,valor,codigo_item) VALUES(?,?,?,?,?)");
        if ($s) { $s->bind_param('sssss',$marca,$acc,$mod,$vs,$cod); $s->execute(); $s->close(); }
    }
    header('Location: acessorios.php'); exit;
}

/* EDICAO */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_linha') {
    if (!$canEdit) exit('Acesso negado.');
    $id = (int)($_POST['id'] ?? 0);
    $marca = trim($_POST['marca'] ?? ''); $acc = trim($_POST['acessorio'] ?? '');
    $mod   = trim($_POST['modelo'] ?? ''); $val = trim($_POST['valor'] ?? '');
    $cod   = trim($_POST['codigo_item'] ?? '');
    if ($id > 0) {
        $vs = number_format(br_to_float($val), 2, '.', '');
        $sql = "UPDATE acessorios_marcas SET marca=?,acessorio=?,modelo=?,valor=?,codigo_item=?";
        $types = 'sssss'; $params = [$marca,$acc,$mod,$vs,$cod];
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
            if (in_array($ext,['jpg','jpeg','png','gif','webp'],true)) {
                $dir = __DIR__.'/uploads_acessorios';
                if (!is_dir($dir)) mkdir($dir,0777,true);
                $nm = 'acc_'.$id.'_'.time().'.'.$ext;
                if (move_uploaded_file($_FILES['foto']['tmp_name'], $dir.'/'.$nm)) {
                    $sql .= ", foto_path=?"; $types .= 's'; $params[] = 'uploads_acessorios/'.$nm;
                }
            }
        }
        $sql .= " WHERE id=?"; $types .= 'i'; $params[] = $id;
        $s = $mysqli->prepare($sql);
        if ($s) { $s->bind_param($types,...$params); $s->execute(); $s->close(); }
    }
    header('Location: acessorios.php'); exit;
}

/* EXCLUSAO */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_linha') {
    if (!$canEdit) exit('Acesso negado.');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmtF = $mysqli->prepare("SELECT foto_path FROM acessorios_marcas WHERE id = ? LIMIT 1");
        if ($stmtF) {
            $stmtF->bind_param('i', $id);
            $stmtF->execute();
            $rowF = $stmtF->get_result()->fetch_assoc();
            $stmtF->close();
            if (!empty($rowF['foto_path']) && file_exists(__DIR__ . '/' . $rowF['foto_path'])) {
                unlink(__DIR__ . '/' . $rowF['foto_path']);
            }
        }
        $s = $mysqli->prepare("DELETE FROM acessorios_marcas WHERE id = ?");
        if ($s) { $s->bind_param('i', $id); $s->execute(); $s->close(); }
    }
    header('Location: acessorios.php'); exit;
}

/* DUPLICAR */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'duplicar_linha') {
    if (!$canEdit) exit('Acesso negado.');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $s = $mysqli->prepare("INSERT INTO acessorios_marcas (marca, acessorio, modelo, valor, codigo_item)
            SELECT marca, acessorio, CONCAT(modelo, ' (cópia)'), valor, codigo_item
            FROM acessorios_marcas WHERE id = ? LIMIT 1");
        if ($s) { $s->bind_param('i', $id); $s->execute(); $s->close(); }
    }
    header('Location: acessorios.php'); exit;
}

/* CARREGA */
$itens = [];
$res = $mysqli->query("SELECT id,marca,acessorio,modelo,valor,foto_path,COALESCE(codigo_item,'') AS codigo_item, valor_promocional, valor_vendedor, promocao_inicio, promocao_fim FROM acessorios_marcas ORDER BY marca,acessorio,modelo");
if ($res) while ($r = $res->fetch_assoc()) $itens[] = aplicarValorEfetivo($r);

$cats = [];
foreach ($itens as $it) {
    $c = $it['acessorio'];
    if (!isset($cats[$c])) $cats[$c] = [];
    $cats[$c][] = $it;
}
ksort($cats);

$modelos = [];
foreach ($itens as $it) {
    foreach (preg_split('/[\/;,]/u', $it['modelo']) as $t) {
        $t = trim($t);
        if (!$t) continue;
        $partes = explode(' - ', $t, 2);
        $carro  = trim($partes[0]);
        $up     = mb_strtoupper($carro, 'UTF-8');
        if (in_array($up, ['UNIVERSAL', 'STANDARD', 'PREMIUM'], true)) continue;
        if (!in_array($carro, $modelos, true)) $modelos[] = $carro;
    }
}
sort($modelos);

/* Modelos agrupados por marca */
$modelosPorMarca = ['FIAT' => [], 'JEEP' => [], 'RAM' => [], 'CAR CARE' => []];
foreach ($itens as $it) {
    $marca = $it['marca'];
    if (!isset($modelosPorMarca[$marca])) continue;
    foreach (preg_split('/[\/;,]/u', $it['modelo']) as $t) {
        $t = trim($t);
        if (!$t) continue;
        $carro = trim(explode(' - ', $t, 2)[0]);
        $up = mb_strtoupper($carro, 'UTF-8');
        if (in_array($up, ['UNIVERSAL', 'STANDARD', 'PREMIUM'], true)) continue;
        if (!in_array($carro, $modelosPorMarca[$marca], true)) $modelosPorMarca[$marca][] = $carro;
    }
}
foreach ($modelosPorMarca as &$arr) sort($arr);
unset($arr);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Acessórios — Exemplo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --r:#2347C8;--rd:#c0000f;--sidebar:#5a5a5a;
    --bg:#f4f4f4;--bg2:#ffffff;--bg3:#efefef;
    --bdr:#d8d8d8;--txt:#333333;--txt2:#555555;--mut:#888888;
    --fiat:#2347C8;--jeep:#3a8c3e;--ram:#b8972a;--carcare:#1a6ba0;
    --orc-w:320px;
    --green:#1b7a1b;
}
html{scroll-behavior:smooth}
body{font-family:'Roboto',system-ui,sans-serif;background:var(--bg);color:var(--txt);min-height:100vh;overflow-x:hidden}

/* ── TOPBAR ── */
.topbar{
    position:sticky;top:0;z-index:60;
    background:#fff;
    border-bottom:2px solid var(--bdr);
    box-shadow:0 2px 6px rgba(0,0,0,.08);
    padding:0 20px;
}
.topbar-row1{
    display:flex;align-items:center;height:54px;gap:12px;
}
.tb-brand{font-size:.95rem;font-weight:700;color:var(--txt);white-space:nowrap;margin-right:14px;display:flex;align-items:center;gap:6px}
.tb-brand em{color:var(--r);font-style:normal;font-weight:700}
.tb-brand-bar{width:3px;height:22px;background:var(--r);border-radius:2px}
.tb-sep{width:1px;height:22px;background:var(--bdr);flex-shrink:0}
.tb-count{font-size:.72rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--mut);white-space:nowrap;margin-left:auto}
.btn-novo{font-size:.72rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:6px 14px;border-radius:4px;border:1.5px solid var(--r);background:var(--r);color:#fff;cursor:pointer;white-space:nowrap;transition:all .13s}
.btn-novo:hover{background:var(--rd);border-color:var(--rd)}

/* ── MARCAS multi-select ── */
.mt{display:flex;gap:4px}
.mt-btn{
    font-size:.72rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;
    padding:5px 13px;border-radius:4px;border:1.5px solid var(--bdr);
    background:#fff;color:var(--mut);cursor:pointer;transition:all .13s;user-select:none;
}
.mt-btn:hover{color:var(--txt);border-color:#aaa;background:#f0f0f0}
.mt-btn.on-todas{background:var(--sidebar);color:#fff;border-color:var(--sidebar)}
.mt-btn.sel-FIAT{background:var(--fiat);color:#fff;border-color:var(--fiat)}
.mt-btn.sel-JEEP{background:var(--jeep);color:#fff;border-color:var(--jeep)}
.mt-btn.sel-RAM{background:var(--ram);color:#fff;border-color:var(--ram)}
.mt-btn.sel-CAR-CARE{background:var(--carcare);color:#fff;border-color:var(--carcare)}
.mt-btn.mt-promo.ativo{background:#a86b00;color:#fff;border-color:#a86b00}

/* ── BUSCA ── */
.tb-busca{display:flex;align-items:center;gap:5px}
.tb-busca input{
    border:1.5px solid var(--bdr);border-radius:4px;padding:6px 10px;
    font-size:.78rem;color:var(--txt);outline:none;width:180px;
    transition:all .14s;font-family:inherit;
}
.tb-busca input:focus{border-color:#aaa;width:230px;box-shadow:0 0 0 3px rgba(35,71,200,.08)}
.tb-busca-clear{background:none;border:none;color:var(--mut);cursor:pointer;font-size:.72rem;padding:2px}
.tb-busca-clear:hover{color:var(--r)}

/* ── FAIXA DE MODELOS (linha abaixo da topbar) ── */
.models-strip{
    display:none;
    align-items:center;
    gap:6px;
    flex-wrap:wrap;
    padding:7px 0 9px;
    border-top:1px solid var(--bdr);
    min-height:40px;
    animation:stripIn .18s ease;
}
.models-strip.visible{display:flex}
@keyframes stripIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
.models-strip-label{
    font-size:.62rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
    color:var(--mut);white-space:nowrap;flex-shrink:0;
}
.model-chip{
    display:inline-flex;align-items:center;gap:4px;
    padding:4px 11px;border-radius:20px;
    border:1.5px solid var(--bdr);background:#fff;
    color:var(--txt);font-size:.72rem;font-weight:600;
    cursor:pointer;user-select:none;transition:all .13s;
    white-space:nowrap;
}
.model-chip:hover{border-color:#aaa;background:#f5f5f5}
.model-chip.sel-FIAT{background:var(--fiat);color:#fff;border-color:var(--fiat)}
.model-chip.sel-JEEP{background:var(--jeep);color:#fff;border-color:var(--jeep)}
.model-chip.sel-RAM{background:var(--ram);color:#fff;border-color:var(--ram)}
.model-chip.sel-CAR-CARE{background:var(--carcare);color:#fff;border-color:var(--carcare)}

/* ── LAYOUT COM PAINEL LATERAL ── */
.page-main{flex:1;min-width:0;transition:margin-right .3s cubic-bezier(.4,0,.2,1)}
.page-main.com-painel{margin-right:var(--orc-w)}

/* ── PAINEL LATERAL DE ORÇAMENTO ── */
.orc-painel{position:fixed;top:54px;right:0;width:var(--orc-w);height:calc(100vh - 54px);background:#fff;border-left:2px solid var(--bdr);box-shadow:-4px 0 18px rgba(0,0,0,.08);display:flex;flex-direction:column;z-index:50;transform:translateX(100%);transition:transform .3s cubic-bezier(.4,0,.2,1)}
.orc-painel.aberto{transform:translateX(0)}
.orc-painel-header{padding:14px 16px 12px;border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.orc-painel-titulo{display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--txt)}
.orc-painel-titulo-barra{width:3px;height:16px;background:var(--r);border-radius:2px}
.orc-painel-badge{background:var(--r);color:#fff;border-radius:999px;font-size:.6rem;font-weight:700;padding:2px 7px;min-width:20px;text-align:center}
.orc-painel-fechar{background:none;border:none;color:var(--mut);cursor:pointer;font-size:.9rem;padding:4px;border-radius:4px;transition:color .12s}
.orc-painel-fechar:hover{color:var(--r)}
.orc-painel-lista{flex:1;overflow-y:auto;padding:8px 0}
.orc-painel-lista::-webkit-scrollbar{width:4px}
.orc-painel-lista::-webkit-scrollbar-thumb{background:var(--bdr);border-radius:2px}
.orc-painel-vazio{padding:40px 20px;text-align:center;font-size:.78rem;color:var(--mut);letter-spacing:.06em;text-transform:uppercase}
.orc-painel-item{display:flex;align-items:flex-start;gap:8px;padding:9px 14px;border-bottom:1px solid var(--bg3);transition:background .12s;animation:itemIn .18s ease}
@keyframes itemIn{from{opacity:0;transform:translateX(12px)}to{opacity:1;transform:translateX(0)}}
.orc-painel-item:hover{background:#fafafa}
.orc-pi-cor{width:3px;border-radius:2px;flex-shrink:0;align-self:stretch;min-height:36px}
.cor-FIAT{background:var(--fiat)}.cor-JEEP{background:var(--jeep)}.cor-RAM{background:var(--ram)}.cor-CAR-CARE{background:var(--carcare)}
.orc-pi-info{flex:1;min-width:0}
.orc-pi-marca{font-size:.58rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:2px}
.orc-pi-marca.FIAT{color:var(--fiat)}.orc-pi-marca.JEEP{color:var(--jeep)}.orc-pi-marca.RAM{color:var(--ram)}.orc-pi-marca.CAR-CARE{color:var(--carcare)}
.orc-pi-nome{font-size:.82rem;font-weight:700;color:var(--txt);line-height:1.2;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.orc-pi-mod{font-size:.66rem;color:var(--mut);line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.orc-pi-cod{display:inline;color:var(--r);font-weight:700;margin-left:6px}
.orc-pi-val{font-size:.88rem;font-weight:700;color:var(--r);white-space:nowrap;flex-shrink:0;border:none;background:transparent;outline:none;width:80px;text-align:right}
.orc-pi-rm{background:none;border:none;color:#ccc;cursor:pointer;font-size:.72rem;padding:2px;flex-shrink:0;line-height:1;transition:color .12s;margin-top:2px}
.orc-pi-rm:hover{color:var(--r)}
.orc-painel-footer{padding:12px 14px;border-top:2px solid var(--bdr);flex-shrink:0;background:#fff}
.orc-painel-total-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
.orc-painel-total-lbl{font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--mut)}
.orc-painel-total-val{font-size:1.3rem;font-weight:700;color:var(--r)}
.orc-painel-total-sub{font-size:.7rem;color:var(--mut);text-decoration:line-through;text-align:right;margin-bottom:6px;min-height:16px}
.orc-painel-btns{display:flex;gap:6px}
.orc-painel-btn{flex:1;height:36px;border-radius:6px;border:none;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;cursor:pointer;transition:all .12s}
.orc-pb-limp{background:#f0f0f0;color:var(--mut);border:1.5px solid var(--bdr)}
.orc-pb-limp:hover{background:#e8e8e8;color:var(--txt)}
.orc-pb-gerar{background:var(--r);color:#fff}
.orc-pb-gerar:hover{background:var(--rd)}
.orc-pb-tags{width:100%;margin-top:6px;height:34px;border-radius:6px;border:1.5px solid var(--bdr);background:#fff;color:var(--txt);font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;cursor:pointer;transition:all .12s}
.orc-pb-tags:hover{background:#f5f5f5;border-color:#aaa}

.orc-fab{position:fixed;bottom:24px;right:24px;z-index:55;background:var(--r);color:#fff;border:none;border-radius:999px;padding:10px 20px;font-size:.75rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;box-shadow:0 6px 20px rgba(35,71,200,.45);display:flex;align-items:center;gap:8px;transition:all .2s;transform:translateY(0)}
.orc-fab:hover{background:var(--rd);transform:translateY(-2px);box-shadow:0 10px 28px rgba(35,71,200,.5)}
.orc-fab.hidden{transform:translateY(120px);pointer-events:none}
.orc-fab-badge{background:#fff;color:var(--r);border-radius:999px;font-size:.65rem;font-weight:700;padding:1px 7px;min-width:20px;text-align:center}

/* ── HERO ── */
.hero{padding:32px 28px 24px;background:#fff;border-bottom:1px solid var(--bdr)}
.hero-eye{font-size:.65rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:var(--r);margin-bottom:6px}
.hero-h1{font-size:clamp(1.5rem,3vw,2.2rem);font-weight:700;color:var(--txt);letter-spacing:-.01em;line-height:1.15}
.hero-h1 em{font-style:normal;color:var(--sidebar)}
.hero-sub{margin-top:8px;font-size:.82rem;color:var(--mut);max-width:480px;line-height:1.6}

/* ── FORM CADASTRO ── */
.cad-wrap{display:none;background:#fff;border-bottom:2px solid var(--bdr);padding:14px 22px;box-shadow:inset 0 -3px 8px rgba(0,0,0,.04)}
.cad-form{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}
.cf{display:flex;flex-direction:column;gap:4px}
.cf label{font-size:.63rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--mut)}
.cf input,.cf select{background:#fff;border:1.5px solid var(--bdr);border-radius:4px;color:var(--txt);font-size:.82rem;padding:7px 10px;outline:none;transition:border-color .14s}
.cf input:focus,.cf select:focus{border-color:#aaa;box-shadow:0 0 0 3px rgba(35,71,200,.08)}
.btn-save{font-size:.75rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:8px 18px;border-radius:4px;border:none;background:var(--r);color:#fff;cursor:pointer;height:36px;transition:background .12s}
.btn-save:hover{background:var(--rd)}
.btn-cl{font-size:.75rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;padding:8px 14px;border-radius:4px;border:1.5px solid var(--bdr);background:#fff;color:var(--mut);cursor:pointer;height:36px;transition:all .12s}
.btn-cl:hover{color:var(--txt);border-color:#aaa}

/* ── MAIN-WRAP e PAGINAÇÃO ── */
.main-wrap{padding:20px 20px 80px}
.pag-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.pag-info{font-size:.77rem;color:var(--mut);font-weight:500}
.pag-info strong{color:var(--txt)}
.pag-controles{display:flex;align-items:center;gap:8px}
.pag-per-page{display:flex;align-items:center;gap:6px;font-size:.75rem;color:var(--mut)}
.pag-per-page select{background:#fff;border:1.5px solid var(--bdr);border-radius:4px;color:var(--txt);font-size:.75rem;padding:4px 8px;outline:none;cursor:pointer}
.pag-divider{width:1px;height:18px;background:var(--bdr)}
.pag-nums{display:flex;gap:4px;align-items:center}
.pag-btn{width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:4px;border:1.5px solid var(--bdr);background:#fff;color:var(--mut);cursor:pointer;font-size:.8rem;font-weight:600;transition:all .13s;padding:0}
.pag-btn:hover:not(:disabled){border-color:#aaa;color:var(--txt);background:#f0f0f0}
.pag-btn:disabled{opacity:.32;cursor:not-allowed}
.pag-btn.active{background:var(--r);border-color:var(--r);color:#fff}
.pag-ellipsis{font-size:.72rem;color:var(--mut);padding:0 2px;line-height:32px}

/* ── CATS GRID ── */
.cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:18px}
.cat-tile{position:relative;overflow:hidden;cursor:pointer;background:#fff;border:1px solid var(--bdr);border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);transition:box-shadow .18s,transform .18s,border-color .18s}
.cat-tile:hover{box-shadow:0 8px 26px rgba(0,0,0,.13);transform:translateY(-3px)}
.cat-tile.aberta{border-color:var(--r);box-shadow:0 0 0 2px rgba(35,71,200,.2),0 6px 20px rgba(0,0,0,.1)}
.cat-tile:hover .cat-bg{transform:scale(1.05)}
.cat-tile:hover .cat-barra{width:42px}
.cat-tile:hover .cat-arrow-wrap,.cat-tile.aberta .cat-arrow-wrap{background:var(--r);color:#fff}
.cat-topo-stripe{position:absolute;top:0;left:0;right:0;height:4px;z-index:3;border-radius:12px 12px 0 0}
.stripe-FIAT{background:var(--fiat)}.stripe-JEEP{background:var(--jeep)}.stripe-RAM{background:var(--ram)}.stripe-CAR-CARE{background:var(--carcare)}
.stripe-MIX{background:linear-gradient(90deg,var(--fiat) 33%,var(--jeep) 33% 66%,var(--ram) 66%)}
.cat-active-bar{position:absolute;bottom:0;left:0;height:3px;background:var(--r);z-index:3;width:0;transition:width .32s cubic-bezier(.4,0,.2,1)}
.cat-tile.aberta .cat-active-bar{width:100%}

/* ── BADGE "NO ORÇAMENTO" nos tiles de item único ── */
.cat-orc-badge{
    position:absolute;top:10px;right:10px;z-index:10;
    background:var(--green);color:#fff;
    font-size:.58rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
    padding:3px 9px 3px 7px;border-radius:999px;
    box-shadow:0 2px 8px rgba(0,0,0,.22);
    pointer-events:none;
    display:flex;align-items:center;gap:4px;
    animation:badgeIn .2s cubic-bezier(.34,1.56,.64,1);
}
.cat-orc-badge::before{content:'✓';font-size:.7rem;font-weight:900}
@keyframes badgeIn{from{opacity:0;transform:scale(.6) translateY(-4px)}to{opacity:1;transform:scale(1) translateY(0)}}

/* Tile com item único no orçamento */
.cat-tile.single-orc{
    border-color:var(--green) !important;
    box-shadow:0 0 0 2px rgba(27,122,27,.2), 0 4px 16px rgba(0,0,0,.08) !important;
}
.cat-tile.single-orc .cat-active-bar{
    background:var(--green);
    width:100%;
}
/* Botão de toggle no rodapé para item único */
.cat-arrow-wrap.orc-toggle-btn{
    width:30px;height:30px;border-radius:50%;
    background:var(--bg3);
    display:flex;align-items:center;justify-content:center;
    font-size:.75rem;color:var(--mut);
    transition:all .2s;
    font-weight:700;
}
.cat-tile.single-orc .cat-arrow-wrap.orc-toggle-btn,
.cat-tile:hover .cat-arrow-wrap.orc-toggle-btn{
    background:var(--green);color:#fff;
}
.cat-tile:not(.single-orc):hover .cat-arrow-wrap.orc-toggle-btn{
    background:var(--r);color:#fff;
}
/* seta normal */
.cat-arrow-wrap{width:30px;height:30px;border-radius:50%;background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:.8rem;color:var(--mut);transition:all .2s}

.cat-thumb{width:100%;aspect-ratio:16/9;overflow:hidden;background:var(--bg3)}
.cat-bg{width:100%;height:100%;background-size:cover;background-position:center;transition:transform .45s cubic-bezier(.25,.46,.45,.94)}
.cat-bg-ph{width:100%;height:100%;background:repeating-linear-gradient(45deg,rgba(0,0,0,.03) 0,rgba(0,0,0,.03) 1px,transparent 1px,transparent 18px),var(--bg3);display:flex;align-items:center;justify-content:center;color:rgba(0,0,0,.12)}
.cat-bg-ph svg{width:48px;height:48px}
.cat-body{padding:14px 16px 16px}
.cat-barra{width:22px;height:3px;background:var(--r);margin-bottom:9px;transition:width .28s;border-radius:2px}
.cat-nome{font-size:1.1rem;font-weight:700;text-transform:uppercase;letter-spacing:.01em;color:var(--txt);line-height:1.15;margin-bottom:8px}
.cat-marcas{display:flex;gap:5px;margin-bottom:10px;flex-wrap:wrap}
.cat-marca-pill{font-size:.6rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:3px 8px;border-radius:3px;color:#fff}
.cm-FIAT{background:var(--fiat)}.cm-JEEP{background:var(--jeep)}.cm-RAM{background:var(--ram)}.cm-CAR-CARE{background:var(--carcare)}
.cat-rodape{display:flex;align-items:center;justify-content:space-between;padding-top:10px;border-top:1px solid var(--bdr)}
.cat-qty{font-size:.72rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--mut)}

/* valor no rodapé para item único */
.cat-val-single{font-size:.85rem;font-weight:700;color:var(--r)}

/* "de X por Y" em promoção */
.preco-de{font-size:.78em;color:var(--mut);text-decoration:line-through}
.preco-por{font-size:1.3em;font-weight:700;color:var(--r)}

/* botão de presente (bônus do vendedor) */
.cat-rodape-dir,.prod-bottom-dir{display:flex;align-items:center;gap:6px}
.cat-bonus-btn,.prod-bonus-btn{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:var(--bg3);color:var(--r);font-size:.95rem;cursor:pointer;flex-shrink:0;transition:background .12s,transform .12s}
.cat-bonus-btn:hover,.prod-bonus-btn:hover{background:var(--r);color:#fff;transform:scale(1.08)}

/* modal de bônus */
.bonus-nome{font-size:1.1rem;font-weight:700;text-transform:uppercase;letter-spacing:.01em;color:var(--txt);margin-bottom:10px}
.bonus-txt{font-size:.85rem;color:var(--txt2);line-height:1.5}
.bonus-valor{font-size:1.75rem;font-weight:700;color:var(--r);margin:4px 0}

/* ── PAINEL HERO ── */
.painel-hero{display:none;background:#fff;border-bottom:2px solid var(--bdr);padding:22px 28px 26px;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.painel-hero.open{display:block;animation:fadeIn .2s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.painel-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:8px}
.painel-titulo{display:flex;align-items:center;gap:10px;font-size:1.1rem;font-weight:700;text-transform:uppercase;letter-spacing:.01em;color:var(--txt)}
.painel-titulo-linha{width:4px;height:20px;background:var(--r);border-radius:2px}
.btn-fechar{font-size:.72rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;background:none;border:1.5px solid var(--bdr);border-radius:4px;color:var(--mut);cursor:pointer;padding:6px 14px;transition:all .12s}
.btn-fechar:hover{color:var(--r);border-color:var(--r)}
.painel-footer{display:flex;align-items:center;justify-content:space-between;margin-top:22px;padding-top:14px;border-top:1px solid var(--bdr);flex-wrap:wrap;gap:8px}
.painel-pag-info{font-size:.75rem;color:var(--mut)}
.painel-pag-info strong{color:var(--txt)}

/* ── PROD GRID ── */
.prod-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px}
.prod-card{position:relative;background:#fff;border:1px solid var(--bdr);border-radius:10px;overflow:hidden;transition:box-shadow .16s,transform .16s;cursor:pointer}
.prod-card:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:2}
.prod-card:hover .prod-foto-img{transform:scale(1.06)}
.prod-card:hover .prod-hover{opacity:1}
.prod-card.no-orc{outline:2px solid var(--r);outline-offset:-2px}
.prod-foto{aspect-ratio:4/3;overflow:hidden;background:#f0f0f0}
.prod-foto-img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .32s}
.prod-foto-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:rgba(0,0,0,.15)}
.prod-foto-ph svg{width:32px;height:32px}
.prod-hover{position:absolute;top:0;left:0;right:0;aspect-ratio:4/3;background:linear-gradient(to top,rgba(0,0,0,.55) 0%,transparent 65%);display:flex;align-items:flex-end;padding:9px;opacity:0;transition:opacity .18s}
.prod-hover-acts{display:flex;gap:5px;width:100%;flex-wrap:wrap}
.btn-ph{font-size:.65rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:5px 10px;border-radius:4px;border:1.5px solid rgba(255,255,255,.6);background:rgba(0,0,0,.35);color:#fff;cursor:pointer;backdrop-filter:blur(3px);transition:all .11s}
.btn-ph:hover{background:rgba(0,0,0,.6);border-color:#fff}
.btn-del-ph{background:rgba(192,57,43,.75);border-color:rgba(192,57,43,.9)}
.btn-del-ph:hover{background:rgba(192,57,43,1);border-color:#fff}
.btn-dup-ph{background:rgba(30,100,200,.75);border-color:rgba(30,100,200,.9)}
.btn-dup-ph:hover{background:rgba(30,100,200,1);border-color:#fff}
.btn-orc-ph{background:var(--r);border-color:var(--r)}
.btn-orc-ph:hover{background:var(--rd);border-color:var(--rd)}
.btn-orc-ph.sel{background:#1b7a1b;border-color:#1b7a1b}
.prod-stripe{position:absolute;top:0;bottom:0;left:0;width:4px}
.prod-info{padding:11px 12px 13px 15px}
.prod-marca-tag{font-size:.6rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin-bottom:3px}
.prod-marca-tag.FIAT{color:var(--fiat)}.prod-marca-tag.JEEP{color:var(--jeep)}.prod-marca-tag.RAM{color:var(--ram)}.prod-marca-tag.CAR-CARE{color:var(--carcare)}
.prod-nome{font-size:.98rem;font-weight:700;text-transform:uppercase;letter-spacing:.01em;line-height:1.2;color:var(--txt);margin-bottom:3px}
.prod-mod{font-size:.72rem;color:var(--mut);line-height:1.45}
.prod-bottom{display:flex;align-items:center;justify-content:space-between;margin-top:9px;padding-top:9px;border-top:1px solid var(--bdr)}
.prod-val{font-size:1.08rem;font-weight:700;color:var(--r)}
.chk-orc{width:16px;height:16px;accent-color:var(--r);cursor:pointer}

/* ── MODAIS ── */
.mbk{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:200;padding:14px;backdrop-filter:blur(4px)}
.mbk.open{display:flex}
.mbox{background:#fff;border:1px solid var(--bdr);border-radius:10px;padding:24px 26px;width:100%;max-width:560px;box-shadow:0 20px 60px rgba(0,0,0,.18);max-height:90vh;overflow-y:auto}
.mbox::-webkit-scrollbar{width:4px}.mbox::-webkit-scrollbar-thumb{background:var(--r);border-radius:2px}
.m-ttl{font-size:1.15rem;font-weight:700;text-transform:uppercase;letter-spacing:.01em;margin-bottom:18px;color:var(--txt)}
.m-ttl span{color:var(--r)}
.mf{display:flex;flex-direction:column;gap:4px}
.mf label{font-size:.62rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--mut)}
.mf input,.mf select{background:#fff;border:1.5px solid var(--bdr);border-radius:4px;color:var(--txt);font-size:.82rem;padding:7px 10px;outline:none;transition:border-color .14s}
.mf input:focus,.mf select:focus{border-color:#aaa;box-shadow:0 0 0 3px rgba(35,71,200,.08)}
.mf input[type="file"]{cursor:pointer;padding:5px 7px}
.mrow{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px}
.mfoot{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}
.btn-mc{font-size:.73rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase;padding:8px 16px;border-radius:4px;border:1.5px solid var(--bdr);background:#fff;color:var(--mut);cursor:pointer;transition:all .12s}
.btn-mc:hover{color:var(--txt);border-color:#aaa}
.btn-ms{font-size:.73rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:8px 20px;border-radius:4px;border:none;background:var(--r);color:#fff;cursor:pointer;transition:background .12s}
.btn-ms:hover{background:var(--rd)}
.btn-del{font-size:.73rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:8px 20px;border-radius:4px;border:none;background:#c0392b;color:#fff;cursor:pointer;transition:background .12s}
.btn-del:hover{background:#a93226}

/* ── MODAL ORÇAMENTO ── */
.orc-section-title{font-size:.62rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--mut);margin-bottom:8px;margin-top:4px;border-bottom:1px solid var(--bdr);padding-bottom:5px}
.orc-list{background:var(--bg3);border:1px solid var(--bdr);border-radius:4px;padding:4px 0;max-height:200px;overflow-y:auto;margin-bottom:10px}
.oi{display:flex;justify-content:space-between;align-items:center;padding:7px 12px;border-bottom:1px solid var(--bdr);gap:8px;font-size:.8rem}
.oi:last-child{border-bottom:none}
.oi-n{flex:1;color:var(--txt);line-height:1.35}
.oi-n small{display:block;color:var(--mut);font-size:.68rem;margin-top:2px}
.oi-v{font-weight:700;color:var(--r);white-space:nowrap;margin-right:4px}
.oi-rm{width:22px;height:22px;border-radius:50%;border:none;background:#fef2f2;color:#c0392b;font-size:.75rem;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .12s}
.oi-rm:hover{background:#fcc;color:#a93226}
.orc-totais{margin-bottom:12px}
.orc-total-linha{display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:.82rem;color:var(--mut)}
.orc-total-linha strong{color:var(--txt)}
.orc-grand{font-size:1.4rem;font-weight:700;color:var(--r);display:flex;justify-content:space-between;align-items:center;border-top:2px solid var(--r);margin-top:6px;padding-top:8px}
.orc-grand-lbl{font-size:.7rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--mut)}

/* ── SIMULADOR DE PARCELAS ── */
.sim-wrap{background:#f9f0f0;border:1.5px solid #f5d0d0;border-radius:8px;padding:14px;margin-bottom:14px}
.sim-titulo{font-size:.62rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--r);margin-bottom:10px;display:flex;align-items:center;gap:6px}
.sim-titulo::before{content:'💳';font-size:.85rem}
.sim-condicao{background:#fff;border:1.5px solid var(--r);border-radius:6px;margin-bottom:6px;overflow:hidden;cursor:pointer;transition:transform .15s,border-color .15s,box-shadow .15s;background:#fff;position:relative}
.sim-condicao:hover{transform:scale(1.01);border-color:#c0000f}
.sim-condicao.sel{box-shadow:0 0 0 7px rgba(35,71,200,.3);border-color:#d10000;background:#eef2fd;transform:scale(1.02);}
.sim-condicao.sel .sim-parc{color:#b00000}
.sim-condicao.sel .sim-obs{color:#b00000;font-weight:700}
.sim-condicao.sel::after{content:'Selecionado';position:absolute;top:6px;right:8px;padding:2px 8px;font-size:.6rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;background:#d1000080;color:#fff;border-radius:999px;}
.sim-condicao:last-child{margin-bottom:0}
.sim-row{display:flex;align-items:center;justify-content:space-between;padding:9px 12px}
.sim-parc{font-size:.9rem;font-weight:700;color:var(--txt)}
.sim-parc em{color:var(--r);font-style:normal}
.sim-obs{font-size:.62rem;color:var(--green);font-weight:600;letter-spacing:.04em}
.sim-val-parc{font-size:.95rem;font-weight:700;color:var(--r)}

/* ── COND PAGAMENTO ── */
.cond-grid{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:4px}
.cond-opt{flex:1;min-width:80px;border:1.5px solid var(--bdr);border-radius:6px;padding:8px 10px;cursor:pointer;transition:all .13s;text-align:center}
.cond-opt:hover{border-color:#aaa;background:#f9f9f9}
.cond-opt.sel{border-color:var(--r);background:#fff5f5}
.cond-opt input{display:none}
.cond-opt-icon{font-size:1.2rem;display:block;margin-bottom:3px}
.cond-opt-nome{font-size:.65rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--txt)}
.cond-obrig{font-size:.65rem;color:var(--r);margin-top:3px;min-height:14px}

/* Visualizar */
.vis-img{width:100%;border-radius:4px;margin-bottom:12px;border:1px solid var(--bdr)}
.vis-marca{font-size:.62rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;margin-bottom:4px}
.vis-marca.FIAT{color:var(--fiat)}.vis-marca.JEEP{color:var(--jeep)}.vis-marca.RAM{color:var(--ram)}.vis-marca.CAR-CARE{color:var(--carcare)}
.vis-nome{font-size:1.3rem;font-weight:700;text-transform:uppercase;letter-spacing:.01em;margin-bottom:5px;color:var(--txt)}
.vis-mod{font-size:.8rem;color:var(--mut);margin-bottom:14px;line-height:1.5}
.vis-val{font-size:1.75rem;font-weight:700;color:var(--r)}
.del-icon{width:56px;height:56px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.6rem}
.del-aviso{font-size:.73rem;color:#9b2226;background:#fef2f2;border:1px solid #fad7d5;border-radius:6px;padding:8px 12px;margin-top:12px;line-height:1.5}
.empty{display:none;padding:60px 20px;text-align:center;font-size:.88rem;letter-spacing:.1em;text-transform:uppercase;color:var(--mut)}

/* Link gestor */
.btn-gestor{font-size:.72rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;padding:5px 12px;border-radius:4px;border:1.5px solid var(--jeep);background:#fff;color:var(--jeep);cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:all .13s;white-space:nowrap}
.btn-gestor:hover{background:var(--jeep);color:#fff}

@media(max-width:900px){:root{--orc-w:280px}}
@media(max-width:640px){
    .topbar{flex-wrap:wrap;height:auto;padding:9px 14px 0;gap:6px}
    .topbar-row1{flex-wrap:wrap;height:auto;padding-bottom:8px}
    .models-strip{padding:6px 0 10px}
    .hero,.painel-hero{padding:22px 16px 18px}
    .main-wrap{padding:12px 12px 80px}
    .cats{grid-template-columns:1fr 1fr;gap:10px}
    .prod-grid{grid-template-columns:1fr 1fr;gap:10px}
    .pag-nums{display:none}
    .page-main.com-painel{margin-right:0}
    .orc-painel{width:100%;top:auto;bottom:0;height:65vh;border-left:none;border-top:2px solid var(--bdr);transform:translateY(100%)}
    .orc-painel.aberto{transform:translateY(0)}
}
</style>
</head>
<body>

<nav class="topbar">
    <div class="topbar-row1">
        <span class="tb-brand">
            <span class="tb-brand-bar"></span>
            Exemplo <em>|</em> Acessórios
        </span>

        <div class="mt" id="mt-marcas">
            <button class="mt-btn on-todas" data-m="TODAS">TODAS</button>
            <button class="mt-btn" data-m="FIAT">FIAT</button>
            <button class="mt-btn" data-m="JEEP">JEEP</button>
            <button class="mt-btn" data-m="RAM">RAM</button>
            <button class="mt-btn" data-m="CAR CARE">CAR CARE</button>
            <button class="mt-btn mt-promo" id="btn-promo-filtro">PROMOÇÃO</button>
        </div>

        <div class="tb-sep"></div>
        <div class="tb-busca">
            <input type="text" id="busca-inp" placeholder="Buscar acessório…">
            <button type="button" class="tb-busca-clear" id="busca-clear" title="Limpar busca">✕</button>
        </div>

        <div class="tb-sep"></div>
        <span class="tb-count" id="tb-count"><?= count($itens) ?> ITENS</span>

        <?php if ($canEdit): ?>
        <a href="acessorios_gestor.php" class="btn-gestor">📋 Gestor</a>
        <button class="btn-novo" id="btn-novo-tog">+ Novo</button>
        <?php endif; ?>
    </div>

    <div class="models-strip" id="models-strip">
        <span class="models-strip-label">Modelo:</span>
        <div id="models-chips" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
    </div>
</nav>

<!-- PAINEL LATERAL DE ORÇAMENTO -->
<div class="orc-painel" id="orc-painel">
    <div class="orc-painel-header">
        <div class="orc-painel-titulo">
            <div class="orc-painel-titulo-barra"></div>
            Orçamento
            <span class="orc-painel-badge" id="orc-painel-badge">0</span>
        </div>
        <button class="orc-painel-fechar" onclick="fecharOrcPainel()" title="Fechar">✕</button>
    </div>
    <div class="orc-painel-lista" id="orc-painel-lista">
        <div class="orc-painel-vazio">Nenhum item selecionado</div>
    </div>
    <div class="orc-painel-footer">
        <div class="orc-painel-total-sub" id="orc-painel-subtotal"></div>
        <div class="orc-painel-total-row">
            <span class="orc-painel-total-lbl">Total</span>
            <span class="orc-painel-total-val" id="orc-painel-total">R$ 0,00</span>
        </div>
        <div class="orc-painel-btns">
            <button class="orc-painel-btn orc-pb-limp" onclick="limparOrc()">Limpar</button>
            <button class="orc-painel-btn orc-pb-gerar" onclick="abrirModalOrc()">Imprimir</button>
        </div>
        <button class="orc-pb-tags" onclick="doGerarTags()">🏷️ Gerar Tags de Preço</button>
    </div>
</div>

<button class="orc-fab hidden" id="orc-fab" onclick="abrirOrcPainel()">
    🛒 Orçamento
    <span class="orc-fab-badge" id="orc-fab-badge">0</span>
</button>

<div class="page-main" id="page-main">

    <section class="hero" id="hero-section">
        <div class="hero-eye">Empresa Exemplo — Catálogo Oficial</div>
        <h1 class="hero-h1">Acessórios <em>Originais</em></h1>
        <p class="hero-sub">Selecione uma categoria para explorar os itens disponíveis. Use os filtros acima para encontrar o acessório certo.</p>
    </section>

    <div class="painel-hero" id="painel-hero">
        <div class="painel-header">
            <div class="painel-titulo">
                <div class="painel-titulo-linha"></div>
                <span id="painel-titulo-txt">—</span>
            </div>
            <button class="btn-fechar" onclick="fecharPainel()">✕ Fechar</button>
        </div>
        <div class="prod-grid" id="prod-grid"></div>
        <div class="painel-footer" id="painel-footer" style="display:none">
            <div class="painel-pag-info" id="prods-info"></div>
            <div class="pag-controles">
                <div class="pag-nums" id="prods-nums"></div>
                <button class="pag-btn" id="prods-prev" onclick="prodsNav(-1)">&#8592;</button>
                <button class="pag-btn" id="prods-next" onclick="prodsNav(+1)">&#8594;</button>
            </div>
        </div>
    </div>

    <?php if ($canEdit): ?>
    <div class="cad-wrap" id="cad-wrap">
        <form method="post" action="" class="cad-form">
            <input type="hidden" name="acao" value="cadastrar_acessorio">
            <div class="cf" style="flex:0 0 88px"><label>Marca *</label>
                <select name="marca" required>
                    <option value="">—</option>
                    <option value="FIAT">FIAT</option>
                    <option value="JEEP">JEEP</option>
                    <option value="RAM">RAM</option>
                    <option value="CAR CARE">CAR CARE</option>
                </select>
            </div>
            <div class="cf" style="flex:1 1 150px"><label>Acessório *</label>
                <input type="text" name="acessorio" required placeholder="Ex.: Alarme">
            </div>
            <div class="cf" style="flex:1 1 180px"><label>Modelo / aplicação *</label>
                <input type="text" name="modelo" required placeholder="Ex.: TORO / CRONOS">
            </div>
            <div class="cf" style="flex:0 0 110px"><label>Valor *</label>
                <input type="text" name="valor" required placeholder="1.250,00">
            </div>
            <div class="cf" style="flex:0 0 110px"><label>Cód. Item</label>
                <input type="text" name="codigo_item" placeholder="Opcional">
            </div>
            <button type="submit" class="btn-save">Salvar</button>
            <button type="button" class="btn-cl" onclick="document.getElementById('cad-wrap').style.display='none'">Cancelar</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="main-wrap">
        <div class="pag-bar">
            <div class="pag-info" id="cats-info"></div>
            <div class="pag-controles">
                <div class="pag-per-page">
                    <span>Mostrar:</span>
                    <select id="cats-per-page" onchange="setCatsPerPage(+this.value)">
                        <option value="12">12</option>
                        <option value="24" selected>24</option>
                        <option value="48">48</option>
                        <option value="9999">Todos</option>
                    </select>
                </div>
                <div class="pag-divider"></div>
                <div class="pag-nums" id="cats-nums"></div>
                <button class="pag-btn" id="cats-prev" onclick="catsNav(-1)">&#8592;</button>
                <button class="pag-btn" id="cats-next" onclick="catsNav(+1)">&#8594;</button>
            </div>
        </div>
        <div class="cats" id="cats-grid"></div>
        <div class="empty" id="empty">Nenhuma categoria encontrada</div>
    </div>

</div>

<?php if ($canEdit): ?>
<div class="mbk" id="m-ed">
    <div class="mbox">
        <div class="m-ttl">Editar <span>Acessório</span></div>
        <form method="post" action="" enctype="multipart/form-data">
            <input type="hidden" name="acao" value="editar_linha">
            <input type="hidden" name="id" id="ed-id">
            <div class="mrow">
                <div class="mf" style="flex:0 0 88px"><label>Marca</label>
                    <select name="marca" id="ed-marca">
                        <option value="FIAT">FIAT</option><option value="JEEP">JEEP</option><option value="RAM">RAM</option><option value="CAR CARE">CAR CARE</option>
                    </select>
                </div>
                <div class="mf" style="flex:1 1 175px"><label>Acessório</label>
                    <input type="text" name="acessorio" id="ed-acc" required>
                </div>
            </div>
            <div class="mrow">
                <div class="mf" style="flex:1 1 210px"><label>Modelo / aplicação</label>
                    <input type="text" name="modelo" id="ed-mod" required>
                </div>
                <div class="mf" style="flex:0 0 120px"><label>Valor</label>
                    <input type="text" name="valor" id="ed-val" required>
                </div>
            </div>
            <div class="mrow">
                <div class="mf" style="flex:1 1 180px"><label>Cód. Item (interno)</label>
                    <input type="text" name="codigo_item" id="ed-cod" placeholder="Não aparece na impressão">
                </div>
            </div>
            <div class="mf" style="margin-bottom:3px"><label>Foto</label>
                <div id="ed-foto-prev" style="margin-bottom:6px"></div>
                <input type="file" name="foto" accept="image/*">
            </div>
            <div class="mfoot">
                <button type="button" class="btn-mc" onclick="fm('m-ed')">Cancelar</button>
                <button type="submit" class="btn-ms">Salvar</button>
            </div>
        </form>
    </div>
</div>
<div class="mbk" id="m-del">
    <div class="mbox" style="max-width:400px;text-align:center">
        <div class="del-icon">🗑️</div>
        <div class="m-ttl" style="text-align:center;margin-bottom:10px">Excluir <span>Acessório</span></div>
        <p id="del-msg" style="font-size:.88rem;color:#555;line-height:1.65;"></p>
        <div class="del-aviso">⚠ Esta ação não pode ser desfeita. A foto do item também será removida.</div>
        <form method="post" action="">
            <input type="hidden" name="acao" value="excluir_linha">
            <input type="hidden" name="id" id="del-id">
            <div class="mfoot" style="justify-content:center;gap:12px;margin-top:20px">
                <button type="button" class="btn-mc" style="min-width:110px" onclick="fm('m-del')">Cancelar</button>
                <button type="submit" class="btn-del" style="min-width:110px">Sim, excluir</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="mbk" id="m-vis">
    <div class="mbox" style="max-width:360px">
        <div id="vis-mt" class="vis-marca"></div>
        <div id="vis-f"></div>
        <div class="vis-nome" id="vis-n"></div>
        <div class="vis-mod"  id="vis-m"></div>
        <div class="vis-val"  id="vis-v"></div>
        <div class="mfoot"><button class="btn-mc" onclick="fm('m-vis')">Fechar</button></div>
    </div>
</div>

<!-- MODAL BÔNUS DE VENDA -->
<div class="mbk" id="m-bonus">
    <div class="mbox" style="max-width:360px;text-align:center">
        <div class="m-ttl">🎁 Bônus de Venda</div>
        <div class="bonus-nome" id="bonus-nome"></div>
        <div class="bonus-txt">Você ganha</div>
        <div class="bonus-valor" id="bonus-valor"></div>
        <div class="bonus-txt">de bônus vendendo este item em promoção</div>
        <div class="mfoot" style="justify-content:center"><button class="btn-mc" onclick="fm('m-bonus')">Fechar</button></div>
    </div>
</div>

<!-- MODAL ORÇAMENTO COMPLETO -->
<div class="mbk" id="m-orc">
    <div class="mbox" style="max-width:580px">
        <div class="m-ttl">Gerar <span>Orçamento</span></div>

        <div class="orc-section-title">Dados do Cliente (opcional)</div>
        <div class="mrow">
            <div class="mf" style="flex:1 1 180px"><label>Nome do cliente</label>
                <input type="text" id="orc-nome" placeholder="Nome completo">
            </div>
            <div class="mf" style="flex:0 0 148px"><label>Telefone</label>
                <input type="text" id="orc-tel" placeholder="(00) 0000-0000">
            </div>
        </div>
        <div class="mrow">
            <div class="mf" style="flex:0 0 170px"><label>Chassi (opcional)</label>
                <input type="text" id="orc-chassi" placeholder="Ex.: 9BD..." maxlength="17" style="text-transform:uppercase">
            </div>
            <div class="mf" style="flex:0 0 120px"><label>Placa (opcional)</label>
                <input type="text" id="orc-placa" placeholder="ABC1D23" maxlength="8" style="text-transform:uppercase">
            </div>
        </div>

        <div class="orc-section-title">Itens do Orçamento</div>
        <div class="orc-list" id="orc-list"></div>

        <div class="orc-section-title">Desconto</div>
        <div class="mrow" style="margin-bottom:12px">
            <div class="mf" style="flex:0 0 120px">
                <label>Desconto (%)</label>
                <input type="number" id="modal-desc-pct" min="0" max="15" step="0.5" value="0"
                       style="text-align:center" oninput="aplicarDescontoModal(this.value)">
            </div>
            <div class="mf" style="flex:1">
                <label>Economia</label>
                <input type="text" id="modal-desc-economy" readonly
                       style="background:#f0f0f0;color:var(--green);font-weight:700">
            </div>
        </div>

        <div class="orc-totais">
            <div class="orc-total-linha"><span>Subtotal</span><strong id="m-subtotal">R$ 0,00</strong></div>
            <div class="orc-total-linha"><span>Desconto (<span id="m-desc-pct-txt">0</span>%)</span><strong id="m-desc-val" style="color:var(--green)">— R$ 0,00</strong></div>
            <div class="orc-grand"><span class="orc-grand-lbl">TOTAL</span><span id="m-total-final">R$ 0,00</span></div>
        </div>

        <div class="orc-section-title">Condição de Pagamento <span style="color:var(--r)">*</span></div>
        <div class="cond-grid" id="cond-grid">
            <label class="cond-opt" onclick="selCond(this,'avista')">
                <input type="radio" name="cond_pag" value="avista">
                <span class="cond-opt-icon">💵</span>
                <span class="cond-opt-nome">À Vista</span>
            </label>
            <label class="cond-opt" onclick="selCond(this,'debito')">
                <input type="radio" name="cond_pag" value="debito">
                <span class="cond-opt-icon">💳</span>
                <span class="cond-opt-nome">Débito</span>
            </label>
            <label class="cond-opt" onclick="selCond(this,'credito')">
                <input type="radio" name="cond_pag" value="credito">
                <span class="cond-opt-icon">💳</span>
                <span class="cond-opt-nome">Crédito</span>
            </label>
            <label class="cond-opt" onclick="selCond(this,'pix')">
                <input type="radio" name="cond_pag" value="pix">
                <span class="cond-opt-icon">⚡</span>
                <span class="cond-opt-nome">PIX</span>
            </label>
        </div>
        <div class="cond-obrig" id="cond-obrig"></div>

        <div id="sim-parc-wrap" style="display:none;margin-top:10px">
            <div class="sim-wrap">
                <div class="sim-titulo">Simulador de Parcelamento — Cartão de Crédito</div>
                <div id="sim-parc-lista"></div>
            </div>
        </div>

        <div class="mfoot">
            <button class="btn-mc" onclick="fm('m-orc')">Cancelar</button>
            <button class="btn-ms" onclick="doImprimir()">Imprimir</button>
        </div>
    </div>
</div>

<script>
const ITENS         = <?php echo json_encode(array_values($itens), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG); ?>;
const MODELOSALL    = <?php echo json_encode($modelos, JSON_UNESCAPED_UNICODE); ?>;
const MODELOS_MARCA = <?php echo json_encode($modelosPorMarca, JSON_UNESCAPED_UNICODE); ?>;
const CANEDIT       = <?php echo $canEdit ? 'true' : 'false'; ?>;

const norm = s => s.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, '');
const fmtR = n => n.toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
const esc  = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');

/* "de R$X por R$Y" quando em promocao; senao so o preco normal */
function fmtPrecoHtml(it) {
    const atual = fmtR(parseFloat(it.valor || 0));
    if (it.em_promocao && it.valor_original) {
        const original = fmtR(parseFloat(it.valor_original));
        return `<span class="preco-de">de ${original}</span> <span class="preco-por">por ${atual}</span>`;
    }
    return atual;
}

function mc(m){return m.replace(/\s+/g,'-');}
let marcaAtiva = 'TODAS';
let modsFiltro = new Set();
let termoBusca = '';
let catAberta;
let orc = {};
let orcPainelAberto = false;
let parcelaSelecionada = null;
let catsPage = 1, catsPerPage = 24, catsFiltradas = [];
const PRODSPER = 12;
let prodsPage = 1, prodsFiltrados = [];
let descontoPct = 0;
let condSelecionada;

/* ── BOTÕES DE MARCA ── */
document.querySelectorAll('#mt-marcas .mt-btn:not(.mt-promo)').forEach(b => {
    b.addEventListener('click', () => {
        const m = b.dataset.m;
        marcaAtiva = m;
        modsFiltro.clear();
        atualizarBtnMarcas();
        renderModelosPorMarca();
        catsPage = 1;
        filtrar();
    });
});

/* ── FILTRO PROMOÇÃO ── */
let promoAtiva = false;
document.getElementById('btn-promo-filtro').addEventListener('click', function() {
    promoAtiva = !promoAtiva;
    this.classList.toggle('ativo', promoAtiva);
    catsPage = 1;
    filtrar();
});

/* ── BUSCA POR TEXTO ── */
let _buscaTimer;
document.getElementById('busca-inp').addEventListener('input', function() {
    clearTimeout(_buscaTimer);
    const valor = this.value;
    _buscaTimer = setTimeout(() => {
        termoBusca = norm(valor.trim());
        catsPage = 1;
        filtrar();
    }, 350);
});
document.getElementById('busca-clear').addEventListener('click', () => {
    document.getElementById('busca-inp').value = '';
    clearTimeout(_buscaTimer);
    termoBusca = '';
    catsPage = 1;
    filtrar();
});

function atualizarBtnMarcas() {
    document.querySelectorAll('#mt-marcas .mt-btn:not(.mt-promo)').forEach(b => {
        b.className = 'mt-btn';
        const m = b.dataset.m;
        if (m === 'TODAS' && marcaAtiva === 'TODAS') b.classList.add('on-todas');
        else if (m === marcaAtiva) b.classList.add('sel-' + mc(m));
    });
}

/* ── FAIXA DE MODELOS ── */
function renderModelosPorMarca() {
    const strip  = document.getElementById('models-strip');
    const chips  = document.getElementById('models-chips');

    if (marcaAtiva === 'TODAS') {
        strip.classList.remove('visible');
        chips.innerHTML = '';
        return;
    }

    const lista = MODELOS_MARCA[marcaAtiva] || [];
    if (!lista.length) {
        strip.classList.remove('visible');
        chips.innerHTML = '';
        return;
    }

    strip.classList.add('visible');
    chips.innerHTML = lista.map(m => {
        const sel = modsFiltro.has(m);
        return `<span class="model-chip${sel ? ' sel-' + mc(marcaAtiva) : ''}"
                      onclick="toggleModelo('${esc(m)}')">${esc(m)}</span>`;
    }).join('');
}

function toggleModelo(m) {
    if (modsFiltro.has(m)) modsFiltro.delete(m);
    else modsFiltro.add(m);
    renderModelosPorMarca();
    catsPage = 1;
    filtrar();
}

/* ── FILTRAGEM ── */
function passaItem(it) {
    if (marcaAtiva !== 'TODAS' && it.marca !== marcaAtiva) return false;
    if (modsFiltro.size > 0) {
        const segs = it.modelo.split(/[\/;,]/);
        const match = segs.some(s => {
            const c = s.split(' - ')[0].trim();
            return [...modsFiltro].some(m => norm(m) === norm(c));
        });
        if (!match) return false;
    }
    if (termoBusca && !norm(it.acessorio).includes(termoBusca) && !norm(it.modelo).includes(termoBusca)) {
        return false;
    }
    if (promoAtiva && !it.em_promocao) return false;
    return true;
}

function filtrar() {
    const porCat = {};
    let totalItens = 0;
    ITENS.forEach(it => {
        if (!passaItem(it)) return;
        if (!porCat[it.acessorio]) porCat[it.acessorio] = [];
        porCat[it.acessorio].push(it);
        totalItens++;
    });
    catsFiltradas = Object.entries(porCat)
        .sort((a, b) => a[0].localeCompare(b[0]))
        .map(([nome, rows]) => {
            const marcas = [...new Set(rows.map(r => r.marca))].sort();
            const capa   = rows.find(r => r.foto_path)?.foto_path;
            return { nome, rows, marcas, capa };
        });
    document.getElementById('tb-count').textContent = totalItens + ' ITENS';
    document.getElementById('empty').style.display  = catsFiltradas.length === 0 ? 'block' : 'none';
    renderCats();
    if (catAberta) {
        const cat = catsFiltradas.find(c => c.nome === catAberta);
        if (cat) { prodsPage = 1; prodsFiltrados = cat.rows; renderProds(); }
        else fecharPainel();
    }
}

function setCatsPerPage(v) { catsPerPage = parseInt(v, 10); catsPage = 1; renderCats(); }

function catsNav(d) {
    const tot = Math.ceil(catsFiltradas.length / catsPerPage) || 1;
    catsPage  = Math.max(1, Math.min(tot, catsPage + d));
    renderCats();
    window.scrollTo({ top: document.getElementById('cats-grid').offsetTop - 80, behavior: 'smooth' });
}

function renderCats() {
    const grid  = document.getElementById('cats-grid');
    const total = catsFiltradas.length;
    const pages = Math.max(1, Math.ceil(total / catsPerPage));
    if (catsPage > pages) catsPage = pages;
    const start = (catsPage - 1) * catsPerPage;
    const slice = catsFiltradas.slice(start, start + catsPerPage);
    grid.innerHTML = '';
    slice.forEach(cat => {
        const mStr = cat.marcas.length > 1 ? 'MIX' : cat.marcas[0] || 'MIX';
        const pills = cat.marcas.map(m => `<span class="cat-marca-pill cm-${esc(mc(m))}">${esc(m)}</span>`).join('');
        const qty   = cat.rows.length;

        // ── LÓGICA PARA ITEM ÚNICO ──
        const singleItem   = qty === 1 ? cat.rows[0] : null;
        const noOrcSingle  = singleItem && !!orc[singleItem.id];
        const valFmt       = singleItem ? fmtPrecoHtml(singleItem) : null;

        const tile  = document.createElement('div');
        tile.className  = 'cat-tile'
            + (cat.nome === catAberta ? ' aberta' : '')
            + (noOrcSingle ? ' single-orc' : '');
        tile.dataset.cat = cat.nome;
        tile.onclick    = () => toggleCat(cat.nome);

        // Badge flutuante "no orçamento" (só para item único já adicionado)
        const badgeHtml = noOrcSingle
            ? `<div class="cat-orc-badge">No Orçamento</div>`
            : '';

        // Rodapé: item único mostra valor + botão de bônus (se em promoção) + ícone toggle; múltiplos mostram qty + seta
        const temBonusSingle = singleItem && singleItem.em_promocao && parseFloat(singleItem.valor_vendedor || 0) > 0;
        const bonusBtnSingle = temBonusSingle
            ? `<span class="cat-bonus-btn" title="Ver bônus" onclick="event.stopPropagation();abrirBonus({acessorio:'${esc(singleItem.acessorio).replace(/&#39;/g,"\\&#39;")}',valor_vendedor:${parseFloat(singleItem.valor_vendedor || 0)}})">🎁</span>`
            : '';
        const rodapeDir = singleItem
            ? `<span class="cat-rodape-dir">${bonusBtnSingle}<span class="cat-arrow-wrap orc-toggle-btn" title="${noOrcSingle ? 'Remover do orçamento' : 'Adicionar ao orçamento'}">${noOrcSingle ? '✓' : '+'}</span></span>`
            : `<span class="cat-arrow-wrap">›</span>`;

        const rodapeEsq = singleItem
            ? `<span class="cat-val-single">${valFmt}</span>`
            : `<span class="cat-qty">${qty} ${qty === 1 ? 'item' : 'itens'}</span>`;

        tile.innerHTML  = `
          <div class="cat-topo-stripe stripe-${esc(mStr)}"></div>
          <div class="cat-active-bar"></div>
          ${badgeHtml}
          <div class="cat-thumb">
            ${cat.capa
              ? `<div class="cat-bg" style="background-image:url('${cat.capa.replace(/'/g, "%27")}')"></div>`
              : `<div class="cat-bg-ph"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg></div>`}
          </div>
          <div class="cat-body">
            <div class="cat-barra"></div>
            <div class="cat-nome">${esc(cat.nome)}</div>
            <div class="cat-marcas">${pills}</div>
            <div class="cat-rodape">
              ${rodapeEsq}
              ${rodapeDir}
            </div>
          </div>
        `;
        grid.appendChild(tile);
    });
    const end = Math.min(start + catsPerPage, total);
    document.getElementById('cats-info').innerHTML =
        `Exibindo <strong>${start + 1}</strong>–<strong>${end}</strong> de <strong>${total}</strong> categorias`;
    document.getElementById('cats-prev').disabled = (catsPage === 1);
    document.getElementById('cats-next').disabled = (catsPage === pages);
    buildNums('cats-nums', pages, catsPage, p => {
        catsPage = p;
        renderCats();
        window.scrollTo({ top: document.getElementById('cats-grid').offsetTop - 80, behavior: 'smooth' });
    });
}

function toggleCat(nome) {
    const cat = catsFiltradas.find(c => c.nome === nome);
    if (!cat) return;

    // Item único: toggle direto no orçamento (sem abrir painel de produtos)
    if (cat.rows.length === 1) {
    const it = cat.rows[0];
    togOrc(it.id, it.marca, it.acessorio, it.modelo, parseFloat(it.valor || 0), it.codigo_item || '');
    return;
}

    // Múltiplos: abre/fecha painel de produtos
    if (catAberta === nome) { fecharPainel(); return; }
    catAberta = nome;
    document.querySelectorAll('.cat-tile').forEach(t => t.classList.toggle('aberta', t.dataset.cat === nome));
    prodsFiltrados = cat.rows;
    prodsPage      = 1;
    document.getElementById('painel-titulo-txt').textContent = nome;
    renderProds();
    document.getElementById('hero-section').style.display = 'none';
    document.getElementById('painel-hero').classList.add('open');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function fecharPainel() {
    catAberta = null;
    document.getElementById('hero-section').style.display = '';
    document.getElementById('painel-hero').classList.remove('open');
    document.querySelectorAll('.cat-tile').forEach(t => t.classList.remove('aberta'));
}

function prodsNav(d) {
    const tot = Math.ceil(prodsFiltrados.length / PRODSPER) || 1;
    prodsPage = Math.max(1, Math.min(tot, prodsPage + d));
    renderProds();
    document.getElementById('painel-hero').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function renderProds() {
    const grid   = document.getElementById('prod-grid');
    const footer = document.getElementById('painel-footer');
    const total  = prodsFiltrados.length;
    const pages  = Math.max(1, Math.ceil(total / PRODSPER));
    if (prodsPage > pages) prodsPage = pages;
    const start  = (prodsPage - 1) * PRODSPER;
    const slice  = prodsFiltrados.slice(start, start + PRODSPER);
    grid.innerHTML = '';
    slice.forEach(it => {
        const vfmt = fmtPrecoHtml(it);
        const noOrc = !!orc[it.id];
        const temBonusProd = it.em_promocao && parseFloat(it.valor_vendedor || 0) > 0;
        const bonusBtnProd = temBonusProd
            ? `<span class="prod-bonus-btn" title="Ver bônus" onclick="event.stopPropagation();abrirBonus({acessorio:'${esc(it.acessorio).replace(/&#39;/g,"\\&#39;")}',valor_vendedor:${parseFloat(it.valor_vendedor || 0)}})">🎁</span>`
            : '';
        const fotoH = it.foto_path
            ? `<img class="prod-foto-img" src="${it.foto_path}" alt="" loading="lazy">`
            : `<div class="prod-foto-ph"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg></div>`;
        const d = document.createElement('div');
        d.className  = 'prod-card' + (noOrc ? ' no-orc' : '');
        d.dataset.id = it.id;
        d.onclick = () => togOrc(it.id, it.marca, it.acessorio, it.modelo, parseFloat(it.valor || 0), it.codigo_item || '');
        d.innerHTML  = `
          <div class="prod-stripe stripe-${esc(mc(it.marca))}"></div>
          <div class="prod-foto">
            ${fotoH}
            <div class="prod-hover">
              <div class="prod-hover-acts">
                <button class="btn-ph btn-dup-ph"
                        onclick="event.stopPropagation();visualizarItem({id:${it.id},marca:'${esc(it.marca)}',acessorio:'${esc(it.acessorio)}',modelo:'${esc(it.modelo)}',valor:${parseFloat(it.valor || 0)},foto_path:'${esc(it.foto_path || '')}'})">
                  Visualizar
                </button>
              </div>
            </div>
          </div>
          <div class="prod-info">
            <div class="prod-marca-tag ${esc(mc(it.marca))}">${esc(it.marca)}</div>
            <div class="prod-nome">${esc(it.acessorio)}</div>
            <div class="prod-mod">${esc(it.modelo)}</div>
            <div class="prod-bottom">
              <span class="prod-val">${vfmt}</span>
              <span class="prod-bottom-dir">
                ${bonusBtnProd}
                <input type="checkbox" class="chk-orc"
       title="${noOrc ? 'Remover do orçamento' : 'Adicionar ao orçamento'}"
       ${noOrc ? 'checked' : ''}
       onchange="togOrcChk(this,${it.id},'${esc(it.marca)}','${esc(it.acessorio)}','${esc(it.modelo)}',${parseFloat(it.valor || 0)},'${esc(it.codigo_item || '')}')">
              </span>
            </div>
          </div>
        `;
        grid.appendChild(d);
    });
    if (total > PRODSPER) {
        footer.style.display = '';
        const end = Math.min(start + PRODSPER, total);
        document.getElementById('prods-info').innerHTML =
            `Exibindo <strong>${start + 1}</strong>–<strong>${end}</strong> de <strong>${total}</strong> itens`;
        document.getElementById('prods-prev').disabled = (prodsPage === 1);
        document.getElementById('prods-next').disabled = (prodsPage === pages);
        buildNums('prods-nums', pages, prodsPage, p => {
            prodsPage = p;
            renderProds();
            document.getElementById('painel-hero').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    } else {
        footer.style.display = 'none';
    }
}

function buildNums(id, total, current, cb) {
    const el = document.getElementById(id);
    if (!el) return;
    el.innerHTML = '';
    if (total === 1) return;
    const show = new Set([1, total]);
    for (let i = current - 2; i <= current + 2; i++) { if (i > 1 && i < total) show.add(i); }
    let prev = null;
    [...show].sort((a, b) => a - b).forEach(p => {
        if (prev !== null && p - prev > 1) {
            const sp = document.createElement('span');
            sp.className  = 'pag-ellipsis';
            sp.textContent = '…';
            el.appendChild(sp);
        }
        const btn = document.createElement('button');
        btn.className  = 'pag-btn' + (p === current ? ' active' : '');
        btn.textContent = p;
        btn.onclick    = () => cb(p);
        el.appendChild(btn);
        prev = p;
    });
}

/* ── ORÇAMENTO ── */
function togOrc(id, marca, acc, mod, val, cod) {
    if (orc[id]) {
        delete orc[id];
    } else {
        orc[id] = { id, marca, acc, mod, val, cod: cod || '' };
        abrirOrcPainel();
    }
    syncOrcUI(id);
    recalc();
    renderCats();
}

function togOrcChk(chk, id, marca, acc, mod, val, cod) {
    if (chk.checked) {
        orc[id] = { id, marca, acc, mod, val, cod: cod || '' };
        abrirOrcPainel();
    } else {
        delete orc[id];
    }
    syncOrcUI(id);
    recalc();
    renderCats();
}

function updateValorItem(id, val) {
    const num = parseFloat(val.replace(/[^\d,.-]/g, '').replace(',', '.'));
    if (!isNaN(num)) { orc[id].val = num; recalc(); }
}

function syncOrcUI(id) {
    const sel  = !!orc[id];
    const c    = document.querySelector('.prod-card[data-id="' + id + '"] .chk-orc');
    if (c) { c.checked = sel; c.title = sel ? 'Remover do orçamento' : 'Adicionar ao orçamento'; }
    const card = document.querySelector('.prod-card[data-id="' + id + '"]');
    if (card) card.classList.toggle('no-orc', sel);
}

function recalc() {
    const arr      = Object.values(orc);
    const subtotal = arr.reduce((s, i) => s + (i.val || 0), 0);
    const pct      = descontoPct || 0;
    const descVal  = subtotal * pct / 100;
    const total    = subtotal - descVal;
    const q        = arr.length;
    document.getElementById('orc-painel-badge').textContent = q;
    document.getElementById('orc-painel-total').textContent = fmtR(total);
    document.getElementById('orc-fab-badge').textContent    = q;
    const subEl = document.getElementById('orc-painel-subtotal');
    subEl.textContent = pct > 0 ? 'Subtotal: ' + fmtR(subtotal) : '';
    renderOrcPainelLista();
    if (q === 0) { fecharOrcPainel(); return; }
    document.getElementById('orc-fab').classList.toggle('hidden', orcPainelAberto);
}

function renderOrcPainelLista() {
    const arr   = Object.values(orc);
    const lista = document.getElementById('orc-painel-lista');
    if (!arr.length) { lista.innerHTML = '<div class="orc-painel-vazio">Nenhum item selecionado</div>'; return; }
    lista.innerHTML = arr.map(it => `
      <div class="orc-painel-item" id="opi-${it.id}">
        <div class="orc-pi-cor cor-${esc(mc(it.marca))}"></div>
        <div class="orc-pi-info">
          <div class="orc-pi-marca ${esc(mc(it.marca))}">${esc(it.marca)}</div>
          <div class="orc-pi-nome" title="${esc(it.acc)}">${esc(it.acc)}</div>
          <div class="orc-pi-mod" title="${esc(it.mod)}">${esc(it.mod)}${it.cod ? `<span class="orc-pi-cod">${esc(it.cod)}</span>` : ''}</div>
        </div>
        <div>
          <input class="orc-pi-val" type="text" value="${fmtR(it.val || 0)}"
                 onfocus="this.value=''" onchange="updateValorItem(${it.id}, this.value)">
          <button class="orc-pi-rm" onclick="removerDoOrc(${it.id})" title="Remover">×</button>
        </div>
      </div>
    `).join('');
}

function removerDoOrc(id) {
    delete orc[id];
    syncOrcUI(id);
    recalc();
    renderCats(); // atualiza badge/estilo dos tiles de item único
    if (!Object.keys(orc).length) fecharOrcPainel();
}

function limparOrc() {
    Object.keys(orc).forEach(id => syncOrcUI(id));
    orc = {}; descontoPct = 0;
    recalc();
    renderCats(); // limpa todos os badges
    fecharOrcPainel();
}

function aplicarDescontoModal(v) {
    let pct = parseFloat(v) || 0;
    if (pct < 0) pct = 0;
    if (pct > 15) pct = 15;
    document.getElementById('modal-desc-pct').value = pct;
    descontoPct = pct;
    const arr      = Object.values(orc);
    const subtotal = arr.reduce((s, i) => s + (i.val || 0), 0);
    const descVal  = subtotal * pct / 100;
    const total    = subtotal - descVal;
    document.getElementById('modal-desc-economy').value  = pct > 0 ? '— ' + fmtR(descVal) : '';
    document.getElementById('m-desc-pct-txt').textContent = pct.toFixed(pct % 1 ? 1 : 0);
    document.getElementById('m-desc-val').textContent     = fmtR(descVal || 0);
    document.getElementById('m-total-final').textContent  = fmtR(total || 0);
    if (condSelecionada === 'credito') {
        parcelaSelecionada = null;
        renderSimulador(total);
    }
    recalc();
}

function abrirOrcPainel() {
    orcPainelAberto = true;
    document.getElementById('orc-painel').classList.add('aberto');
    document.getElementById('page-main').classList.add('com-painel');
    document.getElementById('orc-fab').classList.add('hidden');
}

function fecharOrcPainel() {
    orcPainelAberto = false;
    document.getElementById('orc-painel').classList.remove('aberto');
    document.getElementById('page-main').classList.remove('com-painel');
    const q = Object.keys(orc).length;
    document.getElementById('orc-fab').classList.toggle('hidden', q === 0);
}

function abrirModalOrc() {
    const arr      = Object.values(orc);
    if (!arr.length) return;
    const subtotal = arr.reduce((s, i) => s + (i.val || 0), 0);
    const pct      = descontoPct || 0;
    const descVal  = subtotal * pct / 100;
    const total    = subtotal - descVal;
    document.getElementById('orc-list').innerHTML = arr.map(it => `
      <div class="oi">
        <div class="oi-n"><strong>${esc(it.marca)} ${esc(it.acc)}</strong><small>${esc(it.mod)}</small></div>
        <span class="oi-v">${fmtR(it.val || 0)}</span>
        <button class="oi-rm" onclick="removerDoOrc(${it.id});abrirModalOrc()">×</button>
      </div>
    `).join('');
    document.getElementById('modal-desc-pct').value       = pct;
    document.getElementById('modal-desc-economy').value   = pct > 0 ? '— ' + fmtR(descVal) : '';
    document.getElementById('m-subtotal').textContent      = fmtR(subtotal || 0);
    document.getElementById('m-desc-pct-txt').textContent  = pct.toFixed(pct % 1 ? 1 : 0);
    document.getElementById('m-desc-val').textContent      = fmtR(descVal || 0);
    document.getElementById('m-total-final').textContent   = fmtR(total || 0);
    renderSimulador(total);
    condSelecionada = null; parcelaSelecionada = null;
    document.querySelectorAll('.cond-opt').forEach(o => o.classList.remove('sel'));
    document.getElementById('sim-parc-wrap').style.display = 'none';
    document.getElementById('cond-obrig').textContent = '';
    am('m-orc');
}

function renderSimulador(total) {
    const MINPARC = 300, MAXPARC = 12, taxaAdm = 0.025;
    const fatores = { 1:1.018,2:0.518,3:0.351,4:0.268,5:0.218,6:0.185,7:0.161,8:0.143,9:0.129,10:0.118,11:0.109,12:0.101 };
    const lista   = document.getElementById('sim-parc-lista');
    if (!total || total <= 0) { lista.innerHTML = ''; return; }
    const debito  = total * (1 + taxaAdm);
    let html = '';
    for (let n = 1; n <= MAXPARC; n++) {
        if (n <= 5) {
            const parcVal = total / n;
            if (parcVal < MINPARC) continue;
            const selected = parcelaSelecionada && parcelaSelecionada.n === n && parcelaSelecionada.tipo === 'sem_juros';
            html += `<div class="sim-condicao${selected ? ' sel' : ''}" data-parc="${n}" onclick="selParcela(${n},${parcVal.toFixed(2)},${total.toFixed(2)},'sem_juros')">
              <div class="sim-row">
                <div><div class="sim-parc">${n}x de <em>${fmtR(parcVal)}</em></div>
                <div class="sim-obs">Sem juros • Total ${fmtR(total)}</div></div>
              </div></div>`;
        } else {
            const fator   = fatores[n]; if (!fator) continue;
            const parcVal = debito * fator;
            const totalP  = parcVal * n;
            const selected = parcelaSelecionada && parcelaSelecionada.n === n && parcelaSelecionada.tipo === 'com_juros';
            html += `<div class="sim-condicao${selected ? ' sel' : ''}" data-parc="${n}" onclick="selParcela(${n},${parcVal.toFixed(2)},${totalP.toFixed(2)},'com_juros')">
              <div class="sim-row">
                <div><div class="sim-parc">${n}x de <em>${fmtR(parcVal)}</em></div>
                <div class="sim-obs">Com juros • Total ${fmtR(totalP)}</div></div>
              </div></div>`;
        }
    }
    lista.innerHTML = html || `<div style="font-size:.78rem;color:var(--mut);padding:8px 0">Valor insuficiente para parcelamento mín. R$ 300 por parcela.</div>`;
}

function selParcela(n, parcela, total, tipo) {
    parcelaSelecionada = { n, parcela: parseFloat(parcela), total: parseFloat(total), tipo };
    document.querySelectorAll('#sim-parc-lista .sim-condicao').forEach(el => el.classList.remove('sel'));
    const el = document.querySelector('#sim-parc-lista .sim-condicao[data-parc="' + n + '"]');
    if (el) el.classList.add('sel');
}

function selCond(el, val) {
    condSelecionada = val;
    document.querySelectorAll('.cond-opt').forEach(o => o.classList.remove('sel'));
    el.classList.add('sel');
    document.getElementById('cond-obrig').textContent = '';
    document.getElementById('sim-parc-wrap').style.display = val === 'credito' ? 'block' : 'none';
}

function doImprimir() {
    if (!condSelecionada) { document.getElementById('cond-obrig').textContent = 'Selecione a condição de pagamento antes de imprimir.'; return; }
    if (condSelecionada === 'credito' && !parcelaSelecionada) { document.getElementById('cond-obrig').textContent = 'Selecione a condição de parcelamento antes de imprimir.'; return; }
    const nome   = document.getElementById('orc-nome').value.trim();
    const tel    = document.getElementById('orc-tel').value.trim();
    const chassi = document.getElementById('orc-chassi').value.trim();
    const placa  = document.getElementById('orc-placa').value.trim();
    const ids    = Object.keys(orc).join(',');

    const valoresEditados = Object.values(orc)
        .map(it => it.id + ':' + (it.val || 0).toFixed(2))
        .join(',');

    let url = 'acessorios_orcamento_impressao.php?ids=' + ids;
    url += '&valores=' + encodeURIComponent(valoresEditados);
    if (descontoPct) url += '&desc=' + descontoPct;
    url += '&cond=' + encodeURIComponent(condSelecionada);
    if (condSelecionada === 'credito' && parcelaSelecionada) {
        url += '&parcelas=' + parcelaSelecionada.n;
        url += '&parcela_valor=' + encodeURIComponent(parcelaSelecionada.parcela.toFixed(2));
        url += '&parcela_total=' + encodeURIComponent(parcelaSelecionada.total.toFixed(2));
        url += '&parcela_tipo=' + encodeURIComponent(parcelaSelecionada.tipo);
    }
    if (nome)   url += '&nome='   + encodeURIComponent(nome);
    if (tel)    url += '&tel='    + encodeURIComponent(tel);
    if (chassi) url += '&chassi=' + encodeURIComponent(chassi);
    if (placa)  url += '&placa='  + encodeURIComponent(placa);
    fm('m-orc');
    window.open(url, '_blank');
}

function doGerarTags() {
    const ids = Object.keys(orc).join(',');
    if (!ids) return;
    window.open('acessorios_tags_impressao.php?ids=' + ids, '_blank');
}

function am(id) { document.getElementById(id).classList.add('open'); }
function fm(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.mbk').forEach(b => {
    b.addEventListener('click', e => { if (e.target === b) b.classList.remove('open'); });
});

function visualizarItem(it) {
    document.getElementById('vis-mt').className = 'vis-marca ' + mc(it.marca);
    document.getElementById('vis-mt').textContent = it.marca;
    document.getElementById('vis-n').textContent  = it.acessorio;
    document.getElementById('vis-m').textContent  = it.modelo;
    document.getElementById('vis-v').textContent  = fmtR(it.valor || 0);
    document.getElementById('vis-f').innerHTML    = it.foto_path ? `<img src="${it.foto_path}" class="vis-img" alt="">` : '';
    am('m-vis');
}

function abrirBonus(it) {
    document.getElementById('bonus-nome').textContent  = it.acessorio;
    document.getElementById('bonus-valor').textContent = fmtR(parseFloat(it.valor_vendedor || 0));
    am('m-bonus');
}

function abEdit(id, marca, acc, mod, val, foto, cod) {
    document.getElementById('ed-id').value     = id;
    document.getElementById('ed-marca').value  = marca;
    document.getElementById('ed-acc').value    = acc;
    document.getElementById('ed-mod').value    = mod;
    document.getElementById('ed-val').value    = val;
    document.getElementById('ed-cod').value    = cod;
    document.getElementById('ed-foto-prev').innerHTML = foto ? `<img src="${foto}" style="max-width:100%;max-height:110px;border-radius:4px;border:1px solid #ddd">` : '';
    am('m-ed');
}

function abDel(id, acc, marca) {
    document.getElementById('del-id').value  = id;
    document.getElementById('del-msg').textContent = marca + ' • ' + acc;
    am('m-del');
}

function duplicarItem(id) {
    const f = document.createElement('form');
    f.method  = 'post'; f.action = '';
    f.innerHTML = `<input type="hidden" name="acao" value="duplicar_linha"><input type="hidden" name="id" value="${id}">`;
    document.body.appendChild(f);
    f.submit();
}

document.getElementById('btn-novo-tog')?.addEventListener('click', () => {
    const w = document.getElementById('cad-wrap');
    w.style.display = (w.style.display === 'block' ? 'none' : 'block');
    if (w.style.display === 'block') w.scrollIntoView({ behavior: 'smooth', block: 'start' });
});

/* ── INIT ── */
filtrar();
renderModelosPorMarca();
</script>
</body>
</html>