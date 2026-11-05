<?php
// @versao Ver.002.160926
$link = fn(array $extra): string => url('relatorios/filiais') . '?' . http_build_query(array_filter(array_merge([
    'agrupamento' => $agrupamento, 'data_inicio' => $de, 'data_fim' => $ate,
    'filial_id' => $filialSel, 'setor_id' => $setorId, 'ordem' => $ordem,
], $extra), fn($v) => $v !== null && $v !== ''));
$qtd   = fn($v): string => number_format((float)$v, 2, ',', '.');
// I5: consumo de valor R$ 0 (item sem preço/custo) ainda é consumo — "vazio" é não ter item nenhum.
$vazio = $resumo['itens_distintos'] === 0;
$rotuloAgrup = ['dia' => 'dia', 'mes' => 'mês', 'ano' => 'ano'][$agrupamento];
?>
<div class="mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-building me-2 text-primary"></i>Consumo por Filial</h4>
    <small class="text-muted">
        Saídas de estoque (requisições internas, brindes, perdas, avarias e outras saídas de uso) de
        <?= date('d/m/Y', strtotime($de)) ?> a <?= date('d/m/Y', strtotime($ate)) ?>, por <?= $rotuloAgrup ?>
    </small>
</div>

<?php if (!empty($periodoAjustado)): ?>
<div class="alert alert-info d-flex align-items-center gap-2 py-2 small mb-3">
    <i class="bi bi-info-circle"></i>
    A visualização por dia é limitada a 1 ano — a data de início foi ajustada.
</div>
<?php endif; ?>

<form method="GET" action="<?= url('relatorios/filiais') ?>" class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Agrupar por</label>
                <select name="agrupamento" id="selAgrupamento" class="form-select form-select-sm">
                    <?php foreach (['dia' => 'Dia', 'mes' => 'Mês', 'ano' => 'Ano'] as $valor => $texto): ?>
                    <option value="<?= $valor ?>" <?= $agrupamento === $valor ? 'selected' : '' ?>><?= $texto ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">De</label>
                <input type="date" name="data_inicio" value="<?= e($de) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Até</label>
                <input type="date" name="data_fim" value="<?= e($ate) ?>" class="form-control form-control-sm">
            </div>
            <?php if ($filiaisFiltro): ?>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Filial</label>
                <select name="filial_id" class="form-select form-select-sm">
                    <option value="">Todas</option>
                    <?php foreach ($filiaisFiltro as $f): ?>
                    <option value="<?= e($f['id']) ?>" <?= $filialSel === $f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Setor</label>
                <select name="setor_id" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($setores as $s): ?>
                    <option value="<?= e($s['id']) ?>" <?= $setorId === $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="ordem" value="<?= e($ordem) ?>">
            <div class="col-12 col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                <a href="<?= url('relatorios/filiais') ?>" class="btn btn-sm btn-outline-secondary">Limpar</a>
            </div>
        </div>
    </div>
</form>

<?php if ($vazio): ?>
<div class="card border-0 shadow-sm text-center py-5">
    <div class="card-body text-muted">
        <i class="bi bi-inbox fs-1 d-block mb-2"></i>Nenhum registro para esse filtro.
    </div>
</div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #CC0000!important">
            <div class="card-body">
                <div class="text-muted mb-1" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px">Total consumido</div>
                <div class="fw-bold fs-4"><?= money($resumo['total']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted mb-1" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px">Itens consumidos</div>
                <div class="fw-bold fs-4"><?= (int)$resumo['itens_distintos'] ?></div>
                <small class="text-muted">itens diferentes</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted mb-1" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px">Filial que mais consumiu</div>
                <div class="fw-bold fs-5"><?= e($resumo['maior_filial']['nome']) ?></div>
                <small class="text-muted"><?= money($resumo['maior_filial']['valor']) ?> · <?= $resumo['maior_filial']['pct'] ?>%</small>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><span class="fw-semibold">Consumo por <?= $rotuloAgrup ?></span></div>
            <div class="card-body"><canvas id="chartConsumo" height="110"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><span class="fw-semibold">Ranking de Filiais</span></div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                <?php foreach ($resumo['ranking'] as $pos => $f): ?>
                    <li class="list-group-item d-flex align-items-center gap-3 py-3">
                        <span class="fw-bold text-muted" style="width:20px;font-size:13px"><?= $pos + 1 ?></span>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold" style="font-size:13px"><?= e($f['nome']) ?></span>
                                <span class="fw-bold" style="font-size:13px"><?= money($f['valor']) ?></span>
                            </div>
                            <div class="progress mt-1" style="height:5px">
                                <div class="progress-bar" style="width:<?= $f['pct'] ?>%;background:#CC0000"></div>
                            </div>
                        </div>
                        <span class="text-muted" style="font-size:11px;white-space:nowrap"><?= $f['pct'] ?>%</span>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Top Itens Consumidos</span>
        <div class="btn-group btn-group-sm">
            <a href="<?= e($link(['ordem' => 'valor'])) ?>" class="btn <?= $ordem === 'valor' ? 'btn-primary' : 'btn-outline-primary' ?>">Por valor</a>
            <a href="<?= e($link(['ordem' => 'quantidade'])) ?>" class="btn <?= $ordem === 'quantidade' ? 'btn-primary' : 'btn-outline-primary' ?>">Por quantidade</a>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>#</th><th>Código</th><th>Item</th><th>Un.</th><th class="text-end">Qtd.</th><th class="text-end">Valor</th></tr>
                </thead>
                <tbody>
                <?php foreach ($resumo['top_itens'] as $pos => $t): ?>
                    <tr>
                        <td class="text-muted" style="font-size:12px"><?= $pos + 1 ?></td>
                        <td class="small text-muted"><?= e($t['codigo'] ?? '—') ?></td>
                        <td class="fw-semibold"><a href="<?= url('itens/' . $t['item_id'] . '/relatorio') ?>" class="text-decoration-none"><?= e($t['nome']) ?></a></td>
                        <td class="small text-muted"><?= e($t['unidade'] ?? '') ?></td>
                        <td class="text-end <?= $ordem === 'quantidade' ? 'fw-semibold' : 'text-muted' ?>"><?= $qtd($t['quantidade']) ?></td>
                        <td class="text-end <?= $ordem === 'valor' ? 'fw-semibold' : 'text-muted' ?>"><?= money($t['valor']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const CORES = ['#CC0000','#0d6efd','#198754','#fd7e14','#6f42c1','#20c997','#dc3545','#0dcaf0'];
    const series = <?= json_encode($resumo['series'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    const brl = v => 'R$ ' + Number(v).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    new Chart(document.getElementById('chartConsumo'), {
        type: 'line',
        data: {
            labels: <?= json_encode($resumo['rotulos'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>,
            datasets: series.map((s, i) => {
                const cor = s.nome === 'Outras' ? '#adb5bd' : CORES[i % CORES.length];
                return { label: s.nome, data: s.valores, borderColor: cor, backgroundColor: cor + '22',
                         tension: 0.3, fill: false, pointRadius: 2 };
            })
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top' },
                tooltip: { callbacks: { label: c => c.dataset.label + ': ' + brl(c.raw) } }
            },
            scales: { y: { beginAtZero: true, ticks: { callback: v => brl(v) } } }
        }
    });
})();
</script>
<?php endif; ?>

<script>
// Trocar o agrupamento aplica o período padrão dele
document.getElementById('selAgrupamento').addEventListener('change', function () {
    this.form.querySelector('[name=data_inicio]').value = '';
    this.form.querySelector('[name=data_fim]').value = '';
    this.form.submit();
});
</script>
