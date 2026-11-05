<?php
// viewer_politica.php
session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

// recebe o caminho do pdf cadastrado
$pdf = $_GET['file'] ?? '';
// segurança básica: só permite pastas esperadas
if ($pdf === '' || !preg_match('~^uploads_politicas/[\w\.\-_]+\.pdf$~i', $pdf)) {
    exit('Arquivo inválido');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Política comercial</title>
<style>
html,body{
    margin:0;
    padding:0;
    height:100%;
    width:100%;
    overflow:hidden;
}
iframe{
    border:0;
    width:100%;
    height:100%;
}
</style>
</head>
<body>
<iframe src="<?= htmlspecialchars($pdf, ENT_QUOTES, 'UTF-8'); ?>"></iframe>
</body>
</html>
