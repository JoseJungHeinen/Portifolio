<?php 
require 'includes/db.php';
include 'includes/header.php'; 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$filial_id = $_SESSION['filial_id'];
$user_id   = $_SESSION['user_id'];

$erro = null;

// Processar aprovação/negação
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['processar_solicitacao'])) {
    $solicitacao_id = (int)$_POST['solicitacao_id'];
    $acao           = $_POST['acao']; // 'aprovar' ou 'rejeitar'
    $justificativa  = $_POST['justificativa'];
    $status         = ($acao == 'aprovar') ? 'aprovado' : 'rejeitado';

    $pdo->beginTransaction();
    try {
        // status + justificativa
        $stmt = $pdo->prepare("
            UPDATE solicitacoes 
               SET status = ?, justificativa_comprador = ? 
             WHERE id = ?
        ");
        $stmt->execute([$status, $justificativa, $solicitacao_id]);

        if ($acao == 'aprovar') {
            // atualiza quantidades aprovadas
            $itens_ids      = $_POST['item_id'];
            $qtds_aprovadas = $_POST['qtd_aprovada'];

            $stmt_item = $pdo->prepare("
                UPDATE itens_solicitacao 
                   SET quantidade_aprovada = ? 
                 WHERE id = ?
            ");
            for ($i = 0; $i < count($itens_ids); $i++) {
                $qtd_aprov = (float)$qtds_aprovadas[$i];
                $item_id   = (int)$itens_ids[$i];
                $stmt_item->execute([$qtd_aprov, $item_id]);
            }

            // filial da solicitação
            $stmtFilial = $pdo->prepare("SELECT filial_id FROM solicitacoes WHERE id = ?");
            $stmtFilial->execute([$solicitacao_id]);
            $filial_solic = (int)$stmtFilial->fetchColumn();

            // itens aprovados
            $stmtItens = $pdo->prepare("
                SELECT produto_id, quantidade_aprovada 
                  FROM itens_solicitacao 
                 WHERE solicitacao_id = ?
            ");
            $stmtItens->execute([$solicitacao_id]);
            $itensAprov = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

            // estoque
            $stmtEstSel = $pdo->prepare("
                SELECT quantidade 
                  FROM estoque 
                 WHERE produto_id = ? AND filial_id = ?
            ");
            $stmtEstIns = $pdo->prepare("
                INSERT INTO estoque (produto_id, filial_id, quantidade)
                VALUES (?, ?, ?)
            ");
            $stmtEstUpd = $pdo->prepare("
                UPDATE estoque 
                   SET quantidade = quantidade + ? 
                 WHERE produto_id = ? AND filial_id = ?
            ");

            foreach ($itensAprov as $it) {
                $produto_id = (int)$it['produto_id'];
                $qtd_aprov  = (float)$it['quantidade_aprovada'];
                if ($qtd_aprov <= 0) continue;

                $stmtEstSel->execute([$produto_id, $filial_solic]);
                $existe = $stmtEstSel->fetchColumn();

                if ($existe === false) {
                    $stmtEstIns->execute([$produto_id, $filial_solic, $qtd_aprov]);
                } else {
                    $stmtEstUpd->execute([$qtd_aprov, $produto_id, $filial_solic]);
                }
            }

            // ordem de compra
            $stmt_oc = $pdo->prepare("
                INSERT INTO ordens_compra (solicitacao_id, comprador_id, status) 
                VALUES (?, ?, 'aberta')
            ");
            $stmt_oc->execute([$solicitacao_id, $user_id]);
        }

        // log
        $detalhes_log = "Solicitação #$solicitacao_id $status. Justificativa: $justificativa";
        $stmt_log = $pdo->prepare("
            INSERT INTO logs_auditoria 
                (tabela_nome, registro_id, usuario_id, acao, detalhes) 
            VALUES ('solicitacoes', ?, ?, ?, ?)
        ");
        $stmt_log->execute([$solicitacao_id, $user_id, strtoupper($acao), $detalhes_log]);

        $pdo->commit();
        header("Location: solicitacoes.php?success=1");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $erro = "Erro ao processar: " . $e->getMessage();
    }
}

// Buscar APENAS solicitações pendentes
if ($_SESSION['cargo'] == 'filial') {
    $stmt = $pdo->prepare("
        SELECT s.*, u.nome AS solicitante
          FROM solicitacoes s
          JOIN usuarios u ON s.usuario_id = u.id
         WHERE s.filial_id = ?
           AND s.status = 'pendente'
         ORDER BY s.data_criacao DESC
    ");
    $stmt->execute([$filial_id]);
} else {
    $stmt = $pdo->query("
        SELECT s.*, f.nome AS filial, u.nome AS solicitante
          FROM solicitacoes s
          JOIN filiais f  ON s.filial_id = f.id
          JOIN usuarios u ON s.usuario_id = u.id
         WHERE s.status = 'pendente'
         ORDER BY s.data_criacao DESC
    ");
}
$solicitacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4">
    <div>
        <h1 class="h3 mb-0 text-gray-800">Gestão de Solicitações</h1>
        <p class="text-muted small">Acompanhe e processe os pedidos das unidades.</p>
    </div>
    <?php if ($_SESSION['cargo'] == 'filial'): ?>
        <a href="nova_solicitacao.php" class="btn btn-primary shadow-sm">
            <i class="fas fa-plus me-2"></i> Nova Solicitação
        </a>
    <?php endif; ?>
</div>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?php echo $erro; ?></div>
<?php endif; ?>
<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">Solicitação processada com sucesso.</div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">ID</th>
                        <th>Origem</th>
                        <th>Solicitante</th>
                        <th>Data</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($solicitacoes as $s): ?>
                    <tr>
                        <td class="ps-4"><strong>#<?php echo $s['id']; ?></strong></td>
                        <td><?php echo $s['filial'] ?? 'Sua Unidade'; ?></td>
                        <td><?php echo $s['solicitante']; ?></td>
                        <td><small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($s['data_criacao'])); ?></small></td>
                        <td>
                            <span class="badge bg-warning text-dark">
                                <?php echo ucfirst($s['status']); ?>
                            </span>
                        </td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-light border me-1" data-bs-toggle="modal" data-bs-target="#modalDetalhes<?php echo $s['id']; ?>">
                                <i class="fas fa-eye me-1"></i> Detalhes
                            </button>
                        </td>
                    </tr>

                    <!-- Modal Detalhes -->
                    <div class="modal fade" id="modalDetalhes<?php echo $s['id']; ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                                <div class="modal-header border-0 p-4 pb-0">
                                    <h5 class="font-weight-bold">Detalhes da Solicitação #<?php echo $s['id']; ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body p-4">
                                    <h6 class="text-uppercase small font-weight-bold text-muted mb-3">Itens Solicitados:</h6>
                                    <div class="list-group list-group-flush mb-4 border rounded">
                                        <?php 
                                            $stmt_it_det = $pdo->prepare("
                                                SELECT i.*, p.nome 
                                                  FROM itens_solicitacao i 
                                                  JOIN produtos p ON i.produto_id = p.id 
                                                 WHERE i.solicitacao_id = ?
                                            ");
                                            $stmt_it_det->execute([$s['id']]);
                                            foreach ($stmt_it_det->fetchAll(PDO::FETCH_ASSOC) as $it):
                                        ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                                            <div>
                                                <span class="font-weight-bold"><?php echo $it['nome']; ?></span>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-light text-dark border">Ped: <?php echo $it['quantidade']; ?></span>
                                                <?php if($it['quantidade_aprovada'] !== null): ?>
                                                    <span class="badge bg-primary">Apr: <?php echo $it['quantidade_aprovada']; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($s['justificativa_comprador']): ?>
                                        <div class="p-3 bg-light rounded-3 border-left-primary">
                                            <strong class="small text-uppercase text-primary d-block mb-1">Justificativa do Comprador:</strong>
                                            <p class="mb-0 small"><?php echo $s['justificativa_comprador']; ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="modal-footer border-0 p-4 pt-0">
                                    <button type="button" class="btn btn-light w-100" data-bs-dismiss="modal">Fechar</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal Analisar -->
                    <?php if ($_SESSION['cargo'] != 'filial'): ?>
                    <div class="modal fade" id="modalAprovar<?php echo $s['id']; ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                                <div class="modal-header border-0 p-4 pb-0">
                                    <h5 class="font-weight-bold">Análise Técnica - Solicitação #<?php echo $s['id']; ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body p-4">
                                    <input type="hidden" name="processar_solicitacao" value="1">
                                    <input type="hidden" name="solicitacao_id" value="<?php echo $s['id']; ?>">

                                    <div class="table-responsive border rounded mb-4">
                                        <table class="table table-sm mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th class="ps-3 py-2">Item</th>
                                                    <th class="text-center py-2">Qtd Pedida</th>
                                                    <th class="pe-3 py-2" style="width: 150px;">Qtd Aprovada</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                    $stmt_it_ap = $pdo->prepare("
                                                        SELECT i.*, p.nome 
                                                          FROM itens_solicitacao i 
                                                          JOIN produtos p ON i.produto_id = p.id 
                                                         WHERE i.solicitacao_id = ?
                                                    ");
                                                    $stmt_it_ap->execute([$s['id']]);
                                                    foreach ($stmt_it_ap->fetchAll(PDO::FETCH_ASSOC) as $it):
                                                ?>
                                                <tr>
                                                    <td class="ps-3 py-3 align-middle"><?php echo $it['nome']; ?></td>
                                                    <td class="text-center py-3 align-middle">
                                                        <span class="badge bg-light text-dark border"><?php echo $it['quantidade']; ?></span>
                                                    </td>
                                                    <td class="pe-3 py-3">
                                                        <input type="hidden" name="item_id[]" value="<?php echo $it['id']; ?>">
                                                        <input type="number" name="qtd_aprovada[]" class="form-control form-control-sm" value="<?php echo $it['quantidade']; ?>" min="0">
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="mb-4">
                                        <label class="font-weight-bold">Justificativa / Parecer Técnico</label>
                                        <textarea name="justificativa" class="form-control" rows="3" required placeholder="Explique o motivo da decisão..."></textarea>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <button type="submit" name="acao" value="aprovar" class="btn btn-success w-100 py-2">
                                                <i class="fas fa-check me-2"></i> Aprovar Solicitação
                                            </button>
                                        </div>
                                        <div class="col-md-6">
                                            <button type="submit" name="acao" value="rejeitar" class="btn btn-danger w-100 py-2">
                                                <i class="fas fa-times me-2"></i> Rejeitar Totalmente
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
