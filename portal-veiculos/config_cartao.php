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

$mensagem_texto = '';

// SALVAR TEXTO LEGAL CARTÃO
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['acao'])
    && $_POST['acao'] === 'salvar_texto_cartao') {

    $texto_legal = trim($_POST['texto_legal_cartao'] ?? '');

    $stmt = $mysqli->prepare("
        INSERT INTO config_sistema (chave, valor, descricao)
        VALUES ('texto_legal_cartao', ?, 'Texto legal usado nos PDFs de pagamento com cartão')
        ON DUPLICATE KEY UPDATE valor = ?
    ");
    $stmt->bind_param('ss', $texto_legal, $texto_legal);
    $stmt->execute();
    $stmt->close();

    $mensagem_texto = 'Texto legal de cartão salvo com sucesso!';
}

// BUSCAR TEXTO ATUAL
$texto_cartao_atual = '';
$stmt = $mysqli->prepare("SELECT valor FROM config_sistema WHERE chave = 'texto_legal_cartao' LIMIT 1");
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $texto_cartao_atual = $row['valor'] ?? '';
    }
    $stmt->close();
}

// Texto padrão se vazio
if (empty($texto_cartao_atual)) {
    $texto_cartao_atual =
        "Os dados pessoais e financeiros fornecidos para pagamento com cartão serão utilizados " .
        "exclusivamente para processar a transação, prevenir fraudes, emitir documentos fiscais e " .
        "cumprir obrigações legais e regulatórias aplicáveis. A concessionária poderá compartilhar " .
        "esses dados com operadoras de cartão, instituições financeiras, empresas de meios de pagamento " .
        "e prestadores de serviços antifraude estritamente na medida necessária para a execução da " .
        "transação e atendimento às exigências legais. Os dados serão tratados em conformidade com a " .
        "Lei Geral de Proteção de Dados Pessoais (LGPD) e com a Política de Privacidade da concessionária, " .
        "disponível em seus canais oficiais. Você poderá exercer seus direitos como titular de dados " .
        "por meio dos canais de atendimento indicados pela concessionária.";
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Configuração Texto Legal - Cartão</title>
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
    text-align:center;margin-bottom:1.2rem;
    font-size:1.3rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
    color:#444;
}
.message{margin-bottom:.9rem;padding:.8rem;border-radius:6px;font-size:.85rem;}
.success{background:#d5f7dd;color:#126b2b;border:1px solid #3aa34a;}
label{
    display:block;margin-bottom:6px;font-size:.9rem;font-weight:600;color:#333;
}
textarea{
    width:100%;min-height:160px;padding:10px;border-radius:8px;
    border:1px solid #c0c0c0;font-size:.85rem;line-height:1.4;
}
textarea:focus{
    outline:none;border-color:#2347c8;box-shadow:0 0 0 2px rgba(35,71,200,0.15);
}
.btn-primary{
    margin-top:1rem;padding:9px 18px;background:#2347c8;color:#ffffff;border:none;
    border-radius:999px;font-size:.9rem;font-weight:700;cursor:pointer;
    letter-spacing:.06em;text-transform:uppercase;
}
.btn-secondary{
    display:inline-block;margin-top:.8rem;padding:8px 18px;
    border-radius:999px;border:1px solid #ccc;background:#ffffff;
    color:#333;font-size:.85rem;text-decoration:none;text-align:center;
    cursor:pointer;
}
.preview-title{
    margin-top:1.1rem;margin-bottom:.4rem;font-weight:600;font-size:.9rem;
}
.preview{
    padding:.6rem .8rem;background:#ffffff;
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
    <div class="page-title">Texto Legal - Pagamento com Cartão</div>

    <?php if ($mensagem_texto): ?>
        <div class="message success">
            <?= htmlspecialchars($mensagem_texto, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="acao" value="salvar_texto_cartao">

        <label for="texto_legal_cartao">Texto legal utilizado nos comprovantes de cartão</label>
        <textarea id="texto_legal_cartao"
                  name="texto_legal_cartao"
                  required><?= htmlspecialchars($texto_cartao_atual, ENT_QUOTES, 'UTF-8'); ?></textarea>

        <button type="submit" class="btn-primary">Salvar texto legal</button>
        <a href="menu.php" class="btn-secondary">Voltar ao menu</a>
    </form>

    <div class="preview-title">Pré-visualização (como aparece no PDF):</div>
    <div class="preview" id="preview-cartao-content">
        <?= htmlspecialchars($texto_cartao_atual, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <div class="app-version-label">
    <?php echo VERSAO_CONFIG_CARTAO; ?>
</div>
</div>

<script>
document.getElementById('texto_legal_cartao').addEventListener('input', function () {
    document.getElementById('preview-cartao-content').textContent = this.value;
});
</script>
</body>
</html>
