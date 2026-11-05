<?php
// @versao Ver.001.211026
/**
 * Cartões de permissões (mesmo modelo da tela de usuários do Veiculos):
 * um cartão por grupo do catálogo, "marcar todos" e um switch por chave.
 *
 * Espera:
 *   $catalogo  permissoes_catalogo()
 *   $marcadas  [chave => bool] estado atual dos switches
 *   $padrao    [chave => bool] padrão do cargo (tela do usuário), ou null
 *              (tela do cargo) — com ele aparecem o selo "exceção" e o
 *              "voltar ao padrão do cargo".
 *
 * Os switches saem como perm[<chave>]=1; desmarcado não é enviado.
 */
$padrao = $padrao ?? null;
?>
<style>
    .perm-grupo .card-header { cursor: pointer; user-select: none; }
    .perm-grupo .card-header .bi-chevron-down { transition: transform .15s; }
    .perm-grupo .card-header.collapsed .bi-chevron-down { transform: rotate(-90deg); }
    .perm-grupo .list-group-item { cursor: pointer; }
    .perm-grupo .list-group-item:hover { background: #fafafa; }
    .perm-grupo .perm-sub { padding-left: 1.25rem; color: #555; }
</style>

<div class="d-flex flex-column gap-2">
<?php foreach ($catalogo as $grupoKey => $grupo):
    $idCorpo = 'perm-grupo-' . $grupoKey;
?>
    <div class="card border perm-grupo">
        <div class="card-header bg-light d-flex align-items-center gap-2 py-2 collapsed"
             data-bs-toggle="collapse" data-bs-target="#<?= e($idCorpo) ?>">
            <i class="bi <?= e($grupo['icon']) ?> text-danger"></i>
            <span class="fw-semibold"><?= e($grupo['label']) ?></span>
            <span class="badge rounded-pill bg-secondary-subtle text-secondary perm-contagem"></span>
            <a href="#" class="perm-bulk small text-decoration-none ms-1">marcar todos</a>
            <?php if ($padrao !== null): ?>
            <a href="#" class="perm-reset small text-decoration-none text-muted ms-1">voltar ao padrão do cargo</a>
            <?php endif; ?>
            <i class="bi bi-chevron-down ms-auto small text-muted"></i>
        </div>
        <div id="<?= e($idCorpo) ?>" class="collapse">
            <div class="list-group list-group-flush">
            <?php foreach ($grupo['itens'] as $chave => $item):
                $sub   = !empty($item['sub']);
                $ativa = !empty($item['ativa']);
                $idCb  = 'perm_' . str_replace('.', '__', $chave);
            ?>
                <label class="list-group-item d-flex align-items-center gap-2 py-2 mb-0" for="<?= e($idCb) ?>">
                    <span class="<?= $sub ? 'perm-sub' : 'fw-semibold' ?>"><?= $sub ? '→ ' : '' ?><?= e($item['label']) ?></span>
                    <?php if (!$ativa): ?>
                    <span class="badge bg-light text-muted border fw-normal"
                          title="Pode ser configurada, mas o sistema ainda decide esta ação pelo cargo.">em breve</span>
                    <?php endif; ?>
                    <?php if ($padrao !== null): ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle fw-normal perm-excecao d-none"
                          title="Diferente do padrão do cargo">exceção</span>
                    <?php endif; ?>
                    <span class="form-check form-switch ms-auto mb-0">
                        <input class="form-check-input perm-checkbox" type="checkbox" role="switch"
                               name="perm[<?= e($chave) ?>]" id="<?= e($idCb) ?>" value="1"
                               <?= !empty($marcadas[$chave]) ? 'checked' : '' ?>
                               <?php if ($padrao !== null): ?>data-padrao="<?= !empty($padrao[$chave]) ? '1' : '0' ?>"<?php endif; ?>>
                    </span>
                </label>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<script>
(function () {
    function atualizar(grupo) {
        const cbs   = Array.from(grupo.querySelectorAll('.perm-checkbox'));
        const ligad = cbs.filter(cb => cb.checked).length;
        grupo.querySelector('.perm-contagem').textContent = ligad + '/' + cbs.length;
        grupo.querySelector('.perm-bulk').textContent = ligad === cbs.length ? 'desmarcar todos' : 'marcar todos';
        cbs.forEach(cb => {
            const selo = cb.closest('label').querySelector('.perm-excecao');
            if (selo) selo.classList.toggle('d-none', cb.checked === (cb.dataset.padrao === '1'));
        });
    }

    document.querySelectorAll('.perm-grupo').forEach(grupo => {
        // stopPropagation: os links ficam dentro do cabeçalho que abre/fecha o cartão
        grupo.querySelector('.perm-bulk').addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            const cbs   = grupo.querySelectorAll('.perm-checkbox');
            const todos = Array.from(cbs).every(cb => cb.checked);
            cbs.forEach(cb => { cb.checked = !todos; });
            atualizar(grupo);
        });
        grupo.querySelector('.perm-reset')?.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            grupo.querySelectorAll('.perm-checkbox').forEach(cb => { cb.checked = cb.dataset.padrao === '1'; });
            atualizar(grupo);
        });
        grupo.addEventListener('change', () => atualizar(grupo));
        atualizar(grupo);
    });
})();
</script>
