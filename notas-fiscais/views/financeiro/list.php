<?php
// @versao Fin_01 V_03_12/08/2026
/**
 * views/financeiro/list.php
 * Controle Financeiro — lista de parcelas
 *
 * Renderizado via ob_start + base.php pelo FinancialController.
 *
 * Variáveis esperadas:
 *   $parcelas     array   lista de parcelas paginadas
 *   $total        int     total de parcelas no recorte
 *   $totalPgs     int     total de páginas
 *   $pagina       int     página atual
 *   $porPagina    int     itens por página (20|50)
 *   $aba          string  aba ativa
 *   $ordem        string  ordem ativa
 *   $filtros      array   filtros ativos
 *   $contagens    array   contagens por aba
 *   $kpis         array   KPIs financeiros do recorte
 *   $fornecedores array
 *   $setores      array
 *   $alertasVenc  array   parcelas vencidas para banner vermelho
 *   $alertasProx  array   parcelas próximas para banner âmbar
 *   $is_teste     bool
 *   $currentUser  array
 *   $flash        array|null
 */

$parcelas    = $parcelas    ?? [];
$total       = $total       ?? 0;
$totalPgs    = $totalPgs    ?? 1;
$pagina      = $pagina      ?? 1;
$porPagina   = $porPagina   ?? 20;
$aba         = $aba         ?? 'todos';
$ordem       = $ordem       ?? 'venc-asc';
$filtros     = $filtros     ?? [];
$contagens   = $contagens   ?? [];
$kpis        = $kpis        ?? [];
$fornecedores= $fornecedores?? [];
$setores     = $setores     ?? [];
$alertasVenc = $alertasVenc ?? [];
$alertasProx = $alertasProx ?? [];
$is_teste    = $is_teste    ?? false;

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('fmtMoeda')) {
    function fmtMoeda(float $v): string {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }
}
if (!function_exists('fmtData')) {
    function fmtData(?string $d): string {
        if (!$d) return '—';
        try {
            $dt = new DateTime($d);
            return $dt->format('d/m/Y');
        } catch (\Exception $e) {
            return h($d);
        }
    }
}
if (!function_exists('parcelaStatusBadge')) {
    function parcelaStatusBadge(string $s): string {
        $map = [
            'previsto'  => ['bg'=>'#D8D4E4','color'=>'#606060','label'=>'Previsto'],
            'agendado'  => ['bg'=>'#E3F0FF','color'=>'#1565C0','label'=>'Agendado'],
            'agvenc'    => ['bg'=>'#FFF3E0','color'=>'#FF8A2D','label'=>'Ag. Vencido'],
            'vencido'   => ['bg'=>'#FFE8EE','color'=>'#B3261E','label'=>'Vencido'],
            'pago'      => ['bg'=>'#D8F5E5','color'=>'#00A960','label'=>'Pago'],
            'cancelado' => ['bg'=>'#EAEAEA','color'=>'#888888','label'=>'Cancelado'],
        ];
        $d = $map[$s] ?? ['bg'=>'#EAEAEA','color'=>'#888','label'=>ucfirst($s)];
        return '<span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:8px;'
             . 'font-size:11px;font-weight:700;background:' . $d['bg'] . ';color:' . $d['color'] . '">'
             . '<span style="width:6px;height:6px;border-radius:50%;background:' . $d['color'] . ';flex-shrink:0"></span>'
             . h($d['label']) . '</span>';
    }
}

// URL base para links internos
$baseUrl = '?module=financeiro';

// Monta query string preservando filtros ao trocar aba/página
if (!function_exists('filterUrl')) {
    function filterUrl(array $extra = [], array $remove = []): string {
        $params = ['module' => 'financeiro', 'action' => 'index'];
        foreach (['aba','busca','periodo_inicio','periodo_fim','periodo_preset','ordem','por_pagina','testes'] as $k) {
            if (isset($_GET[$k]) && $_GET[$k] !== '') $params[$k] = $_GET[$k];
        }
        // array params
        foreach (['fornecedor_id','setor_id','proc_status','resp'] as $k) {
            if (!empty($_GET[$k])) {
                $params[$k] = is_array($_GET[$k]) ? $_GET[$k] : [$_GET[$k]];
            }
        }
        foreach ($remove as $k) unset($params[$k]);
        foreach ($extra as $k => $v) $params[$k] = $v;
        return '?' . http_build_query($params);
    }
}

if (!function_exists('sortableTh')) {
    /**
     * Cabeçalho de coluna com ordenação embutida: rótulo + setas ▲ / ▼.
     * Cada seta é um link independente; a que está ativa fica vermelha.
     *
     * @param string   $chave      prefixo da ordem (proc, nf, depto, venc, val)
     * @param callable $urlFor     fn(string $ordem): string — monta a URL já resetando a página
     * @param string   $align      left|center|right (acompanha o alinhamento da coluna)
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

$hasFilters = !empty($filtros['busca'])
           || !empty($_GET['fornecedor_id'])
           || !empty($_GET['setor_id'])
           || !empty($_GET['proc_status'])
           || !empty($_GET['resp'])
           || !empty($filtros['periodo_inicio'])
           || !empty($filtros['periodo_fim'])
           || !empty($_GET['periodo_preset'] ?? '');
?>

<?php include VIEWS . '/components/alert.php'; ?>

<!-- Cabeçalho -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="font-size:32px;font-weight:700;color:#141922;margin:0 0 4px">
      Controle <span style="color:#2347C8">Financeiro</span>
    </h1>
    <p style="font-size:14px;color:#888;margin:0">Acompanhe e gerencie o pagamento de parcelas</p>
  </div>
  <a href="<?= filterUrl(['action'=>'exportar']) ?>"
     style="display:inline-flex;align-items:center;gap:8px;background:#fff;color:#606060;border:1.5px solid #DFDFDF;border-radius:4px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none">
    <i data-lucide="file-spreadsheet" width="15" height="15" style="color:#16A34A"></i>
    Exportar Excel
  </a>
</div>

<?php if (!empty($alertasVenc) || !empty($alertasProx)): ?>
<!-- Modal de alertas financeiros -->
<div id="fin-alert-modal" style="position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:10000;display:none;align-items:center;justify-content:center;padding:20px;animation:btModalIn 0.2s ease">
  <div style="background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,0.2);width:100%;max-width:560px;overflow:hidden;position:relative">

    <!-- Botão fechar -->
    <button onclick="finDismissAlert()"
            style="position:absolute;top:14px;right:14px;width:32px;height:32px;border-radius:8px;border:none;background:#F6F6F6;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#606060;font-size:18px;z-index:1;transition:background 0.15s"
            onmouseover="this.style.background='#FFE8EE';this.style.color='#B3261E'" onmouseout="this.style.background='#F6F6F6';this.style.color='#606060'">✕</button>

    <!-- Header -->
    <div style="padding:24px 24px 0;display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:10px;background:#FFE8EE;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#B3261E" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      </div>
      <div>
        <div style="font-size:18px;font-weight:700;color:#141922">Alertas Financeiros</div>
        <div style="font-size:13px;color:#888">Parcelas que exigem atenção</div>
      </div>
    </div>

    <div style="padding:20px 24px;max-height:400px;overflow-y:auto">

      <?php if (!empty($alertasVenc)): ?>
      <!-- Parcelas vencidas -->
      <div style="background:#FFF5F5;border:1px solid #FECACA;border-radius:8px;padding:14px 16px;margin-bottom:<?= !empty($alertasProx) ? '14px' : '0' ?>">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2.5" stroke-linecap="round" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span style="font-size:14px;font-weight:700;color:#991B1B"><?= count($alertasVenc) ?> parcela(s) vencida(s)</span>
        </div>
        <?php foreach ($alertasVenc as $a): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;<?= $a !== end($alertasVenc) ? 'border-bottom:1px solid #FEE2E2;' : '' ?>">
          <div>
            <span style="font-size:12.5px;font-weight:700;color:#DC2626"><?= h($a['proc_codigo']) ?></span>
            <span style="font-size:12px;color:#991B1B;margin-left:6px"><?= (int)$a['dias_atraso'] ?> dias em atraso</span>
          </div>
          <span style="font-size:12.5px;font-weight:600;color:#991B1B"><?= fmtMoeda((float)$a['valor']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($alertasProx)): ?>
      <!-- Parcelas próximas do vencimento -->
      <div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:14px 16px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2.5" stroke-linecap="round" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span style="font-size:14px;font-weight:700;color:#854D0E"><?= count($alertasProx) ?> parcela(s) vencem em breve</span>
        </div>
        <?php foreach ($alertasProx as $a): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;<?= $a !== end($alertasProx) ? 'border-bottom:1px solid #FEF3C7;' : '' ?>">
          <div>
            <span style="font-size:12.5px;font-weight:700;color:#D97706"><?= h($a['proc_codigo']) ?></span>
            <span style="font-size:12px;color:#854D0E;margin-left:6px">vence <?= fmtData($a['vencimento']) ?></span>
          </div>
          <span style="font-size:12.5px;font-weight:600;color:#854D0E"><?= fmtMoeda((float)$a['valor']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>

    <!-- Footer -->
    <div style="padding:0 24px 20px;display:flex;justify-content:flex-end">
      <button onclick="finDismissAlert()"
              style="padding:10px 24px;background:#2347C8;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer;transition:background 0.15s"
              onmouseover="this.style.background='#1B38A0'" onmouseout="this.style.background='#2347C8'">
        Entendi
      </button>
    </div>

  </div>
</div>
<script>
(function() {
  var KEY = 'fin_alert_seen';
  var hoje = new Date().toISOString().substring(0, 10);
  var modal = document.getElementById('fin-alert-modal');
  if (!modal) return;

  if (localStorage.getItem(KEY) === hoje) {
    modal.remove();
    return;
  }

  modal.style.display = 'flex';

  modal.addEventListener('click', function(e) {
    if (e.target === modal) finDismissAlert();
  });

  window.finDismissAlert = function() {
    localStorage.setItem(KEY, hoje);
    modal.remove();
  };
}());
</script>
<?php endif; ?>

<!-- Cards KPI -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px">
  <?php
  $kpiCards = [
      [
          'label'   => 'Vencido',
          'desc'    => 'Parcelas com vencimento ultrapassado',
          'valor'   => $kpis['vencido'] ?? 0,
          'qtd'     => $kpis['vencido_qtd'] ?? 0,
          'color'   => '#DC2626',
          'bg'      => '#FFF5F5',
          'border'  => '#FECACA',
          'icon'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
          'aba'     => 'vencido',
      ],
      [
          'label'   => 'Vence em 7 dias',
          'desc'    => 'Parcelas sem agendamento próximas do vencimento',
          'valor'   => $kpis['avencer_7d'] ?? 0,
          'qtd'     => $kpis['avencer_7d_qtd'] ?? 0,
          'color'   => '#D97706',
          'bg'      => '#FFFBEB',
          'border'  => '#FDE68A',
          'icon'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
          'aba'     => 'previsto',
      ],
      [
          'label'   => 'Agendado',
          'desc'    => 'Parcelas com pagamento agendado',
          'valor'   => $kpis['agendado'] ?? 0,
          'qtd'     => $kpis['agendado_qtd'] ?? 0,
          'color'   => '#2563EB',
          'bg'      => '#EFF6FF',
          'border'  => '#BFDBFE',
          'icon'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563EB" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
          'aba'     => 'agendado',
      ],
      [
          'label'   => 'Previsto',
          'desc'    => 'Parcelas aguardando agendamento',
          'valor'   => $kpis['previsto'] ?? 0,
          'qtd'     => $kpis['previsto_qtd'] ?? 0,
          'color'   => '#141922',
          'bg'      => '#fff',
          'border'  => '#E5E7EB',
          'icon'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#606060" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
          'aba'     => 'previsto',
      ],
      [
          'label'   => 'Pago',
          'desc'    => 'Parcelas quitadas no período',
          'valor'   => $kpis['pago'] ?? 0,
          'qtd'     => $kpis['pago_qtd'] ?? 0,
          'color'   => '#00A960',
          'bg'      => '#F0FDF4',
          'border'  => '#BBF7D0',
          'icon'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#00A960" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>',
          'aba'     => 'pago',
      ],
  ];
  foreach ($kpiCards as $kc):
  ?>
  <a href="<?= filterUrl(['aba' => $kc['aba']]) ?>"
     style="background:<?= $kc['bg'] ?>;border:1px solid <?= $kc['border'] ?>;border-radius:10px;padding:14px 16px;text-decoration:none;display:flex;flex-direction:column;gap:8px;transition:box-shadow 0.15s,transform 0.15s;cursor:pointer"
     onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)';this.style.transform='translateY(-1px)'"
     onmouseout="this.style.boxShadow='none';this.style.transform='none'">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <div style="display:flex;align-items:center;gap:8px">
        <?= $kc['icon'] ?>
        <span style="font-size:11px;font-weight:700;color:<?= $kc['color'] ?>;text-transform:uppercase;letter-spacing:.4px"><?= h($kc['label']) ?></span>
      </div>
      <span style="font-size:11px;font-weight:600;color:#94A3B8"><?= $kc['qtd'] ?> parc.</span>
    </div>
    <div style="font-size:20px;font-weight:800;color:<?= $kc['color'] ?>;letter-spacing:-.4px"><?= fmtMoeda((float)$kc['valor']) ?></div>
    <div style="font-size:10.5px;color:#94A3B8;line-height:1.3"><?= h($kc['desc']) ?></div>
  </a>
  <?php endforeach; ?>
</div>

<!-- Card principal -->
<div style="background:#fff;border-radius:12px;box-shadow:rgba(0,0,0,0.10) 0 2px 8px">

  <!-- Abas -->
  <div style="display:flex;align-items:center;border-bottom:1px solid #F1F5F9;padding:14px 20px 0;gap:2px">
    <?php
    $abas = [
      ['id'=>'todos',    'label'=>'Em aberto',  'cnt'=>$contagens['todos']    ?? 0, 'alert'=>false],
      ['id'=>'vencido',  'label'=>'Em atraso',  'cnt'=>$contagens['atraso']   ?? 0, 'alert'=>true],
      ['id'=>'previsto', 'label'=>'Previstos',  'cnt'=>$contagens['previsto'] ?? 0, 'alert'=>false],
      ['id'=>'agendado', 'label'=>'Agendados',  'cnt'=>$contagens['agendado'] ?? 0, 'alert'=>false],
      ['id'=>'pago',     'label'=>'Pagos',      'cnt'=>$contagens['pago']     ?? 0, 'alert'=>false],
      ['id'=>'cancelado','label'=>'Cancelados', 'cnt'=>$contagens['cancelado']?? 0, 'alert'=>false],
    ];
    foreach ($abas as $tab):
        $isActive = $aba === $tab['id'];
        $hasAlert = $tab['alert'] && $tab['cnt'] > 0;
        $activeStyle = $isActive
            ? 'color:#2347C8;font-weight:700;border-bottom:2px solid #2347C8;background:transparent'
            : 'color:#888;font-weight:500;border-bottom:2px solid transparent;background:none';
        $cntBg = $isActive ? '#FFE8E8' : ($hasAlert ? '#FEE2E2' : '#F1F5F9');
        $cntColor = $isActive ? '#2347C8' : ($hasAlert ? '#DC2626' : '#888');
    ?>
    <a href="<?= filterUrl(['aba'=>$tab['id'],'pagina'=>1]) ?>"
       style="display:flex;align-items:center;gap:8px;padding:12px 18px;border-radius:8px 8px 0 0;font-size:15px;text-decoration:none;margin-bottom:-1px;<?= $activeStyle ?>">
      <?= h($tab['label']) ?>
      <span style="font-size:12.5px;font-weight:700;padding:2px 9px;border-radius:99px;background:<?= $cntBg ?>;color:<?= $cntColor ?>"><?= $tab['cnt'] ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Barra de filtros -->
  <form method="get" action="" id="filtro-form" style="display:flex;align-items:center;gap:8px;padding:12px 20px;border-bottom:1px solid #F1F5F9;flex-wrap:wrap">
    <input type="hidden" name="module" value="financeiro">
    <input type="hidden" name="action" value="index">
    <input type="hidden" name="aba" value="<?= h($aba) ?>">
    <input type="hidden" name="por_pagina" value="<?= $porPagina ?>">
    <?php if ($is_teste): ?><input type="hidden" name="testes" value="1"><?php endif; ?>

    <!-- Busca -->
    <div style="flex:1;min-width:200px;height:44px;box-sizing:border-box;display:flex;align-items:center;gap:10px;background:#F8F9FC;border:1.5px solid #E5E7EB;border-radius:8px;padding:0 16px"
         onfocusin="this.style.borderColor='#2347C8'" onfocusout="this.style.borderColor='#E5E7EB'">
      <i data-lucide="search" width="18" height="18" style="color:#B0B7C3;flex-shrink:0"></i>
      <input type="text" name="busca" value="<?= h($filtros['busca'] ?? '') ?>"
             placeholder="Buscar por processo, NF, fornecedor, CNPJ…"
             style="border:none;outline:none;font-size:15px;color:#374151;background:transparent;width:100%">
    </div>

    <!-- Fornecedor: filtro próprio removido — a busca acima já cobre fornecedor/CNPJ -->

    <!-- Departamento multi-select -->
    <?php
    $selSetor = isset($_GET['setor_id']) ? (array)$_GET['setor_id'] : [];
    $selSetorCount = count($selSetor);
    $setorBtnLabel = $selSetorCount === 0 ? 'Departamento' : 'Departamento (' . $selSetorCount . ')';
    $setorActive = $selSetorCount > 0;
    ?>
    <div style="position:relative" id="setor-wrap">
      <button type="button"
              onclick="toggleDrop('setor-drop','setor-wrap')"
              style="height:44px;display:flex;align-items:center;gap:8px;padding:0 16px;border:1.5px solid <?= $setorActive ? '#2347C8' : '#E5E7EB' ?>;border-radius:8px;background:<?= $setorActive ? '#FFF5F5' : '#fff' ?>;font-size:14px;color:<?= $setorActive ? '#2347C8' : '#374151' ?>;cursor:pointer;white-space:nowrap;font-weight:<?= $setorActive ? '700' : '400' ?>">
        <?= h($setorBtnLabel) ?>
        <i data-lucide="chevron-down" width="13" height="13" style="color:#888"></i>
      </button>
      <div id="setor-drop" style="display:none;position:absolute;top:calc(100% + 5px);left:0;min-width:240px;background:#fff;border:1.5px solid #E5E7EB;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);z-index:50;overflow:hidden">
        <div style="max-height:240px;overflow-y:auto">
          <?php foreach ($setores as $s): ?>
          <label style="display:flex;align-items:center;gap:10px;padding:11px 16px;font-size:14px;color:#374151;cursor:pointer"
                 onmouseover="this.style.background='#FFF5F5'" onmouseout="this.style.background=''">
            <input type="checkbox" name="setor_id[]" value="<?= h($s['id']) ?>"
                   <?= in_array($s['id'], $selSetor) ? 'checked' : '' ?>
                   style="width:16px;height:16px;accent-color:#2347C8;cursor:pointer">
            <?= h($s['nome']) ?>
          </label>
          <?php endforeach; ?>
        </div>
        <div style="border-top:1px solid #F1F5F9;padding:10px 16px">
          <button type="submit" onclick="document.getElementById('setor-drop').style.display='none'"
                  style="width:100%;padding:9px 0;background:#2347C8;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer">Aplicar</button>
        </div>
      </div>
    </div>

    <!-- Responsável multi-select (aprovador da etapa atual) -->
    <?php
    $responsaveis = $responsaveis ?? [];
    $selResp = isset($_GET['resp']) ? (array)$_GET['resp'] : [];
    $selRespCount = count($selResp);
    $respBtnLabel = $selRespCount === 0 ? 'Responsável' : 'Responsável (' . $selRespCount . ')';
    $respActive = $selRespCount > 0;
    ?>
    <div style="position:relative" id="resp-wrap">
      <button type="button"
              onclick="toggleDrop('resp-drop','resp-wrap')"
              style="height:44px;display:flex;align-items:center;gap:8px;padding:0 16px;border:1.5px solid <?= $respActive ? '#2347C8' : '#E5E7EB' ?>;border-radius:8px;background:<?= $respActive ? '#FFF5F5' : '#fff' ?>;font-size:14px;color:<?= $respActive ? '#2347C8' : '#374151' ?>;cursor:pointer;white-space:nowrap;font-weight:<?= $respActive ? '700' : '400' ?>">
        <?= h($respBtnLabel) ?>
        <i data-lucide="chevron-down" width="13" height="13" style="color:#888"></i>
      </button>
      <div id="resp-drop" style="display:none;position:absolute;top:calc(100% + 5px);left:0;min-width:260px;background:#fff;border:1.5px solid #E5E7EB;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);z-index:50;overflow:hidden">
        <div style="max-height:240px;overflow-y:auto">
          <?php foreach ($responsaveis as $r):
            $rVal = $r['tipo'] . ':' . $r['id']; ?>
          <label style="display:flex;align-items:center;gap:10px;padding:11px 16px;font-size:14px;color:#374151;cursor:pointer"
                 onmouseover="this.style.background='#FFF5F5'" onmouseout="this.style.background=''">
            <input type="checkbox" name="resp[]" value="<?= h($rVal) ?>"
                   <?= in_array($rVal, $selResp) ? 'checked' : '' ?>
                   style="width:16px;height:16px;accent-color:#2347C8;cursor:pointer">
            <span style="flex:1"><?= h($r['nome']) ?></span>
            <span style="font-size:9px;font-weight:700;text-transform:uppercase;padding:2px 7px;border-radius:8px;background:<?= $r['tipo'] === 'grupo' ? '#D8D4E4' : '#EAEAEA' ?>;color:<?= $r['tipo'] === 'grupo' ? '#2347C8' : '#606060' ?>"><?= $r['tipo'] === 'grupo' ? 'Grupo' : 'Usuário' ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <div style="border-top:1px solid #F1F5F9;padding:10px 16px">
          <button type="submit" onclick="document.getElementById('resp-drop').style.display='none'"
                  style="width:100%;padding:9px 0;background:#2347C8;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer">Aplicar</button>
        </div>
      </div>
    </div>

    <!-- Status do Processo multi-select -->
    <?php
    $selProcStatus = isset($_GET['proc_status']) ? (array)$_GET['proc_status'] : [];
    $selProcStatusCount = count($selProcStatus);
    $procStatusBtnLabel = $selProcStatusCount === 0 ? 'Status do Processo' : 'Status do Processo (' . $selProcStatusCount . ')';
    $procStatusActive = $selProcStatusCount > 0;
    $procStatusOptions = [
        'aberto'                  => 'Aberto',
        'aprovado'                => 'Finalizado',
        'devolvido'               => 'Devolvido',
        'cancelado'               => 'Cancelado',
        'cancelamento_solicitado' => 'Canc. Solicitado',
    ];
    ?>
    <div style="position:relative" id="procstatus-wrap">
      <button type="button"
              onclick="toggleDrop('procstatus-drop','procstatus-wrap')"
              style="height:44px;display:flex;align-items:center;gap:8px;padding:0 16px;border:1.5px solid <?= $procStatusActive ? '#2347C8' : '#E5E7EB' ?>;border-radius:8px;background:<?= $procStatusActive ? '#FFF5F5' : '#fff' ?>;font-size:14px;color:<?= $procStatusActive ? '#2347C8' : '#374151' ?>;cursor:pointer;white-space:nowrap;font-weight:<?= $procStatusActive ? '700' : '400' ?>">
        <?= h($procStatusBtnLabel) ?>
        <i data-lucide="chevron-down" width="13" height="13" style="color:#888"></i>
      </button>
      <div id="procstatus-drop" style="display:none;position:absolute;top:calc(100% + 5px);left:0;min-width:220px;background:#fff;border:1.5px solid #E5E7EB;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);z-index:50;overflow:hidden">
        <div style="max-height:240px;overflow-y:auto">
          <?php foreach ($procStatusOptions as $psKey => $psLabel): ?>
          <label style="display:flex;align-items:center;gap:10px;padding:11px 16px;font-size:14px;color:#374151;cursor:pointer"
                 onmouseover="this.style.background='#FFF5F5'" onmouseout="this.style.background=''">
            <input type="checkbox" name="proc_status[]" value="<?= h($psKey) ?>"
                   <?= in_array($psKey, $selProcStatus) ? 'checked' : '' ?>
                   style="width:16px;height:16px;accent-color:#2347C8;cursor:pointer">
            <?= h($psLabel) ?>
          </label>
          <?php endforeach; ?>
        </div>
        <div style="border-top:1px solid #F1F5F9;padding:10px 16px">
          <button type="submit" onclick="document.getElementById('procstatus-drop').style.display='none'"
                  style="width:100%;padding:9px 0;background:#2347C8;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer">Aplicar</button>
        </div>
      </div>
    </div>

    <!-- Período dropdown -->
    <div style="position:relative" id="period-wrap">
      <button type="button" id="period-btn"
              onclick="toggleDrop('period-drop','period-wrap')"
              style="height:44px;display:flex;align-items:center;gap:8px;padding:0 16px;border:1.5px solid #E5E7EB;border-radius:8px;background:#fff;font-size:14px;color:#374151;cursor:pointer;white-space:nowrap">
        <i data-lucide="calendar" width="16" height="16" style="color:#888"></i>
        <span id="period-label"><?php
          if (!empty($filtros['periodo_inicio']) || !empty($filtros['periodo_fim'])) {
              echo h(($filtros['periodo_inicio'] ? fmtData($filtros['periodo_inicio']) : '') . ' – ' . ($filtros['periodo_fim'] ? fmtData($filtros['periodo_fim']) : ''));
          } else {
              echo 'Período';
          }
        ?></span>
        <i data-lucide="chevron-down" width="13" height="13" style="color:#888"></i>
      </button>
      <div id="period-drop" style="display:none;position:absolute;top:calc(100% + 5px);left:0;min-width:220px;background:#fff;border:1.5px solid #E5E7EB;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);z-index:50;overflow:hidden">
        <?php
        $presets = [
            'esta-semana'       => 'Esta semana',
            'proximos-7'        => 'Próximos 7 dias',
            'este-mes'          => 'Este mês',
            'proximos-30'       => 'Próximos 30 dias',
            'proximos-60'       => 'Próximos 60 dias',
            'proximo-trimestre' => 'Próximo trimestre',
            'vencidos'          => 'Vencidos',
        ];
        $currentPreset = $_GET['periodo_preset'] ?? '';
        foreach ($presets as $key => $label): ?>
        <div onclick="selectPreset('<?= h($key) ?>')"
             style="padding:12px 16px;font-size:14px;color:<?= $currentPreset === $key ? '#2347C8' : '#374151' ?>;cursor:pointer;font-weight:<?= $currentPreset === $key ? '700' : '400' ?>"
             onmouseover="this.style.background='#FFF5F5'" onmouseout="this.style.background=''"><?= h($label) ?></div>
        <?php endforeach; ?>
        <div style="border-top:1px solid #F1F5F9;padding:12px 16px;cursor:pointer;font-size:14px;color:#374151;display:flex;align-items:center;gap:9px"
             onclick="document.getElementById('custom-period').style.display=document.getElementById('custom-period').style.display==='none'?'block':'none'"
             onmouseover="this.style.background='#FFF5F5'" onmouseout="this.style.background=''">
          <i data-lucide="calendar-range" width="15" height="15"></i> Período personalizado
        </div>
        <div id="custom-period" style="display:none;padding:10px 14px 12px;border-top:1px solid #F1F5F9">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
            <label style="font-size:13px;color:#888;width:30px;flex-shrink:0">De</label>
            <input type="date" id="cp-from" value="<?= h($filtros['periodo_inicio'] ?? '') ?>"
                   style="flex:1;padding:7px 10px;border:1px solid #E5E7EB;border-radius:6px;font-size:14px;outline:none"
                   onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#E5E7EB'">
          </div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
            <label style="font-size:13px;color:#888;width:30px;flex-shrink:0">Até</label>
            <input type="date" id="cp-to" value="<?= h($filtros['periodo_fim'] ?? '') ?>"
                   style="flex:1;padding:7px 10px;border:1px solid #E5E7EB;border-radius:6px;font-size:14px;outline:none"
                   onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#E5E7EB'">
          </div>
          <button type="button" onclick="applyCustomPeriod()"
                  style="width:100%;padding:9px 0;background:#2347C8;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer">Aplicar</button>
        </div>
        <input type="hidden" name="periodo_preset" id="periodo_preset" value="<?= h($_GET['periodo_preset'] ?? '') ?>">
        <input type="hidden" name="periodo_inicio" id="periodo_inicio" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
        <input type="hidden" name="periodo_fim" id="periodo_fim" value="<?= h($filtros['periodo_fim'] ?? '') ?>">
      </div>
    </div>

    <!-- Ordenação: saiu daqui — agora é a seta ▲/▼ no cabeçalho de cada coluna.
         O hidden mantém a ordem escolhida ao aplicar um filtro. -->
    <input type="hidden" name="ordem" value="<?= h($ordem) ?>">

    <button type="submit" style="height:44px;padding:0 22px;margin-left:auto;background:#2347C8;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer">Filtrar</button>
    <a href="<?= filterUrl([], ['busca','fornecedor_id','setor_id','proc_status','resp','periodo_inicio','periodo_fim','periodo_preset']) ?>"
       style="height:44px;box-sizing:border-box;display:flex;align-items:center;padding:0 16px;font-size:14px;font-weight:600;color:#606060;border:1.5px solid #E5E7EB;border-radius:8px;text-decoration:none;white-space:nowrap">
      Limpar
    </a>
  </form>

  <!-- Chips de filtros ativos -->
  <?php if ($hasFilters): ?>
  <div style="display:flex;align-items:center;gap:6px;padding:0 20px 10px;flex-wrap:wrap">
    <span style="font-size:11px;color:#94A3B8;font-weight:500">Filtros ativos:</span>
    <?php if (!empty($filtros['busca'])): ?>
    <a href="<?= filterUrl([], ['busca']) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none">
      "<?= h($filtros['busca']) ?>" <span style="font-size:13px">×</span>
    </a>
    <?php endif; ?>
    <?php
    $chipFornecIds = !empty($_GET['fornecedor_id']) ? (array)$_GET['fornecedor_id'] : [];
    if (!empty($chipFornecIds)):
        if (count($chipFornecIds) >= 3):
    ?>
    <a href="<?= filterUrl([], ['fornecedor_id']) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none">
      Fornecedor (<?= count($chipFornecIds) ?>) <span style="font-size:13px">×</span>
    </a>
    <?php else: foreach ($chipFornecIds as $fId):
        $fNome = array_values(array_filter($fornecedores, fn($f) => $f['id'] === $fId))[0]['nome_fantasia'] ?? $fId;
        $remainFornec = array_values(array_filter($chipFornecIds, fn($x) => $x !== $fId));
        $removeUrl = count($remainFornec) > 0
            ? filterUrl([], ['fornecedor_id']) . '&' . http_build_query(['fornecedor_id' => $remainFornec])
            : filterUrl([], ['fornecedor_id']);
    ?>
    <a href="<?= $removeUrl ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none">
      <?= h($fNome) ?> <span style="font-size:13px">×</span>
    </a>
    <?php endforeach; endif; endif; ?>
    <?php
    $chipSetorIds = !empty($_GET['setor_id']) ? (array)$_GET['setor_id'] : [];
    if (!empty($chipSetorIds)):
        if (count($chipSetorIds) >= 3):
    ?>
    <a href="<?= filterUrl([], ['setor_id']) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none">
      Departamento (<?= count($chipSetorIds) ?>) <span style="font-size:13px">×</span>
    </a>
    <?php else: foreach ($chipSetorIds as $sId):
        $sNome = array_values(array_filter($setores, fn($s) => $s['id'] === $sId))[0]['nome'] ?? $sId;
        $remainSetor = array_values(array_filter($chipSetorIds, fn($x) => $x !== $sId));
        $removeUrlS = count($remainSetor) > 0
            ? filterUrl([], ['setor_id']) . '&' . http_build_query(['setor_id' => $remainSetor])
            : filterUrl([], ['setor_id']);
    ?>
    <a href="<?= $removeUrlS ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none">
      <?= h($sNome) ?> <span style="font-size:13px">×</span>
    </a>
    <?php endforeach; endif; endif; ?>
    <?php
    $chipProcStatus = !empty($_GET['proc_status']) ? (array)$_GET['proc_status'] : [];
    if (!empty($chipProcStatus)):
        if (count($chipProcStatus) >= 3):
    ?>
    <a href="<?= filterUrl([], ['proc_status']) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none">
      Status do Processo (<?= count($chipProcStatus) ?>) <span style="font-size:13px">×</span>
    </a>
    <?php else: foreach ($chipProcStatus as $psId):
        $psNome = $procStatusOptions[$psId] ?? $psId;
        $remainProcStatus = array_values(array_filter($chipProcStatus, fn($x) => $x !== $psId));
        $removeUrlPs = count($remainProcStatus) > 0
            ? filterUrl([], ['proc_status']) . '&' . http_build_query(['proc_status' => $remainProcStatus])
            : filterUrl([], ['proc_status']);
    ?>
    <a href="<?= $removeUrlPs ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none">
      <?= h($psNome) ?> <span style="font-size:13px">×</span>
    </a>
    <?php endforeach; endif; endif; ?>
    <?php
    $chipResp = !empty($_GET['resp']) ? (array)$_GET['resp'] : [];
    if (!empty($chipResp)):
        if (count($chipResp) >= 3):
    ?>
    <a href="<?= filterUrl([], ['resp']) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none">
      Responsável (<?= count($chipResp) ?>) <span style="font-size:13px">×</span>
    </a>
    <?php else: foreach ($chipResp as $rVal):
        $rNome = array_values(array_filter($responsaveis, fn($r) => $r['tipo'] . ':' . $r['id'] === $rVal))[0]['nome'] ?? $rVal;
        $remainResp = array_values(array_filter($chipResp, fn($x) => $x !== $rVal));
        $removeUrlR = count($remainResp) > 0
            ? filterUrl([], ['resp']) . '&' . http_build_query(['resp' => $remainResp])
            : filterUrl([], ['resp']);
    ?>
    <a href="<?= $removeUrlR ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none">
      <?= h($rNome) ?> <span style="font-size:13px">×</span>
    </a>
    <?php endforeach; endif; endif; ?>
    <?php if (!empty($filtros['periodo_inicio']) || !empty($filtros['periodo_fim'])): ?>
    <a href="<?= filterUrl([], ['periodo_inicio','periodo_fim','periodo_preset']) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none">
      <?= fmtData($filtros['periodo_inicio'] ?? '') ?> – <?= fmtData($filtros['periodo_fim'] ?? '') ?> <span style="font-size:13px">×</span>
    </a>
    <?php elseif (!empty($_GET['periodo_preset'] ?? '')): ?>
    <?php
    $presetLabelsChip = [
        'esta-semana'       => 'Esta semana',
        'proximos-7'        => 'Próximos 7 dias',
        'este-mes'          => 'Este mês',
        'proximos-30'       => 'Próximos 30 dias',
        'proximos-60'       => 'Próximos 60 dias',
        'proximo-trimestre' => 'Próximo trimestre',
        'vencidos'          => 'Vencidos',
    ];
    $presetVal = $_GET['periodo_preset'] ?? '';
    $presetLabel = $presetLabelsChip[$presetVal] ?? h($presetVal);
    ?>
    <a href="<?= filterUrl([], ['periodo_preset','periodo_inicio','periodo_fim']) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;background:#FFE8E8;color:#2347C8;font-size:11px;font-weight:700;text-decoration:none">
      <?= h($presetLabel) ?> <span style="font-size:13px">×</span>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Tabela -->
  <div class="fin-table-wrap" id="tabela-wrap">

    <!-- Bulk bar (seleção em lote) -->
    <div id="bulk-bar" style="display:none;align-items:center;gap:12px;padding:10px 20px;background:#2347C8;color:#fff;font-size:12.5px;font-weight:500">
      <span id="bulk-info" style="flex:1"></span>
      <button id="bulk-btn-primary" onclick="bulkAction()"
              style="padding:6px 14px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;border:none;background:#fff;color:#2347C8">
        Agendar selecionadas
      </button>
      <button onclick="clearSelection()"
              style="padding:6px 14px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid rgba(255,255,255,.4);background:rgba(255,255,255,.15);color:#fff">
        Cancelar seleção
      </button>
    </div>

    <!-- Tabela -->
    <?php if (empty($parcelas)): ?>
    <?php
    $emptyIcon    = 'receipt';
    $emptyTitle   = 'Nenhuma parcela encontrada';
    $emptyMessage = 'Selecione outro período ou aguarde lançamentos.';
    $emptyAction  = null;
    include VIEWS . '/components/empty-state.php';
    ?>
    <?php else: ?>
    <table class="fin-table" id="rows-container">
      <thead>
        <tr>
          <th class="fin-th fin-th--chk">
            <input type="checkbox" id="chk-all" onchange="toggleAll(this)" style="width:14px;height:14px;cursor:pointer;accent-color:#2347C8">
          </th>
          <?php $urlOrdem = fn(string $o): string => filterUrl(['ordem' => $o, 'pagina' => 1]); ?>
          <?= sortableTh('Processo', 'proc', $ordem, $urlOrdem, 'fin-th') ?>
          <?= sortableTh('NF', 'nf', $ordem, $urlOrdem, 'fin-th') ?>
          <th class="fin-th">Fornecedor</th>
          <?= sortableTh('Depto.', 'depto', $ordem, $urlOrdem, 'fin-th') ?>
          <th class="fin-th">Status Proc.</th>
          <th class="fin-th fin-th--center">Etapa</th>
          <th class="fin-th">Tarefa Atual</th>
          <th class="fin-th">Responsável</th>
          <th class="fin-th fin-th--center">Parc.</th>
          <?= sortableTh('Vencimento', 'venc', $ordem, $urlOrdem, 'fin-th') ?>
          <th class="fin-th fin-th--center">SLA</th>
          <?= sortableTh('Valor NF', 'val', $ordem, $urlOrdem, 'fin-th fin-th--right', 'right') ?>
          <th class="fin-th">Agendamento</th>
          <th class="fin-th">Status Parc.</th>
          <th class="fin-th fin-th--center">Ação</th>
        </tr>
      </thead>
      <tbody>
    <?php
    $hoje = date('Y-m-d');
    foreach ($parcelas as $p):
        $rowClass = 'fin-tr';
        switch ($p['status']) {
            case 'vencido':  $rowClass .= ' fin-tr--vencido'; break;
            case 'agvenc':   $rowClass .= ' fin-tr--agvenc'; break;
            case 'pago':     $rowClass .= ' fin-tr--pago'; break;
            case 'cancelado':$rowClass .= ' fin-tr--cancelado'; break;
        }
        $canCheck = !in_array($p['status'], ['pago','cancelado'], true);
        $diffDias = '';
        if ($p['vencimento']) {
            $diff = (new DateTime($p['vencimento']))->diff(new DateTime($hoje));
            $isFuture = $p['vencimento'] >= $hoje;
            if (!$isFuture) {
                $diffDias = '<div style="font-size:11px;color:#DC2626;font-weight:600">' . $diff->days . 'd atraso</div>';
            } elseif ($diff->days <= 7) {
                $diffDias = '<div style="font-size:11px;color:#D97706;font-weight:600">' . $diff->days . 'd restantes</div>';
            } else {
                $diffDias = '<div style="font-size:11px;color:#94A3B8">em ' . $diff->days . 'd</div>';
            }
        }
    ?>
        <tr class="<?= $rowClass ?>" data-id="<?= h($p['id']) ?>" data-status="<?= h($p['status']) ?>" data-valor="<?= h($p['valor']) ?>"
            data-nf="<?= h($p['proc_codigo']) ?>" data-fornec="<?= h($p['fornecedor_nome'] ?? '') ?>" data-parc="<?= (int)$p['numero_parcela'] ?>/<?= (int)$p['total_parcelas'] ?>" data-venc="<?= h(fmtData($p['vencimento'])) ?>"
            data-agendamento="<?= h(fmtData($p['data_agendamento'])) ?>"
            onclick="openProcViewer('<?= h($p['processo_id']) ?>','<?= h($p['proc_codigo']) ?>','<?= h($p['fornecedor_nome'] ?? '') ?>')">

          <td class="fin-td fin-td--chk" onclick="event.stopPropagation()">
            <input type="checkbox" class="row-chk" data-id="<?= h($p['id']) ?>" data-status="<?= h($p['status']) ?>" data-valor="<?= h($p['valor']) ?>"
                   <?= $canCheck ? '' : 'disabled' ?>
                   style="width:14px;height:14px;cursor:<?= $canCheck ? 'pointer' : 'default' ?>;accent-color:#2347C8;opacity:<?= $canCheck ? '1' : '.3' ?>"
                   onchange="updateBulkBar()">
          </td>

          <td class="fin-td">
            <div style="font-size:12.5px;font-weight:700;color:<?= $p['status']==='vencido' ? '#DC2626' : ($p['status']==='agvenc' ? '#D97706' : '#2347C8') ?>;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($p['proc_codigo']) ?></div>
            <?php if ($p['is_teste']): ?><span style="font-size:9px;font-weight:800;background:#FEF3C7;color:#92400E;padding:1px 5px;border-radius:4px">TESTE</span><?php endif; ?>
          </td>

          <td class="fin-td">
            <div style="font-size:12px;color:#64748B;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($p['numero_nf'] ?? '—') ?></div>
          </td>

          <td class="fin-td">
            <div style="font-size:12.5px;font-weight:600;color:#1E293B;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($p['fornecedor_nome'] ?? '—') ?></div>
          </td>

          <td class="fin-td">
            <div style="font-size:12px;color:#64748B;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($p['setor_nome'] ?? '—') ?></div>
          </td>

          <td class="fin-td">
            <?php
            $procStatusMap = [
                'aberto'                  => ['bg' => '#E3F0FF', 'color' => '#1565C0', 'label' => 'Aberto'],
                'aprovado'                => ['bg' => '#D8F5E5', 'color' => '#00A960', 'label' => 'Finalizado'],
                'devolvido'               => ['bg' => '#FFF3E0', 'color' => '#FF8A2D', 'label' => 'Devolvido'],
                'cancelado'               => ['bg' => '#FFE8EE', 'color' => '#B3261E', 'label' => 'Cancelado'],
                'cancelamento_solicitado' => ['bg' => '#FFE8EE', 'color' => '#B3261E', 'label' => 'Canc. Solic.'],
            ];
            $ps = $procStatusMap[$p['proc_status'] ?? ''] ?? ['bg' => '#EAEAEA', 'color' => '#888', 'label' => $p['proc_status'] ?? '—'];
            ?>
            <span style="display:inline-block;padding:3px 8px;border-radius:8px;background:<?= $ps['bg'] ?>;color:<?= $ps['color'] ?>;font-size:10.5px;font-weight:700;white-space:nowrap"><?= h($ps['label']) ?></span>
          </td>

          <td class="fin-td" style="text-align:center">
            <?php if (in_array($p['proc_status'] ?? '', ['aberto','devolvido','cancelamento_solicitado'], true)): ?>
            <span style="font-size:12px;font-weight:600;color:#1E293B"><?= (int)($p['proc_etapa_atual'] ?? 1) ?>/<?= (int)($p['proc_total_etapas'] ?? 1) ?></span>
            <?php else: ?>
            <span style="font-size:11px;color:#94A3B8">—</span>
            <?php endif; ?>
          </td>

          <td class="fin-td">
            <?php if (in_array($p['proc_status'] ?? '', ['aberto','devolvido','cancelamento_solicitado'], true) && !empty($p['proc_etapa_nome'])): ?>
            <div style="font-size:11.5px;font-weight:600;color:#1E293B;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($p['proc_etapa_nome']) ?>"><?= h($p['proc_etapa_nome']) ?></div>
            <?php else: ?>
            <span style="font-size:11px;color:#94A3B8">—</span>
            <?php endif; ?>
          </td>

          <td class="fin-td">
            <?php if (in_array($p['proc_status'] ?? '', ['aberto','devolvido','cancelamento_solicitado'], true) && !empty($p['proc_responsavel_nome'])): ?>
            <div style="font-size:11.5px;font-weight:600;color:#1E293B;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($p['proc_responsavel_nome']) ?>">
              <?php if (($p['proc_tipo_aprovador'] ?? '') === 'grupo'): ?>
              <span style="font-size:9px;font-weight:700;background:#E3F0FF;color:#1565C0;padding:1px 4px;border-radius:3px;margin-right:2px">GRUPO</span>
              <?php endif; ?>
              <?= h($p['proc_responsavel_nome']) ?>
            </div>
            <?php else: ?>
            <span style="font-size:11px;color:#94A3B8">—</span>
            <?php endif; ?>
          </td>

          <td class="fin-td" style="text-align:center">
            <span style="display:inline-block;padding:3px 8px;border-radius:8px;background:#F1F5F9;font-size:11px;font-weight:600;color:#64748B"><?= (int)$p['numero_parcela'] ?>/<?= (int)$p['total_parcelas'] ?></span>
          </td>

          <td class="fin-td">
            <div style="font-size:12.5px;font-weight:500;color:#1E293B"><?= fmtData($p['vencimento']) ?></div>
          </td>

          <td class="fin-td" style="text-align:center">
            <?= $diffDias ?: '<span style="font-size:11px;color:#94A3B8">—</span>' ?>
          </td>

          <td class="fin-td" style="text-align:right">
            <span style="font-size:13px;font-weight:600;color:<?= $p['status']==='vencido' ? '#DC2626' : ($p['status'] === 'cancelado' ? '#94A3B8' : '#1E293B') ?>"><?= fmtMoeda((float)$p['valor']) ?></span>
          </td>

          <td class="fin-td">
            <?php if ($p['data_agendamento']): ?>
              <div style="font-size:12.5px;font-weight:600;color:<?= $p['status']==='agvenc' ? '#D97706' : '#2563EB' ?>"><?= fmtData($p['data_agendamento']) ?></div>
            <?php elseif (!in_array($p['status'], ['pago','cancelado'], true)): ?>
              <span style="font-size:11px;color:#D1D5DB">Não agendado</span>
            <?php else: ?>
              <span style="font-size:11px;color:#D1D5DB">—</span>
            <?php endif; ?>
          </td>

          <td class="fin-td">
            <?= parcelaStatusBadge($p['status']) ?>
          </td>

          <td class="fin-td" style="text-align:center" onclick="event.stopPropagation()">
            <?php if (in_array($p['status'], ['previsto','vencido'], true)): ?>
            <button onclick="openAgendarIndividual('<?= h($p['id']) ?>','<?= h($p['valor']) ?>')"
                    class="fin-action-btn fin-action-btn--agendar">Agendar</button>
            <?php elseif (in_array($p['status'], ['agendado','agvenc'], true)): ?>
            <button onclick="openPagarIndividual('<?= h($p['id']) ?>','<?= h($p['valor']) ?>')"
                    class="fin-action-btn fin-action-btn--pagar">Pagar</button>
            <?php elseif (in_array($p['status'], ['pago','cancelado'], true)): ?>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="2" stroke-linecap="round" style="display:inline-block"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            <?php endif; ?>
          </td>

        </tr>
    <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

  </div><!-- /tabela-wrap -->

  <!-- Rodapé / paginação -->
  <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-top:1px solid #F1F5F9;flex-wrap:wrap;gap:8px">
    <span style="font-size:12px;color:#94A3B8">
      <?= $total ?> resultado<?= $total !== 1 ? 's' : '' ?> — página <?= $pagina ?> de <?= $totalPgs ?>
    </span>
    <div style="display:flex;align-items:center;gap:10px">
      <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:#94A3B8">
        Itens por página:
        <select onchange="location.href=this.value"
                style="border:1.5px solid #E2E8F0;border-radius:7px;padding:3px 8px;font-size:12px;font-weight:600;color:#374151;background:#fff;cursor:pointer;outline:none">
          <?php foreach ([20,50] as $pp): ?>
          <option value="<?= filterUrl(['por_pagina'=>$pp,'pagina'=>1]) ?>" <?= $pp===$porPagina?'selected':''?>><?= $pp ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- Paginação -->
      <div style="display:flex;align-items:center;gap:3px">
        <?php if ($pagina > 1): ?>
        <a href="<?= filterUrl(['pagina'=>$pagina-1]) ?>" style="display:flex;align-items:center;justify-content:center;min-width:30px;height:30px;border:1.5px solid #E2E8F0;border-radius:7px;background:#fff;font-size:12px;color:#6B7280;text-decoration:none">&laquo;</a>
        <?php endif; ?>
        <?php
        $start = max(1, $pagina - 2);
        $end   = min($totalPgs, $pagina + 2);
        if ($start > 1) echo '<span style="font-size:12px;color:#94A3B8;padding:0 3px">…</span>';
        for ($i = $start; $i <= $end; $i++):
            $active = $i === $pagina ? 'background:#2347C8;border-color:#2347C8;color:#fff' : 'background:#fff;color:#6B7280';
        ?>
        <a href="<?= filterUrl(['pagina'=>$i]) ?>" style="display:flex;align-items:center;justify-content:center;min-width:30px;height:30px;border:1.5px solid #E2E8F0;border-radius:7px;font-size:12px;text-decoration:none;<?= $active ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($end < $totalPgs) echo '<span style="font-size:12px;color:#94A3B8;padding:0 3px">…</span>'; ?>
        <?php if ($pagina < $totalPgs): ?>
        <a href="<?= filterUrl(['pagina'=>$pagina+1]) ?>" style="display:flex;align-items:center;justify-content:center;min-width:30px;height:30px;border:1.5px solid #E2E8F0;border-radius:7px;background:#fff;font-size:12px;color:#6B7280;text-decoration:none">&raquo;</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /card principal -->


<!-- ═══════════════════════════════════════════════
     MODAIS
════════════════════════════════════════════════ -->

<!-- Modal Agendar (individual e lote) -->
<div id="modal-agendar-bg" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:100;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;width:410px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.18);animation:modalIn .18s">
    <form id="form-agendar" method="post" action="?module=financeiro&action=agendar">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" id="agendar-id">
      <div style="padding:20px 22px 16px;border-bottom:1px solid #F1F5F9;display:flex;justify-content:space-between;align-items:center">
        <h3 style="font-size:14px;font-weight:700;color:#111827;margin:0" id="modal-agendar-titulo">Agendar Parcela</h3>
        <button type="button" onclick="finCloseModal('modal-agendar-bg')" style="background:none;border:none;cursor:pointer;color:#94A3B8;padding:4px;border-radius:6px;display:flex">
          <i data-lucide="x" width="15" height="15"></i>
        </button>
      </div>
      <div style="padding:18px 22px">
        <div id="modal-agendar-info" style="background:#F8FAFC;border:1px solid #F1F5F9;border-radius:9px;padding:12px 14px;margin-bottom:14px;font-size:12.5px;color:#374151"></div>
        <div style="margin-bottom:12px">
          <label style="font-size:11.5px;font-weight:700;color:#374151;margin-bottom:5px;display:block">Data de agendamento <span style="color:#2347C8">*</span></label>
          <input type="date" name="data_agendamento" id="agendar-data" required
                 style="width:100%;padding:8px 12px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:12.5px;color:#111827;outline:none"
                 onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#E5E7EB'">
        </div>
        <div>
          <label style="font-size:11.5px;font-weight:700;color:#374151;margin-bottom:5px;display:block">Observação <em style="font-weight:400;color:#9CA3AF">(opcional)</em></label>
          <textarea name="observacao" id="agendar-obs" rows="3"
                    style="width:100%;padding:8px 12px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:12.5px;color:#111827;resize:vertical;outline:none;font-family:inherit"
                    onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#E5E7EB'"></textarea>
        </div>
      </div>
      <div style="padding:14px 22px;border-top:1px solid #F1F5F9;display:flex;justify-content:flex-end;gap:8px">
        <button type="button" onclick="finCloseModal('modal-agendar-bg')"
                style="padding:8px 16px;background:#fff;color:#374151;border:1.5px solid #E5E7EB;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer">Cancelar</button>
        <button type="submit"
                style="padding:8px 20px;background:#2347C8;color:#fff;border:none;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer">Confirmar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Pagar (individual e lote) -->
<div id="modal-pagar-bg" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:100;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;width:410px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.18);animation:modalIn .18s">
    <form id="form-pagar" method="post" action="?module=financeiro&action=pagar">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" id="pagar-id">
      <div style="padding:20px 22px 16px;border-bottom:1px solid #F1F5F9;display:flex;justify-content:space-between;align-items:center">
        <h3 style="font-size:14px;font-weight:700;color:#111827;margin:0" id="modal-pagar-titulo">Registrar Pagamento</h3>
        <button type="button" onclick="finCloseModal('modal-pagar-bg')" style="background:none;border:none;cursor:pointer;color:#94A3B8;padding:4px;border-radius:6px;display:flex">
          <i data-lucide="x" width="15" height="15"></i>
        </button>
      </div>
      <div style="padding:18px 22px">
        <div id="modal-pagar-info" style="background:#F8FAFC;border:1px solid #F1F5F9;border-radius:9px;padding:12px 14px;margin-bottom:14px;font-size:12.5px;color:#374151"></div>
        <div style="margin-bottom:12px">
          <label style="font-size:11.5px;font-weight:700;color:#374151;margin-bottom:5px;display:block">Data de pagamento <span style="color:#2347C8">*</span></label>
          <input type="date" name="data_pagamento" id="pagar-data" required
                 style="width:100%;padding:8px 12px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:12.5px;color:#111827;outline:none"
                 onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#E5E7EB'">
        </div>
        <div style="margin-bottom:12px">
          <label style="font-size:11.5px;font-weight:700;color:#374151;margin-bottom:5px;display:block">Nº comprovante <em style="font-weight:400;color:#9CA3AF">(opcional)</em></label>
          <input type="text" name="numero_comprovante" id="pagar-comp" placeholder="Ex: 2026052900143"
                 style="width:100%;padding:8px 12px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:12.5px;color:#111827;outline:none"
                 onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#E5E7EB'">
        </div>
        <div>
          <label style="font-size:11.5px;font-weight:700;color:#374151;margin-bottom:5px;display:block">Observação <em style="font-weight:400;color:#9CA3AF">(opcional)</em></label>
          <textarea name="observacao" id="pagar-obs" rows="3"
                    style="width:100%;padding:8px 12px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:12.5px;color:#111827;resize:vertical;outline:none;font-family:inherit"
                    onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#E5E7EB'"></textarea>
        </div>
      </div>
      <div style="padding:14px 22px;border-top:1px solid #F1F5F9;display:flex;justify-content:flex-end;gap:8px">
        <button type="button" onclick="finCloseModal('modal-pagar-bg')"
                style="padding:8px 16px;background:#fff;color:#374151;border:1.5px solid #E5E7EB;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer">Cancelar</button>
        <button type="submit"
                style="padding:8px 20px;background:#00A960;color:#fff;border:none;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer">Confirmar Pagamento</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════
     MODAL VISUALIZADOR DE PROCESSO (iframe)
════════════════════════════════════════════════ -->
<div id="proc-viewer-overlay" onclick="closeProcViewer(event)"
     style="position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:250;display:none;align-items:center;justify-content:center;padding:12px">
  <div onclick="event.stopPropagation()"
       style="background:#fff;border-radius:14px;width:96vw;max-width:1680px;height:96vh;display:flex;flex-direction:column;box-shadow:0 32px 80px rgba(0,0,0,.28);overflow:hidden">
    <div style="display:flex;align-items:center;gap:10px;padding:12px 18px;border-bottom:1px solid #E5E7EB;flex-shrink:0;background:#141922">
      <div>
        <div id="pv-id"  style="font-size:14px;font-weight:800;color:#fff;letter-spacing:-.2px">—</div>
        <div id="pv-sub" style="font-size:11.5px;color:#aaa;margin-top:1px">—</div>
      </div>
      <div style="flex:1"></div>
      <button id="pv-newtab-btn" onclick="openProcNovaGuia()"
              style="display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.08);color:#ccc">
        <i data-lucide="external-link" width="12" height="12"></i> Abrir em nova aba
      </button>
      <button onclick="closeProcViewer()"
              style="width:32px;height:32px;border-radius:7px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.08);cursor:pointer;color:#a5b4fc"
              onmouseover="this.style.background='#FEE2E2';this.style.color='#DC2626'" onmouseout="this.style.background='rgba(255,255,255,.08)';this.style.color='#a5b4fc'">
        <i data-lucide="x" width="14" height="14"></i>
      </button>
    </div>
    <iframe id="pv-iframe" src="about:blank" style="flex:1;border:none;width:100%"></iframe>
  </div>
</div>

<!-- Toast -->
<div id="toast" style="position:fixed;bottom:24px;right:24px;background:#1E293B;color:#F1F5F9;padding:11px 18px 11px 14px;border-radius:11px;font-size:12.5px;font-weight:600;display:flex;align-items:center;gap:9px;z-index:300;opacity:0;transform:translateY(8px);transition:opacity .24s,transform .24s;pointer-events:none;box-shadow:0 4px 20px rgba(0,0,0,.2)">
  <span id="toast-icon" style="width:20px;height:20px;border-radius:50%;background:#4ADE80;color:#14532D;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:12px">✓</span>
  <span id="toast-msg"></span>
</div>

<style>
@keyframes modalIn { from { transform:translateY(10px);opacity:0; } to { transform:none;opacity:1; } }
@keyframes spin    { to { transform:rotate(360deg); } }
.fin-tr:hover .ag-action { opacity: 1 !important; }

#proc-viewer-overlay.open { display: flex !important; }
</style>

<script>
// ─── Escape helper (previne XSS ao injetar dados do banco no innerHTML) ───
function esc(s) {
    const d = document.createElement('div');
    d.textContent = String(s ?? '');
    return d.innerHTML;
}

// ─── Dropdown helpers ──────────────────────────────────────
function toggleDrop(dropId, wrapId) {
    const d = document.getElementById(dropId);
    const w = document.getElementById(wrapId);
    const isOpen = d.style.display !== 'none';
    // fechar todos
    document.querySelectorAll('.fp-dd').forEach(el => el.style.display = 'none');
    d.style.display = isOpen ? 'none' : 'block';
    d.classList.add('fp-dd');
}
document.addEventListener('click', e => {
    if (!e.target.closest('#period-wrap') && !e.target.closest('#period-drop')) {
        const d = document.getElementById('period-drop');
        if (d) d.style.display = 'none';
    }
    if (!e.target.closest('#setor-wrap')) {
        const d = document.getElementById('setor-drop');
        if (d) d.style.display = 'none';
    }
    if (!e.target.closest('#procstatus-wrap')) {
        const d = document.getElementById('procstatus-drop');
        if (d) d.style.display = 'none';
    }
});

// ─── Período preset ───────────────────────────────────────
function selectPreset(key) {
    document.getElementById('periodo_preset').value = key;
    document.getElementById('periodo_inicio').value = '';
    document.getElementById('periodo_fim').value = '';
    document.getElementById('filtro-form').submit();
}
function applyCustomPeriod() {
    const from = document.getElementById('cp-from').value;
    const to   = document.getElementById('cp-to').value;
    if (!from && !to) return;
    document.getElementById('periodo_inicio').value = from;
    document.getElementById('periodo_fim').value    = to;
    document.getElementById('periodo_preset').value = '';
    document.getElementById('filtro-form').submit();
}

// ─── Bulk selection ───────────────────────────────────────
function updateBulkBar() {
    const checked = document.querySelectorAll('.row-chk:checked');
    const bar     = document.getElementById('bulk-bar');
    if (checked.length === 0) { bar.style.display = 'none'; return; }

    bar.style.display = 'flex';
    let total = 0;
    let hasAgendavel = false, allPagavel = true;
    checked.forEach(c => {
        total += parseFloat(c.dataset.valor) || 0;
        if (['previsto','vencido'].includes(c.dataset.status)) hasAgendavel = true;
        if (!['agendado','agvenc'].includes(c.dataset.status)) allPagavel = false;
    });

    const fmt = v => 'R$ ' + v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    document.getElementById('bulk-info').innerHTML =
        '<strong>' + checked.length + ' parcela(s) selecionada(s)</strong> — ' + fmt(total);

    const btn = document.getElementById('bulk-btn-primary');
    if (!hasAgendavel && allPagavel) {
        btn.textContent = 'Marcar como pagas';
        btn.dataset.mode = 'pagar';
    } else {
        btn.textContent = 'Agendar selecionadas';
        btn.dataset.mode = 'agendar';
    }
}

function toggleAll(master) {
    document.querySelectorAll('.row-chk:not(:disabled)').forEach(c => { c.checked = master.checked; });
    updateBulkBar();
}

function clearSelection() {
    document.querySelectorAll('.row-chk').forEach(c => c.checked = false);
    document.getElementById('chk-all').checked = false;
    document.getElementById('bulk-bar').style.display = 'none';
}

function bulkAction() {
    const btn  = document.getElementById('bulk-btn-primary');
    const mode = btn.dataset.mode;
    const ids  = Array.from(document.querySelectorAll('.row-chk:checked')).map(c => c.dataset.id);
    if (!ids.length) return;

    // calcular total para exibir no modal
    let total = 0;
    document.querySelectorAll('.row-chk:checked').forEach(c => total += parseFloat(c.dataset.valor)||0);
    const fmt = v => 'R$ ' + v.toFixed(2).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.');

    if (mode === 'agendar') {
        // Preparar modal agendar lote
        document.getElementById('form-agendar').action = '?module=financeiro&action=agendar_lote';
        document.getElementById('agendar-id').name = ''; // não usar campo id simples
        // Limpar inputs extras antigos
        document.querySelectorAll('#form-agendar .bulk-id-field').forEach(e => e.remove());
        ids.forEach(id => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
            inp.className = 'bulk-id-field';
            document.getElementById('form-agendar').appendChild(inp);
        });
        document.getElementById('modal-agendar-titulo').textContent = 'Agendar em Lote';
        document.getElementById('modal-agendar-info').innerHTML =
            '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span>Parcelas:</span><strong>' + ids.length + '</strong></div>' +
            '<div style="display:flex;justify-content:space-between"><span>Total:</span><strong>' + fmt(total) + '</strong></div>';
        finOpenModal('modal-agendar-bg');
    } else {
        document.getElementById('form-pagar').action = '?module=financeiro&action=pagar_lote';
        document.getElementById('pagar-id').name = '';
        document.querySelectorAll('#form-pagar .bulk-id-field').forEach(e => e.remove());
        ids.forEach(id => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
            inp.className = 'bulk-id-field';
            document.getElementById('form-pagar').appendChild(inp);
        });
        document.getElementById('modal-pagar-titulo').textContent = 'Registrar Pagamento em Lote';
        document.getElementById('modal-pagar-info').innerHTML =
            '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span>Parcelas:</span><strong>' + ids.length + '</strong></div>' +
            '<div style="display:flex;justify-content:space-between"><span>Total:</span><strong>' + fmt(total) + '</strong></div>';
        document.getElementById('pagar-comp').value = '';
        document.getElementById('pagar-obs').value  = '';
        finOpenModal('modal-pagar-bg');
    }
}

// ─── Modais financeiros (nomes únicos — app.js sobrescreve openModal/closeModal) ───
function finOpenModal(id) {
    const el = document.getElementById(id);
    if (el) { el.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}
function finCloseModal(id) {
    const el = document.getElementById(id);
    if (el) { el.style.display = 'none'; document.body.style.overflow = ''; }
}

function openAgendarIndividual(id, valor) {
    const fmt = v => 'R$ ' + parseFloat(v).toFixed(2).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.');
    document.getElementById('form-agendar').action = '?module=financeiro&action=agendar';
    document.querySelectorAll('#form-agendar .bulk-id-field').forEach(e => e.remove());
    document.getElementById('agendar-id').name  = 'id';
    document.getElementById('agendar-id').value = id;
    document.getElementById('modal-agendar-titulo').textContent = 'Agendar Parcela';
    // Busca dados extras da linha da tabela
    const row = document.querySelector(`.fin-tr[data-id="${id}"]`);
    const nf     = row ? (row.dataset.nf     || '—') : '—';
    const fornec  = row ? (row.dataset.fornec  || '—') : '—';
    const parc   = row ? (row.dataset.parc   || '—') : '—';
    const venc   = row ? (row.dataset.venc   || '—') : '—';
    document.getElementById('modal-agendar-info').innerHTML =
        `<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="color:#6B7280">NF</span><strong>${esc(nf)}</strong></div>` +
        `<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="color:#6B7280">Fornecedor</span><strong>${esc(fornec)}</strong></div>` +
        `<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="color:#6B7280">Parcela</span><strong>${esc(parc)}</strong></div>` +
        `<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="color:#6B7280">Valor</span><strong>${fmt(valor)}</strong></div>` +
        `<div style="display:flex;justify-content:space-between"><span style="color:#6B7280">Vencimento</span><strong>${esc(venc)}</strong></div>`;
    document.getElementById('agendar-data').value = '';
    document.getElementById('agendar-obs').value  = '';
    finOpenModal('modal-agendar-bg');
}

function openPagarIndividual(id, valor) {
    const fmt = v => 'R$ ' + parseFloat(v).toFixed(2).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.');
    document.getElementById('form-pagar').action = '?module=financeiro&action=pagar';
    document.querySelectorAll('#form-pagar .bulk-id-field').forEach(e => e.remove());
    document.getElementById('pagar-id').name  = 'id';
    document.getElementById('pagar-id').value = id;
    document.getElementById('modal-pagar-titulo').textContent = 'Registrar Pagamento';
    const row = document.querySelector(`.fin-tr[data-id="${id}"]`);
    const nf    = row ? (row.dataset.nf    || '—') : '—';
    const fornec = row ? (row.dataset.fornec || '—') : '—';
    const agend = row ? (row.dataset.agendamento || '—') : '—';
    document.getElementById('modal-pagar-info').innerHTML =
        `<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="color:#6B7280">NF</span><strong>${esc(nf)}</strong></div>` +
        `<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="color:#6B7280">Fornecedor</span><strong>${esc(fornec)}</strong></div>` +
        `<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="color:#6B7280">Valor</span><strong>${fmt(valor)}</strong></div>` +
        `<div style="display:flex;justify-content:space-between"><span style="color:#6B7280">Agendado para</span><strong>${esc(agend)}</strong></div>`;
    document.getElementById('pagar-data').value = new Date().toISOString().split('T')[0];
    document.getElementById('pagar-comp').value = '';
    document.getElementById('pagar-obs').value  = '';
    finOpenModal('modal-pagar-bg');
}

// ─── Proc Viewer (modal iframe) ───────────────────────
let currentProcId = null;
function openProcViewer(processoId, codigo, fornec) {
    currentProcId = processoId;
    document.getElementById('pv-id').textContent  = codigo  || '—';
    document.getElementById('pv-sub').textContent = fornec  || '—';
    document.getElementById('pv-iframe').src = '?module=processos&action=detalhe&id=' + encodeURIComponent(processoId) + '&embed=1';
    document.getElementById('proc-viewer-overlay').classList.add('open');
    if (window.lucide) lucide.createIcons();
}
function closeProcViewer(e) {
    if (e && e.target !== document.getElementById('proc-viewer-overlay')) return;
    document.getElementById('proc-viewer-overlay').classList.remove('open');
    document.getElementById('pv-iframe').src = 'about:blank';
}
function openProcNovaGuia() {
    if (!currentProcId) return;
    var voltar = encodeURIComponent(location.pathname + location.search);
    window.open('?module=processos&action=detalhe&id=' + encodeURIComponent(currentProcId) + '&origem=financeiro&voltar=' + voltar, '_blank');
}

// ─── Toast ────────────────────────────────────────────────
function showToast(msg, ok = true) {
    const t  = document.getElementById('toast');
    const ic = document.getElementById('toast-icon');
    const m  = document.getElementById('toast-msg');
    ic.style.background = ok ? '#4ADE80' : '#F87171';
    ic.textContent = ok ? '✓' : '✕';
    m.textContent  = msg;
    t.style.opacity = '1'; t.style.transform = 'translateY(0)'; t.style.pointerEvents = 'auto';
    setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateY(8px)'; t.style.pointerEvents = 'none'; }, 3000);
}

// ─── ESC fecha modais e painéis ───────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    finCloseModal('modal-agendar-bg');
    finCloseModal('modal-pagar-bg');
    closeProcViewer();
});
</script>
