<?php
use App\Services\StatusService;

$base = array_filter(['unidade_id' => $filtros['unidade_id'], 'causal_id' => $filtros['causal_id'], 'ano' => $filtros['ano']]);
$link = fn(array $extra) => url('/ofps?' . http_build_query(array_merge($base, $extra)));
$sel = fn(string $campo, string $valor) => $filtros[$campo] === $valor ? 'selected' : '';
$json = fn($dados) => json_encode($dados, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="titulo h3 mb-0">Painel de <span>OFP</span></h1>
    <form class="d-flex flex-wrap gap-2" method="get" action="<?= url('/painel') ?>">
        <select class="form-select form-select-sm w-auto" name="unidade_id" aria-label="Unidade">
            <option value="">Todas as unidades</option>
            <?php foreach ($unidades as $u): ?><option value="<?= e($u['id']) ?>" <?= $sel('unidade_id', $u['id']) ?>><?= e($u['nome']) ?></option><?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm w-auto" name="causal_id" aria-label="Causal">
            <option value="">Todos os causais</option>
            <?php foreach ($causais as $c): ?><option value="<?= e($c['id']) ?>" <?= $sel('causal_id', $c['id']) ?>><?= e($c['codigo']) ?></option><?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm w-auto" name="ano" aria-label="Ano">
            <option value="">Todos os anos</option>
            <?php foreach ($anos as $ano): ?><option value="<?= $ano ?>" <?= $sel('ano', (string)$ano) ?>><?= $ano ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-dark">Aplicar</button>
    </form>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['Em aberto — peças', money($cards['aberto_peca']), $link(['status' => 'ativos']), ''],
        ['Em aberto — ICMS ST', money($cards['aberto_st']), $link(['status' => 'ativos']), ''],
        ['OFPs ativas', (string)$cards['ativas'], $link(['status' => 'ativos']), ''],
        ['Com prazo vencido', (string)$cards['vencidas'], $link(['status' => 'ativos', 'semaforo' => 'vermelho']), $cards['vencidas'] > 0 ? 'text-danger' : ''],
        ['Provável perda (incluso no em aberto)', money($cards['provavel_perda']), $link(['status' => 'ativos', 'sugestao' => 'qualquer']), $cards['provavel_perda'] > 0 ? 'text-warning' : ''],
        ['Creditado no mês', money($cards['creditado_mes']), $link(['status' => 'creditada']), 'text-success'],
    ] as [$rotulo, $valor, $href, $classe]): ?>
        <div class="col-6 col-lg">
            <div class="card card-body kpi h-100"><a href="<?= $href ?>">
                <div class="small text-muted"><?= e($rotulo) ?></div>
                <div class="valor <?= $classe ?>"><?= e($valor) ?></div>
            </a></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card card-body mb-3">
    <h2 class="h6">Valor em aberto por status e unidade</h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Status</th><?php foreach ($matriz['unidades'] as $nome): ?><th class="text-end"><?= e(str_replace('Eurovale - ', '', $nome)) ?></th><?php endforeach; ?><th class="text-end">Total</th></tr></thead>
            <tbody>
            <?php foreach ($matriz['linhas'] as $status => $l): ?>
                <tr>
                    <td><a href="<?= $link(['status' => $status]) ?>"><?= e(StatusService::LABELS[$status]) ?></a></td>
                    <?php foreach ($matriz['unidades'] as $uid => $nome): $cel = $l['celulas'][$uid] ?? null; ?>
                        <td class="text-end"><?php if ($cel): ?><a href="<?= $link(['status' => $status, 'unidade_id' => $uid]) ?>"><?= money($cel['valor']) ?></a><div class="small text-muted"><?= $cel['qtd'] ?> OFP</div><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                    <?php endforeach; ?>
                    <td class="text-end fw-semibold"><?= money($l['total']) ?><div class="small text-muted"><?= $l['qtd'] ?> OFP</div></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$matriz['linhas']): ?><tr><td colspan="<?= count($matriz['unidades']) + 2 ?>" class="text-center text-muted py-3">Nenhuma OFP ativa.</td></tr><?php endif; ?>
            </tbody>
            <tfoot><tr class="fw-semibold"><td>Total</td><?php foreach ($matriz['totalColunas'] as $valor): ?><td class="text-end"><?= money($valor) ?></td><?php endforeach; ?><td class="text-end"><?= money($matriz['total']) ?></td></tr></tfoot>
        </table>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6"><div class="card card-body h-100"><h2 class="h6">Idade do valor em aberto <span class="small text-muted fw-normal">(clique na barra)</span></h2><canvas id="graf-aging" height="220"></canvas></div></div>
    <div class="col-lg-6"><div class="card card-body h-100"><h2 class="h6">Reclamado × creditado — últimos 12 meses</h2><canvas id="graf-evolucao" height="220"></canvas></div></div>
</div>

<div class="card card-body">
    <h2 class="h6">Causais com mais valor em aberto</h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Causal</th><th class="text-end">OFPs</th><th class="text-end">Decididas</th><th class="text-end">Rejeitadas</th><th class="text-end">Taxa de rejeição</th><th class="text-end">Em aberto</th></tr></thead>
            <tbody>
            <?php foreach ($topCausais as $c): ?>
                <tr>
                    <td><a href="<?= $link(['causal_id' => $c['id'], 'status' => 'todos']) ?>"><?= e($c['codigo'] . ' — ' . $c['descricao']) ?></a></td>
                    <td class="text-end"><?= (int)$c['total'] ?></td>
                    <td class="text-end"><?= (int)$c['decididas'] ?></td>
                    <td class="text-end"><?= (int)$c['rejeitadas'] ?></td>
                    <td class="text-end"><?= number_format($c['taxa_rejeicao'], 1, ',', '.') ?>%</td>
                    <td class="text-end"><a href="<?= $link(['causal_id' => $c['id'], 'status' => 'ativos']) ?>"><?= money($c['aberto']) ?></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$topCausais): ?><tr><td colspan="6" class="text-center text-muted py-3">Sem dados.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card card-body mt-3">
    <h2 class="h6">Perdas por motivo <span class="small text-muted fw-normal">(rejeitadas finais, perdidas no prazo e encerradas; cancelamentos não entram)</span></h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Status</th><th>Motivo</th><th class="text-end">OFPs</th><th class="text-end">Valor</th></tr></thead>
            <tbody>
            <?php foreach ($perdas as $l): ?>
                <tr><td><?= e(StatusService::LABELS[$l['status']] ?? $l['status']) ?></td><td><?= e($l['motivo']) ?></td><td class="text-end"><?= (int)$l['qtd'] ?></td><td class="text-end"><?= money($l['valor']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$perdas): ?><tr><td colspan="4" class="text-center text-muted py-3">Sem perdas no filtro.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(() => {
    const aging = <?= $json($aging) ?>;
    const evolucao = <?= $json($evolucao) ?>;
    const linkAging = <?= $json($link(['status' => 'ativos'])) ?>;
    const reais = v => Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    new Chart(document.getElementById('graf-aging'), {
        type: 'bar',
        data: { labels: aging.map(a => `${a.faixa} dias (${a.qtd})`), datasets: [{ label: 'Em aberto', data: aging.map(a => a.valor), backgroundColor: ['#888888', '#FF8A2D', '#B3261E', '#141922'] }] },
        options: {
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => reais(c.parsed.y) } } },
            scales: { y: { ticks: { callback: v => reais(v) } } },
            onClick: (e, els) => { if (els.length) location.href = linkAging + '&aging=' + encodeURIComponent(aging[els[0].index].faixa); },
        },
    });
    new Chart(document.getElementById('graf-evolucao'), {
        type: 'line',
        data: { labels: evolucao.map(m => m.mes), datasets: [
            { label: 'Reclamado', data: evolucao.map(m => m.reclamado), borderColor: '#2347C8', backgroundColor: '#2347C8', tension: .25 },
            { label: 'Creditado', data: evolucao.map(m => m.creditado), borderColor: '#00A960', backgroundColor: '#00A960', tension: .25 },
        ] },
        options: { plugins: { tooltip: { callbacks: { label: c => `${c.dataset.label}: ${reais(c.parsed.y)}` } } }, scales: { y: { ticks: { callback: v => reais(v) } } } },
    });
})();
</script>
