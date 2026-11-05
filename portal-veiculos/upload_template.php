<?php
// upload_template.php
declare(strict_types=1);

require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php'; // $mysqli

$isEmbedded = defined('UPLOAD_TEMPLATE_EMBUTIDO') && UPLOAD_TEMPLATE_EMBUTIDO === true;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =====================
// Segurança: admin
// =====================
if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    exit('Acesso restrito apenas para administradores.');
}

// =====================
// Helpers (com proteção contra redeclare)
// =====================
if (!function_exists('diretorioFormularios')) {
    function diretorioFormularios(): string {
        return __DIR__ . DIRECTORY_SEPARATOR . 'formularios';
    }
}

if (!function_exists('redirectVolta')) {
    function redirectVolta(int $templateId, string $msg = ''): void {
        $url = 'config_documentos.php?aba=arquivo&template_id=' . $templateId;
        if ($msg !== '') {
            $url .= '&msg=' . urlencode($msg);
        }
        header('Location: ' . $url);
        exit;
    }
}

/**
 * Renderiza o formulário embutido (sem HTML completo).
 * $alertHtml pode conter <div class="alert ...">...</div>
 */
function renderEmbeddedForm(array $template, int $templateId, string $alertHtml = ''): void
{
    ?>
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">📎 Upload / Vincular arquivo .docx</h3>
        </div>

        <div class="p-3">
            <?php if ($alertHtml !== ''): ?>
                <?= $alertHtml ?>
            <?php endif; ?>

            <p class="form-text mb-3">
                <strong>Template:</strong> <?= htmlspecialchars($template['nome'] ?? '') ?>
            </p>

            <!-- Quando embutido, postamos para a própria tela (config_documentos.php) -->
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="template_id" value="<?= (int)$templateId ?>">

                <div class="form-group">
                    <label class="form-label">Escolha o arquivo (.docx)</label>
                    <input class="form-control" type="file" name="arquivo" accept=".docx" required>
                </div>

                <div class="d-flex gap-2 mt-3" style="flex-wrap: wrap;">
                    <button class="btn btn-primary" type="submit"> Enviar arquivo /Editar arquivo</button>
                </div>

                <small class="form-text">O arquivo será salvo na pasta /formularios/</small>
            </form>
        </div>
    </div>
    <?php
}

// =====================
// Template ID
// =====================
$templateId = (int)($_POST['template_id'] ?? ($_GET['template_id'] ?? 0));

if ($templateId <= 0) {
    http_response_code(400);
    exit('Template inválido.');
}

// Verifica se template existe
$stmt = $mysqli->prepare("SELECT id, nome FROM documento_templates WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $templateId);
$stmt->execute();
$res = $stmt->get_result();
$template = $res->fetch_assoc();
$stmt->close();

if (!$template) {
    http_response_code(404);
    exit('Template não encontrado.');
}

// =====================
// GET: renderiza formulário
// =====================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isEmbedded) {
        // Embutido: só o card
        renderEmbeddedForm($template, $templateId);
        return; // importante: não matar o config_documentos.php
    }

    // Standalone: página completa
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <title>Upload de Template</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body { font-family: system-ui, -apple-system, Segoe UI, sans-serif; background:#f5f5f7; padding:20px; }
            .box { max-width:600px; margin:0 auto; background:#fff; padding:20px; border-radius:10px; border:1px solid #e0e0e0; }
            .btn { padding:10px 16px; border-radius:6px; border:1px solid #ccc; background:#fff; cursor:pointer; text-decoration:none; display:inline-block; }
            .btn-primary { background:#2347c8; border-color:#2347c8; color:#fff; }
            .row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        </style>
    </head>
    <body>
        <div class="box">
            <h2>Upload de arquivo (.docx)</h2>
            <p><strong>Template:</strong> <?= htmlspecialchars($template['nome']) ?></p>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="template_id" value="<?= (int)$templateId ?>">
                <div class="row">
                    <input type="file" name="arquivo" accept=".docx" required>
                    <button class="btn btn-primary" type="submit">Enviar</button>
                    <a class="btn" href="config_documentos.php?aba=arquivo&template_id=<?= (int)$templateId ?>">Voltar</a>
                </div>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// =====================
// POST: processar upload
// =====================
$alertHtml = '';

if (!isset($_FILES['arquivo'])) {
    $msg = 'Nenhum arquivo enviado.';
    if ($isEmbedded) {
        $alertHtml = '<div class="alert alert-danger">' . htmlspecialchars($msg) . '</div>';
        renderEmbeddedForm($template, $templateId, $alertHtml);
        return;
    }
    redirectVolta($templateId, $msg);
}

$file = $_FILES['arquivo'];

if (!empty($file['error'])) {
    $msg = 'Erro no upload. Código: ' . (string)$file['error'];
    if ($isEmbedded) {
        $alertHtml = '<div class="alert alert-danger">' . htmlspecialchars($msg) . '</div>';
        renderEmbeddedForm($template, $templateId, $alertHtml);
        return;
    }
    redirectVolta($templateId, $msg);
}

if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    $msg = 'Arquivo inválido.';
    if ($isEmbedded) {
        $alertHtml = '<div class="alert alert-danger">' . htmlspecialchars($msg) . '</div>';
        renderEmbeddedForm($template, $templateId, $alertHtml);
        return;
    }
    redirectVolta($templateId, $msg);
}

// Validação de extensão
$nomeOriginal = $file['name'] ?? '';
$ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
if ($ext !== 'docx') {
    $msg = 'Envie apenas arquivo .docx';
    if ($isEmbedded) {
        $alertHtml = '<div class="alert alert-danger">' . htmlspecialchars($msg) . '</div>';
        renderEmbeddedForm($template, $templateId, $alertHtml);
        return;
    }
    redirectVolta($templateId, $msg);
}

// Limite de tamanho (10MB)
$maxBytes = 10 * 1024 * 1024;
if ((int)$file['size'] > $maxBytes) {
    $msg = 'Arquivo muito grande. Máximo: 10MB.';
    if ($isEmbedded) {
        $alertHtml = '<div class="alert alert-danger">' . htmlspecialchars($msg) . '</div>';
        renderEmbeddedForm($template, $templateId, $alertHtml);
        return;
    }
    redirectVolta($templateId, $msg);
}

// Garante diretório
$dir = diretorioFormularios();
if (!is_dir($dir)) {
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        $msg = 'Não foi possível criar a pasta /formularios.';
        if ($isEmbedded) {
            $alertHtml = '<div class="alert alert-danger">' . htmlspecialchars($msg) . '</div>';
            renderEmbeddedForm($template, $templateId, $alertHtml);
            return;
        }
        redirectVolta($templateId, $msg);
    }
}

// Renomeia automaticamente
$nomeFinal = 'template_' . $templateId . '.docx';
$destino  = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . $nomeFinal;

// Move upload
if (!move_uploaded_file($file['tmp_name'], $destino)) {
    $erro = error_get_last();
    $detalhe = $erro['message'] ?? 'sem detalhes';
    $msg = 'Falha ao salvar o arquivo. Verifique permissões da pasta /formularios. Detalhe: ' . $detalhe;

    if ($isEmbedded) {
        $alertHtml = '<div class="alert alert-danger">' . htmlspecialchars($msg) . '</div>';
        renderEmbeddedForm($template, $templateId, $alertHtml);
        return;
    }
    redirectVolta($templateId, $msg);
}

// Atualiza no banco
$stmt = $mysqli->prepare("UPDATE documento_templates SET arquivo_docx = ? WHERE id = ?");
$stmt->bind_param('si', $nomeFinal, $templateId);
$stmt->execute();
$stmt->close();

// Sucesso
if ($isEmbedded) {
    // Embutido: mostra mensagem e mantém o form (sem redirect / sem exit)
    $alertHtml = '<div class="alert alert-success">Arquivo enviado com sucesso!</div>';
    renderEmbeddedForm($template, $templateId, $alertHtml);
    return;
}

// Standalone: volta para aba Arquivo
redirectVolta($templateId, 'Arquivo enviado com sucesso!');
