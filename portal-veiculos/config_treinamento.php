<?php
// @versao Ver.001.011026
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';

session_start();
if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    exit('Acesso negado');
}

$mysqli->set_charset('utf8mb4');
$baseDir = __DIR__ . '/videos';
$baseUrl = '/Veiculos/videos';

function limpar_nome($s) {
    return str_replace(['..', '/', '\\'], '', trim($s));
}

// converte valores do php.ini ("8M", "2G") em bytes
function ini_bytes($v) {
    $v = trim((string)$v);
    if ($v === '') return 0;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return (int)$n;
}

// tipo do treinamento pela extensão; null = extensão bloqueada (executável no servidor)
function tipo_por_extensao($ext) {
    if (preg_match('/^(php\d*|phtml|phar|pht|phps|shtml|htaccess|htpasswd|cgi|pl|py|sh|asp|aspx|jsp|exe|bat|cmd)$/', $ext)) return null;
    if (in_array($ext, ['mp4','webm','ogg','mov','mkv','avi'], true))  return 'video';
    if ($ext === 'pdf')                                                return 'pdf';
    if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true))       return 'imagem';
    return 'arquivo';
}

// Upload em partes: cada parte fica abaixo de upload_max_filesize/post_max_size,
// então o tamanho total do arquivo não depende da configuração do servidor.
$tmpUploadDir = $baseDir . '/.tmp_upload';
$limiteServidor = min(
    ini_bytes(ini_get('upload_max_filesize')) ?: PHP_INT_MAX,
    ini_bytes(ini_get('post_max_size'))       ?: PHP_INT_MAX
);
$chunkSize = (int)max(256 * 1024, min(8 * 1024 * 1024, $limiteServidor - 64 * 1024));

$mensagem = '';
$msgTipo  = 'ok'; // ok | erro

// ══════════════════════════════════════════════════════════════════════════════
// AJAX – recebe uma parte do arquivo (upload em partes)
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'upload_chunk') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(0);

    $uid    = $_POST['upload_id'] ?? '';
    $offset = (int)($_POST['offset'] ?? -1);
    if (!preg_match('/^[a-f0-9]{32}$/', $uid) || $offset < 0) {
        echo json_encode(['ok' => false, 'erro' => 'Parâmetros inválidos.']);
        exit;
    }
    if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'erro' => 'Falha ao receber parte do arquivo (código ' . ($_FILES['chunk']['error'] ?? '?') . ').']);
        exit;
    }
    if (!is_dir($tmpUploadDir)) @mkdir($tmpUploadDir, 0775, true);

    // limpeza de envios abandonados (> 24h)
    if ($offset === 0) {
        foreach (glob($tmpUploadDir . '/*.part') ?: [] as $velho) {
            if (filemtime($velho) < time() - 86400) @unlink($velho);
        }
    }

    $part  = $tmpUploadDir . '/' . $uid . '.part';
    $len   = (int)$_FILES['chunk']['size'];
    clearstatcache();
    $atual = is_file($part) ? filesize($part) : 0;

    if ($atual === $offset + $len && $atual > 0) {
        // parte já gravada (reenvio após falha de rede na resposta)
        echo json_encode(['ok' => true, 'size' => $atual]);
        exit;
    }
    if ($offset !== 0 && $atual !== $offset) {
        echo json_encode(['ok' => false, 'erro' => 'Sequência de envio inconsistente.', 'size' => $atual]);
        exit;
    }

    $in  = fopen($_FILES['chunk']['tmp_name'], 'rb');
    $out = fopen($part, $offset === 0 ? 'wb' : 'ab');
    if (!$in || !$out) {
        echo json_encode(['ok' => false, 'erro' => 'Não foi possível gravar no servidor.']);
        exit;
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    clearstatcache();
    echo json_encode(['ok' => true, 'size' => filesize($part)]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// AJAX – retorna lista de treinamentos de um setor
// ══════════════════════════════════════════════════════════════════════════════
if (isset($_GET['ajax_treinamentos']) && isset($_GET['setor_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $sid  = (int)$_GET['setor_id'];
    $rows = [];
    $st   = $mysqli->prepare("
        SELECT id, subpasta, arquivo, titulo, tipo, url_externa, ordem
          FROM treinamentos
         WHERE treinamento_setor_id = ?
         ORDER BY subpasta, ordem, titulo
    ");
    $st->bind_param('i', $sid);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $st->close();
    echo json_encode($rows);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// POST – ações
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // ── Adicionar setor ──────────────────────────────────────────────────────
    if ($acao === 'add_setor') {
        $nome = limpar_nome($_POST['nome_setor'] ?? '');
        if ($nome !== '') {
            $ordem = 0;
            // cria pasta física (opcional, mas mantém compatibilidade)
            $dir = $baseDir . '/' . $nome;
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $st = $mysqli->prepare("INSERT INTO treinamento_setores (setor, pasta, ordem) VALUES (?,?,?)");
            $st->bind_param('ssi', $nome, $nome, $ordem);
            if ($st->execute()) {
                $mensagem = "Setor \"$nome\" criado com sucesso.";
            } else {
                $mensagem = "Erro: " . $st->error;
                $msgTipo  = 'erro';
            }
            $st->close();
        }
    }

    // ── Remover setor ────────────────────────────────────────────────────────
    if ($acao === 'del_setor') {
        $sid = (int)($_POST['setor_id'] ?? 0);
        if ($sid > 0) {
            // busca pasta para limpar disco
            $stP = $mysqli->prepare("SELECT pasta FROM treinamento_setores WHERE id=? LIMIT 1");
            $stP->bind_param('i', $sid);
            $stP->execute();
            $rowP = $stP->get_result()->fetch_assoc();
            $stP->close();

            if ($rowP) {
                // remove arquivos físicos de uploads do setor
                $stArq = $mysqli->prepare("SELECT arquivo, subpasta FROM treinamentos WHERE treinamento_setor_id=? AND url_externa IS NULL AND url_externa=''");
                $stArq->bind_param('i', $sid);
                $stArq->execute();
                $resArq = $stArq->get_result();
                while ($rA = $resArq->fetch_assoc()) {
                    $path = $baseDir . '/' . $rowP['pasta'] . '/' . $rA['subpasta'] . '/' . $rA['arquivo'];
                    if (is_file($path)) @unlink($path);
                }
                $stArq->close();
            }

            $mysqli->query("DELETE FROM usuario_treinamentos WHERE treinamento_id IN (SELECT id FROM treinamentos WHERE treinamento_setor_id=$sid)");
            $mysqli->query("DELETE FROM treinamentos WHERE treinamento_setor_id=$sid");
            $st = $mysqli->prepare("DELETE FROM treinamento_setores WHERE id=?");
            $st->bind_param('i', $sid);
            $st->execute();
            $st->close();
            $mensagem = "Setor removido.";
        }
    }

    // ── Adicionar treinamento (upload ou URL) ────────────────────────────────
    if ($acao === 'add_treinamento') {
        $sid    = (int)($_POST['setor_id']  ?? 0);
        $sub    = limpar_nome($_POST['subpasta'] ?? '');
        $titulo = trim($_POST['titulo'] ?? '');
        $url    = trim($_POST['url_externa'] ?? '');
        $ordem  = (int)($_POST['ordem'] ?? 0);

        if ($sid > 0 && $titulo !== '') {
            // busca pasta do setor
            $stP = $mysqli->prepare("SELECT pasta FROM treinamento_setores WHERE id=? LIMIT 1");
            $stP->bind_param('i', $sid);
            $stP->execute();
            $rowP = $stP->get_result()->fetch_assoc();
            $stP->close();

            $arquivo    = '';
            $tipo       = 'url';
            $urlFinal   = $url ?: null;

            $uploadId   = $_POST['upload_id'] ?? '';
            $viaPartes  = preg_match('/^[a-f0-9]{32}$/', $uploadId) === 1;
            $viaForm    = !empty($_FILES['arquivo']['name']);

            if ($viaForm && $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
                $mensagem = in_array($_FILES['arquivo']['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? "Arquivo maior que o limite do servidor (" . ini_get('upload_max_filesize') . ")."
                    : "Falha no envio do arquivo (código {$_FILES['arquivo']['error']}).";
                $msgTipo  = 'erro';
                goto fim_post;
            }

            if ($viaPartes || $viaForm) {
                // upload
                $nome = basename($viaPartes ? ($_POST['nome_arquivo'] ?? '') : $_FILES['arquivo']['name']);
                $nome = str_replace(['..', '/', '\\'], '', $nome);
                $ext  = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
                $tipo = tipo_por_extensao($ext);

                $origem = $viaPartes ? $tmpUploadDir . '/' . $uploadId . '.part' : $_FILES['arquivo']['tmp_name'];

                if ($nome === '' || $tipo === null) {
                    if ($viaPartes) @unlink($origem);
                    $mensagem = "Tipo de arquivo não permitido.";
                    $msgTipo  = 'erro';
                    goto fim_post;
                }

                $pastaSetor = $rowP ? $rowP['pasta'] : 'sem_setor';
                $dir = $baseDir . '/' . $pastaSetor . ($sub !== '' ? '/' . $sub : '');
                if (!is_dir($dir)) @mkdir($dir, 0775, true);

                $destino = $dir . '/' . $nome;
                $salvo = $viaPartes
                    ? (is_file($origem) && @rename($origem, $destino))
                    : move_uploaded_file($origem, $destino);
                if ($salvo) {
                    $arquivo  = $nome;
                    $urlFinal = null;
                } else {
                    if ($viaPartes) @unlink($origem);
                    $mensagem = "Erro ao salvar o arquivo no servidor.";
                    $msgTipo  = 'erro';
                    goto fim_post;
                }
            } elseif ($url !== '') {
                // detecta tipo pela URL
                if (preg_match('/youtube\.com|youtu\.be/i', $url)) $tipo = 'youtube';
                elseif (preg_match('/\.pdf$/i', $url)) $tipo = 'pdf';
                else $tipo = 'url';
            } else {
                $mensagem = "Informe um arquivo ou uma URL.";
                $msgTipo  = 'erro';
                goto fim_post;
            }

            $st = $mysqli->prepare("
                INSERT INTO treinamentos
                    (treinamento_setor_id, subpasta, arquivo, titulo, tipo, url_externa, ordem)
                VALUES (?,?,?,?,?,?,?)
            ");
            $st->bind_param('isssssi', $sid, $sub, $arquivo, $titulo, $tipo, $urlFinal, $ordem);
            if ($st->execute()) {
                $mensagem = "Treinamento \"$titulo\" adicionado.";
            } else {
                $mensagem = "Erro ao salvar no banco: " . $st->error;
                $msgTipo  = 'erro';
            }
            $st->close();
        } else {
            $mensagem = "Preencha ao menos o setor e o título.";
            $msgTipo  = 'erro';
        }
    }

    // ── Remover treinamento ──────────────────────────────────────────────────
    if ($acao === 'del_treinamento') {
        $tid = (int)($_POST['treinamento_id'] ?? 0);
        if ($tid > 0) {
            // busca dados para apagar arquivo físico
            $stF = $mysqli->prepare("
                SELECT t.arquivo, t.subpasta, ts.pasta
                  FROM treinamentos t
                  JOIN treinamento_setores ts ON ts.id = t.treinamento_setor_id
                 WHERE t.id = ? LIMIT 1
            ");
            $stF->bind_param('i', $tid);
            $stF->execute();
            $rowF = $stF->get_result()->fetch_assoc();
            $stF->close();

            if ($rowF && $rowF['arquivo'] !== '') {
                $path = $baseDir . '/' . $rowF['pasta'] . '/' . $rowF['subpasta'] . '/' . $rowF['arquivo'];
                if (is_file($path)) @unlink($path);
            }

            $stPerm = $mysqli->prepare("DELETE FROM usuario_treinamentos WHERE treinamento_id=?");
            $stPerm->bind_param('i', $tid);
            $stPerm->execute();
            $stPerm->close();

            $st = $mysqli->prepare("DELETE FROM treinamentos WHERE id=?");
            $st->bind_param('i', $tid);
            $st->execute();
            $st->close();
            $mensagem = "Treinamento removido.";
        }
    }

    // ── Editar título/ordem ──────────────────────────────────────────────────
    if ($acao === 'editar_treinamento') {
        $tid    = (int)($_POST['treinamento_id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $ordem  = (int)($_POST['ordem'] ?? 0);
        $sub    = limpar_nome($_POST['subpasta'] ?? '');
        if ($tid > 0 && $titulo !== '') {
            $st = $mysqli->prepare("UPDATE treinamentos SET titulo=?, ordem=?, subpasta=? WHERE id=?");
            $st->bind_param('sisi', $titulo, $ordem, $sub, $tid);
            $st->execute();
            $st->close();
            $mensagem = "Treinamento atualizado.";
        }
    }
}
fim_post:

// envio via JS (upload em partes) espera JSON em vez da página
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $msgTipo === 'ok', 'mensagem' => $mensagem]);
    exit;
}

// ── Carrega setores ──────────────────────────────────────────────────────────
$setores = [];
$res = $mysqli->query("SELECT id, setor, pasta, ordem FROM treinamento_setores ORDER BY ordem, setor");
if ($res) while ($r = $res->fetch_assoc()) $setores[] = $r;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Configurar Treinamentos</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --red: #c8102e;
    --bg: #f0f0f0;
    --card: #fff;
    --border: #e5e7eb;
    --muted: #6b7280;
    --radius: 12px;
}
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: system-ui, -apple-system, sans-serif;
    background: var(--bg);
    color: #222;
    font-size: .9rem;
    min-height: 100vh;
    padding: 24px 20px;
}

/* ── Cabeçalho ── */
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 10px;
}
.page-header h1 {
    font-size: 1.25rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}
.page-header h1 i {
    background: var(--red);
    color: #fff;
    width: 34px;
    height: 34px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .95rem;
    flex-shrink: 0;
}
.btn {
    padding: 7px 14px;
    border: none;
    border-radius: 8px;
    font-size: .82rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: opacity .15s;
}
.btn:hover { opacity: .85; }
.btn-red   { background: var(--red); color: #fff; }
.btn-gray  { background: #e5e7eb; color: #374151; }
.btn-ghost { background: transparent; color: var(--red); border: 1.5px solid var(--red); }
.btn-sm    { padding: 4px 9px; font-size: .78rem; }
.btn-danger { background: #fee2e2; color: #b91c1c; }

/* ── Mensagem ── */
.msg {
    padding: 8px 12px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-size: .85rem;
    display: flex;
    align-items: center;
    gap: 7px;
}
.msg.ok   { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
.msg.erro { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* ── Layout principal ── */
.layout {
    display: grid;
    grid-template-columns: 260px 1fr;
    gap: 16px;
    align-items: start;
}
@media (max-width: 700px) { .layout { grid-template-columns: 1fr; } }

/* ── Painel setores ── */
.setores-panel {
    background: var(--card);
    border-radius: var(--radius);
    box-shadow: 0 4px 16px rgba(0,0,0,.07);
    overflow: hidden;
}
.setores-header {
    padding: 12px 14px;
    font-size: .8rem;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    color: var(--muted);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.setor-item {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: background .12s;
}
.setor-item:last-child { border-bottom: none; }
.setor-item:hover { background: #fafafa; }
.setor-item.active { background: #fff5f5; border-left: 3px solid var(--red); }
.setor-item.active .setor-nome { color: var(--red); font-weight: 700; }
.setor-nome { font-size: .88rem; }
.setor-count { font-size: .75rem; color: var(--muted); background: #f3f4f6; padding: 2px 7px; border-radius: 99px; }
.setor-empty { padding: 16px 14px; font-size: .82rem; color: var(--muted); text-align: center; }

/* ── Formulário add setor ── */
.add-setor-form {
    padding: 10px 14px;
    border-top: 1px solid var(--border);
    display: flex;
    gap: 6px;
}
.add-setor-form input {
    flex: 1;
    padding: 5px 8px;
    border: 1.5px solid var(--border);
    border-radius: 7px;
    font-size: .82rem;
    outline: none;
    transition: border-color .15s;
}
.add-setor-form input:focus { border-color: var(--red); }

/* ── Painel treinamentos ── */
.trein-panel {
    background: var(--card);
    border-radius: var(--radius);
    box-shadow: 0 4px 16px rgba(0,0,0,.07);
    overflow: hidden;
    min-height: 300px;
}
.trein-header {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}
.trein-header-title { font-weight: 700; font-size: .95rem; }
.trein-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 24px;
    color: var(--muted);
    font-size: .85rem;
    gap: 8px;
    text-align: center;
}
.trein-empty i { font-size: 2rem; opacity: .3; }

/* ── Tabela de treinamentos ── */
.trein-table { width: 100%; border-collapse: collapse; }
.trein-table th {
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--muted);
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    background: #fafafa;
}
.trein-table td {
    padding: 9px 12px;
    border-bottom: 1px solid var(--border);
    font-size: .85rem;
    vertical-align: middle;
}
.trein-table tr:last-child td { border-bottom: none; }
.trein-table tr:hover td { background: #fafafa; }
.tipo-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 99px;
    font-size: .73rem;
    font-weight: 600;
}
.tipo-video   { background: #dbeafe; color: #1d4ed8; }
.tipo-pdf     { background: #fee2e2; color: #b91c1c; }
.tipo-youtube { background: #fef9c3; color: #92400e; }
.tipo-url     { background: #f3f4f6; color: #374151; }
.tipo-imagem  { background: #d1fae5; color: #065f46; }
.tipo-arquivo { background: #f3f4f6; color: #374151; }
.trein-acoes  { display: flex; gap: 6px; }

/* ── Modal ── */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-overlay.open { display: flex; }
.modal {
    background: var(--card);
    border-radius: var(--radius);
    width: 100%;
    max-width: 500px;
    box-shadow: 0 20px 60px rgba(0,0,0,.2);
    overflow: hidden;
}
.modal-header {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    font-weight: 700;
    font-size: .95rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.modal-body { padding: 16px 18px; display: flex; flex-direction: column; gap: 12px; }
.modal-footer {
    padding: 12px 18px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
.field label { display: block; font-size: .78rem; font-weight: 600; color: var(--muted); margin-bottom: 4px; }
.field input, .field select, .field textarea {
    width: 100%;
    padding: 7px 10px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-size: .85rem;
    outline: none;
    transition: border-color .15s;
    font-family: inherit;
}
.field input:focus, .field select:focus, .field textarea:focus { border-color: var(--red); }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.tabs-tipo { display: flex; gap: 6px; flex-wrap: wrap; }
.tab-tipo {
    padding: 5px 12px;
    border-radius: 99px;
    border: 1.5px solid var(--border);
    font-size: .78rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .12s;
    background: #fff;
    color: #374151;
}
.tab-tipo.active { border-color: var(--red); background: #fff5f5; color: var(--red); }

/* ── Modal editar ── */
#modal-editar .modal { max-width: 420px; }

/* ── Del setor confirm ── */
#modal-del-setor .modal { max-width: 380px; }

/* ── Loading ── */
.trein-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px;
    color: var(--muted);
    gap: 8px;
    font-size: .85rem;
}
.upload-progresso { margin-top: 4px; }
.upload-progresso-topo {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: .8rem;
    color: var(--muted);
    margin-bottom: 6px;
}
.upload-progresso-topo strong { color: #222; font-size: .85rem; }
.upload-barra {
    height: 10px;
    background: #f3f4f6;
    border: 1px solid var(--border);
    border-radius: 99px;
    overflow: hidden;
}
.upload-barra-fill {
    height: 100%;
    width: 0;
    background: var(--red);
    border-radius: 99px;
    transition: width .2s ease;
}
.upload-progresso.ok   .upload-barra-fill { background: #16a34a; }
.upload-progresso.erro .upload-barra-fill { background: #9ca3af; }
.upload-progresso.erro #upload-status    { color: #b91c1c; }
.upload-detalhe { font-size: .73rem; color: var(--muted); margin-top: 5px; }
.spin { animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>

<?php if ($mensagem): ?>
<div id="msg-global" class="msg <?= $msgTipo ?>">
    <i class="fas fa-<?= $msgTipo === 'ok' ? 'check-circle' : 'exclamation-circle' ?>"></i>
    <?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

<div class="page-header">
    <h1>
        <i class="fas fa-graduation-cap"></i>
        Gerenciar Treinamentos
    </h1>
    <button class="btn btn-gray" onclick="history.back()">
        <i class="fas fa-arrow-left"></i> Voltar
    </button>
</div>

<div class="layout">

    <!-- ── Coluna esquerda: setores ── -->
    <div class="setores-panel">
        <div class="setores-header">
            <span>Setores</span>
            <span style="font-size:.7rem;color:#bbb"><?= count($setores) ?> cadastrados</span>
        </div>

        <?php if (empty($setores)): ?>
            <div class="setor-empty">Nenhum setor cadastrado.</div>
        <?php else: ?>
            <?php foreach ($setores as $s): ?>
            <div class="setor-item" data-id="<?= $s['id'] ?>" data-nome="<?= htmlspecialchars($s['setor'], ENT_QUOTES) ?>" onclick="selecionarSetor(this)">
                <span class="setor-nome"><?= htmlspecialchars($s['setor'], ENT_QUOTES) ?></span>
                <span class="setor-count" id="count-<?= $s['id'] ?>">…</span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="post" class="add-setor-form">
            <input type="text" name="nome_setor" placeholder="Novo setor…" required>
            <button type="submit" name="acao" value="add_setor" class="btn btn-red btn-sm">
                <i class="fas fa-plus"></i>
            </button>
        </form>
    </div>

    <!-- ── Coluna direita: treinamentos do setor ── -->
    <div class="trein-panel" id="trein-panel">
        <div class="trein-header">
            <span class="trein-header-title" id="trein-header-title">Selecione um setor</span>
        </div>
        <div class="trein-empty" id="trein-placeholder">
            <i class="fas fa-hand-pointer"></i>
            <span>Clique em um setor para ver e gerenciar os treinamentos</span>
        </div>
        <div id="trein-content" style="display:none"></div>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════════════════
     Modal: Adicionar treinamento
══════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-add">
    <div class="modal">
        <div class="modal-header">
            <span><i class="fas fa-plus-circle" style="color:var(--red);margin-right:6px"></i>Novo Treinamento</span>
            <button class="btn btn-ghost btn-sm" onclick="fecharModal('modal-add')">✕</button>
        </div>
        <form method="post" enctype="multipart/form-data" id="form-add">
            <input type="hidden" name="acao" value="add_treinamento">
            <input type="hidden" name="setor_id" id="add-setor-id">
            <div class="modal-body">

                <div class="field">
                    <label>TÍTULO *</label>
                    <input type="text" name="titulo" placeholder="Ex.: Atendimento ao cliente" required>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>SUBPASTA / CATEGORIA</label>
                        <select id="add-subpasta-select" onchange="onSubpastaSelectChange('add')"></select>
                        <input type="text" id="add-subpasta-novo"
                               placeholder="Nome da nova pasta" autocomplete="off"
                               style="display:none;margin-top:6px" oninput="syncSubpastaHidden('add')">
                        <input type="hidden" name="subpasta" id="add-subpasta">
                    </div>
                    <div class="field">
                        <label>ORDEM</label>
                        <input type="number" name="ordem" value="0" min="0">
                    </div>
                </div>

                <div class="field">
                    <label>TIPO DE CONTEÚDO</label>
                    <div class="tabs-tipo">
                        <button type="button" class="tab-tipo active" data-tipo="upload" onclick="selecionarTipo(this)">
                            <i class="fas fa-upload"></i> Upload
                        </button>
                        <button type="button" class="tab-tipo" data-tipo="url" onclick="selecionarTipo(this)">
                            <i class="fas fa-link"></i> URL / YouTube
                        </button>
                    </div>
                </div>

                <div id="campo-upload" class="field">
                    <label>ARQUIVO (vídeo, PDF ou imagem)</label>
                    <input type="file" name="arquivo" id="add-arquivo" accept="video/*,.pdf,image/*">
                </div>

                <div id="upload-progresso" class="upload-progresso" style="display:none">
                    <div class="upload-progresso-topo">
                        <span id="upload-status"><i class="fas fa-circle-notch spin"></i> Enviando…</span>
                        <strong id="upload-pct">0%</strong>
                    </div>
                    <div class="upload-barra"><div class="upload-barra-fill" id="upload-barra-fill"></div></div>
                    <div class="upload-detalhe" id="upload-detalhe"></div>
                </div>

                <div id="campo-url" class="field" style="display:none">
                    <label>URL EXTERNA</label>
                    <input type="url" name="url_externa" placeholder="https://youtube.com/watch?v=...">
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-gray" id="add-btn-cancelar" onclick="fecharModal('modal-add')">Cancelar</button>
                <button type="submit" class="btn btn-red" id="add-btn-salvar"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     Modal: Editar treinamento
══════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-editar">
    <div class="modal">
        <div class="modal-header">
            <span><i class="fas fa-pen" style="color:var(--red);margin-right:6px"></i>Editar Treinamento</span>
            <button class="btn btn-ghost btn-sm" onclick="fecharModal('modal-editar')">✕</button>
        </div>
        <form method="post" id="form-editar">
            <input type="hidden" name="acao" value="editar_treinamento">
            <input type="hidden" name="treinamento_id" id="edit-id">
            <div class="modal-body">
                <div class="field">
                    <label>TÍTULO *</label>
                    <input type="text" name="titulo" id="edit-titulo" required>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>SUBPASTA / CATEGORIA</label>
                        <select id="edit-subpasta-select" onchange="onSubpastaSelectChange('edit')"></select>
                        <input type="text" id="edit-subpasta-novo"
                               placeholder="Nome da nova pasta" autocomplete="off"
                               style="display:none;margin-top:6px" oninput="syncSubpastaHidden('edit')">
                        <input type="hidden" name="subpasta" id="edit-subpasta">
                    </div>
                    <div class="field">
                        <label>ORDEM</label>
                        <input type="number" name="ordem" id="edit-ordem" min="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-gray" onclick="fecharModal('modal-editar')">Cancelar</button>
                <button type="submit" class="btn btn-red"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     Modal: Confirmar exclusão de setor
══════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-del-setor">
    <div class="modal">
        <div class="modal-header">
            <span><i class="fas fa-trash" style="color:#b91c1c;margin-right:6px"></i>Remover Setor</span>
            <button class="btn btn-ghost btn-sm" onclick="fecharModal('modal-del-setor')">✕</button>
        </div>
        <form method="post" id="form-del-setor">
            <input type="hidden" name="acao" value="del_setor">
            <input type="hidden" name="setor_id" id="del-setor-id">
            <div class="modal-body">
                <p style="font-size:.88rem;line-height:1.5">
                    Tem certeza que deseja remover o setor <strong id="del-setor-nome"></strong>?<br>
                    <span style="color:#b91c1c">Todos os treinamentos e arquivos vinculados serão excluídos.</span>
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-gray" onclick="fecharModal('modal-del-setor')">Cancelar</button>
                <button type="submit" class="btn btn-red"><i class="fas fa-trash"></i> Remover</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     Modal: Confirmar exclusão de treinamento
══════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-del-trein">
    <div class="modal" style="max-width:380px">
        <div class="modal-header">
            <span><i class="fas fa-trash" style="color:#b91c1c;margin-right:6px"></i>Remover Treinamento</span>
            <button class="btn btn-ghost btn-sm" onclick="fecharModal('modal-del-trein')">✕</button>
        </div>
        <form method="post" id="form-del-trein">
            <input type="hidden" name="acao" value="del_treinamento">
            <input type="hidden" name="treinamento_id" id="del-trein-id">
            <div class="modal-body">
                <p style="font-size:.88rem;line-height:1.5">
                    Remover <strong id="del-trein-titulo"></strong>?<br>
                    <span style="color:#b91c1c">O arquivo físico (se houver) também será excluído.</span>
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-gray" onclick="fecharModal('modal-del-trein')">Cancelar</button>
                <button type="submit" class="btn btn-red"><i class="fas fa-trash"></i> Remover</button>
            </div>
        </form>
    </div>
</div>

<script>
let setorAtualId   = null;
let setorAtualNome = '';
let uploadAtual    = null; // controle do envio em andamento (cancelar)

const CHUNK_SIZE = <?= (int)$chunkSize ?>;

// ── ícones por tipo ──────────────────────────────────────────────────────────
const tipoIcone = {
    video:   '<i class="fas fa-film"></i>',
    youtube: '<i class="fab fa-youtube"></i>',
    pdf:     '<i class="fas fa-file-pdf"></i>',
    imagem:  '<i class="fas fa-image"></i>',
    url:     '<i class="fas fa-link"></i>',
    arquivo: '<i class="fas fa-file"></i>',
};
const tipoCls = {
    video: 'tipo-video', youtube: 'tipo-youtube', pdf: 'tipo-pdf',
    imagem: 'tipo-imagem', url: 'tipo-url', arquivo: 'tipo-arquivo',
};

// ── Preenche select com subpastas únicas do setor ────────────────────────────
function preencherSelect(dados, selectId, selecionada) {
    const sel = document.getElementById(selectId);
    if (!sel) return;
    sel.innerHTML = '';

    const optVazia = document.createElement('option');
    optVazia.value = '';
    optVazia.textContent = '— Sem categoria —';
    sel.appendChild(optVazia);

    const unicas = [...new Set(
        dados.map(t => t.subpasta).filter(s => s && s.trim() !== '')
    )];
    unicas.forEach(sub => {
        const opt = document.createElement('option');
        opt.value = sub;
        opt.textContent = sub;
        sel.appendChild(opt);
    });

    const optNova = document.createElement('option');
    optNova.value = '__nova__';
    optNova.textContent = '+ Nova pasta...';
    sel.appendChild(optNova);

    if (selecionada && unicas.includes(selecionada)) {
        sel.value = selecionada;
    } else if (selecionada) {
        sel.value = '__nova__';
    } else {
        sel.value = '';
    }
    onSubpastaSelectChange(selectId.startsWith('edit') ? 'edit' : 'add', selecionada || '');
}

// ── Alterna entre select e campo de nova pasta ────────────────────────────────
function onSubpastaSelectChange(prefixo, valorInicialNova) {
    const sel    = document.getElementById(`${prefixo}-subpasta-select`);
    const campoN = document.getElementById(`${prefixo}-subpasta-novo`);
    const hidden = document.getElementById(`${prefixo}-subpasta`);

    if (sel.value === '__nova__') {
        campoN.style.display = 'block';
        campoN.value = valorInicialNova || campoN.value || '';
        hidden.value = campoN.value;
        campoN.focus();
    } else {
        campoN.style.display = 'none';
        campoN.value = '';
        hidden.value = sel.value;
    }
}

function syncSubpastaHidden(prefixo) {
    document.getElementById(`${prefixo}-subpasta`).value =
        document.getElementById(`${prefixo}-subpasta-novo`).value;
}

// ── Selecionar setor ─────────────────────────────────────────────────────────
function selecionarSetor(el) {
    document.querySelectorAll('.setor-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');
    setorAtualId   = el.dataset.id;
    setorAtualNome = el.dataset.nome;

    document.getElementById('trein-header-title').textContent = setorAtualNome;

    // mostra botões no header
    let hdr = document.querySelector('.trein-header');
    let btnArea = hdr.querySelector('.hdr-btns');
    if (!btnArea) {
        btnArea = document.createElement('div');
        btnArea.className = 'hdr-btns';
        btnArea.style.display = 'flex';
        btnArea.style.gap = '8px';
        btnArea.innerHTML = `
            <button class="btn btn-ghost btn-sm" onclick="confirmarDelSetor()">
                <i class="fas fa-trash"></i> Remover setor
            </button>
            <button class="btn btn-red btn-sm" onclick="abrirModalAdd()">
                <i class="fas fa-plus"></i> Novo treinamento
            </button>`;
        hdr.appendChild(btnArea);
    }

    carregarTreinamentos(setorAtualId);
}

// ── Carrega treinamentos via AJAX ────────────────────────────────────────────
function carregarTreinamentos(sid) {
    const content     = document.getElementById('trein-content');
    const placeholder = document.getElementById('trein-placeholder');

    placeholder.style.display = 'none';
    content.style.display     = 'block';
    content.innerHTML = `<div class="trein-loading"><i class="fas fa-circle-notch spin"></i> Carregando…</div>`;

    fetch(`config_treinamento.php?ajax_treinamentos=1&setor_id=${sid}`)
        .then(r => r.json())
        .then(dados => {
            // atualiza contador no menu lateral
            const countEl = document.getElementById(`count-${sid}`);
            if (countEl) countEl.textContent = dados.length;

            // atualiza selects com subpastas do setor carregado
            preencherSelect(dados, 'add-subpasta-select');
            preencherSelect(dados, 'edit-subpasta-select');

            if (dados.length === 0) {
                content.innerHTML = `
                    <div class="trein-empty">
                        <i class="fas fa-inbox"></i>
                        <span>Nenhum treinamento cadastrado neste setor.</span>
                        <button class="btn btn-red btn-sm" onclick="abrirModalAdd()">
                            <i class="fas fa-plus"></i> Adicionar primeiro
                        </button>
                    </div>`;
                return;
            }

            // agrupa por subpasta
            const grupos = {};
            dados.forEach(t => {
                const sub = t.subpasta || '—';
                if (!grupos[sub]) grupos[sub] = [];
                grupos[sub].push(t);
            });

            let html = '';
            Object.entries(grupos).forEach(([sub, itens]) => {
                html += `<div style="padding:8px 12px 4px;font-size:.75rem;font-weight:700;color:var(--muted);letter-spacing:.05em;text-transform:uppercase;border-bottom:1px solid var(--border);background:#fafafa">
                    <i class="fas fa-folder" style="margin-right:5px;color:#d1d5db"></i>${sub === '—' ? 'Sem categoria' : sub}
                    <span style="font-weight:400;margin-left:4px">(${itens.length})</span>
                </div>`;
                html += `<table class="trein-table">
                    <thead><tr>
                        <th>#</th><th>Título</th><th>Tipo</th><th>Arquivo / URL</th><th>Ações</th>
                    </tr></thead><tbody>`;
                itens.forEach(t => {
                    const icone = tipoIcone[t.tipo] || tipoIcone.arquivo;
                    const cls   = tipoCls[t.tipo] || 'tipo-arquivo';
                    const origem = t.url_externa
                        ? `<a href="${escHtml(t.url_externa)}" target="_blank" style="color:var(--red);font-size:.78rem">${escHtml(t.url_externa.substring(0,40))}…</a>`
                        : `<span style="color:var(--muted);font-size:.78rem">${escHtml(t.arquivo || '—')}</span>`;
                    html += `<tr>
                        <td style="color:var(--muted);font-size:.78rem">${t.ordem}</td>
                        <td><strong>${escHtml(t.titulo)}</strong></td>
                        <td><span class="tipo-badge ${cls}">${icone} ${t.tipo}</span></td>
                        <td>${origem}</td>
                        <td>
                            <div class="trein-acoes">
                                <button class="btn btn-gray btn-sm" onclick='abrirEditar(${JSON.stringify(t)})'>
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="confirmarDelTrein(${t.id},'${escHtml(t.titulo).replace(/'/g,"\\'")}')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>`;
                });
                html += `</tbody></table>`;
            });
            content.innerHTML = html;
        })
        .catch(() => {
            content.innerHTML = `<div class="trein-empty"><i class="fas fa-exclamation-triangle"></i><span>Erro ao carregar.</span></div>`;
        });
}

function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Modais genérico ──────────────────────────────────────────────────────────
function abrirModal(id)  { document.getElementById(id).classList.add('open'); }
function fecharModal(id) {
    if (id === 'modal-add' && uploadAtual) {
        if (!confirm('Cancelar o envio do arquivo?')) return;
        uploadAtual.cancelar();
    }
    document.getElementById(id).classList.remove('open');
}

document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) fecharModal(el.id); });
});

// ── Modal add treinamento ────────────────────────────────────────────────────
function abrirModalAdd() {
    document.getElementById('form-add').reset();
    resetarProgresso();
    document.getElementById('add-setor-id').value = setorAtualId;
    selecionarTipo(document.querySelector('.tab-tipo[data-tipo="upload"]'));

    // Preenche o select com subpastas já existentes no setor atual
    fetch(`config_treinamento.php?ajax_treinamentos=1&setor_id=${setorAtualId}`)
        .then(r => r.json())
        .then(dados => preencherSelect(dados, 'add-subpasta-select'))
        .catch(() => {});

    abrirModal('modal-add');
}

function selecionarTipo(btn) {
    document.querySelectorAll('.tab-tipo').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const tipo = btn.dataset.tipo;
    document.getElementById('campo-upload').style.display = tipo === 'upload' ? 'block' : 'none';
    document.getElementById('campo-url').style.display    = tipo === 'url'    ? 'block' : 'none';
}

// ── Upload em partes com barra de progresso ──────────────────────────────────
function formatarBytes(b) {
    if (b < 1024) return b + ' B';
    const u = ['KB', 'MB', 'GB'];
    let i = -1;
    do { b /= 1024; i++; } while (b >= 1024 && i < u.length - 1);
    return b.toFixed(b < 10 ? 1 : 0) + ' ' + u[i];
}

function formatarTempo(seg) {
    if (!isFinite(seg)) return '';
    seg = Math.ceil(seg);
    if (seg < 60) return seg + 's';
    const m = Math.floor(seg / 60), s = seg % 60;
    return m < 60 ? `${m}min ${s}s` : `${Math.floor(m / 60)}h ${m % 60}min`;
}

function resetarProgresso() {
    const box = document.getElementById('upload-progresso');
    box.style.display = 'none';
    box.className = 'upload-progresso';
    document.getElementById('upload-barra-fill').style.width = '0';
    document.getElementById('upload-pct').textContent = '0%';
    document.getElementById('upload-detalhe').textContent = '';
    document.getElementById('upload-status').innerHTML = '<i class="fas fa-circle-notch spin"></i> Enviando…';
    document.getElementById('add-btn-salvar').disabled = false;
    document.getElementById('add-btn-cancelar').textContent = 'Cancelar';
}

function atualizarProgresso(enviado, total, inicio) {
    const pct = total ? Math.min(100, Math.floor(enviado / total * 100)) : 100;
    const seg = (Date.now() - inicio) / 1000;
    const vel = seg > 0 ? enviado / seg : 0;
    const restante = vel > 0 ? (total - enviado) / vel : Infinity;
    document.getElementById('upload-barra-fill').style.width = pct + '%';
    document.getElementById('upload-pct').textContent = pct + '%';
    document.getElementById('upload-detalhe').textContent =
        `${formatarBytes(enviado)} de ${formatarBytes(total)}` +
        (vel > 0 ? ` · ${formatarBytes(vel)}/s` : '') +
        (enviado < total && isFinite(restante) ? ` · ~${formatarTempo(restante)} restantes` : '');
}

function gerarUploadId() {
    const a = new Uint8Array(16);
    crypto.getRandomValues(a);
    return Array.from(a, b => b.toString(16).padStart(2, '0')).join('');
}

function enviarParte(uploadId, blob, offset, onProgress, controle) {
    return new Promise((resolve, reject) => {
        const fd = new FormData();
        fd.append('acao', 'upload_chunk');
        fd.append('upload_id', uploadId);
        fd.append('offset', offset);
        fd.append('chunk', blob, 'chunk');

        const xhr = new XMLHttpRequest();
        controle.xhr = xhr;
        xhr.open('POST', 'config_treinamento.php');
        xhr.upload.onprogress = e => onProgress(e.loaded);
        xhr.onload = () => {
            try { resolve(JSON.parse(xhr.responseText)); }
            catch { reject(new Error(`Resposta inválida do servidor (HTTP ${xhr.status}).`)); }
        };
        xhr.onerror = () => reject(new Error('Falha de conexão com o servidor.'));
        xhr.onabort = () => reject(new Error('Envio cancelado.'));
        xhr.send(fd);
    });
}

async function enviarArquivoEmPartes(file, controle) {
    const uploadId = gerarUploadId();
    const inicio   = Date.now();
    let offset     = 0;
    let falhas     = 0;

    while (offset < file.size) {
        const blob = file.slice(offset, offset + CHUNK_SIZE);
        let res;
        try {
            res = await enviarParte(uploadId, blob, offset,
                loaded => atualizarProgresso(offset + loaded, file.size, inicio), controle);
        } catch (err) {
            if (controle.cancelado || ++falhas > 5) throw err;
            document.getElementById('upload-status').innerHTML =
                `<i class="fas fa-circle-notch spin"></i> Conexão instável, tentando novamente (${falhas}/5)…`;
            await new Promise(r => setTimeout(r, 2000 * falhas));
            continue;
        }
        if (!res.ok) {
            // servidor informa até onde recebeu: retoma a partir dali
            if (typeof res.size === 'number' && res.size <= file.size && ++falhas <= 5) {
                offset = res.size;
                continue;
            }
            throw new Error(res.erro || 'Erro ao enviar o arquivo.');
        }
        falhas = 0;
        offset = res.size;
        document.getElementById('upload-status').innerHTML = '<i class="fas fa-circle-notch spin"></i> Enviando…';
        atualizarProgresso(offset, file.size, inicio);
    }
    return uploadId;
}

function mostrarMensagem(texto, tipo) {
    document.getElementById('msg-global')?.remove();
    const div = document.createElement('div');
    div.id = 'msg-global';
    div.className = 'msg ' + tipo;
    div.innerHTML = `<i class="fas fa-${tipo === 'ok' ? 'check-circle' : 'exclamation-circle'}"></i> `;
    div.appendChild(document.createTextNode(texto));
    document.body.prepend(div);
    setTimeout(() => div.remove(), 4000);
}

document.getElementById('form-add').addEventListener('submit', async e => {
    const form  = e.target;
    const input = document.getElementById('add-arquivo');
    const tipo  = document.querySelector('.tab-tipo.active')?.dataset.tipo;

    if (tipo !== 'upload') { input.value = ''; return; } // URL: envio normal do formulário
    const file = input.files[0];
    if (!file) return; // o servidor responde pedindo arquivo ou URL

    e.preventDefault();
    if (uploadAtual) return;
    if (file.size === 0) { alert('O arquivo selecionado está vazio.'); return; }

    const controle = {
        cancelado: false, xhr: null,
        cancelar() { this.cancelado = true; if (this.xhr) this.xhr.abort(); }
    };
    uploadAtual = controle;

    const box = document.getElementById('upload-progresso');
    box.className = 'upload-progresso';
    box.style.display = 'block';
    document.getElementById('add-btn-salvar').disabled = true;
    document.getElementById('add-btn-cancelar').textContent = 'Cancelar envio';
    atualizarProgresso(0, file.size, Date.now());

    try {
        const uploadId = await enviarArquivoEmPartes(file, controle);

        document.getElementById('upload-status').innerHTML = '<i class="fas fa-circle-notch spin"></i> Finalizando…';
        const fd = new FormData(form);
        fd.delete('arquivo');
        fd.append('upload_id', uploadId);
        fd.append('nome_arquivo', file.name);
        fd.append('ajax', '1');
        const resp = await fetch('config_treinamento.php', { method: 'POST', body: fd });
        const j = await resp.json().catch(() => ({ ok: false, mensagem: `Resposta inválida do servidor (HTTP ${resp.status}).` }));
        if (!j.ok) throw new Error(j.mensagem || 'Erro ao salvar o treinamento.');

        box.classList.add('ok');
        document.getElementById('upload-status').innerHTML = '<i class="fas fa-check-circle"></i> Concluído';
        uploadAtual = null;
        fecharModal('modal-add');
        mostrarMensagem(j.mensagem, 'ok');
        carregarTreinamentos(setorAtualId);
    } catch (err) {
        uploadAtual = null;
        if (controle.cancelado) { resetarProgresso(); return; }
        box.classList.add('erro');
        document.getElementById('upload-status').innerHTML = '<i class="fas fa-exclamation-circle"></i> ';
        document.getElementById('upload-status').appendChild(document.createTextNode(err.message));
        document.getElementById('add-btn-salvar').disabled = false;
        document.getElementById('add-btn-cancelar').textContent = 'Cancelar';
    }
});

window.addEventListener('beforeunload', e => {
    if (uploadAtual) { e.preventDefault(); e.returnValue = ''; }
});

// ── Modal editar ─────────────────────────────────────────────────────────────
function abrirEditar(t) {
    document.getElementById('edit-id').value       = t.id;
    document.getElementById('edit-titulo').value   = t.titulo;
    document.getElementById('edit-subpasta').value = t.subpasta || '';
    document.getElementById('edit-ordem').value    = t.ordem;

    // Preenche o select de edição com subpastas do setor atual, pré-selecionando a atual
    fetch(`config_treinamento.php?ajax_treinamentos=1&setor_id=${setorAtualId}`)
        .then(r => r.json())
        .then(dados => preencherSelect(dados, 'edit-subpasta-select', t.subpasta || ''))
        .catch(() => {});

    abrirModal('modal-editar');
}

// ── Confirmar del setor ──────────────────────────────────────────────────────
function confirmarDelSetor() {
    document.getElementById('del-setor-id').value        = setorAtualId;
    document.getElementById('del-setor-nome').textContent = setorAtualNome;
    abrirModal('modal-del-setor');
}

// ── Confirmar del treinamento ────────────────────────────────────────────────
function confirmarDelTrein(id, titulo) {
    document.getElementById('del-trein-id').value           = id;
    document.getElementById('del-trein-titulo').textContent  = titulo;
    abrirModal('modal-del-trein');
}

// ── Auto-dismiss mensagem ────────────────────────────────────────────────────
const msgEl = document.getElementById('msg-global');
if (msgEl) setTimeout(() => msgEl.style.display = 'none', 4000);

// ── Carrega contadores iniciais ──────────────────────────────────────────────
document.querySelectorAll('.setor-item').forEach(el => {
    fetch(`config_treinamento.php?ajax_treinamentos=1&setor_id=${el.dataset.id}`)
        .then(r => r.json())
        .then(d => {
            const c = document.getElementById(`count-${el.dataset.id}`);
            if (c) c.textContent = d.length;
        }).catch(() => {});
});

<?php if (!empty($setores)): ?>
// seleciona o primeiro setor automaticamente
selecionarSetor(document.querySelector('.setor-item'));
<?php endif; ?>
</script>
</body>
</html>