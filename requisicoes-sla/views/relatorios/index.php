<?php
// @versao Ver.003.281026
// Um card por relatório, na ordem de uso. Cada um só aparece com a permissão dele.
$cards = [
    ['relatorios/compras', podeModulo('relatorios.compras'), 'bi-bag-check-fill', 'color:#CC0000', 'border-top:3px solid #CC0000!important',
     'Relatório de Compras', 'Ordens de compra, notas fiscais, valores e quantidades por período e setor'],
    ['relatorios/filiais', podeModulo('relatorios.filiais'), 'bi-building-fill', '', 'border-top:3px solid #0d6efd!important',
     'Consumo por Filial', 'Consumo por dia, mês ou ano e top itens por valor ou quantidade'],
    ['relatorios/consumo', podeModulo('relatorios.consumo'), 'bi-bar-chart-fill', '', '',
     'Consumo por Período', 'Consumo do estoque por mês e quanto repor para chegar ao estoque mínimo'],
    ['relatorios/movimentacao-estoque', podeModulo('relatorios.movimentacao_estoque'), 'bi-arrow-left-right', 'color:#0dcaf0', '',
     'Movimentação de Estoque', 'Entradas e saídas do dia a dia — valores, quantidades, setor e loja de destino'],
    ['relatorios/saude-estoque', podeModulo('relatorios.saude_estoque'), 'bi-heart-pulse-fill', 'color:#198754', '',
     'Saúde do Estoque', 'Valor imobilizado, itens parados, abaixo do mínimo e cobertura em dias'],
    ['relatorios/saving', (podeModulo('relatorios.saving') || podeModulo('relatorios.anomalias')), 'bi-piggy-bank-fill', 'color:#198754', '',
     'Saving & Anomalias', 'Economia gerada e alertas de desperdício'],
    ['relatorios/brindes', podeModulo('relatorios.brindes'), 'bi-gift-fill', 'color:#dc3545', '',
     'Brindes', 'Saldo por filial, saídas do período e ranking de brindes mais solicitados'],
    ['relatorios/auditoria', podeModulo('relatorios.auditoria'), 'bi-shield-check', 'color:#6c757d', '',
     'Log de Auditoria', 'Histórico completo de ações dos usuários no sistema'],
];
?>
<h4 class="fw-bold mb-4"><i class="bi bi-graph-up me-2 text-primary"></i>Relatórios</h4>

<div class="row g-3">
    <?php foreach ($cards as [$rota, $pode, $icone, $corIcone, $borda, $nome, $descricao]): ?>
    <?php if (!$pode) continue; ?>
    <div class="col-md-6 col-lg-3">
        <a href="<?= url($rota) ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 card-hover" style="<?= $borda ?>">
                <div class="card-body text-center py-4">
                    <i class="bi <?= $icone ?> display-4 mb-3 d-block <?= $corIcone === '' ? 'text-primary' : '' ?>" style="<?= $corIcone ?>"></i>
                    <h6 class="fw-bold text-dark"><?= e($nome) ?></h6>
                    <p class="text-muted small mb-0"><?= e($descricao) ?></p>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<style>.card-hover:hover { transform: translateY(-2px); transition: transform .15s; }</style>
