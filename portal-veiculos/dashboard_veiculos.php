<?php
// @versao Ver.005.221026
/**
 * Dashboard — Ferramentas do Projeto Veículos
 * Empresa Exemplo
 * 
 * INSTRUÇÕES:
 * 1. Coloque este arquivo na mesma pasta do config_database.php
 * 2. Acesse pelo navegador (precisa estar logado como admin)
 */

require_once __DIR__ . '/config_database.php';
session_start();

if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    exit('Acesso negado — somente administradores.');
}

// ═══════════════════════════════════════
// CONSULTAS AO BANCO DE DADOS
// ═══════════════════════════════════════
$ferramentas = [];

$queries = [
    'Transferência' => [
        'tabela'   => 'transferencia',
        'usuario'  => 'u.cpf = t.cpf_usuario',
        'count'    => "SELECT COUNT(*) AS total FROM transferencia",
        'valor'    => "SELECT IFNULL(SUM(valor_total), 0) AS soma FROM transferencia",
        'mensal'   => "SELECT MONTH(criado_em) AS mes, COUNT(*) AS qtd FROM transferencia WHERE YEAR(criado_em) = YEAR(CURDATE()) GROUP BY MONTH(criado_em) ORDER BY mes",
        'cor'      => '#d81807',
        'icone'    => '🔄',
    ],
    'Emplacamento' => [
        'tabela'   => 'emplacamento',
        'usuario'  => 'u.cpf = t.cpf_usuario',
        'count'    => "SELECT COUNT(*) AS total FROM emplacamento",
        'valor'    => "SELECT IFNULL(SUM(valor), 0) AS soma FROM emplacamento",
        'mensal'   => "SELECT MONTH(criado_em) AS mes, COUNT(*) AS qtd FROM emplacamento WHERE YEAR(criado_em) = YEAR(CURDATE()) GROUP BY MONTH(criado_em) ORDER BY mes",
        'cor'      => '#388bfd',
        'icone'    => '🪧',
    ],
    'Cartão' => [
        'tabela'   => 'cartao',
        'usuario'  => 'u.cpf = t.cpf_usuario',
        'count'    => "SELECT COUNT(*) AS total FROM cartao",
        'valor'    => "SELECT IFNULL(SUM(valor_total), 0) AS soma FROM cartao",
        'mensal'   => "SELECT MONTH(criado_em) AS mes, COUNT(*) AS qtd FROM cartao WHERE YEAR(criado_em) = YEAR(CURDATE()) GROUP BY MONTH(criado_em) ORDER BY mes",
        'cor'      => '#d29922',
        'icone'    => '💳',
    ],
    'Orçamento' => [
        'tabela'   => 'orcamento',
        'usuario'  => 'u.cpf = t.cpf_usuario',
        'count'    => "SELECT COUNT(*) AS total FROM orcamento",
        'valor'    => "SELECT IFNULL(SUM(valor_total), 0) AS soma FROM orcamento",
        'mensal'   => "SELECT MONTH(criado_em) AS mes, COUNT(*) AS qtd FROM orcamento WHERE YEAR(criado_em) = YEAR(CURDATE()) GROUP BY MONTH(criado_em) ORDER BY mes",
        'cor'      => '#a371f7',
        'icone'    => '📋',
    ],
    'Acessórios' => [
        'tabela'   => 'orcamentos_acessorios',
        'usuario'  => 'u.id = t.user_id',
        'count'    => "SELECT COUNT(*) AS total FROM orcamentos_acessorios",
        'valor'    => "SELECT IFNULL(SUM(total), 0) AS soma FROM orcamentos_acessorios",
        'mensal'   => "SELECT MONTH(criado_em) AS mes, COUNT(*) AS qtd FROM orcamentos_acessorios WHERE YEAR(criado_em) = YEAR(CURDATE()) GROUP BY MONTH(criado_em) ORDER BY mes",
        'cor'      => '#39d2c0',
        'icone'    => '🛞',
    ],
];

$totalGeral     = 0;
$valorGeral     = 0;
$evolucaoMensal = [];

foreach ($queries as $nome => $cfg) {
    $res   = $mysqli->query($cfg['count']);
    $total = (int)($res->fetch_assoc()['total'] ?? 0);

    $res  = $mysqli->query($cfg['valor']);
    $soma = (float)($res->fetch_assoc()['soma'] ?? 0);

    $mensal = array_fill(1, 12, 0);
    $res    = $mysqli->query($cfg['mensal']);
    while ($row = $res->fetch_assoc()) {
        $mensal[(int)$row['mes']] = (int)$row['qtd'];
    }

    $ferramentas[] = [
        'nome'       => $nome,
        'tabela'     => $cfg['tabela'],
        'registros'  => $total,
        'valorTotal' => $soma,
        'cor'        => $cfg['cor'],
        'icone'      => $cfg['icone'],
    ];

    $evolucaoMensal[$nome] = array_values($mensal);
    $totalGeral += $total;
    $valorGeral += $soma;
}

$ranking = $ferramentas;
usort($ranking, function ($a, $b) { return $b['registros'] - $a['registros']; });

$ferramentasJson = json_encode($ferramentas, JSON_UNESCAPED_UNICODE);
$evolucaoJson    = json_encode($evolucaoMensal, JSON_UNESCAPED_UNICODE);

// ═══════════════════════════════════════
// USO POR USUÁRIO (filtro de período e loja)
// ═══════════════════════════════════════
$periodos = [
    'mes'  => ['label' => 'Mês atual',       'inicio' => date('Y-m-01')],
    '90d'  => ['label' => 'Últimos 90 dias', 'inicio' => date('Y-m-d', strtotime('-90 days'))],
    'ano'  => ['label' => 'Ano atual',       'inicio' => date('Y-01-01')],
    'tudo' => ['label' => 'Todo o período',  'inicio' => null],
];
$periodo = isset($periodos[$_GET['periodo'] ?? '']) ? $_GET['periodo'] : 'mes';
$visao   = ($_GET['visao'] ?? '') === 'pessoa' ? 'pessoa' : 'ferramenta';

// Lojas e setores marcados (checkbox). Sem o formulário enviado = todos marcados.
$filtroEnviado = isset($_GET['f']);
$SEM_SETOR     = '__sem__';

$whereData = $periodos[$periodo]['inicio'] ? "WHERE t.criado_em >= '{$periodos[$periodo]['inicio']}'" : '';

$lojas = [];
$res = $mysqli->query("SELECT id, nome FROM lojas ORDER BY nome");
while ($res && $row = $res->fetch_assoc()) {
    $lojas[(int)$row['id']] = $row['nome'];
}
$res = $mysqli->query("SELECT 1 FROM usuarios u LEFT JOIN lojas l ON l.id = u.id_loja WHERE u.is_ativo = 1 AND l.id IS NULL LIMIT 1");
if ($res && $res->num_rows) $lojas[0] = '(Sem loja)';

// Um usuário pode ter vários setores gravados separados por vírgula (ex.: "VENDAS,TI")
$separarSetores = function ($setor) use ($SEM_SETOR) {
    $lista = array_values(array_unique(array_filter(array_map('trim', explode(',', strtoupper((string)$setor))), 'strlen')));
    return $lista ?: [$SEM_SETOR];
};

$setores = [];
$res = $mysqli->query("SELECT DISTINCT setor FROM usuarios WHERE is_ativo = 1");
while ($res && $row = $res->fetch_assoc()) {
    foreach ($separarSetores($row['setor']) as $s) {
        $setores[$s] = $s === $SEM_SETOR ? '(Sem setor)' : $s;
    }
}
uasort($setores, function ($a, $b) { return strnatcasecmp($a, $b); });

$lojasMarcadas   = $filtroEnviado ? array_map('intval', (array)($_GET['loja'] ?? [])) : array_keys($lojas);
$setoresMarcados = $filtroEnviado ? array_map('strval', (array)($_GET['setor'] ?? [])) : array_map('strval', array_keys($setores));

$usuarios = [];
$res = $mysqli->query("
    SELECT u.id, u.nome, u.id_loja, u.setor, l.nome AS loja_nome
      FROM usuarios u
      LEFT JOIN lojas l ON l.id = u.id_loja
     WHERE u.is_ativo = 1
");
while ($res && $row = $res->fetch_assoc()) {
    $setoresUsuario = $separarSetores($row['setor']);
    $lojaUsuario  = isset($lojas[(int)$row['id_loja']]) ? (int)$row['id_loja'] : 0;
    if (!in_array($lojaUsuario, $lojasMarcadas, true)) continue;
    if (!array_intersect($setoresUsuario, $setoresMarcados)) continue; // basta um dos setores estar marcado

    $usuarios[(int)$row['id']] = [
        'nome'  => $row['nome'],
        'loja'  => $row['loja_nome'] ?? '—',
        'setor' => implode(', ', array_diff($setoresUsuario, [$SEM_SETOR])),
        'uso'   => array_fill_keys(array_keys($queries), 0),
        'total' => 0,
    ];
}

foreach ($queries as $nome => $cfg) {
    $res = $mysqli->query("
        SELECT u.id, COUNT(*) AS qtd
          FROM {$cfg['tabela']} t
          JOIN usuarios u ON {$cfg['usuario']}
          $whereData
         GROUP BY u.id
    ");
    while ($res && $row = $res->fetch_assoc()) {
        $id = (int)$row['id'];
        if (!isset($usuarios[$id])) continue; // inativo ou fora do filtro de loja/setor
        $usuarios[$id]['uso'][$nome] = (int)$row['qtd'];
        $usuarios[$id]['total']     += (int)$row['qtd'];
    }
}

$topUsuarios = $usuarios;
uasort($topUsuarios, function ($a, $b) { return $b['total'] - $a['total'] ?: strcmp($a['nome'], $b['nome']); });
$topUsuarios = array_slice(array_filter($topUsuarios, function ($u) { return $u['total'] > 0; }), 0, 10, true);

$menosUsuarios = $usuarios;
uasort($menosUsuarios, function ($a, $b) { return $a['total'] - $b['total'] ?: strcmp($a['nome'], $b['nome']); });
$menosUsuarios = array_slice($menosUsuarios, 0, 10, true);

$ativosTotal  = count($usuarios);
$ativosComUso = count(array_filter($usuarios, function ($u) { return $u['total'] > 0; }));
$coresFerramenta = array_map(function ($c) { return $c['cor']; }, $queries);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Empresa Exemplo</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root {
    --vermelho: #CC1B00;
    --vermelho-escuro: #8B0000;
    --vermelho-claro: #E85D50;
    --preto: #1A1A1A;
    --cinza-escuro: #2B2B2B;
    --cinza-medio: #4A4A4A;
    --cinza: #777;
    --cinza-claro: #F2F2F2;
    --branco: #FFFFFF;
    --bg: #F5F3F0;
    --surface: #FFFFFF;
    --border: #E5E2DD;
    --text: #1A1A1A;
    --text-muted: #888;
    --radius: 14px;
    --shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 12px rgba(0,0,0,0.04);
    --shadow-hover: 0 2px 8px rgba(0,0,0,0.08), 0 8px 24px rgba(0,0,0,0.06);
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Plus Jakarta Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
  }

  /* ── HEADER ── */
  .header {
    background: linear-gradient(135deg, var(--preto) 0%, #2a0a06 50%, var(--vermelho-escuro) 100%);
    padding: 28px 48px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: relative;
    overflow: hidden;
  }
  .header::after {
    content: '';
    position: absolute;
    top: -80px; right: -40px;
    width: 350px; height: 350px;
    background: radial-gradient(circle, rgba(204,27,0,0.15) 0%, transparent 70%);
    pointer-events: none;
  }
  .header-left { display: flex; align-items: center; gap: 16px; position: relative; z-index: 1; }
  .logo-img {
    height: 44px; width: auto; object-fit: contain;
    border-radius: 8px;
  }
  .header h1 { color: #fff; font-size: 1.35rem; font-weight: 700; letter-spacing: -0.3px; }
  .header h1 span { color: var(--vermelho-claro); }
  .header-right { position: relative; z-index: 1; }
  .header-right p { color: rgba(255,255,255,0.55); font-size: 0.78rem; text-align: right; }
  .header-right .date { color: rgba(255,255,255,0.8); font-weight: 600; font-size: 0.82rem; }

  /* ── CONTAINER ── */
  .container { max-width: 1320px; margin: 0 auto; padding: 28px 48px 60px; }

  /* ── SECTION LABELS ── */
  .section-label {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 16px; margin-top: 8px;
  }
  .section-index {
    width: 28px; height: 28px;
    background: var(--vermelho);
    color: #fff;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.82rem;
    flex-shrink: 0;
  }
  .section-title { font-size: 1rem; font-weight: 700; color: var(--preto); }

  /* ── KPI CARDS — SINGLE ROW ── */
  .kpi-row {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 14px;
    margin-bottom: 32px;
  }
  .kpi-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px 16px;
    box-shadow: var(--shadow);
    transition: transform 0.2s, box-shadow 0.2s;
    position: relative;
    overflow: hidden;
  }
  .kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-hover);
  }
  .kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
  }
  .kpi-card .kpi-icon {
    font-size: 1.3rem;
    margin-bottom: 10px;
  }
  .kpi-card .kpi-value {
    font-size: 1.55rem;
    font-weight: 800;
    letter-spacing: -0.5px;
    color: var(--preto);
    line-height: 1;
  }
  .kpi-card .kpi-label {
    font-size: 0.72rem;
    color: var(--cinza);
    margin-top: 5px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.4px;
  }

  /* ── TABLE ── */
  .table-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
    margin-bottom: 32px;
  }
  table { width: 100%; border-collapse: collapse; }
  th {
    text-align: left;
    padding: 12px 20px;
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--cinza);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    background: var(--cinza-claro);
    border-bottom: 2px solid var(--border);
  }
  td {
    padding: 16px 20px;
    font-size: 0.85rem;
    border-bottom: 1px solid var(--border);
    color: var(--text);
  }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: #fdf5f4; }
  .tool-name { display: flex; align-items: center; gap: 12px; font-weight: 600; }
  .tool-dot { width: 12px; height: 12px; border-radius: 4px; flex-shrink: 0; }
  .bar-cell { display: flex; align-items: center; gap: 12px; }
  .bar-bg { flex: 1; height: 10px; background: var(--cinza-claro); border-radius: 999px; overflow: hidden; }
  .bar-fill { height: 100%; border-radius: 999px; }
  .bar-pct { font-weight: 700; font-size: 0.82rem; min-width: 48px; text-align: right; color: var(--preto); }
  .valor-col { font-weight: 600; font-size: 0.84rem; white-space: nowrap; }
  .tabela-tag {
    font-size: 0.72rem; font-weight: 600;
    background: var(--cinza-claro); padding: 4px 10px;
    border-radius: 6px; color: var(--cinza-medio);
    font-family: monospace;
  }
  td.idx-col {
    font-weight: 800; color: var(--vermelho);
    font-size: 0.9rem; width: 50px; text-align: center;
  }

  /* ── CHARTS ── */
  .charts-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 32px;
  }
  .chart-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 24px;
    box-shadow: var(--shadow);
  }
  .chart-card.full { grid-column: 1 / -1; }
  .chart-title {
    font-size: 0.92rem; font-weight: 700;
    color: var(--preto); margin-bottom: 2px;
  }
  .chart-subtitle {
    font-size: 0.74rem; color: var(--cinza);
    margin-bottom: 20px;
  }

  /* ── USO POR PESSOA ── */
  .section-label { flex-wrap: wrap; }
  .visao-toggle {
    display: inline-flex; margin-left: auto;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 10px; padding: 3px;
  }
  .visao-toggle button {
    font-family: inherit; font-size: 0.78rem; font-weight: 700;
    border: 0; background: transparent; color: var(--cinza-medio);
    padding: 6px 14px; border-radius: 7px; cursor: pointer;
  }
  .visao-toggle button.ativo { background: var(--vermelho); color: #fff; }
  .filtro-form {
    display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
  }
  .filtro-form select {
    font-family: inherit; font-size: 0.8rem; font-weight: 600;
    padding: 7px 10px; border: 1px solid var(--border); border-radius: 8px;
    background: var(--surface); color: var(--text);
  }
  .filtro-check { position: relative; }
  .filtro-check summary {
    list-style: none; cursor: pointer;
    font-size: 0.8rem; font-weight: 600;
    padding: 7px 28px 7px 10px; border: 1px solid var(--border); border-radius: 8px;
    background: var(--surface); color: var(--text); white-space: nowrap;
  }
  .filtro-check summary::-webkit-details-marker { display: none; }
  .filtro-check summary::after {
    content: '▾'; position: absolute; right: 10px; top: 7px; color: var(--cinza);
  }
  .filtro-check-painel {
    position: absolute; right: 0; top: calc(100% + 6px); z-index: 20;
    width: 260px; background: var(--surface);
    border: 1px solid var(--border); border-radius: 10px; box-shadow: var(--shadow-hover);
    padding: 10px;
  }
  .filtro-check-acoes { display: flex; gap: 8px; margin-bottom: 8px; }
  .filtro-check-acoes button {
    font-family: inherit; font-size: 0.72rem; font-weight: 600;
    border: 0; background: none; color: var(--vermelho); cursor: pointer; padding: 0;
  }
  .filtro-check-lista { max-height: 260px; overflow-y: auto; }
  .filtro-check-lista label {
    display: flex; align-items: center; gap: 8px;
    font-size: 0.8rem; padding: 5px 4px; border-radius: 6px; cursor: pointer;
  }
  .filtro-check-lista label:hover { background: var(--cinza-claro); }
  .filtro-check-lista input { accent-color: var(--vermelho); }
  .filtro-check-aplicar {
    width: 100%; margin-top: 8px;
    font-family: inherit; font-size: 0.8rem; font-weight: 700;
    border: 0; border-radius: 8px; padding: 8px;
    background: var(--vermelho); color: #fff; cursor: pointer;
  }
  .adesao {
    font-size: 0.8rem; color: var(--cinza-medio); margin-bottom: 16px;
  }
  .adesao strong { color: var(--preto); }
  .pessoa-nome { font-weight: 600; }
  .pessoa-loja { font-size: 0.72rem; color: var(--cinza); margin-top: 2px; }
  .uso-bar {
    display: flex; height: 8px; border-radius: 999px; overflow: hidden;
    background: var(--cinza-claro); margin-bottom: 6px; min-width: 120px;
  }
  .uso-chips { display: flex; flex-wrap: wrap; gap: 4px; }
  .uso-chip {
    font-size: 0.68rem; font-weight: 600; white-space: nowrap;
    padding: 2px 7px; border-radius: 6px; background: var(--cinza-claro); color: var(--cinza-medio);
  }
  .uso-chip i {
    display: inline-block; width: 7px; height: 7px; border-radius: 2px;
    margin-right: 4px; vertical-align: 0;
  }
  .uso-zero { font-size: 0.75rem; color: var(--vermelho); font-weight: 600; }
  .table-card td { vertical-align: middle; }
  .ranking-pessoas .table-card { margin-bottom: 0; overflow-x: auto; }
  .ranking-pessoas td { padding: 12px 16px; }
  .ranking-pessoas th { padding: 10px 16px; }

  /* ── FOOTER ── */
  .footer {
    text-align: center; padding: 20px;
    font-size: 0.72rem; color: var(--cinza);
    border-top: 1px solid var(--border);
    margin-top: 12px;
  }
  .footer strong { color: var(--vermelho); }

  /* ── RESPONSIVE ── */
  @media (max-width: 1100px) {
    .kpi-row { grid-template-columns: repeat(3, 1fr); }
  }
  @media (max-width: 800px) {
    .header { padding: 20px 20px; flex-direction: column; gap: 12px; align-items: flex-start; }
    .container { padding: 20px; }
    .charts-row { grid-template-columns: 1fr; }
    .kpi-row { grid-template-columns: repeat(2, 1fr); }
    .header-right { text-align: left; }
    .header-right p { text-align: left; }
  }
  @media (max-width: 480px) {
    .kpi-row { grid-template-columns: 1fr 1fr; }
  }
</style>
</head>
<body>

<!-- ═══ HEADER ═══ -->
<div class="header">
  <div class="header-left">
    <img src="/Veiculos/img/logo.svg" alt="Exemplo" class="logo-img">
    <h1>Dashboard <span>Veículos</span></h1>
  </div>
  <div class="header-right">
    <p class="date"><?= date('d/m/Y') ?></p>
    <p>Levantamento de uso · buscar_servidor</p>
  </div>
</div>

<div class="container">

  <?php ob_start(); ?>
    <div class="visao-toggle">
      <button type="button" data-visao="ferramenta">Por ferramenta</button>
      <button type="button" data-visao="pessoa">Por pessoa</button>
    </div>
  <?php $visaoToggle = ob_get_clean(); ?>

  <div id="visao-ferramenta" class="visao"<?= $visao === 'ferramenta' ? '' : ' hidden' ?>>

  <!-- ═══ 1. KPI CARDS ═══ -->
  <div class="section-label">
    <div class="section-title">Totais por Ferramenta</div>
    <?= $visaoToggle ?>
  </div>
  <div class="kpi-row">
    <?php foreach ($ferramentas as $f): ?>
    <div class="kpi-card" style="--card-color:<?= $f['cor'] ?>">
      <style>.kpi-card[style*="<?= $f['cor'] ?>"]::before { background: <?= $f['cor'] ?>; }</style>
      <div class="kpi-icon"><?= $f['icone'] ?></div>
      <div class="kpi-value"><?= number_format($f['registros'], 0, ',', '.') ?></div>
      <div class="kpi-label"><?= htmlspecialchars($f['nome']) ?></div>
    </div>
    <?php endforeach; ?>
    <div class="kpi-card" style="--card-color:var(--vermelho)">
      <style>.kpi-card:last-child::before { background: var(--vermelho); }</style>
      <div class="kpi-icon">Σ</div>
      <div class="kpi-value"><?= number_format($totalGeral, 0, ',', '.') ?></div>
      <div class="kpi-label">Total geral</div>
    </div>
  </div>

  <!-- ═══ 2. DETALHAMENTO POR FERRAMENTA ═══ -->
  <div class="section-label">
    <div class="section-title">Detalhamento por Ferramenta</div>
  </div>
  <div class="table-card">
    <table>
      <thead>
        <tr>
          <th style="width:50px">#</th>
          <th>Ferramenta</th>
          <th>Registros</th>
          <th>Participação</th>
          <th>Valor Médio</th>
          <th>Valor Total</th>
          <th>Tabela</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ranking as $idx => $f):
          $pct   = $totalGeral > 0 ? ($f['registros'] / $totalGeral) * 100 : 0;
          $media = $f['registros'] > 0 ? $f['valorTotal'] / $f['registros'] : 0;
        ?>
        <tr>
          <td class="idx-col"><?= $idx + 1 ?></td>
          <td>
            <div class="tool-name">
              <div class="tool-dot" style="background:<?= $f['cor'] ?>"></div>
              <?= htmlspecialchars($f['nome']) ?>
            </div>
          </td>
          <td class="valor-col"><?= number_format($f['registros'], 0, ',', '.') ?></td>
          <td>
            <div class="bar-cell">
              <div class="bar-bg"><div class="bar-fill" style="width:<?= number_format($pct, 1) ?>%;background:<?= $f['cor'] ?>"></div></div>
              <span class="bar-pct"><?= number_format($pct, 1, ',', '.') ?>%</span>
            </div>
          </td>
          <td class="valor-col">R$ <?= number_format($media, 2, ',', '.') ?></td>
          <td class="valor-col">R$ <?= number_format($f['valorTotal'], 2, ',', '.') ?></td>
          <td><span class="tabela-tag"><?= $f['tabela'] ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- ═══ 3. DISTRIBUIÇÃO + RANKING ═══ -->
  <div class="section-label">
    <div class="section-title">Distribuição e Ranking</div>
  </div>
  <div class="charts-row">
    <div class="chart-card">
      <div class="chart-title">Distribuição por Ferramenta</div>
      <div class="chart-subtitle">Participação de cada módulo no total de simulações</div>
      <canvas id="chartDonut" height="300"></canvas>
    </div>
    <div class="chart-card">
      <div class="chart-title">Ranking de Uso</div>
      <div class="chart-subtitle">Ferramentas ordenadas por quantidade de registros</div>
      <canvas id="chartRanking" height="300"></canvas>
    </div>
  </div>

  <!-- ═══ 4. EVOLUÇÃO MENSAL ═══ -->
  <div class="section-label">
    <div class="section-title">Evolução Mensal</div>
  </div>
  <div class="charts-row">
    <div class="chart-card full">
      <div class="chart-title">Registros por Mês (<?= date('Y') ?>)</div>
      <div class="chart-subtitle">Uso de cada ferramenta ao longo dos meses</div>
      <canvas id="chartLine" height="110"></canvas>
    </div>
  </div>

  <!-- ═══ 5. VALOR MOVIMENTADO ═══ -->
  <div class="section-label">
    <div class="section-title">Valor Movimentado</div>
  </div>
  <div class="charts-row">
    <div class="chart-card full">
      <div class="chart-title">Valor Total por Ferramenta (R$)</div>
      <div class="chart-subtitle">Soma dos valores registrados em cada módulo</div>
      <canvas id="chartValor" height="90"></canvas>
    </div>
  </div>

  </div><!-- /visao-ferramenta -->

  <!-- ═══ 6. USO POR PESSOA ═══ -->
  <div id="visao-pessoa" class="visao"<?= $visao === 'pessoa' ? '' : ' hidden' ?>>
  <div class="section-label">
    <div class="section-title">Uso por Pessoa</div>
    <?= $visaoToggle ?>
    <form class="filtro-form" method="get">
      <input type="hidden" name="visao" value="pessoa">
      <input type="hidden" name="f" value="1">
      <select name="periodo" onchange="this.form.submit()">
        <?php foreach ($periodos as $chave => $p): ?>
        <option value="<?= $chave ?>" <?= $chave === $periodo ? 'selected' : '' ?>><?= $p['label'] ?></option>
        <?php endforeach; ?>
      </select>
      <?php
      $filtrosCheck = [
          ['nome' => 'loja',  'rotulo' => 'Lojas',   'todos' => 'todas', 'opcoes' => $lojas,   'marcados' => $lojasMarcadas],
          ['nome' => 'setor', 'rotulo' => 'Setores', 'todos' => 'todos', 'opcoes' => $setores, 'marcados' => $setoresMarcados],
      ];
      foreach ($filtrosCheck as $fc):
          $qtdMarcados = count(array_intersect(array_map('strval', array_keys($fc['opcoes'])), array_map('strval', $fc['marcados'])));
      ?>
      <details class="filtro-check">
        <summary><?= $fc['rotulo'] ?>: <?= $qtdMarcados === count($fc['opcoes']) ? $fc['todos'] : "$qtdMarcados de " . count($fc['opcoes']) ?></summary>
        <div class="filtro-check-painel">
          <div class="filtro-check-acoes">
            <button type="button" data-marcar="1">Marcar todos</button>
            <button type="button" data-marcar="0">Desmarcar todos</button>
          </div>
          <div class="filtro-check-lista">
            <?php foreach ($fc['opcoes'] as $valor => $rotulo): ?>
            <label>
              <input type="checkbox" name="<?= $fc['nome'] ?>[]" value="<?= htmlspecialchars((string)$valor) ?>"
                     <?= in_array((string)$valor, array_map('strval', $fc['marcados']), true) ? 'checked' : '' ?>>
              <?= htmlspecialchars($rotulo) ?>
            </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="filtro-check-aplicar">Aplicar</button>
        </div>
      </details>
      <?php endforeach; ?>
    </form>
  </div>
  <p class="adesao">
    <strong><?= $ativosComUso ?></strong> de <strong><?= $ativosTotal ?></strong> usuários ativos usaram alguma ferramenta
    (<?= $ativosTotal > 0 ? number_format($ativosComUso / $ativosTotal * 100, 0) : 0 ?>%)
    · <?= $periodos[$periodo]['label'] ?>
  </p>

  <?php
  $tabelasPessoas = [
      ['titulo' => 'Top 10 — quem mais usa',        'lista' => $topUsuarios],
      ['titulo' => '10 que menos usam (ativos)',    'lista' => $menosUsuarios],
  ];
  ?>
  <div class="charts-row ranking-pessoas">
    <?php foreach ($tabelasPessoas as $tp): ?>
    <div>
      <div class="chart-title" style="margin-bottom:10px"><?= $tp['titulo'] ?></div>
      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th style="width:40px">#</th>
              <th>Pessoa</th>
              <th>Total</th>
              <th>O que usa</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$tp['lista']): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--cinza)">Nenhum registro no período.</td></tr>
            <?php endif; ?>
            <?php $pos = 0; foreach ($tp['lista'] as $u): $pos++; ?>
            <tr>
              <td class="idx-col"><?= $pos ?></td>
              <td>
                <div class="pessoa-nome"><?= htmlspecialchars($u['nome']) ?></div>
                <div class="pessoa-loja"><?= htmlspecialchars($u['loja']) ?><?= $u['setor'] !== '' ? ' · ' . htmlspecialchars($u['setor']) : '' ?></div>
              </td>
              <td class="valor-col"><?= number_format($u['total'], 0, ',', '.') ?></td>
              <td>
                <?php if ($u['total'] === 0): ?>
                <span class="uso-zero">Nunca usou no período</span>
                <?php else: ?>
                <div class="uso-bar">
                  <?php foreach ($u['uso'] as $nomeFerr => $qtd): if (!$qtd) continue; ?>
                  <div style="width:<?= number_format($qtd / $u['total'] * 100, 1, '.', '') ?>%;background:<?= $coresFerramenta[$nomeFerr] ?>" title="<?= htmlspecialchars($nomeFerr) ?>: <?= $qtd ?>"></div>
                  <?php endforeach; ?>
                </div>
                <div class="uso-chips">
                  <?php $usoOrdenado = $u['uso']; arsort($usoOrdenado);
                  foreach ($usoOrdenado as $nomeFerr => $qtd): if (!$qtd) continue; ?>
                  <span class="uso-chip"><i style="background:<?= $coresFerramenta[$nomeFerr] ?>"></i><?= htmlspecialchars($nomeFerr) ?> <?= number_format($qtd, 0, ',', '.') ?></span>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  </div><!-- /visao-pessoa -->

  <div class="footer">
    <strong>Empresa Exemplo</strong> · Dashboard Veículos · Gerado em <?= date('d/m/Y H:i:s') ?>
  </div>
</div>

<script>
const ferramentas = <?= $ferramentasJson ?>;
const evolucao    = <?= $evolucaoJson ?>;
const totalReg    = <?= $totalGeral ?>;
const meses       = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];

// ALTERNAR VISÃO (ferramenta / pessoa)
// Os gráficos só são criados quando a visão por ferramenta aparece: Chart.js não mede canvas escondido.
let graficosCriados = false;
function mostrarVisao(visao) {
  if (visao === 'ferramenta' && !graficosCriados) { graficosCriados = true; criarGraficos(); }
  document.querySelectorAll('.visao').forEach(el => { el.hidden = el.id !== 'visao-' + visao; });
  document.querySelectorAll('.visao-toggle button').forEach(b => b.classList.toggle('ativo', b.dataset.visao === visao));
  const url = new URL(location.href);
  if (visao === 'pessoa') url.searchParams.set('visao', 'pessoa'); else url.searchParams.delete('visao');
  history.replaceState(null, '', url);
}
document.querySelectorAll('.visao-toggle button').forEach(b => b.addEventListener('click', () => mostrarVisao(b.dataset.visao)));
mostrarVisao(<?= json_encode($visao) ?>);

// FILTROS COM CHECKBOX (lojas / setores)
document.querySelectorAll('.filtro-check').forEach(det => {
  det.querySelectorAll('[data-marcar]').forEach(b => b.addEventListener('click', () => {
    det.querySelectorAll('input[type=checkbox]').forEach(c => { c.checked = b.dataset.marcar === '1'; });
  }));
  det.addEventListener('toggle', () => {
    if (det.open) document.querySelectorAll('.filtro-check[open]').forEach(o => { if (o !== det) o.open = false; });
  });
});
document.addEventListener('click', e => {
  if (!e.target.closest('.filtro-check')) document.querySelectorAll('.filtro-check[open]').forEach(o => { o.open = false; });
});

function criarGraficos() {
Chart.defaults.color = '#888';
Chart.defaults.borderColor = '#E5E2DD';
Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.font.size = 12;

// 1) DONUT
const donutSorted = [...ferramentas].sort((a,b) => b.registros - a.registros);
new Chart(document.getElementById('chartDonut'), {
  type: 'doughnut',
  data: {
    labels: donutSorted.map(f => `${f.nome}: ${((f.registros/totalReg)*100).toFixed(1)}%`),
    datasets: [{
      data: donutSorted.map(f => f.registros),
      backgroundColor: donutSorted.map(f => f.cor),
      borderColor: '#fff',
      borderWidth: 3,
      hoverOffset: 8,
    }]
  },
  options: {
    cutout: '58%',
    responsive: true,
    plugins: {
      legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true, pointStyleWidth: 10, font: { weight: 600 } } },
      tooltip: {
        backgroundColor: '#1A1A1A',
        titleFont: { weight: 700 },
        callbacks: {
          label: ctx => {
            return ` ${ctx.parsed.toLocaleString('pt-BR')} registros`;
          }
        }
      }
    }
  }
});

// 2) RANKING
const sorted = [...ferramentas].sort((a, b) => a.registros - b.registros);
new Chart(document.getElementById('chartRanking'), {
  type: 'bar',
  data: {
    labels: sorted.map(f => f.nome),
    datasets: [{
      data: sorted.map(f => f.registros),
      backgroundColor: sorted.map(f => f.cor),
      borderRadius: 8,
      barThickness: 32,
    }]
  },
  options: {
    indexAxis: 'y',
    responsive: true,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#1A1A1A',
        callbacks: { label: ctx => ` ${ctx.parsed.x.toLocaleString('pt-BR')} registros` }
      }
    },
    scales: {
      x: { grid: { color: '#E5E2DD88' }, ticks: { font: { weight: 600 } } },
      y: { grid: { display: false }, ticks: { font: { weight: 600 } } }
    }
  }
});

// 3) LINE
new Chart(document.getElementById('chartLine'), {
  type: 'line',
  data: {
    labels: meses,
    datasets: ferramentas.map(f => ({
      label: f.nome,
      data: evolucao[f.nome],
      borderColor: f.cor,
      backgroundColor: f.cor + '15',
      tension: 0.4,
      pointRadius: 4,
      pointHoverRadius: 7,
      pointBackgroundColor: '#fff',
      pointBorderColor: f.cor,
      pointBorderWidth: 2,
      borderWidth: 2.5,
      fill: false,
    }))
  },
  options: {
    responsive: true,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true, font: { weight: 600 } } },
      tooltip: { backgroundColor: '#1A1A1A' }
    },
    scales: {
      x: { grid: { color: '#E5E2DD66' } },
      y: { grid: { color: '#E5E2DD66' }, ticks: { font: { weight: 600 } } }
    }
  }
});

// 4) VALOR TOTAL
new Chart(document.getElementById('chartValor'), {
  type: 'bar',
  data: {
    labels: ferramentas.map(f => f.nome),
    datasets: [{
      data: ferramentas.map(f => f.valorTotal),
      backgroundColor: ferramentas.map(f => f.cor),
      borderRadius: 10,
      barThickness: 48,
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#1A1A1A',
        callbacks: { label: ctx => ` R$ ${ctx.parsed.y.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}` }
      }
    },
    scales: {
      x: { grid: { display: false }, ticks: { font: { weight: 600 } } },
      y: {
        grid: { color: '#E5E2DD66' },
        ticks: { font: { weight: 600 }, callback: v => 'R$ ' + (v / 1000).toFixed(0) + 'k' }
      }
    }
  }
});
}
</script>
</body>
</html>