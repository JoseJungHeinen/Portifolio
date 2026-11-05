<?php
require 'includes/db.php';
include 'includes/header.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// INSERIR / ATUALIZAR FORNECEDOR
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id           = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $nomeFantasia = trim($_POST['nome_fantasia']);
    $cnpj         = trim($_POST['cnpj']);
    $contato      = trim($_POST['contato']);
    $telefone     = trim($_POST['telefone']);
    $email        = trim($_POST['email']);
    $observacao   = trim($_POST['observacao']);

    if ($id) {
        $stmt = $pdo->prepare("
            UPDATE fornecedores 
               SET nome_fantasia = ?, cnpj = ?, contato = ?, telefone = ?, email = ?, observacao = ?
             WHERE id = ?
        ");
        $stmt->execute([$nomeFantasia, $cnpj, $contato, $telefone, $email, $observacao, $id]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO fornecedores (nome_fantasia, cnpj, contato, telefone, email, observacao)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nomeFantasia, $cnpj, $contato, $telefone, $email, $observacao]);
    }

    header("Location: fornecedores.php?success=1");
    exit;
}

// EXCLUIR (opcional)
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM fornecedores WHERE id = ?")->execute([$id]);
    header("Location: fornecedores.php?deleted=1");
    exit;
}

// LISTAR (AJUSTE: usa nome_fantasia)
$fornecedores = $pdo
    ->query("SELECT * FROM fornecedores ORDER BY nome_fantasia ASC")
    ->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-4">
    <div>
        <h1 class="h3 mb-0">Fornecedores</h1>
        <p class="text-muted small">Cadastro de fornecedores usados nas compras.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalFornecedor">
        + Novo Fornecedor
    </button>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">Fornecedor salvo com sucesso.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-warning">Fornecedor excluído.</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">ID</th>
                        <th>Nome Fantasia</th>
                        <th>CNPJ</th>
                        <th>Contato</th>
                        <th>Telefone</th>
                        <th>Email</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fornecedores as $f): ?>
                    <tr>
                        <td class="ps-4"><?php echo $f['id']; ?></td>
                        <td><?php echo $f['nome_fantasia']; ?></td>
                        <td><?php echo $f['cnpj']; ?></td>
                        <td><?php echo $f['contato']; ?></td>
                        <td><?php echo $f['telefone']; ?></td>
                        <td><?php echo $f['email']; ?></td>
                        <td class="text-end pe-4">
                            <button 
                                class="btn btn-sm btn-light border me-1"
                                data-bs-toggle="modal"
                                data-bs-target="#modalFornecedor"
                                data-id="<?php echo $f['id']; ?>"
                                data-nome_fantasia="<?php echo htmlspecialchars($f['nome_fantasia'] ?? ''); ?>"
                                data-cnpj="<?php echo htmlspecialchars($f['cnpj'] ?? ''); ?>"
                                data-contato="<?php echo htmlspecialchars($f['contato'] ?? ''); ?>"
                                data-telefone="<?php echo htmlspecialchars($f['telefone'] ?? ''); ?>"
                                data-email="<?php echo htmlspecialchars($f['email'] ?? ''); ?>"
                                data-observacao="<?php echo htmlspecialchars($f['observacao'] ?? ''); ?>"

                            >
                                Editar
                            </button>
                            <a href="fornecedores.php?delete=<?php echo $f['id']; ?>" 
                               class="btn btn-sm btn-outline-danger"
                               onclick="return confirm('Excluir fornecedor?');">
                                Excluir
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($fornecedores)): ?>
                    <tr><td colspan="7" class="text-center py-4">Nenhum fornecedor cadastrado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Cadastro/Editar Fornecedor -->
<div class="modal fade" id="modalFornecedor" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Fornecedor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" id="forn_id">

                <div class="mb-3">
                    <label class="form-label">Nome Fantasia</label>
                    <input type="text" name="nome_fantasia" id="forn_nome_fantasia" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">CNPJ</label>
                    <input type="text" name="cnpj" id="forn_cnpj" class="form-control">
                </div>

                <div class="mb-3">
                    <label class="form-label">Contato</label>
                    <input type="text" name="contato" id="forn_contato" class="form-control">
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Telefone</label>
                        <input type="text" name="telefone" id="forn_telefone" class="form-control">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="forn_email" class="form-control">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Observação</label>
                    <textarea name="observacao" id="forn_observacao" rows="2" class="form-control"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
                <button class="btn btn-primary" type="submit">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
// preenche modal ao clicar em "Editar"
var modalF = document.getElementById('modalFornecedor');
modalF.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    if (!button) return;

    var id   = button.getAttribute('data-id') || '';
    document.getElementById('forn_id').value              = id;
    document.getElementById('forn_nome_fantasia').value   = button.getAttribute('data-nome_fantasia') || '';
    document.getElementById('forn_cnpj').value            = button.getAttribute('data-cnpj') || '';
    document.getElementById('forn_contato').value         = button.getAttribute('data-contato') || '';
    document.getElementById('forn_telefone').value        = button.getAttribute('data-telefone') || '';
    document.getElementById('forn_email').value           = button.getAttribute('data-email') || '';
    document.getElementById('forn_observacao').value      = button.getAttribute('data-observacao') || '';
});
</script>

<?php include 'includes/footer.php'; ?>
