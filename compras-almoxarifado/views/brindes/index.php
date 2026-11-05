<?php
// @versao Ver.002.160926
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0"><i class="bi bi-gift me-2"></i>Brindes</h4>
        <?php if ($filtroFilial && $filialMatriz && $filtroFilial === $filialMatriz['id']): ?>
            <small class="text-primary fw-semibold"><i class="bi bi-star-fill me-1"></i>Estoque Principal</small>
        <?php elseif ($filtroFilial): ?>
            <small class="text-muted"><?= e(array_column($filiais, 'nome', 'id')[$filtroFilial] ?? '') ?></small>
        <?php else: ?>
            <small class="text-muted">Todas as filiais</small>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <a href="<?= url('brindes/requisicoes') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-card-checklist me-1"></i>Requisições
        </a>
        <a href="<?= url('brindes/requisicao/nova') ?>" class="btn btn-success">
            <i class="bi bi-box-arrow-up me-1"></i>Solicitar saída
        </a>
    </div>
</div>

<?php $temFiltro = $busca !== '' || $filtroMarca !== '' || $filtroDisponib !== 'disponiveis' || ($podeVerTodasFiliais && !empty($filtroFilial)); ?>
<form method="GET" action="<?= url('brindes') ?>" class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <?php if ($podeVerTodasFiliais): ?>
            <div class="col-12 col-md-3">
                <label class="form-label small text-muted mb-1">Filial</label>
                <select name="filial_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" <?= empty($filtroFilial) ? 'selected' : '' ?>>Todas as filiais</option>
                    <?php if ($filialMatriz): ?>
                    <option value="<?= e($filialMatriz['id']) ?>"
                            <?= $filtroFilial === $filialMatriz['id'] ? 'selected' : '' ?>>
                        ★ <?= e($filialMatriz['nome']) ?> (Principal)
                    </option>
                    <?php endif; ?>
                    <?php foreach ($filiais as $f): ?>
                    <?php if (!empty($f['is_matriz'])) continue; ?>
                    <option value="<?= e($f['id']) ?>" <?= $filtroFilial === $f['id'] ? 'selected' : '' ?>>
                        <?= e($f['nome']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-12 col-md">
                <label class="form-label small text-muted mb-1">Buscar</label>
                <input type="search" name="busca" value="<?= e($busca) ?>" class="form-control form-control-sm" placeholder="Nome ou código">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small text-muted mb-1">Marca</label>
                <select name="marca" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Todas as marcas</option>
                    <?php foreach ($marcas as $m): ?>
                    <option value="<?= e($m) ?>" <?= $filtroMarca === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small text-muted mb-1">Disponibilidade</label>
                <select name="disponibilidade" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach (['disponiveis' => 'Disponíveis', 'indisponiveis' => 'Indisponíveis', 'todos' => 'Todos'] as $valor => $texto): ?>
                    <option value="<?= $valor ?>" <?= $filtroDisponib === $valor ? 'selected' : '' ?>><?= $texto ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                <?php if ($temFiltro): ?>
                <a href="<?= url('brindes') ?>" class="btn btn-sm btn-outline-secondary">Limpar</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<?php if (empty($brindes)): ?>
<div class="card text-center py-5"><div class="card-body text-muted">
    <i class="bi bi-gift fs-1 d-block mb-2"></i>
    <?= $temFiltro ? 'Nenhum brinde encontrado para esse filtro.' : 'Nenhum brinde com estoque disponível.' ?>
</div></div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($brindes as $b): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <div class="card h-100 shadow-sm">
            <a href="<?= url('brindes/' . $b['id']) ?>" class="text-decoration-none">
                <?php if (!empty($capas[$b['id']])): ?>
                <img src="<?= url('brindes/fotos/' . $capas[$b['id']]) ?>" class="card-img-top" style="height:160px;object-fit:cover;" alt="<?= e($b['nome']) ?>">
                <?php else: ?>
                <div class="bg-light d-flex align-items-center justify-content-center" style="height:160px">
                    <i class="bi bi-gift fs-1 text-muted"></i>
                </div>
                <?php endif; ?>
            </a>
            <div class="card-body">
                <a href="<?= url('brindes/' . $b['id']) ?>" class="fw-semibold text-decoration-none text-dark d-block"><?= e($b['nome']) ?></a>
                <?php if (!empty($b['codigo'])): ?>
                <div class="small text-muted"><?= e($b['codigo']) ?></div>
                <?php endif; ?>
                <?php if (!empty($b['marca'])): ?>
                <div class="small text-muted"><i class="bi bi-tag me-1"></i><?= e($b['marca']) ?></div>
                <?php endif; ?>
                <div class="mt-2">
                    <?php if ((float)$b['saldo_total'] > 0): ?>
                    <span class="badge bg-success">Disponível</span>
                    <span class="small text-muted ms-1"><?= number_format((float)$b['saldo_total'], 2, ',', '.') ?> em estoque</span>
                    <?php else: ?>
                    <span class="badge bg-secondary">Indisponível</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
