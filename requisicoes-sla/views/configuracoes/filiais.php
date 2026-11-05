<?php
// @versao Ver.002.301026
?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php $semEstoque = sistema() === 'requisicoes'; // Requisições: sem campos de almoxarifado ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-building me-2 text-primary"></i>Unidades</h4>
    <button class="btn btn-primary btn-sm" onclick="novaFilial()" data-bs-toggle="modal" data-bs-target="#modalFilial">
        <i class="bi bi-plus-lg me-1"></i>Nova Unidade
    </button>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Nome</th>
                    <th>CNPJ</th>
                    <th>Endereço</th>
                    <th>Telefone</th>
                    <th>E-mail</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filiais as $f): ?>
                <tr class="<?= $f['ativo'] ? '' : 'opacity-50' ?>">
                    <td class="fw-semibold"><?= e($f['nome']) ?></td>
                    <td><?= e($f['cnpj'] ?? '—') ?></td>
                    <td><?= e($f['endereco'] ?? '—') ?></td>
                    <td><?= e($f['telefone'] ?? '—') ?></td>
                    <td><?= e($f['email'] ?? '—') ?></td>
                    <td>
                        <?php if ($f['ativo']): ?>
                            <span class="badge bg-success">Ativa</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inativa</span>
                        <?php endif; ?>
                        <?php if (!$semEstoque && !empty($f['usa_estoque_matriz'])): ?>
                            <span class="badge bg-info text-dark" title="Requisição Interna também lista o estoque da matriz">Estoque matriz</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary me-1"
                                onclick="editFilial('<?= e($f['id']) ?>','<?= e($f['nome']) ?>','<?= e($f['cnpj'] ?? '') ?>','<?= e($f['endereco'] ?? '') ?>','<?= e($f['telefone'] ?? '') ?>','<?= e($f['email'] ?? '') ?>',<?= (int)($f['usa_estoque_matriz'] ?? 0) ?>)"
                                data-bs-toggle="modal" data-bs-target="#modalFilial">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" action="<?= url('configuracoes/filiais/' . $f['id'] . '/toggle') ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm <?= $f['ativo'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>"
                                    title="<?= $f['ativo'] ? 'Desativar' : 'Ativar' ?>">
                                <i class="bi <?= $f['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($filiais)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma unidade cadastrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalFilial" tabindex="-1">
    <div class="modal-dialog">
        <form id="formFilial" method="POST" action="<?= url('configuracoes/filiais') ?>" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title">Unidade</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" id="filialId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nome *</label>
                    <input type="text" name="nome" id="filialNome" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">CNPJ</label>
                    <input type="text" name="cnpj" id="filialCnpj" class="form-control" placeholder="11.222.333/0001-81">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Endereço</label>
                    <input type="text" name="endereco" id="filialEndereco" class="form-control">
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-semibold">Telefone</label>
                        <input type="text" name="telefone" id="filialTelefone" class="form-control">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-semibold">E-mail</label>
                        <input type="email" name="email" id="filialEmail" class="form-control">
                    </div>
                </div>
                <?php if (!$semEstoque): ?>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="filialUsaEstoqueMatriz"
                           name="usa_estoque_matriz" value="1">
                    <label class="form-check-label" for="filialUsaEstoqueMatriz">
                        Usa o estoque da matriz
                    </label>
                    <div class="form-text">
                        Nas requisições internas, esta unidade também lista os itens disponíveis no estoque principal.
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
const baseUrl = '<?= url('configuracoes/filiais') ?>';

function novaFilial() {
    document.getElementById('filialId').value = '';
    document.getElementById('filialNome').value = '';
    document.getElementById('filialCnpj').value = '';
    document.getElementById('filialEndereco').value = '';
    document.getElementById('filialTelefone').value = '';
    document.getElementById('filialEmail').value = '';
    const chkNova = document.getElementById('filialUsaEstoqueMatriz');
    if (chkNova) chkNova.checked = false;
    document.getElementById('formFilial').action = baseUrl;
}

function editFilial(id, nome, cnpj, endereco, telefone, email, usaEstoqueMatriz) {
    document.getElementById('filialId').value = id;
    document.getElementById('filialNome').value = nome;
    document.getElementById('filialCnpj').value = cnpj;
    document.getElementById('filialEndereco').value = endereco;
    document.getElementById('filialTelefone').value = telefone;
    document.getElementById('filialEmail').value = email;
    const chkEdit = document.getElementById('filialUsaEstoqueMatriz');
    if (chkEdit) chkEdit.checked = usaEstoqueMatriz == 1;
    document.getElementById('formFilial').action = baseUrl + '/' + id;
}
</script>
