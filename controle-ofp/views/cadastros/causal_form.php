<?php
$c = $causal ?? ['codigo' => '', 'descricao' => '', 'tipo_devolucao' => 'fisica', 'prazo_abertura_dias' => 14, 'fotos_obrigatorias' => '',
    'valor_minimo' => null, 'frete_por' => 'fabricante', 'exige_tp41' => 0, 'transportadora' => null, 'ativo' => 1];
$acao = $causal ? url('/cadastros/causais/' . $causal['id']) : url('/cadastros/causais');
?>
<a class="small" href="<?= url('/cadastros/causais') ?>">&larr; Causais</a>
<h1 class="titulo h3 mb-3"><?= $causal ? 'Causal <span>' . e($c['codigo']) . '</span>' : 'Novo <span>causal</span>' ?></h1>
<form class="card card-body" method="post" action="<?= $acao ?>" style="max-width: 760px">
    <?= csrf_field() ?>
    <div class="row g-3">
        <?php if (!$causal): ?>
            <div class="col-md-3"><label class="form-label" for="codigo">Código *</label><input class="form-control text-uppercase" id="codigo" name="codigo" maxlength="4" required></div>
        <?php endif; ?>
        <div class="col-md-<?= $causal ? '12' : '9' ?>"><label class="form-label" for="descricao">Descrição *</label><input class="form-control" id="descricao" name="descricao" maxlength="150" value="<?= e($c['descricao']) ?>" required></div>
        <div class="col-md-4"><label class="form-label" for="tipo">Devolução</label>
            <select class="form-select" id="tipo" name="tipo_devolucao">
                <option value="fisica" <?= $c['tipo_devolucao'] === 'fisica' ? 'selected' : '' ?>>Física</option>
                <option value="simbolica" <?= $c['tipo_devolucao'] === 'simbolica' ? 'selected' : '' ?>>Simbólica</option>
            </select></div>
        <div class="col-md-4"><label class="form-label" for="prazo">Prazo de abertura (dias após D)</label><input class="form-control" id="prazo" name="prazo_abertura_dias" inputmode="numeric" value="<?= e($c['prazo_abertura_dias'] ?? '') ?>" placeholder="em branco = sem prazo"></div>
        <div class="col-md-4"><label class="form-label" for="minimo">Valor mínimo (R$)</label><input class="form-control" id="minimo" name="valor_minimo" inputmode="decimal" value="<?= $c['valor_minimo'] === null ? '' : e(number_format((float)$c['valor_minimo'], 2, ',', '.')) ?>"></div>
        <div class="col-md-4"><label class="form-label" for="frete">Frete pago por</label>
            <select class="form-select" id="frete" name="frete_por">
                <option value="fabricante" <?= $c['frete_por'] === 'fabricante' ? 'selected' : '' ?>>Fabricante</option>
                <option value="dealer" <?= $c['frete_por'] === 'dealer' ? 'selected' : '' ?>>Dealer</option>
            </select></div>
        <div class="col-md-4"><label class="form-label" for="transp">Transportadora do causal</label>
            <select class="form-select" id="transp" name="transportadora">
                <option value="">—</option>
                <option value="azul" <?= $c['transportadora'] === 'azul' ? 'selected' : '' ?>>Azul</option>
                <option value="jat" <?= $c['transportadora'] === 'jat' ? 'selected' : '' ?>>JAT</option>
            </select></div>
        <div class="col-md-4 d-flex flex-column justify-content-end gap-1">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="tp41" name="exige_tp41" value="1" <?= (int)$c['exige_tp41'] ? 'checked' : '' ?>><label class="form-check-label" for="tp41">Exige NF TP41</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="ativo" name="ativo" value="1" <?= (int)$c['ativo'] ? 'checked' : '' ?>><label class="form-check-label" for="ativo">Ativo</label></div>
        </div>
        <div class="col-12"><label class="form-label" for="fotos">Fotos obrigatórias</label><textarea class="form-control" id="fotos" name="fotos_obrigatorias" rows="3"><?= e($c['fotos_obrigatorias'] ?? '') ?></textarea></div>
        <div class="col-12"><button class="btn btn-primary">Salvar</button></div>
    </div>
</form>
