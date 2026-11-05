<?php
require_once '../config/session.php';
exigirLogin();
$erro = '';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $nome=$_POST['nome']??''; $email=$_POST['email']??''; $senha=$_POST['senha']??'';
    if(!$nome||!$email||!$senha){ $erro='Nome, e-mail e senha são obrigatórios.'; }
    else {
        try {
            $chk=db()->prepare('SELECT id FROM usuarios WHERE email=?'); $chk->execute([$email]);
            if($chk->fetch()){ $erro='E-mail já cadastrado.'; }
            else {
                $hash=password_hash($senha,PASSWORD_DEFAULT);
                $stmt=db()->prepare('INSERT INTO usuarios (nome,email,senha,cargo,perfil) VALUES (?,?,?,?,?)');
                $stmt->execute([$nome,$email,$hash,$_POST['cargo']??null,$_POST['perfil']??'Membro']);
                header('Location: equipe.php?ok=usuario_criado'); exit;
            }
        } catch(PDOException $e){ $erro='Erro: '.$e->getMessage(); }
    }
}
?>
<!DOCTYPE html><html class="light" lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Novo Usuário | Exemplo DevSystems</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Epilogue:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/app.css">

</head><body class="bg-surface text-on-surface font-body antialiased">
<?php include '../config/topnav.php'; ?>
<?php include '../config/notificacoes.php'; ?>
<div class="app-shell"><?php include '../config/sidebar.php'; ?>
<main class="app-main p-8 page-fade-in"><div class="max-w-2xl mx-auto">

<div class="mb-8">
  <a href="equipe.php" class="flex items-center gap-1 text-secondary hover:text-primary transition-colors text-sm mb-4"><span class="material-symbols-outlined text-sm">arrow_back</span> Voltar</a>
  <h1 class="font-headline text-3xl font-extrabold tracking-tight">Novo Usuário</h1>
</div>

<?php if($erro): ?><div class="mb-6 flex items-center gap-2 bg-red-50 border-l-2 border-primary px-4 py-3 text-sm text-red-700"><span class="material-symbols-outlined text-sm">error</span><?php echo htmlspecialchars($erro); ?></div><?php endif; ?>

<form method="POST" class="bg-white rounded-xl p-8 shadow-sm border border-zinc-100 space-y-5">
  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div class="md:col-span-2 space-y-1.5">
      <label class="text-[10px] font-bold uppercase tracking-wider text-secondary">Nome Completo *</label>
      <input name="nome" required value="<?php echo htmlspecialchars($_POST['nome']??''); ?>" placeholder="João da Silva" class="input-field">
    </div>
    <div class="space-y-1.5">
      <label class="text-[10px] font-bold uppercase tracking-wider text-secondary">E-mail *</label>
      <input name="email" type="email" required value="<?php echo htmlspecialchars($_POST['email']??''); ?>" placeholder="joao@empresa.com" class="input-field">
    </div>
    <div class="space-y-1.5">
      <label class="text-[10px] font-bold uppercase tracking-wider text-secondary">Cargo</label>
      <input name="cargo" value="<?php echo htmlspecialchars($_POST['cargo']??''); ?>" placeholder="Designer, Desenvolvedor..." class="input-field">
    </div>
    <div class="space-y-1.5">
      <label class="text-[10px] font-bold uppercase tracking-wider text-secondary">Perfil</label>
      <select name="perfil" class="input-field">
        <?php foreach(['Admin','Gerente','Membro','Visualizador'] as $p): ?>
        <option <?php echo ($_POST['perfil']??'Membro')===$p?'selected':''; ?>><?php echo $p; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="space-y-1.5">
      <label class="text-[10px] font-bold uppercase tracking-wider text-secondary">Senha *</label>
      <input name="senha" type="password" required placeholder="Mínimo 6 caracteres" class="input-field">
    </div>
  </div>
  <div class="flex gap-3 pt-2">
    <button type="submit" class="flex items-center gap-2 bg-gradient-to-b from-primary-container to-primary text-white font-headline font-bold uppercase tracking-widest text-xs px-6 py-3 hover:opacity-90 transition-all active:scale-[0.98]">
      <span class="material-symbols-outlined text-sm">person_add</span> Cadastrar
    </button>
    <a href="equipe.php" class="px-6 py-3 text-xs font-bold uppercase tracking-wider text-secondary hover:text-on-surface transition-colors">Cancelar</a>
  </div>
</form>

</div></main></div>
<script src="../assets/js/app.js"></script></body></html>
