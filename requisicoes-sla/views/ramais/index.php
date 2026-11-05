<?php
// @versao Ver.001.140926
// Espelho de ramais_uteis_servidor.php do Veiculos. Os dados vêm do banco de lá
// (RamaisController::carregar); a edição continua no config_ramais.php do Veiculos.
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0">
        <i class="bi bi-telephone-fill me-2 text-danger"></i>Ramais
    </h4>
</div>

<?php if (!empty($erro)): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i><?= e($erro) ?>
    </div>
<?php elseif (empty($ramaisPorCidade)): ?>
    <p class="text-center text-muted py-4">Nenhum ramal cadastrado ou ativo no momento.</p>
<?php else: ?>
<style>
    #ramais-srv-root { font-family: 'Segoe UI', system-ui, sans-serif; color: #111; }
    #ramais-srv-root * { box-sizing: border-box; }

    /* topo: busca + ações */
    #ramais-srv-root .rsrv-topo {
        display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 1.4rem;
    }
    #ramais-srv-root .rsrv-busca { position: relative; flex: 1; min-width: 240px; }
    #ramais-srv-root .rsrv-busca svg {
        position: absolute; left: 11px; top: 50%; transform: translateY(-50%); pointer-events: none;
    }
    #ramais-srv-root .rsrv-busca input {
        width: 100%; max-width: 380px; padding: 9px 14px 9px 36px;
        border: 1px solid #ddd; border-radius: 999px; font-size: .88rem;
        background: #fff; color: #111; outline: none; font-family: inherit; display: block;
    }
    #ramais-srv-root .rsrv-busca input:focus {
        border-color: #cc0000; box-shadow: 0 0 0 3px rgba(204,0,0,.08);
    }
    #ramais-srv-root .rsrv-btn-pdf {
        display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px;
        border: none; border-radius: 999px; background: #cc0000; color: #fff;
        font-family: inherit; font-size: .85rem; font-weight: 600; cursor: pointer;
        white-space: nowrap; text-decoration: none; transition: opacity .15s;
    }
    #ramais-srv-root .rsrv-btn-pdf:hover { opacity: .88; color: #fff; }

    /* grid */
    #ramais-srv-root .rsrv-grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 14px;
    }

    /* card */
    #ramais-srv-root .rsrv-card {
        background: #fff; border: 1px solid #ebebeb; border-radius: 14px; overflow: hidden;
        transition: box-shadow .2s, transform .2s;
    }
    #ramais-srv-root .rsrv-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.09); transform: translateY(-2px); }
    #ramais-srv-root .rsrv-card-head {
        padding: 12px 14px 10px; border-bottom: 1px solid #f0f0f0;
        display: flex; align-items: center; gap: 10px;
    }
    #ramais-srv-root .rsrv-icon {
        width: 36px; height: 36px; min-width: 36px; border-radius: 10px; background: #cc0000;
        display: flex; align-items: center; justify-content: center;
    }
    #ramais-srv-root .rsrv-info { flex: 1; min-width: 0; }
    #ramais-srv-root .rsrv-cidade {
        font-size: .92rem; font-weight: 700; color: #111;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    #ramais-srv-root .rsrv-tel { font-size: .78rem; color: #cc0000; font-weight: 600; margin-top: 2px; }

    /* lista de ramais */
    #ramais-srv-root .rsrv-list { padding: 8px 0; }
    #ramais-srv-root .rsrv-item {
        display: flex; align-items: center; justify-content: space-between;
        padding: 6px 14px; gap: 8px; transition: background .1s;
    }
    #ramais-srv-root .rsrv-item:hover { background: #fafafa; }
    #ramais-srv-root .rsrv-setor {
        font-size: .82rem; color: #444; flex: 1; min-width: 0;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    #ramais-srv-root .rsrv-num {
        font-size: .85rem; font-weight: 700; color: #111; background: #f4f4f4;
        border-radius: 6px; padding: 2px 8px; white-space: nowrap; letter-spacing: .03em;
    }

    #ramais-srv-root .rsrv-empty {
        text-align: center; color: #aaa; font-size: .9rem; padding: 2rem 0; grid-column: 1 / -1;
    }
    #ramais-srv-root .rsrv-manual { margin-top: 1.4rem; font-size: .82rem; color: #888; text-align: center; }
    #ramais-srv-root .rsrv-manual a { color: #cc0000; font-weight: 600; text-decoration: underline; }
</style>

<div id="ramais-srv-root">

    <div class="rsrv-topo">
        <div class="rsrv-busca">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none">
                <circle cx="9" cy="9" r="7" stroke="#aaa" stroke-width="1.8"/>
                <path d="M14.5 14.5L18 18" stroke="#aaa" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
            <input type="text" id="buscaRamalSrv" placeholder="Buscar cidade, setor ou ramal…" autocomplete="off">
        </div>
        <a class="rsrv-btn-pdf" href="<?= url('ramais/imprimir') ?>" target="_blank" rel="noopener">
            🖨️ Gerar PDF
        </a>
    </div>

    <div class="rsrv-grid" id="rsrvGrid">
        <?php foreach ($ramaisPorCidade as $cid):
            $busca = mb_strtolower(
                $cid['nome'] . ' ' . ($cid['telefone'] ?? '') . ' ' .
                implode(' ', array_column($cid['itens'], 'setor')) . ' ' .
                implode(' ', array_column($cid['itens'], 'ramal')),
                'UTF-8'
            );
        ?>
            <div class="rsrv-card" data-busca="<?= e($busca) ?>">
                <div class="rsrv-card-head">
                    <div class="rsrv-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" fill="white"/>
                            <circle cx="12" cy="9" r="2.5" fill="#cc0000"/>
                        </svg>
                    </div>
                    <div class="rsrv-info">
                        <div class="rsrv-cidade" title="<?= e($cid['nome']) ?>"><?= e($cid['nome']) ?></div>
                        <?php if (!empty($cid['telefone'])): ?>
                            <div class="rsrv-tel"><?= e($cid['telefone']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="rsrv-list">
                    <?php foreach ($cid['itens'] as $r): ?>
                        <div class="rsrv-item">
                            <?php /* title: o setor corta com reticências no card estreito
                                     ("Assistência Técnica · Cri…"); passar o mouse mostra inteiro */ ?>
                            <span class="rsrv-setor" title="<?= e($r['setor']) ?>"><?= e($r['setor']) ?></span>
                            <span class="rsrv-num"><?= e($r['ramal']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="rsrv-empty" id="rsrvSemResultado" style="display:none;">Nenhum resultado encontrado.</div>
    </div>

    <div class="rsrv-manual">
        Manual de padrão de atendimento –
        <a href="/Veiculos/Manual/MANUAL_1.PDF" target="_blank" rel="noopener noreferrer">clique aqui</a>
    </div>

</div>

<script>
(function () {
    // Busca sem acento dos dois lados: "assistencia" acha "Assistência".
    // O Veiculos compara com acento, e aí quem digita sem acento não acha nada.
    var semAcento = function (s) { return s.normalize('NFD').replace(/\p{M}/gu, ''); };
    var cards = [].slice.call(document.querySelectorAll('#rsrvGrid .rsrv-card'));
    cards.forEach(function (c) { c.dataset.buscaNorm = semAcento(c.getAttribute('data-busca') || ''); });

    document.getElementById('buscaRamalSrv').addEventListener('input', function () {
        var termo = semAcento(this.value.toLowerCase().trim());
        var visiveis = 0;
        cards.forEach(function (card) {
            var ok = termo === '' || card.dataset.buscaNorm.indexOf(termo) !== -1;
            card.style.display = ok ? '' : 'none';
            if (ok) visiveis++;
        });
        document.getElementById('rsrvSemResultado').style.display = visiveis === 0 ? 'block' : 'none';
    });
})();
</script>
<?php endif; ?>
