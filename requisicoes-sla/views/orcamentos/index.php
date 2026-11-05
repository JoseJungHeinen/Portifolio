<?php $title = 'Orçamentos'; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-pie-chart me-2 text-primary"></i>Orçamentos</h4>
    <?php if (can(['diretor','admin'])): ?>
        <a href="<?= url('orcamentos/novo') ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Novo Orçamento
        </a>
    <?php endif; ?>
</div>

<!-- Filtro de período -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-auto">
                <select name="mes" class="form-select form-select-sm">
                    <?php $meses = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez']; ?>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $mes == $m ? 'selected' : '' ?>><?= $meses[$m-1] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="ano" class="form-select form-select-sm">
                    <?php for ($a = date('Y')-1; $a <= date('Y')+1; $a++): ?>
                        <option value="<?= $a ?>" <?= $ano == $a ? 'selected' : '' ?>><?= $a ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <?php if (can(['diretor','admin'])): ?>
            <div class="col-auto">
                <select name="filial_id" class="form-select form-select-sm">
                    <option value="">Todas as Filiais</option>
                    <?php foreach ($filiais as $f): ?>
                        <option value="<?= $f['id'] ?>" <?= $filialFiltro === $f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-auto">
                <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-search me-1"></i>Filtrar</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Setor</th>
                        <th>Tipo</th>
                        <th class="text-end">Orçado</th>
                        <th class="text-end">Consumido</th>
                        <th class="text-end">Saldo</th>
                        <th style="min-width:140px">Consumo</th>
                        <th>Status</th>
                        <?php if (can(['diretor','admin'])): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($porSetor as $s): ?>
                    <?php
                    $pct   = (float)($s['pct'] ?? 0);
                    $saldo = (float)($s['orcado'] ?? 0) - (float)($s['realizado'] ?? 0);
                    $statusOrc = $pct >= 100 ? 'esgotado' : ($pct >= 80 ? 'alerta' : 'ativo');
                    ?>
                    <tr>
                        <td class="px-4 fw-semibold"><?= e($s['setor']) ?></td>
                        <td><span class="badge bg-<?= $s['tipo']==='operacional' ? 'primary' : 'secondary' ?>"><?= ucfirst($s['tipo']) ?></span></td>
                        <td class="text-end"><?= money($s['orcado']) ?></td>
                        <td class="text-end fw-semibold <?= $pct >= 90 ? 'text-danger' : '' ?>"><?= money($s['realizado']) ?></td>
                        <td class="text-end <?= $saldo < 0 ? 'text-danger fw-bold' : 'text-success' ?>"><?= money($saldo) ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height:8px">
                                    <div class="progress-bar <?= percent_bar_class($pct) ?>" style="width:<?= min(100,$pct) ?>%"></div>
                                </div>
                                <small class="text-nowrap"><?= $pct ?>%</small>
                            </div>
                        </td>
                        <td>
                            <?php if ($pct >= 100): ?>
                                <span class="badge bg-danger">Esgotado</span>
                            <?php elseif ($pct >= 80): ?>
                                <span class="badge bg-warning text-dark">Alerta</span>
                            <?php else: ?>
                                <span class="badge bg-success">Normal</span>
                            <?php endif; ?>
                        </td>
                        <?php if (can(['diretor','admin'])): ?>
                        <td>
                            <a href="<?= url('orcamentos/solicitacoes') . '?setor=' . urlencode($s['setor_id'] ?? '') ?>" class="btn btn-outline-secondary btn-sm" title="Solicitações">
                                <i class="bi bi-list-check"></i>
                            </a>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($porSetor)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                        Nenhum orçamento para este período.
                        <?php if (can(['diretor','admin'])): ?><br><a href="<?= url('orcamentos/novo') ?>">Cadastrar orçamentos</a><?php endif; ?>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
