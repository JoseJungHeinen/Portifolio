<a class="small" href="<?= url('/financeiro') ?>">&larr; Financeiro</a>
<h1 class="titulo h3 mb-1">Conciliação <span>de devoluções</span></h1>
<p class="text-muted small mb-3">NFs de devolução emitidas (exceto canceladas e estornadas), por unidade e ano da NF.</p>
<div class="table-responsive bg-white rounded border">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Ano</th><th>Unidade</th><th class="text-end">OFPs</th><th class="text-end">NF devolução</th><th class="text-end">ICMS ST</th><th class="text-end">Creditado peça</th><th class="text-end">Creditado ST</th><th class="text-end">Diferença</th></tr></thead>
        <tbody>
        <?php foreach ($linhas as $l): ?>
            <tr>
                <td><?= (int)$l['ano'] ?></td>
                <td><?= e($l['unidade']) ?></td>
                <td class="text-end"><?= (int)$l['qtd'] ?></td>
                <td class="text-end"><?= money($l['devolucao']) ?></td>
                <td class="text-end"><?= money($l['icms_st']) ?></td>
                <td class="text-end"><?= money($l['creditado_peca']) ?></td>
                <td class="text-end"><?= money($l['creditado_st']) ?></td>
                <td class="text-end fw-semibold <?= abs($l['diferenca']) >= 0.01 ? 'text-danger' : 'text-success' ?>"><?= money($l['diferenca']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$linhas): ?><tr><td colspan="8" class="text-center text-muted py-4">Nenhuma NF de devolução registrada.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
