<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-text me-2 text-success"></i>Conferência Fiscal (NF-e)</h4>
    <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalUpload">
        <i class="bi bi-upload me-1"></i>Importar XML
    </button>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2" method="GET">
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Todos os status</option>
                    <option value="pendente"        <?= ($_GET['status'] ?? '') === 'pendente'         ? 'selected' : '' ?>>Pendente</option>
                    <option value="aprovado"        <?= ($_GET['status'] ?? '') === 'aprovado'         ? 'selected' : '' ?>>Aprovado</option>
                    <option value="divergente"      <?= ($_GET['status'] ?? '') === 'divergente'       ? 'selected' : '' ?>>Divergente</option>
                    <option value="rejeitado"       <?= ($_GET['status'] ?? '') === 'rejeitado'        ? 'selected' : '' ?>>Rejeitado</option>
                    <option value="recebido_total"  <?= ($_GET['status'] ?? '') === 'recebido_total'   ? 'selected' : '' ?>>Recebido</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>NF nº</th>
                        <th>Fornecedor</th>
                        <th>Filial</th>
                        <th class="text-end">Valor</th>
                        <th>Emissão</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($notas)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma nota fiscal importada.</td></tr>
                    <?php else: ?>
                    <?php foreach ($notas as $nf): ?>
                    <tr>
                        <td><code><?= e($nf['numero_nf']) ?></code></td>
                        <td><?= e($nf['fornecedor_nome'] ?? '—') ?></td>
                        <td><?= e($nf['filial_nome']) ?></td>
                        <td class="text-end"><?= money($nf['valor_total'] ?? 0) ?></td>
                        <td><?= date_br($nf['data_emissao'] ?? null) ?></td>
                        <td><?= status_badge($nf['status']) ?></td>
                        <td>
                            <a href="<?= url('fiscal/' . $nf['id']) ?>" class="btn btn-sm btn-outline-secondary">
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

<!-- Modal Upload XML -->
<div class="modal fade" id="modalUpload" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="<?= url('fiscal/upload') ?>" enctype="multipart/form-data" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title">Importar NF-e (XML)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Arquivo XML da NF-e *</label>
                    <input type="file" name="xml_nf" class="form-control" accept=".xml" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Ordem de Compra relacionada *</label>
                    <select name="oc_id" class="form-select" required>
                        <option value="">Selecione a Ordem de Compra</option>
                        <?php foreach ($ordensDisponiveis as $oc): ?>
                            <option value="<?= $oc['id'] ?>">
                                <?= e($oc['numero_oc']) ?> — <?= e($oc['fornecedor_nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">O sistema verificará divergências automaticamente.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success">Importar</button>
            </div>
        </form>
    </div>
</div>