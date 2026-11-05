<?php
// @versao Ver.003.281026
?>
<?php $title = 'Requisições'; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-cart-plus me-2 text-primary"></i>Requisições</h4>
    <div class="d-flex gap-2">
        <?php if (tiposRequisicaoPermitidos()): ?>
        <a href="<?= url('requisicoes/nova') ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Nova Requisição
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filtros -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-auto">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Todos os status</option>
                    <?php foreach (['rascunho','pendente','em_aprovacao','aprovado','rejeitado','cancelado'] as $s): ?>
                        <option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="tipo_despesa" class="form-select form-select-sm">
                    <option value="">Todos os tipos</option>
                    <option value="variavel"   <?= ($_GET['tipo_despesa'] ?? '') === 'variavel'   ? 'selected' : '' ?>>Variável</option>
                    <option value="eventual"   <?= ($_GET['tipo_despesa'] ?? '') === 'eventual'   ? 'selected' : '' ?>>Eventual</option>
                    <option value="recorrente" <?= ($_GET['tipo_despesa'] ?? '') === 'recorrente' ? 'selected' : '' ?>>Recorrente</option>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-search me-1"></i>Filtrar</button>
            </div>
            <div class="col-auto">
                <a href="<?= url('requisicoes') ?>" class="btn btn-link btn-sm text-muted">Limpar</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">N°</th>
                        <th class="px-4">Solicitante</th>
                        <th>Setor</th>
                        <th>Modalidade</th>
                        <th>Grupo / Destino</th>
                        <th>Despesa</th>
                        <?php if (can(['comprador','gestor','diretor','admin'])): ?><th class="text-end">Valor</th><?php endif; ?>
                        <th>Status</th>
                        <th>Data</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // Fundo da linha num tom claro da cor do badge da modalidade
                // (as cores dos badges vêm do app.css, não do Bootstrap)
                $corLinhaTipo = [
                    'compra'        => 'color-mix(in srgb, var(--color-primary) 12%, #fff)',
                    'interna'       => 'color-mix(in srgb, var(--color-warning) 15%, #fff)',
                    'transferencia' => 'color-mix(in srgb, #6f42c1 12%, #fff)',
                    'ordem_compra'  => 'color-mix(in srgb, var(--color-gray-dark) 12%, #fff)',
                ];
                ?>
                <?php foreach ($requisicoes as $r): ?>
                    <tr style="--bs-table-bg: <?= $corLinhaTipo[$r['tipo'] ?? 'compra'] ?? $corLinhaTipo['compra'] ?>">
                        <?php
                            $numFormatado = formatar_numero_requisicao($r['tipo'] ?? 'compra', $r['criado_em'], (int)($r['numero'] ?? 0));
                        ?>
                        <td class="px-3">
                            <?php if (!empty($r['oc_id'])): ?>
                                <a href="<?= url('compras/ordens/' . $r['oc_id']) ?>"
                                   class="badge bg-success text-decoration-none"
                                   title="Ver Ordem de Compra">
                                    <?= e($numFormatado) ?>
                                </a>
                                <div class="mt-1">
                                    <a href="<?= url('compras/ordens/' . $r['oc_id']) ?>"
                                       class="badge bg-light text-success border border-success text-decoration-none small"
                                       title="Ordem de Compra">
                                        <i class="bi bi-cart-check me-1"></i><?= e($r['oc_numero']) ?>
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="badge bg-light text-dark border"><?= e($numFormatado) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4">
                            <a href="<?= url('requisicoes/' . $r['id']) ?>" class="text-decoration-none">
                                <div class="fw-semibold small"><?= e($r['solicitante_nome']) ?></div>
                                <small class="text-muted"><?= e($r['filial_nome']) ?></small>
                            </a>
                        </td>
                        <td class="small"><?= e($r['setor_nome']) ?></td>
                        <td>
                            <?php if (($r['tipo'] ?? 'compra') === 'interna'): ?>
                                <span class="badge bg-warning text-dark">Interna</span>
                            <?php elseif (($r['tipo'] ?? 'compra') === 'transferencia'): ?>
                                <span class="badge bg-purple" style="background-color:#6f42c1!important">Transferência</span>
                            <?php elseif (($r['tipo'] ?? 'compra') === 'ordem_compra'): ?>
                                <span class="badge bg-info">Ordem de Compra</span>
                            <?php else: ?>
                                <span class="badge bg-primary">Compra</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?php if (in_array($r['tipo'] ?? 'compra', ['interna', 'transferencia'], true)): ?>
                                <i class="bi bi-arrow-right text-secondary me-1"></i><?= e($r['filial_destino_nome'] ?? '—') ?>
                            <?php else: ?>
                                <?= e($r['grupo_nome'] ?? '—') ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($r['tipo'] ?? 'compra') === 'compra'): ?>
                                <?php $tipoCores = ['variavel'=>'primary','eventual'=>'danger','recorrente'=>'success']; ?>
                                <span class="badge bg-<?= $tipoCores[$r['tipo_despesa']] ?? 'secondary' ?>">
                                    <?= ucfirst($r['tipo_despesa']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <?php if (can(['comprador','gestor','diretor','admin'])): ?><td class="text-end fw-semibold"><?= money($r['valor_total']) ?></td><?php endif; ?>
                        <td>
                            <?= status_badge($r['status']) ?>
                            <?php if (!empty($r['tem_divergencia'])): ?>
                            <span class="badge bg-danger ms-1" title="Um ou mais itens divergiram no recebimento">
                                <i class="bi bi-exclamation-triangle"></i> Divergência
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= date_br($r['criado_em']) ?></td>
                        <td class="pe-3">
                            <div class="d-flex gap-1 justify-content-end">
                                <?php if ($r['status'] === 'aprovado'): ?>
                                    <a href="<?= url('requisicoes/'.$r['id'].'/imprimir') ?>" target="_blank"
                                       class="btn btn-outline-secondary btn-sm" title="Imprimir">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if ($r['status'] === 'rascunho' && ($r['usuario_id'] === $user['id'] || can(['admin']))): ?>
                                    <a href="<?= url('requisicoes/'.$r['id'].'/editar') ?>" class="btn btn-outline-primary btn-sm" title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="<?= url('requisicoes/'.$r['id'].'/enviar') ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-success btn-sm" title="Enviar para aprovação"
                                                onclick="return confirm('Enviar para aprovação?')">
                                            <i class="bi bi-send"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if (in_array($r['status'], ['rascunho','pendente']) && ($r['usuario_id'] === $user['id'] || can(['admin']))): ?>
                                    <form method="POST" action="<?= url('requisicoes/'.$r['id'].'/cancelar') ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-outline-danger btn-sm" title="Cancelar"
                                                onclick="return confirm('Cancelar esta requisição?')">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if (can(['admin'])): ?>
                                    <button type="button"
                                            class="btn btn-outline-danger btn-sm"
                                            title="Excluir requisição"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalExcluirReq"
                                            data-id="<?= e($r['id']) ?>"
                                            data-label="<?= e($r['solicitante_nome'] . ' — ' . date_br($r['criado_em'])) ?>"
                                            data-status="<?= e($r['status']) ?>">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($requisicoes)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>Nenhuma requisição encontrada.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (can(['admin'])): ?>
<!-- Modal: Confirmar Exclusão -->
<div class="modal fade" id="modalExcluirReq" tabindex="-1" aria-labelledby="modalExcluirReqLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="modalExcluirReqLabel">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Excluir Requisição
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1">Tem certeza que deseja <strong>excluir permanentemente</strong> esta requisição?</p>
                <p class="text-muted small mb-3" id="excluirReqLabel">—</p>
                <div id="excluirReqAviso" class="alert alert-warning d-none py-2 small">
                    <i class="bi bi-info-circle me-1"></i>
                    Requisições com status <strong>aprovado</strong> ou <strong>em aprovação</strong> devem ser canceladas antes de excluídas, caso não possuam cotações ou OCs vinculadas.
                </div>
                <p class="text-danger small mb-0"><i class="bi bi-trash3 me-1"></i>Esta ação não pode ser desfeita.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form method="POST" id="formExcluirReq">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash3 me-1"></i>Excluir
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('modalExcluirReq').addEventListener('show.bs.modal', function (e) {
    const btn    = e.relatedTarget;
    const id     = btn.dataset.id;
    const label  = btn.dataset.label;
    const status = btn.dataset.status;

    document.getElementById('excluirReqLabel').textContent = label;
    document.getElementById('formExcluirReq').action = '<?= url('requisicoes') ?>/' + id + '/excluir';

    const aviso = document.getElementById('excluirReqAviso');
    const alertStatuses = ['aprovado', 'em_aprovacao'];
    aviso.classList.toggle('d-none', !alertStatuses.includes(status));
});
</script>
<?php endif; ?>
