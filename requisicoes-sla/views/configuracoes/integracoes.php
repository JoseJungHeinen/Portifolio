<?php
// @versao Ver.001.140926
?>
<?php $title = 'Integrações'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<form method="POST" action="<?= url('configuracoes/integracoes') ?>">
    <?= csrf_field() ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-buildings me-2 text-primary"></i>Consulta de CNPJ (cnpj.ws)
        </div>
        <div class="card-body">
            <p class="text-muted small">
                Preenche o cadastro de fornecedor a partir do CNPJ. Sem token, usa a API pública,
                limitada a <strong>3 consultas por minuto</strong>. Com token, usa a API comercial.
            </p>

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="consulta_ativa" value="1"
                       id="consultaAtiva" <?= $consultaAtiva ? 'checked' : '' ?>>
                <label class="form-check-label" for="consultaAtiva">
                    Consulta ativa <span class="text-muted small">(desativada, o botão não aparece no cadastro)</span>
                </label>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Token da API</label>
                    <input type="password" name="token" class="form-control" autocomplete="new-password"
                           placeholder="<?= $tokenConfigurado ? 'Token configurado — preencha só para substituir' : 'Sem token — usando a API pública' ?>">
                    <div class="form-text">
                        <?php if ($tokenConfigurado): ?>
                            <span class="text-success"><i class="bi bi-check-circle me-1"></i>Token configurado.</span>
                            Deixe em branco para manter.
                        <?php else: ?>
                            Nenhum token configurado.
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Timeout</label>
                    <div class="input-group">
                        <input type="number" name="timeout_seg" class="form-control" min="2" max="30"
                               value="<?= (int)$timeoutSeg ?>">
                        <span class="input-group-text">seg</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 d-flex gap-2 align-items-center">
        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-floppy me-1"></i>Salvar
        </button>
        <?php if ($tokenConfigurado): ?>
        <button type="submit" name="remover_token" value="1" class="btn btn-outline-danger"
                onclick="return confirm('Remover o token? A consulta passa a usar a API pública, com limite de 3 por minuto.')">
            <i class="bi bi-trash me-1"></i>Remover token
        </button>
        <?php endif; ?>
    </div>
</form>
