<?php
// @versao Ver.001.160926
$motivoLabel = [
    'compra'        => 'Compra',
    'inventario'    => 'Inventário',
    'perda'         => 'Perda',
    'avaria'        => 'Avaria',
    'correcao'      => 'Ajuste',
    'transferencia' => 'Transferência',
    'requisicao'    => 'Baixa de Requisição Interna',
    'outro'         => 'Uso e consumo',
];
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-arrow-left-right me-2 text-primary"></i>Movimentação de Estoque</h4>
</div>

<!-- Filtros -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">De</label>
                <input type="date" name="data_ini" class="form-control form-control-sm" value="<?= e($dataIni) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">Até</label>
                <input type="date" name="data_fim" class="form-control form-control-sm" value="<?= e($dataFim) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">Tipo</label>
                <select name="tipo" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <option value="entrada" <?= $tipo === 'entrada' ? 'selected' : '' ?>>Entrada</option>
                    <option value="saida" <?= $tipo === 'saida' ? 'selected' : '' ?>>Saída</option>
                </select>
            </div>
            <?php if (!empty($filiais)): ?>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">Loja / Filial</label>
                <select name="filial_id" class="form-select form-select-sm">
                    <option value="">Todas</option>
                    <?php foreach ($filiais as $fi): ?>
                    <option value="<?= $fi['id'] ?>" <?= $filialId === $fi['id'] ? 'selected' : '' ?>><?= e($fi['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">Setor</label>
                <select name="setor_id" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($setores as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $setorId === $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filtrar</button>
            </div>
            <?php if ($setorId): ?>
            <div class="col-auto">
                <a href="<?= url('relatorios/movimentacao-estoque') ?>?data_ini=<?= e($dataIni) ?>&data_fim=<?= e($dataFim) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-lg me-1"></i>Limpar setor
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<p class="text-muted small mb-3">
    <i class="bi bi-info-circle me-1"></i>O valor é calculado pelo último preço de compra registrado até a data de cada movimentação (sem histórico, usa o custo atual do estoque ou o valor de referência do item).
</p>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:.7rem">Entradas (Qtd.)</div>
                <div class="fs-5 fw-bold text-success"><?= number_format($totais['entrada']['qtd'], 2, ',', '.') ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:.7rem">Entradas (Valor)</div>
                <div class="fs-5 fw-bold text-success"><?= money($totais['entrada']['valor']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:.7rem">Saídas (Qtd.)</div>
                <div class="fs-5 fw-bold text-danger"><?= number_format($totais['saida']['qtd'], 2, ',', '.') ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:.7rem">Saídas (Valor)</div>
                <div class="fs-5 fw-bold text-danger"><?= money($totais['saida']['valor']) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($setorId && $setorNome): ?>
<!-- Drill-down: itens consumidos pelo setor -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-search me-2 text-info"></i>Itens consumidos por "<?= e($setorNome) ?>" no período</h6>
        <?php if (empty($itensSetor)): ?>
        <p class="text-muted small mb-0">Nenhuma saída de estoque registrada para este setor no período.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Código</th><th>Item</th><th class="text-end">Quantidade</th><th class="text-end">Valor</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($itensSetor as $it): ?>
                    <tr>
                        <td class="small"><code><?= e($it['codigo']) ?></code></td>
                        <td class="small"><?= e($it['item']) ?></td>
                        <td class="text-end small"><?= number_format((float)$it['qtd_total'], 2, ',', '.') ?> <?= e($it['unidade']) ?></td>
                        <td class="text-end small fw-semibold"><?= money($it['valor_total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <!-- Por Setor -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h6 class="fw-bold mb-3"><i class="bi bi-building me-2 text-info"></i>Saídas por Setor</h6>
                <?php if (empty($porSetor)): ?>
                <p class="text-muted small mb-0">Nenhuma saída associada a um setor no período.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Setor</th><th class="text-end">Qtd.</th><th class="text-end">Valor</th><th class="text-end">%</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($porSetor as $s):
                                $pct = $totalSetores > 0 ? ($s['valor_total'] / $totalSetores * 100) : 0;
                                $urlSetor = url('relatorios/movimentacao-estoque') . '?data_ini=' . urlencode($dataIni)
                                    . '&data_fim=' . urlencode($dataFim) . '&setor_id=' . urlencode($s['setor_id']);
                            ?>
                            <tr>
                                <td class="small">
                                    <a href="<?= $urlSetor ?>" class="text-decoration-none"><?= e($s['setor']) ?></a>
                                </td>
                                <td class="text-end small"><?= number_format((float)$s['qtd_total'], 2, ',', '.') ?></td>
                                <td class="text-end small fw-semibold"><?= money($s['valor_total']) ?></td>
                                <td class="text-end small text-muted"><?= number_format($pct, 1) ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Por Filial / Loja -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h6 class="fw-bold mb-3"><i class="bi bi-shop me-2 text-primary"></i>Movimentação por Loja</h6>
                <?php if (empty($porFilial)): ?>
                <p class="text-muted small mb-0">Nenhuma movimentação no período.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Loja</th><th class="text-end">Entrada</th><th class="text-end">Saída</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($porFilial as $f): ?>
                            <tr>
                                <td class="small"><?= e($f['filial']) ?></td>
                                <td class="text-end small text-success"><?= money($f['entrada_valor']) ?></td>
                                <td class="text-end small text-danger"><?= money($f['saida_valor']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Lista detalhada -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="px-3 pt-3">
            <h6 class="fw-bold mb-0"><i class="bi bi-list-ul me-2 text-secondary"></i>Movimentações do Período</h6>
        </div>
        <?php if (empty($movimentos)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-2 d-block mb-2"></i>Nenhum movimento encontrado no período selecionado.
        </div>
        <?php else: ?>
        <div class="table-responsive mt-2">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Data / Hora</th>
                        <th>Tipo</th>
                        <th>Item</th>
                        <th>Motivo</th>
                        <th>Setor</th>
                        <th>Loja</th>
                        <th class="text-end">Quantidade</th>
                        <th class="text-end">Valor</th>
                        <th>Usuário</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movimentos as $m):
                        $valor = (float)$m['valor'];
                    ?>
                    <tr>
                        <td class="ps-3 text-nowrap small text-muted"><?= datetime_br($m['criado_em']) ?></td>
                        <td>
                            <span class="badge bg-<?= $m['tipo'] === 'entrada' ? 'success' : 'danger' ?>">
                                <?= $m['tipo'] === 'entrada' ? 'Entrada' : 'Saída' ?>
                            </span>
                        </td>
                        <td class="small"><code><?= e($m['codigo']) ?></code> <?= e($m['item']) ?></td>
                        <td class="small text-muted"><?= $m['motivo'] ? e($motivoLabel[$m['motivo']] ?? $m['motivo']) : '—' ?></td>
                        <td class="small"><?= $m['setor'] ? e($m['setor']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="small"><?= e($m['filial']) ?></td>
                        <td class="text-end small fw-semibold <?= $m['tipo'] === 'entrada' ? 'text-success' : 'text-danger' ?>">
                            <?= $m['tipo'] === 'entrada' ? '+' : '-' ?><?= number_format((float)$m['quantidade'], 2, ',', '.') ?> <?= e($m['unidade']) ?>
                        </td>
                        <td class="text-end small"><?= money($valor) ?></td>
                        <td class="small"><?= e($m['usuario']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2 text-muted small border-top">
            <?= count($movimentos) ?> registro(s) exibido(s) (máx. 500)
        </div>
        <?php endif; ?>
    </div>
</div>
