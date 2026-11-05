<form method="POST" action="<?= url('senha/nova/' . e($token)) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label fw-semibold">Nova Senha</label>
        <input type="password" name="nova_senha" class="form-control" required minlength="8">
        <div class="form-text">Mínimo 8 caracteres, 1 maiúscula e 1 número.</div>
    </div>
    <div class="mb-4">
        <label class="form-label fw-semibold">Confirmar Senha</label>
        <input type="password" name="confirmar_senha" class="form-control" required minlength="8">
    </div>
    <div class="d-grid">
        <button type="submit" class="btn btn-brand py-2 fw-semibold">
            <i class="bi bi-check-circle me-2"></i>Salvar Nova Senha
        </button>
    </div>
</form>
