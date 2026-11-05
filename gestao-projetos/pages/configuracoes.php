<?php
require_once '../config/session.php';
exigirLogin();

try {
    $pdo = db();
    $mysql_version = $pdo->query('SELECT VERSION()')->fetchColumn();
    $tabelas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $conn_ok = true;
} catch(PDOException $e) {
    $mysql_version = ''; $tabelas = []; $conn_ok = false; $conn_erro = $e->getMessage();
}

$db_host    = defined('DB_HOST')    ? DB_HOST    : 'localhost';
$db_name    = defined('DB_NAME')    ? DB_NAME    : '—';
$db_user    = defined('DB_USER')    ? DB_USER    : '—';
$db_charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

// Busca usuários existentes
try {
    $usuarios = $pdo->query("SELECT id, nome, email, cargo, perfil, ativo, criado_em FROM usuarios ORDER BY nome")->fetchAll();
} catch(PDOException $e) { $usuarios = []; }

// Ações POST
$msg_ok  = '';
$msg_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // Criar usuário
    if ($acao === 'criar_usuario') {
        $nome   = trim($_POST['nome']   ?? '');
        $email  = trim($_POST['email']  ?? '');
        $cargo  = trim($_POST['cargo']  ?? '');
        $perfil = trim($_POST['perfil'] ?? 'Membro');
        $senha  = trim($_POST['senha']  ?? '');

        if (!$nome || !$email || !$senha) {
            $msg_err = 'Nome, e-mail e senha são obrigatórios.';
        } else {
            try {
                $hash = password_hash($senha, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO usuarios (nome, email, cargo, perfil, senha, ativo, criado_em) VALUES (?,?,?,?,?,1,NOW())")
                    ->execute([$nome, $email, $cargo, $perfil, $hash]);
                $msg_ok = 'Usuário criado com sucesso!';
                $usuarios = $pdo->query("SELECT id, nome, email, cargo, perfil, ativo, criado_em FROM usuarios ORDER BY nome")->fetchAll();
            } catch(PDOException $e) {
                $msg_err = 'Erro ao criar usuário: ' . $e->getMessage();
            }
        }
    }

    // Ativar/desativar usuário
    if ($acao === 'toggle_ativo') {
        $uid   = (int)($_POST['uid'] ?? 0);
        $ativo = (int)($_POST['ativo'] ?? 0);
        if ($uid) {
            try {
                $pdo->prepare("UPDATE usuarios SET ativo=? WHERE id=?")->execute([$ativo ? 0 : 1, $uid]);
                $msg_ok = 'Usuário atualizado.';
                $usuarios = $pdo->query("SELECT id, nome, email, cargo, perfil, ativo, criado_em FROM usuarios ORDER BY nome")->fetchAll();
            } catch(PDOException $e) { $msg_err = $e->getMessage(); }
        }
    }

    // Alterar perfil
    if ($acao === 'alterar_perfil') {
        $uid    = (int)($_POST['uid']    ?? 0);
        $perfil = trim($_POST['perfil'] ?? '');
        $perfis_ok = ['Admin','Gerente','Membro','Visualizador'];
        if ($uid && in_array($perfil, $perfis_ok)) {
            try {
                $pdo->prepare("UPDATE usuarios SET perfil=? WHERE id=?")->execute([$perfil, $uid]);
                $msg_ok = 'Perfil atualizado.';
                $usuarios = $pdo->query("SELECT id, nome, email, cargo, perfil, ativo, criado_em FROM usuarios ORDER BY nome")->fetchAll();
            } catch(PDOException $e) { $msg_err = $e->getMessage(); }
        }
    }
}

$aba = $_GET['aba'] ?? 'usuarios';
$perfis_cores = [
    'Admin'       => ['bg'=>'#fef2f2','c'=>'#b91c1c'],
    'Gerente'     => ['bg'=>'#faf5ff','c'=>'#7e22ce'],
    'Membro'      => ['bg'=>'#eff6ff','c'=>'#1d4ed8'],
    'Visualizador'=> ['bg'=>'#f4f4f5','c'=>'#52525b'],
];
?>
<!DOCTYPE html>
<html class="light" lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Configurações | Exemplo DevSystems</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Epilogue:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/app.css">
  <style>
    .aba-btn {
      display: flex; align-items: center; gap: 8px;
      padding: 10px 18px; border-radius: 10px;
      font-size: 13px; font-weight: 700;
      cursor: pointer; border: none; background: transparent;
      color: #71717a; transition: all .15s; text-decoration: none;
      white-space: nowrap;
    }
    .aba-btn:hover { background: #f4f4f5; color: #18181b; }
    .aba-btn.ativa { background: #fff; color: #6366f1; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .aba-btn .material-symbols-outlined { font-size: 18px; }

    .field-label {
      display: block; font-size: 11px; font-weight: 700;
      text-transform: uppercase; letter-spacing: .06em;
      color: #71717a; margin-bottom: 6px;
    }
    .field-input {
      width: 100%; border: 1.5px solid #e4e4e7; border-radius: 8px;
      padding: 9px 12px; font-size: 13px; background: #fff;
      color: inherit; outline: none; font-family: inherit;
      transition: border-color .15s, box-shadow .15s;
    }
    .field-input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.10); }

    .usuario-row {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 16px; border-bottom: 1px solid #f4f4f5;
      transition: background .12s;
    }
    .usuario-row:last-child { border-bottom: none; }
    .usuario-row:hover { background: #fafafa; }
    .avatar-u {
      width: 38px; height: 38px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 13px; flex-shrink: 0;
      background: rgba(99,102,241,.1); color: #6366f1;
    }
    .badge-perfil {
      font-size: 10px; font-weight: 700;
      padding: 2px 8px; border-radius: 2rem; flex-shrink: 0;
    }
    .toggle-ativo {
      width: 36px; height: 20px; border-radius: 2rem;
      border: none; cursor: pointer; position: relative;
      transition: background .2s; flex-shrink: 0;
    }
    .toggle-ativo::after {
      content: ''; position: absolute;
      width: 14px; height: 14px; border-radius: 50%;
      background: #fff; top: 3px;
      transition: left .2s;
    }
    .toggle-ativo.on  { background: #10b981; }
    .toggle-ativo.on::after  { left: 19px; }
    .toggle-ativo.off { background: #d4d4d8; }
    .toggle-ativo.off::after { left: 3px; }

    .card-section {
      background: #fff; border: 1px solid #e4e4e7;
      border-radius: 14px; overflow: hidden;
    }
    .card-header {
      padding: 16px 20px; border-bottom: 1px solid #f4f4f5;
      display: flex; align-items: center; justify-content: space-between;
    }
    .stat-card {
      background: #f9f9f9; border-radius: 10px;
      padding: 14px 16px;
    }
  </style>
</head>
<body class="bg-surface text-on-surface font-body antialiased">
<?php include '../config/topnav.php'; ?>
<?php include '../config/notificacoes.php'; ?>
<div class="app-shell">
<?php include '../config/sidebar.php'; ?>

<main class="app-main p-8 page-fade-in">
<div class="max-w-5xl mx-auto">

  <!-- Header -->
  <div class="mb-8">
    <div class="flex items-center gap-2 mb-2">
      <span class="w-8 h-[2px] bg-primary inline-block"></span>
      <span class="text-[10px] font-label font-bold uppercase tracking-widest text-secondary">Sistema</span>
    </div>
    <h1 class="font-headline text-4xl font-extrabold tracking-tight">Configurações</h1>
  </div>

  <!-- Abas -->
  <div class="flex gap-1 mb-6 bg-surface-container p-1 rounded-xl w-fit">
    <?php
    $abas = [
      'usuarios'  => ['icon'=>'group', 'label'=>'Usuários'],
      'sistema'   => ['icon'=>'dns',   'label'=>'Sistema'],
    ];
    foreach ($abas as $key => $ab):
    ?>
    <a href="?aba=<?= $key ?>"
       class="aba-btn <?= $aba === $key ? 'ativa' : '' ?>">
      <span class="material-symbols-outlined"><?= $ab['icon'] ?></span>
      <?= $ab['label'] ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Alertas -->
  <?php if ($msg_ok): ?>
  <div class="mb-5 flex items-center gap-2 bg-emerald-50 border-l-2 border-emerald-500 px-4 py-3 text-sm text-emerald-700 rounded-r-lg">
    <span class="material-symbols-outlined text-sm">check_circle</span> <?= htmlspecialchars($msg_ok) ?>
  </div>
  <?php endif; ?>
  <?php if ($msg_err): ?>
  <div class="mb-5 flex items-center gap-2 bg-red-50 border-l-2 border-red-400 px-4 py-3 text-sm text-red-600 rounded-r-lg">
    <span class="material-symbols-outlined text-sm">error</span> <?= htmlspecialchars($msg_err) ?>
  </div>
  <?php endif; ?>


  <!-- ═══ ABA: USUÁRIOS ═══ -->
  <?php if ($aba === 'usuarios'): ?>
  <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

    <!-- Formulário novo usuário -->
    <div class="lg:col-span-2">
      <div class="card-section">
        <div class="card-header">
          <div>
            <p class="font-headline font-bold text-sm">Novo Usuário</p>
            <p class="text-xs text-secondary mt-0.5">Adicionar membro ao sistema</p>
          </div>
          <span class="material-symbols-outlined text-primary" style="font-variation-settings:'FILL' 1">person_add</span>
        </div>
        <form method="POST" style="padding:20px;" class="space-y-4">
          <input type="hidden" name="acao" value="criar_usuario">

          <div>
            <label class="field-label">Nome completo *</label>
            <input type="text" name="nome" class="field-input" placeholder="João Silva" required>
          </div>
          <div>
            <label class="field-label">E-mail *</label>
            <input type="email" name="email" class="field-input" placeholder="joao@empresa.com" required>
          </div>
          <div>
            <label class="field-label">Cargo</label>
            <input type="text" name="cargo" class="field-input" placeholder="Ex: Desenvolvedor">
          </div>
          <div>
            <label class="field-label">Perfil</label>
            <select name="perfil" class="field-input">
              <option value="Membro">Membro</option>
              <option value="Gerente">Gerente</option>
              <option value="Admin">Admin</option>
              <option value="Visualizador">Visualizador</option>
            </select>
          </div>
          <div>
            <label class="field-label">Senha *</label>
            <input type="password" name="senha" class="field-input" placeholder="Mínimo 6 caracteres" required minlength="6">
          </div>

          <button type="submit"
                  class="w-full flex items-center justify-center gap-2
                         bg-gradient-to-b from-primary-container to-primary
                         text-white font-headline font-bold uppercase tracking-widest text-xs
                         py-2.5 rounded-lg hover:opacity-90 transition-all active:scale-[0.98]">
            <span class="material-symbols-outlined text-sm">add</span> Criar Usuário
          </button>
        </form>
      </div>
    </div>

    <!-- Lista de usuários -->
    <div class="lg:col-span-3">
      <div class="card-section">
        <div class="card-header">
          <div>
            <p class="font-headline font-bold text-sm">Usuários Cadastrados</p>
            <p class="text-xs text-secondary mt-0.5"><?= count($usuarios) ?> no total</p>
          </div>
          <span class="material-symbols-outlined text-secondary">manage_accounts</span>
        </div>

        <div>
          <?php foreach ($usuarios as $u):
            $ini = strtoupper(substr($u['nome'], 0, 2));
            $cp  = $perfis_cores[$u['perfil']] ?? ['bg'=>'#f4f4f5','c'=>'#52525b'];
            $ativo = (int)$u['ativo'];
          ?>
          <div class="usuario-row">
            <div class="avatar-u"><?= $ini ?></div>
            <div style="flex:1;min-width:0;">
              <div style="display:flex;align-items:center;gap:6px;">
                <p style="font-size:13px;font-weight:700;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
                           <?= !$ativo ? 'color:#a1a1aa;text-decoration:line-through;' : '' ?>">
                  <?= htmlspecialchars($u['nome']) ?>
                </p>
                <?php if (!$ativo): ?>
                <span style="font-size:9px;font-weight:700;background:#f4f4f5;color:#71717a;padding:1px 6px;border-radius:2rem;flex-shrink:0;">
                  INATIVO
                </span>
                <?php endif; ?>
              </div>
              <p style="font-size:11px;color:#71717a;margin:0;">
                <?= htmlspecialchars($u['email']) ?>
                <?= $u['cargo'] ? ' · ' . htmlspecialchars($u['cargo']) : '' ?>
              </p>
            </div>

            <!-- Alterar perfil -->
            <form method="POST" style="flex-shrink:0;">
              <input type="hidden" name="acao" value="alterar_perfil">
              <input type="hidden" name="uid" value="<?= $u['id'] ?>">
              <select name="perfil" onchange="this.form.submit()"
                      style="font-size:10px;font-weight:700;padding:3px 8px;border-radius:2rem;border:none;cursor:pointer;background:<?= $cp['bg'] ?>;color:<?= $cp['c'] ?>;">
                <?php foreach(['Admin','Gerente','Membro','Visualizador'] as $p): ?>
                <option value="<?= $p ?>" <?= $u['perfil']===$p?'selected':'' ?>><?= $p ?></option>
                <?php endforeach; ?>
              </select>
            </form>

            <!-- Toggle ativo -->
            <form method="POST" style="flex-shrink:0;"
                  onsubmit="return confirm('<?= $ativo ? 'Desativar' : 'Ativar' ?> o usuário <?= addslashes(htmlspecialchars($u['nome'])) ?>?')">
              <input type="hidden" name="acao" value="toggle_ativo">
              <input type="hidden" name="uid" value="<?= $u['id'] ?>">
              <input type="hidden" name="ativo" value="<?= $ativo ?>">
              <button type="submit"
                      style="display:flex;align-items:center;gap:5px;border:none;border-radius:8px;padding:5px 10px;cursor:pointer;font-size:11px;font-weight:700;transition:all .15s;
                             background:<?= $ativo ? '#fef2f2' : '#ecfdf5' ?>;
                             color:<?= $ativo ? '#b91c1c' : '#065f46' ?>;">
                <span class="material-symbols-outlined" style="font-size:14px;font-variation-settings:'FILL' 1;">
                  <?= $ativo ? 'person_off' : 'person_check' ?>
                </span>
                <?= $ativo ? 'Desativar' : 'Ativar' ?>
              </button>
            </form>
          </div>
          <?php endforeach; ?>

          <?php if (empty($usuarios)): ?>
          <div style="padding:2rem;text-align:center;color:#a1a1aa;font-size:13px;">
            Nenhum usuário cadastrado ainda.
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>


  <!-- ═══ ABA: SISTEMA ═══ -->
  <?php elseif ($aba === 'sistema'): ?>
  <div class="space-y-6">

    <!-- Status DB -->
    <div class="card-section">
      <div class="card-header">
        <p class="font-headline font-bold text-sm">Conexão com Banco de Dados</p>
        <?php if ($conn_ok): ?>
        <span class="flex items-center gap-1.5 text-xs font-bold text-emerald-700 bg-emerald-50 px-3 py-1 rounded-full">
          <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse inline-block"></span> Conectado
        </span>
        <?php else: ?>
        <span class="flex items-center gap-1.5 text-xs font-bold text-red-700 bg-red-50 px-3 py-1 rounded-full">
          <span class="w-2 h-2 bg-red-500 rounded-full inline-block"></span> Falha
        </span>
        <?php endif; ?>
      </div>
      <div style="padding:20px;">
        <div class="grid grid-cols-3 gap-4 mb-4">
          <?php foreach(['Banco'=>$db_name,'Host'=>$db_host,'Usuário'=>$db_user] as $l=>$v): ?>
          <div class="stat-card">
            <p style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#71717a;margin:0 0 4px;"><?= $l ?></p>
            <p style="font-size:14px;font-weight:700;margin:0;"><?= htmlspecialchars($v) ?></p>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if ($conn_ok): ?>
        <div style="background:#ecfdf5;border-left:3px solid #10b981;border-radius:0 8px 8px 0;padding:10px 14px;font-size:13px;color:#065f46;display:flex;align-items:center;gap:8px;">
          <span class="material-symbols-outlined" style="font-size:16px;">check_circle</span>
          MySQL <strong><?= $mysql_version ?></strong> — banco <strong><?= $db_name ?></strong> operacional.
        </div>
        <?php else: ?>
        <div style="background:#fef2f2;border-left:3px solid #ef4444;border-radius:0 8px 8px 0;padding:10px 14px;font-size:13px;color:#b91c1c;">
          <?= htmlspecialchars($conn_erro ?? '') ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Info sistema -->
    <div class="card-section">
      <div class="card-header">
        <p class="font-headline font-bold text-sm">Informações do Sistema</p>
      </div>
      <div style="padding:20px;">
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
          <?php foreach([
            'Versão App' => 'v1.0.0',
            'PHP'        => PHP_VERSION,
            'Servidor'   => ($_SERVER['SERVER_SOFTWARE'] ?? 'Apache'),
            'Ambiente'   => ($db_host==='localhost' ? '🔧 Desenvolvimento' : '🚀 Produção'),
            'Charset'    => $db_charset,
            'Usuário'    => htmlspecialchars(usuario()['nome'] ?? ''),
          ] as $l => $v): ?>
          <div class="stat-card">
            <p style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#71717a;margin:0 0 4px;"><?= $l ?></p>
            <p style="font-size:14px;font-weight:700;margin:0;"><?= $v ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <?php endif; ?>

</div>
</main>
</div>
<script src="../assets/js/app.js"></script>
</body>
</html>
