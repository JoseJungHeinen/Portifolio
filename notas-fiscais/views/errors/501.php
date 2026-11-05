<?php
// @versao Sys_13 V_01_16/07/2026
// $moduloNome pode ser definido pelo index.php antes de incluir esta view
$moduloNome = isset($moduloNome) ? htmlspecialchars($moduloNome, ENT_QUOTES, 'UTF-8') : 'Módulo';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>Em implementação — Exemplo NF</title>
  <style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #F6F6F6; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
    .box { background: #fff; border-radius: 12px; padding: 48px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,.10); max-width: 400px; }
    h1 { color: #FF8A2D; font-size: 64px; margin: 0 0 8px; }
    p { color: #606060; }
    a { color: #2347C8; }
  </style>
</head>
<body>
  <div class="box">
    <h1>501</h1>
    <h2 style="color:#141922;"><?= $moduloNome ?> em implementação</h2>
    <p>Este módulo ainda não está disponível. Em breve!</p>
    <a href="index.php">Voltar ao início</a>
  </div>
</body>
</html>
