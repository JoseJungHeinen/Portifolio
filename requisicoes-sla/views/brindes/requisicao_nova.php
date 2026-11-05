<?php
// @versao Ver.002.270826
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= url('brindes') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-box-arrow-up me-2"></i>Solicitar saída de brinde</h4>
</div>

<?php if (empty($brindes)): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>Não há brindes com saldo disponível na sua filial.
</div>
<?php else: ?>
<form method="POST" action="<?= url('brindes/requisicao') ?>" class="card shadow-sm" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Nome do cliente *</label>
                <input type="text" name="cliente_nome" class="form-control" required maxlength="160"
                       placeholder="Para quem é o brinde" value="<?= e($old['cliente_nome'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Vendedor *</label>
                <input type="text" name="vendedor_nome" class="form-control" required maxlength="160"
                       placeholder="Nome do vendedor" value="<?= e($old['vendedor_nome'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Loja</label>
                <input type="text" class="form-control" value="<?= e($lojaNome) ?>" disabled readonly>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Número do pedido</label>
                <input type="text" name="numero_pedido_carro" class="form-control" maxlength="60"
                       placeholder="Opcional" value="<?= e($old['numero_pedido_carro'] ?? '') ?>">
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold">Observação</label>
                <input type="text" name="observacao" class="form-control" placeholder="Opcional" value="<?= e($old['observacao'] ?? '') ?>">
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold">Autorização do gerente (anexo) *</label>
                <input type="file" name="autorizacao_anexo" class="form-control" required accept="image/*,.pdf">
                <div class="form-text">Foto ou PDF da autorização do gerente para liberação do brinde.</div>
            </div>
        </div>

        <hr>
        <h6 class="fw-bold mb-2">Brindes</h6>
        <?php
        $oldBrindeIds = !empty($old['brinde_id']) ? $old['brinde_id'] : [''];
        $oldQtds      = $old['quantidade'] ?? [];
        ?>
        <div id="linhas">
            <?php foreach ($oldBrindeIds as $i => $oldBid): ?>
            <div class="row g-2 mb-2 linha-brinde">
                <div class="col-md-6">
                    <select name="brinde_id[]" class="form-select" required>
                        <option value="" data-valor="0">Selecione o brinde...</option>
                        <?php foreach ($brindes as $b): ?>
                        <option value="<?= e($b['id']) ?>"
                                data-valor="<?= (float)($b['valor_referencia'] ?? 0) ?>"
                                <?= $oldBid === $b['id'] ? 'selected' : '' ?>>
                            <?= e($b['nome']) ?> (saldo: <?= number_format((float)$b['saldo'], 2, ',', '.') ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="number" name="quantidade[]" class="form-control campo-qtd" step="0.01" min="0.01" placeholder="Qtd" required
                           value="<?= e($oldQtds[$i] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <input type="text" class="form-control bg-light subtotal-linha" value="R$ 0,00" tabindex="-1" readonly>
                </div>
                <div class="col-md-1 d-flex align-items-center">
                    <button type="button" class="btn btn-outline-danger btn-sm remover-linha"><i class="bi bi-trash"></i></button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="addLinha" class="btn btn-outline-primary btn-sm mt-1">
            <i class="bi bi-plus-lg me-1"></i>Adicionar brinde
        </button>
    </div>
    <div class="card-footer d-flex gap-2 align-items-center">
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Enviar para aprovação</button>
        <a href="<?= url('brindes') ?>" class="btn btn-outline-secondary">Cancelar</a>
        <div class="ms-auto text-end">
            <div class="small text-muted">Valor total</div>
            <div class="fs-5 fw-bold" id="totalGeral">R$ 0,00</div>
        </div>
    </div>
</form>

<script>
(function () {
    const linhas = document.getElementById('linhas');
    const totalEl = document.getElementById('totalGeral');

    const fmt = v => 'R$ ' + v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function recalcular() {
        let total = 0;
        linhas.querySelectorAll('.linha-brinde').forEach(function (linha) {
            const opcao = linha.querySelector('select').selectedOptions[0];
            const valor = parseFloat(opcao ? opcao.dataset.valor : 0) || 0;
            const qtd   = parseFloat(linha.querySelector('.campo-qtd').value) || 0;
            const sub   = valor * qtd;
            total += sub;
            linha.querySelector('.subtotal-linha').value = fmt(sub);
        });
        totalEl.textContent = fmt(total);
    }

    document.getElementById('addLinha').addEventListener('click', function () {
        const nova = linhas.querySelector('.linha-brinde').cloneNode(true);
        nova.querySelectorAll('input').forEach(i => i.value = '');
        nova.querySelector('select').selectedIndex = 0;
        nova.querySelector('.subtotal-linha').value = fmt(0);
        linhas.appendChild(nova);
        recalcular();
    });

    linhas.addEventListener('click', function (e) {
        if (e.target.closest('.remover-linha')) {
            if (linhas.querySelectorAll('.linha-brinde').length > 1) {
                e.target.closest('.linha-brinde').remove();
                recalcular();
            }
        }
    });

    linhas.addEventListener('change', recalcular);
    linhas.addEventListener('input', recalcular);

    recalcular(); // estado inicial, inclusive quando o form reabre preenchido
})();
</script>
<?php endif; ?>
