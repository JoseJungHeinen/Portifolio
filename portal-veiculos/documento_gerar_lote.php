<?php
declare(strict_types=1);

// documento_gerar_lote.php (ficando dentro de /public)

require_once __DIR__ . '/config_database.php';
require_once __DIR__ . '/config_versao.php';

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

/**
 * Normaliza valores vindos de formulário.
 * Alguns tipos (checkbox / múltipla escolha) chegam como array.
 * Convertemos para string segura para validação/substituição.
 */
if (!function_exists('normalizarValorInput')) {
    function normalizarValorInput($raw): string {
        if (is_array($raw)) {
            $partes = array_map(static fn($v) => trim((string)$v), $raw);
            $partes = array_values(array_filter($partes, static fn($v) => $v !== ''));
            return trim(implode(', ', $partes));
        }
        return trim((string)$raw);
    }
}




/**
 * Autoload correto (seu debug confirmou):
 * __DIR__ = C:\laragon\www\Veiculos\public
 * vendor  = C:\laragon\www\Veiculos\vendor
 */
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('ERRO: autoload não encontrado em: ' . $autoload);
}
require_once $autoload;
require_once __DIR__ . '/documentos/utils_pdf.php';

// ------------ DEBUG EM TELA (pra pegar o erro real) ------------
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function ($e) {
    http_response_code(500);
    echo "<pre style='white-space:pre-wrap;background:#111;color:#eee;padding:12px;border-radius:10px'>";
    echo "EXCEPTION:\n" . $e . "\n";
    echo "</pre>";
    exit;
});
// ---------------------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['logado'])) {
    http_response_code(403);
    exit('Acesso negado.');
}

/**
 * Garante TCPDF (caso o autoload não tenha carregado por algum motivo).
 */
if (!class_exists('TCPDF')) {
    $tcpdfFile = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
    if (is_file($tcpdfFile)) {
        require_once $tcpdfFile;
    }
}
if (!class_exists('TCPDF')) {
    http_response_code(500);
    exit('ERRO: TCPDF não encontrado. Verifique se existe: vendor/tecnickcom/tcpdf/tcpdf.php');
}

function formatarValorPorTipo(string $tipo, $valor): string {
    $v = is_array($valor)
        ? implode(', ', array_values(array_filter(array_map(static fn($x) => trim((string)$x), $valor), static fn($x) => $x !== '')))
        : (string)$valor;
    $v = trim($v);
    if ($v === '') return '';

    switch ($tipo) {
        case 'cpf':
            $digits = preg_replace('/\D+/', '', $v);
            if (strlen($digits) === 11) {
                return substr($digits,0,3).'.'.substr($digits,3,3).'.'.substr($digits,6,3).'-'.substr($digits,9,2);
            }
            return $v;

        case 'cnpj':
            $digits = preg_replace('/\D+/', '', $v);
            if (strlen($digits) === 14) {
                return substr($digits,0,2).'.'.substr($digits,2,3).'.'.substr($digits,5,3).'/'.substr($digits,8,4).'-'.substr($digits,12,2);
            }
            return $v;

        case 'data':
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $p = explode('-', $v);
                return $p[2].'/'.$p[1].'/'.$p[0];
            }
            return $v;

        case 'moeda':
            $n = str_replace(['.', ' '], '', $v);
            $n = str_replace(',', '.', $n);
            if (is_numeric($n)) {
                return 'R$ ' . number_format((float)$n, 2, ',', '.');
            }
            return $v;

        default:
            return $v;
    }
}

function carregarTemplate(mysqli $mysqli, int $templateId): ?array {
    $stmt = $mysqli->prepare("SELECT id, nome, arquivo_docx FROM documento_templates WHERE id=? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $templateId);
    $stmt->execute();
    $res = $stmt->get_result();
    $tpl = $res->fetch_assoc();
    $stmt->close();
    return $tpl ?: null;
}

function carregarCamposExclusivos(mysqli $mysqli, int $templateId): array {
    $stmt = $mysqli->prepare("SELECT nome_campo, rotulo, tipo, obrigatorio FROM documento_campos WHERE template_id=? ORDER BY ordem ASC, id ASC");
    if (!$stmt) return [];
    $stmt->bind_param('i', $templateId);
    $stmt->execute();
    $r = $stmt->get_result();

    $campos = [];
    while ($row = $r->fetch_assoc()) $campos[] = $row;
    $stmt->close();
    return $campos;
}

function carregarGlobaisDoTemplate(mysqli $mysqli, int $templateId): array {
    $sql = "
        SELECT g.chave, g.rotulo, g.tipo,
               g.obrigatorio AS obrigatorio_padrao,
               tg.obrigatorio AS obrigatorio_no_template,
               tg.obrigatorio_override AS obrigatorio_override
        FROM documento_template_globais tg
        JOIN documento_variaveis_globais g ON g.id = tg.global_id
        WHERE tg.template_id=?
        ORDER BY g.rotulo ASC
    ";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        // fallback
        $sql2 = "
            SELECT g.chave, g.rotulo, g.tipo, g.obrigatorio AS obrigatorio_padrao
            FROM documento_template_globais tg
            JOIN documento_variaveis_globais g ON g.id = tg.global_id
            WHERE tg.template_id=?
            ORDER BY g.rotulo ASC
        ";
        $stmt2 = $mysqli->prepare($sql2);
        if (!$stmt2) return [];

        $stmt2->bind_param('i', $templateId);
        $stmt2->execute();
        $r2 = $stmt2->get_result();

        $out = [];
        while ($row = $r2->fetch_assoc()) {
            $out[] = [
                'chave' => (string)$row['chave'],
                'rotulo' => (string)$row['rotulo'],
                'tipo' => (string)$row['tipo'],
                'obrigatorio' => !empty($row['obrigatorio_padrao']) ? 1 : 0,
            ];
        }
        $stmt2->close();
        return $out;
    }

    $stmt->bind_param('i', $templateId);
    $stmt->execute();
    $r = $stmt->get_result();

    $out = [];
    while ($row = $r->fetch_assoc()) {
        $padrao = !empty($row['obrigatorio_padrao']) ? 1 : 0;

        $efetivo = $padrao;
        if ($row['obrigatorio_override'] !== null) {
            $efetivo = !empty($row['obrigatorio_override']) ? 1 : 0;
        } elseif ($row['obrigatorio_no_template'] !== null) {
            $efetivo = !empty($row['obrigatorio_no_template']) ? 1 : 0;
        }

        $out[] = [
            'chave' => (string)$row['chave'],
            'rotulo' => (string)$row['rotulo'],
            'tipo' => (string)$row['tipo'],
            'obrigatorio' => $efetivo,
        ];
    }

    $stmt->close();
    return $out;
}

function templatePath(string $arquivoDocx): string {
    // Seu upload salva em /public/formularios
    return __DIR__ . DIRECTORY_SEPARATOR . 'formularios' . DIRECTORY_SEPARATOR . basename($arquivoDocx);
}

// ---------------------
// Entrada
// ---------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método inválido.');
}

$templateIds = $_POST['template_ids'] ?? [];
if (!is_array($templateIds) || empty($templateIds)) {
    http_response_code(400);
    exit('Selecione ao menos 1 template.');
}

$ids = [];
foreach ($templateIds as $id) {
    $id = (int)$id;
    if ($id > 0) $ids[$id] = true;
}
$ids = array_keys($ids);

$globaisInput = $_POST['globais'] ?? [];
if (!is_array($globaisInput)) $globaisInput = [];

$exclusivosInput = $_POST['exclusivos'] ?? [];
if (!is_array($exclusivosInput)) $exclusivosInput = [];

// ---------------------
// Geração
// ---------------------
$resultados = [];

foreach ($ids as $templateId) {
    $tpl = carregarTemplate($mysqli, $templateId);
    if (!$tpl) {
        $resultados[] = ['templateId'=>$templateId, 'templateNome'=>"Template #$templateId", 'erros'=>['Template não encontrado.']];
        continue;
    }

    $nomeTpl = (string)$tpl['nome'];
    $arquivoDocx = (string)($tpl['arquivo_docx'] ?? '');
    if ($arquivoDocx === '') {
        $resultados[] = ['templateId'=>$templateId, 'templateNome'=>$nomeTpl, 'erros'=>['Template sem arquivo .docx vinculado.']];
        continue;
    }

    $pathDocx = templatePath($arquivoDocx);
    if (!is_file($pathDocx)) {
        $resultados[] = ['templateId'=>$templateId, 'templateNome'=>$nomeTpl, 'erros'=>['Arquivo .docx não encontrado em: ' . $pathDocx]];
        continue;
    }

    $camposExclusivos = carregarCamposExclusivos($mysqli, $templateId);
    $globaisDoTpl     = carregarGlobaisDoTemplate($mysqli, $templateId);

    // valida obrigatórios
    $erros = [];

    foreach ($globaisDoTpl as $g) {
        if (!empty($g['obrigatorio'])) {
            $ch = strtoupper(trim((string)$g['chave']));
            $raw = $globaisInput[$ch] ?? '';
            $val = normalizarValorInput($raw);
            if ($val === '') $erros[] = "Global obrigatória não preenchida: {$g['rotulo']} ({$ch})";
        }
    }

    $valsTpl = $exclusivosInput[$templateId] ?? [];
    if (!is_array($valsTpl)) $valsTpl = [];

    foreach ($camposExclusivos as $c) {
        if (!empty($c['obrigatorio'])) {
            $nomeCampo = (string)$c['nome_campo'];
            $raw = $valsTpl[$nomeCampo] ?? '';
            $val = normalizarValorInput($raw);
            if ($val === '') $erros[] = "Campo obrigatório não preenchido: {$c['rotulo']} ({$nomeCampo})";
        }
    }

    if (!empty($erros)) {
        $resultados[] = ['templateId'=>$templateId, 'templateNome'=>$nomeTpl, 'erros'=>$erros];
        continue;
    }

    // 1) Preencher DOCX
    if (!class_exists(\PhpOffice\PhpWord\TemplateProcessor::class)) {
        $resultados[] = ['templateId'=>$templateId, 'templateNome'=>$nomeTpl, 'erros'=>['PhpWord TemplateProcessor não carregou (autoload).']];
        continue;
    }

    documentos_habilitar_escape_phpword();
    $processor = new \PhpOffice\PhpWord\TemplateProcessor($pathDocx);

    // seu sistema usa {{CHAVE}}
    if (method_exists($processor, 'setMacroChars')) {
        $processor->setMacroChars('{{', '}}');
    }

    // globais: setValue('CHAVE', 'valor')
    foreach ($globaisDoTpl as $g) {
        $ch = strtoupper(trim((string)($g['chave'] ?? '')));
        if ($ch === '') continue;

        $raw   = $globaisInput[$ch] ?? '';
        $valor = formatarValorPorTipo((string)($g['tipo'] ?? 'texto_curto'), $raw);
        $processor->setValue($ch, documentos_upper_valor($valor));
    }

    // exclusivos: {{NOME_CAMPO}} com nome_campo em UPPER
    foreach ($camposExclusivos as $c) {
        $nomeCampo = (string)($c['nome_campo'] ?? '');
        if ($nomeCampo === '') continue;

        $raw   = $valsTpl[$nomeCampo] ?? '';
        $valor = formatarValorPorTipo((string)($c['tipo'] ?? 'texto_curto'), $raw);
        $processor->setValue(strtoupper($nomeCampo), documentos_upper_valor($valor));
    }

    // salva docx temporário
    $tmpDir    = sys_get_temp_dir();
    $docxSaida = $tmpDir . DIRECTORY_SEPARATOR . 'doc_' . (int)$templateId . '_' . time() . '.docx';
    $processor->saveAs($docxSaida);

    // 2) DOCX -> PDF (LibreOffice) - mantém o layout idêntico ao Word
    if (!function_exists('localizarLibreOfficeWindows')) {
        function localizarLibreOfficeWindows(): ?string {
            return documentos_localizar_libreoffice_windows();
        }
    }

    if (!function_exists('converterDocxParaPdf')) {
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
    }

    try {
        $outDir = dirname($docxSaida);
        $pdfPath = converterDocxParaPdf($docxSaida, $outDir);
        $pdfContent = file_get_contents($pdfPath);

        // limpeza
        @unlink($docxSaida);
        @unlink($pdfPath);

        $resultados[] = [
            'templateId'    => $templateId,
            'templateNome'  => $nomeTpl,
            'base64Pdf'     => base64_encode($pdfContent),
            'nomeArquivo'   => preg_replace('/[^A-Za-z0-9_\-]+/', '_', $nomeTpl) . '.pdf',
            'erros'         => [],
        ];
    } catch (\Throwable $e) {
        @unlink($docxSaida);
        $resultados[] = [
            'templateId'    => $templateId,
            'templateNome'  => $nomeTpl,
            'erros'         => ['Falha ao gerar o documento: ' . $e->getMessage()],
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Documentos gerados</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f5f5f7;margin:0;padding:20px}
    .wrap{max-width:1100px;margin:0 auto}
    .card{background:#fff;border:1px solid #e6e6ea;border-radius:12px;padding:14px;margin-bottom:14px}
    .title{display:flex;align-items:center;justify-content:space-between;gap:10px}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;border:1px solid #d0d0d7;background:#fff;cursor:pointer;text-decoration:none;color:#111}
    .btn-primary{border-color:#bbdefb;background:#e3f2fd;color:#0d47a1}
    .btn-danger{border-color:#ffcdd2;background:#ffebee;color:#b71c1c}
    .grid{display:grid;grid-template-columns:1fr;gap:12px}
    iframe{width:100%;height:520px;border:1px solid #e6e6ea;border-radius:12px;background:#fff}
    ul{margin:8px 0 0 18px}
    .muted{color:#666;font-size:13px}
    .topbar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
  </style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <a class="btn" href="menu.php?abrir=documentos">⬅ Voltar</a>
  </div>

  <div class="card">
    <div class="title">
      <h2 style="margin:0;">✅ Resultado da geração</h2>
      <div class="muted">Você pode baixar cada PDF individualmente (sem ZIP).</div>
    </div>
  </div>

  <?php foreach ($resultados as $r): ?>
    <div class="card">
      <div class="title">
        <div>
          <strong><?= htmlspecialchars((string)$r['templateNome'], ENT_QUOTES, 'UTF-8') ?></strong>
          <div class="muted">Template ID: <?= (int)$r['templateId'] ?></div>
        </div>

        <?php if (empty($r['erros'])): ?>
          <a class="btn btn-primary"
             download="<?= htmlspecialchars((string)$r['nomeArquivo'], ENT_QUOTES, 'UTF-8') ?>"
             href="data:application/pdf;base64,<?= $r['base64Pdf'] ?>">
             ⬇ Baixar PDF
          </a>
        <?php else: ?>
          <span class="btn btn-danger">Falhou</span>
        <?php endif; ?>
      </div>

      <?php if (!empty($r['erros'])): ?>
        <div class="muted">Erros:</div>
        <ul>
          <?php foreach ($r['erros'] as $e): ?>
            <li><?= htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="grid" style="margin-top:12px;">
          <iframe src="data:application/pdf;base64,<?= $r['base64Pdf'] ?>"></iframe>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

</div>
</body>
</html>
