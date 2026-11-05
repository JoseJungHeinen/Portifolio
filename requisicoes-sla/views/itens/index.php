<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0">
        <i class="bi bi-card-list me-2 text-primary"></i>Itens
    </h4>
    <?php if (can(['diretor', 'admin'])): ?>
    <a href="<?= url('itens/comparativo') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-layout-three-columns me-1"></i>Comparar Itens</a>
    <a href="<?= url('itens/novo') ?>" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Novo Item
    </a>
    <?php endif; ?>
</div>

<!-- Contadores -->
<div class="row g-2 mb-3">
    <!-- Geral -->
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small fw-semibold mb-1 text-uppercase" style="font-size:.7rem;letter-spacing:.5px">Total Geral</div>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <a href="<?= url('itens?ativo=1') ?>" class="badge bg-success text-decoration-none fs-6 fw-normal px-2 py-1" title="Ativos">
                        <i class="bi bi-check-circle me-1"></i><?= $estatisticas['ativos'] ?> ativo<?= $estatisticas['ativos'] != 1 ? 's' : '' ?>
                    </a>
                    <a href="<?= url('itens?ativo=0') ?>" class="badge bg-secondary text-decoration-none fs-6 fw-normal px-2 py-1" title="Inativos">
                        <i class="bi bi-slash-circle me-1"></i><?= $estatisticas['inativos'] ?> inativo<?= $estatisticas['inativos'] != 1 ? 's' : '' ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Por Grupo -->
    <div class="col-12 col-md-9">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small fw-semibold mb-1 text-uppercase" style="font-size:.7rem;letter-spacing:.5px">Por Grupo</div>
                <?php
                $paletaGrupos = [
                    ['bg' => '#e8f5e9', 'border' => '#a5d6a7', 'color' => '#2e7d32'],  // verde suave
                    ['bg' => '#f3e5f5', 'border' => '#ce93d8', 'color' => '#6a1b9a'],  // lilás
                    ['bg' => '#e0f7fa', 'border' => '#80deea', 'color' => '#00695c'],  // ciano/teal
                    ['bg' => '#fff8e1', 'border' => '#ffe082', 'color' => '#f57f17'],  // âmbar
                    ['bg' => '#fce4ec', 'border' => '#f48fb1', 'color' => '#880e4f'],  // rosa
                    ['bg' => '#e8eaf6', 'border' => '#9fa8da', 'color' => '#283593'],  // índigo
                    ['bg' => '#e0f2f1', 'border' => '#80cbc4', 'color' => '#004d40'],  // teal escuro
                    ['bg' => '#fff3e0', 'border' => '#ffcc80', 'color' => '#e65100'],  // laranja suave
                ];
                ?>
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($estatisticas['porGrupo'] as $i => $g):
                        $cor = $paletaGrupos[$i % count($paletaGrupos)];
                    ?>
                    <a href="<?= url('itens?grupo_produto_id=' . $g['grupo_id']) ?>"
                       class="text-decoration-none px-2 py-1 small fw-semibold rounded"
                       style="background:<?= $cor['bg'] ?>;border:1px solid <?= $cor['border'] ?>;color:<?= $cor['color'] ?>"
                       title="<?= e($g['grupo']) ?>: <?= $g['ativos'] ?> ativo(s), <?= $g['inativos'] ?> inativo(s)">
                        <?= e($g['grupo']) ?>
                        <span class="ms-1 fw-bold"><?= (int)$g['ativos'] + (int)$g['inativos'] ?></span>
                    </a>
                    <?php endforeach; ?>
                    <?php if (empty($estatisticas['porGrupo'])): ?>
                        <span class="text-muted small">—</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<?php
function itensUrl(array $merge, array $remove = []): string {
    $params = $_GET;
    foreach ($remove as $k) unset($params[$k]);
    foreach ($merge as $k => $v) {
        if ($v === null || $v === '') unset($params[$k]);
        else $params[$k] = $v;
    }
    unset($params['page']);
    $qs = http_build_query($params);
    return url('itens') . ($qs ? '?' . $qs : '');
}
function itensHiddenParams(string $except): string {
    $preserve = ['grupo_produto_id','ativo','setor_id','col_codigo','col_nome','curva','sort','dir'];
    $html = '';
    foreach ($preserve as $k) {
        if ($k !== $except && isset($_GET[$k]) && $_GET[$k] !== '') {
            $html .= '<input type="hidden" name="' . $k . '" value="' . htmlspecialchars($_GET[$k], ENT_QUOTES) . '">';
        }
    }
    return $html;
}
function itensSortLink(string $col, string $label, string $sortAtual, string $dirAtual, string $align = ''): string {
    $nextDir = ($sortAtual === $col && $dirAtual === 'ASC') ? 'DESC' : 'ASC';
    $active  = $sortAtual === $col;
    $icon    = $active
        ? '<i class="bi ' . ($dirAtual === 'ASC' ? 'bi-sort-up' : 'bi-sort-down') . ' text-primary ms-1 small"></i>'
        : '<i class="bi bi-arrow-down-up text-muted ms-1 small" style="opacity:.35"></i>';
    $q = array_merge($_GET, ['sort' => $col, 'dir' => $nextDir]);
    $href = '?' . http_build_query($q);
    $cls  = 'text-decoration-none text-reset d-flex align-items-center gap-1' . ($align ? ' justify-content-' . $align : '');
    return '<a href="' . $href . '" class="' . $cls . '">' . $label . $icon . '</a>';
}
$sortAtual = $_GET['sort'] ?? '';
$dirAtual  = $_GET['dir']  ?? 'DESC';
$colCodigo = trim($_GET['col_codigo'] ?? '');
$colNome   = trim($_GET['col_nome']   ?? '');
$filtroCurva  = $_GET['curva']            ?? '';
$filtroGrupoI = $_GET['grupo_produto_id'] ?? '';
$filtroAtivoI = $_GET['ativo']            ?? '';
$filtroSetorI = $_GET['setor_id']         ?? '';
?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <!-- Código -->
                        <th>
                            <div class="d-flex align-items-center gap-1">
                                <?= itensSortLink('codigo', 'Código', $sortAtual, $dirAtual) ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm p-0 border-0 bg-transparent <?= $colCodigo ? 'text-primary' : 'text-muted' ?>"
                                            data-bs-toggle="dropdown" aria-expanded="false" title="Filtrar código">
                                        <i class="bi <?= $colCodigo ? 'bi-funnel-fill' : 'bi-funnel' ?> small"></i>
                                    </button>
                                    <div class="dropdown-menu shadow p-2" style="min-width:210px">
                                        <form method="GET" action="<?= url('itens') ?>">
                                            <?= itensHiddenParams('col_codigo') ?>
                                            <input type="text" class="form-control form-control-sm mb-2"
                                                   name="col_codigo" value="<?= e($colCodigo) ?>" placeholder="Buscar código...">
                                            <div class="d-flex gap-1">
                                                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Filtrar</button>
                                                <?php if ($colCodigo): ?>
                                                <a href="<?= itensUrl(['col_codigo' => null]) ?>" class="btn btn-outline-secondary btn-sm"></a>
                                                <?php endif; ?>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <!-- Nome -->
                        <th>
                            <div class="d-flex align-items-center gap-1">
                                <?= itensSortLink('nome', 'Nome', $sortAtual, $dirAtual) ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm p-0 border-0 bg-transparent <?= $colNome ? 'text-primary' : 'text-muted' ?>"
                                            data-bs-toggle="dropdown" aria-expanded="false" title="Filtrar nome">
                                        <i class="bi <?= $colNome ? 'bi-funnel-fill' : 'bi-funnel' ?> small"></i>
                                    </button>
                                    <div class="dropdown-menu shadow p-2" style="min-width:210px">
                                        <form method="GET" action="<?= url('itens') ?>">
                                            <?= itensHiddenParams('col_nome') ?>
                                            <input type="text" class="form-control form-control-sm mb-2"
                                                   name="col_nome" value="<?= e($colNome) ?>" placeholder="Buscar nome...">
                                            <div class="d-flex gap-1">
                                                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Filtrar</button>
                                                <?php if ($colNome): ?>
                                                <a href="<?= itensUrl(['col_nome' => null]) ?>" class="btn btn-outline-secondary btn-sm"></a>
                                                <?php endif; ?>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <!-- Grupo -->
                        <th>
                            <div class="d-flex align-items-center gap-1">
                                <?= itensSortLink('grupo', 'Grupo', $sortAtual, $dirAtual) ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm p-0 border-0 bg-transparent <?= $filtroGrupoI ? 'text-primary' : 'text-muted' ?>"
                                            data-bs-toggle="dropdown" title="Filtrar grupo">
                                        <i class="bi <?= $filtroGrupoI ? 'bi-funnel-fill' : 'bi-funnel' ?> small"></i>
                                    </button>
                                    <ul class="dropdown-menu shadow" style="min-width:200px;max-height:280px;overflow-y:auto">
                                        <li><a class="dropdown-item <?= !$filtroGrupoI ? 'active' : '' ?>"
                                               href="<?= itensUrl(['grupo_produto_id' => null]) ?>">Todos</a></li>
                                        <li><hr class="dropdown-divider my-1"></li>
                                        <?php foreach ($grupos as $g): ?>
                                        <li><a class="dropdown-item <?= $filtroGrupoI === $g['id'] ? 'active' : '' ?>"
                                               href="<?= itensUrl(['grupo_produto_id' => $g['id']]) ?>"><?= e($g['nome']) ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </th>
                        <!-- Marca -->
                        <th>Marca</th>
                        <!-- Unidade -->
                        <th><?= itensSortLink('unidade', 'Unidade', $sortAtual, $dirAtual) ?></th>
                        <!-- Curva -->
                        <?php if (can(['diretor', 'admin'])): ?>
                        <th>
                            <div class="d-flex align-items-center gap-1">
                                <?= itensSortLink('curva', 'Curva', $sortAtual, $dirAtual) ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm p-0 border-0 bg-transparent <?= $filtroCurva ? 'text-primary' : 'text-muted' ?>"
                                            data-bs-toggle="dropdown" title="Filtrar curva">
                                        <i class="bi <?= $filtroCurva ? 'bi-funnel-fill' : 'bi-funnel' ?> small"></i>
                                    </button>
                                    <ul class="dropdown-menu shadow">
                                        <li><a class="dropdown-item <?= !$filtroCurva ? 'active' : '' ?>"
                                               href="<?= itensUrl(['curva' => null]) ?>">Todas</a></li>
                                        <li><hr class="dropdown-divider my-1"></li>
                                        <li><a class="dropdown-item <?= $filtroCurva === 'A' ? 'active' : '' ?>"
                                               href="<?= itensUrl(['curva' => 'A']) ?>"><span class="badge bg-danger me-1">A</span> Alta rotatividade</a></li>
                                        <li><a class="dropdown-item <?= $filtroCurva === 'B' ? 'active' : '' ?>"
                                               href="<?= itensUrl(['curva' => 'B']) ?>"><span class="badge bg-warning text-dark me-1">B</span> Média rotatividade</a></li>
                                        <li><a class="dropdown-item <?= $filtroCurva === 'C' ? 'active' : '' ?>"
                                               href="<?= itensUrl(['curva' => 'C']) ?>"><span class="badge bg-secondary me-1">C</span> Baixa rotatividade</a></li>
                                    </ul>
                                </div>
                            </div>
                        </th>
                        <?php endif; ?>
                        <!-- Estoque -->
                        <th class="text-center"><?= itensSortLink('estoque', 'Estoque', $sortAtual, $dirAtual, 'center') ?></th>
                        <!-- Valor Ref. -->
                        <?php if (can(['diretor', 'admin'])): ?>
                        <th class="text-end"><?= itensSortLink('valor_ref', 'Valor Ref.', $sortAtual, $dirAtual, 'end') ?></th>
                        <?php endif; ?>
                        <!-- Status -->
                        <th class="text-center">
                            <div class="d-flex align-items-center justify-content-center gap-1">
                                Status
                                <div class="dropdown">
                                    <button class="btn btn-sm p-0 border-0 bg-transparent <?= $filtroAtivoI !== '' ? 'text-primary' : 'text-muted' ?>"
                                            data-bs-toggle="dropdown" title="Filtrar status">
                                        <i class="bi <?= $filtroAtivoI !== '' ? 'bi-funnel-fill' : 'bi-funnel' ?> small"></i>
                                    </button>
                                    <ul class="dropdown-menu shadow">
                                        <li><a class="dropdown-item <?= $filtroAtivoI === '' ? 'active' : '' ?>"
                                               href="<?= itensUrl(['ativo' => null]) ?>">Todos</a></li>
                                        <li><hr class="dropdown-divider my-1"></li>
                                        <li><a class="dropdown-item <?= $filtroAtivoI === '1' ? 'active' : '' ?>"
                                               href="<?= itensUrl(['ativo' => '1']) ?>">Ativo</a></li>
                                        <li><a class="dropdown-item <?= $filtroAtivoI === '0' ? 'active' : '' ?>"
                                               href="<?= itensUrl(['ativo' => '0']) ?>">Inativo</a></li>
                                    </ul>
                                </div>
                            </div>
                        </th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($itens)): ?>
                    <tr><td colspan="<?= can(['diretor', 'admin']) ? 10 : 8 ?>" class="text-center text-muted py-4">Nenhum item encontrado.</td></tr>
                    <?php else: ?>
                    <?php foreach ($itens as $it): ?>
                    <tr>
                        <td><code><?= e($it['codigo']) ?></code></td>
                        <td class="fw-semibold">
                            <?= e($it['nome']) ?>
                            <?php if ($it['abaixo_minimo']): ?>
                                <span class="badge bg-danger ms-1" style="font-size:.65rem" title="Abaixo do estoque mínimo">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= e($it['grupo_nome'] ?? '—') ?></td>
                        <td class="small text-muted"><?= e($it['marca'] ?? '—') ?></td>
                        <td><?= e($it['unidade']) ?></td>
                        <?php if (can(['diretor', 'admin'])): ?>
                        <td>
                            <?php
                            $curva = $it['curva_abc'];
                            $badge = $curva === 'A' ? 'bg-danger' : ($curva === 'B' ? 'bg-warning text-dark' : 'bg-secondary');
                            ?>
                            <span class="badge <?= $badge ?>"><?= e($curva) ?></span>
                        </td>
                        <?php endif; ?>
                        <td class="text-center">
                            <?php $saldo = (float)$it['saldo_total']; ?>
                            <span class="fw-semibold <?= $it['abaixo_minimo'] ? 'text-danger' : 'text-success' ?>">
                                <?= number_format($saldo, 2, ',', '.') ?>
                            </span>
                            <div class="text-muted" style="font-size:.7rem">mín: <?= number_format((float)$it['estoque_minimo'], 2, ',', '.') ?></div>
                        </td>
                        <?php if (can(['diretor', 'admin'])): ?>
                        <td class="text-end">
                            <?php if (isset($it['valor_referencia']) && $it['valor_referencia'] !== null): ?>
                                <?= money($it['valor_referencia']) ?>
                                <?php
                                $valorAnterior = $it['valor_referencia_anterior'] ?? null;
                                if ($valorAnterior !== null && (float)$it['valor_referencia'] != (float)$valorAnterior):
                                    $subiu = (float)$it['valor_referencia'] > (float)$valorAnterior;
                                ?>
                                <i class="bi <?= $subiu ? 'bi-arrow-up-short text-danger' : 'bi-arrow-down-short text-success' ?>"
                                   title="<?= $subiu ? 'Subiu' : 'Desceu' ?> em relação ao valor anterior (<?= money($valorAnterior) ?>)"></i>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td class="text-center">
                            <?= $it['ativo']
                                ? '<span class="badge bg-success">Ativo</span>'
                                : '<span class="badge bg-light text-dark">Inativo</span>' ?>
                        </td>
                        <td class="text-end pe-3">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= url('itens/' . $it['id'] . '/relatorio') ?>" class="btn btn-sm btn-outline-info" title="Relatorio"><i class="bi bi-bar-chart-line"></i></a>
                                <?php if (can(['diretor', 'admin'])): ?>
                                <a href="<?= url('itens/' . $it['id'] . '/editar') . ($_GET ? '?_back=' . urlencode(http_build_query($_GET)) : '') ?>"
                                   class="btn btn-sm btn-outline-primary" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" action="<?= url('itens/' . $it['id'] . '/toggle') ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <button type="submit"
                                            class="btn btn-sm <?= $it['ativo'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>"
                                            title="<?= $it['ativo'] ? 'Inativar item' : 'Ativar item' ?>"
                                            onclick="return confirm('<?= $it['ativo'] ? 'Inativar' : 'Ativar' ?> o item &quot;<?= addslashes(e($it['nome'])) ?>&quot;?')">
                                        <i class="bi <?= $it['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if (can(['admin'])): ?>
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        title="Excluir item"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalExcluirItem"
                                        data-id="<?= e($it['id']) ?>"
                                        data-nome="<?= e($it['nome']) ?>"
                                        data-codigo="<?= e($it['codigo']) ?>">
                                    <i class="bi bi-trash3"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (can(['admin'])): ?>
<!-- Modal: Confirmar Exclusão de Item -->
<div class="modal fade" id="modalExcluirItem" tabindex="-1" aria-labelledby="modalExcluirItemLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="modalExcluirItemLabel">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Excluir Item
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1">Tem certeza que deseja <strong>excluir permanentemente</strong> este item?</p>
                <p class="mb-3">
                    <code id="excluirItemCodigo" class="text-muted me-2">—</code>
                    <strong id="excluirItemNome">—</strong>
                </p>
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Itens utilizados em requisições, cotações, OCs ou transferências <strong>não podem ser excluídos</strong>. Use <em>Inativar</em> nesses casos.
                </div>
                <p class="text-danger small mb-0"><i class="bi bi-trash3 me-1"></i>Esta ação não pode ser desfeita.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form method="POST" id="formExcluirItem">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash3 me-1"></i>Excluir
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('modalExcluirItem').addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('excluirItemCodigo').textContent = btn.dataset.codigo;
    document.getElementById('excluirItemNome').textContent   = btn.dataset.nome;
    document.getElementById('formExcluirItem').action        = '<?= url('itens') ?>/' + btn.dataset.id + '/excluir';
});
</script>
<?php endif; ?>


