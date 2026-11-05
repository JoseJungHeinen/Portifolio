<?php
// @versao Ver.007.281026
$title     = $requisicao ? 'Editar Requisição' : 'Nova Requisição';
$solicitacaoOrigem = $solicitacaoOrigem ?? null;
$justificativaSugerida = '';
$tipoDespesaSugerido   = 'variavel';
if ($solicitacaoOrigem) {
    $linhas = [
        \App\Services\SolicitacaoCompraService::numeroFormatado($solicitacaoOrigem) . ' — ' . $solicitacaoOrigem['titulo'],
        'Solicitante: ' . $solicitacaoOrigem['solicitante_nome']
            . ' (' . ($solicitacaoOrigem['filial_nome'] ?? '—') . ' / ' . ($solicitacaoOrigem['setor_nome'] ?? '—') . ')',
    ];
    $urg = \App\Services\SolicitacaoCompraService::URGENCIAS[$solicitacaoOrigem['urgencia'] ?? 'planejado'] ?? null;
    if ($urg) $linhas[] = 'Urgência: ' . $urg['cor'] . ' — ' . $urg['rotulo'];
    if (!empty($solicitacaoOrigem['fornecedor_sugerido'])) $linhas[] = 'Fornecedor sugerido: ' . $solicitacaoOrigem['fornecedor_sugerido'];
    if (($solicitacaoOrigem['recorrencia'] ?? '') === 'mensal')  $linhas[] = 'Contrato / serviço mensal';
    if (!empty($solicitacaoOrigem['fora_orcamento']))            $linhas[] = 'Fora do orçamento: sim (aprovação da Diretoria)';
    if (!empty($solicitacaoOrigem['emergencial']))               $linhas[] = 'Emergencial já realizada: sim (regularização)';
    $justificativaSugerida = implode("\n", $linhas)
        . (!empty($solicitacaoOrigem['descricao']) ? "\n\n" . $solicitacaoOrigem['descricao'] : '')
        . "\n\nMotivo: " . $solicitacaoOrigem['motivo'];
    // Emergencial tem precedência sobre mensal: regularização de compra emergencial não é contrato recorrente.
    $tipoDespesaSugerido = !empty($solicitacaoOrigem['emergencial']) ? 'eventual'
        : (($solicitacaoOrigem['recorrencia'] ?? '') === 'mensal' ? 'recorrente' : 'variavel');
}
$iconColor = 'text-primary';
$iconClass = 'bi-cart-plus';
$tipoAtual = $tipo ?? 'compra';
$ehMovimentacaoEstoque = in_array($tipoAtual, ['interna', 'transferencia'], true);
$ehOrdemCompra         = $tipoAtual === 'ordem_compra';
$cotacoesTravadas      = $cotacoesTravadas ?? false;
$cotacoesForm          = $cotacoes ?? [];
$cotacoesItensForm     = $cotacoesItens ?? [];
// O valor por item já gravado volta junto da cotação: é o que o JS usa como
// estado inicial da tabela (data-itens) ao reabrir um rascunho.
foreach ($cotacoesForm as &$cotacaoForm) {
    $cotacaoForm['itens'] = array_map(
        fn($item) => [
            'posicao'   => (int)$item['posicao'],
            'descricao' => (string)$item['descricao'],
            'valor'     => (string)$item['valor'],
            'cotado'    => (int)$item['cotado'],
        ],
        $cotacoesItensForm[$cotacaoForm['id']] ?? []
    );
}
unset($cotacaoForm);
// O formulário sempre mostra pelo menos 3 blocos de cotação (recomendado) —
// menos quando travado (C1): aí é só a leitura do que foi enviado, sem blocos
// em branco para "adicionar" cotação.
if (!$cotacoesTravadas) {
    while (count($cotacoesForm) < max(3, $recomendadoCotacoes ?? 3)) {
        $cotacoesForm[] = ['fornecedor_id' => '', 'fornecedor' => '', 'valor_total' => '', 'frete' => '',
                           'condicao_pagamento' => '', 'vencedora' => 0, 'itens' => []];
    }
}
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('requisicoes') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi <?= $iconClass ?> me-2 <?= $iconColor ?>"></i><?= $title ?></h4>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Itens da Requisição</div>
            <div class="card-body">
                <form method="POST" action="<?= $requisicao ? url('requisicoes/' . $requisicao['id'] . '/atualizar') : url('requisicoes') ?>" id="formReq" enctype="multipart/form-data">
                    <?= csrf_field() ?>

                    <input type="hidden" name="tipo" id="inputTipo" value="<?= $tipoAtual ?>">

                    <?php if ($solicitacaoOrigem): ?>
                    <input type="hidden" name="solicitacao_id" value="<?= e($solicitacaoOrigem['id']) ?>">
                    <div class="alert alert-primary py-2 small">
                        <i class="bi bi-link-45deg me-1"></i>
                        Gerando a partir da solicitação
                        <a href="<?= url('solicitacoes/' . $solicitacaoOrigem['id']) ?>" target="_blank"><?= e(\App\Services\SolicitacaoCompraService::numeroFormatado($solicitacaoOrigem)) ?></a>
                        — <?= e($solicitacaoOrigem['titulo']) ?>. Ao salvar, ela passa para "Em compra".
                    </div>
                    <?php endif; ?>

                    <div class="mb-4">
                        <label class="form-label fw-semibold d-block mb-2">Tipo de Requisição *</label>
                        <?php
                        // Só os tipos permitidos. Na edição o tipo gravado aparece
                        // mesmo sem permissão, para a tela refletir o que está salvo.
                        // Ordem de Compra tem menu próprio (Compras): nela só aparece o
                        // botão dela, e nos demais tipos esse botão não aparece.
                        $mostraTipo = fn(string $t) => ($ehOrdemCompra ? $t === 'ordem_compra' : $t !== 'ordem_compra')
                            && (in_array($t, $tiposPermitidos ?? [], true) || $t === $tipoAtual);
                        ?>
                        <div class="btn-group" role="group">
                            <?php if ($mostraTipo('compra')): ?>
                            <button type="button" id="btnCompra"
                                class="btn <?= $tipoAtual === 'compra' ? 'btn-primary' : 'btn-outline-primary' ?>"
                                onclick="setTipo('compra')">
                                <i class="bi bi-cart me-1"></i>Compra
                            </button>
                            <?php endif; ?>
                            <?php if ($mostraTipo('interna')): ?>
                            <button type="button" id="btnRequisicaoInterna"
                                class="btn <?= $tipoAtual === 'interna' ? 'btn-warning' : 'btn-outline-warning' ?>"
                                onclick="setTipo('interna')">
                                <i class="bi bi-box-seam me-1"></i>Requisição Interna
                            </button>
                            <?php endif; ?>
                            <?php if ($mostraTipo('transferencia')): ?>
                            <button type="button" id="btnTransferencia"
                                class="btn <?= $tipoAtual === 'transferencia' ? 'btn-success' : 'btn-outline-success' ?>"
                                onclick="setTipo('transferencia')">
                                <i class="bi bi-arrow-left-right me-1"></i>Solicitação de Transferência
                            </button>
                            <?php endif; ?>
                            <?php if ($mostraTipo('ordem_compra')): ?>
                            <button type="button" id="btnOrdemCompra"
                                class="btn <?= $ehOrdemCompra ? 'btn-info' : 'btn-outline-info' ?>"
                                onclick="setTipo('ordem_compra')">
                                <i class="bi bi-file-earmark-text me-1"></i>Ordem de Compra
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php
                        $tipoDescricoes = [
                            'compra'        => 'Aquisição de produtos ou serviços externos.',
                            'interna'       => 'Movimentação de estoque interna (fluxo direto, sem confirmação de recebimento).',
                            'transferencia' => 'Transferência de estoque a partir da matriz, com confirmação de recebimento.',
                            'ordem_compra'  => 'Compra com cotações registradas à mão: o setor levanta os preços, escolhe a melhor e a ordem de compra sai na aprovação.',
                        ];
                        ?>
                        <div class="form-text mt-1" id="tipoDescricao">
                            <?= $tipoDescricoes[$tipoAtual] ?? $tipoDescricoes['compra'] ?>
                        </div>
                    </div>

                    <?php
                    $filialAtual = $requisicao['filial_id'] ?? $user['filial_id'];
                    $setorAtual  = $requisicao['setor_id']  ?? $user['setor_id']  ?? null;
                    $filialDestinoAtual = $requisicao['filial_destino_id'] ?? ($tipoAtual === 'interna' ? $filialAtual : '');
                    ?>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Unidade Requisitante *</label>
                            <?php if (can(['diretor','admin'])): ?>
                            <select name="filial_id" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($filiais as $f): ?>
                                    <option value="<?= $f['id'] ?>" <?= $filialAtual === $f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <input type="hidden" name="filial_id" value="<?= e($filialAtual) ?>">
                            <input type="text" class="form-control" value="<?= e(array_column($filiais, 'nome', 'id')[$filialAtual] ?? $filialAtual) ?>" readonly>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Setor Solicitante *</label>
                            <?php
                            // Na Requisição Interna o setor precisa ser o do próprio usuário
                            // (nas filiais, o Almoxarifado da unidade): gestor/comprador/almoxarife
                            // só enxergam requisições da própria filial+setor, então escolher outro
                            // setor faz a requisição sumir logo após ser criada.
                            // Ver RequisicaoController::podeAcessarRequisicao().
                            $setorNomes   = array_column($setores ?? [], 'nome', 'id');
                            $podeTrocarSetor = can(['diretor','admin']) || !$setorAtual || !isset($setorNomes[$setorAtual]);
                            $setorTravado    = !$podeTrocarSetor && $tipoAtual === 'interna';
                            ?>
                            <?php if (!$podeTrocarSetor): ?>
                            <div id="setorFixoWrap" class="<?= $setorTravado ? '' : 'd-none' ?>">
                                <input type="hidden" name="setor_id" id="setorFixoInput" value="<?= e($setorAtual) ?>" <?= $setorTravado ? '' : 'disabled' ?>>
                                <input type="text" class="form-control" value="<?= e($setorNomes[$setorAtual]) ?>" readonly>
                                <div class="form-text">Na Requisição Interna o setor é sempre o da sua unidade.</div>
                            </div>
                            <?php endif; ?>
                            <select name="setor_id" id="setorSelect" class="form-select <?= $setorTravado ? 'd-none' : '' ?>" required <?= $setorTravado ? 'disabled' : '' ?>>
                                <option value="">Selecione...</option>
                                <?php foreach ($setores ?? [] as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= $setorAtual === $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-4 campos-transferencia <?= $tipoAtual === 'transferencia' ? '' : 'd-none' ?>">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Unidade que vai Receber o Material *</label>
                            <select name="filial_destino_id" class="form-select select-filial-destino" <?= $tipoAtual === 'transferencia' ? 'required' : '' ?>>
                                <option value="">Selecione a unidade que vai receber...</option>
                                <?php foreach ($filiais as $f): ?>
                                    <?php $isMatriz = !empty($f['is_matriz']); ?>
                                    <option value="<?= $f['id'] ?>" <?= $filialDestinoAtual === $f['id'] ? 'selected' : '' ?>>
                                        <?= $isMatriz ? '★ ' : '' ?><?= e($f['nome']) ?><?= $isMatriz ? ' (Estoque Principal)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Normalmente é a sua própria unidade. Numa transferência, o material sempre sai da matriz — não selecione a matriz aqui.</div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4 campos-compra <?= $tipoAtual === 'compra' ? '' : 'd-none' ?>">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tipo de Despesa *</label>
                            <select name="tipo_despesa" class="form-select select-tipo-despesa" <?= $tipoAtual === 'compra' ? 'required' : '' ?>>
                                <option value="variavel"   <?= ($requisicao['tipo_despesa'] ?? $tipoDespesaSugerido) === 'variavel'   ? 'selected' : '' ?>>Variável (orçada)</option>
                                <option value="recorrente" <?= ($requisicao['tipo_despesa'] ?? $tipoDespesaSugerido) === 'recorrente' ? 'selected' : '' ?>>Recorrente (contrato)</option>
                                <option value="eventual"   <?= ($requisicao['tipo_despesa'] ?? $tipoDespesaSugerido) === 'eventual'   ? 'selected' : '' ?>>Eventual (emergência)</option>
                            </select>
                        </div>
                    </div>

                    <?php
                    $ehCompra         = in_array($tipoAtual, \App\Models\Requisicao::TIPOS_COMPRA, true);
                    $finalidadeAtual  = $requisicao['finalidade'] ?? ($solicitacaoOrigem['finalidade'] ?? '');
                    $necessarioAtual  = $requisicao['necessario_ate'] ?? ($solicitacaoOrigem['necessario_ate'] ?? '');
                    ?>
                    <div class="row g-3 mb-4 campos-politica <?= $ehCompra ? '' : 'd-none' ?>">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="reqFinalidade">Finalidade da compra *</label>
                            <select name="finalidade" id="reqFinalidade" class="form-select select-politica" <?= $ehCompra ? 'required' : '' ?>>
                                <option value="">Selecione…</option>
                                <?php foreach (\App\Models\Requisicao::FINALIDADES as $valor => $rotulo): ?>
                                <option value="<?= e($valor) ?>" <?= $finalidadeAtual === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Define o crédito de IBS/CBS — na dúvida, consulte o Fiscal.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold" for="reqNecessarioAte">Necessário até *</label>
                            <input type="date" name="necessario_ate" id="reqNecessarioAte" class="form-control select-politica"
                                   value="<?= e($necessarioAtual) ?>" <?= $ehCompra ? 'required' : '' ?>>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Justificativa</label>
                            <textarea name="justificativa" class="form-control" rows="<?= $justificativaSugerida ? 6 : 2 ?>" placeholder="Descreva a necessidade desta requisição..."><?= e($requisicao['justificativa'] ?? $justificativaSugerida) ?></textarea>
                        </div>
                    </div>

                    <hr>
                    <h6 class="fw-bold mb-3">Itens</h6>

                    <div id="itensContainer">
                    <?php if (!empty($itens_req)): ?>
                        <?php foreach ($itens_req as $ir): ?>
                        <?php if (!empty($ir['descricao_livre'])): ?>
                        <?php
                        // Na Ordem de Compra a linha salva tem o mesmo formato do pedido
                        // novo: quantidade e valor não são digitados (vão ocultos), porque
                        // o preço é cotado por fornecedor lá embaixo.
                        ?>
                        <?php if ($ehOrdemCompra): ?>
                        <div class="item-row item-row-livre row g-2 align-items-end mb-2">
                            <input type="hidden" name="item_tipo[]" value="livre">
                            <input type="hidden" name="item_id[]" value="">
                            <input type="hidden" name="quantidade[]" value="<?= e((string)$ir['quantidade']) ?>">
                            <input type="hidden" name="valor_unit[]" value="<?= e((string)$ir['valor_unitario']) ?>">
                            <div class="col-md-7">
                                <label class="form-label small fw-semibold">Descrição *</label>
                                <input type="text" name="descricao_livre[]" class="form-control form-control-sm item-descricao" required
                                       placeholder="Descreva o item..." value="<?= e($ir['descricao_livre']) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Anexo</label>
                                <?php if (!empty($ir['anexo'])): ?>
                                <a href="<?= e(\App\Controllers\AnexoController::url($ir['anexo'])) ?>" target="_blank" class="d-block small text-truncate mb-1">
                                    <i class="bi bi-paperclip me-1"></i><?= e(basename($ir['anexo'])) ?>
                                </a>
                                <?php endif; ?>
                                <input type="file" name="anexo[]" class="form-control form-control-sm" accept="image/*,.pdf">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-item"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="item-row item-row-livre row g-2 align-items-end mb-2">
                            <input type="hidden" name="item_tipo[]" value="livre">
                            <input type="hidden" name="item_id[]" value="">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Descrição *</label>
                                <input type="text" name="descricao_livre[]" class="form-control form-control-sm item-descricao" required
                                       placeholder="Descreva o item..." value="<?= e($ir['descricao_livre']) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Qtd *</label>
                                <input type="number" name="quantidade[]" class="form-control form-control-sm" min="0.01" step="0.01" required value="<?= $ir['quantidade'] ?>">
                            </div>
                            <div class="col-md-2 campo-valor <?= $tipoAtual !== 'compra' ? 'd-none' : '' ?>">
                                <label class="form-label small fw-semibold">Valor Unit. (R$)</label>
                                <input type="number" name="valor_unit[]" class="form-control form-control-sm" min="0" step="0.01" value="<?= $ir['valor_unitario'] ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Anexo</label>
                                <?php if (!empty($ir['anexo'])): ?>
                                <a href="<?= e(\App\Controllers\AnexoController::url($ir['anexo'])) ?>" target="_blank" class="d-block small text-truncate mb-1">
                                    <i class="bi bi-paperclip me-1"></i><?= e(basename($ir['anexo'])) ?>
                                </a>
                                <?php endif; ?>
                                <input type="file" name="anexo[]" class="form-control form-control-sm" accept="image/*,.pdf">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-item"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="item-row row g-2 align-items-end mb-2">
                            <input type="hidden" name="item_tipo[]" value="catalogo">
                            <input type="hidden" name="descricao_livre[]" value="">
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Grupo *</label>
                                <select name="grupo_item_id[]" class="form-select form-select-sm grupo-item-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($grupos as $g): ?>
                                        <option value="<?= $g['id'] ?>" <?= ($ir['grupo_produto_id'] ?? '') === $g['id'] ? 'selected' : '' ?>><?= e($g['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Item *</label>
                                <?php
                                $itemLabel = '';
                                foreach ($itens as $itCheck) {
                                    if ($itCheck['id'] === ($ir['item_id'] ?? '')) {
                                        $itemLabel = $itCheck['nome'] . ' (' . $itCheck['unidade'] . ')';
                                        break;
                                    }
                                }
                                ?>
                                <div class="item-combo position-relative">
                                    <input type="text" class="form-control form-control-sm item-search" placeholder="Pesquisar item..." autocomplete="off" value="<?= e($itemLabel) ?>">
                                    <input type="hidden" name="item_id[]" class="item-id-hidden" value="<?= e($ir['item_id'] ?? '') ?>">
                                    <div class="item-dropdown-list"></div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Qtd *</label>
                                <input type="number" name="quantidade[]" class="form-control form-control-sm" min="0.01" step="0.01" required value="<?= $ir['quantidade'] ?>">
                            </div>
                            <div class="col-md-2 campo-valor <?= $tipoAtual !== 'compra' ? 'd-none' : '' ?>">
                                <label class="form-label small fw-semibold">Valor Unit. (R$)</label>
                                <input type="number" name="valor_unit[]" class="form-control form-control-sm" min="0" step="0.01" value="<?= $ir['valor_unitario'] ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-item"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    <?php elseif ($ehOrdemCompra): ?>
                        <div class="item-row item-row-livre row g-2 align-items-end mb-2">
                            <input type="hidden" name="item_tipo[]" value="livre">
                            <input type="hidden" name="item_id[]" value="">
                            <input type="hidden" name="quantidade[]" value="1">
                            <input type="hidden" name="valor_unit[]" value="0">
                            <div class="col-md-7">
                                <label class="form-label small fw-semibold">Descrição *</label>
                                <input type="text" name="descricao_livre[]" class="form-control form-control-sm item-descricao" required
                                       placeholder="Descreva o item...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Anexo</label>
                                <input type="file" name="anexo[]" class="form-control form-control-sm" accept="image/*,.pdf">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-item"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="item-row row g-2 align-items-end mb-2">
                            <input type="hidden" name="item_tipo[]" value="catalogo">
                            <input type="hidden" name="descricao_livre[]" value="">
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Grupo *</label>
                                <select name="grupo_item_id[]" class="form-select form-select-sm grupo-item-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($grupos as $g): ?>
                                        <option value="<?= $g['id'] ?>"><?= e($g['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Item *</label>
                                <div class="item-combo position-relative">
                                    <input type="text" class="form-control form-control-sm item-search" placeholder="Pesquisar item..." autocomplete="off">
                                    <input type="hidden" name="item_id[]" class="item-id-hidden">
                                    <div class="item-dropdown-list"></div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Qtd *</label>
                                <input type="number" name="quantidade[]" class="form-control form-control-sm" min="0.01" step="0.01" required>
                            </div>
                            <div class="col-md-2 campo-valor <?= $tipoAtual !== 'compra' ? 'd-none' : '' ?>">
                                <label class="form-label small fw-semibold">Valor Unit. (R$)</label>
                                <input type="number" name="valor_unit[]" class="form-control form-control-sm" min="0" step="0.01">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-item"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    <?php endif; ?>
                    </div>

                    <div class="d-flex gap-2 mt-2">
                        <button type="button" id="addItem" class="btn btn-outline-primary btn-sm<?= $ehOrdemCompra ? ' d-none' : '' ?>">
                            <i class="bi bi-plus-lg me-1"></i>Adicionar Item
                        </button>
                        <button type="button" id="addItemLivre" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-pencil-square me-1"></i>Item sem cadastro
                        </button>
                    </div>

                    <?php $disCot = $cotacoesTravadas ? 'disabled' : ''; ?>
                    <div id="blocoCotacoes" class="<?= $ehOrdemCompra ? '' : 'd-none' ?>" data-travado="<?= $cotacoesTravadas ? '1' : '0' ?>">
                        <hr>
                        <h6 class="fw-bold mb-1">Cotações</h6>
                        <?php if ($cotacoesTravadas): ?>
                        <p class="form-text mt-0 text-warning">
                            <i class="bi bi-lock-fill me-1"></i>Esta ordem de compra já foi enviada — as cotações não podem mais ser alteradas.
                        </p>
                        <?php else: ?>
                        <p class="form-text mt-0">
                            Política de Compras: <?= e($regraCotacoes ?? '2 cotações até R$ 1.000,00; 3 acima') ?>
                            — conta o valor da maior cotação.
                        </p>
                        <?php endif; ?>

                        <div id="cotacoesContainer">
                        <?php $opcoesPagamento = ['À vista', '30 dias', '30/60', '30/60/90', '28/35/42']; ?>
                        <?php foreach ($cotacoesForm as $indice => $c): ?>
                        <?php
                        $pagAtual   = trim((string)($c['condicao_pagamento'] ?? ''));
                        $ehOutroPag = $pagAtual !== '' && !in_array($pagAtual, $opcoesPagamento, true);
                        ?>
                            <div class="cotacao-row card border mb-2"
                                 data-itens="<?= e(json_encode($c['itens'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)) ?>">
                                <div class="card-body py-3">
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span class="fw-semibold small">Cotação <?= $indice + 1 ?></span>
                                        <span class="badge bg-success d-none badge-menor">Menor preço</span>
                                        <?php if (!$cotacoesTravadas): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto remove-cotacao"><i class="bi bi-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Fornecedor</label>
                                            <div class="d-flex gap-1">
                                                <div class="item-combo position-relative flex-grow-1">
                                                    <input type="text" class="form-control form-control-sm cot-busca"
                                                           role="combobox" aria-expanded="false" aria-autocomplete="list"
                                                           placeholder="Digite 2 letras do nome..." autocomplete="off"
                                                           value="<?= e($c['fornecedor'] ?? '') ?>" <?= $disCot ?>>
                                                    <div class="item-dropdown-list cot-menu"></div>
                                                </div>
                                                <input type="hidden" name="cot_fornecedor_id[]" class="cot-id" value="<?= e($c['fornecedor_id'] ?? '') ?>">
                                                <?php if (!$cotacoesTravadas): ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary cot-cadastrar" title="Cadastrar fornecedor novo">
                                                    <i class="bi bi-plus-lg"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                            <div class="form-text cot-aviso text-danger d-none">Fornecedor não encontrado. Use o + para cadastrar.</div>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small fw-semibold">Frete (R$)</label>
                                            <input type="number" name="cot_frete[]" class="form-control form-control-sm cot-frete"
                                                   min="0" step="0.01" value="<?= $c['frete'] !== '' ? e((string)$c['frete']) : '' ?>" <?= $disCot ?>>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small fw-semibold">Pagamento</label>
                                            <select class="form-select form-select-sm cot-pagamento-opcao" <?= $disCot ?>>
                                                <option value="">Selecione...</option>
                                                <?php foreach ($opcoesPagamento as $opcao): ?>
                                                <option value="<?= e($opcao) ?>" <?= $pagAtual === $opcao ? 'selected' : '' ?>><?= e($opcao) ?></option>
                                                <?php endforeach; ?>
                                                <option value="__outro__" <?= $ehOutroPag ? 'selected' : '' ?>>Outro...</option>
                                            </select>
                                            <input type="hidden" name="cot_pagamento[]" class="cot-pagamento" value="<?= e($pagAtual) ?>">
                                            <input type="text" class="form-control form-control-sm mt-1 cot-pagamento-livre <?= $ehOutroPag ? '' : 'd-none' ?>"
                                                   maxlength="60" placeholder="Ex.: 45/75"
                                                   value="<?= $ehOutroPag ? e($pagAtual) : '' ?>" <?= $disCot ?>>
                                        </div>
                                    </div>
                                    <div class="row g-2 mt-1">
                                        <div class="col-md-9">
                                            <table class="table table-sm align-middle mb-0 cot-itens">
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="border rounded p-2 text-end h-100">
                                                <div class="small text-muted">Total com frete</div>
                                                <div class="fw-bold fs-6 cot-total">R$ 0,00</div>
                                                <div class="small text-muted cot-itens-resumo"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input cot-vencedora" type="radio" name="cot_vencedora"
                                               value="<?= $indice ?>" id="venc<?= $indice ?>" <?= (int)($c['vencedora'] ?? 0) === 1 ? 'checked' : '' ?> <?= $disCot ?>>
                                        <label class="form-check-label small" for="venc<?= $indice ?>">Selecionar como cotação vencedora</label>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>

                        <?php if (!$cotacoesTravadas): ?>
                        <button type="button" id="addCotacao" class="btn btn-outline-primary btn-sm mt-1">
                            <i class="bi bi-plus-lg me-1"></i>Adicionar Cotação
                        </button>
                        <?php endif; ?>

                        <h6 class="fw-bold mt-4 mb-2">Comparativo</h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle" id="tabelaComparativo">
                                <thead class="table-light">
                                    <tr><th>Fornecedor</th><th class="text-end">Itens</th><th class="text-end">Frete</th><th class="text-end">Total</th><th>Pagamento</th></tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                    <hr>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-floppy me-1"></i>Salvar Rascunho
                        </button>
                        <a href="<?= url('requisicoes') ?>" class="btn btn-outline-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold text-primary">
                <i class="bi bi-info-circle me-1"></i>Verificação de Saldo
            </div>
            <div class="card-body" id="saldoInfo">
                <p class="text-muted small">Adicione os itens e preencha os valores para verificar o saldo disponível por grupo.</p>
            </div>
        </div>

        <?php if ($user['perfil'] !== 'solicitante' || is_matriz_user()): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-boxes me-1 text-info"></i>Estoque Disponível
            </div>
            <div class="card-body" id="estoqueInfo">
                <p class="text-muted small mb-0" id="estoqueVazio">
                    <i class="bi bi-arrow-left me-1"></i>Selecione um item para ver o saldo em estoque.
                </p>
                <div id="estoqueDetalhes" class="d-none">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="fs-4 fw-bold text-info" id="estoqueTotal">0</span>
                        <span class="text-muted small" id="estoqueUnidade"></span>
                        <span class="ms-auto badge" id="estoqueBadge"></span>
                    </div>
                    <div id="estoquePorFilial" class="small"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.item-dropdown-list {
    position: absolute; top: 100%; left: 0; right: 0; z-index: 1060;
    display: none; background: #fff;
    border: 1px solid #dee2e6; border-top: none;
    border-radius: 0 0 .375rem .375rem;
    max-height: 220px; overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,.12);
}
.item-opt {
    padding: .35rem .75rem; cursor: pointer; font-size: .8125rem;
    border-bottom: 1px solid #f0f0f0;
}
.item-opt:hover, .item-opt:focus, .item-opt.active { background: #e8f4fd; }
.item-opt-empty { padding: .35rem .75rem; color: #aaa; font-size: .8125rem; font-style: italic; }
</style>

<script>
const TODOS_GRUPOS = <?php echo json_encode(array_map(fn($g) => [
    'id' => $g['id'],
    'nome' => $g['nome'],
], $grupos), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

// ── Toggle Compra / Interno ───────────────────────────────────────────────────
function setTipo(tipo) {
    document.getElementById('inputTipo').value = tipo;

    const btnCompra        = document.getElementById('btnCompra');
    const btnRequisicao    = document.getElementById('btnRequisicaoInterna');
    const btnTransferencia = document.getElementById('btnTransferencia');
    const descricao        = document.getElementById('tipoDescricao');

    const descricoes = {
        compra:        'Aquisição de produtos ou serviços externos.',
        interna:        'Movimentação de estoque interna (fluxo direto, sem confirmação de recebimento).',
        transferencia: 'Transferência de estoque a partir da matriz, com confirmação de recebimento.',
    };
    descricao.textContent = descricoes[tipo] ?? descricoes.compra;

    if (btnCompra) {
        btnCompra.className = tipo === 'compra' ? 'btn btn-primary' : 'btn btn-outline-primary';
    }
    // Os três botões são condicionais agora (permissão por tipo): qualquer um
    // pode não existir na página.
    if (btnRequisicao) {
        btnRequisicao.className = tipo === 'interna' ? 'btn btn-warning' : 'btn btn-outline-warning';
    }
    if (btnTransferencia) {
        btnTransferencia.className = tipo === 'transferencia' ? 'btn btn-success' : 'btn btn-outline-success';
    }

    // Campos exclusivos de compra (Tipo de Despesa)
    document.querySelectorAll('.campos-compra').forEach(el => {
        el.classList.toggle('d-none', tipo !== 'compra');
    });
    // tipo_despesa só é obrigatório na compra (oculto na interna/transferência)
    const selDespesa = document.querySelector('.select-tipo-despesa');
    if (selDespesa) selDespesa.required = (tipo === 'compra');

    // Finalidade e "necessário até" (Política de Compras): Compra e Ordem de Compra
    const ehCompraPolitica = (tipo === 'compra' || tipo === 'ordem_compra');
    document.querySelectorAll('.campos-politica').forEach(el => el.classList.toggle('d-none', !ehCompraPolitica));
    document.querySelectorAll('.select-politica').forEach(el => { el.required = ehCompraPolitica; });

    const ehMovimentacaoEstoque = (tipo === 'interna' || tipo === 'transferencia');
    document.querySelectorAll('.campos-transferencia').forEach(el => {
        el.classList.toggle('d-none', tipo !== 'transferencia');
    });
    const filialDestino = document.querySelector('.select-filial-destino');
    if (filialDestino) filialDestino.required = (tipo === 'transferencia');
    if (tipo === 'interna') syncFilialDestinoInterna();

    // Setor Solicitante travado na Requisição Interna (ver comentário no campo).
    // O input desabilitado não é enviado, então só um dos dois chega ao POST.
    const setorFixoWrap  = document.getElementById('setorFixoWrap');
    const setorFixoInput = document.getElementById('setorFixoInput');
    const setorSelect    = document.getElementById('setorSelect');
    if (setorFixoWrap && setorFixoInput && setorSelect) {
        const travarSetor = (tipo === 'interna');
        setorFixoWrap.classList.toggle('d-none', !travarSetor);
        setorFixoInput.disabled = !travarSetor;
        setorSelect.classList.toggle('d-none', travarSetor);
        setorSelect.disabled = travarSetor;
    }

    // Coluna valor por item (catálogo e livre)
    document.querySelectorAll('.campo-valor').forEach(el => {
        el.classList.toggle('d-none', tipo !== 'compra');
    });

    // Linhas livres: reaplica estilo visual de destaque
    document.querySelectorAll('.item-row-livre').forEach(el => {
        el.style.background = tipo !== 'compra' ? '' : '';
    });

    const btnOrdemCompra = document.getElementById('btnOrdemCompra');
    if (btnOrdemCompra) {
        btnOrdemCompra.className = tipo === 'ordem_compra' ? 'btn btn-info' : 'btn btn-outline-info';
    }
    const blocoCotacoes = document.getElementById('blocoCotacoes');
    if (blocoCotacoes) blocoCotacoes.classList.toggle('d-none', tipo !== 'ordem_compra');

    ajustarItensOrdemCompra(tipo);
}

// A Ordem de Compra só aceita item sem cadastro: some o botão de item de
// catálogo e as linhas de catálogo ainda em branco saem de cena. Linha com item
// já escolhido fica (quem trocou de tipo sem querer não perde o que digitou) —
// o servidor ignora essas linhas na gravação.
function ajustarItensOrdemCompra(tipo) {
    const ehOc = (tipo === 'ordem_compra');
    const btnCatalogo = document.getElementById('addItem');
    if (btnCatalogo) btnCatalogo.classList.toggle('d-none', ehOc);

    if (!ehOc) return;

    const container = document.getElementById('itensContainer');
    if (!container) return;

    container.querySelectorAll('.item-row:not(.item-row-livre)').forEach(row => {
        if (!row.querySelector('.item-id-hidden')?.value) row.remove();
    });

    if (!container.querySelector('.item-row')) {
        container.insertAdjacentHTML('beforeend', buildItemLivreTemplate());
        marcarObrigatorios(container);
        sincronizarObrigatorios();
    }
}

// Requisição interna: material fica na própria unidade — mantém filial_destino_id
// sincronizada com a unidade requisitante sem exibir o campo ao usuário.
function syncFilialDestinoInterna() {
    if (document.getElementById('inputTipo').value !== 'interna') return;
    const filialOrigem = document.querySelector('[name="filial_id"]');
    const filialDestino = document.querySelector('.select-filial-destino');
    if (filialOrigem && filialDestino) filialDestino.value = filialOrigem.value;
}

// Diretor/admin pode trocar a unidade requisitante — o saldo usado para filtrar
// o combo de itens (requisição interna) precisa ser recarregado para a nova unidade.
function refreshEstoqueUnidade() {
    const filialOrigem = document.querySelector('select[name="filial_id"]');
    if (!filialOrigem || !filialOrigem.value) return;
    fetch(`<?= url('api/estoque/filial') ?>/${filialOrigem.value}`)
        .then(r => r.json())
        .then(saldoPorItem => {
            TODOS_ITENS.forEach(it => { it.saldo_unidade = parseFloat(saldoPorItem[it.id] || 0); });
            // A regra acompanha a unidade escolhida, senão o combo continuaria
            // aplicando a flag da unidade anterior.
            USA_ESTOQUE_MATRIZ = !!FLAGS_MATRIZ[filialOrigem.value];
            filtrarItensPorGrupo();
        })
        .catch(() => {});
}
document.querySelector('select[name="filial_id"]')?.addEventListener('change', syncFilialDestinoInterna);
document.querySelector('select[name="filial_id"]')?.addEventListener('change', refreshEstoqueUnidade);

function buildItemTemplate() {
    const tipo = document.getElementById('inputTipo').value;
    const valorCol = tipo === 'compra'
        ? `<div class="col-md-2 campo-valor">
            <label class="form-label small fw-semibold">Valor Unit. (R$)</label>
            <input type="number" name="valor_unit[]" class="form-control form-control-sm" min="0" step="0.01" placeholder="Valor unit.">
           </div>`
        : `<div class="col-md-2 campo-valor d-none">
            <label class="form-label small fw-semibold">Valor Unit. (R$)</label>
            <input type="number" name="valor_unit[]" class="form-control form-control-sm" min="0" step="0.01" placeholder="Valor unit.">
           </div>`;

    return `
<div class="item-row row g-2 align-items-end mb-2">
    <input type="hidden" name="item_tipo[]" value="catalogo">
    <input type="hidden" name="descricao_livre[]" value="">
    <div class="col-md-3">
        <select name="grupo_item_id[]" class="form-select form-select-sm grupo-item-select" required>
            <option value="">Selecione o grupo...</option>
            ${TODOS_GRUPOS.map(g => `<option value="${g.id}">${g.nome}</option>`).join('')}
        </select>
    </div>
    <div class="col-md-3">
        <div class="item-combo position-relative">
            <input type="text" class="form-control form-control-sm item-search" placeholder="Pesquisar item..." autocomplete="off">
            <input type="hidden" name="item_id[]" class="item-id-hidden">
            <div class="item-dropdown-list"></div>
        </div>
    </div>
    <div class="col-md-2">
        <input type="number" name="quantidade[]" class="form-control form-control-sm" min="0.01" step="0.01" placeholder="Qtd" required>
    </div>
    ${valorCol}
    <div class="col-md-2">
        <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-item"><i class="bi bi-trash"></i></button>
    </div>
</div>`;
}

function buildItemLivreTemplate() {
    const tipo = document.getElementById('inputTipo').value;

    // Ordem de Compra: o item é só descrição (+ anexo) — quantidade e valor vão
    // ocultos porque requisicao_itens.quantidade é NOT NULL e gravarItens()
    // descarta linha com quantidade zero. O valor é digitado nas cotações.
    if (tipo === 'ordem_compra') {
        return `
<div class="item-row item-row-livre row g-2 align-items-end mb-2">
    <input type="hidden" name="item_tipo[]" value="livre">
    <input type="hidden" name="item_id[]" value="">
    <input type="hidden" name="quantidade[]" value="1">
    <input type="hidden" name="valor_unit[]" value="0">
    <div class="col-md-7">
        <label class="form-label small fw-semibold">Descrição *</label>
        <input type="text" name="descricao_livre[]" class="form-control form-control-sm item-descricao" required placeholder="Descreva o item...">
    </div>
    <div class="col-md-3">
        <label class="form-label small fw-semibold">Anexo</label>
        <input type="file" name="anexo[]" class="form-control form-control-sm" accept="image/*,.pdf">
    </div>
    <div class="col-md-2 d-flex align-items-end">
        <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-item"><i class="bi bi-trash"></i></button>
    </div>
</div>`;
    }

    const valorCol = tipo === 'compra'
        ? `<div class="col-md-2 campo-valor">
            <label class="form-label small fw-semibold">Valor Unit. (R$)</label>
            <input type="number" name="valor_unit[]" class="form-control form-control-sm" min="0" step="0.01" placeholder="Valor unit.">
           </div>`
        : `<div class="col-md-2 campo-valor d-none">
            <label class="form-label small fw-semibold">Valor Unit. (R$)</label>
            <input type="number" name="valor_unit[]" class="form-control form-control-sm" min="0" step="0.01" placeholder="Valor unit.">
           </div>`;

    return `
<div class="item-row item-row-livre row g-2 align-items-end mb-2">
    <input type="hidden" name="item_tipo[]" value="livre">
    <input type="hidden" name="item_id[]" value="">
    <div class="col-md-4">
        <label class="form-label small fw-semibold">Descrição *</label>
        <input type="text" name="descricao_livre[]" class="form-control form-control-sm item-descricao" required placeholder="Descreva o item...">
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Qtd *</label>
        <input type="number" name="quantidade[]" class="form-control form-control-sm" min="0.01" step="0.01" placeholder="Qtd" required>
    </div>
    ${valorCol}
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Anexo</label>
        <input type="file" name="anexo[]" class="form-control form-control-sm" accept="image/*,.pdf">
    </div>
    <div class="col-md-2 d-flex align-items-end">
        <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-item"><i class="bi bi-trash"></i></button>
    </div>
</div>`;
}

document.getElementById('addItem').addEventListener('click', () => {
    document.getElementById('itensContainer').insertAdjacentHTML('beforeend', buildItemTemplate());
    // Linha nova nasce com required no HTML: registra e sincroniza, senão ela
    // volta a bloquear o envio enquanto estiver intocada.
    marcarObrigatorios(document.getElementById('itensContainer'));
    sincronizarObrigatorios();
    setTimeout(() => verificarEstoque('', ''), 10);
});

document.getElementById('addItemLivre').addEventListener('click', () => {
    document.getElementById('itensContainer').insertAdjacentHTML('beforeend', buildItemLivreTemplate());
    marcarObrigatorios(document.getElementById('itensContainer'));
    sincronizarObrigatorios();
});

document.getElementById('itensContainer').addEventListener('click', e => {
    if (e.target.closest('.remove-item')) {
        const rows = document.querySelectorAll('.item-row');
        if (rows.length > 1) {
            e.target.closest('.item-row').remove();
            sincronizarObrigatorios();
        }
    }
});

// Verifica estoque ao selecionar item
function verificarEstoque(itemId, unidade) {
    const vazio    = document.getElementById('estoqueVazio');
    const detalhes = document.getElementById('estoqueDetalhes');
    const total    = document.getElementById('estoqueTotal');
    const badge    = document.getElementById('estoqueBadge');
    const porFilial= document.getElementById('estoquePorFilial');
    const unEl     = document.getElementById('estoqueUnidade');

    if (!vazio || !detalhes) return; // card oculto (ex: solicitante fora da matriz)

    if (!itemId) {
        vazio.classList.remove('d-none');
        detalhes.classList.add('d-none');
        return;
    }

    fetch(`<?= url('api/estoque/item') ?>/${itemId}`)
        .then(r => r.json())
        .then(data => {
            vazio.classList.add('d-none');
            detalhes.classList.remove('d-none');
            unEl.textContent = unidade || '';

            // Requisição interna/transferência: mostra somente o saldo já
            // existente na própria unidade requisitante, não de outras filiais.
            const tipo = document.getElementById('inputTipo').value;
            let estoques = data.estoques;
            if (tipo === 'interna' || tipo === 'transferencia') {
                const filialOrigemId = document.querySelector('[name="filial_id"]')?.value;
                estoques = estoques.filter(e => e.filial_id === filialOrigemId);
            }
            const totalExibido = estoques.reduce((soma, e) => soma + parseFloat(e.quantidade), 0);

            total.textContent = totalExibido.toLocaleString('pt-BR', {maximumFractionDigits: 2});

            if (totalExibido > 0) {
                badge.className   = 'ms-auto badge bg-success';
                badge.textContent = 'Em estoque';
            } else {
                badge.className   = 'ms-auto badge bg-warning text-dark';
                badge.textContent = 'Sem estoque';
            }

            if (estoques.length > 0) {
                porFilial.innerHTML = estoques.map(e =>
                    `<div class="d-flex justify-content-between border-bottom py-1">
                        <span class="text-truncate me-2">${e.filial_nome}</span>
                        <span class="fw-semibold">${parseFloat(e.quantidade).toLocaleString('pt-BR', {maximumFractionDigits: 2})}</span>
                    </div>`
                ).join('');
            } else {
                porFilial.innerHTML = '<p class="text-muted mb-0">Nenhum saldo registrado.</p>';
            }
        })
        .catch(() => {
            vazio.classList.remove('d-none');
            detalhes.classList.add('d-none');
        });
}

// ── Combobox pesquisável ──────────────────────────────────────────────────────
function populateCombo(searchInput, grupoId, query) {
    const combo = searchInput.closest('.item-combo');
    const dropdown = combo.querySelector('.item-dropdown-list');
    const q = query.toLowerCase().trim();
    let lista = grupoId ? TODOS_ITENS.filter(it => it.grupo === grupoId) : TODOS_ITENS;
    if (q) lista = lista.filter(it => it.nome.toLowerCase().includes(q));
    const tipoAtual = document.getElementById('inputTipo')?.value;
    if (tipoAtual === 'interna') {
        // Unidade marcada com "usa estoque da matriz" enxerga as duas fontes.
        // Somar em vez de trocar espelha a cascata da baixa, que tenta a
        // própria unidade antes de recorrer à matriz.
        lista = lista.filter(it =>
            (it.saldo_unidade > 0 || (USA_ESTOQUE_MATRIZ && it.saldo_matriz > 0)) && !it.eh_brinde
        );
    } else if (tipoAtual === 'transferencia') {
        lista = lista.filter(it => it.saldo_matriz > 0 && !it.eh_brinde);
    }
    if (!lista.length) {
        dropdown.innerHTML = '<div class="item-opt-empty">Nenhum item encontrado</div>';
        return;
    }
    dropdown.innerHTML = lista.map(it =>
        `<div class="item-opt" data-id="${it.id}" data-label="${it.nome} (${it.unidade})" data-unidade="${it.unidade}" data-grupo="${it.grupo}">${it.nome} <span class="text-muted">(${it.unidade})</span></div>`
    ).join('');
}

const container = document.getElementById('itensContainer');

container.addEventListener('focusin', function(e) {
    if (!e.target.classList.contains('item-search')) return;
    const row = e.target.closest('.item-row');
    const grupoId = row ? (row.querySelector('.grupo-item-select')?.value || '') : '';
    populateCombo(e.target, grupoId, e.target.value);
    e.target.closest('.item-combo').querySelector('.item-dropdown-list').style.display = 'block';
});

container.addEventListener('input', function(e) {
    if (!e.target.classList.contains('item-search')) return;
    const combo = e.target.closest('.item-combo');
    combo.querySelector('.item-id-hidden').value = '';
    const row = e.target.closest('.item-row');
    const grupoId = row ? (row.querySelector('.grupo-item-select')?.value || '') : '';
    populateCombo(e.target, grupoId, e.target.value);
    combo.querySelector('.item-dropdown-list').style.display = 'block';
});

container.addEventListener('mousedown', function(e) {
    const opt = e.target.closest('.item-opt');
    if (!opt) return;
    e.preventDefault();
    const combo = opt.closest('.item-combo');
    combo.querySelector('.item-search').value = opt.dataset.label;
    combo.querySelector('.item-id-hidden').value = opt.dataset.id;
    combo.querySelector('.item-search').classList.remove('is-invalid');
    combo.querySelector('.item-dropdown-list').style.display = 'none';
    verificarEstoque(opt.dataset.id, opt.dataset.unidade);

    const row = opt.closest('.item-row');
    const grupoSelect = row ? row.querySelector('.grupo-item-select') : null;
    if (grupoSelect && opt.dataset.grupo) {
        grupoSelect.value = opt.dataset.grupo;
        grupoSelect.classList.remove('is-invalid');
    }
});

container.addEventListener('focusout', function(e) {
    if (!e.target.classList.contains('item-search')) return;
    setTimeout(() => {
        const combo = e.target.closest('.item-combo');
        if (!combo) return;
        combo.querySelector('.item-dropdown-list').style.display = 'none';
        if (!combo.querySelector('.item-id-hidden').value) e.target.value = '';
    }, 150);
});

container.addEventListener('change', function(e) {
    if (!e.target.classList.contains('grupo-item-select')) return;
    const row = e.target.closest('.item-row');
    const combo = row.querySelector('.item-combo');
    if (combo) {
        combo.querySelector('.item-search').value = '';
        combo.querySelector('.item-id-hidden').value = '';
        combo.querySelector('.item-dropdown-list').style.display = 'none';
    }
    verificarEstoque('', '');
});

// ── Filtragem de itens por grupo ─────────────────────────────────────────────
const TODOS_ITENS = <?php echo json_encode(array_map(fn($it) => [
    'id'      => $it['id'],
    'nome'    => $it['nome'],
    'unidade' => $it['unidade'],
    'grupo'   => $it['grupo_produto_id'],
    'grupo_nome' => $it['grupo_nome'] ?? 'Sem grupo',
    'saldo_unidade' => (float)($estoqueUnidade[$it['id']] ?? 0),
    'saldo_matriz'  => (float)($estoqueMatriz[$it['id']] ?? 0),
    'eh_brinde'     => in_array($it['id'], $itensBrindeIds, true),
], $itens), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

// Mapa filial_id => 0|1 e a flag da unidade atual. USA_ESTOQUE_MATRIZ é `let`
// porque muda quando o diretor troca a unidade requisitante.
const FLAGS_MATRIZ = <?php echo json_encode($flagsMatrizPorFilial ?? [], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
let USA_ESTOQUE_MATRIZ = <?php echo !empty($usaEstoqueMatriz) ? 'true' : 'false'; ?>;

function filtrarItensPorGrupo() {
    // O combo de fornecedor das cotações reaproveita a classe .item-combo só
    // pelo visual: ele não tem .item-search/.item-id-hidden, então é pulado.
    document.querySelectorAll('.item-combo').forEach(combo => {
        const busca = combo.querySelector('.item-search');
        if (!busca) return;
        busca.value = '';
        combo.querySelector('.item-id-hidden').value = '';
        combo.querySelector('.item-dropdown-list').style.display = 'none';
    });
    verificarEstoque('', '');
}

// ── Linhas vazias não podem bloquear o envio ─────────────────────────────────
// O formulário nasce com uma linha de catálogo, e os campos dela são required.
// Quem usa só "Item sem cadastro" era barrado por essa linha que nunca preencheu.
// O servidor já ignora linha sem quantidade (RequisicaoController::criar), então
// o required é mais rígido que a regra real: aqui ele é desligado enquanto a
// linha estiver intocada e religado assim que o usuário mexer nela.
//
// Isso precisa acontecer FORA do submit: quando o form é inválido o navegador
// nem dispara o evento submit, então um handler não teria como afrouxar nada.
function linhaEmUso(row) {
    const val = sel => (row.querySelector(sel)?.value ?? '').trim();
    const qtd = parseFloat(val('[name="quantidade[]"]').replace(',', '.')) || 0;

    return qtd > 0
        || val('[name="item_id[]"]') !== ''
        || val('[name="descricao_livre[]"]') !== ''
        || val('[name="grupo_item_id[]"]') !== '';
}

function sincronizarObrigatorios() {
    document.querySelectorAll('.item-row').forEach(row => {
        const emUso = linhaEmUso(row);
        row.querySelectorAll('[data-required-orig]').forEach(campo => {
            campo.required = emUso;
            if (!emUso) campo.setCustomValidity('');
        });
    });
}

// Marca quais campos são obrigatórios "por natureza", para poder alternar sem
// perder a informação original quando o required é removido.
function marcarObrigatorios(escopo) {
    escopo.querySelectorAll('.item-row [required], .item-row [data-required-orig]').forEach(campo => {
        campo.dataset.requiredOrig = '1';
    });
}

marcarObrigatorios(document);
sincronizarObrigatorios();

document.getElementById('itensContainer').addEventListener('input',  sincronizarObrigatorios);
document.getElementById('itensContainer').addEventListener('change', sincronizarObrigatorios);

// A validação abaixo usa setCustomValidity, que só era limpo na tentativa de
// envio seguinte — o campo continuava vermelho mesmo depois de corrigido. Limpa
// assim que o usuário mexe no campo.
document.getElementById('itensContainer').addEventListener('input', e => {
    const campo = e.target;
    if (typeof campo.setCustomValidity === 'function' && campo.validationMessage) {
        campo.setCustomValidity('');
        campo.classList.remove('is-invalid');
    }
});

// Validação: combos de catálogo precisam ter item selecionado; itens livres em compra precisam de valor
// Ligado ao formulário por id: document.querySelector('form') pegava a form de
// LOGOUT do layout, que vem antes no DOM — e toda esta validação nunca rodava.
document.getElementById('formReq').addEventListener('submit', function(e) {
    // O blur da busca de fornecedor é adiado: sem isto, quem digita o nome e
    // clica direto em Salvar manda a cotação sem fornecedor_id (I2).
    resolverFornecedores();

    let valid = true;
    const tipoReq = document.querySelector('[name="tipo"]')?.value ?? 'compra';

    if (![...document.querySelectorAll('.item-row')].some(linhaEmUso)) {
        alert('Adicione ao menos um item com quantidade informada.');
        e.preventDefault();
        return;
    }

    document.querySelectorAll('.item-row').forEach(row => {
        if (!linhaEmUso(row)) return;   // linha intocada: o servidor a ignora
        const tipoInput = row.querySelector('[name="item_tipo[]"]');
        if (!tipoInput) return;

        if (tipoInput.value === 'livre') {
            if (tipoReq === 'compra') {
                const valorInput = row.querySelector('[name="valor_unit[]"]');
                if (valorInput && (parseFloat(valorInput.value) || 0) <= 0) {
                    valorInput.classList.add('is-invalid');
                    valorInput.setCustomValidity('Informe o valor estimado do item.');
                    valid = false;
                } else if (valorInput) {
                    valorInput.classList.remove('is-invalid');
                    valorInput.setCustomValidity('');
                }
            }
            return;
        }

        const combo  = row.querySelector('.item-combo');
        if (!combo) return;
        const hidden = combo.querySelector('.item-id-hidden');
        const search = combo.querySelector('.item-search');
        if (!hidden.value) {
            search.classList.add('is-invalid');
            search.setCustomValidity('Selecione um item da lista.');
            valid = false;
        } else {
            search.classList.remove('is-invalid');
            search.setCustomValidity('');
        }
    });
    if (!valid) e.preventDefault();
});
// ─────────────────────────────────────────────────────────────────────────────

// ── Cotações da Ordem de Compra ───────────────────────────────────────────────
const FORNECEDORES = <?= json_encode(array_map(fn($f) => ['id' => $f['id'], 'nome' => $f['nome'], 'cnpj' => $f['cnpj'] ?? ''], $fornecedores ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function normalizaTexto(valor) {
    return (valor || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

function formataCnpj(cnpj) {
    const digitos = (cnpj || '').replace(/\D/g, '');
    if (digitos.length !== 14) return cnpj || '';
    return digitos.slice(0, 2) + '.' + digitos.slice(2, 5) + '.' + digitos.slice(5, 8)
         + '/' + digitos.slice(8, 12) + '-' + digitos.slice(12);
}

function fornecedorPorNome(nome) {
    const alvo = normalizaTexto(nome);
    return FORNECEDORES.find(f => normalizaTexto(f.nome) === alvo) || null;
}

// Rede de segurança do envio: garante que toda linha com nome digitado leve o id.
function resolverFornecedores() {
    document.querySelectorAll('.cotacao-row').forEach(row => {
        const busca = row.querySelector('.cot-busca');
        const idInp = row.querySelector('.cot-id');
        if (!busca || !idInp || idInp.value) return;
        const achado = fornecedorPorNome(busca.value);
        if (achado) idInp.value = achado.id;
    });
}

// ── Itens dentro de cada cotação ─────────────────────────────────────────────
// A lista de itens do pedido é a fonte: cada cotação mostra as mesmas linhas, na
// mesma ordem, e é a POSIÇÃO que liga valor ao item (o id da linha de item muda a
// cada salvamento). Item "excluído" continua no formulário, só sai do total.
function itensDoPedido() {
    return Array.from(document.querySelectorAll('#itensContainer .item-row-livre .item-descricao'))
        .map(inp => inp.value.trim())
        .filter(desc => desc !== '');
}

function estadoItensCotacao(row) {
    return Array.from(row.querySelectorAll('.cot-item-linha')).map(linha => ({
        valor:  linha.querySelector('.cot-item-valor').value,
        cotado: linha.querySelector('.cot-item-cotado').value === '1',
    }));
}

function aplicarEstadoItem(tr, cotado) {
    const travado = document.getElementById('blocoCotacoes')?.dataset.travado === '1';
    const desc  = tr.querySelector('.cot-item-desc');
    const valor = tr.querySelector('.cot-item-valor');
    const botao = tr.querySelector('.cot-item-toggle');
    desc.classList.toggle('text-decoration-line-through', !cotado);
    desc.classList.toggle('text-muted', !cotado);
    valor.readOnly = !cotado || travado;
    if (botao) {
        botao.innerHTML = cotado ? '<i class="bi bi-x-lg"></i>' : '<i class="bi bi-arrow-counterclockwise"></i>';
        botao.title = cotado ? 'Este fornecedor não cotou este item' : 'Voltar a cotar este item';
    }
}

function desenharItensCotacao(row, indice) {
    const corpo = row.querySelector('.cot-itens tbody');
    if (!corpo) return;

    const travado    = document.getElementById('blocoCotacoes')?.dataset.travado === '1';
    const anterior   = estadoItensCotacao(row);
    const descricoes = itensDoPedido();

    // Primeira montagem de uma cotação já salva: o estado vem do banco, via
    // data-itens. Depois disso vale o que está na tela (o usuário pode ter
    // digitado), então o atributo é consumido uma única vez.
    let semente = [];
    if (!anterior.length && row.dataset.itens) {
        try {
            semente = JSON.parse(row.dataset.itens) || [];
        } catch (e) {
            semente = [];
        }
        delete row.dataset.itens;
    }

    corpo.innerHTML = '';

    if (!descricoes.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 3;
        td.className = 'small text-muted';
        td.textContent = 'Descreva os itens acima para cotar.';
        tr.appendChild(td);
        corpo.appendChild(tr);
        return;
    }

    descricoes.forEach((descricao, posicao) => {
        const daSemente = semente.find(item => Number(item.posicao) === posicao);
        const guardado  = anterior[posicao]
            || (daSemente ? { valor: String(daSemente.valor), cotado: Number(daSemente.cotado) === 1 } : { valor: '', cotado: true });

        const tr = document.createElement('tr');
        tr.className = 'cot-item-linha';

        const tdDesc = document.createElement('td');
        tdDesc.className = 'small cot-item-desc';
        tdDesc.textContent = descricao;
        tr.appendChild(tdDesc);

        const tdValor = document.createElement('td');
        tdValor.style.width = '9rem';
        const valor = document.createElement('input');
        valor.type = 'number';
        valor.min = '0';
        valor.step = '0.01';
        valor.className = 'form-control form-control-sm cot-item-valor';
        valor.name = `cot_item_valor[${indice}][]`;
        valor.value = guardado.valor;
        valor.placeholder = 'R$';
        // readonly (e não disabled): input desabilitado não é enviado, e a
        // posição no POST precisa bater com a lista de itens.
        valor.readOnly = travado || !guardado.cotado;
        valor.addEventListener('input', () => atualizarComparativo());
        tdValor.appendChild(valor);

        const cotado = document.createElement('input');
        cotado.type = 'hidden';
        cotado.className = 'cot-item-cotado';
        cotado.name = `cot_item_cotado[${indice}][]`;
        cotado.value = guardado.cotado ? '1' : '0';
        tdValor.appendChild(cotado);
        tr.appendChild(tdValor);

        const tdBotao = document.createElement('td');
        tdBotao.style.width = '3rem';
        if (!travado) {
            const botao = document.createElement('button');
            botao.type = 'button';
            botao.className = 'btn btn-sm btn-outline-secondary cot-item-toggle';
            botao.addEventListener('click', () => {
                const agora = cotado.value === '1' ? '0' : '1';
                cotado.value = agora;
                aplicarEstadoItem(tr, agora === '1');
                atualizarComparativo();
            });
            tdBotao.appendChild(botao);
        }
        tr.appendChild(tdBotao);

        aplicarEstadoItem(tr, guardado.cotado);
        corpo.appendChild(tr);
    });
}

function sincronizarItensCotacoes() {
    document.querySelectorAll('.cotacao-row').forEach((row, indice) => desenharItensCotacao(row, indice));
}

function lerCotacoes() {
    return Array.from(document.querySelectorAll('.cotacao-row')).map(row => {
        const linhas  = Array.from(row.querySelectorAll('.cot-item-linha'));
        const cotados = linhas.filter(l => l.querySelector('.cot-item-cotado').value === '1');
        const valor = cotados.reduce(
            (soma, l) => soma + (parseFloat(l.querySelector('.cot-item-valor').value || '0') || 0), 0);
        const frete = parseFloat(row.querySelector('.cot-frete').value || '0') || 0;

        return {
            row,
            nome:  row.querySelector('.cot-busca').value.trim(),
            id:    row.querySelector('.cot-id').value,
            valor, frete,
            total: valor + frete,
            itensCotados: cotados.length,
            itensTotal:   linhas.length,
            pagamento: row.querySelector('.cot-pagamento').value,
            radio: row.querySelector('.cot-vencedora'),
        };
    });
}

function atualizarComparativo() {
    const cotacoes = lerCotacoes();
    const validas  = cotacoes.filter(c => c.id && c.valor > 0);
    const menor    = validas.length ? Math.min(...validas.map(c => c.total)) : null;
    // Declarado aqui (e não junto da tabela comparativa): o total de cada cartão
    // é escrito no laço abaixo, que roda antes — e const não sobe (TDZ).
    const brl = v => 'R$ ' + Number(v).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    cotacoes.forEach(c => {
        c.row.querySelector('.badge-menor').classList.toggle('d-none', !(menor !== null && c.total === menor && c.id && c.valor > 0));
        c.row.classList.toggle('border-success', !!c.radio.checked);

        const alvoTotal = c.row.querySelector('.cot-total');
        if (alvoTotal) alvoTotal.textContent = brl(c.total);
        const resumo = c.row.querySelector('.cot-itens-resumo');
        if (resumo) {
            resumo.textContent = c.itensTotal
                ? `${c.itensCotados} de ${c.itensTotal} ${c.itensTotal === 1 ? 'item' : 'itens'}`
                : '';
        }
    });

    // Sugere a mais barata quando o usuário ainda não escolheu
    if (validas.length && !cotacoes.some(c => c.radio.checked)) {
        const melhor = validas.find(c => c.total === menor);
        if (melhor) melhor.radio.checked = true;
    }

    const corpo = document.querySelector('#tabelaComparativo tbody');
    if (!corpo) return;

    // c.nome (nome do fornecedor digitado/cadastrado na hora) e c.pagamento
    // (texto livre) NÃO são de confiança — montar via createElement/textContent
    // em vez de template string + innerHTML evita que um nome ou condição de
    // pagamento com HTML/script vire XSS ao entrar na tabela (C3).
    corpo.innerHTML = '';
    const ordenadas = validas.slice().sort((a, b) => a.total - b.total);
    if (!ordenadas.length) {
        const trVazio = document.createElement('tr');
        const tdVazio = document.createElement('td');
        tdVazio.colSpan = 5;
        tdVazio.className = 'text-center text-muted small py-3';
        tdVazio.textContent = 'Preencha as cotações para comparar.';
        trVazio.appendChild(tdVazio);
        corpo.appendChild(trVazio);
        return;
    }

    ordenadas.forEach(c => {
        const ehMenor = c.total === menor;
        const tr = document.createElement('tr');

        const tdNome = document.createElement('td');
        tdNome.className = 'small';
        tdNome.appendChild(document.createTextNode(c.nome));
        if (ehMenor) {
            tdNome.appendChild(document.createTextNode(' '));
            const badge = document.createElement('span');
            badge.className = 'badge bg-success';
            badge.textContent = 'melhor';
            tdNome.appendChild(badge);
        }
        tr.appendChild(tdNome);

        const tdItens = document.createElement('td');
        tdItens.className = 'text-end small';
        tdItens.textContent = c.itensTotal ? `${c.itensCotados}/${c.itensTotal}` : '—';
        tr.appendChild(tdItens);

        const tdFrete = document.createElement('td');
        tdFrete.className = 'text-end small text-muted';
        tdFrete.textContent = brl(c.frete);
        tr.appendChild(tdFrete);

        const tdTotal = document.createElement('td');
        tdTotal.className = 'text-end fw-semibold' + (ehMenor ? ' text-success' : '');
        tdTotal.textContent = brl(c.total);
        tr.appendChild(tdTotal);

        const tdPagamento = document.createElement('td');
        tdPagamento.className = 'small';
        tdPagamento.textContent = c.pagamento || '—';
        tr.appendChild(tdPagamento);

        corpo.appendChild(tr);
    });
}

function ligarBuscaFornecedor(row) {
    const busca = row.querySelector('.cot-busca');
    const idInp = row.querySelector('.cot-id');
    const aviso = row.querySelector('.cot-aviso');
    const menu  = row.querySelector('.cot-menu');
    if (!busca || !menu) return;

    let marcado = -1;

    // Visual reaproveitado do combo de itens desta mesma tela: as classes
    // .item-dropdown-list/.item-opt já têm CSS no topo do arquivo, e a
    // visibilidade é por style.display, como lá.
    function fecha() {
        menu.style.display = 'none';
        menu.innerHTML = '';
        busca.setAttribute('aria-expanded', 'false');
        marcado = -1;
    }

    function escolhe(fornecedor) {
        busca.value = fornecedor.nome;
        idInp.value = fornecedor.id;
        aviso.classList.add('d-none');
        fecha();
        atualizarComparativo();
    }

    function marca(indice) {
        const opcoes = menu.querySelectorAll('.cot-opcao');
        if (!opcoes.length) return;
        marcado = (indice + opcoes.length) % opcoes.length;
        opcoes.forEach((el, i) => el.classList.toggle('active', i === marcado));
        opcoes[marcado].scrollIntoView({ block: 'nearest' });
    }

    function abre() {
        const termo = normalizaTexto(busca.value);
        menu.innerHTML = '';
        marcado = -1;
        if (termo.length < 2) { fecha(); return; }

        const achados = FORNECEDORES.filter(f => normalizaTexto(f.nome).includes(termo)).slice(0, 8);
        if (!achados.length) { fecha(); return; }

        // createElement/textContent, nunca innerHTML com dado do banco (XSS).
        achados.forEach(f => {
            const opcao = document.createElement('div');
            opcao.className = 'item-opt cot-opcao';

            const nome = document.createElement('div');
            nome.className = 'fw-semibold';
            nome.textContent = f.nome;

            const doc = document.createElement('div');
            doc.className = 'text-muted';
            doc.textContent = formataCnpj(f.cnpj);

            opcao.appendChild(nome);
            opcao.appendChild(doc);
            // mousedown (e não click): dispara antes do blur do input.
            opcao.addEventListener('mousedown', e => { e.preventDefault(); escolhe(f); });
            menu.appendChild(opcao);
        });

        menu.style.display = 'block';
        busca.setAttribute('aria-expanded', 'true');
    }

    busca.addEventListener('input', () => {
        // Resolve de forma síncrona: se o usuário digitar/colar o nome inteiro e
        // clicar direto em Salvar, o blur (que é adiado) não roda a tempo e a
        // cotação seria descartada em silêncio.
        const exato = fornecedorPorNome(busca.value);
        idInp.value = exato ? exato.id : '';
        aviso.classList.add('d-none');
        abre();
        atualizarComparativo();
    });

    busca.addEventListener('keydown', e => {
        const aberto = menu.style.display === 'block';
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (aberto) { marca(marcado + 1); } else { abre(); }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (aberto) marca(marcado - 1);
        } else if (e.key === 'Enter' && aberto && marcado >= 0) {
            e.preventDefault();
            menu.querySelectorAll('.cot-opcao')[marcado].dispatchEvent(new MouseEvent('mousedown'));
        } else if (e.key === 'Escape') {
            fecha();
        }
    });

    busca.addEventListener('blur', () => {
        // Espera o mousedown da opção resolver antes de julgar o texto digitado.
        setTimeout(() => {
            fecha();
            // Escolha do menu vence: dois fornecedores podem ter o mesmo nome com
            // CNPJs diferentes, e fornecedorPorNome() devolve sempre o primeiro.
            const atual = FORNECEDORES.find(f => f.id === idInp.value);
            if (atual && normalizaTexto(atual.nome) === normalizaTexto(busca.value)) {
                aviso.classList.add('d-none');
                atualizarComparativo();
                return;
            }
            const achado = fornecedorPorNome(busca.value);
            idInp.value = achado ? achado.id : '';
            aviso.classList.toggle('d-none', !!achado || busca.value.trim() === '');
            atualizarComparativo();
        }, 120);
    });
}

function ligarPagamento(row) {
    const opcao = row.querySelector('.cot-pagamento-opcao');
    const livre = row.querySelector('.cot-pagamento-livre');
    const campo = row.querySelector('.cot-pagamento');
    if (!opcao || !livre || !campo) return;

    function sincroniza() {
        const ehOutro = opcao.value === '__outro__';
        livre.classList.toggle('d-none', !ehOutro);
        campo.value = ehOutro ? livre.value.trim() : opcao.value;
        atualizarComparativo();
    }

    opcao.addEventListener('change', () => {
        if (opcao.value === '__outro__') livre.focus();
        sincroniza();
    });
    livre.addEventListener('input', sincroniza);
    sincroniza();
}

function ligarLinhaCotacao(row) {
    ligarBuscaFornecedor(row);
    ligarPagamento(row);

    const busca = row.querySelector('.cot-busca');
    const idInp = row.querySelector('.cot-id');
    const aviso = row.querySelector('.cot-aviso');

    // O valor por item liga o próprio listener em desenharItensCotacao().
    row.querySelectorAll('.cot-frete, .cot-vencedora')
       .forEach(el => el.addEventListener('input', atualizarComparativo));

    // A linha travada (C1) não tem esses dois botões no HTML — querySelector
    // devolve null, então o listener só é ligado se o botão existir.
    row.querySelector('.remove-cotacao')?.addEventListener('click', () => {
        if (document.querySelectorAll('.cotacao-row').length <= 1) return;
        row.remove();
        renumerarCotacoes();
        atualizarComparativo();
    });

    row.querySelector('.cot-cadastrar')?.addEventListener('click', () => {
        const nome = (busca.value || '').trim();
        if (!nome) { alert('Digite o nome do fornecedor antes de cadastrar.'); return; }
        const cnpj = prompt('CNPJ do fornecedor "' + nome + '" (só números):', '');
        if (cnpj === null) return;

        const dados = new FormData();
        dados.append('nome', nome);
        dados.append('cnpj', cnpj);
        // csrf_verify() aceita o token no campo _csrf_token OU no header
        // X-CSRF-Token — este fetch (fora do <form>, então sem csrf_field())
        // manda pelo header, lendo a mesma meta tag que o layout já expõe (C2).
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        fetch('<?= url('api/fornecedores/rapido') ?>', {
            method: 'POST',
            body: dados,
            headers: { 'X-CSRF-Token': csrfToken },
        })
            .then(r => r.json())
            .then(resp => {
                if (resp.erro) { alert(resp.erro); return; }
                FORNECEDORES.push({ id: resp.id, nome: resp.nome, cnpj: resp.cnpj || '' });
                busca.value = resp.nome;
                idInp.value = resp.id;
                aviso.classList.add('d-none');
                atualizarComparativo();
            })
            .catch(() => alert('Não foi possível cadastrar o fornecedor agora.'));
    });
}

function renumerarCotacoes() {
    document.querySelectorAll('.cotacao-row').forEach((row, i) => {
        row.querySelector('.fw-semibold.small').textContent = 'Cotação ' + (i + 1);
        const radio = row.querySelector('.cot-vencedora');
        radio.value = i;
        radio.id = 'venc' + i;
        row.querySelector('.form-check-label').setAttribute('for', 'venc' + i);
        // Os nomes cot_item_valor[i][] / cot_item_cotado[i][] carregam o índice
        // da cotação: redesenha para eles acompanharem a renumeração.
        desenharItensCotacao(row, i);
    });
}

document.getElementById('addCotacao')?.addEventListener('click', () => {
    const container = document.getElementById('cotacoesContainer');
    const nova = container.querySelector('.cotacao-row').cloneNode(true);
    nova.querySelectorAll('input').forEach(inp => {
        if (inp.type === 'radio') { inp.checked = false; } else { inp.value = ''; }
    });
    nova.querySelectorAll('select').forEach(sel => { sel.selectedIndex = 0; });
    nova.querySelector('.cot-pagamento-livre')?.classList.add('d-none');
    const menuNovo = nova.querySelector('.cot-menu');
    if (menuNovo) { menuNovo.innerHTML = ''; menuNovo.style.display = 'none'; }
    nova.querySelector('.cot-aviso').classList.add('d-none');
    nova.querySelector('.badge-menor').classList.add('d-none');
    // O clone traz a tabela de itens da cotação copiada (com os valores dela):
    // zera antes de redesenhar, para a cotação nova nascer em branco.
    const corpoNovo = nova.querySelector('.cot-itens tbody');
    if (corpoNovo) corpoNovo.innerHTML = '';
    // O data-itens do modelo já foi consumido, mas se sobrar no clone a cotação
    // nova nasceria com os valores da copiada.
    delete nova.dataset.itens;
    container.appendChild(nova);
    ligarLinhaCotacao(nova);
    desenharItensCotacao(nova, document.querySelectorAll('.cotacao-row').length - 1);
    renumerarCotacoes();
    atualizarComparativo();
});

document.querySelectorAll('.cotacao-row').forEach(ligarLinhaCotacao);

// Qualquer mexida na lista de itens redesenha as cotações (preservando valores).
document.getElementById('itensContainer')?.addEventListener('input', e => {
    if (e.target.classList.contains('item-descricao')) sincronizarItensCotacoes();
});
document.getElementById('itensContainer')?.addEventListener('click', e => {
    if (e.target.closest('.remove-item')) setTimeout(sincronizarItensCotacoes, 0);
});
document.getElementById('addItemLivre')?.addEventListener('click', () => setTimeout(sincronizarItensCotacoes, 0));

sincronizarItensCotacoes();
atualizarComparativo();
</script>
