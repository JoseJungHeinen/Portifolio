<?php
// @versao Ver.004.301026
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OC <?= e($oc['numero_oc'] ?? substr($oc['id'],0,8)) ?></title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, sans-serif; font-size: 12px; color: #222; background: #fff; }

    .page { max-width: 800px; margin: 0 auto; padding: 32px; }

    /* Cabeçalho */
    .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1a3c5e; padding-bottom: 16px; margin-bottom: 20px; }
    .header-logo { font-size: 22px; font-weight: bold; color: #1a3c5e; }
    .header-logo span { display: block; font-size: 11px; font-weight: normal; color: #666; }
    .header-oc { text-align: right; }
    .header-oc .numero { font-size: 18px; font-weight: bold; color: #1a3c5e; }
    .header-oc .sub { font-size: 11px; color: #666; }

    /* Seções */
    .section { margin-bottom: 18px; }
    .section-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #1a3c5e; letter-spacing: .5px; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 10px; }

    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; }
    .info-item { display: flex; flex-direction: column; }
    .info-label { font-size: 10px; color: #888; text-transform: uppercase; }
    .info-value { font-size: 12px; font-weight: 500; }

    /* Tabela de itens */
    table { width: 100%; border-collapse: collapse; }
    thead th { background: #1a3c5e; color: #fff; padding: 8px 10px; text-align: left; font-size: 11px; }
    thead th.r { text-align: right; }
    tbody td { padding: 7px 10px; border-bottom: 1px solid #eee; font-size: 12px; }
    tbody td.r { text-align: right; }
    tbody tr:nth-child(even) { background: #f9f9f9; }
    tfoot td { padding: 8px 10px; font-weight: bold; font-size: 13px; border-top: 2px solid #1a3c5e; }
    tfoot td.r { text-align: right; color: #1a3c5e; }

    /* Rodapé */
    .footer { margin-top: 32px; padding-top: 16px; border-top: 1px solid #ddd; display: flex; justify-content: space-between; font-size: 10px; color: #aaa; }

    /* Assinaturas */
    .assinaturas { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; }
    .assinatura { text-align: center; }
    .assinatura-linha { border-top: 1px solid #333; margin-bottom: 6px; }
    .assinatura-label { font-size: 10px; color: #666; }

    /* Botão imprimir — some no PDF */
    .btn-print { position: fixed; top: 20px; right: 20px; background: #1a3c5e; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-size: 13px; cursor: pointer; z-index: 999; box-shadow: 0 2px 8px rgba(0,0,0,.3); }
    .btn-print:hover { background: #2a5f9e; }

    @media print {
        .btn-print { display: none; }
        body { font-size: 11px; }
        .page { padding: 0; max-width: 100%; }
    }
</style>
</head>
<body>

<button class="btn-print" onclick="window.print()">🖨 Imprimir / Salvar PDF</button>

<div class="page">

    <!-- CABEÇALHO -->
    <div class="header">
        <div class="header-logo">
            Exemplo
            <span>Sistema de Gestão de Compras</span>
        </div>
        <div class="header-oc">
            <div class="numero">ORDEM DE COMPRA</div>
            <div class="numero" style="font-size:15px"><?= e($oc['numero_oc'] ?? '—') ?></div>
            <div class="sub">Emitida em <?= date('d/m/Y H:i') ?></div>
        </div>
    </div>

    <?php
    $aprovadores = $aprovadores ?? [];
    $numReq = !empty($oc['req_numero'])
        ? formatar_numero_requisicao($oc['req_tipo'] ?? 'compra', $oc['req_criado_em'], (int)$oc['req_numero'])
        : '—';
    $finalidadeTxt = \App\Models\Requisicao::FINALIDADES[$oc['finalidade'] ?? ''] ?? '—';
    $localEntrega  = trim(($oc['filial_nome'] ?? '') . ' — ' . implode(', ', array_filter([
        $oc['filial_endereco'] ?? '', $oc['filial_cidade'] ?? '', $oc['filial_estado'] ?? '',
    ])), ' —');
    $entregaTxt = !empty($oc['necessario_ate']) ? 'até ' . date('d/m/Y', strtotime($oc['necessario_ate'])) : '—';
    if (!empty($oc['prazo_dias'])) {
        $entregaTxt .= ' (fornecedor: ' . (int)$oc['prazo_dias'] . ' dias)';
    }
    ?>

    <?php if (($oc['status'] ?? '') === 'rascunho'): ?>
    <div style="border:2px solid #C8102E;color:#8f0b21;background:#fdf0f0;padding:8px 12px;margin-bottom:14px;font-weight:bold;text-align:center">
        AGUARDANDO APROVAÇÃO — esta OC ainda não autoriza entrega nem faturamento
    </div>
    <?php endif; ?>

    <!-- DADOS DA OC -->
    <div class="section">
        <div class="section-title">Dados da Ordem</div>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Empresa compradora</span>
                <span class="info-value"><?= e($oc['filial_nome'] ?? '—') ?><?= !empty($oc['filial_cnpj']) ? ' — CNPJ ' . e($oc['filial_cnpj']) : '' ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Status</span>
                <span class="info-value"><?= ucfirst($oc['status'] ?? '—') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Requisitante / Setor</span>
                <span class="info-value"><?= e($oc['requisitante_nome'] ?? '—') ?> — <?= e($oc['setor_nome'] ?? '—') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Requisição vinculada</span>
                <span class="info-value"><?= e($numReq) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Comprador</span>
                <span class="info-value"><?= e($oc['comprador_nome'] ?? '—') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Data de emissão</span>
                <span class="info-value"><?= isset($oc['criado_em']) ? date('d/m/Y H:i', strtotime($oc['criado_em'])) : '—' ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Finalidade fiscal</span>
                <span class="info-value"><?= e($finalidadeTxt) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Entrega</span>
                <span class="info-value"><?= e($entregaTxt) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Entrega prevista</span>
                <span class="info-value"><?= !empty($oc['entrega_prevista']) ? date('d/m/Y', strtotime($oc['entrega_prevista'])) : '—' ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Condição de pagamento</span>
                <span class="info-value"><?= e(\App\Services\CondicaoPagamento::rotulo($oc['condicao_pagamento'] ?? null, $oc['parcelas_dias'] ?? null, $oc['prazo_pagamento_dias'] ?? null) ?? '—') ?></span>
            </div>
            <div class="info-item" style="grid-column: span 2">
                <span class="info-label">Local de entrega</span>
                <span class="info-value"><?= e($localEntrega !== '' ? $localEntrega : '—') ?></span>
            </div>
            <?php if (!empty($oc['motivo'])): ?>
            <div class="info-item" style="grid-column: span 2">
                <span class="info-label">Motivo da compra</span>
                <span class="info-value"><?= nl2br(e($oc['motivo'])) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- FORNECEDOR -->
    <div class="section">
        <div class="section-title">Fornecedor</div>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Razão Social</span>
                <span class="info-value"><?= e($oc['fornecedor_razao'] ?? $oc['fornecedor_nome'] ?? '—') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Nome Fantasia</span>
                <span class="info-value"><?= e($oc['fornecedor_nome'] ?? '—') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">CNPJ</span>
                <span class="info-value"><?= e($oc['fornecedor_cnpj'] ?? '—') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">E-mail</span>
                <span class="info-value"><?= e($oc['fornecedor_email'] ?? '—') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Regime tributário</span>
                <span class="info-value"><?= e($oc['fornecedor_regime'] ?: 'Não informado — confirmar com o fornecedor') ?></span>
            </div>
            <?php if (!empty($oc['fornecedor_endereco'])): ?>
            <div class="info-item" style="grid-column: span 2">
                <span class="info-label">Endereço</span>
                <span class="info-value"><?= e($oc['fornecedor_endereco']) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ITENS -->
    <div class="section">
        <div class="section-title">Itens da Ordem</div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Descrição do Item</th>
                    <th class="r">Qtd</th>
                    <th>Un</th>
                    <th class="r">Valor Unit.</th>
                    <th class="r">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itens as $i => $it):
                    $subtotal = ($it['quantidade'] ?? 0) * ($it['valor_unitario'] ?? 0);
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= e($it['item_nome'] ?? $it['descricao'] ?? '—') ?></td>
                    <td class="r"><?= number_format($it['quantidade'], 2, ',', '.') ?></td>
                    <td><?= e($it['unidade']) ?></td>
                    <td class="r">R$ <?= number_format($it['valor_unitario'] ?? 0, 2, ',', '.') ?></td>
                    <td class="r">R$ <?= number_format($subtotal, 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" style="text-align:right; color:#555; font-weight:normal; font-size:12px">Total Geral</td>
                    <td class="r">R$ <?= number_format($oc['valor_total'] ?? 0, 2, ',', '.') ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- APROVAÇÃO -->
    <div class="section">
        <div class="section-title">Aprovação</div>
        <?php if ($aprovadores): ?>
        <table>
            <thead><tr><th>Etapa</th><th>Aprovado por</th><th>Data</th></tr></thead>
            <tbody>
            <?php foreach ($aprovadores as $ap): ?>
                <tr>
                    <td><?= $ap['entidade_tipo'] === 'ordem_compra' ? 'Reaprovação da OC' : 'Requisição' ?> — nível <?= (int)$ap['nivel'] ?></td>
                    <td><?= e($ap['nome']) ?> (<?= e(ucfirst($ap['perfil'])) ?>)</td>
                    <td><?= $ap['resolvido_em'] ? date('d/m/Y H:i', strtotime($ap['resolvido_em'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div style="color:#888;font-size:12px">Sem aprovação registrada.</div>
        <?php endif; ?>
        <?php if (!empty($oc['justificativa_excecao'])): ?>
        <div style="margin-top:8px;font-size:12px;border-left:3px solid #b07a10;padding-left:8px">
            <strong>Exceção ao mínimo de cotações:</strong> <?= nl2br(e($oc['justificativa_excecao'])) ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- INSTRUÇÕES AO FORNECEDOR (Política de Compras, Anexo B) -->
    <div class="section" style="border:1px solid #ccc;padding:8px 12px;font-size:12px;background:#fafafa">
        <strong>Instruções ao fornecedor:</strong>
        informe o nº desta OC (<?= e($oc['numero_oc'] ?? '') ?>) na nota fiscal (campo “Pedido”/xPed ou Informações complementares)
        e emita a NF com os campos de IBS/CBS preenchidos.
        <strong>NF sem OC ou em divergência com a OC não será paga.</strong>
    </div>

    <!-- ASSINATURAS -->
    <div class="assinaturas">
        <div class="assinatura">
            <div class="assinatura-linha"></div>
            <div class="assinatura-label">Comprador / Solicitante</div>
        </div>
        <div class="assinatura">
            <div class="assinatura-linha"></div>
            <div class="assinatura-label">Aprovação / Fornecedor</div>
        </div>
    </div>

    <!-- RODAPÉ -->
    <div class="footer">
        <span>Exemplo — Sistema de Gestão de Compras</span>
        <span>OC: <?= e($oc['numero_oc'] ?? substr($oc['id'],0,8)) ?> | Gerado em <?= date('d/m/Y H:i') ?></span>
    </div>

</div>

<script>
// Auto-abre o diálogo de impressão ao carregar a página
window.addEventListener('load', function() {
    // Pequeno delay para garantir renderização completa
    setTimeout(function() { window.print(); }, 300);
});
</script>

</body>
</html>
