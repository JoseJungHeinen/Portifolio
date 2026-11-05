<?php
declare(strict_types=1);

session_start();
require 'conexao_inventario.php';

if (empty($_SESSION['inventario_login'])) {
    header('Location: index.php');
    exit;
}

// ── Lojas com inventário aberto ──
$lojas = $pdo->query("
    SELECT DISTINCT
        l.codigo,
        l.empresa AS nome_empresa,
        l.revenda AS nome_revenda,
        CAST(SUBSTRING_INDEX(l.codigo,'.',1)  AS UNSIGNED) AS emp_num,
        CAST(SUBSTRING_INDEX(l.codigo,'.',-1) AS UNSIGNED) AS rev_num
    FROM inv_inventarios i
    INNER JOIN inv_lojas l
        ON l.ativo = 1
       AND CAST(SUBSTRING_INDEX(l.codigo,'.',1)  AS UNSIGNED) = i.empresa
       AND CAST(SUBSTRING_INDEX(l.codigo,'.',-1) AS UNSIGNED) = i.revenda
    ORDER BY l.codigo
")->fetchAll(PDO::FETCH_ASSOC);

// ── Parâmetros ──
$codSel = (string)($_GET['loja'] ?? ($lojas[0]['codigo'] ?? ''));
$invSel = ($_GET['inv'] ?? '') !== '' ? (int)$_GET['inv'] : null;

// Empresa/revenda da loja selecionada
$empSel = $revSel = null;
foreach ($lojas as $lj) {
    if ($lj['codigo'] === $codSel) {
        $empSel = (int)$lj['emp_num'];
        $revSel = (int)$lj['rev_num'];
        break;
    }
}

// ── Inventários da loja selecionada ──
$inventarios = [];
if ($empSel && $revSel) {
    $inventarios = $pdo->prepare("
        SELECT id, DATE_FORMAT(data_inicio,'%d/%m/%Y') AS dt, status
        FROM inv_inventarios
        WHERE empresa = :e AND revenda = :r
        ORDER BY data_inicio DESC
    ");
    $inventarios->execute([':e' => $empSel, ':r' => $revSel]);
    $inventarios = $inventarios->fetchAll(PDO::FETCH_ASSOC);
    if ($invSel === null && !empty($inventarios)) {
        $invSel = (int)$inventarios[0]['id'];
    }
}

// ── Itens descartados ──
$itens      = [];
$totalCusto = 0.0;
$totalQtd   = 0;

if ($empSel && $revSel && $invSel) {
    $stmt = $pdo->prepare("
        SELECT
            e.item_estoque_pub,
            e.descricao_item_estoque,
            e.quantidade         AS saldo_oracle,
            e.class_abc,
            e.locacao_geral,
            e.custo_entrada,
            e.cont_1,
            e.cont_2,
            d.qtd_ajuste,
            d.observacao,
            d.usuario,
            d.criado_em,
            (e.custo_entrada * GREATEST(e.cont_1, 1)) AS valor_total
        FROM estoque e
        INNER JOIN inv_decisoes d
            ON d.id_inventario    = e.id_inventario
           AND d.empresa          = e.empresa
           AND d.revenda          = e.revenda
           AND d.item_estoque_pub = e.item_estoque_pub
           AND d.decisao          = 'desconsiderar'
        WHERE e.empresa       = :emp
          AND e.revenda       = :rev
          AND e.id_inventario = :inv
        ORDER BY e.locacao_geral, e.item_estoque_pub
    ");
    $stmt->execute([':emp' => $empSel, ':rev' => $revSel, ':inv' => $invSel]);
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($itens as $it) {
        $totalCusto += (float)$it['valor_total'];
        $totalQtd   += (int)$it['cont_1'];
    }
}

$nomeLoja = '';
foreach ($lojas as $lj) {
    if ($lj['codigo'] === $codSel) {
        $nomeLoja = trim($lj['nome_empresa'] . ' ' . $lj['nome_revenda']);
        break;
    }
}

function fmtBr(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Relatório de Descarte por Inventário</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --red: #2347C8;
      --red-light: #FFEBEE;
      --amber: #F59E0B;
      --amber-light: #FFFBEB;
      --gray-50: #F5F5F7;
      --gray-100: #ECEFF1;
      --gray-200: #E0E0E0;
      --gray-400: #B0BEC5;
      --gray-600: #546E7A;
      --gray-800: #263238;
      --white: #FFFFFF;
      --radius-card: 18px;
      --shadow-soft: 0 10px 20px rgba(15,23,42,0.18);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      background: radial-gradient(circle at top, #FFF 0, #E3F2FD 55%, #CFD8DC 100%);
      color: var(--gray-800);
      -webkit-font-smoothing: antialiased;
    }
    .app { min-height: 100vh; display: flex; flex-direction: column; }

    /* HEADER */
    .header {
      padding: 10px 16px;
      background: linear-gradient(135deg, #B71C1C, var(--red));
      color: #fff;
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      box-shadow: 0 4px 10px rgba(183,28,28,0.45);
      position: sticky; top: 0; z-index: 100;
    }
    .header-left { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .header-badge {
      padding: 2px 8px; border-radius: 999px;
      background: rgba(255,255,255,0.16);
      font-size: 11px; text-transform: uppercase; letter-spacing: .08em; white-space: nowrap;
    }
    .logout-btn {
      margin-left: auto; padding: 6px 12px; border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.75); background: rgba(244,67,54,0.15);
      color: #fff; font-size: 13px; text-decoration: none;
      display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
      transition: background 0.15s; white-space: nowrap;
    }
    .logout-btn:hover { background: rgba(255,255,255,0.22); }
    .logout-btn svg { width: 14px; height: 14px; }

    /* CONTENT */
    .content { flex: 1; padding: 16px; display: flex; flex-direction: column; gap: 14px; }

    /* CARD */
    .card {
      background: rgba(255,255,255,0.98); border-radius: var(--radius-card);
      padding: 14px 16px; display: flex; flex-direction: column; gap: 10px;
      box-shadow: var(--shadow-soft); border: 1px solid rgba(148,163,184,0.25);
    }
    .card-title { font-size: 14px; font-weight: 700; color: var(--gray-800); }

    /* FILTROS */
    .filtro-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    .field { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 160px; }
    .field-label {
      font-size: 12px; font-weight: 600; text-transform: uppercase;
      letter-spacing: .07em; color: var(--gray-600);
    }
    .select {
      height: 44px; border-radius: 12px; border: 1.5px solid var(--gray-200);
      padding: 0 12px; font-size: 14px; background: #FAFAFA;
      color: var(--gray-800); cursor: pointer;
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    .select:focus { outline: none; border-color: var(--red); box-shadow: 0 0 0 2px rgba(35,71,200,0.16); }
    .btn-filtrar {
      height: 44px; padding: 0 20px; border-radius: 12px; border: none;
      background: linear-gradient(135deg, #B71C1C, var(--red));
      color: #fff; font-size: 14px; font-weight: 700; cursor: pointer;
      transition: filter 0.15s, transform 0.05s;
    }
    .btn-filtrar:hover { filter: brightness(1.08); transform: translateY(-1px); }
    .btn-filtrar:active { transform: translateY(0); }

    /* STATS */
    .stats-row { display: flex; gap: 10px; flex-wrap: wrap; }
    .stat-box {
      flex: 1; min-width: 120px;
      background: rgba(255,255,255,0.9); border-radius: 14px; padding: 12px;
      text-align: center; border: 1px solid rgba(148,163,184,0.2);
      box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    .stat-num { font-size: 22px; font-weight: 700; color: var(--red); }
    .stat-lbl { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--gray-600); margin-top: 3px; }

    /* TABELA */
    .table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--gray-200); }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    thead th {
      background: var(--gray-50); padding: 9px 12px;
      text-align: left; font-size: 11px; text-transform: uppercase;
      letter-spacing: .06em; color: var(--gray-600);
      border-bottom: 1px solid var(--gray-200); white-space: nowrap;
    }
    tbody td { padding: 9px 12px; border-bottom: 1px solid var(--gray-100); vertical-align: middle; }
    tbody tr:last-child td { border-bottom: none; }
    tbody tr:hover td { background: var(--red-light); }
    tfoot td {
      padding: 10px 12px; font-size: 13px; font-weight: 700;
      background: var(--gray-50); border-top: 2px solid var(--gray-200);
    }

    .badge-abc {
      padding: 2px 7px; border-radius: 999px; font-size: 11px; font-weight: 700;
      background: var(--gray-100); color: var(--gray-600);
    }
    .badge-abc.A { background: #E8F5E9; color: #2E7D32; }
    .badge-abc.B { background: #E3F2FD; color: #1565C0; }
    .badge-abc.C { background: #FFF3E0; color: #E65100; }

    /* EMPTY */
    .empty-state {
      text-align: center; padding: 48px 20px;
      color: var(--gray-400); font-size: 14px;
    }
    .empty-icon { font-size: 40px; margin-bottom: 12px; }

    /* PRINT */
    .btn-print {
      padding: 6px 14px; border-radius: 999px; border: 1.5px solid var(--gray-200);
      background: #fff; font-size: 12px; font-weight: 600;
      color: var(--gray-600); cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
      transition: border-color 0.15s, color 0.15s;
    }
    .btn-print:hover { border-color: var(--red); color: var(--red); }

    @media (min-width: 768px) {
      .content { max-width: 1100px; margin: 0 auto; width: 100%; }
    }

    @media print {
      .header .logout-btn, .btn-filtrar, .btn-print, .filtro-row { display: none !important; }
      body { background: #fff; }
      .card { box-shadow: none; border: none; }
    }
  </style>
</head>
<body>
<div class="app">

  <header class="header">
    <div class="header-left">
      <span class="header-badge">Relatório de Descarte</span>
      <?php if ($nomeLoja): ?>
        <span class="header-badge"><?= htmlspecialchars($nomeLoja) ?></span>
      <?php endif; ?>
    </div>
    <a href="tomada_decisao.php" class="logout-btn">
      <svg viewBox="0 0 20 20" fill="currentColor">
        <path d="M7 4a1 1 0 0 1 1-1h6a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8a1 1 0 1 1 0-2h6V5H8a1 1 0 0 1-1-1z"/>
        <path d="M4.293 9.293a1 1 0 0 1 1.414 0L7 10.586 5.707 11.88a1 1 0 1 1-1.414-1.414L4.586 10 4.293 9.707a1 1 0 0 1 0-1.414z"/>
      </svg>
      Voltar
    </a>
  </header>

  <div class="content">

    <!-- Filtros -->
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <span class="card-title">Filtros</span>
        <?php if (!empty($itens)): ?>
          <button class="btn-print" onclick="window.print()">
            🖨️ Imprimir
          </button>
        <?php endif; ?>
      </div>
      <form method="get" action="">
        <div class="filtro-row">
          <div class="field">
            <div class="field-label">Loja</div>
            <select class="select" name="loja" id="sel-loja" onchange="this.form.submit()">
              <?php foreach ($lojas as $lj): ?>
                <option value="<?= htmlspecialchars($lj['codigo']) ?>"
                  <?= $lj['codigo'] === $codSel ? 'selected' : '' ?>>
                  <?= htmlspecialchars($lj['nome_empresa'] . ' ' . $lj['nome_revenda']) ?>
                  (<?= htmlspecialchars($lj['codigo']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <div class="field-label">Inventário</div>
            <select class="select" name="inv">
              <?php foreach ($inventarios as $inv): ?>
                <option value="<?= (int)$inv['id'] ?>"
                  <?= (int)$inv['id'] === $invSel ? 'selected' : '' ?>>
                  #<?= (int)$inv['id'] ?> — <?= htmlspecialchars($inv['dt']) ?>
                  <?= $inv['status'] !== 'aberto' ? '(' . htmlspecialchars($inv['status']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn-filtrar" type="submit">Filtrar</button>
        </div>
      </form>
    </div>

    <?php if ($invSel && $empSel): ?>

      <!-- Stats -->
      <div class="stats-row">
        <div class="stat-box">
          <div class="stat-num"><?= count($itens) ?></div>
          <div class="stat-lbl">Itens Descartados</div>
        </div>
        <div class="stat-box">
          <div class="stat-num" style="font-size:18px;"><?= fmtBr($totalCusto) ?></div>
          <div class="stat-lbl">Valor Total (Custo)</div>
        </div>
        <div class="stat-box">
          <div class="stat-num"><?= $totalQtd ?></div>
          <div class="stat-lbl">Qtd. Total</div>
        </div>
      </div>

      <!-- Tabela -->
      <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <span class="card-title">
            Itens Descartados — Inventário #<?= $invSel ?>
            <?php if ($nomeLoja): ?> · <?= htmlspecialchars($nomeLoja) ?><?php endif; ?>
          </span>
          <span style="font-size:11px;color:var(--gray-400);"><?= count($itens) ?> item(s)</span>
        </div>

        <?php if (empty($itens)): ?>
          <div class="empty-state">
            <div class="empty-icon">🗑️</div>
            Nenhum item descartado neste inventário.<br>
            <small>Itens desconsiderados na Tomada de Decisão aparecem aqui.</small>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Código</th>
                  <th>Descrição</th>
                  <th>Locação</th>
                  <th>ABC</th>
                  <th>Saldo Oracle</th>
                  <th>Cont. 1</th>
                  <th>Custo Unit.</th>
                  <th>Valor Total</th>
                  <th>Observação</th>
                  <th>Usuário</th>
                  <th>Data</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($itens as $idx => $it): ?>
                <tr>
                  <td style="color:var(--gray-400);font-size:11px;"><?= $idx + 1 ?></td>
                  <td><strong><?= htmlspecialchars($it['item_estoque_pub']) ?></strong></td>
                  <td style="max-width:220px;">
                    <span title="<?= htmlspecialchars($it['descricao_item_estoque'] ?? '') ?>">
                      <?= htmlspecialchars(mb_strimwidth($it['descricao_item_estoque'] ?? '—', 0, 45, '…')) ?>
                    </span>
                  </td>
                  <td><?= htmlspecialchars($it['locacao_geral'] ?? '—') ?></td>
                  <td>
                    <span class="badge-abc <?= htmlspecialchars($it['class_abc'] ?? '') ?>">
                      <?= htmlspecialchars($it['class_abc'] ?? '—') ?>
                    </span>
                  </td>
                  <td><?= (int)$it['saldo_oracle'] ?></td>
                  <td><?= (int)$it['cont_1'] ?></td>
                  <td><?= fmtBr((float)$it['custo_entrada']) ?></td>
                  <td style="font-weight:600;color:var(--red);"><?= fmtBr((float)$it['valor_total']) ?></td>
                  <td style="color:var(--gray-600);font-size:12px;"><?= htmlspecialchars($it['observacao'] ?? '—') ?></td>
                  <td style="font-size:12px;color:var(--gray-600);"><?= htmlspecialchars($it['usuario'] ?? '—') ?></td>
                  <td style="font-size:11px;white-space:nowrap;color:var(--gray-400);">
                    <?= $it['criado_em'] ? date('d/m/Y H:i', strtotime($it['criado_em'])) : '—' ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="7" style="text-align:right;">Total</td>
                  <td></td>
                  <td style="color:var(--red);"><?= fmtBr($totalCusto) ?></td>
                  <td colspan="3"></td>
                </tr>
              </tfoot>
            </table>
          </div>
        <?php endif; ?>
      </div>

    <?php else: ?>
      <div class="card">
        <div class="empty-state">
          <div class="empty-icon">📋</div>
          Selecione uma loja e um inventário para ver o relatório.
        </div>
      </div>
    <?php endif; ?>

  </div><!-- /.content -->
</div><!-- /.app -->
</body>
</html>
