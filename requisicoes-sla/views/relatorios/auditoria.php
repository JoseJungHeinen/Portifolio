<?php
// @versao Ver.001.281026
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-shield-check me-2 text-secondary"></i>Log de Auditoria</h4>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2" method="GET">
            <div class="col-md-3">
                <select name="entidade_tipo" class="form-select form-select-sm">
                    <option value="">Todas as entidades</option>
                    <?php foreach (['usuario','requisicao','orcamento','contrato','cotacao','ordem_compra','nota_fiscal','estoque','fornecedor','configuracao'] as $ent): ?>
                    <option value="<?= $ent ?>" <?= ($filtros['entidade_tipo']??'') === $ent ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$ent)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <input type="text" name="acao" class="form-control form-control-sm" placeholder="Ação (ex: login, oc.gerada)" value="<?= e($filtros['acao']??'') ?>">
            </div>
            <div class="col-md-2">
                <input type="date" name="data_inicio" class="form-control form-control-sm" value="<?= e($filtros['data_inicio']??'') ?>">
            </div>
            <div class="col-md-2">
                <input type="date" name="data_fim" class="form-control form-control-sm" value="<?= e($filtros['data_fim']??'') ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                <a href="<?= url('relatorios/auditoria') ?>" class="btn btn-outline-secondary btn-sm">Limpar</a>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($logs)):
    // Agregar top ações e entidades para gráficos
    $acoesCont = [];
    $entidadesCont = [];
    foreach ($logs as $log) {
        $acao = $log['acao'] ?? 'desconhecida';
        $ent  = $log['entidade_tipo'] ?? 'n/a';
        $acoesCont[$acao] = ($acoesCont[$acao] ?? 0) + 1;
        $entidadesCont[$ent] = ($entidadesCont[$ent] ?? 0) + 1;
    }
    arsort($acoesCont); $acoesCont = array_slice($acoesCont, 0, 10, true);
    arsort($entidadesCont);
?>
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-list-check me-2 text-secondary"></i>Top 10 Ações Registradas
            </div>
            <div class="card-body"><canvas id="chartAcoes" height="140"></canvas></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-pie-chart me-2 text-secondary"></i>Atividade por Entidade
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <canvas id="chartEntidades" height="200"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>Data/Hora</th><th>Usuário</th><th>Ação</th><th>Entidade</th><th>IP</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Nenhum log encontrado.</td></tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="text-muted small"><?= datetime_br($log['criado_em']) ?></td>
                        <td class="small"><?= e($log['usuario_nome'] ?? $log['usuario_id'] ?? '—') ?></td>
                        <td><span class="badge bg-light text-dark border"><?= e($log['acao']) ?></span></td>
                        <td class="small text-muted"><?= e($log['entidade_tipo']) ?> <code><?= substr($log['entidade_id']??'',0,8) ?></code></td>
                        <td class="text-muted small"><?= e($log['ip_address']??'—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (!empty($logs)): ?>
<script>
const CORES_A = ['#6c757d','#0d6efd','#198754','#fd7e14','#6f42c1','#20c997','#CC0000','#ffc107','#0dcaf0','#343a40'];
(function() {
    const labels = <?= json_encode(array_keys($acoesCont), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const data   = <?= json_encode(array_values($acoesCont), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    new Chart(document.getElementById('chartAcoes'), {
        type: 'bar',
        data: { labels, datasets: [{ label: 'Ocorrências', data, backgroundColor: '#6c757d55', borderColor: '#6c757d', borderWidth: 2 }] },
        options: {
            responsive: true, indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { ticks: { stepSize: 1 } } }
        }
    });
})();
(function() {
    const labels = <?= json_encode(array_keys($entidadesCont), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const data   = <?= json_encode(array_values($entidadesCont), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    new Chart(document.getElementById('chartEntidades'), {
        type: 'doughnut',
        data: { labels, datasets: [{ data, backgroundColor: CORES_A }] },
        options: { responsive: true, plugins: { legend: { position: 'right', labels: { font:{size:11} } } } }
    });
})();
</script>
<?php endif; ?>