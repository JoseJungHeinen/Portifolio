<?php
require_once '../config/session.php';
exigirLogin();

// Ação: adicionar membro à equipe
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao      = $_POST['acao']      ?? '';
    $equipe_id = (int)($_POST['equipe_id'] ?? 0);
    $usuario_id= (int)($_POST['usuario_id'] ?? 0);

    if ($equipe_id && $usuario_id) {
        try {
            $pdo = db();
            if ($acao === 'adicionar') {
                $pdo->prepare("INSERT IGNORE INTO equipe_usuarios (equipe_id, usuario_id, criado_em) VALUES (?,?,NOW())")
                    ->execute([$equipe_id, $usuario_id]);
            } elseif ($acao === 'remover') {
                $pdo->prepare("DELETE FROM equipe_usuarios WHERE equipe_id=? AND usuario_id=?")
                    ->execute([$equipe_id, $usuario_id]);
            }
        } catch (PDOException $e) {}
    }
    header('Location: equipe.php?ok=1');
    exit;
}

$ok = $_GET['ok'] ?? '';

try {
    $pdo = db();

    // Cria tabela de relacionamento se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS equipe_usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        equipe_id INT NOT NULL,
        usuario_id INT NOT NULL,
        criado_em DATETIME DEFAULT NOW(),
        UNIQUE KEY unico (equipe_id, usuario_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Todas as equipes com contagem de membros e nome do líder
    $equipes = $pdo->query("
        SELECT e.*, COUNT(eu.usuario_id) AS total_membros
        FROM equipes e
        LEFT JOIN equipe_usuarios eu ON eu.equipe_id = e.id
        GROUP BY e.id
        ORDER BY e.nome")->fetchAll();

    // Todos os usuários ativos
    $todos_usuarios = $pdo->query("SELECT id, nome, email, cargo, perfil FROM usuarios WHERE ativo=1 ORDER BY nome")->fetchAll();

    // Membros por equipe (para o modal)
    $membros_por_equipe = [];
    $stmt = $pdo->query("SELECT eu.equipe_id, u.id, u.nome, u.email, u.cargo, u.perfil
        FROM equipe_usuarios eu
        JOIN usuarios u ON u.id = eu.usuario_id
        WHERE u.ativo = 1
        ORDER BY u.nome");
    foreach ($stmt->fetchAll() as $row) {
        $membros_por_equipe[$row['equipe_id']][] = $row;
    }

} catch (PDOException $e) {
    $equipes = []; $todos_usuarios = []; $membros_por_equipe = [];
}

$cores_perfil = [
    'Admin'       => ['bg'=>'#fef2f2','c'=>'#b91c1c'],
    'Gerente'     => ['bg'=>'#faf5ff','c'=>'#7e22ce'],
    'Membro'      => ['bg'=>'#eff6ff','c'=>'#1d4ed8'],
    'Visualizador'=> ['bg'=>'#f4f4f5','c'=>'#52525b'],
];
$paleta = ['#6366f1','#ec4899','#f59e0b','#10b981','#3b82f6','#a855f7','#ef4444','#14b8a6'];
?>
<!DOCTYPE html>
<html class="light" lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Equipes | Exemplo DevSystems</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Epilogue:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/app.css">
  <style>
    /* ── Modal ─────────────────────────────────────── */
    .modal-backdrop {
      position: fixed; inset: 0;
      background: rgba(0,0,0,.38);
      backdrop-filter: blur(3px);
      z-index: 50;
      display: flex; align-items: center; justify-content: center;
      opacity: 0; pointer-events: none;
      transition: opacity .2s;
    }
    .modal-backdrop.open { opacity: 1; pointer-events: all; }
    .modal-box {
      background: #fff;
      border-radius: 1.1rem;
      width: 100%; max-width: 520px;
      max-height: 88vh;
      display: flex; flex-direction: column;
      box-shadow: 0 24px 64px rgba(0,0,0,.13);
      transform: translateY(14px) scale(.98);
      transition: transform .2s;
      overflow: hidden;
    }
    .modal-backdrop.open .modal-box { transform: none; }

    /* ── Membro rows ───────────────────────────────── */
    .membro-row {
      display: flex; align-items: center; gap: 12px;
      padding: 10px 0;
      border-bottom: 1px solid #f1f1f1;
    }
    .membro-row:last-child { border-bottom: none; }
    .avatar {
      width: 36px; height: 36px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 700; flex-shrink: 0;
    }
    .badge-perfil {
      font-size: 10px; font-weight: 700;
      padding: 2px 8px; border-radius: 2rem;
    }
    .btn-remove {
      margin-left: auto; flex-shrink: 0;
      background: #fef2f2; border: none; border-radius: 8px;
      padding: 5px 10px; cursor: pointer;
      color: #b91c1c; font-size: 11px; font-weight: 700;
      display: flex; align-items: center; gap: 4px;
      transition: background .15s;
    }
    .btn-remove:hover { background: #fecaca; }

    /* ── Adicionar membro ──────────────────────────── */
    .add-row {
      display: flex; gap: 8px; padding: 12px 0 0;
    }
    .field-select {
      flex: 1;
      border: 1.5px solid #e4e4e7; border-radius: 8px;
      padding: 8px 12px; font-size: 13px;
      background: #fff; outline: none; font-family: inherit;
      transition: border-color .15s;
    }
    .field-select:focus { border-color: #6366f1; }
    .btn-add {
      background: #6366f1; color: #fff;
      border: none; border-radius: 8px;
      padding: 8px 16px; font-size: 12px; font-weight: 700;
      cursor: pointer; display: flex; align-items: center; gap: 5px;
      transition: opacity .15s;
    }
    .btn-add:hover { opacity: .88; }

    /* ── Team card ─────────────────────────────────── */
    .team-card {
      background: #fff; border: 1px solid #e4e4e7;
      border-radius: 14px; padding: 1.25rem;
      cursor: pointer; transition: border-color .15s, box-shadow .15s;
    }
    .team-card:hover {
      border-color: rgba(99,102,241,.35);
      box-shadow: 0 4px 16px rgba(99,102,241,.08);
    }
    .avatar-stack { display: flex; }
    .avatar-stack .avatar { border: 2px solid #fff; margin-left: -8px; }
    .avatar-stack .avatar:first-child { margin-left: 0; }
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
  <div class="mb-8 flex items-end justify-between flex-wrap gap-4">
    <div>
      <div class="flex items-center gap-2 mb-2">
        <span class="w-8 h-[2px] bg-primary inline-block"></span>
        <span class="text-[10px] font-label font-bold uppercase tracking-widest text-secondary">Pessoas</span>
      </div>
      <h1 class="font-headline text-4xl font-extrabold tracking-tight">
        Equipes
        <span class="text-secondary text-2xl font-medium">(<?= count($equipes) ?>)</span>
      </h1>
      <p class="text-secondary text-sm mt-1">Clique em uma equipe para gerenciar os membros</p>
    </div>
    <a href="cadastro-equipes.php"
       class="flex items-center gap-2 bg-gradient-to-b from-primary-container to-primary
              text-white font-headline font-bold uppercase tracking-widest text-xs px-5 py-2.5
              rounded-md hover:opacity-90 transition-all active:scale-[0.98]">
      <span class="material-symbols-outlined text-sm">group_add</span> Nova Equipe
    </a>
  </div>

  <?php if ($ok): ?>
  <div class="mb-6 flex items-center gap-2 bg-emerald-50 border-l-2 border-emerald-500 px-4 py-3 text-sm text-emerald-700 rounded-r-lg">
    <span class="material-symbols-outlined text-sm">check_circle</span> Alteração salva com sucesso!
  </div>
  <?php endif; ?>

  <!-- Grid de equipes -->
  <?php if (empty($equipes)): ?>
  <div class="bg-white rounded-xl p-12 text-center border border-zinc-100">
    <span class="material-symbols-outlined text-5xl text-zinc-300 block mb-4">groups</span>
    <p class="text-secondary mb-2">Nenhuma equipe cadastrada ainda.</p>
    <a href="cadastro-equipes.php" class="text-primary font-bold hover:underline">Criar primeira equipe</a>
  </div>
  <?php else: ?>
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($equipes as $i => $eq):
      $cor     = $paleta[$i % count($paleta)];
      $membros = $membros_por_equipe[$eq['id']] ?? [];
      $ini     = strtoupper(substr($eq['nome'], 0, 2));
    ?>
    <div class="team-card"
         onclick="abrirModal(<?= htmlspecialchars(json_encode([
           'id'       => $eq['id'],
           'nome'     => $eq['nome'],
           'descricao'=> $eq['descricao'] ?? '',
           'lider'    => $eq['lider'] ?? '',
           'cor'      => $cor,
           'membros'  => $membros,
         ]), ENT_QUOTES) ?>)">

      <!-- Topo: ícone + nome -->
      <div class="flex items-center gap-3 mb-4">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center font-headline font-bold text-sm flex-shrink-0"
             style="background:<?= $cor ?>22;color:<?= $cor ?>">
          <?= $ini ?>
        </div>
        <div class="flex-1 min-w-0">
          <p class="font-headline font-bold text-on-surface truncate"><?= htmlspecialchars($eq['nome']) ?></p>
          <?php if (!empty($eq['descricao'])): ?>
          <p class="text-xs text-secondary truncate"><?= htmlspecialchars($eq['descricao']) ?></p>
          <?php endif; ?>
          <?php if (!empty($eq['lider'])): ?>
          <p class="text-xs" style="color:#6366f1;font-weight:600;">
            <span style="font-weight:400;color:#a1a1aa;">Líder:</span> <?= htmlspecialchars($eq['lider']) ?>
          </p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Stack de avatares -->
      <div class="flex items-center justify-between">
        <div class="avatar-stack">
          <?php foreach (array_slice($membros, 0, 5) as $j => $m):
            $cores_av = ['#6366f1','#ec4899','#f59e0b','#10b981','#3b82f6'];
            $c = $cores_av[$j % count($cores_av)];
            $ini_m = strtoupper(substr($m['nome'], 0, 2));
          ?>
          <div class="avatar" style="background:<?= $c ?>22;color:<?= $c ?>;font-size:10px;"
               title="<?= htmlspecialchars($m['nome']) ?>">
            <?= $ini_m ?>
          </div>
          <?php endforeach; ?>
          <?php if (count($membros) > 5): ?>
          <div class="avatar" style="background:#f4f4f5;color:#71717a;font-size:10px;">
            +<?= count($membros) - 5 ?>
          </div>
          <?php endif; ?>
        </div>

        <span class="text-xs text-secondary font-medium">
          <?= count($membros) ?> <?= count($membros) === 1 ? 'membro' : 'membros' ?>
          <span class="material-symbols-outlined text-sm align-middle ml-1" style="color:#d4d4d8">chevron_right</span>
        </span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
</main>
</div>

<!-- ── Modal ────────────────────────────────────────── -->
<div class="modal-backdrop" id="modal" onclick="fecharModal(event)">
  <div class="modal-box" onclick="event.stopPropagation()">

    <!-- Header fixo -->
    <div style="padding:1.5rem 1.5rem 1rem;border-bottom:1px solid #f1f1f1;flex-shrink:0;">
      <div class="flex items-center gap-3">
        <div id="m-avatar" class="w-10 h-10 rounded-xl flex items-center justify-center font-headline font-bold text-sm flex-shrink-0"></div>
        <div class="flex-1 min-w-0">
          <h2 class="font-headline font-bold text-lg" id="m-nome"></h2>
          <p class="text-xs text-secondary" id="m-desc"></p>
          <p class="text-xs" id="m-lider" style="color:#6366f1;font-weight:600;display:none;"></p>
        </div>
        <button onclick="fecharModal()" style="background:none;border:none;cursor:pointer;color:#a1a1aa;padding:4px;">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
    </div>

    <!-- Corpo com scroll -->
    <div style="flex:1;overflow-y:auto;padding:1.25rem 1.5rem;">

      <!-- Adicionar membro -->
      <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#71717a;margin-bottom:8px;">Adicionar membro</p>
      <form method="POST" style="display:flex;gap:8px;margin-bottom:1.5rem;">
        <input type="hidden" name="acao" value="adicionar">
        <input type="hidden" name="equipe_id" id="m-equipe-id">
        <select name="usuario_id" style="flex:1;border:1.5px solid #e4e4e7;border-radius:8px;padding:8px 12px;font-size:13px;background:#fff;outline:none;font-family:inherit;" required id="m-select-usuario">
          <option value="">Selecione um usuário...</option>
          <?php foreach ($todos_usuarios as $u): ?>
          <option value="<?= $u['id'] ?>" data-nome="<?= htmlspecialchars($u['nome'], ENT_QUOTES) ?>">
            <?= htmlspecialchars($u['nome']) ?> — <?= htmlspecialchars($u['cargo'] ?? $u['perfil']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" style="background:#6366f1;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px;">
          <span class="material-symbols-outlined" style="font-size:16px;">person_add</span> Adicionar
        </button>
      </form>

      <!-- Lista de membros -->
      <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#71717a;margin-bottom:8px;">
        Membros da equipe <span id="m-count" style="font-weight:400;text-transform:none;"></span>
      </p>
      <div id="m-membros-lista"></div>

    </div>
  </div>
</div>

<script src="../assets/js/app.js"></script>
<script>
const modal    = document.getElementById('modal');
const todosUsuarios = <?= json_encode($todos_usuarios) ?>;
const coresPerfil   = <?= json_encode($cores_perfil) ?>;
const paleta        = <?= json_encode($paleta) ?>;

let equipeAtual = null;

function abrirModal(eq) {
  equipeAtual = eq;
  document.getElementById('m-nome').textContent      = eq.nome;
  document.getElementById('m-desc').textContent = eq.descricao || '';
  const liderEl = document.getElementById('m-lider');
  if (eq.lider) {
    liderEl.textContent = 'Líder: ' + eq.lider;
    liderEl.style.display = 'block';
  } else {
    liderEl.style.display = 'none';
  }
  document.getElementById('m-equipe-id').value       = eq.id;

  const av = document.getElementById('m-avatar');
  av.textContent = eq.nome.substring(0,2).toUpperCase();
  av.style.background = eq.cor + '22';
  av.style.color      = eq.cor;

  renderMembros(eq.membros);
  modal.classList.add('open');
}

function fecharModal(e) {
  if (e && e.target !== modal) return;
  modal.classList.remove('open');
}

function renderMembros(membros) {
  document.getElementById('m-count').textContent = '(' + membros.length + ')';
  const lista = document.getElementById('m-membros-lista');

  if (membros.length === 0) {
    lista.innerHTML = '<p style="font-size:13px;color:#a1a1aa;text-align:center;padding:1.5rem 0;">Nenhum membro nesta equipe ainda.</p>';
    return;
  }

  lista.innerHTML = membros.map((m, i) => {
    const cores_av = ['#6366f1','#ec4899','#f59e0b','#10b981','#3b82f6','#a855f7'];
    const cor = cores_av[i % cores_av.length];
    const ini = m.nome.substring(0,2).toUpperCase();
    const cp  = coresPerfil[m.perfil] || {bg:'#f4f4f5',c:'#52525b'};
    return `
    <div class="membro-row">
      <div class="avatar" style="background:${cor}22;color:${cor}">${ini}</div>
      <div style="flex:1;min-width:0;">
        <p style="font-size:13px;font-weight:600;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${m.nome}</p>
        <p style="font-size:11px;color:#71717a;margin:0;">${m.cargo||''}</p>
      </div>
      <span class="badge-perfil" style="background:${cp.bg};color:${cp.c}">${m.perfil}</span>
      <form method="POST" onsubmit="return confirm('Remover ${m.nome} desta equipe?')">
        <input type="hidden" name="acao" value="remover">
        <input type="hidden" name="equipe_id" value="${equipeAtual.id}">
        <input type="hidden" name="usuario_id" value="${m.id}">
        <button class="btn-remove" type="submit">
          <span class="material-symbols-outlined" style="font-size:14px;">person_remove</span> Remover
        </button>
      </form>
    </div>`;
  }).join('');
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') modal.classList.remove('open');
});
</script>
</body>
</html>
