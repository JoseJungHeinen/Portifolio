<?php
require_once __DIR__ . '/config_database.php';
require_once __DIR__ . '/config_versao.php';
session_start();

if (empty($_SESSION['logado'])) {
    header('Location: index.php');
    exit;
}

if (!defined('FORMULARIOS_PATH')) {
    define('FORMULARIOS_PATH', __DIR__ . '/formularios/');
}

/**
 * ======================
 * Funções utilitárias
 * ======================
 */
function formatCpf($cpfBruto) {
    $d = preg_replace('/\D+/', '', $cpfBruto ?? '');
    if (strlen($d) !== 11) return $cpfBruto;
    return substr($d,0,3).'.'.substr($d,3,3).'.'.substr($d,6,3).'-'.substr($d,9,2);
}
function formatPlaca($placaBruta) {
    $p = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $placaBruta ?? ''));
    return $p;
}
function formatCnh($cnhBruta) {
    return preg_replace('/\D+/', '', $cnhBruta ?? '');
}
function formatCnpj($cnpjBruto) {
    $d = preg_replace('/\D+/', '', $cnpjBruto ?? '');
    if (strlen($d) !== 14) return $cnpjBruto;
    return substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12,2);
}
function formatTelefone($telBruto) {
    $d = preg_replace('/\D+/', '', $telBruto ?? '');
    if (strlen($d) === 10) {
        return '('.substr($d,0,2).') '.substr($d,2,4).'-'.substr($d,6,4);
    }
    if (strlen($d) === 11) {
        return '('.substr($d,0,2).') '.substr($d,2,5).'-'.substr($d,7,4);
    }
    return $telBruto;
}
function formatCep($cepBruto) {
    $d = preg_replace('/\D+/', '', $cepBruto ?? '');
    if (strlen($d) !== 8) return $cepBruto;
    return substr($d,0,5).'-'.substr($d,5,3);
}

/**
 * Converte nome_campo em placeholder do DOCX: {{NOME_CAMPO_NORMALIZADO}}
 * Ex.: "endereço_completo" -> {{ENDERECO_COMPLETO}}
 */
function campoParaPlaceholderDocx(string $nomeCampo): string {
    $semAcento = iconv('UTF-8', 'ASCII//TRANSLIT', $nomeCampo);
    $limpo = preg_replace('/[^A-Za-z0-9]+/', '_', $semAcento);
    $limpo = trim(preg_replace('/_+/', '_', $limpo), '_');
    $upper = strtoupper($limpo);

    return '{{' . $upper . '}}';
}

/**
 * ======================
 * Validação do template
 * ======================
 */
$templateId = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
if ($templateId <= 0) {
    exit('Template inválido.');
}

// Busca template
$stmt = $mysqli->prepare("SELECT * FROM documento_templates WHERE id=? AND ativo=1");
$stmt->bind_param('i', $templateId);
$stmt->execute();
$resTpl   = $stmt->get_result();
$template = $resTpl->fetch_assoc();
$stmt->close();

if (!$template) {
    exit('Modelo não encontrado ou inativo.');
}

// Busca campos do template
$stmt = $mysqli->prepare("SELECT * FROM documento_campos WHERE template_id=? ORDER BY ordem ASC, id ASC");
$stmt->bind_param('i', $templateId);
$stmt->execute();
$resCampos = $stmt->get_result();
$campos    = [];
while ($row = $resCampos->fetch_assoc()) {
    $campos[] = $row;
}
$stmt->close();

/**
 * ======================
 * Monta dados
 * ======================
 */
$dadosBrutos     = [];
$dadosFormatados = [];

foreach ($campos as $campo) {
    $nomeCampo  = $campo['nome_campo'];
    $tipo       = $campo['tipo'];
    $valorBruto = $_POST['campos'][$nomeCampo] ?? '';

    $dadosBrutos[$nomeCampo] = $valorBruto;

    $valorFmt = $valorBruto;
    switch ($tipo) {
        // Texto
        case 'texto_curto':
        case 'texto_longo':
        case 'endereco':
        case 'cidade_estado_pais':
            $valorFmt = trim($valorBruto);
            break;

        // Documentos formatados
        case 'cpf':
            $valorFmt = formatCpf($valorBruto);
            break;
        case 'cnpj':
            $valorFmt = formatCnpj($valorBruto);
            break;
        case 'cpf_cnpj':
            $digits = preg_replace('/\D+/', '', (string)($valorBruto ?? ''));
            if (strlen($digits) <= 11) {
                $valorFmt = formatCpf($digits);
            } else {
                $valorFmt = formatCnpj($digits);
            }
            break;
        case 'telefone':
            $valorFmt = formatTelefone($valorBruto);
            break;
        case 'cep':
            $valorFmt = formatCep($valorBruto);
            break;
        case 'renavam':
            $valorFmt = strtoupper(preg_replace('/\s+/', '', $valorBruto ?? ''));
            break;
        case 'chassi':
            $valorFmt = strtoupper(trim($valorBruto));
            break;
        case 'placa':
            $valorFmt = formatPlaca($valorBruto);
            break;
        case 'email':
            $valorFmt = trim($valorBruto);
            break;

        // Numéricos
        case 'inteiro':
            $valorFmt = (string)intval($valorBruto);
            break;
        case 'decimal':
        case 'moeda':
        case 'percentual':
            $num = str_replace(',', '.', preg_replace('/[^\d,.-]/', '', $valorBruto));
            $valorFmt = $num;
            break;
        case 'sequencial':
            $valorFmt = trim($valorBruto);
            break;

        // Datas / tempos
        case 'data':
        case 'hora':
        case 'datetime':
        case 'periodo':
            $valorFmt = trim($valorBruto);
            break;

        // Lógicos / seleção
        case 'booleano':
        case 'checkbox':
        case 'select':
        case 'radio':
        case 'multiselect':
        case 'enum':
            $valorFmt = trim($valorBruto);
            break;

        default:
            $valorFmt = $valorBruto;
            break;
    }

    $dadosFormatados[$nomeCampo] = $valorFmt;
}

/**
 * ======================
 * Histórico
 * ======================
 */
mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS documento_gerados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    usuario_id INT NOT NULL,
    dados_json JSON NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    bloqueado TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (template_id) REFERENCES documento_templates(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$usuarioId = (int)($_SESSION['user_id'] ?? 0);
if ($usuarioId <= 0) {
    exit('Usuário inválido para gravação do documento.');
}
$dadosJson = json_encode($dadosBrutos, JSON_UNESCAPED_UNICODE);

$stmt = $mysqli->prepare("INSERT INTO documento_gerados (template_id, usuario_id, dados_json) VALUES (?, ?, ?)");
$stmt->bind_param('iis', $templateId, $usuarioId, $dadosJson);
$stmt->execute();
$idGerado = $stmt->insert_id;
$stmt->close();

/**
 * ======================
 * DOCX -> HTML
 * ======================
 */
$arquivoDocx = FORMULARIOS_PATH . $template['arquivo_docx'];
if (!is_file($arquivoDocx)) {
    exit('Arquivo base do modelo não encontrado: ' . htmlspecialchars($template['arquivo_docx'], ENT_QUOTES, 'UTF-8'));
}

$html = '';

try {
    require_once __DIR__ . '/../vendor/autoload.php'; // PhpWord via Composer
    require_once __DIR__ . '/documentos/utils_pdf.php';
} catch (\Throwable $e) {
    exit('Erro ao carregar dependências (autoload): ' . $e->getMessage());
}

/**
 * ======================
 * DOCX (TemplateProcessor) -> PDF (LibreOffice)
 * ======================
 */
if (!class_exists(\PhpOffice\PhpWord\TemplateProcessor::class)) {
    exit('PhpWord TemplateProcessor não carregou (autoload).');
}

function localizarLibreOfficeWindows(): ?string {
    return documentos_localizar_libreoffice_windows();
}

function converterDocxParaPdf(string $docxPath, string $outDir): string {
    if (!is_file($docxPath)) {
        throw new Exception('DOCX não encontrado: ' . $docxPath);
    }
    documentos_validar_docx_gerado($docxPath);
    if (!is_dir($outDir)) {
        @mkdir($outDir, 0777, true);
    }

    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

    if ($isWindows) {
        $soffice = localizarLibreOfficeWindows();
        if (!$soffice) {
            throw new Exception('LibreOffice não encontrado. Instale-o ou configure o caminho do soffice.exe.');
        }
        $cmd = '"' . $soffice . '" --headless --nologo --nodefault --nolockcheck --norestore --convert-to pdf --outdir "' . $outDir . '" "' . $docxPath . '"';
    } else {
        $cmd = 'libreoffice --headless --nologo --nodefault --nolockcheck --norestore --convert-to pdf --outdir ' . escapeshellarg($outDir) . ' ' . escapeshellarg($docxPath);
    }

    $output = [];
    $ret = 0;
    @exec($cmd . ' 2>&1', $output, $ret);
    $saida = trim(implode("\n", $output));
    if ($ret !== 0) {
        $mensagem = "Falha na conversÃ£o LibreOffice (ret={$ret}).";
        if ($saida !== '') {
            $mensagem .= ' SaÃ­da: ' . $saida;
        }
        throw new Exception($mensagem);
    }
    if ($ret !== 0) {
        throw new Exception("Falha na conversão LibreOffice (ret={$ret}). Saída: " . implode("\n", $output));
    }

    $pdfPath = rtrim($outDir, "\\/") . DIRECTORY_SEPARATOR . pathinfo($docxPath, PATHINFO_FILENAME) . '.pdf';
    if (!is_file($pdfPath)) {
        $mensagem = 'PDF nÃ£o encontrado apÃ³s conversÃ£o: ' . $pdfPath;
        if ($saida !== '') {
            $mensagem .= '. SaÃ­da do LibreOffice: ' . $saida;
        }
        throw new Exception($mensagem);
    }
    if (!is_file($pdfPath)) {
        throw new Exception('PDF não encontrado após conversão: ' . $pdfPath);
    }
    return $pdfPath;
}



/**
 * Converte qualquer valor para CAIXA ALTA (UTF-8), preservando acentos.
 * Regra GLOBAL do sistema: todo valor substituído em templates entra em maiúsculo.
 */
if (!function_exists('documentos_upper_valor')) {
    function documentos_upper_valor($v): string {
        $s = (string)$v;
        if ($s === '') return '';
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($s, 'UTF-8');
        }
        return strtoupper($s);
    }
}

// 1) Preenche o DOCX diretamente (mantém layout idêntico ao Word)
$pathDocx = $arquivoDocx;

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'exemplo_docs';
@mkdir($tmpDir, 0777, true);

$tmpDocx = $tmpDir . DIRECTORY_SEPARATOR . 'doc_' . $template['id'] . '_' . uniqid() . '.docx';

documentos_habilitar_escape_phpword();
$processor = new \PhpOffice\PhpWord\TemplateProcessor($pathDocx);

// seu sistema usa {{CHAVE}}
if (method_exists($processor, 'setMacroChars')) {
    $processor->setMacroChars('{{', '}}');
}

// Substitui placeholders com base no array $dadosFormatados.
// Importante: aqui usamos as chaves SEM {{ }} (ex.: MODELO_VEICULO)
foreach ($dadosFormatados as $chave => $valor) {
    $processor->setValue((string)$chave, documentos_upper_valor($valor));
}

$processor->saveAs($tmpDocx);

// 2) Converte DOCX preenchido para PDF (LibreOffice)
try {
    $tmpPdf = converterDocxParaPdf($tmpDocx, $tmpDir);
    $pdfContent = file_get_contents($tmpPdf);
    $base64Pdf = base64_encode($pdfContent);
} catch (\Throwable $e) {
    exit('Erro ao gerar o documento: ' . $e->getMessage());
}

// limpeza (não é crítica se falhar)
@unlink($tmpDocx);
@unlink($tmpPdf);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($template['nome'], ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        body { margin:0; padding:0; font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; background:#f5f5f7; }
        .page-container { max-width:1100px; margin:20px auto; background:#fff; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.08); padding:16px 18px; }
        .top-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:10px; }
        .btn { display:inline-block; padding:8px 16px; border-radius:999px; border:1px solid transparent; cursor:pointer; font-size:0.9rem; font-weight:600; text-decoration:none; }
        .btn-primary { background:#2347c8; color:#fff; border-color:#2347c8; }
        .btn-secondary { background:#fff; color:#333; border-color:#ccc; }
        iframe { width:100%; height:80vh; border:none; border-radius:6px; box-sizing:border-box; }
        .info { font-size:0.85rem; color:#555; margin-top:4px; }
    </style>
</head>
<body>
<div class="page-container">
    <div class="top-bar">
        <div>
            <strong><?php echo htmlspecialchars($template['nome'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
            <span class="info">Documento #<?php echo (int)$idGerado; ?> gerado agora. Você poderá editar por até 15 minutos.</span>
        </div>
        <div>
            <a href="documento_editar.php?id=<?php echo (int)$idGerado; ?>" class="btn btn-primary">Editar (até 15 min)</a>
            <a href="menu.php" class="btn btn-secondary">Voltar ao menu</a>
        </div>
    </div>

    <iframe src="data:application/pdf;base64,<?php echo $base64Pdf; ?>"></iframe>
</div>
</body>
</html>
