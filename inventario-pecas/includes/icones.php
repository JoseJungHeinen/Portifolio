<?php
/**
 * includes/icones.php
 * Ícone e manifesto para o atalho "Adicionar à tela inicial" dos coletores.
 * Só as telas onde o coletor cria o atalho (index.php e login.php) incluem:
 *   <?php require __DIR__ . '/includes/icones.php'; ?>
 *
 * Caminhos relativos pelo mesmo motivo do head.php: o projeto abre tanto por
 * inventario_teste.test/ quanto por localhost/inventario_teste/, e todas as
 * páginas ficam na raiz. Os PNGs em img/icones/ saem de img/logo.svg.
 */
?>
<link rel="manifest" href="manifest.json">
<link rel="icon" type="image/png" sizes="32x32" href="img/icones/logo.svg">
<link rel="icon" type="image/png" sizes="192x192" href="img/icones/icon-192.png">
<link rel="apple-touch-icon" href="img/icones/apple-touch-icon.png">
<meta name="theme-color" content="#FF0600">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Inventário">
