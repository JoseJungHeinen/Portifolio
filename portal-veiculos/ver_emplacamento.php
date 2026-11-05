<?php
require_once __DIR__ . '/config_database.php';
require_once __DIR__ . '/config_versao.php';

session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

if (empty($_GET['id'])) {
    exit('ID não informado');
}
$id = (int)$_GET['id'];

// SIMULAÇÃO DE EMPLACAMENTO
$stmt = $mysqli->prepare("SELECT * FROM emplacamento WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$emp = $res->fetch_assoc();
$stmt->close();

if (!$emp) {
    exit('Simulação de emplacamento não encontrada');
}

$cpf_cliente  = $emp['cpf_cliente']  ?? '';
$nome_cliente = $emp['nome_cliente'] ?? '';
$tipo_id      = $emp['tipo_id']      ?? null;
$placa        = $emp['placa']        ?? '';
$tipo_veiculo = $emp['tipo_veiculo'] ?? '';
$valor_total  = (float)($emp['valor'] ?? 0);
$detalhes     = $emp['observacoes']  ?? ''; // texto técnico
$observacao   = $emp['observacao']   ?? ''; // texto livre
$data_criado  = $emp['criado_em']    ?? date('Y-m-d H:i:s');
$escolha_placa = strpos($detalhes, 'Escolha de placa: Sim') !== false;

// valores detalhados (se existirem)
$valor_taxas         = (float)($emp['valor_taxas']         ?? 0);
$valor_ipva          = (float)($emp['valor_ipva']          ?? 0);
$valor_placa_padro   = (float)($emp['valor_placa_padrao'] ?? 0);
$valor_escolha_placa = (float)($emp['valor_escolha_placa'] ?? 0);

// ===== DADOS DO VENDEDOR QUE CRIOU O EMPLACAMENTO (cpf_usuario) =====
$vend_nome = '';
$vend_tel  = '';

$cpf_usuario = $emp['cpf_usuario'] ?? '';

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

// ===== LOJA QUE GEROU O EMPLACAMENTO (id_loja da tabela, fallback sessão) =====
$id_loja = (int)($emp['id_loja'] ?? ($_SESSION['id_loja'] ?? 1));

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

// TEXTO LEGAL EMPLACAMENTO
$texto_legal_emplacamento = '';
$stmt = $mysqli->prepare("
    SELECT valor
      FROM config_sistema
     WHERE chave = 'texto_legal_emplacamento'
     LIMIT 1
");
if ($stmt) {
    $stmt->execute();
    $r = $stmt->get_result();
    if ($row = $r->fetch_assoc()) {
        $texto_legal_emplacamento = $row['valor'] ?? '';
    }
    $stmt->close();
}

if (empty($texto_legal_emplacamento)) {
    $texto_legal_emplacamento =
        "Os dados pessoais utilizados para a prestação dos serviços de emplacamento " .
        "serão tratados exclusivamente para a execução do serviço, emissão de documentos " .
        "e cumprimento de obrigações legais, em conformidade com a LGPD e com a Política " .
        "de Privacidade da concessionária.";
}

// Variáveis para o cabeçalho compartilhado
$titulo_orcamento = "Simulação Emplacamento";
$numero_orcamento = $id;
$data_emissao     = date('d/m/Y H:i', strtotime($data_criado));
// $vend_nome e $vend_tel já vieram da consulta por cpf_usuario
// $loja_nome, $loja_cnpj, $loja_telefone já vieram da tabela lojas
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Emplacamento #<?php echo $id; ?></title>
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
    padding:4px 6px;
}
.orc-section td{
    background:#e6e6e6;
    font-weight:bold;
}
.orc-total-row td{
    font-weight:bold;
}
.right{text-align:right;}

.no-print{
    display:inline-block;
}

@media print{
    @page{
        size:A4;
        margin:10mm;
    }
    .no-print{
        display:none !important;
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

<!-- BOTÕES FIXOS -->
<div class="no-print" style="
     position:fixed;
     top:20px;
     right:20px;
     display:flex;
     flex-direction:column;
     gap:6px;
     z-index:1000;">
    <button style="padding:10px 20px;background:#2347c8;color:#fff;border:none;
                   border-radius:5px;cursor:pointer;font-size:14px;"
            onclick="window.print();">
        🖨️ Imprimir
    </button>

    <button style="padding:10px 20px;background:#cccccc;color:#333;border:none;
                   border-radius:5px;cursor:pointer;font-size:12px;"
            onclick="window.location.href='index.blade.emplacamento.php?editar=<?php echo $id; ?>';">
        Voltar
    </button>
</div>

<table class="orc-table">
    <!-- DADOS DO CLIENTE -->
    <tr class="orc-section">
        <td colspan="4">DADOS DO CLIENTE</td>
    </tr>
    <tr>
        <td style="width:30%;font-weight:bold;">Nome:</td>
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

    <!-- DADOS DO EMPLACAMENTO -->
    <tr class="orc-section">
        <td colspan="4">DADOS DO EMPLACAMENTO</td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Tipo:</td>
        <td colspan="3">
            <?php echo htmlspecialchars($tipo_veiculo, ENT_QUOTES, 'UTF-8'); ?>
        </td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Escolha de placa:</td>
        <td colspan="3">
            <?php echo $escolha_placa ? 'Sim' : 'Não'; ?>
        </td>
    </tr>
    <tr>
        <td style="font-weight:bold;">Placa:</td>
        <td colspan="3">
            <?php echo $placa !== '' ? htmlspecialchars($placa, ENT_QUOTES, 'UTF-8') : 'Não informada'; ?>
        </td>
    </tr>

    <!-- DETALHES DO EMPLACAMENTO -->
    <tr class="orc-section">
        <td colspan="4">DETALHES DO EMPLACAMENTO</td>
    </tr>
    <tr>
        <td colspan="4" style="padding:4px 8px;font-size:9pt;line-height:1.4;">
            <p>Valor da nota fiscal</p>
            <p>Taxas</p>
            <p>IPVA considerado</p>
            <p>Placa padrão</p>
            <p>Serviço escolha de placa</p>
        </td>
    </tr>

    <!-- VALOR -->
    <tr class="orc-total-row">
        <td colspan="3">TOTAL DO EMPLACAMENTO</td>
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
            <?php echo nl2br(htmlspecialchars($texto_legal_emplacamento, ENT_QUOTES, 'UTF-8')); ?>
        </td>
    </tr>
</table>

<div class="app-version-label">
    <?php echo VERSAO_VER_EMPLACAMENTO; ?>
</div>
</div>
</body>
</html>
