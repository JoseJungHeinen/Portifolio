<?php
require_once __DIR__ . '/config_database.php';
require_once __DIR__ . '/config_versao.php';
session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}


$sql = "SELECT r.setor,
               r.ramal,
               c.nome      AS cidade,
               c.telefone  AS telefone
        FROM ramais r
        JOIN ramais_cidades c ON c.id = r.id_cidade
        WHERE r.ativo = 1
        ORDER BY c.ordem, c.nome, r.ordem, r.id";

$res = $mysqli->query($sql);

$ramais = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $ramais[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Ramais úteis</title>
<style>
table{
    width:100%;
    border-collapse:collapse;
    font-family:system-ui,sans-serif;
    font-size:.9rem;
}
th,td{
    border:1px solid #ddd;
    padding:6px 8px;
    text-align:left;
}
th{
    background:#f4f4f4;
}
.cidade-telefone{
    font-weight:600;
}
.cidade-telefone small{
    font-weight:500;
    color:#008000;
    margin-left:4px;
}
.app-version-label{
    position:fixed;
    bottom:4px;
    right:8px;
    font-size:10px;
    color:#888;
    opacity:0.6;
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    pointer-events:none;
    z-index:9999;
}
</style>
</head>
<body>
<table>
    <tr>
        <th>Cidade</th>
        <th>Setor</th>
        <th>Ramal</th>
    </tr>
    <?php if (!empty($ramais)): ?>
        <?php foreach ($ramais as $r): ?>
            <tr>
                <td class="cidade-telefone">
                    <?= htmlspecialchars($r['cidade'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if (!empty($r['telefone'])): ?>
                        <small>
                            <?= htmlspecialchars($r['telefone'], ENT_QUOTES, 'UTF-8'); ?>
                        </small>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($r['setor'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars($r['ramal'], ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="3">Nenhum ramal cadastrado.</td>
        </tr>
    <?php endif; ?>
</table>
<div class="app-version-label">
    <?php echo VERSAO_RAMAIS_UTEIS; ?>
</div>
</body>
</html>
