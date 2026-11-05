<?php
$totalDiv  = count($divergencias);
$totalSem  = count($semContagem);
$totalConf = count($conformes);
$totalItens= count($itens);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="<?= url('auditoria-estoque') ?>" class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i>Auditorias
        </a>
        <h4 class="mb-0 mt-1">
            <i class="bi bi-file-earmark-bar-graph me-2"></i>
            Relatório de Auditoria — <?= e($aud['filial_nome']) ?>
        </h4>
        <div class="small text-muted">
            <?= date('d/m/Y', strtotime($aud['criado_em'])) ?>
            <?php if ($aud['finalizado_em']): ?>
            · Finalizada em <?= date('d/m/Y H:i', strtotime($aud['finalizado_em'])) ?>
            <?php endif; ?>
            · por <?= e($aud['criador_nome']) ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Imprimir
        </button>
        <a href="<?= url('auditoria-estoque/' . $aud['id'] . '/imprimir') ?>" class="btn btn-outline-secondary btn-sm" target="_blank">
            <i class="bi bi-file-earmark-text me-1"></i>Folha de campo
        </a>
    </div>
</div>


<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center border-0 shadow-sm">
            <div class="card-body">
                <div class="fs-2 fw-bold text-primary"><?= $totalItens ?></div>
                <div class="small text-muted">Total de itens</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-0 shadow-sm">
            <div class="card-body">
                <div class="fs-2 fw-bold text-success"><?= $totalConf ?></div>
                <div class="small text-muted">Conformes</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-0 shadow-sm">
            <div class="card-body">
                <div class="fs-2 fw-bold text-danger"><?= $totalDiv ?></div>
                <div class="small text-muted">Com divergência</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-0 shadow-sm">
            <div class="card-body">
                <div class="fs-2 fw-bold text-secondary"><?= $totalSem ?></div>
                <div class="small text-muted">Sem contagem</div>
            </div>
        </div>
    </div>
</div>

<?php if ($aud['observacao']): ?>
<div class="alert alert-light border mb-3">
    <strong>Observação:</strong> <?= e($aud['observacao']) ?>
</div>
<?php endif; ?>

<!-- Divergências -->
<?php if ($totalDiv > 0): ?>
<form method="POST" action="<?= url('auditoria-estoque/' . $aud['id'] . '/aplicar-ajustes') ?>"
      onsubmit="return confirm('Aplicar <?= count($pendentes) ?> ajuste(s) ao estoque agora? Essa ação atualiza o saldo real e não pode ser desfeita por aqui.');">
    <?= csrf_field() ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-danger text-white fw-semibold">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Itens com divergência (<?= $totalDiv ?>)
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Item</th>
                    <th>Grupo</th>
                    <th class="text-center">Unid.</th>
                    <th class="text-end">Qtd. Sistema</th>
                    <th class="text-end" style="width:160px">Qtd. Física</th>
                    <th class="text-end">Diferença</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($divergencias as $it): ?>
            <?php $diff = (float)$it['diferenca']; ?>
            <tr class="<?= $diff < 0 ? 'table-danger' : 'table-warning' ?>">
                <td>
                    <div class="fw-semibold"><?= e($it['item_nome']) ?></div>
                    <?php if ($it['codigo']): ?>
                    <div class="small text-muted"><?= e($it['codigo']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="small text-muted"><?= e($it['grupo_nome'] ?? '—') ?></td>
                <td class="text-center small"><?= e($it['unidade']) ?></td>
                <td class="text-end"><?= number_format((float)$it['qtd_sistema'], 2, ',', '.') ?></td>
                <td class="text-end">
                    <?php if ($it['ajustado_em']): ?>
                        <?= number_format((float)$it['qtd_fisica'], 2, ',', '.') ?>
                        <div class="small text-success">
                            <i class="bi bi-check-circle me-1"></i>Ajustado em <?= date('d/m/Y H:i', strtotime($it['ajustado_em'])) ?>
                            <?php if ($it['ajustado_por_nome']): ?> por <?= e($it['ajustado_por_nome']) ?><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <input type="number" name="qtd_fisica[<?= e($it['id']) ?>]"
                               value="<?= rtrim(rtrim(number_format((float)$it['qtd_fisica'], 2, '.', ''), '0'), '.') ?>"
                               class="form-control form-control-sm text-end" style="max-width:130px;margin-left:auto;"
                               step="0.01" min="0">
                    <?php endif; ?>
                </td>
                <td class="text-end fw-bold <?= $diff < 0 ? 'text-danger' : 'text-warning' ?>">
                    <?= ($diff > 0 ? '+' : '') . number_format($diff, 2, ',', '.') ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($pendentes) > 0): ?>
    <div class="card-footer bg-white text-end">
        <button type="submit" class="btn btn-danger">
            <i class="bi bi-check2-circle me-1"></i>
            Aplicar ajustes ao estoque (<?= count($pendentes) ?> pendente<?= count($pendentes) === 1 ? '' : 's' ?>)
        </button>
    </div>
    <?php endif; ?>
</div>
</form>
<?php endif; ?>

<!-- Sem contagem -->
<?php if ($totalSem > 0): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-secondary text-white fw-semibold">
        <i class="bi bi-dash-circle me-1"></i>
        Sem contagem registrada (<?= $totalSem ?>)
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Item</th>
                    <th>Grupo</th>
                    <th class="text-center">Unid.</th>
                    <th class="text-end">Qtd. Sistema</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($semContagem as $it): ?>
            <tr>
                <td><?= e($it['item_nome']) ?></td>
                <td class="small text-muted"><?= e($it['grupo_nome'] ?? '—') ?></td>
                <td class="text-center small"><?= e($it['unidade']) ?></td>
                <td class="text-end"><?= number_format((float)$it['qtd_sistema'], 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Conformes (collapsible) -->
<?php if ($totalConf > 0): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center"
         role="button" data-bs-toggle="collapse" data-bs-target="#collapseConformes">
        <span><i class="bi bi-check-circle me-1"></i>Itens conformes (<?= $totalConf ?>)</span>
        <i class="bi bi-chevron-down"></i>
    </div>
    <div class="collapse" id="collapseConformes">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Item</th>
                        <th>Grupo</th>
                        <th class="text-center">Unid.</th>
                        <th class="text-end">Qtd. Sistema</th>
                        <th class="text-end">Qtd. Física</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($conformes as $it): ?>
                <tr class="table-success">
                    <td><?= e($it['item_nome']) ?></td>
                    <td class="small text-muted"><?= e($it['grupo_nome'] ?? '—') ?></td>
                    <td class="text-center small"><?= e($it['unidade']) ?></td>
                    <td class="text-end"><?= number_format((float)$it['qtd_sistema'], 2, ',', '.') ?></td>
                    <td class="text-end"><?= number_format((float)$it['qtd_fisica'], 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="small text-muted text-end">
    Relatório gerado em <?= date('d/m/Y H:i') ?>
</div>

<style>
@media print {
    .btn, .d-flex.justify-content-between.align-items-center > div:last-child { display: none !important; }
    .collapse { display: block !important; }
    a { color: inherit !important; text-decoration: none !important; }
    .small.text-muted { color: #555 !important; }
    .card { break-inside: avoid; }
}
</style>
