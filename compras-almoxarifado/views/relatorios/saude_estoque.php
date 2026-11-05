<?php
// @versao Ver.002.281026
$link = fn(array $extra): string => url('relatorios/saude-estoque') . '?' . http_build_query(array_filter(array_merge([
    'filial_id' => $filialSel, 'grupo_produto_id' => $grupoId, 'prazo' => $prazo, 'aba' => $aba, 'ordem' => $ordem,
], $extra), fn($v) => $v !== null && $v !== ''));
$qtd       = fn($v): string => number_format((float)$v, 2, ',', '.');
$data      = fn(string $v): string => date('d/m/Y', strtotime($v));
$json      = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
$cards     = $resumo['cards'];
$vazio     = $resumo['cobertura'] === [] && $resumo['minimo'] === [];
$titulo    = 'font-size:11px;text-transform:uppercase;letter-spacing:.5px';
$abas      = [
    'parados'   => 'Parados (' . count($resumo['parados']) . ')',
    'minimo'    => 'Abaixo do mínimo (' . count($resumo['minimo']) . ')',
    'cobertura' => 'Cobertura (' . count($resumo['cobertura']) . ')',
];
$coberturaBadge = function (?float $dias): string {
    if ($dias === null) {
        return '<span class="badge bg-secondary">sem consumo</span>';
    }
    $cor = $dias < 15 ? 'danger' : ($dias < 30 ? 'warning' : 'success');
    return '<span class="badge bg-' . $cor . '">' . number_format($dias, 0, ',', '.') . ' dias</span>';
};
$itemLink = fn(array $l): string => '<a href="' . url('itens/' . $l['item_id'] . '/relatorio') . '" class="fw-semibold text-decoration-none">'
    . e($l['nome']) . '</a>';
?>
<div class="mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-heart-pulse me-2 text-success"></i>Saúde do Estoque</h4>
    <small class="text-muted">Situação do estoque agora · parado = sem saída há mais de <?= $prazo ?> dias · cobertura pelas saídas dos últimos 90 dias</small>
</div>

<form method="GET" action="<?= url('relatorios/saude-estoque') ?>" class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
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
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Parado há mais de</label>
                <select name="prazo" class="form-select form-select-sm">
                    <?php foreach ([60, 90, 180] as $dias): ?>
                    <option value="<?= $dias ?>" <?= $prazo === $dias ? 'selected' : '' ?>><?= $dias ?> dias</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="aba" value="<?= e($aba) ?>">
            <input type="hidden" name="ordem" value="<?= e($ordem) ?>">
            <div class="col-12 col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                <a href="<?= url('relatorios/saude-estoque') ?>" class="btn btn-sm btn-outline-secondary">Limpar</a>
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
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #198754!important">
            <div class="card-body">
                <div class="text-muted mb-1" style="<?= $titulo ?>">Valor imobilizado</div>
                <div class="fw-bold fs-5"><?= money($cards['valor_imobilizado']) ?></div>
                <small class="text-muted">estoque com saldo, a preço atual</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted mb-1" style="<?= $titulo ?>">Itens parados</div>
                <div class="fw-bold fs-5 <?= $cards['parados_qtd'] > 0 ? 'text-warning' : '' ?>"><?= $cards['parados_qtd'] ?></div>
                <small class="text-muted"><?= money($cards['parados_valor']) ?> parados</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted mb-1" style="<?= $titulo ?>">Abaixo do mínimo</div>
                <div class="fw-bold fs-5 <?= $cards['abaixo_minimo_qtd'] > 0 ? 'text-danger' : '' ?>"><?= $cards['abaixo_minimo_qtd'] ?></div>
                <small class="text-muted">itens por filial, incluindo zerados</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted mb-1" style="<?= $titulo ?>">Cobertura mediana</div>
                <div class="fw-bold fs-5"><?= $cards['cobertura_mediana'] === null ? '—' : number_format($cards['cobertura_mediana'], 0, ',', '.') . ' dias' ?></div>
                <small class="text-muted">dos itens que tiveram saída em 90 dias</small>
            </div>
        </div>
    </div>
</div>

<?php if ($resumo['grafico']): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3"><span class="fw-semibold">Valor imobilizado por filial</span></div>
    <div class="card-body"><canvas id="chartSaude" height="90"></canvas></div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <ul class="nav nav-tabs card-header-tabs">
            <?php foreach ($abas as $chave => $nome): ?>
            <li class="nav-item">
                <a class="nav-link <?= $aba === $chave ? 'active' : '' ?>" href="<?= e($link(['aba' => $chave])) ?>"><?= $nome ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
        <div class="d-flex gap-2">
            <?php if ($aba === 'cobertura'): ?>
            <div class="btn-group btn-group-sm">
                <a href="<?= e($link(['ordem' => 'acaba'])) ?>" class="btn <?= $ordem === 'acaba' ? 'btn-primary' : 'btn-outline-primary' ?>">Acaba primeiro</a>
                <a href="<?= e($link(['ordem' => 'sobra'])) ?>" class="btn <?= $ordem === 'sobra' ? 'btn-primary' : 'btn-outline-primary' ?>">Sobra mais</a>
            </div>
            <?php endif; ?>
            <a href="<?= e($link(['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-success">
                <i class="bi bi-download me-1"></i>Exportar CSV
            </a>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!$linhas): ?>
        <p class="text-muted small p-3 mb-0">Nenhum item nesta aba.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
            <?php if ($aba === 'minimo'): ?>
                <thead class="table-light">
                    <tr><th>Código</th><th>Item</th><th>Filial</th><th class="text-end">Qtd.</th><th class="text-end">Mínimo</th><th class="text-end">Falta</th><th>Cobertura</th></tr>
                </thead>
                <tbody>
                <?php foreach ($linhas as $l): ?>
                    <tr data-linha="<?= e($l['item_id'] . '|' . $l['filial_id']) ?>">
                        <td class="small text-muted"><?= e($l['codigo'] ?? '—') ?></td>
                        <td><?= $itemLink($l) ?></td>
                        <td class="small"><?= e($l['filial']) ?></td>
                        <td class="text-end"><?= $qtd($l['quantidade']) ?> <small class="text-muted"><?= e($l['unidade'] ?? '') ?></small></td>
                        <td class="text-end"><?= $qtd($l['minimo']) ?></td>
                        <td class="text-end fw-semibold text-danger"><?= $qtd($l['falta']) ?></td>
                        <td><?= $coberturaBadge($l['cobertura']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            <?php elseif ($aba === 'cobertura'): ?>
                <thead class="table-light">
                    <tr><th>Código</th><th>Item</th><th>Filial</th><th class="text-end">Qtd.</th><th class="text-end">Saída 90 dias</th><th class="text-end">Média/dia</th><th>Cobertura</th></tr>
                </thead>
                <tbody>
                <?php foreach ($linhas as $l): ?>
                    <tr data-linha="<?= e($l['item_id'] . '|' . $l['filial_id']) ?>">
                        <td class="small text-muted"><?= e($l['codigo'] ?? '—') ?></td>
                        <td><?= $itemLink($l) ?></td>
                        <td class="small"><?= e($l['filial']) ?></td>
                        <td class="text-end"><?= $qtd($l['quantidade']) ?> <small class="text-muted"><?= e($l['unidade'] ?? '') ?></small></td>
                        <td class="text-end"><?= $qtd($l['saida_90']) ?></td>
                        <td class="text-end small text-muted"><?= $qtd($l['media_dia']) ?></td>
                        <td><?= $coberturaBadge($l['cobertura']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            <?php else: ?>
                <thead class="table-light">
                    <tr><th>Código</th><th>Item</th><th>Filial</th><th class="text-end">Qtd.</th><th class="text-end">Valor</th><th>Última saída</th><th class="text-end">Dias parado</th></tr>
                </thead>
                <tbody>
                <?php foreach ($linhas as $l): ?>
                    <tr data-linha="<?= e($l['item_id'] . '|' . $l['filial_id']) ?>">
                        <td class="small text-muted"><?= e($l['codigo'] ?? '—') ?></td>
                        <td><?= $itemLink($l) ?></td>
                        <td class="small"><?= e($l['filial']) ?></td>
                        <td class="text-end"><?= $qtd($l['quantidade']) ?> <small class="text-muted"><?= e($l['unidade'] ?? '') ?></small></td>
                        <td class="text-end fw-semibold"><?= money($l['valor']) ?></td>
                        <td class="small"><?= $l['ultima_saida'] ? $data($l['ultima_saida']) : '<span class="text-muted">nunca</span>' ?></td>
                        <td class="text-end"><?= $l['dias_parado'] === null ? '<span class="badge bg-dark">nunca saiu</span>' : $l['dias_parado'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            <?php endif; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <small class="text-muted">Página <?= $pagina ?> de <?= $totalPaginas ?> · <?= $total ?> registros</small>
        <?php if ($totalPaginas > 1): ?>
        <div class="btn-group btn-group-sm">
            <a href="<?= e($link(['pagina' => $pagina - 1])) ?>" class="btn btn-outline-secondary <?= $pagina <= 1 ? 'disabled' : '' ?>">Anterior</a>
            <a href="<?= e($link(['pagina' => $pagina + 1])) ?>" class="btn btn-outline-secondary <?= $pagina >= $totalPaginas ? 'disabled' : '' ?>">Próxima</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($resumo['grafico']): ?>
<script>
(function () {
    const dados = <?= json_encode($resumo['grafico'], $json | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const brl = v => 'R$ ' + Number(v).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    new Chart(document.getElementById('chartSaude'), {
        type: 'bar',
        data: {
            labels: dados.map(d => d.filial),
            datasets: [
                { label: 'Com giro', data: dados.map(d => d.giro),   backgroundColor: '#198754' },
                { label: 'Parado',   data: dados.map(d => d.parado), backgroundColor: '#fd7e14' },
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: { tooltip: { callbacks: { label: c => c.dataset.label + ': ' + brl(c.raw) } } },
            scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { callback: v => brl(v) } } }
        }
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>
