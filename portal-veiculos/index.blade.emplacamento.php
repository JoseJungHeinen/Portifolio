<?php
require_once __DIR__ . '/config_database.php';
session_start();

if (empty($_SESSION['logado'])) {
    header('Location: index.php');
    exit;
}

/**
 * TIPOS DE EMPLACAMENTO
 * Vêm da tabela emplacamento_config (tipo_id, nome)
 */
$tipos = [];
$result = $mysqli->query("
    SELECT tipo_id, nome
      FROM emplacamento_config
  ORDER BY tipo_id ASC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $tipos[] = (object)[
            'id'   => (int)$row['tipo_id'],
            'nome' => $row['nome'],
        ];
    }
    $result->free();
}

$erros           = [];
$resultado_tipo  = null;
$resultado_texto = '';
$resultado_total = null;
$emplacamento_id = null;

$ano_veiculo     = null;
$tipo_id         = null;
$valor_nota      = null;
$incluir_placa   = 0;
$nome_cliente    = '';
$cpf_cliente     = '';
$placa_veiculo   = '';
$observacao      = '';
$ipva_aliquota_1 = 0;
$data_nota       = ''; // sem data padrão

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_id         = isset($_POST['tipo_id']) ? (int)$_POST['tipo_id'] : null;
    $incluir_placa   = isset($_POST['incluir_placa']) ? 1 : 0;
    $ipva_aliquota_1 = isset($_POST['ipva_aliquota_1']) ? 1 : 0;
    $ano_veiculo     = isset($_POST['ano_veiculo']) ? (int)$_POST['ano_veiculo'] : null;

    $valor_nota_raw  = isset($_POST['valor_nota']) ? trim($_POST['valor_nota']) : '';
    $valor_nota_raw  = str_replace('.', '', $valor_nota_raw);   // remove pontos de milhar
    $valor_nota_raw  = str_replace(',', '.', $valor_nota_raw);  // vírgula → ponto decimal
    $valor_nota      = is_numeric($valor_nota_raw) ? (float)$valor_nota_raw : 0.0;

    $nome_cliente    = isset($_POST['nome_cliente']) ? trim($_POST['nome_cliente']) : '';
    $cpf_cliente     = isset($_POST['cpf_cliente']) ? preg_replace('/\D/', '', $_POST['cpf_cliente']) : '';
    $placa_veiculo   = isset($_POST['placa_veiculo']) ? strtoupper(trim($_POST['placa_veiculo'])) : '';
    $observacao      = isset($_POST['observacao']) ? trim($_POST['observacao']) : '';

    $data_nota       = isset($_POST['data_nota']) && $_POST['data_nota'] !== ''
        ? $_POST['data_nota']
        : '';
    $mes_nota        = (int)date('m', strtotime($data_nota));

    // validações
    if (!$ano_veiculo || $ano_veiculo < 1900 || $ano_veiculo > (int)date('Y') + 1) {
        $erros[] = 'Informe um ano de veículo válido.';
    }

    if ($nome_cliente === '') {
        $erros[] = 'Informe o nome do cliente.';
    }
    if (!in_array(strlen($cpf_cliente), [11, 14], true)) {
        $erros[] = 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.';
    }
    if (!$tipo_id) {
        $erros[] = 'Selecione um tipo de emplacamento.';
    }
    if ($valor_nota <= 0) {
        $erros[] = 'Informe o valor da nota fiscal do veículo.';
    }
    if ($incluir_placa && $placa_veiculo === '') {
        $erros[] = 'Informe a placa do veículo ao selecionar escolha de placa.';
    }

    // localizar tipo selecionado
    if (empty($erros)) {
        foreach ($tipos as $t) {
            if ($t->id === $tipo_id) {
                $resultado_tipo = $t;
                break;
            }
        }

        if (!$resultado_tipo) {
            $erros[] = 'Tipo de emplacamento inválido.';
        }
    }

    // buscar configuração
    if (empty($erros)) {
        $stmt = $mysqli->prepare("
            SELECT
                taxas,
                ipva_percent,
                placa,
                escolha_placa,
                margem,
                despachante
            FROM emplacamento_config
            WHERE tipo_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            $erros[] = 'Não foi possível preparar a consulta de configuração.';
        } else {
            $stmt->bind_param('i', $tipo_id);
            $stmt->execute();
            $stmt->bind_result(
                $taxas,
                $ipva_percent,
                $placa_cfg,
                $escolha_placa_cfg,
                $margem_cfg,
                $despachante_cfg
            );
            if (!$stmt->fetch()) {
                $erros[] = 'Configuração de emplacamento não encontrada para esse tipo.';
            }
            $stmt->close();
        }
    }

    // cálculo
    if (empty($erros)) {

        // Normaliza valores vindos do banco (evita null)
        $taxas             = (float)($taxas             ?? 0);
        $ipva_percent      = (float)($ipva_percent      ?? 0);
        $placa_cfg         = (float)($placa_cfg         ?? 0);
        $escolha_placa_cfg = (float)($escolha_placa_cfg ?? 0);
        $margem_cfg        = (float)($margem_cfg        ?? 0);
        $despachante_cfg   = (float)($despachante_cfg   ?? 0);

        // IPVA proporcional pelo mês da data da nota
        $ipva_percent_efetivo = $ipva_percent;
        if ($ipva_aliquota_1) {
            $ipva_percent_efetivo = 1.0;
        }

        // IPVA anual
        $valor_ipva_anual = $valor_nota * ($ipva_percent_efetivo / 100.0);

        // Proporcional: (IPVA_anual / 12) * ((12 - mês(data_nota)) + 1)
        $meses_restantes = (12 - $mes_nota) + 1;
        if ($meses_restantes < 0) {
            $meses_restantes = 0;
        } elseif ($meses_restantes > 12) {
            $meses_restantes = 12;
        }
        $valor_ipva_proporcional = ($valor_ipva_anual / 12.0) * $meses_restantes;

        // Taxas sempre entram
        $taxas_efetivas = $taxas;

        // Placa padrão: sempre entra
        $placa_efetiva = $placa_cfg;

        // Escolha de placa: só entra se marcar "Digite a placa"
        if ($incluir_placa) {
            $escolha_placa_efetiva = $escolha_placa_cfg;
        } else {
            $escolha_placa_efetiva = 0.00;
        }

        // Margem sempre entra
        $margem_efetiva = $margem_cfg;

        // Despachante sempre entra (configurável)
        $despachante_efetivo = $despachante_cfg;

        // Normaliza para evitar null no number_format
        $valor_ipva_anual        = (float)$valor_ipva_anual;
        $valor_ipva_proporcional = (float)$valor_ipva_proporcional;
        $taxas_efetivas          = (float)$taxas_efetivas;
        $placa_efetiva           = (float)$placa_efetiva;
        $escolha_placa_efetiva   = (float)$escolha_placa_efetiva;
        $margem_efetiva          = (float)$margem_efetiva;
        $despachante_efetivo     = (float)$despachante_efetivo;

        // Total final (somando despachante)
        $resultado_total =
            $valor_ipva_proporcional +
            $taxas_efetivas +
            $placa_efetiva +
            $escolha_placa_efetiva +
            $margem_efetiva +
            $despachante_efetivo;

        $resultado_total = (float)$resultado_total;

        // Detalhes técnicos
        $linhas   = [];
        $linhas[] = 'Tipo de emplacamento: ' . $resultado_tipo->nome;
        $linhas[] = 'Valor da nota fiscal do veículo: R$ ' . number_format((float)$valor_nota, 2, ',', '.');
        $linhas[] = 'Data da nota: ' . date('d/m/Y', strtotime($data_nota));
        $linhas[] = 'IPVA anual (' . number_format($ipva_percent_efetivo, 2, ',', '.') . '%): R$ ' . number_format($valor_ipva_anual, 2, ',', '.');
        $linhas[] = 'IPVA proporcional: R$ ' . number_format($valor_ipva_proporcional, 2, ',', '.');
        $linhas[] = 'Taxas: R$ ' . number_format($taxas_efetivas, 2, ',', '.');
        $linhas[] = 'Escolha de placa: ' . ($incluir_placa ? 'Sim' : 'Não');
        $linhas[] = 'Valor placa: R$ ' . number_format($placa_efetiva, 2, ',', '.');
        $linhas[] = 'Valor serviço escolha de placa: R$ ' . number_format($escolha_placa_efetiva, 2, ',', '.');
        $linhas[] = 'Margem: R$ ' . number_format($margem_efetiva, 2, ',', '.');
        $linhas[] = 'Despachante: R$ ' . number_format($despachante_efetivo, 2, ',', '.');
        $linhas[] = 'Total calculado: R$ ' . number_format($resultado_total, 2, ',', '.');

        $resultado_texto = implode("\n", $linhas);

        // gravar no banco
        $cpf_usuario  = $_SESSION['cpf'] ?? '';
        $placa_str    = $placa_veiculo;
        $tipo_veiculo = $resultado_tipo->nome;
        $valor_total  = $resultado_total;

        $stmt = $mysqli->prepare("INSERT INTO emplacamento
            (cpf_usuario, tipo_id, placa, tipo_veiculo, valor, observacoes, observacao, nome_cliente, cpf_cliente)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param(
                'sissdssss',
                $cpf_usuario,
                $tipo_id,
                $placa_str,
                $tipo_veiculo,
                $valor_total,
                $resultado_texto,
                $observacao,
                $nome_cliente,
                $cpf_cliente
            );
            $stmt->execute();
            $emplacamento_id = $mysqli->insert_id;
            $stmt->close();
        }
    }
} // fecha o if ($_SERVER['REQUEST_METHOD'] === 'POST')
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Simulador de Emplacamento</title>
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
    width: 100%; max-width: 640px; border: 1px solid #ebebeb;
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
    border-radius: 12px; background: #cc0000
    url("data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%20512%20512%22%3E%3Cpath%20fill%3D%22white%22%20d%3D%22M499.2%20240l-51.2-144C440.9%2075.1%20422.2%2064%20400%2064H112C89.8%2064%2071.1%2075.1%2064%2096L12.8%20240C5.3%20243.3%200%20250.9%200%20260v176c0%208.8%207.2%2016%2016%2016h32c8.8%200%2016-7.2%2016-16v-16h384v16c0%208.8%207.2%2016%2016%2016h32c8.8%200%2016-7.2%2016-16V260c0-9.1-5.3-16.7-12.8-20zM112%2096h288l38.4%20112H73.6L112%2096zM64%20352c-17.7%200-32-14.3-32-32s14.3-32%2032-32%2032%2014.3%2032%2032-14.3%2032-32%2032zm384%200c-17.7%200-32-14.3-32-32s14.3-32%2032-32%2032%2014.3%2032%2032-14.3%2032-32%2032z%22%2F%3E%3Ccircle%20fill%3D%22white%22%20cx%3D%2264%22%20cy%3D%22320%22%20r%3D%2220%22%2F%3E%3Ccircle%20fill%3D%22white%22%20cx%3D%22448%22%20cy%3D%22320%22%20r%3D%2220%22%2F%3E%3C%2Fsvg%3E") center/26px no-repeat;
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
textarea {
    width: 100%; padding: 10px 14px; border: 1px solid #ddd;
    border-radius: 12px; font-size: .92rem; background: #fafafa;
    color: #1a1a1a; font-family: inherit; outline: none; resize: vertical;
    transition: border-color .15s, box-shadow .15s, background .15s;
}
textarea:focus { border-color: #cc0000; background: #fff; box-shadow: 0 0 0 3px rgba(204,0,0,.08); }
.form-check { margin-bottom: .8rem; display: flex; align-items: center; gap: .5rem; }
.form-check input[type="checkbox"] { width: auto; accent-color: #cc0000; }
.form-check label { margin: 0; font-weight: 500; font-size: .88rem; color: #555; }
.btn-primary {
    width: 100%; padding: 11px 18px; background: #cc0000; color: #fff;
    border: none; border-radius: 999px; font-size: .92rem; font-weight: 700;
    cursor: pointer; letter-spacing: .04em; text-transform: uppercase;
    box-shadow: 0 4px 14px rgba(204,0,0,.35); font-family: inherit;
    transition: background .15s, box-shadow .15s, transform .1s;
}
.btn-primary:hover { background: #aa0000; box-shadow: 0 6px 18px rgba(204,0,0,.45); }
.btn-primary:active { transform: translateY(1px); }
.btn-secondary {
    display: inline-flex; align-items: center; justify-content: center;
    margin-top: .9rem; padding: 9px 20px; border-radius: 999px;
    border: 1px solid #ddd; background: #fff; color: #444;
    font-size: .85rem; font-weight: 600; text-decoration: none;
    cursor: pointer; font-family: inherit;
    transition: border-color .15s, background .15s;
}
.btn-secondary:hover { background: #fafafa; border-color: #bbb; }
.message { margin-top: .9rem; padding: .75rem 1rem; border-radius: 10px; font-size: .85rem; }
.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.result-box {
    margin-top: 1.4rem; padding: 1.2rem; border-radius: 14px;
    background: #fafafa; border: 1px solid #ebebeb; font-size: .9rem; line-height: 1.6;
}
.result-title { font-weight: 700; margin-bottom: .6rem; font-size: .98rem; color: #111; }
.result-total { margin-top: .8rem; font-weight: 700; font-size: 1.1rem; color: #cc0000; }
.actions { margin-top: 1rem; display: flex; flex-wrap: wrap; gap: .6rem; justify-content: space-between; }
.actions .action-top { flex: 1 1 48%; }
.actions .action-bottom { flex: 1 1 100%; }
.linha-escolha-placa { display: flex; align-items: center; gap: 0.8rem; }
.titulo-escolha { white-space: nowrap; font-weight: 600; font-size: .85rem; color: #444; }
.checkbox-wrapper { display: inline-flex; align-items: center; gap: 0.3rem; margin: 0; font-size: .88rem; }
.checkbox-wrapper input[type="checkbox"] { width: auto; margin: 0; accent-color: #cc0000; }
.input-placa { width: auto; max-width: 130px; border-radius: 999px; padding: 10px 14px; }
@media (max-width: 640px) {
    body { padding: 0; }
    .page-container { border-radius: 0; box-shadow: none; border: none; padding: 1.5rem 1.2rem 2rem; }
}
</style>
</head>
<body>
<div class="page-container">
    <div class="page-title">Simulador de Emplacamento</div>

    <?php if (!empty($erros)): ?>
        <div class="message error">
            <?php echo implode('<br>', array_map('htmlspecialchars', $erros)); ?>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <div class="form-group">
            <label>Nome do cliente</label>
            <input type="text" name="nome_cliente"
                   value="<?php echo htmlspecialchars($nome_cliente, ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label>CPF/CNPJ do cliente</label>
            <input type="text" name="cpf_cliente" maxlength="18"
                   value="<?php echo htmlspecialchars($cpf_cliente, ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label>Tipo de emplacamento</label>
            <select name="tipo_id" required>
                <option value="">Selecione...</option>
                <?php
                $old_tipo_id = isset($tipo_id) ? (int)$tipo_id : null;
                foreach ($tipos as $tipo):
                ?>
                    <option value="<?php echo $tipo->id; ?>"
                        <?php echo ($old_tipo_id === $tipo->id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($tipo->nome, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Valor da nota fiscal do veículo</label>
            <input type="text"
                   id="valor_nota"
                   name="valor_nota"
                   inputmode="numeric"
                   placeholder="0,00"
                   autocomplete="off"
                   value="<?php
                       $v = $_POST['valor_nota'] ?? '';
                       if ($v !== '') {
                           $v_num = (float) str_replace(['.', ','], ['', '.'], $v);
                           echo number_format($v_num, 2, ',', '.');
                       }
                   ?>" required>
        </div>

        <div class="form-group">
            <label>Data da nota do veículo</label>
            <input type="date"
                   name="data_nota"
                   value="<?php echo htmlspecialchars($data_nota, ENT_QUOTES, 'UTF-8'); ?>"
                   required>
        </div>

        <div class="form-group">
            <label>Ano do veículo</label>
            <input type="number"
                   name="ano_veiculo"
                   min="1900"
                   max="<?php echo date('Y') + 1; ?>"
                   value="<?php echo htmlspecialchars($ano_veiculo ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                   required>
        </div>

        <div class="form-group">
            <div class="form-check">
                <input type="checkbox"
                       id="ipva_aliquota_1"
                       name="ipva_aliquota_1"
                       value="1"
                       <?php echo $ipva_aliquota_1 ? 'checked' : ''; ?>>
                <label for="ipva_aliquota_1">Cliente com IPVA alíquota 1%</label>
            </div>
        </div>

        <div class="form-group linha-escolha-placa">
            <span class="titulo-escolha">Escolha de placa</span>

            <label class="checkbox-wrapper">
                <input type="checkbox"
                       id="incluir_placa"
                       name="incluir_placa"
                       value="1"
                       onclick="toggleCampoPlaca()"
                       <?php echo $incluir_placa ? 'checked' : ''; ?>>
                <span>Digite a placa</span>
            </label>

            <input type="text"
                   id="placa_veiculo"
                   name="placa_veiculo"
                   maxlength="10"
                   placeholder="Placa"
                   class="input-placa"
                   value="<?php echo htmlspecialchars($placa_veiculo ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="form-group">
            <label>Observação</label>
            <textarea name="observacao" rows="3"
                      style="width:100%;padding:10px 14px;border:1px solid #c0c0c0;border-radius:12px;"><?php
                echo htmlspecialchars($observacao ?? ($_POST['observacao'] ?? ''), ENT_QUOTES, 'UTF-8');
            ?></textarea>
        </div>

        <button type="submit" class="btn-primary">Calcular</button>

        <a href="menu.php" class="btn-secondary" style="width:100%;text-align:center;margin-top:8px;">
            Voltar ao menu
        </a>

        <button type="reset"
                class="btn-secondary"
                style="width:100%;text-align:center;margin-top:8px;">
            Limpar dados e recomeçar
        </button>
    </form>

    <?php if ($resultado_total !== null && $resultado_tipo !== null): ?>
        <div class="result-box">
            <?php if (!empty($emplacamento_id)): ?>
                <div style="font-size:.85rem;margin-bottom:.4rem;">
                    ID da simulação: <?php echo (int)$emplacamento_id; ?>
                </div>
            <?php endif; ?>

            <div class="result-title">
                <?php echo htmlspecialchars($resultado_tipo->nome, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div>
                <?php echo nl2br(htmlspecialchars($resultado_texto, ENT_QUOTES, 'UTF-8')); ?>
            </div>
            <div class="result-total">
                Total: R$ <?php echo number_format($resultado_total, 2, ',', '.'); ?>
            </div>

            <div class="actions">
                <a href="index.blade.emplacamento.php" class="btn-secondary action-top">
                    Nova simulação
                </a>

                <?php if (!empty($emplacamento_id)): ?>
                    <a href="ver_emplacamento.php?id=<?php echo (int)$emplacamento_id; ?>"
                       class="btn-secondary action-top">
                        Imprimir
                    </a>
                <?php endif; ?>

                <a href="menu.php" class="btn-secondary action-bottom" style="text-align:center;">
                    Voltar ao menu
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleCampoPlaca() {
    const chk   = document.getElementById('incluir_placa');
    const campo = document.getElementById('placa_veiculo');

    if (chk.checked) {
        campo.disabled = false;
        campo.focus();
    } else {
        campo.value    = '';
        campo.disabled = true;
    }
}
document.addEventListener('DOMContentLoaded', toggleCampoPlaca);


(function () {
    const campo = document.getElementById('valor_nota');
    if (!campo) return;

    function formatarMoeda(valor) {
        var digitos = valor.replace(/\D/g, '');
        if (digitos === '') return '';
        var numero = parseInt(digitos, 10);
        var reais = Math.floor(numero / 100);
        var centavos = numero % 100;
        var reaisStr = reais.toLocaleString('pt-BR');
        var centavosStr = centavos.toString().padStart(2, '0');
        return reaisStr + ',' + centavosStr;
    }

    campo.addEventListener('input', function () {
        var pos = this.selectionStart;
        var antes = this.value.length;
        this.value = formatarMoeda(this.value);
        var depois = this.value.length;
        this.setSelectionRange(pos + (depois - antes), pos + (depois - antes));
    });

    if (campo.value !== '') {
        campo.value = formatarMoeda(campo.value);
    }
})();
// ───────────────────────────────────────────────────────────────────────────
</script>
</body>
</html>