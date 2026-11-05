<a class="small" href="<?= url('/cadastros/unidades') ?>">&larr; Unidades</a>
<h1 class="titulo h3 mb-3">Unidade <span><?= e($unidade['nome']) ?></span></h1>
<form class="card card-body" method="post" action="<?= url('/cadastros/unidades/' . $unidade['id']) ?>" style="max-width: 560px">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label" for="nome">Nome *</label><input class="form-control" id="nome" name="nome" maxlength="80" value="<?= e($unidade['nome']) ?>" required></div>
    <div class="row g-3 mb-3">
        <div class="col-8"><label class="form-label" for="dealer">Código dealer Fabricante</label><input class="form-control" id="dealer" name="codigo_dealer" maxlength="20" value="<?= e($unidade['codigo_dealer'] ?? '') ?>"></div>
        <div class="col-4"><label class="form-label" for="uf">UF *</label><input class="form-control text-uppercase" id="uf" name="uf" maxlength="2" value="<?= e($unidade['uf']) ?>" required></div>
    </div>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="ativo" name="ativo" value="1" <?= (int)$unidade['ativo'] ? 'checked' : '' ?>><label class="form-check-label" for="ativo">Ativa</label></div>
    <div><button class="btn btn-primary">Salvar</button></div>
</form>
