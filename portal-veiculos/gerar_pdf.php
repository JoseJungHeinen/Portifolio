<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

// RECEBE DADOS DO POST
$tipo_orcamento = $_POST['tipo_orcamento'] ?? '';
$loja           = $_POST['loja'] ?? '';
$cnpj_loja      = $_POST['cnpj_loja'] ?? 'XX.XXX.XXX/0001-XX';
$id_loja        = $_POST['id_loja'] ?? 0;

// Dados do cliente
$cliente_nome     = $_POST['cliente_nome'] ?? '';
$cliente_cpf      = $_POST['cliente_cpf'] ?? '';
$cliente_telefone = $_POST['cliente_telefone'] ?? '';

// Veículos vendidos
$veiculos = isset($_POST['veiculos']) && is_array($_POST['veiculos']) ? $_POST['veiculos'] : [];

foreach ($veiculos as $i => $v) {
    $foto = $v['foto'] ?? '';
    if ($foto !== '') {
        $veiculos[$i]['foto'] = $foto;
    }
}

// Veículos em troca
$troca_modelo = $_POST['troca_modelo'] ?? [];
$troca_ano    = $_POST['troca_ano']    ?? [];
$troca_cor    = $_POST['troca_cor']    ?? [];
$troca_placa  = $_POST['troca_placa']  ?? [];
$troca_valor  = $_POST['troca_valor']  ?? [];

// Tabela Gama
$gama_vd           = $_POST['gama_vd']              ?? '';
$gama_vr           = $_POST['gama_vr']              ?? '';
$gama_desc_mont_vd = $_POST['gama_desc_mont_vd']    ?? '';
$gama_desc_ced_vd  = $_POST['gama_desc_ced_vd']     ?? '';
$gama_desc_ced_vr  = $_POST['gama_desc_ced_vr']     ?? '';
$gama_desc_ced_vd_tipo = $_POST['gama_desc_ced_vd_tipo'] ?? 'pct';
$gama_desc_ced_vr_tipo = $_POST['gama_desc_ced_vr_tipo'] ?? 'pct';

// Opcionais
$opcionais = array_filter(array_map('trim', $_POST['opcionais'] ?? []));

$isVD     = ($tipo_orcamento === 'Novos Venda Direta');
$isVarejo = ($tipo_orcamento === 'Novos Varejo' || $tipo_orcamento === 'Usado');

// Bonificações
$bonif_item  = $_POST['bonif_item']  ?? [];
$bonif_valor = $_POST['bonif_valor'] ?? [];

// Custos adicionais
$transferencia = $_POST['transferencia'] ?? 0;
$emplacamento  = $_POST['emplacamento']  ?? 0;
$seguro        = $_POST['seguro']        ?? 0;  // NOVO

function unmaskMoney($str) {
    if (!$str) return 0;
    $str = str_replace(['R$', ' ', "\xc2\xa0"], '', $str);
    $str = str_replace('.', '', $str);
    $str = str_replace(',', '.', $str);
    return (float)$str;
}

function fmtMoney($val) {
    return 'R$ ' . number_format($val, 2, ',', '.');
}

function parsePct($str) {
    $str = str_replace('%', '', $str);
    $str = str_replace(',', '.', $str);
    $val = (float)$str;
    return ($val > 0) ? $val : null;
}

$valor_transferencia = unmaskMoney($transferencia);
$valor_emplacamento  = unmaskMoney($emplacamento);
$valor_seguro        = unmaskMoney($seguro);
// Observações / desconto venda direta
$observacoes       = $_POST['observacoes']       ?? '';
$pct_desconto      = $_POST['pct_desconto']      ?? '';
$tipo_venda_direta = $_POST['tipo_venda_direta'] ?? '';
$tipo_venda_outros = $_POST['tipo_venda_outros'] ?? '';

/** CÁLCULOS **/
$valor_total_veiculos_vendidos = 0;
foreach ($veiculos as $v) {
    if (!empty($v['nome']) && !empty($v['valor'])) {
        $valor_total_veiculos_vendidos += unmaskMoney($v['valor']);
    }
}

$valor_total_troca = 0;
$troca_descricao_db = '';
$troca_valor_db     = 0.0;
$linhas_troca = [];
foreach ($troca_modelo as $i => $modelo) {
    $modelo = trim($modelo);
    if ($modelo === '') continue;
    $val = unmaskMoney($troca_valor[$i] ?? '');
    $valor_total_troca  += $val;
    $troca_valor_db     += $val;
    $linhas_troca[] = implode('|', [$modelo, $troca_ano[$i] ?? '', $troca_cor[$i] ?? '', $troca_placa[$i] ?? '']);
}
if (!empty($linhas_troca)) $troca_descricao_db = implode("\n", $linhas_troca);



$gamaVD_val  = unmaskMoney($gama_vd);
$gamaVR_val  = unmaskMoney($gama_vr);

$pctMont  = parsePct($gama_desc_mont_vd);
$subVD    = ($pctMont !== null && $gamaVD_val > 0) ? $gamaVD_val - ($gamaVD_val * $pctMont / 100) : $gamaVD_val;
$subVR    = $gamaVR_val;

$totalGamaVD = $subVD;
if (trim($gama_desc_ced_vd) !== '' && $subVD > 0) {
    if ($gama_desc_ced_vd_tipo === 'pct') {
        $pct = parsePct($gama_desc_ced_vd);
        if ($pct !== null) $totalGamaVD = $subVD - ($subVD * $pct / 100);
    } else {
        $val = unmaskMoney($gama_desc_ced_vd);
        if ($val > 0) $totalGamaVD = $subVD - $val;
    }
}

$totalGamaVR = $subVR;
if (trim($gama_desc_ced_vr) !== '' && $subVR > 0) {
    if ($gama_desc_ced_vr_tipo === 'pct') {
        $pct = parsePct($gama_desc_ced_vr);
        if ($pct !== null) $totalGamaVR = $subVR - ($subVR * $pct / 100);
    } else {
        $val = unmaskMoney($gama_desc_ced_vr);
        if ($val > 0) $totalGamaVR = $subVR - $val;
    }
}

$temDescCedido = (trim($gama_desc_ced_vd) !== '' || trim($gama_desc_ced_vr) !== '');

$valor_venda_total = ($isVD ? $totalGamaVD : $totalGamaVR) + $valor_transferencia + $valor_emplacamento + $valor_seguro;
$pagamento_total   = $valor_total_troca;

$cpf_usuario  = $_SESSION['cpf']      ?? '';
$nome_usuario = $_SESSION['nome']     ?? '';
$tel_usuario  = $_SESSION['telefone'] ?? '';

if (!empty($cpf_usuario)) {
    $stmtUser = $mysqli->prepare("SELECT telefone FROM usuarios WHERE cpf = ? LIMIT 1");
    if ($stmtUser) {
        $stmtUser->bind_param('s', $cpf_usuario);
        $stmtUser->execute();
        $resUser = $stmtUser->get_result();
        if ($rowUser = $resUser->fetch_assoc()) {
            $tel_usuario = $rowUser['telefone'] ?? '';
        }
        $stmtUser->close();
    }
}

// GRAVAR NO BANCO
$primeiro_veiculo = !empty($veiculos[0]['nome']) ? $veiculos[0]['nome'] : '';
$detalhes = "Tipo: $tipo_orcamento | Loja: $loja | Veículos: " . count($veiculos);

$id_loja_db = (int)$id_loja;
$stmt = $mysqli->prepare("
    INSERT INTO orcamento
    (id_loja, cpf_usuario, cliente_nome, cliente_cpf, cliente_telefone,
     nome_cliente, descricao, quantidade, preco_unitario,
     desconto, adicional, subtotal, valor_total, detalhes,
     troca_descricao, troca_valor)
    VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, 0, 0, ?, ?, ?, ?, ?)
");
if ($stmt) {
    $stmt->bind_param(
        'issssssdddssd',
        $id_loja_db,
        $cpf_usuario, $cliente_nome, $cliente_cpf, $cliente_telefone,
        $nome_usuario, $primeiro_veiculo,
        $valor_total_veiculos_vendidos,
        $valor_venda_total, $pagamento_total, $detalhes,
        $troca_descricao_db, $troca_valor_db
    );
    $stmt->execute();
    $orcamento_id = $mysqli->insert_id;
    $stmt->close();
}

$numero_orcamento = $orcamento_id ?? date('YmdHis');
$data_orcamento   = date('d/m/Y H:i');

$loja_telefone = '';
if ($id_loja > 0) {
    $stmtLoja = $mysqli->prepare("SELECT telefone FROM lojas WHERE id = ? LIMIT 1");
    if ($stmtLoja) {
        $stmtLoja->bind_param('i', $id_loja);
        $stmtLoja->execute();
        $resLoja = $stmtLoja->get_result();
        if ($rowLoja = $resLoja->fetch_assoc()) {
            $loja_telefone = $rowLoja['telefone'] ?? '';
        }
        $stmtLoja->close();
    }
}

$texto_legal = '';
$stmt = $mysqli->prepare("SELECT valor FROM config_sistema WHERE chave = 'texto_legal_orcamento' LIMIT 1");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $texto_legal = $row['valor'] ?? '';
    }
    $stmt->close();
}
if (empty($texto_legal)) {
    $texto_legal = 'A Fabricante e a rede de concessionárias tratarão os seus dados pessoais de acordo com a legislação vigente.';
}

$temGama   = ($isVD && $gamaVD_val > 0) || ($isVarejo && $gamaVR_val > 0);
$temTroca  = false;
foreach ($troca_modelo as $m) {
    if (trim($m) !== '') { $temTroca = true; break; }
}
$temBonif  = !empty($bonif_item) && !empty($bonif_item[0]);
$temObs    = !empty($observacoes);
$temVD     = $isVD && !empty($pct_desconto);

$valorFinalDestaque = $isVD ? $totalGamaVD : $totalGamaVR;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orçamento #<?= $numero_orcamento ?> - Empresa Exemplo</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --red: #2347C8;
            --red-dark: #B0000E;
            --red-light: #EEF2FD;
            --gray-900: #4B5563;
            --gray-700: #374151;
            --gray-500: #6B7280;
            --gray-400: #9CA3AF;
            --gray-200: #E5E7EB;
            --gray-100: #F3F4F6;
            --gray-50:  #F9FAFB;
            --white: #FFFFFF;
            --black: #000000;
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
            padding: 0;
            display: flex;
            align-items: stretch;
            min-height: 24mm;
        }
        .header-brand {
            flex: 1;
            padding: 7mm 8mm;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 3px;
        }
        .header-logo-text {
            font-size: 18pt;
            font-weight: 700;
            color: #111827;
            letter-spacing: -0.5px;
            line-height: 1;
        }
        .header-logo-text em { color: #111827; font-style: normal; }
        .header-tagline {
            font-size: 7.5pt;
            color: #4B5563;
            letter-spacing: .12em;
            text-transform: uppercase;
            font-weight: 400;
        }
        .header-loja {
            padding: 7mm 8mm;
            border-left: 1px solid #D1D5DB;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 3px;
            min-width: 72mm;
        }
        .header-loja-name {
            font-size: 9pt;
            font-weight: 600;
            color: #111827;
            line-height: 1.3;
        }
        .header-loja-detail {
            font-size: 7.5pt;
            color: #4B5563;
            line-height: 1.6;
        }
        .header-vendedor {
            padding: 7mm 8mm;
            border-left: 1px solid #D1D5DB;
            display: flex;
            align-items: center;
        }
        .header-stripe { height: 4px; background: #9CA3AF; }

        .doc-meta {
            background: var(--gray-50);
            border-bottom: 1px solid var(--gray-200);
            padding: 4mm 8mm;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .doc-meta-title {
            font-size: 13pt;
            font-weight: 700;
            color: var(--gray-900);
            letter-spacing: -0.3px;
        }
        .doc-meta-title span { color: var(--red); }
        .doc-meta-badges {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 7pt;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .badge-num  { background: var(--red-light); color: var(--red-dark); border: 1px solid rgba(35,71,200,.15); }
        .badge-date { background: var(--gray-100); color: var(--gray-700); }
        .badge-tipo { background: var(--gray-900); color: var(--white); }

        .doc-body { padding: 6mm 8mm 8mm; }

        .sec { margin-bottom: 6mm; page-break-inside: avoid; }
        .sec-head {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 4mm;
        }
        .sec-head-bar {
            width: 3px;
            height: 14px;
            background: var(--red);
            border-radius: 2px;
            flex-shrink: 0;
        }
        .sec-head-label {
            font-size: 7pt;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--gray-500);
        }

        /* ── CLIENTE ── */
        .cliente-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4mm;
            background: transparent;
        }
        .cliente-cell { background: transparent; padding: 0 0 2mm 0; }
        .cliente-cell-label {
            font-size: 7pt;
            color: var(--gray-400);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 3px;
        }
        .cliente-cell-value {
            font-size: 9.5pt;
            font-weight: 600;
            color: var(--gray-900);
        }

        /* ── VENDEDOR CHIP ── */
        .vendedor-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--gray-100);
            border: 1px solid var(--gray-200);
            border-radius: 20px;
            padding: 3px 10px 3px 4px;
            margin-top: 3mm;
        }
        .vendedor-avatar {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--red);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 7pt;
            font-weight: 700;
            color: var(--black);
            flex-shrink: 0;
        }
        .vendedor-info { font-size: 7.5pt; color: var(--gray-700); line-height: 1.4; }
        .vendedor-info strong {
            display: block;
            font-weight: 600;
            color: var(--gray-900);
            font-size: 8pt;
        }

        /* ── VEÍCULO CARD ── */
        .veiculo-card {
            border-radius: 10px;
            overflow: hidden;
            background: var(--gray-50);
        }
        .veiculo-card-top {
            display: flex;
            align-items: stretch;
        }
        .veiculo-foto-wrap {
            flex: 0 0 110mm;
            max-width: 110mm;
            background: var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 65mm;
            padding: 4mm;
            position: relative;
        }
        .veiculo-foto-figure { width: 100%; text-align: center; position: relative; }
        .veiculo-foto-wrap img {
            width: 100%;
            max-height: 63mm;
            object-fit: contain;
            display: block;
        }
        .veiculo-foto-caption {
            position: absolute;
            bottom: 4px;
            right: 4px;
            background: rgba(17,24,39,0.65);
            color: #FFFFFF;
            font-size: 6pt;
            padding: 2px 5px;
            border-radius: 3px;
            white-space: nowrap;
            letter-spacing: .02em;
        }
        .veiculo-foto-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            color: var(--gray-400);
        }
        .veiculo-foto-empty-icon { font-size: 22pt; line-height: 1; }
        .veiculo-foto-empty-txt  { font-size: 7.5pt; }

        .veiculo-dados {
            flex: 1;
            padding: 5mm 6mm;
            display: flex;
            flex-direction: column;
        }
        .veiculo-nome {
            font-size: 12pt;
            font-weight: 700;
            color: var(--gray-900);
            letter-spacing: -0.3px;
            line-height: 1.2;
            margin-bottom: 4mm;
        }

        /* Atributos do veículo — linhas fluidas sem aspecto de tabela */
        .veiculo-attrs { display: flex; flex-direction: column; gap: 0; flex: 1; }
        .attr-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 3.5px 0;
            font-size: 8.5pt;
        }
        .attr-row + .attr-row {
            border-top: none;
        }
        .attr-label { color: var(--gray-400); font-weight: 400; }
        .attr-value { font-weight: 600; color: var(--gray-900); text-align: right; }

        .opcionais-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 3px;
            justify-content: flex-end;
            max-width: 52mm;
        }
        .opt-tag {
            background: var(--gray-100);
            color: var(--gray-700);
            font-size: 7pt;
            font-weight: 500;
            padding: 2px 6px;
            border-radius: 3px;
        }

        /* ── BARRA DE PREÇO ── */
        .veiculo-preco-bar {
            background: #4B5563;
            padding: 4mm 6mm;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .veiculo-preco-label {
            font-size: 8pt;
            color: rgba(255,255,255,.9);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .veiculo-preco-valor {
            font-size: 17pt;
            font-weight: 700;
            color: #FFFFFF;
            letter-spacing: -0.5px;
        }
        .veiculo-preco-tag {
            font-size: 7pt;
            font-weight: 600;
            background: #FBBF24;
            color: #111827;
            padding: 2px 7px;
            border-radius: 3px;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        /* ── COMPOSIÇÃO DO VALOR ── */
        .gama-card {
            background: var(--gray-50);
            border-radius: 8px;
            overflow: hidden;
        }
        .gama-card-header {
            padding: 3.5mm 5mm 2mm;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .gama-card-header-label {
            font-size: 7pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--gray-400);
        }
        .gama-card-header-tipo {
            font-size: 7pt;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--gray-500);
        }
        .gama-divider { display: none; }
        .gama-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 3.5mm 5mm;
            font-size: 8.5pt;
        }
        .gama-item + .gama-item {
            border-top: none;
        }
        .gama-item.is-subtotal {
            background: rgba(0,0,0,.025);
            border-top: none !important;
        }
        .gama-item-label        { color: var(--gray-700); }
        .gama-item-label.muted  { color: var(--gray-400); font-style: italic; font-size: 8pt; }
        .gama-item-label.strong { font-weight: 600; color: var(--gray-900); }
        .gama-item-val          { font-weight: 600; color: var(--gray-900); }
        .gama-item-val.desconto { color: #B45309; }

        /* Faixa de total — sem border-top de tabela */
        .gama-total-strip {
            background: #4B5563;
            margin: 0;
            padding: 4mm 5mm;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .gama-total-label {
            font-size: 9pt;
            font-weight: 700;
            color: #FFFFFF;
            text-transform: uppercase;
            letter-spacing: .06em;
        }
        .gama-total-val {
            font-size: 13pt;
            font-weight: 700;
            color: #FFFFFF;
        }

        /* ── TROCA — cards em coluna, sem tabela ── */
        .troca-list { display: flex; flex-direction: column; gap: 3mm; }

        .troca-item-card {
            border-radius: 8px;
            overflow: hidden;
            background: var(--gray-50);
        }
        .troca-item-main {
            padding: 4mm 5mm;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 6mm;
        }
        .troca-item-modelo {
            font-size: 9.5pt;
            font-weight: 700;
            color: var(--gray-900);
            flex: 1;
        }
        .troca-item-price {
            font-size: 11pt;
            font-weight: 700;
            color: var(--gray-900);
            white-space: nowrap;
        }
        .troca-item-meta {
            display: flex;
            gap: 0;
            border-top: none;
        }
        .troca-meta-piece {
            flex: 1;
            padding: 2.5mm 5mm;
            display: flex;
            flex-direction: column;
            gap: 1px;
        }
        .troca-meta-piece + .troca-meta-piece {
            border-left: none;
        }
        .troca-meta-label {
            font-size: 6.5pt;
            color: var(--gray-400);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: .07em;
        }
        .troca-meta-value {
            font-size: 8.5pt;
            font-weight: 600;
            color: var(--gray-700);
        }
        .troca-total-row {
            display: flex;
            justify-content: flex-end;
            margin-top: 2mm;
        }
        .troca-total-pill {
            font-size: 7.5pt;
            color: var(--gray-500);
            background: var(--gray-100);
            border-radius: 5px;
            padding: 3px 10px;
        }
        .troca-total-pill strong { color: var(--gray-900); }

        /* ── BONIFICAÇÕES ── */
        .bonif-list { display: flex; flex-direction: column; gap: 2px; }
        .bonif-item-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 3mm 4mm;
            background: var(--gray-50);
            border-radius: 6px;
            font-size: 8.5pt;
        }
        .bonif-item-name { color: var(--gray-700); font-weight: 500; }
        .bonif-item-val  { color: var(--gray-900); font-weight: 600; }
        .bonif-gift-icon {
            width: 16px;
            height: 16px;
            background: #D1FAE5;
            border-radius: 3px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 9pt;
            margin-right: 5px;
            flex-shrink: 0;
        }

        /* ── OBSERVAÇÕES ── */
        .obs-box {
            background: #FFFBEB;
            border-left: 4px solid #F59E0B;
            border-radius: 6px;
            padding: 4mm 5mm;
            font-size: 8.5pt;
            line-height: 1.6;
            color: #78350F;
        }

        /* ── VENDA DIRETA ── */
        .vd-box {
            background: #EFF6FF;
            border-radius: 8px;
            padding: 4mm 5mm;
        }
        .vd-row {
            display: flex;
            justify-content: space-between;
            font-size: 8.5pt;
            padding: 2px 0;
        }
        .vd-row + .vd-row { border-top: none; padding-top: 4px; margin-top: 2px; }
        .vd-label { color: #1E40AF; font-weight: 500; }
        .vd-value { font-weight: 600; color: #1E3A8A; }

        /* ── LEGAL ── */
        .legal-box {
            background: var(--gray-50);
            border-radius: 3px;
            padding: 2mm 3mm;
            font-size: 5pt;
            line-height: 1.7;
            color: var(--gray-500);
            text-align: justify;
        }

        /* ── FOOTER ── */
        .doc-footer {
            border-top: 1px solid var(--gray-200);
            padding: 3mm 5mm 3mm;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 8px;
        }
        .footer-info { font-size: 7.5pt; color: var(--gray-400); line-height: 1.7; }
        .footer-info strong {
            font-weight: 600;
            color: #4B5563;
            display: block;
            margin-bottom: 1px;
        }
        .footer-validade {
            font-size: 8pt;
            color: var(--white);
            background: var(--red);
            padding: 3mm 5mm;
            border-radius: 6px;
            text-align: center;
            white-space: nowrap;
            font-weight: 500;
        }
        .footer-validade strong {
            display: block;
            font-size: 10pt;
            font-weight: 700;
            letter-spacing: -0.3px;
        }

        /* ── ASSINATURA ── */
        .assinatura-row { display: flex; gap: 6mm; margin-top: 6mm; }
        .assinatura-box {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
        }
        .assinatura-linha {
            border-top: 1px solid var(--gray-400);
            margin-bottom: 2mm;
        }
        .assinatura-label { font-size: 7pt; color: var(--gray-400); text-align: center; }

        /* ── TOOLBAR ── */
        .toolbar.no-print {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 48px;
            background: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 16px;
            z-index: 1000;
            box-shadow: 0 2px 12px rgba(0,0,0,.3);
        }
        .toolbar-title { color: rgba(255,255,255,.5); font-size: 9pt; font-family: var(--font); flex: 1; letter-spacing: .04em; }
        .toolbar-title strong { color: var(--black); font-weight: 600; }
        .btn-toolbar {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 14px;
            border: none;
            border-radius: 5px;
            font-family: var(--font);
            font-size: 8.5pt;
            font-weight: 600;
            cursor: pointer;
            letter-spacing: .02em;
            transition: opacity .15s;
        }
        .btn-toolbar:hover { opacity: .85; }
        .btn-print-main { background: var(--red); color: var(--white); }
        .btn-back { background: rgba(255,255,255,.12); color: var(--white); border: 1px solid rgba(255,255,255,.2); }
        .toolbar-spacer.no-print { height: 48px; }
        .app-version-label.no-print {
            position: fixed;
            bottom: 4px; right: 8px;
            font-size: 8pt;
            color: #888;
            opacity: .5;
            font-family: system-ui, sans-serif;
            pointer-events: none;
            z-index: 9999;
        }

        @media print {
            @page { size: A4; margin: 0; }
            html { font-size: 8pt; }
            body { background: var(--white) !important; padding: 0 !important; }
            .toolbar, .toolbar-spacer, .app-version-label { display: none !important; }
            .doc { max-width: 100% !important; box-shadow: none !important; margin: 0 !important; }
            .sec { page-break-inside: avoid; margin-bottom: 3mm !important; }
            .sec-head { margin-bottom: 2mm !important; }

            /* Header */
            .doc-header { min-height: 16mm !important; }
            .header-brand { padding: 4mm 6mm !important; }
            .header-logo-text { font-size: 14pt !important; }
            .header-tagline { font-size: 6.5pt !important; }
            .header-loja { padding: 4mm 6mm !important; min-width: 60mm !important; }
            .header-loja-name { font-size: 8pt !important; }
            .header-loja-detail { font-size: 6.5pt !important; line-height: 1.4 !important; }
            .header-vendedor { padding: 4mm 6mm !important; }
            .vendedor-info { font-size: 6.5pt !important; }
            .vendedor-info strong { font-size: 7pt !important; }
            .header-stripe { height: 3px !important; }

            /* Meta */
            .doc-meta { padding: 2.5mm 6mm !important; }
            .doc-meta-title { font-size: 11pt !important; }
            .badge { font-size: 6pt !important; padding: 2px 6px !important; }

            /* Body */
            .doc-body { padding: 3mm 6mm 3mm !important; }

            /* Cliente */
            .cliente-grid { gap: 3mm !important; }
            .cliente-cell { padding: 0 !important; }
            .cliente-cell-label { font-size: 6pt !important; margin-bottom: 1px !important; }
            .cliente-cell-value { font-size: 8.5pt !important; }

            /* Veículo */
            .veiculo-foto-wrap {
                flex: 0 0 72mm !important;
                max-width: 72mm !important;
                min-height: 42mm !important;
                padding: 3mm !important;
            }
            .veiculo-foto-wrap img { max-height: 40mm !important; }
            .veiculo-dados { padding: 3.5mm 5mm !important; }
            .veiculo-nome { font-size: 10pt !important; margin-bottom: 2.5mm !important; }
            .attr-row { padding: 2px 0 !important; font-size: 7.5pt !important; }
            .veiculo-preco-bar { padding: 3mm 5mm !important; }
            .veiculo-preco-valor { font-size: 14pt !important; }
            .veiculo-preco-label { font-size: 7pt !important; }

            /* Gama */
            .gama-card-header { padding: 2mm 4mm 1.5mm !important; }
            .gama-item { padding: 2.5mm 4mm !important; font-size: 7.5pt !important; }
            .gama-total-strip { padding: 3mm 4mm !important; }
            .gama-total-val { font-size: 11pt !important; }
            .gama-total-label { font-size: 7.5pt !important; }

            /* Troca */
            .troca-list { gap: 2mm !important; }
            .troca-item-main { padding: 2.5mm 4mm !important; }
            .troca-item-modelo { font-size: 8pt !important; }
            .troca-item-price { font-size: 9.5pt !important; }
            .troca-total-row { margin-top: 1.5mm !important; }

            /* Bonif / Obs / VD */
            .bonif-item-row { padding: 2mm 3mm !important; font-size: 7.5pt !important; }
            .obs-box { padding: 2.5mm 4mm !important; font-size: 7.5pt !important; line-height: 1.4 !important; }
            .vd-box { padding: 2.5mm 4mm !important; }
            .vd-row { font-size: 7.5pt !important; }

            /* Legal */
            .legal-box { padding: 1.5mm 2.5mm !important; font-size: 4.5pt !important; line-height: 1.6 !important; }

            /* Assinatura — espaço para escrita à mão */
            .assinatura-row { margin-top: 5mm !important; margin-bottom: 2mm !important; gap: 16mm !important; height: 18mm !important; }
            .assinatura-box { padding-top: 0 !important; }
            .assinatura-label { font-size: 7pt !important; }

            /* Footer */
            .doc-footer { padding: 2mm 6mm !important; }
            .footer-info { font-size: 6.5pt !important; line-height: 1.5 !important; }
            .footer-validade { padding: 2mm 4mm !important; font-size: 7pt !important; }
            .footer-validade strong { font-size: 9pt !important; }
        }
    </style>
</head>
<body>

<div class="toolbar no-print">
    <div class="toolbar-title">
        Visualização do orçamento&nbsp;&nbsp;<strong>#<?= $numero_orcamento ?></strong>
    </div>
    <button class="btn-toolbar btn-back" onclick="window.location.href='menu.php'">Voltar</button>
    <button class="btn-toolbar btn-print-main" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
</div>
<div class="toolbar-spacer no-print"></div>

<div class="doc">
    <header class="doc-header">
        <div class="header-brand">
            <div class="header-logo-text">Grupo <em>Exemplo</em></div>
            <div class="header-tagline">Concessionária Oficial FIAT · JEEP · RAM</div>
        </div>
        <div class="header-loja">
            <div class="header-loja-name"><?= htmlspecialchars($loja ?: 'Exemplo Veículos') ?></div>
            <div class="header-loja-detail">
                CNPJ: <?= htmlspecialchars($cnpj_loja) ?><br>
                <?php if ($loja_telefone): ?>
                    Tel. <?= htmlspecialchars($loja_telefone) ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($nome_usuario): ?>
            <?php
            $iniciais_header = '';
            $partes_header = explode(' ', trim($nome_usuario));
            foreach ($partes_header as $p) {
                if ($p) {
                    $iniciais_header .= strtoupper($p[0]);
                    if (strlen($iniciais_header) >= 2) break;
                }
            }
            ?>
            <div class="header-vendedor">
                <div class="vendedor-chip">
                    <div class="vendedor-info">
                        <strong><?= htmlspecialchars($nome_usuario) ?></strong>
                        <?php if ($tel_usuario): ?>
                            Consultor · <?= htmlspecialchars($tel_usuario) ?>
                        <?php else: ?>
                            Consultor de vendas
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </header>

    <div class="header-stripe"></div>

    <div class="doc-meta">
        <div class="doc-meta-title">Orçamento de <span>Veículos</span></div>
        <div class="doc-meta-badges">
            <span class="badge badge-num">Nº <?= $numero_orcamento ?></span>
            <span class="badge badge-date"><?= $data_orcamento ?></span>
            <?php if ($tipo_orcamento): ?>
                <span class="badge badge-tipo"><?= htmlspecialchars($tipo_orcamento) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="doc-body">

        <!-- CLIENTE -->
        <?php if ($cliente_nome || $cliente_cpf || $cliente_telefone): ?>
        <div class="sec">
            <div class="sec-head">
                <div class="sec-head-bar"></div>
                <div class="sec-head-label">Dados do Cliente</div>
            </div>
            <div class="cliente-grid">
                <?php if ($cliente_nome): ?>
                <div class="cliente-cell" style="grid-column: span <?= ($cliente_cpf && $cliente_telefone) ? 1 : (($cliente_cpf || $cliente_telefone) ? 2 : 3) ?>;">
                    <div class="cliente-cell-label">Nome completo</div>
                    <div class="cliente-cell-value"><?= htmlspecialchars($cliente_nome) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($cliente_cpf): ?>
                <div class="cliente-cell">
                    <div class="cliente-cell-label">CPF</div>
                    <div class="cliente-cell-value"><?= htmlspecialchars($cliente_cpf) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($cliente_telefone): ?>
                <div class="cliente-cell">
                    <div class="cliente-cell-label">Telefone</div>
                    <div class="cliente-cell-value"><?= htmlspecialchars($cliente_telefone) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- VEÍCULO -->
        <?php if (!empty($veiculos) && !empty($veiculos[0]['nome'])): ?>
        <div class="sec">
            <div class="sec-head">
                <div class="sec-head-bar"></div>
                <div class="sec-head-label">Veículo Orçado</div>
            </div>

            <?php foreach ($veiculos as $idx => $v):
                if (empty($v['nome'])) continue;
                $fotoNome = $v['foto'] ?? '';
                $fotoSrc  = $fotoNome !== '' ? '/Veiculos/img/carros/' . $fotoNome : '';
            ?>
            <div class="veiculo-card" <?= $idx > 0 ? 'style="margin-top:4mm"' : '' ?>>
                <div class="veiculo-card-top">

                    <div class="veiculo-foto-wrap">
                        <?php if ($fotoSrc): ?>
                            <figure class="veiculo-foto-figure">
                                <img src="<?= htmlspecialchars($fotoSrc) ?>" alt="<?= htmlspecialchars($v['nome']) ?>">
                                <figcaption class="veiculo-foto-caption">Imagem meramente ilustrativa</figcaption>
                            </figure>
                        <?php else: ?>
                            <div class="veiculo-foto-empty">
                                <div class="veiculo-foto-empty-icon">🚗</div>
                                <div class="veiculo-foto-empty-txt">Imagem ilustrativa</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="veiculo-dados">
                        <div class="veiculo-nome"><?= htmlspecialchars($v['nome']) ?></div>
                        <div class="veiculo-attrs">
                            <?php if (!empty($v['ano'])): ?>
                            <div class="attr-row">
                                <span class="attr-label">Ano</span>
                                <span class="attr-value"><?= htmlspecialchars($v['ano']) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($v['cor'])): ?>
                            <div class="attr-row">
                                <span class="attr-label">Cor</span>
                                <span class="attr-value"><?= htmlspecialchars($v['cor']) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($opcionais)): ?>
                            <div class="attr-row" style="align-items: flex-start;">
                                <span class="attr-label">Opcionais</span>
                                <div class="opcionais-tags">
                                    <?php
                                    $opCharsUsed = 0;
                                    $opTruncated = false;
                                    foreach ($opcionais as $op):
                                        if ($opCharsUsed >= 500) { $opTruncated = true; break; }
                                        $opSlice = mb_substr($op, 0, 500 - $opCharsUsed);
                                        $opCharsUsed += mb_strlen($opSlice);
                                        if ($opCharsUsed >= 500 && mb_strlen($op) > mb_strlen($opSlice)) $opTruncated = true;
                                    ?>
                                    <span class="opt-tag"><?= htmlspecialchars($opSlice) ?></span>
                                    <?php endforeach; ?>
                                    <?php if ($opTruncated): ?>
                                    <span class="opt-tag" style="color:var(--gray-400);">...</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php
                    $precoTabela = $isVD ? $gamaVD_val : $gamaVR_val;
                    $precoFinal  = $isVD ? $totalGamaVD : $totalGamaVR;
                    $temDesconto = $temGama && $precoFinal < $precoTabela;
                ?>
                <?php if ($precoFinal > 0 || !empty($v['valor'])): ?>
                <div class="veiculo-preco-bar">
                    <?php if ($temDesconto): ?>
                    <div style="text-align: left;">
                        <div class="veiculo-preco-label">Valor de tabela</div>
                        <div style="font-size: 9.5pt; color: rgba(255,255,255,.9); text-decoration: line-through;">
                            <?= fmtMoney($precoTabela) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div>
                        <div class="veiculo-preco-label">
                            <?= $temDesconto ? 'Valor com desconto' : 'Valor do veículo' ?>
                        </div>
                    </div>
                    <div class="veiculo-preco-valor">
                        <?php
                        if ($temGama) {
                            echo fmtMoney($precoFinal > 0 ? $precoFinal : $precoTabela);
                        } else {
                            echo htmlspecialchars($v['valor'] ?? '0,00');
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- COMPOSIÇÃO DO VALOR — sem tabela -->
        <?php if ($temGama): ?>
        <div class="sec">
            <div class="sec-head">
                <div class="sec-head-bar"></div>
                <div class="sec-head-label">Composição do Valor</div>
            </div>
            
            <div class="gama-card">
                <div class="gama-card-header">
                    <span class="gama-card-header-label">Descrição</span>
                    <span class="gama-card-header-tipo"><?= $isVD ? 'Venda Direta' : 'Varejo' ?></span>
                </div>
                <div class="gama-divider"></div>

                <div class="gama-item">
                    <span class="gama-item-label">Valor de venda c/ opcionais</span>
                    <span class="gama-item-val"><?= htmlspecialchars($veiculos[0]['valor'] ?? 'N/A') ?></span>
                </div>

                <?php if ($isVD && $pctMont !== null): ?>
                <div class="gama-item">
                    <span class="gama-item-label muted">Desconto Montadora (<?= number_format($pctMont, 2, ',', '.') ?>%)</span>
                    <span class="gama-item-val desconto"><?= fmtMoney($gamaVD_val - $subVD) ?></span>
                </div>
                <?php endif; ?>

                <div class="gama-item is-subtotal">
                    <span class="gama-item-label strong">Subtotal Veículo</span>
                    <span class="gama-item-val"><?= $isVD ? fmtMoney($subVD) : fmtMoney($subVR) ?></span>
                </div>

                <?php
                $descLabel = $isVD ? $gama_desc_ced_vd : $gama_desc_ced_vr;
                $descTipo  = $isVD ? $gama_desc_ced_vd_tipo : $gama_desc_ced_vr_tipo;
                if (trim($descLabel) !== ''):
                ?>
                <div class="gama-item">
                    <span class="gama-item-label muted">
                        Desconto cedido
                        <?php
                        if ($descTipo === 'pct') {
                            echo ' (' . htmlspecialchars($descLabel) . ')';
                        } else {
                            echo ' (' . fmtMoney(unmaskMoney($descLabel)) . ')';
                        }
                        ?>
                    </span>
                    <span class="gama-item-val desconto">
                        <?= $isVD ? fmtMoney($subVD - $totalGamaVD) : fmtMoney($subVR - $totalGamaVR) ?>
                    </span>
                </div>
                <?php endif; ?>

                <div class="gama-total-strip">
                    <span class="gama-total-label">Total Veículo</span>
                    <span class="gama-total-val"><?= $isVD ? fmtMoney($totalGamaVD) : fmtMoney($totalGamaVR) ?></span>
                </div>

                <?php if ($valor_transferencia > 0): ?>
                <div class="gama-item">
                    <span class="gama-item-label">Transferência</span>
                    <span class="gama-item-val"><?= fmtMoney($valor_transferencia) ?></span>
                </div>
                <?php endif; ?>

                <?php if ($valor_emplacamento > 0): ?>
                <div class="gama-item">
                    <span class="gama-item-label">Emplacamento</span>
                    <span class="gama-item-val"><?= fmtMoney($valor_emplacamento) ?></span>
                </div>
                <?php endif; ?>

                <?php if ($valor_seguro > 0): ?>
                <div class="gama-item">
                    <span class="gama-item-label">Seguro</span>
                    <span class="gama-item-val"><?= fmtMoney($valor_seguro) ?></span>
                </div>
                <?php endif; ?>

                <div class="gama-total-strip" style="background: #2347C8;">
                    <span class="gama-total-label">Valor Total da Proposta</span>
                    <span class="gama-total-val"><?= fmtMoney($valor_venda_total) ?></span>
                </div>
            </div>

        </div>
        <?php endif; ?>

        <!-- VEÍCULOS EM TROCA — cards sem tabela -->
        <?php if ($temTroca): ?>
        <div class="sec">
            <div class="sec-head">
                <div class="sec-head-bar"></div>
                <div class="sec-head-label">Veículos em Troca</div>
                <span class="veiculo-preco-tag" style="margin-left:auto; background:#FBBF24; color:#111827; font-size:7pt; font-weight:600; padding:2px 8px; border-radius:3px; text-transform:uppercase; letter-spacing:.06em;">Sujeito a avaliação</span>
            </div>
            <div class="troca-list">
                <?php foreach ($troca_modelo as $i => $modelo):
                    if (trim($modelo) === '') continue;
                    $tAno   = $troca_ano[$i]   ?? '';
                    $tCor   = $troca_cor[$i]   ?? '';
                    $tPlaca = $troca_placa[$i] ?? '';
                    $tVal   = $troca_valor[$i] ?? '';
                    $hasMeta = ($tAno !== '' || $tCor !== '' || $tPlaca !== '');
                ?>
                <div class="troca-item-card">
                    <div class="troca-item-main">
                        <div class="troca-item-modelo">
                            <?= htmlspecialchars($modelo) ?>
                            <?php
                            $partes = [];
                            if ($tAno   !== '') $partes[] = $tAno;
                            if ($tCor   !== '') $partes[] = $tCor;
                            if ($tPlaca !== '') $partes[] = $tPlaca;
                            if (!empty($partes)):
                            ?>
                            <span style="font-weight:400; color:var(--gray-500); font-size:8pt; margin-left:6px;"><?= htmlspecialchars(implode(' · ', $partes)) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($tVal !== ''): ?>
                        <div class="troca-item-price"><?= htmlspecialchars($tVal) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($valor_total_troca > 0): ?>
            <div class="troca-total-row">
                <div class="troca-total-pill">
                    Total em troca <strong><?= fmtMoney($valor_total_troca) ?></strong>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- VENDA DIRETA -->
        <?php if ($temVD): ?>
        <div class="sec">
            <div class="sec-head">
                <div class="sec-head-bar"></div>
                <div class="sec-head-label">Desconto Venda Direta</div>
            </div>
            <div class="vd-box">
                <?php if ($pct_desconto): ?>
                <div class="vd-row">
                    <span class="vd-label">Desconto aplicado</span>
                    <span class="vd-value"><?= htmlspecialchars($pct_desconto) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($tipo_venda_direta): ?>
                <div class="vd-row">
                    <span class="vd-label">Tipo de venda</span>
                    <span class="vd-value">
                        <?= htmlspecialchars($tipo_venda_direta) ?>
                        <?php if ($tipo_venda_direta === 'Outros' && $tipo_venda_outros): ?>
                            - <?= htmlspecialchars($tipo_venda_outros) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- BONIFICAÇÕES -->
        <?php if ($temBonif): ?>
        <div class="sec">
            <div class="sec-head">
                <div class="sec-head-bar"></div>
                <div class="sec-head-label">Bonificações / Cortesia</div>
            </div>
            <div class="bonif-list">
                <?php foreach ($bonif_item as $i => $item):
                    if (empty($item)) continue;
                ?>
                <div class="bonif-item-row">
                    <span class="bonif-item-name">
                        <span class="bonif-gift-icon">🎁</span><?= htmlspecialchars($item) ?>
                    </span>
                    <span class="bonif-item-val"><?= htmlspecialchars($bonif_valor[$i] ?? '') ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- OBSERVAÇÕES -->
        <?php if ($temObs): ?>
        <div class="sec">
            <div class="sec-head">
                <div class="sec-head-bar"></div>
                <div class="sec-head-label">Detalhamento de Proposta</div>
            </div>
            <div class="obs-box">
                <?= nl2br(htmlspecialchars($observacoes)) ?>
            </div>
        </div>
        <?php endif; ?>

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

    <footer class="doc-footer">
        <div class="footer-info">
            <strong>Empresa Exemplo · Concessionária Oficial</strong>
            Este orçamento foi gerado em <?= $data_orcamento ?> pelo consultor
            <?= htmlspecialchars($nome_usuario) ?> da loja <?= htmlspecialchars($loja ?: 'Exemplo Veículos') ?>.<br>
            As condições apresentadas estão sujeitas à disponibilidade de estoque e aprovação comercial.
        </div>
        <div class="footer-validade">
            Válido por
            <strong>7 dias</strong>
            a partir da emissão
        </div>
    </footer>
</div><!-- /doc -->

<div class="app-version-label no-print">
</div>

</body>
</html>