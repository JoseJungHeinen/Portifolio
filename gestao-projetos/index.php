<?php
// Ponto de entrada — redireciona conforme sessão
session_start();
if (isset($_SESSION['usuario'])) {
    header('Location: pages/dashboard.php');
} else {
    header('Location: login.php');
}
exit;
