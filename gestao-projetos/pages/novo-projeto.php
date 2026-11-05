<?php
require_once '../config/session.php';
exigirLogin();
$erro = ''; $ok = '';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $nome = trim($_POST['nome']??'');
    if(!$nome){ $erro='Nome do projeto é obrigatório.'; }
    else {
        try {
            $stmt = db()->prepare('INSERT INTO projetos (nome,descricao,cliente,status,prioridade,prazo,criado_por) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$nome,$_POST['descricao']??null,$_POST['cliente']??null,$_POST['status']??'Planejado',$_POST['prioridade']??'Média',$_POST['prazo']??null,usuario()['id']]);
            header('Location: kanban.php?ok=projeto_criado'); exit;
        } catch(PDOException $e){ $erro='Erro ao salvar: '.$e->getMessage(); }
    }
}
?>
<!DOCTYPE html><html class="light" lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Novo Projeto | Exemplo DevSystems</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Epilogue:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/app.css">

</head><body class="bg-surface text-on-surface font-body antialiased">
<?php include '../config/topnav.php'; ?>
<?php include '../config/notificacoes.php'; ?>
<div class="app-shell"><?php include '../config/sidebar.php'; ?>
<main class="app-main p-8 page-fade-in"><div class="max-w-3xl mx-auto">

<div class="mb-8">
  <a href="kanban.php" class="flex items-center gap-1 text-secondary hover:text-primary transition-colors text-sm mb-4"><span class="material-symbols-outlined text-sm">arrow_back</span> Voltar</a>
  <div class="flex items-center gap-2 mb-2"><span class="w-8 h-[2px] bg-primary inline-block"></span><span class="text-[10px] font-label font-bold uppercase tracking-widest text-secondary">Projetos</span></div>
  <h1 class="font-headline text-4xl font-extrabold tracking-tight">Novo Projeto</h1>
</div>

<?php if($erro): ?><div class="mb-6 flex items-center gap-2 bg-red-50 border-l-2 border-primary px-4 py-3 text-sm text-red-700"><span class="material-symbols-outlined text-sm">error</span><?php echo htmlspecialchars($erro); ?></div><?php endif; ?>

<form method="POST" class="bg-white rounded-xl p-8 shadow-sm border border-zinc-100 space-y-5">
  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div class="md:col-span-2 space-y-1.5">
      <label class="text-[10px] font-bold uppercase tracking-wider text-secondary">Nome do Projeto *</label>
      <input name="nome" required value="<?php echo htmlspecialchars($_POST['nome']??''); ?>" placeholder="Ex: Site Loja Fashion" class="input-field">
    </div>
    <div class="md:col-span-2 space-y-1.5">
      <label class="text-[10px] font-bold uppercase tracking-wider text-secondary">Descrição</label>
      <textarea name="descricao" rows="3" placeholder="Descreva o projeto..." class="input-field resize-none"><?php echo htmlspecialchars($_POST['descricao']??''); ?></textarea>
    </div>
    <div class="space-y-1.5">
      <label class="text-[10px] font-bold uppercase tracking-wider text-secondary">Cliente</label>
      <input name="cliente" value="<?php echo htmlspecialchars($_POST['cliente']??''); ?>" placeholder="Nome do cliente" class="input-field">
    </div>
    <div class="space-y-1.5">
      <label class="text-[10px] font-bold uppercase tracking-wider text-secondary">Prazo</label>
      <input name="prazo" type="date" value="<?php echo htmlspecialchars($_POST['prazo']??''); ?>" class="input-field">
    </div>
    <div class="space-y-1.5">
      <label class="text-[10px] font-bold uppercase tracking-wider text-secondary">Status</label>
      <select name="status" class="input-field">
        <?php foreach(['Planejado','Em andamento','Revisão','Concluído','Pausado'] as $s): ?>
        <option <?php echo ($_POST['status']??'Planejado')===$s?'selected':''; ?>><?php echo $s; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="space-y-1.5">
      <label class="text-[10px] font-bold uppercase tracking-wider text-secondary">Prioridade</label>
      <select name="prioridade" class="input-field">
        <?php foreach(['Baixa','Média','Alta'] as $p): ?>
        <option <?php echo ($_POST['prioridade']??'Média')===$p?'selected':''; ?>><?php echo $p; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="flex items-center gap-3 pt-2">
    <button type="submit" class="flex items-center gap-2 bg-gradient-to-b from-primary-container to-primary text-white font-headline font-bold uppercase tracking-widest text-xs px-6 py-3 hover:opacity-90 transition-all active:scale-[0.98]">
      <span class="material-symbols-outlined text-sm">save</span> Criar Projeto
    </button>
    <a href="kanban.php" class="px-6 py-3 text-xs font-bold uppercase tracking-wider text-secondary hover:text-on-surface transition-colors">Cancelar</a>
  </div>
</form>

</div></main></div>
<script src="../assets/js/app.js"></script></body></html>
