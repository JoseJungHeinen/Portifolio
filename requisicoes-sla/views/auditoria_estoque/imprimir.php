<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Folha de Auditoria — <?= e($aud['filial_nome']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 11px; color: #000; }
        .cabecalho { text-align: center; margin-bottom: 12px; border-bottom: 2px solid #000; padding-bottom: 8px; }
        .cabecalho h2 { font-size: 15px; margin-bottom: 4px; }
        .cabecalho .sub { font-size: 11px; color: #555; }
        .info-linha { display: flex; gap: 24px; margin-bottom: 10px; font-size: 11px; }
        .info-linha span { display: inline-block; }
        .info-linha .label { font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        thead th { background: #eee; border: 1px solid #bbb; padding: 4px 6px; text-align: left; font-size: 11px; }
        tbody td { border: 1px solid #ccc; padding: 4px 6px; font-size: 11px; vertical-align: middle; }
        .td-contagem { min-width: 60px; background: #fffde7; }
        .td-obs { min-width: 100px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .assinatura { margin-top: 32px; border-top: 1px solid #999; padding-top: 8px; font-size: 10px; color: #555; }
        .rodape { margin-top: 24px; font-size: 10px; color: #777; text-align: center; }
        @media print {
            .no-print { display: none !important; }
            @page { size: A4 portrait; margin: 14mm; }
        }
    </style>
</head>
<body>

<div class="no-print" style="padding:12px;background:#f8f9fa;border-bottom:1px solid #ddd;display:flex;gap:12px;align-items:center">
    <button onclick="window.print()" style="padding:6px 18px;background:#0d6efd;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px">
        🖨 Imprimir
    </button>
    <a href="javascript:history.back()" style="color:#555;text-decoration:none;font-size:13px">← Voltar</a>
</div>

<div style="padding:16px">

<div class="cabecalho">
    <h2>FOLHA DE AUDITORIA DE ESTOQUE</h2>
    <div class="sub">Exemplo — Sistema de Compras</div>
</div>

<div class="info-linha">
    <span><span class="label">Filial:</span> <?= e($aud['filial_nome']) ?></span>
    <span><span class="label">Data:</span> <?= date('d/m/Y', strtotime($aud['criado_em'])) ?></span>
    <span><span class="label">Escopo:</span>
        <?php if ($aud['escopo'] === 'todos'): ?>Todos os itens
        <?php else: ?><?= ucfirst($aud['escopo']) ?>: <?= e($aud['escopo_nome'] ?? '—') ?>
        <?php endif; ?>
    </span>
    <span><span class="label">Conferente:</span> ___________________________</span>
</div>
<?php if ($aud['observacao']): ?>
<div style="margin-bottom:8px;font-size:11px"><span style="font-weight:bold">Obs:</span> <?= e($aud['observacao']) ?></div>
<?php endif; ?>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Código</th>
            <th>Item</th>
            <th>Grupo</th>
            <th class="text-center">Unid.</th>
            <th class="text-right">Qtd. Sistema</th>
            <th class="text-center td-contagem">Qtd. Física</th>
            <th class="td-obs">Observação</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($itens as $idx => $it): ?>
    <tr>
        <td class="text-center"><?= $idx + 1 ?></td>
        <td><?= e($it['codigo'] ?? '') ?></td>
        <td><?= e($it['item_nome']) ?></td>
        <td><?= e($it['grupo_nome'] ?? '—') ?></td>
        <td class="text-center"><?= e($it['unidade']) ?></td>
        <td class="text-right"><?= number_format((float)$it['qtd_sistema'], 2, ',', '.') ?></td>
        <td class="td-contagem text-center">&nbsp;</td>
        <td class="td-obs">&nbsp;</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div class="assinatura">
    <table style="width:100%;border:none;margin-top:24px">
        <tr>
            <td style="width:45%;border:none;padding:0">
                <div style="border-top:1px solid #000;width:260px;margin-top:32px;padding-top:4px">Conferente / Assinatura</div>
            </td>
            <td style="width:10%;border:none"></td>
            <td style="width:45%;border:none;padding:0">
                <div style="border-top:1px solid #000;width:260px;margin-top:32px;padding-top:4px">Responsável / Assinatura</div>
            </td>
        </tr>
    </table>
</div>

<div class="rodape">
    Folha gerada em <?= date('d/m/Y H:i') ?> · <?= count($itens) ?> itens
</div>

</div>
</body>
</html>
