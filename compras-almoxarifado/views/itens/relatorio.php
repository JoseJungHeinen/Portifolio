<?php
// @versao Ver.002.281026
?>
<?php $title = 'Relatório — ' . e($item['nome']); ?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('itens') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Relatório do Item</h4>
    <a href="<?= url('itens/' . $item['id'] . '/relatorio') ?>" class="btn btn-outline-secondary btn-sm ms-auto" onclick="window.print();return false;">
        <i class="bi bi-printer me-1"></i>Imprimir
    </a>
</div>

<!-- Dados do Item -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-primary text-white">
        <div class="d-flex align-items-center justify-content-between">
            <h5 class="mb-0"><?= e($item['nome']) ?></h5>
            <span class="badge bg-light text-dark"><?= e($item['codigo'] ?? '—') ?></span>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-2"><small class="text-muted d-block">Grupo</small><strong><?= e($item['grupo_nome'] ?? '—') ?></strong></div>
            <div class="col-md-2"><small class="text-muted d-block">Marca</small><strong><?= e($item['marca'] ?? '—') ?></strong></div>
            <div class="col-md-1"><small class="text-muted d-block">Unidade</small><strong><?= e($item['unidade'] ?? '—') ?></strong></div>
            <div class="col-md-2"><small class="text-muted d-block">Estoque Mínimo</small><strong><?= number_format($item['estoque_minimo'] ?? 0, 2, ',', '.') ?></strong></div>
            <div class="col-md-5"><small class="text-muted d-block">Descrição</small><span><?= e($item['descricao'] ?? '—') ?></span></div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Estoque por Filial -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-boxes me-2 text-info"></i>Estoque por Filial</div>
            <div class="card-body p-0">
                <?php if (empty($estoqueFiliais)): ?>
                    <p class="text-muted small p-3 mb-0">Sem registros de estoque.</p>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Filial</th><th class="text-end">Qtd</th></tr></thead>
                    <tbody>
                    <?php foreach ($estoqueFiliais as $e): ?>
                    <tr>
                        <td class="small"><?= e($e['filial_nome']) ?></td>
                        <td class="text-end fw-semibold"><?= number_format($e['quantidade'], 2, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-light">
                        <td class="fw-bold small">Total</td>
                        <td class="text-end fw-bold"><?= number_format(array_sum(array_column($estoqueFiliais, 'quantidade')), 2, ',', '.') ?></td>
                    </tr>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Resumo por Fornecedor -->
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-shop me-2 text-success"></i>Histórico de Preços por Fornecedor</div>
            <div class="card-body p-0">
                <?php if (empty($resumoFornecedores)): ?>
                    <p class="text-muted small p-3 mb-0">Nenhuma compra registrada.</p>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Fornecedor</th>
                            <th class="text-end">Mín.</th>
                            <th class="text-end">Médio</th>
                            <th class="text-end">Máx.</th>
                            <th class="text-end">Último</th>
                            <th class="text-center">Compras</th>
                            <th>Última em</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($resumoFornecedores as $f): ?>
                    <tr>
                        <td class="small fw-semibold"><?= e($f['fornecedor_nome']) ?></td>
                        <td class="text-end small text-success"><?= money($f['preco_min']) ?></td>
                        <td class="text-end small"><?= money($f['preco_medio']) ?></td>
                        <td class="text-end small text-danger"><?= money($f['preco_max']) ?></td>
                        <td class="text-end fw-semibold"><?= money($f['ultimo_preco']) ?></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= $f['total_compras'] ?></span></td>
                        <td class="small text-muted"><?= date('d/m/Y', strtotime($f['ultima_compra'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Gráfico de preços -->
<?php if (!empty($historico)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-graph-up me-2 text-primary"></i>Evolução do Preço</div>
    <div class="card-body">
        <canvas id="chartPrecos" height="80"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- Entradas e saídas no período -->
<?php $t = $periodo['totais']; $qtdFmt = fn($v) => number_format((float)$v, 2, ',', '.'); ?>
<div class="card border-0 shadow-sm mb-4" id="periodo">
    <div class="card-header bg-white d-flex align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="bi bi-arrow-left-right me-2 text-primary"></i>Entradas e Saídas no Período</span>
        <small class="text-muted"><?= $periodo['inicio']->format('d/m/Y') ?> a <?= date('d/m/Y') ?></small>
        <div class="btn-group btn-group-sm ms-auto d-print-none" role="group">
            <?php foreach ([30, 60, 365] as $opcao): ?>
            <a href="<?= url('itens/' . $item['id'] . '/relatorio') ?>?dias=<?= $opcao ?>#periodo"
               class="btn <?= $dias === $opcao ? 'btn-primary' : 'btn-outline-primary' ?>"><?= $opcao ?> dias</a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3 text-center">
            <div class="col-6 col-md-3">
                <small class="text-muted d-block">Estoque no início</small>
                <strong><?= $qtdFmt($t['saldo_inicial_qtd']) ?> <?= e($item['unidade'] ?? '') ?></strong>
                <div class="small text-muted"><?= money($t['saldo_inicial_valor']) ?></div>
            </div>
            <div class="col-6 col-md-3">
                <small class="text-muted d-block">Entrou</small>
                <strong class="text-success">+<?= $qtdFmt($t['entrada_qtd']) ?></strong>
                <div class="small text-success"><?= money($t['entrada_valor']) ?></div>
            </div>
            <div class="col-6 col-md-3">
                <small class="text-muted d-block">Saiu</small>
                <strong class="text-danger">-<?= $qtdFmt($t['saida_qtd']) ?></strong>
                <div class="small text-danger"><?= money($t['saida_valor']) ?></div>
            </div>
            <div class="col-6 col-md-3">
                <small class="text-muted d-block">Estoque hoje</small>
                <strong><?= $qtdFmt($t['saldo_final_qtd']) ?> <?= e($item['unidade'] ?? '') ?></strong>
                <?php $difValor = $t['saldo_final_valor'] - $t['saldo_inicial_valor']; ?>
                <div class="small text-muted">
                    <?= money($t['saldo_final_valor']) ?>
                    <span class="<?= $difValor >= 0 ? 'text-success' : 'text-danger' ?>">(<?= $difValor >= 0 ? '+' : '-' ?><?= money(abs($difValor)) ?>)</span>
                </div>
            </div>
        </div>
        <canvas id="chartPeriodo" height="90"></canvas>
        <p class="small text-muted mb-0 mt-2">
            Valores calculados pelo último preço de compra registrado até cada data.
            <?php if ($periodo['sem_preco']): ?><span class="text-warning">Item sem preço registrado — valores ficam zerados.</span><?php endif; ?>
        </p>
    </div>
</div>

<script>
(function () {
    const ctx = document.getElementById('chartPeriodo');
    if (!ctx) return;
    const b = <?= json_encode(array_map(fn($x) => [
        'rotulo' => $x['rotulo'], 'eq' => round($x['entrada_qtd'], 2), 'sq' => round($x['saida_qtd'], 2),
        'ev' => round($x['entrada_valor'], 2), 'sv' => round($x['saida_valor'], 2),
        'saldo' => round($x['saldo_qtd'], 2), 'valor' => round($x['saldo_valor'], 2),
    ], $periodo['buckets']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const brl = v => 'R$ ' + v.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const num = v => v.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    new Chart(ctx, {
        data: {
            labels: b.map(x => x.rotulo),
            datasets: [
                { type: 'bar',  label: 'Entrou (qtd)', data: b.map(x => x.eq), backgroundColor: 'rgba(25,135,84,0.7)', yAxisID: 'yQtd', order: 2 },
                { type: 'bar',  label: 'Saiu (qtd)',   data: b.map(x => x.sq), backgroundColor: 'rgba(220,53,69,0.7)', yAxisID: 'yQtd', order: 2 },
                { type: 'line', label: 'Valor do estoque (R$)', data: b.map(x => x.valor), borderColor: '#2a5f9e',
                  backgroundColor: 'rgba(42,95,158,0.1)', borderWidth: 2, pointRadius: 2, tension: 0.3, yAxisID: 'yValor', order: 1 },
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: c => {
                            const x = b[c.dataIndex];
                            if (c.datasetIndex === 0) return 'Entrou: ' + num(x.eq) + ' (' + brl(x.ev) + ')';
                            if (c.datasetIndex === 1) return 'Saiu: ' + num(x.sq) + ' (' + brl(x.sv) + ')';
                            return 'Estoque: ' + num(x.saldo) + ' — ' + brl(x.valor);
                        }
                    }
                }
            },
            scales: {
                yQtd:   { position: 'left',  beginAtZero: true, title: { display: true, text: 'Quantidade' } },
                yValor: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false },
                          ticks: { callback: v => brl(v) }, title: { display: true, text: 'Valor do estoque' } }
            }
        }
    });
})();
</script>

<!-- Movimentos Recentes -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-2 text-secondary"></i>Movimentos Recentes</div>
    <div class="card-body p-0">
        <?php if (empty($movimentos)): ?>
            <p class="text-muted small p-3 mb-0">Nenhum movimento registrado.</p>
        <?php else: ?>
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr><th>Data</th><th>Tipo</th><th>Motivo</th><th>Filial</th><th class="text-end">Qtd</th><th>Usuário</th></tr>
            </thead>
            <tbody>
            <?php foreach ($movimentos as $m): ?>
            <tr>
                <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($m['criado_em'])) ?></td>
                <td>
                    <span class="badge <?= $m['tipo'] === 'entrada' ? 'bg-success' : ($m['tipo'] === 'saida' ? 'bg-danger' : 'bg-info') ?>">
                        <?= ucfirst($m['tipo']) ?>
                    </span>
                </td>
                <td class="small text-muted"><?= e(ucfirst($m['motivo'] ?? '—')) ?></td>
                <td class="small"><?= e($m['filial_nome']) ?></td>
                <td class="text-end fw-semibold <?= $m['tipo'] === 'entrada' ? 'text-success' : 'text-danger' ?>">
                    <?= $m['tipo'] === 'entrada' ? '+' : '-' ?><?= number_format($m['quantidade'], 2, ',', '.') ?>
                </td>
                <td class="small text-muted"><?= e($m['usuario_nome'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($historico)): ?>
<script>
const ctxPrecos = document.getElementById('chartPrecos');
if (ctxPrecos) {
    const labels = <?= json_encode(array_map(fn($h) => date('d/m/Y', strtotime($h['registrado_em'])), array_reverse($historico)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const valores = <?= json_encode(array_map(fn($h) => (float)$h['valor_unitario'], array_reverse($historico)), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const fornecedores = <?= json_encode(array_map(fn($h) => $h['fornecedor_nome'], array_reverse($historico)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    new Chart(ctxPrecos, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Preço Unitário (R$)',
                data: valores,
                borderColor: '#2a5f9e',
                backgroundColor: 'rgba(42,95,158,0.1)',
                borderWidth: 2,
                pointRadius: 4,
                tension: 0.3,
                fill: true,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: ctx => 'R$ ' + ctx.raw.toLocaleString('pt-BR', {minimumFractionDigits:2}) + ' — ' + fornecedores[ctx.dataIndex]
                    }
                }
            },
            scales: {
                y: { ticks: { callback: v => 'R$ ' + v.toLocaleString('pt-BR', {minimumFractionDigits:2}) } }
            }
        }
    });
}
</script>
<?php endif; ?>
