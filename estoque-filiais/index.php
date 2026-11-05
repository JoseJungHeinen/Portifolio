<?php 
require 'includes/db.php';
include 'includes/header.php'; 

// KPIs principais
$total_solicitacoes = $pdo->query("
    SELECT COUNT(*) 
      FROM solicitacoes
")->fetchColumn();

$pendentes = $pdo->query("
    SELECT COUNT(*) 
      FROM solicitacoes 
     WHERE status = 'pendente'
")->fetchColumn();

$aprovadas_mes = $pdo->query("
    SELECT COUNT(*) 
      FROM solicitacoes 
     WHERE status = 'aprovado' 
       AND MONTH(data_criacao) = MONTH(CURRENT_DATE)
       AND YEAR(data_criacao)  = YEAR(CURRENT_DATE)
")->fetchColumn();

$valor_compras_mes = $pdo->query("
    SELECT COALESCE(SUM(valor_total), 0) 
      FROM ordens_compra 
     WHERE status = 'concluida' 
       AND MONTH(data_emissao) = MONTH(CURRENT_DATE)
       AND YEAR(data_emissao)  = YEAR(CURRENT_DATE)
")->fetchColumn();

// KPI de itens críticos (valor padrão)
$total_itens_criticos = 0;

// inclui lógica de cálculo dos itens críticos
require 'includes/kpi_criticos.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4">
    <div>
        <h1 class="h3 mb-0 text-gray-800">Painel de Controle</h1>
        <p class="text-muted small">Bem-vindo ao sistema de gestão estratégica.</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <span class="badge bg-white text-dark shadow-sm p-2 border">📅 <?php echo date('d/m/Y'); ?></span>
    </div>
</div>

<div class="row">
    <div class="col-md-3">
        <div class="card-kpi bg-gradient-red shadow">
            <div class="card-body">
                <h6>Pendentes</h6>
                <h2><?php echo (int)$pendentes; ?></h2>
                <a href="solicitacoes.php" class="text-white-50 small stretched-link">Ver detalhes</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-kpi bg-gradient-green shadow">
            <div class="card-body">
                <h6>Aprovadas (Mês)</h6>
                <h2><?php echo (int)$aprovadas_mes; ?></h2>
                <span class="text-white-50 small">Solicitações concluídas</span>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-kpi bg-gradient-blue shadow">
            <div class="card-body">
                <h6>Investimento</h6>
                <h2>R$ <?php echo number_format($valor_compras_mes, 0, ',', '.'); ?></h2>
                <span class="text-white-50 small">Compras este mês</span>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-kpi bg-gradient-orange shadow">
            <div class="card-body">
                <h6>Alertas</h6>
                <h2><?php echo (int)$total_itens_criticos; ?></h2>
                <a href="estoque.php" class="text-white-50 small stretched-link">Ver detalhes</a>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold text-primary">Atividades Recentes</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <tbody>
                            <?php 
                            $recentes = $pdo->query("
                                SELECT l.*, u.nome 
                                  FROM logs_auditoria l 
                                  JOIN usuarios u ON l.usuario_id = u.id 
                              ORDER BY l.data_hora DESC 
                                 LIMIT 6
                            ")->fetchAll(PDO::FETCH_ASSOC);

                            foreach ($recentes as $r):
                            ?>
                            <tr>
                                <td class="ps-4 py-3 text-muted small" style="width: 80px;">
                                    <?php echo date('H:i', strtotime($r['data_hora'])); ?>
                                </td>
                                <td class="py-3">
                                    <span class="font-weight-bold"><?php echo htmlspecialchars($r['nome']); ?></span> 
                                    <span class="text-muted"><?php echo strtolower($r['acao']); ?></span> 
                                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($r['tabela_nome']); ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recentes)): ?>
                            <tr>
                                <td colspan="2" class="text-center text-muted py-4">
                                    Nenhuma atividade recente.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold text-primary">Ações Rápidas</h6>
            </div>
            <div class="card-body">
                <div class="d-grid gap-3">
                    <a href="nova_solicitacao.php" class="btn btn-primary">Nova Solicitação</a>
                    <a href="estoque.php" class="btn btn-outline-primary">Consultar Estoque</a>
                    <a href="produtos.php" class="btn btn-outline-primary">Gerenciar Itens</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
