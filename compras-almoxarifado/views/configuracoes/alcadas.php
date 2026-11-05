<?php include __DIR__ . '/_nav.php'; ?>
<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-shield-lock me-2 text-primary"></i>Configuração de Alçadas</h4>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Regras de Aprovação por Perfil e Valor</div>
            <div class="card-body">
                <form method="POST" action="<?= url('configuracoes/alcadas') ?>" id="formAlcadas">
                    <?= csrf_field() ?>
                    <div id="alcadasContainer">
                        <?php foreach ($alcadas as $i => $a): ?>
                        <div class="row g-2 align-items-center mb-2 alcada-row">
                            <div class="col-md-3">
                                <select name="perfil[]" class="form-select form-select-sm" required>
                                    <?php foreach (['solicitante','almoxarife','comprador','gestor','diretor','admin'] as $p): ?>
                                    <option value="<?= $p ?>" <?= $a['perfil'] === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <input type="number" name="valor_min[]" class="form-control form-control-sm" placeholder="Valor Mín." min="0" step="0.01" value="<?= $a['valor_min'] ?>" required>
                            </div>
                            <div class="col-md-2">
                                <input type="number" name="valor_max[]" class="form-control form-control-sm" placeholder="Máx. (vazio=∞)" min="0" step="0.01" value="<?= $a['valor_max'] ?? '' ?>">
                            </div>
                            <div class="col-md-2">
                                <select name="nivel[]" class="form-select form-select-sm">
                                    <?php for ($n = 1; $n <= 3; $n++): ?>
                                    <option value="<?= $n ?>" <?= (int)$a['nivel'] === $n ? 'selected' : '' ?>>Nível <?= $n ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-alcada"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="addAlcada" class="btn btn-outline-primary btn-sm mt-2">
                        <i class="bi bi-plus-lg me-1"></i>Adicionar Regra
                    </button>
                    <hr>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Salvar Alçadas</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Como funciona</div>
            <div class="card-body small text-muted">
                <p><strong>Valor Mín/Máx:</strong> Faixa de valor da requisição que esta regra cobre. Deixe Máx em branco para "sem limite".</p>
                <p><strong>Nível:</strong> Ordem de aprovação. Nível 1 aprova primeiro, depois o Nível 2, etc.</p>
                <p class="mb-0"><strong>Exemplo:</strong> Gestor de R$0 a R$5.000 nível 1 significa que ele aprova sozinho compras até R$5.000.</p>
            </div>
        </div>
    </div>
</div>

<script>
const template = `<div class="row g-2 align-items-center mb-2 alcada-row">
    <div class="col-md-3"><select name="perfil[]" class="form-select form-select-sm" required>
        <option value="solicitante">Solicitante</option><option value="almoxarife">Almoxarife</option>
        <option value="comprador">Comprador</option><option value="gestor">Gestor</option>
        <option value="diretor" selected>Diretor</option><option value="admin">Admin</option>
    </select></div>
    <div class="col-md-2"><input type="number" name="valor_min[]" class="form-control form-control-sm" placeholder="Valor Mín." min="0" step="0.01" required></div>
    <div class="col-md-2"><input type="number" name="valor_max[]" class="form-control form-control-sm" placeholder="Máx. (vazio=∞)" min="0" step="0.01"></div>
    <div class="col-md-2"><select name="nivel[]" class="form-select form-select-sm">
        <option value="1">Nível 1</option><option value="2">Nível 2</option><option value="3">Nível 3</option>
    </select></div>
    <div class="col-md-2"><button type="button" class="btn btn-outline-danger btn-sm w-100 remove-alcada"><i class="bi bi-trash"></i></button></div>
</div>`;

document.getElementById('addAlcada').addEventListener('click', () => {
    document.getElementById('alcadasContainer').insertAdjacentHTML('beforeend', template);
});
document.getElementById('alcadasContainer').addEventListener('click', e => {
    if (e.target.closest('.remove-alcada')) {
        const rows = document.querySelectorAll('.alcada-row');
        if (rows.length > 1) e.target.closest('.alcada-row').remove();
    }
});
</script>
