<?php $title = 'Comparativo de Estoque — NF-e ' . e($nf['numero_nf']); ?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('fiscal/' . $nf['id']) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-boxes me-2 text-info"></i>Comparativo de Estoque</h4>
        <small class="text-muted">
            NF-e nº <?= e($nf['numero_nf']) ?> — <?= e($nf['fornecedor_nome']) ?> |
            Filial: <strong><?= e($nf['filial_nome']) ?></strong>
        </small>
    </div>
</div>

<?php
$semMatch   = array_filter($comparativo, fn($r) => $r['cadastrado'] === null);
$comMatch   = array_filter($comparativo, fn($r) => $r['cadastrado'] !== null);
$abaixoMin  = array_filter($comparativo, fn($r) =>
    $r['cadastrado'] !== null &&
    (float)$r['cadastrado']['estoque_minimo'] > 0 &&
    $r['saldo_apos'] < (float)$r['cadastrado']['estoque_minimo']
);
?>

<!-- Resumo -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold"><?= count($comparativo) ?></div>
                <div class="text-muted small">Itens no XML</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center border-start border-success border-3">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold text-success"><?= count($comMatch) ?></div>
                <div class="text-muted small">Encontrados no sistema</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center border-start border-warning border-3">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold text-warning"><?= count($semMatch) ?></div>
                <div class="text-muted small">Não encontrados</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center border-start border-danger border-3">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold text-danger"><?= count($abaixoMin) ?></div>
                <div class="text-muted small">Abaixo do mínimo após recebimento</div>
            </div>
        </div>
    </div>
</div>

<!-- Tabela comparativa -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-table text-info"></i> Itens da NF-e vs. Estoque
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Cód. NF</th>
                        <th>Descrição NF-e</th>
                        <th class="text-end">Qtd. NF</th>
                        <th class="text-end">Vlr. Unit.</th>
                        <th class="text-center">Item no Sistema</th>
                        <th class="text-end">Saldo Atual</th>
                        <th class="text-end">Saldo Após</th>
                        <th class="text-end">Estoque Mín.</th>
                        <th class="text-center">Situação</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($comparativo as $row):
                    $nfItem   = $row['nf'];
                    $cad      = $row['cadastrado'];
                    $saldoApos = $row['saldo_apos'];

                    if ($cad === null) {
                        $situacao  = '<span class="badge bg-warning text-dark">Não cadastrado</span>';
                    } elseif ((float)$cad['estoque_minimo'] > 0 && $saldoApos < (float)$cad['estoque_minimo']) {
                        $situacao  = '<span class="badge bg-danger">Abaixo do mínimo</span>';
                    } else {
                        $situacao  = '<span class="badge bg-success">OK</span>';
                    }
                ?>
                <tr>
                    <td class="text-muted"><?= e($nfItem['codigo']) ?></td>
                    <td><?= e($nfItem['descricao']) ?>
                        <div class="text-muted" style="font-size:.75em">NCM: <?= e($nfItem['ncm']) ?> | Un: <?= e($nfItem['unidade']) ?></div>
                    </td>
                    <td class="text-end fw-semibold"><?= number_format($nfItem['quantidade'], 3, ',', '.') ?></td>
                    <td class="text-end"><?= money($nfItem['valor_unit']) ?></td>
                    <td class="text-center">
                        <?php if ($cad): ?>
                            <span class="fw-semibold"><?= e($cad['nome']) ?></span>
                            <?php if ($cad['codigo']): ?>
                            <div class="text-muted" style="font-size:.75em"><?= e($cad['codigo']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted fst-italic">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?= $cad ? number_format((float)$cad['saldo_filial'], 3, ',', '.') : '—' ?>
                    </td>
                    <td class="text-end <?= ($saldoApos !== null && $cad && (float)$cad['estoque_minimo'] > 0 && $saldoApos < (float)$cad['estoque_minimo']) ? 'text-danger fw-bold' : '' ?>">
                        <?= $saldoApos !== null ? number_format($saldoApos, 3, ',', '.') : '—' ?>
                    </td>
                    <td class="text-end text-muted">
                        <?= $cad ? number_format((float)$cad['estoque_minimo'], 3, ',', '.') : '—' ?>
                    </td>
                    <td class="text-center"><?= $situacao ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (count($semMatch) > 0): ?>
<div class="alert alert-warning mt-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong><?= count($semMatch) ?> ite<?= count($semMatch) > 1 ? 'ns' : 'm' ?></strong> da NF-e não foram encontrados no cadastro de itens do sistema.
    Verifique se os códigos ou nomes coincidem com o cadastro, ou cadastre os itens antes de dar entrada no estoque.
</div>
<?php endif; ?>
