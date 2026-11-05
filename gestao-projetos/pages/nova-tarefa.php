<?php
require_once '../config/session.php';
exigirLogin();

try {
    $pdo     = db();
    $projetos = $pdo->query("SELECT id, nome FROM projetos WHERE status NOT IN ('Cancelado','Concluído') ORDER BY nome")->fetchAll();
    $usuarios = $pdo->query("SELECT id, nome FROM usuarios ORDER BY nome")->fetchAll();
} catch (PDOException $e) {
    $projetos = []; $usuarios = [];
}

$projeto_id_pre = (int)($_GET['projeto_id'] ?? 0);
$erro   = '';
$sucesso = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $projeto_id     = (int)($_POST['projeto_id']     ?? 0);
    $titulo         = trim($_POST['titulo']           ?? '');
    $descricao      = trim($_POST['descricao']        ?? '');
    $status         = trim($_POST['status']           ?? '');
    $prioridade     = trim($_POST['prioridade']       ?? '');
    $data_inicio    = trim($_POST['data_inicio']      ?? '');
    $data_fim       = trim($_POST['data_fim']         ?? '');
    $prazo          = $data_fim;
    $pontos         = (int)($_POST['pontos']          ?? 0);
    $responsavel_id = (int)($_POST['responsavel_id']  ?? 0) ?: null;

    if (!$projeto_id)        $erro = 'Selecione um projeto.';
    elseif (strlen($titulo) < 3) $erro = 'Título deve ter ao menos 3 caracteres.';
    elseif ($data_inicio && $data_fim && $data_fim < $data_inicio) $erro = 'Data de termino deve ser maior ou igual a data de inicio.';
    else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO tarefas (projeto_id, titulo, descricao, status, prioridade, data_inicio, data_fim, prazo, responsavel_id, criado_em, atualizado_em)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $projeto_id,
                $titulo,
                $descricao ?: null,
                $status,
                $prioridade,
                $data_inicio ?: null,
                $data_fim    ?: null,
                $prazo     ?: null,
                $responsavel_id,
            ]);
            $sucesso    = true;
            $nova_id    = $pdo->lastInsertId();
            $projeto_id_pre = $projeto_id; // mantém o projeto selecionado
        } catch (PDOException $e) {
            $erro = 'Erro ao salvar: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nova Tarefa | Exemplo DevSystems</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Epilogue:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/app.css">
  <style>
    .field-label {
      display: block;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: #71717a;
      margin-bottom: 0.4rem;
    }
    .field-input {
      width: 100%;
      border: 1.5px solid #e4e4e7;
      border-radius: 0.6rem;
      padding: 0.625rem 0.875rem;
      font-size: 0.875rem;
      background: #fff;
      color: inherit;
      outline: none;
      transition: border-color 0.15s, box-shadow 0.15s;
      font-family: inherit;
    }
    .field-input:focus {
      border-color: var(--color-primary, #6366f1);
      box-shadow: 0 0 0 3px rgba(99,102,241,0.10);
    }
    textarea.field-input { resize: vertical; min-height: 88px; }

    /* Chip radio */
    .chip-group { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .chip input[type=radio] { position: absolute; opacity: 0; pointer-events: none; }
    .chip label {
      display: inline-flex; align-items: center; gap: 0.35rem;
      padding: 0.4rem 0.9rem;
      border: 1.5px solid #e4e4e7;
      border-radius: 2rem;
      font-size: 0.72rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.15s;
      user-select: none;
      background: #fff;
    }
    /* Status chips */
    .chip.s-backlog  input:checked + label { border-color:#a1a1aa; background:#f4f4f5; color:#52525b; }
    .chip.s-todo     input:checked + label { border-color:#3b82f6; background:#eff6ff; color:#2563eb; }
    .chip.s-doing    input:checked + label { border-color:#f59e0b; background:#fffbeb; color:#d97706; }
    .chip.s-review   input:checked + label { border-color:#a855f7; background:#faf5ff; color:#9333ea; }
    .chip.s-done     input:checked + label { border-color:#10b981; background:#ecfdf5; color:#059669; }
    /* Prioridade chips */
    .chip.p-alta   input:checked + label { border-color:#ef4444; background:#fef2f2; color:#ef4444; }
    .chip.p-media  input:checked + label { border-color:#f59e0b; background:#fffbeb; color:#d97706; }
    .chip.p-baixa  input:checked + label { border-color:#6b7280; background:#f4f4f5; color:#52525b; }

    .form-card {
      background: #fff;
      border: 1px solid #e4e4e7;
      border-radius: 1rem;
      padding: 2rem;
    }
    .section-divider {
      border: none;
      border-top: 1px solid #f1f1f1;
      margin: 1.5rem 0;
    }
  </style>
</head>
<body class="bg-surface text-on-surface font-body antialiased">

<?php include '../config/topnav.php'; ?>
<?php include '../config/notificacoes.php'; ?>
<div class="flex">
<?php include '../config/sidebar.php'; ?>

<main class="flex-1 md:ml-64 p-8 min-h-screen page-fade-in">

  <!-- Header -->
  <div class="mb-8">
    <a href="kanban.php" class="inline-flex items-center gap-1.5 text-xs text-secondary hover:text-primary mb-5 transition-colors">
      <span class="material-symbols-outlined text-sm">arrow_back</span> Voltar ao Kanban
    </a>
    <div class="flex items-center gap-2 mb-2">
      <span class="w-8 h-[2px] bg-primary inline-block"></span>
      <span class="text-[10px] font-label font-bold uppercase tracking-widest text-secondary">Tarefas</span>
    </div>
    <h1 class="font-headline text-4xl font-extrabold tracking-tight">Nova Tarefa</h1>
    <p class="text-secondary text-sm mt-1">Adicione uma tarefa a um projeto ativo</p>
  </div>

  <!-- Feedback -->
  <?php if ($sucesso): ?>
  <div class="mb-6 bg-emerald-50 border-l-2 border-emerald-500 px-4 py-3 rounded-r-lg flex items-center justify-between gap-4">
    <div class="flex items-center gap-2 text-sm text-emerald-700">
      <span class="material-symbols-outlined text-sm">check_circle</span>
      Tarefa criada com sucesso!
    </div>
    <div class="flex gap-3">
      <a href="nova-tarefa.php?projeto_id=<?= $projeto_id_pre ?>"
         class="text-xs font-bold text-emerald-700 hover:underline">+ Nova tarefa</a>
      <a href="kanban.php"
         class="text-xs font-bold text-emerald-700 hover:underline">Ver Kanban</a>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($erro): ?>
  <div class="mb-6 bg-red-50 border-l-2 border-red-400 px-4 py-3 rounded-r-lg flex items-center gap-2 text-sm text-red-600">
    <span class="material-symbols-outlined text-sm">error</span> <?= htmlspecialchars($erro) ?>
  </div>
  <?php endif; ?>

  <!-- Formulário -->
  <div class="max-w-2xl">
    <form method="POST" class="form-card space-y-5">

      <!-- Projeto -->
      <div>
        <label class="field-label">Projeto *</label>
        <select name="projeto_id" class="field-input" required>
          <option value="">Selecione o projeto...</option>
          <?php foreach ($projetos as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $p['id'] == $projeto_id_pre ? 'selected' : '' ?>>
            <?= htmlspecialchars($p['nome']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <hr class="section-divider">

      <!-- Título -->
      <div>
        <label class="field-label">Título da Tarefa *</label>
        <input type="text" name="titulo" class="field-input"
               placeholder="Ex: Implementar tela de login"
               value="<?= htmlspecialchars($_POST['titulo'] ?? '') ?>"
               required maxlength="255">
      </div>

      <!-- Descrição -->
      <div>
        <label class="field-label">Descrição</label>
        <textarea name="descricao" class="field-input"
                  placeholder="Descreva o que deve ser feito..."><?= htmlspecialchars($_POST['descricao'] ?? '') ?></textarea>
      </div>

      <hr class="section-divider">

      <!-- Status -->
      <div>
        <label class="field-label">Status</label>
        <div class="chip-group">
          <?php
          $statuses = ['backlog'=>'Backlog','todo'=>'A Fazer','doing'=>'Em Andamento','review'=>'Revisão','done'=>'Concluído'];
          $status_sel = $_POST['status'] ?? 'backlog';
          foreach ($statuses as $val => $label): ?>
          <div class="chip s-<?= $val ?>">
            <input type="radio" name="status" id="s_<?= $val ?>"
                   value="<?= $val ?>" <?= $status_sel === $val ? 'checked' : '' ?>>
            <label for="s_<?= $val ?>"><?= $label ?></label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Prioridade -->
      <div>
        <label class="field-label">Prioridade</label>
        <div class="chip-group">
          <?php
          $prios = ['Alta'=>'🔴 Alta','Média'=>'🟡 Média','Baixa'=>'⚪ Baixa'];
          $prio_sel = $_POST['prioridade'] ?? 'Média';
          $prio_cls_map = ['Alta'=>'p-alta','Média'=>'p-media','Baixa'=>'p-baixa'];
          foreach ($prios as $val => $label): ?>
          <div class="chip <?= $prio_cls_map[$val] ?>">
            <input type="radio" name="prioridade" id="p_<?= $val ?>"
                   value="<?= $val ?>" <?= $prio_sel === $val ? 'checked' : '' ?>>
            <label for="p_<?= $val ?>"><?= $label ?></label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <hr class="section-divider">

      <!-- Responsável + Prazo + Pontos (linha) -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

        <div>
          <label class="field-label">Responsável</label>
          <select name="responsavel_id" class="field-input">
            <option value="">Nenhum</option>
            <?php foreach ($usuarios as $u): ?>
            <option value="<?= $u['id'] ?>" <?= (($_POST['responsavel_id'] ?? '') == $u['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($u['nome']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="field-label">Data de inicio</label>
          <input type="date" name="data_inicio" id="data_inicio" class="field-input"
                 value="<?= htmlspecialchars($_POST['data_inicio'] ?? '') ?>">
        </div>

        <div class="sm:col-span-2">
          <label class="field-label">Data de termino</label>
          <input type="date" name="data_fim" id="data_fim" class="field-input"
                 value="<?= htmlspecialchars($_POST['data_fim'] ?? ($_POST['prazo'] ?? '')) ?>">
        </div>


      </div>

      <!-- Botões -->
      <div class="flex items-center gap-3 pt-2">
        <button type="submit"
                class="flex items-center gap-2 bg-gradient-to-b from-primary-container to-primary
                       text-white font-headline font-bold uppercase tracking-widest text-xs
                       px-6 py-2.5 rounded-md hover:opacity-90 transition-all active:scale-[0.98]">
          <span class="material-symbols-outlined text-sm">add_task</span> Criar Tarefa
        </button>
        <a href="kanban.php"
           class="text-sm text-secondary hover:text-on-surface transition-colors">Cancelar</a>
      </div>

    </form>

    <!-- Atalho: criar outra no mesmo projeto -->
    <?php if ($sucesso): ?>
    <p class="text-xs text-secondary mt-4 text-center">
      Tarefa <span class="font-bold text-emerald-600">#<?= $nova_id ?? '' ?></span> criada.
      <a href="nova-tarefa.php?projeto_id=<?= $projeto_id_pre ?>" class="text-primary font-bold hover:underline ml-1">Criar outra tarefa neste projeto →</a>
    </p>
    <?php endif; ?>
  </div>

</main>
</div>

<script src="../assets/js/app.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var inicio = document.getElementById('data_inicio');
  var fim = document.getElementById('data_fim');
  function validarDatas() {
    if (!inicio || !fim) return;
    fim.setCustomValidity(inicio.value && fim.value && fim.value < inicio.value
      ? 'Data de termino deve ser maior ou igual a data de inicio.'
      : '');
  }
  if (inicio && fim) {
    inicio.addEventListener('change', validarDatas);
    fim.addEventListener('change', validarDatas);
    validarDatas();
  }
});
</script>
</body>
</html>
