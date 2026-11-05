<?php
$isAberta = in_array($aud['status'], ['aberta','em_conferencia']);
$conferidos = count(array_filter($itens, fn($i) => $i['qtd_fisica'] !== null));
$totalItens = count($itens);
$pct        = $totalItens > 0 ? round($conferidos / $totalItens * 100) : 0;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="<?= url('auditoria-estoque') ?>" class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i>Auditorias
        </a>
        <h4 class="mb-0 mt-1">
            <i class="bi bi-clipboard2-check me-2"></i>
            Conferência — <?= e($aud['filial_nome']) ?>
        </h4>
        <div class="small text-muted">
            Criada em <?= date('d/m/Y \à\s H:i', strtotime($aud['criado_em'])) ?>
            por <?= e($aud['criador_nome']) ?>
            <?php if ($aud['escopo'] !== 'todos' && $aud['escopo_nome']): ?>
            · Escopo: <?= ucfirst($aud['escopo']) ?> <strong><?= e($aud['escopo_nome']) ?></strong>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url('auditoria-estoque/' . $aud['id'] . '/imprimir') ?>" class="btn btn-outline-secondary btn-sm" target="_blank">
            <i class="bi bi-printer me-1"></i>Imprimir folha
        </a>
        <?php if ($aud['status'] === 'finalizada'): ?>
        <a href="<?= url('auditoria-estoque/' . $aud['id'] . '/relatorio') ?>" class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-bar-graph me-1"></i>Ver Relatório
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Progresso -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="d-flex align-items-center gap-3">
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between small mb-1">
                    <span><?= $conferidos ?> de <?= $totalItens ?> itens conferidos</span>
                    <span class="fw-semibold"><?= $pct ?>%</span>
                </div>
                <div class="progress" style="height:8px">
                    <div class="progress-bar bg-<?= $pct === 100 ? 'success' : 'primary' ?>"
                         style="width:<?= $pct ?>%"></div>
                </div>
            </div>
            <?php if ($aud['status'] !== 'finalizada'): ?>
            <span class="badge bg-<?= $aud['status'] === 'em_conferencia' ? 'warning' : 'secondary' ?>">
                <?= $aud['status'] === 'em_conferencia' ? 'Em Conferência' : 'Aberta' ?>
            </span>
            <?php else: ?>
            <span class="badge bg-success">Finalizada</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$isAberta): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle me-1"></i>
    Esta auditoria foi <strong>finalizada</strong> em <?= date('d/m/Y \à\s H:i', strtotime($aud['finalizado_em'])) ?>.
    <a href="<?= url('auditoria-estoque/' . $aud['id'] . '/relatorio') ?>" class="alert-link ms-1">Ver relatório →</a>
</div>
<?php endif; ?>

<!-- Fotos do estoque -->
<div class="card shadow-sm mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-camera me-2"></i>Fotos do estoque</span>
        <span class="badge bg-light text-dark border"><?= count($fotos) ?> foto(s)</span>
    </div>
    <div class="card-body">
        <?php if ($isAberta): ?>
        <form method="POST" action="<?= url('auditoria-estoque/' . $aud['id'] . '/fotos') ?>"
              enctype="multipart/form-data" class="row g-2 align-items-end mb-3">
            <?= csrf_field() ?>
            <div class="col-md-5">
                <label class="form-label small mb-1">Foto (JPG, PNG ou WEBP — máx. 10MB)</label>
                <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-5">
                <label class="form-label small mb-1">Legenda (opcional)</label>
                <input type="text" name="legenda" maxlength="255" class="form-control form-control-sm"
                       placeholder="Ex: Prateleira A — depósito matriz">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-upload me-1"></i>Enviar
                </button>
            </div>
        </form>
        <?php endif; ?>

        <?php if (empty($fotos)): ?>
        <div class="text-muted small text-center py-3">
            <i class="bi bi-image me-1"></i>Nenhuma foto adicionada<?= $isAberta ? '' : ' nesta auditoria' ?>.
        </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($fotos as $f): ?>
            <div class="col-6 col-md-3 col-lg-2">
                <div class="border rounded overflow-hidden h-100 d-flex flex-column">
                    <a href="<?= url('auditoria-estoque/fotos/' . $f['id']) ?>" target="_blank" title="Ampliar">
                        <img src="<?= url('auditoria-estoque/fotos/' . $f['id']) ?>"
                             alt="<?= e($f['legenda'] ?? 'Foto do estoque') ?>"
                             class="w-100" style="height:120px;object-fit:cover;">
                    </a>
                    <div class="p-2 small flex-grow-1">
                        <?php if (!empty($f['legenda'])): ?>
                        <div class="text-truncate" title="<?= e($f['legenda']) ?>"><?= e($f['legenda']) ?></div>
                        <?php else: ?>
                        <div class="text-muted fst-italic">Sem legenda</div>
                        <?php endif; ?>
                    </div>
                    <?php if ($isAberta): ?>
                    <form method="POST" action="<?= url('auditoria-estoque/fotos/' . $f['id'] . '/excluir') ?>"
                          onsubmit="return confirm('Excluir esta foto?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger w-100 rounded-0 border-0 border-top">
                            <i class="bi bi-trash me-1"></i>Excluir
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Filtro rápido -->
<div class="mb-2 d-flex gap-2 align-items-center">
    <input type="text" id="filtroItem" class="form-control form-control-sm" style="max-width:280px" placeholder="Filtrar item...">
    <div class="btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-outline-secondary active" data-filtro="todos">Todos</button>
        <button type="button" class="btn btn-outline-warning" data-filtro="pendente">Pendentes</button>
        <button type="button" class="btn btn-outline-success" data-filtro="conferido">Conferidos</button>
    </div>
</div>

<!-- Formulário de conferência -->
<form method="POST" action="<?= url('auditoria-estoque/' . $aud['id'] . '/conferir') ?>">
    <?= csrf_field() ?>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle" id="tabelaConferencia">
            <thead class="table-light">
                <tr>
                    <th>Item</th>
                    <th>Grupo</th>
                    <th class="text-center">Unid.</th>
                    <th class="text-end">Qtd. Sistema</th>
                    <th class="text-center" style="width:140px">Qtd. Física</th>
                    <th class="text-center">Situação</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($itens as $it): ?>
            <?php
            $temFisica = $it['qtd_fisica'] !== null;
            $diff      = $temFisica ? (float)$it['qtd_fisica'] - (float)$it['qtd_sistema'] : null;
            $rowClass  = '';
            if ($temFisica) {
                $rowClass = $diff == 0 ? 'table-success' : ($diff < 0 ? 'table-danger' : 'table-warning');
            }
            ?>
            <tr class="<?= $rowClass ?>" data-nome="<?= strtolower(e($it['item_nome'])) ?>"
                data-conferido="<?= $temFisica ? '1' : '0' ?>">
                <td>
                    <div class="fw-semibold"><?= e($it['item_nome']) ?></div>
                    <?php if ($it['codigo']): ?>
                    <div class="small text-muted"><?= e($it['codigo']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="small text-muted"><?= e($it['grupo_nome'] ?? '—') ?></td>
                <td class="text-center small"><?= e($it['unidade']) ?></td>
                <td class="text-end fw-semibold"><?= number_format((float)$it['qtd_sistema'], 2, ',', '.') ?></td>
                <td class="text-center">
                    <?php if ($isAberta): ?>
                    <input type="number" name="qtd_fisica[<?= e($it['id']) ?>]"
                           value="<?= $temFisica ? rtrim(rtrim(number_format((float)$it['qtd_fisica'], 2, '.', ''), '0'), '.') : '' ?>"
                           class="form-control form-control-sm text-center qtd-input"
                           step="0.01" min="0"
                           placeholder="—"
                           data-sistema="<?= (float)$it['qtd_sistema'] ?>">
                    <?php else: ?>
                        <?= $temFisica ? number_format((float)$it['qtd_fisica'], 2, ',', '.') : '<span class="text-muted">—</span>' ?>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if (!$temFisica): ?>
                        <span class="badge bg-secondary">Pendente</span>
                    <?php elseif ($diff == 0): ?>
                        <span class="badge bg-success"><i class="bi bi-check-lg"></i> Conforme</span>
                    <?php elseif ($diff < 0): ?>
                        <span class="badge bg-danger"><i class="bi bi-arrow-down"></i> <?= number_format(abs($diff), 2, ',', '.') ?></span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-arrow-up"></i> <?= number_format($diff, 2, ',', '.') ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($isAberta): ?>
<div class="d-flex gap-2 mt-3 justify-content-end">
    <button type="submit" class="btn btn-outline-primary">
        <i class="bi bi-floppy me-1"></i>Salvar contagens
    </button>
    <button type="submit" name="finalizar" value="1" class="btn btn-success"
            onclick="return confirm('Finalizar a auditoria? Após finalizar não será possível alterar as contagens.')">
        <i class="bi bi-check2-circle me-1"></i>Finalizar e gerar relatório
    </button>
</div>
<?php endif; ?>
</form>

<script>
(function() {
    const filtroText = document.getElementById('filtroItem');
    const botoesEstado = document.querySelectorAll('[data-filtro]');
    let estadoAtivo = 'todos';

    function filtrar() {
        const txt = filtroText.value.toLowerCase();
        document.querySelectorAll('#tabelaConferencia tbody tr').forEach(tr => {
            const nome      = tr.dataset.nome || '';
            const conferido = tr.dataset.conferido;
            const matchTxt  = !txt || nome.includes(txt);
            const matchEst  = estadoAtivo === 'todos'
                || (estadoAtivo === 'pendente'  && conferido === '0')
                || (estadoAtivo === 'conferido' && conferido === '1');
            tr.style.display = matchTxt && matchEst ? '' : 'none';
        });
    }

    filtroText.addEventListener('input', filtrar);

    botoesEstado.forEach(btn => {
        btn.addEventListener('click', () => {
            botoesEstado.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            estadoAtivo = btn.dataset.filtro;
            filtrar();
        });
    });

    // Atualiza situação ao digitar sem precisar salvar (visual)
    document.querySelectorAll('.qtd-input').forEach(inp => {
        inp.addEventListener('input', () => {
            const tr    = inp.closest('tr');
            const val   = parseFloat(inp.value);
            const sist  = parseFloat(inp.dataset.sistema);
            // Remove classes de cor antigas
            tr.classList.remove('table-success','table-danger','table-warning');
            const badge = tr.querySelector('.badge');
            if (inp.value === '') {
                if (badge) { badge.className='badge bg-secondary'; badge.innerHTML='Pendente'; }
                tr.dataset.conferido = '0';
                return;
            }
            tr.dataset.conferido = '1';
            const diff = val - sist;
            if (diff === 0) {
                tr.classList.add('table-success');
                if (badge) { badge.className='badge bg-success'; badge.innerHTML='<i class="bi bi-check-lg"></i> Conforme'; }
            } else if (diff < 0) {
                tr.classList.add('table-danger');
                if (badge) { badge.className='badge bg-danger'; badge.innerHTML='<i class="bi bi-arrow-down"></i> '+Math.abs(diff).toFixed(2).replace('.',','); }
            } else {
                tr.classList.add('table-warning');
                if (badge) { badge.className='badge bg-warning text-dark'; badge.innerHTML='<i class="bi bi-arrow-up"></i> '+diff.toFixed(2).replace('.',','); }
            }
        });
    });
})();
</script>
