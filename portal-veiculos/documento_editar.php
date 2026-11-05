<?php
require_once __DIR__ . '/config_database.php';
session_start();
if (empty($_SESSION['logado'])) {
    header('Location: index.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    exit('Documento inválido.');
}

$usuarioId = (int)($_SESSION['id'] ?? 0);

// Busca registro e verifica tempo
$stmt = $mysqli->prepare("
    SELECT d.*, 
           TIMESTAMPDIFF(MINUTE, d.criado_em, NOW()) AS minutos_passados,
           t.nome AS nome_template
    FROM documento_gerados d
    JOIN documento_templates t ON t.id = d.template_id
    WHERE d.id = ? AND d.usuario_id = ?
");
$stmt->bind_param('ii', $id, $usuarioId);
$stmt->execute();
$res = $stmt->get_result();
$doc = $res->fetch_assoc();
$stmt->close();

if (!$doc) {
    exit('Documento não encontrado.');
}

$minutos = (int)$doc['minutos_passados'];
$bloqueado = ($minutos > 15);

$templateId = (int)$doc['template_id'];
$nomeTemplate = $doc['nome_template'];

// Busca campos do template
$stmt = $mysqli->prepare("SELECT * FROM documento_campos WHERE template_id=? ORDER BY ordem ASC, id ASC");
$stmt->bind_param('i', $templateId);
$stmt->execute();
$resCampos = $stmt->get_result();
$campos = [];
while ($row = $resCampos->fetch_assoc()) {
    $campos[] = $row;
}
$stmt->close();

// Dados atuais
$dadosAtuais = json_decode($doc['dados_json'], true);
if (!is_array($dadosAtuais)) {
    $dadosAtuais = [];
}

// Se POST e ainda dentro do tempo, salvar alteração e redirecionar para gerar novamente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloqueado) {
    $novosDados = [];
    foreach ($campos as $campo) {
        $nomeCampo = $campo['nome_campo'];
        $novosDados[$nomeCampo] = $_POST['campos'][$nomeCampo] ?? '';
    }
    $dadosJson = json_encode($novosDados, JSON_UNESCAPED_UNICODE);

    $stmt = $mysqli->prepare("UPDATE documento_gerados SET dados_json = ?, atualizado_em = NOW() WHERE id = ? AND usuario_id = ?");
    $stmt->bind_param('sii', $dadosJson, $id, $usuarioId);
    $stmt->execute();
    $stmt->close();

    // Reaproveita o script documento_gerar.php passando template_id e campos
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <title>Regerando documento...</title>
    </head>
    <body>
    <form id="f" method="post" action="documento_gerar.php">
        <input type="hidden" name="template_id" value="<?php echo (int)$templateId; ?>">
        <?php foreach ($novosDados as $nome => $valor): ?>
            <input type="hidden" name="campos[<?php echo htmlspecialchars($nome, ENT_QUOTES, 'UTF-8'); ?>]"
                   value="<?php echo htmlspecialchars($valor, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endforeach; ?>
    </form>
    <script>document.getElementById('f').submit();</script>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Editar documento #<?php echo (int)$id; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin:0; padding:0; box-sizing:border-box; font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; background:#ffffff; min-height:100vh; display:flex; align-items:center; justify-content:center; color:#333; }
        .page-container { background:#f4f4f4; padding:2.2rem 2.6rem; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.12); width:100%; max-width:720px; }
        .page-title { text-align:center; margin-bottom:1.4rem; font-size:1.15rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:#444; }
        .info { font-size:.85rem; color:#555; margin-bottom:.9rem; text-align:center; }
        .form-group { margin-bottom:.9rem; }
        label { display:block; margin-bottom:.3rem; color:#555; font-weight:600; font-size:.9rem; }
        input, textarea {
            width:100%; padding:8px 12px; border:1px solid #c0c0c0; border-radius:999px;
            font-size:.9rem; background:#fff; color:#333; box-sizing:border-box;
        }
        textarea { border-radius:10px; min-height:70px; resize:vertical; }
        .btn-primary {
            width:100%; padding:10px; background:#2347c8; color:#ffffff; border:none; border-radius:999px;
            font-size:.95rem; font-weight:700; cursor:pointer; letter-spacing:.06em; text-transform:uppercase;
            box-shadow:0 6px 16px rgba(35,71,200,0.45); margin-top:.4rem;
        }
        .btn-secondary {
            display:inline-block; margin-top:.9rem; padding:8px 18px; border-radius:999px; border:1px solid #ccc;
            background:#ffffff; color:#333; font-size:.85rem; text-decoration:none; text-align:center; cursor:pointer;
        }
        .alert { margin-bottom:.9rem; padding:.8rem; border-radius:6px; font-size:.85rem; }
        .alert-error { background:#ffd6d6; color:#b00000; border:1px solid #2347c8; text-align:center; }
        @media (max-width:640px) {
            .page-container { padding:1.6rem 1.4rem; border-radius:0; box-shadow:none; }
        }
    </style>
</head>
<body>
<div class="page-container">
    <div class="page-title">Editar documento</div>
    <div class="info">
        Modelo: <strong><?php echo htmlspecialchars($nomeTemplate, ENT_QUOTES, 'UTF-8'); ?></strong><br>
        Documento #<?php echo (int)$id; ?> | Gerado há <?php echo $minutos; ?> minuto(s).
    </div>

    <?php if ($bloqueado): ?>
        <div class="alert alert-error">
            Edição bloqueada. O prazo de 15 minutos para ajustes deste documento expirou.
        </div>
        <div style="text-align:center;">
            <a href="menu.php" class="btn-secondary">Voltar ao menu</a>
        </div>
    <?php else: ?>
        <form method="post">
            <?php foreach ($campos as $campo): ?>
                <?php
                $nomeCampo = $campo['nome_campo'];
                $valor = $dadosAtuais[$nomeCampo] ?? '';
                $tipo = $campo['tipo'];
                ?>
                <div class="form-group">
                    <label>
                        <?php echo htmlspecialchars($campo['rotulo'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($campo['obrigatorio']): ?> *<?php endif; ?>
                    </label>
                    <?php if ($tipo === 'observacao'): ?>
                        <textarea name="campos[<?php echo htmlspecialchars($nomeCampo, ENT_QUOTES, 'UTF-8'); ?>]"><?php
                            echo htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
                        ?></textarea>
                    <?php else: ?>
                        <input type="text" name="campos[<?php echo htmlspecialchars($nomeCampo, ENT_QUOTES, 'UTF-8'); ?>]"
                               value="<?php echo htmlspecialchars($valor, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <button type="submit" class="btn-primary">Salvar e regerar documento</button>
        </form>
        <a href="menu.php" class="btn-secondary">Cancelar e voltar</a>
    <?php endif; ?>
</div>
</body>
</html>
