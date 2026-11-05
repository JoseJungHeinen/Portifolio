<?php
$statusLabel = [
    'em_aprovacao' => ['Em aprovação', 'warning'],
    'aprovado'     => ['Aprovado', 'success'],
    'rejeitado'    => ['Rejeitado', 'danger'],
    'cancelado'    => ['Cancelado', 'secondary'],
];
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-gift-fill me-2 text-danger"></i>Relatório de Brindes</h4>
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
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filtrar</button>
            </div>
        </form>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:.7rem">Saldo em Estoque (Qtd.)</div>
                <div class="fs-5 fw-bold text-primary"><?= number_format((float)$saldoAtual['qtd'], 2, ',', '.') ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:.7rem">Saldo em Estoque (Valor)</div>
                <div class="fs-5 fw-bold text-primary"><?= money($saldoAtual['valor']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:.7rem">Saídas no Período (Qtd.)</div>
                <div class="fs-5 fw-bold text-danger"><?= number_format((float)$saidasPeriodo['qtd'], 2, ',', '.') ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:.7rem">Saídas no Período (Valor)</div>
                <div class="fs-5 fw-bold text-danger"><?= money($saidasPeriodo['valor']) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Requisições por status -->
<div class="row g-3 mb-4">
    <?php foreach ($statusLabel as $key => [$label, $cor]): ?>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:.7rem">
                    <span class="badge bg-<?= $cor ?>"><?= $label ?></span>
                </div>
                <div class="fs-5 fw-bold"><?= (int)$requisicoesPorStatus[$key]['total'] ?></div>
                <div class="small text-muted"><?= money($requisicoesPorStatus[$key]['valor']) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <!-- Saldo atual por filial -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h6 class="fw-bold mb-3"><i class="bi bi-shop me-2 text-primary"></i>Saldo Atual por Filial</h6>
                <?php if (empty($saldoPorFilial)): ?>
                <p class="text-muted small mb-0">Nenhuma filial ativa encontrada.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Filial</th><th class="text-end">Qtd.</th><th class="text-end">Valor</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($saldoPorFilial as $f): ?>
                            <tr>
                                <td class="small"><?= e($f['nome']) ?></td>
                                <td class="text-end small"><?= number_format((float)$f['qtd'], 2, ',', '.') ?></td>
                                <td class="text-end small fw-semibold"><?= money($f['valor']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Consumo por filial no período -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h6 class="fw-bold mb-3"><i class="bi bi-box-arrow-up me-2 text-danger"></i>Saídas por Filial no Período</h6>
                <?php if (empty($consumoPorFilial)): ?>
                <p class="text-muted small mb-0">Nenhuma saída registrada no período.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Filial</th><th class="text-end">Qtd.</th><th class="text-end">Valor</th><th class="text-end">%</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($consumoPorFilial as $f):
                                $pct = $totalConsumoFilial > 0 ? ($f['valor'] / $totalConsumoFilial * 100) : 0;
                            ?>
                            <tr>
                                <td class="small"><?= e($f['nome']) ?></td>
                                <td class="text-end small"><?= number_format((float)$f['qtd'], 2, ',', '.') ?></td>
                                <td class="text-end small fw-semibold"><?= money($f['valor']) ?></td>
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
</div>

<!-- Ranking de brindes mais solicitados -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-trophy me-2 text-warning"></i>Brindes Mais Solicitados no Período</h6>
        <?php if (empty($rankingBrindes)): ?>
        <p class="text-muted small mb-0">Nenhuma saída registrada no período.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Código</th><th>Brinde</th><th class="text-end">Quantidade</th><th class="text-end">Valor</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rankingBrindes as $b): ?>
                    <tr>
                        <td class="small"><code><?= e($b['codigo']) ?></code></td>
                        <td class="small"><?= e($b['nome']) ?></td>
                        <td class="text-end small"><?= number_format((float)$b['qtd'], 2, ',', '.') ?></td>
                        <td class="text-end small fw-semibold"><?= money($b['valor']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Lista detalhada de requisições -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="px-3 pt-3">
            <h6 class="fw-bold mb-0"><i class="bi bi-list-ul me-2 text-secondary"></i>Requisições de Brinde no Período</h6>
        </div>
        <?php if (empty($requisicoes)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-2 d-block mb-2"></i>Nenhuma requisição encontrada no período selecionado.
        </div>
        <?php else: ?>
        <div class="table-responsive mt-2">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Data</th>
                        <th>Cliente</th>
                        <th>Filial</th>
                        <th>Status</th>
                        <th class="text-end">Itens</th>
                        <th class="text-end">Valor</th>
                        <th>Solicitante</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requisicoes as $r):
                        [$label, $cor] = $statusLabel[$r['status']] ?? [$r['status'], 'secondary'];
                    ?>
                    <tr>
                        <td class="ps-3 text-nowrap small text-muted"><?= datetime_br($r['criado_em']) ?></td>
                        <td class="small"><?= e($r['cliente_nome']) ?></td>
                        <td class="small"><?= e($r['filial_nome']) ?></td>
                        <td><span class="badge bg-<?= $cor ?>"><?= e($label) ?></span></td>
                        <td class="text-end small"><?= (int)$r['total_itens'] ?></td>
                        <td class="text-end small fw-semibold"><?= money($r['valor_total']) ?></td>
                        <td class="small"><?= e($r['criador_nome']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
