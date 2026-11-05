<?php
// @versao Ver.002.291026
    // Almoxarife: controle apenas de quantidades — oculta todos os valores (R$).
    $ocultarValores = (auth()['perfil'] ?? null) === 'almoxarife';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-boxes me-2 text-info"></i>Estoque</h4>
        <?php if ($filtroFilial && $filialMatriz && $filtroFilial === $filialMatriz['id']): ?>
            <small class="text-primary fw-semibold"><i class="bi bi-star-fill me-1"></i>Estoque Principal</small>
        <?php elseif ($filtroFilial): ?>
            <small class="text-muted"><?= e(array_column($filiais, 'nome', 'id')[$filtroFilial] ?? '') ?></small>
        <?php else: ?>
            <small class="text-muted">Todas as filiais</small>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <?php if (ve_todas_filiais()): ?>
        <div class="d-flex align-items-center gap-2">
            <label class="small text-muted mb-0 text-nowrap">Filial</label>
            <select id="seletorFilialEstoque" class="form-select form-select-sm" style="min-width:200px">
                <option value="" <?= empty($filtroFilial) ? 'selected' : '' ?>>Todas as filiais</option>
                <?php if ($filialMatriz): ?>
                <option value="<?= e($filialMatriz['id']) ?>"
                        <?= $filtroFilial === $filialMatriz['id'] ? 'selected' : '' ?>>
                    ★ <?= e($filialMatriz['nome']) ?> (Principal)
                </option>
                <?php endif; ?>
                <?php foreach ($filiais as $f): ?>
                <option value="<?= e($f['id']) ?>" <?= $filtroFilial === $f['id'] ? 'selected' : '' ?>>
                    <?= e($f['nome']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if (can(['admin'])): ?>
        <a href="<?= url('estoque/entrada') ?>" class="btn btn-success btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Entrada
        </a>
        <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalSaida">
            <i class="bi bi-dash-lg me-1"></i>Saída
        </button>
        <button class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#modalTransferencia">
            <i class="bi bi-arrow-left-right me-1"></i>Transferir
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                         style="width:42px;height:42px;background:#e8f5e9;flex-shrink:0">
                        <i class="bi bi-boxes text-success fs-5"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-4 lh-1"><?= number_format($kpis['total_itens'] ?? 0) ?></div>
                        <div class="text-muted small">Itens em estoque</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                         style="width:42px;height:42px;background:#fff3e0;flex-shrink:0">
                        <i class="bi bi-exclamation-triangle text-warning fs-5"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-4 lh-1"><?= number_format($kpis['abaixo_minimo'] ?? 0) ?></div>
                        <div class="text-muted small">Abaixo do mínimo</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                         style="width:42px;height:42px;background:#fce4ec;flex-shrink:0">
                        <i class="bi bi-archive text-danger fs-5"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-4 lh-1"><?= number_format($kpis['sem_estoque'] ?? 0) ?></div>
                        <div class="text-muted small">Sem estoque</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                         style="width:42px;height:42px;background:#ede7f6;flex-shrink:0">
                        <i class="bi bi-stack fs-5" style="color:#7c3aed"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-4 lh-1"><?= number_format($kpis['total_disponivel'] ?? 0, 0, ',', '.') ?></div>
                        <div class="text-muted small">Unid. disponíveis</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php if (!$ocultarValores): ?>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                         style="width:42px;height:42px;background:#e3f2fd;flex-shrink:0">
                        <i class="bi bi-cash-coin text-primary fs-5"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-5 lh-1"><?= money($kpis['valor_estimado'] ?? 0) ?></div>
                        <div class="text-muted small">Valor estimado <i class="bi bi-info-circle" title="Baseado no último preço de compra de cada item" data-bs-toggle="tooltip"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Por Grupo -->
<?php if (!empty($porGrupo)): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white border-0 d-flex align-items-center justify-content-between py-2"
         style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#colapsoPorGrupo">
        <h6 class="fw-bold mb-0 small text-uppercase" style="letter-spacing:.04em">
            <i class="bi bi-bar-chart-steps me-2 text-secondary"></i>Resumo por Grupo de Produto
        </h6>
        <i class="bi bi-chevron-down text-muted" id="iconePorGrupo"></i>
    </div>
    <div class="collapse show" id="colapsoPorGrupo">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="tabelaPorGrupo">
                    <thead class="table-light">
                        <tr>
                            <th class="px-3">
                                <div class="d-flex align-items-center gap-1">
                                    Grupo
                                    <div class="dropdown">
                                        <button class="btn btn-sm p-0 border-0 bg-transparent text-muted" id="btnFiltroGrupoRes"
                                                data-bs-toggle="dropdown" aria-expanded="false" title="Filtrar grupo">
                                            <i class="bi bi-funnel small"></i>
                                        </button>
                                        <div class="dropdown-menu shadow p-2" style="min-width:210px">
                                            <input type="text" id="inputFiltroGrupoRes" class="form-control form-control-sm mb-2"
                                                   placeholder="Buscar grupo...">
                                            <button type="button" class="btn btn-outline-secondary btn-sm w-100" id="btnLimparFiltroGrupo">
                                                Limpar
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </th>
                            <th class="text-center" style="cursor:pointer;user-select:none" data-sort-col="1" data-sort-dir="">
                                Itens <i class="bi bi-arrow-down-up text-muted small" style="opacity:.35"></i>
                            </th>
                            <th class="text-end" style="cursor:pointer;user-select:none" data-sort-col="2" data-sort-dir="">
                                Qtd. Total <i class="bi bi-arrow-down-up text-muted small" style="opacity:.35"></i>
                            </th>
                            <th class="text-center" style="cursor:pointer;user-select:none" data-sort-col="3" data-sort-dir="">
                                Abaixo mín. <i class="bi bi-arrow-down-up text-muted small" style="opacity:.35"></i>
                            </th>
                            <th class="text-center" style="cursor:pointer;user-select:none" data-sort-col="4" data-sort-dir="">
                                Sem estoque <i class="bi bi-arrow-down-up text-muted small" style="opacity:.35"></i>
                            </th>
                            <?php if (!$ocultarValores): ?>
                            <th class="text-end pe-3" style="cursor:pointer;user-select:none" data-sort-col="5" data-sort-dir="">
                                Valor estimado <i class="bi bi-arrow-down-up text-muted small" style="opacity:.35"></i>
                            </th>
                            <th class="text-center">Cobertura</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $valorTotal = array_sum(array_column($porGrupo, 'valor_estimado')) ?: 1;
                    foreach ($porGrupo as $pg):
                        $pct = round(($pg['valor_estimado'] / $valorTotal) * 100);
                        $alertas = (int)($pg['abaixo_minimo'] ?? 0) + (int)($pg['sem_estoque'] ?? 0);
                    ?>
                    <tr>
                        <td class="px-3 fw-semibold small"><?= e($pg['grupo']) ?></td>
                        <td class="text-center small" data-val="<?= $pg['total_itens'] ?>"><?= $pg['total_itens'] ?></td>
                        <td class="text-end small fw-semibold" data-val="<?= (float)($pg['quantidade_total'] ?? 0) ?>">
                            <?= number_format((float)($pg['quantidade_total'] ?? 0), 2, ',', '.') ?>
                        </td>
                        <td class="text-center" data-val="<?= (int)($pg['abaixo_minimo'] ?? 0) ?>">
                            <?php if ($pg['abaixo_minimo'] > 0): ?>
                                <span class="badge bg-warning text-dark"><?= $pg['abaixo_minimo'] ?></span>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center" data-val="<?= (int)($pg['sem_estoque'] ?? 0) ?>">
                            <?php if ($pg['sem_estoque'] > 0): ?>
                                <span class="badge bg-danger"><?= $pg['sem_estoque'] ?></span>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <?php if (!$ocultarValores): ?>
                        <td class="text-end pe-3 fw-semibold small" data-val="<?= (float)($pg['valor_estimado'] ?? 0) ?>"><?= money($pg['valor_estimado'] ?? 0) ?></td>
                        <td class="text-center" style="min-width:100px">
                            <div class="progress" style="height:6px">
                                <div class="progress-bar <?= $alertas > 0 ? 'bg-warning' : 'bg-success' ?>"
                                     style="width:<?= $pct ?>%"></div>
                            </div>
                            <small class="text-muted"><?= $pct ?>%</small>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<?php
// Helper: monta URL preservando GET params, substituindo/removendo chaves específicas
function estoqueUrl(array $merge, array $remove = []): string {
    $params = $_GET;
    foreach ($remove as $k) unset($params[$k]);
    foreach ($merge as $k => $v) {
        if ($k === 'filial_id') {
            // filial_id sempre presente na URL (mesmo vazio) para diferenciar
            // "limpar filtro" de "sem filtro na URL" — ver EstoqueController::index()
            $params[$k] = $v ?? '';
        } elseif ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    unset($params['page']); // reset página ao filtrar
    $qs = http_build_query($params);
    return url('estoque') . ($qs ? '?' . $qs : '');
}
function estoquePagUrl(int $pg): string {
    $params = $_GET;
    $params['page'] = $pg;
    return url('estoque') . '?' . http_build_query($params);
}
?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" style="font-size:.875rem">
                <?php
                // Render a sortable column header link
                function estoqueSort(string $col, string $label, ?string $sortCol, string $sortDir, string $align = ''): string {
                    $nextDir = ($sortCol === $col && $sortDir === 'ASC') ? 'desc' : 'asc';
                    $active  = $sortCol === $col;
                    $icon    = $active
                        ? '<i class="bi ' . ($sortDir === 'ASC' ? 'bi-sort-up' : 'bi-sort-down') . ' text-primary small"></i>'
                        : '<i class="bi bi-arrow-down-up text-muted small" style="opacity:.35"></i>';
                    $href    = estoqueUrl(['sort' => $col, 'dir' => $nextDir]);
                    $cls     = $align ? 'text-decoration-none text-reset d-flex align-items-center gap-1 justify-content-' . $align : 'text-decoration-none text-reset d-flex align-items-center gap-1';
                    return '<a href="' . $href . '" class="' . $cls . '">' . $label . $icon . '</a>';
                }
                // Hidden inputs preserving all active GET params except the excluded one
                function estoqueHiddenParams(string $except): string {
                    $preserve = ['filial_id','status','setor_id','grupo_id','per_page','col_codigo','col_item','sort','dir','busca'];
                    $html = '';
                    foreach ($preserve as $k) {
                        if ($k !== $except && isset($_GET[$k]) && $_GET[$k] !== '') {
                            $html .= '<input type="hidden" name="' . $k . '" value="' . htmlspecialchars($_GET[$k], ENT_QUOTES) . '">';
                        }
                    }
                    return $html;
                }
                ?>
                <thead class="table-light">
                    <tr>
                        <th>
                            <div class="d-flex align-items-center gap-1">
                                Código
                                <div class="dropdown">
                                    <button class="btn btn-sm p-0 border-0 bg-transparent <?= $colCodigo ? 'text-primary' : 'text-muted' ?>"
                                            data-bs-toggle="dropdown" aria-expanded="false" title="Filtrar código">
                                        <i class="bi <?= $colCodigo ? 'bi-funnel-fill' : 'bi-funnel' ?> small"></i>
                                    </button>
                                    <div class="dropdown-menu shadow p-2" style="min-width:210px">
                                        <form method="GET" action="<?= url('estoque') ?>">
                                            <?= estoqueHiddenParams('col_codigo') ?>
                                            <input type="text" class="form-control form-control-sm mb-2"
                                                   name="col_codigo" value="<?= e($colCodigo) ?>" placeholder="Buscar código...">
                                            <div class="d-flex gap-1">
                                                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Filtrar</button>
                                                <?php if ($colCodigo): ?>
                                                <a href="<?= estoqueUrl(['col_codigo' => null]) ?>" class="btn btn-outline-secondary btn-sm">✕</a>
                                                <?php endif; ?>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <th>
                            <div class="d-flex align-items-center gap-1">
                                Item
                                <div class="dropdown">
                                    <button class="btn btn-sm p-0 border-0 bg-transparent <?= $colItem ? 'text-primary' : 'text-muted' ?>"
                                            data-bs-toggle="dropdown" aria-expanded="false" title="Filtrar item">
                                        <i class="bi <?= $colItem ? 'bi-funnel-fill' : 'bi-funnel' ?> small"></i>
                                    </button>
                                    <div class="dropdown-menu shadow p-2" style="min-width:210px">
                                        <form method="GET" action="<?= url('estoque') ?>">
                                            <?= estoqueHiddenParams('col_item') ?>
                                            <input type="text" class="form-control form-control-sm mb-2"
                                                   name="col_item" value="<?= e($colItem) ?>" placeholder="Buscar item...">
                                            <div class="d-flex gap-1">
                                                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Filtrar</button>
                                                <?php if ($colItem): ?>
                                                <a href="<?= estoqueUrl(['col_item' => null]) ?>" class="btn btn-outline-secondary btn-sm">✕</a>
                                                <?php endif; ?>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <th>
                            <div class="d-flex align-items-center gap-1">
                                <?= estoqueSort('filial', 'Filial', $sortCol, $sortDir) ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm p-0 border-0 bg-transparent <?= $filtroFilial ? 'text-primary' : 'text-muted' ?>"
                                            data-bs-toggle="dropdown" title="Filtrar filial">
                                        <i class="bi <?= $filtroFilial ? 'bi-funnel-fill' : 'bi-funnel' ?> small"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:200px;max-height:300px;overflow-y:auto">
                                        <li><a class="dropdown-item <?= !$filtroFilial ? 'active' : '' ?>"
                                               href="<?= estoqueUrl(['filial_id' => null]) ?>">Todas as filiais</a></li>
                                        <?php if ($filialMatriz): ?>
                                        <li>
                                            <a class="dropdown-item fw-semibold text-primary <?= $filtroFilial === $filialMatriz['id'] ? 'active' : '' ?>"
                                               href="<?= estoqueUrl(['filial_id' => $filialMatriz['id']]) ?>">
                                                <i class="bi bi-star-fill me-1 small"></i><?= e($filialMatriz['nome']) ?> (Principal)
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                        <li><hr class="dropdown-divider my-1"></li>
                                        <?php foreach ($filiais as $f): ?>
                                        <li>
                                            <a class="dropdown-item <?= $filtroFilial === $f['id'] ? 'active' : '' ?>"
                                               href="<?= estoqueUrl(['filial_id' => $f['id']]) ?>">
                                                <?= e($f['nome']) ?>
                                            </a>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </th>
                        <th class="text-center"><?= estoqueSort('quantidade', 'Disponível', $sortCol, $sortDir, 'center') ?></th>
                        <th class="text-center"><?= estoqueSort('minimo', 'Mínimo', $sortCol, $sortDir, 'center') ?></th>
                        <th class="text-center">
                            <div class="d-flex align-items-center justify-content-center gap-1">
                                Status
                                <div class="dropdown">
                                    <button class="btn btn-sm p-0 border-0 bg-transparent <?= $filtroStatus ? 'text-primary' : 'text-muted' ?>"
                                            data-bs-toggle="dropdown" title="Filtrar status">
                                        <i class="bi <?= $filtroStatus ? 'bi-funnel-fill' : 'bi-funnel' ?> small"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow">
                                        <li><a class="dropdown-item <?= !$filtroStatus ? 'active' : '' ?>"
                                               href="<?= estoqueUrl(['status' => null]) ?>">Todos</a></li>
                                        <li><hr class="dropdown-divider my-1"></li>
                                        <li><a class="dropdown-item <?= $filtroStatus === 'abaixo' ? 'active' : '' ?>"
                                               href="<?= estoqueUrl(['status' => 'abaixo']) ?>">Abaixo do mínimo</a></li>
                                        <li><a class="dropdown-item <?= $filtroStatus === 'ok' ? 'active' : '' ?>"
                                               href="<?= estoqueUrl(['status' => 'ok']) ?>">OK</a></li>
                                        <li><a class="dropdown-item <?= $filtroStatus === 'zerado' ? 'active' : '' ?>"
                                               href="<?= estoqueUrl(['status' => 'zerado']) ?>">Zerado</a></li>
                                    </ul>
                                </div>
                            </div>
                        </th>
                        <?php if (!$ocultarValores): ?>
                        <th class="text-end"><?= estoqueSort('valor_unit', 'Valor Unit.', $sortCol, $sortDir, 'end') ?></th>
                        <th class="text-end"><?= estoqueSort('valor_total', 'Valor Total', $sortCol, $sortDir, 'end') ?></th>
                        <?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($estoque)): ?>
                    <tr><td colspan="<?= $ocultarValores ? 7 : 9 ?>" class="text-center text-muted py-4">Nenhum item encontrado.</td></tr>
                    <?php else: ?>
                    <?php foreach ($estoque as $e): ?>
                    <tr class="<?= $e['abaixo_minimo'] ? 'table-warning' : '' ?>">
                        <td><code><?= e($e['codigo']) ?></code></td>
                        <td><?= e($e['item_nome']) ?></td>
                        <td>
                                            <?= e($e['filial_nome']) ?>
                                            <?php if ($filialMatriz && $e['filial_id'] === $filialMatriz['id']): ?>
                                                <span class="badge bg-primary ms-1" title="Estoque Principal">Principal</span>
                                            <?php endif; ?>
                                        </td>
                        <td class="text-center">
                            <span class="fw-semibold <?= ($e['quantidade'] ?? 0) > 0 ? 'text-success' : 'text-danger' ?>">
                                <?= number_format($e['quantidade'] ?? 0, 2, ',', '.') ?> <?= e($e['unidade']) ?>
                            </span>
                        </td>
                        <td class="text-center text-muted">
                            <?= number_format($e['estoque_minimo'] ?? 0, 2, ',', '.') ?>
                            <?php if (can(['admin'])): ?>
                            <button type="button" class="btn btn-sm btn-link p-0 ms-1 text-muted"
                                    title="Definir estoque mínimo desta filial"
                                    onclick="abrirMinimo('<?= $e['item_id'] ?>', '<?= e(addslashes($e['item_nome'])) ?>', '<?= $e['filial_id'] ?>', '<?= e(addslashes($e['filial_nome'])) ?>', '<?= $e['estoque_minimo'] ?? 0 ?>')">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($e['abaixo_minimo']): ?>
                                <span class="badge bg-warning text-dark">Abaixo do mínimo</span>
                            <?php else: ?>
                                <span class="badge bg-success">OK</span>
                            <?php endif; ?>
                        </td>
                        <?php if (!$ocultarValores): ?>
                        <?php
                            $valUnit  = (float)($e['valor_unitario'] ?? 0);
                            $valTotal = $valUnit * (float)($e['quantidade'] ?? 0);
                        ?>
                        <td class="text-end text-muted small"><?= $valUnit > 0 ? money($valUnit) : '—' ?></td>
                        <td class="text-end fw-semibold small"><?= $valTotal > 0 ? money($valTotal) : '—' ?></td>
                        <?php endif; ?>
                        <td class="text-end">
                            <a href="<?= url('estoque/' . $e['item_id'] . '/historico?filial_id=' . $e['filial_id']) ?>"
                               class="btn btn-sm btn-outline-secondary" title="Histórico">
                                <i class="bi bi-clock-history"></i>
                            </a>
                            <?php if (can(['admin'])): ?>
                            <button type="button" class="btn btn-sm btn-outline-warning"
                                    title="Ajuste de Estoque"
                                    onclick="abrirAjuste('<?= $e['item_id'] ?>', '<?= e(addslashes($e['item_nome'])) ?>', '<?= $e['filial_id'] ?>')">
                                <i class="bi bi-sliders"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-info"
                                    title="Transferir"
                                    onclick="abrirTransferencia('<?= $e['item_id'] ?>', '<?= e(addslashes($e['item_nome'])) ?>', '<?= $e['filial_id'] ?>', '<?= e(addslashes($e['filial_nome'])) ?>', '<?= $e['quantidade'] ?>')">
                                <i class="bi bi-arrow-left-right"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginação -->
        <?php if ($paginacao['total_paginas'] > 1 || $paginacao['total_registros'] > 0): ?>
        <div class="d-flex align-items-center justify-content-between px-3 py-2 border-top bg-light">
            <div class="text-muted small">
                <?php if ($paginacao['total_registros'] > 0): ?>
                    Exibindo <?= $paginacao['inicio'] ?>–<?= $paginacao['fim'] ?> de <?= number_format($paginacao['total_registros'], 0, ',', '.') ?> itens
                <?php else: ?>
                    Nenhum item encontrado
                <?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="d-flex align-items-center gap-1">
                    <label class="text-muted small mb-0">Por página:</label>
                    <select class="form-select form-select-sm" style="width:auto"
                            onchange="window.location=this.value">
                        <?php foreach ([25, 50, 100] as $pp): ?>
                        <option value="<?= estoqueUrl(['per_page' => $pp]) ?>"
                                <?= $paginacao['per_page'] === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($paginacao['total_paginas'] > 1): ?>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $paginacao['pagina_atual'] <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= estoquePagUrl($paginacao['pagina_atual'] - 1) ?>">‹</a>
                        </li>
                        <?php
                        $pg    = $paginacao['pagina_atual'];
                        $total = $paginacao['total_paginas'];
                        $pages = array_unique(array_filter([1, $pg-1, $pg, $pg+1, $total], fn($p) => $p >= 1 && $p <= $total));
                        sort($pages);
                        $prev  = null;
                        foreach ($pages as $p):
                            if ($prev !== null && $p - $prev > 1): ?>
                            <li class="page-item disabled"><span class="page-link">…</span></li>
                        <?php endif; ?>
                        <li class="page-item <?= $p === $pg ? 'active' : '' ?>">
                            <a class="page-link" href="<?= estoquePagUrl($p) ?>"><?= $p ?></a>
                        </li>
                        <?php $prev = $p; endforeach; ?>
                        <li class="page-item <?= $paginacao['pagina_atual'] >= $total ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= estoquePagUrl($paginacao['pagina_atual'] + 1) ?>">›</a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Saída -->
<style>
#modalSaida .modal-body    { overflow: visible; }
#modalSaida .modal-content { overflow: visible; }
.saida-dropdown {
    position: absolute; top: 100%; left: 0; right: 0;
    z-index: 1060; display: none;
    background: #fff;
    border: 1px solid #dee2e6; border-top: none;
    border-radius: 0 0 .375rem .375rem;
    max-height: 190px; overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,.12);
}
.saida-dropdown .sd-opt {
    padding: .4rem .75rem; cursor: pointer; font-size: .875rem;
    border-bottom: 1px solid #f0f0f0;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.saida-dropdown .sd-opt:hover { background: #fdecea; }
.saida-dropdown .sd-opt.no-result { color:#aaa; cursor:default; font-style:italic; }
</style>
<div class="modal fade" id="modalSaida" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="<?= url('estoque/saida') ?>" class="modal-content needs-validation" novalidate id="formSaidaEstoque">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title">Registrar Saída</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Filtro de grupo global -->
                <div class="mb-2">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <label class="form-label fw-semibold mb-0">Itens *</label>
                        <select class="form-select form-select-sm w-auto" id="saidaFiltroGrupo" style="min-width:150px">
                            <option value="">Todos os grupos</option>
                            <?php foreach ($grupos as $g): ?>
                                <option value="<?= e($g['id']) ?>"><?= e($g['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Linhas de itens -->
                    <div id="saidaLinhasItens"></div>
                    <button type="button" class="btn btn-outline-danger btn-sm mt-1 w-100" id="saidaBtnAdicionar">
                        <i class="bi bi-plus-lg me-1"></i>Adicionar Item
                    </button>
                </div>

                <?php if (can(['diretor','admin'])): ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Filial</label>
                    <select name="filial_id" class="form-select">
                        <?php foreach ($filiais as $f): ?>
                            <option value="<?= $f['id'] ?>" <?= $f['id'] === auth()['filial_id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Motivo <span class="text-danger">*</span></label>
                    <select name="motivo" id="saidaMotivo" class="form-select" required>
                        <option value="">Selecione...</option>
                        <option value="outro">Uso / Consumo</option>
                        <option value="requisicao">Requisição Interna</option>
                        <option value="perda">Perda</option>
                        <option value="avaria">Avaria</option>
                    </select>
                </div>
                <div class="mb-3" id="blocoNumReq" style="display:none">
                    <label class="form-label fw-semibold">Número da Requisição</label>
                    <input type="text" name="num_requisicao" id="saidaNumReq"
                           class="form-control" placeholder="REQ-001, ABC1D23-05...">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Justificativa / Observação <span class="text-danger">*</span></label>
                    <input type="text" name="justificativa" id="saidaJustificativa"
                           class="form-control" placeholder="Setor, destino, observações..."
                           minlength="5" required>
                    <div class="invalid-feedback">Informe ao menos 5 caracteres.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger">Registrar Saída</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    const itensSaida = <?= json_encode(array_map(fn($it) => [
        'id'       => $it['id'],
        'nome'     => $it['nome'],
        'unidade'  => $it['unidade'] ?? '',
        'grupo_id' => $it['grupo_produto_id'] ?? '',
    ], $itens), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    const filtroGrupo = document.getElementById('saidaFiltroGrupo');
    const linhasDiv   = document.getElementById('saidaLinhasItens');
    const btnAdd      = document.getElementById('saidaBtnAdicionar');

    function listaFiltrada(busca) {
        const grupo = filtroGrupo.value;
        const termo = (busca || '').toLowerCase().trim();
        return itensSaida.filter(it => {
            if (grupo && it.grupo_id !== grupo) return false;
            if (termo && !it.nome.toLowerCase().includes(termo)) return false;
            return true;
        });
    }

    function adicionarLinha() {
        const row = document.createElement('div');
        row.className = 'input-group mb-1 saida-item-row';

        const wrap = document.createElement('div');
        wrap.style.cssText = 'position:relative;flex:1 1 auto;min-width:0';

        const searchInp = document.createElement('input');
        searchInp.type        = 'text';
        searchInp.className   = 'form-control form-control-sm';
        searchInp.placeholder = 'Buscar item...';
        searchInp.autocomplete = 'off';

        const hiddenInp = document.createElement('input');
        hiddenInp.type = 'hidden';
        hiddenInp.name = 'item_id[]';

        const dropdown = document.createElement('div');
        dropdown.className = 'saida-dropdown';

        wrap.appendChild(searchInp);
        wrap.appendChild(hiddenInp);
        wrap.appendChild(dropdown);

        const qtdInp = document.createElement('input');
        qtdInp.type        = 'text';
        qtdInp.name        = 'quantidade[]';
        qtdInp.className   = 'form-control form-control-sm';
        qtdInp.placeholder = 'Qtd';
        qtdInp.style.maxWidth = '80px';
        qtdInp.required    = true;

        const rmBtn = document.createElement('button');
        rmBtn.type      = 'button';
        rmBtn.className = 'btn btn-outline-danger btn-sm';
        rmBtn.innerHTML = '<i class="bi bi-trash3"></i>';
        rmBtn.onclick   = () => row.remove();

        row.appendChild(wrap);
        row.appendChild(qtdInp);
        row.appendChild(rmBtn);
        linhasDiv.appendChild(row);

        function abrirDropdown(busca) {
            const lista = listaFiltrada(busca);
            dropdown.innerHTML = '';
            if (lista.length === 0) {
                const d = document.createElement('div');
                d.className = 'sd-opt no-result';
                d.textContent = 'Nenhum item encontrado';
                dropdown.appendChild(d);
            } else {
                lista.forEach(it => {
                    const d = document.createElement('div');
                    d.className   = 'sd-opt';
                    d.textContent = it.nome + ' (' + it.unidade + ')';
                    d.addEventListener('mousedown', e => {
                        e.preventDefault();
                        hiddenInp.value = it.id;
                        searchInp.value = it.nome + ' (' + it.unidade + ')';
                        searchInp.classList.remove('is-invalid');
                        dropdown.style.display = 'none';
                        qtdInp.focus();
                    });
                    dropdown.appendChild(d);
                });
            }
            dropdown.style.display = 'block';
        }

        searchInp.addEventListener('focus', () => abrirDropdown(searchInp.value));
        searchInp.addEventListener('input', () => { hiddenInp.value = ''; abrirDropdown(searchInp.value); });
        searchInp.addEventListener('blur',  () => {
            setTimeout(() => { dropdown.style.display = 'none'; }, 150);
            if (!hiddenInp.value) searchInp.value = '';
        });

        searchInp.focus();
    }

    filtroGrupo.addEventListener('change', () => {
        linhasDiv.querySelectorAll('.saida-item-row').forEach(row => {
            const hid = row.querySelector('input[type=hidden]');
            const txt = row.querySelector('input[type=text]');
            if (!hid || !hid.value) return;
            const it = itensSaida.find(i => i.id === hid.value);
            if (it && filtroGrupo.value && it.grupo_id !== filtroGrupo.value) {
                hid.value = ''; txt.value = '';
            }
        });
    });

    btnAdd.addEventListener('click', adicionarLinha);

    // Mostrar/ocultar campo de número de requisição
    const saidaMotivo  = document.getElementById('saidaMotivo');
    const blocoNumReq  = document.getElementById('blocoNumReq');
    const saidaNumReq  = document.getElementById('saidaNumReq');
    saidaMotivo.addEventListener('change', () => {
        const isReq = saidaMotivo.value === 'requisicao';
        blocoNumReq.style.display = isReq ? '' : 'none';
        saidaNumReq.required = isReq;
    });

    document.getElementById('modalSaida').addEventListener('show.bs.modal', () => {
        linhasDiv.innerHTML        = '';
        filtroGrupo.value          = '';
        saidaMotivo.value          = '';
        blocoNumReq.style.display  = 'none';
        saidaNumReq.required       = false;
        saidaNumReq.value          = '';
        document.getElementById('saidaJustificativa').value = '';
        adicionarLinha();
    });

    document.getElementById('formSaidaEstoque').addEventListener('submit', function (e) {
        const hiddens = linhasDiv.querySelectorAll('input[name="item_id[]"]');
        if (hiddens.length === 0) { e.preventDefault(); alert('Adicione ao menos um item.'); return; }
        let invalido = false;
        const vistos = [];
        hiddens.forEach(h => {
            if (!h.value) invalido = true;
            else if (vistos.includes(h.value)) invalido = true;
            else vistos.push(h.value);
        });
        if (invalido) {
            e.preventDefault();
            alert('Verifique os itens: todos devem ser selecionados e sem repetição.');
            return;
        }
        if (!this.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
        this.classList.add('was-validated');
    });
})();
</script>

<!-- Modal Ajuste de Estoque -->
<div class="modal fade" id="modalAjuste" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0" style="background:#fd7e14;border-radius:8px 8px 0 0">
                <h5 class="modal-title text-white fw-bold">
                    <i class="bi bi-sliders me-2"></i>Ajuste de Estoque
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="formAjuste">
                <?= csrf_field() ?>
                <div class="modal-body px-4 py-3">
                    <p class="fw-semibold mb-3" id="ajusteItemNome"></p>

                    <div class="mb-3 p-2 rounded bg-light border small">
                        <i class="bi bi-building me-1 text-primary"></i>
                        <strong>Filial:</strong> <span id="ajusteFilialNome"></span>
                    </div>
                    <input type="hidden" name="filial_id" id="ajusteFilialId">

                    <div class="mb-3 p-3 rounded" style="background:#fff8e1;border:1px solid #ffe082">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted" style="font-size:13px">Saldo atual</span>
                            <span class="fw-bold fs-5" id="saldoAtual">—</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nova Quantidade *</label>
                        <input type="text" name="nova_quantidade" id="novaQtd"
                               class="form-control" placeholder="Ex: 10 ou 2,5" required
                               oninput="calcularDelta()">
                        <div class="form-text" id="deltaTxt"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Novo Valor Unitário (R$)</label>
                        <input type="text" name="novo_valor_unitario" id="novoValor"
                               class="form-control" placeholder="Opcional — deixe igual para não alterar">
                        <div class="form-text">Valor atual da filial: <span id="valorAtualTxt">—</span></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Justificativa *</label>
                        <textarea name="justificativa" class="form-control" rows="3"
                                  placeholder="Descreva o motivo do ajuste (mínimo 10 caracteres)..."
                                  required minlength="5"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning btn-sm fw-bold px-4">
                        <i class="bi bi-check-lg me-1"></i>Confirmar Ajuste
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (can(['admin'])): ?>
<div class="modal fade" id="modalMinimo" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0" style="background:#6c757d;border-radius:8px 8px 0 0">
                <h5 class="modal-title text-white fw-bold">
                    <i class="bi bi-pencil-square me-2"></i>Estoque Mínimo
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="formMinimo" action="<?= url('estoque/definir-minimo') ?>">
                <?= csrf_field() ?>
                <div class="modal-body px-4 py-3">
                    <p class="fw-semibold mb-3" id="minimoItemNome"></p>

                    <div class="mb-3 p-2 rounded bg-light border small">
                        <i class="bi bi-building me-1 text-primary"></i>
                        <strong>Filial:</strong> <span id="minimoFilialNome"></span>
                    </div>
                    <input type="hidden" name="item_id" id="minimoItemId">
                    <input type="hidden" name="filial_id" id="minimoFilialId">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Estoque Mínimo nesta filial *</label>
                        <input type="text" name="estoque_minimo" id="minimoValor"
                               class="form-control" placeholder="Ex: 10 ou 2,5" required>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-secondary btn-sm fw-bold px-4">
                        <i class="bi bi-check-lg me-1"></i>Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const APP_URL_ESTOQUE = '<?= rtrim($_ENV['APP_URL'] ?? '', '/') ?>';

// Ícone do collapse por grupo + persistência de estado via localStorage
const colapsoPorGrupo = document.getElementById('colapsoPorGrupo');
if (colapsoPorGrupo) {
    // Restaurar estado ANTES do Bootstrap inicializar (manipula classe diretamente)
    if (localStorage.getItem('estoque_resumo_grupo') === 'hidden') {
        colapsoPorGrupo.classList.remove('show');
        document.getElementById('iconePorGrupo')?.classList.replace('bi-chevron-down','bi-chevron-up');
    }
    colapsoPorGrupo.addEventListener('hide.bs.collapse', () => {
        document.getElementById('iconePorGrupo')?.classList.replace('bi-chevron-down','bi-chevron-up');
        localStorage.setItem('estoque_resumo_grupo', 'hidden');
    });
    colapsoPorGrupo.addEventListener('show.bs.collapse', () => {
        document.getElementById('iconePorGrupo')?.classList.replace('bi-chevron-up','bi-chevron-down');
        localStorage.setItem('estoque_resumo_grupo', 'shown');
    });
}

// Seletor de filial no header — preserva todos os filtros ativos
const seletorFilial = document.getElementById('seletorFilialEstoque');
if (seletorFilial) {
    seletorFilial.addEventListener('change', function () {
        const url = new URL(window.location.href);
        url.searchParams.set('filial_id', this.value); // mantém o parâmetro presente mesmo vazio, para "esquecer" o filtro salvo — ver EstoqueController::index()
        url.searchParams.delete('page');
        window.location.href = url.toString();
    });
}

// Filtro e ordenação da tabela "Resumo por Grupo"
(function () {
    const tabela  = document.getElementById('tabelaPorGrupo');
    if (!tabela) return;
    const tbody   = tabela.querySelector('tbody');
    const inputFiltro = document.getElementById('inputFiltroGrupoRes');
    const btnFiltro   = document.getElementById('btnFiltroGrupoRes');
    const btnLimpar   = document.getElementById('btnLimparFiltroGrupo');
    let sortCol = -1, sortDir = 1;

    function linhas() { return Array.from(tbody.querySelectorAll('tr')); }

    function applyFilter() {
        const q = (inputFiltro.value || '').toLowerCase().trim();
        linhas().forEach(tr => {
            const texto = tr.cells[0].textContent.toLowerCase();
            tr.style.display = (!q || texto.includes(q)) ? '' : 'none';
        });
        const ativo = q !== '';
        btnFiltro.querySelector('i').className = ativo
            ? 'bi bi-funnel-fill small text-primary'
            : 'bi bi-funnel small';
        btnFiltro.classList.toggle('text-primary', ativo);
        btnFiltro.classList.toggle('text-muted', !ativo);
    }

    function applySort(col) {
        if (sortCol === col) {
            sortDir *= -1;
        } else {
            sortCol = col; sortDir = -1; // primeira vez: maior primeiro
        }
        const rows = linhas().sort((a, b) => {
            const va = parseFloat(a.cells[col]?.dataset.val ?? 0);
            const vb = parseFloat(b.cells[col]?.dataset.val ?? 0);
            return (va - vb) * sortDir;
        });
        rows.forEach(r => tbody.appendChild(r));

        // Atualiza ícones
        tabela.querySelectorAll('[data-sort-col]').forEach(th => {
            const c = parseInt(th.dataset.sortCol);
            const i = th.querySelector('i');
            if (!i) return;
            if (c === col) {
                i.className = sortDir === 1
                    ? 'bi bi-sort-up text-primary small'
                    : 'bi bi-sort-down text-primary small';
                i.style.opacity = '1';
            } else {
                i.className = 'bi bi-arrow-down-up text-muted small';
                i.style.opacity = '.35';
            }
        });
    }

    // Restaurar filtro salvo
    const filtroSalvo = localStorage.getItem('estoque_resumo_filtro_grupo');
    if (filtroSalvo) { inputFiltro.value = filtroSalvo; applyFilter(); }

    inputFiltro.addEventListener('input', () => {
        localStorage.setItem('estoque_resumo_filtro_grupo', inputFiltro.value);
        applyFilter();
    });
    inputFiltro.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
    btnLimpar.addEventListener('click', () => {
        inputFiltro.value = '';
        localStorage.removeItem('estoque_resumo_filtro_grupo');
        applyFilter();
    });

    tabela.querySelectorAll('[data-sort-col]').forEach(th => {
        th.addEventListener('click', () => applySort(parseInt(th.dataset.sortCol)));
    });
})();
let ajusteItemId = null;
let saldoAtualVal = 0;

function abrirAjuste(itemId, itemNome, filialId) {
    ajusteItemId = itemId;

    const filialSelect = document.querySelector('select[name="filial_id"]');
    let filialNome = '';
    if (filialSelect) {
        const opt = filialSelect.querySelector('option[value="' + filialId + '"]');
        filialNome = opt ? opt.textContent.trim() : filialId;
    }

    document.getElementById('ajusteItemNome').textContent = itemNome;
    document.getElementById('ajusteFilialNome').textContent = filialNome || filialId;
    document.getElementById('ajusteFilialId').value = filialId;
    document.getElementById('formAjuste').action = APP_URL_ESTOQUE + '/estoque/' + itemId + '/ajuste-estoque';
    document.getElementById('novaQtd').value = '';
    document.getElementById('deltaTxt').textContent = '';
    document.getElementById('saldoAtual').textContent = '...';
    saldoAtualVal = 0;
    buscarSaldoEstoque(filialId);
    new bootstrap.Modal(document.getElementById('modalAjuste')).show();
}

function abrirMinimo(itemId, itemNome, filialId, filialNome, minimoAtual) {
    document.getElementById('minimoItemNome').textContent = itemNome;
    document.getElementById('minimoFilialNome').textContent = filialNome || filialId;
    document.getElementById('minimoItemId').value = itemId;
    document.getElementById('minimoFilialId').value = filialId;
    document.getElementById('minimoValor').value = String(minimoAtual).replace('.', ',');
    new bootstrap.Modal(document.getElementById('modalMinimo')).show();
}

function buscarSaldoEstoque(filialId) {
    if (!ajusteItemId) return;
    fetch(APP_URL_ESTOQUE + '/api/estoque/item/' + ajusteItemId)
        .then(r => r.json())
        .then(data => {
            let saldo = 0; let valor = null;
            if (data.estoques) {
                const found = data.estoques.find(e => e.filial_id === filialId);
                saldo = found ? parseFloat(found.quantidade) : 0;
                valor = found ? parseFloat(found.valor_unitario) : null;
            } else {
                saldo = parseFloat(data.total) || 0;
            }
            saldoAtualVal = saldo;
            document.getElementById('saldoAtual').textContent =
                saldo.toLocaleString('pt-BR', {minimumFractionDigits: 2});
            document.getElementById('novaQtd').value = saldo.toLocaleString('pt-BR', {minimumFractionDigits: 2});
            const vTxt = (valor != null && !isNaN(valor)) ? valor.toLocaleString('pt-BR', {minimumFractionDigits: 2}) : '—';
            document.getElementById('valorAtualTxt').textContent = vTxt;
            document.getElementById('novoValor').value = (valor != null && !isNaN(valor)) ? vTxt : '';
            calcularDelta();
        })
        .catch(() => {
            document.getElementById('saldoAtual').textContent = '0,00';
        });
}

function calcularDelta() {
    const raw  = document.getElementById('novaQtd').value.replace('.', '').replace(',', '.');
    const nova = parseFloat(raw);
    const el   = document.getElementById('deltaTxt');
    if (isNaN(nova) || nova < 0) { el.textContent = ''; return; }
    const delta = nova - saldoAtualVal;
    if (delta === 0) {
        el.textContent = 'Sem alteração.';
        el.className = 'form-text text-muted';
    } else if (delta > 0) {
        el.textContent = 'Entrada de +' + delta.toLocaleString('pt-BR', {minimumFractionDigits: 2});
        el.className = 'form-text text-success fw-semibold';
    } else {
        el.textContent = 'Saída de ' + delta.toLocaleString('pt-BR', {minimumFractionDigits: 2});
        el.className = 'form-text text-danger fw-semibold';
    }
}
</script>

<!-- Modal Transferencia -->
<style>
#modalTransferencia .modal-body { overflow: visible; }
#modalTransferencia .modal-content { overflow: visible; }
.tr-dropdown {
    position: absolute; top: 100%; left: 0; right: 0; z-index: 1070;
    display: none; background: #fff;
    border: 1px solid #dee2e6; border-top: none;
    border-radius: 0 0 .375rem .375rem;
    max-height: 190px; overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,.12);
}
.tr-dropdown .tr-opt {
    padding: .4rem .75rem; cursor: pointer; font-size: .875rem;
    border-bottom: 1px solid #f0f0f0;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.tr-dropdown .tr-opt:hover { background: #e8f4fd; }
.tr-dropdown .tr-opt.no-result { color:#aaa; cursor:default; font-style:italic; }
</style>
<div class="modal fade" id="modalTransferencia" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="<?= url('estoque/transferencia') ?>" class="modal-content" id="formTransferencia">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i>Transferir Estoque</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Unidade de Origem *</label>
                        <select name="filial_origem_id" id="trOrigem" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($filiais as $f): ?>
                                <option value="<?= $f['id'] ?>"><?= e($f['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Unidade de Destino *</label>
                        <select name="filial_destino_id" id="trDestino" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($filiais as $f): ?>
                                <option value="<?= $f['id'] ?>"><?= e($f['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Modelo</label>
                        <input type="text" name="modelo" class="form-control" placeholder="Ex: Toyota Corolla">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Cor</label>
                        <input type="text" name="cor" class="form-control" placeholder="Ex: Prata">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Placa</label>
                        <input type="text" name="placa" class="form-control" placeholder="Ex: ABC1D23"
                               style="text-transform:uppercase" maxlength="10">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Transportadora</label>
                        <input type="text" name="transportadora" class="form-control" placeholder="Nome da transportadora">
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label fw-semibold">Itens *</label>
                    <div id="trLinhasItens"></div>
                    <button type="button" class="btn btn-outline-info btn-sm mt-1 w-100" id="trBtnAdicionar">
                        <i class="bi bi-plus-lg me-1"></i>Adicionar Item
                    </button>
                </div>

                <div class="mb-3 mt-3">
                    <label class="form-label fw-semibold">Observação</label>
                    <input type="text" name="observacao" class="form-control" placeholder="Opcional">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-info text-white">Transferir</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    const estoqueData = <?= json_encode(array_map(fn($e) => [
        'item_id'   => $e['item_id'],
        'item_nome' => $e['item_nome'],
        'filial_id' => $e['filial_id'],
        'unidade'   => $e['unidade'] ?? '',
        'saldo'     => (float)($e['quantidade'] ?? 0),
    ], $estoqueTransferencia), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    const trOrigem  = document.getElementById('trOrigem');
    const trDestino = document.getElementById('trDestino');
    const linhasDiv = document.getElementById('trLinhasItens');
    const btnAdd    = document.getElementById('trBtnAdicionar');

    // Filter destino options to exclude origem
    trOrigem.addEventListener('change', function () {
        const origemId = this.value;
        Array.from(trDestino.options).forEach(o => {
            o.hidden = o.value && o.value === origemId;
        });
        if (trDestino.value === origemId) trDestino.value = '';
        // Update saldo hints on existing rows
        document.querySelectorAll('.tr-item-row').forEach(row => updateSaldo(row));
    });

    function getItensOrigem() {
        const origemId = trOrigem.value;
        return estoqueData.filter(e => !origemId || e.filial_id === origemId);
    }

    function updateSaldo(row) {
        const origemId = trOrigem.value;
        const itemId   = row.querySelector('input[name="item_id[]"]').value;
        const hint     = row.querySelector('.tr-saldo');
        if (!hint) return;
        const entry = estoqueData.find(e => e.item_id === itemId && e.filial_id === origemId);
        hint.textContent = entry ? 'Saldo: ' + entry.saldo.toLocaleString('pt-BR', {minimumFractionDigits:2}) + ' ' + entry.unidade : '';
    }

    function criarLinha(itemId, itemNome, saldo, unidade) {
        const row = document.createElement('div');
        row.className = 'input-group mb-1 tr-item-row';

        const wrap = document.createElement('div');
        wrap.style.cssText = 'position:relative;flex:1 1 auto;min-width:0';

        const hiddenId = document.createElement('input');
        hiddenId.type = 'hidden'; hiddenId.name = 'item_id[]';
        hiddenId.value = itemId || '';

        const search = document.createElement('input');
        search.type = 'text';
        search.className = 'form-control form-control-sm';
        search.placeholder = 'Buscar item...';
        search.autocomplete = 'off';
        search.value = itemNome || '';

        const drop = document.createElement('div');
        drop.className = 'tr-dropdown';

        function renderDrop(busca) {
            const origemId = trOrigem.value;
            const termo = (busca || '').toLowerCase().trim();
            const lista = estoqueData.filter(e => {
                if (origemId && e.filial_id !== origemId) return false;
                if (termo && !e.item_nome.toLowerCase().includes(termo)) return false;
                return true;
            });
            drop.innerHTML = '';
            if (!lista.length) {
                drop.innerHTML = '<div class="tr-opt no-result">Nenhum item encontrado</div>';
            } else {
                lista.slice(0, 30).forEach(it => {
                    const opt = document.createElement('div');
                    opt.className = 'tr-opt';
                    opt.textContent = it.item_nome + ' (saldo: ' + it.saldo.toLocaleString('pt-BR', {minimumFractionDigits:2}) + ' ' + it.unidade + ')';
                    opt.addEventListener('mousedown', e => {
                        e.preventDefault();
                        hiddenId.value = it.item_id;
                        search.value   = it.item_nome;
                        saldoDiv.textContent = 'Saldo: ' + it.saldo.toLocaleString('pt-BR', {minimumFractionDigits:2}) + ' ' + it.unidade;
                        drop.style.display = 'none';
                    });
                    drop.appendChild(opt);
                });
            }
            drop.style.display = 'block';
        }

        search.addEventListener('input', () => { hiddenId.value = ''; renderDrop(search.value); });
        search.addEventListener('focus', () => renderDrop(search.value));
        search.addEventListener('blur',  () => setTimeout(() => { drop.style.display = 'none'; }, 150));

        wrap.appendChild(hiddenId);
        wrap.appendChild(search);
        wrap.appendChild(drop);

        const saldoDiv = document.createElement('div');
        saldoDiv.className = 'tr-saldo form-text text-muted';
        if (itemId && saldo !== undefined) {
            saldoDiv.textContent = 'Saldo: ' + parseFloat(saldo).toLocaleString('pt-BR', {minimumFractionDigits:2}) + (unidade ? ' ' + unidade : '');
        }

        const wrapOuter = document.createElement('div');
        wrapOuter.style.flex = '1 1 auto';
        wrapOuter.appendChild(wrap);
        wrapOuter.appendChild(saldoDiv);

        const qtdInp = document.createElement('input');
        qtdInp.type = 'text'; qtdInp.name = 'quantidade[]';
        qtdInp.className = 'form-control form-control-sm';
        qtdInp.placeholder = 'Qtd'; qtdInp.required = true;
        qtdInp.style.maxWidth = '90px';

        const btnRem = document.createElement('button');
        btnRem.type = 'button'; btnRem.className = 'btn btn-outline-danger btn-sm';
        btnRem.innerHTML = '<i class="bi bi-trash"></i>';
        btnRem.addEventListener('click', () => {
            if (linhasDiv.querySelectorAll('.tr-item-row').length > 1) row.remove();
        });

        row.appendChild(wrapOuter);
        row.appendChild(qtdInp);
        row.appendChild(btnRem);
        return row;
    }

    btnAdd.addEventListener('click', () => linhasDiv.appendChild(criarLinha()));

    // Inicializa com uma linha vazia
    linhasDiv.appendChild(criarLinha());

    // Chamada da linha da tabela
    window.abrirTransferencia = function(itemId, itemNome, origemId, origemNome, saldo) {
        // Limpa linhas
        linhasDiv.innerHTML = '';
        // Define origem
        trOrigem.value = origemId;
        Array.from(trDestino.options).forEach(o => { o.hidden = o.value && o.value === origemId; });
        trDestino.value = '';
        // Adiciona linha pré-preenchida
        const row = criarLinha(itemId, itemNome, saldo);
        linhasDiv.appendChild(row);
        new bootstrap.Modal(document.getElementById('modalTransferencia')).show();
    };

    // Reseta modal ao fechar
    document.getElementById('modalTransferencia').addEventListener('hidden.bs.modal', () => {
        trOrigem.value = ''; trDestino.value = '';
        linhasDiv.innerHTML = '';
        linhasDiv.appendChild(criarLinha());
    });
})();
</script>
