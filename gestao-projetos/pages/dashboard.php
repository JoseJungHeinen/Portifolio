<?php

require_once '../config/session.php';

exigirLogin();
try {
    $pdo = db(); $uid = usuario()['id'];
    $tp = $pdo->query("SELECT COUNT(*) FROM projetos WHERE status+0 NOT IN (4,5)")->fetchColumn();
    $s=$pdo->prepare("SELECT COUNT(*) FROM tarefas WHERE responsavel_id=? AND status!='done'"); $s->execute([$uid]); $tt=$s->fetchColumn();
    $s=$pdo->prepare("SELECT COUNT(*) FROM tarefas WHERE responsavel_id=? AND status='done' AND DATE(atualizado_em)=CURDATE()"); $s->execute([$uid]); $ch=$s->fetchColumn();
    $tm=$pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo=1")->fetchColumn();
    $projetos=$pdo->query("SELECT p.id,p.nome,p.status,ROUND((SELECT COUNT(*) FROM tarefas WHERE projeto_id=p.id AND status='done')/GREATEST((SELECT COUNT(*) FROM tarefas WHERE projeto_id=p.id),1)*100) AS progresso FROM projetos p ORDER BY p.criado_em DESC LIMIT 5")->fetchAll();
    $s=$pdo->prepare("SELECT t.id,t.titulo,t.status,t.prioridade,COALESCE(t.data_fim,t.prazo) AS prazo,p.nome AS projeto FROM tarefas t LEFT JOIN projetos p ON p.id=t.projeto_id WHERE t.responsavel_id=? ORDER BY t.atualizado_em DESC LIMIT 6"); $s->execute([$uid]); $tarefas=$s->fetchAll();
} catch(PDOException $e){ $projetos=$tarefas=[]; $tp=$tt=$ch=$tm='-'; }
$pcor=['Em andamento'=>'bg-primary','Revisão'=>'bg-amber-500','Planejado'=>'bg-zinc-400','Concluído'=>'bg-emerald-500','Pausado'=>'bg-zinc-300'];
$scls=['todo'=>'text-zinc-500 bg-zinc-100','doing'=>'text-blue-600 bg-blue-50','review'=>'text-amber-600 bg-amber-50','done'=>'text-emerald-700 bg-emerald-50','backlog'=>'text-zinc-400 bg-zinc-50'];
$slbl=['todo'=>'A Fazer','doing'=>'Em andamento','review'=>'Revisão','done'=>'Concluído','backlog'=>'Backlog'];
$pcls=['Alta'=>'text-red-600 bg-red-50','Média'=>'text-amber-600 bg-amber-50','Baixa'=>'text-zinc-500 bg-zinc-100'];
?>
<!DOCTYPE html><html class="light" lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | Exemplo Gestão de Projetos</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Epilogue:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/app.css">

</head><body class="bg-surface text-on-surface font-body antialiased">
<?php include '../config/topnav.php'; ?>
<?php include '../config/sidebar.php'; ?>
<?php include '../config/notificacoes.php'; ?>
<div class="app-shell">
<main class="app-main p-8 page-fade-in"><div class="max-w-7xl mx-auto">

<div class="mb-10">
  <div class="flex items-center gap-2 mb-2"><span class="w-8 h-[2px] bg-primary inline-block"></span><span class="text-[10px] font-label font-bold uppercase tracking-widest text-secondary">Painel Principal</span></div>
  <div class="flex items-end justify-between flex-wrap gap-4">
    <div><h1 class="font-headline text-4xl font-extrabold tracking-tight">Bom dia, <?php echo htmlspecialchars(explode(' ',usuario()['nome'])[0]); ?></h1><p class="text-secondary mt-1 text-sm">Resumo de hoje.</p></div>
    <a href="novo-projeto.php" class="flex items-center gap-2 bg-gradient-to-b from-primary-container to-primary text-white font-headline font-bold uppercase tracking-widest text-xs px-6 py-3 hover:opacity-90 transition-all"><span class="material-symbols-outlined text-sm">add</span> Novo Projeto</a>
  </div>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-10">
<?php foreach([['Projetos Ativos',$tp,'folder_open','text-primary'],['Tarefas Pendentes',$tt,'assignment_late','text-amber-500'],['Concluídas Hoje',$ch,'task_alt','text-emerald-600'],['Membros Ativos',$tm,'group','text-blue-500']] as [$l,$v,$ic,$c]): ?>
<div class="bg-white rounded-xl p-5 shadow-sm border border-zinc-100 hover:border-primary/20 transition-colors">
  <span class="material-symbols-outlined <?php echo $c; ?> text-2xl" style="font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24"><?php echo $ic; ?></span>
  <p class="font-headline text-3xl font-extrabold mt-3"><?php echo $v; ?></p>
  <p class="text-xs text-secondary mt-1"><?php echo $l; ?></p>
</div>
<?php endforeach; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
<section>
  <div class="flex items-center justify-between mb-4"><h2 class="font-headline text-lg font-bold">Projetos Recentes</h2><a href="kanban.php" class="text-xs text-primary font-bold hover:underline flex items-center gap-1">Ver todos <span class="material-symbols-outlined text-sm">arrow_forward</span></a></div>
  <?php if(empty($projetos)): ?><div class="bg-white rounded-xl p-6 text-center text-secondary text-sm border border-zinc-100">Nenhum projeto. <a href="novo-projeto.php" class="text-primary font-bold">Criar agora</a></div>
  <?php else: ?><div class="space-y-3"><?php foreach($projetos as $p): $cor=$pcor[$p['status']]??"bg-zinc-400"; ?>
    <a href="kanban.php" class="block bg-white rounded-xl p-4 shadow-sm border border-zinc-100 hover:border-primary/20 transition-colors">
      <div class="flex items-center justify-between mb-2"><p class="font-medium text-sm truncate pr-2"><?php echo htmlspecialchars($p['nome']); ?></p><span class="text-xs text-secondary bg-surface-container px-2 py-0.5 rounded-full flex-shrink-0"><?php echo $p['status']; ?></span></div>
      <div class="flex items-center gap-3"><div class="flex-1 h-1.5 bg-surface-container-high rounded-full overflow-hidden"><div class="h-full <?php echo $cor; ?> rounded-full" style="width:<?php echo $p['progresso']; ?>%"></div></div><span class="text-xs font-bold text-secondary"><?php echo $p['progresso']; ?>%</span></div>
    </a>
  <?php endforeach; ?></div><?php endif; ?>
</section>
<section>
  <div class="flex items-center justify-between mb-4"><h2 class="font-headline text-lg font-bold">Minhas Tarefas</h2><a href="minhas-tarefas.php" class="text-xs text-primary font-bold hover:underline flex items-center gap-1">Ver todas <span class="material-symbols-outlined text-sm">arrow_forward</span></a></div>
  <?php if(empty($tarefas)): ?><div class="bg-white rounded-xl p-6 text-center text-secondary text-sm border border-zinc-100">Nenhuma tarefa atribuída.</div>
  <?php else: ?><div class="space-y-3"><?php foreach($tarefas as $t): ?>
    <div class="bg-white rounded-xl p-4 shadow-sm border border-zinc-100 hover:border-primary/20 transition-colors">
      <p class="font-medium text-sm mb-2 <?php echo $t['status']==='done'?'line-through text-secondary':''; ?>"><?php echo htmlspecialchars($t['titulo']); ?></p>
      <div class="flex items-center gap-2 flex-wrap">
        <span class="text-[10px] text-secondary"><?php echo htmlspecialchars($t['projeto']??''); ?></span>
        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?php echo $pcls[$t['prioridade']]??""; ?>"><?php echo $t['prioridade']; ?></span>
        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?php echo $scls[$t['status']]??""; ?>"><?php echo $slbl[$t['status']]??$t['status']; ?></span>
        <?php if($t['prazo']): ?><span class="text-[10px] text-secondary ml-auto inline-flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">calendar_month</span><?php echo $t['prazo']; ?></span><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?></div><?php endif; ?>
</section>
</div>

</div></main></div>
<script src="../assets/js/app.js"></script></body></html>


