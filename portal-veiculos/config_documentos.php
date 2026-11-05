<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';
require_once __DIR__ . '/documentos/globais_helpers.php';


function campoParaPlaceholderDocx(string $nomeCampo): string {
    $semAcento = iconv('UTF-8', 'ASCII//TRANSLIT', $nomeCampo);
    $limpo = preg_replace('/[^A-Za-z0-9]+/', '_', $semAcento);
    $limpo = trim(preg_replace('/_+/', '_', $limpo), '_');
    $upper = strtoupper($limpo);
    return '{{' . $upper . '}}';
}

function diretorioFormularios(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'formularios';
}

function caminhoTemplateDocx(string $arquivoDocx): string {
    $nomeSeguro = basename($arquivoDocx);
    return diretorioFormularios() . DIRECTORY_SEPARATOR . $nomeSeguro;
}

session_start();
if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    exit('Acesso restrito apenas para administradores.');
}

/**
 * ==================== GARANTIR TABELAS ====================
 */
mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS documento_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    descricao VARCHAR(255) DEFAULT NULL,
    arquivo_docx VARCHAR(255) DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS documento_campos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    nome_campo VARCHAR(100) NOT NULL,
    rotulo VARCHAR(150) NOT NULL,
    tipo VARCHAR(50) NOT NULL DEFAULT 'texto_curto',
    obrigatorio TINYINT(1) NOT NULL DEFAULT 0,
    ordem INT NOT NULL DEFAULT 0,
    lista_id INT NULL,
    origem_lista VARCHAR(50) DEFAULT NULL,
    config_lista TEXT DEFAULT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES documento_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS documento_listas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    tipo ENUM('bd','manual') NOT NULL,
    tabela VARCHAR(100) DEFAULT NULL,
    coluna_valor VARCHAR(100) DEFAULT NULL,
    coluna_label VARCHAR(100) DEFAULT NULL,
    coluna_filtro VARCHAR(100) DEFAULT NULL,
    valor_filtro VARCHAR(150) DEFAULT NULL,
    ordenacao ENUM('alpha_asc','alpha_desc','num_asc','num_desc') DEFAULT 'alpha_asc',
    config_manual TEXT DEFAULT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS documento_variaveis_globais (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chave VARCHAR(120) NOT NULL UNIQUE,
    rotulo VARCHAR(150) NOT NULL,
    tipo VARCHAR(50) NOT NULL DEFAULT 'texto_curto',
    obrigatorio TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS documento_template_globais (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    global_id INT NOT NULL,
    obrigatorio_override TINYINT(1) NULL DEFAULT NULL,
    ordem INT NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_template_global (template_id, global_id),
    CONSTRAINT fk_dtg_template FOREIGN KEY (template_id) REFERENCES documento_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_dtg_global FOREIGN KEY (global_id) REFERENCES documento_variaveis_globais(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$erros = [];
$mensagem = '';
$acao = $_POST['acao'] ?? null;
require_once __DIR__ . '/documentos/globais_actions.php';

/**
 * ==================== ATIVAR / DESATIVAR (BOTÃO - ABA 1 e ABA 4) ====================
 * Regra: só ativa se tiver arquivo vinculado.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'toggle_template_ativo') {
    $templateId = (int)($_POST['template_id'] ?? 0);
    $novoStatus = (int)($_POST['novo_status'] ?? -1); // 0 ou 1

    if ($templateId <= 0 || !in_array($novoStatus, [0, 1], true)) {
        $erros[] = 'Ação inválida para ativar/desativar.';
    } else {
        // Se for ativar, exige arquivo vinculado
        if ($novoStatus === 1) {
            $stmtChk = $mysqli->prepare("SELECT arquivo_docx FROM documento_templates WHERE id = ? LIMIT 1");
            $stmtChk->bind_param('i', $templateId);
            $stmtChk->execute();
            $resChk = $stmtChk->get_result();
            $rowChk = $resChk->fetch_assoc();
            $stmtChk->close();

            if (!$rowChk || empty($rowChk['arquivo_docx'])) {
                $erros[] = 'Para ativar o modelo, primeiro vincule/enviar o arquivo .docx na guia 3 (Upload do Arquivo).';
            }
        }

        if (empty($erros)) {
            $stmtUp = $mysqli->prepare("UPDATE documento_templates SET ativo = ? WHERE id = ? LIMIT 1");
            $stmtUp->bind_param('ii', $novoStatus, $templateId);
            $stmtUp->execute();
            $stmtUp->close();

            $mensagem = ($novoStatus === 1)
                ? 'Template ativado com sucesso.'
                : 'Template desativado com sucesso.';

            // Volta para a ABA 1 mantendo o template selecionado
            if (!headers_sent()) {
                header('Location: config_documentos.php?aba=template&template_id=' . $templateId);
                exit;
            }
        }
    }
}


/**
 * ==================== EXCLUIR TEMPLATE ====================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'excluir_template') {
    $templateIdExcluir = (int)($_POST['template_id'] ?? 0);

    if ($templateIdExcluir <= 0) {
        $erros[] = 'Template inválido para exclusão.';
    } else {
        // Buscar arquivo vinculado (se existir)
        $stmt = $mysqli->prepare("SELECT arquivo_docx FROM documento_templates WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $templateIdExcluir);
        $stmt->execute();
        $res = $stmt->get_result();
        $tpl = $res->fetch_assoc();
        $stmt->close();

        if (!$tpl) {
            $erros[] = 'Template não encontrado.';
        } else {
            // Excluir arquivo físico se existir
            if (!empty($tpl['arquivo_docx'])) {
                $arquivo = basename($tpl['arquivo_docx']); // segurança
                $caminho = caminhoTemplateDocx($arquivo);

                if (is_file($caminho)) {
                    @unlink($caminho);
                }
            }

            // Excluir do banco (campos serão apagados por ON DELETE CASCADE)
            $stmt = $mysqli->prepare("DELETE FROM documento_templates WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $templateIdExcluir);
            $stmt->execute();
            $stmt->close();

            $mensagem = 'Template excluído com sucesso.';

            // Limpa preview do teste se for o mesmo template
            if (!empty($_SESSION['preview_teste']) && (int)($_SESSION['preview_teste']['template_id'] ?? 0) === $templateIdExcluir) {
                unset($_SESSION['preview_teste']);
            }

            if (!headers_sent()) {
                header('Location: config_documentos.php?aba=template');
                exit;
            }
        }
    }
}


/* ===== FLASH MESSAGE (pós-redirect do teste) ===== */
if (empty($mensagem) && !empty($_SESSION['flash_mensagem'])) {
    $mensagem = $_SESSION['flash_mensagem'];
    unset($_SESSION['flash_mensagem']);
}

/**
 * ==================== CRUD LISTAS (MANUAL) ====================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'salvar_lista') {
    $listaId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $nomeLista = trim($_POST['nome'] ?? '');

    $opcoesManuais = [];
    if (isset($_POST['config_manual_opcoes']) && is_array($_POST['config_manual_opcoes'])) {
        foreach ($_POST['config_manual_opcoes'] as $opcao) {
            $valor = trim($opcao['valor'] ?? '');
            $label = trim($opcao['label'] ?? '');
            if ($valor !== '' || $label !== '') {
                $opcoesManuais[] = ['valor' => $valor, 'label' => $label];
            }
        }
    }

    $configManual = !empty($opcoesManuais)
        ? json_encode($opcoesManuais, JSON_UNESCAPED_UNICODE)
        : '';

    if ($nomeLista === '') {
        $erros[] = 'Informe o nome da lista.';
    } elseif ($configManual === '') {
        $erros[] = 'Para listas manuais, adicione pelo menos uma opção.';
    }

    if (empty($erros)) {
        $tipoLista = 'manual';
        $ordenacao = 'alpha_asc';

        if ($listaId > 0) {
            $stmt = $mysqli->prepare("UPDATE documento_listas SET nome=?, tipo=?, ordenacao=?, config_manual=? WHERE id=?");
            $stmt->bind_param('ssssi', $nomeLista, $tipoLista, $ordenacao, $configManual, $listaId);
            $stmt->execute();
            $stmt->close();
            $mensagem = 'Lista atualizada com sucesso.';
        } else {
            $stmt = $mysqli->prepare("INSERT INTO documento_listas (nome, tipo, ordenacao, config_manual) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $nomeLista, $tipoLista, $ordenacao, $configManual);
            $stmt->execute();
            $stmt->close();
            $mensagem = 'Lista criada com sucesso.';
        }

        $tidRedirect = (int)($_GET['template_id'] ?? ($_POST['template_id'] ?? 0));
        if (!headers_sent() && $tidRedirect > 0) {
            header('Location: config_documentos.php?aba=campos&template_id=' . $tidRedirect);
            exit;
        }
    }
}

/**
 * ==================== CRUD CAMPOS ====================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($acao, ['inserir_campo', 'salvar_campos'], true)) {
    $templateId = (int)($_POST['template_id'] ?? 0);
    if ($templateId <= 0) {
        $erros[] = 'Template inválido para cadastrar campos.';
    }

    // Remover campo
    if (empty($erros) && isset($_POST['remover_campo']) && (int)$_POST['remover_campo'] > 0) {
        $campoId = (int)$_POST['remover_campo'];
        $stmt = $mysqli->prepare("DELETE FROM documento_campos WHERE id=? AND template_id=?");
        $stmt->bind_param('ii', $campoId, $templateId);
        $stmt->execute();
        $stmt->close();

        if (!headers_sent()) {
            header("Location: config_documentos.php?aba=campos&template_id=" . $templateId);
            exit;
        }
    }

    // Inserir novo campo
    if (empty($erros) && $acao === 'inserir_campo') {
        $rotulo = trim($_POST['novo_campo']['rotulo'] ?? '');
        $nomeCampo = trim($_POST['novo_campo']['nome_campo'] ?? '');
        $tipo = $_POST['novo_campo']['tipo'] ?? 'texto_curto';
        $ordem = (int)($_POST['novo_campo']['ordem'] ?? 0);

        $listaId = $_POST['novo_campo']['lista_id'] ?? '';
        $listaId = ($listaId === '') ? 0 : (int)$listaId;

        // Rótulo continua obrigatório
        if ($rotulo === '') {
            $erros[] = 'Informe o rótulo do campo.';
        }

        // Se "Nome do Campo" vier vazio, gera automaticamente a partir do rótulo (igual às globais)
        if (empty($erros) && $nomeCampo === '') {
            $semAcento = @iconv('UTF-8', 'ASCII//TRANSLIT', $rotulo);
            if ($semAcento === false) {
                $semAcento = $rotulo;
            }
            $limpo = preg_replace('/[^A-Za-z0-9]+/', '_', (string)$semAcento);
            $limpo = trim(preg_replace('/_+/', '_', $limpo), '_');
            $nomeCampo = strtolower($limpo);
        }

        if (empty($erros)) {
            if ($nomeCampo === '' || !preg_match('/^[A-Za-z0-9_]+$/', $nomeCampo)) {
                $erros[] = 'Nome do campo inválido. Use apenas letras, números e underline (_).';
            }
        }

        if (empty($erros)) {
            $stmt = $mysqli->prepare("
                INSERT INTO documento_campos (template_id, nome_campo, rotulo, tipo, obrigatorio, ordem, lista_id)
                VALUES (?, ?, ?, ?, 0, ?, NULLIF(?, 0))
            ");
            $stmt->bind_param('isssii', $templateId, $nomeCampo, $rotulo, $tipo, $ordem, $listaId);
            $stmt->execute();
            $stmt->close();

            if (!headers_sent()) {
                header("Location: config_documentos.php?aba=campos&template_id=" . $templateId);
                exit;
            }
        }
    }


    // Salvar todos os campos
    if (empty($erros) && $acao === 'salvar_campos') {
        $camposPost = $_POST['campos'] ?? [];
        if (is_array($camposPost)) {
            foreach ($camposPost as $campoId => $dados) {
                $campoId = (int)$campoId;

                $rotulo = trim($dados['rotulo'] ?? '');
                $nomeCampo = trim($dados['nome_campo'] ?? '');
                $tipo = $dados['tipo'] ?? 'texto_curto';
                $ordem = (int)($dados['ordem'] ?? 0);
                $obrigatorio = !empty($dados['obrigatorio']) ? 1 : 0;

                $listaId = $dados['lista_id'] ?? '';
                $listaId = ($listaId === '') ? 0 : (int)$listaId;

                if ($rotulo === '') {
                    continue;
                }

                if ($nomeCampo === '') {
                    $semAcento = @iconv('UTF-8', 'ASCII//TRANSLIT', $rotulo);
                    if ($semAcento === false) {
                        $semAcento = $rotulo;
                    }
                    $limpo = preg_replace('/[^A-Za-z0-9]+/', '_', (string)$semAcento);
                    $limpo = trim(preg_replace('/_+/', '_', $limpo), '_');
                    $nomeCampo = strtolower($limpo);
                }

                if ($nomeCampo === '' || !preg_match('/^[A-Za-z0-9_]+$/', $nomeCampo)) {
                    continue;
                }

                $stmt = $mysqli->prepare("
                    UPDATE documento_campos
                    SET rotulo=?, nome_campo=?, tipo=?, obrigatorio=?, ordem=?, lista_id=NULLIF(?, 0)
                    WHERE id=? AND template_id=?
                ");
                $stmt->bind_param('sssiiiii', $rotulo, $nomeCampo, $tipo, $obrigatorio, $ordem, $listaId, $campoId, $templateId);

                $stmt->execute();
                $stmt->close();
            }
        }

        $mensagem = 'Campos salvos com sucesso.';

        if (!headers_sent()) {
            header("Location: config_documentos.php?aba=campos&template_id=" . $templateId);
            exit;
        }
    }
}

/**
 * ==================== CRUD TEMPLATES ====================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'salvar_template') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $ativo = !empty($_POST['ativo']) ? 1 : 0;

    if ($nome === '') {
        $erros[] = 'Informe o nome do modelo.';
    }

    if (empty($erros) && $id > 0 && $ativo === 1) {
        $stmtChk = $mysqli->prepare("SELECT arquivo_docx FROM documento_templates WHERE id = ?");
        $stmtChk->bind_param('i', $id);
        $stmtChk->execute();
        $resChk = $stmtChk->get_result();
        $rowChk = $resChk->fetch_assoc();
        $stmtChk->close();

        if (!$rowChk || empty($rowChk['arquivo_docx'])) {
            $erros[] = 'Para ativar o modelo, primeiro envie o arquivo .docx usando o botão “Enviar arquivo”.';
        }
    }

    if (empty($erros)) {
        if ($id > 0) {
            $stmt = $mysqli->prepare("UPDATE documento_templates SET nome=?, descricao=?, ativo=? WHERE id=?");
            $stmt->bind_param('ssii', $nome, $descricao, $ativo, $id);
            $stmt->execute();
            $stmt->close();

            $mensagem = 'Modelo atualizado com sucesso.';
            $novoId = $id;
        } else {
            $stmt = $mysqli->prepare("INSERT INTO documento_templates (nome, descricao, ativo) VALUES (?, ?, 0)");
            $stmt->bind_param('ss', $nome, $descricao);
            $stmt->execute();
            $novoId = $stmt->insert_id;
            $stmt->close();

            $mensagem = 'Modelo criado com sucesso.';
        }

        if (!headers_sent()) {
            header('Location: config_documentos.php?aba=campos&template_id=' . (int)$novoId);
            exit;
        }
    }
}

/**
 * ==================== TESTAR TEMPLATE ====================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($acao === 'testar_template')) {
    $templateId = (int)($_POST['template_id'] ?? 0);

    if ($templateId <= 0) {
        $erros[] = 'Template inválido.';
    } else {
        $stmt = $mysqli->prepare("SELECT arquivo_docx, ativo FROM documento_templates WHERE id = ?");
        $stmt->bind_param('i', $templateId);
        $stmt->execute();
        $result = $stmt->get_result();
        $template = $result->fetch_assoc();
        $stmt->close();

        if (!$template) {
            $erros[] = 'Template não encontrado.';
        } elseif (empty($template['arquivo_docx'])) {
            $erros[] = 'Template não possui arquivo .docx vinculado.';
        } else {
            $arquivo = $template['arquivo_docx'];
            $caminhoArquivo = caminhoTemplateDocx($arquivo);

            if (!is_file($caminhoArquivo)) {
                $erros[] = 'Arquivo não encontrado na pasta /formularios/: ' . $arquivo;
            } else {
                $mensagem = '✅ Teste realizado com sucesso! Arquivo encontrado: ' . $arquivo;
            }
        }
    }

    // ✅ Só grava preview/flash se não houve erro crítico
    // (Mesmo com erro, você pode salvar para exibir se quiser. Aqui salva sempre.)
    $_SESSION['preview_teste'] = [
        'template_id' => $templateId,
        'criado_em'   => time(),
        'mensagem'    => $mensagem,
        'erros'       => $erros,
        'valores'     => (isset($_POST['teste']) && is_array($_POST['teste'])) ? $_POST['teste'] : []
    ];

    $_SESSION['flash_mensagem'] = $mensagem;

    // ✅ Redirect seguro para GET (evita repost)
    if (!headers_sent() && $templateId > 0) {
        header('Location: config_documentos.php?aba=teste&template_id=' . $templateId);
        exit;
    }
}

/**
 * ==================== CARREGAR DADOS ====================
 */
$listas = [];
$resL = mysqli_query($mysqli, "SELECT * FROM documento_listas ORDER BY nome ASC");
while ($rowL = $resL->fetch_assoc()) {
    $listas[] = $rowL;
}
$resL->free();

$editLista = null;
if (!empty($_GET['edit_lista_id'])) {
    $editId = (int)$_GET['edit_lista_id'];
    foreach ($listas as $l) {
        if ((int)$l['id'] === $editId) {
            $editLista = $l;
            break;
        }
    }
}

$templates = [];
$res = mysqli_query($mysqli, "SELECT * FROM documento_templates ORDER BY nome ASC");
while ($row = $res->fetch_assoc()) {
    $templates[] = $row;
}
$res->free();

$abaAtual = $_GET['aba'] ?? 'template';
if (!in_array($abaAtual, ['template', 'campos', 'arquivo', 'teste'], true)) {
    $abaAtual = 'template';
}

$templateSelecionado = null;
$campos = [];
$tid = 0;

if (!empty($_GET['template_id'])) {
    $tid = (int)$_GET['template_id'];
} elseif (!empty($templates) && in_array($abaAtual, ['campos', 'arquivo', 'teste'], true)) {
    $tid = (int)$templates[0]['id'];
}

if ($tid > 0) {
    foreach ($templates as $t) {
        if ((int)$t['id'] === $tid) {
            $templateSelecionado = $t;
            break;
        }
    }

    if ($templateSelecionado) {
        $stmt = mysqli_prepare($mysqli, "SELECT * FROM documento_campos WHERE template_id = ? ORDER BY ordem ASC, id ASC");
        mysqli_stmt_bind_param($stmt, "i", $tid);
        mysqli_stmt_execute($stmt);
        $resC = mysqli_stmt_get_result($stmt);
        while ($row = $resC->fetch_assoc()) {
            $campos[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}

// =====================
// LIMPAR PRÉVIA AO TROCAR TEMPLATE (ABA TESTE)
// =====================
if ($abaAtual === 'teste' && isset($_SESSION['preview_teste']) && is_array($_SESSION['preview_teste'])) {
    $previewTid = (int)($_SESSION['preview_teste']['template_id'] ?? 0);

    // se o template atual (GET) for diferente do template guardado na prévia, limpa
    if ($tid > 0 && $previewTid !== (int)$tid) {
        unset($_SESSION['preview_teste']);
    }
}

$editTemplate = null;
if (!empty($_GET['edit_template_id'])) {
    $editId = (int)$_GET['edit_template_id'];
    foreach ($templates as $t) {
        if ((int)$t['id'] === $editId) {
            $editTemplate = $t;
            break;
        }
    }
}

$tipos = [
    'texto_curto' => 'Texto curto',
    'texto_longo' => 'Texto longo',
    'cpf' => 'CPF',
    'cnpj' => 'CNPJ',
    'cpf_cnpj' => 'CPF/CNPJ',
    'telefone' => 'Telefone',
    'cep' => 'CEP',
    'renavam' => 'Renavam',
    'chassi' => 'Chassi',
    'placa' => 'Placa',
    'email' => 'E-mail',
    'inteiro' => 'Número inteiro',
    'decimal' => 'Número decimal',
    'moeda' => 'Valor monetário',
    'percentual' => 'Percentual',
    'data' => 'Data',
    'hora' => 'Hora',
    'booleano' => 'Booleano (Sim/Não)',
    'checkbox' => 'Checkbox (múltipla escolha)',
    'select' => 'Lista suspensa',
    'radio' => 'Radio (escolha única)',
    'enum' => 'Enum (valores fixos)',
    'endereco' => 'Endereço completo',
];

function obterOpcoesCampoLista(array $campo, array $listas): array
{
    $opcoes = [];

    // 1) tenta pela lista_id na tabela documento_listas.config_manual
    $listaIdCampo = (int)($campo['lista_id'] ?? 0);
    if ($listaIdCampo > 0 && !empty($listas)) {
        foreach ($listas as $l) {
            if ((int)$l['id'] === $listaIdCampo) {
                if (!empty($l['config_manual'])) {
                    $tmp = json_decode($l['config_manual'], true);
                    if (is_array($tmp)) {
                        $opcoes = $tmp;
                    }
                }
                break;
            }
        }
    }

    // 2) fallback: tenta pelo config_lista do próprio campo (documento_campos.config_lista)
    if (empty($opcoes) && !empty($campo['config_lista'])) {
        $tmp2 = json_decode($campo['config_lista'], true);
        if (is_array($tmp2)) {
            $opcoes = $tmp2;
        }
    }

    // 3) normaliza chaves para ['valor' => ..., 'label' => ...]
    $norm = [];
    foreach ($opcoes as $o) {
        if (!is_array($o)) continue;

        $val = (string)($o['valor'] ?? $o['value'] ?? $o['id'] ?? $o['key'] ?? '');
        $lab = (string)($o['label'] ?? $o['text'] ?? $o['nome'] ?? $o['name'] ?? '');

        $texto = ($lab !== '') ? $lab : $val;

        if ($val === '' && $texto === '') continue;

        $norm[] = [
            'valor' => $val,
            'label' => $texto,
        ];
    }

    return $norm;
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Configuração de Documentos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* ================================================================
           CONFIG DOCUMENTOS — design system unificado Exemplo
           ================================================================ */
        *, *::before, *::after { box-sizing: border-box; }

        html, body {
            margin: 0; padding: 0;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #f3f4f6;
            color: #1a1a1a;
            overflow-x: hidden;
        }

        /* ---- container principal ---- */
        .page-container {
            width: 100%;
            max-width: none;
            margin: 0;
            padding: clamp(14px, 2vw, 28px) clamp(16px, 3vw, 48px);
            background: #fff;
            min-height: 100vh;
        }

        /* ---- cabeçalho ---- */
        .page-header-row {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 1.4rem;
            flex-wrap: wrap;
        }
        .page-header-icon {
            width: 48px; height: 48px; min-width: 48px;
            background: #cc0000;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
        }
        h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111;
            margin: 0;
            letter-spacing: -.01em;
        }
        .subtitulo {
            font-size: .82rem;
            color: #999;
            margin: 3px 0 0;
            line-height: 1.4;
        }

        /* ---- tabs ---- */
        .tabs-header {
            display: flex;
            background: #fafafa;
            border: 1px solid #ebebeb;
            border-radius: 12px;
            padding: 4px;
            gap: 4px;
            margin-bottom: 1.4rem;
            flex-wrap: wrap;
        }
        .tab-btn {
            padding: 9px 18px;
            background: transparent;
            border: none;
            border-radius: 9px;
            font-size: .85rem;
            font-weight: 500;
            color: #888;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background .15s, color .15s;
            font-family: inherit;
            white-space: nowrap;
        }
        .tab-btn:hover { background: #f0f0f0; color: #444; }
        .tab-btn.active {
            background: #fff;
            color: #cc0000;
            font-weight: 700;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        }
        .tab-step {
            width: 22px; height: 22px;
            border-radius: 50%;
            background: #e5e5e5;
            color: #666;
            font-size: .75rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .tab-btn.active .tab-step { background: #cc0000; color: #fff; }

        /* ---- tab content ---- */
        .tab-content { display: none; animation: fadeIn .2s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

        /* ---- cards ---- */
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 16px;
            border: 1px solid #ebebeb;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            overflow: hidden;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f0f0f0;
        }
        .card-title {
            font-size: 1rem;
            font-weight: 700;
            color: #111;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-title::before {
            content: '';
            display: inline-block;
            width: 3px; height: 16px;
            background: #cc0000;
            border-radius: 999px;
            flex-shrink: 0;
        }
        .card-subtitle { font-size: .8rem; color: #999; margin-top: 4px; }

        /* ---- badges ---- */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            font-size: .72rem;
            font-weight: 700;
            border-radius: 999px;
        }
        .badge-success { background: #dcfce7; color: #16a34a; }
        .badge-warning { background: #fef9c3; color: #854d0e; }
        .badge-danger  { background: #fee2e2; color: #b91c1c; }
        .badge-info    { background: #dbeafe; color: #1d4ed8; }

        .template-status { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: .72rem; font-weight: 700; }
        .template-status.active  { background: #dcfce7; color: #16a34a; }
        .template-status.draft   { background: #fef9c3; color: #854d0e; }
        .template-status.no-file { background: #fee2e2; color: #b91c1c; }

        /* ---- botões ---- */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s, box-shadow .15s, transform .1s;
            font-family: inherit;
            white-space: nowrap;
        }
        .btn:active { transform: translateY(1px); }
        .btn-primary  { background: #cc0000; color: #fff; box-shadow: 0 3px 10px rgba(204,0,0,.25); }
        .btn-primary:hover { background: #aa0000; }
        .btn-secondary{ background: #6c757d; color: #fff; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-success  { background: #e8f5e9; color: #2e7d32; border-color: #c8e6c9; }
        .btn-success:hover { background: #dcf0dd; }
        .btn-outline  { background: #fff; color: #555; border-color: #ddd; }
        .btn-outline:hover { background: #f5f5f5; }
        .btn-danger   { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
        .btn-danger:hover { background: #fecaca; }
        .btn-info     { background: #dbeafe; color: #1d4ed8; border-color: #bfdbfe; }
        .btn-info:hover { background: #bfdbfe; }
        .btn-small    { padding: 5px 12px; font-size: .78rem; }

        /* action-btn (usado nas tabelas) */
        .action-btn {
            display: inline-flex;
            align-items: center; justify-content: center;
            padding: 5px 12px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: .78rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            font-family: inherit;
            transition: background .12s;
        }
        .action-edit   { background: #e8f5e9; border-color: #c8e6c9; color: #2e7d32; }
        .action-edit:hover { background: #dcf0dd; }
        .action-test   { background: #fef9c3; border-color: #fde68a; color: #854d0e; }
        .action-test:hover { background: #fef08a; }
        .action-file   { background: #dbeafe; border-color: #bfdbfe; color: #1d4ed8; }
        .action-file:hover { background: #bfdbfe; }
        .action-delete { background: #fee2e2; border-color: #fecaca; color: #b91c1c; }
        .action-delete:hover { background: #fecaca; }
        .action-danger { background: #fee2e2; border-color: #fecaca; color: #b91c1c; }

        .actions-group { display: inline-flex; gap: 6px; align-items: center; flex-wrap: wrap; }

        /* ---- formulários ---- */
        .form-group { margin-bottom: 14px; }
        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #444;
            font-size: .83rem;
        }
        .form-control, textarea, select {
            width: 100%;
            max-width: 100%;
            display: block;
            padding: 9px 14px;
            border: 1px solid #ddd;
            border-radius: 999px;
            font-size: .88rem;
            font-family: inherit;
            background: #fafafa;
            color: #1a1a1a;
            outline: none;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        textarea { border-radius: 10px; min-height: 70px; resize: vertical; }
        select { border-radius: 999px; }
        .form-control:focus, textarea:focus, select:focus {
            border-color: #cc0000;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(204,0,0,.08);
        }
        .form-control-sm { padding: 5px 10px; font-size: .8rem; }
        .form-text { font-size: .76rem; color: #999; margin-top: 3px; }
        input[type="checkbox"] { width: auto; accent-color: #cc0000; }

        /* ---- alertas ---- */
        .alert {
            padding: 10px 14px;
            border-radius: 10px;
            margin-bottom: 14px;
            font-size: .85rem;
        }
        .alert-success { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
        .alert-danger  { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .alert-info    { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .alert-warning { background: #fef9c3; color: #854d0e; border: 1px solid #fde68a; }

        /* ---- tabelas ---- */
        .table-responsive { width: 100%; overflow-x: auto; border-radius: 10px; border: 1px solid #ebebeb; }
        .table { width: 100%; border-collapse: collapse; font-size: .83rem; }
        .table th {
            background: #fafafa;
            padding: 10px 14px;
            text-align: left;
            font-weight: 700;
            color: #777;
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            border-bottom: 2px solid #f0f0f0;
            white-space: nowrap;
        }
        .table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: middle;
        }
        .table tbody tr:last-child td { border-bottom: none; }
        .table tbody tr:hover { background: #fafafa; }
        .table td.actions { white-space: nowrap; text-align: right; }

        /* tabela de templates (aba 1) */
        #tab-template .table { table-layout: fixed; }
        #tab-template .table th:nth-child(1), #tab-template .table td:nth-child(1) { width: 30%; white-space: normal; word-break: break-word; }
        #tab-template .table th:nth-child(2), #tab-template .table td:nth-child(2) { width: 15%; }
        #tab-template .table th:nth-child(3), #tab-template .table td:nth-child(3) { width: 12%; white-space: nowrap; }
        #tab-template .table th:nth-child(4), #tab-template .table td:nth-child(4) { width: 43%; }
        #tab-template .table td:nth-child(2) code { display: inline-block; max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        #tab-template .actions-group { flex-wrap: nowrap; justify-content: flex-start; }

        /* tabela globais (aba 2) */
        .tabela-globais-unica, table.tabela-globais { width: 100%; border-collapse: collapse; }
        table.tabela-globais thead th, .tabela-globais-unica thead th {
            background: #fafafa; font-weight: 700; font-size: .72rem; padding: 10px 12px;
            border-bottom: 1px solid #ebebeb; white-space: nowrap; text-transform: uppercase;
            letter-spacing: .04em; color: #777;
        }
        table.tabela-globais tbody td, .tabela-globais-unica tbody td {
            padding: 10px 12px; border-bottom: 1px solid #f5f5f5; vertical-align: middle;
        }
        table.tabela-globais tbody tr:last-child td, .tabela-globais-unica tbody tr:last-child td { border-bottom: none; }

        /* tabela campos exclusivos (aba 2) */
        table.table-campos-exclusivos { width: 100%; border-collapse: collapse; }
        table.table-campos-exclusivos thead th {
            background: #fafafa; font-weight: 700; font-size: .72rem;
            padding: 10px 12px; border-bottom: 1px solid #ebebeb;
            white-space: nowrap; text-transform: uppercase; letter-spacing: .04em; color: #777;
        }
        table.table-campos-exclusivos tbody td {
            padding: 10px 12px; border-bottom: 1px solid #f5f5f5; vertical-align: middle;
        }
        table.table-campos-exclusivos tbody tr:last-child td { border-bottom: none; }
        table.table-campos-exclusivos th:nth-child(1) { width: 60px; }
        table.table-campos-exclusivos th:nth-child(6) { width: 70px; text-align: center; }
        table.table-campos-exclusivos th:nth-child(8) { width: 140px; text-align: center; }
        table.table-campos-exclusivos .form-control-sm { padding: 6px 10px; font-size: .8rem; }

        /* ---- copy-box ---- */
        .copy-box { display: flex; gap: 8px; align-items: center; min-width: 0; }
        .copy-box code {
            flex: 1 1 auto;
            background: #f8f9fa;
            padding: 5px 10px;
            border-radius: 6px;
            border: 1px solid #ebebeb;
            font-size: .8rem;
            font-family: ui-monospace, 'Courier New', monospace;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }
        .copy-btn {
            flex: 0 0 auto;
            background: #f0f0f0;
            color: #444;
            border: 1px solid #ddd;
            padding: 5px 12px;
            border-radius: 999px;
            cursor: pointer;
            font-size: .75rem;
            font-weight: 600;
            transition: background .15s;
            height: 30px;
            display: inline-flex; align-items: center;
            white-space: nowrap;
        }
        .copy-btn:hover { background: #e0e0e0; }
        .copy-btn.copied { background: #dcfce7; color: #16a34a; border-color: #bbf7d0; }

        /* ---- layout helpers ---- */
        .row { display: flex; flex-wrap: wrap; margin: 0 -10px; }
        .col { flex: 1; padding: 0 10px; min-width: 280px; }
        .col-6 { flex: 0 0 50%; padding: 0 10px; }
        .col-4 { flex: 0 0 33.3333%; padding: 0 10px; }
        .d-flex { display: flex; }
        .align-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .gap-2 { gap: .5rem; }
        .gap-3 { gap: 1rem; }
        .text-center { text-align: center; }
        .mb-3 { margin-bottom: 1rem; }
        .mb-4 { margin-bottom: 1.5rem; }
        .mt-3 { margin-top: 1rem; }
        .mt-4 { margin-top: 1.5rem; }

        /* grid 2 colunas (aba 1 e 4) */
        .row.grid-2 {
            display: grid;
            grid-template-columns: minmax(400px, 38%) 1fr;
            gap: 16px;
            margin: 0;
        }
        .row.grid-2 > .col { padding: 0; min-width: 0; }

        /* grid globais (aba 2) */
        #tab-campos .globals-grid {
            display: grid;
            grid-template-columns: minmax(460px, 42%) 1fr;
            gap: 16px;
            margin: 0;
        }
        #tab-campos .globals-grid > .col { padding: 0; min-width: 0; }

        /* coluna empilhada (aba 2) */
        #tab-campos .stack { display: flex; flex-direction: column; gap: 16px; }
        #tab-campos .stack > .col { padding: 0; min-width: 0; flex: 0 0 auto; }

        /* ---- progresso de passos ---- */
        .progress-steps {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-top: 14px;
            padding: 14px 12px;
            background: #fafafa;
            border: 1px solid #ebebeb;
            border-radius: 12px;
        }
        .progress-step {
            flex: 1; min-width: 120px;
            display: flex; flex-direction: column;
            align-items: center; gap: 8px;
            position: relative; text-align: center;
        }
        .progress-step:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 18px; left: calc(50% + 18px); right: calc(-50% + 18px);
            height: 2px; background: #e5e5e5; border-radius: 999px;
        }
        .progress-step.step-completed:not(:last-child)::after { background: #cc0000; }
        .step-circle {
            width: 36px; height: 36px; border-radius: 50%;
            background: #e5e5e5; color: #666;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: .88rem;
            border: 2px solid #d5d5d5;
        }
        .step-circle.completed { background: #cc0000; color: #fff; border-color: #cc0000; }
        .step-label { font-size: .8rem; color: #666; font-weight: 600; line-height: 1.2; }
        .progress-step.step-completed .step-label { color: #111; }

        /* ---- lista de placeholders (aba 4) ---- */
        .placeholders-list .ph-row {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px;
            border: 1px solid #ebebeb; border-radius: 10px;
            background: #fff; margin-bottom: 8px;
        }
        .placeholders-list .ph-label {
            width: 440px; min-width: 200px; max-width: 440px;
            font-weight: 600; font-size: .83rem;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .placeholders-list .ph-label .ph-chave { font-weight: 400; font-size: .75rem; color: #999; display: block; }
        .placeholders-list .ph-value { flex: 1; min-width: 0; }
        .placeholders-list .ph-value .ph-input {
            width: 100%; padding: 6px 10px;
            border: 1px solid #ddd; border-radius: 8px;
            font-family: ui-monospace, monospace; font-size: .8rem;
            background: #fafafa;
        }
        .placeholders-list .ph-value .ph-input:focus { border-color: #cc0000; outline: none; }
        .placeholders-list .ph-actions { width: 90px; min-width: 90px; display: flex; justify-content: flex-end; }

        /* ---- preview aba 4 ---- */
        #tab-teste .preview-grid { display: grid; grid-template-columns: 1fr; gap: 10px; }
        #tab-teste .preview-item { border: 1px solid #ebebeb; border-radius: 10px; padding: 12px 14px; background: #fff; }
        #tab-teste .preview-label { display: flex; flex-direction: column; gap: 2px; font-weight: 700; color: #222; margin-bottom: 6px; font-size: .88rem; }
        #tab-teste .preview-label small { font-weight: 500; color: #999; font-size: .75rem; }
        #tab-teste .preview-value { font-size: .88rem; color: #333; line-height: 1.35; word-break: break-word; }
        #tab-teste .preview-value.is-empty { color: #999; font-style: italic; }
        #tab-teste .form-group > .form-text { display: none; }
        #tab-teste .form-group { margin-bottom: 10px; }

        /* ---- global-card (aba 2) ---- */
        #tab-campos .global-card {
            border: 1px solid #ebebeb; border-radius: 10px;
            padding: 10px 14px; background: #fff; margin-bottom: 8px;
        }
        #tab-campos .global-card-row { display: flex; align-items: center; gap: 10px; }
        #tab-campos .global-card-title { flex: 1; min-width: 0; font-weight: 600; font-size: .85rem; }
        #tab-campos .global-card-required { display: inline-flex; align-items: center; gap: 8px; margin-left: auto; white-space: nowrap; }
        #tab-campos .global-card-required small { margin: 0; }

        /* ---- lista de opções (form lista manual) ---- */
        .opcao-item { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .opcao-item .opcao-valor, .opcao-item .opcao-label { flex: 1 1 0; min-width: 180px; }
        .opcao-item .btn.btn-danger { flex: 0 0 auto; width: 34px; height: 34px; padding: 0; border-radius: 8px; justify-content: center; }

        /* ---- versão label ---- */
        .app-version-label {
            position: fixed; bottom: 10px; right: 10px;
            background: rgba(0,0,0,.65); color: #fff;
            padding: 4px 8px; border-radius: 6px;
            font-size: .72rem; z-index: 1000;
        }

        /* ================================================================
           RESPONSIVIDADE
           ================================================================ */
        @media (max-width: 1100px) {
            .row.grid-2         { grid-template-columns: 1fr !important; }
            #tab-campos .globals-grid { grid-template-columns: 1fr !important; }
            #tab-teste > .row   { display: block !important; }
            #tab-teste > .row > .col { padding: 0 !important; margin-bottom: 16px; }
        }

        @media (max-width: 900px) {
            #tab-template .table th:nth-child(1), #tab-template .table td:nth-child(1) { width: auto; }
            #tab-template .table th:nth-child(4), #tab-template .table td:nth-child(4) { width: auto; }
            #tab-template .actions-group { flex-wrap: wrap; }
            .placeholders-list .ph-label { width: auto; max-width: 200px; }
        }

        @media (max-width: 768px) {
            .page-container { padding: 14px 16px; }
            .tabs-header { gap: 2px; }
            .tab-btn { flex: 1 1 auto; min-width: 100px; font-size: .78rem; padding: 8px 10px; justify-content: center; }
            .col, .col-6, .col-4 { flex: 0 0 100%; min-width: 0; }
            #tab-campos .opcao-item { flex-wrap: wrap; }
            #tab-campos .opcao-item .opcao-valor,
            #tab-campos .opcao-item .opcao-label { flex: 1 1 100%; min-width: 0; }
            #tab-campos .opcao-item .btn.btn-danger { width: 100%; }
            .progress-steps { flex-direction: column; align-items: stretch; }
            .progress-step { flex-direction: row; justify-content: flex-start; text-align: left; min-width: 0; }
            .progress-step:not(:last-child)::after { top: auto; left: 18px; right: auto; width: 2px; height: calc(100% + 12px); margin-top: 36px; }
            .actions-group { flex-wrap: wrap; }
            .action-btn { padding: 4px 10px; font-size: .75rem; }
            table.campos-exclusivos-table, table.table-campos-exclusivos { min-width: 600px; }
            #tab-template .table { min-width: 600px; }
        }

        /* ---- aba 4 grid de preview ---- */
        @media (min-width: 1100px) {
            #tab-teste .preview-grid { grid-template-columns: 1fr 1fr; }
        }

        /* ---- aba 3 (upload) normalização ---- */
        #tab-arquivo .box {
            max-width: 100% !important; margin: 0 !important;
            background: #fff !important; border: 1px solid #ebebeb !important;
            border-radius: 12px !important; padding: 18px !important;
            box-shadow: 0 2px 8px rgba(0,0,0,.04) !important;
        }
        #tab-arquivo .box > h2 { font-size: 1rem !important; font-weight: 700 !important; color: #111 !important; margin: 0 0 10px !important; }
        #tab-arquivo .box p { margin: 0 0 12px !important; color: #444 !important; font-size: .88rem !important; }
        #tab-arquivo .box input[type="file"] {
            width: 100% !important; max-width: 700px !important;
            padding: 10px 14px !important; border: 1px dashed #ddd !important;
            border-radius: 10px !important; background: #fafafa !important; display: block !important;
        }
        #tab-arquivo .box form { display: flex !important; flex-direction: column !important; gap: 12px !important; }
        #tab-arquivo .box form br { display: none !important; }

    </style>
</head>
<body>
<div class="page-container">
    <div class="page-header-row mb-4">
        <div class="page-header-icon">
            <svg width="26" height="26" fill="none" viewBox="0 0 24 24">
                <path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-5z" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M14 3v5h5M9 12h6M9 16h4" stroke="white" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
        </div>
        <div style="flex:1;min-width:0;">
            <h1>Configuração de Documentos Automatizados</h1>
            <p class="subtitulo">Crie modelos Word personalizados com campos dinâmicos para automatizar documentos</p>
        </div>
        <a href="menu.php" class="btn btn-outline" style="flex-shrink:0;">← Voltar ao Menu</a>
    </div>

    <div class="tabs-header">
        <button type="button" class="tab-btn <?= $abaAtual === 'template' ? 'active' : '' ?>" data-tab="template">
            <span class="tab-step">1</span> Cadastrar Template
        </button>

        <button type="button" class="tab-btn <?= $abaAtual === 'campos' ? 'active' : '' ?>" data-tab="campos">
            <span class="tab-step">2</span> Cadastrar Campos e Listas
        </button>

        <button type="button" class="tab-btn <?= $abaAtual === 'arquivo' ? 'active' : '' ?>" data-tab="arquivo">
            <span class="tab-step">3</span> Fazer Upload do Arquivo
        </button>

        <button type="button" class="tab-btn <?= $abaAtual === 'teste' ? 'active' : '' ?>" data-tab="teste">
            <span class="tab-step">4</span> Testar Template
        </button>
    </div>

    <?php if (!empty($erros)): ?>
        <div class="alert alert-danger">
            <?= implode('<br>', array_map('htmlspecialchars', $erros)) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($mensagem)): ?>
        <div class="alert alert-success">
            <?= $mensagem /* mensagem já é string simples, mas pode conter <br> */ ?>
        </div>
    <?php endif; ?>

    <!-- ==================== ABA 1: TEMPLATE ==================== -->
    <div class="tab-content <?= $abaAtual === 'template' ? 'active' : '' ?>" id="tab-template">
        <?php if (!empty($templates)): ?>
            <div class="mb-3">
                <label class="form-label">Template selecionado</label>
                <select class="form-control" onchange="window.location.href='config_documentos.php?aba=template&template_id=' + this.value">
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= ($templateSelecionado && (int)$t['id'] === (int)$templateSelecionado['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Selecione um template para visualizar o progresso e editar.</div>
            </div>
        <?php endif; ?>

        <?php if ($templateSelecionado): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">🎯 Progresso do Template</h3>
                    <span class="template-status <?= !empty($templateSelecionado['ativo']) ? 'active' : 'draft' ?>">
                        <?= !empty($templateSelecionado['ativo']) ? '✅ Ativo' : '📝 Rascunho' ?>
                    </span>
                </div>
                <div class="p-3">
                    <h4 class="mb-3"><?= htmlspecialchars($templateSelecionado['nome']) ?></h4>
                    <?php if (!empty($templateSelecionado['descricao'])): ?>
                        <p class="mb-3"><?= htmlspecialchars($templateSelecionado['descricao']) ?></p>
                    <?php endif; ?>

                    <div class="progress-steps">
                        <div class="progress-step step-completed">
                            <div class="step-circle completed">1</div>
                            <div class="step-label">Template Criado</div>
                        </div>

                        <div class="progress-step <?= count($campos) > 0 ? 'step-completed' : '' ?>">
                            <div class="step-circle <?= count($campos) > 0 ? 'completed' : '' ?>">2</div>
                            <div class="step-label">Campos Configurados</div>
                        </div>

                        <div class="progress-step <?= !empty($templateSelecionado['arquivo_docx']) ? 'step-completed' : '' ?>">
                            <div class="step-circle <?= !empty($templateSelecionado['arquivo_docx']) ? 'completed' : '' ?>">3</div>
                            <div class="step-label">Arquivo Vinculado</div>
                        </div>

                        <div class="progress-step <?= !empty($templateSelecionado['ativo']) ? 'step-completed' : '' ?>">
                            <div class="step-circle <?= !empty($templateSelecionado['ativo']) ? 'completed' : '' ?>">4</div>
                            <div class="step-label">Template Ativo</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row grid-2">
            <div class="col">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><?= $editTemplate ? '✏️ Editar Template' : '📄 Criar Novo Template' ?></h3>
                        <?php if ($editTemplate): ?>
                            <span class="badge badge-info">Editando</span>
                        <?php endif; ?>
                    </div>

                    <form method="post">
                        <input type="hidden" name="acao" value="salvar_template">
                        <input type="hidden" name="id" value="<?= $editTemplate ? (int)$editTemplate['id'] : '' ?>">

                        <div class="form-group">
                            <label class="form-label">Nome do Template *</label>
                            <input type="text" name="nome" class="form-control"
                                   value="<?= $editTemplate ? htmlspecialchars($editTemplate['nome']) : '' ?>"
                                   placeholder="Ex: Contrato de Locação" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Descrição</label>
                            <textarea name="descricao" class="form-control" rows="3"
                                      placeholder="Descreva para que serve este template"><?= $editTemplate ? htmlspecialchars($editTemplate['descricao'] ?? '') : '' ?></textarea>
                        </div>

                        <?php if ($editTemplate): ?>
                            <div class="form-group">
                                <label style="display:flex; align-items:center; gap:8px;">
                                    <input type="checkbox" name="ativo" value="1" <?= !empty($editTemplate['ativo']) ? 'checked' : '' ?>>
                                    Template Ativo
                                </label>
                                <small class="form-text">Só marque como ativo após ter o arquivo .docx pronto e testado</small>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex gap-3 mt-4">
                            <button type="submit" class="btn btn-primary">
                                <?= $editTemplate ? '💾 Salvar Alterações' : '➕ Criar Template' ?>
                            </button>

                            <?php if ($editTemplate): ?>
                                <a href="config_documentos.php?aba=template" class="btn btn-outline">Cancelar</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">📋 Templates Cadastrados</h3>
                        <span class="badge badge-info"><?= count($templates) ?> templates</span>
                    </div>

                    <?php if (empty($templates)): ?>
                        <div class="text-center py-4">
                            <p>Nenhum template cadastrado ainda.</p>
                            <p class="form-text">Comece criando seu primeiro template!</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table campos-exclusivos-table">
                                <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Arquivo</th>
                                    <th>Status</th>
                                    <th class="text-center">Ações</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($templates as $tpl): ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <strong><?= htmlspecialchars($tpl['nome']) ?></strong>
                                                <?php if (!empty($tpl['descricao'])): ?>
                                                    <br><small class="form-text"><?= htmlspecialchars($tpl['descricao']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($tpl['arquivo_docx'])): ?>
                                                <code><?= htmlspecialchars($tpl['arquivo_docx']) ?></code>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($tpl['ativo']) && !empty($tpl['arquivo_docx'])): ?>
                                                <span class="template-status active">✅ Ativo</span>
                                            <?php elseif (empty($tpl['ativo']) && !empty($tpl['arquivo_docx'])): ?>
                                                <span class="template-status draft">📝 Arquivo Ok</span>
                                            <?php else: ?>
                                                <span class="template-status no-file">❌ Sem arquivo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions text-center">
                                            <div class="actions-group">
                                                <a href="config_documentos.php?aba=campos&template_id=<?= (int)$tpl['id'] ?>" class="action-btn action-edit">Editar</a>
                                                <a href="config_documentos.php?aba=arquivo&template_id=<?= (int)$tpl['id'] ?>" class="action-btn action-file">Arquivo</a>
                                                <a href="config_documentos.php?aba=teste&template_id=<?= (int)$tpl['id'] ?>" class="action-btn action-test">Testar</a>
                                                <form method="post" style="display:inline; margin:0;">
                                                <input type="hidden" name="acao" value="toggle_template_ativo">
                                                <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
                                                <input type="hidden" name="novo_status" value="<?= !empty($tpl['ativo']) ? 0 : 1 ?>">
                                                <button
                                                    type="submit"
                                                    class="action-btn action-edit"
                                                    <?= (empty($tpl['arquivo_docx']) && empty($tpl['ativo'])) ? 'disabled title="Vincule um arquivo .docx para ativar"' : '' ?>
                                                >
                                                    <?= !empty($tpl['ativo']) ? 'Desativar' : 'Ativar' ?>
                                                </button>
                                            </form>
                                                <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Tem certeza que deseja excluir este template? Isso apagará também o arquivo .docx (se existir).');">
                                                    <input type="hidden" name="acao" value="excluir_template">
                                                    <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
                                                    <button type="submit" class="action-btn action-delete">Excluir</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== ABA 2: CAMPOS E LISTAS ==================== -->
    <div class="tab-content <?= $abaAtual === 'campos' ? 'active' : '' ?>" id="tab-campos">
        <?php if ($templateSelecionado): ?>
            <div class="mb-3">
                <label class="form-label">Template selecionado</label>
                <select class="form-control" onchange="window.location.href='config_documentos.php?aba=campos&template_id=' + this.value">
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= ($templateSelecionado && (int)$t['id'] === (int)$templateSelecionado['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php
            // ==================== VARIÁVEIS GLOBAIS (ABA 2) ====================
            require_once __DIR__ . '/documentos/globais_ui.php';
            ?>
            <div class="stack">
                <div class="col">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">✏️ Campos Exclusivos do Template</h3>
                            <span class="badge badge-info"><?= count($campos) ?> campos</span>
                        </div>

                        <p class="card-subtitle mb-3">
                            Configure os campos que serão substituídos no documento Word. Copie os placeholders para usar no template.
                        </p>

                        <form method="post">
                            <input type="hidden" name="template_id" value="<?= (int)$templateSelecionado['id'] ?>">
                            <input type="hidden" name="acao" value="salvar_campos">

                            <div class="table-responsive">
                                <table class="table table-campos-exclusivos">
                                    <thead>
                                    <tr>
                                        <th style="width:60px;">#</th>
                                        <th>Rótulo</th>
                                        <th>Nome do Campo</th>
                                        <th>Tipo</th>
                                        <th>Lista</th>
                                        <th class="text-center">Obrig.</th>
                                        <th>Placeholder</th>
                                        <th class="text-center">Ações</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($campos)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">Nenhum campo cadastrado ainda.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($campos as $campo): ?>
                                            <?php
                                            $idCampo = (int)($campo['id'] ?? 0);
                                            $rotuloAtual = (string)($campo['rotulo'] ?? '');
                                            $nomeCampoAtual = (string)($campo['nome_campo'] ?? '');
                                            $tipoAtual = (string)($campo['tipo'] ?? 'texto_curto');
                                            $ordemAtual = (int)($campo['ordem'] ?? 0);
                                            $listaAtual = (int)($campo['lista_id'] ?? 0);
                                            $obrigAtual = !empty($campo['obrigatorio']);
                                            $placeholder = campoParaPlaceholderDocx($nomeCampoAtual);
                                            ?>
                                            <tr>
                                                <td>
                                                    <input type="number"
                                                           class="form-control form-control-sm"
                                                           name="campos[<?= $idCampo ?>][ordem]"
                                                           value="<?= $ordemAtual ?>"
                                                           style="width:60px;"
                                                           min="0">
                                                </td>

                                                <td>
                                                    <input type="text"
                                                           class="form-control form-control-sm"
                                                           name="campos[<?= $idCampo ?>][rotulo]"
                                                           value="<?= htmlspecialchars($rotuloAtual, ENT_QUOTES, 'UTF-8') ?>">
                                                </td>

                                                <td>
                                                    <input type="text"
                                                           class="form-control form-control-sm"
                                                           name="campos[<?= $idCampo ?>][nome_campo]"
                                                           value="<?= htmlspecialchars($nomeCampoAtual, ENT_QUOTES, 'UTF-8') ?>"
                                                           pattern="[A-Za-z0-9_]+">
                                                </td>

                                                <td>
                                                    <select class="form-control form-control-sm"
                                                            name="campos[<?= $idCampo ?>][tipo]">
                                                        <?php foreach ($tipos as $valor => $label): ?>
                                                            <option value="<?= htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8') ?>"
                                                                <?= ($tipoAtual === (string)$valor) ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>

                                                <td>
                                                    <select class="form-control form-control-sm"
                                                            name="campos[<?= $idCampo ?>][lista_id]">
                                                        <option value="">— Nenhuma —</option>
                                                        <?php foreach (($listas ?? []) as $l): ?>
                                                            <option value="<?= (int)$l['id'] ?>"
                                                                <?= ($listaAtual === (int)$l['id']) ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars((string)$l['nome'], ENT_QUOTES, 'UTF-8') ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>

                                                <td class="text-center">
                                                    <input type="checkbox"
                                                           name="campos[<?= $idCampo ?>][obrigatorio]"
                                                           value="1"
                                                        <?= $obrigAtual ? 'checked' : '' ?>>
                                                </td>

                                                <td>
                                                    <div class="copy-box">
                                                        <code><?= htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') ?></code>
                                                        <button type="button"
                                                                class="copy-btn"
                                                                data-text="<?= htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') ?>">
                                                            Copiar
                                                        </button>
                                                    </div>
                                                </td>

                                                <td class="text-center">
                                                    <button type="submit"
                                                            class="action-btn action-danger"
                                                            name="remover_campo"
                                                            value="<?= $idCampo ?>"
                                                            formnovalidate
                                                            onclick="return confirm('Excluir este campo?');">
                                                        Excluir
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="card mt-4">
                                <div class="card-header">
                                    <h4 class="card-title mb-0">➕ Adicionar Novo Campo</h4>
                                </div>

                                <div class="p-3">
                                    <div class="row">
                                        <div class="col-6">
                                            <div class="form-group">
                                                <label class="form-label">Rótulo *</label>
                                                <input type="text" name="novo_campo[rotulo]" class="form-control" placeholder="Ex: Nome do Cliente" required>
                                            </div>
                                        </div>

                                        <div class="col-6">
                                            <div class="form-group">
                                                <label class="form-label">Nome do Campo (placeholder)</label>
                                                <input type="text" name="novo_campo[nome_campo]" class="form-control"
                                                       placeholder="Ex: nome_cliente" pattern="[A-Za-z0-9_]+" required>
                                                <small class="form-text">Se deixar em branco, o nome será gerado automaticamente a partir do rótulo. Sem espaços, use underline (_).</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-4">
                                            <div class="form-group">
                                                <label class="form-label">Tipo</label>
                                                <select name="novo_campo[tipo]" class="form-control">
                                                    <?php foreach ($tipos as $valor => $label): ?>
                                                        <option value="<?= htmlspecialchars($valor) ?>"><?= htmlspecialchars($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-4">
                                            <div class="form-group">
                                                <label class="form-label">Lista (opcional)</label>
                                                <select name="novo_campo[lista_id]" class="form-control">
                                                    <option value="">— Nenhuma —</option>
                                                    <?php foreach ($listas as $lista): ?>
                                                        <option value="<?= (int)$lista['id'] ?>"><?= htmlspecialchars($lista['nome']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-4">
                                            <div class="form-group">
                                                <label class="form-label">Ordem</label>
                                                <input type="number" name="novo_campo[ordem]" value="0" class="form-control" min="0">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex gap-2 mt-3" style="flex-wrap: wrap;">
                                        <button type="submit" name="acao" value="inserir_campo" class="btn btn-success"> Inserir Campo</button>
                                        <button type="submit" name="acao" value="salvar_campos" class="btn btn-primary" formnovalidate>
                                            Salvar Todos os Campos
                                        </button>
                                        <a href="config_documentos.php?aba=teste&template_id=<?= (int)$templateSelecionado['id'] ?>" class="btn btn-outline">
                                            Testar Template →
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="col">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">📋 Listas Reutilizáveis</h3>
                            <button type="button" id="btnNovaLista" class="btn btn-primary btn-small" onclick="toggleListaForm(true)">
                                + Nova Lista
                            </button>
                        </div>

                        <?php
                        $opcoesEdit = [];
                        if ($editLista && !empty($editLista['config_manual'])) {
                            $tmp = json_decode($editLista['config_manual'], true);
                            $opcoesEdit = is_array($tmp) ? $tmp : [];
                        }
                        if (empty($opcoesEdit)) {
                            $opcoesEdit = [['valor' => '', 'label' => '']];
                        }
                        ?>

                        <div id="listaForm" class="card mt-3" style="display:none;">
                            <div class="card-header">
                                <h4 class="card-title mb-0">
                                    <?= $editLista ? '✏️ Editar Lista Manual' : '➕ Nova Lista Manual' ?>
                                </h4>
                            </div>

                            <div class="p-3">
                                <form method="post" onsubmit="return validarFormLista();">
                                    <input type="hidden" name="acao" value="salvar_lista">
                                    <input type="hidden" name="id" value="<?= $editLista ? (int)$editLista['id'] : 0 ?>">
                                    <input type="hidden" name="template_id" value="<?= (int)$templateSelecionado['id'] ?>">

                                    <div class="form-group">
                                        <label class="form-label">Nome da lista *</label>
                                        <input type="text" name="nome" class="form-control" placeholder="Ex: Lojas"
                                               value="<?= $editLista ? htmlspecialchars($editLista['nome']) : '' ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Opções *</label>

                                        <div id="opcoesLista">
                                            <?php foreach ($opcoesEdit as $i => $op): ?>
                                                <div class="d-flex gap-2 mb-2 opcao-item">
                                                    <input type="text"
                                                           name="config_manual_opcoes[<?= (int)$i ?>][valor]"
                                                           class="form-control form-control-sm opcao-valor"
                                                           placeholder="Valor"
                                                           value="<?= htmlspecialchars($op['valor'] ?? '') ?>" required>

                                                    <input type="text"
                                                           name="config_manual_opcoes[<?= (int)$i ?>][label]"
                                                           class="form-control form-control-sm opcao-label"
                                                           placeholder="Rótulo"
                                                           value="<?= htmlspecialchars($op['label'] ?? '') ?>" required>

                                                    <button type="button" class="btn btn-small btn-danger" onclick="removerOpcao(this)">-</button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <button type="button" class="btn btn-outline btn-small mt-2" onclick="adicionarOpcao()">
                                            + Adicionar opção
                                        </button>

                                        <div class="form-text">Preencha pelo menos 1 opção (valor e rótulo).</div>
                                    </div>

                                    <div class="d-flex gap-2 mt-3" style="flex-wrap:wrap;">
                                        <button type="submit" class="btn btn-success"> Salvar Lista</button>
                                        <button type="button" class="btn btn-outline" onclick="toggleListaForm(false)">Cancelar</button>

                                        <?php if ($editLista): ?>
                                            <a href="config_documentos.php?aba=campos&template_id=<?= (int)$templateSelecionado['id'] ?>" class="btn btn-outline">
                                                Sair da edição
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <h4 class="mt-4 mb-3">Listas Disponíveis</h4>

                        <?php if (empty($listas)): ?>
                            <p class="form-text text-center">Nenhuma lista cadastrada.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th class="text-center">Ações</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($listas as $lista): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($lista['nome']) ?></td>
                                            <td class="text-center">
                                                <a href="config_documentos.php?aba=campos&template_id=<?= (int)$templateSelecionado['id'] ?>&edit_lista_id=<?= (int)$lista['id'] ?>"
                                                   class="btn btn-small btn-secondary">
                                                    Editar
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">Selecione um template para cadastrar campos e listas.</div>
        <?php endif; ?>
    </div>

    <!-- ==================== ABA 3: ARQUIVO (UPLOAD) ==================== -->
    <div class="tab-content <?= $abaAtual === 'arquivo' ? 'active' : '' ?>" id="tab-arquivo">
        <?php if (empty($templates)): ?>
            <div class="card">
                <div class="text-center py-4">
                    <p>Nenhum template cadastrado ainda.</p>
                    <a href="config_documentos.php?aba=template" class="btn btn-primary">📄 Criar Template</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">📎 Upload / Vincular Arquivo .docx</h3>
                </div>

                <div class="mb-3">
                    <label class="form-label">Template selecionado</label>
                    <select class="form-control" onchange="window.location.href='config_documentos.php?aba=arquivo&template_id=' + this.value">
                        <?php foreach ($templates as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= ($templateSelecionado && (int)$t['id'] === (int)$templateSelecionado['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($templateSelecionado): ?>
                    <div class="alert alert-info">
                        Para enviar/alterar o arquivo deste template, use a tela o botão abaixo.
                    </div>

                    <?php
                    
                    define('UPLOAD_TEMPLATE_EMBUTIDO', true);
                    $_GET['template_id'] = (int)$templateSelecionado['id'];
                    include __DIR__ . '/upload_template.php';
                    
                    ?>

                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==================== ABA 4: TESTE ==================== -->
    <div class="tab-content <?= $abaAtual === 'teste' ? 'active' : '' ?>" id="tab-teste">
        <?php if (empty($templates)): ?>
            <div class="card">
                <div class="text-center py-4">
                    <h4 class="mb-3">🧪 Nenhum Template Disponível</h4>
                    <p class="form-text mb-4">Crie e configure um template completo antes de testar.</p>
                    <a href="config_documentos.php?aba=template" class="btn btn-primary">📄 Criar Primeiro Template</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">🧪 Testar Template</h3>
                    <span class="badge badge-warning">Ambiente de Testes</span>
                </div>

                <div class="d-flex align-center gap-3">
                    <label class="form-label mb-0">Selecione o template:</label>
                    <select id="templateSelector" class="form-control" style="max-width: 400px;"
                            onchange="window.location.href='config_documentos.php?aba=teste&template_id=' + this.value">
                        <?php foreach ($templates as $t): ?>
                            <?php if (!empty($t['arquivo_docx'])): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= ($templateSelecionado && (int)$t['id'] === (int)$templateSelecionado['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t['nome']) ?><?= !empty($t['ativo']) ? ' (✅)' : ' (📝)' ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

             <?php if ($templateSelecionado && !empty($templateSelecionado['arquivo_docx'])): ?>
                <div class="row">
                    <div class="col">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">📝 Preencha os Campos de Teste</h3>
                                <?php
                                $caminhoArquivo = caminhoTemplateDocx($templateSelecionado['arquivo_docx']);
                                $arquivoExiste = is_file($caminhoArquivo);
                                ?>
                                <?php if ($arquivoExiste): ?>
                                    <span class="badge badge-success">Arquivo encontrado</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Arquivo não encontrado</span>
                                <?php endif; ?>
                            </div>

                            <div class="p-4">
                                <div class="alert alert-info mb-4">
                                    <h5>📄 Arquivo de Teste:</h5>
                                    <p class="mb-2"><strong><?= htmlspecialchars($templateSelecionado['arquivo_docx']) ?></strong></p>
                                    <?php if ($arquivoExiste): ?>
                                        <p class="mb-0">✅ Arquivo encontrado na pasta /formularios/</p>
                                    <?php else: ?>
                                        <p class="mb-0" style="color:#b00020;">❌ Arquivo não encontrado na pasta /formularios/</p>
                                    <?php endif; ?>
                                </div>

                                    <?php
                                    // --------------------
                                    // Globais vinculadas ao template (para teste)
                                    // --------------------
                                    $globaisVinculadas = [];

                                    if (!empty($templateSelecionado)) {
                                        $tid = (int)$templateSelecionado['id'];

                                        $stmtGV = $mysqli->prepare("
                                            SELECT 
                                                g.id, g.rotulo, g.chave, g.tipo, g.lista_id,
                                                tg.obrigatorio,
                                                tg.ordem AS ordem,
                                                l.config_manual
                                            FROM documento_template_globais tg
                                            INNER JOIN documento_variaveis_globais g ON g.id = tg.global_id
                                            LEFT JOIN documento_listas l ON l.id = g.lista_id
                                            WHERE tg.template_id = ?
                                            ORDER BY tg.ordem ASC, g.rotulo ASC, g.id ASC
                                        ");

                                        if ($stmtGV) {
                                            $stmtGV->bind_param('i', $tid);
                                            $stmtGV->execute();
                                            $rGV = $stmtGV->get_result();
                                            while ($row = $rGV->fetch_assoc()) {
                                                $globaisVinculadas[] = $row;
                                            }
                                            $stmtGV->close();
                                        }
                                    }
                                    ?>

                                    <?php
                                    $temAlgumCampoVinculado = (!empty($campos) || !empty($globaisVinculadas));
                                    ?>

                                    <?php if ($temAlgumCampoVinculado): ?>


                                    <form method="post" id="formTeste">
                                        <input type="hidden" name="acao" value="testar_template">
                                        <input type="hidden" name="template_id" value="<?= (int)$templateSelecionado['id'] ?>">

                                        <h5 class="mb-3">Campos do Template:</h5>

                                        <?php if (!empty($globaisVinculadas)): ?>
                                            <h5 class="mb-3">Campos Globais Vinculados:</h5>

                                            <?php foreach ($globaisVinculadas as $g): ?>
                                                <?php
                                                $chaveG = strtoupper(trim((string)($g['chave'] ?? '')));
                                                if ($chaveG === '') { continue; }

                                                $placeholderG = '{{' . $chaveG . '}}';
                                                $tipoG = (string)($g['tipo'] ?? 'texto_curto');

                                                // valores já preenchidos (quando volta do teste)
                                                $valoresTesteForm = (is_array($preview['valores'] ?? null)) ? $preview['valores'] : [];
                                                $keyPost = 'GLOBAL__' . $chaveG; // evita conflito com nome_campo dos exclusivos
                                                $valorAtual = (string)($valoresTesteForm[$keyPost] ?? '');

                                                // opções da lista (config_manual)
                                                $opcoesListaG = [];
                                                $cfgG = (string)($g['config_manual'] ?? '');
                                                $arrG = json_decode($cfgG, true);

                                                if (is_array($arrG)) {
                                                    foreach ($arrG as $it) {
                                                        if (is_array($it)) {
                                                            $val = (string)($it['valor'] ?? $it['value'] ?? $it['id'] ?? $it['label'] ?? '');
                                                            $lab = (string)($it['label'] ?? $it['nome'] ?? $it['text'] ?? $val);
                                                        } else {
                                                            $val = (string)$it;
                                                            $lab = (string)$it;
                                                        }
                                                        if ($val !== '') {
                                                            $opcoesListaG[] = ['valor' => $val, 'label' => $lab];
                                                        }
                                                    }
                                                }
                                                ?>

                                                <div class="form-group">
                                                    <label class="form-label">
                                                        <?= htmlspecialchars((string)$g['rotulo'], ENT_QUOTES, 'UTF-8') ?>
                                                        <?php if (!empty($g['obrigatorio'])): ?>
                                                            <span style="color:#b00020;">*</span>
                                                        <?php endif; ?>
                                                    </label>

                                                    <?php if (in_array($tipoG, ['select', 'radio', 'enum'], true)): ?>

                                                        <?php if (empty($opcoesListaG)): ?>
                                                            <select class="form-control" name="teste[<?= htmlspecialchars($keyPost, ENT_QUOTES, 'UTF-8') ?>]" disabled>
                                                                <option value="">(Sem opções na lista)</option>
                                                            </select>
                                                        <?php else: ?>

                                                            <?php if ($tipoG === 'radio'): ?>
                                                                <div style="display:flex; gap:12px; flex-wrap:wrap; padding:6px 0;">
                                                                    <?php foreach ($opcoesListaG as $op): ?>
                                                                        <label style="display:inline-flex; align-items:center; gap:6px;">
                                                                            <input
                                                                                type="radio"
                                                                                name="teste[<?= htmlspecialchars($keyPost, ENT_QUOTES, 'UTF-8') ?>]"
                                                                                value="<?= htmlspecialchars($op['valor'], ENT_QUOTES, 'UTF-8') ?>"
                                                                                <?= ($valorAtual === (string)$op['valor']) ? 'checked' : '' ?>
                                                                            >
                                                                            <?= htmlspecialchars($op['label'], ENT_QUOTES, 'UTF-8') ?>
                                                                        </label>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php else: ?>
                                                                <select class="form-control" name="teste[<?= htmlspecialchars($keyPost, ENT_QUOTES, 'UTF-8') ?>]">
                                                                    <option value="">Selecione...</option>
                                                                    <?php foreach ($opcoesListaG as $op): ?>
                                                                        <option
                                                                            value="<?= htmlspecialchars($op['valor'], ENT_QUOTES, 'UTF-8') ?>"
                                                                            <?= ($valorAtual === (string)$op['valor']) ? 'selected' : '' ?>
                                                                        >
                                                                            <?= htmlspecialchars($op['label'], ENT_QUOTES, 'UTF-8') ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            <?php endif; ?>

                                                        <?php endif; ?>

                                                    <?php elseif ($tipoG === 'texto_longo'): ?>

                                                        <textarea class="form-control"
                                                                  name="teste[<?= htmlspecialchars($keyPost, ENT_QUOTES, 'UTF-8') ?>]"
                                                                  rows="3"
                                                                  placeholder="<?= htmlspecialchars($placeholderG, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($valorAtual, ENT_QUOTES, 'UTF-8') ?></textarea>
                                                    <?php elseif ($tipoG === 'booleano'): ?>
                                                        <select class="form-control" name="teste[<?= htmlspecialchars($keyPost, ENT_QUOTES, 'UTF-8') ?>]">
                                                            <option value="">Selecione...</option>
                                                            <option value="Sim" <?= ($valorAtual === 'Sim') ? 'selected' : '' ?>>Sim</option>
                                                            <option value="Não" <?= ($valorAtual === 'Não') ? 'selected' : '' ?>>Não</option>
                                                        </select>
                                                    <?php elseif ($tipoG === 'data'): ?>
                                                        <input type="date" class="form-control"
                                                               name="teste[<?= htmlspecialchars($keyPost, ENT_QUOTES, 'UTF-8') ?>]"
                                                               value="<?= htmlspecialchars($valorAtual, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?php else: ?>
                                                        <input type="text"
                                                               class="form-control"
                                                               name="teste[<?= htmlspecialchars($keyPost, ENT_QUOTES, 'UTF-8') ?>]"
                                                               value="<?= htmlspecialchars($valorAtual, ENT_QUOTES, 'UTF-8') ?>"
                                                               placeholder="<?= htmlspecialchars($placeholderG, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?php endif; ?>

                                                    <small class="form-text">Placeholder: <code><?= htmlspecialchars($placeholderG, ENT_QUOTES, 'UTF-8') ?></code></small>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                        <?php foreach ($campos as $campo): ?>
                                            <?php
                                            $placeholder = campoParaPlaceholderDocx($campo['nome_campo']);
                                            $tipo = $campo['tipo'];
                                            ?>
                                            <div class="form-group">
                                                <label class="form-label">
                                                    <?= htmlspecialchars($campo['rotulo']) ?>
                                                    <?php if (!empty($campo['obrigatorio'])): ?>
                                                        <span style="color:#b00020;">*</span>
                                                    <?php endif; ?>
                                                </label>

                                                <?php if (in_array($tipo, ['select', 'radio', 'enum'], true)): ?>

                                                    <?php
                                                    // Valor atual (quando já gerou teste e voltou pra tela)
                                                    $valoresTesteForm = (is_array($preview['valores'] ?? null)) ? $preview['valores'] : [];
                                                    $valorAtual = (string)($valoresTesteForm[$campo['nome_campo']] ?? '');

                                                    // Opções da lista (config_manual da documento_listas)
                                                    $opcoesLista = [];
                                                    $listaIdCampo = (int)($campo['lista_id'] ?? 0);

                                                    if ($listaIdCampo > 0) {
                                                        foreach (($listas ?? []) as $l) {
                                                            if ((int)$l['id'] === $listaIdCampo) {
                                                                $cfg = (string)($l['config_manual'] ?? '');
                                                                $arr = json_decode($cfg, true);

                                                                if (is_array($arr)) {
                                                                    foreach ($arr as $it) {
                                                                        if (is_array($it)) {
                                                                            $val = (string)($it['valor'] ?? $it['value'] ?? $it['id'] ?? $it['label'] ?? '');
                                                                            $lab = (string)($it['label'] ?? $it['nome'] ?? $it['text'] ?? $val);
                                                                        } else {
                                                                            $val = (string)$it;
                                                                            $lab = (string)$it;
                                                                        }

                                                                        if ($val !== '') {
                                                                            $opcoesLista[] = ['valor' => $val, 'label' => $lab];
                                                                        }
                                                                    }
                                                                }
                                                                break;
                                                            }
                                                        }
                                                    }
                                                    ?>

                                                    <?php if (empty($opcoesLista)): ?>
                                                        <select class="form-control" name="teste[<?= htmlspecialchars($campo['nome_campo']) ?>]" disabled>
                                                            <option value="">(Sem opções na lista)</option>
                                                        </select>
                                                    <?php else: ?>

                                                        <?php if ($tipo === 'radio'): ?>
                                                            <div style="display:flex; gap:12px; flex-wrap:wrap; padding:6px 0;">
                                                                <?php foreach ($opcoesLista as $op): ?>
                                                                    <label style="display:inline-flex; align-items:center; gap:6px;">
                                                                        <input
                                                                            type="radio"
                                                                            name="teste[<?= htmlspecialchars($campo['nome_campo']) ?>]"
                                                                            value="<?= htmlspecialchars($op['valor']) ?>"
                                                                            <?= ($valorAtual === (string)$op['valor']) ? 'checked' : '' ?>
                                                                        >
                                                                        <?= htmlspecialchars($op['label']) ?>
                                                                    </label>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <select class="form-control" name="teste[<?= htmlspecialchars($campo['nome_campo']) ?>]">
                                                                <option value="">Selecione...</option>
                                                                <?php foreach ($opcoesLista as $op): ?>
                                                                    <option
                                                                        value="<?= htmlspecialchars($op['valor']) ?>"
                                                                        <?= ($valorAtual === (string)$op['valor']) ? 'selected' : '' ?>
                                                                    >
                                                                        <?= htmlspecialchars($op['label']) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        <?php endif; ?>

                                                    <?php endif; ?>

                                                <?php elseif ($tipo === 'texto_longo'): ?>

                                                    <textarea class="form-control" name="teste[<?= htmlspecialchars($campo['nome_campo']) ?>]" rows="3" placeholder="<?= htmlspecialchars($placeholder) ?>"></textarea>
                                                <?php elseif ($tipo === 'booleano'): ?>
                                                    <select class="form-control" name="teste[<?= htmlspecialchars($campo['nome_campo']) ?>]">
                                                        <option value="">Selecione...</option>
                                                        <option value="Sim">Sim</option>
                                                        <option value="Não">Não</option>
                                                    </select>
                                                <?php elseif ($tipo === 'data'): ?>
                                                    <input type="date" class="form-control" name="teste[<?= htmlspecialchars($campo['nome_campo']) ?>]" placeholder="<?= htmlspecialchars($placeholder) ?>">
                                                <?php else: ?>
                                                    <input type="text" class="form-control" name="teste[<?= htmlspecialchars($campo['nome_campo']) ?>]" placeholder="<?= htmlspecialchars($placeholder) ?>">
                                                <?php endif; ?>

                                                <small class="form-text">Placeholder: <code><?= htmlspecialchars($placeholder) ?></code></small>
                                            </div>
                                        <?php endforeach; ?>

                                        <div class="d-flex gap-3 mt-4">
                                            <button type="submit" class="btn btn-success"> Gerar Teste</button>
                                            <button type="button" class="btn btn-outline" onclick="limparTeste()">Limpar Campos</button>
                                            <a href="config_documentos.php?aba=template&edit_template_id=<?= (int)$templateSelecionado['id'] ?>" class="btn btn-primary">
                                                Editar Template
                                            </a>

                                            <a href="config_documentos.php?aba=arquivo&template_id=<?= (int)$templateSelecionado['id'] ?>" class="btn btn-info">
                                                Arquivo
                                            </a>
                                        </div>
                                    </form>
                                    <p>Para ativar o template acesse a Guia 1 - Cadastrar Template</p>
                                <?php else: ?>
                                    <div class="alert alert-warning">
                                        <h5>⚠️ Template sem campos</h5>
                                        <p>Este template não possui campos configurados. Configure os campos primeiro antes de testar.</p>
                                        <a href="config_documentos.php?aba=campos&template_id=<?= (int)$templateSelecionado['id'] ?>" class="btn btn-warning">
                                            ✏️ Configurar Campos
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">📊 Resultados do Teste</h3>
                            </div>
                            <?php
                            $preview = $_SESSION['preview_teste'] ?? null;

                            $previewValido = false;
                            if (
                                is_array($preview)
                                && !empty($templateSelecionado)
                                && (int)$preview['template_id'] === (int)$templateSelecionado['id']
                            ) {
                                $previewValido = true;
                            }
                            ?>
                            <div class="p-4">
                                 <div id="testResults">

                                    <?php if ($previewValido): ?>

                                        <div class="alert alert-success">
                                            <h5>✅ Teste Realizado!</h5>
                                            <p><?= htmlspecialchars($preview['mensagem'] ?? 'Teste concluído.') ?></p>

                                            <div class="preview-simples mt-3">
                                                <div class="alert alert-info">
                                                    <strong>Prévia simplificada:</strong>
                                                    isto <u>não</u> é o documento final.
                                                </div>

                                                <?php
                                                $valoresTeste = is_array($preview['valores'] ?? null)
                                                    ? $preview['valores']
                                                    : [];
                                                ?>

                                                <?php if (!empty($campos) || !empty($globaisVinculadas)): ?>
                                                    <div class="preview-grid">
                                                        <?php foreach ($campos as $c): ?>
                                                            <?php
                                                            $nome  = $c['nome_campo'];
                                                            $rot   = $c['rotulo'];
                                                            $valor = trim($valoresTeste[$nome] ?? '');
                                                            ?>
                                                            <div class="preview-item">
                                                                <div class="preview-label">
                                                                    <?= htmlspecialchars($rot) ?>
                                                                    <small><?= htmlspecialchars($nome) ?></small>
                                                                </div>
                                                                <div class="preview-value <?= ($valor === '' ? 'is-empty' : '') ?>">
                                                                    <?= $valor === '' ? '— (vazio)' : nl2br(htmlspecialchars($valor)) ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                    <?php else: ?>

                                        <p class="form-text text-center py-4">
                                            Preencha os campos e clique em "Gerar Documento de Teste" para ver os resultados.
                                        </p>

                                    <?php endif; ?>

                                </div>


                                <div class="card mt-4">
                                    <div class="card-header">
                                        <h3 class="card-title">📋 Placeholders do Template</h3>
                                    </div>
                                    <div class="p-3">
                                        <?php if (!empty($campos) || !empty($globaisVinculadas)): ?>
                                            <p class="form-text">Copie estes placeholders para usar no seu documento Word:</p>
                                            <div class="placeholders-list">

                                                <?php if (!empty($globaisVinculadas)): ?>
                                                    <?php foreach ($globaisVinculadas as $g): ?>
                                                        <?php
                                                        $ch = strtoupper(trim((string)($g['chave'] ?? '')));
                                                        if ($ch === '') { continue; }
                                                        $ph = '{{' . $ch . '}}';
                                                        ?>
                                                        
<div class="ph-row">
    <div class="ph-label">
        <div class="ph-rotulo"><strong><?= htmlspecialchars((string)$g['rotulo'], ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div class="ph-chave"><?= htmlspecialchars($ch, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div class="ph-value">
        <input class="ph-input" type="text" value="<?= htmlspecialchars($ph, ENT_QUOTES, 'UTF-8') ?>" readonly>
    </div>
    <div class="ph-actions">
        <button type="button" class="copy-btn" data-text="<?= htmlspecialchars($ph, ENT_QUOTES, 'UTF-8') ?>">Copiar</button>
    </div>
</div>

                                                    <?php endforeach; ?>
                                                <?php endif; ?>

                                                <?php foreach ($campos as $campo): ?>
                                                    <?php $ph = campoParaPlaceholderDocx($campo['nome_campo']); ?>
                                                    <div class="ph-row">
                                                        <div class="ph-label">
                                                            <div class="ph-rotulo"><strong><?= htmlspecialchars($campo['rotulo']) ?></strong></div>
                                                            <div class="ph-chave"><?= htmlspecialchars($campo['nome_campo']) ?></div>
                                                        </div>
                                                        <div class="ph-value">
                                                            <input class="ph-input" type="text" value="<?= htmlspecialchars($ph) ?>" readonly>
                                                        </div>
                                                        <div class="ph-actions">
                                                            <button type="button" class="copy-btn" data-text="<?= htmlspecialchars($ph) ?>">Copiar</button>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="form-text text-center">Nenhum placeholder disponível.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($templateSelecionado && empty($templateSelecionado['arquivo_docx'])): ?>
                <div class="card">
                    <div class="text-center py-4">
                        <h4 class="mb-3">📎 Template sem Arquivo Vinculado</h4>
                        <p class="form-text mb-4">Este template não possui arquivo .docx vinculado. Vincule um arquivo antes de testar.</p>
                        <a
                            href="config_documentos.php?aba=arquivo&template_id=<?= (int)$templateSelecionado['id'] ?>"
                            class="action-btn action-file"
                            >
                            Arquivo
                            </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="text-center py-4">
                        <h4 class="mb-3">🎯 Selecione um Template</h4>
                        <p class="form-text mb-4">Selecione um template com arquivo vinculado para testar.</p>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="app-version-label">
    <?= defined('VERSAO_CONFIG_DOCUMENTOS') ? VERSAO_CONFIG_DOCUMENTOS : 'DOCUMENTOS v2.0' ?>
</div>

<script>
 
// ===== ABAS =====
const FIRST_TEMPLATE_ID = <?= !empty($templates) ? (int)$templates[0]['id'] : 0 ?>;
const NEED_TEMPLATE = new Set(['campos', 'arquivo', 'teste']);

function normalizeTab(tab) {
  return ['template', 'campos', 'arquivo', 'teste'].includes(tab) ? tab : 'template';
}

function setActiveTab(tab) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  const btn = document.querySelector(`.tab-btn[data-tab="${tab}"]`);
  const content = document.getElementById(`tab-${tab}`);
  if (btn) btn.classList.add('active');
  if (content) content.classList.add('active');
}

function buildUrlWithParams(tab) {
  const url = new URL(window.location.href);
  const params = url.searchParams;
  tab = normalizeTab(tab);
  params.set('aba', tab);
  
  if (NEED_TEMPLATE.has(tab)) {
    const tid = params.get('templateid');  // ← MUDANÇA 1
    if (!tid && FIRST_TEMPLATE_ID > 0) {
      params.set('templateid', String(FIRST_TEMPLATE_ID));  // ← MUDANÇA 2
    }
  }
  
  url.search = params.toString();
  return url.toString();
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const tab = btn.dataset.tab;
      const newUrl = buildUrlWithParams(tab);
      
      if (newUrl === window.location.href) {
        setActiveTab(tab);
        return;
      }
      window.location.href = newUrl;
    });
  });

  const url = new URL(window.location.href);
  const tab = normalizeTab(url.searchParams.get('aba') || 'template');
  
  if (NEED_TEMPLATE.has(tab) && !url.searchParams.get('templateid') && FIRST_TEMPLATE_ID > 0) {  // ← MUDANÇA 3
    window.location.href = buildUrlWithParams(tab);
    return;
  }
  
  setActiveTab(tab);
  
  const isEdit = <?= !empty($editLista) ? 'true' : 'false' ?>;
  if (isEdit) toggleListaForm(true);
});


  // ===== LISTAS =====
  let opcaoIndex = <?php
    if ($editLista && !empty($editLista['config_manual'])) {
      $opcoes = json_decode($editLista['config_manual'], true);
      echo is_array($opcoes) ? count($opcoes) : 1;
    } else {
      echo '1';
    }
  ?>;

  function toggleListaForm(forceOpen) {
    const form = document.getElementById('listaForm');
    if (!form) return;

    const isHidden = window.getComputedStyle(form).display === 'none';

    if (forceOpen === true) form.style.display = 'block';
    else if (forceOpen === false) form.style.display = 'none';
    else form.style.display = isHidden ? 'block' : 'none';

    if (window.getComputedStyle(form).display !== 'none') {
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      const first = form.querySelector('input,select,textarea');
      if (first) first.focus();
    }
  }

  function validarFormLista() {
    const opcoes = document.querySelectorAll('.opcao-item');
    let temDados = false;

    opcoes.forEach(opcao => {
      const valor = opcao.querySelector('.opcao-valor')?.value ?? '';
      const label = opcao.querySelector('.opcao-label')?.value ?? '';
      if (valor.trim() !== '' || label.trim() !== '') temDados = true;
    });

    if (!temDados) {
      alert('Para listas manuais, adicione pelo menos uma opção válida');
      return false;
    }
    return true;
  }

  function adicionarOpcao() {
    const container = document.getElementById('opcoesLista');
    if (!container) return;

    const div = document.createElement('div');
    div.className = 'd-flex gap-2 mb-2 opcao-item';
    div.innerHTML = `
      <input type="text" name="config_manual_opcoes[${opcaoIndex}][valor]"
             class="form-control form-control-sm opcao-valor"
             placeholder="Valor" required>
      <input type="text" name="config_manual_opcoes[${opcaoIndex}][label]"
             class="form-control form-control-sm opcao-label"
             placeholder="Rótulo" required>
      <button type="button" class="btn btn-small btn-danger" onclick="removerOpcao(this)">-</button>
    `;
    container.appendChild(div);
    opcaoIndex++;
  }

  function removerOpcao(btn) {
    const container = document.getElementById('opcoesLista');
    if (!container) return;

    if (container.children.length > 1) {
      btn.parentElement.remove();
      const opcoes = container.querySelectorAll('.opcao-item');
      opcoes.forEach((opcao, index) => {
        opcao.querySelector('.opcao-valor').name = `config_manual_opcoes[${index}][valor]`;
        opcao.querySelector('.opcao-label').name = `config_manual_opcoes[${index}][label]`;
      });
      opcaoIndex = opcoes.length;
      return;
    }

    btn.parentElement.querySelectorAll('input').forEach(i => (i.value = ''));
  }

  // ===== COPIAR PLACEHOLDER =====
  document.addEventListener('click', (e) => {
  const btn = e.target.closest('.copy-btn');
  if (!btn) return;

  const text = btn.dataset.text || '';
  if (!text) return;

  // tenta clipboard moderno (funciona em https / localhost)
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(() => {
      const originalText = btn.textContent;
      btn.textContent = '✅ Copiado!';
      btn.classList.add('copied');
      setTimeout(() => {
        btn.textContent = originalText;
        btn.classList.remove('copied');
      }, 2000);
    }).catch(() => fallbackCopy(text, btn));
  } else {
    fallbackCopy(text, btn);
  }
});

function fallbackCopy(text, btn) {
  const textArea = document.createElement('textarea');
  textArea.value = text;
  document.body.appendChild(textArea);
  textArea.select();
  document.execCommand('copy');
  document.body.removeChild(textArea);

  const originalText = btn.textContent;
  btn.textContent = '✅ Copiado!';
  btn.classList.add('copied');
  setTimeout(() => {
    btn.textContent = originalText;
    btn.classList.remove('copied');
  }, 2000);
}


  // ===== LIMPAR TESTE =====
  function limparTeste() {
    const form = document.getElementById('formTeste');
    if (form) form.reset();

    const results = document.getElementById('testResults');
    if (results) {
      results.innerHTML = `
        <p class="form-text text-center py-4">
          Preencha os campos e clique em "Gerar Documento de Teste" para ver os resultados.
        </p>
      `;
    }
  }

  function abrirUploadModal(templateId){
    const overlay = document.getElementById('uploadModalOverlay');
    const frame = document.getElementById('uploadFrame');
    frame.src = 'upload_template.php?template_id=' + templateId;
    overlay.style.display = 'flex';
  }

  function fecharUploadModal(){
    const overlay = document.getElementById('uploadModalOverlay');
    const frame = document.getElementById('uploadFrame');
    overlay.style.display = 'none';
    frame.src = '';
  }
  

// ===== Aba 2: sugestão automática para "Nome do Campo" (placeholder) =====
document.addEventListener('DOMContentLoaded', function () {
  const tab = document.getElementById('tab-campos');
  if (!tab) return;

  const rotuloInput = tab.querySelector('input[name="novo_campo[rotulo]"]');
  const nomeInput = tab.querySelector('input[name="novo_campo[nome_campo]"]');
  if (!rotuloInput || !nomeInput) return;

  function slugFromRotulo(v){
    if (!v) return '';
    // normaliza acentos
    try { v = v.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch(e) {}
    v = v.replace(/[^A-Za-z0-9]+/g, '_').replace(/_+/g, '_').replace(/^_+|_+$/g, '');
    return v.toLowerCase();
  }

  function preencherSugestao(){
    if (nomeInput.value.trim() !== '') return; // só sugere se estiver vazio
    const s = slugFromRotulo(rotuloInput.value.trim());
    if (s) nomeInput.value = s;
  }

  rotuloInput.addEventListener('blur', preencherSugestao);
  rotuloInput.addEventListener('change', preencherSugestao);
});

</script>
</body>
</html>