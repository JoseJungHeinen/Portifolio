<?php
require_once __DIR__ . '/config_database.php';
require_once __DIR__ . '/acessorios_promocao.php';

session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

garantirSchemaPromocaoAcessorios($mysqli);

$ids = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
if (!$ids) exit('Nenhum item selecionado.');

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types        = str_repeat('i', count($ids));
$stmt = $mysqli->prepare(
    "SELECT id, marca, acessorio, modelo, valor, COALESCE(codigo_item,'') AS codigo_item,
     valor_promocional, valor_vendedor, promocao_inicio, promocao_fim
     FROM acessorios_marcas
     WHERE id IN ($placeholders)
     ORDER BY marca, acessorio, modelo"
);
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$res   = $stmt->get_result();
$itens = [];
while ($row = $res->fetch_assoc()) {
    $itens[] = aplicarValorEfetivo($row);
}
$stmt->close();
if (!$itens) exit('Nenhum item encontrado.');

/**
 * Monta o HTML do preço da tag: parte inteira grande + centavos pequenos
 * sobrescritos, só quando os centavos forem diferentes de zero (igual ao
 * PDF de referência: "R$499" sem vírgula, mas "R$109,90" com centavos
 * pequenos sobrescritos).
 */
function renderTagPreco(float $valor): string {
    $centavos = (int) round($valor * 100);
    $reais    = intdiv($centavos, 100);
    $cent     = $centavos % 100;
    $reaisFmt = number_format($reais, 0, ',', '.');
    if ($cent === 0) {
        return '<span class="rs">R$</span><span class="inteiro">' . $reaisFmt . '</span>';
    }
    $centFmt = str_pad((string) $cent, 2, '0', STR_PAD_LEFT);
    return '<span class="rs">R$</span><span class="inteiro">' . $reaisFmt . ',</span><span class="centavos">' . $centFmt . '</span>';
}

/**
 * Formata um valor como "R$ X.XXX,XX" simples (sem separar em partes) —
 * usado só na linha "de" riscada, igual ao formato já usado no catálogo.
 */
function fmtMoneySimples(float $valor): string {
    return 'R$ ' . number_format($valor, 2, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Tags de Preço</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --red: #2347C8;
    --gray-700: #374151; --gray-500: #6B7280; --gray-300: #D1D5DB; --gray-200: #E5E7EB;
    --white: #FFFFFF; --black: #000000;
    --font: 'Inter', -apple-system, Arial, sans-serif;
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
.toolbar-title { color: rgba(255,255,255,.6); font-size: 9pt; font-family: var(--font); flex: 1; letter-spacing: .04em; }
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

/* ── FOLHA / GRADE DE TAGS ── */
.doc {
    width: 297mm;
    margin: 0 auto;
    background: var(--white);
    box-shadow: 0 4px 32px rgba(0,0,0,.14);
    padding: 6mm;
}
.tags-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 4mm;
}

/* ── TAG ── */
.tag-card {
    border: 1px solid var(--gray-300);
    display: flex;
    flex-direction: column;
    background: var(--white);
    overflow: hidden;
    page-break-inside: avoid;
    break-inside: avoid;
}
.tag-body { padding: 5mm 5mm 3mm; flex: 1; }
.tag-nome { font-size: 14pt; font-weight: 800; text-transform: uppercase; line-height: 1.15; }
.tag-modelo { font-size: 9.5pt; font-weight: 700; text-transform: uppercase; text-align: center; margin-top: 1mm; }
.tag-de { font-size: 8pt; font-weight: 600; color: var(--gray-500); text-decoration: line-through; margin-top: 4mm; }
.tag-preco { margin-top: 4mm; line-height: 1; white-space: nowrap; }
.tag-de + .tag-preco { margin-top: 1mm; }
.tag-preco .por { font-size: 9pt; font-weight: 700; text-transform: uppercase; color: var(--red); margin-right: 2px; }
.tag-preco .rs { font-size: 15pt; font-weight: 700; }
.tag-preco .inteiro { font-size: 32pt; font-weight: 800; }
.tag-preco .centavos { font-size: 13pt; font-weight: 800; vertical-align: super; }
.tag-codigo { font-size: 7pt; color: var(--gray-500); margin-top: 2mm; }
.tag-footer { background: var(--gray-500); color: var(--white); text-align: center; padding: 2mm 0; }
.tag-footer .grupo { font-weight: 400; font-size: 7pt; }
.tag-footer .exemplo { font-weight: 800; font-size: 10pt; }

@media print {
    @page { size: A4 landscape; margin: 0; }
    body { background: var(--white) !important; padding: 0 !important; }
    .toolbar, .toolbar-spacer { display: none !important; }
    .doc { width: 100% !important; box-shadow: none !important; margin: 0 !important; }
}
</style>
</head>
<body>

<div class="toolbar no-print">
    <div class="toolbar-title">Tags de Preço &nbsp;&nbsp;<strong><?= count($itens) ?> <?= count($itens) === 1 ? 'item' : 'itens' ?></strong></div>
    <button class="btn-toolbar btn-back" onclick="window.close()">Fechar</button>
    <button class="btn-toolbar btn-print-main" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
</div>
<div class="toolbar-spacer no-print"></div>

<div class="doc">
    <div class="tags-grid">
        <?php foreach ($itens as $it): ?>
        <div class="tag-card">
            <div class="tag-body">
                <div class="tag-nome"><?= htmlspecialchars($it['acessorio']) ?></div>
                <div class="tag-modelo"><?= htmlspecialchars($it['modelo']) ?></div>
                <?php if (!empty($it['em_promocao']) && isset($it['valor_original'])): ?>
                <div class="tag-de">de <?= fmtMoneySimples((float) $it['valor_original']) ?></div>
                <div class="tag-preco"><span class="por">por</span><?= renderTagPreco((float) $it['valor']) ?></div>
                <?php else: ?>
                <div class="tag-preco"><?= renderTagPreco((float) $it['valor']) ?></div>
                <?php endif; ?>
                <?php if (!empty($it['codigo_item'])): ?>
                <div class="tag-codigo">cód. <?= htmlspecialchars($it['codigo_item']) ?></div>
                <?php endif; ?>
            </div>
            <div class="tag-footer"><span class="grupo">grupo</span> <span class="exemplo">Exemplo</span></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</body>
</html>
