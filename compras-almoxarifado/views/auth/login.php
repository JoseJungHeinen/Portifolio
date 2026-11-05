<form method="POST" action="<?= url('login') ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label fw-semibold">Nome ou e-mail</label>
        <div class="input-group">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-person text-muted"></i></span>
            <input type="text" name="email" class="form-control border-start-0"
                   placeholder="Seu nome ou seu@email.com" required autofocus
                   value="<?= e($_GET['email'] ?? $_POST['email'] ?? '') ?>">
        </div>
    </div>
    <div class="mb-4">
        <label class="form-label fw-semibold">Senha</label>
        <div class="input-group">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-muted"></i></span>
            <input type="password" name="senha" id="senhaInput"
                   class="form-control border-start-0 border-end-0"
                   placeholder="••••••••" required
                   value="<?= e($_GET['senha'] ?? '') ?>">
            <button type="button" class="btn btn-light border" onclick="toggleSenha()">
                <i class="bi bi-eye" id="senhaIcon"></i>
            </button>
        </div>
    </div>
    <div class="d-grid mb-3">
        <button type="submit" class="btn btn-brand py-2 fw-semibold">
            <i class="bi bi-box-arrow-in-right me-2"></i>Entrar
        </button>
    </div>
    <div class="text-center">
        <a href="<?= url('senha/reset') ?>" class="text-muted small">Esqueci minha senha</a>
    </div>
</form>
<script>
function toggleSenha() {
    const inp  = document.getElementById('senhaInput');
    const icon = document.getElementById('senhaIcon');
    inp.type   = inp.type === 'password' ? 'text' : 'password';
    icon.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
</script>
