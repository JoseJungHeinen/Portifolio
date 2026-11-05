<?php
declare(strict_types=1);

session_start();
require 'conexao_inventario.php';

if (empty($_SESSION['inventario_login'])) {
    header('Location: index.php');
    exit;
}
if (!in_array($_SESSION['inventario_login']['perfil'] ?? '', ['admin', 'gestor'], true)) {
    header('Location: dashboard_inventario.php');
    exit;
}

// Transferências de saldo entre filiais feitas pela conferência. O Apollo já
// foi alterado nas 'gravado'; 'pendente' é a que abriu o registro e não fechou
// (conferir no Apollo); 'erro' não alterou nada.

$hoje = date('Y-m-d');
$de   = (string)($_GET['de']  ?? date('Y-m-d', strtotime('-30 days')));
$ate  = (string)($_GET['ate'] ?? $hoje);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de))  { $de  = date('Y-m-d', strtotime('-30 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) { $ate = $hoje; }

$status = (string)($_GET['status'] ?? '');
if (!in_array($status, ['', 'gravado', 'pendente', 'erro'], true)) { $status = ''; }

// codigo "empresa.revenda" → nome da filial e cidade.
$nomeLoja = [];
foreach ($pdo->query("SELECT codigo, empresa, revenda FROM inv_lojas") as $l) {
    $nomeLoja[(string)$l['codigo']] = ['cidade' => (string)$l['empresa'], 'filial' => (string)$l['revenda']];
}
$filial = function (int $emp, int $rev) use ($nomeLoja): string {
    return $nomeLoja["$emp.$rev"]['filial'] ?? "Revenda $rev";
};
$cidade = function (int $emp, int $rev) use ($nomeLoja): string {
    return $nomeLoja["$emp.$rev"]['cidade'] ?? "Empresa $emp";
};

$sql = "
    SELECT *
      FROM inv_transferencias
     WHERE criado_em >= :de
       AND criado_em <  DATE_ADD(:ate, INTERVAL 1 DAY)
";
$params = [':de' => $de, ':ate' => $ate];
if ($status !== '') {
    $sql .= " AND status = :status";
    $params[':status'] = $status;
}
$sql .= " ORDER BY criado_em DESC, id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$badge = ['gravado' => 'badge--success', 'pendente' => 'badge--warning', 'erro' => 'badge--danger'];
$h = fn($v) => htmlspecialchars((string)$v);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <?php $pageTitle = 'Transferências entre filiais'; require __DIR__ . '/includes/head.php'; ?>
  <style>
    body { background: var(--color-bg); }
    .transf-pagina { padding: var(--space-6) 16px; }
    .transf-filtros { display: flex; gap: var(--space-3); flex-wrap: wrap; align-items: flex-end; margin-bottom: var(--space-4); }
    .transf-filtros label { display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 4px; }
    .table-wrap { overflow-x: auto; }
    .table td.num { text-align: right; font-variant-numeric: tabular-nums; }
  </style>
</head>
<body>
  <div class="content-wrapper transf-pagina">
    <div class="page-header d-flex justify-between align-center gap-2">
      <div>
        <h1 class="page-title">Transferências <span>entre filiais</span></h1>
        <div class="page-subtitle">Saldo passado de uma filial para outra na conferência. Altera o Apollo direto, sem nota.</div>
      </div>
      <a href="dashboard_inventario.php" class="btn btn-tertiary btn-sm">Voltar</a>
    </div>

    <form method="get" class="transf-filtros">
      <div>
        <label for="de">De</label>
        <input class="form-input" type="date" id="de" name="de" value="<?php echo $h($de); ?>">
      </div>
      <div>
        <label for="ate">Até</label>
        <input class="form-input" type="date" id="ate" name="ate" value="<?php echo $h($ate); ?>">
      </div>
      <div>
        <label for="status">Situação</label>
        <select class="form-select" id="status" name="status">
          <option value="">Todas</option>
          <?php foreach (['gravado' => 'Gravadas', 'pendente' => 'Pendentes', 'erro' => 'Com erro'] as $v => $r): ?>
            <option value="<?php echo $v; ?>" <?php echo $status === $v ? 'selected' : ''; ?>><?php echo $r; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Filtrar</button>
    </form>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Data</th>
            <th>Cidade</th>
            <th>Item</th>
            <th>De</th>
            <th>Para</th>
            <th>Qtd</th>
            <th>Saldo antes (de / para)</th>
            <th>Locação</th>
            <th>Quem</th>
            <th>Situação</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($linhas === []): ?>
            <tr><td colspan="10" class="muted">Nenhuma transferência no período.</td></tr>
          <?php endif; ?>
          <?php foreach ($linhas as $t):
              $emp = (int)$t['empresa']; ?>
            <tr>
              <td><?php echo $h(date('d/m/Y H:i', strtotime((string)$t['criado_em']))); ?></td>
              <td><?php echo $h($cidade($emp, (int)$t['revenda_destino'])); ?></td>
              <td>
                <strong><?php echo $h($t['item_estoque_pub']); ?></strong><br>
                <span class="muted" style="font-size:12px;"><?php echo $h($t['descricao']); ?></span>
              </td>
              <td><?php echo $h($filial($emp, (int)$t['revenda_origem'])); ?></td>
              <td><?php echo $h($filial($emp, (int)$t['revenda_destino'])); ?></td>
              <td class="num"><?php echo (int)$t['quantidade']; ?></td>
              <td class="num">
                <?php echo $t['saldo_origem_antes'] === null ? '—' : (int)$t['saldo_origem_antes']; ?>
                /
                <?php echo $t['saldo_destino_antes'] === null ? '—' : (int)$t['saldo_destino_antes']; ?>
              </td>
              <td>
                <?php echo $h($t['locacao_destino']); ?>
                <?php if ($t['entrada_locacao'] === 'digitado'): ?>
                  <span class="badge badge--warning">digitada</span>
                <?php endif; ?>
              </td>
              <td><?php echo $h($t['dupla_label']); ?></td>
              <td>
                <span class="badge <?php echo $badge[$t['status']] ?? 'badge--muted'; ?>"><?php echo $h($t['status']); ?></span>
                <?php if ($t['status'] === 'erro' && $t['erro']): ?>
                  <div class="muted" style="font-size:12px;"><?php echo $h($t['erro']); ?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
