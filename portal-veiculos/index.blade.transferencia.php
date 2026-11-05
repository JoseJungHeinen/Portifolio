<?php
require_once __DIR__ . '/config_database.php';
session_start();

if (empty($_SESSION['logado'])) {
    header('Location: index.php');
    exit;
}

// TIPOS DE TRANSFERÊNCIA
$tipos = [
    (object)['id' => 1, 'nome' => 'Moto'],
    (object)['id' => 2, 'nome' => 'Carro 2021 ou mais velho'],
    (object)['id' => 3, 'nome' => 'Carro 2022 ou mais novo'],
    (object)['id' => 4, 'nome' => 'Lojista'],
];

$erros             = [];
$resultado_tipo    = null;
$resultado_texto   = '';
$resultado_total   = null;
$transferencia_id  = null;

// variáveis usadas no layout / impressão
$taxa_ipva_pendente  = 0.00;
$taxa_intermunicipal = 0.00;
$ano_veiculo         = null;
$ipva_pago           = 0;
$intermunicipal      = 0;
$tipo_id             = null;
$nome_cliente        = '';
$cpf_cliente         = '';
$ipva_valor          = 0.00;
$placa_incluir       = 0; // padrão incluir placa
$observacao          = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_id        = isset($_POST['tipo_id'])        ? (int)$_POST['tipo_id']        : null;
    $ano_veiculo    = isset($_POST['ano_veiculo'])    ? (int)$_POST['ano_veiculo']    : null;
    $ipva_pago      = isset($_POST['ipva_pago'])      ? 1 : 0;
    $intermunicipal = isset($_POST['intermunicipal']) ? 1 : 0;
    $placa_incluir  = isset($_POST['placa_incluir'])  ? 1 : 0;

    $nome_cliente   = isset($_POST['nome_cliente'])   ? trim($_POST['nome_cliente'])  : '';
    $cpf_cliente    = isset($_POST['cpf_cliente'])    ? preg_replace('/\D/', '', $_POST['cpf_cliente']) : '';
    $observacao     = isset($_POST['observacao'])     ? trim($_POST['observacao'])    : '';

    // valor digitado de IPVA (quando NÃO considerar pago)
    $ipva_valor_raw = isset($_POST['ipva_valor']) ? str_replace(',', '.', $_POST['ipva_valor']) : '';
    $ipva_valor     = is_numeric($ipva_valor_raw) ? (float)$ipva_valor_raw : 0.00;

    // validações
    if ($nome_cliente === '') {
        $erros[] = 'Informe o nome do cliente.';
    }
    if ($cpf_cliente === '' || strlen($cpf_cliente) != 11) {
        $erros[] = 'Informe um CPF válido (11 dígitos).';
    }
    if (!$tipo_id) {
        $erros[] = 'Selecione um tipo de transferência.';
    }
    if (!$ano_veiculo || $ano_veiculo < 1900 || $ano_veiculo > (int)date('Y') + 1) {
        $erros[] = 'Informe um ano de veículo válido.';
    }
    if (!$ipva_pago && $ipva_valor <= 0) {
        $erros[] = 'Informe o valor do IPVA a considerar quando não estiver pago.';
    }

    // validações específicas dos tipos (BACK-END)
    if ($tipo_id == 2 && $ano_veiculo > 2021) {
        $erros[] = 'Tipo "Carro 2021 ou mais velho" não pode ter ano acima de 2021.';
    }
    if ($tipo_id == 3 && $ano_veiculo <= 2021) {
        $erros[] = 'Tipo "Carro 2022 ou mais novo" não pode ter ano até 2021.';
    }

    if (empty($erros)) {
        // achar o tipo selecionado
        foreach ($tipos as $t) {
            if ($t->id === $tipo_id) {
                $resultado_tipo = $t;
                break;
            }
        }

        // Tabela:
        // Moto:            Placa 180, Inter 330, Transf  990
        // Carro <= 2021:   Placa 350, Inter 330, Transf 1490
        // Carro >= 2022:   Placa 350, Inter 330, Transf 1590
        // Lojista:         Placa 350, Inter 330, Transf 1190

        $valor_placa                 = 0.00;
        $valor_intermunicipal_tabela = 0.00;
        $valor_transferencia_tabela  = 0.00;

        switch ($tipo_id) {
            case 1: // Moto
                $valor_placa                 = 180.00;
                $valor_intermunicipal_tabela = 330.00;
                $valor_transferencia_tabela  = 990.00;
                break;

            case 2: // Carro 2021 ou mais velho
                $valor_placa                 = 350.00;
                $valor_intermunicipal_tabela = 330.00;
                $valor_transferencia_tabela  = 1490.00;
                break;

            case 3: // Carro 2022 ou mais novo
                $valor_placa                 = 350.00;
                $valor_intermunicipal_tabela = 330.00;
                $valor_transferencia_tabela  = 1590.00;
                break;

            case 4: // Lojista
                $valor_placa                 = 350.00;
                $valor_intermunicipal_tabela = 330.00;
                $valor_transferencia_tabela  = 1190.00;
                break;
        }

        // IPVA: se considerar pago, não soma; se não, soma o valor digitado
        $taxa_ipva_pendente = $ipva_pago ? 0.00 : $ipva_valor;

        // Intermunicipal só entra se marcado
        $taxa_intermunicipal = $intermunicipal ? $valor_intermunicipal_tabela : 0.00;

        // Placa: só soma se checkbox marcado
        $valor_placa_efetivo = $placa_incluir ? $valor_placa : 0.00;

        // Soma final
        $resultado_total = $valor_placa_efetivo
                         + $valor_transferencia_tabela
                         + $taxa_ipva_pendente
                         + $taxa_intermunicipal;

        // Detalhes técnicos (sem mês)
        $linhas   = [];
$linhas[] = "Valor placa: R$ " . number_format($valor_placa_efetivo, 2, ',', '.');
$linhas[] = "Valor IPVA considerado: R$ " . number_format($taxa_ipva_pendente, 2, ',', '.');
$linhas[] = "Valor transferência: R$ " . number_format($valor_transferencia_tabela, 2, ',', '.');
$linhas[] = "Taxa intermunicipal: R$ " . number_format($taxa_intermunicipal, 2, ',', '.');
$linhas[] = "Valor calculado: R$ " . number_format($resultado_total, 2, ',', '.');

        $resultado_texto = implode("\n", $linhas);

        // GRAVAR NO BANCO (sem mes_atual)
        $cpf_usuario = $_SESSION['cpf'] ?? '';

        $stmt = $mysqli->prepare("INSERT INTO transferencia
    (cpf_usuario, cpf_cliente, nome_cliente, tipo_id, tipo_nome,
     ano_veiculo, ipva_pago, intermunicipal,
     valor_total, detalhes, observacao)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

if ($stmt) {
    $tipo_nome = $resultado_tipo->nome;

    // s s s i s i i d s s  => 11 tipos
    $stmt->bind_param(
    'sssisiidsss',
    $cpf_usuario,
    $cpf_cliente,
    $nome_cliente,
    $tipo_id,
    $tipo_nome,
    $ano_veiculo,
    $ipva_pago,
    $intermunicipal,
    $resultado_total,
    $resultado_texto,
    $observacao
);
            $stmt->execute();
            $transferencia_id = $mysqli->insert_id;
            $stmt->close();
        }

        // Atualiza a linha "ID da simulação" apenas para exibição na tela
        if (!empty($transferencia_id)) {
            $linhas[1] = "ID da simulação: {$transferencia_id}";
            $resultado_texto = implode("\n", $linhas);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Simulador de Transferência</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Futura","Segoe UI",sans-serif;
    background:#ffffff;min-height:100vh;display:flex;
    align-items:center;justify-content:center;color:#333333;
}
.page-container{
    background:#f4f4f4;padding:2.5rem 3rem;border-radius:12px;
    box-shadow:0 10px 25px rgba(0,0,0,0.12);width:100%;max-width:640px;
}
.page-title{
    text-align:center;margin-bottom:1.8rem;
    font-size:1.3rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
    color:#444;
}
.form-group{margin-bottom:1.1rem;}
label{display:block;margin-bottom:.35rem;color:#555;font-weight:600;font-size:.9rem;letter-spacing:.03em;}
input, select{
    width:100%;padding:10px 14px;border:1px solid #c0c0c0;border-radius:999px;
    font-size:.95rem;background:#fff;color:#333;
}
.form-check{
    margin-bottom:.8rem;display:flex;align-items:center;gap:.4rem;
}
.form-check input[type="checkbox"]{width:auto;}
.form-check label{margin:0;font-weight:500;font-size:.9rem;}
.btn-primary{
    width:100%;padding:11px;background:#2347c8;color:#ffffff;border:none;
    border-radius:999px;font-size:.95rem;font-weight:700;cursor:pointer;
    letter-spacing:.06em;text-transform:uppercase;
    box-shadow:0 6px 16px rgba(35,71,200,0.45);
}
.btn-secondary{
    display:inline-block;margin-top:.9rem;padding:8px 18px;
    border-radius:999px;border:1px solid #ccc;background:#ffffff;
    color:#333;font-size:.85rem;text-decoration:none;text-align:center;
    cursor:pointer;
}
.message{margin-top:.9rem;padding:.8rem;border-radius:6px;font-size:.85rem;}
.error{background:#ffd6d6;color:#b00000;border:1px solid #2347c8;}
.result-box{
    margin-top:1.4rem;padding:1rem;border-radius:8px;background:#ffffff;
    border:1px solid #e0e0e0;font-size:.9rem;line-height:1.4;
}
.result-title{font-weight:700;margin-bottom:.6rem;font-size:.98rem;}
.result-total{margin-top:.8rem;font-weight:700;font-size:1.05rem;color:#b00000;}
.actions{
    margin-top:1rem;
    display:flex;
    flex-wrap:wrap;
    gap:.6rem;
    justify-content:space-between;
}
.actions .action-top{flex:1 1 48%;}
.actions .action-bottom{flex:1 1 100%;}
.ipva-input-pequeno{width:180px;}
</style>
</head>
<body>
<div class="page-container">
    <div class="page-title">Simulador de Transferência</div>

    <?php if (!empty($erros)): ?>
        <div class="message error">
            <?php echo implode('<br>', array_map('htmlspecialchars', $erros)); ?>
        </div>
    <?php endif; ?>

    <form method="post" action="" id="formTransferencia">
        <div class="form-group">
            <label>Nome do cliente</label>
            <input type="text" name="nome_cliente"
                   value="<?php echo htmlspecialchars($nome_cliente, ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label>CPF do cliente</label>
            <input type="text" name="cpf_cliente"
                   value="<?php echo htmlspecialchars($cpf_cliente, ENT_QUOTES, 'UTF-8'); ?>"
                   maxlength="14" required>
        </div>

        <div class="form-group">
            <label>Tipo de transferência</label>
            <select name="tipo_id" id="tipo_id" required>
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
            <label>Ano do veículo</label>
            <input type="number" name="ano_veiculo" id="ano_veiculo"
                   min="1900" max="<?php echo date('Y') + 1; ?>"
                   value="<?php echo htmlspecialchars($ano_veiculo ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <!-- IPVA -->
        <div class="form-group">
            <label>IPVA</label>
            <div style="display:flex; align-items:center; gap:.6rem;">
                <div class="form-check" style="margin-bottom:0;">
                    <input type="checkbox" id="ipva_pago" name="ipva_pago" value="1"
                        <?php echo $ipva_pago ? 'checked' : ''; ?>
                        onclick="toggleIpvaValor()">
                    <label for="ipva_pago">Considerar IPVA já pago</label>
                </div>

                <input type="number"
                       id="ipva_valor"
                       name="ipva_valor"
                       class="ipva-input-pequeno"
                       step="0.01"
                       min="0"
                       placeholder="Valor IPVA"
                       value="<?php echo htmlspecialchars($_POST['ipva_valor'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <!-- Checkbox placa -->
        <div class="form-check">
            <input type="checkbox" id="placa_incluir" name="placa_incluir" value="1"
                <?php echo $placa_incluir ? 'checked' : ''; ?>>
            <label for="placa_incluir">Incluir valor de placa</label>
        </div>

        <div class="form-check">
            <input type="checkbox" id="intermunicipal" name="intermunicipal" value="1"
                <?php echo $intermunicipal ? 'checked' : ''; ?>>
            <label for="intermunicipal">Transferência intermunicipal</label>
        </div>

        <div class="form-group">
            <label>Observação</label>
            <textarea name="observacao" rows="3"
          style="width:100%;padding:10px 14px;border:1px solid #c0c0c0;
                 border-radius:12px;resize:none;"><?php
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
            <?php if (!empty($transferencia_id)): ?>
                <div style="font-size:.85rem;margin-bottom:.4rem;">
                    ID da simulação: <?php echo (int)$transferencia_id; ?>
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
                <a href="index.blade.transferencia.php" class="btn-secondary action-top">
                    Nova simulação
                </a>

                <?php if (!empty($transferencia_id)): ?>
                    <a href="ver_transferencia.php?id=<?php echo (int)$transferencia_id; ?>"
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
function toggleIpvaValor() {
    const chk   = document.getElementById('ipva_pago');
    const campo = document.getElementById('ipva_valor');

    if (chk.checked) {
        campo.value    = '';
        campo.disabled = true;
    } else {
        campo.disabled = false;
        campo.focus();
    }
}
document.addEventListener('DOMContentLoaded', toggleIpvaValor);

// Validação de ano x tipo (apenas no submit)
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('formTransferencia');
    const tipo = document.getElementById('tipo_id');
    const ano  = document.getElementById('ano_veiculo');

    function validaAnoTipo() {
        const tipoVal = parseInt(tipo.value, 10);
        const anoStr  = (ano.value || '').trim();
        if (!tipoVal || anoStr === '') {
            return true; // deixa outras validações cuidarem disso
        }
        const anoVal = parseInt(anoStr, 10);
        if (isNaN(anoVal)) {
            return true;
        }

        // Moto (id 1): sem limitação
        if (tipoVal === 2 && anoVal > 2021) {
            alert('Para "Carro 2021 ou mais velho", o ano do veículo deve ser até 2021.');
            return false;
        }
        if (tipoVal === 3 && anoVal <= 2021) {
            alert('Para "Carro 2022 ou mais novo", o ano do veículo deve ser a partir de 2022.');
            return false;
        }
        return true;
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            if (!validaAnoTipo()) {
                e.preventDefault();
                ano.focus();
            }
        });
    }
});
</script>

</script>
</body>
</html>
