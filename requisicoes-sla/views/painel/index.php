<?php
// @versao Ver.003.301026
use App\Services\PainelComprasService as Painel;

$title = 'Painel de compras';
$barras = function (array $linhas): string {
    if (!$linhas) return '<p class="text-muted small mb-0">Nenhuma OC no período.</p>';
    $max = max(1.0, ...array_column($linhas, 'valor'));
    $h = '';
    foreach ($linhas as $l) {
        $h .= '<div class="painel-barra"><span class="text-truncate" title="' . e($l['rotulo']) . '">' . e($l['rotulo']) . '</span>'
            . '<div class="painel-trilho"><i style="width:' . number_format($l['valor'] / $max * 100, 1, '.', '') . '%"></i></div>'
            . '<span class="text-end text-nowrap">' . e(money($l['valor'])) . '</span></div>';
    }
    return $h;
};
$sevClasse = ['bad' => 'danger', 'warn' => 'warning', 'info' => 'primary'];
$sevIcone  = ['bad' => 'bi-exclamation-octagon-fill', 'warn' => 'bi-exclamation-triangle-fill', 'info' => 'bi-info-circle-fill'];
?>
<style>
.painel-kpi { border-left: 4px solid var(--bs-gray-400); }
.painel-kpi.ok  { border-left-color: var(--bs-success); }
.painel-kpi.nok { border-left-color: var(--bs-danger); }
.painel-kpi .valor { font-size: 1.6rem; line-height: 1.1; }
.painel-etapa { border-top: 4px solid var(--bs-gray-400); }
.painel-etapa.fase1 { border-top-color: #0d6efd; }
.painel-etapa.fase2 { border-top-color: #fd7e14; }
.painel-etapa.fase3 { border-top-color: #198754; }
.painel-etapa .n { font-size: 1.5rem; }
.painel-barra { display: grid; grid-template-columns: minmax(0, 11rem) 1fr auto; gap: .5rem; align-items: center; font-size: .85rem; margin-bottom: .4rem; }
.painel-trilho { background: var(--bs-gray-200); border-radius: 4px; height: .7rem; overflow: hidden; }
.painel-trilho i { display: block; height: 100%; background: var(--color-primary, #c00); }
.painel-alerta { border-left: 4px solid; }
.painel-alerta.danger  { border-left-color: var(--bs-danger); }
.painel-alerta.warning { border-left-color: var(--bs-warning); }
.painel-alerta.primary { border-left-color: var(--bs-primary); }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="mb-0"><i class="bi bi-speedometer2 me-2"></i>Painel de compras</h4>
    <form method="GET" action="<?= url('painel') ?>" class="d-flex flex-wrap gap-2">
        <select name="per" aria-label="Período" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <?php foreach (Painel::PERIODOS as $k => $rotulo): ?>
            <option value="<?= e($k) ?>" <?= $per === $k ? 'selected' : '' ?>><?= e($rotulo) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($todas): ?>
        <select name="filial" aria-label="Unidade" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="">Todas as unidades</option>
            <?php foreach ($filiais as $f): ?>
            <option value="<?= e($f['id']) ?>" <?= $filialId === $f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <span class="form-control form-control-sm bg-light" style="width:auto"><?= e($filiais[0]['nome'] ?? 'Sua unidade') ?></span>
        <?php endif; ?>
        <noscript><button class="btn btn-sm btn-outline-secondary">Filtrar</button></noscript>
    </form>
</div>

<p class="text-muted small mb-2">
    Indicadores das OCs emitidas <?= $inicio ? 'desde ' . e(date('d/m/Y', strtotime($inicio))) : 'em todo o histórico' ?>
    (OCs canceladas ficam fora).
</p>

<div class="row g-3 mb-4" id="painel-kpis">
    <?php foreach ($painel['kpis'] as $k): ?>
    <div class="col-12 col-sm-6 col-xl">
        <div class="card shadow-sm h-100 painel-kpi <?= $k['ok'] === true ? 'ok' : ($k['ok'] === false ? 'nok' : '') ?>">
            <div class="card-body">
                <div class="small text-muted mb-1"><i class="bi <?= e($k['icone']) ?> me-1"></i><?= e($k['nome']) ?></div>
                <div class="valor fw-semibold mb-1"><?= $k['valor'] === null ? '—' : e($k['valor']) ?></div>
                <div class="small text-muted"><?= e($k['meta']) ?></div>
                <?php if ($k['ok'] === null): ?>
                    <div class="small text-muted mt-1">Sem dados no período</div>
                <?php else: ?>
                    <div class="small mt-1 fw-semibold <?= $k['ok'] ? 'text-success' : 'text-danger' ?>">
                        <?= $k['ok'] ? 'Dentro da meta' : 'Fora da meta' ?> · <?= e($k['base']) ?>
                    </div>
                <?php endif; ?>
                <?php if ($k['nota']): ?><div class="small fst-italic text-muted mt-1"><?= e($k['nota']) ?></div><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm mb-4" id="painel-pipeline">
    <div class="card-header bg-white fw-semibold">Onde estão os pedidos abertos
        <span class="text-muted fw-normal small">· etapa atual do fluxograma, todos, sem filtro de período</span></div>
    <div class="card-body">
        <?php $totalAbertos = array_sum(array_column($painel['pipeline'], 'n')); ?>
        <div class="row g-2">
            <?php foreach ($painel['pipeline'] as $etapa):
                $rot = str_pad((string)$etapa['numero'], 2, '0', STR_PAD_LEFT) . ' ' . $etapa['rotulo'];
                $partes = [];
                if ($etapa['sol']) $partes[] = $etapa['sol'] . ' SOL';
                if ($etapa['req']) $partes[] = $etapa['req'] . ' req. sem SOL';
            ?>
            <div class="col-6 col-md-4 col-lg-3 col-xxl-2">
                <div class="border rounded p-2 h-100 painel-etapa fase<?= (int)$etapa['fase'] ?><?= $etapa['n'] ? '' : ' text-muted' ?>">
                    <?php if ($etapa['link'] && $etapa['n']): ?>
                        <a href="<?= e(url($etapa['link'])) ?>" class="text-reset text-decoration-none d-block">
                            <div class="n fw-semibold"><?= (int)$etapa['n'] ?></div>
                            <div class="small"><?= e($rot) ?> <i class="bi bi-arrow-right-short"></i></div>
                        </a>
                    <?php else: ?>
                        <div class="n fw-semibold"><?= (int)$etapa['n'] ?></div>
                        <div class="small"><?= e($rot) ?></div>
                    <?php endif; ?>
                    <?php if ($partes): ?><div class="small text-muted"><?= e(implode(' · ', $partes)) ?></div><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="small text-muted mt-2 mb-0">
            <?= $totalAbertos ? $totalAbertos . ' pedido' . ($totalAbertos === 1 ? '' : 's') . ' em andamento.' : 'Sem dados: nenhum pedido em andamento.' ?>
            Conta cada SOL aberta (e cada requisição de compra sem SOL) uma vez, na etapa em que está agora;
            concluídos, cancelados e recusados ficam fora.
        </p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" id="painel-alertas">
            <div class="card-header bg-white d-flex justify-content-between">
                <span class="fw-semibold">Precisa de atenção</span>
                <span class="text-muted small"><?= $painel['alertas']['total'] ?> ite<?= $painel['alertas']['total'] === 1 ? 'm' : 'ns' ?></span>
            </div>
            <div class="card-body">
                <?php if (!$painel['alertas']['itens']): ?>
                    <p class="text-muted mb-0">Nada fora do prazo ou das regras agora.</p>
                <?php else: ?>
                    <div class="d-grid gap-2">
                    <?php foreach ($painel['alertas']['itens'] as $a): $c = $sevClasse[$a['sev']]; ?>
                        <a href="<?= e(url($a['link'])) ?>" class="painel-alerta <?= $c ?> border rounded p-2 small text-reset text-decoration-none d-flex gap-2">
                            <i class="bi <?= $sevIcone[$a['sev']] ?> text-<?= $c ?>"></i>
                            <span><?php if ($a['ref']): ?><strong><?= e($a['ref']) ?></strong> · <?php endif; ?><?= e($a['texto']) ?></span>
                        </a>
                    <?php endforeach; ?>
                    </div>
                    <?php if ($painel['alertas']['total'] > count($painel['alertas']['itens'])): ?>
                        <p class="small text-muted mt-2 mb-0">+<?= $painel['alertas']['total'] - count($painel['alertas']['itens']) ?> outros.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" id="painel-valor">
            <div class="card-header bg-white d-flex justify-content-between">
                <span class="fw-semibold">Valor em OCs no período</span>
                <span class="fw-semibold" id="painel-valor-total"><?= e(money($painel['valor']['total'])) ?></span>
            </div>
            <div class="card-body">
                <div class="small text-muted text-uppercase mb-2">Por unidade</div>
                <?= $barras($painel['valor']['porUnidade']) ?>
                <div class="small text-muted text-uppercase mt-3 mb-2">Por finalidade fiscal</div>
                <?= $barras($painel['valor']['porFinalidade']) ?>
            </div>
        </div>
    </div>
</div>
