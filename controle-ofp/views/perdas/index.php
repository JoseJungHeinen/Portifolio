<?php
$titulos = [
    'perdida_prazo' => ['Registradas e não abertas no prazo', 'O prazo de abertura no CSPS venceu há mais de 5 dias.'],
    'encerrada'     => ['Aprovadas sem NF de devolução em 30 dias', 'O manual considera o processo sem validade após 30 dias da aprovação.'],
];
?>
<h1 class="titulo h3 mb-1">Perdas <span>sugeridas</span></h1>
<p class="text-muted small mb-3">Confira e confirme com o motivo. Uma OFP que ainda tem salvação deve ser movida para a etapa certa na própria tela da OFP.</p>

<?php foreach ($grupos as $tipo => $ofps): ?>
    <form class="card card-body mb-4" method="post" action="<?= url('/perdas/confirmar') ?>">
        <?= csrf_field() ?>
        <h2 class="h6 mb-0"><?= e($titulos[$tipo][0]) ?> <span class="badge text-bg-secondary"><?= (int)$totais[$tipo] ?></span></h2>
        <p class="small text-muted"><?= e($titulos[$tipo][1]) ?></p>
        <?php if ($totais[$tipo] > count($ofps)): ?><p class="small text-warning">mostrando <?= count($ofps) ?> de <?= (int)$totais[$tipo] ?></p><?php endif; ?>
        <?php if ($ofps): ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th></th><th>Nº OFP</th><th>Unidade</th><th>Causal</th><th>Peça</th><th class="text-end">Em aberto</th><th>Prazo</th><th>Responsável</th></tr></thead>
                    <tbody>
                    <?php foreach ($ofps as $o): ?>
                        <tr>
                            <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= e($o['id']) ?>" aria-label="Selecionar"></td>
                            <td><a href="<?= url('/ofps/' . $o['id']) ?>"><?= e($o['numero_ofp'] ?? 'sem nº') ?></a></td>
                            <td><?= e(str_replace('Eurovale - ', '', $o['unidade_nome'])) ?></td>
                            <td><?= e($o['causal_codigo']) ?></td>
                            <td><?= e($o['desenho']) ?></td>
                            <td class="text-end"><?= money((float)$o['aberto_peca'] + (float)$o['aberto_st']) ?></td>
                            <td><?= semaforo_badge($o['prazo_data']) ?></td>
                            <td><?= e($o['responsavel_nome'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label" for="motivo-<?= e($tipo) ?>">Motivo *</label>
                    <select class="form-select" id="motivo-<?= e($tipo) ?>" name="motivo_id" required>
                        <option value="">Selecione…</option>
                        <?php foreach ($motivos[$tipo] ?? [] as $m): ?><option value="<?= e($m['id']) ?>"><?= e($m['descricao']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5"><label class="form-label" for="obs-<?= e($tipo) ?>">Observação</label><input class="form-control" id="obs-<?= e($tipo) ?>" name="observacao" maxlength="2000"></div>
                <div class="col-md-2 d-grid"><button class="btn btn-primary">Confirmar perda</button><small class="text-muted text-center mt-1">até <?= (int)\App\Services\PerdaService::MAX_LOTE ?> por vez · <span class="contador">0</span> marcadas</small></div>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">Nenhuma OFP nesta situação.</p>
        <?php endif; ?>
    </form>
<?php endforeach; ?>
<script>
document.querySelectorAll('form[action$="/perdas/confirmar"]').forEach(f => {
    const max = <?= (int)\App\Services\PerdaService::MAX_LOTE ?>;
    const botao = f.querySelector('button.btn-primary');
    const contador = f.querySelector('.contador');
    if (!botao || !contador) return;
    const atualizar = () => {
        const n = f.querySelectorAll('input[name="ids[]"]:checked').length;
        contador.textContent = n;
        contador.classList.toggle('text-danger', n > max);
        botao.disabled = n > max;
    };
    f.addEventListener('change', atualizar);
    atualizar();
});
</script>
