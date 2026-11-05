<?php $title = 'Solicitações de Aumento de Orçamento'; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-cash-stack me-2 text-warning"></i>Solicitações de Orçamento</h4>
    <div class="d-flex gap-2">
        <?php foreach (['pendente','aprovado','rejeitado'] as $st): ?>
            <a href="?status=<?= $st ?>" class="btn btn-sm <?= ($_GET['status'] ?? '') === $st ? 'btn-secondary' : 'btn-outline-secondary' ?>">
                <?= ucfirst($st) ?>
            </a>
        <?php endforeach; ?>
        <a href="?" class="btn btn-sm btn-outline-secondary">Todos</a>
        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#modalNovaSolicitacao">
            <i class="bi bi-plus-lg me-1"></i>Nova Solicitação
        </button>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Solicitante</th>
                        <th>Filial / Setor</th>
                        <th>Grupo</th>
                        <th class="text-end">Saldo Atual</th>
                        <th class="text-end">Valor Solicitado</th>
                        <th>Status</th>
                        <th>Data</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($solicitacoes as $s): ?>
                    <tr>
                        <td class="px-4 small fw-semibold"><?= e($s['solicitante_nome']) ?></td>
                        <td class="small"><?= e($s['filial_nome']) ?> / <?= e($s['setor_nome']) ?></td>
                        <td class="small"><?= e($s['grupo_nome']) ?></td>
                        <td class="text-end small <?= (float)$s['valor_saldo_atual'] <= 0 ? 'text-danger fw-bold' : '' ?>"><?= money($s['valor_saldo_atual']) ?></td>
                        <td class="text-end fw-semibold"><?= money($s['valor_solicitado']) ?></td>
                        <td><?= status_badge($s['status']) ?></td>
                        <td class="small text-muted"><?= date_br($s['criado_em']) ?></td>
                        <td class="pe-3">
                            <?php if ($s['status'] === 'pendente' && can(['diretor','admin'])): ?>
                                <button class="btn btn-success btn-sm"
                                        data-bs-toggle="modal" data-bs-target="#modalDecisao"
                                        data-id="<?= $s['id'] ?>" data-acao="aprovar"
                                        data-valor="<?= money($s['valor_solicitado']) ?>"
                                        data-justificativa="<?= e($s['justificativa']) ?>">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                                <button class="btn btn-outline-danger btn-sm"
                                        data-bs-toggle="modal" data-bs-target="#modalDecisao"
                                        data-id="<?= $s['id'] ?>" data-acao="rejeitar"
                                        data-valor="<?= money($s['valor_solicitado']) ?>"
                                        data-justificativa="<?= e($s['justificativa']) ?>">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            <?php elseif ($s['observacao_direcao']): ?>
                                <span class="text-muted small" title="<?= e($s['observacao_direcao']) ?>"><i class="bi bi-chat-text"></i></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($solicitacoes)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>Nenhuma solicitação encontrada.
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Nova Solicitação -->
<div class="modal fade" id="modalNovaSolicitacao" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="<?= url('orcamentos/solicitacoes') ?>" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-send me-2 text-warning"></i>Nova Solicitação de Orçamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="orcamento_id" value="">
                <input type="hidden" name="requisicao_id" value="">
                <input type="hidden" name="valor_necessario" value="0">

                <?php if (can(['diretor','admin'])): ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Filial *</label>
                    <select name="filial_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($filiais as $f): ?>
                            <option value="<?= $f['id'] ?>" <?= $f['id'] === $user['filial_id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Setor *</label>
                    <select name="setor_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($setores as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $s['id'] === $user['setor_id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Grupo de Produto *</label>
                    <select name="grupo_produto_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($grupos as $g): ?>
                            <option value="<?= $g['id'] ?>"><?= e($g['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Valor a Solicitar (R$) *</label>
                    <input type="number" name="valor_solicitado" class="form-control"
                           min="0.01" step="0.01" required placeholder="0,00">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Justificativa *</label>
                    <textarea name="justificativa" class="form-control" rows="3" required
                              placeholder="Descreva a necessidade e o motivo da solicitação..."></textarea>
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

<div class="modal fade" id="modalDecisao" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="formDecisao">
            <?= csrf_field() ?>
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold" id="modalDecisaoTitulo"></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3" id="justificativaTexto"></div>
                    <label class="form-label fw-semibold">Observação *</label>
                    <textarea name="observacao" class="form-control" rows="3" required placeholder="Informe sua decisão..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnDecisao">Confirmar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('modalDecisao').addEventListener('show.bs.modal', e => {
    const btn     = e.relatedTarget;
    const id      = btn.dataset.id;
    const acao    = btn.dataset.acao;
    const isAprova= acao === 'aprovar';

    document.getElementById('modalDecisaoTitulo').textContent = isAprova ? '✅ Aprovar Aumento' : '❌ Rejeitar Solicitação';
    document.getElementById('justificativaTexto').textContent = 'Justificativa: ' + btn.dataset.justificativa;
    document.getElementById('formDecisao').action = `<?= url('orcamentos/solicitacoes') ?>/${id}/${acao}`;
    document.getElementById('btnDecisao').className = `btn ${isAprova ? 'btn-success' : 'btn-danger'}`;
    document.getElementById('btnDecisao').textContent = isAprova ? 'Aprovar' : 'Rejeitar';
});
</script>


