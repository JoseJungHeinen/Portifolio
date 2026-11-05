<?php include __DIR__ . '/_nav.php'; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-tags me-2 text-primary"></i>Grupos de Produto</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalGrupo">
        <i class="bi bi-plus-lg me-1"></i>Novo Grupo
    </button>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr><th>Nome</th><th>Descrição</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($grupos as $g): ?>
                <tr>
                    <td class="fw-semibold"><?= e($g['nome']) ?></td>
                    <td class="text-muted small"><?= e($g['descricao'] ?? '—') ?></td>
                    <td><?= $g['ativo'] ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-light text-dark">Inativo</span>' ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary"
                                onclick="editGrupo('<?= $g['id'] ?>','<?= e(addslashes($g['nome'])) ?>','<?= e(addslashes($g['descricao'] ?? '')) ?>')"
                                data-bs-toggle="modal" data-bs-target="#modalGrupo">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalGrupo" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="<?= url('configuracoes/grupos') ?>" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title">Grupo de Produto</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="id" id="grupoId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nome *</label>
                    <input type="text" name="nome" id="grupoNome" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Descrição</label>
                    <textarea name="descricao" id="grupoDesc" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>
<script>
function editGrupo(id, nome, desc) {
    document.getElementById('grupoId').value   = id;
    document.getElementById('grupoNome').value = nome;
    document.getElementById('grupoDesc').value = desc;
}
</script>
