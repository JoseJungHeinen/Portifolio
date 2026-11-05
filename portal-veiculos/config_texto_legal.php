<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';
session_start();
if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    exit('Acesso negado');
}


// Criar tabela se não existir
$mysqli->query("
    CREATE TABLE IF NOT EXISTS config_sistema (
        id INT AUTO_INCREMENT PRIMARY KEY,
        chave VARCHAR(100) UNIQUE NOT NULL,
        valor TEXT,
        descricao VARCHAR(255),
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Salvar alteração
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar') {
    $texto_legal = trim($_POST['texto_legal'] ?? '');
    
    $stmt = $mysqli->prepare("
        INSERT INTO config_sistema (chave, valor, descricao) 
        VALUES ('texto_legal_orcamento', ?, 'Texto legal que aparece no rodapé do PDF de orçamento')
        ON DUPLICATE KEY UPDATE valor = ?
    ");
    $stmt->bind_param('ss', $texto_legal, $texto_legal);
    $stmt->execute();
    $stmt->close();
    
    $mensagem = 'Texto legal salvo com sucesso!';
}

// Buscar texto atual
$texto_atual = '';
$stmt = $mysqli->prepare("SELECT valor FROM config_sistema WHERE chave = 'texto_legal_orcamento' LIMIT 1");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $texto_atual = $row['valor'] ?? '';
    }
    $stmt->close();
}

// Texto padrão se não houver nada salvo
if (empty($texto_atual)) {
    $texto_atual = "A Fabricante e a rede de concessionárias tratarão os seus dados pessoais para elaborar proposta de serviços ou venda de veículo e prestar os serviços contratados por você ou para comunicar informações pertinentes ao seu veículo, como realização de recall, manutenções preventivas e garantias. Além disso, na medida do permitido pela legislação, a Fabricante e a concessionária na qual você solicitou proposta de serviços ou aquisição de veículo, ou tenha adquirido um produto ou contratado um serviço, compartilharão seus dados pessoais entre si para, entre outras finalidades realizar estudos com objetivo de desenvolver melhorias em nossos produtos e serviços, enviar comunicações a você sobre a Fabricante, seus produtos e serviços, bem como entrar em contato para realização de pesquisas de satisfação. Para saber todas as finalidades para as quais a Fabricante trata seus dados pessoais, e como exercitar seus direitos, consulte nossa Política de Privacidade de Clientes e Política de Privacidade de Potenciais Clientes, disponíveis em https://www.fiat.com.br/politica-de-privacidade.html. É importante que você também consulte a política de privacidade da concessionária, para saber mais sobre como ela trata seus dados pessoais.";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Configurar Texto Legal</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            padding: 20px;
            background: #f5f5f7;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        h1 {
            color: #2347c8;
            margin: 0 0 10px;
            font-size: 1.4rem;
        }
        .helper {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 20px;
            line-height: 1.4;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            color: #333;
        }
        textarea {
            width: 100%;
            min-height: 200px;
            padding: 12px;
            border: 1px solid #d0d0d0;
            border-radius: 8px;
            font-size: 0.85rem;
            line-height: 1.5;
            resize: vertical;
            font-family: system-ui, sans-serif;
        }
        textarea:focus {
            outline: none;
            border-color: #2347c8;
            box-shadow: 0 0 0 2px rgba(35,71,200,0.18);
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 999px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            margin-top: 15px;
        }
        .btn-primary {
            background: #2347c8;
            color: #fff;
        }
        .btn-secondary {
            background: #fff;
            color: #333;
            border: 1px solid #ccc;
            text-decoration: none;
            display: inline-block;
            margin-left: 10px;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .mensagem {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 10px 12px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 0.85rem;
        }
        .preview {
            margin-top: 20px;
            padding: 12px;
            background: #f9f9f9;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }
        .preview-title {
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.9rem;
            color: #333;
        }
        .preview-text {
            font-size: 7pt;
            line-height: 1.3;
            text-align: justify;
            color: #444;
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
<div class="container">
    <h1>Configurar Texto Legal do Orçamento</h1>
    <p class="helper">
        Este texto aparece no final do PDF de orçamento, abaixo das observações. 
        Geralmente contém informações sobre LGPD, privacidade e termos legais.
    </p>

    <?php if (isset($mensagem)): ?>
        <div class="mensagem"><?= htmlspecialchars($mensagem) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="acao" value="salvar">
        
        <label for="texto_legal">Texto Legal</label>
        <textarea id="texto_legal" name="texto_legal" required><?= htmlspecialchars($texto_atual, ENT_QUOTES, 'UTF-8'); ?></textarea>
        
        <div>
            <button type="submit" class="btn btn-primary">Salvar Texto</button>
            <a href="menu.php" class="btn btn-secondary">Voltar ao Menu</a>
        </div>
    </form>

    <div class="preview">
        <div class="preview-title">📄 Pré-visualização (como aparecerá no PDF):</div>
        <div class="preview-text" id="preview-content">
            <?= htmlspecialchars($texto_atual, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>
</div>

<script>
    // Atualizar preview em tempo real
    document.getElementById('texto_legal').addEventListener('input', function() {
        document.getElementById('preview-content').textContent = this.value;
    });
</script>
<div class="app-version-label">
    <?php echo VERSAO_CONFIG_TEXTO_LEGAL; ?>
</div>
</body>
</html>
