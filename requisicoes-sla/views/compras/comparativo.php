<?php
// @versao Ver.006.301026
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('compras/cotacoes') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Comparativo de Cotação</h4>
</div>

<?php if (empty($fornecedores)): ?>
<div class="alert alert-info">Aguardando respostas dos fornecedores.</div>
<?php else: ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Respostas por Fornecedor</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Fornecedor</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Itens Respondidos</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fornecedores as $f): ?>
                    <?php
                        $ocTotal = 0;
                        if (!empty($itensComparativos[$f['id']])) {
                            foreach ($itensComparativos[$f['id']] as $_it) {
                                $ocTotal += (float)$_it['quantidade'] * (float)$_it['valor_unitario'];
                            }
                        }
                        // Frete cotado com o fornecedor (cotacao_fornecedores.frete) — não entra
                        // no valor_total da OC (isso é só produtos), mas o comprador precisa ver
                        // o total real do pedido antes de confirmar.
                        $ocFrete = (float)($f['frete'] ?? 0);
                    ?>
                    <tr>
                        <td><?= e($f['nome_fantasia'] ?? $f['razao_social']) ?></td>
                        <td class="text-center"><?= status_badge($f['status']) ?></td>
                        <td class="text-center"><?= (int)($f['qtd_itens_respondidos'] ?? 0) ?></td>
                        <td>
                            <?php if ($f['status'] === 'respondida'): ?>
                            <button type="button" class="btn btn-success btn-sm"
                                    onclick="abrirModalOC(
                                        '<?= $f['id'] ?>',
                                        '<?= $f['fornecedor_id'] ?>',
                                        '<?= e(addslashes($f['nome_fantasia'] ?? $f['razao_social'])) ?>',
                                        <?= number_format($ocTotal, 2, '.', '') ?>,
                                        <?= number_format($ocFrete, 2, '.', '') ?>
                                    )">
                                <i class="bi bi-check-circle me-1"></i>Gerar OC
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Detalhamento dos itens por fornecedor -->
<?php foreach ($fornecedores as $f): ?>
    <?php if (!empty($itensComparativos[$f['id']])): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-box-seam me-1"></i>Itens — <?= e($f['nome_fantasia'] ?? $f['razao_social']) ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Item</th>
                            <th>Unidade</th>
                            <th class="text-center">Quantidade</th>
                            <th class="text-end">Valor Unitário</th>
                            <th class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $totalFornecedor = 0; ?>
                        <?php foreach ($itensComparativos[$f['id']] as $item): ?>
                            <?php $subtotal = (float)$item['quantidade'] * (float)$item['valor_unitario']; $totalFornecedor += $subtotal; ?>
                            <tr>
                                <td><?= e($item['item_nome']) ?></td>
                                <td><?= e($item['unidade']) ?></td>
                                <td class="text-center"><?= number_format($item['quantidade'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= money($item['valor_unitario']) ?></td>
                                <td class="text-end"><?= money($subtotal) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="4" class="text-end">Total:</td>
                            <td class="text-end"><?= money($totalFornecedor) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php endif; ?>

<!-- Modal Gerar OC -->
<div class="modal fade" id="modalGerarOC" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 text-white fw-bold"
                 style="background:#198754;border-radius:8px 8px 0 0">
                <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Gerar Ordem de Compra</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="formGerarOC"
                  action="<?= url('compras/cotacoes/' . $cotacao['id'] . '/gerar-oc') ?>">
                      <?= csrf_field() ?>
                <input type="hidden" name="cotacao_fornecedor_id" id="ocCotFornId">
                <?php if (empty($cotacao['requisicao_id'])): ?>
                <div class="px-4 pt-3" id="ocBlocoRequisicao">
                    <div class="alert alert-warning py-2 px-3 small mb-2">
                        <i class="bi bi-link-45deg me-1"></i>
                        Cotação avulsa: escolha a requisição que esta compra atende.
                    </div>
                    <select name="requisicao_id" id="ocRequisicao" class="form-select form-select-sm mb-2" required>
                        <option value="">Selecione a requisição</option>
                    </select>
                    <div class="alert alert-danger py-2 px-3 small mb-2 d-none" id="ocSemRequisicao">
                        Nenhuma requisição aprovada, sem ordem de compra, contém todos os itens cotados.
                        Crie ou aprove uma requisição com esses itens antes de gerar a OC.
                    </div>
                </div>
                <?php endif; ?>
                <div class="px-4 pt-3 d-none" id="ocBlocoExcesso">
                    <div class="alert alert-warning py-2 px-3 small mb-2">
                        <strong><i class="bi bi-exclamation-triangle me-1"></i>Esta compra ultrapassa o pedido da requisição.</strong>
                        <ul class="mb-2 mt-2 ps-3" id="ocListaExcesso"></ul>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="confirma_excesso" value="1" id="ocConfirmaExcesso">
                            <label class="form-check-label fw-semibold" for="ocConfirmaExcesso">
                                Confirmo a compra acima do que foi pedido
                            </label>
                        </div>
                    </div>
                </div>
                <script>
                    const REQS_COMPATIVEIS = <?= json_encode($requisicoesCompativeis, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
                    const EXCESSOS = <?= json_encode($excessos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
                    const OC_PRAZOS = <?= json_encode((object)($prazosOc ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
                </script>
                <div class="modal-body px-4 py-3">
                    <p class="mb-3">
                        Confirmar geração de OC para o fornecedor
                        <strong id="ocFornNome"></strong>?
                    </p>

                    <!-- Painel fase 2: obrigatórios na OC -->
                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold mb-1" for="ocEntregaPrevista">Entrega prevista *</label>
                            <input type="date" name="entrega_prevista" id="ocEntregaPrevista" class="form-control form-control-sm"
                                   min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold mb-1" for="ocCondicaoPagamentoOc">Condição de pagamento *</label>
                            <input type="text" name="condicao_pagamento" id="ocCondicaoPagamentoOc" class="form-control form-control-sm"
                                   maxlength="<?= \App\Services\CondicaoPagamento::MAX_TEXTO ?>" placeholder="Ex.: 30/60/90 ou à vista"
                                   autocomplete="off" required>
                            <div class="form-text mt-1" id="ocCondicaoPagamentoOcDica"></div>
                        </div>
                        <div class="col-12 form-text mt-0 d-none" id="ocCondicaoPagamento"></div>
                    </div>

                    <!-- Política de Compras §8: mínimo de cotações -->
                    <?php $abaixoMinimo = ($cotacoesValidas ?? 0) < ($minimoPolitica ?? 0); ?>
                    <div class="alert <?= $abaixoMinimo ? 'alert-warning' : 'alert-light border' ?> py-2 px-3 small mb-3">
                        <i class="bi bi-clipboard-check me-1"></i>
                        Política de Compras: <?= e($regraCotacoes ?? '') ?>.
                        Respondidas: <strong><?= (int)($cotacoesValidas ?? 0) ?></strong>.
                        <label class="form-label small fw-semibold mt-2 mb-1" for="ocJustificativaExcecao">
                            Justificativa de exceção <?= $abaixoMinimo ? '(obrigatória)' : '(só se ficar abaixo do mínimo)' ?>
                        </label>
                        <textarea name="justificativa_excecao" id="ocJustificativaExcecao" class="form-control form-control-sm" rows="2"
                                  minlength="15" <?= $abaixoMinimo ? 'required' : '' ?>
                                  placeholder="Ex.: fornecedor exclusivo da peça; emergência com veículo de cliente parado"></textarea>
                    </div>

                    <!-- Flag de crédito — sempre visível -->
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="usar_credito"
                                   id="ocChkCredito" value="1" onchange="ocAtualizarResumo()">
                            <label class="form-check-label fw-semibold" for="ocChkCredito">
                                Aplicar crédito nesta OC
                            </label>
                        </div>
                        <div id="ocValorCreditoWrap" class="d-none mt-2 ms-3">
                            <label class="form-label small fw-semibold mb-1">Valor do crédito a usar (R$):</label>
                            <input type="text" class="form-control form-control-sm" name="valor_credito"
                                   id="ocValorCredito" placeholder="0,00"
                                   oninput="ocAtualizarResumo()">
                            <div class="form-text text-muted" id="ocValorCreditoMax"></div>
                        </div>
                    </div>

                    <!-- Resumo financeiro -->
                    <div class="p-2 rounded" style="background:#f8f9fa;border:1px solid #dee2e6">
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">Produtos (OC):</span>
                            <span class="fw-bold" id="ocResumoTotal"></span>
                        </div>
                        <div class="d-flex justify-content-between small d-none" id="ocResumoFreteWrap">
                            <span class="text-muted">Frete previsto:</span>
                            <span id="ocResumoFrete"></span>
                        </div>
                        <div class="d-flex justify-content-between small d-none" id="ocResumoGeralWrap">
                            <span class="text-muted">Total do pedido:</span>
                            <span id="ocResumoGeral"></span>
                        </div>
                        <div id="ocResumoExtra" class="d-none">
                            <div class="d-flex justify-content-between small mt-1 text-success">
                                <span><i class="bi bi-dash-circle me-1"></i>Crédito a aplicar:</span>
                                <span class="fw-bold" id="ocResumoCreditoVal">R$ 0,00</span>
                            </div>
                            <hr class="my-1">
                            <div class="d-flex justify-content-between small fw-bold">
                                <span>A cobrar do fornecedor:</span>
                                <span id="ocResumoCobrVal" class="text-primary"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Créditos disponíveis via AJAX (informativo) -->
                    <div id="ocCreditosLoading" class="text-muted small d-none mt-2">
                        <i class="bi bi-hourglass-split me-1"></i>Verificando créditos...
                    </div>
                    <div id="ocCreditosBox" class="d-none mt-2">
                        <div class="p-2 rounded small" style="background:#f0fdf4;border:1px solid #bbf7d0">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-semibold text-success">
                                    <i class="bi bi-credit-card me-1"></i>Créditos disponíveis
                                </span>
                                <span class="fw-bold text-success" id="ocCreditoTotal"></span>
                            </div>
                            <div id="ocCreditoLista" class="text-muted"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary btn-sm"
                            data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success btn-sm fw-bold px-4">
                        <i class="bi bi-check-lg me-1"></i>Confirmar e Gerar OC
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include ROOT . '/views/partials/condicao_pagamento_js.php'; ?>
<script>
const OC_APP_BASE = '<?= rtrim($_ENV['APP_URL'] ?? '', '/') ?>';
let _ocTotal = 0;
let _ocFrete = 0;
let _ocMaxCredito = 0;

function ocFmt(val) {
    return 'R$ ' + parseFloat(val).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function ocAtualizarResumo() {
    const chk = document.getElementById('ocChkCredito');
    const inp = document.getElementById('ocValorCredito');

    if (chk.checked) {
        document.getElementById('ocValorCreditoWrap').classList.remove('d-none');
    } else {
        document.getElementById('ocValorCreditoWrap').classList.add('d-none');
        inp.value = '';
    }

    const creditoStr = (inp.value || '0').replace(/\./g, '').replace(',', '.');
    let creditoVal = chk.checked ? (parseFloat(creditoStr) || 0) : 0;
    if (_ocMaxCredito > 0 && creditoVal > _ocMaxCredito) creditoVal = _ocMaxCredito;
    if (creditoVal > _ocTotal) creditoVal = _ocTotal;

    const extra = document.getElementById('ocResumoExtra');
    if (chk.checked && creditoVal > 0) {
        extra.classList.remove('d-none');
    } else {
        extra.classList.add('d-none');
    }
    document.getElementById('ocResumoCreditoVal').textContent = ocFmt(creditoVal);
    document.getElementById('ocResumoCobrVal').textContent    = ocFmt(Math.max(0, _ocTotal - creditoVal));
}

async function abrirModalOC(cotFornId, fornId, fornNome, ocTotal, ocFrete) {
    _ocTotal      = parseFloat(ocTotal) || 0;
    _ocFrete      = parseFloat(ocFrete) || 0;
    _ocMaxCredito = 0;

    document.getElementById('ocCotFornId').value         = cotFornId;
    document.getElementById('ocFornNome').textContent    = fornNome;

    // Entrega prevista / condição de pagamento pré-preenchidas (requisição ou condição
    // da proposta). Trocou de fornecedor: a condição é a dele; reabriu o mesmo: o que
    // foi digitado fica (CondicaoPagamento.preencherAuto só troca vazio/auto).
    const prazos = (typeof OC_PRAZOS !== 'undefined' && OC_PRAZOS[cotFornId]) || {};
    document.getElementById('ocEntregaPrevista').value = prazos.entrega || '';
    const condInp = document.getElementById('ocCondicaoPagamentoOc');
    if (condInp.dataset.forn !== String(cotFornId)) {
        condInp.value = '';
        condInp.dataset.auto = '';
        condInp.dataset.forn = String(cotFornId);
    }
    CondicaoPagamento.preencherAuto(condInp, prazos.condicao_oc || '');
    CondicaoPagamento.atualizarDica(condInp, document.getElementById('ocCondicaoPagamentoOcDica'));
    const condEl = document.getElementById('ocCondicaoPagamento');
    condEl.textContent = prazos.condicao ? 'Condição de pagamento da proposta: ' + prazos.condicao : '';
    condEl.classList.toggle('d-none', !prazos.condicao);

    // Cotação avulsa: repopula o seletor com as requisições compatíveis COM ESTE
    // fornecedor — a regra depende do que ele cotou, então muda a cada botão.
    // O seletor de requisição só existe na cotação avulsa. Guardar pelo elemento,
    // e não por REQS_COMPATIVEIS estar definida: a constante passou a ser sempre
    // definida (o aviso de excesso precisa dela nos dois casos), então checar só
    // ela fazia este bloco rodar na cotação normal e estourar em sel.innerHTML —
    // a exceção abortava o resto do abrirModalOC, inclusive o aviso de excesso.
    const sel = document.getElementById('ocRequisicao');
    if (sel && typeof REQS_COMPATIVEIS !== 'undefined') {
        const aviso = document.getElementById('ocSemRequisicao');
        const lista = REQS_COMPATIVEIS[cotFornId] || [];

        sel.innerHTML = '<option value="">Selecione a requisição</option>';
        lista.forEach(r => {
            const opt = document.createElement('option');
            opt.value       = r.id;
            opt.textContent = r.label;
            sel.appendChild(opt);
        });

        const temOpcao = lista.length > 0;
        sel.classList.toggle('d-none', !temOpcao);
        sel.required = temOpcao;              // sem opção, não bloqueia o submit no browser
        aviso.classList.toggle('d-none', temOpcao);
    }

    // Recalcula o aviso de excesso. Na avulsa a chave inclui a requisição, então
    // muda a cada troca do seletor.
    function atualizarExcesso() {
        const bloco  = document.getElementById('ocBlocoExcesso');
        const lista  = document.getElementById('ocListaExcesso');
        const check  = document.getElementById('ocConfirmaExcesso');
        const selReq = document.getElementById('ocRequisicao');

        const chave = selReq ? cotFornId + '|' + selReq.value : cotFornId;
        const itens = (typeof EXCESSOS !== 'undefined' && (EXCESSOS[chave] || EXCESSOS[cotFornId])) || [];

        lista.innerHTML = '';
        itens.forEach(e => {
            const li = document.createElement('li');
            li.textContent = e.nome + ': pedido ' + e.pedida
                           + ', já comprado ' + e.comprada
                           + ', comprando agora ' + e.agora
                           + ' — excede em ' + e.excesso;
            lista.appendChild(li);
        });

        const temExcesso = itens.length > 0;
        bloco.classList.toggle('d-none', !temExcesso);
        check.required = temExcesso;
        if (!temExcesso) check.checked = false;
    }

    atualizarExcesso();
    document.getElementById('ocRequisicao')?.addEventListener('change', atualizarExcesso);

    document.getElementById('ocResumoTotal').textContent = ocFmt(_ocTotal);
    document.getElementById('ocResumoFreteWrap').classList.toggle('d-none', _ocFrete <= 0);
    document.getElementById('ocResumoFrete').textContent = ocFmt(_ocFrete);
    document.getElementById('ocResumoGeralWrap').classList.toggle('d-none', _ocFrete <= 0);
    document.getElementById('ocResumoGeral').textContent = ocFmt(_ocTotal + _ocFrete);
    document.getElementById('ocResumoExtra').classList.add('d-none');
    document.getElementById('ocCreditosBox').classList.add('d-none');
    document.getElementById('ocCreditosLoading').classList.add('d-none');
    document.getElementById('ocChkCredito').checked      = false;
    document.getElementById('ocValorCreditoWrap').classList.add('d-none');
    document.getElementById('ocValorCredito').value      = '';
    document.getElementById('ocValorCreditoMax').textContent = '';

    new bootstrap.Modal(document.getElementById('modalGerarOC')).show();

    const loading = document.getElementById('ocCreditosLoading');
    loading.classList.remove('d-none');
    try {
        const r    = await fetch(`${OC_APP_BASE}/api/fornecedor/${fornId}/creditos`);
        const data = await r.json();
        loading.classList.add('d-none');

        const total = parseFloat(data.total) || 0;
        if (total > 0) {
            _ocMaxCredito = total;
            document.getElementById('ocCreditoTotal').textContent = ocFmt(total);
            document.getElementById('ocCreditoLista').innerHTML = (data.creditos || []).map(c =>
                `<div>• ${c.descricao} — <strong>${ocFmt(c.valor)}</strong>` +
                (c.validade
                    ? ` <span class="text-warning">(vence ${c.validade.split('-').reverse().join('/')})</span>`
                    : '') +
                `</div>`
            ).join('');
            document.getElementById('ocValorCreditoMax').textContent = 'Máximo disponível: ' + ocFmt(total);
            document.getElementById('ocCreditosBox').classList.remove('d-none');
        }
    } catch {
        loading.classList.add('d-none');
    }
}

CondicaoPagamento.ligar(document.getElementById('ocCondicaoPagamentoOc'), document.getElementById('ocCondicaoPagamentoOcDica'));
</script>