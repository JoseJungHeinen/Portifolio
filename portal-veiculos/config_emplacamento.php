<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';
session_start();

// só admin acessa
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

$erros          = [];
$sucesso        = '';
$mensagem_texto = '';

// --------- SALVAR CONFIG DE VALORES ---------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar_config') {
    if (!empty($_POST['config']) && is_array($_POST['config'])) {
        foreach ($_POST['config'] as $id => $dados) {
            $id = (int)$id;

            $taxas         = str_replace(',', '.', $dados['taxas']         ?? '0');
            $ipva_percent  = str_replace(',', '.', $dados['ipva_percent']  ?? '0');
            $placa         = str_replace(',', '.', $dados['placa']         ?? '0');
            $escolha_placa = str_replace(',', '.', $dados['escolha_placa'] ?? '0');
            $margem        = str_replace(',', '.', $dados['margem']        ?? '0');
            $despachante   = str_replace(',', '.', $dados['despachante']   ?? '0');

            if (
                !is_numeric($taxas) ||
                !is_numeric($ipva_percent) ||
                !is_numeric($placa) ||
                !is_numeric($escolha_placa) ||
                !is_numeric($margem) ||
                !is_numeric($despachante)
            ) {
                $erros[] = "Valores inválidos na linha ID {$id}.";
                continue;
            }

            $taxas         = (float)$taxas;
            $ipva_percent  = (float)$ipva_percent;
            $placa         = (float)$placa;
            $escolha_placa = (float)$escolha_placa;
            $margem        = (float)$margem;
            $despachante   = (float)$despachante;

            $stmt = $mysqli->prepare("
                UPDATE emplacamento_config
                   SET taxas = ?, ipva_percent = ?, placa = ?, escolha_placa = ?, margem = ?, despachante = ?
                 WHERE id = ?
            ");
            if ($stmt) {
                $stmt->bind_param('ddddddi',
                    $taxas,
                    $ipva_percent,
                    $placa,
                    $escolha_placa,
                    $margem,
                    $despachante,
                    $id
                );
                $stmt->execute();
                $stmt->close();
            }
        }

        if (empty($erros)) {
            $sucesso = 'Configurações de emplacamento atualizadas com sucesso.';
        }
    }
}

// --------- SALVAR TEXTO LEGAL EMPLACAMENTO ---------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar_texto') {
    $texto_legal = trim($_POST['texto_legal_emplacamento'] ?? '');

    $stmt = $mysqli->prepare("
        INSERT INTO config_sistema (chave, valor, descricao)
        VALUES ('texto_legal_emplacamento', ?, 'Texto legal usado nos PDFs de emplacamento')
        ON DUPLICATE KEY UPDATE valor = ?
    ");
    $stmt->bind_param('ss', $texto_legal, $texto_legal);
    $stmt->execute();
    $stmt->close();

    $mensagem_texto = 'Texto legal de emplacamento salvo com sucesso!';
}

// --------- BUSCAR CONFIGS DE VALORES ---------
$result = $mysqli->query("
    SELECT id, tipo_id, nome, taxas, ipva_percent, placa, escolha_placa, margem, despachante
      FROM emplacamento_config
  ORDER BY tipo_id ASC
");
$configs = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $configs[] = $row;
    }
    $result->free();
}

// --------- BUSCAR TEXTO LEGAL EMPLACAMENTO ---------
$texto_emplacamento_atual = '';
$stmt = $mysqli->prepare("SELECT valor FROM config_sistema WHERE chave = 'texto_legal_emplacamento' LIMIT 1");
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $texto_emplacamento_atual = $row['valor'] ?? '';
    }
    $stmt->close();
}

// texto padrão se vazio
if (empty($texto_emplacamento_atual)) {
    $texto_emplacamento_atual =
        "Os dados pessoais e de veículo fornecidos para a realização de serviços de emplacamento " .
        "serão utilizados exclusivamente para processar os serviços contratados, emitir documentos " .
        "fiscais, registrar o veículo junto aos órgãos competentes e cumprir obrigações legais e " .
        "regulatórias aplicáveis. A concessionária poderá compartilhar esses dados com despachantes, " .
        "órgãos de trânsito, empresas terceirizadas e demais parceiros estritamente na medida necessária " .
        "para a execução dos serviços e atendimento às exigências legais. Os dados serão tratados em " .
        "conformidade com a Lei Geral de Proteção de Dados Pessoais (LGPD) e com a Política de " .
        "Privacidade da concessionária, disponível em seus canais oficiais. Você poderá exercer seus " .
        "direitos como titular de dados por meio dos canais de atendimento indicados pela concessionária.";
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Configurações de Emplacamento</title>
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
    margin-top:1.6rem;margin-bottom:.5rem;font-weight:600;font-size:.95rem;
}
textarea{
    width:100%;min-height:130px;padding:10px;border-radius:8px;
    border:1px solid #c0c0c0;font-size:.85rem;line-height:1.4;
}
textarea:focus{
    outline:none;border-color:#2347c8;box-shadow:0 0 0 2px rgba(35,71,200,0.15);
}
.preview{
    margin-top:.6rem;padding:.6rem .8rem;background:#ffffff;
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
    <div class="page-title">Configurações de Emplacamento</div>

    <?php if (!empty($erros)): ?>
        <div class="message error">
            <?= implode('<br>', array_map('htmlspecialchars', $erros)); ?>
        </div>
    <?php endif; ?>

    <?php if ($sucesso): ?>
        <div class="message success">
            <?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($mensagem_texto): ?>
        <div class="message success">
            <?= htmlspecialchars($mensagem_texto, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- FORM CONFIG VALORES -->
    <form method="post" action="">
        <input type="hidden" name="acao" value="salvar_config">

        <table class="table-config">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Taxas (R$)</th>
                    <th>IPVA (%)</th>
                    <th>Placa (R$)</th>
                    <th>Escolha de placa (R$)</th>
                    <th>Margem (R$)</th>
                    <th>Despachante (R$)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($configs as $cfg): ?>
                <tr>
                    <td><?= htmlspecialchars($cfg['nome'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <input type="number" step="0.01" min="0"
                               name="config[<?= (int)$cfg['id']; ?>][taxas]"
                               value="<?= htmlspecialchars(number_format($cfg['taxas'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0"
                               name="config[<?= (int)$cfg['id']; ?>][ipva_percent]"
                               value="<?= htmlspecialchars(number_format($cfg['ipva_percent'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0"
                               name="config[<?= (int)$cfg['id']; ?>][placa]"
                               value="<?= htmlspecialchars(number_format($cfg['placa'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0"
                               name="config[<?= (int)$cfg['id']; ?>][escolha_placa]"
                               value="<?= htmlspecialchars(number_format($cfg['escolha_placa'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0"
                               name="config[<?= (int)$cfg['id']; ?>][margem]"
                               value="<?= htmlspecialchars(number_format($cfg['margem'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0"
                               name="config[<?= (int)$cfg['id']; ?>][despachante]"
                               value="<?= htmlspecialchars(number_format($cfg['despachante'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
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

    <!-- FORM TEXTO LEGAL EMPLACAMENTO -->
    <h2 class="section-title">Texto legal de emplacamento</h2>
    <form method="post" action="">
        <input type="hidden" name="acao" value="salvar_texto">

        <textarea id="texto_legal_emplacamento"
                  name="texto_legal_emplacamento"
                  required><?= htmlspecialchars($texto_emplacamento_atual, ENT_QUOTES, 'UTF-8'); ?></textarea>

        <button type="submit" class="btn-primary">Salvar texto legal</button>
    </form>

    <div class="section-title" style="margin-top:.7rem;">Pré-visualização (como aparece no PDF):</div>
    <div class="preview" id="preview-emplacamento-content">
        <?= htmlspecialchars($texto_emplacamento_atual, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <div class="app-version-label">
        <?php echo VERSAO_CONFIG_EMPLACAMENTO; ?>
    </div>
</div>

<script>
document.getElementById('texto_legal_emplacamento').addEventListener('input', function () {
    document.getElementById('preview-emplacamento-content').textContent = this.value;
});
</script>
</body>
</html>
