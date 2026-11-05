<?php
// @versao Ver.001.161026
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';
session_start();

if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php');
    exit;
}

$mysqli->set_charset('utf8mb4');

$opcoesSetorGlobais = [
    'VENDAS'       => 'Vendas',
    'TI'           => 'TI',
    'DIRETORIA'    => 'Diretoria',
    'FINANCEIRO'   => 'Financeiro',
    'OFICINA'      => 'Oficina',
    'PECAS'        => 'Peças',
    'VENDA DIRETA' => 'Venda Direta',
    'LOCADORA'      => 'Locadora',
    'DOCUMENTAL'    => 'Documental',
    'RH'            => 'RH',
    'COMPRAS'         => 'Compras',
    'CONTROLADORIA'   => 'Controladoria',
];

function listarSidebarPermissoes(): array {
    return [
        'geral' => [
            'label' => 'Geral',
            'icon'  => 'fa fa-home',
            'items' => [
                'menu_buscar'     => 'Buscar',
                'menu_links'      => 'Links úteis',
                'menu_ramais'     => 'Ramais',
                'menu_inventario' => 'Inventário',
            ],
        ],
        'veiculos' => [
            'label' => 'Veiculos',
            'icon'  => 'fa fa-car',
            'items' => [
                'menu_apresentacao_carros'      => 'Vídeos dos carros',
                'menu_bancos'                    => 'Bancos',
                'menu_ferramenta_transferencia' => '→ Transferência',
                'menu_ferramenta_emplacamento'  => '→ Emplacamento',
                'menu_ferramenta_cartao'        => '→ Cartão',
                'menu_ferramenta_documentos'    => '→ Documentos',
                'menu_ferramenta_orcamento'     => '→ Orçamento',
            ],
        ],
        'politicas' => [
            'label' => 'Políticas comerciais',
            'icon'  => 'fa fa-file-pdf',
            'items' => [
                'menu_politicas'       => 'Políticas comerciais',
                'menu_politicas_admin' => '→ Admin. políticas',
            ],
        ],
        'oficina' => [
            'label' => 'Oficina',
            'icon'  => 'fa fa-wrench',
            'items' => [
                'menu_acessorios' => 'Acessórios',
            ],
        ],
        'administrativo' => [
            'label' => 'Administrativo',
            'icon'  => 'fa fa-chart-bar',
            'items' => [
                'menu_bi_veiculo'       => 'BI Veículos',
                'menu_bi_metas'         => 'BI Metas',
                'bi_metas_admin'        => '→ BI Metas: Administrador (editar metas/exclusões)',
                'menu_bi_locadora'      => 'BI Locadora',
                'menu_bi_oficina'       => 'BI Oficina',
                'menu_bi_pecas'         => 'BI Peças',
                'menu_bi_financeiro'    => 'BI Financeiro',
                'menu_bi_contabilidade' => 'BI Contabilidade',
            ],
        ],
        'treinamentos' => [
            'label' => 'Treinamentos',
            'icon'  => 'fa fa-graduation-cap',
            'items' => [
                'menu_treinamentos'        => 'Meus treinamentos',
                'menu_treinamentos_gestor' => 'Gestão de equipe',
            ],
        ],
        'locadora' => [
            'label' => 'Locadora',
            'icon'  => 'fa fa-car',
            'items' => [
                'menu_locadora' => 'Locadora',
            ],
        ],
        'renave' => [
            'label' => 'Renave',
            'icon'  => 'fa fa-id-card-alt',
            'items' => [
                'menu_renave' => 'Renave',
            ],
        ],
        'pecas' => [
            'label' => 'Peças',
            'icon'  => 'fa fa-cogs',
            'items' => [
                'menu_pecas'                  => 'Peças',
                'menu_pecas_inventario'       => '→ Inventário',
                'menu_pecas_acerto_locacao'   => '→ Acerto de locação',
                'menu_pecas_consulta_placa'   => '→ Consulta de Placa',
            ],
        ],
    ];
}

$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

// ── AJAX: carregar dados do usuário ────────────────────────────────────────
if (isset($_GET['user_dados']) && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)$_GET['user_dados'];

    $stmt = $mysqli->prepare("
        SELECT u.id, u.nome, u.cpf, u.email, u.telefone, u.id_loja,
               u.setor, u.is_admin, u.is_gestor, u.is_ativo,
               u.can_edit_acessorios,
               l.nome AS loja_nome
          FROM usuarios u
          LEFT JOIN lojas l ON l.id = u.id_loja
         WHERE u.id = ? LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $perms = [];
    $stmt2 = $mysqli->prepare("SELECT * FROM usuarios_permissoes WHERE usuario_id = ? LIMIT 1");
    $stmt2->bind_param('i', $id);
    $stmt2->execute();
    if ($rowPerm = $stmt2->get_result()->fetch_assoc()) $perms = $rowPerm;
    $stmt2->close();
    $user['permissoes'] = $perms;

    $treinLiberados = [];
    $stmt3 = $mysqli->prepare("SELECT treinamento_id FROM usuario_treinamentos WHERE usuario_id = ?");
    $stmt3->bind_param('i', $id);
    $stmt3->execute();
    $res3 = $stmt3->get_result();
    while ($r3 = $res3->fetch_assoc()) $treinLiberados[] = (int)$r3['treinamento_id'];
    $stmt3->close();
    $user['treinamentos_liberados'] = $treinLiberados;

    echo json_encode($user);
    exit;
}

// ── AJAX: salvar usuário ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_usuario']) && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');

    $idUser = (int)($_POST['id_usuario'] ?? 0);
    if ($idUser <= 0) { echo json_encode(['ok' => false, 'msg' => 'Usuário inválido.']); exit; }

    $isAdmin           = ($_POST['tipo_usuario'] ?? 'USER') === 'ADMIN' ? 1 : 0;
    $isGestor          = (int)($_POST['is_gestor'] ?? 0) === 1 ? 1 : 0;
    $status            = (int)($_POST['is_ativo']  ?? 1)  === 1 ? 1 : 0;
    $canEditAcessorios = (int)($_POST['can_edit_acessorios'] ?? 0) === 1 ? 1 : 0;

    $telefone = trim($_POST['telefone'] ?? '');
    if (mb_strlen($telefone) > 20) { echo json_encode(['ok' => false, 'msg' => 'Telefone deve ter no máximo 20 caracteres.']); exit; }

    $setoresPost = $_POST['setor'] ?? [];
    if (!is_array($setoresPost)) $setoresPost = [$setoresPost];
    $setoresPost = array_slice(array_values(array_unique(array_filter($setoresPost, 'strlen'))), 0, 2);
    $setorStr    = implode(',', $setoresPost);

    $permsPost = $_POST['perms'] ?? [];
    if (!is_array($permsPost)) $permsPost = [];

    try {
        $mysqli->begin_transaction();

        $stmt = $mysqli->prepare(
            "UPDATE usuarios SET telefone=?, setor=?, is_admin=?, is_gestor=?, is_ativo=?, can_edit_acessorios=? WHERE id=?"
        );
        $stmt->bind_param('ssiiiii', $telefone, $setorStr, $isAdmin, $isGestor, $status, $canEditAcessorios, $idUser);
        $stmt->execute();
        $stmt->close();

        $colsPerms = [];
        foreach (listarSidebarPermissoes() as $g) {
            foreach ($g['items'] as $permKey => $label) {
                $colsPerms[$permKey] = isset($permsPost[$permKey]) ? 1 : 0;
            }
        }

        $stmtCheck = $mysqli->prepare("SELECT id FROM usuarios_permissoes WHERE usuario_id = ? LIMIT 1");
        $stmtCheck->bind_param('i', $idUser);
        $stmtCheck->execute();
        $existe = $stmtCheck->get_result()->fetch_assoc();
        $stmtCheck->close();

        if ($existe) {
            $sets = []; $vals = [];
            foreach ($colsPerms as $col => $val) { $sets[] = "`{$col}` = ?"; $vals[] = $val; }
            $vals[] = $idUser;
            $stmtU = $mysqli->prepare("UPDATE usuarios_permissoes SET " . implode(', ', $sets) . " WHERE usuario_id = ?");
            $stmtU->bind_param(str_repeat('i', count($vals)), ...$vals);
            $stmtU->execute(); $stmtU->close();
        } else {
            $cols  = implode(',', array_map(fn($c) => "`$c`", array_keys($colsPerms)));
            $ph    = implode(',', array_fill(0, count($colsPerms) + 1, '?'));
            $stmtI = $mysqli->prepare("INSERT INTO usuarios_permissoes (usuario_id,$cols) VALUES ($ph)");
            $vals  = array_merge([$idUser], array_values($colsPerms));
            $stmtI->bind_param(str_repeat('i', count($vals)), ...$vals);
            $stmtI->execute(); $stmtI->close();
        }

        $treinPost = isset($_POST['treinamentos']) && is_array($_POST['treinamentos'])
            ? array_map('intval', $_POST['treinamentos']) : [];
        $stmtDel = $mysqli->prepare("DELETE FROM usuario_treinamentos WHERE usuario_id = ?");
        $stmtDel->bind_param('i', $idUser);
        $stmtDel->execute();
        $stmtDel->close();
        if (!empty($treinPost)) {
            $stmtIns = $mysqli->prepare("INSERT IGNORE INTO usuario_treinamentos (usuario_id, treinamento_id) VALUES (?, ?)");
            foreach ($treinPost as $tid) {
                $stmtIns->bind_param('ii', $idUser, $tid);
                $stmtIns->execute();
            }
            $stmtIns->close();
        }

        $mysqli->commit();
        echo json_encode(['ok' => true, 'msg' => 'Usuário e permissões atualizados com sucesso.']);
    } catch (Throwable $e) {
        $mysqli->rollback();
        echo json_encode(['ok' => false, 'msg' => 'Erro ao salvar: ' . $e->getMessage()]);
    }
    exit;
}

// ── AJAX: resetar senha ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resetar_senha']) && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');

    $idUser = (int)($_POST['id_usuario'] ?? 0);
    if ($idUser <= 0) { echo json_encode(['ok' => false, 'msg' => 'Usuário inválido.']); exit; }

    $senha = trim($_POST['nova_senha'] ?? '');
    if (strlen($senha) < 4) {
        echo json_encode(['ok' => false, 'msg' => 'A senha deve ter no mínimo 4 caracteres.']);
        exit;
    }

    $hash = password_hash($senha, PASSWORD_DEFAULT);

    $stmt = $mysqli->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ? LIMIT 1");
    $stmt->bind_param('si', $hash, $idUser);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();

    echo json_encode([
        'ok'  => $ok,
        'msg' => $ok ? 'Senha redefinida com sucesso.' : 'Erro ao redefinir senha.',
    ]);
    exit;
}

// ── Listar usuários ────────────────────────────────────────────────────────
$result = $mysqli->query("
    SELECT u.id, u.nome, u.cpf, u.email, u.telefone, u.id_loja,
           u.setor, u.is_admin, u.is_gestor, u.is_ativo, u.can_edit_acessorios,
           l.nome AS loja_nome
      FROM usuarios u
      LEFT JOIN lojas l ON l.id = u.id_loja
     ORDER BY u.nome ASC
");
$usuarios = [];
if ($result) { while ($r = $result->fetch_assoc()) $usuarios[] = $r; }
else die('Erro na query: ' . $mysqli->error);

$treinamentosPorSetor = [];
$resTS = $mysqli->query("SELECT id, setor, pasta FROM treinamento_setores ORDER BY ordem, setor");
if ($resTS) {
    while ($r = $resTS->fetch_assoc()) {
        $r['treinamentos'] = [];
        $treinamentosPorSetor[] = $r;
    }
}
$idxPorSetor = [];
foreach ($treinamentosPorSetor as $i => $s) $idxPorSetor[$s['id']] = $i;

$resT = $mysqli->query("SELECT id, treinamento_setor_id, subpasta, titulo FROM treinamentos ORDER BY treinamento_setor_id, subpasta, ordem, titulo");
if ($resT) {
    while ($t = $resT->fetch_assoc()) {
        $sid = (int)$t['treinamento_setor_id'];
        if (!isset($idxPorSetor[$sid])) continue;
        $treinamentosPorSetor[$idxPorSetor[$sid]]['treinamentos'][] = [
            'id'       => (int)$t['id'],
            'titulo'   => $t['titulo'],
            'subpasta' => $t['subpasta'],
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Gerenciar Usuários</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{--red:#c8102e;--bg:#f5f5f5;--card-bg:#fff;--border:#ddd;--muted:#777}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:system-ui,-apple-system,sans-serif;background:var(--bg);min-height:100vh}

        .page-header{padding:16px 24px 8px;display:flex;justify-content:space-between;align-items:flex-end}
        .page-header h2{font-size:20px;font-weight:700;color:#222;display:flex;align-items:center;gap:8px}
        .page-header p{font-size:12px;color:var(--muted);margin-top:2px}
        .btn-novo{background:#2347c8;color:#fff;border:none;padding:10px 18px;border-radius:999px;font-size:.85rem;font-weight:700;cursor:pointer;box-shadow:0 6px 16px rgba(35,71,200,.45);text-decoration:none}

        .layout{display:flex;padding:0 24px 24px;gap:18px}

        .users-panel{width:280px;background:var(--card-bg);border-radius:14px;box-shadow:0 8px 20px rgba(0,0,0,.08);overflow:hidden;display:flex;flex-direction:column}
        .users-header{padding:12px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #eee;font-size:14px;font-weight:600}
        .users-count{font-size:11px;background:#eee;padding:2px 8px;border-radius:999px}
        .users-search{padding:8px 12px;border-bottom:1px solid #f0f0f0}
        .users-search input{width:100%;padding:5px 8px;border-radius:999px;border:1px solid #ddd;font-size:12px;outline:none}
        .users-list{max-height:calc(100vh - 210px);overflow:auto}
        .user-item{display:flex;padding:8px 12px;gap:10px;border-bottom:1px solid #f2f2f2;cursor:pointer}
        .user-item:hover{background:#fafafa}
        .user-item.selecionado{background:#fff3f5}
        .user-item.inativo{opacity:.6}
        .user-avatar{width:32px;height:32px;border-radius:999px;background:#ffebee;color:#b00020;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0}
        .user-meta{display:flex;flex-direction:column;gap:2px;min-width:0;flex:1}
        .user-name{font-size:13px;font-weight:600;color:#222;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .user-email{font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;color:#fff}
        .badge-green{background:#27ae60}
        .badge-gray{background:#7f8c8d}
        .badge-acc{background:#e67e22}

        .right-panel{flex:1;min-width:0;background:var(--card-bg);border-radius:14px;box-shadow:0 8px 20px rgba(0,0,0,.08);padding:18px 20px;display:flex;flex-direction:column;overflow-y:auto;max-height:calc(100vh - 80px)}
        .right-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--muted);font-size:14px;gap:8px}
        .user-form-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
        .user-form-title{font-size:16px;font-weight:700}
        .user-form-subtitle{font-size:12px;color:var(--muted)}
        .user-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 16px;font-size:13px}
        .form-group label{display:block;font-size:11px;text-transform:uppercase;color:#777;margin-bottom:2px}
        .form-group input[type="text"],.form-group input[type="email"],.form-group input[type="tel"]{width:100%;padding:5px 8px;border-radius:6px;border:1px solid #ddd;font-size:13px;background:#f9f9f9}
        .form-group input.editavel{background:#fff;border-color:#bbb}
        .form-group input.editavel:focus{outline:none;border-color:var(--red)}

        .pill-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600}
        .pill-admin{background:#2ecc71;color:#fff}
        .pill-user{background:#7f8c8d;color:#fff}

        .setor-dropdown,.tipo-dropdown,.status-dropdown{position:relative;display:inline-block;font-size:12px}
        .setor-dropdown-toggle,.tipo-dropdown-toggle,.status-dropdown-toggle{display:flex;align-items:center;justify-content:space-between;gap:6px;padding:3px 8px;border-radius:999px;border:1px solid #d0d0d0;background:#fff;cursor:pointer;min-height:28px}
        .setor-chips{display:flex;flex-wrap:wrap;gap:4px;max-width:220px}
        .setor-chip{background:#ffe5e5;color:#b00000;border-radius:999px;padding:2px 8px;font-size:11px}
        .setor-placeholder{color:#888;font-size:11px}
        .dropdown-icon{font-size:10px;color:#777}
        .setor-dropdown-menu,.tipo-dropdown-menu,.status-dropdown-menu{position:absolute;top:100%;left:0;margin-top:4px;background:#fff;border-radius:8px;border:1px solid #d0d0d0;box-shadow:0 8px 20px rgba(0,0,0,.12);padding:6px 8px;min-width:180px;z-index:30;display:none}
        .setor-dropdown.open .setor-dropdown-menu,.tipo-dropdown.open .tipo-dropdown-menu,.status-dropdown.open .status-dropdown-menu{display:block}
        .setor-dropdown-item,.tipo-dropdown-item,.status-dropdown-item{display:flex;align-items:center;gap:6px;padding:3px 4px;font-size:12px;cursor:pointer;border-radius:4px}
        .setor-dropdown-item:hover,.tipo-dropdown-item:hover,.status-dropdown-item:hover{background:#f7f7f7}
        .setor-dropdown-item input[type="checkbox"]{accent-color:#2347c8;cursor:pointer}

        .toggle-btn{border-radius:999px;padding:3px 10px;font-size:12px;border:1px solid;background:#fff;cursor:pointer;display:inline-flex;align-items:center;gap:6px;height:28px;transition:background .15s,color .15s}
        .toggle-btn-blue{border-color:#3498db;color:#3498db}
        .toggle-btn-blue.is-on{background:#3498db;color:#fff}
        .toggle-btn-orange{border-color:#e67e22;color:#e67e22}
        .toggle-btn-orange.is-on{background:#e67e22;color:#fff}

        .perm-grupos{margin-top:14px;border-top:1px solid #eee;padding-top:10px}
        .perm-grupos-title{font-size:11px;text-transform:uppercase;color:#999;font-weight:700;margin-bottom:8px;letter-spacing:.05em}
        .perm-grupo-card{border:1px solid #eee;border-radius:10px;margin-bottom:8px;overflow:hidden}
        .perm-grupo-header{padding:8px 12px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;font-size:12px;font-weight:600;background:#fafafa;user-select:none}
        .perm-grupo-header:hover{background:#f0f0f0}
        .perm-grupo-header-left{display:flex;align-items:center;gap:7px}
        .perm-grupo-body{padding:6px 12px 8px;display:block;border-top:1px solid #f0f0f0}
        .perm-grupo-card.closed .perm-grupo-body{display:none}
        .perm-toggle-row{display:flex;align-items:center;justify-content:space-between;font-size:12px;padding:5px 0;border-bottom:1px solid #f7f7f7}
        .perm-toggle-row:last-child{border-bottom:none}
        .perm-toggle-row span:first-child{color:#444}
        .perm-toggle-switch input[type="checkbox"]{accent-color:#2347c8;width:14px;height:14px;cursor:pointer}
        .perm-toggle-row span.perm-sub{color:#999;padding-left:10px}
        .perm-grupo-bulk{font-size:10px;color:#aaa;cursor:pointer;text-decoration:underline;margin-left:6px}
        .perm-grupo-bulk:hover{color:var(--red)}

        .right-footer{margin-top:14px;display:flex;justify-content:space-between;align-items:center;font-size:12px;padding-top:10px;border-top:1px solid #eee}
        .btn-reset{background:#fff;color:#e67e22;border:1px solid #e67e22;border-radius:999px;padding:8px 20px;font-size:13px;font-weight:700;cursor:pointer;transition:background .15s,color .15s}
        .btn-reset:hover{background:#e67e22;color:#fff}
        .btn-salvar{background:#2347c8;color:#fff;border:none;border-radius:999px;padding:8px 20px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(35,71,200,.3)}
        .btn-salvar:hover{background:#cc0000}
        .alert-erro{display:none;background:#fdecea;color:#c0392b;padding:6px 10px;border-radius:8px;font-size:12px;margin-bottom:8px}
        .alert-ok{display:none;background:#e8f5e9;color:#2e7d32;padding:6px 10px;border-radius:8px;font-size:12px;margin-bottom:8px}

        .app-version-label{position:fixed;bottom:4px;right:8px;font-size:10px;color:#888;opacity:.6;pointer-events:none;z-index:9999}
    </style>
</head>
<body>

<div class="page-header">
    <div>
        <h2><i class="fas fa-users"></i> Gerenciar Usuários</h2>
        <p>Clique em um usuário para editar dados e permissões de menu</p>
    </div>
    <a href="cadastro_usuario.php" class="btn-novo">+ NOVO USUÁRIO</a>
</div>

<div class="layout">

    <div class="users-panel">
        <div class="users-header">
            <span><i class="fa fa-users"></i> Usuários</span>
            <span class="users-count" id="users-count"><?= count($usuarios) ?></span>
        </div>
        <div class="users-search">
            <input type="text" id="search-usuarios" placeholder="Buscar usuário..." oninput="filtrarUsuarios(this.value)">
        </div>
        <div class="users-list" id="users-list">
            <?php foreach ($usuarios as $u):
                $initials = mb_strtoupper(mb_substr($u['nome'], 0, 1));
                $parts = preg_split('/\s+/', trim($u['nome']));
                if (count($parts) > 1) $initials .= mb_strtoupper(mb_substr(end($parts), 0, 1));
                $nomeSafe = htmlspecialchars($u['nome'], ENT_QUOTES, 'UTF-8');
            ?>
            <div class="user-item<?= !$u['is_ativo'] ? ' inativo' : '' ?>"
                 id="user-item-<?= (int)$u['id'] ?>"
                 data-nome="<?= strtolower($nomeSafe) ?>"
                 onclick="selecionarUsuario(<?= (int)$u['id'] ?>, '<?= $nomeSafe ?>')">
                <div class="user-avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="user-meta">
                    <div class="user-name"><?= $nomeSafe ?></div>
                    <div class="user-email"><?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="user-status" style="display:flex;gap:4px;flex-wrap:wrap;margin-top:1px">
                        <?= $u['is_ativo']
                            ? '<span class="badge badge-green">Ativo</span>'
                            : '<span class="badge badge-gray">Inativo</span>' ?>
                        <?php if (!empty($u['can_edit_acessorios'])): ?>
                        <span class="badge badge-acc" title="Pode editar acessórios">Acessórios</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="right-panel" id="right-panel">
        <div class="right-empty">
            <i class="fa fa-user-cog fa-2x" style="color:#ddd;"></i>
            <span>Selecione um usuário para editar dados e permissões</span>
        </div>
    </div>

</div>

<div class="app-version-label">
    <?= htmlspecialchars(VERSAO_GERENCIAR_USUARIOS, ENT_QUOTES, 'UTF-8') ?>
</div>

<template id="tpl-user-panel">
    <div>
        <div class="user-form-header">
            <div>
                <div class="user-form-title" id="up-nome"></div>
                <div class="user-form-subtitle" id="up-subtitle"></div>
            </div>
        </div>

        <div id="up-erro" class="alert-erro"></div>
        <div id="up-ok"   class="alert-ok"></div>

        <div class="user-form-grid">
            <div class="form-group">
                <label>CPF</label>
                <input type="text" id="up-cpf" readonly>
            </div>
            <div class="form-group">
                <label>E-mail</label>
                <input type="email" id="up-email" readonly>
            </div>
            <div class="form-group">
                <label>Telefone</label>
                <input type="tel" id="up-telefone" maxlength="20" class="editavel">
            </div>
            <div class="form-group">
                <label>Loja</label>
                <input type="text" id="up-loja" readonly>
            </div>

            <div class="form-group">
                <label>Setor (máx. 2)</label>
                <div class="setor-dropdown" id="up-setor-dropdown">
                    <div class="setor-dropdown-toggle">
                        <div class="setor-chips"></div>
                        <i class="fas fa-chevron-down dropdown-icon"></i>
                    </div>
                    <div class="setor-dropdown-menu"></div>
                </div>
            </div>

            <div class="form-group">
                <label>Tipo</label>
                <div class="tipo-dropdown" id="up-tipo-dropdown">
                    <div class="tipo-dropdown-toggle">
                        <span id="up-tipo-badge" class="pill-badge pill-user">USUÁRIO</span>
                        <i class="fas fa-chevron-down dropdown-icon"></i>
                    </div>
                    <div class="tipo-dropdown-menu">
                        <div class="tipo-dropdown-item" data-value="ADMIN">
                            <span class="pill-badge pill-admin">ADMIN</span><span>Administrador</span>
                        </div>
                        <div class="tipo-dropdown-item" data-value="USER">
                            <span class="pill-badge pill-user">USUÁRIO</span><span>Usuário padrão</span>
                        </div>
                    </div>
                </div>
                <input type="hidden" id="up-tipo-hidden" value="USER">
            </div>

            <div class="form-group">
                <label>Gestor</label>
                <button type="button" class="toggle-btn toggle-btn-blue" id="up-gestor-btn">
                    <i class="fas fa-user-tie"></i>
                    <span id="up-gestor-text">Não gestor</span>
                </button>
                <input type="hidden" id="up-gestor-hidden" value="0">
            </div>

            <div class="form-group">
                <label>Acessórios</label>
                <button type="button" class="toggle-btn toggle-btn-orange" id="up-acc-btn">
                    <i class="fas fa-tools"></i>
                    <span id="up-acc-text">Só visualizar</span>
                </button>
                <input type="hidden" id="up-acc-hidden" value="0">
                <div style="font-size:10px;color:#aaa;margin-top:3px">Permite adicionar e editar itens</div>
            </div>

            <div class="form-group">
                <label>Status</label>
                <div class="status-dropdown" id="up-status-dropdown">
                    <div class="status-dropdown-toggle">
                        <span id="up-status-badge" class="pill-badge" style="background:#27ae60;color:#fff;">ATIVO</span>
                        <i class="fas fa-chevron-down dropdown-icon"></i>
                    </div>
                    <div class="status-dropdown-menu">
                        <div class="status-dropdown-item" data-value="1">
                            <span class="pill-badge" style="background:#27ae60;color:#fff;">ATIVO</span>
                        </div>
                        <div class="status-dropdown-item" data-value="0">
                            <span class="pill-badge" style="background:#c0392b;color:#fff;">INATIVO</span>
                        </div>
                    </div>
                </div>
                <input type="hidden" id="up-status-hidden" value="1">
            </div>
        </div>

        <div class="perm-grupos" id="up-menu-grupos">
            <div class="perm-grupos-title">Permissões de menu</div>
        </div>

        <div class="right-footer">
            <button type="button" class="btn-reset" onclick="abrirModalReset()">
                <i class="fas fa-key" style="margin-right:5px;"></i>Resetar Senha
            </button>
            <div style="display:flex;gap:8px;align-items:center;">
                <div id="up-ok-inline" style="display:none;font-size:12px;color:#2e7d32;"></div>
                <button class="btn-salvar" onclick="salvarUsuario()">
                    <i class="fas fa-save" style="margin-right:5px;"></i>Salvar Usuário
                </button>
            </div>
        </div>
    </div>
</template>

<!-- Modal reset de senha -->
<div id="overlay-reset" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:50;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:24px 26px;width:100%;max-width:400px;box-shadow:0 20px 40px rgba(0,0,0,.25);">
    <h3 style="margin:0 0 6px;font-size:16px;display:flex;align-items:center;gap:8px;">
      <i class="fas fa-key" style="color:#e67e22;"></i> Resetar Senha
    </h3>
    <p id="reset-nome-label" style="font-size:13px;color:#777;margin-bottom:14px;"></p>

    <div style="margin-bottom:14px;">
      <label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;color:#777;margin-bottom:4px;">Nova Senha</label>
      <div style="position:relative;">
        <input type="password" id="reset-senha1" placeholder="Digite a nova senha..."
               style="width:100%;height:40px;border-radius:8px;border:1px solid #ddd;padding:0 40px 0 12px;font-size:15px;"
               onkeydown="if(event.key==='Enter') confirmarReset()">
        <span onclick="toggleVerSenha('reset-senha1',this)"
              style="position:absolute;right:10px;top:50%;transform:translateY(-50%);cursor:pointer;color:#aaa;font-size:14px;">👁</span>
      </div>
    </div>

    <div id="reset-result" style="display:none;margin-bottom:14px;padding:12px 14px;border-radius:10px;background:#e8f5e9;color:#166534;border:1px solid #bbf7d0;font-size:13px;"></div>
    <div id="reset-erro"   style="display:none;margin-bottom:14px;padding:10px 14px;border-radius:8px;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;font-size:13px;"></div>

    <div id="reset-acoes" style="display:flex;justify-content:flex-end;gap:8px;">
      <button onclick="fecharModalReset()" style="padding:8px 18px;border-radius:8px;border:1px solid #ddd;background:#fff;cursor:pointer;font-size:13px;">Cancelar</button>
      <button id="btn-confirmar-reset" onclick="confirmarReset()"
              style="padding:8px 18px;border-radius:8px;border:none;background:#e67e22;color:#fff;font-weight:700;cursor:pointer;font-size:13px;display:inline-flex;align-items:center;gap:6px;">
        <i class="fas fa-key"></i> Salvar Senha
      </button>
    </div>
  </div>
</div>

<script>
const opcoesSetorGlobais = <?= json_encode($opcoesSetorGlobais, JSON_UNESCAPED_UNICODE) ?>;
const sidebarGrupos      = <?= json_encode(listarSidebarPermissoes(), JSON_UNESCAPED_UNICODE) ?>;
const treinamentosPorSetor = <?= json_encode($treinamentosPorSetor, JSON_UNESCAPED_UNICODE) ?>;
let usuarioAtivoId = null;

function filtrarUsuarios(q) {
    q = q.toLowerCase().trim();
    let visiveis = 0;
    document.querySelectorAll('.user-item').forEach(el => {
        const nome = el.dataset.nome || '';
        const show = !q || nome.includes(q);
        el.style.display = show ? '' : 'none';
        if (show) visiveis++;
    });
    document.getElementById('users-count').textContent = visiveis;
}

async function selecionarUsuario(id, nome) {
    document.querySelectorAll('.user-item').forEach(el => el.classList.remove('selecionado'));
    const el = document.getElementById('user-item-' + id);
    if (el) el.classList.add('selecionado');
    usuarioAtivoId = id;

    const panel = document.getElementById('right-panel');
    panel.innerHTML = '<div class="right-empty"><i class="fas fa-spinner fa-spin" style="color:#ddd;font-size:1.5rem;"></i><span>Carregando...</span></div>';

    try {
        const r     = await fetch('gerenciar_usuarios.php?user_dados=' + id, { headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const dados = await r.json();

        const tpl = document.getElementById('tpl-user-panel');
        panel.innerHTML = '';
        panel.appendChild(tpl.content.cloneNode(true));

        document.getElementById('up-nome').textContent     = dados.nome || '';
        document.getElementById('up-subtitle').textContent = (dados.email || '') + ' · ' + (dados.cpf || '');
        document.getElementById('up-cpf').value            = dados.cpf || '';
        document.getElementById('up-email').value          = dados.email || '';
        document.getElementById('up-telefone').value       = dados.telefone || '';
        document.getElementById('up-loja').value           = dados.loja_nome || '';

        montarDropdownSetor('up-setor-dropdown', (dados.setor || '').split(',').map(s => s.trim()).filter(Boolean));

        const ehAdmin   = parseInt(dados.is_admin || '0') === 1;
        const tipoBadge = document.getElementById('up-tipo-badge');
        document.getElementById('up-tipo-hidden').value = ehAdmin ? 'ADMIN' : 'USER';
        tipoBadge.textContent = ehAdmin ? 'ADMIN' : 'USUÁRIO';
        tipoBadge.className   = 'pill-badge ' + (ehAdmin ? 'pill-admin' : 'pill-user');
        configurarDropdownTipo();

        const ehGestor     = parseInt(dados.is_gestor || '0') === 1;
        const btnGestor    = document.getElementById('up-gestor-btn');
        const hiddenGestor = document.getElementById('up-gestor-hidden');
        hiddenGestor.value = ehGestor ? '1' : '0';
        atualizarToggleBtn(btnGestor, document.getElementById('up-gestor-text'), ehGestor, 'Gestor', 'Não gestor');
        btnGestor.addEventListener('click', () => {
            const novo = hiddenGestor.value !== '1';
            hiddenGestor.value = novo ? '1' : '0';
            atualizarToggleBtn(btnGestor, document.getElementById('up-gestor-text'), novo, 'Gestor', 'Não gestor');
        });

        const podeEditar = parseInt(dados.can_edit_acessorios || '0') === 1;
        const btnAcc     = document.getElementById('up-acc-btn');
        const hiddenAcc  = document.getElementById('up-acc-hidden');
        hiddenAcc.value  = podeEditar ? '1' : '0';
        atualizarToggleBtn(btnAcc, document.getElementById('up-acc-text'), podeEditar, 'Pode editar', 'Só visualizar');
        btnAcc.addEventListener('click', () => {
            const novo = hiddenAcc.value !== '1';
            hiddenAcc.value = novo ? '1' : '0';
            atualizarToggleBtn(btnAcc, document.getElementById('up-acc-text'), novo, 'Pode editar', 'Só visualizar');
        });

        const ativo = parseInt(dados.is_ativo || '1') === 1;
        document.getElementById('up-status-hidden').value = ativo ? '1' : '0';
        const sb = document.getElementById('up-status-badge');
        sb.textContent      = ativo ? 'ATIVO' : 'INATIVO';
        sb.style.background = ativo ? '#27ae60' : '#c0392b';
        configurarDropdownStatus();

        montarPermissoesMenu(dados);

    } catch(e) {
        panel.innerHTML = '<div class="right-empty"><span style="color:#c00;">Erro ao carregar: ' + e.message + '</span></div>';
    }
}

function atualizarToggleBtn(btn, spanTexto, ativo, labelOn, labelOff) {
    btn.classList.toggle('is-on', ativo);
    spanTexto.textContent = ativo ? labelOn : labelOff;
}

function montarDropdownSetor(idDropdown, setoresUsuario) {
    const dropdown = document.getElementById(idDropdown);
    const chips    = dropdown.querySelector('.setor-chips');
    const menu     = dropdown.querySelector('.setor-dropdown-menu');
    chips.innerHTML = ''; menu.innerHTML = '';

    Object.entries(opcoesSetorGlobais).forEach(([valor, rotulo]) => {
        const lbl = document.createElement('label');
        lbl.className = 'setor-dropdown-item';
        lbl.innerHTML = `<input type="checkbox" class="setor-checkbox" value="${valor}"><span>${rotulo}</span>`;
        if (setoresUsuario.includes(valor)) lbl.querySelector('input').checked = true;
        menu.appendChild(lbl);
    });

    const atualizar = () => {
        const marcados = Array.from(dropdown.querySelectorAll('.setor-checkbox:checked'));
        chips.innerHTML = '';
        if (!marcados.length) {
            chips.innerHTML = '<span class="setor-placeholder">Selecionar setores</span>';
            return;
        }
        marcados.forEach(cb => {
            const span = document.createElement('span');
            span.className   = 'setor-chip';
            span.textContent = cb.parentElement.querySelector('span').textContent;
            chips.appendChild(span);
        });
    };

    dropdown.querySelector('.setor-dropdown-toggle').addEventListener('click', e => {
        e.stopPropagation();
        document.querySelectorAll('.setor-dropdown.open').forEach(d => { if (d !== dropdown) d.classList.remove('open'); });
        dropdown.classList.toggle('open');
    });
    dropdown.addEventListener('change', e => {
        if (!e.target.classList.contains('setor-checkbox')) return;
        if (Array.from(dropdown.querySelectorAll('.setor-checkbox:checked')).length > 2) {
            e.target.checked = false;
            alert('Selecione no máximo 2 setores.');
            return;
        }
        atualizar();
    });
    atualizar();
}

function configurarDropdownTipo() {
    const dd     = document.getElementById('up-tipo-dropdown');
    const badge  = document.getElementById('up-tipo-badge');
    const hidden = document.getElementById('up-tipo-hidden');
    dd.querySelector('.tipo-dropdown-toggle').addEventListener('click', e => {
        e.stopPropagation();
        document.querySelectorAll('.tipo-dropdown.open').forEach(d => { if (d !== dd) d.classList.remove('open'); });
        dd.classList.toggle('open');
    });
    dd.querySelectorAll('.tipo-dropdown-item').forEach(item => {
        item.addEventListener('click', () => {
            const v      = item.dataset.value;
            hidden.value = v;
            badge.textContent = v === 'ADMIN' ? 'ADMIN' : 'USUÁRIO';
            badge.className   = 'pill-badge ' + (v === 'ADMIN' ? 'pill-admin' : 'pill-user');
            dd.classList.remove('open');
        });
    });
}

function configurarDropdownStatus() {
    const dd     = document.getElementById('up-status-dropdown');
    const badge  = document.getElementById('up-status-badge');
    const hidden = document.getElementById('up-status-hidden');
    dd.querySelector('.status-dropdown-toggle').addEventListener('click', e => {
        e.stopPropagation();
        document.querySelectorAll('.status-dropdown.open').forEach(d => { if (d !== dd) d.classList.remove('open'); });
        dd.classList.toggle('open');
    });
    dd.querySelectorAll('.status-dropdown-item').forEach(item => {
        item.addEventListener('click', () => {
            const v      = item.dataset.value;
            hidden.value = v;
            badge.textContent      = v === '1' ? 'ATIVO' : 'INATIVO';
            badge.style.background = v === '1' ? '#27ae60' : '#c0392b';
            dd.classList.remove('open');
        });
    });
}

function montarPermissoesMenu(dados) {
    const container = document.getElementById('up-menu-grupos');
    const titulo    = container.querySelector('.perm-grupos-title');
    container.innerHTML = '';
    if (titulo) container.appendChild(titulo);

    const perms = dados.permissoes || {};

    Object.entries(sidebarGrupos).forEach(([grupoKey, grupo]) => {
        const card     = document.createElement('div');
        card.className = 'perm-grupo-card';
        const permKeys = Object.keys(grupo.items);
        const todosOn  = permKeys.every(k => (perms[k] ?? 0) == 1);

        card.innerHTML = `
            <div class="perm-grupo-header" onclick="this.parentElement.classList.toggle('closed')">
                <div class="perm-grupo-header-left">
                    <i class="${grupo.icon}" style="color:#c8102e;font-size:.9rem;"></i>
                    <span>${grupo.label}</span>
                    <span class="perm-grupo-bulk" data-grupo="${grupoKey}" onclick="toggleGrupo(event,'${grupoKey}')">
                        ${todosOn ? 'desmarcar todos' : 'marcar todos'}
                    </span>
                </div>
                <i class="fa fa-chevron-down" style="font-size:.7rem;color:#aaa;"></i>
            </div>
            <div class="perm-grupo-body"></div>`;

        const body = card.querySelector('.perm-grupo-body');
        Object.entries(grupo.items).forEach(([permKey, permLabel]) => {
            const checked = (perms[permKey] ?? 0) == 1;
            const isSub   = permLabel.startsWith('→');
            const row     = document.createElement('label');
            row.className = 'perm-toggle-row';
            row.innerHTML = `
                <span class="${isSub ? 'perm-sub' : ''}">${permLabel}</span>
                <span class="perm-toggle-switch">
                    <input type="checkbox" id="perm-${permKey}" data-perm="${permKey}" ${checked ? 'checked' : ''}>
                </span>`;
            body.appendChild(row);
        });

        container.appendChild(card);
    });

    if (treinamentosPorSetor.length > 0) {
        const treinLiberados = dados.treinamentos_liberados || [];

        const tituloTrein = document.createElement('div');
        tituloTrein.style.cssText = 'font-size:11px;font-weight:700;color:#999;text-transform:uppercase;letter-spacing:.05em;margin:14px 0 6px;';
        tituloTrein.textContent = 'Treinamentos visíveis';
        container.appendChild(tituloTrein);

        treinamentosPorSetor.forEach(setor => {
            const itens = setor.treinamentos || [];
            if (itens.length === 0) return;

            const cardSetor    = document.createElement('div');
            cardSetor.className = 'perm-grupo-card';
            const todosOnSetor = itens.every(t => treinLiberados.includes(t.id));

            cardSetor.innerHTML = `
                <div class="perm-grupo-header" onclick="this.parentElement.classList.toggle('closed')">
                    <div class="perm-grupo-header-left">
                        <i class="fa fa-folder" style="color:#c8102e;font-size:.9rem;"></i>
                        <span>${setor.setor || setor.pasta}</span>
                        <span class="perm-grupo-bulk" onclick="toggleTreinPasta(event, ${setor.id})">
                            ${todosOnSetor ? 'desmarcar todos' : 'marcar todos'}
                        </span>
                    </div>
                    <i class="fa fa-chevron-down" style="font-size:.7rem;color:#aaa;"></i>
                </div>
                <div class="perm-grupo-body"></div>`;

            const body = cardSetor.querySelector('.perm-grupo-body');
            itens.forEach(t => {
                const checked = treinLiberados.includes(t.id);
                const row     = document.createElement('label');
                row.className = 'perm-toggle-row';
                row.innerHTML = `
                    <span>${t.subpasta ? `<small style="color:#999">${t.subpasta} — </small>` : ''}${t.titulo}</span>
                    <span class="perm-toggle-switch">
                        <input type="checkbox" class="trein-item-cb" data-setor-id="${setor.id}" data-treinamento-id="${t.id}" ${checked ? 'checked' : ''}>
                    </span>`;
                body.appendChild(row);
            });

            container.appendChild(cardSetor);
        });
    }
}

function toggleTreinPasta(e, setorId) {
    e.stopPropagation();
    const cbs     = document.querySelectorAll(`.trein-item-cb[data-setor-id="${setorId}"]`);
    const algumOn = Array.from(cbs).some(cb => cb.checked);
    cbs.forEach(cb => cb.checked = !algumOn);
    e.target.textContent = algumOn ? 'marcar todos' : 'desmarcar todos';
}

function toggleGrupo(e, grupoKey) {
    e.stopPropagation();
    const grupo = sidebarGrupos[grupoKey];
    if (!grupo) return;
    const keys    = Object.keys(grupo.items);
    const algumOn = keys.some(k => document.getElementById('perm-' + k)?.checked);
    keys.forEach(k => {
        const cb = document.getElementById('perm-' + k);
        if (cb) cb.checked = !algumOn;
    });
    e.target.textContent = algumOn ? 'marcar todos' : 'desmarcar todos';
}

document.addEventListener('click', () => {
    document.querySelectorAll('.setor-dropdown.open,.tipo-dropdown.open,.status-dropdown.open')
            .forEach(d => d.classList.remove('open'));
});

async function salvarUsuario() {
    if (!usuarioAtivoId) return;
    const erroEl = document.getElementById('up-erro');
    const okEl   = document.getElementById('up-ok');
    erroEl.style.display = 'none';
    okEl.style.display   = 'none';

    const fd = new FormData();
    fd.append('salvar_usuario',      '1');
    fd.append('id_usuario',          usuarioAtivoId);
    fd.append('tipo_usuario',        document.getElementById('up-tipo-hidden').value);
    fd.append('is_gestor',           document.getElementById('up-gestor-hidden').value);
    fd.append('is_ativo',            document.getElementById('up-status-hidden').value);
    fd.append('can_edit_acessorios', document.getElementById('up-acc-hidden').value);
    fd.append('telefone',            document.getElementById('up-telefone').value.trim());

    document.querySelectorAll('#up-setor-dropdown .setor-checkbox:checked')
            .forEach(cb => fd.append('setor[]', cb.value));

    document.querySelectorAll('#up-menu-grupos input[type="checkbox"][id^="perm-"]')
            .forEach(cb => { if (cb.checked) fd.append(`perms[${cb.id.replace('perm-','')}]`, '1'); });

    document.querySelectorAll('.trein-item-cb:checked')
            .forEach(cb => fd.append('treinamentos[]', cb.dataset.treinamentoId));

    try {
        const r = await fetch('gerenciar_usuarios.php', {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: fd
        });
        const d = await r.json();

        if (!d.ok) {
            erroEl.textContent   = d.msg || 'Erro ao salvar.';
            erroEl.style.display = 'block';
        } else {
            okEl.textContent   = d.msg || 'Salvo com sucesso.';
            okEl.style.display = 'block';
            setTimeout(() => { okEl.style.display = 'none'; }, 3000);

            const item = document.getElementById('user-item-' + usuarioAtivoId);
            if (item) {
                const ativo      = document.getElementById('up-status-hidden').value === '1';
                const podeEditar = document.getElementById('up-acc-hidden').value === '1';
                const statusDiv  = item.querySelector('.user-status');
                statusDiv.innerHTML =
                    (ativo
                        ? '<span class="badge badge-green">Ativo</span>'
                        : '<span class="badge badge-gray">Inativo</span>') +
                    (podeEditar
                        ? '<span class="badge badge-acc" title="Pode editar acessórios">Acessórios</span>'
                        : '');
                item.classList.toggle('inativo', !ativo);
            }
        }
    } catch (e) {
        erroEl.textContent   = 'Erro: ' + e.message;
        erroEl.style.display = 'block';
    }
}

// ── Reset de senha ─────────────────────────────────────────────────────────
function toggleVerSenha(inputId, icon) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') { input.type = 'text'; icon.textContent = '🙈'; }
    else { input.type = 'password'; icon.textContent = '👁'; }
}

function abrirModalReset() {
    if (!usuarioAtivoId) return;
    const nome = document.getElementById('up-nome')?.textContent || 'este usuário';
    document.getElementById('reset-nome-label').textContent = 'Definir nova senha para: ' + nome;
    document.getElementById('reset-senha1').value = '';
    document.getElementById('reset-result').style.display = 'none';
    document.getElementById('reset-erro').style.display   = 'none';
    document.getElementById('reset-acoes').style.display  = 'flex';
    const btn = document.getElementById('btn-confirmar-reset');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-key"></i> Salvar Senha';
    document.getElementById('overlay-reset').style.display = 'flex';
    setTimeout(() => document.getElementById('reset-senha1').focus(), 50);
}

function fecharModalReset() {
    document.getElementById('overlay-reset').style.display = 'none';
}

async function confirmarReset() {
    const s1     = document.getElementById('reset-senha1').value.trim();
    const btn    = document.getElementById('btn-confirmar-reset');
    const erroEl = document.getElementById('reset-erro');

    erroEl.style.display = 'none';

    if (s1.length < 4) { erroEl.textContent = 'A senha deve ter no mínimo 4 caracteres.'; erroEl.style.display = 'block'; return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

    const fd = new FormData();
    fd.append('resetar_senha', '1');
    fd.append('id_usuario',    usuarioAtivoId);
    fd.append('nova_senha',    s1);

    try {
        const r = await fetch('gerenciar_usuarios.php', {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: fd
        });
        const d = await r.json();

        if (d.ok) {
            document.getElementById('reset-result').innerHTML = '<strong>✓ Senha redefinida com sucesso!</strong>';
            document.getElementById('reset-result').style.display = 'block';
            document.getElementById('reset-acoes').innerHTML =
                '<button onclick="fecharModalReset()" style="padding:8px 22px;border-radius:8px;border:1px solid #ddd;background:#fff;cursor:pointer;font-size:13px;font-weight:600;">Fechar</button>';
        } else {
            erroEl.textContent = d.msg || 'Erro ao redefinir senha.';
            erroEl.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-key"></i> Salvar Senha';
        }
    } catch(e) {
        erroEl.textContent = 'Falha na comunicação: ' + e.message;
        erroEl.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-key"></i> Salvar Senha';
    }
}

document.getElementById('overlay-reset').addEventListener('click', function(e) {
    if (e.target === this) fecharModalReset();
});
</script>
</body>
</html>