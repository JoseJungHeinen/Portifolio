<?php
$creditadas = $situacao === 'creditadas';
$podeBaixar = !$creditadas && can(\App\Services\ConciliacaoService::PERFIS_BAIXA);
$fmt = fn($v) => number_format((float)$v, 2, ',', '.');
$base = array_filter(['unidade_id' => $filtros['unidade_id'], 'q' => $filtros['q'], 'situacao' => $creditadas ? 'creditadas' : '']);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="titulo h3 mb-0"><?= $creditadas ? 'Créditos <span>recebidos</span>' : 'Créditos <span>a receber</span>' ?></h1>
    <a class="btn btn-outline-dark" href="<?= url('/financeiro/conciliacao') ?>"><i class="bi bi-table"></i> Conciliação por unidade e ano</a>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $creditadas ? '' : 'active' ?>" href="<?= e(url('/financeiro?' . http_build_query(array_diff_key($base, ['situacao' => 1])))) ?>">Aguardando crédito</a></li>
    <li class="nav-item"><a class="nav-link <?= $creditadas ? 'active' : '' ?>" href="<?= e(url('/financeiro?' . http_build_query(['situacao' => 'creditadas'] + $base))) ?>">Creditadas</a></li>
</ul>

<form class="row g-2 mb-3" method="get" action="<?= url('/financeiro') ?>">
    <?php if ($creditadas): ?><input type="hidden" name="situacao" value="creditadas"><?php endif; ?>
    <div class="col-md-4">
        <select class="form-select" name="unidade_id" aria-label="Unidade">
            <option value="">Todas as unidades</option>
            <?php foreach ($unidades as $u): ?><option value="<?= e($u['id']) ?>" <?= $filtros['unidade_id'] === $u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4"><input class="form-control" name="q" placeholder="Nº OFP, desenho ou NF de devolução" value="<?= e($filtros['q']) ?>"></div>
    <div class="col-md-2 d-grid"><button class="btn btn-dark">Filtrar</button></div>
</form>

<?php if ($podeBaixar): ?>
    <form id="form-lote" class="card card-body mb-3" method="post" action="<?= url('/financeiro/lote') ?>">
        <?= csrf_field() ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-4"><label class="form-label" for="lote-titulo">Título Apollo</label><input class="form-control" id="lote-titulo" name="titulo_apollo" required></div>
            <div class="col-md-3"><label class="form-label" for="lote-data">Data do crédito</label><input class="form-control" id="lote-data" type="date" name="data_credito" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-5 d-grid"><button class="btn btn-primary">Baixar selecionadas pelo valor integral</button></div>
        </div>
    </form>
<?php endif; ?>

<?php if ($creditadas): ?>
<p class="text-muted small mb-2"><?= $total ?> OFP(s) creditada(s), da baixa mais recente para a mais antiga.</p>
<div class="table-responsive bg-white rounded border">
    <table class="table align-middle mb-0">
        <thead><tr>
            <th>Nº OFP</th><th>Unidade</th><th>NF devolução</th><th>Título Apollo</th><th>Data do crédito</th>
            <th class="text-end">Creditado peça</th><th class="text-end">Creditado ICMS ST</th>
        </tr></thead>
        <tbody>
        <?php foreach ($ofps as $o): ?>
            <tr>
                <td><a href="<?= url('/ofps/' . $o['id']) ?>"><?= e($o['numero_ofp'] ?? 'sem nº') ?></a></td>
                <td><?= e(str_replace('Eurovale - ', '', $o['unidade_nome'])) ?></td>
                <td><?= e($o['nf_devolucao'] ?? '—') ?></td>
                <td><?= e($o['titulo_apollo'] ?? '—') ?></td>
                <td><?= date_br($o['data_credito']) ?></td>
                <td class="text-end"><?= money($o['valor_creditado_peca']) ?></td>
                <td class="text-end"><?= money($o['valor_creditado_st']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$ofps): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhuma OFP creditada.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="table-responsive bg-white rounded border">
    <table class="table align-middle mb-0">
        <thead><tr>
            <th><?php if ($podeBaixar): ?><input type="checkbox" id="todas" class="form-check-input" aria-label="Selecionar todas desta página"><?php endif; ?></th>
            <th>Nº OFP</th><th>Unidade</th><th>NF devolução</th><th class="text-end">Aberto peça</th><th class="text-end">Aberto ICMS ST</th><th>Prazo</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($ofps as $o): ?>
            <tr>
                <td><?php if ($podeBaixar): ?><input class="form-check-input sel" type="checkbox" name="ids[]" value="<?= e($o['id']) ?>" form="form-lote" aria-label="Selecionar"><?php endif; ?></td>
                <td><a href="<?= url('/ofps/' . $o['id']) ?>"><?= e($o['numero_ofp'] ?? 'sem nº') ?></a></td>
                <td><?= e(str_replace('Eurovale - ', '', $o['unidade_nome'])) ?></td>
                <td><?= e($o['nf_devolucao'] ?? '—') ?> <span class="small text-muted"><?= date_br($o['data_nf_devolucao']) ?></span></td>
                <td class="text-end"><?= money($o['aberto_peca']) ?></td>
                <td class="text-end"><?= money($o['aberto_st']) ?></td>
                <td><?= semaforo_badge($o['prazo_data']) ?></td>
                <td class="text-end"><?php if ($podeBaixar): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#baixa"
                            data-id="<?= e($o['id']) ?>" data-numero="<?= e($o['numero_ofp'] ?? '') ?>"
                            data-peca="<?= e($fmt($o['valor_nf_devolucao'] ?? $o['valor_reclamado'])) ?>" data-st="<?= e($fmt($o['valor_icms_st'] ?? 0)) ?>">Baixar</button>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$ofps): ?><tr><td colspan="8" class="text-center text-muted py-4">Nenhuma OFP aguardando crédito. As já creditadas estão na aba "Creditadas".</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($paginas > 1): ?>
    <nav class="mt-3" aria-label="Paginação"><ul class="pagination pagination-sm flex-wrap">
        <?php for ($p = max(1, $pagina - 5); $p <= min($paginas, $pagina + 5); $p++): ?>
            <li class="page-item <?= $p === $pagina ? 'active' : '' ?>"><a class="page-link" href="<?= e(url('/financeiro?' . http_build_query($base + ['pagina' => $p]))) ?>"><?= $p ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>

<?php if ($podeBaixar): ?>
<div class="modal fade" id="baixa" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" id="form-baixa">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h2 class="modal-title h5">Baixa de crédito — OFP <span id="baixa-numero"></span></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label" for="baixa-titulo">Título Apollo *</label><input class="form-control" id="baixa-titulo" name="titulo_apollo" required></div>
                <div class="mb-3"><label class="form-label" for="baixa-data">Data do crédito *</label><input class="form-control" id="baixa-data" type="date" name="data_credito" value="<?= date('Y-m-d') ?>" required></div>
                <div class="mb-3"><label class="form-label" for="baixa-peca">Valor creditado — peça (total acumulado) *</label><input class="form-control" id="baixa-peca" name="valor_creditado_peca" inputmode="decimal" required></div>
                <div class="mb-3"><label class="form-label" for="baixa-st">Valor creditado — ICMS ST (total acumulado)</label><input class="form-control" id="baixa-st" name="valor_creditado_st" inputmode="decimal"></div>
                <div class="mb-2"><label class="form-label" for="baixa-obs">Observação</label><textarea class="form-control" id="baixa-obs" name="observacao" rows="2"></textarea></div>
                <p class="small text-muted mb-0">Se ficar faltando valor, a OFP continua aguardando crédito.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Voltar</button>
                <button class="btn btn-primary">Confirmar baixa</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('todas')?.addEventListener('change', e => document.querySelectorAll('.sel').forEach(c => { c.checked = e.target.checked; }));
document.getElementById('baixa').addEventListener('show.bs.modal', e => {
    const b = e.relatedTarget;
    document.getElementById('form-baixa').action = <?= json_encode(url('/financeiro/')) ?> + b.dataset.id + '/baixa';
    document.getElementById('baixa-numero').textContent = b.dataset.numero;
    document.getElementById('baixa-peca').value = b.dataset.peca;
    document.getElementById('baixa-st').value = b.dataset.st;
});
</script>
<?php endif; ?>
