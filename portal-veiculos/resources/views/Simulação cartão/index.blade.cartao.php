<?php
// ------- BACK-END SIMPLES PARA SIMULADOR DE CARTÃO DE CRÉDITO -------

// Variáveis de resultado
$erros           = [];
$resultado_texto = '';
$resultado_total = null;
$resultado_parcela = null;

// Processa o formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Captura dados
    $limite        = isset($_POST['limite']) ? (float)str_replace(',', '.', $_POST['limite']) : null;
    $valor_compra  = isset($_POST['valor_compra']) ? (float)str_replace(',', '.', $_POST['valor_compra']) : null;
    $juros_mes     = isset($_POST['juros_mes']) ? (float)str_replace(',', '.', $_POST['juros_mes']) : null;
    $qtd_meses     = isset($_POST['qtd_meses']) ? (int)$_POST['qtd_meses'] : null;
    $nome_cliente  = isset($_POST['nome_cliente']) ? trim($_POST['nome_cliente']) : '';
    $cartao_bandeira = isset($_POST['cartao_bandeira']) ? trim($_POST['cartao_bandeira']) : '';

    // Validações básicas
    if ($limite === null || $limite <= 0) {
        $erros[] = 'Informe um limite de cartão válido.';
    }

    if ($valor_compra === null || $valor_compra <= 0) {
        $erros[] = 'Informe um valor de compra válido.';
    }

    if ($valor_compra !== null && $limite !== null && $valor_compra > $limite) {
        $erros[] = 'O valor da compra não pode ser maior que o limite do cartão.';
    }

    if ($juros_mes === null || $juros_mes < 0) {
        $erros[] = 'Informe uma taxa de juros mensal válida.';
    }

    if ($qtd_meses === null || $qtd_meses <= 0) {
        $erros[] = 'Informe uma quantidade de meses válida.';
    }

    // Se não há erros, faz a simulação
    if (empty($erros)) {
        // Cálculo de parcelamento com juros compostos
        // Fórmula: parcela = P * i / (1 - (1 + i)^(-n))  [web:41][web:51]
        $P = $valor_compra;
        $i = $juros_mes / 100.0;
        $n = $qtd_meses;

        if ($i > 0) {
            $parcela = $P * $i / (1 - pow(1 + $i, -$n));
            $total   = $parcela * $n;
        } else {
            // Sem juros
            $parcela = $P / $n;
            $total   = $P;
        }

        $resultado_parcela = $parcela;
        $resultado_total   = $total;

        // Monta texto explicativo
        $linhas = [];
        $linhas[] = "Nome do cliente: " . ($nome_cliente !== '' ? $nome_cliente : 'Não informado');
        $linhas[] = "Bandeira do cartão: " . ($cartao_bandeira !== '' ? $cartao_bandeira : 'Não informada');
        $linhas[] = "Limite do cartão: R$ " . number_format($limite, 2, ',', '.');
        $linhas[] = "Valor da compra: R$ " . number_format($valor_compra, 2, ',', '.');
        $linhas[] = "Taxa de juros ao mês: " . number_format($juros_mes, 2, ',', '.') . " %";
        $linhas[] = "Quantidade de parcelas: {$qtd_meses}";
        $linhas[] = "Valor de cada parcela: R$ " . number_format($resultado_parcela, 2, ',', '.');
        $linhas[] = "Total ao final: R$ " . number_format($resultado_total, 2, ',', '.');

        $resultado_texto = implode("\n", $linhas); // será exibido com nl2br [web:30]
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Simulador de Cartão de Crédito</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-4">

    <h1 class="mb-4">Simulador de Cartão de Crédito</h1>

    <?php if (!empty($erros)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($erros as $erro): ?>
                    <li><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label">Nome do cliente (opcional)</label>
                    <input type="text" name="nome_cliente" class="form-control"
                           value="<?php echo isset($_POST['nome_cliente']) ? htmlspecialchars($_POST['nome_cliente'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Bandeira do cartão (opcional)</label>
                    <input type="text" name="cartao_bandeira" class="form-control"
                           value="<?php echo isset($_POST['cartao_bandeira']) ? htmlspecialchars($_POST['cartao_bandeira'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Limite do cartão (R$)</label>
                    <input type="number" step="0.01" name="limite" class="form-control"
                           value="<?php echo isset($_POST['limite']) ? htmlspecialchars($_POST['limite'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                           required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Valor da compra (R$)</label>
                    <input type="number" step="0.01" name="valor_compra" class="form-control"
                           value="<?php echo isset($_POST['valor_compra']) ? htmlspecialchars($_POST['valor_compra'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                           required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Taxa de juros ao mês (%)</label>
                    <input type="number" step="0.01" name="juros_mes" class="form-control"
                           value="<?php echo isset($_POST['juros_mes']) ? htmlspecialchars($_POST['juros_mes'], ENT_QUOTES, 'UTF-8') : '0'; ?>"
                           required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Quantidade de parcelas (meses)</label>
                    <input type="number" name="qtd_meses" class="form-control"
                           value="<?php echo isset($_POST['qtd_meses']) ? htmlspecialchars($_POST['qtd_meses'], ENT_QUOTES, 'UTF-8') : '12'; ?>"
                           required>
                </div>

                <button type="submit" class="btn btn-primary">
                    Calcular
                </button>
            </form>
        </div>
    </div>

    <?php if ($resultado_total !== null): ?>
        <h2 class="mb-3">Resultado da Simulação</h2>

        <div class="card mb-3">
            <div class="card-body">
                <p>
                    <?php echo nl2br(htmlspecialchars($resultado_texto, ENT_QUOTES, 'UTF-8')); // [web:21][web:27] ?>
                </p>

                <h2 class="h4 mt-3">
                    Total a pagar: R$
                    <?php echo number_format($resultado_total, 2, ',', '.'); // [web:35] ?>
                </h2>
                <h3 class="h5 mt-1">
                    Parcela aproximada: R$
                    <?php echo number_format($resultado_parcela, 2, ',', '.'); ?>
                </h3>
            </div>
        </div>

        <a href="cartao_credito.php" class="btn btn-secondary me-2">
            Nova simulação
        </a>
    <?php endif; ?>

</div>
</body>
</html>
