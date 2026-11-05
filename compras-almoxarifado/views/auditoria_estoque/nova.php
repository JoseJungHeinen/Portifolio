<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-clipboard2-plus me-2"></i>Nova Auditoria de Estoque</h4>
    <a href="<?= url('auditoria-estoque') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Voltar
    </a>
</div>


<div class="card shadow-sm" style="max-width:640px">
    <div class="card-body">
        <form method="POST" action="<?= url('auditoria-estoque') ?>">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label fw-semibold">Filial <span class="text-danger">*</span></label>
                <select name="filial_id" class="form-select" required>
                    <option value="">Selecione a filial...</option>
                    <?php foreach ($filiais as $f): ?>
                    <option value="<?= e($f['id']) ?>"><?= e($f['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Escopo da auditoria</label>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="escopo" id="escTodos" value="todos" checked>
                        <label class="form-check-label" for="escTodos">Todos os itens</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="escopo" id="escGrupo" value="grupo">
                        <label class="form-check-label" for="escGrupo">Por grupo</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="escopo" id="escCategoria" value="categoria">
                        <label class="form-check-label" for="escCategoria">Por categoria</label>
                    </div>
                </div>
            </div>

            <div id="divGrupo" class="mb-3 d-none">
                <label class="form-label">Grupo de produtos</label>
                <select name="escopo_id" id="selGrupo" class="form-select">
                    <option value="">Selecione...</option>
                    <?php foreach ($grupos as $g): ?>
                    <option value="<?= e($g['id']) ?>"><?= e($g['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="divCategoria" class="mb-3 d-none">
                <label class="form-label">Categoria</label>
                <select name="escopo_id" id="selCategoria" class="form-select">
                    <option value="">Selecione...</option>
                    <?php foreach ($categorias as $c): ?>
                    <option value="<?= e($c['id']) ?>"><?= e($c['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-4">
                <label class="form-label">Observação (opcional)</label>
                <textarea name="observacao" class="form-control" rows="2" placeholder="Ex: Auditoria mensal de medicamentos..."></textarea>
            </div>

            <div class="alert alert-info small mb-4">
                <i class="bi bi-info-circle me-1"></i>
                Ao criar, o sistema vai registrar o saldo atual do estoque para cada item. Você poderá imprimir a folha, fazer a contagem física e depois registrar os resultados aqui.
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-clipboard2-check me-1"></i>Criar Auditoria
                </button>
                <a href="<?= url('auditoria-estoque') ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    const radios   = document.querySelectorAll('input[name="escopo"]');
    const divGrupo = document.getElementById('divGrupo');
    const divCat   = document.getElementById('divCategoria');
    const selGrupo = document.getElementById('selGrupo');
    const selCat   = document.getElementById('selCategoria');

    function toggle() {
        const val = document.querySelector('input[name="escopo"]:checked').value;
        divGrupo.classList.toggle('d-none', val !== 'grupo');
        divCat.classList.toggle('d-none',   val !== 'categoria');
        selGrupo.disabled = val !== 'grupo';
        selCat.disabled   = val !== 'categoria';
    }

    radios.forEach(r => r.addEventListener('change', toggle));
    toggle();
})();
</script>
