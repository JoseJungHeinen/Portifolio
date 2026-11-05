<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-check me-2 text-primary"></i>Contratos Recorrentes</h4>
        <small class="text-muted">Total mensal: <strong class="text-success"><?= money($totalMensal) ?></strong></small>
    </div>
    <?php if (can(['comprador','admin'])): ?>
    <a href="<?= url('contratos/novo') ?>" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Novo Contrato
    </a>
    <?php endif; ?>
</div>

<?php if (!empty($vencendo)): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
    <div>
        <strong><?= count($vencendo) ?> contrato(s) vencendo em até 30 dias:</strong>
        <?php foreach ($vencendo as $v): ?>
            <span class="badge bg-warning text-dark ms-1"><?= e($v['fornecedor_nome']) ?> — <?= $v['dias_restantes'] ?>d</span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2" method="GET">
            <div class="col-auto">
                <select name="status" class="form-select form-select-sm">
                    <option value="ativo"    <?= ($_GET['status'] ?? 'ativo') === 'ativo'    ? 'selected' : '' ?>>Ativos</option>
                    <option value="vencido"  <?= ($_GET['status'] ?? '') === 'vencido'  ? 'selected' : '' ?>>Vencidos</option>
                    <option value="cancelado"<?= ($_GET['status'] ?? '') === 'cancelado' ? 'selected' : '' ?>>Cancelados</option>
                    <option value=""         <?= ($_GET['status'] ?? 'ativo') === ''    ? 'selected' : '' ?>>Todos</option>
                </select>
            </div>
            <div class="col-auto">
                <select name="setor_id" class="form-select form-select-sm">
                    <option value="">Todos os setores</option>
                    <?php foreach ($setores as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= ($_GET['setor_id'] ?? '') === $s['id'] ? 'selected' : '' ?>>
                        <?= e($s['nome']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Fornecedor</th>
                        <th>Setor</th>
                        <th>Objeto</th>
                        <th class="text-end">Valor</th>
                        <th class="text-end">Mensal Aprox.</th>
                        <th>Vigência</th>
                        <th class="text-center">Vencimento</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($contratos)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Nenhum contrato encontrado.</td></tr>
                    <?php else: ?>
                    <?php foreach ($contratos as $c): ?>
                    <?php
                        if (!empty($c['valor_mensal'])) {
                            $mensal = (float)$c['valor_mensal'];
                        } elseif (!empty($c['is_valor_total'])) {
                            $mensal = (float)$c['valor'] / max(1, (int)($c['num_parcelas'] ?? 1));
                        } else {
                            $mensal = (float)$c['valor'];
                        }
                        $dias   = (int)($c['dias_vencimento'] ?? 999);
                        $rowClass = '';
                        if ($dias <= 7 && $c['status'] === 'ativo')       $rowClass = 'table-danger';
                        elseif ($dias <= 30 && $c['status'] === 'ativo')   $rowClass = 'table-warning';
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td class="fw-semibold">
                            <?= e($c['fornecedor_nome']) ?>
                            <?php if (!empty($c['fornecedor_fantasia'])): ?>
                                <div class="small fw-normal text-muted"><?= e($c['fornecedor_fantasia']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= e($c['setor_nome'] ?? '—') ?></td>
                        <td class="small text-truncate" style="max-width:200px" title="<?= e($c['objeto']) ?>"><?= e($c['objeto']) ?></td>
                        <td class="text-end">
                            <?php if (!empty($c['sem_valor'])): ?>
                                <span class="badge bg-light text-secondary border">Sem valor fixo</span>
                            <?php else: ?>
                                <?= money($c['valor']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-muted">
                            <?= empty($c['sem_valor']) ? money($mensal) : '—' ?>
                        </td>
                        <td class="small"><?= date_br($c['data_inicio']) ?> a <?= date_br($c['data_fim']) ?></td>
                        <td class="text-center">
                            <?php if ($c['status'] === 'ativo'): ?>
                                <span class="badge <?= $dias <= 30 ? 'bg-warning text-dark' : 'bg-light text-dark' ?>"><?= $dias ?>d</span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= status_badge($c['status']) ?></td>
                        <td>
                            <?php if (can(['comprador','admin'])): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Anexar arquivo"
                                    onclick="abrirModalUploadAnexo('<?= $c['id'] ?>')">
                                <i class="bi bi-paperclip"></i>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Ver anexos"
                                    onclick="abrirModalVerAnexos('<?= $c['id'] ?>')">
                                <i class="bi bi-folder2-open"></i>
                            </button>
                            <?php if (can(['comprador','admin'])): ?>
                            <a href="<?= url('contratos/' . $c['id'] . '/editar') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
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

<!-- Modal: Upload de Anexo -->
<div class="modal fade" id="modalUploadAnexo" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="formUploadAnexo" enctype="multipart/form-data" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title">Anexar Arquivo ao Contrato</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold">Arquivo (PDF, imagem, Word ou Excel — máx. 10MB)</label>
                <input type="file" name="arquivo" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Enviar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Ver Anexos -->
<div class="modal fade" id="modalVerAnexos" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Anexos do Contrato</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="listaAnexosContainer" class="p-3 text-muted small">Carregando...</div>
            </div>
        </div>
    </div>
</div>

<script>
const APP_URL_CONTRATOS = '<?= rtrim($_ENV['APP_URL'] ?? '', '/') ?>';
const PODE_EXCLUIR_ANEXO = <?= can(['comprador','admin']) ? 'true' : 'false' ?>;

function abrirModalUploadAnexo(contratoId) {
    document.getElementById('formUploadAnexo').action = APP_URL_CONTRATOS + '/contratos/' + contratoId + '/documentos';
    new bootstrap.Modal(document.getElementById('modalUploadAnexo')).show();
}

function formatarBytes(bytes) {
    return (bytes / 1024).toFixed(1) + ' KB';
}

function abrirModalVerAnexos(contratoId) {
    const container = document.getElementById('listaAnexosContainer');
    container.innerHTML = 'Carregando...';
    new bootstrap.Modal(document.getElementById('modalVerAnexos')).show();

    fetch(APP_URL_CONTRATOS + '/contratos/' + contratoId + '/documentos')
        .then(r => r.json())
        .then(data => {
            const docs = data.documentos || [];
            if (docs.length === 0) {
                container.innerHTML = '<p class="text-muted small p-3 mb-0"><i class="bi bi-inbox me-1"></i>Nenhum anexo enviado.</p>';
                return;
            }
            let html = '<table class="table table-sm table-hover mb-0 align-middle">' +
                '<thead class="table-light"><tr><th class="ps-3">Arquivo</th><th>Tamanho</th><th>Data</th><th></th></tr></thead><tbody>';
            docs.forEach(doc => {
                html += '<tr>' +
                    '<td class="ps-3"><a href="' + doc.url_download + '" target="_blank" class="text-decoration-none small">' +
                    '<i class="bi bi-file-earmark me-1 text-primary"></i>' + escapeHtml(doc.nome_original) + '</a></td>' +
                    '<td class="text-muted small">' + formatarBytes(doc.tamanho_bytes) + '</td>' +
                    '<td class="small text-muted">' + escapeHtml(doc.data_br) + '</td>' +
                    '<td class="pe-2 text-end">' +
                    (PODE_EXCLUIR_ANEXO
                        ? '<form method="POST" action="' + APP_URL_CONTRATOS + '/contratos/documentos/' + doc.id + '/excluir" class="d-inline">' +
                          '<button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm(\'Remover este anexo?\')" title="Remover"><i class="bi bi-trash"></i></button></form>'
                        : '') +
                    '</td></tr>';
            });
            html += '</tbody></table>';
            container.innerHTML = html;
        })
        .catch(() => {
            <?= csrf_field() ?>
            container.innerHTML = '<p class="text-danger small p-3 mb-0">Erro ao carregar anexos.</p>';
        });
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
</script>
