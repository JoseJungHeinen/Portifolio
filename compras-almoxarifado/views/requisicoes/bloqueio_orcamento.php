<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('requisicoes') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0 text-warning"><i class="bi bi-exclamation-triangle-fill me-2"></i>Saldo Orçamentário Insuficiente</h4>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7">

        <!-- Debug temporário — visível apenas em APP_DEBUG=true -->
        <?php if (($_ENV['APP_DEBUG'] ?? 'false') === 'true'): ?>
        <div class="alert alert-secondary small mb-3">
            <strong>Debug orçamento:</strong><br>
            filial_id: <code><?= e($req['filial_id']) ?></code><br>
            setor_id: <code><?= e($req['setor_id']) ?></code><br>
            grupos: <code><?= e(implode(', ', array_column($check['grupos'] ?? [], 'grupo_nome'))) ?></code><br>
            valor_total: <code><?= money($req['valor_total'] ?? 0) ?></code><br>
            mês/ano: <code><?= date('n/Y') ?></code>
        </div>
        <?php endif; ?>

        <!-- Resumo da requisição -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Requisição #<?= substr($req['id'], 0, 8) ?></div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6">
                        <small class="text-muted d-block">Valor solicitado</small>
                        <span class="fw-bold fs-5 text-danger"><?= money($req['valor_total'] ?? 0) ?></span>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block">Tipo de despesa</small>
                        <span><?= ucfirst($req['tipo_despesa'] ?? '—') ?></span>
                    </div>
                    <?php if (!empty($req['justificativa'])): ?>
                    <div class="col-12">
                        <small class="text-muted d-block">Justificativa</small>
                        <span class="small"><?= e($req['justificativa']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Verificação Orçamentária — Setor -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Verificação Orçamentária</div>
            <div class="card-body">
                <?php $camada = $check['camada1'] ?? []; ?>
                <div class="d-flex justify-content-between align-items-center p-2 rounded <?= !($camada['ok'] ?? true) ? 'bg-danger bg-opacity-10 border border-danger-subtle' : 'bg-success bg-opacity-10 border border-success-subtle' ?>">
                    <div>
                        <div class="fw-semibold"><?= e($camada['label'] ?? 'Setor') ?></div>
                        <div class="text-muted small">Saldo disponível</div>
                    </div>
                    <div class="text-end">
                        <div class="fw-bold fs-5 <?= !($camada['ok'] ?? true) ? 'text-danger' : 'text-success' ?>">
                            <?= money($camada['saldo'] ?? 0) ?>
                        </div>
                        <?php if ($camada['ok'] ?? true): ?>
                            <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>OK</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><i class="bi bi-x-lg me-1"></i>Insuficiente</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($check['grupos'])): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Valores por Grupo</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr><th>Grupo</th><th class="text-end">Valor</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($check['grupos'] as $g): ?>
                        <tr>
                            <td><?= e($g['grupo_nome'] ?? 'Grupo') ?></td>
                            <td class="text-end"><?= money($g['valor_total'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Ações -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">O que deseja fazer?</div>
            <div class="card-body">
                <div class="d-grid gap-2">

                    <!-- Solicitar aumento à Direção — sempre disponível -->
                    <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#modalSolicitar">
                        <i class="bi bi-send me-2"></i>Solicitar Orçamento à Direção
                    </button>
                    <?php if (!($check['orcamento_id'] ?? null)): ?>
                    <div class="alert alert-info small py-2 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Não há orçamento cadastrado para este setor/grupo. A solicitação criará um pedido para a Direção liberar o orçamento.
                    </div>
                    <?php endif; ?>

                    <!-- Cancelar / Voltar -->
                    <a href="<?= url('requisicoes/' . $req['id'] . '/editar') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-pencil me-2"></i>Editar a Requisição
                    </a>
                    <form method="POST" action="<?= url('requisicoes/' . $req['id'] . '/cancelar') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline-danger w-100"
                                onclick="return confirm('Cancelar esta requisição?')">
                            <i class="bi bi-trash me-2"></i>Cancelar Requisição
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Modal: Solicitar Orçamento/Aumento -->
<div class="modal fade" id="modalSolicitar" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="<?= url('orcamentos/solicitacoes') ?>" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-send me-2 text-warning"></i>Solicitar Orçamento à Direção</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Campos de contexto para o service criar orçamento se necessário -->
                <input type="hidden" name="orcamento_id"     value="<?= e($check['orcamento_id'] ?? '') ?>">
                <input type="hidden" name="requisicao_id"    value="<?= e($req['id']) ?>">
                <input type="hidden" name="grupo_produto_id" value="<?= e($check['grupos'][0]['grupo_produto_id'] ?? $req['grupo_produto_id'] ?? '') ?>">
                <input type="hidden" name="valor_necessario" value="<?= e($req['valor_total'] ?? 0) ?>">

                <div class="alert alert-warning small">
                    <?php if ($check['orcamento_id'] ?? null): ?>
                        <strong>Saldo atual (Setor/Grupo):</strong> <?= money($check['camada1']['saldo'] ?? 0) ?><br>
                    <?php else: ?>
                        <i class="bi bi-info-circle me-1"></i> Nenhum orçamento registrado para este setor/grupo. A solicitação pedirá à Direção que libere o orçamento necessário.<br>
                    <?php endif; ?>
                    <strong>Valor necessário:</strong> <?= money($req['valor_total'] ?? 0) ?>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Valor a Solicitar (R$) *</label>
                    <input type="number" name="valor_solicitado" class="form-control"
                           min="0.01" step="0.01" required
                           value="<?= number_format(max((float)($req['valor_total'] ?? 0), (float)($req['valor_total'] ?? 0) - ($check['camada1']['saldo'] ?? 0)), 2, '.', '') ?>">
                    <div class="form-text">Pode solicitar um valor maior para cobrir necessidades futuras do mês.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Justificativa *</label>
                    <textarea name="justificativa" class="form-control" rows="3" required
                              placeholder="Explique a necessidade e o motivo do estouro do orçamento..."><?= e($req['justificativa'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-send me-1"></i>Enviar para a Direção
                </button>
            </div>
        </form>
    </div>
</div>
