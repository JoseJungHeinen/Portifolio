<?php $title = $brinde ? 'Editar Brinde' : 'Novo Brinde'; ?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= url($brinde ? 'brindes/' . $brinde['id'] : 'brindes') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="mb-0"><i class="bi bi-gift me-2"></i><?= $title ?></h4>
</div>

<form method="POST" action="<?= url($brinde ? 'brindes/' . $brinde['id'] : 'brindes') ?>" class="card shadow-sm">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label fw-semibold">Nome *</label>
                <input type="text" name="nome" class="form-control" required value="<?= e($brinde['nome'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Código</label>
                <input type="text" name="codigo" class="form-control" value="<?= e($brinde['codigo'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Marca</label>
                <input type="text" name="marca" class="form-control" value="<?= e($brinde['marca'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Valor de Referência (R$)</label>
                <input type="text" name="valor_referencia" class="form-control"
                       value="<?= isset($brinde['valor_referencia']) ? e(number_format((float)$brinde['valor_referencia'], 2, ',', '.')) : '' ?>"
                       placeholder="0,00">
                <div class="form-text">Usado para definir o nível de aprovação da saída.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Item Vinculado no Estoque</label>
                <select name="item_id" class="form-select">
                    <option value="">Nenhum</option>
                    <?php foreach ($itensGerais as $it): ?>
                        <option value="<?= e($it['id']) ?>" <?= ($brinde['item_id'] ?? '') === $it['id'] ? 'selected' : '' ?>>
                            <?= e($it['nome']) ?><?= $it['codigo'] ? ' (' . e($it['codigo']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Opcional. Se escolhido, entrada e saída deste brinde também movimentam o estoque geral desse item.</div>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Descrição</label>
                <textarea name="descricao" class="form-control" rows="2"><?= e($brinde['descricao'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
                <div class="form-check ms-1">
                    <input type="checkbox" class="form-check-input" id="ativo" name="ativo" value="1" <?= ($brinde['ativo'] ?? 1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ativo">Ativo</label>
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex gap-2">
        <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar</button>
        <a href="<?= url($brinde ? 'brindes/' . $brinde['id'] : 'brindes') ?>" class="btn btn-outline-secondary">Cancelar</a>
    </div>
</form>
