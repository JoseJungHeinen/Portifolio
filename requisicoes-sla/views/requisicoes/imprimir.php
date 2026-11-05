<?php
// @versao Ver.002.281026
$numFormatado = formatar_numero_requisicao($req['tipo'] ?? 'compra', $req['criado_em'], (int)($req['numero'] ?? 0));

$ehMovimentacaoEstoque = in_array($req['tipo'] ?? 'compra', ['interna', 'transferencia'], true);
$destinoLabel = $ehMovimentacaoEstoque ? 'Filial Destino' : 'Grupo de Produto';
$destinoValor = $ehMovimentacaoEstoque
    ? ($req['filial_destino_nome'] ?? '—')
    : ($req['grupo_nome'] ?? '—');

$aprovadoLabel = 'Aprovado';
if ($aprovacao) {
    $aprovadoLabel = 'Aprovado por ' . ($aprovacao['usuario_nome'] ?? '—');
    if (!empty($aprovacao['resolvido_em'])) {
        $aprovadoLabel .= ' em ' . date('d/m/Y H:i', strtotime($aprovacao['resolvido_em']));
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Requisição <?= e($numFormatado) ?></title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, sans-serif; font-size: 12px; color: #222; background: #fff; }
    .page { max-width: 820px; margin: 0 auto; padding: 32px; }
    .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1a3c5e; padding-bottom: 16px; margin-bottom: 20px; }
    .brand { font-size: 22px; font-weight: bold; color: #1a3c5e; }
    .brand span { display: block; font-size: 11px; font-weight: normal; color: #666; }
    .doc-info { text-align: right; }
    .doc-info .title { font-size: 18px; font-weight: bold; color: #1a3c5e; }
    .doc-info .number { font-size: 15px; font-weight: bold; color: #1a3c5e; margin-top: 2px; }
    .doc-info .sub { font-size: 11px; color: #666; margin-top: 3px; }
    .section { margin-bottom: 18px; }
    .section-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #1a3c5e; letter-spacing: .5px; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 10px; }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; }
    .info-item { display: flex; flex-direction: column; }
    .info-label { font-size: 10px; color: #888; text-transform: uppercase; }
    .info-value { font-size: 12px; font-weight: 500; }
    .justificativa { background: #f7f7f7; border: 1px solid #ddd; border-radius: 4px; padding: 8px 10px; line-height: 1.45; }
    table { width: 100%; border-collapse: collapse; }
    thead th { background: #1a3c5e; color: #fff; padding: 8px 10px; text-align: left; font-size: 11px; }
    thead th.r, tbody td.r, tfoot td.r { text-align: right; }
    tbody td { padding: 7px 10px; border-bottom: 1px solid #eee; font-size: 12px; vertical-align: top; }
    tbody tr:nth-child(even) { background: #f9f9f9; }
    tfoot td { padding: 8px 10px; font-weight: bold; font-size: 13px; border-top: 2px solid #1a3c5e; }
    tfoot td.r { color: #1a3c5e; }
    .assinaturas { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 44px; }
    .assinatura { text-align: center; }
    .assinatura-linha { border-top: 1px solid #333; margin-bottom: 6px; padding-top: 7px; }
    .assinatura-label { font-size: 10px; color: #666; text-transform: uppercase; letter-spacing: .4px; }
    .footer { margin-top: 32px; padding-top: 16px; border-top: 1px solid #ddd; display: flex; justify-content: space-between; font-size: 10px; color: #aaa; }
    .btn-print { position: fixed; top: 20px; right: 20px; background: #1a3c5e; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-size: 13px; cursor: pointer; z-index: 999; box-shadow: 0 2px 8px rgba(0,0,0,.3); }
    .btn-print:hover { background: #2a5f9e; }
    @media print {
        .btn-print { display: none; }
        .page { padding: 0; max-width: 100%; }
        body { font-size: 11px; }
    }
</style>
</head>
<body>

<button class="btn-print" onclick="window.print()">Imprimir / Salvar PDF</button>

<div class="page">
    <div class="header">
        <div class="brand">
            Exemplo
            <span>Sistema de Gestão de Compras</span>
        </div>
        <div class="doc-info">
            <div class="title">REQUISIÇÃO APROVADA</div>
            <div class="number"><?= e($numFormatado) ?></div>
            <div class="sub">Emitida em <?= date('d/m/Y H:i') ?></div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Dados da Requisição</div>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Status</span>
                <span class="info-value"><?= e($aprovadoLabel) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Modalidade</span>
                <span class="info-value"><?= match ($req['tipo'] ?? 'compra') {
                    'interna'       => 'Requisição Interna',
                    'transferencia' => 'Solicitação de Transferência',
                    'ordem_compra'  => 'Ordem de Compra',
                    default         => 'Compra',
                } ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Solicitante</span>
                <span class="info-value"><?= e($req['solicitante_nome'] ?? '—') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">E-mail</span>
                <span class="info-value"><?= e($req['solicitante_email'] ?? '—') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Unidade / Filial</span>
                <span class="info-value"><?= e($req['filial_nome'] ?? '—') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Setor</span>
                <span class="info-value"><?= e($req['setor_nome'] ?? '—') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label"><?= e($destinoLabel) ?></span>
                <span class="info-value"><?= e($destinoValor) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Data da Requisição</span>
                <span class="info-value"><?= date_br($req['criado_em']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Tipo de Despesa</span>
                <span class="info-value"><?= $ehMovimentacaoEstoque ? '—' : ucfirst($req['tipo_despesa'] ?? '—') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Valor Total</span>
                <span class="info-value"><?= money($req['valor_total'] ?? 0) ?></span>
            </div>
            <?php if (!empty($req['finalidade'])): ?>
            <div class="info-item">
                <span class="info-label">Finalidade</span>
                <span class="info-value"><?= e(\App\Models\Requisicao::FINALIDADES[$req['finalidade']] ?? $req['finalidade']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($req['necessario_ate'])): ?>
            <div class="info-item">
                <span class="info-label">Necessário até</span>
                <span class="info-value"><?= e(date('d/m/Y', strtotime($req['necessario_ate']))) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($req['justificativa'])): ?>
    <div class="section">
        <div class="section-title">Justificativa</div>
        <div class="justificativa"><?= e($req['justificativa']) ?></div>
    </div>
    <?php endif; ?>

    <div class="section">
        <div class="section-title">Itens da Requisição</div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Descrição</th>
                    <th class="r">Qtd</th>
                    <th>Un</th>
                    <th class="r">Valor Unit.</th>
                    <th class="r">Subtotal</th>
                    <th>Obs.</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itens as $i => $item):
                    $valorUnit = (float)($item['valor_unitario'] ?? 0);
                    $subtotal = (float)($item['quantidade'] ?? 0) * $valorUnit;
                    $descricao = $item['item_nome'] ?? $item['descricao_livre'] ?? '—';
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= e($descricao) ?></td>
                    <td class="r"><?= number_format((float)($item['quantidade'] ?? 0), 2, ',', '.') ?></td>
                    <td><?= e($item['unidade'] ?? '—') ?></td>
                    <td class="r"><?= money($valorUnit) ?></td>
                    <td class="r"><?= money($subtotal) ?></td>
                    <td><?= e($item['observacao'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" style="text-align:right; color:#555; font-weight:normal; font-size:12px">Total Geral</td>
                    <td class="r"><?= money($req['valor_total'] ?? 0) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="assinaturas">
        <div class="assinatura">
            <div class="assinatura-linha"></div>
            <div class="assinatura-label">Responsável pela Entrega</div>
        </div>
        <div class="assinatura">
            <div class="assinatura-linha"></div>
            <div class="assinatura-label">Responsável pela Retirada</div>
        </div>
    </div>

    <div class="footer">
        <span>Exemplo - Sistema de Gestão de Compras</span>
        <span><?= e($numFormatado) ?> | Gerado em <?= date('d/m/Y H:i') ?></span>
    </div>
</div>

<script>
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 300);
});
</script>

</body>
</html>
