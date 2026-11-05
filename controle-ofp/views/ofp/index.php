<?php
use App\Services\StatusService;
$sel = fn(string $campo, string $valor) => ($filtros[$campo] ?? '') === $valor ? 'selected' : '';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="titulo h3 mb-0">OFPs <span>em controle</span></h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('/ofps/exportar?' . http_build_query($filtros))) ?>"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</a>
        <?php if (can(['estoque', 'comprador', 'gerente', 'admin'])): ?>
            <a class="btn btn-primary" href="<?= url('/ofps/nova') ?>"><i class="bi bi-plus-lg"></i> Nova OFP</a>
        <?php endif; ?>
    </div>
</div>

<form class="card card-body mb-3" method="get" action="<?= url('/ofps') ?>">
    <div class="row g-2">
        <div class="col-md-2">
            <select class="form-select" name="status" aria-label="Status">
                <option value="ativos" <?= $sel('status', 'ativos') ?>>Todas ativas</option>
                <option value="todos" <?= $sel('status', 'todos') ?>>Todos os status</option>
                <?php foreach (StatusService::LABELS as $chave => $rotulo): ?>
                    <option value="<?= $chave ?>" <?= $sel('status', $chave) ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select class="form-select" name="unidade_id" aria-label="Unidade">
                <option value="">Todas as unidades</option>
                <?php foreach ($unidades as $u): ?><option value="<?= e($u['id']) ?>" <?= $sel('unidade_id', $u['id']) ?>><?= e($u['nome']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select class="form-select" name="causal_id" aria-label="Causal">
                <option value="">Todos os causais</option>
                <?php foreach ($causais as $c): ?><option value="<?= e($c['id']) ?>" <?= $sel('causal_id', $c['id']) ?>><?= e($c['codigo'] . ' — ' . $c['descricao']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select class="form-select" name="semaforo" aria-label="Prazo">
                <option value="">Qualquer prazo</option>
                <?php foreach (['vermelho' => 'Vencidas', 'amarelo' => 'Vencendo (até 3 dias)', 'verde' => 'No prazo', 'sem_prazo' => 'Sem prazo'] as $chave => $rotulo): ?>
                    <option value="<?= $chave ?>" <?= $sel('semaforo', $chave) ?>><?= $rotulo ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1"><input class="form-control" aria-label="Ano" name="ano" inputmode="numeric" placeholder="Ano" value="<?= e($filtros['ano']) ?>"></div>
        <div class="col-md-2"><input class="form-control" aria-label="Busca" name="q" placeholder="Nº OFP, desenho ou NF" value="<?= e($filtros['q']) ?>"></div>
        <div class="col-md-1 d-grid"><button class="btn btn-dark">Filtrar</button></div>
    </div>
    <input type="hidden" name="aging" value="<?= e($filtros['aging']) ?>">
    <input type="hidden" name="responsavel_id" value="<?= e($filtros['responsavel_id']) ?>">
    <input type="hidden" name="sugestao" value="<?= e($filtros['sugestao']) ?>">
</form>

<p class="text-muted small mb-2"><?= $total ?> OFP(s) encontrada(s)<?= isset(\App\Models\Ofp::FAIXAS_IDADE[$filtros['aging']]) ? ' · idade ' . e($filtros['aging']) . ' dias' : '' ?></p>
<?php if ($filtros['sugestao'] !== ''): ?>
    <p class="mb-2"><span class="badge text-bg-warning">Sugestão de perda: <?= e(['qualquer' => 'qualquer', 'perdida_prazo' => 'perdida no prazo', 'encerrada' => 'encerrada sem NF'][$filtros['sugestao']] ?? $filtros['sugestao']) ?>
        <a class="text-reset ms-1 text-decoration-none" aria-label="Remover filtro de sugestão" href="<?= e(url('/ofps?' . http_build_query(array_merge($filtros, ['sugestao' => '']))) ) ?>">&times;</a></span></p>
<?php endif; ?>
<?php include ROOT . '/views/ofp/_tabela.php'; ?>

<?php if ($paginas > 1): ?>
    <nav class="mt-3" aria-label="Paginação"><ul class="pagination pagination-sm flex-wrap">
        <?php for ($p = max(1, $pagina - 5); $p <= min($paginas, $pagina + 5); $p++): ?>
            <li class="page-item <?= $p === $pagina ? 'active' : '' ?>"><a class="page-link" href="<?= e(url('/ofps?' . http_build_query(array_merge($filtros, ['pagina' => $p])))) ?>"><?= $p ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>
