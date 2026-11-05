<?php
// @versao Ver.003.301026
$estoqueItens = $estoqueItens ?? [];
$badgeMap = [
    'rascunho'     => 'secondary',
    'em_aprovacao' => 'warning',
    'aprovado'     => 'success',
    'rejeitado'    => 'danger',
    'cancelado'    => 'dark',
];
$badgeCor = $badgeMap[$req['status']] ?? 'secondary';
$title    = 'Requisição — Fluxo';
$numFormatado = formatar_numero_requisicao($req['tipo'] ?? 'compra', $req['criado_em'], (int)($req['numero'] ?? 0));
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('requisicoes') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="fw-bold mb-0">
        <i class="bi bi-diagram-3 me-2 text-primary"></i>
        Monitor de Fluxo — <?= e($numFormatado) ?>
    </h4>
    <span class="badge bg-<?= $badgeCor ?>"><?= ucfirst(str_replace('_', ' ', $req['status'])) ?></span>
    <?php if ($req['tipo'] === 'interna'): ?>
        <span class="badge bg-warning text-dark">Requisição Interna</span>
    <?php elseif ($req['tipo'] === 'transferencia'): ?>
        <span class="badge bg-info text-dark">Solicitação de Transferência</span>
    <?php elseif ($req['tipo'] === 'ordem_compra'): ?>
        <span class="badge bg-info">Ordem de Compra</span>
    <?php endif; ?>
</div>

<?php if (!empty($andamento)) { $andamentoCompacto = false; include ROOT . '/views/partials/andamento.php'; } ?>

<?php if (!empty($solicitacaoOrigem)): ?>
<div class="alert alert-primary py-2 small mb-4">
    <i class="bi bi-link-45deg me-1"></i>
    Originada da solicitação
    <a href="<?= url('solicitacoes/' . $solicitacaoOrigem['id']) ?>"><?= e(\App\Services\SolicitacaoCompraService::numeroFormatado($solicitacaoOrigem)) ?></a>
    — <?= e($solicitacaoOrigem['titulo']) ?>
</div>
<?php endif; ?>

<!-- Timeline -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-clock-history me-2"></i>Linha do Tempo
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-0 align-items-start">
            <?php $total = count($timeline); ?>
            <?php foreach ($timeline as $i => $etapa): ?>
                <?php $falhou = $etapa['falhou'] ?? false; ?>
                <div class="d-flex flex-column align-items-center text-center position-relative"
                     style="min-width:120px;flex:1;padding:0 8px">
                    <!-- Circle -->
                    <div class="rounded-circle d-flex align-items-center justify-content-center mb-2
                                <?php if ($falhou): ?>bg-danger<?php elseif ($etapa['concluida']): ?>bg-<?= $etapa['cor'] ?><?php else: ?>bg-light border<?php endif; ?>"
                         style="width:48px;height:48px;flex-shrink:0">
                        <i class="bi <?= $etapa['icon'] ?> fs-5 <?= ($falhou || $etapa['concluida']) ? 'text-white' : 'text-muted' ?>"></i>
                    </div>
                    <!-- Connector line (except last) -->
                    <?php if ($i < $total - 1): ?>
                        <div class="position-absolute <?= $etapa['concluida'] ? 'bg-' . $etapa['cor'] : 'bg-light' ?>"
                             style="top:24px;left:calc(50% + 24px);right:calc(-50% + 24px);height:2px;z-index:0"></div>
                    <?php endif; ?>
                    <!-- Label -->
                    <div class="fw-semibold small"><?= e($etapa['label']) ?></div>
                    <?php if ($etapa['data']): ?>
                        <div class="text-muted" style="font-size:0.73rem">
                            <?= date('d/m H:i', strtotime($etapa['data'])) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($etapa['usuario']): ?>
                        <div class="text-muted" style="font-size:0.72rem">
                            <?= e($etapa['usuario']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($etapa['motivo'])): ?>
                        <div class="text-danger" style="font-size:0.72rem">
                            <?= e($etapa['motivo']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Details -->
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Itens da Requisição</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="px-3">Item</th>
                            <th>Grupo</th>
                            <th class="text-end">Qtd</th>
                            <th class="text-end">Valor Unit.</th>
                            <th class="text-end px-3">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itens as $item): ?>
                        <tr>
                            <td class="px-3">
                                <?= e($item['item_nome'] ?? $item['descricao_livre'] ?? '—') ?>
                                <?php
                                $itemId = $item['item_id'] ?? null;
                                $fonteEstoque = isset($estoqueItens[$itemId]) ? ($estoqueItens[$itemId]['fonte'] ?? null) : null;
                                if ($fonteEstoque === 'matriz'):
                                ?>
                                <span class="badge bg-info text-dark ms-1" title="Item disponível no Estoque Principal">
                                    <i class="bi bi-building"></i> Estoque Principal
                                </span>
                                <?php endif; ?>
                                <?php if (!$item['item_nome'] && !empty($item['descricao_livre'])): ?>
                                    <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">Livre</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($item['grupo_nome'])): ?>
                                <span class="badge bg-light text-dark border">
                                    <?= e($item['grupo_nome']) ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?= number_format((float)$item['quantidade'], 2, ',', '.') ?>
                                <?= e($item['unidade'] ?? '') ?>
                            </td>
                            <?php
                                $vu = (float)($item['valor_unitario'] ?? 0);
                                if ($vu == 0.0) $vu = (float)($item['valor_referencia'] ?? 0);
                            ?>
                            <td class="text-end"><?= money($vu) ?></td>
                            <td class="text-end fw-semibold px-3">
                                <?= money((float)$item['quantidade'] * $vu) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($itens)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">Sem itens.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <?php
                        $totalShow = array_sum(array_map(function($item) {
                            $vu = (float)($item['valor_unitario'] ?? 0);
                            if ($vu == 0.0) $vu = (float)($item['valor_referencia'] ?? 0);
                            return $vu * (float)($item['quantidade'] ?? 0);
                        }, $itens));
                        ?>
                        <tr>
                            <td colspan="4" class="text-end fw-bold px-3">Total</td>
                            <td class="text-end fw-bold px-3"><?= money($totalShow) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Informações</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Número</dt>
                    <dd class="col-7"><span class="badge bg-light text-dark border"><?= e($numFormatado) ?></span></dd>

                    <dt class="col-5 text-muted">Criada em</dt>
                    <dd class="col-7"><?= date('d/m/Y H:i', strtotime($req['criado_em'])) ?></dd>

                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7">
                        <span class="badge bg-<?= $badgeCor ?>">
                            <?= ucfirst(str_replace('_', ' ', $req['status'])) ?>
                        </span>
                    </dd>

                    <dt class="col-5 text-muted">Caminho</dt>
                    <dd class="col-7">
                        <?php if ($req['tipo'] === 'interna'): ?>
                            <span class="badge bg-warning text-dark">Requisição Interna</span>
                        <?php elseif ($req['tipo'] === 'transferencia'): ?>
                            <span class="badge bg-info text-dark">Solicitação de Transferência</span>
                        <?php elseif ($req['tipo'] === 'ordem_compra'): ?>
                            <span class="badge bg-info">Ordem de Compra</span>
                        <?php else: ?>
                            <span class="badge bg-primary">Compra</span>
                        <?php endif; ?>
                    </dd>

                    <?php if (!empty($req['finalidade'])): ?>
                    <dt class="col-5 text-muted">Finalidade</dt>
                    <dd class="col-7"><?= e(\App\Models\Requisicao::FINALIDADES[$req['finalidade']] ?? $req['finalidade']) ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($req['necessario_ate'])): ?>
                    <dt class="col-5 text-muted">Necessário até</dt>
                    <dd class="col-7"><?= e(date('d/m/Y', strtotime($req['necessario_ate']))) ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($req['justificativa'])): ?>
                    <dt class="col-5 text-muted">Justificativa</dt>
                    <dd class="col-7"><?= e($req['justificativa']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if (can(['gestor','diretor','admin','comprador'])): ?>
        <div class="mt-3">
            <a href="<?= url('requisicoes/' . $req['id'] . '/editar') ?>"
               class="btn btn-outline-primary btn-sm w-100">
                <i class="bi bi-pencil me-1"></i>Editar Requisição
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$cotacoesShow = $req['tipo'] === 'ordem_compra'
    ? (new \App\Models\RequisicaoCotacao())->daRequisicao($req['id'])
    : [];
?>
<?php if ($cotacoesShow): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check me-2 text-info"></i>Cotações</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>Fornecedor</th><th class="text-end">Valor</th><th class="text-end">Frete</th><th class="text-end">Total</th><th>Pagamento</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($cotacoesShow as $c): ?>
                    <?php $total = (float)$c['valor_total'] + (float)$c['frete']; ?>
                    <tr class="<?= (int)$c['vencedora'] === 1 ? 'table-success' : '' ?>">
                        <td class="small fw-semibold"><?= e($c['fornecedor']) ?></td>
                        <td class="text-end"><?= money($c['valor_total']) ?></td>
                        <td class="text-end small text-muted"><?= money($c['frete']) ?></td>
                        <td class="text-end fw-semibold"><?= money($total) ?></td>
                        <td class="small"><?= e($c['condicao_pagamento'] ?? '—') ?></td>
                        <td><?= (int)$c['vencedora'] === 1 ? '<span class="badge bg-success">Vencedora</span>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
