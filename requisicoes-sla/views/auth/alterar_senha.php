<?php
// @versao Ver.001.281026
?>
<?php $noSidebar = true; ?>
<?php $title = 'Alterar Senha'; ?>
<div class="row justify-content-center w-100">
    <div class="col-md-5 col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                <h5 class="fw-bold mb-0"><i class="bi bi-key me-2 text-primary"></i>Alterar Senha</h5>
            </div>
            <div class="card-body px-4 pb-4">
                <form method="POST" action="<?= url('senha/alterar') ?>">
                    <?= csrf_field() ?>
                    <?php if (!empty($trocaObrigatoria)): ?>
                    <div class="alert alert-info small py-2">
                        Primeiro acesso: defina sua senha pessoal para continuar.
                    </div>
                    <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Senha Atual</label>
                        <input type="password" name="senha_atual" class="form-control" required autocomplete="current-password">
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nova Senha</label>
                        <input type="password" name="nova_senha" class="form-control" required minlength="8">
                        <div class="form-text">Mínimo 8 caracteres, 1 maiúscula e 1 número.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Confirmar Nova Senha</label>
                        <input type="password" name="confirmar_senha" class="form-control" required minlength="8">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Salvar
                        </button>
                        <a href="<?= url('dashboard') ?>" class="btn btn-outline-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
