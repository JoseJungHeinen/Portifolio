<?php $title = 'Novo Orçamento'; ?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('orcamentos') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi bi-pie-chart me-2 text-primary"></i>Novo Orçamento</h4>
</div>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="POST" action="<?= url('orcamentos') ?>">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Filial *</label>
                            <select name="filial_id" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($filiais as $f): ?>
                                    <option value="<?= $f['id'] ?>"><?= e($f['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Setor *</label>
                            <select name="setor_id" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($setores as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Grupo de Produto *</label>
                            <select name="grupo_produto_id" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($grupos as $g): ?>
                                    <option value="<?= $g['id'] ?>"><?= e($g['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Mês *</label>
                            <select name="mes" class="form-select" required>
                                <?php $mn = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez']; ?>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= $m == date('n') ? 'selected' : '' ?>><?= $mn[$m-1] ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Ano *</label>
                            <select name="ano" class="form-select" required>
                                <?php for ($a = date('Y'); $a <= date('Y')+2; $a++): ?>
                                    <option value="<?= $a ?>"><?= $a ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Valor Aprovado (R$) *</label>
                            <input type="text" name="valor_aprovado" class="form-control money-mask" required placeholder="0,00">
                        </div>
                    </div>
                    <hr>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar Orçamento</button>
                        <a href="<?= url('orcamentos') ?>" class="btn btn-outline-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
