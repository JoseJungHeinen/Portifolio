<?php
// @versao Ver.001.090926
?>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-person-gear me-2 text-primary"></i>Gestores por Unidade e Setor</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalGestor">
        <i class="bi bi-plus-lg me-1"></i>Novo Vínculo
    </button>
</div>

<div class="alert alert-light border small">
    <i class="bi bi-info-circle me-1"></i>
    Cada unidade e setor pode ter mais de um gestor — qualquer um deles aprova, e o primeiro que decidir resolve.
    Combinação sem gestor cadastrado fica visível apenas para admin e diretor.
</div>

<form method="GET" action="<?= url('configuracoes/gestores') ?>" class="row g-2 mb-3">
    <div class="col-md-4">
        <select name="filial_id" class="form-select form-select-sm">
            <option value="">Todas as unidades</option>
            <?php foreach ($filiais as $f): ?>
            <option value="<?= $f['id'] ?>" <?= $filtroFilial === $f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4">
        <select name="setor_id" class="form-select form-select-sm">
            <option value="">Todos os setores</option>
            <?php foreach ($setores as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $filtroSetor === $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
        <a href="<?= url('configuracoes/gestores') ?>" class="btn btn-sm btn-outline-secondary">Limpar</a>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr><th>Unidade</th><th>Setor</th><th>Gestor</th><th>Perfil</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (empty($vinculos)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Nenhum vínculo cadastrado.</td></tr>
                <?php endif; ?>
                <?php foreach ($vinculos as $v): ?>
                <tr>
                    <td class="fw-semibold"><?= e($v['filial_nome']) ?></td>
                    <td><?= e($v['setor_nome']) ?></td>
                    <td>
                        <?= e($v['gestor_nome']) ?>
                        <?php if (!$v['gestor_ativo']): ?>
                        <span class="badge bg-light text-danger ms-1" title="Usuário inativo: o vínculo não tem efeito">usuário inativo</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge bg-secondary"><?= ucfirst($v['gestor_perfil']) ?></span></td>
                    <td><?= $v['ativo'] ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-light text-dark">Inativo</span>' ?></td>
                    <td class="d-flex gap-1">
                        <form method="POST" action="<?= url('configuracoes/gestores/' . $v['id'] . '/toggle') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm <?= $v['ativo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                    title="<?= $v['ativo'] ? 'Desativar' : 'Ativar' ?>">
                                <i class="bi <?= $v['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                            </button>
                        </form>
                        <form method="POST" action="<?= url('configuracoes/gestores/' . $v['id'] . '/excluir') ?>"
                              onsubmit="return confirm('Excluir o vínculo de <?= e($v['gestor_nome']) ?> em <?= e($v['filial_nome']) ?> / <?= e($v['setor_nome']) ?>?')">
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

<div class="modal fade" id="modalGestor" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="<?= url('configuracoes/gestores') ?>" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title">Novo Vínculo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Unidade *</label>
                    <select name="filial_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($filiais as $f): ?>
                        <option value="<?= $f['id'] ?>"><?= e($f['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Setor *</label>
                    <select name="setor_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($setores as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Gestor *</label>
                    <select name="usuario_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($gestores as $g): ?>
                        <option value="<?= $g['id'] ?>"><?= e($g['nome']) ?> (<?= ucfirst($g['perfil']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Apenas usuários ativos com perfil Gestor ou Diretor.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>
