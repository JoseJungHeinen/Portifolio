<?php
// @versao Ver.006.011026
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
            r.ramal
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

/* Agrupa por cidade (mesma lógica de ramais_uteis_servidor.php) */
$ramais_por_cidade = [];
foreach ($ramais as $r) {
    $cidade = $r['cidade'];
    if (!isset($ramais_por_cidade[$cidade])) {
        $ramais_por_cidade[$cidade] = [
            'telefone' => $r['telefone'],
            'itens'    => []
        ];
    }
    $ramais_por_cidade[$cidade]['itens'][] = $r;
}

$total_cidades = count($ramais_por_cidade);
$total_ramais  = count($ramais);
$data_emissao  = date('d/m/Y');

/**
 * Ate quantos ramais a unidade sai num card indivisivel (break-inside:avoid).
 *
 * Card curto indivisivel evita cabecalho de unidade orfao no pe da coluna.
 * Acima do limite o card fica divisivel de proposito: uma unidade grande passa
 * de 290mm, mais alto que uma folha A4 inteira, e card indivisivel que nao cabe
 * em pagina nenhuma faz o Chrome empurrar tudo - a primeira folha sai em
 * branco. Medido: com 41 ramais a Cidade A - Fiat da ~297mm contra ~255mm de
 * coluna util, entao ela PRECISA poder se dividir.
 *
 * A unidade grande mantem UM cabecalho so e a lista atravessa a coluna. O custo
 * e que a continuacao nao repete o nome da unidade; ja foi tentado fatiar em
 * blocos rotulados "(cont.)" e o resultado foi recusado por repetir cabecalho.
 */
const RAMAIS_MAX_CARD_INTEIRO = 24;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Ramais Úteis</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --red: #cc0000;
    --gray-700: #374151; --gray-500: #6B7280; --gray-400: #9CA3AF;
    --gray-300: #D1D5DB; --gray-200: #E5E7EB; --gray-100: #F4F4F4;
    --white: #FFFFFF; --black: #111111;
    --font: 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
}
html { font-size: 10pt; }
body {
    font-family: var(--font);
    color: var(--black);
    background: #EAECEF;
    padding: 8mm 0;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* ── TOOLBAR ── */
.toolbar.no-print {
    position: fixed; top: 0; left: 0; right: 0; height: 48px;
    background: var(--gray-700); display: flex; align-items: center;
    gap: 8px; padding: 0 16px; z-index: 1000; box-shadow: 0 2px 12px rgba(0,0,0,.3);
}
.toolbar-title { color: rgba(255,255,255,.6); font-size: 9pt; flex: 1; letter-spacing: .04em; }
.toolbar-title strong { color: var(--white); font-weight: 600; }
.btn-toolbar {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 14px; border: none; border-radius: 5px;
    font-family: var(--font); font-size: 8.5pt; font-weight: 600; cursor: pointer;
    letter-spacing: .02em; transition: opacity .15s;
}
.btn-toolbar:hover { opacity: .85; }
.btn-print-main { background: var(--red); color: var(--white); }
.btn-back { background: rgba(255,255,255,.12); color: var(--white); border: 1px solid rgba(255,255,255,.2); }
.toolbar-spacer.no-print { height: 48px; }

/* ── FOLHA ── */
.doc {
    width: 210mm;
    margin: 0 auto;
    background: var(--white);
    box-shadow: 0 4px 32px rgba(0,0,0,.14);
    padding: 12mm 12mm 10mm;
}

/* ── CABEÇALHO ── */
.rimp-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10mm;
    padding-bottom: 3mm;
    border-bottom: 2px solid var(--red);
}
.rimp-head img { width: 18mm; height: auto; display: block; }
.rimp-head-center { flex: 1; text-align: center; }
.rimp-titulo { font-size: 16pt; font-weight: 700; letter-spacing: .02em; line-height: 1.1; }
.rimp-sub { font-size: 9pt; color: var(--gray-500); margin-top: 1.5mm; }
.rimp-head-right { text-align: right; font-size: 8pt; color: var(--gray-500); line-height: 1.6; min-width: 30mm; }

/* ── GRADE DE CIDADES ──
   Usa multi-coluna (e nao display:grid) de proposito: o Chrome nao fragmenta
   container de grid entre paginas, entao com muitos ramais a grade inteira era
   empurrada para a pagina seguinte e a primeira saia em branco. Multi-coluna
   fragmenta corretamente na impressao. */
.rimp-grid {
    margin-top: 4mm;
    column-count: 3;
    column-gap: 4mm;
}
/* Sem overflow:hidden: ele tornaria o card monolitico para a fragmentacao.
   break-inside:avoid vai so no card CURTO (.rimp-card-inteiro, marcado no PHP
   por quantidade de ramais). O card grande fica divisivel porque e mais alto
   que uma folha - indivisivel, ele nao caberia em pagina nenhuma. */
.rimp-card {
    border: 1px solid var(--gray-300);
    border-radius: 3mm;
    margin-bottom: 4mm;
}
.rimp-card-inteiro {
    page-break-inside: avoid;
    break-inside: avoid;
}
.rimp-card-head {
    background: var(--red);
    color: var(--white);
    padding: 2mm 3mm;
    border-radius: 2.6mm 2.6mm 0 0;
    /* nao deixa o nome da unidade sozinho no pe da coluna */
    page-break-after: avoid;
    break-after: avoid;
}
.rimp-cidade { font-size: 10pt; font-weight: 700; line-height: 1.2; }
.rimp-tel { font-size: 8pt; font-weight: 600; opacity: .9; margin-top: .5mm; }
.rimp-list { padding: 1mm 0; }
.rimp-item {
    display: flex; align-items: center; justify-content: space-between;
    gap: 2mm; padding: .7mm 3mm;
    page-break-inside: avoid;
    break-inside: avoid;
}
.rimp-item + .rimp-item { border-top: 1px solid var(--gray-200); }
.rimp-setor { font-size: 8.5pt; color: var(--gray-700); }
.rimp-num {
    font-size: 9pt; font-weight: 700; color: var(--black);
    background: var(--gray-100); border-radius: 1.5mm;
    padding: .3mm 2mm; letter-spacing: .04em; white-space: nowrap;
}

.rimp-vazio { text-align: center; color: var(--gray-400); font-size: 10pt; padding: 20mm 0; }

@media print {
    @page { size: A4 portrait; margin: 3mm; }
    body { background: var(--white) !important; padding: 0 !important; }
    .toolbar, .toolbar-spacer, .app-version-label { display: none !important; }
    .doc {
        width: 100% !important; box-shadow: none !important;
        margin: 0 !important; padding: 0 !important;
    }
    .rimp-head {
        display: flex !important;
        gap: 4mm;
        padding-bottom: 2.7mm;
        margin-bottom: 2.7mm;
        border-bottom-width: 1.2px;
    }
    .rimp-head img { width: 15mm; }
    .rimp-titulo {
        font-size: 16pt;
        line-height: 1;
    }
    .rimp-sub {
        font-size: 8.2pt;
        margin-top: 1mm;
    }
    .rimp-head-right {
        min-width: 22mm;
        font-size: 8.2pt;
        line-height: 1.25;
    }
    .rimp-grid {
        margin-top: 0 !important;
        column-count: 3;
        column-gap: 2.6mm;
    }
    .rimp-card {
        border-radius: 1.8mm;
        margin-bottom: 2.6mm;
    }
    .rimp-card-head {
        padding: 1.55mm 2.3mm;
        border-radius: 1.5mm 1.5mm 0 0;
    }
    .rimp-cidade {
        font-size: 9.9pt;
        line-height: 1.08;
    }
    .rimp-tel {
        font-size: 8.2pt;
        margin-top: .35mm;
    }
    .rimp-list { padding: .45mm 0; }
    .rimp-item {
        gap: 1.4mm;
        padding: .76mm 2.3mm;
        min-height: 5.15mm;
    }
    .rimp-setor {
        font-size: 8.6pt;
        line-height: 1.08;
    }
    .rimp-num {
        font-size: 8.9pt;
        border-radius: 1.1mm;
        padding: .18mm 1.2mm;
        letter-spacing: 0;
    }
}

.app-version-label {
    position: fixed; bottom: 4px; right: 8px;
    font-size: 10px; color: #888; opacity: .6;
    font-family: var(--font); pointer-events: none; z-index: 9999;
}
</style>
</head>
<body>

<div class="toolbar no-print">
    <div class="toolbar-title">
        Ramais Úteis &nbsp;&nbsp;<strong><?= $total_cidades ?> <?= $total_cidades === 1 ? 'unidade' : 'unidades' ?> · <?= $total_ramais ?> <?= $total_ramais === 1 ? 'ramal' : 'ramais' ?></strong>
    </div>
    <button class="btn-toolbar btn-back" onclick="window.close()">Fechar</button>
    <button class="btn-toolbar btn-print-main" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
</div>
<div class="toolbar-spacer no-print"></div>

<div class="doc">
    <div class="rimp-head">
        <img src="img/logo.svg" alt="Exemplo">
        <div class="rimp-head-center">
            <div class="rimp-titulo">Ramais Úteis</div>
            <div class="rimp-sub">Lista de ramais por unidade &middot; Empresa Exemplo &middot; uso interno</div>
        </div>
        <div class="rimp-head-right">
            Emissão<br><strong><?= htmlspecialchars($data_emissao, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </div>

    <?php if (empty($ramais_por_cidade)): ?>
        <div class="rimp-vazio">Nenhum ramal cadastrado ou ativo no momento.</div>
    <?php else: ?>
        <div class="rimp-grid">
            <?php foreach ($ramais_por_cidade as $cidade => $dados): ?>
                <?php $cardInteiro = count($dados['itens']) <= RAMAIS_MAX_CARD_INTEIRO; ?>
                <div class="rimp-card<?= $cardInteiro ? ' rimp-card-inteiro' : ''; ?>">
                    <div class="rimp-card-head">
                        <div class="rimp-cidade"><?= htmlspecialchars($cidade, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php if (!empty($dados['telefone'])): ?>
                            <div class="rimp-tel"><?= htmlspecialchars($dados['telefone'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="rimp-list">
                        <?php foreach ($dados['itens'] as $r): ?>
                            <div class="rimp-item">
                                <span class="rimp-setor"><?= htmlspecialchars($r['setor'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="rimp-num"><?= htmlspecialchars($r['ramal'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<div class="app-version-label"><?php echo VERSAO_RAMAIS_UTEIS_IMPRESSAO; ?></div>

</body>
</html>
