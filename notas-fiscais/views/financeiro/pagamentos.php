<?php
// @versao Fin_02 V_06_13/08/2026
/**
 * views/financeiro/pagamentos.php
 * Segunda tela do Financeiro — visão por etapa de pagamento.
 *
 * Abas: Em Aberto · Em Atraso · Em Andamento · Liberados · Pagos
 *
 * Variáveis (do FinancialController, action=pagamentos):
 *   $parcelas, $total, $totalPgs, $pagina, $porPagina,
 *   $aba, $ordem, $filtros, $contagens, $fornecedores, $is_teste,
 *   $currentUser, $flash
 */

$parcelas   = $parcelas   ?? [];
$total      = $total      ?? 0;
$totalPgs   = $totalPgs   ?? 1;
$pagina     = $pagina     ?? 1;
$porPagina  = $porPagina  ?? 20;
$aba        = $aba        ?? 'aberto';
$ordem      = $ordem      ?? 'venc-asc';
$filtros    = $filtros    ?? [];
$contagens  = $contagens  ?? [];
$is_teste   = $is_teste   ?? false;

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmtMoeda')) {
    function fmtMoeda(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
}
if (!function_exists('fmtData')) {
    function fmtData(?string $d): string {
        if (!$d) return '—';
        try { return (new DateTime($d))->format('d/m/Y'); } catch (\Exception $e) { return h($d); }
    }
}

function pagUrl(array $extra = [], array $remove = []): string {
    $params = ['module' => 'financeiro', 'action' => 'pagamentos'];
    foreach (['aba','busca','ordem','por_pagina','testes','periodo_inicio','periodo_fim','periodo_preset'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $params[$k] = $_GET[$k];
    }
    foreach (['fornecedor_id','setor_id','proc_status','resp','filial_id'] as $k) {
        if (!empty($_GET[$k])) $params[$k] = is_array($_GET[$k]) ? $_GET[$k] : [$_GET[$k]];
    }
    foreach ($remove as $k) unset($params[$k]);
    foreach ($extra as $k => $v) $params[$k] = $v;
    return '?' . http_build_query($params);
}

if (!function_exists('sortableTh')) {
    /**
     * Cabeçalho de coluna com ordenação embutida: rótulo + setas ▲ / ▼.
     * Cada seta é um link independente; a que está ativa fica vermelha.
     *
     * @param string   $chave   prefixo da ordem (proc, nf, venc, val)
     * @param callable $urlFor  fn(string $ordem): string
     * @param string   $align   left|center|right
     */
    function sortableTh(string $label, string $chave, string $ordemAtual, callable $urlFor, string $class = '', string $align = 'left'): string
    {
        $asc   = $chave . '-asc';
        $desc  = $chave . '-desc';
        $cAsc  = $ordemAtual === $asc  ? '#2347C8' : '#B0B7C3';
        $cDesc = $ordemAtual === $desc ? '#2347C8' : '#B0B7C3';
        $just  = $align === 'right' ? 'flex-end' : ($align === 'center' ? 'center' : 'flex-start');

        return '<th' . ($class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '') . '>'
             . '<span style="display:flex;align-items:center;gap:6px;justify-content:' . $just . '">'
             . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
             . '<span style="display:inline-flex;flex-direction:column;line-height:8px">'
             . '<a href="' . htmlspecialchars($urlFor($asc), ENT_QUOTES, 'UTF-8') . '" title="Ordenar crescente"'
             . ' style="font-size:9px;text-decoration:none;color:' . $cAsc . '">&#9650;</a>'
             . '<a href="' . htmlspecialchars($urlFor($desc), ENT_QUOTES, 'UTF-8') . '" title="Ordenar decrescente"'
             . ' style="font-size:9px;text-decoration:none;color:' . $cDesc . '">&#9660;</a>'
             . '</span></span></th>';
    }
}

// Ordem pedida pelo usuário (13/08/2026): o que exige ação primeiro — liberado
// para pagar, depois atrasado — e o já pago no fim. A aba inicial continua
// sendo 'aberto'; aqui muda só a ordem de exibição.
$abasDef = [
    'liberados' => 'Liberados',
    'atraso'    => 'Em Atraso',
    'andamento' => 'Em Andamento',
    'aberto'    => 'Em Aberto',
    'pagos'     => 'Pagos',
];

$podePagar = in_array($aba, ['atraso', 'liberados'], true);
$hoje      = date('Y-m-d');

$fmtResp = function (array $p): string {
    if (empty($p['proc_responsavel_nome'])) return '—';
    $pref = (($p['proc_tipo_aprovador'] ?? '') === 'grupo') ? 'Grupo: ' : '';
    return $pref . $p['proc_responsavel_nome'];
};

// Total (valor) do recorte para o resumo
$totalValor = 0.0;
foreach ($parcelas as $p) { $totalValor += (float)$p['valor']; }
?>

<style>
.pag-wrap { background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.06) 0 1px 4px; overflow:hidden; }
.pag-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
.pag-table { width:100%; border-collapse:collapse; font-size:13px; }
.pag-table thead th {
  background:#606060; color:#fff; font-size:10.5px; font-weight:700;
  text-transform:uppercase; letter-spacing:.5px; text-align:left;
  padding:12px 16px; white-space:nowrap;
}
.pag-table tbody td { padding:13px 16px; color:#141922; border-bottom:1px solid #F0F0F0; vertical-align:middle; }
.pag-table tbody tr:last-child td { border-bottom:none; }
.pag-table tbody tr:hover td { background:#FFF6F6; }
.pag-c-right  { text-align:right; }
.pag-c-center { text-align:center; }
.pag-c-chk    { width:44px; text-align:center; }
.pag-forn     { max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.pag-btn {
  display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px;
  font-size:12.5px; font-weight:700; cursor:pointer; text-decoration:none; border:1.5px solid transparent; white-space:nowrap;
}
.pag-btn--ghost { border-color:#E5E7EB; color:#606060; background:#fff; }
.pag-btn--ghost:hover { border-color:#2347C8; color:#2347C8; }
.pag-btn--pay { background:#00A960; color:#fff; }
.pag-btn--pay:hover { background:#008F52; }
</style>

<?php include VIEWS . '/components/alert.php'; ?>

<!-- Cabeçalho -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="font-size:32px;font-weight:700;color:#141922;margin:0 0 4px">
      Contas a <span style="color:#2347C8">Pagar</span>
    </h1>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="<?= h(pagUrl(['action' => 'exportar_v2'])) ?>"
       style="height:40px;display:inline-flex;align-items:center;gap:7px;padding:0 16px;border:1.5px solid #DFDFDF;border-radius:8px;background:#fff;font-size:13.5px;font-weight:700;color:#606060;text-decoration:none">
      <i data-lucide="file-spreadsheet" width="15" height="15" style="color:#16A34A"></i>
      Exportar Excel
    </a>
    <a href="?module=financeiro&action=index"
       style="height:40px;display:inline-flex;align-items:center;gap:7px;padding:0 16px;border:1.5px solid #E5E7EB;border-radius:8px;background:#fff;font-size:13.5px;font-weight:600;color:#606060;text-decoration:none">
      <i data-lucide="arrow-left" width="15" height="15"></i>
      Financeiro (visão atual)
    </a>
  </div>
</div>

<!-- Abas -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">
  <?php foreach ($abasDef as $key => $label): $ativa = ($aba === $key); ?>
  <a href="<?= h(pagUrl(['aba' => $key])) ?>"
     style="display:inline-flex;align-items:center;gap:8px;height:40px;padding:0 18px;border-radius:10px;text-decoration:none;font-size:13.5px;font-weight:700;
            background:<?= $ativa ? '#2347C8' : '#fff' ?>;color:<?= $ativa ? '#fff' : '#606060' ?>;border:1.5px solid <?= $ativa ? '#2347C8' : '#EAEAEA' ?>">
    <?= h($label) ?>
    <span style="min-width:22px;height:20px;padding:0 7px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;
                 background:<?= $ativa ? 'rgba(255,255,255,0.25)' : '#F1F1F1' ?>;color:<?= $ativa ? '#fff' : '#999' ?>">
      <?= (int)($contagens[$key] ?? 0) ?>
    </span>
  </a>
  <?php endforeach; ?>
</div>

<?php
$setores      = $setores      ?? [];
$fornecedores = $fornecedores ?? [];
$procStatusOptions = ['aberto'=>'Aberto','aprovado'=>'Finalizado','devolvido'=>'Devolvido','cancelado'=>'Cancelado','cancelamento_solicitado'=>'Canc. Solicitado'];
$selFornec = isset($_GET['fornecedor_id']) ? (array)$_GET['fornecedor_id'] : [];
$selSetor  = isset($_GET['setor_id'])      ? (array)$_GET['setor_id']      : [];
$selProc   = isset($_GET['proc_status'])   ? (array)$_GET['proc_status']   : [];
$selResp   = isset($_GET['resp'])          ? (array)$_GET['resp']          : [];
$selFilial = isset($_GET['filial_id'])     ? (array)$_GET['filial_id']     : [];
$responsaveis = $responsaveis ?? [];
$curPreset = $_GET['periodo_preset'] ?? '';
$temPeriodo = !empty($filtros['periodo_inicio']) || !empty($filtros['periodo_fim']);
$presetLabels = ['hoje'=>'Hoje','esta-semana'=>'Esta semana','proximos-7'=>'Próximos 7 dias','este-mes'=>'Este mês','proximos-30'=>'Próximos 30 dias','proximos-60'=>'Próximos 60 dias','proximo-trimestre'=>'Próximo trimestre','vencidos'=>'Vencidos'];
$qtdFiltros = (!empty($filtros['busca'])?1:0) + (count($selFornec)?1:0) + (count($selSetor)?1:0) + (count($selProc)?1:0) + (count($selResp)?1:0) + (count($selFilial)?1:0) + (($temPeriodo || $curPreset!=='')?1:0);
$temChips = $qtdFiltros > 0;
$dropStyle = fn(bool $ativo) => "height:44px;display:flex;align-items:center;gap:8px;padding:0 14px;border:1.5px solid ".($ativo?'#2347C8':'#E5E7EB').";border-radius:8px;background:".($ativo?'#FFF5F5':'#fff').";font-size:14px;color:".($ativo?'#2347C8':'#374151').";cursor:pointer;white-space:nowrap;font-weight:".($ativo?'700':'400');
?>
<div style="background:#fff;border-radius:12px;box-shadow:rgba(0,0,0,0.06) 0 1px 4px;margin-bottom:16px">
  <!-- Cabeçalho do bloco (toggle) -->
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 18px;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:8px;font-size:14px;font-weight:700;color:#141922">
      <i data-lucide="sliders-horizontal" width="16" height="16" style="color:#2347C8"></i>
      Filtros e Seleção
      <?php if ($qtdFiltros > 0): ?><span style="font-size:11px;font-weight:700;background:#FFE8E8;color:#2347C8;padding:2px 8px;border-radius:99px"><?= $qtdFiltros ?> ativo(s)</span><?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:12px">
      <span style="font-size:13px;color:#888"><?= (int)$total ?> parcela(s) · <b style="color:#141922"><?= fmtMoeda($totalValor) ?></b></span>
      <button type="button" id="filtros-toggle" onclick="toggleFiltros()" title="Minimizar / expandir filtros"
              style="width:32px;height:32px;border:1px solid #EAEAEA;border-radius:8px;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#606060">
        <i data-lucide="chevron-up" width="16" height="16" id="filtros-chevron"></i>
      </button>
    </div>
  </div>

  <div id="filtros-body" style="border-top:1px solid #F1F5F9;padding:14px 18px 16px">
    <form method="get" action="" id="filtro-form" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <input type="hidden" name="module" value="financeiro">
      <input type="hidden" name="action" value="pagamentos">
      <input type="hidden" name="aba" value="<?= h($aba) ?>">
      <?php if ($is_teste): ?><input type="hidden" name="testes" value="1"><?php endif; ?>

      <!-- Busca — metade da largura anterior: era flex:1 e engolia a sobra da
           linha; agora tem base fixa e cede espaço aos demais filtros. -->
      <div style="flex:0 1 300px;min-width:200px;height:44px;box-sizing:border-box;display:flex;align-items:center;gap:10px;background:#F8F9FC;border:1.5px solid #E5E7EB;border-radius:8px;padding:0 14px">
        <i data-lucide="search" width="18" height="18" style="color:#B0B7C3;flex-shrink:0"></i>
        <input type="text" name="busca" value="<?= h($filtros['busca'] ?? '') ?>" placeholder="Buscar por processo, NF, fornecedor…"
               style="border:none;outline:none;font-size:14px;color:#374151;background:transparent;width:100%">
      </div>

      <!-- Fornecedor: filtro próprio removido — a busca acima já cobre fornecedor/CNPJ -->

      <!-- Loja — multi-seleção. Sem coluna na tabela de propósito (pedido de
           13/08): o filtro recorta, mas a linha não ganha mais uma coluna. -->
      <?php
      $flSufixo   = 'pag';
      $flOpcoes   = $filiaisPag ?? [];
      $flSel      = isset($_GET['filial_id']) ? (array) $_GET['filial_id'] : [];
      $flVariante = 'inline';
      $flLabel    = 'Loja';
      $flBtnExtra = 'height:44px; border-radius:8px; border-color:#E5E7EB;';
      // Sem rodapé próprio: quem aplica e limpa são os botões "Filtrar" e
      // "Limpar" que já existem no fim desta mesma linha de filtros.
      $flRodape   = false;
      include VIEWS . '/components/filtro-lojas.php';
      ?>

      <!-- Departamento -->
      <div style="position:relative" id="setor-wrap">
        <button type="button" onclick="toggleDrop('setor-drop','setor-wrap')" style="<?= $dropStyle(count($selSetor)>0) ?>">
          <?= count($selSetor) ? 'Departamento ('.count($selSetor).')' : 'Departamento' ?>
          <i data-lucide="chevron-down" width="13" height="13" style="color:#888"></i>
        </button>
        <div id="setor-drop" style="display:none;position:absolute;top:calc(100% + 5px);left:0;min-width:240px;background:#fff;border:1.5px solid #E5E7EB;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);z-index:50;overflow:hidden">
          <div style="max-height:240px;overflow-y:auto">
            <?php foreach ($setores as $s): ?>
            <label style="display:flex;align-items:center;gap:10px;padding:11px 16px;font-size:14px;color:#374151;cursor:pointer" onmouseover="this.style.background='#FFF5F5'" onmouseout="this.style.background=''">
              <input type="checkbox" name="setor_id[]" value="<?= h($s['id']) ?>" <?= in_array($s['id'],$selSetor)?'checked':'' ?> style="width:16px;height:16px;accent-color:#2347C8;cursor:pointer">
              <?= h($s['nome']) ?>
            </label>
            <?php endforeach; ?>
          </div>
          <div style="border-top:1px solid #F1F5F9;padding:10px 16px"><button type="submit" style="width:100%;padding:9px 0;background:#2347C8;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer">Aplicar</button></div>
        </div>
      </div>

      <!-- Responsável (aprovador da etapa atual) -->
      <div style="position:relative" id="resp-wrap">
        <button type="button" onclick="toggleDrop('resp-drop','resp-wrap')" style="<?= $dropStyle(count($selResp)>0) ?>">
          <?= count($selResp) ? 'Responsável ('.count($selResp).')' : 'Responsável' ?>
          <i data-lucide="chevron-down" width="13" height="13" style="color:#888"></i>
        </button>
        <div id="resp-drop" style="display:none;position:absolute;top:calc(100% + 5px);left:0;min-width:260px;background:#fff;border:1.5px solid #E5E7EB;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);z-index:50;overflow:hidden">
          <div style="max-height:240px;overflow-y:auto">
            <?php foreach ($responsaveis as $r): $rVal = $r['tipo'].':'.$r['id']; ?>
            <label style="display:flex;align-items:center;gap:10px;padding:11px 16px;font-size:14px;color:#374151;cursor:pointer" onmouseover="this.style.background='#FFF5F5'" onmouseout="this.style.background=''">
              <input type="checkbox" name="resp[]" value="<?= h($rVal) ?>" <?= in_array($rVal,$selResp)?'checked':'' ?> style="width:16px;height:16px;accent-color:#2347C8;cursor:pointer">
              <span style="flex:1"><?= h($r['nome']) ?></span>
              <span style="font-size:9px;font-weight:700;text-transform:uppercase;padding:2px 7px;border-radius:8px;background:<?= $r['tipo']==='grupo'?'#D8D4E4':'#EAEAEA' ?>;color:<?= $r['tipo']==='grupo'?'#2347C8':'#606060' ?>"><?= $r['tipo']==='grupo'?'Grupo':'Usuário' ?></span>
            </label>
            <?php endforeach; ?>
          </div>
          <div style="border-top:1px solid #F1F5F9;padding:10px 16px"><button type="submit" style="width:100%;padding:9px 0;background:#2347C8;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer">Aplicar</button></div>
        </div>
      </div>

      <!-- Status do Processo -->
      <div style="position:relative" id="procstatus-wrap">
        <button type="button" onclick="toggleDrop('procstatus-drop','procstatus-wrap')" style="<?= $dropStyle(count($selProc)>0) ?>">
          <?= count($selProc) ? 'Status do Processo ('.count($selProc).')' : 'Status do Processo' ?>
          <i data-lucide="chevron-down" width="13" height="13" style="color:#888"></i>
        </button>
        <div id="procstatus-drop" style="display:none;position:absolute;top:calc(100% + 5px);left:0;min-width:220px;background:#fff;border:1.5px solid #E5E7EB;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);z-index:50;overflow:hidden">
          <div style="max-height:240px;overflow-y:auto">
            <?php foreach ($procStatusOptions as $psKey => $psLabel): ?>
            <label style="display:flex;align-items:center;gap:10px;padding:11px 16px;font-size:14px;color:#374151;cursor:pointer" onmouseover="this.style.background='#FFF5F5'" onmouseout="this.style.background=''">
              <input type="checkbox" name="proc_status[]" value="<?= h($psKey) ?>" <?= in_array($psKey,$selProc)?'checked':'' ?> style="width:16px;height:16px;accent-color:#2347C8;cursor:pointer">
              <?= h($psLabel) ?>
            </label>
            <?php endforeach; ?>
          </div>
          <div style="border-top:1px solid #F1F5F9;padding:10px 16px"><button type="submit" style="width:100%;padding:9px 0;background:#2347C8;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer">Aplicar</button></div>
        </div>
      </div>

      <!-- Período -->
      <div style="position:relative" id="period-wrap">
        <button type="button" onclick="toggleDrop('period-drop','period-wrap')" style="<?= $dropStyle($temPeriodo || $curPreset!=='') ?>">
          <i data-lucide="calendar" width="16" height="16" style="color:#888"></i>
          <span><?php
            if ($temPeriodo) echo h(($filtros['periodo_inicio']?fmtData($filtros['periodo_inicio']):'').' – '.($filtros['periodo_fim']?fmtData($filtros['periodo_fim']):''));
            elseif ($curPreset!=='') echo h($presetLabels[$curPreset] ?? $curPreset);
            else echo 'Período';
          ?></span>
          <i data-lucide="chevron-down" width="13" height="13" style="color:#888"></i>
        </button>
        <div id="period-drop" style="display:none;position:absolute;top:calc(100% + 5px);left:0;min-width:220px;background:#fff;border:1.5px solid #E5E7EB;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);z-index:50;overflow:hidden">
          <?php foreach ($presetLabels as $key => $label): ?>
          <div onclick="selectPreset('<?= h($key) ?>')" style="padding:12px 16px;font-size:14px;color:<?= $curPreset===$key?'#2347C8':'#374151' ?>;cursor:pointer;font-weight:<?= $curPreset===$key?'700':'400' ?>" onmouseover="this.style.background='#FFF5F5'" onmouseout="this.style.background=''"><?= h($label) ?></div>
          <?php endforeach; ?>
          <div style="border-top:1px solid #F1F5F9;padding:12px 16px;cursor:pointer;font-size:14px;color:#374151;display:flex;align-items:center;gap:9px" onclick="var c=document.getElementById('custom-period');c.style.display=c.style.display==='none'?'block':'none'" onmouseover="this.style.background='#FFF5F5'" onmouseout="this.style.background=''">
            <i data-lucide="calendar-range" width="15" height="15"></i> Período personalizado
          </div>
          <div id="custom-period" style="display:none;padding:10px 14px 12px;border-top:1px solid #F1F5F9">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><label style="font-size:13px;color:#888;width:30px;flex-shrink:0">De</label><input type="date" id="cp-from" value="<?= h($filtros['periodo_inicio'] ?? '') ?>" style="flex:1;padding:7px 10px;border:1px solid #E5E7EB;border-radius:6px;font-size:14px;outline:none"></div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px"><label style="font-size:13px;color:#888;width:30px;flex-shrink:0">Até</label><input type="date" id="cp-to" value="<?= h($filtros['periodo_fim'] ?? '') ?>" style="flex:1;padding:7px 10px;border:1px solid #E5E7EB;border-radius:6px;font-size:14px;outline:none"></div>
            <button type="button" onclick="applyCustomPeriod()" style="width:100%;padding:9px 0;background:#2347C8;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer">Aplicar</button>
          </div>
          <input type="hidden" name="periodo_preset" id="periodo_preset" value="<?= h($curPreset) ?>">
          <input type="hidden" name="periodo_inicio" id="periodo_inicio" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
          <input type="hidden" name="periodo_fim" id="periodo_fim" value="<?= h($filtros['periodo_fim'] ?? '') ?>">
        </div>
      </div>

      <!-- Ordenação: saiu daqui — agora é a seta ▲/▼ no cabeçalho de cada coluna.
           O hidden mantém a ordem escolhida ao aplicar um filtro. -->
      <input type="hidden" name="ordem" value="<?= h($ordem) ?>">

      <!-- Por página -->
      <select name="por_pagina" onchange="this.form.submit()" style="border:1.5px solid #E5E7EB;border-radius:8px;padding:0 12px;height:44px;font-size:14px;color:#374151;background:#fff;cursor:pointer;outline:none">
        <option value="20" <?= $porPagina===20?'selected':'' ?>>20/pág</option>
        <option value="50" <?= $porPagina===50?'selected':'' ?>>50/pág</option>
      </select>

      <button type="submit" style="height:44px;padding:0 22px;background:#2347C8;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer">Filtrar</button>
      <a href="<?= h(pagUrl(['limpar' => '1'], ['busca','fornecedor_id','setor_id','proc_status','resp','filial_id','periodo_inicio','periodo_fim','periodo_preset'])) ?>" style="height:44px;box-sizing:border-box;display:flex;align-items:center;padding:0 16px;font-size:14px;font-weight:600;color:#606060;border:1.5px solid #E5E7EB;border-radius:8px;text-decoration:none;white-space:nowrap">Limpar</a>
    </form>

    <?php if ($temChips): ?>
    <!-- Chips de filtros ativos -->
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:12px">
      <?php if (!empty($filtros['busca'])): ?>
      <a href="<?= h(pagUrl([],['busca'])) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none">“<?= h($filtros['busca']) ?>” ×</a>
      <?php endif; ?>
      <?php foreach ($selFornec as $fId): $fNome = array_values(array_filter($fornecedores, fn($f)=>$f['id']===$fId))[0]['nome_fantasia'] ?? $fId;
            $rem = array_values(array_filter($selFornec, fn($x)=>$x!==$fId));
            $url = $rem ? pagUrl([],['fornecedor_id']).'&'.http_build_query(['fornecedor_id'=>$rem]) : pagUrl([],['fornecedor_id']); ?>
      <a href="<?= h($url) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none"><?= h($fNome) ?> ×</a>
      <?php endforeach; ?>
      <?php foreach ($selSetor as $sId): $sNome = array_values(array_filter($setores, fn($s)=>$s['id']===$sId))[0]['nome'] ?? $sId;
            $rem = array_values(array_filter($selSetor, fn($x)=>$x!==$sId));
            $url = $rem ? pagUrl([],['setor_id']).'&'.http_build_query(['setor_id'=>$rem]) : pagUrl([],['setor_id']); ?>
      <a href="<?= h($url) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none"><?= h($sNome) ?> ×</a>
      <?php endforeach; ?>
      <?php foreach ($selProc as $psId): $psNome = $procStatusOptions[$psId] ?? $psId;
            $rem = array_values(array_filter($selProc, fn($x)=>$x!==$psId));
            $url = $rem ? pagUrl([],['proc_status']).'&'.http_build_query(['proc_status'=>$rem]) : pagUrl([],['proc_status']); ?>
      <a href="<?= h($url) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none"><?= h($psNome) ?> ×</a>
      <?php endforeach; ?>
      <?php foreach ($selResp as $rVal): $rNome = array_values(array_filter($responsaveis, fn($r)=>$r['tipo'].':'.$r['id']===$rVal))[0]['nome'] ?? $rVal;
            $rem = array_values(array_filter($selResp, fn($x)=>$x!==$rVal));
            $url = $rem ? pagUrl([],['resp']).'&'.http_build_query(['resp'=>$rem]) : pagUrl([],['resp']); ?>
      <a href="<?= h($url) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none"><?= h($rNome) ?> ×</a>
      <?php endforeach; ?>
      <?php if ($temPeriodo): ?>
      <a href="<?= h(pagUrl([],['periodo_inicio','periodo_fim','periodo_preset'])) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none"><?= fmtData($filtros['periodo_inicio'] ?? '') ?> – <?= fmtData($filtros['periodo_fim'] ?? '') ?> ×</a>
      <?php elseif ($curPreset!==''): ?>
      <a href="<?= h(pagUrl([],['periodo_preset','periodo_inicio','periodo_fim'])) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none"><?= h($presetLabels[$curPreset] ?? $curPreset) ?> ×</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Tabela -->
<div class="pag-wrap">

  <?php if ($podePagar): ?>
  <!-- Barra de seleção em lote -->
  <div id="bulk-bar" style="display:none;align-items:center;gap:12px;padding:12px 20px;background:#2347C8;color:#fff;font-size:13.5px;font-weight:500">
    <span id="bulk-info" style="flex:1"></span>
    <button onclick="bulkPay()" style="padding:7px 16px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;border:none;background:#fff;color:#2347C8">Pagar selecionadas</button>
    <button onclick="clearSelection()" style="padding:7px 16px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;border:1px solid rgba(255,255,255,.4);background:rgba(255,255,255,.15);color:#fff">Cancelar seleção</button>
  </div>
  <?php endif; ?>

  <?php if (empty($parcelas)): ?>
  <div style="padding:8px">
  <?php
  $emptyIcon    = 'receipt';
  $emptyTitle   = 'Nenhuma parcela nesta aba';
  $emptyMessage = 'Ajuste a busca ou verifique outra aba.';
  $emptyAction  = null;
  include VIEWS . '/components/empty-state.php';
  ?>
  </div>
  <?php else: ?>
  <div class="pag-scroll">
  <table class="pag-table">
    <thead>
      <tr>
        <?php if ($podePagar): ?>
        <th class="pag-c-chk"><input type="checkbox" id="chk-all" onchange="toggleAll(this)" style="width:15px;height:15px;cursor:pointer;accent-color:#2347C8"></th>
        <?php endif; ?>
        <?php $urlOrdem = fn(string $o): string => pagUrl(['ordem' => $o, 'page' => 1]); ?>
        <?= sortableTh('Processo', 'proc', $ordem, $urlOrdem) ?>
        <?= sortableTh('NFE / NFSE', 'nf', $ordem, $urlOrdem) ?>
        <th>Fornecedor</th>
        <th>Responsável</th>
        <th class="pag-c-center">Parc.</th>
        <?= sortableTh('Vencimento', 'venc', $ordem, $urlOrdem) ?>
        <?= sortableTh('Valor', 'val', $ordem, $urlOrdem, 'pag-c-right', 'right') ?>
        <th class="pag-c-center">Ação</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($parcelas as $p):
        $procDetalhe = '?module=processos&action=detalhe&id=' . urlencode($p['processo_id']);
        $atrasada = in_array($p['status'], ['vencido','agvenc'], true);
      ?>
      <tr>
        <?php if ($podePagar): ?>
        <td class="pag-c-chk">
          <input type="checkbox" class="row-chk" data-id="<?= h($p['id']) ?>" data-valor="<?= h($p['valor']) ?>"
                 onchange="updateSelection()" style="width:15px;height:15px;cursor:pointer;accent-color:#2347C8">
        </td>
        <?php endif; ?>
        <td>
          <a href="<?= h($procDetalhe) ?>" target="_blank" style="font-weight:700;color:#141922;text-decoration:none"><?= h($p['proc_codigo']) ?></a>
          <?php if (!empty($p['is_teste'])): ?>
          <span style="margin-left:5px;font-size:9px;font-weight:700;background:#FFF3CD;color:#856404;padding:1px 5px;border-radius:6px">TESTE</span>
          <?php endif; ?>
        </td>
        <td style="color:#606060">
          <?php
          $nfePartes = array_filter([
              trim((string)($p['numero_nf'] ?? '')),
              trim((string)($p['num_nota_servico'] ?? '')),
          ], fn($x) => $x !== '');
          echo $nfePartes ? h(implode(' / ', $nfePartes)) : '—';
          ?>
        </td>
        <td class="pag-forn" title="<?= h($p['fornecedor_nome'] ?? $p['fornecedor_razao'] ?? '') ?>"><?= h($p['fornecedor_nome'] ?? $p['fornecedor_razao'] ?? '—') ?></td>
        <td style="color:#606060"><?= h($fmtResp($p)) ?></td>
        <td class="pag-c-center" style="color:#606060"><?= (int)$p['numero_parcela'] ?>/<?= (int)$p['total_parcelas'] ?></td>
        <td style="<?= $atrasada ? 'color:#B3261E;font-weight:700' : 'color:#374151' ?>"><?= fmtData($p['vencimento']) ?></td>
        <td class="pag-c-right" style="font-weight:700;white-space:nowrap"><?= fmtMoeda((float)$p['valor']) ?></td>
        <td class="pag-c-center">
          <?php if ($aba === 'aberto'): ?>
            <a href="<?= h($procDetalhe) ?>" target="_blank" class="pag-btn pag-btn--ghost"><i data-lucide="settings-2" width="13" height="13"></i> Ação</a>
          <?php elseif ($aba === 'pagos'): ?>
            <a href="<?= h($procDetalhe) ?>" target="_blank" class="pag-btn pag-btn--ghost"><i data-lucide="eye" width="13" height="13"></i> Visualizar</a>
          <?php elseif ($podePagar): ?>
            <button type="button" onclick="openPay('<?= h($p['id']) ?>','<?= h($p['proc_codigo']) ?>','<?= h(fmtMoeda((float)$p['valor'])) ?>')" class="pag-btn pag-btn--pay"><i data-lucide="check" width="13" height="13"></i> Pagar</button>
          <?php else: ?>
            <span style="color:#C4C4C4;font-size:12.5px">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <!-- Rodapé / paginação -->
  <div style="padding:14px 18px;border-top:1px solid #EEE;display:flex;align-items:center;justify-content:space-between;font-size:13px;color:#888;flex-wrap:wrap;gap:8px">
    <span><?= (int)$total ?> parcela(s)</span>
    <?php if ($totalPgs > 1):
      $module = 'financeiro'; $action = 'pagamentos';
      $extraParams = ['aba' => $aba];
      if (!empty($filtros['busca'])) $extraParams['busca'] = $filtros['busca'];
      if ($is_teste) $extraParams['testes'] = '1';
      $currentPage = $pagina; $totalPages = $totalPgs;
      include VIEWS . '/components/pagination.php';
    endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Modal Pagar (individual e lote) -->
<div id="modal-pagar-bg" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:100;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;width:420px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.18)">
    <form id="form-pagar" method="post" action="?module=financeiro&action=pagar_v2">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" id="pagar-id">
      <input type="hidden" name="aba" value="<?= h($aba) ?>">
      <div style="padding:20px 22px 16px;border-bottom:1px solid #F1F5F9;display:flex;justify-content:space-between;align-items:center">
        <h3 style="font-size:15px;font-weight:700;color:#111827;margin:0" id="modal-pagar-titulo">Registrar Pagamento</h3>
        <button type="button" onclick="closePay()" style="background:none;border:none;cursor:pointer;color:#94A3B8;padding:4px;display:flex"><i data-lucide="x" width="16" height="16"></i></button>
      </div>
      <div style="padding:18px 22px">
        <div id="modal-pagar-info" style="background:#F8FAFC;border:1px solid #F1F5F9;border-radius:9px;padding:12px 14px;margin-bottom:14px;font-size:13px;color:#374151"></div>
        <div style="margin-bottom:12px">
          <label style="font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;display:block">Data de pagamento <span style="color:#2347C8">*</span></label>
          <input type="date" name="data_pagamento" id="pagar-data" required value="<?= h($hoje) ?>"
                 style="width:100%;padding:9px 12px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:13px;color:#111827;outline:none"
                 onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#E5E7EB'">
        </div>
        <div style="margin-bottom:12px">
          <label style="font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;display:block">Nº comprovante <em style="font-weight:400;color:#9CA3AF">(opcional)</em></label>
          <input type="text" name="numero_comprovante" placeholder="Ex: 2026070900143"
                 style="width:100%;padding:9px 12px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:13px;color:#111827;outline:none"
                 onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#E5E7EB'">
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;display:block">Observação <em style="font-weight:400;color:#9CA3AF">(opcional)</em></label>
          <textarea name="observacao" rows="3"
                    style="width:100%;padding:9px 12px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:13px;color:#111827;resize:vertical;outline:none;font-family:inherit"
                    onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#E5E7EB'"></textarea>
        </div>
      </div>
      <div style="padding:14px 22px;border-top:1px solid #F1F5F9;display:flex;justify-content:flex-end;gap:8px">
        <button type="button" onclick="closePay()" style="padding:9px 16px;background:#fff;color:#374151;border:1.5px solid #E5E7EB;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">Cancelar</button>
        <button type="submit" style="padding:9px 20px;background:#00A960;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer">Confirmar Pagamento</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var form   = document.getElementById('form-pagar');
  var modal  = document.getElementById('modal-pagar-bg');
  var titulo = document.getElementById('modal-pagar-titulo');
  var info   = document.getElementById('modal-pagar-info');

  window.openPay = function (id, codigo, valor) {
    form.action = '?module=financeiro&action=pagar_v2';
    document.querySelectorAll('#form-pagar .bulk-id').forEach(function (e) { e.remove(); });
    document.getElementById('pagar-id').value = id;
    titulo.textContent = 'Registrar Pagamento';
    info.innerHTML = '<b>' + codigo + '</b> · ' + valor;
    modal.style.display = 'flex';
  };

  window.closePay = function () { modal.style.display = 'none'; };

  window.bulkPay = function () {
    var chks = Array.prototype.slice.call(document.querySelectorAll('.row-chk:checked'));
    if (chks.length === 0) return;
    form.action = '?module=financeiro&action=pagar_lote_v2';
    document.getElementById('pagar-id').value = '';
    document.querySelectorAll('#form-pagar .bulk-id').forEach(function (e) { e.remove(); });
    var total = 0;
    chks.forEach(function (c) {
      var inp = document.createElement('input');
      inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = c.dataset.id; inp.className = 'bulk-id';
      form.appendChild(inp);
      total += parseFloat(c.dataset.valor || '0');
    });
    titulo.textContent = 'Pagamento em Lote (' + chks.length + ')';
    info.innerHTML = chks.length + ' parcela(s) · Total: <b>R$ ' + total.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</b>';
    modal.style.display = 'flex';
  };

  window.toggleAll = function (master) {
    document.querySelectorAll('.row-chk').forEach(function (c) { c.checked = master.checked; });
    updateSelection();
  };

  window.updateSelection = function () {
    var bar = document.getElementById('bulk-bar');
    if (!bar) return;
    var chks = document.querySelectorAll('.row-chk:checked');
    if (chks.length > 0) {
      var total = 0;
      chks.forEach(function (c) { total += parseFloat(c.dataset.valor || '0'); });
      document.getElementById('bulk-info').innerHTML = chks.length + ' selecionada(s) · Total: R$ ' + total.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      bar.style.display = 'flex';
    } else {
      bar.style.display = 'none';
    }
  };

  window.clearSelection = function () {
    document.querySelectorAll('.row-chk, #chk-all').forEach(function (c) { c.checked = false; });
    updateSelection();
  };
})();
</script>

<script>
// ── Filtros: dropdowns, período e minimizar/expandir ──
function toggleDrop(dropId) {
  var drop = document.getElementById(dropId);
  if (!drop) return;
  var abrir = drop.style.display !== 'block';
  document.querySelectorAll('[id$="-drop"]').forEach(function (d) { d.style.display = 'none'; });
  drop.style.display = abrir ? 'block' : 'none';
}
document.addEventListener('click', function (e) {
  ['setor-wrap','resp-wrap','procstatus-wrap','period-wrap'].forEach(function (id) {
    var w = document.getElementById(id);
    if (w && !w.contains(e.target)) { var d = w.querySelector('[id$="-drop"]'); if (d) d.style.display = 'none'; }
  });
});
function selectPreset(key) {
  document.getElementById('periodo_preset').value = key;
  document.getElementById('periodo_inicio').value = '';
  document.getElementById('periodo_fim').value = '';
  document.getElementById('filtro-form').submit();
}
function applyCustomPeriod() {
  document.getElementById('periodo_inicio').value = document.getElementById('cp-from').value;
  document.getElementById('periodo_fim').value = document.getElementById('cp-to').value;
  document.getElementById('periodo_preset').value = '';
  document.getElementById('filtro-form').submit();
}
function toggleFiltros() {
  var body = document.getElementById('filtros-body');
  var btn  = document.getElementById('filtros-toggle');
  if (!body) return;
  var recolher = body.style.display !== 'none';
  body.style.display = recolher ? 'none' : '';
  if (btn) { btn.style.transition = 'transform .15s'; btn.style.transform = recolher ? 'rotate(180deg)' : ''; }
  try { localStorage.setItem('pag_filtros_collapsed', recolher ? '1' : '0'); } catch (e) {}
}
(function () {
  try {
    if (localStorage.getItem('pag_filtros_collapsed') === '1') {
      var body = document.getElementById('filtros-body');
      var btn  = document.getElementById('filtros-toggle');
      if (body) body.style.display = 'none';
      if (btn)  btn.style.transform = 'rotate(180deg)';
    }
  } catch (e) {}
})();
</script>
