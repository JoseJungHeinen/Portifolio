<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-building me-2 text-primary"></i>Fornecedores</h4>
    <div class="d-flex align-items-center gap-3">
        <!-- Contadores -->
        <div class="d-flex gap-2">
            <span class="badge rounded-pill bg-success fs-6 fw-normal px-3 py-2" title="Fornecedores ativos">
                <i class="bi bi-check-circle me-1"></i><?= $totalAtivos ?> ativo<?= $totalAtivos !== 1 ? 's' : '' ?>
            </span>
            <span class="badge rounded-pill bg-secondary fs-6 fw-normal px-3 py-2" title="Fornecedores inativos">
                <i class="bi bi-slash-circle me-1"></i><?= $totalInativos ?> inativo<?= $totalInativos !== 1 ? 's' : '' ?>
            </span>
        </div>
        <?php if (can(['comprador','admin'])): ?>
        <a href="<?= url('fornecedores/novo') ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Novo Fornecedor
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($top10)): ?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex align-items-center justify-content-between fw-semibold"
                 style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#colapsoTop10Fornecedores">
                <span><i class="bi bi-trophy me-2 text-warning"></i>Top 10 Fornecedores por Volume de Compras</span>
                <i class="bi bi-chevron-down text-muted" id="iconeTop10Fornecedores"></i>
            </div>
            <div class="collapse show" id="colapsoTop10Fornecedores">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>#</th><th>Fornecedor</th><th class="text-end">Total Comprado</th><th class="text-center">Qtd OC</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top10 as $i => $t): ?>
                                <tr>
                                    <td class="text-muted"><?= $i+1 ?>º</td>
                                    <td class="fw-semibold"><?= e($t['razao_social']) ?></td>
                                    <td class="text-end"><?= money($t['total_comprado'] ?? 0) ?></td>
                                    <td class="text-center"><?= $t['qtd_oc'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <form class="row g-2" method="GET">
            <div class="col-md-4">
                <input type="text" name="busca" class="form-control form-control-sm" placeholder="Buscar por nome, CNPJ..." value="<?= e($busca ?? '') ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Buscar</button>
            </div>
            <?php if (!empty($busca)): ?>
            <div class="col-auto">
                <a href="<?= url('fornecedores') ?>" class="btn btn-outline-secondary btn-sm">Limpar</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Razão Social / Fantasia</th>
                        <th>CNPJ</th>
                        <th>Cidade / UF</th>
                        <th>E-mail</th>
                        <th>Avaliação</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($fornecedores)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">
                        <?= !empty($busca) ? 'Nenhum fornecedor encontrado para "<strong>' . e($busca) . '</strong>".' : 'Nenhum fornecedor cadastrado.' ?>
                    </td></tr>
                    <?php else: ?>
                    <?php foreach ($fornecedores as $f): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= e($f['razao_social']) ?></div>
                            <?php if ($f['nome_fantasia']): ?>
                                <div class="text-muted small"><?= e($f['nome_fantasia']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($f['descricao_servico'])): ?>
                                <div class="text-muted" style="font-size:.75rem;margin-top:2px">
                                    <i class="bi bi-briefcase me-1 opacity-50"></i><?= e(mb_strimwidth($f['descricao_servico'], 0, 70, '…')) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><code><?= e(preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $f['cnpj'] ?? '')) ?></code></td>
                        <td><?= e($f['cidade'] ?? '—') ?><?= $f['estado'] ? '/' . e($f['estado']) : '' ?></td>
                        <td class="small"><?= e($f['email'] ?? '—') ?></td>
                        <td>
                            <?php if ($f['avaliacao']): ?>
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <i class="bi bi-star<?= $s <= round($f['avaliacao']) ? '-fill text-warning' : ' text-muted' ?>"></i>
                                <?php endfor; ?>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $f['ativo'] ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>' ?></td>
                        <td class="text-end">
                            <a href="<?= url('fornecedores/' . $f['id']) ?>" class="btn btn-sm btn-outline-secondary" title="Detalhes"><i class="bi bi-eye"></i></a>
                            <?php if (can(['comprador','admin'])): ?>
                            <a href="<?= url('fornecedores/' . $f['id'] . '/editar') ?>" class="btn btn-sm btn-outline-primary" title="Editar"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="<?= url('fornecedores/' . $f['id'] . '/toggle') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm <?= $f['ativo'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>"
                                        title="<?= $f['ativo'] ? 'Marcar como inativo' : 'Marcar como ativo' ?>">
                                    <i class="bi <?= $f['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const colapsoTop10Fornecedores = document.getElementById('colapsoTop10Fornecedores');
if (colapsoTop10Fornecedores) {
    // Restaurar estado ANTES do Bootstrap inicializar (manipula classe diretamente)
    if (localStorage.getItem('fornecedores_top10') === 'hidden') {
        colapsoTop10Fornecedores.classList.remove('show');
        document.getElementById('iconeTop10Fornecedores')?.classList.replace('bi-chevron-down', 'bi-chevron-up');
    }
    colapsoTop10Fornecedores.addEventListener('hide.bs.collapse', () => {
        document.getElementById('iconeTop10Fornecedores')?.classList.replace('bi-chevron-down', 'bi-chevron-up');
        localStorage.setItem('fornecedores_top10', 'hidden');
    });
    colapsoTop10Fornecedores.addEventListener('show.bs.collapse', () => {
        document.getElementById('iconeTop10Fornecedores')?.classList.replace('bi-chevron-up', 'bi-chevron-down');
        localStorage.setItem('fornecedores_top10', 'shown');
    });
}
</script>
