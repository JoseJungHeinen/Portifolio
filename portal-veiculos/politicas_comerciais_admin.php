<?php
require_once __DIR__ . '/config_database.php';
session_start();

if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}
$is_admin  = !empty($_SESSION['is_admin']);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

if (!$is_admin) {
    exit('Acesso restrito ao administrador.');
}

function registrar_log(mysqli $db, int $politicaId, int $usuarioId, string $acao, string $detalhes = null): void {
    $stmt = $db->prepare("
        INSERT INTO politicas_comerciais_log (politica_id, usuario_id, acao, detalhes)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('iiss', $politicaId, $usuarioId, $acao, $detalhes);
    $stmt->execute();
    $stmt->close();
}

/* AÇÕES POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    /* NOVA POLÍTICA */
    if ($acao === 'nova') {
        $titulo = trim($_POST['titulo'] ?? '');
        if ($titulo !== '' && isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['arquivo']['tmp_name'];
            $nomeArq = basename($_FILES['arquivo']['name']);
            $ext     = strtolower(pathinfo($nomeArq, PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                $dir = __DIR__ . '/uploads_politicas';
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                $novoNome   = 'politica_'.time().'.pdf';
                $destinoFs  = $dir . '/' . $novoNome;
                $destinoUrl = 'uploads_politicas/' . $novoNome;

                if (move_uploaded_file($tmpName, $destinoFs)) {
                    $stmt = $mysqli->prepare("
                        INSERT INTO politicas_comerciais (titulo, arquivo_pdf, ativo, criado_por)
                        VALUES (?, ?, 1, ?)
                    ");
                    $stmt->bind_param('ssi', $titulo, $destinoUrl, $usuarioId);
                    $stmt->execute();
                    $idNova = $stmt->insert_id;
                    $stmt->close();

                    registrar_log($mysqli, $idNova, $usuarioId, 'CRIAR', 'Cadastro de nova política');
                }
            }
        }
        header('Location: politicas_comerciais_admin.php');
        exit;
    }

    /* TROCAR PDF */
    if ($acao === 'trocar_arquivo') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['arquivo']['tmp_name'];
            $nomeArq = basename($_FILES['arquivo']['name']);
            $ext     = strtolower(pathinfo($nomeArq, PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                $dir = __DIR__ . '/uploads_politicas';
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                $novoNome   = 'politica_'.$id.'_'.time().'.pdf';
                $destinoFs  = $dir . '/' . $novoNome;
                $destinoUrl = 'uploads_politicas/' . $novoNome;

                if (move_uploaded_file($tmpName, $destinoFs)) {
                    $stmtUp = $mysqli->prepare("
                        UPDATE politicas_comerciais
                           SET arquivo_pdf = ?, atualizado_por = ?
                         WHERE id = ?
                    ");
                    $stmtUp->bind_param('sii', $destinoUrl, $usuarioId, $id);
                    $stmtUp->execute();
                    $stmtUp->close();

                    registrar_log($mysqli, $id, $usuarioId, 'TROCAR_ARQUIVO', 'Substituição de PDF');
                }
            }
        }
        header('Location: politicas_comerciais_admin.php');
        exit;
    }

    /* SALVAR SETOR (via fetch, sem recarregar) */
    if ($acao === 'salvar_setor') {
        $id             = (int)($_POST['id'] ?? 0);
        $setorPermitido = $_POST['setor_permitido'] ?? '';

        if ($id > 0) {
            $stmt = $mysqli->prepare("
                UPDATE politicas_comerciais
                   SET setor_permitido = ?, atualizado_por = ?
                 WHERE id = ?
            ");
            $stmt->bind_param('sii', $setorPermitido, $usuarioId, $id);
            $stmt->execute();
            $stmt->close();

            registrar_log($mysqli, $id, $usuarioId, 'ALTERAR_SETOR', 'Alteração de setor permitido');
        }
        // chamada AJAX, não redireciona
        exit;
    }

    /* ATIVAR / DESATIVAR */
    if ($acao === 'toggle_ativo') {
        $id    = (int)($_POST['id'] ?? 0);
        $ativo = (int)($_POST['ativo'] ?? 0);

        if ($id > 0) {
            $stmt = $mysqli->prepare("
                UPDATE politicas_comerciais
                   SET ativo = ?, atualizado_por = ?
                 WHERE id = ?
            ");
            $stmt->bind_param('iii', $ativo, $usuarioId, $id);
            $stmt->execute();
            $stmt->close();

            registrar_log(
                $mysqli,
                $id,
                $usuarioId,
                $ativo ? 'ATIVAR' : 'DESATIVAR',
                $ativo ? 'Política ativada' : 'Política desativada'
            );
        }
        header('Location: politicas_comerciais_admin.php');
        exit;
    }
}

/* LISTAGEM + última atualização pelo log */
$politicas = [];
$res = $mysqli->query("
    SELECT p.id,
           p.titulo,
           p.arquivo_pdf,
           p.ativo,
           p.criado_em,
           p.setor_permitido,
           l.criado_em   AS ultima_acao_em,
           u.nome        AS ultima_acao_por,
           l.acao        AS ultima_acao_tipo
      FROM politicas_comerciais p
 LEFT JOIN politicas_comerciais_log l
        ON l.id = (
             SELECT l2.id
               FROM politicas_comerciais_log l2
              WHERE l2.politica_id = p.id
           ORDER BY l2.criado_em DESC, l2.id DESC
              LIMIT 1
        )
 LEFT JOIN usuarios u
        ON u.id = l.usuario_id
  ORDER BY p.criado_em DESC
");
while ($row = $res->fetch_assoc()) {
    $politicas[] = $row;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Admin — Políticas Comerciais</title>
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

.page-shell { max-width: 1200px; margin: 0 auto; }

/* cabeçalho */
.page-header {
    background: #fff;
    border: 1px solid #ebebeb;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.07);
    padding: 1.4rem 2rem;
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
.page-header-text h1 { font-size: 1.15rem; font-weight: 700; color: #111; }
.page-header-text p  { font-size: .8rem; color: #999; margin-top: 3px; }

/* formulário nova política */
.nova-politica-card {
    background: #fff;
    border: 1px solid #ebebeb;
    border-radius: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    padding: 1.2rem 1.5rem;
    margin-bottom: 1.5rem;
}
.nova-politica-card h2 {
    font-size: .92rem;
    font-weight: 700;
    color: #111;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.nova-politica-card h2::before {
    content: '';
    display: inline-block;
    width: 3px; height: 16px;
    background: #cc0000;
    border-radius: 999px;
}
.nova-form {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}
.nova-form .field { display: flex; flex-direction: column; gap: 4px; flex: 1 1 260px; }
.nova-form .field label { font-size: .8rem; font-weight: 600; color: #555; }
.nova-form .field input[type="text"] {
    padding: 9px 14px;
    border: 1px solid #ddd;
    border-radius: 999px;
    font-size: .88rem;
    background: #fafafa;
    font-family: inherit;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.nova-form .field input[type="text"]:focus {
    border-color: #cc0000;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(204,0,0,.08);
}

/* upload customizado */
.file-label {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 16px;
    border: 1px dashed #ddd;
    border-radius: 999px;
    background: #fafafa;
    font-size: .84rem;
    color: #555;
    cursor: pointer;
    font-weight: 600;
    transition: border-color .15s, background .15s;
    white-space: nowrap;
}
.file-label:hover { border-color: #cc0000; background: #fff8f8; color: #cc0000; }
.file-label.selecionado { border-color: #16a34a; color: #16a34a; background: #f0fdf4; }
input[type="file"] { display: none; }

.btn-salvar {
    padding: 9px 24px;
    background: #cc0000; color: #fff;
    border: none; border-radius: 999px;
    font-size: .88rem; font-weight: 700;
    cursor: pointer; font-family: inherit;
    box-shadow: 0 4px 12px rgba(204,0,0,.3);
    transition: background .15s, transform .1s;
    white-space: nowrap;
}
.btn-salvar:hover { background: #aa0000; }
.btn-salvar:active { transform: translateY(1px); }

/* lista de políticas */
.lista-card {
    background: #fff;
    border: 1px solid #ebebeb;
    border-radius: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    overflow: hidden;
}
.lista-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.lista-header h2 {
    font-size: .92rem;
    font-weight: 700;
    color: #111;
    display: flex;
    align-items: center;
    gap: 8px;
}
.lista-header h2::before {
    content: '';
    display: inline-block;
    width: 3px; height: 16px;
    background: #cc0000;
    border-radius: 999px;
}

/* tabela moderna */
.politicas-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .83rem;
}
.politicas-table thead tr {
    background: #fafafa;
    border-bottom: 2px solid #f0f0f0;
}
.politicas-table th {
    padding: 10px 14px;
    font-weight: 700;
    color: #777;
    text-transform: uppercase;
    font-size: .72rem;
    letter-spacing: .05em;
    text-align: left;
    white-space: nowrap;
}
.politicas-table tbody tr {
    border-bottom: 1px solid #f5f5f5;
    transition: background .1s;
}
.politicas-table tbody tr:last-child { border-bottom: none; }
.politicas-table tbody tr:hover { background: #fafafa; }
.politicas-table td {
    padding: 12px 14px;
    vertical-align: middle;
}

/* coluna título */
.td-titulo .nome {
    font-weight: 600;
    color: #111;
    font-size: .88rem;
}
.td-titulo .meta {
    font-size: .73rem;
    color: #bbb;
    margin-top: 3px;
}

/* coluna arquivo */
.link-pdf {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: .78rem;
    color: #cc0000;
    text-decoration: none;
    font-weight: 600;
}
.link-pdf:hover { text-decoration: underline; }
.ultima-acao {
    font-size: .72rem;
    color: #bbb;
    margin-top: 3px;
    line-height: 1.4;
}

/* badges */
.badge-ativo {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: .73rem; padding: 3px 10px; border-radius: 999px;
    background: #dcfce7; color: #16a34a; font-weight: 700;
}
.badge-inativo {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: .73rem; padding: 3px 10px; border-radius: 999px;
    background: #fee2e2; color: #b91c1c; font-weight: 700;
}

/* ações inline */
.td-acoes {
    white-space: nowrap;
}
.acoes-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: nowrap;
}

/* select setor */
.select-setor {
    padding: 6px 10px;
    border: 1px solid #ddd;
    border-radius: 999px;
    font-size: .78rem;
    font-family: inherit;
    background: #fafafa;
    color: #444;
    cursor: pointer;
    outline: none;
    transition: border-color .15s;
    height: 32px;
}
.select-setor:focus { border-color: #cc0000; }
.select-setor.salvo { border-color: #16a34a; }

/* botões de ação pequenos */
.btn-acao {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 999px;
    border: 1px solid #ddd;
    background: #fff;
    color: #444;
    font-size: .76rem;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    white-space: nowrap;
    transition: background .12s, border-color .12s;
    height: 32px;
}
.btn-acao:hover { background: #f5f5f5; border-color: #bbb; }
.btn-acao.pdf { border-color: #fca5a5; color: #cc0000; background: #fff8f8; }
.btn-acao.pdf:hover { background: #fee2e2; }
.btn-acao.toggle-ativar { border-color: #86efac; color: #16a34a; background: #f0fdf4; }
.btn-acao.toggle-ativar:hover { background: #dcfce7; }
.btn-acao.toggle-desativar { border-color: #fca5a5; color: #b91c1c; background: #fff8f8; }
.btn-acao.toggle-desativar:hover { background: #fee2e2; }

/* estado vazio */
.empty-row td {
    padding: 2.5rem;
    text-align: center;
    color: #bbb;
    font-size: .88rem;
}

@media (max-width: 900px) {
    body { padding: 0; }
    .page-header { border-radius: 0; }
    .nova-politica-card, .lista-card { border-radius: 0; margin-bottom: 1rem; }
    .acoes-row { flex-wrap: wrap; }
    .politicas-table { font-size: .78rem; }
}
</style>
</head>
<body>
<div class="page-shell">

    <!-- Cabeçalho -->
    <div class="page-header">
        <div class="page-header-icon">
            <svg width="26" height="26" fill="none" viewBox="0 0 24 24">
                <path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke="white" stroke-width="1.8" stroke-linecap="round"/>
                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L13 14l-4 1 1-4 8.5-8.5z" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <div class="page-header-text">
            <h1>Gestão de Políticas Comerciais</h1>
            <p>Cadastro, substituição de PDF, setor permitido e ativação/desativação.</p>
        </div>
    </div>

    <!-- Nova política -->
    <div class="nova-politica-card">
        <h2>Nova política</h2>
        <form class="nova-form" method="post" enctype="multipart/form-data" id="form-nova">
            <input type="hidden" name="acao" value="nova">

            <div class="field">
                <label>Título da política</label>
                <input type="text" name="titulo" placeholder="Ex: Política de descontos 2025" required>
            </div>

            <div class="field" style="flex:0 1 auto;">
                <label>Arquivo PDF</label>
                <label class="file-label" id="label-arquivo-nova" for="arquivo-nova">
                    <svg width="15" height="15" fill="none" viewBox="0 0 24 24">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span id="label-arquivo-nova-txt">Escolher PDF</span>
                </label>
                <input type="file" id="arquivo-nova" name="arquivo" accept="application/pdf" required>
            </div>

            <button type="submit" class="btn-salvar">
                + Cadastrar
            </button>
        </form>
    </div>

    <!-- Lista -->
    <div class="lista-card">
        <div class="lista-header">
            <h2>Políticas cadastradas</h2>
        </div>

        <?php if (!$politicas): ?>
            <table class="politicas-table">
                <tbody>
                    <tr class="empty-row"><td colspan="5">Nenhuma política cadastrada.</td></tr>
                </tbody>
            </table>
        <?php else: ?>
            <?php
            $setores = [
                ''           => 'Todos os setores',
                'vendas'     => 'Vendas',
                'financeiro' => 'Financeiro',
                'locadora'   => 'Locadora',
                'oficina'    => 'Oficina',
                'pecas'      => 'Peças',
            ];
            ?>
            <table class="politicas-table">
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Arquivo</th>
                        <th>Setor</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($politicas as $p): ?>
                    <tr id="row-<?= (int)$p['id']; ?>">

                        <!-- Título -->
                        <td class="td-titulo">
                            <div class="nome"><?= htmlspecialchars($p['titulo'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php if (!empty($p['criado_em'])): ?>
                                <div class="meta">Criado em <?= date('d/m/Y', strtotime($p['criado_em'])); ?></div>
                            <?php endif; ?>
                        </td>

                        <!-- Arquivo -->
                        <td>
                            <a href="<?= htmlspecialchars($p['arquivo_pdf'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="link-pdf">
                                <svg width="12" height="12" fill="none" viewBox="0 0 24 24">
                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Ver PDF
                            </a>
                            <?php if (!empty($p['ultima_acao_em']) && !empty($p['ultima_acao_por'])): ?>
                                <div class="ultima-acao">
                                    <?= date('d/m/Y H:i', strtotime($p['ultima_acao_em'])); ?>
                                    · <?= htmlspecialchars($p['ultima_acao_por'], ENT_QUOTES, 'UTF-8'); ?>
                                    · <?= htmlspecialchars($p['ultima_acao_tipo'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Setor (salva via AJAX) -->
                        <td>
                            <form method="post" class="form-setor">
                                <input type="hidden" name="acao" value="salvar_setor">
                                <input type="hidden" name="id" value="<?= (int)$p['id']; ?>">
                                <select name="setor_permitido" class="select-setor seletor-setor">
                                    <?php foreach ($setores as $valor => $label): ?>
                                        <option value="<?= $valor; ?>" <?= $valor === ($p['setor_permitido'] ?? '') ? 'selected' : ''; ?>>
                                            <?= $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>

                        <!-- Status -->
                        <td>
                            <?php if ((int)$p['ativo'] === 1): ?>
                                <span class="badge-ativo">Ativo</span>
                            <?php else: ?>
                                <span class="badge-inativo">Inativo</span>
                            <?php endif; ?>
                        </td>

                        <!-- Ações -->
                        <td class="td-acoes">
                            <div class="acoes-row">

                                <!-- Substituir PDF -->
                                <form method="post" enctype="multipart/form-data" class="form-trocar" style="display:flex;align-items:center;gap:6px;">
                                    <input type="hidden" name="acao" value="trocar_arquivo">
                                    <input type="hidden" name="id" value="<?= (int)$p['id']; ?>">

                                    <label class="btn-acao pdf" for="arq_<?= (int)$p['id']; ?>" id="label-arq-<?= (int)$p['id']; ?>">
                                        <svg width="12" height="12" fill="none" viewBox="0 0 24 24">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                        <span id="txt-arq-<?= (int)$p['id']; ?>">Trocar PDF</span>
                                    </label>
                                    <input type="file" id="arq_<?= (int)$p['id']; ?>" name="arquivo" accept="application/pdf" required
                                           onchange="arquivoSelecionado(<?= (int)$p['id']; ?>, this)">

                                    <button type="submit" class="btn-acao" id="btn-enviar-<?= (int)$p['id']; ?>" style="display:none;">
                                        Enviar
                                    </button>
                                </form>

                                <!-- Ativar / Desativar -->
                                <form method="post">
                                    <input type="hidden" name="acao" value="toggle_ativo">
                                    <input type="hidden" name="id" value="<?= (int)$p['id']; ?>">
                                    <input type="hidden" name="ativo" value="<?= (int)$p['ativo'] ? 0 : 1; ?>">
                                    <button type="submit" class="btn-acao <?= (int)$p['ativo'] ? 'toggle-desativar' : 'toggle-ativar'; ?>">
                                        <?= (int)$p['ativo'] ? '⏸ Desativar' : '▶ Ativar'; ?>
                                    </button>
                                </form>

                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
// feedback de arquivo selecionado no upload
function arquivoSelecionado(id, input) {
    const label  = document.getElementById('label-arq-' + id);
    const txt    = document.getElementById('txt-arq-' + id);
    const btnEnv = document.getElementById('btn-enviar-' + id);

    if (input.files && input.files.length > 0) {
        txt.textContent = input.files[0].name.length > 18
            ? input.files[0].name.substring(0, 16) + '…'
            : input.files[0].name;
        label.style.borderColor = '#16a34a';
        label.style.color = '#16a34a';
        label.style.background = '#f0fdf4';
        btnEnv.style.display = 'inline-flex';
    }
}

// feedback no label de upload do form nova
document.getElementById('arquivo-nova').addEventListener('change', function () {
    const label = document.getElementById('label-arquivo-nova');
    const txt   = document.getElementById('label-arquivo-nova-txt');
    if (this.files && this.files.length > 0) {
        txt.textContent = this.files[0].name.length > 22
            ? this.files[0].name.substring(0, 20) + '…'
            : this.files[0].name;
        label.classList.add('selecionado');
    }
});

// salvar setor via AJAX
document.querySelectorAll('.seletor-setor').forEach(function (select) {
    select.addEventListener('change', function () {
        const form = this.closest('form');
        if (!form) return;

        fetch('politicas_comerciais_admin.php', {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'fetch' }
        }).then(function () {
            select.classList.add('salvo');
            setTimeout(function () { select.classList.remove('salvo'); }, 800);
        }).catch(function () {
            select.style.borderColor = '#dc2626';
            setTimeout(function () { select.style.borderColor = ''; }, 800);
        });
    });
});
</script>
</body>
</html>