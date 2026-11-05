<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-truck me-2 text-primary"></i>Confirmação de Recebimento</h4>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Transferências pendentes de confirmação</div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th class="px-3">Nº</th>
                    <th>Origem</th>
                    <th>Itens</th>
                    <th>Data</th>
                    <th class="pe-3 text-end">Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transferencias)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Nenhuma transferência pendente de confirmação.</td></tr>
                <?php else: ?>
                <?php foreach ($transferencias as $t): ?>
                <?php $tNumFormatado = 'TRAN-' . date('Y', strtotime($t['criado_em'])) . '-' . str_pad((string)$t['numero'], 5, '0', STR_PAD_LEFT); ?>
                <tr>
                    <td class="px-3"><span class="badge bg-light text-dark border"><?= e($tNumFormatado) ?></span></td>
                    <td><?= e($t['filial_origem_nome']) ?></td>
                    <td><?= (int)$t['total_itens'] ?> item(ns)</td>
                    <td class="small text-muted"><?= date_br($t['criado_em']) ?></td>
                    <td class="pe-3 text-end">
                        <a href="<?= url('transferencias/' . $t['id']) ?>" class="btn btn-primary btn-sm">
                            <i class="bi bi-clipboard-check me-1"></i>Conferir
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
