<?php
require_once __DIR__ . '/config_database.php';
session_start();
if (empty($_SESSION['logado'])) {
    header('Location: index.php');
    exit;
}

// BUSCAR LOJA DO USUÁRIO
$loja_usuario    = '';
$cnpj_loja       = '';
$id_loja_usuario = null;
$cpf_usuario     = $_SESSION['cpf'] ?? '';

if ($cpf_usuario !== '') {
    $stmt = $mysqli->prepare("
        SELECT u.id_loja, l.nome AS loja, l.cnpj 
          FROM usuarios u 
          LEFT JOIN lojas l ON l.id = u.id_loja 
         WHERE u.cpf = ? 
         LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('s', $cpf_usuario);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $id_loja_usuario = (int)($row['id_loja'] ?? 0);
            $loja_usuario    = $row['loja'] ?? '';
            $cnpj_loja       = $row['cnpj'] ?? '';
        }
        $stmt->close();
    }
}

// LISTAR FOTOS EM /img/carros (MARCA / MODELO / ARQUIVO)
$dir_fotos       = __DIR__ . '/img/carros';
$carrosEstrutura = []; // [marca][modelo][] = arquivo
if (is_dir($dir_fotos)) {
    $marcas = array_diff(scandir($dir_fotos), ['.', '..']);
    foreach ($marcas as $marcaDir) {
        $caminhoMarca = $dir_fotos . '/' . $marcaDir;
        if (!is_dir($caminhoMarca)) continue;

        $carrosEstrutura[$marcaDir] = [];
        $modelos = array_diff(scandir($caminhoMarca), ['.', '..']);
        foreach ($modelos as $modeloDir) {
            $caminhoModelo = $caminhoMarca . '/' . $modeloDir;
            if (!is_dir($caminhoModelo)) continue;

            $carrosEstrutura[$marcaDir][$modeloDir] = [];
            $arquivos = array_diff(scandir($caminhoModelo), ['.', '..']);
            foreach ($arquivos as $arq) {
                $ext = strtolower(pathinfo($arq, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                    $carrosEstrutura[$marcaDir][$modeloDir][] = $arq;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <title>Orçamento de Veículo - Empresa Exemplo</title>
    <style>
:root{
    --exemplo-red:#2347c8;
    --exemplo-dark:#606060;
    --exemplo-yellow:#7a4fd0;
    --exemplo-gray-light:#eaeaea;
    --exemplo-bg:#f5f5f7;
    --exemplo-border:rgba(0,0,0,0.08);
    --exemplo-card:#ffffff;
    --exemplo-accent:#0070c9;
    --exemplo-font:"Arial",system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
*,*::before,*::after{box-sizing:border-box}
body{
    margin:0;
    font-family:var(--exemplo-font);
    font-size:14px;
    color:var(--exemplo-dark);
    background:var(--exemplo-bg);
}
.app-wrapper{min-height:100vh;display:flex;flex-direction:column;overflow:visible}
.app-main{width:100%;max-width:1200px;margin:0 auto 24px;padding:20px 24px;overflow:visible}
.page-title{text-align:center;font-size:2rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--exemplo-dark);margin:0 0 1.8rem 0}
.section{
    background:var(--exemplo-card);
    border-radius:10px;
    padding:16px 18px 18px;
    border:1px solid var(--exemplo-border);
    box-shadow:0 6px 18px rgba(0,0,0,0.08);
    margin-bottom:16px;
}
.section h2{font-size:16px;margin:0 0 8px;padding:8px 10px;background:#f5f5f5;border-radius:4px}
.field{display:flex;flex-direction:column;margin-bottom:8px}
.field-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px 16px}
.field-small{max-width:260px}
.field-full{width:100%}
.field label{font-size:12px;margin-bottom:2px;color:#333}
.input,.field input,.field select,.field textarea{
    border-radius:999px;
    border:1px solid #d0d0d0;
    padding:8px 12px;
    font-size:13px;
    outline:none;
    background:#ffffff;
    box-shadow:0 2px 4px rgba(0,0,0,0.06);
    transition:border-color .15s ease,box-shadow .15s ease;
}
.field textarea{min-height:70px;resize:vertical;border-radius:14px}
textarea[name="observacoes"]{
    background:#f5f5f5;
    border:1px solid #d0d0d0;
    padding:10px 12px;
    border-radius:14px;
    min-height:70px;
    resize:vertical;
    font-size:13px;
}
textarea[name="observacoes"]:focus{
    background:#fff;
    border-color:var(--exemplo-red);
    box-shadow:0 0 0 2px rgba(35,71,200,0.18);
}
.field input:focus,.field select:focus,.field textarea:focus,.input:focus{
    border-color:var(--exemplo-red);
    box-shadow:0 0 0 2px rgba(35,71,200,0.18);
}
.card-list{display:flex;flex-direction:column;gap:6px;margin-bottom:6px}
.card-item{background:#fafafa;border-radius:8px;padding:8px 10px;border:1px solid rgba(0,0,0,0.04)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px 16px}
.actions-inline{display:flex;gap:8px;margin-top:6px}
.btn{
    border-radius:999px;
    border:none;
    padding:9px 18px;
    font-size:13px;
    font-weight:600;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:4px;
    box-shadow:0 6px 14px rgba(0,0,0,0.18);
    transition:transform .1s ease,box-shadow .1s ease,filter .1s ease;
}
.btn-primary{background:var(--exemplo-red);color:#ffffff}
#btn-print{background:#ffffff;color:var(--exemplo-red)}
.btn-warning{background:#ffffff;color:#333;border:1px solid #ccc}
.btn-warning:hover{background:#f5f5f5;border-color:#999}
.btn:hover{filter:brightness(1.05);box-shadow:0 8px 18px rgba(0,0,0,0.24)}
.btn:active{transform:translateY(1px);box-shadow:0 4px 10px rgba(0,0,0,0.2)}

.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px 16px}
.summary-card{background:#fafafa;border-radius:8px;padding:8px 10px;border:1px solid rgba(0,0,0,0.04)}
.summary-card.ok{background:#e6f4ea;border-color:#2e7d32}
.summary-card.error{background:#ffebee;border-color:#c62828}
.summary-label{display:block;font-size:12px;color:#666;margin-bottom:3px}
.summary-value{font-size:15px;font-weight:bold}
.summary-alert{margin-top:8px;font-size:13px}
.summary-alert.ok{color:#2e7d32}
.summary-alert.error{color:#c62828}
@media(max-width:900px){.app-main{margin-top:-40px;padding:0 12px}}
#secao-tabela-gama{display:none;}
    </style>
</head>
<body>
<div class="app-wrapper">
    <div class="app-main">
        <main class="app-content">
            <header class="content-header">
                <div class="page-title">Orçamento de Veículos</div>
                <p>Preencha os dados do veículo, condições comerciais e formas de pagamento para gerar o PDF.</p>
            </header>

            <form id="formOrcamento" method="post" action="gerar_pdf.php" target="_blank" novalidate>
                <input type="hidden" name="id_loja" value="<?= (int)$id_loja_usuario; ?>">
                <input type="hidden" name="loja" value="<?= htmlspecialchars($loja_usuario, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="cnpj_loja" value="<?= htmlspecialchars($cnpj_loja, ENT_QUOTES, 'UTF-8'); ?>">

                <!-- TIPO DE ORÇAMENTO -->
                <section class="section">
                    <h2>Tipo de orçamento</h2>
                    <div class="grid">
                        <div class="field">
                            <label for="tipo_orcamento">Tipo de orçamento</label>
                            <select id="tipo_orcamento" name="tipo_orcamento">
                                <option value="">Selecione...</option>
                                <option value="Usado">Usado</option>
                                <option value="Novos Varejo">Novos Varejo</option>
                                <option value="Novos Venda Direta">Novos Venda Direta</option>
                            </select>
                        </div>
                    </div>
                </section>

                <!-- DADOS DO CLIENTE -->
                <section class="section">
                    <h2>Dados do cliente</h2>
                    <div class="grid">
                        <div class="field field-full">
                            <label for="cliente_nome">Nome</label>
                            <input type="text" id="cliente_nome" name="cliente_nome">
                        </div>
                        <div class="field field-small">
                            <label for="cliente_cpf">CPF</label>
                            <input type="text" id="cliente_cpf" name="cliente_cpf">
                        </div>
                        <div class="field field-small">
                            <label for="cliente_telefone">Telefone</label>
                            <input type="text" id="cliente_telefone" name="cliente_telefone">
                        </div>
                    </div>
                </section>

                <!-- DADOS DO VEÍCULO -->
                <section class="section">
                    <h2>Dados do veículo</h2>
                    <div class="card-list" id="veiculoscontainer">
                        <div class="card-item veiculo-bloco" data-index="0">
                            <!-- bloco de foto (só aparece para Novos) -->
                            <div class="field-row bloco-foto-veiculo">
                                <div class="field">
                                    <label>Marca</label>
                                    <select class="select-marca">
                                        <option value="">Selecione...</option>
                                        <?php foreach (array_keys($carrosEstrutura) as $marca): ?>
                                            <option value="<?= htmlspecialchars($marca, ENT_QUOTES, 'UTF-8'); ?>">
                                                <?= htmlspecialchars($marca, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="field">
                                    <label>Modelo</label>
                                    <select class="select-modelo" disabled>
                                        <option value="">Selecione a marca...</option>
                                    </select>
                                </div>

                                <div class="field">
                                    <label>Versão</label>
                                    <select class="select-versao" disabled>
                                        <option value="">Selecione o modelo...</option>
                                    </select>
                                    <input type="hidden" name="veiculos[0][foto]" class="input-foto-veiculo">
                                    <img src="" alt="Foto do veículo" class="miniatura-foto"
                                         style="margin-top:6px;max-width:120px;display:none;border-radius:4px;border:1px solid #ccc;">
                                </div>
                            </div>

                            <div class="field-row">
                                <div class="field">
                                    <label>Nome do veículo</label>
                                    <input type="text" name="veiculos[0][nome]" required>
                                </div>
                                <div class="field field-small">
                                    <label>Ano fab/modelo</label>
                                    <input type="text" name="veiculos[0][ano]" required>
                                </div>
                                <div class="field field-small">
                                    <label>Cor</label>
                                    <input type="text" name="veiculos[0][cor]">
                                </div>
                                <div class="field field-small" id="campo-gama-veiculo">
                                    <label>Valor de Gama</label>
                                    <input type="text" id="gama_veiculo_proxy" class="mask-money" placeholder="R$ 0,00"
                                           style="font-size:15px;font-weight:700;">
                                </div>
                            </div>

                            <!-- OPCIONAIS -->
                            <div style="margin-top:10px;">
                                <label style="font-size:12px;color:#333;font-weight:600;display:block;margin-bottom:6px;">Opcionais</label>
                                <div id="opcionais-container" style="display:flex;flex-direction:column;gap:6px;margin-bottom:6px;">
                                    <div class="opcional-row" style="display:flex;gap:8px;align-items:center;">
                                        <input type="text" name="opcionais[]" placeholder="Ex: Ar condicionado, Teto solar..."
                                               maxlength="500" oninput="atualizaContadorOpcionais()"
                                               style="flex:1;border-radius:999px;border:1px solid #d0d0d0;padding:7px 12px;font-size:13px;outline:none;">
                                        <button type="button" onclick="removeOpcional(this)"
                                                style="background:#eee;border:none;border-radius:50%;width:26px;height:26px;cursor:pointer;font-size:16px;line-height:1;color:#888;flex-shrink:0;">×</button>
                                    </div>
                                </div>
                                <div id="opcionais-contador" style="font-size:11px;color:#aaa;text-align:right;margin:-2px 0 4px;">0 / 500 caracteres</div>
                                <button type="button" onclick="addOpcional()"
                                        style="background:none;border:1px dashed #ccc;border-radius:999px;padding:5px 14px;font-size:12px;color:#888;cursor:pointer;">
                                    + Adicionar opcional
                                </button>
                            </div>
                        </div>
                    </div>

                </section>

                <!-- TABELA VALOR GAMA -->
                <section class="section" id="secao-tabela-gama">
                    <h2>Tabela valor gama</h2>
                    <div style="overflow-x:auto;">
                        <table id="tabela-gama" style="width:100%;border-collapse:collapse;font-size:13px;">
                            <thead>
                                <tr>
                                    <th style="text-align:left;padding:8px 10px;background:#f5f5f5;border-radius:4px 0 0 4px;width:40%"></th>
                                    <th style="text-align:center;padding:8px 10px;background:#f5f5f5;color:var(--exemplo-dark);font-size:14px;" id="th-venda-direta" data-col="vd">VENDA DIRETA</th>
                                    <th style="text-align:center;padding:8px 10px;background:#f5f5f5;color:var(--exemplo-dark);font-size:14px;border-radius:0 4px 4px 0;" id="th-varejo" data-col="vr">VAREJO</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Valor de Gama -->
                                <tr class="gama-row">
                                    <td style="padding:8px 10px;font-weight:600;">Valor de Gama</td>
                                    <td style="padding:6px 10px;" data-col="vd">
                                        <input type="text" id="gama_vd" name="gama_vd" class="mask-money gama-input" placeholder="R$ 0,00"
                                               style="width:100%;border-radius:999px;border:1px solid #d0d0d0;padding:7px 12px;font-size:13px;outline:none;text-align:right;">
                                    </td>
                                    <td style="padding:6px 10px;" id="td-varejo-gama" data-col="vr">
                                        <input type="text" id="gama_vr" name="gama_vr" class="mask-money gama-input" placeholder="R$ 0,00"
                                               style="width:100%;border-radius:999px;border:1px solid #d0d0d0;padding:7px 12px;font-size:13px;outline:none;text-align:right;">
                                    </td>
                                </tr>
                                <!-- Valor de venda c/ opcionais -->
                                <tr class="gama-row">
                                    <td style="padding:8px 10px;font-weight:600;">Valor de venda c/ opcionais</td>
                                    <td style="padding:6px 10px;" data-col="vd">
                                        <input type="text" name="veiculos[0][valor]" id="veiculo_valor_vd" class="mask-money veiculo-valor" placeholder="R$ 0,00"
                                               style="width:100%;border-radius:999px;border:1px solid #d0d0d0;padding:7px 12px;font-size:13px;outline:none;text-align:right;">
                                    </td>
                                    <td style="padding:6px 10px;" data-col="vr">
                                        <input type="text" id="veiculo_valor_vr" class="mask-money veiculo-valor" placeholder="R$ 0,00"
                                               style="width:100%;border-radius:999px;border:1px solid #d0d0d0;padding:7px 12px;font-size:13px;outline:none;text-align:right;">
                                    </td>
                                </tr>
                                <!-- Desconto Mont. -->
                                <tr class="gama-row" id="row-desc-mont">
                                    <td style="padding:8px 10px;font-weight:600;">
                                        <span style="display:inline-block;background:var(--exemplo-red);color:#fff;border-radius:50%;width:18px;height:18px;text-align:center;line-height:18px;font-size:11px;margin-right:4px;">%</span>
                                        Desconto Mont.
                                    </td>
                                    <td style="padding:6px 10px;" data-col="vd">
                                        <input type="text" id="gama_desc_mont_vd" name="gama_desc_mont_vd" class="gama-pct" placeholder="0%"
                                               style="width:100%;border-radius:999px;border:1px solid #d0d0d0;padding:7px 12px;font-size:13px;outline:none;text-align:right;">
                                    </td>
                                    <td style="padding:6px 10px;text-align:center;color:#999;font-style:italic;font-size:12px;" id="td-varejo-desc-mont" data-col="vr">
                                        Não existe varejo
                                    </td>
                                </tr>

                                <!-- Subtotal — sempre visível -->
                                <tr style="background:#f9f9f9;">
                                    <td style="padding:8px 10px;font-weight:600;color:#555;">Subtotal</td>
                                    <td style="padding:8px 10px;text-align:right;font-weight:700;" id="gama_subtotal_vd" data-col="vd">—</td>
                                    <td style="padding:8px 10px;text-align:right;font-weight:700;" id="gama_subtotal_vr" data-col="vr">—</td>
                                </tr>

                                <!-- FLAG: Desconto Cedido -->
                                <tr class="gama-row">
                                    <td colspan="3" style="padding:8px 10px;">
                                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:600;user-select:none;">
                                            <div id="flag-desc-cedido" onclick="toggleDescCedido()"
                                                 style="width:42px;height:24px;border-radius:999px;background:#ccc;position:relative;cursor:pointer;transition:background .2s;flex-shrink:0;">
                                                <div id="flag-desc-cedido-knob"
                                                     style="width:18px;height:18px;background:#fff;border-radius:50%;position:absolute;top:3px;left:3px;transition:left .2s;box-shadow:0 1px 3px rgba(0,0,0,0.3);"></div>
                                            </div>
                                            Desconto Cedido
                                        </label>
                                    </td>
                                </tr>

                                <!-- Valor Desconto Cedido — aparece ao ativar flag -->
                                <tr class="gama-desc-cedido-row" style="display:none;">
                                    <td style="padding:8px 10px;font-weight:600;color:#555;">Valor desconto</td>
                                    <!-- Venda Direta -->
                                    <td style="padding:6px 10px;" data-col="vd">
                                        <div style="display:flex;gap:6px;align-items:center;">
                                            <div style="display:flex;border-radius:999px;overflow:hidden;border:1px solid #d0d0d0;flex-shrink:0;">
                                                <button type="button" id="btn_tipo_ced_vd_pct" onclick="setTipoCedido('vd','pct')"
                                                    style="padding:5px 10px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:var(--exemplo-red);color:#fff;">%</button>
                                                <button type="button" id="btn_tipo_ced_vd_val" onclick="setTipoCedido('vd','val')"
                                                    style="padding:5px 10px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:#fff;color:#333;">R$</button>
                                            </div>
                                            <input type="text" id="gama_desc_ced_vd" name="gama_desc_ced_vd" placeholder="0%"
                                                   style="flex:1;border-radius:999px;border:1px solid #d0d0d0;padding:7px 12px;font-size:13px;outline:none;text-align:right;">
                                            <input type="hidden" id="gama_desc_ced_vd_tipo" name="gama_desc_ced_vd_tipo" value="pct">
                                        </div>
                                    </td>
                                    <!-- Varejo -->
                                    <td style="padding:6px 10px;" data-col="vr">
                                        <div style="display:flex;gap:6px;align-items:center;">
                                            <div style="display:flex;border-radius:999px;overflow:hidden;border:1px solid #d0d0d0;flex-shrink:0;">
                                                <button type="button" id="btn_tipo_ced_vr_pct" onclick="setTipoCedido('vr','pct')"
                                                    style="padding:5px 10px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:var(--exemplo-red);color:#fff;">%</button>
                                                <button type="button" id="btn_tipo_ced_vr_val" onclick="setTipoCedido('vr','val')"
                                                    style="padding:5px 10px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:#fff;color:#333;">R$</button>
                                            </div>
                                            <input type="text" id="gama_desc_ced_vr" name="gama_desc_ced_vr" placeholder="0%"
                                                   style="flex:1;border-radius:999px;border:1px solid #d0d0d0;padding:7px 12px;font-size:13px;outline:none;text-align:right;">
                                            <input type="hidden" id="gama_desc_ced_vr_tipo" name="gama_desc_ced_vr_tipo" value="pct">
                                        </div>
                                    </td>
                                </tr>
                                <!-- Total — sempre visível -->
                                <tr style="background:#f0f0f0;border-top:2px solid #ddd;">
                                    <td style="padding:10px;font-weight:700;font-size:14px;">TOTAL</td>
                                    <td style="padding:10px;text-align:right;font-weight:700;font-size:15px;color:var(--exemplo-dark);" id="gama_total_vd" data-col="vd">—</td>
                                    <td style="padding:10px;text-align:right;font-weight:700;font-size:15px;color:var(--exemplo-dark);" id="gama_total_vr" data-col="vr">—</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>


                <!-- VEÍCULOS EM TROCA -->
                <section class="section">
                    <h2>Veículos em troca</h2>
                    <div class="card-list" id="trocascontainer">
                        <div class="card-item troca-template" style="display:none;">
                            <div class="field-row">
                                <div class="field">
                                    <label>Modelo</label>
                                    <input type="text" name="troca_modelo[]">
                                </div>
                                <div class="field field-small">
                                    <label>Ano</label>
                                    <input type="text" name="troca_ano[]">
                                </div>
                                <div class="field field-small">
                                    <label>Cor</label>
                                    <input type="text" name="troca_cor[]">
                                </div>
                                <div class="field field-small">
                                    <label>Placa</label>
                                    <input type="text" name="troca_placa[]">
                                </div>
                                <div class="field field-small">
                                    <label>Valor avaliação</label>
                                    <input type="text" name="troca_valor[]" class="troca-valor mask-money">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="actions-inline">
                        <button type="button" class="btn btn-primary"
                                onclick="addRow('trocascontainer','troca-template')">
                            Adicionar veículo
                        </button>
                        <button type="button" class="btn btn-warning"
                                onclick="removeLastRow('trocascontainer','troca-template')">
                            Remover último
                        </button>
                    </div>
                </section>

                <!-- DESCONTO VENDA DIRETA -->
                <section class="section" id="vdsection" style="display:none;">
                    <h2>Desconto venda direta</h2>
                    <div class="grid">
                        <div class="field field-small">
                            <label for="pctdesconto">Desconto</label>
                            <input type="text" id="pctdesconto" name="pct_desconto" placeholder="0%">
                        </div>

                        <div class="field field-full">
                            <label for="tipo_venda_direta">Tipo de venda direta</label>
                            <select id="tipo_venda_direta" name="tipo_venda_direta">
                                <option value="">Selecione...</option>
                                <option value="CNPJ">CNPJ</option>
                                <option value="CPF">CPF</option>
                                <option value="Produtor Rural">Produtor Rural</option>
                                <option value="Taxi c/ isencao">Táxi c/ isenção</option>
                                <option value="Taxi Alvara">Táxi Alvará</option>
                                <option value="PCD IPI">PCD IPI</option>
                                <option value="PCD IPI e ICM">PCD IPI e ICM</option>
                                <option value="Locadora Frota">Locadora Frota</option>
                                <option value="Locadora Abla">Locadora Abla</option>
                                <option value="Ducato">Ducato</option>
                                <option value="Finame BNDES">Finame BNDES</option>
                                <option value="Outros">Outros</option>
                            </select>
                        </div>

                        <div class="field field-full" id="tipo_venda_outros_container" style="display:none;">
                            <label for="tipo_venda_outros">Outros (descrever)</label>
                            <input type="text" id="tipo_venda_outros" name="tipo_venda_outros">
                        </div>
                    </div>
                </section>

                <!-- CUSTOS ADICIONAIS -->
                <section class="section">
                    <h2>Custos adicionais</h2>
                    <div class="grid">
                        <div class="field">
                            <label>Transferência</label>
                            <input type="text" id="transferencia" name="transferencia" class="mask-money">
                        </div>
                        <div class="field">
                            <label>Emplacamento</label>
                            <input type="text" id="emplacamento" name="emplacamento" class="mask-money">
                        </div>
                        <div class="field">
      <label for="seguro">Seguro</label>
      <input type="text" id="seguro" name="seguro" class="mask-money">
    </div>
  </div>
                </section>

                <!-- OBSERVAÇÕES -->
                <section class="section">
                    <h2>Detalhamento de Proposta</h2>
                    <div class="grid">
                        <div class="field field-full">
                            <label>Detalhes feitos para a proposta</label>
                            <textarea name="observacoes" rows="3" placeholder="Condições comerciais, validade, comentários adicionais."></textarea>
                        </div>
                    </div>
                </section>

                <!-- RESUMO FINANCEIRO -->
                <section class="section">
                    <h2>Resumo financeiro</h2>
                    <div class="summary-grid">
                        <div class="summary-card">
                            <span class="summary-label">Valor de venda total</span>
                            <span class="summary-value" id="res_valor_total">R$ 0,00</span>
                        </div>

                        <div class="summary-card">
                            <span class="summary-label">Pagamento total</span>
                            <span class="summary-value" id="res_total_pagamento">R$ 0,00</span>
                        </div>

                        <div class="summary-card">
                            <span class="summary-label">Veículos em troca</span>
                            <span class="summary-value" id="res_total_troca">R$ 0,00</span>
                        </div>
                    </div>
                    <p id="alert-diferenca" class="summary-alert" style="display:none;"></p>
                </section>

                <!-- BOTÃO PRINCIPAL -->
                <button type="submit" id="btn-print" class="btn btn-primary">Imprimir</button>
            </form>
        </main>
    </div>
</div>

<script>
const CARROS = <?= json_encode($carrosEstrutura, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

// utilidades dinheiro
function unmaskMoney(str){
    if(!str) return 0;
    return parseFloat(str.toString().replace(/[R$\s\.]/g,'').replace(',','.'))||0;
}
function moneyMask(e){
    let v=e.target.value.replace(/\D/g,'');
    if(!v){e.target.value='';calculaResumo();return;}
    v=(parseInt(v,10)/100).toFixed(2);
    e.target.value=Number(v).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
    calculaResumo();
}

// quando seleciona a foto: mostra miniatura e preenche nome/ano
function onFotoChange(select){
    const card = select.closest('.card-item') || select.closest('.veiculo-bloco');
    if (!card) return;

    const tipoOrcamento = document.getElementById('tipo_orcamento')?.value || '';
    const isNovos = (tipoOrcamento === 'Novos Varejo' || tipoOrcamento === 'Novos Venda Direta');

    // fotos só funcionam para Novos
    if (!isNovos) return;

    const inputFoto = card.querySelector('.input-foto-veiculo');
    const filename  = inputFoto && inputFoto.value ? inputFoto.value : '';
    const img = card.querySelector('.miniatura-foto');
    if (img && filename) {
        const partes = filename.split('/');
        const seguro = partes.map(p => encodeURIComponent(p)).join('/');
        img.src = '/Veiculos/img/carros/' + seguro;
        img.style.display = 'block';
    } else if (img) {
        img.src = '';
        img.style.display = 'none';
    }

    if (!filename) return;

    const nomeSemExt  = filename.replace(/\.[^.]+$/, '');
    const partesPath  = nomeSemExt.split('/');
    const nomeArquivo = partesPath[partesPath.length - 1];
    const partes      = nomeArquivo.split(' ');

    let nomeVeic = '';
    let anoVeic  = '';

    const posFlex = partes.indexOf('FLEX');

    if (posFlex !== -1) {
        nomeVeic = partes.slice(0, posFlex + 1).join(' ');
        for (let i = posFlex + 1; i < partes.length; i++) {
            if (/^\d{4}$/.test(partes[i])) {
                anoVeic = partes[i];
                break;
            }
        }
        if (!anoVeic && isNovos) {
            anoVeic = '2026';
        }
    } else {
        for (let i = partes.length - 1; i >= 0; i--) {
            if (/^\d{4}$/.test(partes[i])) {
                anoVeic  = partes[i];
                nomeVeic = partes.slice(0, i).join(' ');
                break;
            }
        }
        if (!anoVeic) {
            if (isNovos) {
                anoVeic  = '2026';
                nomeVeic = nomeArquivo;
            } else {
                nomeVeic = nomeArquivo;
            }
        }
    }

    const inpNome = card.querySelector('input[name*="[nome]"]');
    const inpAno  = card.querySelector('input[name*="[ano]"]');

    if (inpNome) inpNome.value = nomeVeic;
    if (inpAno)  inpAno.value  = anoVeic;
}

// inicializa selects Marca/Modelo/Versão em um bloco
function initVeiculoSelects(bloco){
    const marcaSel  = bloco.querySelector('.select-marca');
    const modeloSel = bloco.querySelector('.select-modelo');
    const versaoSel = bloco.querySelector('.select-versao');
    const inputFoto = bloco.querySelector('.input-foto-veiculo');

    if (!marcaSel || !modeloSel || !versaoSel || !inputFoto) return;

    marcaSel.addEventListener('change', function(){
        const marca = this.value;
        modeloSel.innerHTML = '<option value="">Selecione...</option>';
        versaoSel.innerHTML = '<option value="">Selecione o modelo...</option>';
        modeloSel.disabled = true;
        versaoSel.disabled = true;
        inputFoto.value = '';
        onFotoChange(versaoSel);

        if (!marca || !CARROS[marca]) return;

        Object.keys(CARROS[marca]).forEach(modelo => {
            const opt = document.createElement('option');
            opt.value = modelo;
            opt.textContent = modelo;
            modeloSel.appendChild(opt);
        });
        modeloSel.disabled = false;
    });

    modeloSel.addEventListener('change', function(){
        const marca  = marcaSel.value;
        const modelo = this.value;
        versaoSel.innerHTML = '<option value="">Selecione...</option>';
        versaoSel.disabled = true;
        inputFoto.value = '';
        onFotoChange(versaoSel);

        if (!marca || !modelo || !CARROS[marca] || !CARROS[marca][modelo]) return;

        CARROS[marca][modelo].forEach(arquivo => {
            const opt = document.createElement('option');
            opt.value = arquivo;
            opt.textContent = arquivo.replace(/\.[^.]+$/, '');
            versaoSel.appendChild(opt);
        });
        versaoSel.disabled = false;
    });

    versaoSel.addEventListener('change', function(){
        const marca  = marcaSel.value;
        const modelo = modeloSel.value;
        const arquivo = this.value;
        if (!marca || !modelo || !arquivo) {
            inputFoto.value = '';
            onFotoChange(versaoSel);
            return;
        }
        inputFoto.value = marca + '/' + modelo + '/' + arquivo;
        onFotoChange(versaoSel);
    });
}

// veículos
function addVeiculo(){
    const c=document.getElementById('veiculoscontainer');
    const blocos=c.querySelectorAll('.veiculo-bloco');
    const idx=blocos.length;
    const clone=blocos[0].cloneNode(true);
    clone.setAttribute('data-index',idx);

    clone.querySelectorAll('input').forEach(inp=>{
        inp.value='';
        if(inp.name) inp.name=inp.name.replace(/\[\d+\]/,`[${idx}]`);
        if(inp.id)   inp.id  =inp.id.replace(/_\d+$/,`_${idx}`);
    });

    clone.querySelectorAll('select').forEach(sel=>{
        sel.value = '';
        if (sel.classList.contains('select-modelo')) {
            sel.innerHTML = '<option value="">Selecione a marca...</option>';
            sel.disabled = true;
        } else if (sel.classList.contains('select-versao')) {
            sel.innerHTML = '<option value="">Selecione o modelo...</option>';
            sel.disabled = true;
        }
    });

    const mini = clone.querySelector('.miniatura-foto');
    if (mini){
        mini.src = '';
        mini.style.display = 'none';
    }

    clone.querySelectorAll('.mask-money').forEach(inp=>{
        inp.removeEventListener('input',moneyMask);
        inp.removeEventListener('blur',moneyMask);
        inp.addEventListener('input',moneyMask);
        inp.addEventListener('blur',moneyMask);
    });

    c.appendChild(clone);
    initVeiculoSelects(clone);
    calculaResumo();
}

function removeVeiculo(){
    const c=document.getElementById('veiculoscontainer');
    const blocos=c.querySelectorAll('.veiculo-bloco');
    if(blocos.length>1){c.removeChild(blocos[blocos.length-1]);calculaResumo();}
}


// linhas dinâmicas (troca / bonif)
function addRow(containerId,templateClass){
    const c=document.getElementById(containerId);
    if(!c) return;
    const tpl=c.querySelector('.'+templateClass);
    if(!tpl) return;
    const clone=tpl.cloneNode(true);
    clone.classList.remove(templateClass);
    clone.classList.add('dynamic-row');
    clone.style.display='block';
    clone.querySelectorAll('input,textarea').forEach(el=>{
        if(el.type==='checkbox'||el.type==='radio') el.checked=false;
        else el.value='';
    });
    clone.querySelectorAll('.mask-money').forEach(el=>{
        el.addEventListener('input',moneyMask);
        el.addEventListener('blur',moneyMask);
    });
    c.appendChild(clone);
    calculaResumo();
}
function removeLastRow(containerId,templateClass){
    const c=document.getElementById(containerId);
    if(!c) return;
    const rows=c.querySelectorAll('.dynamic-row');
    if(rows.length){
        c.removeChild(rows[rows.length-1]);
        calculaResumo();
    }
}

// cálculo resumo
function calculaResumo() {
    let totalVeiculos = 0;
    document.querySelectorAll('.veiculo-valor').forEach(inp => {
        totalVeiculos += unmaskMoney(inp.value);
    });

    let totalTroca = 0;
    document.querySelectorAll('.troca-valor').forEach(inp => {
        totalTroca += unmaskMoney(inp.value);
    });

    const transferencia = unmaskMoney(document.getElementById('transferencia')?.value);
    const emplacamento  = unmaskMoney(document.getElementById('emplacamento')?.value);

    const valorVendaTotal = totalVeiculos + transferencia + emplacamento;
    const totalPagamento  = totalTroca;

    // corrigidos
    document.getElementById('res_valor_total').textContent =
        'R$ ' + valorVendaTotal.toLocaleString('pt-BR', { minimumFractionDigits: 2 });

    document.getElementById('res_total_troca').textContent =
        'R$ ' + totalTroca.toLocaleString('pt-BR', { minimumFractionDigits: 2 });

    document.getElementById('res_total_pagamento').textContent =
        'R$ ' + totalPagamento.toLocaleString('pt-BR', { minimumFractionDigits: 2 });

    const cardVenda = document.getElementById('res_valor_total')?.closest('.summary-card');
    const cardPagto = document.getElementById('res_total_pagamento')?.closest('.summary-card');

    if (cardVenda && cardPagto) {
        cardVenda.classList.remove('ok','error');
        cardPagto.classList.remove('ok','error');

        const diff = totalPagamento - valorVendaTotal;
        const igual = Math.abs(diff) < 0.01 && valorVendaTotal > 0;

        if (igual) {
            cardVenda.classList.add('ok');
            cardPagto.classList.add('ok');
        } else if (valorVendaTotal > 0 || totalPagamento > 0) {
            cardVenda.classList.add('error');
            cardPagto.classList.add('error');
        }
    }

    const alertDiv = document.getElementById('alert-diferenca');
    if(alertDiv){
        alertDiv.style.display='none';
        alertDiv.textContent='';
        alertDiv.className='summary-alert';
    }
}

// inicialização
document.addEventListener('DOMContentLoaded',()=>{
    document.querySelectorAll('.mask-money').forEach(el=>{
        el.addEventListener('input',moneyMask);
        el.addEventListener('blur',moneyMask);
    });



    const tipoSelect=document.getElementById('tipo_orcamento');
    if(tipoSelect){
        const toggleTipo = () => {
            const tipo = tipoSelect.value;
            const isNovos    = (tipo === 'Novos Varejo' || tipo === 'Novos Venda Direta');
            const isVD       = (tipo === 'Novos Venda Direta');
            const isVarejo   = (tipo === 'Novos Varejo' || tipo === 'Usado');

            document.querySelectorAll('.bloco-foto-veiculo').forEach(div=>{
                div.style.display = isNovos ? 'grid' : 'none';
                if (!isNovos) {
                    const inputFoto = div.querySelector('.input-foto-veiculo');
                    const img       = div.querySelector('.miniatura-foto');
                    const marcaSel  = div.querySelector('.select-marca');
                    const modeloSel = div.querySelector('.select-modelo');
                    const versaoSel = div.querySelector('.select-versao');

                    if (inputFoto) inputFoto.value='';
                    if (img) { img.src=''; img.style.display='none'; }
                    if (marcaSel) marcaSel.value='';
                    if (modeloSel) {
                        modeloSel.innerHTML='<option value="">Selecione a marca...</option>';
                        modeloSel.disabled=true;
                    }
                    if (versaoSel) {
                        versaoSel.innerHTML='<option value="">Selecione o modelo...</option>';
                        versaoSel.disabled=true;
                    }
                }
            });

            const vd=document.getElementById('vdsection');
            if (vd) vd.style.display = isVD ? 'block' : 'none';

            // Tabela Gama — mostra/oculta colunas conforme tipo
            const secaoGama = document.getElementById('secao-tabela-gama');
            if (secaoGama) secaoGama.style.display = tipo ? 'block' : 'none';

            // Cabeçalhos e colunas da tabela gama
            const thVD   = document.getElementById('th-venda-direta');
            const thVR   = document.getElementById('th-varejo');
            const colsVD = document.querySelectorAll('.gama-col-vd');
            const colsVR = document.querySelectorAll('.gama-col-vr');

            // Controla visibilidade de cada coluna via atributo hidden nas células
            document.querySelectorAll('#tabela-gama [data-col]').forEach(td => {
                const col = td.getAttribute('data-col');
                if (col === 'vd') td.style.display = isVD    ? '' : 'none';
                if (col === 'vr') td.style.display = isVarejo ? '' : 'none';
            });

            if (thVD) thVD.style.display = isVD    ? '' : 'none';
            if (thVR) thVR.style.display = isVarejo ? '' : 'none';

            calculaGama();
            atualizaGamaDestaque();
        };

        tipoSelect.addEventListener('change', toggleTipo);
        toggleTipo();
    }

    const bloco0 = document.querySelector('.veiculo-bloco');
    if (bloco0) {
        initVeiculoSelects(bloco0);
    }

    document.getElementById('formOrcamento')?.addEventListener('submit',function(e){
        // Validação do veículo (obrigatório)
        const nomeVeic=document.querySelector('input[name="veiculos[0][nome]"]');
        const anoVeic =document.querySelector('input[name="veiculos[0][ano]"]');
        // valor agora está no campo veiculo_valor_vd (dentro da tabela gama)
        const valVeic =document.getElementById('veiculo_valor_vd');
        if(!nomeVeic?.value.trim()||!anoVeic?.value.trim()){
            e.preventDefault();
            alert('Preencha nome e ano/modelo do veículo.');
            nomeVeic?.focus();
            return false;
        }

        // Validação dos veículos em troca
        let trocasValidas=true;
        document.querySelectorAll('#trocascontainer .dynamic-row').forEach(row=>{
            const modelo=row.querySelector('input[name="troca_modelo[]"]');
            const ano   =row.querySelector('input[name="troca_ano[]"]');
            const cor   =row.querySelector('input[name="troca_cor[]"]');
            const placa =row.querySelector('input[name="troca_placa[]"]');
            const valor =row.querySelector('input[name="troca_valor[]"]');
            const preencheuAlgo=
                modelo.value.trim()||
                ano.value.trim()||
                cor.value.trim()||
                placa.value.trim()||
                valor.value.trim();
            if(preencheuAlgo && (
                !modelo.value.trim()||
                !ano.value.trim()||
                !cor.value.trim()||
                !placa.value.trim()||
                !valor.value.trim()
            )){
                trocasValidas=false;
            }
        });
        if(!trocasValidas){
            e.preventDefault();
            alert('Preencha modelo, ano, cor, placa e valor em todos os veículos de troca.');
            return false;
        }
    });

    const descontoInput = document.getElementById('pctdesconto');
    if (descontoInput) {
        descontoInput.addEventListener('input', function () {
            let v = this.value.replace(/[^0-9,]/g, '');
            const parts = v.split(',');
            if (parts.length > 2) v = parts[0] + ',' + parts[1];
            if (v.includes(',')) {
                const [intPart, decPart] = v.split(',');
                v = intPart + ',' + decPart.substring(0, 2);
            }
            const num = parseFloat(v.replace(',', '.')) || 0;
            if (num > 100) v = '100';
            this.value = v;
        });
        descontoInput.addEventListener('blur', function () {
            let v = this.value.replace(/[^0-9,]/g, '');
            if (!v) { this.value = ''; return; }
            const parts = v.split(',');
            let intPart = parts[0] || '0';
            let decPart = parts[1] ? parts[1].substring(0, 2) : '';
            this.value = decPart ? intPart + ',' + decPart + '%' : intPart + '%';
        });
        descontoInput.addEventListener('focus', function () {
            this.value = this.value.replace('%', '');
        });
    }

    calculaResumo();
});

// Sincroniza Valor de Gama entre bloco do veículo e tabela
function atualizaGamaDestaque() {
    const tipo  = document.getElementById('tipo_orcamento')?.value || '';
    const isVD  = tipo === 'Novos Venda Direta';
    const isVR  = (tipo === 'Novos Varejo' || tipo === 'Usado');
    const proxy = document.getElementById('gama_veiculo_proxy');
    if (!proxy) return;

    const gamaVD = document.getElementById('gama_vd')?.value || '';
    const gamaVR = document.getElementById('gama_vr')?.value || '';
    const val    = isVD ? gamaVD : (isVR ? gamaVR : '');

    // só atualiza o proxy se o usuário não está digitando nele agora
    if (document.activeElement?.id !== 'gama_veiculo_proxy') {
        proxy.value = val;
    }
}

function syncProxyParaTabela() {
    const tipo  = document.getElementById('tipo_orcamento')?.value || '';
    const isVD  = tipo === 'Novos Venda Direta';
    const isVR  = (tipo === 'Novos Varejo' || tipo === 'Usado');
    const proxy = document.getElementById('gama_veiculo_proxy');
    if (!proxy) return;

    if (isVD) {
        const el = document.getElementById('gama_vd');
        if (el) { el.value = proxy.value; calculaGama(); }
    } else if (isVR) {
        const el = document.getElementById('gama_vr');
        if (el) { el.value = proxy.value; calculaGama(); }
    }
}

function addOpcional() {
    const container = document.getElementById('opcionais-container');
    const row = document.createElement('div');
    row.className = 'opcional-row';
    row.style.cssText = 'display:flex;gap:8px;align-items:center;';
    row.innerHTML = `
        <input type="text" name="opcionais[]" placeholder="Ex: Ar condicionado, Teto solar..."
               maxlength="500" oninput="atualizaContadorOpcionais()"
               style="flex:1;border-radius:999px;border:1px solid #d0d0d0;padding:7px 12px;font-size:13px;outline:none;">
        <button type="button" onclick="removeOpcional(this)"
                style="background:#eee;border:none;border-radius:50%;width:26px;height:26px;cursor:pointer;font-size:16px;line-height:1;color:#888;flex-shrink:0;">×</button>
    `;
    container.appendChild(row);
    atualizaContadorOpcionais();
}
function removeOpcional(btn) {
    const container = document.getElementById('opcionais-container');
    const rows = container.querySelectorAll('.opcional-row');
    if (rows.length > 1) btn.closest('.opcional-row').remove();
    else btn.closest('.opcional-row').querySelector('input').value = '';
    atualizaContadorOpcionais();
}
function atualizaContadorOpcionais() {
    const inputs = document.querySelectorAll('#opcionais-container input[name="opcionais[]"]');
    let total = 0;
    inputs.forEach(i => { total += i.value.length; });
    const el = document.getElementById('opcionais-contador');
    if (!el) return;
    el.textContent = total + ' / 500 caracteres';
    el.style.color = total >= 500 ? '#2347C8' : '#aaa';
}

// ── FLAG DESCONTO CEDIDO ─────────────────────────────────────
let descCedidoAtivo = false;
function toggleDescCedido() {
    descCedidoAtivo = !descCedidoAtivo;
    const flag  = document.getElementById('flag-desc-cedido');
    const knob  = document.getElementById('flag-desc-cedido-knob');
    const rows  = document.querySelectorAll('.gama-desc-cedido-row');

    flag.style.background = descCedidoAtivo ? 'var(--exemplo-red)' : '#ccc';
    knob.style.left       = descCedidoAtivo ? '21px' : '3px';

    rows.forEach(row => {
        // respeita visibilidade das colunas ao mostrar
        row.style.display = descCedidoAtivo ? '' : 'none';
        if (descCedidoAtivo) {
            // reaplica ocultação de colunas
            row.querySelectorAll('[data-col]').forEach(td => {
                const col      = td.getAttribute('data-col');
                const tipo     = document.getElementById('tipo_orcamento')?.value || '';
                const isVD     = tipo === 'Novos Venda Direta';
                const isVarejo = (tipo === 'Novos Varejo' || tipo === 'Usado');
                if (col === 'vd') td.style.display = isVD    ? '' : 'none';
                if (col === 'vr') td.style.display = isVarejo ? '' : 'none';
            });
        }
    });

    if (!descCedidoAtivo) {
        // limpa valores ao desativar
        ['gama_desc_ced_vd','gama_desc_ced_vr'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
    }
    calculaGama();
}

// ── TABELA GAMA ──────────────────────────────────────────────
function parsePct(str) {
    if (!str) return null;
    const v = parseFloat(str.toString().replace('%','').replace(',','.'));
    return isNaN(v) ? null : v;
}

function setTipoCedido(col, tipo) {
    const inp   = document.getElementById('gama_desc_ced_' + col);
    const hidden= document.getElementById('gama_desc_ced_' + col + '_tipo');
    const btnPct= document.getElementById('btn_tipo_ced_' + col + '_pct');
    const btnVal= document.getElementById('btn_tipo_ced_' + col + '_val');
    if (!inp || !hidden) return;

    hidden.value = tipo;
    inp.value    = '';

    if (tipo === 'pct') {
        inp.placeholder      = '0%';
        btnPct.style.background = 'var(--exemplo-red)';
        btnPct.style.color      = '#fff';
        btnVal.style.background = '#fff';
        btnVal.style.color      = '#333';
    } else {
        inp.placeholder      = 'R$ 0,00';
        btnVal.style.background = 'var(--exemplo-red)';
        btnVal.style.color      = '#fff';
        btnPct.style.background = '#fff';
        btnPct.style.color      = '#333';
    }
    calculaGama();
}

function calculaGama() {
    const fmt = v => 'R$ ' + v.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});

    // Linha 1: Valor de Gama (referência, não entra no cálculo)
    const gamaVD = unmaskMoney(document.getElementById('gama_vd')?.value);
    const gamaVR = unmaskMoney(document.getElementById('gama_vr')?.value);

    // Linha 2: Valor de venda c/ opcionais (base do cálculo)
    const vendaVD = unmaskMoney(document.getElementById('veiculo_valor_vd')?.value);
    const vendaVR = unmaskMoney(document.getElementById('veiculo_valor_vr')?.value);

    // Linha 3: Desconto Mont. (só VD, sobre valor de venda)
    const pctMontRaw = document.getElementById('gama_desc_mont_vd')?.value || '';
    const pctMont    = parsePct(pctMontRaw);
    const subVD      = (pctMont !== null && vendaVD > 0)
                        ? vendaVD - (vendaVD * pctMont / 100)
                        : vendaVD;
    const subVR      = vendaVR;

    // Linha 4: Subtotal (sempre visível)
    document.getElementById('gama_subtotal_vd').textContent = vendaVD > 0 ? fmt(subVD) : '—';
    document.getElementById('gama_subtotal_vr').textContent = vendaVR > 0 ? fmt(subVR) : '—';

    // Desconto Cedido VD (sobre subtotal)
    const cedVDRaw  = document.getElementById('gama_desc_ced_vd')?.value || '';
    const cedVDTipo = document.getElementById('gama_desc_ced_vd_tipo')?.value || 'pct';
    let totalVD = subVD;
    if (cedVDRaw.trim() !== '' && subVD > 0) {
        if (cedVDTipo === 'pct') {
            const pct = parsePct(cedVDRaw);
            if (pct !== null) totalVD = subVD - (subVD * pct / 100);
        } else {
            const val = unmaskMoney(cedVDRaw);
            if (val > 0) totalVD = subVD - val;
        }
    }

    // Desconto Cedido VR (sobre subtotal)
    const cedVRRaw  = document.getElementById('gama_desc_ced_vr')?.value || '';
    const cedVRTipo = document.getElementById('gama_desc_ced_vr_tipo')?.value || 'pct';
    let totalVR = subVR;
    if (cedVRRaw.trim() !== '' && subVR > 0) {
        if (cedVRTipo === 'pct') {
            const pct = parsePct(cedVRRaw);
            if (pct !== null) totalVR = subVR - (subVR * pct / 100);
        } else {
            const val = unmaskMoney(cedVRRaw);
            if (val > 0) totalVR = subVR - val;
        }
    }

    document.getElementById('gama_total_vd').textContent = vendaVD > 0 ? fmt(totalVD) : '—';
    document.getElementById('gama_total_vr').textContent = vendaVR > 0 ? fmt(totalVR) : '—';
}

// Máscara % para desconto mont. e cedido (quando tipo=pct)
function maskPct(e) {
    let v = e.target.value.replace(/[^0-9,]/g, '');
    const parts = v.split(',');
    if (parts.length > 2) v = parts[0] + ',' + parts[1];
    if (v.includes(',')) {
        const [i, d] = v.split(',');
        v = i + ',' + d.substring(0, 2);
    }
    const num = parseFloat(v.replace(',', '.')) || 0;
    if (num > 100) v = '100';
    e.target.value = v;
    calculaGama();
}
function blurPct(e) {
    let v = e.target.value.replace(/[^0-9,]/g, '');
    if (!v) { e.target.value = ''; calculaGama(); return; }
    const [i, d] = v.split(',');
    e.target.value = d ? i + ',' + d + '%' : i + '%';
    calculaGama();
}
function focusPct(e) {
    e.target.value = e.target.value.replace('%', '');
}

// Inicializa inputs da tabela gama após DOM
document.addEventListener('DOMContentLoaded', () => {
    // Proxy Valor de Gama no bloco do veículo → sincroniza com tabela
    const proxy = document.getElementById('gama_veiculo_proxy');
    if (proxy) {
        proxy.addEventListener('input', function(e) { moneyMask(e); syncProxyParaTabela(); calculaGama(); });
        proxy.addEventListener('blur',  function(e) { moneyMask(e); syncProxyParaTabela(); calculaGama(); });
    }

    // Valor de Gama (gama_vd / gama_vr) — máscara + cálculo + sync proxy
    ['gama_vd','gama_vr'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', function(e) { moneyMask(e); calculaGama(); atualizaGamaDestaque(); });
        el.addEventListener('blur',  function(e) { moneyMask(e); calculaGama(); atualizaGamaDestaque(); });
    });

    // Valor de venda c/ opcionais (veiculo_valor_vd / veiculo_valor_vr) — máscara + resumo
    ['veiculo_valor_vd','veiculo_valor_vr'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', function(e) { moneyMask(e); calculaResumo(); });
        el.addEventListener('blur',  function(e) { moneyMask(e); calculaResumo(); });
    });

    // Desconto Mont VD — sempre %
    const dmVD = document.getElementById('gama_desc_mont_vd');
    if (dmVD) {
        dmVD.addEventListener('input', maskPct);
        dmVD.addEventListener('blur',  blurPct);
        dmVD.addEventListener('focus', focusPct);
    }

    // Desconto Cedido — comportamento dinâmico (% ou R$)
    ['vd','vr'].forEach(col => {
        const inp = document.getElementById('gama_desc_ced_' + col);
        if (!inp) return;
        inp.addEventListener('input', function(e) {
            const tipo = document.getElementById('gama_desc_ced_' + col + '_tipo')?.value || 'pct';
            if (tipo === 'pct') maskPct(e);
            else { moneyMask(e); calculaGama(); }
        });
        inp.addEventListener('blur', function(e) {
            const tipo = document.getElementById('gama_desc_ced_' + col + '_tipo')?.value || 'pct';
            if (tipo === 'pct') blurPct(e);
            else calculaGama();
        });
        inp.addEventListener('focus', function(e) {
            const tipo = document.getElementById('gama_desc_ced_' + col + '_tipo')?.value || 'pct';
            if (tipo === 'pct') focusPct(e);
        });
    });
});
</script>
</body>
</html>