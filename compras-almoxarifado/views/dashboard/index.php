<?php
// @versao Ver.004.281026
$title = 'Dashboard';
$mesNome = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'][$mes - 1];
$reqPendentes = ($resumoReq['pendentes'] ?? 0) + ($resumoReq['em_aprovacao'] ?? 0);
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="fw-bold mb-0">Dashboard Executivo</h4>
        <small class="text-muted"><?= $mesNome ?>/<?= $ano ?> &mdash; <?= e($filialSelecionadaNome ?? ($user['filial_nome'] ?? 'Todas as Filiais')) ?></small>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <?php if (!empty($podeSelecionarFilial)): ?>
            <form method="GET" action="<?= url('dashboard') ?>" class="d-flex align-items-center gap-2">
                <label for="dashboardFilial" class="small text-muted mb-0">Filial</label>
                <select id="dashboardFilial" name="filial" class="form-select form-select-sm" style="min-width:220px" onchange="this.form.submit()">
                    <option value="" <?= empty($filialSelecionada) ? 'selected' : '' ?>>Todas as filiais</option>
                    <?php foreach ($filiaisDashboard as $filialOpcao): ?>
                        <option value="<?= e($filialOpcao['id']) ?>" <?= ($filialSelecionada ?? '') === $filialOpcao['id'] ? 'selected' : '' ?>>
                            <?= e($filialOpcao['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary btn-sm"
                data-bs-toggle="modal" data-bs-target="#modalPersonalizar">
            <i class="bi bi-sliders me-1"></i>Personalizar
        </button>
        <span class="badge bg-light text-dark border fs-6 py-2 px-3">
            <i class="bi bi-calendar3 me-1"></i><?= date('d/m/Y') ?>
        </span>
    </div>
</div>

<?php if ($prefs['kpis']): ?>
<!-- ══ KPIs TOPO ══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card kpi-blue">
            <div class="kpi-icon"><i class="bi bi-check2-circle"></i></div>
            <div class="kpi-label">Aprovado no Mês</div>
            <div class="kpi-value"><?= money($totalAprovadoMes) ?></div>
            <div class="kpi-sub text-muted small">Total aprovado — <?= $mesNome ?>/<?= $ano ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card kpi-orange">
            <div class="kpi-icon"><i class="bi bi-file-earmark-check"></i></div>
            <div class="kpi-label">Req. Aprovadas</div>
            <div class="kpi-value"><?= $totalAprovMes ?></div>
            <div class="kpi-sub text-muted small">Aprovadas em <?= $mesNome ?>/<?= $ano ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card <?= $reqPendentes > 0 ? 'kpi-orange' : 'kpi-green' ?>">
            <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
            <div class="kpi-label">Req. Pendentes</div>
            <div class="kpi-value"><?= $reqPendentes ?></div>
            <div class="kpi-sub text-muted small">Aguardando aprovação</div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card <?= count($anomalias) > 0 ? 'kpi-red' : 'kpi-green' ?>">
            <div class="kpi-icon"><i class="bi bi-shield-exclamation"></i></div>
            <div class="kpi-label">Anomalias Ativas</div>
            <div class="kpi-value"><?= count($anomalias) ?></div>
            <div class="kpi-sub text-muted small">Impacto: <?= money($totalImpactoAnomalia) ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($prefs['comparativo_filial'] && !empty($comparativoFiliais)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-0 pb-0">
        <div class="d-flex align-items-center justify-content-between">
            <h6 class="fw-bold mb-0"><i class="bi bi-buildings me-2 text-primary"></i>Comparativo por Filial - <?= $mesNome ?>/<?= $ano ?></h6>
            <span class="badge bg-light text-dark border">Visao geral</span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">Filial</th>
                        <th class="text-end">Aprovações</th>
                        <th class="text-end">Total Aprovado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $totalRealizadoFiliais = array_sum(array_column($comparativoFiliais, 'realizado'));
                foreach ($comparativoFiliais as $filialResumo):
                    $pctFilial = $totalRealizadoFiliais > 0
                        ? round((float)$filialResumo['realizado'] / $totalRealizadoFiliais * 100, 1)
                        : 0;
                ?>
                    <tr>
                        <td class="px-3 fw-semibold"><?= e($filialResumo['filial']) ?></td>
                        <td class="text-end small"><?= (int)$filialResumo['total_aprov'] ?></td>
                        <td class="text-end small fw-semibold"><?= money($filialResumo['realizado'] ?? 0) ?></td>
                        <td class="text-end pe-3">
                            <a href="<?= url('dashboard') . '?filial=' . urlencode($filialResumo['filial_id']) ?>" class="btn btn-outline-primary btn-sm">
                                Ver filial
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ VISÃO 1: ORÇAMENTO × REALIZADO ════════════════════════════════════ -->
<?php if ($prefs['grafico_historico'] || $prefs['resumo_requisicoes']): ?>
<div class="row g-3 mb-4">
    <?php if ($prefs['grafico_historico']): ?>
    <div class="col-12 col-xl-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pb-0">
                <div class="d-flex align-items-center justify-content-between">
                    <h6 class="fw-bold mb-0"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Compras — 12 Meses</h6>
                    <a href="<?= url('relatorios/compras') ?>" class="btn btn-outline-primary btn-sm">Ver relatório</a>
                </div>
            </div>
            <div class="card-body">
                <canvas id="chartHistorico" height="100"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($prefs['resumo_requisicoes']): ?>
    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pb-0">
                <h6 class="fw-bold mb-0"><i class="bi bi-receipt me-2 text-warning"></i>Resumo de Requisições</h6>
            </div>
            <div class="card-body">
                <div class="d-flex flex-column gap-3">
                    <div class="d-flex justify-content-between align-items-center p-2 rounded bg-light">
                        <span class="small fw-semibold">Total <?= $ano ?></span>
                        <span class="badge bg-secondary"><?= $resumoReq['total'] ?? 0 ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center p-2 rounded" style="background:#fff3cd">
                        <span class="small fw-semibold">Pendentes / Em aprovação</span>
                        <span class="badge bg-warning text-dark"><?= ($resumoReq['pendentes'] ?? 0) + ($resumoReq['em_aprovacao'] ?? 0) ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center p-2 rounded" style="background:#d1e7dd">
                        <span class="small fw-semibold">Aprovadas</span>
                        <span class="badge bg-success"><?= $resumoReq['aprovadas'] ?? 0 ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center p-2 rounded" style="background:#f8d7da">
                        <span class="small fw-semibold">Rejeitadas</span>
                        <span class="badge bg-danger"><?= $resumoReq['rejeitadas'] ?? 0 ?></span>
                    </div>
                    <hr class="my-1">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted">Valor aprovado (mês)</span>
                        <strong><?= money($resumoReq['valor_aprovado'] ?? 0) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted">Despesas eventuais (mês)</span>
                        <strong class="text-danger"><?= money($resumoReq['valor_eventual'] ?? 0) ?></strong>
                    </div>
                </div>
                <?php if (tiposRequisicaoPermitidos()): ?>
                <div class="d-grid mt-3">
                    <a href="<?= url('requisicoes/nova') ?>" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg me-1"></i>Nova Requisição
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ VISÃO 2: COMPRAS POR SETOR ══════════════════════════════════════════ -->
<?php if ($prefs['consumo_setor']): ?>
<?php $totalSetores = array_sum(array_column($porSetor, 'realizado')); ?>
<div class="row g-3 mb-4">
    <div class="col-12 col-xl-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pb-0">
                <div class="d-flex align-items-center justify-content-between">
                    <h6 class="fw-bold mb-0"><i class="bi bi-building me-2 text-info"></i>Compras por Setor — <?= $mesNome ?>/<?= $ano ?></h6>
                    <a href="<?= url('relatorios/compras') ?>" class="btn btn-outline-info btn-sm">Relatório</a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="px-3">Setor</th>
                                <th class="text-end">Aprovações</th>
                                <th class="text-end">Total Aprovado</th>
                                <th style="min-width:120px">Participação</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($porSetor as $s):
                            $pct = $totalSetores > 0 ? round((float)$s['realizado'] / $totalSetores * 100, 1) : 0;
                        ?>
                            <tr>
                                <td class="px-3"><?= e($s['setor']) ?></td>
                                <td class="text-end small"><?= (int)$s['total_aprov'] ?></td>
                                <td class="text-end small fw-semibold"><?= money($s['realizado']) ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height:8px">
                                            <div class="progress-bar bg-info"
                                                 style="width:<?= $pct ?>%"></div>
                                        </div>
                                        <small class="text-nowrap"><?= $pct ?>%</small>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($porSetor)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">Nenhuma compra registrada neste período.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pb-0">
                <h6 class="fw-bold mb-0"><i class="bi bi-pie-chart me-2 text-info"></i>Distribuição por Setor</h6>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <canvas id="chartSetores" style="max-height:240px"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ VISÃO 3: CONTRATOS RECORRENTES ════════════════════════════════════ -->
<?php if ($prefs['contratos'] || $prefs['eventuais']): ?>
<div class="row g-3 mb-4">
    <?php if ($prefs['contratos']): ?>
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pb-0">
                <div class="d-flex align-items-center justify-content-between">
                    <h6 class="fw-bold mb-0">
                        <i class="bi bi-file-earmark-text me-2 text-success"></i>Contratos Recorrentes
                        <span class="badge bg-success ms-1"><?= money($totalRecorr) ?>/mês</span>
                    </h6>
                    <a href="<?= url('contratos') ?>" class="btn btn-outline-success btn-sm">Ver todos</a>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($vencendo30d)): ?>
                    <div class="alert alert-warning m-3 py-2 mb-0">
                        <i class="bi bi-clock me-1"></i>
                        <strong><?= count($vencendo30d) ?> contrato(s)</strong> vencendo em 30 dias —
                        <a href="<?= url('contratos') ?>">revisar agora</a>
                    </div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="px-3">Fornecedor</th>
                                <th class="text-end">Valor/mês</th>
                                <th>Vencimento</th>
                                <th>Reajuste</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($contratos as $c): ?>
                            <?php $dias = (int)($c['dias_restantes'] ?? 99); ?>
                            <tr>
                                <td class="px-3 small"><?= e($c['fornecedor_nome']) ?></td>
                                <td class="text-end small fw-semibold"><?= money($c['valor_mensal']) ?></td>
                                <td class="small">
                                    <span class="<?= $dias <= 30 ? 'text-danger fw-bold' : ($dias <= 60 ? 'text-warning fw-semibold' : 'text-muted') ?>">
                                        <?= date_br($c['data_fim']) ?>
                                        <?php if ($dias <= 30): ?><i class="bi bi-exclamation-circle ms-1"></i><?php endif; ?>
                                    </span>
                                </td>
                                <td class="small text-muted"><?= e($c['reajuste_indexador'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($contratos)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">Nenhum contrato ativo.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ VISÃO 4: DESPESAS EVENTUAIS ════════════════════════════════════ -->
    <?php if ($prefs['eventuais']): ?>
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pb-0">
                <div class="d-flex align-items-center justify-content-between">
                    <h6 class="fw-bold mb-0">
                        <i class="bi bi-lightning-charge me-2 text-danger"></i>Despesas Eventuais
                        <span class="badge bg-danger ms-1"><?= money($resumoReq['valor_eventual'] ?? 0) ?></span>
                    </h6>
                    <a href="<?= url('requisicoes?tipo_despesa=eventual') ?>" class="btn btn-outline-danger btn-sm">Ver todas</a>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($eventuais)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="px-3">Solicitante / Setor</th>
                                    <th class="text-end">Valor</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($eventuais as $ev): ?>
                                <tr>
                                    <td class="px-3 small">
                                        <div class="fw-semibold"><?= e($ev['solicitante_nome'] ?? '—') ?></div>
                                        <small class="text-muted"><?= e($ev['setor_nome'] ?? '—') ?></small>
                                    </td>
                                    <td class="text-end small fw-semibold text-danger"><?= money($ev['valor_total']) ?></td>
                                    <td><?= status_badge($ev['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-check-circle-fill text-success fs-3 d-block mb-2"></i>
                        Nenhuma despesa eventual este mês.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ ANOMALIAS ══════════════════════════════════════════════════════════ -->
<?php if ($prefs['anomalias'] && !empty($anomalias)): ?>
<div class="card border-0 shadow-sm border-start border-danger border-3 mb-4">
    <div class="card-header bg-white border-0 pb-0">
        <div class="d-flex align-items-center justify-content-between">
            <h6 class="fw-bold mb-0 text-danger">
                <i class="bi bi-shield-exclamation me-2"></i>
                Oportunidades de Economia Detectadas
                <span class="badge bg-danger ms-1">Impacto: <?= money($totalImpactoAnomalia) ?></span>
            </h6>
            <a href="<?= url('relatorios/saving?aba=anomalias') ?>" class="btn btn-outline-danger btn-sm">Ver todas</a>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-2">
        <?php foreach (array_slice($anomalias, 0, 4) as $a): ?>
            <div class="col-md-6">
                <div class="anomalia-card">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold small"><?= e($a['titulo']) ?></div>
                            <div class="text-muted" style="font-size:.8rem"><?= e(mb_strimwidth($a['descricao'], 0, 100, '...')) ?></div>
                        </div>
                        <span class="badge bg-danger text-nowrap"><?= money($a['valor_impacto']) ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ MODAL PERSONALIZAR DASHBOARD ════════════════════════════════════ -->
<div class="modal fade" id="modalPersonalizar" tabindex="-1"
     aria-labelledby="modalPersonalizarLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalPersonalizarLabel">
                    <i class="bi bi-sliders me-2 text-primary"></i>Personalizar Dashboard
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form method="POST" action="<?= url('dashboard/preferencias') ?>">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Marque os blocos que deseja exibir:</p>
                    <div class="d-flex flex-column gap-2">
                        <?php
                        $widgetLabels = [
                            'kpis'               => ['bi-check2-circle',      'KPIs do topo (Aprovado, Req. Aprovadas, Pendentes, Anomalias)'],
                            'grafico_historico'  => ['bi-bar-chart-line',    'Gráfico Compras — 12 meses'],
                            'resumo_requisicoes' => ['bi-receipt',           'Resumo de Requisições'],
                            'consumo_setor'      => ['bi-building',          'Compras por Setor'],
                            'comparativo_filial' => ['bi-buildings',         'Comparativo por Filial'],
                            'contratos'          => ['bi-file-earmark-text', 'Contratos Recorrentes'],
                            'eventuais'          => ['bi-lightning-charge',  'Despesas Eventuais'],
                            'anomalias'          => ['bi-shield-exclamation','Anomalias Ativas'],
                        ];
                        foreach ($widgetLabels as $key => [$icon, $label]):
                            if (in_array($key, $widgetsBloqueados ?? [], true)) continue;
                        ?>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox"
                                   name="widgets[<?= $key ?>]" id="pref_<?= $key ?>"
                                   value="1" <?= $prefs[$key] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="pref_<?= $key ?>">
                                <i class="bi <?= $icon ?> me-1 text-muted"></i><?= $label ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-floppy me-1"></i>Salvar Preferências
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ── Gráfico Histórico 12 meses ──────────────────────────────────────────
const ctxHistorico = document.getElementById('chartHistorico');
if (ctxHistorico) {
    new Chart(ctxHistorico, {
        type: 'bar',
        data: {
            labels: <?= json_encode($labelsHistorico, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
            datasets: [
                {
                    label: 'Compras',
                    data: <?= json_encode($realizadoHistorico, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    backgroundColor: 'rgba(42,95,158,0.7)',
                    borderColor: 'rgba(42,95,158,1)',
                    borderWidth: 0,
                    borderRadius: 4,
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: ctx => 'R$ ' + ctx.raw.toLocaleString('pt-BR', {minimumFractionDigits:2})
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: v => 'R$ ' + (v/1000).toFixed(0) + 'K'
                    }
                }
            }
        }
    });
}

// ── Gráfico Setores (Donut) ─────────────────────────────────────────────
const ctxSetores = document.getElementById('chartSetores');
if (ctxSetores) {
    const setorLabels    = <?= json_encode(array_column($porSetor, 'setor'), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const setorRealizado = <?= json_encode(array_map(fn($s) => (float)$s['realizado'], $porSetor), JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    const colors = ['#1a3c5e','#2a5f9e','#3d8ef7','#5ba3fb','#8cc0fd','#b8d8fe',
                    '#e63946','#f4a261','#2a9d8f','#264653','#6c757d','#adb5bd',
                    '#198754','#ffc107','#dc3545'];

    if (!setorRealizado.some(v => v > 0)) {
        ctxSetores.closest('.card-body').innerHTML =
            '<div class="d-flex flex-column align-items-center justify-content-center text-muted py-5">' +
            '<i class="bi bi-pie-chart fs-1 mb-2 opacity-25"></i>' +
            '<span class="small">Nenhuma compra registrada neste período.</span></div>';
    } else {
        new Chart(ctxSetores, {
            type: 'doughnut',
            data: {
                labels: setorLabels,
                datasets: [{
                    data: setorRealizado,
                    backgroundColor: colors.slice(0, setorLabels.length),
                    borderWidth: 2,
                    hoverOffset: 8,
                }]
            },
            options: {
                responsive: true,
                cutout: '65%',
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: ctx => ctx.label + ': R$ ' + ctx.raw.toLocaleString('pt-BR', {minimumFractionDigits:2})
                        }
                    }
                }
            }
        });
    }
}
</script>
