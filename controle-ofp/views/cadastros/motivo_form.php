<?php
use App\Services\StatusService;

$m = $motivo ?? ['codigo' => '', 'descricao' => '', 'aplica_a' => '', 'exige_observacao' => 0, 'ordem' => 100, 'ativo' => 1];
$aplica = array_map('trim', explode(',', (string)$m['aplica_a']));
$acao = $motivo ? url('/cadastros/motivos/' . $motivo['id']) : url('/cadastros/motivos');
?>
<a class="small" href="<?= url('/cadastros/motivos') ?>">&larr; Motivos</a>
<h1 class="titulo h3 mb-3"><?= $motivo ? 'Motivo <span>' . e($m['codigo']) . '</span>' : 'Novo <span>motivo</span>' ?></h1>
<form class="card card-body" method="post" action="<?= $acao ?>" style="max-width: 720px">
    <?= csrf_field() ?>
    <div class="row g-3">
        <?php if (!$motivo): ?>
            <div class="col-md-4"><label class="form-label" for="codigo">Código *</label><input class="form-control text-uppercase" id="codigo" name="codigo" maxlength="30" required></div>
        <?php endif; ?>
        <div class="col-md-<?= $motivo ? '10' : '6' ?>"><label class="form-label" for="descricao">Descrição *</label><input class="form-control" id="descricao" name="descricao" maxlength="150" value="<?= e($m['descricao']) ?>" required></div>
        <div class="col-md-2"><label class="form-label" for="ordem">Ordem</label><input class="form-control" id="ordem" name="ordem" inputmode="numeric" value="<?= (int)$m['ordem'] ?>"></div>
        <fieldset class="col-12">
            <legend class="form-label fs-6">Usado quando a OFP vai para *</legend>
            <?php foreach (StatusService::EXIGEM_MOTIVO as $status): ?>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="ap-<?= e($status) ?>" name="aplica_a[]" value="<?= e($status) ?>" <?= in_array($status, $aplica, true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ap-<?= e($status) ?>"><?= e(StatusService::LABELS[$status]) ?></label>
                </div>
            <?php endforeach; ?>
        </fieldset>
        <div class="col-12">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="obs" name="exige_observacao" value="1" <?= (int)$m['exige_observacao'] ? 'checked' : '' ?>><label class="form-check-label" for="obs">Exige observação (ex.: "Outro")</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="ativo" name="ativo" value="1" <?= (int)$m['ativo'] ? 'checked' : '' ?>><label class="form-check-label" for="ativo">Ativo</label></div>
        </div>
        <div class="col-12"><button class="btn btn-primary">Salvar</button></div>
    </div>
</form>
