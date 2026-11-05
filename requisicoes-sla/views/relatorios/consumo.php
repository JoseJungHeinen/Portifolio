<?php
// @versao Ver.002.281026
$link = fn(array $extra): string => url('relatorios/consumo') . '?' . http_build_query(array_filter(array_merge([
    'de' => $de, 'ate' => $ate, 'filial_id' => $filialSel, 'grupo_produto_id' => $grupoId,
    'so_repor' => $soRepor ? '1' : null, 'mes' => $mes,
], $extra), fn($v) => $v !== null && $v !== ''));
$qtd      = fn($v): string => number_format((float)$v, 2, ',', '.');
$nomesMes = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
$rotulo   = fn(string $m): string => $nomesMes[(int)substr($m, 5, 2) - 1] . '/' . substr($m, 2, 2);
$json     = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
$titulo   = 'font-size:11px;text-transform:uppercase;letter-spacing:.5px';

$totalConsumo = array_sum(array_column($porMes, 'consumo_valor'));
$mediaConsumo = $porMes ? $totalConsumo / count($porMes) : 0.0;
$ultimoMes    = $porMes ? $porMes[count($porMes) - 1] : null;
$vazio        = $totalConsumo <= 0 && array_sum(array_column($porMes, 'repor_valor')) <= 0;
$itemLink = fn(array $l): string => '<a href="' . url('itens/' . $l['item_id'] . '/relatorio') . '" class="fw-semibold text-decoration-none">'
    . e($l['nome']) . '</a>';
?>
<div class="mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-clipboard2-data me-2 text-primary"></i>Consumo por Período</h4>
    <small class="text-muted">Saídas de uso do estoque de cada filial (requisição, brinde, perda, avaria, outro) por mês e quanto faltava no fim do mês para chegar ao estoque mínimo · o mínimo usado é o configurado hoje</small>
</div>

<form method="GET" action="<?= url('relatorios/consumo') ?>" class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">De</label>
                <input type="month" name="de" value="<?= e($de) ?>" max="<?= date('Y-m') ?>" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Até</label>
                <input type="month" name="ate" value="<?= e($ate) ?>" max="<?= date('Y-m') ?>" class="form-control form-control-sm">
            </div>
            <?php if ($filiaisFiltro): ?>
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted mb-1">Filial</label>
                <select name="filial_id" class="form-select form-select-sm">
                    <option value="">Todas</option>
                    <?php foreach ($filiaisFiltro as $f): ?>
                    <option value="<?= e($f['id']) ?>" <?= $filialSel === $f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted mb-1">Grupo de produto</label>
                <select name="grupo_produto_id" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($grupos as $gr): ?>
                    <option value="<?= e($gr['id']) ?>" <?= $grupoId === $gr['id'] ? 'selected' : '' ?>><?= e($gr['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($soRepor): ?><input type="hidden" name="so_repor" value="1"><?php endif; ?>
            <div class="col-12 col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                <a href="<?= url('relatorios/consumo') ?>" class="btn btn-sm btn-outline-secondary">Limpar</a>
            </div>
        </div>
    </div>
</form>

<?php if ($vazio): ?>
<div class="card border-0 shadow-sm text-center py-5">
    <div class="card-body text-muted">
        <i class="bi bi-inbox fs-1 d-block mb-2"></i>Nenhum consumo nem item abaixo do mínimo nesse período.
    </div>
</div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted fw-semibold" style="<?= $titulo ?>">Consumido no período</div>
            <div class="fs-5 fw-bold text-primary"><?= money($totalConsumo) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted fw-semibold" style="<?= $titulo ?>">Média por mês</div>
            <div class="fs-5 fw-bold"><?= money($mediaConsumo) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted fw-semibold" style="<?= $titulo ?>">Abaixo do mínimo · <?= $rotulo($ultimoMes['mes']) ?></div>
            <div class="fs-5 fw-bold text-danger"><?= (int)$ultimoMes['abaixo_minimo'] ?> <small class="fs-6 fw-normal text-muted">item(ns) × filial</small></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <div class="text-muted fw-semibold" style="<?= $titulo ?>">A repor · <?= $rotulo($ultimoMes['mes']) ?></div>
            <div class="fs-5 fw-bold text-danger"><?= money($ultimoMes['repor_valor']) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><span class="fw-semibold">Consumido × a repor por mês</span></div>
            <div class="card-body"><canvas id="chartConsumo" height="130"></canvas></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><span class="fw-semibold">Resumo mensal</span> <small class="text-muted">· clique no mês para ver os itens</small></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr><th>Mês</th><th class="text-end">Consumido</th><th class="text-end">Itens</th><th class="text-end">Abaixo mín.</th><th class="text-end">A repor</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_reverse($porMes) as $p): ?>
                            <tr class="<?= $p['mes'] === $mes ? 'table-primary' : '' ?>">
                                <td><a href="<?= e($link(['mes' => $p['mes'], 'pagina' => null])) ?>" class="text-decoration-none fw-semibold"><?= $rotulo($p['mes']) ?></a></td>
                                <td class="text-end"><?= money($p['consumo_valor']) ?></td>
                                <td class="text-end small"><?= (int)$p['itens_consumidos'] ?></td>
                                <td class="text-end small"><?= (int)$p['abaixo_minimo'] ?></td>
                                <td class="text-end <?= $p['repor_valor'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= money($p['repor_valor']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-semibold">
                            <tr><td>Total</td><td class="text-end"><?= money($totalConsumo) ?></td><td colspan="3"></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
        <span class="fw-semibold">Itens · <?= $rotulo($mes) ?> <small class="text-muted fw-normal">(<?= $total ?>)</small></span>
        <div class="d-flex gap-2">
            <div class="btn-group btn-group-sm">
                <a href="<?= e($link(['so_repor' => null, 'pagina' => null])) ?>" class="btn <?= !$soRepor ? 'btn-primary' : 'btn-outline-primary' ?>">Todos</a>
                <a href="<?= e($link(['so_repor' => '1', 'pagina' => null])) ?>" class="btn <?= $soRepor ? 'btn-primary' : 'btn-outline-primary' ?>">Só a repor</a>
            </div>
            <a href="<?= e($link(['export' => 'csv', 'mes' => null])) ?>" class="btn btn-sm btn-outline-success" title="Todos os meses do período">
                <i class="bi bi-download me-1"></i>Exportar CSV
            </a>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!$linhas): ?>
        <p class="text-muted small p-3 mb-0">Nenhum item neste mês.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>Código</th><th>Item</th><th>Filial</th><th class="text-end">Consumido</th><th class="text-end">Valor consumido</th>
                        <th class="text-end">Saldo fim do mês</th><th class="text-end">Mínimo</th><th class="text-end">A repor</th><th class="text-end">Valor a repor</th></tr>
                </thead>
                <tbody>
                <?php foreach ($linhas as $l): ?>
                    <tr data-linha="<?= e($l['item_id'] . '|' . $l['filial_id']) ?>">
                        <td class="small text-muted"><?= e($l['codigo'] ?? '—') ?></td>
                        <td><?= $itemLink($l) ?></td>
                        <td class="small"><?= e($l['filial']) ?></td>
                        <td class="text-end"><?= $qtd($l['consumo_qtd']) ?> <small class="text-muted"><?= e($l['unidade'] ?? '') ?></small></td>
                        <td class="text-end"><?= money($l['consumo_valor']) ?></td>
                        <td class="text-end"><?= $qtd($l['saldo_fim']) ?></td>
                        <td class="text-end"><?= $l['minimo'] > 0 ? $qtd($l['minimo']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end <?= $l['repor'] > 0 ? 'fw-semibold text-danger' : 'text-muted' ?>"><?= $qtd($l['repor']) ?></td>
                        <td class="text-end <?= $l['repor'] > 0 ? 'fw-semibold text-danger' : 'text-muted' ?>"><?= money($l['repor_valor']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr><td colspan="4">Total do mês</td><td class="text-end"><?= money($totalMes['consumo_valor']) ?></td>
                        <td colspan="3"></td><td class="text-end text-danger"><?= money($totalMes['repor_valor']) ?></td></tr>
                </tfoot>
            </table>
        </div>
        <?php if ($totalPaginas > 1): ?>
        <nav class="p-2 border-top">
            <ul class="pagination pagination-sm mb-0 justify-content-center">
                <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                <li class="page-item <?= $p === $pagina ? 'active' : '' ?>"><a class="page-link" href="<?= e($link(['pagina' => $p])) ?>"><?= $p ?></a></li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const labels = <?= json_encode(array_map(fn($p) => $rotulo($p['mes']), $porMes), $json) ?>;
    const consumo = <?= json_encode(array_map(fn($p) => round($p['consumo_valor'], 2), $porMes), $json) ?>;
    const repor   = <?= json_encode(array_map(fn($p) => round($p['repor_valor'], 2), $porMes), $json) ?>;
    const brl = v => v.toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'});
    new Chart(document.getElementById('chartConsumo'), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {label: 'Consumido', data: consumo, backgroundColor: 'rgba(13,110,253,.75)'},
                {label: 'A repor (fim do mês)', data: repor, backgroundColor: 'rgba(220,53,69,.7)'},
            ],
        },
        options: {
            plugins: {tooltip: {callbacks: {label: c => c.dataset.label + ': ' + brl(c.parsed.y)}}},
            scales: {y: {beginAtZero: true, ticks: {callback: v => brl(v)}}},
        },
    });
})();
</script>
<?php endif; ?>
