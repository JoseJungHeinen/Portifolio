<?php
session_start();

// limpa só a sessão do inventário (se quiser manter outras)
unset($_SESSION['inventario_login']);

// ou, se quiser matar tudo:
// $_SESSION = [];
// session_destroy();

header('Location: index.php'); // volta para o index
exit;
