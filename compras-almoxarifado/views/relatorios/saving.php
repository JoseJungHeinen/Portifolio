<?php
// @versao Ver.003.281026
$json  = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
$link  = fn(array $extra): string => url('relatorios/saving') . '?' . http_build_query(array_filter(array_merge([
    'aba' => $aba, 'filial_id' => $filialSel,
], $extra), fn($v) => $v !== null && $v !== ''));
$tipoLabel = fn(?string $tipo): string => ucfirst(str_replace('_', ' ', $tipo ?? 'outro'));
$titulo    = 'font-size:11px;text-transform:uppercase;letter-spacing:.5px';
$nomesAbas = ['saving' => 'Saving', 'anomalias' => 'Anomalias (' . count($anomalias) . ')'];
?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-piggy-bank-fill me-2 text-success"></i>Saving & Anomalias</h4>
    <?php if ($filiaisFiltro): ?>
    <form method="GET" action="<?= url('relatorios/saving') ?>" class="d-flex align-items-center gap-2">
        <input type="hidden" name="aba" value="<?= e($aba) ?>">
        <label class="small text-muted mb-0">Filial</label>
        <select name="filial_id" class="form-select form-select-sm" style="min-width:200px" onchange="this.form.submit()">
            <option value="">Todas</option>
            <?php foreach ($filiaisFiltro as $f): ?>
            <option value="<?= e($f['id']) ?>" <?= $filialSel === $f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
</div>

<?php if (count($abasPermitidas) > 1): ?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($abasPermitidas as $chave): ?>
    <li class="nav-item">
        <a class="nav-link <?= $aba === $chave ? 'active' : '' ?>" href="<?= e($link(['aba' => $chave])) ?>"><?= $nomesAbas[$chave] ?></a>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($aba === 'saving'): ?>
<?php
    $savingTotal    = array_sum(array_map(fn($s) => (float)$s['saving'], $savings));
    $savingPorSetor = [];
    foreach ($savings as $s) {
        $savingPorSetor[$s['setor']] = ($savingPorSetor[$s['setor']] ?? 0) + (float)$s['saving'];
    }
    arsort($savingPorSetor);
?>
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #198754!important">
            <div class="card-body">
                <div class="text-muted mb-1" style="<?= $titulo ?>">Saving acumulado</div>
                <div class="fw-bold fs-4 <?= $savingTotal >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($savingTotal) ?></div>
                <small class="text-muted">diferença entre o mês de referência e o realizado</small>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted mb-1" style="<?= $titulo ?>">Períodos com referência</div>
                <div class="fw-bold fs-4"><?= count($savings) ?></div>
                <small class="text-muted">orçamentos por filial, setor e mês (até 50, maiores savings primeiro)</small>
            </div>
        </div>
    </div>
</div>

<?php if (!$savings): ?>
<div class="card border-0 shadow-sm text-center py-5">
    <div class="card-body text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Nenhum registro para esse filtro.</div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-bar-chart me-2 text-success"></i>Saving por setor</div>
    <div class="card-body"><canvas id="chartSaving" height="110"></canvas></div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Saving por filial, setor e período</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>Filial</th><th>Setor</th><th>Período</th><th class="text-end">Referência</th><th class="text-end">Realizado</th><th class="text-end">Saving</th><th class="text-end">%</th></tr>
                </thead>
                <tbody>
                <?php foreach ($savings as $s): ?>
                    <tr>
                        <td class="small"><?= e($s['filial']) ?></td>
                        <td><?= e($s['setor']) ?></td>
                        <td><?= str_pad((string)$s['mes'], 2, '0', STR_PAD_LEFT) ?>/<?= (int)$s['ano'] ?></td>
                        <td class="text-end"><?= money($s['valor_mes_anterior'] ?? 0) ?></td>
                        <td class="text-end"><?= money($s['valor_consumido'] ?? 0) ?></td>
                        <td class="text-end fw-semibold <?= (float)$s['saving'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($s['saving'] ?? 0) ?></td>
                        <td class="text-end"><?= (float)($s['saving_pct'] ?? 0) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const labels = <?= json_encode(array_keys($savingPorSetor), $json | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const data   = <?= json_encode(array_values($savingPorSetor), $json | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    new Chart(document.getElementById('chartSaving'), {
        type: 'bar',
        data: { labels, datasets: [{ label: 'Saving (R$)', data,
            backgroundColor: data.map(v => v >= 0 ? '#19875488' : '#CC000088'),
            borderColor: data.map(v => v >= 0 ? '#198754' : '#CC0000'), borderWidth: 2 }] },
        options: {
            responsive: true, indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { ticks: { callback: v => 'R$ ' + Number(v).toLocaleString('pt-BR', {minimumFractionDigits: 0}) } } }
        }
    });
})();
</script>
<?php endif; ?>

<?php else: ?>
<?php
    $porTipoQtd     = [];
    $porTipoImpacto = [];
    foreach ($anomalias as $a) {
        $t = $tipoLabel($a['tipo']);
        $porTipoQtd[$t]     = ($porTipoQtd[$t] ?? 0) + 1;
        $porTipoImpacto[$t] = ($porTipoImpacto[$t] ?? 0) + (float)$a['valor_impacto'];
    }
?>
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #ffc107!important">
            <div class="card-body">
                <div class="text-muted mb-1" style="<?= $titulo ?>">Anomalias ativas</div>
                <div class="fw-bold fs-4"><?= count($anomalias) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted mb-1" style="<?= $titulo ?>">Impacto estimado</div>
                <div class="fw-bold fs-4 text-danger"><?= money($impactoTotal) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if (!$anomalias): ?>
<div class="card border-0 shadow-sm text-center py-5">
    <div class="card-body text-muted">
        <i class="bi bi-check-circle text-success fs-1 d-block mb-2"></i>Nenhuma anomalia ativa para esse filtro.
    </div>
</div>
<?php else: ?>
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-pie-chart me-2 text-warning"></i>Anomalias por tipo</div>
            <div class="card-body d-flex align-items-center justify-content-center"><canvas id="chartTipoQtd" height="200"></canvas></div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-bar-chart me-2 text-danger"></i>Impacto estimado por tipo</div>
            <div class="card-body"><canvas id="chartTipoImpacto" height="130"></canvas></div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>Tipo</th><th>Anomalia</th><th>Setor</th><th class="text-end">Impacto est.</th><th>Detectada em</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($anomalias as $a): ?>
                    <tr>
                        <td><span class="badge bg-warning text-dark"><?= e($tipoLabel($a['tipo'])) ?></span></td>
                        <td>
                            <div class="fw-semibold small"><?= e($a['titulo']) ?></div>
                            <div class="small text-muted"><?= e($a['descricao'] ?? '') ?></div>
                        </td>
                        <td class="small"><?= e($a['setor_nome'] ?? '—') ?></td>
                        <td class="text-end fw-semibold text-danger"><?= money($a['valor_impacto'] ?? 0) ?></td>
                        <td class="text-muted small"><?= datetime_br($a['criado_em']) ?></td>
                        <td><?= status_badge($a['status'] ?? 'ativa') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const CORES = ['#ffc107','#fd7e14','#CC0000','#6f42c1','#0d6efd','#198754','#20c997','#0dcaf0','#adb5bd'];
    const brl = v => 'R$ ' + Number(v).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    new Chart(document.getElementById('chartTipoQtd'), {
        type: 'doughnut',
        data: { labels: <?= json_encode(array_keys($porTipoQtd), $json | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                datasets: [{ data: <?= json_encode(array_values($porTipoQtd), $json | JSON_HEX_TAG | JSON_HEX_AMP) ?>, backgroundColor: CORES }] },
        options: { responsive: true, plugins: { legend: { position: 'right', labels: { font: { size: 11 } } } } }
    });
    new Chart(document.getElementById('chartTipoImpacto'), {
        type: 'bar',
        data: { labels: <?= json_encode(array_keys($porTipoImpacto), $json | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                datasets: [{ label: 'Impacto (R$)', data: <?= json_encode(array_values($porTipoImpacto), $json | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                             backgroundColor: '#CC000066', borderColor: '#CC0000', borderWidth: 2 }] },
        options: { responsive: true, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => brl(c.raw) } } },
                   scales: { y: { ticks: { callback: v => brl(v) } } } }
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>
