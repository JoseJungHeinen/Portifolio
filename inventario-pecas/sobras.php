<?php
declare(strict_types=1);
session_start();
require 'conexao_inventario.php';
require 'conexao_oracle.php';
require_once 'contagem_2_lib.php'; // resolução do código bipado (sufixo, zeros do leitor)


if (empty($_SESSION['inventario_login'])) {
    header('Location: index.php');
    exit;
}

function fetchAllPrepared(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ------------------------
// Contexto vindo da URL
// ------------------------

// Novo formato: empresa, revenda, inv, item, locacao
$empresaSel = isset($_GET['empresa']) && $_GET['empresa'] !== '' ? (int)$_GET['empresa'] : null;
$revendaSel = isset($_GET['revenda']) && $_GET['revenda'] !== '' ? (int)$_GET['revenda'] : null;
$invSel     = isset($_GET['inv']) && $_GET['inv'] !== '' ? (int)$_GET['inv'] : null;

// Item/locação opcionais, caso venham do botão "Sobras" da tela de ajuste
$itemSel    = isset($_GET['item']) && $_GET['item'] !== '' ? trim($_GET['item']) : '';
$locSel     = isset($_GET['locacao']) && $_GET['locacao'] !== '' ? trim($_GET['locacao']) : '';

// Compatibilidade com formato antigo: codigo=1.2
$codSel = $_GET['codigo'] ?? '';

// Se não veio empresa/revenda individual, tenta derivar de codigo
if (($empresaSel === null || $revendaSel === null) && $codSel !== '' && strpos($codSel, '.') !== false) {
    [$emp, $rev] = array_map('intval', explode('.', $codSel));
    $empresaSel  = $emp;
    $revendaSel  = $rev;
}

// Se não há codigo, mas temos empresa/revenda, monta para manter o select em sincronia
if ($codSel === '' && $empresaSel !== null && $revendaSel !== null) {
    $codSel = $empresaSel . '.' . $revendaSel;
}

// ------------------------
// Lojas com inventário
// ------------------------
$codigosLoja = fetchAllPrepared($pdo, "
    SELECT DISTINCT l.codigo, l.empresa AS nome_empresa, l.revenda AS nome_revenda
      FROM inv_inventarios i
     INNER JOIN inv_lojas l
        ON l.ativo = 1
       AND CAST(SUBSTRING_INDEX(l.codigo,'.',1) AS UNSIGNED) = i.empresa
       AND CAST(SUBSTRING_INDEX(l.codigo,'.',-1) AS UNSIGNED) = i.revenda
     ORDER BY l.codigo
");

// Inventários da loja
$inventarios = [];
if ($empresaSel !== null && $revendaSel !== null) {
    $inventarios = fetchAllPrepared($pdo, "
        SELECT id, data_inicio FROM inv_inventarios
         WHERE empresa = :e AND revenda = :r
         ORDER BY data_inicio DESC
    ", [':e' => $empresaSel, ':r' => $revendaSel]);
}

$itensSobra = [];
$totQtd = $totValor = 0;

if ($empresaSel !== null && $revendaSel !== null && $invSel !== null) {
    $itensSobra = fetchAllPrepared($pdo, "
        SELECT item_estoque_pub,
               descricao_item_estoque,
               locacao_geral,
               quantidade              AS qtd_estoque,
               cont_1                  AS qtd_inv,
               (cont_1 - quantidade)   AS dif,
               custo_entrada           AS valor_uni,
               class_abc,
               (cont_1 - quantidade) * custo_entrada AS valor_total
          FROM estoque
         WHERE id_inventario = :inv
           AND empresa       = :e
           AND revenda       = :r
           AND cont_1 IS NOT NULL
           AND cont_1 > quantidade
         ORDER BY (cont_1 - quantidade) * custo_entrada DESC
    ", [':inv' => $invSel, ':e' => $empresaSel, ':r' => $revendaSel]);

    if (!empty($itensSobra)) {
        $ora = oracle_conectar();
        if ($ora) {
            foreach ($itensSobra as &$it) {
                $dadosOracle = oracle_buscar_item($ora, $empresaSel, $revendaSel, $it['item_estoque_pub']);
                if ($dadosOracle) {
                    $it['qtd_estoque'] = $dadosOracle['quantidade'];
                    $it['valor_uni']   = $dadosOracle['custo_entrada'];
                    $it['class_abc']   = $dadosOracle['class_abc'];
                    $it['dif']         = $it['qtd_inv'] - $it['qtd_estoque'];
                    $it['valor_total'] = $it['dif'] * $it['valor_uni'];
                }
            }
            unset($it);
            oci_close($ora);
        }
    }

    foreach ($itensSobra as $row) {
        $totQtd   += max(0, (int)$row['dif']);
        $totValor += max(0, (float)$row['valor_total']);
    }
}

// -------------------------------------------------------
// AJAX: consultar item bipado
// -------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'consultar_item') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $ajaxItem = trim($_GET['item']    ?? '');
    $ajaxEmp  = (int)($_GET['empresa'] ?? 0);
    $ajaxRev  = (int)($_GET['revenda'] ?? 0);
    $ajaxInv  = (int)($_GET['inv']     ?? 0);

    if ($ajaxItem === '' || $ajaxEmp <= 0 || $ajaxRev <= 0 || $ajaxInv <= 0) {
        echo json_encode(['ok' => false, 'mensagem' => 'Dados inválidos.']);
        exit;
    }

    // O bipe vem como o leitor leu: sufixo de variante (-01, 001), zeros na
    // frente, regra do K. Qual item ele é se decide contra o inventário, como
    // na 1ª contagem — a tela cortava o sufixo às cegas e não tirava os zeros.
    $ajaxItem = contagem2_resolver_codigo($pdo, $ajaxInv, $ajaxItem);

    try {
        $stmt = $pdo->prepare("
            SELECT item_estoque_pub,
                   descricao_item_estoque,
                   locacao_geral,
                   quantidade    AS qtd_estoque,
                   cont_1        AS qtd_inv,
                   custo_entrada AS valor_uni,
                   class_abc
              FROM estoque
             WHERE item_estoque_pub = :item
               AND id_inventario    = :inv
               AND empresa          = :e
               AND revenda          = :r
             ORDER BY locacao_geral
        ");
        $stmt->execute([':item' => $ajaxItem, ':inv' => $ajaxInv, ':e' => $ajaxEmp, ':r' => $ajaxRev]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            echo json_encode(['ok' => false, 'mensagem' => 'Item não encontrado neste inventário.']);
            exit;
        }

        // Enriquece com Oracle
        $ora = oracle_conectar();
        if ($ora) {
            $dadosOracle = oracle_buscar_item($ora, $ajaxEmp, $ajaxRev, $ajaxItem);
            oci_close($ora);
            if ($dadosOracle) {
                foreach ($rows as &$r) {
                    $r['qtd_estoque'] = $dadosOracle['quantidade'];
                    $r['valor_uni']   = $dadosOracle['custo_entrada'];
                    $r['class_abc']   = $dadosOracle['class_abc'];
                }
                unset($r);
            }
        }

        echo json_encode(['ok' => true, 'item' => $ajaxItem, 'locacoes' => $rows]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => 'Erro: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------
// AJAX: gravar sobra
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gravar_sobra'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $gItem    = trim($_POST['item']        ?? '');
    $gLoc     = trim($_POST['locacao']     ?? '');
    $gQtd     = (int)($_POST['quantidade'] ?? 0);
    $gInv     = (int)($_POST['inv']        ?? 0);
    $gEmp     = (int)($_POST['empresa']    ?? 0);
    $gRev     = (int)($_POST['revenda']    ?? 0);

    if ($gItem === '' || $gLoc === '' || $gQtd <= 0 || $gInv <= 0) {
        echo json_encode(['ok' => false, 'mensagem' => 'Dados inválidos.']);
        exit;
    }

    // O item já vem resolvido da consulta; resolver de novo é idempotente e
    // protege quem chamar direto com o bipe cru.
    $gItem = contagem2_resolver_codigo($pdo, $gInv, $gItem, $gLoc);

    // Terceira porta para a cont_1: esta tela grava direto, sem passar pelo
    // contagem_gravar.php. A recontagem anda por item, não pelo inventário
    // inteiro — esta porta só grava em linha que ainda está na 1ª contagem;
    // sem esta trava dava para reabrir uma linha já mandada para a recontagem.
    require_once 'recontagem_lib.php';

    // A recontagem anda por item: esta porta da cont_1 só grava na 1ª.
    if (recontagem_item_encerrado($pdo, $gInv, $gItem, $gLoc)) {
        echo json_encode(['ok' => false, 'mensagem' => 'Este item já foi encerrado e está em decisão.']);
        exit;
    }

    $rodadaItem = recontagem_rodada_do_item($pdo, $gInv, $gItem, $gLoc);

    if ($rodadaItem !== null && $rodadaItem > 1) {
        echo json_encode(['ok' => false, 'mensagem' => 'Este item já está na ' . $rodadaItem . 'ª contagem.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE estoque
               SET cont_1   = COALESCE(cont_1, 0) + :qtd,
                   situacao = 'C'
             WHERE item_estoque_pub = :item
               AND locacao_geral    = :loc
               AND id_inventario    = :inv
               AND empresa          = :e
               AND revenda          = :r
               AND rodada_item      = 1
               AND recontagem_encerrada_em IS NULL
             LIMIT 1
        ");
        $stmt->execute([
            ':qtd'  => $gQtd,
            ':item' => $gItem,
            ':loc'  => $gLoc,
            ':inv'  => $gInv,
            ':e'    => $gEmp,
            ':r'    => $gRev,
        ]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['ok' => true, 'mensagem' => 'Sobra registrada com sucesso!']);
        } else {
            echo json_encode(['ok' => false, 'mensagem' => 'Nenhuma linha atualizada.']);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => 'Erro: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Lista de Sobras</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --red:#2347C8; --green:#16a34a;
      --bg:#f3f4f6; --white:#fff;
      --gray-100:#f9fafb; --gray-200:#e5e7eb;
      --gray-400:#9ca3af; --gray-600:#4b5563; --gray-800:#111827;
      --shadow:0 2px 8px rgba(15,23,42,.07); --radius:10px;
    }
    * { box-sizing:border-box; }
    body { margin:0; font-family:system-ui,-apple-system,sans-serif; background:var(--bg); color:var(--gray-800); }
    header { background:#fff; border-bottom:1px solid var(--gray-200); padding:10px 20px;
             display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:20;
             box-shadow:0 1px 4px rgba(15,23,42,.04); }
    header h1 { margin:0; font-size:16px; font-weight:700; }
    .btn-back { font-size:13px; color:var(--gray-600); text-decoration:none; padding:5px 12px;
                border:1px solid var(--gray-200); border-radius:999px; background:#fff; }
    .btn-back:hover { border-color:var(--red); color:var(--red); }
    .btn-export { font-size:13px; font-weight:600; color:#fff; padding:5px 14px;
                  border:none; border-radius:999px; background:linear-gradient(135deg,var(--red),#FF1744);
                  cursor:pointer; text-decoration:none; }
    main { padding:16px 20px 32px; max-width:1200px; margin:0 auto; display:flex; flex-direction:column; gap:14px; }
    .card { background:#fff; border-radius:var(--radius); padding:14px 16px; box-shadow:var(--shadow); border:1px solid rgba(15,23,42,.04); }
    .card-title { font-size:13px; font-weight:700; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
    .card-title::before { content:""; width:3px; height:14px; border-radius:999px; background:var(--red); }
    .filters { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
    .filters select, .filters button {
      height:34px; border-radius:999px; border:1px solid var(--gray-200);
      padding:0 12px; font-size:13px; background:var(--gray-100);
    }
    .filters button { background:linear-gradient(135deg,var(--red),#FF1744); color:#fff; border:none; cursor:pointer; font-weight:600; }
    .totais { display:flex; gap:12px; flex-wrap:wrap; }
    .tot-card { flex:1; min-width:160px; padding:12px 16px; border-radius:var(--radius);
                background:var(--gray-100); border:1px solid var(--gray-200); }
    .tot-label { font-size:11px; font-weight:600; color:var(--gray-600); text-transform:uppercase; letter-spacing:.08em; }
    .tot-value { font-size:22px; font-weight:700; color:var(--gray-800); margin-top:4px; }
    .tot-value.red { color:var(--red); }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    thead tr { background:var(--gray-100); }
    th,td { border-bottom:1px solid var(--gray-200); padding:8px 6px; text-align:left; }
    th { font-weight:600; color:var(--gray-600); font-size:12px; }
    tbody tr:hover { background:#FFF3F4; }
    .badge { display:inline-block; padding:2px 7px; border-radius:999px; font-size:11px; font-weight:700; }
    .badge-a { background:#dcfce7; color:#166534; }
    .badge-b { background:#dbeafe; color:#1e40af; }
    .badge-c { background:#fef9c3; color:#854d0e; }
    .dif-pos { color:#16a34a; font-weight:700; }

    /* Painel coletor */
    .coletor-panel { background:#fff; border-radius:var(--radius); padding:14px 16px; box-shadow:var(--shadow); border:1px solid rgba(15,23,42,.04); }
    .coletor-input-wrap { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .coletor-input { height:38px; border-radius:999px; border:1px solid var(--gray-200);
                     padding:0 14px; font-size:14px; width:220px; background:var(--gray-100); }
    .coletor-input:focus { outline:none; border-color:var(--red); box-shadow:0 0 0 2px rgba(35,71,200,.15); background:#fff; }
    .btn-bipar { height:38px; padding:0 18px; border-radius:999px; border:none;
                 background:linear-gradient(135deg,var(--red),#FF1744);
                 color:#fff; font-weight:600; cursor:pointer; font-size:13px; }
    .coletor-status { font-size:12px; color:var(--gray-400); }

    /* Modal locações */
    .overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:50; align-items:center; justify-content:center; }
    .overlay.open { display:flex; }
    .modal { background:#fff; border-radius:14px; padding:22px 24px; width:100%; max-width:460px;
             box-shadow:0 20px 40px rgba(0,0,0,.25); max-height:90vh; overflow-y:auto; }
    .modal h2 { margin:0 0 4px; font-size:16px; }
    .modal-sub { font-size:12px; color:var(--gray-400); margin-bottom:14px; }
    .loc-item { border:1px solid var(--gray-200); border-radius:10px; padding:12px 14px; margin-bottom:8px;
                display:flex; justify-content:space-between; align-items:center; gap:8px; }
    .loc-item:last-child { margin-bottom:0; }
    .loc-info { display:flex; flex-direction:column; gap:3px; }
    .loc-name { font-weight:700; font-size:14px; }
    .loc-detail { font-size:12px; color:var(--gray-600); }
    .btn-add-sobra { height:34px; padding:0 14px; border-radius:8px; border:none;
                     background:linear-gradient(135deg,#16a34a,#22c55e);
                     color:#fff; font-weight:600; cursor:pointer; font-size:13px; white-space:nowrap; }
    .btn-add-sobra:hover { filter:brightness(1.05); }
    .modal-actions { display:flex; justify-content:flex-end; margin-top:14px; }
    .btn-cancel { padding:8px 18px; border-radius:8px; border:1px solid var(--gray-200); background:#fff; cursor:pointer; font-size:13px; }

    /* Modal quantidade */
    .modal-qty label { display:block; font-size:12px; font-weight:600; color:var(--gray-600); margin-bottom:4px; margin-top:10px; }
    .modal-qty input { width:100%; height:38px; border-radius:8px; border:1px solid var(--gray-200); padding:0 10px; font-size:14px; }
    .modal-qty input:focus { outline:none; border-color:var(--red); box-shadow:0 0 0 2px rgba(35,71,200,.15); }
    .btn-save { padding:8px 18px; border-radius:8px; border:none;
                background:linear-gradient(135deg,var(--red),#FF1744); color:#fff; font-weight:700; cursor:pointer; }
    .msg-ok  { padding:8px 12px; border-radius:8px; background:#e8f5e9; color:#166534; border:1px solid #bbf7d0; font-size:13px; }
    .msg-err { padding:8px 12px; border-radius:8px; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; font-size:13px; }

    @media print {
      header, .filters, .coletor-panel { display:none; }
      .card { box-shadow:none; border:none; }
    }
  </style>
</head>
<body>
<header>
  <h1>Lista de Sobras</h1>
  <div style="display:flex; gap:8px; align-items:center;">
    <?php if (!empty($itensSobra)): ?>
      <button class="btn-export" onclick="window.print()">Imprimir</button>
    <?php endif; ?>
    <a href="dashboard_inventario.php?codigo=<?php echo urlencode($codSel); ?>&inv=<?php echo (int)$invSel; ?>" class="btn-back">← Dashboard</a>
  </div>
</header>

<main>
  <!-- Filtros -->
  <div class="card">
    <div class="card-title">Selecionar inventário</div>
    <form method="get" class="filters">
      <select name="codigo" onchange="this.form.submit()">
        <option value="">Loja...</option>
        <?php foreach ($codigosLoja as $row):
          $c   = $row['codigo'];
          $txt = $row['nome_empresa'] . ' ' . $row['nome_revenda'];
        ?>
          <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $codSel === $c ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($txt); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="inv" onchange="this.form.submit()">
        <option value="">Inventário...</option>
        <?php foreach ($inventarios as $inv): ?>
          <option value="<?php echo (int)$inv['id']; ?>" <?php echo $invSel == $inv['id'] ? 'selected' : ''; ?>>
            #<?php echo (int)$inv['id']; ?> - <?php echo htmlspecialchars($inv['data_inicio']); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Carregar</button>
    </form>
  </div>

  <?php if ($empresaSel !== null && $invSel !== null): ?>
  <!-- Painel Coletor: bipar item -->
  <div class="coletor-panel">
    <div class="card-title">Registrar sobra via coletor</div>
    <div class="coletor-input-wrap">
      <input type="text" id="input-bipar" class="coletor-input"
             placeholder="Bipe ou digite o item..."
             onkeydown="if(event.key==='Enter') consultarItem()">
      <button class="btn-bipar" onclick="consultarItem()">Consultar</button>
      <span id="bipar-status" class="coletor-status"></span>
    </div>
  </div>
  <?php endif; ?>

  <div id="msg-global" style="display:none;"></div>

  <?php if ($invSel !== null && empty($itensSobra)): ?>
    <div class="card" style="color:var(--gray-600); font-size:13px;">Nenhuma sobra encontrada para este inventário.</div>
  <?php endif; ?>

  <?php if (!empty($itensSobra)): ?>
  <!-- Totais -->
  <div class="totais">
    <div class="tot-card">
      <div class="tot-label">Itens com sobra</div>
      <div class="tot-value"><?php echo count($itensSobra); ?></div>
    </div>
    <div class="tot-card">
      <div class="tot-label">Peças em sobra</div>
      <div class="tot-value red"><?php echo number_format($totQtd, 0, ',', '.'); ?></div>
    </div>
    <div class="tot-card">
      <div class="tot-label">Valor total sobra</div>
      <div class="tot-value red">R$ <?php echo number_format($totValor, 2, ',', '.'); ?></div>
    </div>
  </div>

  <!-- Tabela -->
  <div class="card">
    <div class="card-title">Itens em sobra (<?php echo count($itensSobra); ?> itens)</div>
    <div style="overflow:auto;">
      <table id="tabela-sobras">
        <thead>
          <tr>
            <th>Referência</th>
            <th>Descrição</th>
            <th>Locação</th>
            <th style="text-align:right;">Qtd Estoque</th>
            <th style="text-align:right;">Qtd Inventário</th>
            <th style="text-align:right;">Diferença</th>
            <th style="text-align:right;">Valor Unit.</th>
            <th style="text-align:right;">Valor Total</th>
            <th>ABC</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($itensSobra as $it):
            $abc = strtoupper($it['class_abc'] ?? '');
            $cls = $abc === 'A' ? 'badge-a' : ($abc === 'B' ? 'badge-b' : 'badge-c');
          ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($it['item_estoque_pub']); ?></strong></td>
            <td><?php echo htmlspecialchars($it['descricao_item_estoque']); ?></td>
            <td><?php echo htmlspecialchars($it['locacao_geral']); ?></td>
            <td style="text-align:right;"><?php echo (int)$it['qtd_estoque']; ?></td>
            <td style="text-align:right;"><?php echo (int)$it['qtd_inv']; ?></td>
            <td style="text-align:right;" class="dif-pos">+<?php echo (int)$it['dif']; ?></td>
            <td style="text-align:right;">R$ <?php echo number_format((float)$it['valor_uni'], 2, ',', '.'); ?></td>
            <td style="text-align:right;">R$ <?php echo number_format((float)$it['valor_total'], 2, ',', '.'); ?></td>
            <td><span class="badge <?php echo $cls; ?>"><?php echo htmlspecialchars($abc ?: '-'); ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</main>

<!-- Modal: locações do item bipado -->
<div class="overlay" id="overlay-locacoes">
  <div class="modal">
    <h2 id="modal-item-ref">Item</h2>
    <div class="modal-sub" id="modal-item-desc"></div>
    <div id="modal-locacoes-lista"></div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="fecharModalLocacoes()">Fechar</button>
    </div>
  </div>
</div>

<!-- Modal: informar quantidade de sobra -->
<div class="overlay" id="overlay-qty">
  <div class="modal modal-qty" style="max-width:340px;">
    <h2>Adicionar Sobra</h2>
    <div class="modal-sub" id="qty-info"></div>
    <label>Quantidade a adicionar</label>
    <input type="number" id="qty-input" min="1" max="99999" value="1">
    <div id="qty-erro" style="display:none; margin-top:8px;" class="msg-err"></div>
    <div class="modal-actions" style="gap:8px;">
      <button class="btn-cancel" onclick="fecharModalQty()">Cancelar</button>
      <button class="btn-save" onclick="confirmarSobra()">Confirmar</button>
    </div>
  </div>
</div>

<script>
const CTX_EMP = <?php echo (int)($empresaSel ?? 0); ?>;
const CTX_REV = <?php echo (int)($revendaSel ?? 0); ?>;
const CTX_INV = <?php echo (int)($invSel     ?? 0); ?>;

let sobraAtual = null; // { item, locacao, descricao }

// Só limpa: o sufixo de variante, os zeros que o leitor põe na frente e a regra
// do K se resolvem no servidor (consultar_item), contra os itens do inventário.
// O código que vale é o que ele devolve em data.item.
function normalizarItem(bruto) {
  return (bruto || '').replace(/[\x00-\x1F\x7F-\x9F]/g, '').replace(/\s+/g, '').toUpperCase();
}

async function consultarItem() {
  const raw    = document.getElementById('input-bipar').value.trim();
  const item   = normalizarItem(raw);
  const status = document.getElementById('bipar-status');

  if (!item) { status.textContent = 'Digite ou bipe um item.'; return; }
  if (CTX_INV <= 0) { status.textContent = 'Selecione um inventário primeiro.'; return; }

  status.textContent = 'Consultando...';

  const url = `?ajax=consultar_item&item=${encodeURIComponent(item)}&empresa=${CTX_EMP}&revenda=${CTX_REV}&inv=${CTX_INV}`;

  try {
    const res  = await fetch(url);
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch(e) {
      console.error('Resposta inválida:', text);
      status.textContent = 'Erro interno do servidor.';
      return;
    }

    status.textContent = '';

    if (!data.ok) {
      status.textContent = '⚠ ' + (data.mensagem || 'Item não encontrado.');
      return;
    }

    abrirModalLocacoes(data.item || item, data.locacoes);

  } catch(err) {
    status.textContent = 'Falha na comunicação.';
    console.error(err);
  }
}

function abrirModalLocacoes(item, locacoes) {
  document.getElementById('modal-item-ref').textContent  = item;
  document.getElementById('modal-item-desc').textContent = locacoes[0]?.descricao_item_estoque || '';

  const lista = document.getElementById('modal-locacoes-lista');
  lista.innerHTML = '';

  locacoes.forEach(loc => {
    const abc    = (loc.class_abc || '').toUpperCase();
    const abcCls = abc === 'A' ? 'badge-a' : (abc === 'B' ? 'badge-b' : 'badge-c');
    const dif    = (loc.qtd_inv || 0) - (loc.qtd_estoque || 0);

    const div = document.createElement('div');
    div.className = 'loc-item';
    div.innerHTML = `
      <div class="loc-info">
        <span class="loc-name">📍 ${loc.locacao_geral}</span>
        <span class="loc-detail">
          Estoque: <strong>${loc.qtd_estoque}</strong> &nbsp;|&nbsp;
          Contado: <strong>${loc.qtd_inv ?? '-'}</strong> &nbsp;|&nbsp;
          Sobra atual: <strong style="color:${dif > 0 ? '#16a34a' : '#6b7280'}">
            ${dif > 0 ? '+' + dif : dif}
          </strong> &nbsp;|&nbsp;
          Custo: <strong>R$ ${parseFloat(loc.valor_uni || 0).toFixed(2).replace('.', ',')}</strong> &nbsp;|&nbsp;
          <span class="badge ${abcCls}">${abc || '-'}</span>
        </span>
      </div>
      <button class="btn-add-sobra" onclick="pedirQtd('${item}', '${loc.locacao_geral}', '${(loc.descricao_item_estoque||'').replace(/'/g,"\\'")}')">
        + Sobra
      </button>
    `;
    lista.appendChild(div);
  });

  document.getElementById('overlay-locacoes').classList.add('open');
  document.getElementById('input-bipar').value = '';
}

function fecharModalLocacoes() {
  document.getElementById('overlay-locacoes').classList.remove('open');
}

function pedirQtd(item, locacao, descricao) {
  sobraAtual = { item, locacao, descricao };
  document.getElementById('qty-info').textContent = `${item} — ${descricao || ''} | Locação: ${locacao}`;
  document.getElementById('qty-input').value = '1';
  document.getElementById('qty-erro').style.display = 'none';
  fecharModalLocacoes();
  document.getElementById('overlay-qty').classList.add('open');
  setTimeout(() => document.getElementById('qty-input').focus(), 50);
}

function fecharModalQty() {
  document.getElementById('overlay-qty').classList.remove('open');
  sobraAtual = null;
}

async function confirmarSobra() {
  if (!sobraAtual) return;

  const qtd  = parseInt(document.getElementById('qty-input').value) || 0;
  const erro = document.getElementById('qty-erro');

  if (qtd <= 0) {
    erro.textContent = 'Informe uma quantidade válida.';
    erro.style.display = 'block';
    return;
  }

  const body = new URLSearchParams({
    gravar_sobra: '1',
    item:         sobraAtual.item,
    locacao:      sobraAtual.locacao,
    quantidade:   qtd,
    inv:          CTX_INV,
    empresa:      CTX_EMP,
    revenda:      CTX_REV,
  });

  try {
    const res  = await fetch('', { method: 'POST', body });
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch(e) {
      console.error('Resposta inválida:', text);
      erro.textContent = 'Erro interno do servidor.';
      erro.style.display = 'block';
      return;
    }

    if (data.ok) {
      fecharModalQty();
      mostrarMsgGlobal(data.mensagem, 'ok');
      setTimeout(() => location.reload(), 1500);
    } else {
      erro.textContent = data.mensagem;
      erro.style.display = 'block';
    }
  } catch(err) {
    erro.textContent = 'Falha na comunicação.';
    erro.style.display = 'block';
  }
}

function mostrarMsgGlobal(texto, tipo) {
  const el = document.getElementById('msg-global');
  el.className = tipo === 'ok' ? 'msg-ok' : 'msg-err';
  el.textContent = texto;
  el.style.display = 'block';
  setTimeout(() => { el.style.display = 'none'; }, 3000);
}

// Fecha modais ao clicar fora
document.getElementById('overlay-locacoes').addEventListener('click', function(e) {
  if (e.target === this) fecharModalLocacoes();
});
document.getElementById('overlay-qty').addEventListener('click', function(e) {
  if (e.target === this) fecharModalQty();
});
</script>
</body>
</html>
