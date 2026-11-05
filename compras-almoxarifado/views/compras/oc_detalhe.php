<?php
// @versao Ver.002.281026
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('compras/ordens') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi bi-receipt me-2 text-primary"></i>Ordem de Compra</h4>
    <span class="badge bg-secondary"><?= e($oc['numero_oc'] ?? substr($oc['id'], 0, 8)) ?></span>
    <a href="<?= url('compras/ordens/' . $oc['id'] . '/pdf') ?>"
       target="_blank"
       class="btn btn-outline-danger btn-sm ms-auto">
        <i class="bi bi-file-earmark-pdf me-1"></i>Gerar PDF
    </a>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Dados da OC</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Fornecedor</dt><dd class="col-sm-8"><?= e($oc['fornecedor_nome']) ?></dd>
                    <dt class="col-sm-4">CNPJ</dt><dd class="col-sm-8"><?= e($oc['fornecedor_cnpj'] ?? '—') ?></dd>
                    <dt class="col-sm-4">Filial</dt><dd class="col-sm-8"><?= e($oc['filial_nome']) ?></dd>
                    <dt class="col-sm-4">Gerada por</dt><dd class="col-sm-8"><?= e($oc['solicitante_nome']) ?></dd>
                    <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= status_badge($oc['status']) ?></dd>
                    <dt class="col-sm-4">Criado em</dt><dd class="col-sm-8"><?= datetime_br($oc['criado_em']) ?></dd>
                    <dt class="col-sm-4 fw-bold">Total</dt><dd class="col-sm-8 fw-bold text-success"><?= money($oc['valor_total'] ?? 0) ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Itens</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Item</th>
                            <th class="text-center">Qtd</th>
                            <th class="text-center">Unidade</th>
                            <th class="text-end">Valor Unit.</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itens as $it): ?>
                        <tr>
                            <td><?= e($it['item_nome'] ?? $it['descricao'] ?? '—') ?></td>
                            <td class="text-center"><?= number_format($it['quantidade'], 2, ',', '.') ?></td>
                            <td class="text-center"><?= e($it['unidade']) ?></td>
                            <td class="text-end"><?= money($it['valor_unitario'] ?? 0) ?></td>
                            <td class="text-end fw-semibold"><?= money(($it['quantidade'] ?? 0) * ($it['valor_unitario'] ?? 0)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="4" class="text-end fw-bold">Total da OC</td>
                            <td class="text-end fw-bold text-success"><?= money($oc['valor_total'] ?? 0) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
