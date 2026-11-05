<?php
require_once '../config/session.php';
exigirLogin();

$colunas = [
    1 => 'Em Análise',
    2 => 'Em Andamento',
    3 => 'Revisão',
    4 => 'Concluído',
    5 => 'Pausado',
];

$col_cor = [
    1 => 'border-zinc-300',
    2 => 'border-amber-400',
    3 => 'border-purple-300',
    4 => 'border-emerald-400',
    5 => 'border-orange-300',
];
$col_badge = [
    1 => 'bg-zinc-100 text-zinc-600',
    2 => 'bg-amber-50 text-amber-600',
    3 => 'bg-purple-50 text-purple-600',
    4 => 'bg-emerald-50 text-emerald-700',
    5 => 'bg-orange-50 text-orange-600',
];
$prio_cls = [
    'Alta'  => 'text-red-600 bg-red-50',
    'Média' => 'text-amber-600 bg-amber-50',
    'Baixa' => 'text-zinc-500 bg-zinc-100',
];

$projetos_por_col = [];
foreach (array_keys($colunas) as $col) $projetos_por_col[$col] = [];

try {
    $pdo  = db();
    $stmt = $pdo->query("
        SELECT id, nome, descricao, cliente, status+0 AS status_idx, prioridade, prazo, progresso
        FROM projetos
        ORDER BY FIELD(prioridade,'Alta','Baixa'), nome
    ");
    foreach ($stmt->fetchAll() as $p) {
        $idx = (int)$p['status_idx'];
        if (isset($projetos_por_col[$idx])) {
            $projetos_por_col[$idx][] = $p;
        }
    }
} catch (PDOException $e) {
    $projetos_por_col = [];
}

$ok = $_GET['ok'] ?? '';
?>
<!DOCTYPE html>
<html class="light" lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kanban | Exemplo DevSystems</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Epilogue:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/app.css">
  <style>
    /* Board layout */

    .kanban-board-shell {
      min-width: 0;
      max-width: 100%;
      overflow: visible;
    }

    .kanban-actions-row {
      display: flex;
      justify-content: flex-end;
      margin-bottom: 0.75rem;
    }

    .kanban-actions-slot {
      width: 100%;
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 0.5rem;
    }

    .kanban-actions-spacer {
      display: none;
    }

    /* Mobile-first: 1 coluna em grid */
    #kanban-board {
      display: grid;
      grid-template-columns: 1fr;
      gap: 1rem;
      align-items: start;
    }

    /* 2 colunas a partir de 640 px */
    @media (min-width: 640px) {
      #kanban-board {
        grid-template-columns: repeat(2, 1fr);
      }
    }

    /* 3 colunas a partir de 1024 px */
    @media (min-width: 1024px) {
      .kanban-board-shell {
        overflow-x: auto;
        padding-bottom: 0.75rem;
        scrollbar-width: thin;
        scrollbar-color: #d4d4d8 transparent;
      }
      .kanban-actions-row {
        width: max-content;
        min-width: max-content;
        flex-wrap: nowrap;
        gap: 1rem;
      }
      .kanban-actions-slot,
      .kanban-actions-spacer {
        display: flex;
        flex: 0 0 218px;
        min-width: 218px;
      }
      .kanban-actions-spacer {
        justify-content: flex-start;
      }
      .kanban-board-shell::-webkit-scrollbar        { height: 6px; }
      .kanban-board-shell::-webkit-scrollbar-track  { background: transparent; }
      .kanban-board-shell::-webkit-scrollbar-thumb  { background: #d4d4d8; border-radius: 99px; }
      #kanban-board {
        display: flex;
        flex-wrap: nowrap;
        min-width: max-content;
      }
      #kanban-board > .kanban-col-wrap {
        flex: 0 0 218px;
        min-width: 218px;
      }
    }

    /* 5 colunas fixas + scroll horizontal em telas maiores */
    @media (min-width: 1400px) {
      .kanban-actions-slot,
      .kanban-actions-spacer {
        flex: 0 0 232px;
        min-width: 232px;
      }
      #kanban-board > .kanban-col-wrap {
        flex: 0 0 232px;
        min-width: 232px;
      }
    }

    @media (min-width: 1600px) {
      .kanban-actions-slot,
      .kanban-actions-spacer {
        flex: 0 0 280px;
        min-width: 280px;
      }
      #kanban-board > .kanban-col-wrap {
        flex: 0 0 280px;
        min-width: 280px;
      }
    }

    /* Coluna */
    .kanban-col {
      min-height: 40vh;
      border-radius: 0.75rem;
      padding: 0.75rem;
      background: rgba(0,0,0,0.02);
      transition: background 0.15s, box-shadow 0.15s;
    }
    .kanban-col.drag-over {
      background: rgba(99,102,241,0.06);
      box-shadow: inset 0 0 0 2px rgba(99,102,241,0.3);
    }

    /* Card */
    .kanban-card { touch-action: none; }
    .kanban-card.dragging { opacity: 0.4; transform: rotate(1.5deg); }

    /* Placeholder de drop */
    .drop-placeholder {
      min-height: 56px;
      border: 2px dashed #d4d4d8;
      border-radius: 0.75rem;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.7rem;
      color: #a1a1aa;
      margin-top: 0.5rem;
      opacity: 0;
      transition: opacity 0.15s;
    }
    .kanban-col.drag-over .drop-placeholder { opacity: 1; }

    /* Utilidades */
    .line-clamp-2 {
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    /* Scroll snap suave em mobile (facilita swipe entre colunas) */
    @media (max-width: 639px) {
      #kanban-board {
        scroll-snap-type: x mandatory;
        -webkit-overflow-scrolling: touch;
      }
      #kanban-board > .kanban-col-wrap {
        scroll-snap-align: start;
      }
    }
  </style>
</head>
<body class="bg-surface text-on-surface font-body antialiased">

<?php include '../config/topnav.php'; ?>
<div class="app-shell">
<?php include '../config/sidebar.php'; ?>

<main class="app-main p-3 sm:p-4 lg:p-4 xl:p-5 page-fade-in">

  <!-- Cabecalho -->
  <div class="mb-5 sm:mb-6">
    <div>
      <div class="flex items-center gap-2 mb-2">
        <span class="w-8 h-[2px] bg-primary inline-block"></span>
        <span class="text-[10px] font-label font-bold uppercase tracking-widest text-secondary">Gestão</span>
      </div>
      <h1 class="font-headline text-3xl sm:text-4xl font-extrabold tracking-tight">Board Kanban</h1>
      <p class="text-secondary text-sm mt-1">Todos os projetos - arraste para mudar a fase</p>
    </div>
  </div>

  <?php if ($ok === 'projeto_criado'): ?>
  <div class="mb-6 flex items-center gap-2 bg-emerald-50 border-l-2 border-emerald-500 px-4 py-3 text-sm text-emerald-700 rounded-r-lg">
    <span class="material-symbols-outlined text-sm">check_circle</span> Projeto criado com sucesso!
  </div>
  <?php endif; ?>

  <div class="mb-4 hidden items-center gap-2 text-xs text-secondary lg:flex">
    <span class="material-symbols-outlined text-sm text-zinc-400">open_with</span>
    Role horizontalmente para visualizar todas as colunas do board.
  </div>

  <!-- Seletor de coluna -->
  <div class="flex gap-2 overflow-x-auto pb-2 mb-4 sm:hidden scrollbar-none">
    <?php foreach ($colunas as $col_idx => $col_label): ?>
    <button onclick="scrollToCol(<?= $col_idx ?>)"
            class="flex-shrink-0 text-[11px] font-bold px-3 py-1.5 rounded-full border
                   <?= $col_badge[$col_idx] ?> border-zinc-200 whitespace-nowrap">
      <?= $col_label ?>
    </button>
    <?php endforeach; ?>
  </div>

  <div class="kanban-board-shell">
  <div class="kanban-actions-row">
    <div class="kanban-actions-spacer" aria-hidden="true"></div>
    <div class="kanban-actions-spacer" aria-hidden="true"></div>
    <div class="kanban-actions-spacer" aria-hidden="true"></div>
    <div class="kanban-actions-spacer" aria-hidden="true"></div>
    <div class="kanban-actions-slot">
      <a href="relatorio.php"
         class="flex items-center gap-2 bg-surface-container text-on-surface
                font-headline font-bold uppercase tracking-widest text-xs px-3 py-2
                rounded-md hover:bg-surface-container-high transition-all active:scale-[0.98]">
        <span class="material-symbols-outlined text-sm">summarize</span>
        Relatório
      </a>
      <a href="novo-projeto.php"
         class="flex items-center gap-2 bg-gradient-to-b from-primary-container to-primary
                text-white font-headline font-bold uppercase tracking-widest text-xs px-3 py-2
                rounded-md hover:opacity-90 transition-all active:scale-[0.98]">
        <span class="material-symbols-outlined text-sm">add</span>
        Novo Projeto
      </a>
    </div>
  </div>

  <!-- Board -->
  <div id="kanban-board">
    <?php foreach ($colunas as $col_idx => $col_label):
      $cards = $projetos_por_col[$col_idx];
      $borda = $col_cor[$col_idx];
      $badge = $col_badge[$col_idx];
    ?>
    <div class="kanban-col-wrap" data-wrap-col="<?= $col_idx ?>" id="wrap-col-<?= $col_idx ?>">

      <!-- Header da coluna -->
      <div class="flex items-center justify-between mb-3 pb-3 border-b-2 <?= $borda ?>">
        <span class="font-headline font-bold text-sm text-on-surface"><?= $col_label ?></span>
        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $badge ?> count-badge"
              data-col="<?= $col_idx ?>"><?= count($cards) ?></span>
      </div>

      <!-- Coluna (zona de drop) -->
      <div class="kanban-col" data-col="<?= $col_idx ?>">

        <?php foreach ($cards as $proj): ?>
        <div class="kanban-card bg-white rounded-xl p-4 shadow-sm border border-zinc-100
                    hover:border-primary/20 hover:shadow-md transition-all cursor-grab active:cursor-grabbing select-none mb-3"
             draggable="true"
             data-id="<?= $proj['id'] ?>"
             data-status="<?= $col_idx ?>">

          <!-- Prioridade + ID -->
          <div class="flex items-center justify-between mb-2">
            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $prio_cls[$proj['prioridade']] ?? 'text-zinc-500 bg-zinc-100' ?>">
              <?= htmlspecialchars($proj['prioridade']) ?>
            </span>
            <span class="text-[10px] text-zinc-400 font-mono">#<?= $proj['id'] ?></span>
          </div>

          <!-- Nome -->
          <p class="text-sm font-semibold text-on-surface mb-1 leading-snug">
            <?= htmlspecialchars($proj['nome']) ?>
          </p>

          <!-- Descricao -->
          <?php if (!empty($proj['descricao'])): ?>
          <p class="text-[11px] text-secondary mb-3 leading-snug line-clamp-2">
            <?= htmlspecialchars($proj['descricao']) ?>
          </p>
          <?php endif; ?>

          <!-- Progresso -->
          <?php $prog = (int)$proj['progresso']; ?>
          <div class="mb-3">
            <div class="flex justify-between items-center mb-1">
              <span class="text-[10px] text-secondary">Progresso</span>
              <span class="text-[10px] font-bold text-on-surface"><?= $prog ?>%</span>
            </div>
            <div class="w-full bg-zinc-100 rounded-full h-1.5">
              <div class="bg-primary h-1.5 rounded-full" style="width:<?= $prog ?>%"></div>
            </div>
          </div>

          <!-- Rodape -->
          <div class="flex items-center justify-between">
            <?php if (!empty($proj['cliente'])): ?>
            <div class="flex items-center gap-1.5">
              <div class="w-5 h-5 rounded-full bg-primary/10 text-primary text-[10px] font-bold flex items-center justify-center">
                <?= strtoupper(substr($proj['cliente'], 0, 2)) ?>
              </div>
              <span class="text-[10px] text-secondary truncate max-w-[100px]"><?= htmlspecialchars($proj['cliente']) ?></span>
            </div>
            <?php else: ?><div></div><?php endif; ?>
            <?php if (!empty($proj['prazo'])): ?>
            <span class="text-[10px] text-secondary inline-flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">calendar_month</span><?= htmlspecialchars($proj['prazo']) ?></span>
            <?php endif; ?>
          </div>

          <!-- Botao adicionar tarefa -->
          <div class="mt-3 pt-3 border-t border-zinc-100">
            <a href="nova-tarefa.php?projeto_id=<?= $proj['id'] ?>"
               onclick="event.stopPropagation()"
               draggable="false"
               class="flex items-center justify-center gap-1.5 w-full py-1.5 rounded-lg
                      text-[11px] font-bold text-primary bg-primary/5
                      hover:bg-primary/10 transition-colors">
              <span class="material-symbols-outlined text-sm">add_task</span>
              Nova Tarefa
            </a>
          </div>
        </div>
        <?php endforeach; ?>

        <div class="drop-placeholder">Soltar aqui</div>
      </div>
    </div><!-- /.kanban-col-wrap -->
    <?php endforeach; ?>
  </div><!-- /#kanban-board -->
  </div><!-- /.kanban-board-shell -->

</main>
</div>

<?php include '../config/notificacoes.php'; ?>

<script src="../assets/js/app.js"></script>
<script>
(function () {
  var originCol = null;

  // Drag & Drop
  document.querySelectorAll('.kanban-card').forEach(function(card) {
    card.addEventListener('dragstart', function(e) {
      originCol = this.dataset.status;
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('cardId',  this.dataset.id);
      e.dataTransfer.setData('fromCol', this.dataset.status);
      setTimeout(function() { card.classList.add('dragging'); }, 0);
    });

    card.addEventListener('dragend', function() {
      this.classList.remove('dragging');
      document.querySelectorAll('.kanban-col').forEach(function(c) {
        c.classList.remove('drag-over');
      });
    });
  });

  document.querySelectorAll('.kanban-col').forEach(function(col) {
    col.addEventListener('dragover', function(e) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      document.querySelectorAll('.kanban-col').forEach(function(c) {
        c.classList.remove('drag-over');
      });
      this.classList.add('drag-over');
    });

    col.addEventListener('dragleave', function(e) {
      if (!this.contains(e.relatedTarget)) this.classList.remove('drag-over');
    });

    col.addEventListener('drop', function(e) {
      e.preventDefault();
      this.classList.remove('drag-over');

      var cardId  = e.dataTransfer.getData('cardId');
      var fromCol = e.dataTransfer.getData('fromCol');
      var toCol   = this.dataset.col;

      if (!cardId || fromCol === toCol) return;

      var card = document.querySelector('[data-id="' + cardId + '"]');
      if (!card) return;

      var colOrigem = document.querySelector('.kanban-col[data-col="' + fromCol + '"]');
      var placeholder = this.querySelector('.drop-placeholder');
      this.insertBefore(card, placeholder);
      card.dataset.status = toCol;
      atualizarContador(fromCol);
      atualizarContador(toCol);

      fetch('kanban-update.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(cardId) + '&status=' + encodeURIComponent(toCol)
      })
      .then(function(r) {
        return r.text().then(function(txt) { return { ok: r.ok, status: r.status, txt: txt }; });
      })
      .then(function(res) {
        if (!res.ok || res.txt.trim() !== 'ok') {
          throw new Error('HTTP ' + res.status + ': ' + res.txt.trim());
        }
      })
      .catch(function(err) {
        console.error('Kanban save error:', err);
        if (colOrigem) {
          var phOrig = colOrigem.querySelector('.drop-placeholder');
          colOrigem.insertBefore(card, phOrig);
          card.dataset.status = fromCol;
          atualizarContador(fromCol);
          atualizarContador(toCol);
        }
        alert('Erro ao salvar:\n' + err.message + '\n\nCard revertido.');
      });
    });
  });

  function atualizarContador(statusVal) {
    var col   = document.querySelector('.kanban-col[data-col="' + statusVal + '"]');
    var badge = document.querySelector('.count-badge[data-col="' + statusVal + '"]');
    if (col && badge) badge.textContent = col.querySelectorAll('.kanban-card').length;
  }

  // Scroll para coluna via botoes mobile
  window.scrollToCol = function(colIdx) {
    var el = document.getElementById('wrap-col-' + colIdx);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
})();
</script>
</body>
</html>


