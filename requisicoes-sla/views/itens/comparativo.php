<?php $title = 'Comparativo de Itens'; ?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('itens') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi bi-layout-three-columns me-2 text-primary"></i>Comparativo de Itens</h4>
</div>

<!-- Seletor de itens -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="<?= url('itens/comparativo') ?>" id="formComparativo">
            <div class="row g-2 align-items-end">
                <div class="col-md-10">
                    <label class="form-label fw-semibold">Selecione os itens para comparar (até 5)</label>
                    <select name="ids[]" id="selectItens" class="form-select" multiple size="1" style="height:auto">
                        <?php foreach ($todosItens as $it): ?>
                        <option value="<?= $it['id'] ?>"
                            <?= in_array($it['id'], $ids) ? 'selected' : '' ?>>
                            <?= e($it['codigo'] ? '[' . $it['codigo'] . '] ' : '') ?><?= e($it['nome']) ?>
                            <?= $it['grupo_nome'] ? '— ' . e($it['grupo_nome']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Segure Ctrl (Windows) ou Cmd (Mac) para selecionar múltiplos.</div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-layout-three-columns me-1"></i>Comparar
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($itens)): ?>
<!-- Tabela comparativa -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-table me-2 text-primary"></i>Resultado — <?= count($itens) ?> item(ns) comparados
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead class="table-primary">
                    <tr>
                        <th style="min-width:180px">Critério</th>
                        <?php foreach ($itens as $it): ?>
                        <th class="text-center">
                            <div class="fw-bold"><?= e($it['nome']) ?></div>
                            <small class="text-muted fw-normal"><?= e($it['codigo'] ?? '') ?> · <?= e($it['unidade'] ?? '') ?></small>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <!-- Grupo -->
                    <tr class="table-light">
                        <td class="fw-semibold small text-muted text-uppercase">Grupo</td>
                        <?php foreach ($itens as $it): ?>
                        <td class="text-center small"><?= e($it['grupo_nome'] ?? '—') ?></td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- Marca -->
                    <tr>
                        <td class="fw-semibold small text-muted text-uppercase">Marca</td>
                        <?php foreach ($itens as $it): ?>
                        <td class="text-center small"><?= e($it['marca'] ?? '—') ?></td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- Estoque Total -->
                    <tr class="table-light">
                        <td class="fw-semibold small text-muted text-uppercase">Estoque Total</td>
                        <?php
                        $maxEst = max(array_map(fn($it) => (float)($comparativo[$it['id']]['total'] ?? 0), $itens));
                        foreach ($itens as $it):
                            $est = (float)($comparativo[$it['id']]['total'] ?? 0);
                            $isMax = $est === $maxEst && $maxEst > 0;
                        ?>
                        <td class="text-center fw-semibold <?= $isMax ? 'text-success' : '' ?>">
                            <?= number_format($est, 2, ',', '.') ?> <?= e($it['unidade'] ?? '') ?>
                            <?= $isMax ? '<i class="bi bi-star-fill text-warning ms-1" title="Maior estoque"></i>' : '' ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- Total de Compras -->
                    <tr class="table-light">
                        <td class="fw-semibold small text-muted text-uppercase">Total de Compras</td>
                        <?php foreach ($itens as $it): ?>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= $comparativo[$it['id']]['total_compras'] ?? 0 ?></span>
                        </td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- Preço Mínimo -->
                    <tr>
                        <td class="fw-semibold small text-muted text-uppercase">Menor Preço Pago</td>
                        <?php
                        $mins = array_filter(array_map(fn($it) => (float)($comparativo[$it['id']]['preco_min'] ?? 0), $itens));
                        $menorMin = !empty($mins) ? min($mins) : 0;
                        foreach ($itens as $it):
                            $val = (float)($comparativo[$it['id']]['preco_min'] ?? 0);
                            $isBest = $val > 0 && $val === $menorMin;
                        ?>
                        <td class="text-end <?= $isBest ? 'text-success fw-bold' : '' ?>">
                            <?= $val > 0 ? money($val) : '<span class="text-muted small">—</span>' ?>
                            <?= $isBest ? '<i class="bi bi-trophy-fill text-success ms-1" title="Mais barato"></i>' : '' ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- Preço Médio -->
                    <tr class="table-light">
                        <td class="fw-semibold small text-muted text-uppercase">Preço Médio</td>
                        <?php
                        $medios = array_filter(array_map(fn($it) => (float)($comparativo[$it['id']]['preco_medio'] ?? 0), $itens));
                        $menorMedio = !empty($medios) ? min($medios) : 0;
                        foreach ($itens as $it):
                            $val = (float)($comparativo[$it['id']]['preco_medio'] ?? 0);
                            $isBest = $val > 0 && $val === $menorMedio;
                        ?>
                        <td class="text-end <?= $isBest ? 'fw-bold text-success' : '' ?>">
                            <?= $val > 0 ? money($val) : '<span class="text-muted small">—</span>' ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- Preço Máximo -->
                    <tr>
                        <td class="fw-semibold small text-muted text-uppercase">Maior Preço Pago</td>
                        <?php foreach ($itens as $it): ?>
                        <td class="text-end text-danger small">
                            <?php $val = (float)($comparativo[$it['id']]['preco_max'] ?? 0); ?>
                            <?= $val > 0 ? money($val) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- Último Preço -->
                    <tr class="table-light">
                        <td class="fw-semibold small text-muted text-uppercase">Último Preço</td>
                        <?php
                        $ultimos = array_filter(array_map(fn($it) => (float)($comparativo[$it['id']]['ultimo_preco'] ?? 0), $itens));
                        $menorUltimo = !empty($ultimos) ? min($ultimos) : 0;
                        foreach ($itens as $it):
                            $val = (float)($comparativo[$it['id']]['ultimo_preco'] ?? 0);
                            $isBest = $val > 0 && $val === $menorUltimo;
                        ?>
                        <td class="text-end fw-semibold <?= $isBest ? 'text-success' : '' ?>">
                            <?= $val > 0 ? money($val) : '<span class="text-muted small">—</span>' ?>
                            <?= $isBest ? '<i class="bi bi-trophy-fill text-success ms-1"></i>' : '' ?>
                            <div class="small text-muted fw-normal"><?= e($comparativo[$it['id']]['ultimo_fornecedor'] ?? '') ?></div>
                        </td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- Links -->
                    <tr>
                        <td class="fw-semibold small text-muted text-uppercase">Relatório Completo</td>
                        <?php foreach ($itens as $it): ?>
                        <td class="text-center">
                            <a href="<?= url('itens/' . $it['id'] . '/relatorio') ?>" class="btn btn-outline-primary btn-sm" target="_blank">
                                <i class="bi bi-bar-chart-line me-1"></i>Ver
                            </a>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-layout-three-columns fs-1 d-block mb-3 opacity-25"></i>
        <p>Selecione ao menos 2 itens acima e clique em <strong>Comparar</strong>.</p>
    </div>
</div>
<?php endif; ?>

<script>
// Limita seleção a 5 itens
document.getElementById('selectItens').addEventListener('change', function() {
    const sel = Array.from(this.selectedOptions);
    if (sel.length > 5) {
        sel[sel.length - 1].selected = false;
        alert('Selecione no máximo 5 itens para comparar.');
    }
});
</script>
