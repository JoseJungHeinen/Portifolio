<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

// ID vindo da listagem
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    exit('ID inválido');
}

/*
 * Busca exatamente o que o gerar_pdf gravou.
 * Aqui estou assumindo que a tabela ORCAMENTO tem:
 *  - cpf_usuario (quem fez o orçamento)
 *  - id_loja     (loja que gerou o orçamento)
 */
$stmt = $mysqli->prepare("
    SELECT
        o.*,
        u.nome        AS nome_usuario,
        u.telefone    AS tel_usuario,
        l.nome        AS loja,
        l.cnpj        AS cnpj_loja,
        l.telefone    AS loja_telefone
    FROM orcamento o
    LEFT JOIN usuarios u ON u.cpf = o.cpf_usuario
    LEFT JOIN lojas    l ON l.id  = COALESCE(NULLIF(o.id_loja, 0), u.id_loja)
    WHERE o.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$orc = $res->fetch_assoc();
$stmt->close();

if (!$orc) {
    exit('Orçamento não encontrado.');
}

// monta variáveis principais
$numero_orcamento = $orc['id'];
$data_orcamento   = !empty($orc['criado_em'])
    ? date('d/m/Y H:i', strtotime($orc['criado_em']))
    : date('d/m/Y H:i');

$cliente_nome     = $orc['cliente_nome']     ?? '';
$cliente_cpf      = $orc['cliente_cpf']      ?? '';
$cliente_telefone = $orc['cliente_telefone'] ?? '';

$loja             = $orc['loja']       ?? 'Empresa Exemplo';
$cnpj_loja        = $orc['cnpj_loja']  ?? 'XX.XXX.XXX/0001-XX';
$nome_usuario     = $orc['nome_usuario'] ?? 'Usuário N/A';
$tel_usuario      = $orc['tel_usuario']  ?? '';

$loja_telefone    = $orc['loja_telefone'] ?? '';

$valor_total      = (float)($orc['valor_total'] ?? 0);
$subtotal         = (float)($orc['subtotal']     ?? 0);

// dados do veículo principal
$veic_descricao   = $orc['descricao']       ?? '';
$veic_quantidade  = (int)($orc['quantidade']     ?? 1);
$veic_preco_unit  = (float)($orc['preco_unitario'] ?? 0);

// veículos em troca
$troca_descricao  = $orc['troca_descricao'] ?? '';
$troca_valor      = isset($orc['troca_valor']) ? (float)$orc['troca_valor'] : 0;

// cabeçalho compartilhado
$titulo_orcamento = "Orçamento de Veículo";
$data_emissao     = $data_orcamento;
$vend_nome        = $nome_usuario;
$vend_tel         = $tel_usuario;
$loja_nome        = $loja;
$loja_cnpj        = $cnpj_loja;

// texto legal
$texto_legal = '';
$stmt = $mysqli->prepare("
    SELECT valor
      FROM config_sistema
     WHERE chave = 'texto_legal_orcamento'
     LIMIT 1
");
if ($stmt) {
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $texto_legal = $r['valor'] ?? '';
    $stmt->close();
}
if (empty($texto_legal)) {
    $texto_legal = "DISPOSIÇÕES LEGAIS: ...";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Orçamento #<?= htmlspecialchars($numero_orcamento, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        @media print {
            @page { size: A4; margin: 10mm; }
            body { margin:0; padding:0; filter:grayscale(100%); }
            .orc-logo, .orc-header-red { filter:grayscale(0%); }
            .no-print { display:none !important; }
        }
        *{margin:0;padding:0;box-sizing:border-box}
        body{
            font-family:Arial,Helvetica,sans-serif;
            font-size:11pt;
            color:#000;
            background:#fff;
            padding:10mm;
        }
        .orcamento-container{max-width:190mm;margin:0 auto;background:#fff;}
        .orc-header{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            border-bottom:2px solid #c0c0c0;
            padding-bottom:10px;
            margin-bottom:15px;
        }
        .orc-logo{width:80px;}
        .orc-logo img{width:100%;height:auto;}
        .orc-header-text{text-align:right;}
        .orc-header-text h1{
            font-size:18pt;
            color:#000;
            margin-bottom:4px;
            font-weight:bold;
        }
        .orc-header-text p{
            font-size:9pt;
            line-height:1.4;
            color:#333;
        }
        .orc-title{
            text-align:center;
            font-size:14pt;
            font-weight:bold;
            margin:10px 0 3px;
            text-transform:uppercase;
        }
        .orc-subtitle{
            text-align:center;
            font-size:9pt;
            color:#666;
            margin-bottom:15px;
        }
        .orc-section{margin-bottom:12px;page-break-inside:avoid;}
        .orc-section-title{
            background:#c0c0c0;
            color:#fff;
            padding:5px 8px;
            font-size:10pt;
            font-weight:bold;
            text-transform:uppercase;
            margin-bottom:5px;
        }
        .orc-table{
            width:100%;
            border-collapse:collapse;
            font-size:9pt;
            margin-bottom:8px;
        }
        .orc-table td{
            border:1px solid #ccc;
            padding:5px 7px;
            vertical-align:top;
            background:#fff;
        }
        .orc-table td:first-child{
            font-weight:bold;
            width:30%;
            background:#f5f5f5;
        }
        .orc-footer{
            margin-top:20px;
            padding-top:10px;
            border-top:1px solid #ccc;
            font-size:8pt;
            text-align:center;
            color:#666;
        }
        .btn-print{
            position:fixed;
            top:20px;
            right:20px;
            padding:10px 20px;
            background:#2347c8;
            color:#fff;
            border:none;
            border-radius:5px;
            cursor:pointer;
            font-size:14px;
            z-index:1000;
        }
        .btn-print:hover{background:#4b3535;}
    </style>
</head>
<body>

<button class="btn-print no-print" onclick="window.print()">🖨️ Imprimir</button>
<button class="btn-print no-print"
        style="right: 160px; background:#cccccc; color:#333;"
        onclick="window.close();">
    Fechar
</button>

<div class="orcamento-container">

    <!-- CABEÇALHO COMPARTILHADO -->
    <?php include 'cabecalho_impressao.php'; ?>

    <!-- TÍTULO -->
    <h2 class="orc-title">Orçamento de Veículo</h2>
    <p class="orc-subtitle">
        Nº <?= htmlspecialchars($numero_orcamento, ENT_QUOTES, 'UTF-8') ?>
        | Data: <?= htmlspecialchars($data_orcamento, ENT_QUOTES, 'UTF-8') ?>
    </p>

    <!-- DADOS DO CLIENTE -->
    <div class="orc-section">
        <div class="orc-section-title">Dados do Cliente</div>
        <table class="orc-table">
            <tr>
                <td>Nome</td>
                <td><?= htmlspecialchars($cliente_nome ?: 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <tr>
                <td>CPF</td>
                <td><?= htmlspecialchars($cliente_cpf ?: 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <tr>
                <td>Telefone</td>
                <td><?= htmlspecialchars($cliente_telefone ?: 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        </table>
    </div>

    <!-- VEÍCULO PRINCIPAL -->
    <div class="orc-section">
        <div class="orc-section-title">Veículo Orçado</div>
        <table class="orc-table">
            <tr>
                <td>Descrição</td>
                <td><?= htmlspecialchars($veic_descricao ?: 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <tr>
                <td>Quantidade</td>
                <td><?= $veic_quantidade ?></td>
            </tr>
            <tr>
                <td>Preço unitário</td>
                <td>R$ <?= number_format($veic_preco_unit, 2, ',', '.') ?></td>
            </tr>
            <tr>
                <td>Subtotal</td>
                <td>R$ <?= number_format($subtotal, 2, ',', '.') ?></td>
            </tr>
        </table>
    </div>

    <!-- VEÍCULOS EM TROCA -->
    <?php if (!empty($troca_descricao)): ?>
        <?php $linhas_troca = array_filter(explode("\n", $troca_descricao)); ?>
        <div class="orc-section">
            <div class="orc-section-title">VEÍCULOS EM TROCA</div>
            <table class="orc-table">
                <tr>
                    <td style="padding:0;" colspan="2">
                        <table class="orc-table" style="margin-bottom:0;">
                            <tr>
                                <td><strong>Modelo</strong></td>
                                <td><strong>Ano</strong></td>
                                <td><strong>Cor</strong></td>
                                <td><strong>Placa</strong></td>
                            </tr>
                            <?php foreach ($linhas_troca as $linha): ?>
                                <?php
                                    $partes = explode('|', $linha);
                                    $modelo = trim($partes[0] ?? '');
                                    $ano    = trim($partes[1] ?? '');
                                    $cor    = trim($partes[2] ?? '');
                                    $placa  = trim($partes[3] ?? '');
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($modelo, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($ano, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($cor, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($placa, ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </td>
                </tr>

                <?php if ($troca_valor > 0): ?>
                <tr>
                    <td><strong>Valor total da troca</strong></td>
                    <td>R$ <?= number_format($troca_valor, 2, ',', '.') ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
    <?php endif; ?>

    <!-- RESUMO FINANCEIRO -->
    <div class="orc-section">
        <div class="orc-section-title">Resumo Financeiro</div>
        <table class="orc-table">
            <tr>
                <td>Total do negócio</td>
                <td>R$ <?= number_format($valor_total, 2, ',', '.') ?></td>
            </tr>
        </table>
    </div>

    <!-- TEXTO LEGAL -->
    <div class="orc-section">
        <div class="orc-section-title">Informações sobre Privacidade e Proteção de Dados</div>
        <table class="orc-table">
            <tr>
                <td style="font-size:7pt; line-height:1.3; text-align:justify; padding:8px;">
                    <?= nl2br(htmlspecialchars($texto_legal, ENT_QUOTES, 'UTF-8')) ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- RODAPÉ -->
    <div class="orc-footer">
        <p>Este orçamento é válido por 7 dias a partir da data acima.</p>
        <p>Para mais informações, entre em contato conosco.</p>
        <p style="margin-top:8px;"><strong>Empresa Exemplo - Concessionária de Veículos</strong></p>
    </div>
</div>
</body>
</html>
