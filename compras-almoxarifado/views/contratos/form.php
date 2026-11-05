<?php $title = $contrato ? 'Editar Contrato' : 'Novo Contrato'; ?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('contratos') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-check me-2 text-primary"></i><?= $title ?></h4>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST"
                      action="<?= $contrato ? url('contratos/' . $contrato['id']) : url('contratos') ?>"
                      enctype="multipart/form-data">
                          <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Fornecedor *</label>
                            <select name="fornecedor_id" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($fornecedores as $f): ?>
                                <option value="<?= $f['id'] ?>" <?= ($contrato['fornecedor_id'] ?? '') === $f['id'] ? 'selected' : '' ?>>
                                    <?= e($f['razao_social']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Número do Contrato</label>
                            <?php if ($contrato): ?>
                            <input type="text" name="numero" class="form-control" value="<?= e($contrato['numero'] ?? '') ?>">
                            <?php else: ?>
                            <div class="input-group">
                                <input type="text" name="numero" id="inputNumeroContrato" class="form-control"
                                       value="<?= e($proximoNumero ?? '') ?>" readonly>
                                <button type="button" class="btn btn-outline-secondary" title="Editar manualmente"
                                        onclick="const i=document.getElementById('inputNumeroContrato');i.readOnly=!i.readOnly;this.innerHTML=i.readOnly?'<i class=\'bi bi-pencil\'></i>':'<i class=\'bi bi-lock\'></i>'">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            </div>
                            <div class="form-text">Gerado automaticamente. Clique no lápis para editar.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Objeto / Descrição *</label>
                            <textarea name="objeto" class="form-control" rows="2" required><?= e($contrato['objeto'] ?? '') ?></textarea>
                        </div>
                        <!-- Flag: Sem valor fixo -->
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="semValor"
                                       name="sem_valor" value="1"
                                       <?= !empty($contrato['sem_valor']) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="semValor">
                                    Contrato sem valor fixo
                                </label>
                                <div class="form-text">Marque quando o valor varia (ex: serviços sob demanda, contratos de faturamento variável).</div>
                            </div>
                        </div>

                        <!-- Campos de valor (ocultos quando sem valor fixo) -->
                        <div id="blocoCamposValor">
                        <!-- Valor Mensal (sempre visível) -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Valor Mensal (R$) *</label>
                            <input type="text" name="valor_mensal" id="valorMensal" class="form-control"
                                   value="<?= ($contrato && empty($contrato['sem_valor'])) ? number_format((float)($contrato['valor_mensal'] ?? $contrato['valor']), 2, ',', '.') : '' ?>"
                                   placeholder="0,00">
                        </div>

                        <!-- Flag: É valor total? -->
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check form-switch pb-1">
                                <input class="form-check-input" type="checkbox" id="isValorTotal"
                                       name="is_valor_total" value="1"
                                       <?= (!empty($contrato['is_valor_total']) && empty($contrato['sem_valor'])) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="isValorTotal">É valor total?</label>
                            </div>
                        </div>

                        <!-- Parcelas + Valor Total (visível só com flag) -->
                        <div class="col-md-2" id="camposParcelas" style="display:none">
                            <label class="form-label fw-semibold">Nº Parcelas</label>
                            <input type="number" name="num_parcelas" id="numParcelas" class="form-control"
                                   min="1" step="1" placeholder="12"
                                   value="<?= e($contrato['num_parcelas'] ?? '') ?>">
                        </div>
                        <div class="col-md-2" id="campoValorTotalInput" style="display:none">
                            <label class="form-label fw-semibold">Valor Total (R$)</label>
                            <input type="text" name="valor" id="valorTotal" class="form-control"
                                   placeholder="0,00"
                                   value="<?= ($contrato && !empty($contrato['is_valor_total']) && empty($contrato['sem_valor'])) ? number_format($contrato['valor'], 2, ',', '.') : '' ?>">
                            <div class="form-text" id="previewMensal"></div>
                        </div>
                        </div><!-- /blocoCamposValor -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Data Início *</label>
                            <input type="date" name="data_inicio" class="form-control" required value="<?= e($contrato['data_inicio'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Data Fim *</label>
                            <input type="date" name="data_fim" class="form-control" required value="<?= e($contrato['data_fim'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Indexador de Reajuste</label>
                            <select name="reajuste_indexador" class="form-select">
                                <option value="">Nenhum</option>
                                <?php foreach (['IPCA','IGP-M','INPC','IPC-A','Fixo'] as $idx): ?>
                                <option value="<?= $idx ?>" <?= ($contrato['reajuste_indexador'] ?? '') === $idx ? 'selected' : '' ?>><?= $idx ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">% Reajuste</label>
                            <input type="number" name="percentual_reajuste" class="form-control" step="0.01" min="0"
                                   value="<?= e($contrato['percentual_reajuste'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Alertar (dias antes)</label>
                            <input type="number" name="alerta_antecipacao_dias" class="form-control" min="1"
                                   value="<?= e($contrato['alerta_antecipacao_dias'] ?? 30) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Recorrente?</label>
                            <select name="recorrente" class="form-select">
                                <option value="1" <?= ($contrato['recorrente'] ?? 1) ? 'selected' : '' ?>>Sim</option>
                                <option value="0" <?= !($contrato['recorrente'] ?? 1) ? 'selected' : '' ?>>Não</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Setor</label>
                            <select name="setor_id" class="form-select">
                                <option value="">— Não informado —</option>
                                <?php foreach ($setores as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= ($contrato['setor_id'] ?? '') === $s['id'] ? 'selected' : '' ?>>
                                    <?= e($s['nome']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Categoria Dashboard</label>
                            <select name="categoria_dashboard" class="form-select">
                                <?php foreach (['operacional','administrativo','investimento'] as $cat): ?>
                                <option value="<?= $cat ?>" <?= ($contrato['categoria_dashboard'] ?? 'operacional') === $cat ? 'selected' : '' ?>>
                                    <?= ucfirst($cat) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Arquivo (PDF/DOC)</label>
                            <input type="file" name="arquivo" class="form-control" accept=".pdf,.doc,.docx">
                            <?php if (!empty($contrato['arquivo_path'])): ?>
                            <div class="form-text text-success">
                                <i class="bi bi-paperclip"></i>
                                <a href="<?= url('contratos/' . $contrato['id'] . '/arquivo') ?>" target="_blank">Ver arquivo anexado</a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($contrato): ?>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <option value="ativo"    <?= $contrato['status'] === 'ativo'     ? 'selected' : '' ?>>Ativo</option>
                                <option value="cancelado"<?= $contrato['status'] === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <hr>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Salvar</button>
                        <a href="<?= url('contratos') ?>" class="btn btn-outline-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const semValorChk = document.getElementById('semValor');
    const blocoValor  = document.getElementById('blocoCamposValor');
    const flag        = document.getElementById('isValorTotal');
    const campos      = document.getElementById('camposParcelas');
    const campoVal    = document.getElementById('campoValorTotalInput');
    const mensal      = document.getElementById('valorMensal');
    const total       = document.getElementById('valorTotal');
    const parcelas    = document.getElementById('numParcelas');
    const preview     = document.getElementById('previewMensal');

    function parseVal(v) {
        return parseFloat(v.replace(/\./g, '').replace(',', '.')) || 0;
    }
    function fmtBR(n) {
        return n.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function toggleSemValor() {
        const sv = semValorChk.checked;
        blocoValor.style.display = sv ? 'none' : '';
        if (mensal) mensal.required = !sv;
        if (sv) {
            if (flag) flag.checked = false;
            toggleValorTotal();
        }
    }

    function toggleValorTotal() {
        const on = flag && flag.checked;
        if (campos)   campos.style.display   = on ? '' : 'none';
        if (campoVal) campoVal.style.display = on ? '' : 'none';
        if (mensal)   mensal.required        = !on && !semValorChk.checked;
        if (!on && preview) { preview.textContent = ''; }
    }

    function calcMensal() {
        if (!total || !parcelas) return;
        const t = parseVal(total.value);
        const p = parseInt(parcelas.value) || 0;
        if (t > 0 && p > 0) {
            const m = t / p;
            preview.textContent = '≈ R$ ' + fmtBR(m) + '/mês';
            mensal.value = fmtBR(m);
        } else {
            if (preview) preview.textContent = '';
        }
    }

    semValorChk.addEventListener('change', toggleSemValor);
    if (flag) flag.addEventListener('change', toggleValorTotal);
    if (total)    total.addEventListener('input', calcMensal);
    if (parcelas) parcelas.addEventListener('input', calcMensal);

    toggleSemValor();  // estado inicial
    toggleValorTotal();
})();
</script>