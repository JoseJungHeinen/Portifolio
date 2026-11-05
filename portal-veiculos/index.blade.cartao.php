<?php
require_once __DIR__ . '/config_database.php';
session_start();
if (empty($_SESSION['logado'])) {
    header('Location: index.php');
    exit;
}


// BACK-END SIMULADOR CARTÃO
$erros             = [];
$resultado_texto   = '';
$resultado_total   = null;
$resultado_parcela = null;
$cartao_id         = null;
$tabela_parcelas   = [];

$valor_compra    = null;
$qtd_meses       = null;
$nome_cliente    = '';
$cpf_cliente     = '';
$cartao_bandeira = '';
$tipo_pagamento  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $valor_compra = isset($_POST['valor_compra']) ? (float)str_replace(',', '.', $_POST['valor_compra']) : null;

    $nome_cliente = isset($_POST['nome_cliente']) ? trim($_POST['nome_cliente']) : '';
    $cpf_cliente  = isset($_POST['cpf_cliente'])  ? preg_replace('/\D/', '', $_POST['cpf_cliente']) : '';

    $qtd_meses = isset($_POST['qtd_meses']) && $_POST['qtd_meses'] !== ''
        ? (int)$_POST['qtd_meses']
        : null;

    $cartao_bandeira = isset($_POST['cartao_bandeira']) ? trim($_POST['cartao_bandeira']) : '';
    $tipo_pagamento  = isset($_POST['tipo_pagamento'])  ? $_POST['tipo_pagamento'] : '';

    // validações
    if ($nome_cliente === '') {
        $erros[] = 'Informe o nome do cliente.';
    }
    // Documento opcional: aceita vazio, CPF (11 dígitos) ou CNPJ (14 dígitos)
if ($cpf_cliente !== '') {
    $len = strlen($cpf_cliente);

    if ($len !== 11 && $len !== 14) {
        $erros[] = 'Se informar documento, use CPF (11 dígitos) ou CNPJ (14 dígitos).';
    }
}

    if ($valor_compra === null || $valor_compra <= 0) {
        $erros[] = 'Informe um valor de compra válido.';
    }
    if ($tipo_pagamento !== 'debito' && $tipo_pagamento !== 'credito') {
        $erros[] = 'Selecione se é débito ou crédito.';
    }

    // se for débito, força sempre 1 parcela; se for crédito e vier vazio, assume 12
    if ($tipo_pagamento === 'debito') {
        $qtd_meses = 1;
    } else {
        if ($qtd_meses === null) {
            $qtd_meses = 12;
        }
    }

    if ($qtd_meses <= 0 || $qtd_meses > 12) {
        $erros[] = 'A quantidade de parcelas deve ser entre 1 e 12.';
    }

    if (empty($erros)) {
        $P = $valor_compra;
        $n = $qtd_meses;

        $taxa_adm_percent = 2.5;
        $taxa_adm         = $taxa_adm_percent / 100;

        // fatores de crédito
        $fatores = [
            1  => 1.018,
            2  => 0.518,
            3  => 0.351,
            4  => 0.268,
            5  => 0.218,
            6  => 0.185,
            7  => 0.161,
            8  => 0.143,
            9  => 0.129,
            10 => 0.118,
            11 => 0.109,
            12 => 0.101,
        ];

        if ($tipo_pagamento === 'debito') {
            // DÉBITO: 1 parcela, apenas taxa de administração
            $qtd_meses = 1;
            $n         = 1;

            $valor_debito      = $P * (1 + $taxa_adm);
            $resultado_parcela = $valor_debito;
            $resultado_total   = $valor_debito;

            $tabela_parcelas = [[
                'n'       => 1,
                'parcela' => $valor_debito,
                'total'   => $valor_debito,
            ]];
        } else {
            // CRÉDITO
            $debito = $P * (1 + $taxa_adm);

            // REGRA: compras até 5.000 em até 3x sem juros (apenas taxa adm)
            if ($P <= 5000 && $n <= 3) {
                $resultado_parcela = $P / $n;
                $resultado_total   = $P;

                $tabela_parcelas = [];
                $tabela_parcelas[] = [
                    'n'       => $n,
                    'parcela' => $resultado_parcela,
                    'total'   => $resultado_total,
                ];
            } else {
                // regra normal com fatores de crédito
                $fator_escolhido   = $fatores[$n] ?? 0;
                $resultado_parcela = $debito * $fator_escolhido;
                $resultado_total   = $resultado_parcela * $n;

                $tabela_parcelas = [];
                for ($i = 1; $i <= 12; $i++) {
                    if (!isset($fatores[$i])) continue;
                    $valor_parc_i = $debito * $fatores[$i];
                    $total_i      = $valor_parc_i * $i;

                    $tabela_parcelas[] = [
                        'n'       => $i,
                        'parcela' => $valor_parc_i,
                        'total'   => $total_i,
                    ];
                }
            }
        }

        // texto técnico
        $linhas   = [];
        $linhas[] = "Data da simulação: " . date('d/m/Y H:i');
        $linhas[] = "Nome do cliente: " . $nome_cliente;
        $linhas[] = "CPF: " . $cpf_cliente;
        $linhas[] = "Bandeira do cartão: " . ($cartao_bandeira !== '' ? $cartao_bandeira : 'Não informada');
        $linhas[] = "Tipo de pagamento: " . ($tipo_pagamento === 'debito' ? 'Débito' : 'Crédito');
        $linhas[] = "Valor da compra: R$ " . number_format($valor_compra, 2, ',', '.');
        $linhas[] = "Quantidade de parcelas: {$qtd_meses}";
        $linhas[] = "Valor de cada parcela: R$ " . number_format($resultado_parcela, 2, ',', '.');
        $linhas[] = "Total ao final: R$ " . number_format($resultado_total, 2, ',', '.');

        $resultado_texto = implode("\n", $linhas);

        $cpf_usuario = $_SESSION['cpf'] ?? '';

        $stmt = $mysqli->prepare("INSERT INTO cartao
            (cpf_usuario, nome_cliente, bandeira, qtd_meses,
             valor_parcela, valor_total, detalhes, tipo_pagamento, cpf_cliente)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param(
                "sssiddsss",
                $cpf_usuario,
                $nome_cliente,
                $cartao_bandeira,
                $qtd_meses,
                $resultado_parcela,
                $resultado_total,
                $resultado_texto,
                $tipo_pagamento,
                $cpf_cliente
            );
            $stmt->execute();
            $cartao_id = $mysqli->insert_id;
            $stmt->close();
        }

            $stmt2 = $mysqli->prepare("UPDATE cartao SET detalhes = ? WHERE id = ?");
            if ($stmt2) {
                $stmt2->bind_param("si", $resultado_texto, $cartao_id);
                $stmt2->execute();
                $stmt2->close();
            }
        
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Simulador de Cartão de Crédito</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #f3f4f6; min-height: 100vh;
            display: flex; align-items: flex-start; justify-content: center;
            color: #1a1a1a; padding: 2rem 1rem 3rem;
        }
        .page-container {
            background: #ffffff; padding: 2rem 2.5rem 2.5rem;
            border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.07);
            width: 100%; max-width: 900px; border: 1px solid #ebebeb;
        }
        .page-title {
            display: flex; align-items: center; gap: 14px;
            margin-bottom: 1.8rem; padding-bottom: 1.2rem;
            border-bottom: 2px solid #f0f0f0;
            font-size: 1.2rem; font-weight: 700; color: #111;
            letter-spacing: -.01em; text-transform: none; text-align: left;
        }
        .page-title::before {
            content: ''; display: inline-flex;
            width: 44px; height: 44px; min-width: 44px;
            border-radius: 12px;
            background: #cc0000
            url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='22' height='22' fill='none' viewBox='0 0 24 24'%3E%3Crect x='2' y='5' width='20' height='14' rx='3' stroke='white' stroke-width='1.8'/%3E%3Cpath d='M2 10h20' stroke='white' stroke-width='2'/%3E%3Cpath d='M6 15h4' stroke='white' stroke-width='1.8' stroke-linecap='round'/%3E%3C/svg%3E") center/22px no-repeat;
        }
        .form-group { margin-bottom: 1.1rem; }
        label { display: block; margin-bottom: .4rem; color: #444; font-weight: 600; font-size: .85rem; letter-spacing: .02em; }
        input, select {
            width: 100%; padding: 10px 14px; border: 1px solid #ddd;
            border-radius: 999px; font-size: .92rem; background: #fafafa;
            color: #1a1a1a; font-family: inherit; outline: none;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        input:focus, select:focus {
            border-color: #cc0000; background: #fff;
            box-shadow: 0 0 0 3px rgba(204,0,0,.08);
        }
        .btn-primary-custom {
            width: 100%; padding: 11px 18px; background: #cc0000; color: #fff;
            border: none; border-radius: 999px; font-size: .92rem; font-weight: 700;
            cursor: pointer; letter-spacing: .04em; text-transform: uppercase;
            box-shadow: 0 4px 14px rgba(204,0,0,.35); font-family: inherit;
            transition: background .15s, box-shadow .15s, transform .1s;
        }
        .btn-primary-custom:hover { background: #aa0000; box-shadow: 0 6px 18px rgba(204,0,0,.45); }
        .btn-primary-custom:active { transform: translateY(1px); }
        .btn-secondary-custom {
            display: inline-flex; align-items: center; justify-content: center;
            margin-top: .9rem; padding: 9px 20px; border-radius: 999px;
            border: 1px solid #ddd; background: #fff; color: #444;
            font-size: .85rem; font-weight: 600; text-decoration: none;
            cursor: pointer; font-family: inherit;
            transition: border-color .15s, background .15s;
        }
        .btn-secondary-custom:hover { background: #fafafa; border-color: #bbb; }
        .message { margin-top: .9rem; padding: .75rem 1rem; border-radius: 10px; font-size: .85rem; }
        .error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .result-box {
            margin-top: 1.4rem; padding: 1.2rem; border-radius: 14px;
            background: #fafafa; border: 1px solid #ebebeb; font-size: .9rem; line-height: 1.6;
        }
        .result-title { font-weight: 700; margin-bottom: .6rem; font-size: .98rem; color: #111; }
        .actions {
            margin-top: 1rem; display: flex; flex-wrap: wrap;
            gap: .6rem; justify-content: space-between;
        }
        .actions a { flex: 1 1 30%; }
        .actions a:last-child { flex: 1 1 100%; width: 100%; text-align: center; }
        @media (max-width: 640px) {
            body { padding: 0; }
            .page-container { border-radius: 0; box-shadow: none; border: none; padding: 1.5rem 1.2rem 2rem; }
        }
    </style>
</head>
<body>
<div class="page-container">
    <div class="page-title">Simulador de Cartão</div>

    <?php if (!empty($erros)): ?>
        <div class="message error">
            <ul class="mb-0">
                <?php foreach ($erros as $erro): ?>
                    <li><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label>Nome do cliente</label>
            <input type="text" name="nome_cliente"
                   value="<?php echo htmlspecialchars($nome_cliente, ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label>CPF do cliente</label>
            <input type="text" name="cpf_cliente"
                   value="<?php echo htmlspecialchars($cpf_cliente, ENT_QUOTES, 'UTF-8'); ?>"
                   maxlength="18">
        </div>

        <div class="form-group">
            <label>Bandeira do cartão (opcional)</label>
            <input type="text" name="cartao_bandeira"
                   value="<?php echo htmlspecialchars($cartao_bandeira, ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="form-group">
            <label>Tipo de pagamento</label>
            <select id="tipo_pagamento" name="tipo_pagamento" required>
                <option value="">Selecione...</option>
                <option value="debito"  <?php echo $tipo_pagamento === 'debito'  ? 'selected' : ''; ?>>Débito</option>
                <option value="credito" <?php echo $tipo_pagamento === 'credito' ? 'selected' : ''; ?>>Crédito</option>
            </select>
        </div>

        <div class="form-group">
            <label>Valor da compra (R$)</label>
            <input type="text" inputmode="numeric" id="valor_compra_display" name="valor_compra"
                   value="<?php echo $valor_compra !== null ? htmlspecialchars(number_format($valor_compra, 2, ',', '.'), ENT_QUOTES, 'UTF-8') : ''; ?>"
                   autocomplete="off" required>
        </div>

        <div class="form-group">
            <label>Nº de parcelas (somente crédito)</label>
            <input
                type="number"
                id="qtd_meses"
                name="qtd_meses"
                value="<?php echo $qtd_meses !== null ? htmlspecialchars($qtd_meses, ENT_QUOTES, 'UTF-8') : ''; ?>"
                min="1"
                max="12"
            >
        </div>

        <button type="submit" class="btn-primary-custom">
            Calcular
        </button>

        <button type="reset"
                class="btn-secondary-custom"
                style="width:100%;margin-top:8px;">
            Limpar dados e recomeçar
        </button>
    </form>

    <?php if ($resultado_total !== null): ?>
        <div class="result-box">
            <?php if (!empty($cartao_id)): ?>
                <div style="font-size:.85rem;margin-bottom:.4rem;">
                    ID da simulação: <?php echo (int)$cartao_id; ?>
                </div>
            <?php endif; ?>

            <div class="result-title">Resultado da simulação</div>

            <div>
                <?php echo nl2br(htmlspecialchars($resultado_texto, ENT_QUOTES, 'UTF-8')); ?>
            </div>

            <hr style="margin:1rem 0;">

            <div class="result-title">Tabela de parcelas (simulação)</div>

            <div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap;">
                <table style="flex:1 1 60%;min-width:260px;border-collapse:collapse;font-size:.85rem;">
                    <tr style="background:#f5f5f5;">
                        <td style="border:1px solid #ddd;padding:4px 6px;text-align:center;">Nº</td>
                        <td style="border:1px solid #ddd;padding:4px 6px;text-align:right;">R$ Parcela</td>
                        <td style="border:1px solid #ddd;padding:4px 6px;text-align:right;">R$ Total</td>
                    </tr>
                    <?php foreach ($tabela_parcelas as $linha): ?>
                        <?php $ehEscolhida = ($linha['n'] == $qtd_meses); ?>
                        <tr style="<?php echo $ehEscolhida ? 'background:#eef2fd;font-weight:700;' : ''; ?>">
                            <td style="border:1px solid #ddd;padding:4px 6px;text-align:center;<?php echo $ehEscolhida ? 'color:#cc0000;' : ''; ?>">
                                <?php echo $linha['n']; ?>
                                <?php if ($ehEscolhida): ?> ◀<?php endif; ?>
                            </td>
                            <td style="border:1px solid #ddd;padding:4px 6px;text-align:right;<?php echo $ehEscolhida ? 'color:#cc0000;' : ''; ?>">
                                <?php echo number_format($linha['parcela'], 2, ',', '.'); ?>
                            </td>
                            <td style="border:1px solid #ddd;padding:4px 6px;text-align:right;<?php echo $ehEscolhida ? 'color:#cc0000;' : ''; ?>">
                                <?php echo number_format($linha['total'], 2, ',', '.'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <div style="flex:0 0 220px;padding:.8rem 1rem;border:1px solid #ddd;border-radius:8px;background:#fafafa;">
                    <div style="font-weight:700;margin-bottom:.4rem;">Opção escolhida</div>
                    <div style="font-size:.9rem;margin-bottom:.3rem;">
                        Parcelas: <?php echo $qtd_meses; ?>x
                    </div>
                    <div style="font-size:.9rem;margin-bottom:.3rem;">
                        Parcela: <strong>R$ <?php echo number_format($resultado_parcela, 2, ',', '.'); ?></strong>
                    </div>
                    <div style="font-size:.95rem;font-weight:700;color:#b00000;">
                        Total a pagar: R$ <?php echo number_format($resultado_total, 2, ',', '.'); ?>
                    </div>
                </div>
            </div>

            <?php if ($tipo_pagamento === 'credito'): ?>
            <form method="POST" action="" style="margin-top:1rem;">
                <input type="hidden" name="nome_cliente" value="<?php echo htmlspecialchars($nome_cliente, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="cpf_cliente" value="<?php echo htmlspecialchars($cpf_cliente, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="cartao_bandeira" value="<?php echo htmlspecialchars($cartao_bandeira, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="tipo_pagamento" value="<?php echo htmlspecialchars($tipo_pagamento, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="valor_compra" value="<?php echo htmlspecialchars(number_format($valor_compra, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">

                <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;">
                    <span style="font-size:.9rem;">Recalcular para nº de parcelas:</span>

                    <input
                        type="number"
                        name="qtd_meses"
                        id="qtd_meses_recalc"
                        value="<?php echo htmlspecialchars($qtd_meses, ENT_QUOTES, 'UTF-8'); ?>"
                        min="1"
                        max="12"
                        style="width:70px;padding:6px 10px;border-radius:999px;border:1px solid #ccc;font-size:.9rem;"
                    >

                    <button type="submit"
                            style="padding:6px 16px;border-radius:999px;border:none;background:#2347c8;color:#fff;font-size:.85rem;font-weight:600;cursor:pointer;">
                        Recalcular
                    </button>
                </div>

                <div style="font-size:.8rem;color:#666;margin-top:.3rem;">
                    Em débito será sempre 1x automaticamente.
                </div>
            </form>
            <?php endif; ?>

            <div class="actions">
                <a href="index.blade.cartao.php" class="btn-secondary-custom">
                    Nova simulação
                </a>
                <?php if (!empty($cartao_id)): ?>
                    <a href="ver_cartao.php?id=<?php echo (int)$cartao_id; ?>"
                       class="btn-secondary-custom" target="_blank">
                        Imprimir
                    </a>
                <?php endif; ?>
                <a href="menu.php" class="btn-secondary-custom">
                    Voltar ao menu
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // ---- máscara moeda BR no campo Valor da compra ----
    const valorInput = document.getElementById('valor_compra_display');
    if (valorInput) {
        function formatarMoeda(valor) {
            var digitos = valor.replace(/\D/g, '');
            if (digitos === '') return '';
            var numero = parseInt(digitos, 10);
            var reais = Math.floor(numero / 100);
            var centavos = numero % 100;
            return reais.toLocaleString('pt-BR') + ',' + centavos.toString().padStart(2, '0');
        }
        valorInput.addEventListener('input', function () {
            var pos = this.selectionStart;
            var oldLen = this.value.length;
            this.value = formatarMoeda(this.value);
            var newLen = this.value.length;
            this.setSelectionRange(pos + (newLen - oldLen), pos + (newLen - oldLen));
        });
        // garantir que valor pré-preenchido (vindo do PHP) já está formatado
        if (valorInput.value && !/,/.test(valorInput.value)) {
            var n = parseFloat(valorInput.value);
            if (!isNaN(n)) {
                var cents = Math.round(n * 100);
                var r = Math.floor(cents / 100);
                var c = cents % 100;
                valorInput.value = r.toLocaleString('pt-BR') + ',' + c.toString().padStart(2, '0');
            }
        }
        // antes de submeter, converter formato BR → ponto decimal para o PHP
        valorInput.closest('form').addEventListener('submit', function () {
            var raw = valorInput.value.replace(/\./g, '').replace(',', '.');
            valorInput.value = raw;
        });
    }

    // ---- habilitar/desabilitar campo de parcelas conforme tipo ----
    const tipoPag  = document.getElementById('tipo_pagamento');
    const parcelas = document.getElementById('qtd_meses');

    function atualizarParcelas() {
        if (!parcelas || !tipoPag) return;
        if (tipoPag.value === 'debito') {
            parcelas.value    = '';
            parcelas.disabled = true;
        } else if (tipoPag.value === 'credito') {
            parcelas.disabled = false;
        } else {
            parcelas.value    = '';
            parcelas.disabled = true;
        }
    }

    atualizarParcelas();
    tipoPag.addEventListener('change', atualizarParcelas);
});
</script>
</body>
</html>