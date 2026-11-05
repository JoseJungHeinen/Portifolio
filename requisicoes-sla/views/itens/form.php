<?php
// @versao Ver.001.281026
?>
<?php $title = $item ? 'Editar Item' : 'Novo Item'; ?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('itens') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi bi-card-list me-2 text-primary"></i><?= $title ?></h4>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="POST" action="<?= $item ? url('itens/' . $item['id']) : url('itens') ?>">
                    <?= csrf_field() ?>
                    <?php if (!empty($_GET['_back'])): ?>
                    <input type="hidden" name="_back" value="<?= e($_GET['_back']) ?>">
                    <?php endif; ?>

                    <h6 class="text-muted text-uppercase fw-semibold mb-3" style="font-size:.75rem;letter-spacing:.05em">
                        Identificação
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Código *</label>
                            <?php if ($item): ?>
                                <input type="text" name="codigo" class="form-control" required
                                       value="<?= e($item['codigo'] ?? '') ?>" placeholder="Ex: MAT-001">
                            <?php else: ?>
                                <div class="input-group">
                                    <input type="text" name="codigo_prefixo" id="codigoPrefixo"
                                           class="form-control text-uppercase" required
                                           placeholder="Ex: MAT" maxlength="10">
                                    <span class="input-group-text fw-bold text-primary" id="previewCodigo">-???</span>
                                </div>
                                <div class="form-text">Digite o prefixo — o número sequencial é gerado automaticamente.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Nome *</label>
                            <input type="text" name="nome" class="form-control" required
                                   value="<?= e($item['nome'] ?? '') ?>"
                                   placeholder="Nome completo do item">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Referência / Cód. Alternativo</label>
                            <input type="text" name="referencia" class="form-control"
                                   value="<?= e($item['referencia'] ?? '') ?>"
                                   placeholder="Ex: REF-001, COD-ABC">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Marca</label>
                            <input type="text" name="marca" class="form-control"
                                   value="<?= e($item['marca'] ?? '') ?>"
                                   placeholder="Ex: Bosch, 3M, Pirelli">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Descrição</label>
                            <textarea name="descricao" class="form-control" rows="2"
                                      placeholder="Descrição técnica, especificações..."><?= e($item['descricao'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <h6 class="text-muted text-uppercase fw-semibold mb-3" style="font-size:.75rem;letter-spacing:.05em">
                        Classificação
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Categoria *</label>
                            <select name="categoria_id" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"
                                        <?= ($item['categoria_id'] ?? '') === $cat['id'] ? 'selected' : '' ?>>
                                        <?= e($cat['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Grupo de Produto *</label>
                            <select name="grupo_produto_id" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($grupos as $gp): ?>
                                    <option value="<?= $gp['id'] ?>"
                                        <?= ($item['grupo_produto_id'] ?? '') === $gp['id'] ? 'selected' : '' ?>>
                                        <?= e($gp['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Unidade</label>
                            <select name="unidade" class="form-select">
                                <?php foreach (['UN','KG','LT','MT','M2','M3','CX','PC','GL','SC','BD','RL','DZ','PR','JG','FD','PCT'] as $un): ?>
                                    <option value="<?= $un ?>" <?= ($item['unidade'] ?? 'UN') === $un ? 'selected' : '' ?>>
                                        <?= $un ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Curva ABC</label>
                            <select name="curva_abc" class="form-select">
                                <option value="A" <?= ($item['curva_abc'] ?? '') === 'A' ? 'selected' : '' ?>>A — Alta prioridade</option>
                                <option value="B" <?= ($item['curva_abc'] ?? '') === 'B' ? 'selected' : '' ?>>B — Média prioridade</option>
                                <option value="C" <?= ($item['curva_abc'] ?? 'C') === 'C' ? 'selected' : '' ?>>C — Baixa prioridade</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Estoque Mínimo</label>
                            <input type="text" name="estoque_minimo" class="form-control"
                                   value="<?= e(number_format((float)($item['estoque_minimo'] ?? 0), 2, ',', '.')) ?>"
                                   placeholder="0,00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Valor de Referência (R$)</label>
                            <input type="text" name="valor_referencia" id="valorReferencia" class="form-control"
                                   value="<?= isset($item['valor_referencia']) && $item['valor_referencia'] !== null ? e(number_format((float)$item['valor_referencia'], 2, ',', '.')) : '' ?>"
                                   placeholder="0,00">
                            <div class="form-text" id="mediaPrecoHint">
                                <?= $item ? '<i class="bi bi-pencil me-1"></i>Pode ser editado manualmente.' : 'Opcional. Pode ser ajustado manualmente.' ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($item): ?>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="ativo" id="ativo"
                                       value="1" <?= ($item['ativo'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="ativo">Item ativo</label>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!$item): ?>
                    <h6 class="text-muted text-uppercase fw-semibold mb-3 mt-2" style="font-size:.75rem;letter-spacing:.05em">
                        Estoque Inicial <span class="text-muted fw-normal" style="text-transform:none;letter-spacing:0">(opcional)</span>
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Quantidade Inicial</label>
                            <input type="text" name="estoque_inicial" class="form-control"
                                   value="" placeholder="0,00"
                                   oninput="this.value=this.value.replace(/[^0-9,\.]/g,'')">
                            <div class="form-text">Deixe em branco para registrar depois via Estoque.</div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Filial</label>
                            <select name="estoque_filial_id" class="form-select">
                                <?php foreach ($filiais as $f): ?>
                                    <option value="<?= e($f['id']) ?>"
                                        <?= ($f['id'] === (auth()['filial_id'] ?? '')) ? 'selected' : '' ?>>
                                        <?= e($f['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="eh_brinde" id="eh_brinde" value="1">
                                <label class="form-check-label fw-semibold" for="eh_brinde">Este item é um Brinde</label>
                            </div>
                            <div class="form-text">Cria também o cadastro correspondente no módulo de Brindes, já vinculado a este item.</div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <hr>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Salvar
                        </button>
                        <a href="<?= url('itens') ?>" class="btn btn-outline-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    const inp = document.getElementById('codigoPrefixo');
    const prev = document.getElementById('previewCodigo');
    if (!inp || !prev) return;
    inp.addEventListener('input', function() {
        const v = this.value.toUpperCase().trim();
        this.value = v;
        if (v) {
            fetch('' + (window.APP_URL||'') + '/itens/proximo-codigo?prefixo=' + encodeURIComponent(v))
                .then(r => r.json())
                .then(d => { prev.textContent = d.codigo ? '-' + d.codigo.split('-').pop() : '-001'; })
                .catch(() => { prev.textContent = '-???'; });
        } else {
            prev.textContent = '-???';
        }
    });
})();

(function () {
    const campo = document.getElementById('valorReferencia');
    if (!campo) return;
    const itemId = <?= $item ? json_encode($item['id'], JSON_HEX_TAG | JSON_HEX_AMP) : 'null' ?>;
    if (!itemId) return;
    const hint = document.getElementById('mediaPrecoHint');
    fetch((window.APP_URL || '') + '/api/itens/' + itemId + '/media-preco')
        .then(r => r.json())
        .then(d => {
            if (d.media !== null) {
                hint.innerHTML = '<i class="bi bi-calculator me-1"></i>Média histórica: ' +
                    parseFloat(d.media).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}) +
                    ' (' + d.total + ' registro(s)).';
                hint.className = 'form-text text-muted';
            } else {
                hint.innerHTML = '<i class="bi bi-info-circle me-1"></i>Sem histórico de preços ainda.';
                hint.className = 'form-text text-muted';
            }
        })
        .catch(() => {});
})();
</script>
