<?php
// @versao Ver.006.301026
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('compras/ordens') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi bi-receipt me-2 text-primary"></i>Ordem de Compra</h4>
    <span class="badge bg-secondary fs-6"><?= e($oc['numero_oc'] ?? substr($oc['id'], 0, 8)) ?></span>
    <a href="<?= url('compras/ordens/' . $oc['id'] . '/pdf') ?>"
       target="_blank"
       class="btn btn-outline-danger btn-sm ms-auto">
        <i class="bi bi-file-earmark-pdf me-1"></i>Gerar PDF
    </a>
</div>

<?php if (!empty($andamento)) { $andamentoCompacto = false; include ROOT . '/views/partials/andamento.php'; } ?>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex align-items-center gap-2 flex-wrap">
                <span class="fw-semibold">Dados da OC</span>
                <span class="fs-5 fw-bold font-monospace text-primary ms-1" id="ocNumeroDestaque"><?= e($oc['numero_oc'] ?? substr($oc['id'], 0, 8)) ?></span>
                <button type="button" class="btn btn-link btn-sm p-0 text-muted" title="Copiar número da OC" aria-label="Copiar número da OC"
                        onclick="navigator.clipboard?.writeText(document.getElementById('ocNumeroDestaque').textContent.trim()).then(() => { this.innerHTML = '<i class=&quot;bi bi-check2&quot;></i>'; setTimeout(() => this.innerHTML = '<i class=&quot;bi bi-copy&quot;></i>', 1500); })"><i class="bi bi-copy"></i></button>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Fornecedor</dt><dd class="col-sm-8"><?= e($oc['fornecedor_nome']) ?></dd>
                    <dt class="col-sm-4">CNPJ</dt><dd class="col-sm-8"><?= e($oc['fornecedor_cnpj'] ?? '—') ?></dd>
                    <dt class="col-sm-4">Filial</dt><dd class="col-sm-8"><?= e($oc['filial_nome']) ?></dd>
                    <dt class="col-sm-4">Gerada por</dt><dd class="col-sm-8"><?= e($oc['solicitante_nome']) ?></dd>
                    <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= status_badge($oc['status']) ?></dd>
                    <dt class="col-sm-4">Criado em</dt><dd class="col-sm-8"><?= datetime_br($oc['criado_em']) ?></dd>
                    <dt class="col-sm-4">Entrega prevista</dt>
                    <dd class="col-sm-8"><?= !empty($oc['entrega_prevista']) ? date_br($oc['entrega_prevista']) : '<span class="text-muted">Não informada</span>' ?></dd>
                    <?php $rotuloPagamento = \App\Services\CondicaoPagamento::rotulo($oc['condicao_pagamento'] ?? null, $oc['parcelas_dias'] ?? null, $oc['prazo_pagamento_dias'] ?? null); ?>
                    <dt class="col-sm-4">Condição de pagamento</dt>
                    <dd class="col-sm-8">
                        <?= $rotuloPagamento !== null ? e($rotuloPagamento) : '<span class="text-muted">Não informada</span>' ?>
                        <?php if (str_contains((string)($oc['parcelas_dias'] ?? ''), '/') && $oc['prazo_pagamento_dias'] !== null): ?>
                        <div class="small text-muted">Prazo médio: <?= (int)$oc['prazo_pagamento_dias'] ?> dias</div>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-sm-4 fw-bold">Total</dt><dd class="col-sm-8 fw-bold text-success"><?= money($oc['valor_total'] ?? 0) ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <?php if (can(['comprador','admin']) && ($oc['status'] ?? '') !== 'cancelado'): ?>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Entrega e pagamento</div>
            <div class="card-body">
                <form method="POST" action="<?= url('compras/ordens/' . $oc['id'] . '/prazos') ?>" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <div class="col-sm-6">
                        <label class="form-label small fw-semibold" for="ocEntregaPrevista">Entrega prevista *</label>
                        <input type="date" name="entrega_prevista" id="ocEntregaPrevista" class="form-control form-control-sm" required
                               min="<?= e(substr((string)$oc['criado_em'], 0, 10)) ?>" value="<?= e($oc['entrega_prevista'] ?? '') ?>">
                    </div>
                    <div class="col-sm-6">
                        <?php $condicaoAtual = trim((string)($oc['condicao_pagamento'] ?? '')) ?: trim((string)($oc['parcelas_dias'] ?? '')) ?: (string)($oc['prazo_pagamento_dias'] ?? ''); ?>
                        <label class="form-label small fw-semibold" for="ocCondicaoPagamentoDet">Condição de pagamento *</label>
                        <input type="text" name="condicao_pagamento" id="ocCondicaoPagamentoDet" class="form-control form-control-sm" required
                               maxlength="<?= \App\Services\CondicaoPagamento::MAX_TEXTO ?>" placeholder="Ex.: 30/60/90 ou à vista" autocomplete="off"
                               value="<?= e($condicaoAtual) ?>">
                        <div class="form-text mt-1" id="ocCondicaoPagamentoDetDica"></div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-floppy me-1"></i>Salvar</button>
                    </div>
                </form>
                <?php include ROOT . '/views/partials/condicao_pagamento_js.php'; ?>
                <script>
                CondicaoPagamento.ligar(document.getElementById('ocCondicaoPagamentoDet'), document.getElementById('ocCondicaoPagamentoDetDica'));
                </script>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php // Marcações manuais (S5): 06 envio, 08 trânsito/CT-e, 09 recebimento físico. ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Envio, trânsito e recebimento</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="small text-muted text-uppercase mb-1">06 · Envio ao fornecedor</div>
                        <?php if (!empty($oc['enviada_em'])): ?>
                            <div><i class="bi bi-check-circle-fill text-success me-1"></i>Enviada em <?= datetime_br($oc['enviada_em']) ?></div>
                            <div class="small text-muted">por <?= e($oc['enviada_por_nome'] ?? '—') ?></div>
                        <?php elseif (!empty($podeMarcar)): ?>
                            <form method="POST" action="<?= url('compras/ordens/' . $oc['id'] . '/enviada') ?>"
                                  onsubmit="return confirm('Marcar esta OC como enviada ao fornecedor?')">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-send me-1"></i>Marcar como enviada ao fornecedor</button>
                            </form>
                        <?php else: ?>
                            <span class="text-muted">Não enviada</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted text-uppercase mb-1">08 · CT-e / trânsito</div>
                        <?php if (!empty($oc['transito_em'])): ?>
                            <div><i class="bi bi-check-circle-fill text-success me-1"></i>Em trânsito desde <?= datetime_br($oc['transito_em']) ?></div>
                            <div class="small text-muted">CT-e <?= e($oc['cte_numero'] ?: 'não informado') ?> · por <?= e($oc['transito_por_nome'] ?? '—') ?></div>
                        <?php elseif (!empty($podeMarcar)): ?>
                            <form method="POST" action="<?= url('compras/ordens/' . $oc['id'] . '/transito') ?>" class="d-flex flex-wrap gap-2 align-items-end">
                                <?= csrf_field() ?>
                                <div>
                                    <label class="form-label small mb-0" for="ocCte">Nº do CT-e (opcional)</label>
                                    <input type="text" name="cte_numero" id="ocCte" maxlength="60" class="form-control form-control-sm" style="max-width:12rem">
                                </div>
                                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-truck me-1"></i>Em trânsito</button>
                            </form>
                        <?php else: ?>
                            <span class="text-muted">Sem marcação</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted text-uppercase mb-1">09 · Recebimento físico</div>
                        <?php if (!empty($oc['recebido_fisico_em'])): ?>
                            <div><i class="bi bi-check-circle-fill text-success me-1"></i>Recebida em <?= date_br($oc['recebido_fisico_em']) ?></div>
                            <div class="small text-muted">por <?= e($oc['recebido_fisico_por']) ?> · registrado por <?= e($oc['recebimento_registrado_por_nome'] ?? '—') ?></div>
                        <?php elseif (!empty($podeMarcar)): ?>
                            <form method="POST" action="<?= url('compras/ordens/' . $oc['id'] . '/recebimento') ?>" class="row g-2 align-items-end">
                                <?= csrf_field() ?>
                                <div class="col-sm-5">
                                    <label class="form-label small mb-0" for="ocRecebidoEm">Data *</label>
                                    <input type="date" name="recebido_fisico_em" id="ocRecebidoEm" class="form-control form-control-sm" required
                                           min="<?= e(substr((string)$oc['criado_em'], 0, 10)) ?>" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-sm-7">
                                    <label class="form-label small mb-0" for="ocRecebidoPor">Quem recebeu *</label>
                                    <input type="text" name="recebido_fisico_por" id="ocRecebidoPor" class="form-control form-control-sm" required maxlength="150">
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-box-seam me-1"></i>Mercadoria recebida</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <span class="text-muted">Não recebida</span>
                        <?php endif; ?>
                    </div>
                </div>
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
