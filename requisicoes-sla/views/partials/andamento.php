<?php
// @versao Ver.001.301026
/**
 * Barra de andamento (11 etapas do fluxograma). Spec: docs/2026-10-30-sistema-compras-proprio.md
 * Uso: $andamento = AndamentoService::calcular(...) / daSolicitacao() / daRequisicao();
 *      $andamentoCompacto = true para a versão de lista; depois include deste arquivo.
 */
$andamentoCompacto = $andamentoCompacto ?? false;
if (empty($andamento['etapas'])) return;

$andCores = [
    'concluida'     => ['#198754', 'Concluída'],
    'atual'         => ['#0d6efd', 'Etapa atual'],
    'pendente'      => ['#ced4da', 'Pendente'],
    'nao_se_aplica' => ['#adb5bd', 'Não se aplica'],
];
if (!defined('ANDAMENTO_CSS')):
    define('ANDAMENTO_CSS', true); ?>
<style>
.andamento-etapas { display:flex; flex-wrap:wrap; gap:.5rem .4rem; list-style:none; padding:0; margin:0; }
.andamento-etapas li { flex:1 1 6.8rem; min-width:6.8rem; border-top:5px solid var(--and-cor); padding-top:.35rem; font-size:.78rem; line-height:1.2; }
.andamento-etapas li .and-num { display:inline-flex; align-items:center; justify-content:center; width:1.35rem; height:1.35rem;
    border-radius:50%; font-size:.7rem; font-weight:600; color:#fff; background:var(--and-cor); margin-right:.25rem; flex:none; }
.andamento-etapas li.and-pendente .and-num { color:#495057; }
.andamento-etapas li.and-atual { font-weight:600; }
.andamento-etapas li.and-pendente, .andamento-etapas li.and-nao_se_aplica { color:#6c757d; }
.andamento-etapas li.and-nao_se_aplica,
.andamento-mini .and-seg.and-nao_se_aplica {
    border-image: repeating-linear-gradient(45deg, #adb5bd 0 4px, #f1f3f5 4px 8px) 5;
}
.andamento-mini { display:inline-flex; align-items:center; gap:.4rem; white-space:nowrap; font-size:.75rem; }
.andamento-mini .and-segs { display:inline-flex; gap:2px; }
.andamento-mini .and-seg { display:inline-block; width:7px; height:8px; border-radius:1px; background:var(--and-cor); }
.andamento-mini .and-seg.and-nao_se_aplica { background:repeating-linear-gradient(45deg, #adb5bd 0 2px, #f1f3f5 2px 4px); }
</style>
<?php endif;

if ($andamentoCompacto):
    $andTitulo = implode("\n", array_map(fn($et) => sprintf('%02d %s — %s', $et['numero'], $et['rotulo'],
        $andCores[$et['estado']][1] . ($et['detalhe'] ? ' (' . $et['detalhe'] . ')' : '')), $andamento['etapas'])); ?>
<span class="andamento-mini" title="<?= e($andTitulo) ?>">
    <span class="and-segs" aria-hidden="true"><?php foreach ($andamento['etapas'] as $et): ?><span class="and-seg and-<?= e($et['estado']) ?>" style="--and-cor:<?= $andCores[$et['estado']][0] ?>"></span><?php endforeach; ?></span>
    <span class="<?= $andamento['interrompida'] ? 'text-danger' : ($andamento['concluida'] ? 'text-success' : 'text-muted') ?>"><?= e($andamento['resumo']) ?></span>
</span>
<?php else: ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <span class="fw-semibold small"><i class="bi bi-signpost-split me-1"></i>Andamento da compra</span>
            <span class="small <?= $andamento['interrompida'] ? 'text-danger fw-semibold' : ($andamento['concluida'] ? 'text-success fw-semibold' : 'text-muted') ?>"><?= e($andamento['resumo']) ?></span>
        </div>
        <ol class="andamento-etapas">
            <?php foreach ($andamento['etapas'] as $et): ?>
            <li class="and-<?= e($et['estado']) ?>" style="--and-cor:<?= $andCores[$et['estado']][0] ?>" title="<?= e($andCores[$et['estado']][1]) ?>"<?= $et['estado'] === 'atual' ? ' aria-current="step"' : '' ?>>
                <div class="d-flex align-items-start">
                    <span class="and-num"><?= $et['estado'] === 'concluida' ? '<i class="bi bi-check-lg"></i>' : sprintf('%02d', $et['numero']) ?></span>
                    <span><?= e($et['rotulo']) ?><?php if ($et['estado'] === 'nao_se_aplica'): ?><br><span class="fst-italic">não se aplica</span><?php endif; ?></span>
                </div>
                <?php if ($et['detalhe']): ?><div class="text-muted mt-1" style="font-weight:400"><?= e($et['detalhe']) ?></div><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>
<?php endif; ?>
