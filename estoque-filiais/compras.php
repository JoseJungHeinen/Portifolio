<?php 
require 'includes/db.php';
include 'includes/header.php'; 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SESSION['cargo'] == 'filial') {
    header("Location: index.php");
    exit;
}

/*
  Lógica de atualização de fornecedor na OC
  - grava nome do fornecedor na coluna existente 'fornecedor'
  - grava valor_total e frete
  - marca status como 'concluida'
*/
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['atualizar_oc'])) {
    $oc_id        = (int)$_POST['oc_id'];
    $fornecedor_id= (int)$_POST['fornecedor_id'];
    $valor        = (float)str_replace(',', '.', $_POST['valor_total']);
    $frete        = (float)str_replace(',', '.', $_POST['frete']);

    // pega nome_fantasia para gravar no campo 'fornecedor'
    $stmtF = $pdo->prepare("SELECT nome_fantasia FROM fornecedores WHERE id = ?");
    $stmtF->execute([$fornecedor_id]);
    $fornecedor_nome = $stmtF->fetchColumn();

    $stmt = $pdo->prepare("
        UPDATE ordens_compra 
           SET fornecedor  = ?, 
               valor_total = ?, 
               frete       = ?, 
               status      = 'concluida'
         WHERE id = ?
    ");
    $stmt->execute([$fornecedor_nome, $valor, $frete, $oc_id]);

    header("Location: compras.php?success=1");
    exit;
}

/*
  Lista de OCs
  - não usa fornecedor_id, só campo fornecedor já existente
*/
$ordens = $pdo->query("
    SELECT oc.*, 
           u.nome AS comprador, 
           f.nome AS filial,
           s.id   AS solicitacao_id 
      FROM ordens_compra oc 
      JOIN usuarios u      ON oc.comprador_id   = u.id 
      LEFT JOIN solicitacoes s ON oc.solicitacao_id = s.id 
      LEFT JOIN filiais f   ON s.filial_id      = f.id 
     ORDER BY 
        FIELD(oc.status, 'aberta','concluida','cancelada'),
        oc.data_emissao DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* lista de fornecedores para o select */
$fornecedores = $pdo->query("
    SELECT id, nome_fantasia 
      FROM fornecedores
     ORDER BY nome_fantasia
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Ordens de Compra</h1>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">Ordem de compra atualizada com sucesso.</div>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-striped align-middle">
        <thead>
            <tr>
                <th>OC #</th>
                <th>Filial</th>
                <th>Comprador</th>
                <th>Fornecedor</th>
                <th>Total</th>
                <th>Status</th>
                <th style="width: 160px;">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($ordens as $o): ?>
            <tr>
                <td>#<?php echo $o['id']; ?></td>
                <td><?php echo $o['filial'] ?? 'Manual'; ?></td>
                <td><?php echo $o['comprador']; ?></td>
                <td>
                    <?php 
                        $fornExibicao = $o['fornecedor'] ?? null;
                        echo $fornExibicao ? $fornExibicao : '<span class="text-muted">Não definido</span>'; 
                    ?>
                </td>
                <td>R$ <?php echo number_format($o['valor_total'] ?? 0, 2, ',', '.'); ?></td>
                <td>
                    <?php 
                        $status = $o['status'];
                        $badge  = 'secondary';
                        if ($status == 'aberta')     $badge = 'info';
                        if ($status == 'concluida')  $badge = 'success';
                        if ($status == 'cancelada')  $badge = 'danger';
                    ?>
                    <span class="badge bg-<?php echo $badge; ?>">
                        <?php echo ucfirst($status); ?>
                    </span>
                </td>
                <td>
                    <?php if ($o['status'] == 'aberta'): ?>
                    <button 
                        class="btn btn-sm btn-primary" 
                        data-bs-toggle="modal" 
                        data-bs-target="#editOC<?php echo $o['id']; ?>">
                        Finalizar OC
                    </button>

                    <!-- Modal Edit OC -->
                    <div class="modal fade" id="editOC<?php echo $o['id']; ?>" tabindex="-1">
                      <div class="modal-dialog">
                        <form method="POST" class="modal-content">
                          <div class="modal-header">
                              <h5 class="modal-title">
                                  Finalizar Ordem de Compra #<?php echo $o['id']; ?>
                              </h5>
                              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                          </div>
                          <div class="modal-body">
                            <input type="hidden" name="atualizar_oc" value="1">
                            <input type="hidden" name="oc_id" value="<?php echo $o['id']; ?>">

                            <div class="mb-3">
                                <label class="form-label">Fornecedor</label>
                                <select name="fornecedor_id" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($fornecedores as $f): ?>
                                    <option value="<?php echo $f['id']; ?>">
                                        <?php echo $f['nome_fantasia']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Valor Total (R$)</label>
                                <input 
                                    type="number" 
                                    step="0.01" 
                                    name="valor_total" 
                                    class="form-control" 
                                    required
                                    value="<?php echo number_format($o['valor_total'] ?? 0, 2, '.', ''); ?>"
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Frete (R$)</label>
                                <input 
                                    type="number" 
                                    step="0.01" 
                                    name="frete" 
                                    class="form-control" 
                                    value="<?php echo number_format($o['frete'] ?? 0, 2, '.', ''); ?>"
                                >
                            </div>
                          </div>
                          <div class="modal-footer">
                              <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                                  Cancelar
                              </button>
                              <button type="submit" class="btn btn-success">
                                  Gerar Ordem de Compra
                              </button>
                          </div>
                        </form>
                      </div>
                    </div>
                    <?php else: ?>
                        <small class="text-muted">Sem ações</small>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($ordens)): ?>
            <tr><td colspan="7" class="text-center py-4">Nenhuma OC registrada.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include 'includes/footer.php'; ?>
