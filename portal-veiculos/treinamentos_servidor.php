<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';

session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

$is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;

$setorUsuarioRaw = $_SESSION['setor'] ?? '';
$setoresSessao   = [];

if ($setorUsuarioRaw !== '') {
    foreach (explode(',', $setorUsuarioRaw) as $parte) {
        $parte = trim($parte);
        if ($parte === '') continue;
        $parte = strtoupper(str_replace('_', ' ', $parte));
        $setoresSessao[] = $parte;
    }
}

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

$progressoPorTrein = [];
if ($usuarioId > 0) {
    $sqlProg = "SELECT treinamento_id, status, progresso FROM treinamento_progresso WHERE usuario_id = ?";
    $stmtProg = $mysqli->prepare($sqlProg);
    $stmtProg->bind_param('i', $usuarioId);
    $stmtProg->execute();
    $resProg = $stmtProg->get_result();
    while ($rowP = $resProg->fetch_assoc()) {
        $tid = (int)$rowP['treinamento_id'];
        $progressoPorTrein[$tid] = [
            'status'    => $rowP['status'],
            'progresso' => (int)$rowP['progresso'],
        ];
    }
    $stmtProg->close();
}

$baseDir = __DIR__ . '/videos';
$baseUrl = '/Veiculos/videos';

$grupos = [];

$treinamentosPermitidos = null; // null = admin, vê tudo

if (!$is_admin) {
    $treinamentosPermitidos = [];
    if ($usuarioId > 0) {
        $stmtUt = $mysqli->prepare("SELECT treinamento_id FROM usuario_treinamentos WHERE usuario_id = ?");
        $stmtUt->bind_param('i', $usuarioId);
        $stmtUt->execute();
        $resUt = $stmtUt->get_result();
        while ($rowUt = $resUt->fetch_assoc()) {
            $treinamentosPermitidos[] = (int)$rowUt['treinamento_id'];
        }
        $stmtUt->close();
    }
}

$sqlSetores = "SELECT ts.id, ts.setor, ts.pasta, ts.ordem FROM treinamento_setores ts ORDER BY ts.ordem, ts.setor";
$resSetores = $mysqli->query($sqlSetores);

while ($rowS = $resSetores->fetch_assoc()) {
    $treinamento_setor_id = (int)$rowS['id'];
    $setorNomePasta       = $rowS['pasta'];
    $setorBanco           = $rowS['setor'] ?: $setorNomePasta;

    $grupo = [
        'titulo'            => $setorBanco,
        'pasta'             => $setorNomePasta,
        'descricao'         => 'Treinamentos do setor ' . $setorBanco,
        'subpastas'         => [],
        'duracao_total_seg' => 0,
    ];

    $stmtT = $mysqli->prepare("SELECT id, subpasta, arquivo, titulo, tipo, ordem FROM treinamentos WHERE treinamento_setor_id = ? ORDER BY ordem, id");
    $stmtT->bind_param('i', $treinamento_setor_id);
    $stmtT->execute();
    $resT = $stmtT->get_result();

    while ($rowT = $resT->fetch_assoc()) {
        $treinamento_id = (int)$rowT['id'];

        if ($treinamentosPermitidos !== null && !in_array($treinamento_id, $treinamentosPermitidos, true)) {
            continue;
        }

        $subEntry       = $rowT['subpasta'];
        $file           = $rowT['arquivo'];
        $tipo           = $rowT['tipo'] ?? '';

        if (!isset($grupo['subpastas'][$subEntry])) {
            $grupo['subpastas'][$subEntry] = [];
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $url = $baseUrl . '/' . rawurlencode($setorNomePasta) . '/' . rawurlencode($subEntry) . '/' . rawurlencode($file);

        $videoExts = ['mp4','webm','ogg','mov','mkv','avi'];
        $pdfExts   = ['pdf'];

        if ($tipo === '' || $tipo === null) {
            if (in_array($ext, $videoExts, true))     $tipo = 'video';
            elseif (in_array($ext, $pdfExts, true))   $tipo = 'pdf';
            else                                        $tipo = 'arquivo';
        }

        $duracaoSegundos = ($tipo === 'video') ? 300 : 0;
        $progInfo   = $progressoPorTrein[$treinamento_id] ?? null;
        $statusItem = $progInfo['status'] ?? 'nao_iniciado';
        $progItem   = $progInfo['progresso'] ?? 0;

        $grupo['subpastas'][$subEntry][] = [
            'titulo'           => $rowT['titulo'],
            'url'              => $url,
            'duracao_segundos' => $duracaoSegundos,
            'tipo'             => $tipo,
            'extensao'         => $ext,
            'treinamento_id'   => $treinamento_id,
            'status'           => $statusItem,
            'progresso'        => $progItem,
            'ordem'            => (int)$rowT['ordem'],
        ];
    }
    $stmtT->close();

    $temItens = false;
    foreach ($grupo['subpastas'] as $videosSub) {
        if (!empty($videosSub)) { $temItens = true; break; }
    }

    if ($temItens) {
        $totalSeg = 0;
        foreach ($grupo['subpastas'] as $videosSub) {
            foreach ($videosSub as $it) { $totalSeg += (int)($it['duracao_segundos'] ?? 0); }
        }
        $grupo['duracao_total_seg'] = $totalSeg;
        $grupos[] = $grupo;
    }
}

function formatar_duracao($seg){
    $seg = (int)$seg;
    $h = intdiv($seg, 3600);
    $m = intdiv($seg % 3600, 60);
    $s = $seg % 60;
    return $h > 0 ? sprintf('%02d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
}
?>
<style>
/* ── BASE ── */
.trein-wrap *,
.trein-wrap *::before,
.trein-wrap *::after { box-sizing: border-box; }

.trein-wrap {
    --red:      #c8102e;
    --red-dark: #a00d24;
    --dark:     #222;
    --mid:      #555;
    --soft:     #999;
    --bg:       #f0f0f0;
    --white:    #ffffff;
    --border:   #ddd;
    --radius:   999px;
    --radius-card: 18px;
    --shadow:   0 2px 12px rgba(0,0,0,0.09);

    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    font-size: 14px;
    color: var(--dark);
    background: var(--bg);
    padding: 24px 16px 40px;
    min-height: 100vh;
}

.trein-container {
    background: var(--white);
    border-radius: var(--radius-card);
    box-shadow: var(--shadow);
    max-width: 680px;
    margin: 0 auto;
    padding: 0 0 24px;
    overflow: hidden;
}

.trein-card-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 22px 24px 20px;
    border-bottom: 1px solid #f0f0f0;
}
.trein-card-icon {
    width: 44px; height: 44px;
    background: var(--red);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    color: #fff;
    font-size: 1.1rem;
    flex-shrink: 0;
}
.trein-card-title {
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--dark);
    margin: 0;
}

.trein-body {
    padding: 18px 24px 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.grupo-card {
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    background: #fafafa;
}
.grupo-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    cursor: pointer;
    user-select: none;
    background: #fafafa;
    gap: 10px;
}
.grupo-head-left {
    display: flex;
    align-items: center;
    gap: 10px;
}
.grupo-icon {
    width: 32px; height: 32px;
    background: #f0f0f0;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem;
    color: var(--red);
    flex-shrink: 0;
}
.grupo-titulo {
    font-size: .92rem;
    font-weight: 600;
    color: var(--dark);
}
.grupo-head-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.grupo-pill {
    font-size: .7rem;
    font-weight: 600;
    padding: 3px 10px;
    border-radius: var(--radius);
    background: #ececec;
    color: var(--mid);
    white-space: nowrap;
}
.grupo-pill.progresso-ok { background: #e6f4ea; color: #2e7d32; }
.grupo-seta {
    font-size: .65rem;
    color: var(--soft);
    transition: transform .2s;
}
.grupo-card.aberto .grupo-seta { transform: rotate(180deg); }

.grupo-progress-bar { height: 2px; background: #eee; }
.grupo-progress-fill { height: 100%; background: var(--red); transition: width .4s; width: 0%; }

.grupo-itens { display: none; }
.grupo-card.aberto .grupo-itens { display: block; }

.subpasta-bloco { border-top: 1px solid #ebebeb; }
.subpasta-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 9px 16px;
    cursor: pointer;
    background: #f5f5f5;
    user-select: none;
}
.subpasta-titulo {
    font-size: .75rem;
    font-weight: 700;
    color: var(--mid);
    text-transform: uppercase;
    letter-spacing: .05em;
}
.subpasta-seta { font-size: .65rem; color: var(--soft); transition: transform .2s; }
.subpasta-bloco.aberto .subpasta-seta { transform: rotate(180deg); }
.subpasta-itens { display: none; padding: 8px 12px 10px; flex-direction: column; gap: 6px; }
.subpasta-bloco.aberto .subpasta-itens { display: flex; }

.item-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border-radius: 10px;
    background: var(--white);
    border: 1px solid #e8e8e8;
    cursor: pointer;
    transition: background .13s, border-color .13s;
}
.item-card:hover {
    background: #fff5f6;
    border-color: rgba(200,16,46,0.25);
}
.item-thumb {
    width: 36px; height: 36px;
    border-radius: 8px;
    background: var(--red);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: .7rem;
    font-weight: 700;
    flex-shrink: 0;
}
.item-thumb.pdf { background: #c62828; }
.item-thumb.arq { background: #546e7a; }
.item-info { flex: 1; min-width: 0; }
.item-info-titulo {
    font-size: .85rem;
    font-weight: 500;
    color: var(--dark);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.item-info-desc { font-size: .72rem; color: var(--soft); margin-top: 1px; }
.item-visto {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: .72rem;
    color: var(--soft);
    flex-shrink: 0;
}
.item-visto input[type="checkbox"] { accent-color: var(--red); width: 14px; height: 14px; cursor: pointer; }
.item-visto input:checked + span { color: #2e7d32; font-weight: 600; }

.trein-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--soft);
    font-size: .9rem;
}

.app-version-label-trein {
    font-size: 10px;
    color: #ccc;
    text-align: right;
    padding: 0 24px;
    margin-top: 14px;
}

/* ── MODAL VÍDEO ── */
.video-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.65);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}
.video-modal {
    width: 90%;
    max-width: 900px;
    border-radius: 14px;
    overflow: hidden;
    background: #000;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
}
.video-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 16px;
    background: #111;
    color: #fff;
    font-size: .85rem;
    font-weight: 600;
}
.video-modal-close {
    cursor: pointer;
    width: 26px; height: 26px;
    border-radius: 50%;
    background: rgba(255,255,255,0.12);
    display: flex; align-items: center; justify-content: center;
    font-size: .9rem;
    transition: background .15s;
}
.video-modal-close:hover { background: var(--red); }
.video-modal-body video { width: 100%; display: block; }

/* ══════════════════════════════════════
   PDF MODAL — novo viewer
══════════════════════════════════════ */
#pdf-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.65);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    padding: 16px;
}
#pdf-modal-overlay.ativo { display: flex; }

.pdf-modal {
    width: 100%;
    max-width: 860px;
    max-height: 92dvh;
    border-radius: 16px;
    background: #1a1a1a;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 32px 80px rgba(0,0,0,.55);
}

.pdf-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: #111;
    gap: 12px;
    flex-shrink: 0;
}
.pdf-modal-title {
    font-size: .85rem;
    font-weight: 600;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pdf-modal-actions { display: flex; gap: 6px; flex-shrink: 0; }
.pdf-icon-btn {
    width: 30px; height: 30px;
    border-radius: 8px;
    border: none;
    background: rgba(255,255,255,.08);
    color: #ccc;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s, color .15s;
    text-decoration: none;
}
.pdf-icon-btn:hover { background: rgba(255,255,255,.18); color: #fff; }
.pdf-icon-btn svg { width: 16px; height: 16px; }

.pdf-toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 16px;
    background: #222;
    border-bottom: 1px solid rgba(255,255,255,.06);
    flex-shrink: 0;
    flex-wrap: wrap;
}
.pdf-nav, .pdf-zoom { display: flex; align-items: center; gap: 4px; }
.pdf-btn {
    width: 30px; height: 30px;
    border-radius: 8px;
    border: none;
    background: rgba(255,255,255,.07);
    color: #ccc;
    cursor: pointer;
    font-size: .95rem;
    font-weight: 600;
    display: flex; align-items: center; justify-content: center;
    transition: background .13s;
    line-height: 1;
}
.pdf-btn:hover { background: rgba(255,255,255,.17); color: #fff; }
.pdf-btn:disabled { opacity: .3; cursor: default; pointer-events: none; }
.pdf-btn svg { width: 14px; height: 14px; }

.pdf-paginfo {
    font-size: .8rem;
    color: #ccc;
    min-width: 52px;
    text-align: center;
}
.pdf-pag-sep { margin: 0 2px; color: #555; }
#pdf-zoom-label { font-size: .8rem; color: #ccc; min-width: 36px; text-align: center; }

.pdf-progress-wrap { display: flex; align-items: center; gap: 7px; margin-left: auto; }
.pdf-progress-bar {
    width: 100px; height: 4px;
    border-radius: 99px;
    background: rgba(255,255,255,.1);
    overflow: hidden;
}
.pdf-progress-fill {
    height: 100%;
    background: #c8102e;
    border-radius: 99px;
    transition: width .3s ease;
    width: 0%;
}
.pdf-progress-txt { font-size: .72rem; color: #888; min-width: 30px; }

.pdf-canvas-wrap {
    flex: 1;
    overflow: auto;
    background: #2d2d2d;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 20px;
    position: relative;
    min-height: 200px;
}
#pdf-canvas {
    border-radius: 4px;
    box-shadow: 0 4px 24px rgba(0,0,0,.4);
    max-width: 100%;
}

.pdf-spinner {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #2d2d2d;
    z-index: 2;
}
.pdf-spinner.oculto { display: none; }
.pdf-spin-ring {
    width: 36px; height: 36px;
    border: 3px solid rgba(255,255,255,.1);
    border-top-color: #c8102e;
    border-radius: 50%;
    animation: pdfSpin .75s linear infinite;
}
@keyframes pdfSpin { to { transform: rotate(360deg); } }

.pdf-footer {
    padding: 8px 16px;
    background: #111;
    display: flex;
    justify-content: center;
    flex-shrink: 0;
}
.pdf-concluido-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: .78rem;
    font-weight: 600;
    color: #4caf50;
    background: rgba(76,175,80,.12);
    padding: 5px 14px;
    border-radius: 99px;
    border: 1px solid rgba(76,175,80,.25);
}

@media (max-width: 480px) {
    .pdf-progress-wrap { display: none; }
    .pdf-toolbar { gap: 8px; }
}
</style>

<div class="trein-wrap">
    <div class="trein-container">

        <div class="trein-card-header">
            <div class="trein-card-icon">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <h2 class="trein-card-title">Treinamentos</h2>
        </div>

        <div class="trein-body">

        <?php if (empty($grupos)): ?>
            <div class="trein-empty">Nenhum treinamento disponível para o seu setor.</div>
        <?php else: ?>
            <?php foreach ($grupos as $idxGrupo => $grupo): ?>
                <div class="grupo-card" data-grupo-index="<?= $idxGrupo ?>">

                    <div class="grupo-progress-bar">
                        <div class="grupo-progress-fill" data-fill></div>
                    </div>

                    <div class="grupo-head grupo-toggle">
                        <div class="grupo-head-left">
                            <div class="grupo-icon"><i class="fas fa-folder"></i></div>
                            <div class="grupo-titulo">
                                <?= htmlspecialchars($grupo['titulo'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                        <div class="grupo-head-right">
                            <span class="grupo-pill grupo-progresso-texto" data-total="0" data-concluidos="0">
                                0% concluído
                            </span>
                            <span class="grupo-seta">▼</span>
                        </div>
                    </div>

                    <div class="grupo-itens">
                        <?php foreach ($grupo['subpastas'] as $nomeSubpasta => $videosSub): ?>
                            <?php if (empty($videosSub)) continue; ?>
                            <div class="subpasta-bloco">
                                <div class="subpasta-header">
                                    <span class="subpasta-titulo">
                                        <?= htmlspecialchars($nomeSubpasta, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <span class="subpasta-seta">▼</span>
                                </div>
                                <div class="subpasta-itens">
                                    <?php foreach ($videosSub as $it):
                                        $durItem     = formatar_duracao($it['duracao_segundos'] ?? 0);
                                        $tipo        = $it['tipo'] ?? 'arquivo';
                                        $treinId     = (int)($it['treinamento_id'] ?? 0);
                                        $statusItem  = $it['status'] ?? 'nao_iniciado';
                                        $jaConcluido = ($statusItem === 'concluido');
                                        $extLabel    = strtoupper($it['extensao'] ?? '');
                                        $thumbClass  = $tipo === 'pdf' ? 'pdf' : ($tipo !== 'video' ? 'arq' : '');
                                    ?>
                                    <div class="item-card"
                                         data-url="<?= htmlspecialchars($it['url'], ENT_QUOTES, 'UTF-8') ?>"
                                         data-titulo="<?= htmlspecialchars($it['titulo'], ENT_QUOTES, 'UTF-8') ?>"
                                         data-tipo="<?= htmlspecialchars($tipo, ENT_QUOTES, 'UTF-8') ?>"
                                         data-treinamento-id="<?= $treinId ?>">
                                        <div class="item-thumb <?= $thumbClass ?>">
                                            <?php
                                            if ($tipo === 'pdf')       echo 'PDF';
                                            elseif ($tipo === 'video') echo '▶';
                                            else                        echo ($extLabel ?: 'ARQ');
                                            ?>
                                        </div>
                                        <div class="item-info">
                                            <div class="item-info-titulo">
                                                <?= htmlspecialchars($it['titulo'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <div class="item-info-desc">
                                                <?php if ($tipo === 'pdf'): ?>
                                                    Arquivo PDF
                                                <?php elseif ($tipo === 'video'): ?>
                                                    <?= htmlspecialchars($durItem, ENT_QUOTES, 'UTF-8') ?>
                                                <?php else: ?>
                                                    Arquivo <?= htmlspecialchars($extLabel ?: 'genérico', ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="item-visto">
                                            <input type="checkbox"
                                                   class="item-checkbox"
                                                   <?= $jaConcluido ? 'checked' : '' ?>
                                                   <?= $jaConcluido ? '' : 'disabled' ?>>
                                            <span>Visto</span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        </div><!-- /trein-body -->

        <div class="app-version-label-trein">
            <?= VERSAO_TREINAMENTO_SERVIDOR ?>
        </div>

    </div><!-- /trein-container -->
</div><!-- /trein-wrap -->

<!-- ══════════ MODAL VÍDEO (original) ══════════ -->
<div class="video-modal-backdrop" id="videoBackdrop" onclick="fecharVideoBackdrop()">
    <div class="video-modal" onclick="event.stopPropagation()">
        <div class="video-modal-header">
            <span id="videoTituloModal">Vídeo</span>
            <span class="video-modal-close" onclick="fecharVideo()">✕</span>
        </div>
        <div class="video-modal-body">
            <video id="videoPlayer" controls playsinline></video>
            <iframe id="videoIframe" style="display:none;width:100%;height:100%;border:none;" allowfullscreen allow="autoplay; encrypted-media"></iframe>
            <img id="videoImagem" style="display:none;max-width:100%;max-height:100%;object-fit:contain;border-radius:8px;" alt="">
        </div>
    </div>
</div>

<!-- ══════════ MODAL PDF (novo viewer) ══════════ -->
<div id="pdf-modal-overlay" onclick="fecharPdfModal(event)">
    <div class="pdf-modal" onclick="event.stopPropagation()">

        <div class="pdf-modal-header">
            <div class="pdf-modal-title" id="pdf-modal-titulo">Documento</div>
            <div class="pdf-modal-actions">
                <a id="pdf-btn-download" href="#" download class="pdf-icon-btn" title="Download">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M10 3v10M6 9l4 4 4-4M4 16h12"/>
                    </svg>
                </a>
                <button class="pdf-icon-btn" onclick="fecharPdfModal()" title="Fechar (Esc)">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M5 5l10 10M15 5L5 15"/>
                    </svg>
                </button>
            </div>
        </div>

        <div class="pdf-toolbar">
            <div class="pdf-nav">
                <button class="pdf-btn" id="pdf-btn-prev" onclick="mudarPagina(-1)" title="Anterior (←)">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M13 4l-7 6 7 6"/>
                    </svg>
                </button>
                <span class="pdf-paginfo">
                    <span id="pdf-pagina-atual">1</span>
                    <span class="pdf-pag-sep">/</span>
                    <span id="pdf-pagina-total">–</span>
                </span>
                <button class="pdf-btn" id="pdf-btn-next" onclick="mudarPagina(1)" title="Próxima (→)">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M7 4l7 6-7 6"/>
                    </svg>
                </button>
            </div>
            <div class="pdf-zoom">
                <button class="pdf-btn" onclick="ajustarZoom(-0.2)" title="Reduzir (-)">−</button>
                <span id="pdf-zoom-label">100%</span>
                <button class="pdf-btn" onclick="ajustarZoom(0.2)" title="Aumentar (+)">+</button>
            </div>
            <div class="pdf-progress-wrap" title="Páginas visitadas">
                <div class="pdf-progress-bar">
                    <div class="pdf-progress-fill" id="pdf-progress-fill"></div>
                </div>
                <span class="pdf-progress-txt" id="pdf-progress-txt">0%</span>
            </div>
        </div>

        <div class="pdf-canvas-wrap" id="pdf-canvas-wrap">
            <div class="pdf-spinner" id="pdf-spinner">
                <div class="pdf-spin-ring"></div>
            </div>
            <canvas id="pdf-canvas"></canvas>
        </div>

        <div class="pdf-footer" id="pdf-footer" style="display:none;">
            <span class="pdf-concluido-badge">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" width="14" height="14">
                    <path d="M4 10l5 5L16 5"/>
                </svg>
                Leitura concluída — treinamento registrado
            </span>
        </div>

    </div>
</div>

<script>
/* ════════════════════════════════════════════════════
   FUNÇÕES ORIGINAIS (vídeo)
════════════════════════════════════════════════════ */
function registrarConclusao(cardElement) {
    const id = parseInt(cardElement.dataset.treinamentoId || '0', 10);
    if (!id) return;
    fetch('treinamentos_registrar_progresso.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ treinamento_id: id, status: 'concluido', progresso: 100 })
    }).catch(() => {});
}

function abrirVideo(url, titulo) {
    const backdrop = document.getElementById('videoBackdrop');
    const player   = document.getElementById('videoPlayer');
    const iframe   = document.getElementById('videoIframe');
    const imagem   = document.getElementById('videoImagem');
    const tituloEl = document.getElementById('videoTituloModal');
    if (!backdrop || !tituloEl) return;

    tituloEl.textContent = titulo || '';

    const isYoutube = /youtube\.com\/embed\/|youtu\.be/.test(url);
    const isImagem  = /\.(jpg|jpeg|png|gif|webp)(\?|$)/i.test(url);

    player.style.display = 'none'; player.pause(); player.src = '';
    iframe.style.display = 'none'; iframe.src = '';
    imagem.style.display = 'none'; imagem.src = '';

    if (isImagem) {
        imagem.src = url;
        imagem.style.display = 'block';
    } else if (isYoutube) {
        iframe.src = url + (url.includes('?') ? '&' : '?') + 'autoplay=1';
        iframe.style.display = 'block';
    } else {
        player.style.display = 'block';
        player.src = url;
        player.currentTime = 0;
        player.play();
    }

    backdrop.style.display = 'flex';
}
function fecharVideo() {
    const backdrop = document.getElementById('videoBackdrop');
    const player   = document.getElementById('videoPlayer');
    const iframe   = document.getElementById('videoIframe');
    const imagem   = document.getElementById('videoImagem');
    if (!backdrop) return;
    player.pause(); player.src = '';
    iframe.src = ''; iframe.style.display = 'none';
    imagem.src = ''; imagem.style.display = 'none';
    player.style.display = 'none';
    backdrop.style.display = 'none';
}
function fecharVideoBackdrop() { fecharVideo(); }

(function configurarPlayerVideo() {
    const player = document.getElementById('videoPlayer');
    if (!player) return;
    let liberadoParaEsteVideo = false;
    let maxTempoAssistido = 0;
    let travandoSeek  = false;
    let ultimoCheck   = 0;

    player.addEventListener('loadedmetadata', () => {
        liberadoParaEsteVideo = false;
        maxTempoAssistido = 0;
        ultimoCheck = 0;
    });
    player.addEventListener('timeupdate', () => {
        const agora = performance.now();
        const delta = agora - ultimoCheck;
        if (!player.seeking && !travandoSeek && player.currentTime > maxTempoAssistido) {
            maxTempoAssistido = player.currentTime;
        }
        if (delta < 1000) return;
        ultimoCheck = agora;
        const card = window.cardAtual;
        if (!card || liberadoParaEsteVideo) return;
        if (!player.duration || player.duration === Infinity) return;
        if (player.currentTime / player.duration >= 0.9) {
            const cb = card.querySelector('.item-checkbox');
            if (cb) { cb.disabled = false; cb.checked = true; cb.dispatchEvent(new Event('change')); registrarConclusao(card); }
            liberadoParaEsteVideo = true;
        }
    });
    player.addEventListener('seeking', () => {
        if (travandoSeek) return;
        if (player.currentTime > maxTempoAssistido) {
            travandoSeek = true;
            player.currentTime = maxTempoAssistido;
            travandoSeek = false;
        }
    });
    player.addEventListener('ended', () => {
        const card = window.cardAtual;
        if (card) {
            const cb = card.querySelector('.item-checkbox');
            if (cb) { cb.disabled = false; cb.checked = true; cb.dispatchEvent(new Event('change')); registrarConclusao(card); }
        }
        maxTempoAssistido = player.duration || maxTempoAssistido;
    });
})();

window.cardAtual = null;

/* ════════════════════════════════════════════════════
   PDF VIEWER — PDF.js
════════════════════════════════════════════════════ */
(function () {
    'use strict';

    const state = {
        pdfDoc:        null,
        pagAtual:      1,
        pagTotal:      0,
        zoom:          1.2,
        renderandoId:  0,
        paginasVistas: new Set(),
        cardAtualPdf:  null,
        concluido:     false,
    };

    function carregarPdfJs(cb) {
        if (window.pdfjsLib) { cb(); return; }
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
        s.onload = () => {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc =
                'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
            cb();
        };
        document.head.appendChild(s);
    }

    window.abrirPdf = function (url, titulo, cardElement) {
        state.cardAtualPdf  = cardElement;
        state.paginasVistas = new Set();
        state.concluido     = false;
        state.pagAtual      = 1;
        state.pdfDoc        = null;

        document.getElementById('pdf-modal-titulo').textContent = titulo || 'Documento';
        document.getElementById('pdf-btn-download').href = url;
        document.getElementById('pdf-btn-download').setAttribute('download', titulo || 'documento.pdf');
        document.getElementById('pdf-pagina-atual').textContent = '1';
        document.getElementById('pdf-pagina-total').textContent = '–';
        document.getElementById('pdf-footer').style.display = 'none';
        atualizarProgressoPdf();

        document.getElementById('pdf-modal-overlay').classList.add('ativo');
        mostrarSpinner(true);

        carregarPdfJs(() => {
            pdfjsLib.getDocument(url).promise
                .then(doc => {
                    state.pdfDoc   = doc;
                    state.pagTotal = doc.numPages;
                    document.getElementById('pdf-pagina-total').textContent = doc.numPages;
                    atualizarBotoesPdf();
                    renderizarPagina(1);
                })
                .catch(() => {
                    mostrarSpinner(false);
                    alert('Não foi possível carregar o PDF. Tente o download direto.');
                });
        });
    };

    window.fecharPdfModal = function (evt) {
        if (evt && evt.target !== document.getElementById('pdf-modal-overlay')) return;
        document.getElementById('pdf-modal-overlay').classList.remove('ativo');
        const canvas = document.getElementById('pdf-canvas');
        canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
        state.pdfDoc = null;
        state.renderandoId++;
    };

    function renderizarPagina(num) {
        if (!state.pdfDoc) return;
        const myId = ++state.renderandoId;
        mostrarSpinner(true);
        state.pdfDoc.getPage(num).then(page => {
            if (myId !== state.renderandoId) return;
            const viewport = page.getViewport({ scale: state.zoom });
            const canvas   = document.getElementById('pdf-canvas');
            const ctx      = canvas.getContext('2d');
            canvas.height  = viewport.height;
            canvas.width   = viewport.width;
            page.render({ canvasContext: ctx, viewport }).promise.then(() => {
                if (myId !== state.renderandoId) return;
                mostrarSpinner(false);
                state.pagAtual = num;
                document.getElementById('pdf-pagina-atual').textContent = num;
                state.paginasVistas.add(num);
                atualizarProgressoPdf();
                atualizarBotoesPdf();
                verificarConclusao();
                document.getElementById('pdf-canvas-wrap').scrollTop = 0;
            });
        });
    }

    window.mudarPagina = function (delta) {
        const nova = state.pagAtual + delta;
        if (nova < 1 || nova > state.pagTotal) return;
        renderizarPagina(nova);
    };

    window.ajustarZoom = function (delta) {
        state.zoom = Math.min(3, Math.max(0.5, state.zoom + delta));
        document.getElementById('pdf-zoom-label').textContent = Math.round(state.zoom * 100) + '%';
        if (state.pdfDoc) renderizarPagina(state.pagAtual);
    };

    function atualizarProgressoPdf() {
        const total = state.pagTotal || 1;
        const pct   = Math.round((state.paginasVistas.size / total) * 100);
        document.getElementById('pdf-progress-fill').style.width = pct + '%';
        document.getElementById('pdf-progress-txt').textContent  = pct + '%';
    }

    function verificarConclusao() {
        if (state.concluido) return;
        if (state.paginasVistas.size < state.pagTotal) return;
        state.concluido = true;
        document.getElementById('pdf-footer').style.display = 'flex';
        const card = state.cardAtualPdf;
        if (!card) return;
        const cb = card.querySelector('.item-checkbox');
        if (cb && cb.disabled) {
            cb.disabled = false;
            cb.checked  = true;
            cb.dispatchEvent(new Event('change'));
            registrarConclusao(card);
        }
    }

    function mostrarSpinner(show) {
        document.getElementById('pdf-spinner').classList.toggle('oculto', !show);
    }

    function atualizarBotoesPdf() {
        document.getElementById('pdf-btn-prev').disabled = state.pagAtual <= 1;
        document.getElementById('pdf-btn-next').disabled = state.pagAtual >= state.pagTotal;
    }

    document.addEventListener('keydown', e => {
        if (!document.getElementById('pdf-modal-overlay').classList.contains('ativo')) return;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') mudarPagina(1);
        if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')  mudarPagina(-1);
        if (e.key === 'Escape')    fecharPdfModal({ target: document.getElementById('pdf-modal-overlay') });
        if (e.key === '+' || e.key === '=') ajustarZoom(0.2);
        if (e.key === '-')                  ajustarZoom(-0.2);
    });
})();

/* ════════════════════════════════════════════════════
   HANDLERS DOS ITENS
════════════════════════════════════════════════════ */
document.querySelectorAll('.item-card').forEach(item => {
    item.addEventListener('click', function (ev) {
        if (ev.target.classList.contains('item-checkbox')) return;
        const url    = this.dataset.url;
        const titulo = this.dataset.titulo;
        const tipo   = this.dataset.tipo || 'arquivo';

        /* PDF — novo viewer */
        if (tipo === 'pdf') {
            abrirPdf(url, titulo, this);
            return;
        }

        if (tipo === 'video')   { window.cardAtual = this; abrirVideo(url, titulo); return; }
        if (tipo === 'arquivo') { window.open(url, '_blank'); return; }
        if (tipo === 'url')     { window.open(url, '_blank'); return; }
        if (tipo === 'youtube') {
            let embedUrl = url;
            const matchV     = url.match(/[?&]v=([^&]+)/);
            const matchShort = url.match(/youtu\.be\/([^?]+)/);
            const matchEmbed = url.match(/youtube\.com\/embed\/([^?]+)/);
            if (matchEmbed)      embedUrl = url;
            else if (matchV)     embedUrl = 'https://www.youtube.com/embed/' + matchV[1];
            else if (matchShort) embedUrl = 'https://www.youtube.com/embed/' + matchShort[1];
            window.cardAtual = this;
            abrirVideo(embedUrl, titulo);
            return;
        }
        if (tipo === 'imagem') { window.cardAtual = this; abrirVideo(url, titulo); return; }
    });
});

/* ════════════════════════════════════════════════════
   ACORDEÕES
════════════════════════════════════════════════════ */
document.querySelectorAll('.grupo-card').forEach(grupo => {
    const head = grupo.querySelector('.grupo-toggle');
    if (head) head.addEventListener('click', () => grupo.classList.toggle('aberto'));
});

document.querySelectorAll('.subpasta-bloco').forEach(bloco => {
    const header = bloco.querySelector('.subpasta-header');
    if (header) header.addEventListener('click', () => bloco.classList.toggle('aberto'));
});

/* ════════════════════════════════════════════════════
   PROGRESSO POR GRUPO
════════════════════════════════════════════════════ */
document.querySelectorAll('.grupo-card').forEach(grupo => {
    const textoProg = grupo.querySelector('.grupo-progresso-texto');
    const fillBar   = grupo.querySelector('[data-fill]');
    const cbs       = grupo.querySelectorAll('.item-checkbox');
    const total     = cbs.length;

    function atualizarProgresso() {
        let concluidos = 0;
        cbs.forEach(cb => { if (cb.checked) concluidos++; });
        const perc = total > 0 ? Math.round((concluidos / total) * 100) : 0;
        if (textoProg) {
            textoProg.textContent = perc + '% concluído';
            textoProg.classList.toggle('progresso-ok', perc === 100);
        }
        if (fillBar) fillBar.style.width = perc + '%';
    }

    cbs.forEach(cb => cb.addEventListener('change', atualizarProgresso));
    atualizarProgresso();
});
</script>