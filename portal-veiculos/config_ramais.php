<?php
// @versao Ver.002.020926
require_once __DIR__ . '/config_versao.php'; 
require_once __DIR__ . '/config_database.php';
session_start();
if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    exit('Acesso negado');
}


/* ===== EXCLUIR RAMAL ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    $id_excluir = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id_excluir > 0) {
        $stmt = $mysqli->prepare("DELETE FROM ramais WHERE id = ?");
        $stmt->bind_param('i', $id_excluir);
        $stmt->execute();
        $stmt->close();
    }
    echo "OK";
    exit;
}

/* ===== INSERIR NOVO RAMAL ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'novo') {
    $id_cidade = (int)($_POST['id_cidade'] ?? 0);
    $setor     = trim($_POST['setor']  ?? '');
    $ramal     = trim($_POST['ramal']  ?? '');

    if ($id_cidade > 0 && $setor !== '' && $ramal !== '') {
        $stmtMax = $mysqli->prepare("SELECT COALESCE(MAX(ordem),0)+1 AS prox FROM ramais WHERE id_cidade = ?");
        $stmtMax->bind_param('i', $id_cidade);
        $stmtMax->execute();
        $prox = ($stmtMax->get_result()->fetch_assoc()['prox'] ?? 1);
        $stmtMax->close();

        $stmt = $mysqli->prepare(
            "INSERT INTO ramais (id_cidade, setor, ramal, ativo, ordem)
             VALUES (?, ?, ?, 1, ?)"
        );
        $stmt->bind_param('issi', $id_cidade, $setor, $ramal, $prox);
        $stmt->execute();
        $stmt->close();
    }
    echo "OK";
    exit;
}

/* ===== EDITAR RAMAL (setor / ramal) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar') {
    $id    = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $setor = trim($_POST['setor'] ?? '');
    $ramal = trim($_POST['ramal'] ?? '');

    if ($id <= 0 || $setor === '' || $ramal === '') {
        echo 'Erro: informe setor e ramal.';
        exit;
    }

    $stmt = $mysqli->prepare("UPDATE ramais SET setor = ?, ramal = ? WHERE id = ?");
    $stmt->bind_param('ssi', $setor, $ramal, $id);
    $stmt->execute();
    $stmt->close();

    echo 'OK';
    exit;
}

/**
 * Nome de unidade precisa ser unico: as telas de exibicao
 * (ramais_uteis_servidor.php e ramais_uteis_impressao.php) agrupam por NOME,
 * nao por id. Duas unidades com o mesmo nome sairiam fundidas num card so.
 */
function nomeUnidadeEmUso(mysqli $mysqli, string $nome, int $ignorarId = 0): bool
{
    $stmt = $mysqli->prepare(
        "SELECT COUNT(*) AS n FROM ramais_cidades WHERE LOWER(nome) = LOWER(?) AND id <> ?"
    );
    $stmt->bind_param('si', $nome, $ignorarId);
    $stmt->execute();
    $n = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();
    return $n > 0;
}

/* ===== NOVA UNIDADE ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cidade_nova') {
    $nome     = trim($_POST['nome'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');

    if ($nome === '') {
        echo 'Erro: informe o nome da unidade.';
        exit;
    }
    if (nomeUnidadeEmUso($mysqli, $nome)) {
        echo 'Erro: ja existe uma unidade com esse nome.';
        exit;
    }

    $stmtMax = $mysqli->query("SELECT COALESCE(MAX(ordem),0)+1 AS prox FROM ramais_cidades");
    $prox    = (int)($stmtMax->fetch_assoc()['prox'] ?? 1);

    $stmt = $mysqli->prepare(
        "INSERT INTO ramais_cidades (nome, telefone, ordem) VALUES (?, ?, ?)"
    );
    $stmt->bind_param('ssi', $nome, $telefone, $prox);
    $stmt->execute();
    $stmt->close();

    echo 'OK';
    exit;
}

/* ===== EDITAR UNIDADE (nome / telefone) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cidade_editar') {
    $id       = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $nome     = trim($_POST['nome'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');

    if ($id <= 0 || $nome === '') {
        echo 'Erro: informe o nome da unidade.';
        exit;
    }
    if (nomeUnidadeEmUso($mysqli, $nome, $id)) {
        echo 'Erro: ja existe outra unidade com esse nome.';
        exit;
    }

    $stmt = $mysqli->prepare(
        "UPDATE ramais_cidades SET nome = ?, telefone = ? WHERE id = ?"
    );
    $stmt->bind_param('ssi', $nome, $telefone, $id);
    $stmt->execute();
    $stmt->close();

    echo 'OK';
    exit;
}

/* ===== EXCLUIR UNIDADE ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cidade_excluir') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        echo 'Erro: unidade invalida.';
        exit;
    }

    // Recusa unidade com ramal para nao apagar varios registros de uma vez
    // sem o usuario ver o que esta perdendo.
    $stmt = $mysqli->prepare("SELECT COUNT(*) AS n FROM ramais WHERE id_cidade = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $qtd = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();

    if ($qtd > 0) {
        echo 'Erro: a unidade tem ' . $qtd . ' ramal(is). Exclua os ramais antes.';
        exit;
    }

    $stmt = $mysqli->prepare("DELETE FROM ramais_cidades WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    echo 'OK';
    exit;
}

/* ===== SALVAR ORDEM / ATIVO / ORDEM_CIDADE ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar') {
    $ordens       = $_POST['ordem']        ?? [];
    $ordensCidade = $_POST['ordem_cidade'] ?? [];
    $ativos       = $_POST['ativo']        ?? [];

    $mysqli->begin_transaction();

    try {
        // Atualiza ordem e ativo dos ramais
        $stmtRamal = $mysqli->prepare("
            UPDATE ramais
               SET ordem = ?, ativo = ?
             WHERE id = ?
        ");

        foreach ($ordens as $idRamal => $ordem) {
            $id    = (int)$idRamal;
            $ord   = (int)$ordem;
            $ativo = isset($ativos[$idRamal]) ? 1 : 0;

            $stmtRamal->bind_param('iii', $ord, $ativo, $id);
            $stmtRamal->execute();
        }
        $stmtRamal->close();

        // Atualiza ordem das cidades
        $stmtCid = $mysqli->prepare("
            UPDATE ramais_cidades
               SET ordem = ?
             WHERE id = ?
        ");

        foreach ($ordensCidade as $idCid => $ordemCid) {
            $id  = (int)$idCid;
            $ord = (int)$ordemCid;

            $stmtCid->bind_param('ii', $ord, $id);
            $stmtCid->execute();
        }
        $stmtCid->close();

        $mysqli->commit();
        echo 'OK';
    } catch (Throwable $e) {
        $mysqli->rollback();
        echo 'Erro MySQL: ' . $e->getMessage();
    }

    exit;
}

/* ===== CARREGAR UNIDADES =====
   Carrega de ramais_cidades primeiro (e nao de um JOIN com ramais) de
   proposito: unidade recem-criada ainda nao tem ramal nenhum, e num JOIN ela
   ficaria invisivel - sem card e, pior, fora do <select> de "Novo ramal",
   entao nao haveria como cadastrar o primeiro ramal dela. */
$ramais_por_cidade = [];
$resCid = $mysqli->query(
    "SELECT id, nome, telefone, ordem
       FROM ramais_cidades
      ORDER BY ordem ASC, nome ASC"
);
if ($resCid) {
    while ($c = $resCid->fetch_assoc()) {
        $ramais_por_cidade[(int)$c['id']] = [
            'id'           => (int)$c['id'],
            'nome'         => $c['nome'],
            'telefone'     => $c['telefone'],
            'ordem_cidade' => (int)$c['ordem'],
            'ramais'       => []
        ];
    }
}

/* ===== CARREGAR RAMAIS E DISTRIBUIR NAS UNIDADES ===== */
$resRam = $mysqli->query(
    "SELECT id, id_cidade, setor, ramal, ativo, ordem
       FROM ramais
      ORDER BY ordem ASC, id ASC"
);
if ($resRam) {
    while ($r = $resRam->fetch_assoc()) {
        $idc = (int)$r['id_cidade'];
        if (isset($ramais_por_cidade[$idc])) {
            $ramais_por_cidade[$idc]['ramais'][] = $r;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Configurar ramais</title>
<style>
.ramais-config-container{
    max-width:1100px;
    margin:0 auto;
    font-family:system-ui,sans-serif;
    font-size:.9rem;
}
.cards-ramais-container{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(320px,1fr));
    gap:16px;
}
.card-cidade{
    background:#fff;
    border-radius:14px;
    box-shadow:0 6px 15px rgba(0,0,0,0.08);
    padding:14px 18px;
    border:1px solid #eee;
}
.card-cidade-titulo{
    font-weight:700;
    color:#c40000;
    margin-bottom:8px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
}
.card-cidade-titulo{
    flex-wrap:wrap;
}
.card-cidade-titulo input[type=number]{
    width:60px;
    font-size:.8rem;
}
.cidade-visor{
    flex:1;
    min-width:0;
}
.cidade-nome-visor{
    display:block;
}
.cidade-tel-visor{
    display:block;
    font-weight:500;
    font-size:.75rem;
    color:#008000;
}
.cidade-tel-visor:empty{
    display:none;
}
.cidade-ordem{
    font-weight:400;
    color:#555;
    font-size:.8rem;
}

/* acoes da unidade, mesmo padrao das acoes da linha */
.cidade-acoes{
    display:flex;
    gap:4px;
    flex-shrink:0;
}
.cidade-acoes button{
    border:none;
    border-radius:4px;
    padding:2px 6px;
    font-size:.75rem;
    cursor:pointer;
}
.btn-editar-cidade{ background:#e4e4e4; color:#333; }
.btn-editar-cidade:hover{ background:#d4d4d4; }
.btn-confirmar-cidade{ background:#d8f0d8; color:#186018; font-weight:600; }
.btn-confirmar-cidade:hover{ background:#c2e6c2; }
.btn-cancelar-cidade{ background:#eee; color:#666; }
.btn-cancelar-cidade:hover{ background:#e0e0e0; }
.btn-excluir-cidade{ background:#ffdddd; color:#a00; }
.btn-excluir-cidade:hover{ background:#ffbbbb; }

/* alternancia visor / edicao da unidade (mesma razao da linha:
   .cidade-edicao precisa de display:flex quando visivel) */
/* ocupa a linha inteira do cabecalho: no card estreito o campo de nome
   colapsava para quase zero ao dividir espaco com botoes e "Ordem" */
.cidade-edicao{
    display:none;
    flex:1 0 100%;
    gap:4px;
    min-width:0;
    margin-top:4px;
}
.cidade-edicao input{
    font-size:.8rem;
    padding:2px 5px;
    border:1px solid #bbb;
    border-radius:4px;
    min-width:0;
}
.cidade-edicao .in-cidade-nome{ flex:1; }
.cidade-edicao .in-cidade-tel{ width:110px; flex-shrink:0; }
.cidade-edicao input:focus{
    outline:none;
    border-color:#c40000;
    box-shadow:0 0 0 2px rgba(196,0,0,.12);
}

.card-cidade-titulo .btn-confirmar-cidade,
.card-cidade-titulo .btn-cancelar-cidade{ display:none; }

.card-cidade-titulo.editando .cidade-visor{ display:none; }
.card-cidade-titulo.editando .cidade-edicao{ display:flex; }
.card-cidade-titulo.editando .btn-editar-cidade,
.card-cidade-titulo.editando .btn-excluir-cidade{ display:none; }
.card-cidade-titulo.editando .btn-confirmar-cidade,
.card-cidade-titulo.editando .btn-cancelar-cidade{ display:inline-block; }
.ramais-config-lista{
    list-style:none;
    margin:0;
    padding:0;
}
.item-ramal{
    display:flex;
    align-items:center;
    gap:6px;
    margin-bottom:4px;
    font-size:.88rem;
}
.drag-handle{
    cursor:grab;
    user-select:none;
}
.ramais-novo{
    margin:12px 0 18px 0;
    padding:8px 10px;
    border-radius:8px;
    background:#f4f4f4;
    border:1px solid #ddd;
}
.ramais-novo input, .ramais-novo select{
    margin-right:6px;
    padding:4px 6px;
    font-size:.85rem;
}
.btn-salvar-ramais{
    margin-top:12px;
    padding:8px 14px;
    border:none;
    border-radius:999px;
    background:#2347c8;
    color:#fff;
    font-weight:600;
    cursor:pointer;
}
.ramais-msg{
    margin-top:6px;
    font-size:.85rem;
}
/* grupo de acoes empurrado para a direita da linha */
.ramal-acoes{
    margin-left:auto;
    display:flex;
    gap:4px;
    flex-shrink:0;
}
.ramal-acoes button{
    border:none;
    border-radius:4px;
    padding:2px 6px;
    font-size:.75rem;
    cursor:pointer;
}
.btn-excluir-ramal{
    background:#ffdddd;
    color:#a00;
}
.btn-excluir-ramal:hover{
    background:#ffbbbb;
}
.btn-editar-ramal{
    background:#e4e4e4;
    color:#333;
}
.btn-editar-ramal:hover{
    background:#d4d4d4;
}
.btn-confirmar-ramal{
    background:#d8f0d8;
    color:#186018;
    font-weight:600;
}
.btn-confirmar-ramal:hover{
    background:#c2e6c2;
}
.btn-cancelar-ramal{
    background:#eee;
    color:#666;
}
.btn-cancelar-ramal:hover{
    background:#e0e0e0;
}

/* ── ALTERNANCIA VISOR / EDICAO ──
   Feito por classe no <li> (e nao pelo atributo hidden) porque .ramal-edicao
   precisa de display:flex quando visivel, e isso sobrescreveria o hidden. */
.ramal-visor{
    flex:1;
    min-width:0;
}
.ramal-edicao{
    display:none;
    flex:1;
    gap:4px;
    min-width:0;
}
.ramal-edicao input{
    font-size:.8rem;
    padding:2px 5px;
    border:1px solid #bbb;
    border-radius:4px;
    min-width:0;
}
.ramal-edicao .in-setor{ flex:1; }
.ramal-edicao .in-ramal{ width:70px; flex-shrink:0; }
.ramal-edicao input:focus{
    outline:none;
    border-color:#c40000;
    box-shadow:0 0 0 2px rgba(196,0,0,.12);
}

.item-ramal .btn-confirmar-ramal,
.item-ramal .btn-cancelar-ramal{ display:none; }

.item-ramal.editando .ramal-visor{ display:none; }
.item-ramal.editando .ramal-edicao{ display:flex; }
.item-ramal.editando .btn-editar-ramal,
.item-ramal.editando .btn-excluir-ramal{ display:none; }
.item-ramal.editando .btn-confirmar-ramal,
.item-ramal.editando .btn-cancelar-ramal{ display:inline-block; }

/* durante a edicao o arraste sai do caminho */
.item-ramal.editando .drag-handle{
    opacity:.25;
    pointer-events:none;
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

<div class="ramais-config-container">
    <!-- novo ramal -->
    <!-- nova unidade -->
    <div class="ramais-novo">
        <strong>Nova unidade</strong><br>
        <input type="text" id="unidade_nome" placeholder="Nome da unidade" maxlength="100">
        <input type="text" id="unidade_tel"  placeholder="Telefone (opcional)" maxlength="20">
        <button type="button" id="btn-unidade-nova">Adicionar unidade</button>
    </div>

    <div class="ramais-novo">
        <strong>Novo ramal</strong><br>
        <select id="cidade_nova">
    <option value="">Cidade</option>
    <?php foreach ($ramais_por_cidade as $cid): ?>
        <option value="<?= (int)$cid['id']; ?>">
            <?= htmlspecialchars($cid['nome'], ENT_QUOTES, 'UTF-8'); ?>
        </option>
    <?php endforeach; ?>
</select>
        <input type="text" id="setor_novo"  placeholder="Setor">
        <input type="text" id="ramal_novo"  placeholder="Ramal">
        <button type="button" id="btn-ramal-novo">Adicionar</button>
    </div>

    <form id="form-ramais" method="post">
        <div class="cards-ramais-container">
            <?php if (!empty($ramais_por_cidade)): ?>
                <?php foreach ($ramais_por_cidade as $cid): ?>
                    <div class="card-cidade">
                        <div class="card-cidade-titulo" data-id="<?= (int)$cid['id']; ?>">
                            <span class="cidade-visor">
                                <span class="cidade-nome-visor"><?= htmlspecialchars($cid['nome'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <small class="cidade-tel-visor"><?= htmlspecialchars($cid['telefone'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small>
                            </span>

                            <span class="cidade-edicao">
                                <input type="text" class="in-cidade-nome"
                                       value="<?= htmlspecialchars($cid['nome'], ENT_QUOTES, 'UTF-8'); ?>"
                                       placeholder="Nome" maxlength="100">
                                <input type="text" class="in-cidade-tel"
                                       value="<?= htmlspecialchars($cid['telefone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                       placeholder="Telefone" maxlength="20">
                            </span>

                            <span class="cidade-acoes">
                                <button type="button" class="btn-editar-cidade">Editar</button>
                                <button type="button" class="btn-confirmar-cidade">Salvar</button>
                                <button type="button" class="btn-cancelar-cidade">Cancelar</button>
                                <button type="button" class="btn-excluir-cidade">Excluir</button>
                            </span>

                            <label class="cidade-ordem">
                                Ordem:
                                <input type="number"
                                       name="ordem_cidade[<?= (int)$cid['id']; ?>]"
                                       value="<?= (int)$cid['ordem_cidade']; ?>">
                            </label>
                        </div>
                        <ul class="ramais-config-lista">
                            <?php foreach ($cid['ramais'] as $r): ?>
                                <li class="item-ramal" data-id="<?= (int)$r['id']; ?>">
                                    <input type="checkbox"
                                           name="ativo[<?= (int)$r['id']; ?>]"
                                           <?= $r['ativo'] ? 'checked' : ''; ?>>
                                    <span class="drag-handle">☰</span>

                                    <span class="ramal-visor">
                                        <strong class="visor-setor"><?= htmlspecialchars($r['setor'], ENT_QUOTES, 'UTF-8'); ?></strong> -
                                        <span class="visor-ramal"><?= htmlspecialchars($r['ramal'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </span>

                                    <span class="ramal-edicao">
                                        <input type="text" class="in-setor"
                                               value="<?= htmlspecialchars($r['setor'], ENT_QUOTES, 'UTF-8'); ?>"
                                               placeholder="Setor" maxlength="120">
                                        <input type="text" class="in-ramal"
                                               value="<?= htmlspecialchars($r['ramal'], ENT_QUOTES, 'UTF-8'); ?>"
                                               placeholder="Ramal" maxlength="40">
                                    </span>

                                    <span class="ramal-acoes">
                                        <button type="button" class="btn-editar-ramal">Editar</button>
                                        <button type="button" class="btn-confirmar-ramal">Salvar</button>
                                        <button type="button" class="btn-cancelar-ramal">Cancelar</button>
                                        <button type="button"
                                                class="btn-excluir-ramal"
                                                data-id="<?= (int)$r['id']; ?>">Excluir</button>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="padding:6px 8px;font-size:.85rem;color:#666;">
                    Nenhuma unidade cadastrada. Comece criando uma em "Nova unidade".
                </p>
            <?php endif; ?>
        </div>

        <input type="hidden" name="acao" value="salvar">
        <button type="submit" class="btn-salvar-ramais">Salvar ordem / ativo</button>
        <div class="ramais-msg" id="ramais-msg"></div>
    </form>
</div>

<script>
// ---- TORNAR LISTAS DRAGGABLE ----
document.querySelectorAll('.ramais-config-lista').forEach(function(ul){
    new Sortable(ul, {
        animation: 150,
        handle: '.drag-handle',
        ghostClass: 'drag-ghost'
    });
});

// ---- SALVAR ORDEM / ATIVO / CIDADES ----
var formRamais = document.getElementById('form-ramais');
if (formRamais) {
    formRamais.addEventListener('submit', function(e){
        e.preventDefault();

        // remove ordens antigas se houver
        Array.from(formRamais.querySelectorAll('input[name^="ordem["]')).forEach(function(el){
            el.parentNode.removeChild(el);
        });

        // gera ordem[id] pela posição dos <li>
        document.querySelectorAll('.ramais-config-lista').forEach(function(ul){
            var items = ul.querySelectorAll('.item-ramal');
            items.forEach(function(li, index){
                var id = li.getAttribute('data-id');
                if (!id) return;
                var hidden = document.createElement('input');
                hidden.type  = 'hidden';
                hidden.name  = 'ordem['+id+']';
                hidden.value = index + 1;
                formRamais.appendChild(hidden);
            });
        });

        var fd = new FormData(formRamais);
        fd.set('acao','salvar');

        fetch('config_ramais.php', { method:'POST', body:fd })
            .then(r => r.text())
            .then(txt => {
                document.getElementById('ramais-msg').textContent =
                    txt.trim() === 'OK' ? 'Ramais salvos com sucesso.' : 'Erro ao salvar: ' + txt;
            })
            .catch(err => {
                document.getElementById('ramais-msg').textContent = 'Erro: ' + err;
            });
    });
}

// ---- ADICIONAR NOVO ----
function ramaisNovo(){
    const id_cidade = document.getElementById('cidade_nova').value;
    const setor     = document.getElementById('setor_novo').value.trim();
    const ramal     = document.getElementById('ramal_novo').value.trim();
    if (!id_cidade || !setor || !ramal) {
        alert('Preencha cidade, setor e ramal.');
        return;
    }

    const fd = new FormData();
    fd.append('acao','novo');
    fd.append('id_cidade', id_cidade);
    fd.append('setor', setor);
    fd.append('ramal', ramal);

    fetch('config_ramais.php', { method:'POST', body:fd })
        .then(r => r.text())
        .then(txt => {
            if (txt.trim() === 'OK') {
                location.reload();
            } else {
                alert('Erro ao adicionar: ' + txt);
            }
        })
        .catch(err => alert('Erro: ' + err));
}

var btnNovoRamais = document.getElementById('btn-ramal-novo');
if (btnNovoRamais) {
    btnNovoRamais.addEventListener('click', ramaisNovo);
}

// ---- EXCLUIR RAMAL ----
function excluirRamal(id){
    if (!confirm('Deseja realmente excluir este ramal?')) return;

    const fd = new FormData();
    fd.append('acao','excluir');
    fd.append('id', id);

    fetch('config_ramais.php', { method:'POST', body:fd })
        .then(r => r.text())
        .then(txt => {
            if (txt.trim() === 'OK') {
                location.reload();
            } else {
                alert('Erro ao excluir: ' + txt);
            }
        })
        .catch(err => alert('Erro: ' + err));
}

document.querySelectorAll('.btn-excluir-ramal').forEach(btn => {
    btn.addEventListener('click', function(){
        const id = this.getAttribute('data-id');
        excluirRamal(id);
    });
});

// ---- NOVA UNIDADE ----
// Recarrega: a unidade nova precisa aparecer como card e no <select> de ramal.
var btnUnidadeNova = document.getElementById('btn-unidade-nova');
if (btnUnidadeNova) {
    btnUnidadeNova.addEventListener('click', function(){
        const nome = document.getElementById('unidade_nome').value.trim();
        const tel  = document.getElementById('unidade_tel').value.trim();
        if (!nome) {
            alert('Informe o nome da unidade.');
            return;
        }

        const fd = new FormData();
        fd.append('acao','cidade_nova');
        fd.append('nome', nome);
        fd.append('telefone', tel);

        btnUnidadeNova.disabled = true;
        fetch('config_ramais.php', { method:'POST', body:fd })
            .then(r => r.text())
            .then(txt => {
                if (txt.trim() === 'OK') {
                    location.reload();
                } else {
                    alert(txt);
                    btnUnidadeNova.disabled = false;
                }
            })
            .catch(err => { alert('Erro: ' + err); btnUnidadeNova.disabled = false; });
    });
}

// ---- EDITAR / EXCLUIR UNIDADE ----
function abrirEdicaoCidade(tit){
    document.querySelectorAll('.card-cidade-titulo.editando').forEach(o => {
        if (o !== tit) o.classList.remove('editando');
    });
    tit.querySelector('.in-cidade-nome').value =
        tit.querySelector('.cidade-nome-visor').textContent.trim();
    tit.querySelector('.in-cidade-tel').value =
        tit.querySelector('.cidade-tel-visor').textContent.trim();
    tit.classList.add('editando');
    tit.querySelector('.in-cidade-nome').focus();
}

function salvarCidade(tit){
    const id   = tit.getAttribute('data-id');
    const nome = tit.querySelector('.in-cidade-nome').value.trim();
    const tel  = tit.querySelector('.in-cidade-tel').value.trim();

    if (!nome) {
        alert('Informe o nome da unidade.');
        return;
    }

    const btn = tit.querySelector('.btn-confirmar-cidade');
    btn.disabled = true;

    const fd = new FormData();
    fd.append('acao','cidade_editar');
    fd.append('id', id);
    fd.append('nome', nome);
    fd.append('telefone', tel);

    fetch('config_ramais.php', { method:'POST', body:fd })
        .then(r => r.text())
        .then(txt => {
            if (txt.trim() === 'OK') {
                tit.querySelector('.cidade-nome-visor').textContent = nome;
                tit.querySelector('.cidade-tel-visor').textContent  = tel;
                tit.classList.remove('editando');
                document.getElementById('ramais-msg').textContent =
                    'Unidade atualizada: ' + nome;
            } else {
                alert(txt);
            }
        })
        .catch(err => alert('Erro: ' + err))
        .finally(() => { btn.disabled = false; });
}

function excluirCidade(tit){
    const id   = tit.getAttribute('data-id');
    const nome = tit.querySelector('.cidade-nome-visor').textContent.trim();
    if (!confirm('Excluir a unidade "' + nome + '"?')) return;

    const fd = new FormData();
    fd.append('acao','cidade_excluir');
    fd.append('id', id);

    fetch('config_ramais.php', { method:'POST', body:fd })
        .then(r => r.text())
        .then(txt => {
            if (txt.trim() === 'OK') {
                location.reload();
            } else {
                alert(txt);
            }
        })
        .catch(err => alert('Erro: ' + err));
}

document.querySelectorAll('.card-cidade-titulo').forEach(tit => {
    tit.querySelector('.btn-editar-cidade')
       .addEventListener('click', () => abrirEdicaoCidade(tit));
    tit.querySelector('.btn-cancelar-cidade')
       .addEventListener('click', () => tit.classList.remove('editando'));
    tit.querySelector('.btn-confirmar-cidade')
       .addEventListener('click', () => salvarCidade(tit));
    tit.querySelector('.btn-excluir-cidade')
       .addEventListener('click', () => excluirCidade(tit));

    tit.querySelectorAll('.cidade-edicao input').forEach(inp => {
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter')  { e.preventDefault(); salvarCidade(tit); }
            if (e.key === 'Escape') { e.preventDefault(); tit.classList.remove('editando'); }
        });
    });
});

// ---- EDITAR RAMAL (setor / ramal) ----
// Nao usa location.reload() de proposito: recarregar descartaria uma reordenacao
// ainda nao salva. Em caso de sucesso a propria linha e atualizada no DOM.
function abrirEdicao(li){
    // uma linha em edicao por vez, para nao perder alteracao sem salvar
    document.querySelectorAll('.item-ramal.editando').forEach(outro => {
        if (outro !== li) fecharEdicao(outro);
    });

    const setor = li.querySelector('.visor-setor').textContent.trim();
    const ramal = li.querySelector('.visor-ramal').textContent.trim();
    li.querySelector('.in-setor').value = setor;
    li.querySelector('.in-ramal').value = ramal;

    li.classList.add('editando');
    li.querySelector('.in-setor').focus();
}

function fecharEdicao(li){
    li.classList.remove('editando');
}

function salvarEdicao(li){
    const id    = li.getAttribute('data-id');
    const setor = li.querySelector('.in-setor').value.trim();
    const ramal = li.querySelector('.in-ramal').value.trim();

    if (!setor || !ramal) {
        alert('Preencha setor e ramal.');
        return;
    }

    const btn = li.querySelector('.btn-confirmar-ramal');
    btn.disabled = true;

    const fd = new FormData();
    fd.append('acao','editar');
    fd.append('id', id);
    fd.append('setor', setor);
    fd.append('ramal', ramal);

    fetch('config_ramais.php', { method:'POST', body:fd })
        .then(r => r.text())
        .then(txt => {
            if (txt.trim() === 'OK') {
                li.querySelector('.visor-setor').textContent = setor;
                li.querySelector('.visor-ramal').textContent = ramal;
                fecharEdicao(li);
                document.getElementById('ramais-msg').textContent =
                    'Ramal atualizado: ' + setor + ' - ' + ramal;
            } else {
                alert('Erro ao editar: ' + txt);
            }
        })
        .catch(err => alert('Erro: ' + err))
        .finally(() => { btn.disabled = false; });
}

document.querySelectorAll('.item-ramal').forEach(li => {
    li.querySelector('.btn-editar-ramal')
      .addEventListener('click', () => abrirEdicao(li));

    li.querySelector('.btn-cancelar-ramal')
      .addEventListener('click', () => fecharEdicao(li));

    li.querySelector('.btn-confirmar-ramal')
      .addEventListener('click', () => salvarEdicao(li));

    // Enter salva, Esc cancela
    li.querySelectorAll('.ramal-edicao input').forEach(inp => {
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter')  { e.preventDefault(); salvarEdicao(li); }
            if (e.key === 'Escape') { e.preventDefault(); fecharEdicao(li); }
        });
    });
});
</script>
<div class="app-version-label">
    <?php echo VERSAO_CONFIG_RAMAIS; ?>
</div>
</body>
</html>
