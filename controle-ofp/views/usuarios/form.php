<?php use App\Models\Usuario; ?>
<a class="small" href="<?= url('/usuarios') ?>">&larr; Usuários</a>
<h1 class="titulo h3 mb-3"><?= $usuario ? 'Editar <span>' . e($usuario['nome']) . '</span>' : 'Novo <span>usuário</span>' ?></h1>
<form class="card card-body" method="post" action="<?= $usuario ? url('/usuarios/' . $usuario['id']) : url('/usuarios') ?>" style="max-width: 640px">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label" for="nome">Nome *</label><input class="form-control" id="nome" name="nome" maxlength="120" value="<?= e($dados['nome'] ?? '') ?>" required></div>
    <div class="mb-3"><label class="form-label" for="email">E-mail *</label><input class="form-control" id="email" type="email" name="email" maxlength="160" value="<?= e($dados['email'] ?? '') ?>" required></div>
    <div class="mb-3"><label class="form-label" for="perfil">Perfil *</label>
        <select class="form-select" id="perfil" name="perfil" required>
            <option value="">Selecione…</option>
            <?php foreach (Usuario::PERFIS as $chave => $rotulo): ?><option value="<?= $chave ?>" <?= ($dados['perfil'] ?? '') === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?>
        </select></div>
    <fieldset class="mb-3">
        <legend class="form-label fs-6">Unidades</legend>
        <p class="small text-muted mb-1">Sem unidade marcada: estoque e comprador não veem nada; gerente, financeiro, diretor e admin veem todas.</p>
        <?php foreach ($unidades as $u): ?>
            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="un-<?= e($u['id']) ?>" name="unidades[]" value="<?= e($u['id']) ?>" <?= in_array($u['id'], $vinculadas, true) ? 'checked' : '' ?>><label class="form-check-label" for="un-<?= e($u['id']) ?>"><?= e($u['nome']) ?></label></div>
        <?php endforeach; ?>
    </fieldset>
    <div class="mb-3"><label class="form-label" for="senha"><?= $usuario ? 'Nova senha (em branco = manter)' : 'Senha *' ?></label><input class="form-control" id="senha" type="password" name="senha" autocomplete="new-password" minlength="8" <?= $usuario ? '' : 'required' ?>></div>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="ativo" name="ativo" value="1" <?= (int)($dados['ativo'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label" for="ativo">Ativo</label></div>
    <div><button class="btn btn-primary">Salvar</button></div>
</form>
