<?php
// @versao Ver.001.281026
$badge = [
    'em_aprovacao' => 'warning',
    'aprovado'     => 'success',
    'rejeitado'    => 'danger',
    'cancelado'    => 'secondary',
];
$label = [
    'em_aprovacao' => 'Em aprovação',
    'aprovado'     => 'Entregue',
    'rejeitado'    => 'Rejeitado',
    'cancelado'    => 'Cancelado',
];
$numFormatado = 'BRN-' . date('Y', strtotime($req['criado_em'])) . '-'
    . str_pad((string)($req['numero'] ?? 0), 5, '0', STR_PAD_LEFT);
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= url('brindes/requisicoes') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="mb-0"><i class="bi bi-clock-history me-2"></i>Histórico da Saída de Brinde</h4>
    <span class="badge bg-light text-dark border ms-1"><?= e($numFormatado) ?></span>
    <span class="badge bg-<?= $badge[$req['status']] ?? 'secondary' ?>">
        <?= $label[$req['status']] ?? $req['status'] ?>
    </span>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle me-2"></i>Dados</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Cliente</dt>
                    <dd class="col-7 fw-semibold"><?= e($req['cliente_nome']) ?></dd>

                    <dt class="col-5 text-muted">Vendedor</dt>
                    <dd class="col-7"><?= e($req['vendedor_nome']) ?></dd>

                    <dt class="col-5 text-muted">Nº do Pedido</dt>
                    <dd class="col-7"><?= e($req['numero_pedido_carro'] ?: '—') ?></dd>

                    <dt class="col-5 text-muted">Filial</dt>
                    <dd class="col-7"><?= e($req['filial_nome']) ?></dd>

                    <dt class="col-5 text-muted">Data</dt>
                    <dd class="col-7"><?= date('d/m/Y H:i', strtotime($req['criado_em'])) ?></dd>

                    <dt class="col-5 text-muted">Criado por</dt>
                    <dd class="col-7"><?= e($req['criador_nome']) ?></dd>

                    <?php if (!empty($req['resolvido_em'])): ?>
                    <dt class="col-5 text-muted">Resolvido em</dt>
                    <dd class="col-7"><?= date('d/m/Y H:i', strtotime($req['resolvido_em'])) ?></dd>
                    <?php endif; ?>

                    <dt class="col-5 text-muted">Valor Total</dt>
                    <dd class="col-7 fw-semibold text-primary">R$ <?= number_format((float)$req['valor_total'], 2, ',', '.') ?></dd>
                </dl>
                <?php if (!empty($req['observacao'])): ?>
                <hr>
                <div class="small">
                    <div class="text-muted mb-1">Observação</div>
                    <div><?= nl2br(e($req['observacao'])) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($req['autorizacao_anexo'])): ?>
                <hr>
                <div class="small">
                    <div class="text-muted mb-1">Autorização do gerente</div>
                    <a href="<?= e(\App\Controllers\AnexoController::url($req['autorizacao_anexo'])) ?>" target="_blank">
                        <i class="bi bi-paperclip me-1"></i>Ver anexo
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-gift me-2"></i>Brindes Entregues</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Brinde</th>
                            <th class="text-center">Qtd</th>
                            <th class="text-end">Valor Unit.</th>
                            <th class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($itens as $it): ?>
                        <?php $vu = (float)($it['valor_referencia'] ?? 0); $q = (float)$it['quantidade']; ?>
                        <tr>
                            <td>
                                <?= e($it['brinde_nome']) ?>
                                <?php if (!empty($it['brinde_codigo'])): ?>
                                <div class="small text-muted"><?= e($it['brinde_codigo']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= number_format($q, 2, ',', '.') ?></td>
                            <td class="text-end">R$ <?= number_format($vu, 2, ',', '.') ?></td>
                            <td class="text-end">R$ <?= number_format($vu * $q, 2, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
