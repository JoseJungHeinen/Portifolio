<?php
// @versao Ver.001.160926
$badge = [
    'em_aprovacao' => 'warning',
    'aprovado'     => 'success',
    'rejeitado'    => 'danger',
    'cancelado'    => 'secondary',
];
$label = [
    'em_aprovacao' => 'Em aprovação',
    'aprovado'     => 'Entregue',
    'rejeitado'    => 'Rejeitado',
    'cancelado'    => 'Cancelado',
];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-card-checklist me-2"></i>Requisições de Brinde</h4>
    <a href="<?= url('brindes/requisicao/nova') ?>" class="btn btn-success">
        <i class="bi bi-box-arrow-up me-1"></i>Nova saída
    </a>
</div>

<form method="GET" action="<?= url('brindes/requisicoes') ?>" class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md">
                <label class="form-label small text-muted mb-1">Buscar</label>
                <input type="search" name="busca" value="<?= e($filtros['busca']) ?>" class="form-control form-control-sm"
                       placeholder="Nº, cliente, vendedor, pedido ou criador">
            </div>
            <?php if ($filiaisFiltro): ?>
            <div class="col-12 col-md-2">
                <label class="form-label small text-muted mb-1">Filial</label>
                <select name="filial_id" class="form-select form-select-sm">
                    <option value="">Todas</option>
                    <?php foreach ($filiaisFiltro as $f): ?>
                    <option value="<?= e($f['id']) ?>" <?= $filtros['filial_id'] === $f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($label as $valor => $texto): ?>
                    <option value="<?= e($valor) ?>" <?= $filtros['status'] === $valor ? 'selected' : '' ?>><?= e($texto) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label small text-muted mb-1">De</label>
                <input type="date" name="data_de" value="<?= e($filtros['data_de']) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label small text-muted mb-1">Até</label>
                <input type="date" name="data_ate" value="<?= e($filtros['data_ate']) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                <?php if ($temFiltro): ?>
                <a href="<?= url('brindes/requisicoes') ?>" class="btn btn-sm btn-outline-secondary">Limpar</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<?php if (empty($requisicoes)): ?>
<div class="card text-center py-5"><div class="card-body text-muted">
    <i class="bi bi-card-checklist fs-1 d-block mb-2"></i>
    <?= $temFiltro ? 'Nenhuma requisição encontrada para esse filtro.' : 'Nenhuma requisição de brinde ainda.' ?>
</div></div>
<?php else: ?>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nº</th>
                    <th>Data</th>
                    <th>Filial</th>
                    <th>Cliente</th>
                    <th>Vendedor</th>
                    <th class="text-center">Itens</th>
                    <th class="text-end">Valor</th>
                    <th>Status</th>
                    <th>Criado por</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($requisicoes as $r): ?>
                <?php
                    $numFormatado = 'BRN-' . date('Y', strtotime($r['criado_em'])) . '-'
                        . str_pad((string)($r['numero'] ?? 0), 5, '0', STR_PAD_LEFT);
                ?>
                <tr>
                    <td class="text-nowrap"><span class="badge bg-light text-dark border"><?= e($numFormatado) ?></span></td>
                    <td class="text-nowrap">
                        <?= date('d/m/Y', strtotime($r['criado_em'])) ?>
                        <div class="small text-muted"><?= date('H:i', strtotime($r['criado_em'])) ?></div>
                    </td>
                    <td><?= e($r['filial_nome']) ?></td>
                    <td><?= e($r['cliente_nome']) ?></td>
                    <td><?= e($r['vendedor_nome']) ?></td>
                    <td class="text-center"><?= (int)$r['total_itens'] ?></td>
                    <td class="text-end"><?= number_format((float)$r['valor_total'], 2, ',', '.') ?></td>
                    <td>
                        <span class="badge bg-<?= $badge[$r['status']] ?? 'secondary' ?>">
                            <?= $label[$r['status']] ?? $r['status'] ?>
                        </span>
                    </td>
                    <td class="small text-muted"><?= e($r['criador_nome']) ?></td>
                    <td class="text-end">
                        <a href="<?= url('brindes/requisicoes/' . $r['id']) ?>"
                           class="btn btn-sm btn-outline-secondary" title="Ver histórico">
                            <i class="bi bi-clock-history"></i>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
