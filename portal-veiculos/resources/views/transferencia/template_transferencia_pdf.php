<?php
// $dados vem do ver_transferencia.php
$id              = $dados['id'];
$cpf_usuario     = $dados['cpf_usuario'] ?? '';
$tipo_nome       = $dados['tipo_nome'] ?? '';
$ano_veiculo     = $dados['ano_veiculo'] ?? '';
$mes_atual       = $dados['mes_atual'] ?? '';
$ipva_pago       = !empty($dados['ipva_pago']);
$intermunicipal  = !empty($dados['intermunicipal']);
$valor_total     = (float)($dados['valor_total'] ?? 0);
$detalhes        = $dados['detalhes'] ?? '';
$data_criado     = $dados['criado_em'] ?? ''; // se tiver esse campo; senão use date()
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Transferência #<?php echo $id; ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{
    background:#ffffff;
    font-family:Arial,Helvetica,sans-serif;
    font-size:11pt;
    color:#333;
}
.print-layout{
    width:210mm;
    min-height:297mm;
    margin:0 auto;
    padding:20mm 15mm;
}
.orc-header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    border-bottom:1px solid #ccc;
    padding-bottom:10px;
    margin-bottom:15px;
}
.orc-logo img{
    width:90px;
    height:auto;
}
.orc-header-text h1{
    font-size:14pt;
    margin-bottom:4px;
    text-align:right;
}
.orc-header-text p{
    font-size:9pt;
    text-align:right;
    line-height:1.3;
}
.orc-title{
    text-align:center;
    font-size:13pt;
    margin-bottom:2px;
    font-weight:bold;
}
.orc-subtitle{
    text-align:center;
    font-size:9pt;
    margin-bottom:12px;
}
.orc-table{
    width:100%;
    border-collapse:collapse;
    font-size:9pt;
}
.orc-table td{
    border:1px solid #c0c0c0;
    padding:4px 6px;
}
.orc-section td{
    background:#e6e6e6;
    font-weight:bold;
}
.orc-header-row td{
    background:#f5f5f5;
    font-weight:bold;
}
.orc-total-row td{
    font-weight:bold;
}
.right{text-align:right;}
@media print{
    @page{
        size:A4;
        margin:10mm;
    }
}
</style>
</head>
<body>
<div class="print-layout">
    <header class="orc-header">
        <div class="orc-logo">
            <img src="img/logo.svg" alt="Exemplo">
        </div>
        <div class="orc-header-text">
            <h1>EXEMPLO</h1>
            <p>Empresa Exemplo<br>
               Concessionária de Veículos<br>
               CNPJ: XX.XXX.XXX/0001-XX<br>
               Tel: (00) 0000-0000</p>
        </div>
    </header>

    <h2 class="orc-title">ORÇAMENTO DE TRANSFERÊNCIA</h2>
    <p class="orc-subtitle">
        Número: <?php echo sprintf('%04d', $id); ?>
        <?php if ($data_criado): ?>
            | Data: <?php echo htmlspecialchars($data_criado, ENT_QUOTES, 'UTF-8'); ?>
        <?php else: ?>
            | Data: <?php echo date('Y-m-d H:i:s'); ?>
        <?php endif; ?>
    </p>

    <table class="orc-table">
        <tr class="orc-section">
            <td colspan="4">DADOS DO CLIENTE</td>
        </tr>
        <tr>
            <td colspan="4">
                Cliente: <!-- se tiver nome em outra tabela, pode preencher depois --><br>
                CPF: <?php echo htmlspecialchars($cpf_usuario, ENT_QUOTES, 'UTF-8'); ?>
            </td>
        </tr>

        <tr class="orc-section">
            <td colspan="4">DESCRIÇÃO DO SERVIÇO</td>
        </tr>
        <tr>
            <td colspan="4" style="height:80px;vertical-align:top;">
                Tipo de transferência: <?php echo htmlspecialchars($tipo_nome, ENT_QUOTES, 'UTF-8'); ?><br>
                Ano do veículo: <?php echo htmlspecialchars($ano_veiculo, ENT_QUOTES, 'UTF-8'); ?><br>
                Mês atual: <?php echo htmlspecialchars($mes_atual, ENT_QUOTES, 'UTF-8'); ?><br>
                IPVA pago: <?php echo $ipva_pago ? 'Sim' : 'Não'; ?><br>
                Intermunicipal: <?php echo $intermunicipal ? 'Sim' : 'Não'; ?>
            </td>
        </tr>

        <tr class="orc-section">
            <td colspan="4">RESUMO FINANCEIRO</td>
        </tr>
        <tr class="orc-header-row">
            <td colspan="3">DESCRIÇÃO</td>
            <td class="right">VALOR (R$)</td>
        </tr>
        <tr>
            <td colspan="3">Total dos itens</td>
            <td class="right">
                <?php echo number_format($valor_total, 2, ',', '.'); ?>
            </td>
        </tr>
        <tr class="orc-total-row">
            <td colspan="3">TOTAL DO ORÇAMENTO</td>
            <td class="right">
                R$ <?php echo number_format($valor_total, 2, ',', '.'); ?>
            </td>
        </tr>

        <tr class="orc-section">
            <td colspan="4">OBSERVAÇÕES</td>
        </tr>
        <tr>
            <td colspan="4" style="height:100px;vertical-align:top;">
                <?php echo nl2br(htmlspecialchars($detalhes, ENT_QUOTES, 'UTF-8')); ?>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
