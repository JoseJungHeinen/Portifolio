<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}



// TIPOS DE TRANSFERÊNCIA
$tipos = [
    (object)['id' => 1, 'nome' => 'Transferência comum'],
    (object)['id' => 2, 'nome' => 'Transferência com financiamento'],
    (object)['id' => 3, 'nome' => 'Transferência com isenção'],
];

$erros = [];
$resultado_tipo      = null;
$resultado_texto     = '';
$resultado_total     = null;
$transferencia_id    = null;

// variáveis usadas no layout / impressão
$taxa_ipva_pendente  = 0.00;
$taxa_intermunicipal = 0.00;
$ano_veiculo         = null;
$mes_atual           = null;
$ipva_pago           = 0;
$intermunicipal      = 0;
$tipo_id             = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_id        = isset($_POST['tipo_id'])        ? (int)$_POST['tipo_id']        : null;
    $ano_veiculo    = isset($_POST['ano_veiculo'])    ? (int)$_POST['ano_veiculo']    : null;
    $mes_atual      = isset($_POST['mes_atual'])      ? (int)$_POST['mes_atual']      : null;
    $ipva_pago      = isset($_POST['ipva_pago'])      ? 1 : 0;
    $intermunicipal = isset($_POST['intermunicipal']) ? 1 : 0;
    $nome_cliente   = isset($_POST['nome_cliente']) ? trim($_POST['nome_cliente']) : '';
    $cpf_cliente    = isset($_POST['cpf_cliente'])  ? preg_replace('/\D/', '', $_POST['cpf_cliente']) : '';

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
    if (!$mes_atual || $mes_atual < 1 || $mes_atual > 12) {
        $erros[] = 'Informe um mês entre 1 e 12.';
    }

    if (empty($erros)) {
        foreach ($tipos as $t) {
            if ($t->id === $tipo_id) {
                $resultado_tipo = $t;
                break;
            }
        }

        $base = 500;
        $idade_veiculo = (int)date('Y') - $ano_veiculo;
        if ($idade_veiculo < 5) {
            $base += 300;
        } elseif ($idade_veiculo < 10) {
            $base += 150;
        } else {
            $base += 50;
        }

        $taxa_ipva_pendente   = $ipva_pago ? 0.00  : 200.00;
        $taxa_intermunicipal  = $intermunicipal ? 100.00 : 0.00;

        $base += $taxa_ipva_pendente + $taxa_intermunicipal;
        $resultado_total = $base;

        $linhas = [];
        $linhas[] = "Data da simulação: " . date('d/m/Y H:i');
        $linhas[] = "Nome do cliente: {$nome_cliente}";
        $linhas[] = "CPF do cliente: {$cpf_cliente}";
        $linhas[] = "Tipo de transferência: {$resultado_tipo->nome}";
        $linhas[] = "Ano do veículo: {$ano_veiculo}";
        $linhas[] = "Mês atual: {$mes_atual}";
        $linhas[] = "IPVA pago: " . ($ipva_pago ? 'Sim' : 'Não');
        $linhas[] = "Intermunicipal: " . ($intermunicipal ? 'Sim' : 'Não');
        $linhas[] = "Taxa IPVA pendente: R$ " . number_format($taxa_ipva_pendente, 2, ',', '.');
        $linhas[] = "Taxa intermunicipal: R$ " . number_format($taxa_intermunicipal, 2, ',', '.');
        $linhas[] = "Valor calculado: R$ " . number_format($resultado_total, 2, ',', '.');

        $resultado_texto = implode("\n", $linhas);

        $cpf_usuario = $_SESSION['cpf'] ?? '';

        $stmt = $mysqli->prepare("INSERT INTO transferencia
            (cpf_usuario, cpf_cliente, nome_cliente, tipo_id, tipo_nome, ano_veiculo, mes_atual,
             ipva_pago, intermunicipal, valor_total, detalhes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $tipo_nome = $resultado_tipo->nome;

            $stmt->bind_param(
                "sssissiiids",
                $cpf_usuario,
                $cpf_cliente,
                $nome_cliente,
                $tipo_id,
                $tipo_nome,
                $ano_veiculo,
                $mes_atual,
                $ipva_pago,
                $intermunicipal,
                $resultado_total,
                $resultado_texto
            );
            $stmt->execute();
            $transferencia_id = $mysqli->insert_id;
            $stmt->close();
        }

        if (!empty($transferencia_id)) {
            array_splice($linhas, 1, 0, "ID da simulação: " . $transferencia_id);
            $resultado_texto = implode("\n", $linhas);
        }
    }
}
?>
<style>
/* ... seu CSS existente ... */

.app-version-label{
    position:fixed;
    bottom:4px;
    right:8px;
    font-size:10px;
    color:#888;
    opacity:0.6;
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    pointer-events:none;
    z-index:9999;
}
</style>
<div class="page-container">
    <div class="logo">
        <img src="img/logo.svg" alt="Logo Exemplo">
    </div>
    <div class="page-title">Simulador de Transferência</div>

    <?php if (!empty($erros)): ?>
        <div class="message error">
            <?php echo implode('<br>', array_map('htmlspecialchars', $erros)); ?>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <div class="form-group">
            <label>Nome do cliente</label>
            <input type="text" name="nome_cliente"
                   value="<?php echo htmlspecialchars($nome_cliente ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label>CPF do cliente</label>
            <input type="text" name="cpf_cliente"
                   value="<?php echo htmlspecialchars($cpf_cliente ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                   maxlength="14" required>
        </div>

        <div class="form-group">
            <label>Tipo de transferência</label>
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
            <label>Ano do veículo</label>
            <input type="number" name="ano_veiculo" min="1900" max="<?php echo date('Y') + 1; ?>"
                   value="<?php echo htmlspecialchars($ano_veiculo ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label>Mês atual (1 a 12)</label>
            <input type="number" name="mes_atual" min="1" max="12"
                   value="<?php echo htmlspecialchars($mes_atual ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-check">
            <input type="checkbox" id="ipva_pago" name="ipva_pago" value="1"
                <?php echo $ipva_pago ? 'checked' : ''; ?>>
            <label for="ipva_pago">IPVA já está pago</label>
        </div>

        <div class="form-check">
            <input type="checkbox" id="intermunicipal" name="intermunicipal" value="1"
                <?php echo $intermunicipal ? 'checked' : ''; ?>>
            <label for="intermunicipal">Transferência intermunicipal</label>
        </div>

        <button type="submit" class="btn-primary">Calcular</button>

        <a href="menu.php" class="btn-secondary" style="width:100%;text-align:center;">
            Voltar ao menu
        </a>
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
                <a href="menu.php?pagina=transferencia" class="btn-secondary action-top">
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
    <div class="app-version-label">
    <?php echo VERSAO_CONTEUDO_TRANSFERENCIA; ?>
</div>
</div>
