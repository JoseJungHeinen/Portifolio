<?php
// @versao Ver.001.270826
// Fornecedor que já respondeu aparece marcado e travado: removê-lo apagaria os
// preços que ele enviou. Para tirá-lo do comparativo, o caminho é o Refazer.
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= url('compras/cotacoes') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Editar cotação</h4>
</div>

<form method="POST" action="<?= url('compras/cotacoes/' . $cotacao['id'] . '/editar') ?>" class="card shadow-sm">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Prazo de resposta</label>
                <input type="datetime-local" name="prazo_resposta" class="form-control"
                       value="<?= e(date('Y-m-d\TH:i', strtotime($cotacao['prazo_resposta']))) ?>">
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="usarCredito"
                           name="usar_credito" value="1" <?= $cotacao['usar_credito'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="usarCredito">Usar crédito do fornecedor</label>
                </div>
            </div>
        </div>

        <hr>
        <h6 class="fw-bold mb-2">Fornecedores</h6>
        <div class="row g-2">
            <?php foreach ($fornecedores as $f): ?>
            <?php
                $naCotacao  = isset($atuais[$f['id']]);
                $respondeu  = ($atuais[$f['id']] ?? null) === 'respondida';
            ?>
            <div class="col-md-6">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="fornecedor_id[]"
                           id="forn<?= e($f['id']) ?>" value="<?= e($f['id']) ?>"
                           <?= $naCotacao ? 'checked' : '' ?>
                           <?= $respondeu ? 'disabled' : '' ?>>
                    <label class="form-check-label" for="forn<?= e($f['id']) ?>">
                        <?= e($f['nome_fantasia']) ?>
                        <?php if ($respondeu): ?>
                        <span class="badge bg-success ms-1">Já respondeu — remova pelo Refazer</span>
                        <?php endif; ?>
                    </label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-footer d-flex gap-2">
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button>
        <a href="<?= url('compras/cotacoes') ?>" class="btn btn-outline-secondary">Cancelar</a>
    </div>
</form>
