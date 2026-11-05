<?php 
require 'includes/db.php';
include 'includes/header.php'; 

if ($_SESSION['cargo'] != 'admin') {
    echo "<div class='alert alert-danger'>Acesso restrito à administração.</div>";
    include 'includes/footer.php';
    exit;
}

$logs = $pdo->query("SELECT l.*, u.nome as usuario_nome FROM logs_auditoria l JOIN usuarios u ON l.usuario_id = u.id ORDER BY l.data_hora DESC LIMIT 100")->fetchAll();
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Rastreabilidade e Auditoria</h1>
</div>

<div class="table-responsive">
    <table class="table table-sm table-striped">
        <thead>
            <tr>
                <th>Data/Hora</th>
                <th>Usuário</th>
                <th>Ação</th>
                <th>Entidade</th>
                <th>Detalhes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $l): ?>
            <tr>
                <td><?php echo date('d/m/Y H:i:s', strtotime($l['data_hora'])); ?></td>
                <td><?php echo $l['usuario_nome']; ?></td>
                <td>
                    <span class="badge bg-<?php 
                        echo ($l['acao'] == 'APROVAR' ? 'success' : ($l['acao'] == 'REJEITAR' ? 'danger' : 'info')); 
                    ?>"><?php echo $l['acao']; ?></span>
                </td>
                <td><?php echo strtoupper($l['tabela_nome']); ?> (#<?php echo $l['registro_id']; ?>)</td>
                <td><?php echo $l['detalhes']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include 'includes/footer.php'; ?>
