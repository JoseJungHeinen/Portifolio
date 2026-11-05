<div class="mb-4">
    <h4 class="fw-bold mb-1">
        <i class="bi bi-link-45deg me-2 text-primary"></i>Links Úteis
    </h4>
    <p class="text-muted small mb-0">Atalhos para sistemas e ferramentas usados no dia a dia.</p>
</div>

<?php if (empty($grupos)): ?>
<div class="text-center text-muted py-5">
    <i class="bi bi-link-45deg fs-1 d-block mb-2 opacity-25"></i>
    Nenhum link cadastrado.
</div>
<?php else: ?>
<?php foreach ($grupos as $nomeGrupo => $links): ?>
<div class="mb-4">
    <h6 class="text-uppercase fw-bold text-danger mb-3" style="font-size:.75rem;letter-spacing:.08em">
        <?= e($nomeGrupo) ?>
    </h6>
    <div class="row g-3">
        <?php foreach ($links as $link):
            $iniciais   = mb_strtoupper(mb_substr($link['titulo'], 0, 2, 'UTF-8'), 'UTF-8');
            $host       = parse_url($link['url'], PHP_URL_HOST) ?: $link['url'];
            $faviconUrl = 'https://www.google.com/s2/favicons?domain=' . urlencode($host) . '&sz=64';
        ?>
        <div class="col-md-3 col-sm-4 col-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex flex-column gap-2 p-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle bg-light d-flex align-items-center justify-content-center flex-shrink-0 overflow-hidden"
                             style="width:36px;height:36px;">
                            <img src="<?= e($faviconUrl) ?>"
                                 alt="<?= e($link['titulo']) ?>"
                                 style="width:24px;height:24px;object-fit:contain"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                            <span class="fw-bold text-dark" style="font-size:.8rem;display:none"><?= e($iniciais) ?></span>
                        </div>
                        <div class="fw-semibold text-truncate small"><?= e($link['titulo']) ?></div>
                    </div>
                    <?php if (!empty($link['descricao'])): ?>
                    <div class="text-muted" style="font-size:.8rem;line-height:1.3">
                        <?= e($link['descricao']) ?>
                    </div>
                    <?php endif; ?>
                    <div class="text-muted" style="font-size:.75rem"><?= e($host) ?></div>
                    <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener"
                       class="btn btn-sm btn-danger mt-auto">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Abrir link
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
