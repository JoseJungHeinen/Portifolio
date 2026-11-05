<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-receipt me-2 text-primary"></i>Ordens de Compra</h4>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Fornecedor</th>
                        <th>Filial</th>
                        <th>Solicitante</th>
                        <th class="text-end">Valor Total</th>
                        <th>Status</th>
                        <th>Criado em</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ordens)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma ordem de compra encontrada.</td></tr>
                    <?php else: ?>
                    <?php foreach ($ordens as $oc): ?>
                    <tr>
                        <td><code><?= substr($oc['id'], 0, 8) ?></code></td>
                        <td><?= e($oc['fornecedor_nome']) ?></td>
                        <td><?= e($oc['filial_nome']) ?></td>
                        <td><?= e($oc['solicitante_nome']) ?></td>
                        <td class="text-end fw-semibold"><?= money($oc['valor_total'] ?? 0) ?></td>
                        <td><?= status_badge($oc['status']) ?></td>
                        <td><?= datetime_br($oc['criado_em']) ?></td>
                        <td>
                            <a href="<?= url('compras/ordens/' . $oc['id']) ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
