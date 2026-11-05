<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Responder Cotação — Exemplo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:700px">
    <div class="card border-0 shadow">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Cotação — <?= e($cotacaoForn['fornecedor_nome']) ?></h5>
        </div>
        <div class="card-body">
            <p class="text-muted">Preencha os valores unitários para cada item abaixo e informe o prazo de entrega.</p>
            <form method="POST" action="<?= url('cotacao/responder/' . $token) ?>">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Prazo de Entrega (dias)</label>
                    <input type="number" name="prazo_entrega_dias" class="form-control" value="7" min="1" required>
                </div>
                <hr>
                <?php foreach ($itens as $it): ?>
                <div class="row g-2 align-items-center mb-3">
                    <input type="hidden" name="item_id[]" value="<?= $it['item_id'] ?>">
                    <div class="col-12">
                        <strong><?= e($it['item_nome']) ?></strong>
                        <div class="text-muted small">Qtd solicitada: <?= number_format($it['quantidade'], 2, ',', '.') ?> <?= e($it['unidade']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Qtd que pode fornecer (<?= e($it['unidade']) ?>)</label>
                        <input type="text" name="quantidade[]" class="form-control"
                               value="<?= number_format($it['quantidade'], 2, ',', '.') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Valor unitário</label>
                        <div class="input-group">
                            <span class="input-group-text">R$</span>
                            <input type="text" name="valor_unitario[]" class="form-control"
                                   placeholder="0,00" required>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <hr>
                <hr>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Frete (R$)</label>
                        <div class="input-group">
                            <span class="input-group-text">R$</span>
                            <input type="text" name="frete" class="form-control" placeholder="0,00" value="0,00">
                        </div>
                        <div class="form-text">Frete total da entrega. Deixe 0,00 se incluso nos precos.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Condicao de Pagamento</label>
                        <input type="text" name="observacao" class="form-control" placeholder="Ex: 30 dias, a vista, boleto...">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Enviar Cotacao</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
