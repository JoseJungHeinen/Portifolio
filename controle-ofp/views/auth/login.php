<h1 class="titulo h4 mb-3">Controle de <span>OFP</span></h1>
<form method="post" action="<?= url('/login') ?>">
    <div class="mb-3">
        <label class="form-label" for="email">E-mail</label>
        <input class="form-control" type="email" id="email" name="email" required autofocus>
    </div>
    <div class="mb-3">
        <label class="form-label" for="senha">Senha</label>
        <input class="form-control" type="password" id="senha" name="senha" required>
    </div>
    <button class="btn btn-primary w-100" type="submit">Entrar</button>
</form>
