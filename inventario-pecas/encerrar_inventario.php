<?php
declare(strict_types=1);

session_start();
require 'conexao_inventario.php';

if (empty($_SESSION['inventario_login'])) {
    header('Location: index.php');
    exit;
}

$usuarioLogado = $_SESSION['inventario_login'];

$codigo = $_GET['codigo'] ?? '';
$invSel = isset($_GET['inv']) ? (int)$_GET['inv'] : 0;

if ($codigo === '' || $invSel === 0 || strpos($codigo, '.') === false) {
    exit('Parâmetros inválidos.');
}

[$empresaSel, $revendaSel] = array_map('intval', explode('.', $codigo));

if (($usuarioLogado['perfil'] ?? '') !== 'admin') {
    exit('Sem permissão para encerrar o inventário.');
}

$sql = "
    UPDATE inv_inventarios
       SET data_fim = NOW(),
           status   = 'concluido'
     WHERE empresa = :empresa
       AND revenda = :revenda
       AND id      = :id
       AND status  = 'aberto'
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':empresa' => $empresaSel,
    ':revenda' => $revendaSel,
    ':id'      => $invSel,
]);

// flash message
// "Inventário encerrado", não "contagem encerrada": este botão fecha o
// inventário inteiro e com ele a recontagem. Chamar isso de contagem fazia o
// supervisor que só queria fechar a 1ª contagem perder a 2ª, e o caminho de
// volta é UPDATE no banco.
$_SESSION['flash_msg'] = 'Inventário #' . $invSel . ' encerrado.';

header('Location: dashboard_inventario.php?codigo=' . urlencode($codigo) . '&inv=' . $invSel);
exit;
