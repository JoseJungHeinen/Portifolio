<?php $saldoTotal = array_sum(array_map(fn($e) => (float)$e['quantidade'], $estoque)); ?>
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <a href="<?= url('brindes') ?>" class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i>Brindes
        </a>
        <h4 class="mb-0 mt-1"><i class="bi bi-gift me-2"></i><?= e($brinde['nome']) ?></h4>
        <div class="small text-muted">
            <?php if (!empty($brinde['codigo'])): ?><?= e($brinde['codigo']) ?> · <?php endif; ?>
            <?php if (!empty($brinde['marca'])): ?><?= e($brinde['marca']) ?> · <?php endif; ?>
            <?php if ($saldoTotal > 0): ?>
            <span class="badge bg-success">Disponível</span>
            <?php else: ?>
            <span class="badge bg-secondary">Indisponível</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url('brindes/requisicao/nova') ?>" class="btn btn-success btn-sm">
            <i class="bi bi-box-arrow-up me-1"></i>Solicitar saída
        </a>
        <?php if ($podeGerenciar): ?>
        <a href="<?= url('brindes/' . $brinde['id'] . '/entrada') ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-box-arrow-in-down me-1"></i>Entrada de estoque
        </a>
        <a href="<?= url('brindes/' . $brinde['id'] . '/editar') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-pencil me-1"></i>Editar
        </a>
        <form method="POST" action="<?= url('brindes/' . $brinde['id'] . '/excluir') ?>" class="d-inline"
              onsubmit="return confirm('Excluir este brinde e todas as suas fotos? Esta ação não pode ser desfeita.');">
            <?= csrf_field() ?>
            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($brinde['descricao'])): ?>
<div class="card shadow-sm mb-3"><div class="card-body py-2 small"><?= nl2br(e($brinde['descricao'])) ?></div></div>
<?php endif; ?>

<div class="row g-3">
    <?php if ($podeVerEstoquePorLoja): ?>
    <!-- Saldo por loja -->
    <div class="col-md-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-shop me-2"></i>Estoque por loja</div>
            <div class="card-body p-0">
                <?php if (empty($estoque)): ?>
                <div class="text-muted small text-center py-3">Sem estoque em nenhuma filial.</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($estoque as $e): ?>
                    <tr>
                        <td><?= e($e['filial_nome']) ?></td>
                        <td class="text-end fw-semibold"><?= number_format((float)$e['quantidade'], 2, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Fotos -->
    <div class="<?= $podeVerEstoquePorLoja ? 'col-md-7' : 'col-12' ?>">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-camera me-2"></i>Fotos</span>
                <span class="badge bg-light text-dark border"><?= count($fotos) ?> foto(s)</span>
            </div>
            <div class="card-body">
                <?php if ($podeGerenciar): ?>
                <form method="POST" action="<?= url('brindes/' . $brinde['id'] . '/fotos') ?>"
                      enctype="multipart/form-data" class="row g-2 align-items-end mb-3">
                    <?= csrf_field() ?>
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Foto (JPG, PNG ou WEBP — máx. 10MB)</label>
                        <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Legenda (opcional)</label>
                        <input type="text" name="legenda" maxlength="255" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-upload"></i></button>
                    </div>
                </form>
                <?php endif; ?>

                <?php if (empty($fotos)): ?>
                <div class="text-muted small text-center py-3"><i class="bi bi-image me-1"></i>Nenhuma foto.</div>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($fotos as $f): ?>
                    <div class="col-6 col-lg-4">
                        <div class="border rounded overflow-hidden h-100 d-flex flex-column">
                            <a href="<?= url('brindes/fotos/' . $f['id']) ?>" target="_blank" title="Ampliar">
                                <img src="<?= url('brindes/fotos/' . $f['id']) ?>" class="w-100" style="height:110px;object-fit:cover;"
                                     alt="<?= e($f['legenda'] ?? 'Foto do brinde') ?>">
                            </a>
                            <div class="p-2 small flex-grow-1">
                                <?php if (!empty($f['legenda'])): ?>
                                <div class="text-truncate" title="<?= e($f['legenda']) ?>"><?= e($f['legenda']) ?></div>
                                <?php else: ?>
                                <div class="text-muted fst-italic">Sem legenda</div>
                                <?php endif; ?>
                            </div>
                            <?php if ($podeGerenciar): ?>
                            <form method="POST" action="<?= url('brindes/fotos/' . $f['id'] . '/excluir') ?>"
                                  onsubmit="return confirm('Excluir esta foto?');">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger w-100 rounded-0 border-0 border-top">
                                    <i class="bi bi-trash me-1"></i>Excluir
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
