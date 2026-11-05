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

// BUSCA A SIMULAÇÃO DE CARTÃO
$stmt = $mysqli->prepare("SELECT * FROM cartao WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res    = $stmt->get_result();
$cartao = $res->fetch_assoc();
$stmt->close();

if (!$cartao) {
    exit('Simulação de cartão não encontrada');
}

// ===== LOJA QUE GEROU A SIMULAÇÃO (id_loja da tabela, fallback sessão) =====
$id_loja = (int)($cartao['id_loja'] ?? ($_SESSION['id_loja'] ?? 1));

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

// ===== DADOS DO VENDEDOR QUE CRIOU A SIMULAÇÃO (cpf_usuario) =====
$vend_nome = '';
$vend_tel  = '';

$cpf_usuario = $cartao['cpf_usuario'] ?? '';

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

// dados gravados
$nome_cliente    = $cartao['nome_cliente']    ?? '';
$cpf_cliente     = $cartao['cpf_cliente']     ?? '';
$cartao_bandeira = $cartao['bandeira']        ?? '';
$qtd_meses       = (int)($cartao['qtd_meses'] ?? 1);
$valor_total_bd  = (float)($cartao['valor_total']   ?? 0);
$valor_parc_bd   = (float)($cartao['valor_parcela'] ?? 0);
$data_criado     = $cartao['criado_em']       ?? '';
$tipo_pagamento  = $cartao['tipo_pagamento']  ?? 'credito';

// taxa adm
$taxa_adm_percent = 2.5;
$taxa_adm         = $taxa_adm_percent / 100;

// fatores
$fatores = [
    1  => 1.018,
    2  => 0.518,
    3  => 0.351,
    4  => 0.268,
    5  => 0.218,
    6  => 0.185,
    7  => 0.161,
    8  => 0.143,
    9  => 0.129,
    10 => 0.118,
    11 => 0.109,
    12 => 0.101,
];

// calcula valor_compra aproximado a partir do total gravado
$valor_compra = 0.0;
if ($tipo_pagamento === 'debito') {
    // débito: total = P * (1 + taxa_adm)
    $valor_compra = $valor_total_bd / (1 + $taxa_adm);
} else {
    if (isset($fatores[$qtd_meses]) && $fatores[$qtd_meses] > 0) {
        // total = P*(1+taxa)*fator*n  =>  P = total / ((1+taxa)*fator*n)
        $valor_compra = $valor_total_bd / ((1 + $taxa_adm) * $fatores[$qtd_meses] * $qtd_meses);
    } else {
        $valor_compra = $valor_total_bd;
    }
}

// se tiver POST, recalcula usando o novo número de parcelas (somente crédito)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tipo_pagamento === 'credito') {
    $novo_qtd = isset($_POST['qtd_meses']) ? (int)$_POST['qtd_meses'] : $qtd_meses;
    if ($novo_qtd >= 1 && $novo_qtd <= 12) {
        $qtd_meses = $novo_qtd;
    }
}

// valor base com taxa de adm
$debito = $valor_compra * (1 + $taxa_adm);

// monta tabela (só será usada em crédito)
$tabela_parcelas = [];
foreach ($fatores as $i => $fator) {
    $valor_parc_i = $debito * $fator;
    $total_i      = $valor_parc_i * $i;

    $tabela_parcelas[] = [
        'n'       => $i,
        'parcela' => $valor_parc_i,
        'total'   => $total_i,
    ];
}

// opção escolhida
if ($tipo_pagamento === 'debito') {
    $qtd_meses         = 1;
    $resultado_parcela = $valor_total_bd;
    $resultado_total   = $valor_total_bd;
} else {
    $fator_escolhido   = $fatores[$qtd_meses] ?? 0;
    $resultado_parcela = $debito * $fator_escolhido;
    $resultado_total   = $resultado_parcela * $qtd_meses;
}

// BUSCA TEXTO LEGAL DO CARTÃO
$texto_cartao = '';
$stmt = $mysqli->prepare("SELECT valor FROM config_sistema WHERE chave = 'texto_legal_cartao' LIMIT 1");
if ($stmt) {
    $stmt->execute();
    $resTexto = $stmt->get_result();
    if ($rowTxt = $resTexto->fetch_assoc()) {
        $texto_cartao = $rowTxt['valor'] ?? '';
    }
    $stmt->close();
}

// Variáveis para o cabeçalho compartilhado
$titulo_orcamento = "Simulação Cartão";
$numero_orcamento = $id;
$data_emissao     = date('d/m/Y H:i', strtotime($data_criado));
// $vend_nome, $vend_tel, $loja_nome, $loja_cnpj, $loja_telefone já preenchidos

// texto padrão se vazio
if (empty($texto_cartao)) {
    $texto_cartao =
        "Os dados pessoais e financeiros fornecidos para pagamento com cartão serão utilizados " .
        "exclusivamente para processar a transação, prevenir fraudes, emitir documentos fiscais e " .
        "cumprir obrigações legais e regulatórias aplicáveis. A concessionária poderá compartilhar " .
        "esses dados com operadoras de cartão, instituições financeiras, empresas de meios de pagamento " .
        "e prestadores de serviços antifraude estritamente na medida necessária para a execução da " .
        "transação e atendimento às exigências legais. Os dados serão tratados em conformidade com a " .
        "Lei Geral de Proteção de Dados Pessoais (LGPD) e com a Política de Privacidade da concessionária, " .
        "disponível em seus canais oficiais. Você poderá exercer seus direitos como titular de dados " .
        "por meio dos canais de atendimento indicados pela concessionária.";
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Simulação #<?php echo $id; ?></title>
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
.result-title{
    font-weight:bold;
    margin-bottom:8px;
    font-size:11pt;
}
.orc-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    border-bottom:1px solid #ccc;
    padding-bottom:10px;
    margin-bottom:15px;
}
.orc-logo img{
    width:90px;
    height:auto;
}
.orc-header-text{
    text-align:right;
}
.orc-header-text p{
    font-size:9pt;
    line-height:1.3;
}
.table-wrap{
    margin-top:6px;
    display:flex;
    gap:20px;
    align-items:flex-start;
}
.parc-table{
    flex:1 1 60%;
    min-width:260px;
    border-collapse:collapse;
    font-size:9pt;
}
.parc-table td{
    border:1px solid #c0c0c0;
    padding:4px 6px;
}
.parc-table thead td{
    background:#f5f5f5;
    font-weight:bold;
}
.opcao-box{
    flex:0 0 220px;
    padding:8px 12px;
    border:1px solid #c0c0c0;
    border-radius:8px;
    background:#fafafa;
    font-size:9pt;
}
.opcao-box-title{
    font-weight:bold;
    margin-bottom:4px;
}
.opcao-box-total{
    margin-top:4px;
    font-weight:bold;
    color:#b00000;
}
.header-sim{
    margin-bottom:10px;
    font-size:9pt;
}
.header-sim strong{
    font-weight:bold;
}
.legal-block{
    margin-top:18px;
    padding:10px 12px;
    border:1px solid #d0d0d0;
    border-radius:8px;
    background:#f7f7f7;
    font-size:8pt;
    line-height:1.4;
    text-align:justify;
}
.legal-block-title{
    font-weight:bold;
    margin-bottom:4px;
    font-size:8.5pt;
}
.no-print{display:inline-block;}
@media print{
    @page{
        size:A4;
        margin:10mm;
    }
    .no-print{display:none!important;}
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

<div class="header-sim">
    <strong>SIMULAÇÃO CARTÃO DE <?php echo strtoupper($tipo_pagamento === 'debito' ? 'DÉBITO' : 'CRÉDITO'); ?></strong><br>
    Número: <?php echo sprintf('%04d', $id); ?> |
    Data: <?php echo date('d/m/Y H:i', strtotime($data_criado)); ?><br>
    Cliente: <?php echo htmlspecialchars($nome_cliente, ENT_QUOTES, 'UTF-8'); ?> |
    CPF: <?php echo htmlspecialchars($cpf_cliente, ENT_QUOTES, 'UTF-8'); ?><br>
    Bandeira: <?php echo htmlspecialchars($cartao_bandeira, ENT_QUOTES, 'UTF-8'); ?><br>
    Valor da compra: R$ <?php echo number_format($valor_compra, 2, ',', '.'); ?>
</div>

<!-- BOTÕES FIXOS -->
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
            onclick="window.location.href='index.blade.cartao.php?editar=<?php echo $id; ?>';">
        Voltar e editar
    </button>
</div>

<?php if ($tipo_pagamento === 'credito'): ?>

    <div class="result-title">Tabela de parcelas (simulação)</div>

    <div class="table-wrap">
        <table class="parc-table">
            <thead>
            <tr>
                <td style="text-align:center;">Nº</td>
                <td style="text-align:right;">R$ Parcela</td>
                <td style="text-align:right;">R$ Total</td>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tabela_parcelas as $linha): ?>
                <?php
                $styleHighlight = ($linha['n'] == $qtd_meses)
                    ? 'background-color:#fff9c4;'
                    : '';
                ?>
                <tr style="<?php echo $styleHighlight; ?>">
                    <td style="text-align:center;"><?php echo $linha['n']; ?></td>
                    <td style="text-align:right;"><?php echo number_format($linha['parcela'], 2, ',', '.'); ?></td>
                    <td style="text-align:right;"><?php echo number_format($linha['total'], 2, ',', '.'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="opcao-box">
            <div class="opcao-box-title">Opção escolhida</div>
            <div>Tipo: Crédito</div>
            <div>Parcelas: <?php echo (int)$qtd_meses; ?>x</div>
            <div>Parcela:
                <strong>R$ <?php echo number_format($resultado_parcela, 2, ',', '.'); ?></strong>
            </div>
            <div class="opcao-box-total">
                Total a pagar: R$ <?php echo number_format($resultado_total, 2, ',', '.'); ?>
            </div>
        </div>
    </div>

    <!-- FORMULÁRIO PARA RE-CALCULAR Nº DE PARCELAS -->
    <form method="POST" action="" style="margin-top:12px;font-size:9pt;">
        <input type="hidden" name="nome_cliente" value="<?php echo htmlspecialchars($nome_cliente, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="cpf_cliente" value="<?php echo htmlspecialchars($cpf_cliente, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="cartao_bandeira" value="<?php echo htmlspecialchars($cartao_bandeira, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="tipo_pagamento" value="<?php echo htmlspecialchars($tipo_pagamento, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="valor_compra" value="<?php echo htmlspecialchars(number_format($valor_compra, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">

        <span>Recalcular para nº de parcelas:</span>
        <input type="number" name="qtd_meses" value="<?php echo (int)$qtd_meses; ?>"
               min="1" max="12"
               style="width:50px;font-size:9pt;margin:0 4px;padding:2px 4px;">
        <input type="submit" value="Calcular"
               style="font-size:9pt;padding:2px 10px;cursor:pointer;">
    </form>

<?php else: ?>

    <div class="result-title">Simulação de débito</div>
    <p style="font-size:9pt;margin-top:4px;">
        Pagamento em 1x no débito. Valor com taxa de administração:
        <strong>R$ <?php echo number_format($resultado_total, 2, ',', '.'); ?></strong>
    </p>

<?php endif; ?>

<div class="legal-block">
    <div class="legal-block-title">Proteção de dados pessoais e financeiros</div>
    <div>
        <?php echo nl2br(htmlspecialchars($texto_cartao, ENT_QUOTES, 'UTF-8')); ?>
    </div>
    <div class="app-version-label">
        <?php echo VERSAO_VER_CARTAO; ?>
    </div>
</div>

</div>
</body>
</html>
