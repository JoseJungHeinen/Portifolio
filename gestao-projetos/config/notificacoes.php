<?php
$_avisos = [];
$_total  = 0;

try {
    $_hoje    = date('Y-m-d');
    $_dia_sem = (int)date('w');

    // 1. Reunião toda terça-feira
    if ($_dia_sem === 2) {
        $_avisos[] = [
            'tipo'  => 'reuniao',
            'icon'  => 'groups',
            'cor'   => '#10b981',
            'bg'    => '#ecfdf5',
            'titulo'=> 'Reunião de Atualização — hoje às 08h',
            'sub'   => 'Reunião semanal recorrente toda terça-feira.',
            'link'  => 'calendario.php',
        ];
    }

    // 2. Tarefas com prazo hoje
    $_prio_cor = ['Alta'=>'#ef4444','Média'=>'#f59e0b','Baixa'=>'#3b82f6'];
    $_prio_bg  = ['Alta'=>'#fef2f2','Média'=>'#fffbeb','Baixa'=>'#eff6ff'];

    $_stmt = db()->prepare("
        SELECT t.titulo, t.prioridade, t.status, p.nome AS projeto
        FROM tarefas t
        LEFT JOIN projetos p ON p.id = t.projeto_id
        WHERE COALESCE(t.data_fim, t.prazo) = ? AND t.status != 'done'
        ORDER BY FIELD(t.prioridade,'Alta','Média','Baixa')
    ");
    $_stmt->execute([$_hoje]);

    foreach ($_stmt->fetchAll() as $_t) {
        $_avisos[] = [
            'tipo'  => 'tarefa',
            'icon'  => 'task_alt',
            'cor'   => $_prio_cor[$_t['prioridade']] ?? '#6b7280',
            'bg'    => $_prio_bg[$_t['prioridade']]  ?? '#f4f4f5',
            'titulo'=> $_t['titulo'],
            'sub'   => 'Prazo: hoje · ' . ($_t['projeto'] ?? '') . ' · ' . $_t['prioridade'],
            'link'  => 'minhas-tarefas.php',
        ];
    }
} catch (Throwable $_e) {
    // silencia qualquer erro para não quebrar a página
}

$_total = count($_avisos);

// Compatível com PHP 7 e PHP 8
$_temUrgente = !empty(array_filter($_avisos, function($a) {
    return $a['tipo'] === 'reuniao' || strpos($a['sub'], 'Alta') !== false;
}));
?>
<?php if ($_total > 0): ?>
<style>
  #notif-bell {
    position: fixed;
    bottom: 28px; right: 28px;
    z-index: 999;
    display: flex; flex-direction: column; align-items: flex-end; gap: 12px;
  }
  #notif-btn {
    width: 52px; height: 52px;
    background: #6366f1;
    border: none; border-radius: 50%;
    box-shadow: 0 4px 20px rgba(99,102,241,.4);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    position: relative;
    transition: transform .15s, box-shadow .15s;
  }
  #notif-btn:hover { transform: scale(1.08); box-shadow: 0 6px 28px rgba(99,102,241,.5); }
  #notif-btn:active { transform: scale(.96); }
  #notif-badge {
    position: absolute; top: -4px; right: -4px;
    background: #ef4444; color: #fff;
    font-size: 10px; font-weight: 800;
    min-width: 18px; height: 18px;
    border-radius: 2rem;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid #fff;
    padding: 0 3px;
    animation: pulse-badge 2s infinite;
  }
  @keyframes pulse-badge {
    0%,100% { transform: scale(1); }
    50%      { transform: scale(1.15); }
  }
  #notif-panel {
    background: #fff;
    border: 1px solid #e4e4e7;
    border-radius: 14px;
    box-shadow: 0 12px 48px rgba(0,0,0,.14);
    width: 340px;
    max-height: 70vh;
    overflow: hidden;
    display: flex; flex-direction: column;
    transform: translateY(10px) scale(.97);
    opacity: 0; pointer-events: none;
    transition: transform .2s, opacity .2s;
    transform-origin: bottom right;
  }
  #notif-panel.open { transform: none; opacity: 1; pointer-events: all; }
  .notif-item {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 12px 16px;
    border-bottom: 1px solid #f4f4f5;
    text-decoration: none; color: inherit;
    transition: background .12s;
    cursor: pointer;
  }
  .notif-item:last-child { border-bottom: none; }
  .notif-item:hover { background: #f8f8f8; }
  .notif-icon {
    width: 34px; height: 34px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
  }
</style>

<div id="notif-bell">
  <div id="notif-panel">
    <div style="padding:14px 16px 10px;border-bottom:1px solid #f4f4f5;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
      <div>
        <p style="font-size:13px;font-weight:700;margin:0;">Avisos de hoje</p>
        <p style="font-size:11px;color:#71717a;margin:0;"><?php echo date('d/m/Y'); ?> · <?php echo $_total; ?> <?php echo $_total === 1 ? 'notificação' : 'notificações'; ?></p>
      </div>
      <button onclick="fecharNotif()" style="background:none;border:none;cursor:pointer;color:#a1a1aa;padding:4px;">
        <span class="material-symbols-outlined" style="font-size:18px;">close</span>
      </button>
    </div>

    <div style="overflow-y:auto;flex:1;">
      <?php foreach ($_avisos as $_av): ?>
      <a href="<?php echo htmlspecialchars($_av['link']); ?>" class="notif-item">
        <div class="notif-icon" style="background:<?php echo $_av['bg']; ?>;">
          <span class="material-symbols-outlined" style="font-size:17px;color:<?php echo $_av['cor']; ?>;font-variation-settings:'FILL' 1;">
            <?php echo $_av['icon']; ?>
          </span>
        </div>
        <div style="flex:1;min-width:0;">
          <p style="font-size:12px;font-weight:700;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#18181b;">
            <?php echo htmlspecialchars($_av['titulo']); ?>
          </p>
          <p style="font-size:11px;color:#71717a;margin:2px 0 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?php echo htmlspecialchars($_av['sub']); ?>
          </p>
        </div>
        <span class="material-symbols-outlined" style="font-size:15px;color:#d4d4d8;flex-shrink:0;margin-top:2px;">chevron_right</span>
      </a>
      <?php endforeach; ?>
    </div>

    <div style="padding:10px 16px;border-top:1px solid #f4f4f5;flex-shrink:0;">
      <a href="calendario.php" style="font-size:11px;font-weight:700;color:#6366f1;text-decoration:none;display:flex;align-items:center;gap:4px;">
        <span class="material-symbols-outlined" style="font-size:14px;">calendar_month</span>
        Ver calendário completo
      </a>
    </div>
  </div>

  <button id="notif-btn" onclick="toggleNotif()" title="<?php echo $_total; ?> aviso(s) hoje">
    <span class="material-symbols-outlined" style="color:#fff;font-size:22px;font-variation-settings:'FILL' 1;">notifications</span>
    <span id="notif-badge"><?php echo $_total; ?></span>
  </button>
</div>

<script>
(function(){
  var panel = document.getElementById('notif-panel');
  var open  = false;

  function abrirNotif() {
    open = true;
    panel.classList.add('open');
    sessionStorage.setItem('notif_visto', '1');
  }

  function fecharNotifFn() {
    open = false;
    panel.classList.remove('open');
  }

  window.toggleNotif = function() { open ? fecharNotifFn() : abrirNotif(); };
  window.fecharNotif = fecharNotifFn;

  // Abre automaticamente uma vez por sessão se houver urgente
  var temUrgente = <?php echo $_temUrgente ? 'true' : 'false'; ?>;
  if (temUrgente && !sessionStorage.getItem('notif_visto')) {
    setTimeout(abrirNotif, 1200);
  }

  document.addEventListener('click', function(e) {
    if (open && !document.getElementById('notif-bell').contains(e.target)) {
      fecharNotifFn();
    }
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') fecharNotifFn();
  });
})();
</script>
<?php endif; ?>
