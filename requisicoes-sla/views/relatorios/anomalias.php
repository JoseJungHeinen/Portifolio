<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-exclamation-triangle-fill me-2 text-warning"></i>Anomalias Ativas</h4>
    <span class="badge bg-warning text-dark"><?= count($anomalias) ?> ativas</span>
    <span class="ms-auto fw-semibold text-danger">Impacto: <?= money($impacto ?? 0) ?></span>
</div>

<?php if (!empty($anomalias)):
    $porTipoQtd    = [];
    $porTipoImpact = [];
    foreach ($anomalias as $a) {
        $t = ucfirst(str_replace('_',' ',$a['tipo'] ?? 'outro'));
        $porTipoQtd[$t]    = ($porTipoQtd[$t] ?? 0) + 1;
        $porTipoImpact[$t] = ($porTipoImpact[$t] ?? 0) + (float)($a['impacto_estimado'] ?? 0);
    }
?>
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-pie-chart me-2 text-warning"></i>Anomalias por Tipo
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <canvas id="chartTipoQtd" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-bar-chart me-2 text-danger"></i>Impacto Estimado por Tipo (R$)
            </div>
            <div class="card-body"><canvas id="chartTipoImpact" height="130"></canvas></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>Tipo</th><th>Descrição</th><th>Entidade</th><th class="text-end">Impacto Est.</th><th>Detectado em</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($anomalias)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">
                        <i class="bi bi-check-circle text-success display-4 d-block mb-2"></i>
                        Nenhuma anomalia ativa no momento.
                    </td></tr>
                    <?php else: ?>
                    <?php foreach ($anomalias as $a): ?>
                    <tr>
                        <td><span class="badge bg-warning text-dark"><?= e(str_replace('_',' ',$a['tipo'])) ?></span></td>
                        <td class="small"><?= e($a['descricao'] ?? '—') ?></td>
                        <td class="small text-muted"><?= e($a['entidade_tipo'] ?? '—') ?> <code><?= substr($a['entidade_id'] ?? '',0,8) ?></code></td>
                        <td class="text-end fw-semibold text-danger"><?= money($a['impacto_estimado'] ?? 0) ?></td>
                        <td class="text-muted small"><?= datetime_br($a['criado_em']) ?></td>
                        <td><?= status_badge($a['status'] ?? 'pendente') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (!empty($anomalias)): ?>
<script>
const CORES_W = ['#ffc107','#fd7e14','#CC0000','#6f42c1','#0d6efd','#198754','#20c997'];
(function() {
    const labels = <?= json_encode(array_keys($porTipoQtd)) ?>;
    const qtd    = <?= json_encode(array_values($porTipoQtd)) ?>;
    new Chart(document.getElementById('chartTipoQtd'), {
        type: 'doughnut',
        data: { labels, datasets: [{ data: qtd, backgroundColor: CORES_W }] },
        options: { responsive: true, plugins: { legend: { position: 'right', labels: { font:{size:11} } } } }
    });
})();
(function() {
    const labels  = <?= json_encode(array_keys($porTipoImpact)) ?>;
    const impacto = <?= json_encode(array_values($porTipoImpact)) ?>;
    new Chart(document.getElementById('chartTipoImpact'), {
        type: 'bar',
        data: { labels, datasets: [{ label: 'Impacto (R$)', data: impacto, backgroundColor: '#CC000066', borderColor: '#CC0000', borderWidth: 2 }] },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { ticks: { callback: v => 'R$' + v.toLocaleString('pt-BR',{minimumFractionDigits:0}) } } }
        }
    });
})();
</script>
<?php endif; ?>