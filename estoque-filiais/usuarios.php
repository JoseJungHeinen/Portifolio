<?php 
require 'includes/db.php';
include 'includes/header.php'; 

if ($_SESSION['cargo'] != 'admin') {
    echo "<div class='alert alert-danger'>Acesso negado.</div>";
    include 'includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['novo_usuario'])) {
    $nome = $_POST['nome'];
    $email = $_POST['email'];
    $senha = $_POST['senha'];
    $cargo = $_POST['cargo'];
    $setor = $_POST['setor'];
    $filial_id = $_POST['filial_id'];

    $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, cargo, setor, filial_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$nome, $email, $senha, $cargo, $setor, $filial_id]);
    header("Location: usuarios.php?success=1");
    exit;
}

$usuarios = $pdo->query("SELECT u.*, f.nome as filial_nome FROM usuarios u LEFT JOIN filiais f ON u.filial_id = f.id")->fetchAll();
$filiais = $pdo->query("SELECT * FROM filiais")->fetchAll();
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Gestão de Usuários</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#novoUsuarioModal">Novo Usuário</button>
</div>

<div class="table-responsive">
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Nome</th>
                <th>Email</th>
                <th>Cargo</th>
                <th>Setor</th>
                <th>Filial</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?php echo $u['nome']; ?></td>
                <td><?php echo $u['email']; ?></td>
                <td><?php echo ucfirst($u['cargo']); ?></td>
                <td><?php echo $u['setor']; ?></td>
                <td><?php echo $u['filial_nome'] ?? 'N/A'; ?></td>
                <td><button class="btn btn-sm btn-outline-secondary">Editar</button></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="modal fade" id="novoUsuarioModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Novo Usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="novo_usuario" value="1">
        <div class="mb-3"><label>Nome</label><input type="text" name="nome" class="form-control" required></div>
        <div class="mb-3"><label>Email</label><input type="email" name="email" class="form-control" required></div>
        <div class="mb-3"><label>Senha</label><input type="password" name="senha" class="form-control" required></div>
        <div class="mb-3"><label>Cargo</label>
            <select name="cargo" class="form-select">
                <option value="admin">Admin</option>
                <option value="comprador">Comprador</option>
                <option value="filial">Filial</option>
            </select>
        </div>
        <div class="mb-3"><label>Setor</label><input type="text" name="setor" class="form-control"></div>
        <div class="mb-3"><label>Filial</label>
            <select name="filial_id" class="form-select">
                <?php foreach ($filiais as $f): ?>
                <option value="<?php echo $f['id']; ?>"><?php echo $f['nome']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary">Salvar</button></div>
    </form>
  </div>
</div>
<?php include 'includes/footer.php'; ?>
