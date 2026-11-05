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

// O que foi conferido: cada OK ou Troca gravado pela tela de Conferência, com
// ou sem inventário (id_inventario = 0 é a conferência avulsa). Na Troca, a
// locação nova vem do Ajuste de Locação feito em seguida.

$hoje = date('Y-m-d');
$de   = (string)($_GET['de']  ?? date('Y-m-d', strtotime('-30 days')));
$ate  = (string)($_GET['ate'] ?? $hoje);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de))  { $de  = date('Y-m-d', strtotime('-30 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) { $ate = $hoje; }

$status = (string)($_GET['status'] ?? '');
if (!in_array($status, ['', 'ok', 'troca'], true)) { $status = ''; }

$loja = (string)($_GET['loja'] ?? '');
if (!preg_match('/^\d+\.\d+$/', $loja)) { $loja = ''; }

$quem = trim((string)($_GET['quem'] ?? ''));

// codigo "empresa.revenda" → cidade e filial.
$lojas    = [];
$nomeLoja = [];
foreach ($pdo->query("SELECT codigo, empresa, revenda, ativo FROM inv_lojas ORDER BY codigo") as $l) {
    $nomeLoja[(string)$l['codigo']] = (string)$l['empresa'] . ' - ' . (string)$l['revenda'];
    if ((int)$l['ativo'] === 1) {
        $lojas[(string)$l['codigo']] = $nomeLoja[(string)$l['codigo']];
    }
}

// Os nomes vêm das próprias conferências, não do cadastro de conferentes: assim
// os nomes de antes do PIN (texto livre) também podem ser filtrados.
$nomes = $pdo->query("
    SELECT DISTINCT dupla_label
      FROM inv_conferencias
     WHERE dupla_label IS NOT NULL AND dupla_label <> ''
     ORDER BY dupla_label
")->fetchAll(PDO::FETCH_COLUMN);

// O ajuste não guarda de qual conferência veio. Casa pela mesma loja, item e
// pessoa, pegando o primeiro ajuste feito até 2 horas depois da Troca.
$sql = "
    SELECT c.*, a.locacao_anterior, a.locacao_nova, a.entrada AS entrada_ajuste
      FROM inv_conferencias c
      LEFT JOIN inv_ajustes_locacao a
        ON c.status = 'troca'
       AND a.id = (
            SELECT a2.id
              FROM inv_ajustes_locacao a2
             WHERE a2.id_inventario    = c.id_inventario
               AND a2.empresa          = c.empresa
               AND a2.revenda          = c.revenda
               AND a2.item_estoque_pub = c.item_estoque_pub
               AND a2.dupla_label     <=> c.dupla_label
               AND a2.ajustado_em     >= c.conferido_em
               AND a2.ajustado_em      < c.conferido_em + INTERVAL 2 HOUR
             ORDER BY a2.ajustado_em, a2.id
             LIMIT 1
       )
     WHERE c.conferido_em >= :de
       AND c.conferido_em <  DATE_ADD(:ate, INTERVAL 1 DAY)
";
$params = [':de' => $de, ':ate' => $ate];
if ($status !== '') {
    $sql .= " AND c.status = :status";
    $params[':status'] = $status;
}
if ($loja !== '') {
    [$emp, $rev] = array_map('intval', explode('.', $loja));
    $sql .= " AND c.empresa = :emp AND c.revenda = :rev";
    $params[':emp'] = $emp;
    $params[':rev'] = $rev;
}
if ($quem !== '') {
    $sql .= " AND c.dupla_label = :quem";
    $params[':quem'] = $quem;
}
$sql .= " ORDER BY c.conferido_em DESC, c.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalOk    = 0;
$totalTroca = 0;
foreach ($linhas as $c) {
    if ($c['status'] === 'ok') { $totalOk++; } else { $totalTroca++; }
}

$h = function ($v): string { return htmlspecialchars((string)$v); };
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <?php $pageTitle = 'Conferências'; require __DIR__ . '/includes/head.php'; ?>
  <style>
    body { background: var(--color-bg); }
    .conf-pagina { padding: var(--space-6) 16px; }
    .conf-filtros { display: flex; gap: var(--space-3); flex-wrap: wrap; align-items: flex-end; margin-bottom: var(--space-4); }
    .conf-filtros label { display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 4px; }
    .conf-resumo { display: flex; gap: var(--space-3); flex-wrap: wrap; margin-bottom: var(--space-4); }
    .table-wrap { overflow-x: auto; }
    .table td.num { text-align: right; font-variant-numeric: tabular-nums; }
    .conf-so-papel { display: none; }

    /* No papel: sem filtros nem botões (o .btn some pelo app.css), com o
       período e os filtros escritos, e a tabela inteira sem rolagem. */
    @page { size: A4 landscape; margin: 10mm; }
    @media print {
      body { background: #fff; }
      .conf-pagina { padding: 0; }
      .conf-filtros { display: none; }
      .conf-so-papel { display: block; font-size: 12px; margin-bottom: 8px; }
      .table-wrap { overflow: visible; }
      .table { font-size: 10px; }
      .table th, .table td { padding: 4px 6px; }
      .table tr { break-inside: avoid; }
      .badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
  </style>
</head>
<body>
  <div class="content-wrapper conf-pagina">
    <div class="page-header d-flex justify-between align-center gap-2">
      <div>
        <h1 class="page-title">Conferências <span>realizadas</span></h1>
        <div class="page-subtitle">Itens conferidos na tela de Conferência: OK ou Troca de locação, com quem conferiu.</div>
      </div>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">Imprimir</button>
        <a href="dashboard_inventario.php" class="btn btn-tertiary btn-sm">Voltar</a>
      </div>
    </div>

    <!-- Só no papel: os filtros somem na impressão, então o que foi filtrado
         vai escrito. -->
    <div class="conf-so-papel">
      Período: <strong><?php echo $h(date('d/m/Y', strtotime($de))); ?> a <?php echo $h(date('d/m/Y', strtotime($ate))); ?></strong>
      · Loja: <strong><?php echo $h($loja === '' ? 'Todas' : ($nomeLoja[$loja] ?? $loja)); ?></strong>
      · Conferente: <strong><?php echo $h($quem === '' ? 'Todos' : $quem); ?></strong>
      · Decisão: <strong><?php echo $status === '' ? 'Todas' : ($status === 'ok' ? 'OK' : 'Troca'); ?></strong>
      · Impresso em <?php echo $h(date('d/m/Y H:i')); ?>
    </div>

    <form method="get" class="conf-filtros">
      <div>
        <label for="de">De</label>
        <input class="form-input" type="date" id="de" name="de" value="<?php echo $h($de); ?>">
      </div>
      <div>
        <label for="ate">Até</label>
        <input class="form-input" type="date" id="ate" name="ate" value="<?php echo $h($ate); ?>">
      </div>
      <div>
        <label for="loja">Loja</label>
        <select class="form-select" id="loja" name="loja">
          <option value="">Todas</option>
          <?php foreach ($lojas as $cod => $nome): ?>
            <option value="<?php echo $h($cod); ?>" <?php echo $loja === $cod ? 'selected' : ''; ?>><?php echo $h($nome); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="quem">Conferente</label>
        <select class="form-select" id="quem" name="quem">
          <option value="">Todos</option>
          <?php foreach ($nomes as $nome): ?>
            <option value="<?php echo $h($nome); ?>" <?php echo $quem === $nome ? 'selected' : ''; ?>><?php echo $h($nome); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="status">Decisão</label>
        <select class="form-select" id="status" name="status">
          <option value="">Todas</option>
          <?php foreach (['ok' => 'OK', 'troca' => 'Troca'] as $v => $r): ?>
            <option value="<?php echo $v; ?>" <?php echo $status === $v ? 'selected' : ''; ?>><?php echo $r; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Filtrar</button>
    </form>

    <div class="conf-resumo">
      <span class="badge badge--dark"><?php echo count($linhas); ?> conferidos</span>
      <span class="badge badge--success"><?php echo $totalOk; ?> OK</span>
      <span class="badge badge--warning"><?php echo $totalTroca; ?> Troca</span>
    </div>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Data</th>
            <th>Loja</th>
            <th>Item</th>
            <th>Locação no Apollo</th>
            <th>Saldo</th>
            <th>Decisão</th>
            <th>Locação nova</th>
            <th>Quem</th>
            <th>Inventário</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($linhas === []): ?>
            <tr><td colspan="9" class="text-muted">Nenhuma conferência no período.</td></tr>
          <?php endif; ?>
          <?php foreach ($linhas as $c):
              $codLoja = (int)$c['empresa'] . '.' . (int)$c['revenda']; ?>
            <tr>
              <td><?php echo $h(date('d/m/Y H:i', strtotime((string)$c['conferido_em']))); ?></td>
              <td><?php echo $h($nomeLoja[$codLoja] ?? $codLoja); ?></td>
              <td>
                <strong><?php echo $h($c['item_estoque_pub']); ?></strong>
                <?php if ($c['entrada'] === 'digitado'): ?>
                  <span class="badge badge--warning">digitado</span>
                <?php endif; ?>
                <br>
                <span class="text-muted" style="font-size:12px;"><?php echo $h($c['descricao']); ?></span>
              </td>
              <td><?php echo $c['locacao_geral'] !== null && $c['locacao_geral'] !== '' ? $h($c['locacao_geral']) : '<span class="text-muted">sem locação</span>'; ?></td>
              <!-- null é "não sei" (snapshot sem Apollo), e não pode parecer zero. -->
              <td class="num"><?php echo $c['saldo_oracle'] === null ? '—' : (int)$c['saldo_oracle']; ?></td>
              <td>
                <?php if ($c['status'] === 'ok'): ?>
                  <span class="badge badge--success">OK</span>
                <?php else: ?>
                  <span class="badge badge--warning">Troca</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($c['status'] !== 'troca'): ?>
                  <span class="text-muted">—</span>
                <?php elseif ($c['locacao_nova'] !== null): ?>
                  <?php echo $h($c['locacao_anterior']); ?> → <strong><?php echo $h($c['locacao_nova']); ?></strong>
                  <?php if ($c['entrada_ajuste'] === 'digitado'): ?>
                    <span class="badge badge--warning">digitada</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted">ajuste não feito</span>
                <?php endif; ?>
              </td>
              <td><?php echo $h($c['dupla_label']); ?></td>
              <td><?php echo (int)$c['id_inventario'] === 0 ? '<span class="text-muted">Avulsa</span>' : 'Nº ' . (int)$c['id_inventario']; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
