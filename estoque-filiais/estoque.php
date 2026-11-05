<?php 
require 'includes/db.php';
include 'includes/header.php'; 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Dados da sessão
$filial_id = $_SESSION['filial_id'] ?? null;
$cargo     = $_SESSION['cargo'] ?? null;

// Período atual para validação
$mes_atual = date('m');
$ano_atual = date('Y');

// 1) Verificar se o estoque do mês já foi validado pela filial
$stmt = $pdo->prepare("
    SELECT * 
      FROM validacoes_estoque 
     WHERE filial_id      = ? 
       AND mes_referencia = ? 
       AND ano_referencia = ?
");
$stmt->execute([$filial_id, $mes_atual, $ano_atual]);
$validado = $stmt->fetch(PDO::FETCH_ASSOC);

// Processa o POST para validar o estoque
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['validar'])) {
    if (!$validado && $cargo === 'filial') {
        $stmt = $pdo->prepare("
            INSERT INTO validacoes_estoque (filial_id, mes_referencia, ano_referencia, usuario_id) 
            VALUES (?, ?, ?, ?)
        ");
        $usuario_id = $_SESSION['usuario_id'] ?? null; 
        $stmt->execute([$filial_id, $mes_atual, $ano_atual, $usuario_id]);
        header("Location: estoque.php?success=1");
        exit;
    }
}

// 2) Dados mestre
$filiais  = $pdo->query("SELECT * FROM filiais ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$produtos = $pdo->query("SELECT * FROM produtos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// 3) Saldos atuais por produto e filial
$saldos_raw = $pdo->query("
    SELECT produto_id, filial_id, quantidade 
      FROM estoque
")->fetchAll(PDO::FETCH_ASSOC);

$saldos = []; // [produto_id][filial_id] => quantidade
foreach ($saldos_raw as $s) {
    $saldos[(int)$s['produto_id']][(int)$s['filial_id']] = (int)$s['quantidade'];
}

// 4) Média mensal de CONSUMO (saídas) por produto E FILIAL nos últimos 3 meses
$medias_raw = $pdo->query("
    SELECT
        produto_id,
        filial_id,
        AVG(consumo_mes) AS media_mensal
    FROM (
        SELECT 
            produto_id,
            filial_id,
            YEAR(data_movimento)  AS ano,
            MONTH(data_movimento) AS mes,
            SUM(quantidade)       AS consumo_mes
        FROM movimentacoes_estoque
        WHERE tipo = 'S'
          AND data_movimento >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH)
        GROUP BY 
            produto_id,
            filial_id,
            YEAR(data_movimento),
            MONTH(data_movimento)
    ) t
    GROUP BY 
        produto_id,
        filial_id
")->fetchAll(PDO::FETCH_ASSOC);

$medias = []; // [produto_id][filial_id] => media_mensal
foreach ($medias_raw as $m) {
    $pid = (int)$m['produto_id'];
    $fid = (int)$m['filial_id'];
    $medias[$pid][$fid] = (float)$m['media_mensal'];
}

// 5) Lista para o card lateral
$itensCriticos = [];
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4">
    <div>
        <h1 class="h3 mb-0 text-gray-800">Painel de Estoque</h1>
        <p class="text-muted small mb-0">Visão consolidada de saldos e consumo em todas as unidades.</p>
    </div>

    <?php if ($cargo === 'filial' && !$validado): ?>
        <form method="POST" onsubmit="return confirm('Deseja realmente validar o estoque para <?php echo date('m/Y'); ?>? Esta ação não pode ser desfeita.');">
            <button type="submit" name="validar" class="btn btn-primary">
                <i class="fas fa-check-circle me-2"></i>Validar Estoque (<?php echo date('m/Y'); ?>)
            </button>
        </form>
    <?php elseif ($cargo === 'filial' && $validado): ?>
        <span class="badge bg-success shadow-sm p-2">
            <i class="fas fa-check-circle me-1"></i> Estoque de <?php echo date('m/Y'); ?> Validado
        </span>
    <?php endif; ?>
</div>

<div class="row">
    <div class="col-lg-9">
        <ul class="nav nav-tabs" id="estoqueTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="visao-geral-tab" data-bs-toggle="tab" data-bs-target="#visao-geral-pane" type="button" role="tab" aria-controls="visao-geral-pane" aria-selected="true">
                    <i class="fas fa-boxes me-2"></i>Visão Geral do Estoque
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="medias-consumo-tab" data-bs-toggle="tab" data-bs-target="#medias-consumo-pane" type="button" role="tab" aria-controls="medias-consumo-pane" aria-selected="false">
                    <i class="fas fa-chart-line me-2"></i>Médias de Consumo
                </button>
            </li>
        </ul>

        <div class="tab-content" id="estoqueTabContent">
            <!-- Aba 1 -->
            <div class="tab-pane fade show active" id="visao-geral-pane" role="tabpanel" aria-labelledby="visao-geral-tab" tabindex="0">
                <div class="card shadow-sm border-top-0 rounded-0 rounded-bottom">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-4 py-3">Produto</th>
                                        <?php foreach ($filiais as $f): ?>
                                            <th class="text-center py-3"><?php echo htmlspecialchars($f['nome']); ?></th>
                                        <?php endforeach; ?>
                                        <th class="text-center bg-light fw-bold py-3">Total Global</th>
                                        <th class="text-center py-3">Situação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($produtos as $p): 
                                        $produtoId  = (int)$p['id'];
                                        $total_item = 0;
                                        $critico    = false;
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <strong><?php echo htmlspecialchars($p['nome']); ?></strong>
                                        </td>

                                        <?php foreach ($filiais as $f): 
                                            $fid = (int)$f['id'];
                                            $qtd = $saldos[$produtoId][$fid] ?? 0;
                                            $total_item += $qtd;

                                            $bg_class = ($fid === (int)$filial_id) ? 'bg-light fw-bold' : '';

                                            // média mensal específica desse produto nessa filial
                                            $mediaFilial = $medias[$produtoId][$fid] ?? 0;

                                            // Crítico quando o saldo da filial estiver abaixo ou igual à média mensal + 1
                                            if ($mediaFilial > 0 && $qtd <= $mediaFilial + 1) {
                                                $critico = true;
                                            }
                                        ?>
                                        <td class="text-center <?php echo $bg_class; ?>">
                                            <?php echo (int)$qtd; ?>
                                        </td>
                                        <?php endforeach; ?>

                                        <td class="text-center fw-bold text-primary">
                                            <?php echo (int)$total_item; ?>
                                        </td>

                                        <td class="text-center">
                                            <?php if ($critico): ?>
                                                <?php $itensCriticos[] = $produtoId; ?>
                                                <span class="badge bg-danger">Crítico</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Normal</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Aba 2 -->
            <div class="tab-pane fade" id="medias-consumo-pane" role="tabpanel" aria-labelledby="medias-consumo-tab" tabindex="0">
                <div class="card shadow-sm border-top-0 rounded-0 rounded-bottom">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-4 py-3">Produto</th>
                                        <th class="text-center py-3">Média Mensal (Últimos 3 meses)</th>
                                        <th class="text-center py-3">Nível de Alerta (Média + 1)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($produtos)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted p-4">Nenhum produto cadastrado.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($produtos as $p): 
                                            $produtoId = (int)$p['id'];
                                            // média global aproximada (soma das filiais / qtd de filiais com média)
                                            $soma = 0;
                                            $cont = 0;
                                            foreach ($filiais as $f) {
                                                $fid = (int)$f['id'];
                                                if (isset($medias[$produtoId][$fid])) {
                                                    $soma += $medias[$produtoId][$fid];
                                                    $cont++;
                                                }
                                            }
                                            $media = $cont > 0 ? $soma / $cont : 0;
                                        ?>
                                        <tr>
                                            <td class="ps-4">
                                                <strong><?php echo htmlspecialchars($p['nome']); ?></strong>
                                            </td>
                                            <td class="text-center fw-bold text-primary">
                                                <?php echo number_format($media, 2, ',', '.'); ?>
                                            </td>
                                            <td class="text-center fw-bold text-danger">
                                                <?php echo number_format($media + 1, 2, ',', '.'); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Lateral -->
    <div class="col-lg-3">
        <div class="card shadow-sm mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-exclamation-triangle text-danger me-2"></i>
                    Itens Críticos
                </h6>
            </div>
            <div class="card-body">
                <?php 
                $itensCriticosUnicos = array_unique($itensCriticos);
                if (empty($itensCriticosUnicos)): 
                ?>
                    <p class="text-muted small mb-0">
                        Nenhum item em estado crítico no momento.
                    </p>
                <?php else: ?>
                    <ul class="list-group list-group-flush small">
                        <?php 
                        foreach ($produtos as $p) {
                            if (in_array((int)$p['id'], $itensCriticosUnicos, true)) {
                                echo '<li class="list-group-item d-flex justify-content-between align-items-center px-0">';
                                echo htmlspecialchars($p['nome']);
                                echo '<span class="badge bg-danger">Crítico</span>';
                                echo '</li>';
                            }
                        }
                        ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="p-3 bg-white rounded shadow-sm border-start border-primary border-4">
            <h6 class="text-dark">Como a criticidade é calculada?</h6>
            <p class="small text-muted mb-0">
                <i class="fas fa-info-circle me-2"></i>
                A situação <strong>Crítico</strong> é acionada quando o saldo de um item em alguma filial é menor ou igual à média de consumo mensal dos últimos 3 meses, acrescida de 1 unidade.
            </p>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
