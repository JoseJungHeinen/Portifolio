<?php
// @versao Ver.001.281026
?>
<div class="text-center py-5">
    <div class="display-1 fw-bold text-danger">403</div>
    <h4 class="mt-2 mb-3">Acesso não autorizado</h4>
    <p class="text-muted">Você não tem permissão para acessar esta página.</p>
    <a href="<?= url('dashboard') ?>" class="btn btn-primary"><?= sistema() === 'requisicoes' ? 'Voltar ao início' : 'Voltar ao Dashboard' ?></a>
</div>
