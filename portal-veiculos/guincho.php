<?php
require_once __DIR__ . '/config_database.php';
session_start();

if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

/* ====== CONEXÃO ====== */
/** @var mysqli $mysqli */
$mysqli = $mysqli ?? null;
if (!$mysqli) {
    exit('Sem conexão com o banco.');
}

/* ====== TRATAMENTO POST (INSERIR / CONCLUIR / CANCELAR) ====== */
$erros  = [];
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'novo') {
        $placa           = trim($_POST['placa'] ?? '');
        $origem          = trim($_POST['origem'] ?? '');
        $destino         = trim($_POST['destino'] ?? '');
        $empresa_guincho = trim($_POST['empresa_guincho'] ?? '');
        $motorista       = trim($_POST['motorista'] ?? '');
        $tel_motorista   = trim($_POST['tel_motorista'] ?? '');
        $data_saida      = trim($_POST['data_saida'] ?? '');
        $prev_chegada    = trim($_POST['prev_chegada'] ?? '');
        $observacoes     = trim($_POST['observacoes'] ?? '');

        if ($placa === '' || $origem === '' || $destino === '' || $empresa_guincho === '' || $data_saida === '') {
            $erros[] = 'Preencha placa, origem, destino, empresa de guincho e data/hora de saída.';
        } else {
            $sql = "
                INSERT INTO guinchos_mov
                (id_veiculo, placa, origem, destino, empresa_guincho, motorista, tel_motorista,
                 data_saida, prev_chegada, status, observacoes)
                VALUES
                (0, ?, ?, ?, ?, ?, ?, ?, ?, 'em_transito', ?)
            ";

            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
                $prev = $prev_chegada !== '' ? $prev_chegada : null;
                $stmt->bind_param(
                    'ssssssss',
                    $placa,
                    $origem,
                    $destino,
                    $empresa_guincho,
                    $motorista,
                    $tel_motorista,
                    $data_saida,
                    $prev,
                    $observacoes
                );
                if ($stmt->execute()) {
                    $sucesso = 'Movimentação de guincho cadastrada com sucesso.';
                } else {
                    $erros[] = 'Erro ao salvar: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $erros[] = 'Erro ao preparar INSERT.';
            }
        }
    }

    if ($acao === 'concluir' || $acao === 'cancelar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $erros[] = 'ID inválido.';
        } else {
            if ($acao === 'concluir') {
                $sql = "UPDATE guinchos_mov
                           SET status = 'concluido',
                               data_chegada = NOW()
                         WHERE id = ?";
            } else {
                $sql = "UPDATE guinchos_mov
                           SET status = 'cancelado'
                         WHERE id = ?";
            }
            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    $sucesso = $acao === 'concluir'
                        ? 'Movimentação concluída.'
                        : 'Movimentação cancelada.';
                } else {
                    $erros[] = 'Erro ao atualizar: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $erros[] = 'Erro ao preparar UPDATE.';
            }
        }
    }
}

/* ====== FILTROS GET ====== */
$filtro_placa  = trim($_GET['placa'] ?? '');
$filtro_status = trim($_GET['status'] ?? '');

/* ====== BUSCA LISTA ====== */
$sqlLista = "
    SELECT
        id,
        placa,
        origem,
        destino,
        empresa_guincho,
        data_saida,
        prev_chegada,
        data_chegada,
        status
    FROM guinchos_mov
    WHERE 1 = 1
";

$params = [];
$types  = '';

if ($filtro_placa !== '') {
    $sqlLista .= " AND placa LIKE CONCAT('%', ?, '%')";
    $params[] = $filtro_placa;
    $types   .= 's';
}

if ($filtro_status !== '') {
    $sqlLista .= " AND status = ?";
    $params[] = $filtro_status;
    $types   .= 's';
}

$sqlLista .= " ORDER BY data_saida DESC, id DESC";

$stmtLista = $mysqli->prepare($sqlLista);
if ($stmtLista) {
    if (!empty($params)) {
        $stmtLista->bind_param($types, ...$params);
    }
    $stmtLista->execute();
    $resultLista = $stmtLista->get_result();
} else {
    $resultLista = false;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Controle de Guinchos</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; }
        .filtros, .form-novo { margin-bottom: 20px; padding: 10px; border: 1px solid #ccc; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background-color: #f0f0f0; }
        .status-aberto      { color: #0066cc; font-weight: bold; }
        .status-em_transito { color: #ff9900; font-weight: bold; }
        .status-concluido   { color: #009900; font-weight: bold; }
        .status-cancelado   { color: #cc0000; font-weight: bold; }
        .msg-erro { color: #c00; margin-bottom: 5px; }
        .msg-ok   { color: #080; margin-bottom: 5px; }
        .btn { padding: 4px 8px; font-size: 12px; cursor: pointer; }
    </style>
</head>
<body>

<h1>Controle de Guinchos</h1>

<?php if (!empty($erros)): ?>
    <?php foreach ($erros as $e): ?>
        <div class="msg-erro"><?php echo htmlspecialchars($e); ?></div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($sucesso): ?>
    <div class="msg-ok"><?php echo htmlspecialchars($sucesso); ?></div>
<?php endif; ?>

<div class="filtros">
    <form method="get">
        <label>
            Placa:
            <input type="text" name="placa" value="<?php echo htmlspecialchars($filtro_placa); ?>">
        </label>
        &nbsp;
        <label>
            Status:
            <select name="status">
                <option value="">Todos</option>
                <option value="aberto"     <?php if ($filtro_status==='aberto')     echo 'selected'; ?>>Aberto</option>
                <option value="em_transito"<?php if ($filtro_status==='em_transito')echo 'selected'; ?>>Em trânsito</option>
                <option value="concluido"  <?php if ($filtro_status==='concluido')  echo 'selected'; ?>>Concluído</option>
                <option value="cancelado"  <?php if ($filtro_status==='cancelado')  echo 'selected'; ?>>Cancelado</option>
            </select>
        </label>
        &nbsp;
        <button type="submit" class="btn">Filtrar</button>
    </form>
</div>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Placa</th>
            <th>Origem</th>
            <th>Destino</th>
            <th>Empresa do guincho</th>
            <th>Saída</th>
            <th>Prev. chegada</th>
            <th>Chegada</th>
            <th>Status</th>
            <th>Ações</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($resultLista && $resultLista->num_rows > 0): ?>
        <?php while ($row = $resultLista->fetch_assoc()): ?>
            <tr>
                <td><?php echo (int)$row['id']; ?></td>
                <td><?php echo htmlspecialchars($row['placa']); ?></td>
                <td><?php echo htmlspecialchars($row['origem']); ?></td>
                <td><?php echo htmlspecialchars($row['destino']); ?></td>
                <td><?php echo htmlspecialchars($row['empresa_guincho']); ?></td>
                <td><?php echo htmlspecialchars($row['data_saida']); ?></td>
                <td><?php echo htmlspecialchars($row['prev_chegada']); ?></td>
                <td><?php echo htmlspecialchars($row['data_chegada']); ?></td>
                <td class="status-<?php echo htmlspecialchars($row['status']); ?>">
                    <?php
                    switch ($row['status']) {
                        case 'aberto':      echo 'Aberto'; break;
                        case 'em_transito': echo 'Em trânsito'; break;
                        case 'concluido':   echo 'Concluído'; break;
                        case 'cancelado':   echo 'Cancelado'; break;
                        default:            echo htmlspecialchars($row['status']); break;
                    }
                    ?>
                </td>
                <td>
                    <?php if ($row['status'] === 'em_transito' || $row['status'] === 'aberto'): ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="acao" value="concluir">
                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                            <button type="submit" class="btn">Concluir</button>
                        </form>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="acao" value="cancelar">
                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                            <button type="submit" class="btn" onclick="return confirm('Cancelar esta movimentação?');">
                                Cancelar
                            </button>
                        </form>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr><td colspan="10">Nenhuma movimentação encontrada.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<div class="form-novo">
    <h2>Novo guincho</h2>
    <form method="post">
        <input type="hidden" name="acao" value="novo">

        <p>
            <label>
                Placa*:
                <input type="text" name="placa" maxlength="10" required>
            </label>
            &nbsp;
            <label>
                Origem*:
                <input type="text" name="origem" maxlength="100" required>
            </label>
            &nbsp;
            <label>
                Destino*:
                <input type="text" name="destino" maxlength="100" required>
            </label>
        </p>

        <p>
            <label>
                Empresa do guincho*:
                <input type="text" name="empresa_guincho" maxlength="100" required>
            </label>
            &nbsp;
            <label>
                Motorista:
                <input type="text" name="motorista" maxlength="100">
            </label>
            &nbsp;
            <label>
                Tel. motorista:
                <input type="text" name="tel_motorista" maxlength="20">
            </label>
        </p>

        <p>
            <label>
                Data/hora saída*:
                <input type="datetime-local" name="data_saida"
                       value="<?php echo date('Y-m-d\TH:i'); ?>" required>
            </label>
            &nbsp;
            <label>
                Prev. chegada:
                <input type="datetime-local" name="prev_chegada">
            </label>
        </p>

        <p>
            <label>
                Observações:
                <br>
                <textarea name="observacoes" rows="3" cols="80"></textarea>
            </label>
        </p>

        <p>
            <button type="submit" class="btn">Salvar guincho</button>
        </p>
    </form>
</div>

</body>
</html>
