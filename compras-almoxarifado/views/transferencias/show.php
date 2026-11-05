<?php
$tNumFormatado = 'TRAN-' . date('Y', strtotime($transferencia['criado_em'])) . '-' . str_pad((string)$transferencia['numero'], 5, '0', STR_PAD_LEFT);
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('transferencias/recebimento') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi bi-clipboard-check me-2 text-primary"></i>Confirmar Recebimento</h4>
    <span class="badge bg-light text-dark border"><?= e($tNumFormatado) ?></span>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        Origem: <?= e($transferencia['filial_origem_nome']) ?>
        <?php if (!empty($transferencia['observacao'])): ?>
            <div class="small text-muted mt-1"><?= e($transferencia['observacao']) ?></div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="POST" action="<?= url('transferencias/' . $transferencia['id'] . '/confirmar') ?>">
            <?= csrf_field() ?>
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="text-end">Qtd. Enviada</th>
                        <th class="text-end" style="width:160px">Qtd. Recebida *</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($itens as $item): ?>
                    <tr>
                        <td><?= e($item['item_nome']) ?> <span class="text-muted small">(<?= e($item['unidade']) ?>)</span></td>
                        <td class="text-end"><?= number_format((float)$item['quantidade'], 2, ',', '.') ?></td>
                        <td class="text-end">
                            <input type="text" class="form-control form-control-sm text-end"
                                   name="quantidade_recebida[<?= e($item['id']) ?>]"
                                   value="<?= number_format((float)$item['quantidade'], 2, ',', '.') ?>" required>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="text-end">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-lg me-1"></i>Confirmar Recebimento
                </button>
            </div>
        </form>
    </div>
</div>
