<?php
session_start();
require_once __DIR__ . '/../includes/admin_provisionamento.php';
require_once __DIR__ . '/../includes/veiculos_permissoes.php';
require_once __DIR__ . '/../includes/compras_permissoes.php';

if (empty($_SESSION['portal_logado']) || empty($_SESSION['portal_is_admin'])) {
    header('Location: ../login.php');
    exit;
}

$sistemas = carregar_todos_sistemas();
$erro     = '';
$sucesso  = '';

$filiais    = [];
$setores    = [];
$avisoBanco = '';
if (isset($sistemas['compras'])) {
    try {
        $pdoCompras = conectar_sistema($sistemas['compras']['db']);
        $filiais = $pdoCompras->query('SELECT id, nome FROM filiais ORDER BY nome')->fetchAll();
        $setores = $pdoCompras->query('SELECT id, nome FROM setores ORDER BY nome')->fetchAll();
    } catch (\PDOException $e) {
        error_log('Falha ao conectar no banco do Compras (filiais/setores): ' . $e->getMessage());
        $avisoBanco = 'Não foi possível conectar no banco do Compras agora — filial/setor e permissões desse sistema ficaram indisponíveis nesta tela.';
    }
}

$filiaisNotas = [];
$setoresNotas = [];
if (isset($sistemas['notas'])) {
    try {
        $pdoNotas = conectar_sistema($sistemas['notas']['db']);
        $filiaisNotas = $pdoNotas->query('SELECT id, nome FROM filiais ORDER BY nome')->fetchAll();
        $setoresNotas = $pdoNotas->query('SELECT id, nome FROM setores ORDER BY nome')->fetchAll();
    } catch (\PDOException $e) {
        error_log('Falha ao conectar no banco do Notas (filiais/setores): ' . $e->getMessage());
        $avisoBanco = 'Não foi possível conectar no banco do Notas agora — filial/setor desse sistema ficaram indisponíveis nesta tela.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome     = trim($_POST['nome'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $senha    = $_POST['senha'] ?? '';
    $marcados = array_keys($_POST['sistemas'] ?? []);

    if ($nome === '' || $email === '') {
        $erro = 'Preencha nome e e-mail.';
    } else {
        $sistemasIndisponiveis = [];
        try {
            foreach ($sistemas as $id => $cfg) {
                try {
                    $tinhaAntes = buscar_hash_atual($cfg, $email) !== null;
                } catch (\PDOException $e) {
                    error_log("Falha ao conectar no banco de '$id' ao salvar usuário: " . $e->getMessage());
                    $sistemasIndisponiveis[] = $cfg['nome'] ?? $id;
                    continue;
                }
                $marcadoAgora = in_array($id, $marcados, true);

                if ($marcadoAgora) {
                    if ($senha !== '') {
                        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
                    } elseif ($tinhaAntes) {
                        $senhaHash = buscar_hash_atual($cfg, $email);
                    } else {
                        throw new \RuntimeException("Informe uma senha: {$cfg['nome']} ainda não tem conta para esse e-mail.");
                    }

                    if ($id === 'estoque') {
                        provisionar_estoque($cfg, $email, $nome, $senhaHash, $_POST['estoque_cargo'] ?? 'filial');
                    } elseif ($id === 'compras') {
                        provisionar_compras(
                            $cfg,
                            $email,
                            $nome,
                            $senhaHash,
                            $_POST['compras_perfil'] ?? 'solicitante',
                            $_POST['compras_filial'] ?? '',
                            $_POST['compras_setor'] ?? ''
                        );
                    } elseif ($id === 'projeto') {
                        provisionar_projeto($cfg, $email, $nome, $senhaHash, $_POST['projeto_perfil'] ?? 'Membro');
                    } elseif ($id === 'veiculos') {
                        $cpf = trim($_POST['veiculos_cpf'] ?? '');
                        if (!$tinhaAntes && $cpf === '') {
                            throw new \RuntimeException("Informe o CPF: {$cfg['nome']} exige CPF pra criar uma conta nova.");
                        }
                        provisionar_veiculos($cfg, $email, $nome, $senhaHash, $cpf);
                    } elseif ($id === 'locadora') {
                        $role = trim($_POST['locadora_role'] ?? '') ?: 'Assistente de Frota';
                        provisionar_locadora($cfg, $email, $nome, $senhaHash, $role);
                    } elseif ($id === 'notas') {
                        provisionar_notas(
                            $cfg,
                            $email,
                            $nome,
                            $senhaHash,
                            $_POST['notas_perfil'] ?? 'solicitante',
                            $_POST['notas_filial'] ?? '',
                            $_POST['notas_setor'] ?? ''
                        );
                    } elseif ($id === 'inventario') {
                        $usuarioInventario = trim($_POST['inventario_usuario'] ?? '');
                        if (!$tinhaAntes && $usuarioInventario === '') {
                            throw new \RuntimeException("Informe o usuário: {$cfg['nome']} exige um usuário pra criar uma conta nova.");
                        }
                        provisionar_inventario(
                            $cfg,
                            $email,
                            $nome,
                            $senhaHash,
                            $_POST['inventario_perfil'] ?? 'gestor',
                            $usuarioInventario
                        );
                    } elseif ($id === 'imoveis') {
                        provisionar_imoveis($cfg, $email, $nome, $senhaHash, $_POST['imoveis_perfil'] ?? 'operador');
                    }

                    if ($id === 'veiculos') {
                        $extraVeiculos = buscar_veiculos_extra($cfg, $email);
                        if ($extraVeiculos) {
                            $marcadasPermissoes = array_keys($_POST['veiculos_perms'] ?? []);
                            salvar_permissoes_veiculos($cfg, (int)$extraVeiculos['id'], $marcadasPermissoes);
                        }
                    }

                    if ($id === 'compras') {
                        $extraCompras = buscar_compras_extra($cfg, $email);
                        if ($extraCompras) {
                            $niveisPost = $_POST['compras_perms'] ?? [];
                            $niveis     = [];
                            foreach (listar_modulos_compras() as $modulo => $label) {
                                $niveis[$modulo] = $niveisPost[$modulo] ?? 'nenhum';
                            }
                            salvar_permissoes_compras($cfg, $extraCompras['id'], $niveis);
                        }
                    }
                } elseif ($tinhaAntes) {
                    if ($id === 'estoque') {
                        revogar_estoque($cfg, $email);
                    } elseif ($id === 'compras') {
                        revogar_compras($cfg, $email);
                    } elseif ($id === 'projeto') {
                        revogar_projeto($cfg, $email);
                    } elseif ($id === 'veiculos') {
                        revogar_veiculos($cfg, $email);
                    } elseif ($id === 'locadora') {
                        revogar_locadora($cfg, $email);
                    } elseif ($id === 'notas') {
                        revogar_notas($cfg, $email);
                    } elseif ($id === 'inventario') {
                        revogar_inventario($cfg, $email);
                    } elseif ($id === 'imoveis') {
                        revogar_imoveis($cfg, $email);
                    }
                }
            }
            $sucesso = "Usuário $email salvo com sucesso.";
            if ($sistemasIndisponiveis) {
                $sucesso .= ' (Sistemas indisponíveis neste servidor, não alterados: ' . implode(', ', $sistemasIndisponiveis) . ')';
            }
        } catch (\RuntimeException $e) {
            $erro = $e->getMessage();
        } catch (\PDOException $e) {
            $erro = 'Erro ao gravar no banco: ' . $e->getMessage();
        }
    }
}

$usuarios = listar_usuarios_federado($sistemas);

$emailEditando = trim($_GET['editar'] ?? '');
$dadosEdicao   = $emailEditando !== '' ? ($usuarios[$emailEditando] ?? null) : null;

$comprasExtraEdicao = null;
$comprasPermsEdicao = [];
if (isset($sistemas['compras']) && $emailEditando !== '') {
    try {
        $comprasExtraEdicao = buscar_compras_extra($sistemas['compras'], $emailEditando);
        if ($comprasExtraEdicao) {
            $comprasPermsEdicao = buscar_permissoes_compras(
                $sistemas['compras'],
                $comprasExtraEdicao['id'],
                $comprasExtraEdicao['perfil'] ?? 'solicitante'
            );
        }
    } catch (\PDOException $e) {
        error_log('Falha ao conectar no banco do Compras (dados de edição): ' . $e->getMessage());
        $avisoBanco = 'Não foi possível conectar no banco do Compras agora — filial/setor e permissões desse sistema ficaram indisponíveis nesta tela.';
    }
}

$veiculosExtraEdicao = null;
$veiculosPermsEdicao = [];
if (isset($sistemas['veiculos']) && $emailEditando !== '') {
    try {
        $veiculosExtraEdicao = buscar_veiculos_extra($sistemas['veiculos'], $emailEditando);
        if ($veiculosExtraEdicao) {
            $veiculosPermsEdicao = buscar_permissoes_veiculos($sistemas['veiculos'], (int)$veiculosExtraEdicao['id']);
        }
    } catch (\PDOException $e) {
        error_log('Falha ao conectar no banco do Veículos (dados de edição): ' . $e->getMessage());
        $avisoBanco = 'Não foi possível conectar no banco do Veículos agora — CPF e permissões desse sistema ficaram indisponíveis nesta tela.';
    }
}

$locadoraExtraEdicao = null;
if (isset($sistemas['locadora']) && $emailEditando !== '') {
    try {
        $locadoraExtraEdicao = buscar_locadora_extra($sistemas['locadora'], $emailEditando);
    } catch (\PDOException $e) {
        error_log('Falha ao conectar no banco da Locadora (dados de edição): ' . $e->getMessage());
        $avisoBanco = 'Não foi possível conectar no banco da Locadora agora — dados desse sistema ficaram indisponíveis nesta tela.';
    }
}

$notasExtraEdicao = null;
if (isset($sistemas['notas']) && $emailEditando !== '') {
    try {
        $notasExtraEdicao = buscar_notas_extra($sistemas['notas'], $emailEditando);
    } catch (\PDOException $e) {
        error_log('Falha ao conectar no banco do Notas (dados de edição): ' . $e->getMessage());
        $avisoBanco = 'Não foi possível conectar no banco do Notas agora — filial/setor desse sistema ficaram indisponíveis nesta tela.';
    }
}

$inventarioExtraEdicao = null;
if (isset($sistemas['inventario']) && $emailEditando !== '') {
    try {
        $inventarioExtraEdicao = buscar_inventario_extra($sistemas['inventario'], $emailEditando);
    } catch (\PDOException $e) {
        error_log('Falha ao conectar no banco do Inventário (dados de edição): ' . $e->getMessage());
        $avisoBanco = 'Não foi possível conectar no banco do Inventário agora — dados desse sistema ficaram indisponíveis nesta tela.';
    }
}

$imoveisExtraEdicao = null;
if (isset($sistemas['imoveis']) && $emailEditando !== '') {
    try {
        $imoveisExtraEdicao = buscar_imoveis_extra($sistemas['imoveis'], $emailEditando);
    } catch (\PDOException $e) {
        error_log('Falha ao conectar no banco do Imóveis (dados de edição): ' . $e->getMessage());
        $avisoBanco = 'Não foi possível conectar no banco do Imóveis agora — dados desse sistema ficaram indisponíveis nesta tela.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Administração — Portal Único</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/design-system.css">
<style>
.page-title{font-family:var(--font-bold);font-size:var(--text-h1);color:var(--text-primary);margin-bottom:var(--space-5)}

.admin-layout{display:grid;grid-template-columns:300px 1fr;gap:var(--space-5);align-items:start}

.admin-sidebar{background:var(--color-surface);border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;position:sticky;top:calc(var(--space-6))}
.admin-sidebar__header{padding:var(--space-4);border-bottom:1px solid var(--color-gray-light);display:flex;flex-direction:column;gap:var(--space-3)}
.admin-sidebar__header input{width:100%;height:38px;border:1px solid var(--color-gray-light);border-radius:6px;padding:0 12px;font-family:var(--font-regular);font-size:var(--text-body-sm)}
.admin-sidebar__lista{max-height:640px;overflow-y:auto}
.usuario-item{display:block;padding:var(--space-3) var(--space-4);border-bottom:1px solid var(--color-gray-light);text-decoration:none;color:inherit}
.usuario-item:hover{background:var(--color-bg)}
.usuario-item.ativo{background:#eef2fd;border-left:3px solid var(--color-primary)}
.usuario-item__nome{font-family:var(--font-bold);font-size:var(--text-body-sm);color:var(--text-primary)}
.usuario-item__email{font-family:var(--font-regular);font-size:var(--text-caption);color:var(--text-muted);margin-bottom:6px;word-break:break-all}
.usuario-item__badges{display:flex;flex-wrap:wrap;gap:4px}
.mini-badge{font-size:9px;font-weight:700;padding:2px 6px;border-radius:4px}

.admin-detalhe{background:var(--color-surface);border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:var(--space-5)}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:var(--space-4)}

.tabs__strip{display:flex;gap:var(--space-2);border-bottom:2px solid var(--color-gray-light);margin:var(--space-5) 0 var(--space-4);flex-wrap:wrap}
.tab-btn{background:none;border:none;padding:10px 16px;font-family:var(--font-bold);font-size:var(--text-body-sm);color:var(--text-muted);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px}
.tab-btn.ativo{color:var(--color-primary);border-color:var(--color-primary)}
.tab-painel{display:none}
.tab-painel.ativo{display:block}
.sistema-bloco__head{display:flex;align-items:center;gap:10px;font-family:var(--font-bold);font-size:var(--text-body);color:var(--text-primary);margin-bottom:var(--space-4)}
.sistema-bloco__head input{width:16px;height:16px;accent-color:var(--color-primary)}

.permissoes-menu{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:var(--space-4);margin-top:var(--space-4)}
.permissoes-grupo{border:1px solid var(--color-gray-light);border-radius:8px;padding:var(--space-3)}
.permissoes-grupo__titulo{font-family:var(--font-bold);font-size:var(--text-caption);text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:var(--space-2)}
.permissoes-item{display:flex;align-items:center;gap:6px;font-size:var(--text-body-sm);padding:3px 0}

.permissoes-modulos{margin-top:var(--space-4);border:1px solid var(--color-gray-light);border-radius:8px}
.permissoes-modulo-linha{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-bottom:1px solid var(--color-gray-light);gap:var(--space-3)}
.permissoes-modulo-linha:last-child{border-bottom:none}
.permissoes-modulo-linha span{font-size:var(--text-body-sm)}
.permissoes-modulo-linha select{width:150px;flex-shrink:0}

.form-actions{margin-top:var(--space-6)}
.btn-novo{margin-bottom:var(--space-4)}
</style>
</head>
<body>
<nav class="topbar">
    <div class="topbar__brand">
        <img src="../assets/img/logo.svg" alt="Portal Único" style="height:36px;border-radius:8px">
        <div class="topbar__title">Administração<span>Usuários do portal</span></div>
    </div>
    <div class="topbar__nav">
        <a href="../index.php">Sistemas</a>
        <a href="usuarios.php" class="active">Administração</a>
        <a href="sistemas_sem_manifesto.php">Sistemas configurados</a>
    </div>
    <div class="topbar__right">
        <a href="../index.php" class="btn btn-tertiary" style="height:36px;padding:0 16px">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Voltar ao portal
        </a>
    </div>
</nav>

<div class="main-content">
    <h1 class="page-title">Usuários</h1>

    <?php if ($erro): ?>
    <div class="alert alert--danger" style="margin-bottom:var(--space-5)"><span><?= htmlspecialchars($erro) ?></span></div>
    <?php endif; ?>
    <?php if ($sucesso): ?>
    <div class="alert alert--success" style="margin-bottom:var(--space-5)"><span><?= htmlspecialchars($sucesso) ?></span></div>
    <?php endif; ?>
    <?php if ($avisoBanco): ?>
    <div class="alert alert--warning" style="margin-bottom:var(--space-5)"><span><?= htmlspecialchars($avisoBanco) ?></span></div>
    <?php endif; ?>

    <div class="admin-layout">
        <aside class="admin-sidebar">
            <div class="admin-sidebar__header">
                <a href="usuarios.php" class="btn btn-primary btn-novo">+ Novo usuário</a>
                <input type="text" id="buscaUsuario" placeholder="Buscar usuário..." oninput="filtrarUsuarios()">
            </div>
            <div class="admin-sidebar__lista">
                <?php foreach ($usuarios as $email => $u): ?>
                <a href="usuarios.php?editar=<?= urlencode($email) ?>"
                   class="usuario-item<?= $emailEditando === $email ? ' ativo' : '' ?>"
                   data-busca="<?= htmlspecialchars(strtolower($u['nome'] . ' ' . $email)) ?>">
                    <div class="usuario-item__nome"><?= htmlspecialchars($u['nome']) ?></div>
                    <div class="usuario-item__email"><?= htmlspecialchars($email) ?></div>
                    <div class="usuario-item__badges">
                        <?php foreach ($u['sistemas'] as $sid => $info): ?>
                        <span class="mini-badge" style="background:<?= htmlspecialchars($sistemas[$sid]['cor_bg'] ?? '#eee') ?>;color:<?= htmlspecialchars($sistemas[$sid]['cor'] ?? '#888') ?>">
                            <?= htmlspecialchars($sistemas[$sid]['nome'] ?? $sid) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="admin-detalhe">
            <h2 class="card-title" style="font-family:var(--font-bold);font-size:var(--text-h3);margin-bottom:var(--space-2)">
                <?= $dadosEdicao ? 'Editar usuário' : 'Criar usuário' ?>
            </h2>

            <form method="post">
                <div class="form-grid">
                    <div class="form-field">
                        <label class="form-label">Nome</label>
                        <input class="form-input" type="text" name="nome" value="<?= htmlspecialchars($dadosEdicao['nome'] ?? '') ?>" required>
                    </div>
                    <div class="form-field">
                        <label class="form-label">E-mail</label>
                        <input class="form-input" type="email" name="email" value="<?= htmlspecialchars($emailEditando) ?>"
                               <?= $dadosEdicao ? 'readonly' : '' ?> required>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Senha</label>
                        <input class="form-input" type="password" name="senha" placeholder="<?= $dadosEdicao ? 'Manter atual' : '' ?>">
                        <p class="form-hint">Em branco mantém a senha atual, se já existir em algum sistema marcado.</p>
                    </div>
                </div>

                <div class="tabs__strip">
                    <?php foreach ($sistemas as $id => $cfg): ?>
                    <button type="button" class="tab-btn" data-tab="<?= $id ?>" onclick="mostrarAba('<?= $id ?>')"><?= htmlspecialchars($cfg['nome']) ?></button>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($sistemas as $id => $cfg):
                    $sistemaAtual   = $dadosEdicao['sistemas'][$id] ?? null;
                    $marcadoInicial = $sistemaAtual && $sistemaAtual['ativo'];
                    $papelAtual     = $sistemaAtual['papel'] ?? null;
                ?>
                <div class="tab-painel" data-tab-painel="<?= $id ?>">
                    <label class="sistema-bloco__head">
                        <input type="checkbox" name="sistemas[<?= $id ?>]" value="1" <?= $marcadoInicial ? 'checked' : '' ?>>
                        Tem conta em <?= htmlspecialchars($cfg['nome']) ?>
                    </label>

                    <?php if ($id === 'estoque'): ?>
                        <div class="form-field" style="max-width:260px">
                            <label class="form-label">Cargo</label>
                            <select class="form-select" name="estoque_cargo">
                                <?php foreach ($cfg['roles_disponiveis'] as $r): ?>
                                <option value="<?= htmlspecialchars($r) ?>" <?= $papelAtual === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    <?php elseif ($id === 'compras'): ?>
                        <div class="form-grid">
                            <div class="form-field">
                                <label class="form-label">Perfil</label>
                                <select class="form-select" name="compras_perfil">
                                    <?php foreach ($cfg['roles_disponiveis'] as $r): ?>
                                    <option value="<?= htmlspecialchars($r) ?>" <?= $papelAtual === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-field">
                                <label class="form-label">Filial</label>
                                <select class="form-select" name="compras_filial">
                                    <?php foreach ($filiais as $f): ?>
                                    <option value="<?= htmlspecialchars($f['id']) ?>" <?= ($comprasExtraEdicao['filial_id'] ?? null) === $f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-field">
                                <label class="form-label">Setor</label>
                                <select class="form-select" name="compras_setor">
                                    <?php foreach ($setores as $s): ?>
                                    <option value="<?= htmlspecialchars($s['id']) ?>" <?= ($comprasExtraEdicao['setor_id'] ?? null) === $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="permissoes-modulos">
                            <?php foreach (listar_modulos_compras() as $modulo => $label): ?>
                            <div class="permissoes-modulo-linha">
                                <span><?= htmlspecialchars($label) ?></span>
                                <select class="form-select" name="compras_perms[<?= htmlspecialchars($modulo) ?>]">
                                    <?php foreach (NIVEIS_ACESSO_COMPRAS as $nivel): ?>
                                    <option value="<?= $nivel ?>" <?= ($comprasPermsEdicao[$modulo] ?? 'nenhum') === $nivel ? 'selected' : '' ?>><?= ucfirst($nivel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif ($id === 'projeto'): ?>
                        <div class="form-field" style="max-width:260px">
                            <label class="form-label">Perfil</label>
                            <select class="form-select" name="projeto_perfil">
                                <?php foreach ($cfg['roles_disponiveis'] as $r): ?>
                                <option value="<?= htmlspecialchars($r) ?>" <?= $papelAtual === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    <?php elseif ($id === 'veiculos'): ?>
                        <div class="form-field" style="max-width:260px">
                            <label class="form-label">CPF</label>
                            <input class="form-input" name="veiculos_cpf" value="<?= htmlspecialchars($veiculosExtraEdicao['cpf'] ?? '') ?>" placeholder="Só ao criar conta nova aqui">
                            <p class="form-hint">Obrigatório só se essa pessoa ainda não tiver conta no Veículos.</p>
                        </div>
                        <div class="permissoes-menu">
                            <?php foreach (listar_grupos_permissoes_veiculos() as $grupo): ?>
                            <div class="permissoes-grupo">
                                <div class="permissoes-grupo__titulo"><?= htmlspecialchars($grupo['label']) ?></div>
                                <?php foreach ($grupo['items'] as $chave => $label): ?>
                                <label class="permissoes-item">
                                    <input type="checkbox" name="veiculos_perms[<?= htmlspecialchars($chave) ?>]" value="1" <?= !empty($veiculosPermsEdicao[$chave]) ? 'checked' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif ($id === 'locadora'): ?>
                        <div class="form-field" style="max-width:260px">
                            <label class="form-label">Papel</label>
                            <select class="form-input" name="locadora_role">
                                <?php foreach ($cfg['roles_disponiveis'] as $papel): ?>
                                <option value="<?= htmlspecialchars($papel) ?>" <?= ($locadoraExtraEdicao['role'] ?? '') === $papel ? 'selected' : '' ?>><?= htmlspecialchars($papel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php elseif ($id === 'notas'): ?>
                        <div class="form-grid">
                            <div class="form-field">
                                <label class="form-label">Perfil</label>
                                <select class="form-select" name="notas_perfil">
                                    <?php foreach ($cfg['roles_disponiveis'] as $r): ?>
                                    <option value="<?= htmlspecialchars($r) ?>" <?= $papelAtual === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-field">
                                <label class="form-label">Filial</label>
                                <select class="form-select" name="notas_filial">
                                    <?php foreach ($filiaisNotas as $f): ?>
                                    <option value="<?= htmlspecialchars($f['id']) ?>" <?= ($notasExtraEdicao['filial_id'] ?? null) === $f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-field">
                                <label class="form-label">Setor</label>
                                <select class="form-select" name="notas_setor">
                                    <?php foreach ($setoresNotas as $s): ?>
                                    <option value="<?= htmlspecialchars($s['id']) ?>" <?= ($notasExtraEdicao['setor_id'] ?? null) === $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php elseif ($id === 'inventario'): ?>
                        <div class="form-field" style="max-width:260px">
                            <label class="form-label">Usuário</label>
                            <input class="form-input" name="inventario_usuario" value="<?= htmlspecialchars($inventarioExtraEdicao['usuario'] ?? '') ?>" placeholder="Só ao criar conta nova aqui">
                            <p class="form-hint">Obrigatório só se essa pessoa ainda não tiver conta no Inventário.</p>
                        </div>
                        <div class="form-field" style="max-width:260px">
                            <label class="form-label">Perfil</label>
                            <select class="form-select" name="inventario_perfil">
                                <?php foreach ($cfg['roles_disponiveis'] as $r): ?>
                                <option value="<?= htmlspecialchars($r) ?>" <?= $papelAtual === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php elseif ($id === 'imoveis'): ?>
                        <div class="form-field" style="max-width:260px">
                            <label class="form-label">Perfil</label>
                            <select class="form-select" name="imoveis_perfil">
                                <?php foreach ($cfg['roles_disponiveis'] as $r): ?>
                                <option value="<?= htmlspecialchars($r) ?>" <?= ($imoveisExtraEdicao['perfil'] ?? $papelAtual) === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </section>
    </div>
</div>

<script>
function mostrarAba(id) {
    document.querySelectorAll('.tab-painel').forEach(function (p) {
        p.classList.toggle('ativo', p.dataset.tabPainel === id);
    });
    document.querySelectorAll('.tab-btn').forEach(function (b) {
        b.classList.toggle('ativo', b.dataset.tab === id);
    });
}

function filtrarUsuarios() {
    var termo = document.getElementById('buscaUsuario').value.toLowerCase();
    document.querySelectorAll('.usuario-item').forEach(function (item) {
        item.style.display = item.dataset.busca.indexOf(termo) !== -1 ? '' : 'none';
    });
}

document.addEventListener('DOMContentLoaded', function () {
    var primeiro = document.querySelector('.tab-btn');
    if (primeiro) mostrarAba(primeiro.dataset.tab);
});
</script>
</body>
</html>
