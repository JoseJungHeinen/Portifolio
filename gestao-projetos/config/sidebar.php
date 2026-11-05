<?php
// config/sidebar.php - include nas paginas internas
$atual = basename($_SERVER['PHP_SELF']);
$nav = [
    ['dashboard.php',      'dashboard',            'Painel'],
    ['kanban.php',         'folder_open',          'Projetos'],
    ['minhas-tarefas.php', 'assignment_turned_in', 'Tarefas'],
    ['cronograma.php',     'event_note',           'Cronograma'],
    ['equipe.php',         'group',                'Equipe'],
    ['calendario.php',     'calendar_today',       'Calendario'],
    ['backlog.php',        'speed',                'Sprints'],
];
?>
<aside id="app-sidebar" class="app-sidebar">
  <div class="sidebar-user-wrap mb-8 px-2">
    <?php
      $nome_completo = trim(htmlspecialchars(usuario()['nome'] ?? 'Usuario'));
      $partes = explode(' ', $nome_completo);
      $meio   = (int)ceil(count($partes) / 2);
      $linha1 = implode(' ', array_slice($partes, 0, $meio));
      $linha2 = implode(' ', array_slice($partes, $meio));
    ?>
    <p class="sidebar-user text-[11px] uppercase tracking-widest text-secondary font-bold leading-snug">
      <?= $linha1 ?>
    </p>
    <?php if ($linha2): ?>
    <p class="sidebar-user text-[11px] uppercase tracking-widest text-secondary font-bold leading-snug">
      <?= $linha2 ?>
    </p>
    <?php endif; ?>
    <p class="sidebar-user mt-1 text-[10px] text-zinc-400">V1.0.0 · Exemplo DevSystems</p>
  </div>

  <nav class="flex-1 space-y-1">
    <?php foreach ($nav as [$page, $icon, $label]):
      $ativo = ($atual === $page)
        ? 'bg-white text-primary font-bold shadow-sm'
        : 'text-zinc-600 hover:bg-zinc-100 hover:translate-x-1';
    ?>
    <a class="flex items-center gap-3 rounded-md px-4 py-3 transition-all duration-200 <?= $ativo ?>"
       href="<?= $page ?>">
      <span class="material-symbols-outlined"><?= $icon ?></span>
      <span class="sidebar-label"><?= $label ?></span>
    </a>
    <?php endforeach; ?>

    <div class="px-3 pb-2 pt-3">
      <span class="sidebar-section-title text-[10px] font-bold uppercase tracking-widest text-zinc-400">Administracao</span>
    </div>

    <a class="flex items-center gap-3 rounded-md px-4 py-3 transition-all duration-200 <?= $atual === 'configuracoes.php' ? 'bg-white text-primary font-bold shadow-sm' : 'text-zinc-600 hover:bg-zinc-100 hover:translate-x-1' ?>"
       href="configuracoes.php">
      <span class="material-symbols-outlined">settings</span>
      <span class="sidebar-label">Configuracoes</span>
    </a>
  </nav>

  <div class="sidebar-cta-wrap mt-4 px-1">
    <a href="novo-projeto.php"
       class="flex w-full items-center justify-center gap-2 rounded-md bg-gradient-to-b from-primary-container to-primary px-4 py-3 text-xs font-bold uppercase tracking-widest text-white font-headline transition-all hover:opacity-90">
      <span class="material-symbols-outlined text-sm">add</span>
      <span class="sidebar-cta-label">Novo Projeto</span>
    </a>
  </div>

  <a href="logout.php"
     class="sidebar-logout-link mt-3 flex items-center justify-center gap-2 py-2 text-sm text-zinc-400 transition-colors hover:text-primary">
    <span class="material-symbols-outlined text-sm">logout</span>
    <span class="sidebar-logout-label">Sair</span>
  </a>
</aside>
