<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';
session_start();

if (empty($_SESSION['logado'])) {
    header('Location: index.php');
    exit;
}

$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
if (!$is_admin) {
    http_response_code(403);
    echo 'Acesso restrito apenas para administradores.';
    exit;
}


// garante tabela de configs gerais
$mysqli->query("
    CREATE TABLE IF NOT EXISTS config_sistema (
        id INT AUTO_INCREMENT PRIMARY KEY,
        chave VARCHAR(100) UNIQUE NOT NULL,
        valor TEXT,
        descricao VARCHAR(255),
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$erros   = [];
$sucesso = '';
$mensagem_texto = '';

// --------- SALVAR CONFIG DE VALORES ---------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar_config') {
    if (!empty($_POST['config']) && is_array($_POST['config'])) {
        foreach ($_POST['config'] as $id => $dados) {
            $id = (int)$id;

            $placa          = str_replace(',', '.', $dados['placa'] ?? '0');
            $intermunicipal = str_replace(',', '.', $dados['intermunicipal'] ?? '0');
            $transferencia  = str_replace(',', '.', $dados['transferencia'] ?? '0');

            if (!is_numeric($placa) || !is_numeric($intermunicipal) || !is_numeric($transferencia)) {
                $erros[] = "Valores inválidos na linha ID {$id}.";
                continue;
            }

            $placa          = (float)$placa;
            $intermunicipal = (float)$intermunicipal;
            $transferencia  = (float)$transferencia;

            $stmt = $mysqli->prepare("
                UPDATE transferencia_config
                   SET placa = ?, intermunicipal = ?, transferencia = ?
                 WHERE id = ?
            ");
            if ($stmt) {
                $stmt->bind_param('dddi', $placa, $intermunicipal, $transferencia, $id);
                $stmt->execute();
                $stmt->close();
            }
        }

        if (empty($erros)) {
            $sucesso = 'Configurações atualizadas com sucesso.';
        }
    }
}

// --------- SALVAR TEXTO LEGAL TRANSFERÊNCIA ---------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar_texto') {
    $texto_legal = trim($_POST['texto_legal_transferencia'] ?? '');

    $stmt = $mysqli->prepare("
        INSERT INTO config_sistema (chave, valor, descricao)
        VALUES ('texto_legal_transferencia', ?, 'Texto legal usado nos PDFs de transferência')
        ON DUPLICATE KEY UPDATE valor = ?
    ");
    $stmt->bind_param('ss', $texto_legal, $texto_legal);
    $stmt->execute();
    $stmt->close();

    $mensagem_texto = 'Texto legal de transferência salvo com sucesso!';
}

// --------- BUSCAR CONFIGS DE VALORES ---------
$result = $mysqli->query("
    SELECT id, tipo_id, nome, placa, intermunicipal, transferencia
      FROM transferencia_config
  ORDER BY tipo_id ASC
");
$configs = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $configs[] = $row;
    }
    $result->free();
}

// --------- BUSCAR TEXTO LEGAL TRANSFERÊNCIA ---------
$texto_transferencia_atual = '';
$stmt = $mysqli->prepare("SELECT valor FROM config_sistema WHERE chave = 'texto_legal_transferencia' LIMIT 1");
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $texto_transferencia_atual = $row['valor'] ?? '';
    }
    $stmt->close();
}

// Texto padrão se não houver nada salvo
if (empty($texto_transferencia_atual)) {
    $texto_transferencia_atual =
        "Os dados pessoais, bancários e de pagamento fornecidos para realização de transferências " .
        "serão utilizados exclusivamente para processar a transação, emitir documentos fiscais, " .
        "registrar o negócio jurídico e cumprir obrigações legais e regulatórias aplicáveis. " .
        "A concessionária poderá compartilhar tais dados com instituições financeiras, prestadores " .
        "de serviços de pagamento, bureaus de crédito e órgãos públicos, sempre na medida necessária " .
        "para a execução da transferência e atendimento às exigências legais. " .
        "Os dados serão tratados em conformidade com a Lei Geral de Proteção de Dados Pessoais (LGPD) " .
        "e com a Política de Privacidade da concessionária, disponível em seus canais oficiais. " .
        "Você poderá exercer seus direitos como titular de dados por meio dos canais de atendimento " .
        "indicados pela concessionária.";
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Configurações de Transferência</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Futura","Segoe UI",sans-serif;
    background:#ffffff;min-height:100vh;display:flex;
    align-items:flex-start;justify-content:center;color:#333333;padding:20px;
}
.page-container{
    background:#f4f4f4;padding:2rem 2.5rem;border-radius:12px;
    box-shadow:0 10px 25px rgba(0,0,0,0.12);width:100%;max-width:900px;
}
.page-title{
    text-align:center;margin-bottom:1.4rem;
    font-size:1.3rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
    color:#444;
}
.message{margin-bottom:.9rem;padding:.8rem;border-radius:6px;font-size:.85rem;}
.error{background:#ffd6d6;color:#b00000;border:1px solid #2347c8;}
.success{background:#d5f7dd;color:#126b2b;border:1px solid #3aa34a;}
.table-config{
    width:100%;border-collapse:collapse;font-size:.9rem;background:#ffffff;
}
.table-config th,
.table-config td{
    border:1px solid #dddddd;padding:.5rem .4rem;text-align:center;
}
.table-config th{
    background:#eeeeee;font-weight:600;
}
input[type="number"]{
    width:100%;padding:6px 8px;border-radius:6px;border:1px solid #c0c0c0;
    font-size:.9rem;text-align:right;
}
.btn-primary{
    margin-top:1rem;padding:9px 18px;background:#2347c8;color:#ffffff;border:none;
    border-radius:999px;font-size:.9rem;font-weight:700;cursor:pointer;
    letter-spacing:.06em;text-transform:uppercase;
}
.btn-secondary{
    display:inline-block;margin-top:.7rem;padding:8px 18px;
    border-radius:999px;border:1px solid #ccc;background:#ffffff;
    color:#333;font-size:.85rem;text-decoration:none;text-align:center;
    cursor:pointer;
}
.footer-actions{
    display:flex;justify-content:space-between;align-items:center;margin-top:1rem;
    flex-wrap:wrap;gap:.5rem;
}
.section-title{
    margin-top:1.4rem;margin-bottom:.5rem;font-weight:600;font-size:.95rem;
}
textarea{
    width:100%;min-height:130px;padding:10px;border-radius:8px;
    border:1px solid #c0c0c0;font-size:.85rem;line-height:1.4;
}
textarea:focus{
    outline:none;border-color:#2347c8;box-shadow:0 0 0 2px rgba(35,71,200,0.15);
}
.preview{
    margin-top:.8rem;padding:.6rem .8rem;background:#ffffff;
    border-radius:8px;border:1px solid #e0e0e0;font-size:7pt;line-height:1.3;
    text-align:justify;
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
<div class="page-container">
    <div class="page-title">Configurações de Transferência</div>

    <?php if (!empty($erros)): ?>
        <div class="message error">
            <?php echo implode('<br>', array_map('htmlspecialchars', $erros)); ?>
        </div>
    <?php endif; ?>

    <?php if ($sucesso): ?>
        <div class="message success">
            <?php echo htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($mensagem_texto): ?>
        <div class="message success">
            <?php echo htmlspecialchars($mensagem_texto, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- FORM CONFIG VALORES -->
    <form method="post" action="">
        <input type="hidden" name="acao" value="salvar_config">
        <table class="table-config">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Placa (R$)</th>
                    <th>Intermunicipal (R$)</th>
                    <th>Transferência (R$)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($configs as $cfg): ?>
                <tr>
                    <td><?php echo htmlspecialchars($cfg['nome'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <input type="number" step="0.01" min="0"
                               name="config[<?php echo (int)$cfg['id']; ?>][placa]"
                               value="<?php echo htmlspecialchars(number_format($cfg['placa'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0"
                               name="config[<?php echo (int)$cfg['id']; ?>][intermunicipal]"
                               value="<?php echo htmlspecialchars(number_format($cfg['intermunicipal'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0"
                               name="config[<?php echo (int)$cfg['id']; ?>][transferencia]"
                               value="<?php echo htmlspecialchars(number_format($cfg['transferencia'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="footer-actions">
            <a href="menu.php" class="btn-secondary">Voltar ao menu</a>
            <button type="submit" class="btn-primary">Salvar configurações</button>
        </div>
    </form>

    <!-- FORM TEXTO LEGAL TRANSFERÊNCIA -->
    <h2 class="section-title">Texto legal de transferência</h2>
    <form method="post" action="">
        <input type="hidden" name="acao" value="salvar_texto">

        <textarea id="texto_legal_transferencia"
                  name="texto_legal_transferencia"
                  required><?= htmlspecialchars($texto_transferencia_atual, ENT_QUOTES, 'UTF-8'); ?></textarea>

        <button type="submit" class="btn-primary">Salvar texto legal</button>
    </form>

    <div class="section-title" style="margin-top:0.8rem;">Pré-visualização (como aparece no PDF):</div>
    <div class="preview" id="preview-transferencia-content">
        <?= htmlspecialchars($texto_transferencia_atual, ENT_QUOTES, 'UTF-8'); ?>
    </div>
</div>

<script>
document.getElementById('texto_legal_transferencia').addEventListener('input', function () {
    document.getElementById('preview-transferencia-content').textContent = this.value;
});
</script>
<div class="app-version-label">
    <?php echo VERSAO_CONFIG_TRANSFERENCIA; ?>
</div>
</body>
</html>
