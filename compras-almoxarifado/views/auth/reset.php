<form method="POST" action="<?= url('senha/reset') ?>">
    <?= csrf_field() ?>
    <p class="text-muted small mb-3">Informe seu e-mail cadastrado e enviaremos um link de recuperação.</p>
    <div class="mb-3">
        <label class="form-label fw-semibold">E-mail</label>
        <div class="input-group">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-muted"></i></span>
            <input type="email" name="email" class="form-control border-start-0" placeholder="seu@email.com" required autofocus>
        </div>
    </div>
    <div class="d-grid mb-3">
        <button type="submit" class="btn btn-brand py-2 fw-semibold">
            <i class="bi bi-send me-2"></i>Enviar link de recuperação
        </button>
    </div>
    <div class="text-center">
        <a href="<?= url('login') ?>" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Voltar ao login</a>
    </div>
</form>
