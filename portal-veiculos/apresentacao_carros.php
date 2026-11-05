<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';

session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

$nome      = $_SESSION['nome'] ?? 'Usuário';
$idUsuario = $_SESSION['id_usuario'] ?? null;

/**
 * Extrai o ID de um link do YouTube.
 * Aceita formatos:
 *  - https://www.youtube.com/watch?v=ID
 *  - https://youtu.be/ID
 *  - https://www.youtube.com/embed/ID
 */
function youtubeIdFromUrl(string $url): ?string {
    $parts = parse_url($url);
    if (empty($parts['host'])) {
        return null;
    }

    $host = strtolower($parts['host']);

    // youtu.be/ID
    if ($host === 'youtu.be') {
        return ltrim($parts['path'] ?? '', '/');
    }

    // youtube.com/watch?v=ID
    if (strpos($host, 'youtube.com') !== false) {
        parse_str($parts['query'] ?? '', $q);
        if (!empty($q['v'])) {
            return $q['v'];
        }
    }

    // youtube.com/embed/ID
    if (!empty($parts['path']) && strpos($parts['path'], '/embed/') === 0) {
        return substr($parts['path'], strlen('/embed/'));
    }

    return null;
}

// BUSCAR CARROS DO BANCO
$cars = [];
$sql = "
    SELECT id, nome, ano, categoria, youtube_url, destaque, ativo
    FROM carros_apresentacao
    WHERE ativo = 1
    ORDER BY destaque DESC, nome ASC
";
try {
    if ($res = $mysqli->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            // calcula o ID e a thumb aqui para já mandar pronto para o HTML
            $videoId = youtubeIdFromUrl($row['youtube_url'] ?? '');
            $row['youtube_id'] = $videoId;
            if ($videoId) {
                // thumb padrão de boa qualidade
                $row['thumb_url'] = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
            } else {
                $row['thumb_url'] = '/Veiculos/img/placeholder_carro.png'; // ajuste esse caminho
            }
            $cars[] = $row;
        }
    }
} catch (mysqli_sql_exception $e) {
    // se a tabela não existir ou der erro, segue com lista vazia
    $cars = [];
}
?>
<style>
    :root{
        --bg:#020617;
        --bg-card:#020617;
        --bg-card-soft:#0b1120;
        --border-soft:rgba(148,163,184,.4);
        --border-strong:rgba(148,163,184,.8);
        --accent:#22c55e;
        --accent-soft:rgba(34,197,94,.15);
        --text:#e5e7eb;
        --text-soft:#9ca3af;
    }
    *{margin:0;padding:0;box-sizing:border-box}
    body{
        font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
        background:#fff;
        color: #ffffff;;
        min-height:100vh;
        padding:20px;
    }
    .page-shell{max-width:1180px;margin:0 auto;}
    .page-header{
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:16px;
        margin-bottom:18px;
    }
    .page-title{
        font-size:26px;
        letter-spacing:-.03em;
    }
    .page-subtitle{
        font-size:13px;
        color:var(--text-soft);
        margin-top:4px;
    }
    .page-actions{
        display:flex;
        gap:10px;
        align-items:center;
    }
    .ghost-chip{
        border-radius:999px;
        border:1px solid var(--border-soft);
        padding:6px 14px;
        font-size:12px;
        color:var(--text-soft);
        display:inline-flex;
        align-items:center;
        gap:6px;
        background:rgba(15,23,42,.8);
        backdrop-filter:blur(10px);
    }
    .ghost-chip-dot{
        width:6px;height:6px;border-radius:999px;
        background:var(--accent);
        box-shadow:0 0 0 5px var(--accent-soft);
    }
    .cars-grid{
        display:grid;
        grid-template-columns:repeat(3,minmax(0,1fr));
        gap:16px;
    }
    @media(max-width:1024px){
        .cars-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
    }
    @media(max-width:720px){
        body{padding:14px;}
        .page-header{flex-direction:column;}
        .cars-grid{grid-template-columns:minmax(0,1fr);}
    }
    .car-card{
        position:relative;
        border-radius:18px;
        padding:10px 10px 14px;
        background:
            radial-gradient(circle at top left,rgba(34,197,94,.18),transparent 55%),
            linear-gradient(145deg,var(--bg-card-soft),var(--bg-card));
        border:1px solid var(--border-soft);
        cursor:pointer;
        transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease;
        overflow:hidden;
    }
    .car-card:hover{
        transform:translateY(-3px) scale(1.01);
        box-shadow:0 18px 40px rgba(15,23,42,.9);
        border-color:var(--border-strong);
    }
    .car-thumb{
        position:relative;
        border-radius:14px;
        overflow:hidden;
        margin-bottom:10px;
        background:#020617;
    }
    .car-thumb img{
        width:100%;height:190px;
        object-fit:cover;
        display:block;
        transform-origin:center;
        transition:transform .4s ease;
    }
    .car-card:hover .car-thumb img{transform:scale(1.05);}
    .car-thumb-overlay{
        position:absolute;
        inset:0;
        background:linear-gradient(to top,rgba(15,23,42,.75),transparent 55%);
        display:flex;
        align-items:flex-end;
        justify-content:space-between;
        padding:10px;
        pointer-events:none;
    }
    .play-chip{
        pointer-events:auto;
        border-radius:999px;
        background:rgba(15,23,42,.9);
        color:#e5e7eb;
        border:1px solid rgba(148,163,184,.7);
        font-size:11px;
        padding:6px 10px;
        display:inline-flex;
        align-items:center;
        gap:6px;
        backdrop-filter:blur(10px);
    }
    .play-chip-dot{
        width:8px;height:8px;border-radius:999px;
        background:var(--accent);
        box-shadow:0 0 0 5px var(--accent-soft);
    }
    .car-name{font-size:15px;margin-bottom:3px;}
    .car-meta{font-size:12px;color:var(--text-soft);margin-bottom:6px;}
    .car-badges{display:flex;flex-wrap:wrap;gap:6px;font-size:11px;}
    .badge{
        border-radius:999px;
        padding:3px 8px;
        border:1px solid rgba(148,163,184,.55);
        background:rgba(15,23,42,.9);
        color:var(--text-soft);
    }

    /* MODAL VÍDEO */
    .video-modal-overlay{
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.72);
        backdrop-filter:blur(8px);
        display:none;
        align-items:center;
        justify-content:center;
        z-index:9999;
    }
    .video-modal-overlay.is-open{display:flex;}
    .video-modal{
        width:min(1200px,96vw);
        max-height:100vh;
        background:radial-gradient(circle at top,#020617,#020617);
        border-radius:18px;
        border:1px solid rgba(148,163,184,.55);
        box-shadow:0 24px 70px rgba(15,23,42,.95);
        display:flex;
        flex-direction:row;
        overflow:hidden;
    }
    @media(max-width:900px){
        .video-modal{flex-direction:column;}
    }
    .video-modal-media{
        flex:3;
        position:relative;
        background:#000;
    }
    .video-wrapper{
        position:relative;
        padding-top:60%;
        background:#000;
    }
    .yt-iframe{
        position:absolute;
        inset:0;
        width:100%;
        height:100%;
        border:0;
    }
    .video-overlay-ui{
        position:absolute;
        inset:0;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        background:radial-gradient(circle at center,rgba(15,23,42,.1),rgba(0,0,0,.85));
        color:#fff;
        z-index:2;
        cursor:pointer;
        text-align:center;
        padding:12px;
    }
    .big-play-btn{
        width:72px;height:72px;border-radius:999px;
        border:1px solid rgba(248,250,252,.28);
        background:rgba(15,23,42,.6);
        backdrop-filter:blur(10px);
        position:relative;
    }
    .big-play-btn::before{
        content:"";
        position:absolute;
        left:50%;top:50%;
        transform:translate(-40%,-50%);
        border-style:solid;
        border-width:14px 0 14px 22px;
        border-color:transparent transparent transparent #fff;
    }
    .overlay-label{
        margin-top:10px;
        font-size:13px;
        color:#e5e7eb;
    }
    .video-modal-info{
        flex:2;
        padding:16px 16px 18px;
        display:flex;
        flex-direction:column;
        gap:8px;
        border-left:1px solid rgba(15,23,42,.85);
        background:radial-gradient(circle at top left,#020617,#020617);
    }
    @media(max-width:900px){
        .video-modal-info{border-left:none;border-top:1px solid rgba(15,23,42,.85);}
    }
    .video-modal-header{
        display:flex;
        justify-content:space-between;
        gap:10px;
        align-items:flex-start;
    }
    .video-title{
        font-size:18px;
        margin-bottom:2px;
    }
    .video-sub{
        font-size:12px;
        color:var(--text-soft);
    }
    .video-modal-close-btn{
        border-radius:999px;
        border:1px solid rgba(148,163,184,.7);
        width:30px;height:30px;
        background:rgba(15,23,42,.9);
        color:#e5e7eb;
        cursor:pointer;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        font-size:16px;
    }
    .video-specs{
        margin-top:6px;
        font-size:12px;
        color:var(--text-soft);
        line-height:1.5;
    }
    .video-actions{
        margin-top:auto;
        display:flex;
        gap:8px;
        flex-wrap:wrap;
    }
    .secondary-btn{
        border-radius:999px;
        border:1px solid var(--border-soft);
        padding:7px 14px;
        font-size:12px;
        background:rgba(15,23,42,.9);
        color:var(--text-soft);
        cursor:pointer;
    }
</style>

<div class="page-shell">
    <header class="page-header">
        <div>
            <h1 class="page-title">Apresentação de veículos</h1>
            <p class="page-subtitle">
                Escolha um modelo para ver o vídeo de apresentação e detalhes principais.
            </p>
        </div>
    </header>

    <?php if (empty($cars)): ?>
        <p style="color:var(--text-soft);font-size:14px;">
            Nenhum veículo configurado para apresentação.
        </p>
    <?php else: ?>
    <section class="cars-grid" id="cars-grid">
        <?php foreach ($cars as $car): ?>
            <?php
                $videoId  = $car['youtube_id'];
                $thumbUrl = $car['thumb_url'];
            ?>
            <article class="car-card"
                     data-video-id="<?php echo htmlspecialchars($videoId); ?>"
                     data-name="<?php echo htmlspecialchars($car['nome']); ?>"
                     data-year="<?php echo (int)$car['ano']; ?>"
                     data-category="<?php echo htmlspecialchars($car['categoria']); ?>">
                <div class="car-thumb">
                    <img src="<?php echo htmlspecialchars($thumbUrl); ?>"
                         alt="<?php echo htmlspecialchars($car['nome']); ?>">
                    <div class="car-thumb-overlay">
                        <button type="button" class="play-chip">
                            <span class="play-chip-dot"></span>
                            Ver apresentação
                        </button>
                    </div>
                </div>
                <h2 class="car-name"><?php echo htmlspecialchars($car['nome']); ?></h2>
                <p class="car-meta">
                    <?php echo (int)$car['ano']; ?> • <?php echo htmlspecialchars($car['categoria']); ?>
                </p>
                <div class="car-badges">
                    <span class="badge">Vídeo disponível</span>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
</div>

<div class="video-modal-overlay" id="video-modal-overlay">
    <div class="video-modal">
        <div class="video-modal-media">
            <div class="video-wrapper">
                <div class="video-overlay-ui" id="video-overlay-ui">
                    <button type="button" class="big-play-btn" id="big-play-btn"></button>
                    <div class="overlay-label">Clique para iniciar o vídeo</div>
                </div>
                <iframe class="yt-iframe" id="yt-iframe"
                        src=""
                        allow="autoplay; encrypted-media"
                        allowfullscreen
                        loading="lazy"></iframe>
            </div>
        </div>
        <div class="video-modal-info">
            <div class="video-modal-header">
                <div>
                    <div class="video-title" id="video-title"></div>
                    <div class="video-sub" id="video-sub"></div>
                </div>
                <button type="button" class="video-modal-close-btn" id="video-modal-close">
                    ×
                </button>
            </div>
            <div class="video-specs">
                <p id="video-specs-text">
                    Apresentação em vídeo do veículo focada em destaques visuais,
                    itens de série e diferenciais para o cliente.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
const cards = document.querySelectorAll('.car-card');
const modalOverlay = document.getElementById('video-modal-overlay');
const ytIframe = document.getElementById('yt-iframe');
const overlayUi = document.getElementById('video-overlay-ui');
const videoTitleEl = document.getElementById('video-title');
const videoSubEl = document.getElementById('video-sub');
const btnClose = document.getElementById('video-modal-close');
const btnBigPlay = document.getElementById('big-play-btn');

let currentVideoId = null;

cards.forEach(card => {
    card.addEventListener('click', () => {
        const videoId = card.dataset.videoId;
        const name = card.dataset.name;
        const year = card.dataset.year;
        const category = card.dataset.category;

        if (!videoId) return;

        currentVideoId = videoId;
        videoTitleEl.textContent = name;
        videoSubEl.textContent = `${year} • ${category}`;

        const base = `https://www.youtube.com/embed/${videoId}`;
        const params = [
            'controls=0',       // sem controles nativos
            'rel=0',
            'modestbranding=1',
            'playsinline=1'
        ].join('&');
        ytIframe.src = `${base}?${params}`;

        overlayUi.style.display = 'flex';
        modalOverlay.classList.add('is-open');
    });
});

function playVideoWithAutoplay() {
    if (!currentVideoId) return;
    const base = `https://www.youtube.com/embed/${currentVideoId}`;
    const params = [
        'controls=0',
        'rel=0',
        'modestbranding=1',
        'playsinline=1',
        'autoplay=1'
    ].join('&');
    ytIframe.src = `${base}?${params}`;
    overlayUi.style.display = 'none';
}
overlayUi.addEventListener('click', playVideoWithAutoplay);
btnBigPlay.addEventListener('click', (e) => {
    e.stopPropagation();
    playVideoWithAutoplay();
});

function closeModal() {
    modalOverlay.classList.remove('is-open');
    ytIframe.src = '';
    currentVideoId = null;
}
btnClose.addEventListener('click', closeModal);
modalOverlay.addEventListener('click', (e) => {
    if (e.target === modalOverlay) {
        closeModal();
    }
});
</script>
