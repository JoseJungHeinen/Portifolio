<?php
// @versao Ver.001.020926
require_once __DIR__ . '/config_database.php';
require_once __DIR__ . '/config_versao.php';
session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}


$sql = "SELECT 
            c.nome      AS cidade,
            c.telefone  AS telefone,
            r.setor, 
            r.ramal,
            c.ordem     AS ordem_cidade,
            r.ordem     AS ordem_ramal
        FROM ramais r
        INNER JOIN ramais_cidades c ON c.id = r.id_cidade
        WHERE r.ativo = 1
        ORDER BY c.ordem ASC, c.nome ASC, r.ordem ASC, r.id ASC";

$res = $mysqli->query($sql);

if (!$res) {
    exit('Erro na query: ' . $mysqli->error);
}

$ramais = [];
while ($row = $res->fetch_assoc()) {
    $ramais[] = $row;
}

// Agrupar por cidade
$ramais_por_cidade = [];
foreach ($ramais as $r) {
    $cidade = $r['cidade'];
    if (!isset($ramais_por_cidade[$cidade])) {
        $ramais_por_cidade[$cidade] = [
            'telefone' => $r['telefone'], // guarda telefone da cidade
            'itens'    => []
        ];
    }
    $ramais_por_cidade[$cidade]['itens'][] = $r;
}
?>

<?php if (empty($ramais)): ?>
    <p style="padding:20px;text-align:center;color:#666;">
        Nenhum ramal cadastrado ou ativo no momento.
    </p>
<?php else: ?>
    <style>
        #ramais-srv-root {
            font-family: 'Segoe UI', system-ui, sans-serif;
            color: #111;
        }
        #ramais-srv-root * {
            box-sizing: border-box;
        }

        /* topo: busca + ações */
        #ramais-srv-root .rsrv-topo {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 1.4rem;
        }
        #ramais-srv-root .rsrv-topo .rsrv-busca {
            flex: 1;
            min-width: 240px;
            margin-bottom: 0;
        }
        #ramais-srv-root .rsrv-btn-pdf {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 16px;
            border: none;
            border-radius: 999px;
            background: #cc0000;
            color: #fff;
            font-family: inherit;
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: opacity .15s;
        }
        #ramais-srv-root .rsrv-btn-pdf:hover {
            opacity: .88;
        }

        /* busca */
        #ramais-srv-root .rsrv-busca {
            position: relative;
            margin-bottom: 1.4rem;
        }
        #ramais-srv-root .rsrv-busca svg {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
        }
        #ramais-srv-root .rsrv-busca input {
            width: 100%;
            max-width: 380px;
            padding: 9px 14px 9px 36px;
            border: 1px solid #ddd;
            border-radius: 999px;
            font-size: .88rem;
            background: #fff;
            color: #111;
            outline: none;
            font-family: inherit;
            display: block;
        }
        #ramais-srv-root .rsrv-busca input:focus {
            border-color: #cc0000;
            box-shadow: 0 0 0 3px rgba(204,0,0,.08);
        }

        /* grid */
        #ramais-srv-root .rsrv-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 14px;
        }

        /* card */
        #ramais-srv-root .rsrv-card {
            background: #fff;
            border: 1px solid #ebebeb;
            border-radius: 14px;
            overflow: hidden;
            transition: box-shadow .2s, transform .2s;
        }
        #ramais-srv-root .rsrv-card:hover {
            box-shadow: 0 6px 24px rgba(0,0,0,.09);
            transform: translateY(-2px);
        }

        /* cabeçalho do card */
        #ramais-srv-root .rsrv-card-head {
            padding: 12px 14px 10px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        #ramais-srv-root .rsrv-icon {
            width: 36px;
            height: 36px;
            min-width: 36px;
            border-radius: 10px;
            background: #cc0000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #ramais-srv-root .rsrv-info {
            flex: 1;
            min-width: 0;
        }
        #ramais-srv-root .rsrv-cidade {
            font-size: .92rem;
            font-weight: 700;
            color: #111;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        #ramais-srv-root .rsrv-tel {
            font-size: .78rem;
            color: #cc0000;
            font-weight: 600;
            margin-top: 2px;
        }

        /* lista de ramais */
        #ramais-srv-root .rsrv-list {
            padding: 8px 0;
        }
        #ramais-srv-root .rsrv-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 14px;
            gap: 8px;
            transition: background .1s;
        }
        #ramais-srv-root .rsrv-item:hover {
            background: #fafafa;
        }
        #ramais-srv-root .rsrv-setor {
            font-size: .82rem;
            color: #444;
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        #ramais-srv-root .rsrv-num {
            font-size: .85rem;
            font-weight: 700;
            color: #111;
            background: #f4f4f4;
            border-radius: 6px;
            padding: 2px 8px;
            white-space: nowrap;
            letter-spacing: .03em;
        }

        /* sem resultado */
        #ramais-srv-root .rsrv-empty {
            text-align: center;
            color: #aaa;
            font-size: .9rem;
            padding: 2rem 0;
            grid-column: 1 / -1;
        }

        /* link manual */
        #ramais-srv-root .rsrv-manual {
            margin-top: 1.4rem;
            font-size: .82rem;
            color: #888;
            text-align: center;
        }
        #ramais-srv-root .rsrv-manual a {
            color: #cc0000;
            font-weight: 600;
            text-decoration: underline;
        }

        .app-version-label {
            position: fixed;
            bottom: 4px;
            right: 8px;
            font-size: 10px;
            color: #888;
            opacity: 0.6;
            font-family: system-ui, sans-serif;
            pointer-events: none;
            z-index: 9999;
        }
    </style>

    <div id="ramais-srv-root">

        <div class="rsrv-topo">
            <div class="rsrv-busca">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none">
                    <circle cx="9" cy="9" r="7" stroke="#aaa" stroke-width="1.8"/>
                    <path d="M14.5 14.5L18 18" stroke="#aaa" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                <input type="text" id="buscaRamalSrv" placeholder="Buscar cidade, setor ou ramal…">
            </div>
            <button type="button" class="rsrv-btn-pdf" id="btnRamaisPdf">
                🖨️ Gerar PDF
            </button>
        </div>

        <div class="rsrv-grid" id="rsrvGrid">
            <?php foreach ($ramais_por_cidade as $cidade => $dados):
                $busca = mb_strtolower(
                    $cidade . ' ' . $dados['telefone'] . ' ' .
                    implode(' ', array_column($dados['itens'], 'setor')) . ' ' .
                    implode(' ', array_column($dados['itens'], 'ramal')),
                    'UTF-8'
                );
            ?>
                <div class="rsrv-card" data-busca="<?= htmlspecialchars($busca, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="rsrv-card-head">
                        <div class="rsrv-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                                <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" fill="white"/>
                                <circle cx="12" cy="9" r="2.5" fill="#cc0000"/>
                            </svg>
                        </div>
                        <div class="rsrv-info">
                            <div class="rsrv-cidade"><?= htmlspecialchars($cidade, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (!empty($dados['telefone'])): ?>
                                <div class="rsrv-tel"><?= htmlspecialchars($dados['telefone'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="rsrv-list">
                        <?php foreach ($dados['itens'] as $r): ?>
                            <div class="rsrv-item">
                                <span class="rsrv-setor"><?= htmlspecialchars($r['setor'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="rsrv-num"><?= htmlspecialchars($r['ramal'], ENT_QUOTES, 'UTF-8') ?></span>
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

<?php endif; ?>

<div class="app-version-label"><?php echo VERSAO_RAMAIS_UTEIS_SERVIDOR; ?></div>

<script>
document.getElementById('buscaRamalSrv').addEventListener('input', function () {
    var termo = this.value.toLowerCase().trim();
    var cards = document.querySelectorAll('#rsrvGrid .rsrv-card');
    var visiveis = 0;
    cards.forEach(function (card) {
        var match = termo === '' || (card.getAttribute('data-busca') || '').includes(termo);
        card.style.display = match ? '' : 'none';
        if (match) visiveis++;
    });
    document.getElementById('rsrvSemResultado').style.display = visiveis === 0 ? 'block' : 'none';
});

var btnRamaisPdf = document.getElementById('btnRamaisPdf');
if (btnRamaisPdf) {
    btnRamaisPdf.addEventListener('click', function () {
        window.open('ramais_uteis_impressao.php', '_blank', 'noopener');
    });
}
</script>