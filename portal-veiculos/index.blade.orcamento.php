<?php
require_once __DIR__ . '/config_database.php';
session_start();
if (empty($_SESSION['logado'])) {
    header('Location: index.php');
    exit;
}



// BACK-END SIMPLES PARA SIMULADOR DE ORÇAMENTO

$erros           = [];
$resultado_texto = '';
$resultado_total = null;
$orcamento       = null;
$orcamento_id    = null;

// variáveis para impressão
$descricao      = '';
$quantidade     = null;
$preco_unitario = null;
$desconto       = 0;
$adicional      = 0;
$subtotal       = 0;
$nome_cliente   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $descricao      = isset($_POST['descricao']) ? trim($_POST['descricao']) : '';
    $quantidade     = isset($_POST['quantidade']) ? (int)$_POST['quantidade'] : null;
    $preco_unitario = isset($_POST['preco_unitario']) ? (float)str_replace(',', '.', $_POST['preco_unitario']) : null;
    $desconto       = isset($_POST['desconto']) ? (float)str_replace(',', '.', $_POST['desconto']) : 0;
    $adicional      = isset($_POST['adicional']) ? (float)str_replace(',', '.', $_POST['adicional']) : 0;
    $nome_cliente   = isset($_POST['nome_cliente']) ? trim($_POST['nome_cliente']) : '';

    if ($descricao === '') {
        $erros[] = 'Informe a descrição do serviço/produto.';
    }
    if ($quantidade === null || $quantidade <= 0) {
        $erros[] = 'Informe uma quantidade válida.';
    }
    if ($preco_unitario === null || $preco_unitario <= 0) {
        $erros[] = 'Informe um preço unitário válido.';
    }
    if ($desconto < 0) {
        $erros[] = 'O desconto não pode ser negativo.';
    }
    if ($adicional < 0) {
        $erros[] = 'O valor adicional não pode ser negativo.';
    }

    if (empty($erros)) {
        $subtotal = $quantidade * $preco_unitario;
        $total    = $subtotal - $desconto + $adicional;
        if ($total < 0) {
            $total = 0;
        }

        $resultado_total = $total;
        $orcamento       = (object)['id' => 1];

        // monta texto
        $linhas = [];
        $linhas[] = "Data da simulação: " . date('d/m/Y H:i');
        $linhas[] = "Cliente: " . ($nome_cliente !== '' ? $nome_cliente : 'Não informado');
        $linhas[] = "Descrição: {$descricao}";
        $linhas[] = "Quantidade: {$quantidade}";
        $linhas[] = "Preço unitário: R$ " . number_format($preco_unitario, 2, ',', '.');
        $linhas[] = "Subtotal: R$ " . number_format($subtotal, 2, ',', '.');
        $linhas[] = "Desconto: R$ " . number_format($desconto, 2, ',', '.');
        $linhas[] = "Adicional: R$ " . number_format($adicional, 2, ',', '.');
        $linhas[] = "Total do orçamento: R$ " . number_format($resultado_total, 2, ',', '.');

        $resultado_texto = implode("\n", $linhas);

        // GRAVAR NO BANCO
        $cpf_usuario = $_SESSION['cpf'] ?? '';

        $stmt = $mysqli->prepare("INSERT INTO orcamento
            (cpf_usuario, nome_cliente, descricao, quantidade,
             preco_unitario, desconto, adicional, subtotal,
             valor_total, detalhes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if ($stmt) {
            $stmt->bind_param(
                "sssiddddds",
                $cpf_usuario,
                $nome_cliente,
                $descricao,
                $quantidade,
                $preco_unitario,
                $desconto,
                $adicional,
                $subtotal,
                $resultado_total,
                $resultado_texto
            );

            $stmt->execute();
            $orcamento_id = $mysqli->insert_id;
            $stmt->close();
        }

        if (!empty($orcamento_id)) {
            array_splice($linhas, 1, 0, "ID do orçamento: " . $orcamento_id);
            $resultado_texto = implode("\n", $linhas);

            $stmt2 = $mysqli->prepare("UPDATE orcamento SET detalhes = ? WHERE id = ?");
            if ($stmt2) {
                $stmt2->bind_param("si", $resultado_texto, $orcamento_id);
                $stmt2->execute();
                $stmt2->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ferramentas de Vendas - Orçamento</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{
            font-family:system-ui,-apple-system,BlinkMacSystemFont,"Futura","Segoe UI",sans-serif;
            background:#ffffff;min-height:100vh;display:flex;
            align-items:center;justify-content:center;color:#333333;
        }
        .page-container{
            background:#f4f4f4;padding:2.5rem 3rem;border-radius:12px;
            box-shadow:0 10px 25px rgba(0,0,0,0.12);width:100%;max-width:640px;
        }
        .logo{text-align:center;margin-bottom:1.5rem;}
        .logo img{max-width:160px;height:auto;margin-bottom:.4rem;}
        .page-title{
            text-align:center;margin-bottom:1.8rem;
            font-size:1.3rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
            color:#444;
        }
        .form-group{margin-bottom:1.1rem;}
        label{display:block;margin-bottom:.35rem;color:#555;font-weight:600;font-size:.9rem;letter-spacing:.03em;}
input, textarea{
    width:100%;padding:10px 14px;border:1px solid #c0c0c0;border-radius:999px;
    font-size:.95rem;background:#fff;color:#333;
}
        textarea{min-height:70px;resize:vertical;}
        .btn-primary{
            width:100%;padding:11px;background:#2347c8;color:#ffffff;border:none;
            border-radius:999px;font-size:.95rem;font-weight:700;cursor:pointer;
            letter-spacing:.06em;text-transform:uppercase;
            box-shadow:0 6px 16px rgba(35,71,200,0.45);
        }
        .btn-secondary,
        .btn-print{
            display:inline-block;
            margin-top:.9rem;
            padding:8px 18px;
            border-radius:999px;
            border:1px solid #ccc;
            background:#ffffff;
            color:#333;
            font-size:.85rem;
            text-decoration:none;
            text-align:center;
            cursor:pointer;
        }
        .btn-outline-red{
            border-color:#2347c8;color:#2347c8;background:#ffffff;
        }
        .message{margin-top:.9rem;padding:.8rem;border-radius:6px;font-size:.85rem;}
        .error{background:#ffd6d6;color:#b00000;border:1px solid #2347c8;}
        .result-box{
            margin-top:1.4rem;padding:1rem;border-radius:8px;background:#ffffff;
            border:1px solid #e0e0e0;font-size:.9rem;line-height:1.4;
        }
        .result-title{
            font-weight:700;margin-bottom:.6rem;font-size:.98rem;
        }
        .result-total{
            margin-top:.8rem;font-weight:700;font-size:1.05rem;color:#b00000;
        }
        .actions{
            margin-top:1rem;
            display:flex;
            flex-wrap:wrap;
            gap:.6rem;
            justify-content:space-between;
        }
        .actions a,
        .actions button{
            flex:1 1 30%;
        }
        .actions a:last-child{
            flex:1 1 100%;
            width:100%;
            text-align:center;
        }

        .screen-only{display:block;}
        .print-only{display:none;}

        /* LAYOUT DE IMPRESSÃO ESTILO PDF */

        .print-layout{
            font-family: Arial, Helvetica, sans-serif;
            font-size:11pt;
        }
        .orcamento-a4{
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 20mm 15mm;
            background:#ffffff;
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
        .right{
            text-align:right;
        }

        @media print{
            .btn-primary,.btn-secondary,.btn-print,.actions a,
            .screen-only{
                display:none !important;
            }
            .page-container{
                box-shadow:none;
                max-width:100%;
                padding:0;
            }
            .print-only{
                display:block;
            }
            body{
                background:#ffffff;
            }
            @page{
                size:A4;
                margin:10mm;
            }
        }
    </style>
</head>
<body>
<div class="page-container">
    <div class="page-title screen-only">
        Simulador de Orçamento
    </div>

    <?php if (!empty($erros)): ?>
        <div class="message error screen-only">
            <?php echo implode('<br>', array_map('htmlspecialchars', $erros)); ?>
        </div>
    <?php endif; ?>

    <form method="post" action="" class="screen-only">
        <div class="form-group">
            <label>Nome do cliente (opcional)</label>
            <input type="text" name="nome_cliente"
                   value="<?php echo isset($_POST['nome_cliente']) ? htmlspecialchars($_POST['nome_cliente'], ENT_QUOTES, 'UTF-8') : ''; ?>">
        </div>

        <div class="form-group">
            <label>Descrição do serviço/produto</label>
            <input type="text" name="descricao" required
                   value="<?php echo isset($_POST['descricao']) ? htmlspecialchars($_POST['descricao'], ENT_QUOTES, 'UTF-8') : ''; ?>">
        </div>

        <div class="form-group">
            <label>Quantidade</label>
            <input type="number" name="quantidade" min="1" required
                   value="<?php echo isset($_POST['quantidade']) ? htmlspecialchars($_POST['quantidade'], ENT_QUOTES, 'UTF-8') : '1'; ?>">
        </div>

        <div class="form-group">
            <label>Preço unitário (R$)</label>
            <input type="number" step="0.01" name="preco_unitario" required
                   value="<?php echo isset($_POST['preco_unitario']) ? htmlspecialchars($_POST['preco_unitario'], ENT_QUOTES, 'UTF-8') : ''; ?>">
        </div>

        <div class="form-group">
            <label>Desconto (R$)</label>
            <input type="number" step="0.01" name="desconto"
                   value="<?php echo isset($_POST['desconto']) ? htmlspecialchars($_POST['desconto'], ENT_QUOTES, 'UTF-8') : '0'; ?>">
        </div>

        <div class="form-group">
            <label>Valor adicional (R$)</label>
            <input type="number" step="0.01" name="adicional"
                   value="<?php echo isset($_POST['adicional']) ? htmlspecialchars($_POST['adicional'], ENT_QUOTES, 'UTF-8') : '0'; ?>">
        </div>

        <button type="submit" class="btn-primary">Calcular orçamento</button>
    </form>

    <?php if ($resultado_total !== null): ?>
        <div class="result-box screen-only">
            <?php if (!empty($orcamento_id)): ?>
                <div style="font-size:.85rem;margin-bottom:.4rem;">
                    ID do orçamento: <?php echo (int)$orcamento_id; ?>
                </div>
            <?php endif; ?>

            <div class="result-title">Resumo do orçamento</div>
            <div>
                <?php echo nl2br(htmlspecialchars($resultado_texto, ENT_QUOTES, 'UTF-8')); ?>
            </div>
            <div class="result-total">
                Total: R$ <?php echo number_format($resultado_total, 2, ',', '.'); ?>
            </div>

            <div class="actions">
                <a href="index.blade.orcamento.php" class="btn-secondary">
                    Novo orçamento
                </a>

                <a href="ver_orcamento.php?id=<?php echo (int)$orcamento_id; ?>"
					target="_blank"
					class="btn-secondary">
                    Imprimir
                </a>

                <a href="menu.php" class="btn-secondary">
                    Voltar ao menu
                </a>
            </div>
        </div>

        <!-- LAYOUT PARA IMPRESSÃO -->
        <div class="print-layout print-only">
            <div class="orcamento-a4">
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

                <h2 class="orc-title">ORÇAMENTO DE VEÍCULO</h2>
                <p class="orc-subtitle">
                    Número: <?php echo (int)$orcamento_id; ?> |
                    Data: <?php echo date('d/m/Y H:i'); ?>
                </p>

                <table class="orc-table">
                    <tr class="orc-section">
                        <td colspan="4">DADOS DO CLIENTE</td>
                    </tr>
                    <tr>
                        <td colspan="4">
                            <?php echo $nome_cliente !== '' ?
                                htmlspecialchars($nome_cliente, ENT_QUOTES, 'UTF-8') : 'Não informado'; ?>
                        </td>
                    </tr>

                    <tr class="orc-section">
                        <td colspan="4">VEÍCULO VENDIDO</td>
                    </tr>
                    <tr>
                        <td colspan="4">
                            <?php echo htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8'); ?>
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
                        <td colspan="3">Valor do veículo</td>
                        <td class="right">
                            <?php echo number_format($subtotal, 2, ',', '.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="3">Subtotal</td>
                        <td class="right">
                            <?php echo number_format($subtotal, 2, ',', '.'); ?>
                        </td>
                    </tr>
                    <tr class="orc-total-row">
                        <td colspan="3">TOTAL DO NEGÓCIO</td>
                        <td class="right">
                            R$ <?php echo number_format($resultado_total, 2, ',', '.'); ?>
                        </td>
                    </tr>

                    <tr class="orc-section">
                        <td colspan="4">CONDIÇÃO DE PAGAMENTO</td>
                    </tr>
                    <tr>
                        <td>Forma:</td>
                        <td colspan="3">&nbsp;</td>
                    </tr>

                    <tr class="orc-section">
                        <td colspan="4">OBSERVAÇÕES</td>
                    </tr>
                    <tr>
                        <td colspan="4" style="height:120px;vertical-align:top;">
                            <!-- Espaço para observações manuscritas ou futuras -->
                        </td>
                    </tr>
					<table class="no-border" style="margin-top:25px;font-size:8pt;text-align:center;">
                <tr>
                    <td style="border:none;">
                        Este orçamento é válido por 7 dias a partir da data acima. Para mais informações, entre em contato conosco.<br>
                        Página 1 de 1
                    </td>
                </tr>
            </table>
                </table>
            </div>
        </div>
    <?php else: ?>
        <a href="menu.php" class="btn-secondary screen-only">
            Voltar ao menu
        </a>
    <?php endif; ?>
</div>
</body>
</html>
