<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['inventario_login'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Percentual de Inventário</title>
</head>
<body>
  <h1>Percentual de Inventário</h1>
  <p>Em breve...</p>
</body>
</html>
