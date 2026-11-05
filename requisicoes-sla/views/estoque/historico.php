<?php
// @versao Ver.001.291026
$title = 'Histórico — ' . e($item['nome']);

$tipoLabel = ['entrada' => 'Entrada', 'saida' => 'Saída'];
$tipoBadge = ['entrada' => 'success', 'saida' => 'danger'];

$motivoLabel = [
    'compra'        => 'Compra',
    'inventario'    => 'Inventário',
    'perda'         => 'Perda',
    'avaria'        => 'Avaria',
    'correcao'      => 'Ajuste',
    'ajuste_valor'  => 'Ajuste de Valor',
    'transferencia' => 'Transferência',
    'requisicao'    => 'Baixa de Requisição Interna',
    'outro'         => 'Uso e consumo',
];
$motivoBadge = [
    'compra'        => 'primary',
    'inventario'    => 'info',
    'perda'         => 'danger',
    'avaria'        => 'warning',
    'correcao'      => 'secondary',
    'transferencia' => 'info',
    'requisicao'    => 'success',
    'outro'         => 'secondary',
];
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('estoque') ?>" class="btn btn-outline-secondary btn-sm" onclick="if (document.referrer.includes('/estoque')) { window.history.back(); return false; }">
        <i class="bi bi-arrow-left"></i>
    </a>
    <div>
        <h4 class="fw-bold mb-0">
            <i class="bi bi-clock-history me-2 text-primary"></i>Histórico de Estoque
        </h4>
        <small class="text-muted">
            <?= e($item['codigo']) ?> — <?= e($item['nome']) ?>
            <?php if ($item['grupo']): ?><span class="ms-2 badge bg-light text-secondary border"><?= e($item['grupo']) ?></span><?php endif; ?>
            <span class="ms-1 badge bg-light text-secondary border"><?= e($item['unidade']) ?></span>
        </small>
    </div>
</div>

<?php if (ve_todas_filiais() && !empty($filiais)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-2">
        <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
            <label class="form-label mb-0 fw-semibold small">Filial:</label>
            <select name="filial_id" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                <option value="">Todas as filiais</option>
                <?php foreach ($filiais as $f): ?>
                    <option value="<?= $f['id'] ?>" <?= $filialId === $f['id'] ? 'selected' : '' ?>>
                        <?= e($f['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($filialId): ?>
                <a href="<?= url('estoque/' . $itemId . '/historico') ?>?filial_id=" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i> Limpar
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($movimentos)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                Nenhum movimento registrado para este item.
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Data / Hora</th>
                        <th>Tipo</th>
                        <th>Motivo</th>
                        <th>Filial</th>
                        <th class="text-end">Quantidade</th>
                        <th>Usuário</th>
                        <th>Ordem de Compra Interna</th>
                        <th>Justificativa / Obs.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movimentos as $m): ?>
                    <?php
                        $motivoEfetivo = $m['motivo'] ?: ($m['referencia_tipo'] === 'requisicao' ? 'requisicao' : null);
                        $justificativaEfetiva = $m['justificativa'] ?: ($m['req_justificativa'] ?? '');
                        $reqNumFormatado = null;
                        if ($m['referencia_tipo'] === 'requisicao' && $m['req_numero'] !== null) {
                            $reqNumFormatado = formatar_numero_requisicao($m['req_tipo'] ?? 'compra', $m['req_criado_em'], (int)$m['req_numero']);
                        }
                        $tranNumFormatado = null;
                        if ($m['referencia_tipo'] === 'transferencia' && $m['tr_numero'] !== null) {
                            $tranNumFormatado = 'TRAN-' . date('Y', strtotime($m['tr_criado_em']))
                                . '-' . str_pad((string)$m['tr_numero'], 5, '0', STR_PAD_LEFT);
                        }
                        $tranOutraFilial = $m['tipo'] === 'saida' ? ($m['tr_destino_nome'] ?? null) : ($m['tr_origem_nome'] ?? null);
                        $tranSeta = $m['tipo'] === 'saida' ? '→' : '←';
                    ?>
                    <tr>
                        <td class="ps-3 text-nowrap small text-muted">
                            <?= datetime_br($m['criado_em']) ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $tipoBadge[$m['tipo']] ?? 'secondary' ?>">
                                <?= $tipoLabel[$m['tipo']] ?? e($m['tipo']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($motivoEfetivo): ?>
                            <span class="badge bg-<?= $motivoBadge[$motivoEfetivo] ?? 'secondary' ?> bg-opacity-75">
                                <?= $motivoLabel[$motivoEfetivo] ?? e($motivoEfetivo) ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= e($m['filial']) ?></td>
                        <td class="text-end fw-semibold <?= $m['tipo'] === 'entrada' ? 'text-success' : 'text-danger' ?>">
                            <?= $m['tipo'] === 'entrada' ? '+' : '-' ?><?= number_format((float)$m['quantidade'], 2, ',', '.') ?>
                            <span class="text-muted fw-normal"><?= e($item['unidade']) ?></span>
                        </td>
                        <td class="small"><?= e($m['usuario']) ?></td>
                        <td class="small">
                            <?php if ($reqNumFormatado): ?>
                            <a href="<?= url('requisicoes/' . $m['referencia_id']) ?>" class="badge bg-light text-dark border text-decoration-none">
                                <?= e($reqNumFormatado) ?>
                            </a>
                            <?php elseif (!empty($m['oc_numero'])): ?>
                            <a href="<?= url('compras/ordens/' . $m['referencia_id']) ?>" class="badge bg-light text-dark border text-decoration-none">
                                <?= e($m['oc_numero']) ?>
                            </a>
                            <?php elseif ($tranNumFormatado): ?>
                            <span class="badge bg-light text-dark border"><?= e($tranNumFormatado) ?></span>
                            <?php if ($tranOutraFilial): ?>
                            <div class="text-muted" style="font-size:0.75rem;"><?= $tranSeta ?> <?= e($tranOutraFilial) ?></div>
                            <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?php if ($m['valor_unit_novo'] !== null): ?>
                            <span class="badge bg-light text-dark border">
                                Valor: <?= number_format((float)$m['valor_unit_anterior'], 2, ',', '.') ?> → <?= number_format((float)$m['valor_unit_novo'], 2, ',', '.') ?>
                            </span>
                            <?php endif; ?>
                            <?= e($justificativaEfetiva) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2 text-muted small border-top">
            <?= count($movimentos) ?> registro(s) exibido(s)
        </div>
        <?php endif; ?>
    </div>
</div>
