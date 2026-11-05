<?php 
require 'includes/db.php';
include 'includes/header.php'; 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$filial_id_sessao = $_SESSION['filial_id'];
$user_id          = $_SESSION['user_id'];

// validação mensal
$mes_atual = date('m');
$ano_atual = date('Y');
$stmt = $pdo->prepare("SELECT * FROM validacoes_estoque WHERE filial_id = ? AND mes_referencia = ? AND ano_referencia = ?");
$stmt->execute([$filial_id_sessao, $mes_atual, $ano_atual]);
$validado = $stmt->fetch(PDO::FETCH_ASSOC);

// bloqueio para filial sem validação
if (!$validado && $_SESSION['cargo'] == 'filial') {
    echo "<div class='card shadow-sm border-0 mt-4' style='border-radius: 20px;'>
            <div class='card-body p-5 text-center'>
                <div class='logo-placeholder mx-auto mb-4' style='background: #ffc107; color: #000;'>!</div>
                <h4 class='font-weight-bold'>Acesso Bloqueado!</h4>
                <p class='text-muted mb-4'>Você precisa validar seu estoque mensal antes de prosseguir com novas solicitações.</p>
                <a href='estoque.php' class='btn btn-primary px-4'>Validar Agora</a>
            </div>
          </div>";
    include 'includes/footer.php';
    exit;
}

// carregar filiais (para matriz escolher)
$filiais = $pdo->query("SELECT id, nome FROM filiais ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// grava solicitação
$erro = null;
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $pdo->beginTransaction();
    try {
        // se veio do form, usa filial escolhida; caso contrário, filial da sessão
        $filial_id = !empty($_POST['filial_id']) ? (int)$_POST['filial_id'] : $filial_id_sessao;

        $stmt = $pdo->prepare("
            INSERT INTO solicitacoes (filial_id, usuario_id, status) 
            VALUES (?, ?, 'pendente')
        ");
        $stmt->execute([$filial_id, $user_id]);
        $solicitacao_id = $pdo->lastInsertId();

        $produtos_ids = $_POST['produtos'] ?? [];
        $quantidades  = $_POST['quantidades'] ?? [];

        $stmt_item = $pdo->prepare("
            INSERT INTO itens_solicitacao (solicitacao_id, produto_id, quantidade) 
            VALUES (?, ?, ?)
        ");
        for ($i = 0; $i < count($produtos_ids); $i++) {
            $qtd = (float)$quantidades[$i];
            if ($qtd > 0) {
                $stmt_item->execute([$solicitacao_id, $produtos_ids[$i], $qtd]);
            }
        }

        // log
        $stmt_log = $pdo->prepare("
            INSERT INTO logs_auditoria 
                (tabela_nome, registro_id, usuario_id, acao, detalhes) 
            VALUES ('solicitacoes', ?, ?, 'CRIOU', 'Nova solicitação criada pela filial')
        ");
        $stmt_log->execute([$solicitacao_id, $user_id]);

        $pdo->commit();
        header("Location: solicitacoes.php?success=1");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $erro = "Erro: " . $e->getMessage();
    }
}

$produtos = $pdo->query("SELECT * FROM produtos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4">
    <div>
        <h1 class="h3 mb-0 text-gray-800">Nova Solicitação</h1>
        <p class="text-muted small">Selecione os itens e quantidades necessárias.</p>
    </div>
    <a href="solicitacoes.php" class="btn btn-light border shadow-sm">
        <i class="fas fa-arrow-left me-2"></i> Voltar
    </a>
</div>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?php echo $erro; ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <form method="POST">

            <?php if ($_SESSION['cargo'] != 'filial'): ?>
            <!-- seleção de filial para matriz / usuários com visão geral -->
            <div class="p-4 pb-0">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label">Filial da Solicitação</label>
                        <select name="filial_id" class="form-select" required>
                            <?php foreach ($filiais as $f): ?>
                                <option 
                                    value="<?php echo $f['id']; ?>"
                                    <?php echo $f['id'] == $filial_id_sessao ? 'selected' : ''; ?>
                                >
                                    <?php echo $f['nome']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <?php else: ?>
                <!-- filial fixa para usuário de filial -->
                <input type="hidden" name="filial_id" value="<?php echo $filial_id_sessao; ?>">
            <?php endif; ?>

            <div class="table-responsive mt-3">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Item</th>
                            <th>Finalidade</th>
                            <th>Recorrência</th>
                            <th class="pe-4" width="180">Quantidade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($produtos as $p): ?>
                        <tr>
                            <td class="ps-4 py-3">
                                <div class="font-weight-bold text-primary"><?php echo $p['nome']; ?></div>
                                <small class="text-muted d-block" style="max-width: 300px;"><?php echo $p['descricao']; ?></small>
                                <input type="hidden" name="produtos[]" value="<?php echo $p['id']; ?>">
                            </td>
                            <td><small><?php echo $p['finalidade']; ?></small></td>
                            <td>
                                <?php 
                                    $badge_class = 'bg-light text-dark border';
                                    if($p['recorrencia'] == 'alta')  $badge_class = 'bg-danger text-white';
                                    if($p['recorrencia'] == 'media') $badge_class = 'bg-warning text-dark';
                                ?>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst($p['recorrencia']); ?></span>
                            </td>
                            <td class="pe-4">
                                <div class="input-group input-group-sm">
                                    <input type="number" name="quantidades[]" class="form-control text-center" value="0" min="0" style="height: 38px;">
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="p-4 bg-light border-top text-end">
                <button type="submit" class="btn btn-primary px-5 py-2 shadow-sm">
                    <i class="fas fa-paper-plane me-2"></i> Enviar para Aprovação
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
