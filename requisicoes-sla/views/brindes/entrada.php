<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= url('brindes/' . $brinde['id']) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="mb-0"><i class="bi bi-box-arrow-in-down me-2"></i>Entrada de estoque — <?= e($brinde['nome']) ?></h4>
</div>

<form method="POST" action="<?= url('brindes/' . $brinde['id'] . '/entrada') ?>" class="card shadow-sm" style="max-width:520px">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label fw-semibold">Filial *</label>
            <select name="filial_id" class="form-select" required>
                <option value="">Selecione a filial...</option>
                <?php foreach ($filiais as $f): ?>
                <option value="<?= e($f['id']) ?>"><?= e($f['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Quantidade *</label>
            <input type="number" name="quantidade" class="form-control" step="0.01" min="0.01" required placeholder="0">
        </div>
    </div>
    <div class="card-footer d-flex gap-2">
        <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Registrar entrada</button>
        <a href="<?= url('brindes/' . $brinde['id']) ?>" class="btn btn-outline-secondary">Cancelar</a>
    </div>
</form>
