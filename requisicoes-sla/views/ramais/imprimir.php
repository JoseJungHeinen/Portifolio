<?php
// @versao Ver.001.140926
// Espelho de ramais_uteis_impressao.php do Veiculos: folha A4 para imprimir ou
// salvar em PDF. Renderizada sem o layout principal (RamaisController::imprimir).

$totalCidades = count($ramaisPorCidade);
$totalRamais  = array_sum(array_map(fn($c) => count($c['itens']), $ramaisPorCidade));
$dataEmissao  = date('d/m/Y');

/**
 * Até quantos ramais a unidade sai num card indivisível (break-inside:avoid).
 *
 * Card curto indivisível evita cabeçalho de unidade órfão no pé da coluna.
 * Acima do limite o card fica divisível de propósito: uma unidade grande passa
 * da altura de uma folha A4 inteira, e card indivisível que não cabe em página
 * nenhuma faz o Chrome empurrar tudo — a primeira folha sai em branco. Valor e
 * justificativa herdados do Veiculos, onde foram medidos (a Cidade A - Fiat, com
 * 41 ramais, dá ~297mm contra ~255mm de coluna útil).
 */
$maxCardInteiro = 24;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
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
    max-width: 100%;
    margin: 0 auto;
    background: var(--white);
    box-shadow: 0 4px 32px rgba(0,0,0,.14);
    padding: 12mm 12mm 10mm;
}

/* ── CABEÇALHO ── */
.rimp-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 10mm; padding-bottom: 3mm; border-bottom: 2px solid var(--red);
}
.rimp-head img { width: 18mm; height: auto; display: block; }
.rimp-head-center { flex: 1; text-align: center; }
.rimp-titulo { font-size: 16pt; font-weight: 700; letter-spacing: .02em; line-height: 1.1; }
.rimp-sub { font-size: 9pt; color: var(--gray-500); margin-top: 1.5mm; }
.rimp-head-right { text-align: right; font-size: 8pt; color: var(--gray-500); line-height: 1.6; min-width: 30mm; }

/* ── GRADE DE CIDADES ──
   Multi-coluna (e não display:grid) de propósito: o Chrome não fragmenta
   container de grid entre páginas, então com muitos ramais a grade inteira era
   empurrada para a página seguinte e a primeira saía em branco. Multi-coluna
   fragmenta corretamente na impressão. */
.rimp-grid { margin-top: 4mm; column-count: 3; column-gap: 4mm; }
/* Sem overflow:hidden: tornaria o card monolítico para a fragmentação.
   break-inside:avoid só no card CURTO (.rimp-card-inteiro, marcado no PHP). */
.rimp-card { border: 1px solid var(--gray-300); border-radius: 3mm; margin-bottom: 4mm; }
.rimp-card-inteiro { page-break-inside: avoid; break-inside: avoid; }
.rimp-card-head {
    background: var(--red); color: var(--white); padding: 2mm 3mm;
    border-radius: 2.6mm 2.6mm 0 0;
    /* não deixa o nome da unidade sozinho no pé da coluna */
    page-break-after: avoid; break-after: avoid;
}
.rimp-cidade { font-size: 10pt; font-weight: 700; line-height: 1.2; }
.rimp-tel { font-size: 8pt; font-weight: 600; opacity: .9; margin-top: .5mm; }
.rimp-list { padding: 1mm 0; }
.rimp-item {
    display: flex; align-items: center; justify-content: space-between;
    gap: 2mm; padding: .7mm 3mm;
    page-break-inside: avoid; break-inside: avoid;
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
    .toolbar, .toolbar-spacer { display: none !important; }
    .doc { width: 100% !important; box-shadow: none !important; margin: 0 !important; padding: 0 !important; }
    .rimp-head { display: flex !important; gap: 4mm; padding-bottom: 2.7mm; margin-bottom: 2.7mm; border-bottom-width: 1.2px; }
    .rimp-head img { width: 15mm; }
    .rimp-titulo { font-size: 16pt; line-height: 1; }
    .rimp-sub { font-size: 8.2pt; margin-top: 1mm; }
    .rimp-head-right { min-width: 22mm; font-size: 8.2pt; line-height: 1.25; }
    .rimp-grid { margin-top: 0 !important; column-count: 3; column-gap: 2.6mm; }
    .rimp-card { border-radius: 1.8mm; margin-bottom: 2.6mm; }
    .rimp-card-head { padding: 1.55mm 2.3mm; border-radius: 1.5mm 1.5mm 0 0; }
    .rimp-cidade { font-size: 9.9pt; line-height: 1.08; }
    .rimp-tel { font-size: 8.2pt; margin-top: .35mm; }
    .rimp-list { padding: .45mm 0; }
    .rimp-item { gap: 1.4mm; padding: .76mm 2.3mm; min-height: 5.15mm; }
    .rimp-setor { font-size: 8.6pt; line-height: 1.08; }
    .rimp-num { font-size: 8.9pt; border-radius: 1.1mm; padding: .18mm 1.2mm; letter-spacing: 0; }
}
</style>
</head>
<body>

<div class="toolbar no-print">
    <div class="toolbar-title">
        Ramais Úteis &nbsp;&nbsp;<strong><?= $totalCidades ?> <?= $totalCidades === 1 ? 'unidade' : 'unidades' ?> · <?= $totalRamais ?> <?= $totalRamais === 1 ? 'ramal' : 'ramais' ?></strong>
    </div>
    <button class="btn-toolbar btn-back" onclick="window.close()">Fechar</button>
    <button class="btn-toolbar btn-print-main" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
</div>
<div class="toolbar-spacer no-print"></div>

<div class="doc">
    <div class="rimp-head">
        <img src="<?= asset('img/logo.svg') ?>" alt="Exemplo">
        <div class="rimp-head-center">
            <div class="rimp-titulo">Ramais Úteis</div>
            <div class="rimp-sub">Lista de ramais por unidade &middot; Empresa Exemplo &middot; uso interno</div>
        </div>
        <div class="rimp-head-right">
            Emissão<br><strong><?= e($dataEmissao) ?></strong>
        </div>
    </div>

    <?php if (!empty($erro)): ?>
        <div class="rimp-vazio"><?= e($erro) ?></div>
    <?php elseif (empty($ramaisPorCidade)): ?>
        <div class="rimp-vazio">Nenhum ramal cadastrado ou ativo no momento.</div>
    <?php else: ?>
        <div class="rimp-grid">
            <?php foreach ($ramaisPorCidade as $cid): ?>
                <?php $cardInteiro = count($cid['itens']) <= $maxCardInteiro; ?>
                <div class="rimp-card<?= $cardInteiro ? ' rimp-card-inteiro' : '' ?>">
                    <div class="rimp-card-head">
                        <div class="rimp-cidade"><?= e($cid['nome']) ?></div>
                        <?php if (!empty($cid['telefone'])): ?>
                            <div class="rimp-tel"><?= e($cid['telefone']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="rimp-list">
                        <?php foreach ($cid['itens'] as $r): ?>
                            <div class="rimp-item">
                                <span class="rimp-setor"><?= e($r['setor']) ?></span>
                                <span class="rimp-num"><?= e($r['ramal']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
