<?php
// @versao Ver.002.281026
$link = fn(array $extra): string => url('relatorios/compras') . '?' . http_build_query(array_filter(array_merge([
    'data_inicio' => $de, 'data_fim' => $ate, 'filial_id' => $filialSel, 'setor_id' => $filtros['setor_id'],
    'fornecedor_id' => $filtros['fornecedor_id'], 'status' => $status, 'aba' => $aba,
], $extra), fn($v) => $v !== null && $v !== ''));
$qtd   = fn($v): string => number_format((float)$v, 2, ',', '.');
$data  = fn(string $v): string => date('d/m/Y', strtotime($v));
$json  = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
$corOc = ['rascunho' => 'secondary', 'aprovado' => 'primary', 'enviado' => 'info',
          'recebido_parcial' => 'warning', 'recebido_total' => 'success', 'cancelado' => 'dark'];
$corNf = ['pendente_conferencia' => 'warning', 'aprovada' => 'success', 'divergente' => 'danger', 'rejeitada' => 'dark'];
$abas  = ['ocs' => 'Ordens de Compra', 'nfs' => 'Notas Fiscais', 'itens' => 'Itens'];
$titulo = 'font-size:11px;text-transform:uppercase;letter-spacing:.5px';
?>
<div class="mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-bag-check me-2 text-primary"></i>Relatório de Compras</h4>
    <small class="text-muted">
        Ordens de compra de <?= $data($de) ?> a <?= $data($ate) ?>
        · <?= $status ? 'status ' . e($statusOc[$status]) : 'sem rascunhos e canceladas' ?>
    </small>
</div>

<form method="GET" action="<?= url('relatorios/compras') ?>" class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
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
                    <option value="<?= e($s['id']) ?>" <?= $filtros['setor_id'] === $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Fornecedor</label>
                <select name="fornecedor_id" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($fornecedores as $fo): ?>
                    <option value="<?= e($fo['id']) ?>" <?= $filtros['fornecedor_id'] === $fo['id'] ? 'selected' : '' ?>><?= e($fo['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Status da OC</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Todos (sem rascunho e cancelada)</option>
                    <?php foreach ($statusOc as $valor => $texto): ?>
                    <option value="<?= e($valor) ?>" <?= $status === $valor ? 'selected' : '' ?>><?= e($texto) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="aba" value="<?= e($aba) ?>">
            <div class="col-12 col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                <a href="<?= url('relatorios/compras') ?>" class="btn btn-sm btn-outline-secondary">Limpar</a>
            </div>
        </div>
    </div>
</form>

<?php if ($resumo['qtd_ocs'] === 0): ?>
<div class="card border-0 shadow-sm text-center py-5">
    <div class="card-body text-muted">
        <i class="bi bi-inbox fs-1 d-block mb-2"></i>Nenhum registro para esse filtro.
    </div>
</div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #CC0000!important">
            <div class="card-body">
                <div class="text-muted mb-1" style="<?= $titulo ?>">Em OCs</div>
                <div class="fw-bold fs-5"><?= money($resumo['valor_ocs']) ?></div>
                <small class="text-muted"><?= $resumo['qtd_ocs'] ?> ordens de compra</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted mb-1" style="<?= $titulo ?>">Em NFs</div>
                <div class="fw-bold fs-5"><?= money($resumo['valor_nfs']) ?></div>
                <small class="text-muted"><?= $resumo['qtd_nfs'] ?> notas (total com impostos e frete, sem rejeitadas)</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted mb-1" style="<?= $titulo ?>">Pendente de nota</div>
                <div class="fw-bold fs-5 <?= $resumo['pendente'] > 0 ? 'text-warning' : '' ?>"><?= money($resumo['pendente']) ?></div>
                <small class="text-muted">OCs sem nota ou com nota parcial</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted mb-1" style="<?= $titulo ?>">Itens comprados</div>
                <div class="fw-bold fs-5"><?= $resumo['itens_distintos'] ?></div>
                <small class="text-muted">itens diferentes</small>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><span class="fw-semibold">Compras por setor</span></div>
            <div class="card-body"><canvas id="chartSetor" height="220"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><span class="fw-semibold">Compras por fornecedor (top 8)</span></div>
            <div class="card-body"><canvas id="chartFornecedor" height="220"></canvas></div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <ul class="nav nav-tabs card-header-tabs">
            <?php foreach ($abas as $chave => $nome): ?>
            <li class="nav-item">
                <a class="nav-link <?= $aba === $chave ? 'active' : '' ?>" href="<?= e($link(['aba' => $chave])) ?>"><?= $nome ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
        <a href="<?= e($link(['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-success">
            <i class="bi bi-download me-1"></i>Exportar CSV
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
            <?php if ($aba === 'nfs'): ?>
                <thead class="table-light">
                    <tr><th>NF</th><th>Data</th><th>Fornecedor</th><th>OC</th><th class="text-end">Valor</th><th class="text-end">Frete</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($linhas as $l): ?>
                    <tr data-nf="<?= e($l['id']) ?>">
                        <td class="text-nowrap"><a href="<?= url('fiscal/' . $l['id']) ?>" class="fw-semibold text-decoration-none"><?= e($l['numero_nf']) ?></a></td>
                        <td class="small text-nowrap"><?= $data($l['criado_em']) ?></td>
                        <td class="small"><?= e($l['fornecedor']) ?></td>
                        <td class="small text-nowrap"><a href="<?= url('compras/ordens/' . $l['oc_id']) ?>" class="text-decoration-none"><?= e($l['numero_oc']) ?></a></td>
                        <td class="text-end"><?= money($l['valor']) ?></td>
                        <td class="text-end small text-muted"><?= money($l['frete']) ?></td>
                        <td><span class="badge bg-<?= $corNf[$l['status']] ?? 'secondary' ?>"><?= e($statusNf[$l['status']] ?? $l['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            <?php elseif ($aba === 'itens'): ?>
                <thead class="table-light">
                    <tr><th>Código</th><th>Item</th><th>Un.</th><th class="text-end">Qtd. OC</th><th class="text-end">Valor OC</th><th class="text-end">Qtd. recebida NF</th><th class="text-end">Preço médio</th></tr>
                </thead>
                <tbody>
                <?php foreach ($linhas as $l): ?>
                    <tr data-item="<?= e($l['item_id']) ?>">
                        <td class="small text-muted"><?= e($l['codigo'] ?? '—') ?></td>
                        <td class="fw-semibold"><a href="<?= url('itens/' . $l['item_id'] . '/relatorio') ?>" class="text-decoration-none"><?= e($l['nome']) ?></a></td>
                        <td class="small text-muted"><?= e($l['unidade'] ?? '') ?></td>
                        <td class="text-end"><?= $qtd($l['qtd_oc']) ?></td>
                        <td class="text-end fw-semibold"><?= money($l['valor_oc']) ?></td>
                        <td class="text-end <?= $l['qtd_nf'] + 0.009 < $l['qtd_oc'] ? 'text-warning' : 'text-success' ?>"><?= $qtd($l['qtd_nf']) ?></td>
                        <td class="text-end small"><?= money($l['preco_medio']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            <?php else: ?>
                <thead class="table-light">
                    <tr><th>OC</th><th>Data</th><th>Filial</th><th>Setor</th><th>Fornecedor</th><th>Status</th><th class="text-end">Valor OC</th><th class="text-end">Produtos nas NFs</th><th class="text-end">Diferença</th></tr>
                </thead>
                <tbody>
                <?php foreach ($linhas as $l): ?>
                    <tr data-oc="<?= e($l['id']) ?>">
                        <td class="text-nowrap"><a href="<?= url('compras/ordens/' . $l['id']) ?>" class="fw-semibold text-decoration-none"><?= e($l['numero_oc']) ?></a></td>
                        <td class="small text-nowrap"><?= $data($l['criado_em']) ?></td>
                        <td class="small"><?= e($l['filial']) ?></td>
                        <td class="small"><?= e($l['setor']) ?></td>
                        <td class="small"><?= e($l['fornecedor']) ?></td>
                        <td><span class="badge bg-<?= $corOc[$l['status']] ?? 'secondary' ?>"><?= e($statusOc[$l['status']] ?? $l['status']) ?></span></td>
                        <td class="text-end"><?= money($l['valor_oc']) ?></td>
                        <td class="text-end"><?= money($l['valor_nfs']) ?></td>
                        <td class="text-end fw-semibold <?= $l['diferenca'] > 0.009 ? 'text-warning' : ($l['diferenca'] < -0.009 ? 'text-danger' : 'text-success') ?>"><?= money($l['diferenca']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            <?php endif; ?>
            </table>
        </div>
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

<script>
(function () {
    const CORES = ['#CC0000','#0d6efd','#198754','#fd7e14','#6f42c1','#20c997','#dc3545','#0dcaf0'];
    const brl = v => 'R$ ' + Number(v).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const setores = <?= json_encode($porSetor, $json | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const fornecedores = <?= json_encode($porFornecedor, $json | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    new Chart(document.getElementById('chartSetor'), {
        type: 'bar',
        data: { labels: setores.map(s => s.label),
                datasets: [{ label: 'Em OCs', data: setores.map(s => s.total), backgroundColor: '#CC0000' }] },
        options: { indexAxis: 'y', responsive: true,
                   plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => brl(c.raw) } } },
                   scales: { x: { beginAtZero: true, ticks: { callback: v => brl(v) } } } }
    });

    new Chart(document.getElementById('chartFornecedor'), {
        type: 'doughnut',
        data: { labels: fornecedores.map(f => f.label),
                datasets: [{ data: fornecedores.map(f => f.total), backgroundColor: CORES }] },
        options: { responsive: true,
                   plugins: { legend: { position: 'right' },
                              tooltip: { callbacks: { label: c => ' ' + c.label + ': ' + brl(c.raw) } } } }
    });
})();
</script>
<?php endif; ?>
