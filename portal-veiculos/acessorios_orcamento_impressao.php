<?php
require_once __DIR__ . '/config_database.php';
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/acessorios_promocao.php';

session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

garantirSchemaPromocaoAcessorios($mysqli);

$ids              = [];
$total            = 0.0;
$numero_orcamento = null;
$user_id          = (int)($_SESSION['usuario_id'] ?? $_SESSION['user_id'] ?? 0);
$id_loja          = (int)($_SESSION['id_loja'] ?? 1);

// VALORES EDITADOS PELO USUÁRIO: "id:valor,id:valor,..."
$valoresEditados = [];
if (!empty($_GET['valores'])) {
    foreach (explode(',', $_GET['valores']) as $par) {
        $par = trim($par);
        if (!$par) continue;
        [$vid, $vval] = explode(':', $par, 2) + [null, null];
        if ($vid !== null && $vval !== null) {
            $valoresEditados[(int)$vid] = (float)str_replace(',', '.', $vval);
        }
    }
}

// MODO 1: orçamento já gravado
if (!empty($_GET['orcamento_id'])) {
    $orcId = (int)$_GET['orcamento_id'];
    $stmt  = $mysqli->prepare("SELECT user_id, ids_itens, total, criado_em, id_loja FROM orcamentos_acessorios WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $orcId);
    $stmt->execute();
    $orc = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$orc) exit('Orçamento não encontrado.');
    $numero_orcamento = $orcId;
    $user_id          = (int)$orc['user_id'];
    $ids              = array_filter(array_map('intval', explode(',', $orc['ids_itens'])));
    $total            = (float)$orc['total'];
    $id_loja          = (int)($orc['id_loja'] ?? $id_loja);
} else {
    // MODO 2: via ?ids=1,2,3
    if (empty($_GET['ids'])) exit('Nenhum item selecionado.');
    $ids = array_filter(array_map('intval', explode(',', $_GET['ids'])));
    if (!$ids) exit('Lista de itens inválida.');
}

// DADOS DO VENDEDOR
$vend_nome = $_SESSION['nome']        ?? '';
$vend_tel  = $_SESSION['tel_usuario'] ?? '';
if ($user_id > 0) {
    $stmt = $mysqli->prepare("SELECT nome, telefone FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        $vend_nome = $row['nome']     ?? $vend_nome;
        $vend_tel  = $row['telefone'] ?? $vend_tel;
    }
    $stmt->close();
}

// DADOS DA LOJA
$stmt = $mysqli->prepare("SELECT nome, cnpj, telefone, endereco FROM lojas WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id_loja);
$stmt->execute();
$loja = $stmt->get_result()->fetch_assoc() ?? [];
$stmt->close();

$loja_nome     = $loja['nome']     ?? 'Empresa Exemplo';
$loja_cnpj     = $loja['cnpj']     ?? '';
$loja_telefone = $loja['telefone'] ?? '';
$loja_endereco = $loja['endereco'] ?? '';

// DADOS DO CLIENTE (via GET)
$cliente_nome = trim($_GET['nome'] ?? '');
$cliente_tel  = trim($_GET['tel']  ?? '');

// DADOS DO VEÍCULO (via GET)
$cliente_chassi = trim($_GET['chassi'] ?? '');
$cliente_placa  = trim($_GET['placa']  ?? '');

// CONDIÇÃO DE PAGAMENTO E DESCONTO
$condicao     = trim($_GET['cond'] ?? '');
$desconto_pct = isset($_GET['desc']) ? (float)str_replace(',', '.', $_GET['desc']) : 0;

// CONDIÇÃO DE PARCELAMENTO SELECIONADA (só vou confiar em qtd e tipo)
$parcelas_n   = isset($_GET['parcelas']) ? (int)$_GET['parcelas'] : null;
$parcela_tipo = trim($_GET['parcela_tipo'] ?? ''); // 'sem_juros' ou 'com_juros'

// ACESSÓRIOS — busca dados do banco, mas sobrescreve valor se foi editado
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types        = str_repeat('i', count($ids));
$stmt = $mysqli->prepare(
    "SELECT id, marca, acessorio, modelo, valor, foto_path, COALESCE(codigo_item,'') AS codigo_item,
     valor_promocional, valor_vendedor, promocao_inicio, promocao_fim
     FROM acessorios_marcas
     WHERE id IN ($placeholders)
     ORDER BY marca, acessorio, modelo"
);
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$res   = $stmt->get_result();
$itens = [];

// Se veio do modo orçamento gravado, total já está definido — senão recalcula
if ($numero_orcamento === null) $total = 0.0;

while ($row = $res->fetch_assoc()) {
    // Só recalcula o preço promocional ao gerar um orçamento NOVO. Numa reimpressão
    // (orcamento_id já existente), o preço promocional de hoje pode não ser mais o
    // mesmo de quando o total foi congelado — melhor mostrar o valor base, previsível,
    // do que um valor "efetivo" que muda com o tempo e diverge do total já salvo.
    if ($numero_orcamento === null) {
        $row = aplicarValorEfetivo($row);
    } else {
        $row['em_promocao'] = false;
    }
    // Sobrescreve o valor com o que o usuário editou na tela, se houver
    // (uma edição manual tem prioridade sobre o valor promocional, igual já tinha prioridade sobre o valor normal)
    if (isset($valoresEditados[(int)$row['id']])) {
        $row['valor'] = $valoresEditados[(int)$row['id']];
    }
    $itens[] = $row;
    if ($numero_orcamento === null) $total += (float)$row['valor'];
}
$stmt->close();
if (!$itens) exit('Nenhum acessório encontrado.');

// GRAVAR SE NOVO (grava com o total dos valores editados)
if ($numero_orcamento === null) {
    $stmt = $mysqli->prepare("INSERT INTO orcamentos_acessorios (user_id, ids_itens, total, id_loja) VALUES (?, ?, ?, ?)");
    $ids_str = implode(',', $ids);
    $stmt->bind_param('isdi', $user_id, $ids_str, $total, $id_loja);
    $stmt->execute();
    $numero_orcamento = $stmt->insert_id;
    $stmt->close();

    // Credita o vendedor (usuário que gerou o orçamento) para cada item em promoção ativa
    if ($user_id > 0) {
        $stmtCred = $mysqli->prepare("INSERT INTO acessorios_promocao_creditos (item_id, orcamento_id, user_id, valor_vendedor) VALUES (?, ?, ?, ?)");
        foreach ($itens as $it) {
            if (!empty($it['em_promocao']) && (float)($it['valor_vendedor'] ?? 0) > 0) {
                $itemId = (int)$it['id'];
                $valorVend = (float)$it['valor_vendedor'];
                $stmtCred->bind_param('iiid', $itemId, $numero_orcamento, $user_id, $valorVend);
                $stmtCred->execute();
            }
        }
        $stmtCred->close();
    }
}

// TEXTO LEGAL
$texto_legal = '';
$stmt = $mysqli->prepare("SELECT valor FROM config_sistema WHERE chave = 'texto_legal_orcamento' LIMIT 1");
if ($stmt) {
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) $texto_legal = $row['valor'] ?? '';
    $stmt->close();
}
if (empty($texto_legal)) {
    $texto_legal = "A Fabricante e a rede de concessionárias tratarão os seus dados pessoais para elaborar proposta de serviços ou venda de veículo e prestar os serviços contratados por você ou para comunicar informações pertinentes ao seu veículo. Para saber todas as finalidades, consulte nossa Política de Privacidade disponível em https://www.fiat.com.br/politica-de-privacidade.html.";
}

function fmtMoney($val): string {
    return 'R$ ' . number_format((float)$val, 2, ',', '.');
}

$data_hoje = date('d/m/Y H:i');

// ------------------------
// CÁLCULO DOS TOTAIS
// ------------------------
$subtotal     = $total; // já calculado com valores editados
$desconto_val = ($desconto_pct > 0 ? $subtotal * $desconto_pct / 100 : 0);
$total_final  = $subtotal - $desconto_val;

// ------------------------
// RECÁLCULO DAS PARCELAS
// ------------------------
$parcela_valor = null;
$parcela_total = null;

if ($condicao === 'credito' && $parcelas_n && $total_final > 0) {

    // Defina aqui a taxa de juros que você realmente usa (ex.: 2% ao mês)
    $taxa_juros = 0.02;

    if ($parcela_tipo === 'com_juros') {
        // fórmula de financiamento com juros compostos
        $i = $taxa_juros;
        $n = $parcelas_n;
        // PMT
        $parcela_valor = $total_final * ($i * pow(1 + $i, $n)) / (pow(1 + $i, $n) - 1);
        $parcela_total = $parcela_valor * $n;
    } else {
        // sem juros (qualquer outro valor de $parcela_tipo cai aqui)
        $parcela_valor = $total_final / $parcelas_n;
        $parcela_total = $total_final;
        $parcela_tipo  = 'sem_juros';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Orçamento de Acessórios #<?= $numero_orcamento ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --red: #2347C8; --red-dark: #B0000E; --red-light: #EEF2FD;
    --gray-900: #4B5563; --gray-700: #374151; --gray-500: #6B7280;
    --gray-400: #9CA3AF; --gray-200: #E5E7EB; --gray-100: #F3F4F6;
    --gray-50: #F9FAFB; --white: #FFFFFF; --black: #000000;
    --font: 'Inter', -apple-system, Arial, sans-serif;
}
html { font-size: 10pt; }
body {
    font-family: var(--font);
    color: var(--gray-900);
    background: #EAECEF;
    padding: 8mm 0;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.doc {
    max-width: 210mm;
    margin: 0 auto;
    background: var(--white);
    box-shadow: 0 4px 32px rgba(0,0,0,.14);
}

/* ── HEADER ── */
.doc-header {
    background: #E5E7EB;
    display: flex;
    align-items: stretch;
    min-height: 18mm;
}
.header-brand {
    flex: 1;
    padding: 4mm 6mm;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 3px;
}
.header-logo-text { font-size: 15pt; font-weight: 700; color: #111827; letter-spacing: -0.5px; line-height: 1; }
.header-tagline { font-size: 7.5pt; color: #4B5563; letter-spacing: .12em; text-transform: uppercase; font-weight: 400; }
.header-loja {
    padding: 4mm 6mm;
    border-left: 1px solid #D1D5DB;
    display: flex; flex-direction: column; justify-content: center; gap: 3px;
    min-width: 60mm;
}
.header-loja-name { font-size: 9pt; font-weight: 600; color: #111827; line-height: 1.3; }
.header-loja-detail { font-size: 7.5pt; color: #4B5563; line-height: 1.6; }
.header-vendedor {
    padding: 4mm 6mm;
    border-left: 1px solid #D1D5DB;
    display: flex; align-items: center;
}
.header-stripe { height: 4px; background: #9CA3AF; }
.vendedor-chip {
    display: inline-flex; align-items: center; gap: 6px;
    background: var(--gray-100); border: 1px solid var(--gray-200);
    border-radius: 20px; padding: 3px 10px 3px 4px;
}
.vendedor-avatar {
    width: 22px; height: 22px; border-radius: 50%; background: var(--red);
    display: flex; align-items: center; justify-content: center;
    font-size: 7pt; font-weight: 700; color: var(--white); flex-shrink: 0;
}
.vendedor-info { font-size: 7.5pt; color: var(--gray-700); line-height: 1.4; }
.vendedor-info strong { display: block; font-weight: 600; color: var(--gray-900); font-size: 8pt; }

/* ── META ── */
.doc-meta {
    background: var(--gray-50); border-bottom: 1px solid var(--gray-200);
    padding: 2.5mm 8mm; display: flex; align-items: center; justify-content: space-between; gap: 8px;
}
.doc-meta-title { font-size: 11pt; font-weight: 700; color: var(--gray-900); letter-spacing: -0.3px; }
.doc-meta-title span { color: var(--red); }
.doc-meta-badges { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 8px; border-radius: 4px; font-size: 7pt;
    font-weight: 600; letter-spacing: .04em; text-transform: uppercase;
}
.badge-num  { background: var(--red-light); color: var(--red-dark); border: 1px solid rgba(35,71,200,.15); }
.badge-date { background: var(--gray-100); color: var(--gray-700); }
.badge-tipo { background: var(--gray-900); color: var(--white); }

/* ── BODY ── */
.doc-body { padding: 4mm 8mm 6mm; }
.sec { margin-bottom: 4mm; page-break-inside: avoid; }
.sec-head { display: flex; align-items: center; gap: 6px; margin-bottom: 2.5mm; }
.sec-head-bar { width: 3px; height: 14px; background: var(--red); border-radius: 2px; flex-shrink: 0; }
.sec-head-label { font-size: 7pt; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--gray-500); }

/* ── CLIENTE ── */
.cliente-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 4mm; }
.cliente-cell-label { font-size: 7pt; color: var(--gray-400); font-weight: 500; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 3px; }
.cliente-cell-value { font-size: 9.5pt; font-weight: 600; color: var(--gray-900); }

/* ── GRID ACESSÓRIOS ── */
.acc-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 2.5mm;
}
.acc-card {
    border-radius: 5px;
    overflow: hidden;
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    display: flex;
    flex-direction: column;
}
.acc-foto {
    width: 100%;
    aspect-ratio: 4/3;
    background: var(--gray-100);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.acc-foto img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.acc-foto-empty {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 2px; color: var(--gray-400); font-size: 6pt;
}
.acc-foto-empty-icon { font-size: 14pt; line-height: 1; }
.acc-info { padding: 2mm; flex: 1; display: flex; flex-direction: column; gap: 1px; }
.acc-marca { font-size: 5.5pt; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
.acc-marca.FIAT { color: #2347C8; }
.acc-marca.JEEP { color: #3a8c3e; }
.acc-marca.RAM  { color: #b8972a; }
.acc-nome { font-size: 7pt; font-weight: 700; color: var(--gray-900); line-height: 1.2; text-transform: uppercase; }
.acc-modelo { font-size: 5.5pt; color: var(--gray-500); line-height: 1.3; flex: 1; }
.acc-codigo { font-size: 5.5pt; color: var(--gray-400); line-height: 1.3; }
.acc-preco-bar {
    background: #4B5563;
    padding: 1.5mm 2mm;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.acc-preco-label { font-size: 5.5pt; color: rgba(255,255,255,.8); text-transform: uppercase; letter-spacing: .06em; }
.acc-preco-val   { font-size: 8pt; font-weight: 700; color: var(--white); }

/* ── TOTAL ── */
.total-strip {
    background: var(--red);
    border-radius: 6px;
    padding: 3mm 5mm;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 2mm;
}
.total-label { font-size: 9pt; font-weight: 700; color: var(--white); text-transform: uppercase; letter-spacing: .06em; }
.total-val   { font-size: 13pt; font-weight: 700; color: var(--white); }

/* ── LEGAL ── */
.legal-box {
    background: var(--gray-50); border-radius: 3px; padding: 2mm 3mm;
    font-size: 5pt; line-height: 1.7; color: var(--gray-500); text-align: justify;
}

/* ── ASSINATURA ── */
.assinatura-row { display: flex; gap: 6mm; margin-top: 4mm; }
.assinatura-box { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; }
.assinatura-linha { border-top: 1px solid var(--gray-400); margin-bottom: 2mm; }
.assinatura-label { font-size: 7pt; color: var(--gray-400); text-align: center; }

/* ── FOOTER ── */
.doc-footer {
    border-top: 1px solid var(--gray-200);
    padding: 2mm 8mm;
    display: flex; align-items: flex-end; justify-content: space-between; gap: 8px;
}
.footer-info { font-size: 7.5pt; color: var(--gray-400); line-height: 1.7; }
.footer-info strong { font-weight: 600; color: #4B5563; display: block; margin-bottom: 1px; }
.footer-validade {
    font-size: 8pt; color: var(--white); background: var(--red);
    padding: 3mm 5mm; border-radius: 6px; text-align: center; white-space: nowrap; font-weight: 500;
}
.footer-validade strong { display: block; font-size: 10pt; font-weight: 700; }

/* ── TOOLBAR ── */
.toolbar.no-print {
    position: fixed; top: 0; left: 0; right: 0; height: 48px;
    background: var(--gray-900); display: flex; align-items: center;
    gap: 8px; padding: 0 16px; z-index: 1000; box-shadow: 0 2px 12px rgba(0,0,0,.3);
}
.toolbar-title { color: rgba(255,255,255,.5); font-size: 9pt; font-family: var(--font); flex: 1; letter-spacing: .04em; }
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

@media print {
    @page { size: A4; margin: 0; }
    html { font-size: 8pt; }
    body { background: var(--white) !important; padding: 0 !important; }
    .toolbar, .toolbar-spacer { display: none !important; }
    .doc { max-width: 100% !important; box-shadow: none !important; margin: 0 !important; }
    .sec { page-break-inside: avoid; margin-bottom: 3mm !important; }
    .doc-header { min-height: 16mm !important; }
    .header-brand { padding: 4mm 6mm !important; }
    .header-logo-text { font-size: 14pt !important; }
    .header-loja { padding: 4mm 6mm !important; min-width: 60mm !important; }
    .header-loja-detail, .header-tagline { font-size: 6.5pt !important; }
    .header-vendedor { padding: 4mm 6mm !important; }
    .doc-meta { padding: 2.5mm 6mm !important; }
    .doc-meta-title { font-size: 11pt !important; }
    .doc-body { padding: 3mm 6mm 3mm !important; }
    .acc-grid { grid-template-columns: repeat(4,1fr) !important; gap: 2mm !important; }
    .acc-info { padding: 2mm !important; }
    .acc-preco-bar { padding: 2mm !important; }
    .acc-preco-val { font-size: 8.5pt !important; }
    .total-strip { padding: 3mm 5mm !important; }
    .total-val { font-size: 13pt !important; }
    .legal-box { font-size: 4.5pt !important; }
    .assinatura-row { margin-top: 5mm !important; height: 18mm !important; }
    .doc-footer { padding: 2mm 6mm !important; }
}
</style>
</head>
<body>

<div class="toolbar no-print">
    <div class="toolbar-title">
        Orçamento de Acessórios &nbsp;&nbsp;<strong>#<?= $numero_orcamento ?></strong>
    </div>
    <button class="btn-toolbar btn-back" onclick="window.location.href='menu.php'">Voltar</button>
    <button class="btn-toolbar btn-print-main" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
</div>
<div class="toolbar-spacer no-print"></div>

<div class="doc">

    <!-- HEADER -->
    <header class="doc-header">
        <div class="header-brand">
            <div class="header-logo-text">Grupo <span style="color:#111827;">Exemplo</span></div>
            <div class="header-tagline">Concessionária Oficial FIAT · JEEP · RAM</div>
        </div>
        <div class="header-loja">
            <div class="header-loja-name"><?= htmlspecialchars($loja_nome) ?></div>
            <div class="header-loja-detail">
                <?php if ($loja_cnpj): ?>CNPJ: <?= htmlspecialchars($loja_cnpj) ?><br><?php endif; ?>
                <?php if ($loja_telefone): ?>Tel. <?= htmlspecialchars($loja_telefone) ?><?php endif; ?>
            </div>
        </div>
        <?php if ($vend_nome): ?>
        <?php
            $iniciais = '';
            foreach (explode(' ', trim($vend_nome)) as $p) {
                if ($p) { $iniciais .= strtoupper($p[0]); if (strlen($iniciais) >= 2) break; }
            }
        ?>
        <div class="header-vendedor">
            <div class="vendedor-chip">
                <div class="vendedor-avatar"><?= htmlspecialchars($iniciais) ?></div>
                <div class="vendedor-info">
                    <strong><?= htmlspecialchars($vend_nome) ?></strong>
                    Consultor<?= $vend_tel ? ' · ' . htmlspecialchars($vend_tel) : '' ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </header>
    <div class="header-stripe"></div>

    <!-- META -->
    <div class="doc-meta">
        <div class="doc-meta-title">Orçamento de <span>Acessórios</span></div>
        <div class="doc-meta-badges">
            <span class="badge badge-num">Nº <?= $numero_orcamento ?></span>
            <span class="badge badge-date"><?= $data_hoje ?></span>
        </div>
    </div>

    <div class="doc-body">

        <!-- CLIENTE -->
        <?php if ($cliente_nome || $cliente_tel): ?>
        <div class="sec">
            <div class="sec-head">
                <div class="sec-head-bar"></div>
                <div class="sec-head-label">Dados do Cliente</div>
            </div>
            <div class="cliente-grid">
                <?php if ($cliente_nome): ?>
                <div style="grid-column: span <?= $cliente_tel ? 2 : 3 ?>;">
                    <div class="cliente-cell-label">Nome completo</div>
                    <div class="cliente-cell-value"><?= htmlspecialchars($cliente_nome) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($cliente_tel): ?>
                <div>
                    <div class="cliente-cell-label">Telefone</div>
                    <div class="cliente-cell-value"><?= htmlspecialchars($cliente_tel) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- VEÍCULO -->
        <?php if ($cliente_chassi || $cliente_placa): ?>
        <div class="sec">
            <div class="sec-head">
                <div class="sec-head-bar"></div>
                <div class="sec-head-label">Dados do Veículo</div>
            </div>
            <div class="cliente-grid">
                <?php if ($cliente_chassi): ?>
                <div style="grid-column: span <?= $cliente_placa ? 2 : 3 ?>;">
                    <div class="cliente-cell-label">Chassi</div>
                    <div class="cliente-cell-value" style="font-family:monospace;"><?= htmlspecialchars($cliente_chassi) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($cliente_placa): ?>
                <div>
                    <div class="cliente-cell-label">Placa</div>
                    <div class="cliente-cell-value" style="font-family:monospace;"><?= htmlspecialchars($cliente_placa) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ACESSÓRIOS -->
        <div class="sec">
            <div class="sec-head">
                <div class="sec-head-bar"></div>
                <div class="sec-head-label">Itens do Orçamento (<?= count($itens) ?> <?= count($itens) === 1 ? 'item' : 'itens' ?>)</div>
            </div>
            <div class="acc-grid">
                <?php foreach ($itens as $it):
                    $fotoSrc    = !empty($it['foto_path']) ? $it['foto_path'] : '';
                    $marca      = strtoupper($it['marca'] ?? '');
                    $foiEditado = isset($valoresEditados[(int)$it['id']]);
                ?>
                <div class="acc-card">
                    <div class="acc-foto">
                        <?php if ($fotoSrc): ?>
                            <img src="<?= htmlspecialchars($fotoSrc) ?>" alt="<?= htmlspecialchars($it['acessorio']) ?>" loading="lazy">
                        <?php else: ?>
                            <div class="acc-foto-empty">
                                <div class="acc-foto-empty-icon">🔧</div>
                                <span>Sem imagem</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="acc-info">
                        <div class="acc-marca <?= htmlspecialchars($marca) ?>"><?= htmlspecialchars($marca) ?></div>
                        <div class="acc-nome"><?= htmlspecialchars($it['acessorio']) ?></div>
                        <div class="acc-modelo"><?= htmlspecialchars($it['modelo']) ?></div>
                        <?php if (!empty($it['codigo_item'])): ?>
                        <div class="acc-codigo"><?= htmlspecialchars($it['codigo_item']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="acc-preco-bar">
                        <span class="acc-preco-label">Valor</span>
                        <div style="text-align:right;">
                            <span class="acc-preco-val"><?= fmtMoney($it['valor']) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- TOTAIS -->
            <div class="total-strip" style="background:#4B5563;margin-top:2mm;">
                <span class="total-label">Subtotal</span>
                <span class="total-val"><?= fmtMoney($subtotal) ?></span>
            </div>

            <?php if ($desconto_pct > 0): ?>
            <div class="total-strip" style="background:#1b7a1b;margin-top:2px;">
                <span class="total-label">Desconto (<?= number_format($desconto_pct, 2, ',', '.') ?>%)</span>
                <span class="total-val">- <?= fmtMoney($desconto_val) ?></span>
            </div>
            <?php endif; ?>

            <div class="total-strip" style="margin-top:2px;">
                <span class="total-label">Total</span>
                <span class="total-val"><?= fmtMoney($total_final) ?></span>
            </div>

            <!-- CONDIÇÃO DE PAGAMENTO -->
            <div class="sec" style="margin-top:6px;">
                <div class="sec-head">
                    <div class="sec-head-bar"></div>
                    <div class="sec-head-label">Condição de Pagamento</div>
                </div>
                <div style="font-size:8.5pt; color:#374151; line-height:1.8;">
                    Forma de pagamento: <strong><?= ($condicao ? htmlspecialchars(ucfirst(str_replace('_', ' ', $condicao))) : 'Não informada') ?></strong><br>
                    <?php if ($desconto_pct > 0): ?>
                    Desconto aplicado: <strong><?= number_format($desconto_pct, 2, ',', '.') ?>%</strong> (<?= fmtMoney($desconto_val) ?>)<br>
                    <?php endif; ?>
                    <?php if ($condicao === 'credito' && $parcelas_n && $parcela_valor && $parcela_total): ?>
                    Parcelamento: <strong><?= $parcelas_n ?>x de <?= fmtMoney($parcela_valor) ?></strong>
                    (<?= $parcela_tipo === 'sem_juros' ? 'sem juros' : 'com juros' ?>) — Total: <strong><?= fmtMoney($parcela_total) ?></strong>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- LGPD -->
        <div class="sec">
            <div class="sec-head">
                <div class="sec-head-bar" style="background:var(--gray-400);"></div>
                <div class="sec-head-label">Privacidade e Proteção de Dados</div>
            </div>
            <div class="legal-box">
                <?= nl2br(htmlspecialchars($texto_legal)) ?>
            </div>
        </div>

        <!-- ASSINATURA -->
        <div class="assinatura-row">
            <div class="assinatura-box">
                <div class="assinatura-linha"></div>
                <div class="assinatura-label">Assinatura do Cliente</div>
            </div>
            <div class="assinatura-box">
                <div class="assinatura-linha"></div>
                <div class="assinatura-label">Assinatura do Consultor</div>
            </div>
        </div>

    </div>

    <!-- FOOTER -->
    <footer class="doc-footer">
        <div class="footer-info">
            <strong>Empresa Exemplo · Concessionária Oficial</strong>
            Orçamento gerado em <?= $data_hoje ?> pelo consultor
            <?= htmlspecialchars($vend_nome) ?> — loja <?= htmlspecialchars($loja_nome) ?>.<br>
            Condições sujeitas à disponibilidade de estoque e aprovação comercial.
        </div>
        <div class="footer-validade">
            Válido por
            <strong>7 dias</strong>
            a partir da emissão
        </div>
    </footer>

</div>
</body>
</html>