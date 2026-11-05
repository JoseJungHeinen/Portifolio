<?php
/**
 * includes/head.php
 * Inclua no <head> de todas as páginas:
 *   <?php require __DIR__ . '/includes/head.php'; ?>
 *
 * Defina $pageTitle antes de incluir para personalizar o <title>:
 *   <?php $pageTitle = 'Dashboard'; require __DIR__ . '/includes/head.php'; ?>
 */
$pageTitle = $pageTitle ?? 'Inventário';
?>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($pageTitle); ?> — Exemplo Inventário</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
// Caminho relativo à página, e não absoluto: o projeto é aberto tanto por
// inventario_teste.test/ quanto por localhost/inventario_teste/, e um caminho
// começando em /inventario_teste/ só funciona no segundo. (O caminho antigo,
// /inventario_teste/app.css, não apontava para arquivo nenhum.) Todas as
// páginas ficam na raiz do projeto. O filemtime força o celular a buscar o CSS
// novo quando ele muda, em vez de usar a cópia em cache.
$appCssVersao = @filemtime(__DIR__ . '/app.css') ?: 0;
?>
<link rel="stylesheet" href="includes/app.css?v=<?php echo (int)$appCssVersao; ?>">
