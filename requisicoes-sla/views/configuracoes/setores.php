<?php include __DIR__ . '/_nav.php'; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-diagram-3 me-2 text-primary"></i>Setores</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalSetor">
        <i class="bi bi-plus-lg me-1"></i>Novo Setor
    </button>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr><th>Nome</th><th>Tipo</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($setores as $s): ?>
                <tr>
                    <td class="fw-semibold"><?= e($s['nome']) ?></td>
                    <td><span class="badge <?= $s['tipo'] === 'operacional' ? 'bg-primary' : 'bg-secondary' ?>"><?= ucfirst($s['tipo']) ?></span></td>
                    <td><?= $s['ativo'] ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-light text-dark">Inativo</span>' ?></td>
                    <td class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-primary"
                                onclick="editSetor('<?= $s['id'] ?>','<?= e($s['nome']) ?>','<?= $s['tipo'] ?>')"
                                data-bs-toggle="modal" data-bs-target="#modalSetor">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" action="<?= url('configuracoes/setores/' . $s['id'] . '/toggle') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm <?= $s['ativo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                    title="<?= $s['ativo'] ? 'Desativar' : 'Ativar' ?>">
                                <i class="bi <?= $s['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                            </button>
                        </form>
                        <form method="POST" action="<?= url('configuracoes/setores/' . $s['id'] . '/excluir') ?>"
                              onsubmit="return confirm('Excluir o setor \"<?= e($s['nome']) ?>\"? Esta ação não pode ser desfeita.')">
                                  <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalSetor" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="<?= url('configuracoes/setores') ?>" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title">Setor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="id" id="setorId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nome *</label>
                    <input type="text" name="nome" id="setorNome" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Tipo *</label>
                    <select name="tipo" id="setorTipo" class="form-select" required>
                        <option value="operacional">Operacional</option>
                        <option value="administrativo">Administrativo</option>
                    </select>
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
function editSetor(id, nome, tipo) {
    document.getElementById('setorId').value = id;
    document.getElementById('setorNome').value = nome;
    document.getElementById('setorTipo').value = tipo;
}
</script>
