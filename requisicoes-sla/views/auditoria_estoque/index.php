<?php
$badgeStatus = [
    'aberta'           => 'secondary',
    'em_conferencia'   => 'warning',
    'finalizada'       => 'success',
];
$labelStatus = [
    'aberta'           => 'Aberta',
    'em_conferencia'   => 'Em Conferência',
    'finalizada'       => 'Finalizada',
];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-clipboard2-check me-2"></i>Auditoria de Estoque</h4>
    <a href="<?= url('auditoria-estoque/nova') ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Nova Auditoria
    </a>
</div>


<?php if (empty($auditorias)): ?>
<div class="card text-center py-5">
    <div class="card-body text-muted">
        <i class="bi bi-clipboard2 fs-1 d-block mb-2"></i>
        Nenhuma auditoria realizada ainda.
        <div class="mt-3">
            <a href="<?= url('auditoria-estoque/nova') ?>" class="btn btn-outline-primary">Iniciar primeira auditoria</a>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Data</th>
                    <th>Filial</th>
                    <th>Escopo</th>
                    <th>Itens</th>
                    <th>Status</th>
                    <th>Criado por</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($auditorias as $a): ?>
                <?php
                $conferidos = (int)$a['itens_conferidos'];
                $total      = (int)$a['total_itens'];
                $pct        = $total > 0 ? round($conferidos / $total * 100) : 0;
                ?>
                <tr>
                    <td class="text-nowrap">
                        <?= date('d/m/Y', strtotime($a['criado_em'])) ?>
                        <div class="small text-muted"><?= date('H:i', strtotime($a['criado_em'])) ?></div>
                    </td>
                    <td><?= e($a['filial_nome']) ?></td>
                    <td>
                        <?php if ($a['escopo'] === 'todos'): ?>
                            <span class="badge bg-light text-dark border">Todos os itens</span>
                        <?php else: ?>
                            <span class="badge bg-light text-dark border"><?= ucfirst($a['escopo']) ?>:</span>
                            <span class="small"><?= e($a['escopo_nome'] ?? '—') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="small"><?= $conferidos ?>/<?= $total ?> conferidos</div>
                        <div class="progress mt-1" style="height:4px;width:80px">
                            <div class="progress-bar bg-<?= $pct === 100 ? 'success' : 'primary' ?>"
                                 style="width:<?= $pct ?>%"></div>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-<?= $badgeStatus[$a['status']] ?>">
                            <?= $labelStatus[$a['status']] ?>
                        </span>
                        <?php if ($a['finalizado_em']): ?>
                            <div class="small text-muted"><?= date('d/m/Y', strtotime($a['finalizado_em'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= e($a['criador_nome']) ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($a['status'] !== 'finalizada'): ?>
                        <a href="<?= url('auditoria-estoque/' . $a['id']) ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil-square me-1"></i>Conferir
                        </a>
                        <?php else: ?>
                        <a href="<?= url('auditoria-estoque/' . $a['id'] . '/relatorio') ?>" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-file-earmark-bar-graph me-1"></i>Relatório
                        </a>
                        <?php endif; ?>
                        <a href="<?= url('auditoria-estoque/' . $a['id'] . '/imprimir') ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Imprimir folha">
                            <i class="bi bi-printer"></i>
                        </a>
                        <?php if (can(['diretor','admin'])): ?>
                        <form method="POST" action="<?= url('auditoria-estoque/' . $a['id'] . '/excluir') ?>"
                              class="d-inline"
                              onsubmit="return confirm('Excluir esta auditoria e todas as suas fotos? Esta ação não pode ser desfeita.');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir auditoria">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
