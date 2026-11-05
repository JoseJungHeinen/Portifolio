<?php /** @var array $ofps */ ?>
<div class="table-responsive bg-white rounded border">
    <table class="table table-hover align-middle mb-0">
        <thead>
        <tr><th>Nº OFP</th><th>Unidade</th><th>Causal</th><th>Peça</th><th class="text-end">Reclamado</th><th class="text-end">Em aberto</th><th>Status</th><th>Prazo</th><th>Responsável</th></tr>
        </thead>
        <tbody>
        <?php foreach ($ofps as $o): ?>
            <tr>
                <td><a href="<?= url('/ofps/' . $o['id']) ?>"><?= e($o['numero_ofp'] ?? 'sem nº') ?></a></td>
                <td><?= e(str_replace('Eurovale - ', '', $o['unidade_nome'])) ?></td>
                <td><span class="badge text-bg-light border"><?= e($o['causal_codigo']) ?></span></td>
                <td><?= e($o['desenho']) ?><div class="small text-muted"><?= e($o['descricao_peca'] ?? '') ?></div></td>
                <td class="text-end"><?= money($o['valor_reclamado']) ?></td>
                <td class="text-end"><?= money((float)$o['aberto_peca'] + (float)$o['aberto_st']) ?></td>
                <td><?= status_badge($o['status']) ?></td>
                <td><?= semaforo_badge($o['prazo_data']) ?></td>
                <td><?= e($o['responsavel_nome'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$ofps): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">Nenhuma OFP encontrada.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
