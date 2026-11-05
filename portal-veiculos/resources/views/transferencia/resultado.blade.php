<?php
// Exemplo de dados que viriam do back-end:
$tipo = (object)['nome' => 'Tipo de transferência'];
$texto = "Linha 1 do texto\nLinha 2 do texto";
$total = 1234.56;
$orcamento = (object)['id' => 1];

// Fim dos dados de exemplo
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Resultado da Transferência</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-4">
    <h1 class="mb-3">Resultado da Transferência</h1>

    <div class="card mb-3">
        <div class="card-body">
            <h3 class="h5 mb-3">
                <?php echo htmlspecialchars($tipo->nome, ENT_QUOTES, 'UTF-8'); ?>
            </h3>

            <p>
                <?php echo nl2br(htmlspecialchars($texto, ENT_QUOTES, 'UTF-8')); ?>
            </p>

            <h2 class="h4 mt-3">
                Total: R$
                <?php echo number_format($total, 2, ',', '.'); ?>
            </h2>
        </div>
    </div>

    <a href="/transferencia.php" class="btn btn-secondary me-2">
        Nova simulação
    </a>
    <a href="/transferencia_pdf.php?id=<?php echo urlencode($orcamento->id); ?>" class="btn btn-primary">
        Gerar PDF
    </a>
</div>
</body>
</html>
