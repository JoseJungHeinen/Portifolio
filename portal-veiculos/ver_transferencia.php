<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';

session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

if (empty($_GET['id'])) {
    exit('ID não informado');
}
$id = (int)$_GET['id'];

// BUSCA A SIMULAÇÃO DE TRANSFERÊNCIA
$stmt = $mysqli->prepare("SELECT * FROM transferencia WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res   = $stmt->get_result();
$trans = $res->fetch_assoc();
$stmt->close();

if (!$trans) {
    exit('Simulação de transferência não encontrada');
}

$cpf_cliente         = $trans['cpf_cliente']         ?? '';
$nome_cliente        = $trans['nome_cliente']        ?? '';
$tipo_id             = $trans['tipo_id']             ?? null;
$tipo_nome           = $trans['tipo_nome']           ?? '';
$ano_veiculo         = $trans['ano_veiculo']         ?? '';
$ipva_pago           = !empty($trans['ipva_pago']);
$intermunicipal      = !empty($trans['intermunicipal']);
$valor_total         = (float)($trans['valor_total']         ?? 0);
$detalhes            = $trans['detalhes']            ?? '';
$observacao          = $trans['observacao']          ?? '';
$data_criado         = $trans['criado_em']           ?? date('Y-m-d H:i:s');
$valor_placa         = (float)($trans['valor_placa']         ?? 0);
$valor_ipva_consider = (float)($trans['valor_ipva']          ?? 0);
$valor_transferencia = (float)($trans['valor_transferencia'] ?? 0);
$taxa_intermunicipal = (float)($trans['taxa_intermunicipal'] ?? 0);

// ===== DADOS DO VENDEDOR QUE CRIOU A TRANSFERÊNCIA (cpf_usuario) =====
$vend_nome = '';
$vend_tel  = '';

$cpf_usuario = $trans['cpf_usuario'] ?? '';

if ($cpf_usuario !== '') {
    $stmt = $mysqli->prepare("
        SELECT nome, telefone
          FROM usuarios
         WHERE cpf = ?
         LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('s', $cpf_usuario);
        $stmt->execute();
        $resVend = $stmt->get_result();
        if ($rowVend = $resVend->fetch_assoc()) {
            $vend_nome = $rowVend['nome']     ?? '';
            $vend_tel  = $rowVend['telefone'] ?? '';
        }
        $stmt->close();
    }
}

// ===== LOJA QUE GEROU A TRANSFERÊNCIA (id_loja da tabela, fallback sessão) =====
$id_loja = (int)($trans['id_loja'] ?? ($_SESSION['id_loja'] ?? 1));

$stmt = $mysqli->prepare("
    SELECT nome, cnpj, telefone, endereco
      FROM lojas
     WHERE id = ?
");
$stmt->bind_param("i", $id_loja);
$stmt->execute();
$loja = $stmt->get_result()->fetch_assoc();
$stmt->close();

$loja_nome     = $loja['nome']     ?? 'Empresa Exemplo';
$loja_cnpj     = $loja['cnpj']     ?? '';
$loja_telefone = $loja['telefone'] ?? '';
$loja_endereco = $loja['endereco'] ?? '';

// BUSCAR TEXTO LEGAL TRANSFERÊNCIA
$texto_legal_transferencia = '';
$stmt = $mysqli->prepare("
    SELECT valor
      FROM config_sistema
     WHERE chave = 'texto_legal_transferencia'
     LIMIT 1
");
if ($stmt) {
    $stmt->execute();
    $r = $stmt->get_result();
    if ($row = $r->fetch_assoc()) {
        $texto_legal_transferencia = $row['valor'] ?? '';
    }
    $stmt->close();
}
if (empty($texto_legal_transferencia)) {
    $texto_legal_transferencia =
        "Os dados pessoais, bancários e de pagamento fornecidos para realização de transferências " .
        "serão utilizados exclusivamente para processar a transação, emitir documentos fiscais, " .
        "registrar o negócio jurídico e cumprir obrigações legais e regulatórias aplicáveis. " .
        "A concessionária poderá compartilhar tais dados com instituições financeiras, prestadores " .
        "de serviços de pagamento, bureaus de crédito e órgãos públicos, sempre na medida necessária " .
        "para a execução da transferência e atendimento às exigências legais. " .
        "Os dados serão tratados em conformidade com a Lei Geral de Proteção de Dados Pessoais (LGPD) " .
        "e com a Política de Privacidade da concessionária, disponível em seus canais oficiais. " .
        "Você poderá exercer seus direitos como titular de dados por meio dos canais de atendimento " .
        "indicados pela concessionária.";
}

// Variáveis para o cabeçalho compartilhado
$titulo_orcamento = "Simulação Transferência";
$numero_orcamento = $id;
$data_emissao     = date('d/m/Y H:i', strtotime($data_criado));
// $vend_nome, $vend_tel, $loja_nome, $loja_cnpj, $loja_telefone já preenchidos
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
    padding:6px 8px;
    line-height:1.4;
}
.orc-section td{
    background:#e6e6e6;
    font-weight:bold;
    padding:6px 8px;
}
.orc-total-row td{
    font-weight:bold;
}
.right{text-align:right;}
.no-print{display:inline-block;}
@media print{
    @page{
        size:A4;
        margin:10mm;
    }
    .no-print{
        display:none!important;
    }
}
.app-version-label{
    position:fixed;
    bottom:4px;
    right:8px;
    font-size:10px;
    color:#888;
    opacity:0.6;
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    pointer-events:none;
    z-index:9999;
}
</style>
</head>
<body>
<div class="print-layout">

<?php include 'cabecalho_impressao.php'; ?>

<div class="no-print" style="
     position:fixed;top:20px;right:20px;
     display:flex;flex-direction:column;gap:6px;z-index:1000;">
    <button style="padding:10px 20px;background:#2347c8;color:#fff;border:none;
                   border-radius:5px;cursor:pointer;font-size:14px;"
            onclick="window.print();">
        🖨️ Imprimir
    </button>
    <button style="padding:10px 20px;background:#cccccc;color:#333;border:none;
                   border-radius:5px;cursor:pointer;font-size:12px;"
            onclick="window.location.href='index.blade.transferencia.php?editar=<?php echo $id; ?>';">
        Voltar
    </button>
</div>

<h2 class="orc-title">SIMULAÇÃO TRANSFERÊNCIA</h2>
<p class="orc-subtitle">
    Número: <?php echo sprintf('%04d', $id); ?> |
    Data: <?php echo date('d/m/Y H:i', strtotime($data_criado)); ?>
</p>

<table class="orc-table">
    <!-- DADOS DO CLIENTE -->
    <tr class="orc-section">
        <td colspan="4">DADOS DO CLIENTE</td>
    </tr>
    <tr>
        <td style="width:30%; font-weight:bold;">Nome:</td>
        <td colspan="3">
            <?php echo htmlspecialchars($nome_cliente, ENT_QUOTES, 'UTF-8'); ?>
        </td>
    </tr>
    <tr>
        <td style="font-weight:bold;">CPF:</td>
        <td colspan="3">
            <?php echo htmlspecialchars($cpf_cliente, ENT_QUOTES, 'UTF-8'); ?>
        </td>
    </tr>

    <!-- DADOS DA TRANSFERÊNCIA -->
    <tr class="orc-section">
        <td colspan="4">DADOS DA TRANSFERÊNCIA</td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Tipo:</td>
        <td colspan="3">
            <?php echo htmlspecialchars($tipo_nome, ENT_QUOTES, 'UTF-8'); ?>
        </td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Ano do veículo:</td>
        <td colspan="3"><?php echo (int)$ano_veiculo; ?></td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Intermunicipal:</td>
        <td colspan="3"><?php echo $intermunicipal ? 'Sim' : 'Não'; ?></td>
    </tr>
    <tr>
        <td style="font-weight:bold;">IPVA pago:</td>
        <td colspan="3"><?php echo $ipva_pago ? 'Sim' : 'Não'; ?></td>
    </tr>

    <!-- DETALHES -->
    <tr class="orc-section">
        <td colspan="4">DETALHES DA TRANSFERÊNCIA - Serviços Inclusos</td>
    </tr>
    <tr>
        <td colspan="4">
            <div style="font-size:9pt;line-height:1.4;padding:4px 8px;">
                <p>Placa</p>
                <p>IPVA</p>
                <p>Transferência</p>
                <p>Transferência intermunicipal</p>
            </div>
        </td>
    </tr>

    <!-- VALOR TOTAL -->
    <tr class="orc-total-row">
        <td colspan="3">TOTAL DA TRANSFERÊNCIA</td>
        <td class="right">
            R$ <?php echo number_format($valor_total, 2, ',', '.'); ?>
        </td>
    </tr>

    <!-- OBSERVAÇÕES -->
    <tr class="orc-section">
        <td colspan="4">OBSERVAÇÕES</td>
    </tr>
    <tr>
        <td colspan="4" style="height:100px;vertical-align:top;">
            <?php echo nl2br(htmlspecialchars($observacao ?? '', ENT_QUOTES, 'UTF-8')); ?>
        </td>
    </tr>
</table>

<!-- TEXTO LEGAL -->
<table class="orc-table" style="margin-top:10px;">
    <tr class="orc-section">
        <td>INFORMAÇÕES SOBRE PRIVACIDADE E PROTEÇÃO DE DADOS</td>
    </tr>
    <tr>
        <td style="font-size:7pt; line-height:1.3; text-align:justify; padding:8px;">
            <?php echo nl2br(htmlspecialchars($texto_legal_transferencia, ENT_QUOTES, 'UTF-8')); ?>
        </td>
    </tr>
</table>

<div class="app-version-label">
    <?php echo VERSAO_VER_TRANSFERENCIA; ?>
</div>

</div>
</body>
</html>
