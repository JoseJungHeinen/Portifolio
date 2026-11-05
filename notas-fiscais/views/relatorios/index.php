<?php
// @versao Rel_01 V_04_12/08/2026
/**
 * views/relatorios/index.php
 * Relatórios gerenciais — 3 abas, 9 relatórios colapsáveis
 *
 * Variáveis do ReportController:
 *   $aba       string   'financeiro', 'operacional' ou 'meus'
 *   $filtros   array    filtros ativos
 *   $dados     array    dados de todos os relatórios da aba ativa
 *   $dropdowns array    filiais, setores, tipos_nota, grupos, empresas
 */

// Helpers reutilizados
function rptMoeda(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}

function rptPeriodoLabel(array $filtros): string {
    $preset = $filtros['periodo_preset'] ?? 'este-mes';
    return match($preset) {
        'esta-semana'    => 'Esta semana',
        'este-mes'       => 'Este mês',
        'este-trimestre' => 'Este trimestre',
        'personalizado'  => (!empty($filtros['periodo_inicio']) && !empty($filtros['periodo_fim']))
            ? DateHelper::formatBr($filtros['periodo_inicio']) . ' – ' . DateHelper::formatBr($filtros['periodo_fim'])
            : 'Período personalizado',
        default => 'Este mês',
    };
}

// URL para filtros dos relatórios
function rptUrl(string $aba, string $relatorio, array $extra = []): string {
    return '?' . http_build_query(array_merge(
        ['module' => 'relatorios', 'action' => 'index', 'aba' => $aba, 'relatorio' => $relatorio],
        $extra
    ));
}

// URL de exportação
function rptExportUrl(string $relatorio, string $formato, array $filtros): string {
    $params = array_merge(['module'=>'relatorios','action'=>'exportar','relatorio'=>$relatorio,'formato'=>$formato], $filtros);
    return '?' . http_build_query($params);
}

// Opções do menu Exportar — os três formatos andam juntos: quem acessa o
// relatório exporta em qualquer um deles.
function rptExportOpcoes(string $relatorio, array $filtros): void {
    $formatos = [
        'xlsx' => ['file-spreadsheet', 'Excel (XLSX)'],
        'csv'  => ['file-text',        'CSV'],
        'pdf'  => ['file-type-2',      'PDF'],
    ];
    foreach ($formatos as $fmt => [$icone, $rotulo]) {
        echo '<a class="export-option" href="' . h(rptExportUrl($relatorio, $fmt, $filtros)) . '">'
           . '<i data-lucide="' . $icone . '" width="14" height="14"></i> ' . h($rotulo) . '</a>';
    }
}

/** O relatório está liberado para o usuário logado? */
function rptPode(string $relatorio): bool {
    return ReportAccess::pode($relatorio);
}

$periodoLabel = rptPeriodoLabel($filtros);
?>

<?php include VIEWS . '/components/alert.php'; ?>

<!-- PAGE TABS — só as abas com ao menos um relatório liberado -->
<?php $rptIcones = ['financeiro' => 'dollar-sign', 'operacional' => 'activity', 'meus' => 'user-check']; ?>
<div class="page-tabs">
  <?php foreach ($abasPermitidas as $abaChave => $abaRotulo): ?>
  <a href="?module=relatorios&action=index&aba=<?= h($abaChave) ?>"
     class="page-tab<?= $aba === $abaChave ? ' active' : '' ?>">
    <i data-lucide="<?= h($rptIcones[$abaChave] ?? 'file-text') ?>" width="14" height="14"></i>
    <?= h($abaRotulo) ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="rpt-content">

<?php if (empty($abasPermitidas)): ?>
<?php
$emptyIcon    = 'lock';
$emptyTitle   = 'Nenhum relatório liberado';
$emptyMessage = 'Seu acesso não inclui nenhum relatório. Fale com o administrador em Controle de Acesso.';
include VIEWS . '/components/empty-state.php';
?>
<?php endif; ?>

<?php if ($abasPermitidas && $aba === 'financeiro'): ?>
<!-- ============================================================ -->
<!-- ABA: FINANCEIRO -->
<!-- ============================================================ -->

<h1 style="font-family:var(--font-bold);font-size:22px;color:var(--color-dark);margin-bottom:20px">
  Relatórios <span style="color:var(--color-primary)">financeiros</span>
</h1>

<?php if (rptPode('fluxo_caixa')): ?>
<?php
// ─── Relatório 1: Fluxo de caixa previsto ────────────────────────
$rFluxo = $dados['fluxo_caixa'];
$kFluxo = $rFluxo['kpis'];
$tFluxo = $rFluxo['tabela'];
?>
<div class="report-panel open" id="rpt-fluxo">
  <div class="report-panel-header" onclick="rptToggle('fluxo')">
    <div class="report-panel-icon green"><i data-lucide="trending-up" width="18" height="18"></i></div>
    <div class="report-panel-titles">
      <div class="report-panel-title">Fluxo de caixa previsto</div>
      <div class="report-panel-sub">Parcelas futuras agrupadas por semana</div>
    </div>
    <div class="report-panel-actions">
      <div class="export-wrap" id="exp-fluxo" onclick="event.stopPropagation()">
        <button class="export-btn" onclick="rptToggleExport('fluxo')">
          <i data-lucide="download" width="13" height="13"></i> Exportar
          <i data-lucide="chevron-down" width="11" height="11"></i>
        </button>
        <div class="export-dropdown" id="exp-dd-fluxo">
          <?php rptExportOpcoes('fluxo_caixa', $filtros); ?>
        </div>
      </div>
      <i data-lucide="chevron-down" width="16" height="16" class="report-chevron"></i>
    </div>
  </div>
  <div class="report-body">
    <!-- Filtros -->
    <form method="get" action="" class="filter-bar">
      <input type="hidden" name="module" value="relatorios">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="financeiro">
      <input type="hidden" name="relatorio" value="fluxo_caixa">
      <?php rptFiltrosPeriodo('fluxo', $filtros); ?>
      <?php rptFiltroLojas('loja', $dropdowns['filiais'], $filtros); ?>
      <?php rptFiltroSelect('setor', 'setor_id', $dropdowns['setores'], $filtros, 'Departamento'); ?>
      <input type="hidden" name="periodo_preset" id="inp-periodo_preset-fluxo" value="<?= h($filtros['periodo_preset'] ?? 'este-mes') ?>">
      <input type="hidden" name="periodo_inicio" id="inp-periodo_inicio-fluxo" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="periodo_fim"    id="inp-periodo_fim-fluxo"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">
      <button type="submit" class="btn-gerar">
        <i data-lucide="bar-chart-2" width="13" height="13"></i> Gerar relatório
      </button>
    </form>
    <!-- KPI strip -->
    <div class="kpi-strip">
      <div class="kpi-strip-item"><div class="kpi-strip-label">Total previsto</div><div class="kpi-strip-val"><?= rptMoeda($kFluxo['total_previsto']) ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Agendado</div><div class="kpi-strip-val green"><?= rptMoeda($kFluxo['agendado']) ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Não agendado</div><div class="kpi-strip-val amber"><?= rptMoeda($kFluxo['nao_agendado']) ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Qtd. parcelas</div><div class="kpi-strip-val"><?= $kFluxo['qtd_parcelas'] ?></div></div>
    </div>
    <!-- Tabela -->
    <div class="tbl-wrap">
      <table class="data-table">
        <thead><tr><th>Semana</th><th>Parcelas</th><th>Valor previsto</th><th>Valor agendado</th><th>Não agendado</th></tr></thead>
        <tbody>
          <?php if (empty($tFluxo)): ?>
          <tr><td colspan="5" class="empty-state">Sem parcelas no período selecionado.</td></tr>
          <?php else: foreach ($tFluxo as $r): ?>
          <tr>
            <td>Sem. de <?= $r['semana_ini'] ? DateHelper::formatBr(substr($r['semana_ini'], 0, 10)) : '—' ?></td>
            <td><?= (int)$r['parcelas'] ?></td>
            <td><?= rptMoeda((float)$r['valor_previsto']) ?></td>
            <td style="color:var(--color-success)"><?= rptMoeda((float)$r['valor_agendado']) ?></td>
            <td style="color:var(--color-warning)"><?= rptMoeda((float)$r['nao_agendado']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <?php if (!empty($tFluxo)): ?>
        <tfoot style="background:#f9f9f9">
          <tr>
            <td style="font-family:var(--font-bold)">Total</td>
            <td style="font-family:var(--font-bold)"><?= array_sum(array_column($tFluxo, 'parcelas')) ?></td>
            <td style="font-family:var(--font-bold)"><?= rptMoeda(array_sum(array_column($tFluxo, 'valor_previsto'))) ?></td>
            <td style="font-family:var(--font-bold);color:var(--color-success)"><?= rptMoeda(array_sum(array_column($tFluxo, 'valor_agendado'))) ?></td>
            <td style="font-family:var(--font-bold);color:var(--color-warning)"><?= rptMoeda(array_sum(array_column($tFluxo, 'nao_agendado'))) ?></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (rptPode('pagamentos')): ?>
<?php
// ─── Relatório 2: Pagamentos realizados ──────────────────────────
$rPgto = $dados['pagamentos'];
$kPgto = $rPgto['kpis'];
$tPgto = $rPgto['tabela'];
$agruparPorAtual = $filtros['agrupar_por'] ?? 'fornecedor';
?>
<div class="report-panel" id="rpt-pagamentos">
  <div class="report-panel-header" onclick="rptToggle('pagamentos')">
    <div class="report-panel-icon green"><i data-lucide="check-circle" width="18" height="18"></i></div>
    <div class="report-panel-titles">
      <div class="report-panel-title">Pagamentos realizados</div>
      <div class="report-panel-sub">Parcelas pagas no período com totais</div>
    </div>
    <div class="report-panel-actions">
      <div class="export-wrap" id="exp-pagamentos" onclick="event.stopPropagation()">
        <button class="export-btn" onclick="rptToggleExport('pagamentos')">
          <i data-lucide="download" width="13" height="13"></i> Exportar
          <i data-lucide="chevron-down" width="11" height="11"></i>
        </button>
        <div class="export-dropdown" id="exp-dd-pagamentos">
          <?php rptExportOpcoes('pagamentos', $filtros); ?>
        </div>
      </div>
      <i data-lucide="chevron-down" width="16" height="16" class="report-chevron"></i>
    </div>
  </div>
  <div class="report-body">
    <form method="get" action="" class="filter-bar">
      <input type="hidden" name="module" value="relatorios">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="financeiro">
      <div class="filter-search">
        <i data-lucide="search" width="14" height="14" style="color:var(--color-gray-medium)"></i>
        <input type="text" name="busca" value="<?= h($filtros['busca'] ?? '') ?>" placeholder="Buscar...">
      </div>
      <?php rptFiltrosPeriodo('pgto', $filtros); ?>
      <select name="agrupar_por" style="padding:7px 10px;border:1.5px solid var(--color-gray-light);border-radius:6px;font-family:var(--font-bold);font-size:12px;color:var(--color-gray-dark);outline:none">
        <?php foreach (['fornecedor'=>'Por Fornecedor','loja'=>'Por Loja','departamento'=>'Por Departamento'] as $v=>$l): ?>
        <option value="<?= $v ?>"<?= $agruparPorAtual === $v ? ' selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="periodo_preset" id="inp-periodo_preset-pgto" value="<?= h($filtros['periodo_preset'] ?? 'este-mes') ?>">
      <input type="hidden" name="periodo_inicio" id="inp-periodo_inicio-pgto" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="periodo_fim"    id="inp-periodo_fim-pgto"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">
      <button type="submit" class="btn-gerar"><i data-lucide="bar-chart-2" width="13" height="13"></i> Gerar relatório</button>
    </form>
    <div class="kpi-strip">
      <div class="kpi-strip-item"><div class="kpi-strip-label">Total pago</div><div class="kpi-strip-val green"><?= rptMoeda($kPgto['total']) ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Qtd. pagamentos</div><div class="kpi-strip-val"><?= $kPgto['qtd'] ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Ticket médio</div><div class="kpi-strip-val"><?= rptMoeda($kPgto['ticket_medio']) ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Maior pagamento</div><div class="kpi-strip-val amber"><?= rptMoeda($kPgto['maior']) ?></div></div>
    </div>
    <div class="tbl-wrap">
      <table class="data-table">
        <thead><tr><th>Grupo</th><th>Pagamentos</th><th>Total pago</th><th>% do total</th><th>Participação</th></tr></thead>
        <tbody>
          <?php if (empty($tPgto)): ?>
          <tr><td colspan="5" class="empty-state">Nenhum pagamento no período.</td></tr>
          <?php else: foreach ($tPgto as $r): ?>
          <tr>
            <td><?= h($r['grupo']) ?></td>
            <td><?= (int)$r['pagamentos'] ?></td>
            <td style="color:var(--color-success);font-family:var(--font-bold)"><?= rptMoeda((float)$r['total_pago']) ?></td>
            <td><?= $r['pct'] ?>%</td>
            <td>
              <div class="part-bar-wrap">
                <div class="part-bar"><div class="part-bar-fill" style="width:<?= min(100, (float)$r['pct']) ?>%"></div></div>
                <span style="font-size:11px;color:var(--color-gray-medium)"><?= $r['pct'] ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (rptPode('contas_pagar')): ?>
<?php
// ─── Relatório 3: Contas a pagar ─────────────────────────────────
$rCp = $dados['contas_pagar'];
$kCp = $rCp['kpis'];
$tCp = $rCp['tabela'];
?>
<div class="report-panel" id="rpt-contas">
  <div class="report-panel-header" onclick="rptToggle('contas')">
    <div class="report-panel-icon amber"><i data-lucide="alert-circle" width="18" height="18"></i></div>
    <div class="report-panel-titles">
      <div class="report-panel-title">Contas a pagar</div>
      <div class="report-panel-sub">Parcelas vencidas e a vencer com aging</div>
    </div>
    <div class="report-panel-actions">
      <div class="export-wrap" id="exp-contas" onclick="event.stopPropagation()">
        <button class="export-btn" onclick="rptToggleExport('contas')">
          <i data-lucide="download" width="13" height="13"></i> Exportar <i data-lucide="chevron-down" width="11" height="11"></i>
        </button>
        <div class="export-dropdown" id="exp-dd-contas">
          <?php rptExportOpcoes('contas_pagar', $filtros); ?>
        </div>
      </div>
      <i data-lucide="chevron-down" width="16" height="16" class="report-chevron"></i>
    </div>
  </div>
  <div class="report-body">
    <form method="get" action="" class="filter-bar">
      <input type="hidden" name="module" value="relatorios">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="financeiro">
      <div class="filter-search">
        <i data-lucide="search" width="14" height="14" style="color:var(--color-gray-medium)"></i>
        <input type="text" name="busca" value="<?= h($filtros['busca'] ?? '') ?>" placeholder="Buscar...">
      </div>
      <!-- Situação filter -->
      <select name="situacao" style="padding:7px 10px;border:1.5px solid var(--color-gray-light);border-radius:6px;font-family:var(--font-bold);font-size:12px;color:var(--color-gray-dark);outline:none">
        <option value="">Todas as situações</option>
        <option value="vencido"<?= ($filtros['situacao']??'') === 'vencido' ? ' selected' : '' ?>>Vencidos</option>
        <option value="vence-hoje"<?= ($filtros['situacao']??'') === 'vence-hoje' ? ' selected' : '' ?>>Vence hoje</option>
        <option value="a-vencer"<?= ($filtros['situacao']??'') === 'a-vencer' ? ' selected' : '' ?>>A vencer</option>
      </select>
      <?php rptFiltrosPeriodo('cp', $filtros); ?>
      <?php rptFiltroLojas('loja-cp', $dropdowns['filiais'], $filtros); ?>
      <?php rptFiltroSelect('setor-cp', 'setor_id', $dropdowns['setores'], $filtros, 'Departamento'); ?>
      <input type="hidden" name="periodo_preset" id="inp-periodo_preset-cp" value="<?= h($filtros['periodo_preset'] ?? 'este-mes') ?>">
      <input type="hidden" name="periodo_inicio" id="inp-periodo_inicio-cp" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="periodo_fim"    id="inp-periodo_fim-cp"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">
      <button type="submit" class="btn-gerar"><i data-lucide="bar-chart-2" width="13" height="13"></i> Gerar relatório</button>
    </form>
    <div class="kpi-strip">
      <div class="kpi-strip-item"><div class="kpi-strip-label">Total em aberto</div><div class="kpi-strip-val amber"><?= rptMoeda($kCp['total']) ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Vencidos</div><div class="kpi-strip-val red"><?= rptMoeda($kCp['vencido']) ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">A vencer</div><div class="kpi-strip-val blue"><?= rptMoeda($kCp['a_vencer']) ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Qtd. parcelas</div><div class="kpi-strip-val"><?= $kCp['qtd'] ?></div></div>
    </div>
    <div class="tbl-wrap">
      <table class="data-table">
        <thead><tr><th>Processo</th><th>Fornecedor</th><th>Loja</th><th>Valor</th><th>Vencimento</th><th>Situação</th></tr></thead>
        <tbody>
          <?php if (empty($tCp)): ?>
          <tr><td colspan="6" class="empty-state">Nenhuma conta a pagar no período.</td></tr>
          <?php else: foreach ($tCp as $r):
            $sit = $r['situacao_calc'];
            $rowCls = $sit === 'vencido' ? 'row-atrasado' : ($sit === 'vence-hoje' ? 'row-warn' : '');
          ?>
          <tr class="<?= $rowCls ?>">
            <td><a class="link-cell" href="<?= h(linkDetalheProcesso($r['processo_id'], 'relatorios')) ?>"><?= h($r['proc_codigo']) ?></a></td>
            <td><?= h($r['fornecedor'] ?? '—') ?></td>
            <td><?= h($r['loja'] ?? '—') ?></td>
            <td style="font-family:var(--font-bold)"><?= rptMoeda((float)$r['valor']) ?></td>
            <td style="<?= $sit === 'vencido' ? 'color:var(--color-danger);font-family:var(--font-bold)' : ($sit === 'vence-hoje' ? 'color:var(--color-warning);font-family:var(--font-bold)' : '') ?>">
              <?= DateHelper::formatBr($r['vencimento']) ?>
            </td>
            <td><?= match($sit) {
              'vencido'    => '<span class="badge badge-danger">Vencido +' . (int)$r['dias_atraso'] . 'd</span>',
              'vence-hoje' => '<span class="badge badge-warning">Vence hoje</span>',
              default      => '<span class="badge badge-muted">A vencer</span>',
            }; ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (rptPode('gastos_grupo')): ?>
<?php
// ─── Relatório 4: Gastos por loja/departamento ───────────────────
$tGastos = $dados['gastos_grupo']['tabela'];
$agruparGastos = $filtros['agrupar_por'] ?? 'loja';
?>
<div class="report-panel" id="rpt-gastos">
  <div class="report-panel-header" onclick="rptToggle('gastos')">
    <div class="report-panel-icon blue"><i data-lucide="building-2" width="18" height="18"></i></div>
    <div class="report-panel-titles">
      <div class="report-panel-title">Gastos por loja / departamento</div>
      <div class="report-panel-sub">Tabela cruzada de gastos com totais</div>
    </div>
    <div class="report-panel-actions">
      <div class="export-wrap" id="exp-gastos" onclick="event.stopPropagation()">
        <button class="export-btn" onclick="rptToggleExport('gastos')">
          <i data-lucide="download" width="13" height="13"></i> Exportar <i data-lucide="chevron-down" width="11" height="11"></i>
        </button>
        <div class="export-dropdown" id="exp-dd-gastos">
          <?php rptExportOpcoes('gastos_grupo', $filtros); ?>
        </div>
      </div>
      <i data-lucide="chevron-down" width="16" height="16" class="report-chevron"></i>
    </div>
  </div>
  <div class="report-body">
    <form method="get" action="" class="filter-bar">
      <input type="hidden" name="module" value="relatorios">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="financeiro">
      <?php rptFiltrosPeriodo('gastos', $filtros); ?>
      <select name="agrupar_por" style="padding:7px 10px;border:1.5px solid var(--color-gray-light);border-radius:6px;font-family:var(--font-bold);font-size:12px;color:var(--color-gray-dark);outline:none">
        <?php foreach (['loja'=>'Por Loja','departamento'=>'Por Departamento'] as $v=>$l): ?>
        <option value="<?= $v ?>"<?= $agruparGastos === $v ? ' selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="periodo_preset" id="inp-periodo_preset-gastos" value="<?= h($filtros['periodo_preset'] ?? 'este-mes') ?>">
      <input type="hidden" name="periodo_inicio" id="inp-periodo_inicio-gastos" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="periodo_fim"    id="inp-periodo_fim-gastos"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">
      <button type="submit" class="btn-gerar"><i data-lucide="bar-chart-2" width="13" height="13"></i> Gerar relatório</button>
    </form>
    <div class="tbl-wrap">
      <table class="data-table">
        <thead><tr><th>Grupo</th><th>Processos</th><th>Total pago</th><th>Em aberto</th><th>Total movimentado</th><th>% do total</th><th>Participação</th></tr></thead>
        <tbody>
          <?php if (empty($tGastos)): ?>
          <tr><td colspan="7" class="empty-state">Sem dados no período.</td></tr>
          <?php else: foreach ($tGastos as $r): ?>
          <tr>
            <td><?= h($r['grupo']) ?></td>
            <td><?= (int)$r['processos'] ?></td>
            <td style="color:var(--color-success);font-family:var(--font-bold)"><?= rptMoeda((float)$r['total_pago']) ?></td>
            <td style="color:var(--color-warning)"><?= rptMoeda((float)$r['em_aberto']) ?></td>
            <td style="font-family:var(--font-bold)"><?= rptMoeda((float)$r['total_movimentado']) ?></td>
            <td><?= $r['pct'] ?>%</td>
            <td>
              <div class="part-bar-wrap">
                <div class="part-bar"><div class="part-bar-fill" style="width:<?= min(100, (float)$r['pct']) ?>%"></div></div>
                <span style="font-size:11px;color:var(--color-gray-medium)"><?= $r['pct'] ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php elseif ($aba === 'operacional'): ?>
<!-- ============================================================ -->
<!-- ABA: OPERACIONAL -->
<!-- ============================================================ -->

<h1 style="font-family:var(--font-bold);font-size:22px;color:var(--color-dark);margin-bottom:20px">
  Relatórios <span style="color:var(--color-primary)">operacionais</span>
</h1>

<?php if (rptPode('volume_processos')): ?>
<?php
// ─── Relatório 5: Volume de processos ────────────────────────────
$rVol = $dados['volume_processos'];
$kVol = $rVol['kpis'];
$tVol = $rVol['tabela'];
?>
<div class="report-panel open" id="rpt-volume">
  <div class="report-panel-header" onclick="rptToggle('volume')">
    <div class="report-panel-icon blue"><i data-lucide="layers" width="18" height="18"></i></div>
    <div class="report-panel-titles">
      <div class="report-panel-title">Volume de processos</div>
      <div class="report-panel-sub">Criados, concluídos e cancelados por período</div>
    </div>
    <div class="report-panel-actions">
      <div class="export-wrap" id="exp-volume" onclick="event.stopPropagation()">
        <button class="export-btn" onclick="rptToggleExport('volume')">
          <i data-lucide="download" width="13" height="13"></i> Exportar <i data-lucide="chevron-down" width="11" height="11"></i>
        </button>
        <div class="export-dropdown" id="exp-dd-volume">
          <?php rptExportOpcoes('volume_processos', $filtros); ?>
        </div>
      </div>
      <i data-lucide="chevron-down" width="16" height="16" class="report-chevron"></i>
    </div>
  </div>
  <div class="report-body">
    <form method="get" action="" class="filter-bar">
      <input type="hidden" name="module" value="relatorios">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="operacional">
      <?php rptFiltrosPeriodo('vol', $filtros); ?>
      <?php rptFiltroSelect('tipo-vol', 'tipo_nota_id', $dropdowns['tipos_nota'], $filtros, 'Tipo de nota'); ?>
      <?php rptFiltroLojas('loja-vol', $dropdowns['filiais'], $filtros); ?>
      <input type="hidden" name="periodo_preset" id="inp-periodo_preset-vol" value="<?= h($filtros['periodo_preset'] ?? 'este-mes') ?>">
      <input type="hidden" name="periodo_inicio" id="inp-periodo_inicio-vol" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="periodo_fim"    id="inp-periodo_fim-vol"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">
      <button type="submit" class="btn-gerar"><i data-lucide="bar-chart-2" width="13" height="13"></i> Gerar relatório</button>
    </form>
    <div class="kpi-strip">
      <div class="kpi-strip-item"><div class="kpi-strip-label">Abertos</div><div class="kpi-strip-val blue"><?= $kVol['total'] ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Concluídos</div><div class="kpi-strip-val green"><?= $kVol['concluidos'] ?> (<?= $kVol['pct_conclusao'] ?>%)</div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Em andamento</div><div class="kpi-strip-val amber"><?= $kVol['em_andamento'] ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Cancelados</div><div class="kpi-strip-val red"><?= $kVol['cancelados'] ?></div></div>
    </div>
    <div class="tbl-wrap">
      <table class="data-table">
        <thead><tr><th>Mês</th><th>Abertos</th><th>Concluídos</th><th>Cancelados</th><th>Em andamento</th><th>Taxa de conclusão</th></tr></thead>
        <tbody>
          <?php if (empty($tVol)): ?>
          <tr><td colspan="6" class="empty-state">Sem dados no período.</td></tr>
          <?php else: foreach ($tVol as $r): ?>
          <tr>
            <td><?= ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'][(int)date('m', strtotime($r['mes'] . '-01')) - 1] . '/' . date('Y', strtotime($r['mes'] . '-01')) ?></td>
            <td><?= $r['abertos'] ?></td>
            <td style="color:var(--color-success)"><?= $r['concluidos'] ?></td>
            <td><?= $r['cancelados'] ?></td>
            <td><?= $r['em_andamento'] ?></td>
            <td>
              <div class="part-bar-wrap">
                <div class="part-bar"><div class="part-bar-fill" style="background:var(--color-success);width:<?= min(100, $r['taxa_conclusao']) ?>%"></div></div>
                <span style="font-size:11px;color:var(--color-gray-medium)"><?= $r['taxa_conclusao'] ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (rptPode('sla')): ?>
<?php
// ─── Relatório 6: SLA ────────────────────────────────────────────
$rSla = $dados['sla'];
$kSla = $rSla['kpis'];
$tSla = $rSla['tabela'];
?>
<div class="report-panel" id="rpt-sla">
  <div class="report-panel-header" onclick="rptToggle('sla')">
    <div class="report-panel-icon purple"><i data-lucide="clock" width="18" height="18"></i></div>
    <div class="report-panel-titles">
      <div class="report-panel-title">SLA e tempo de ciclo</div>
      <div class="report-panel-sub">Tempo médio por tipo de processo e por etapa</div>
    </div>
    <div class="report-panel-actions">
      <div class="export-wrap" id="exp-sla" onclick="event.stopPropagation()">
        <button class="export-btn" onclick="rptToggleExport('sla')">
          <i data-lucide="download" width="13" height="13"></i> Exportar <i data-lucide="chevron-down" width="11" height="11"></i>
        </button>
        <div class="export-dropdown" id="exp-dd-sla">
          <?php rptExportOpcoes('sla', $filtros); ?>
        </div>
      </div>
      <i data-lucide="chevron-down" width="16" height="16" class="report-chevron"></i>
    </div>
  </div>
  <div class="report-body">
    <form method="get" action="" class="filter-bar">
      <input type="hidden" name="module" value="relatorios">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="operacional">
      <?php rptFiltrosPeriodo('sla', $filtros); ?>
      <input type="hidden" name="periodo_preset" id="inp-periodo_preset-sla" value="<?= h($filtros['periodo_preset'] ?? 'este-mes') ?>">
      <input type="hidden" name="periodo_inicio" id="inp-periodo_inicio-sla" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="periodo_fim"    id="inp-periodo_fim-sla"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">
      <button type="submit" class="btn-gerar"><i data-lucide="bar-chart-2" width="13" height="13"></i> Gerar relatório</button>
    </form>
    <div class="kpi-strip">
      <div class="kpi-strip-item"><div class="kpi-strip-label">Tempo médio de ciclo</div><div class="kpi-strip-val"><?= $kSla['tempo_medio'] ?> dias</div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Etapa mais lenta</div><div class="kpi-strip-val red" style="font-size:14px"><?= h($kSla['etapa_mais_lenta']) ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Etapa mais rápida</div><div class="kpi-strip-val green" style="font-size:14px"><?= h($kSla['etapa_mais_rapida']) ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Processos concluídos</div><div class="kpi-strip-val"><?= $kSla['concluidos'] ?></div></div>
    </div>
    <div class="tbl-wrap">
      <table class="data-table">
        <thead><tr><th>Etapa</th><th>SLA esperado</th><th>Tempo médio</th><th>Variação %</th><th>% do ciclo</th><th>Situação</th></tr></thead>
        <tbody>
          <?php if (empty($tSla)): ?>
          <tr><td colspan="6" class="empty-state">Sem dados de SLA no período.</td></tr>
          <?php else: foreach ($tSla as $r): ?>
          <tr class="<?= $r['situacao'] === 'vencido' ? 'row-atrasado' : '' ?>">
            <td><?= h($r['etapa']) ?></td>
            <td><?= $r['esperado_dias'] !== null ? $r['esperado_dias'] . ' dias' : '—' ?></td>
            <td><?= $r['media_dias'] ?> dias</td>
            <td style="color:<?= ($r['variacao_pct'] ?? 0) > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>">
              <?= $r['variacao_pct'] !== null ? (($r['variacao_pct'] > 0 ? '+' : '') . $r['variacao_pct'] . '%') : '—' ?>
            </td>
            <td>
              <div class="part-bar-wrap">
                <div class="part-bar"><div class="part-bar-fill" style="width:<?= min(100, $r['pct_ciclo']) ?>%"></div></div>
                <span style="font-size:11px;color:var(--color-gray-medium)"><?= $r['pct_ciclo'] ?>%</span>
              </div>
            </td>
            <td><?= match($r['situacao']) {
              'vencido'  => '<span class="sla-badge sla-vencido">Gargalo</span>',
              'atencao'  => '<span class="sla-badge sla-atencao">Atenção</span>',
              'ok'       => '<span class="sla-badge sla-ok">Dentro do SLA</span>',
              default    => '<span class="sla-badge sla-sem">Sem SLA</span>',
            }; ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (rptPode('devolucoes')): ?>
<?php
// ─── Relatório 7: Devoluções ──────────────────────────────────────
$rDev = $dados['devolucoes'];
$kDev = $rDev['kpis'];
$tDev = $rDev['tabela'];
?>
<div class="report-panel" id="rpt-dev">
  <div class="report-panel-header" onclick="rptToggle('dev')">
    <div class="report-panel-icon red"><i data-lucide="rotate-ccw" width="18" height="18"></i></div>
    <div class="report-panel-titles">
      <div class="report-panel-title">Devoluções e retrabalho</div>
      <div class="report-panel-sub">Processos devolvidos, motivos mais frequentes</div>
    </div>
    <div class="report-panel-actions">
      <div class="export-wrap" id="exp-dev" onclick="event.stopPropagation()">
        <button class="export-btn" onclick="rptToggleExport('dev')">
          <i data-lucide="download" width="13" height="13"></i> Exportar <i data-lucide="chevron-down" width="11" height="11"></i>
        </button>
        <div class="export-dropdown" id="exp-dd-dev">
          <?php rptExportOpcoes('devolucoes', $filtros); ?>
        </div>
      </div>
      <i data-lucide="chevron-down" width="16" height="16" class="report-chevron"></i>
    </div>
  </div>
  <div class="report-body">
    <form method="get" action="" class="filter-bar">
      <input type="hidden" name="module" value="relatorios">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="operacional">
      <?php rptFiltrosPeriodo('dev', $filtros); ?>
      <input type="hidden" name="periodo_preset" id="inp-periodo_preset-dev" value="<?= h($filtros['periodo_preset'] ?? 'este-mes') ?>">
      <input type="hidden" name="periodo_inicio" id="inp-periodo_inicio-dev" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="periodo_fim"    id="inp-periodo_fim-dev"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">
      <button type="submit" class="btn-gerar"><i data-lucide="bar-chart-2" width="13" height="13"></i> Gerar relatório</button>
    </form>
    <div class="kpi-strip">
      <div class="kpi-strip-item"><div class="kpi-strip-label">Total devolvidos</div><div class="kpi-strip-val red"><?= $kDev['total'] ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Taxa de devolução</div><div class="kpi-strip-val amber"><?= $kDev['taxa_devolucao'] ?>%</div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Motivo mais frequente</div><div class="kpi-strip-val" style="font-size:13px"><?= h($kDev['motivo_freq']) ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Maior devolvedor</div><div class="kpi-strip-val" style="font-size:13px"><?= h($kDev['maior_devolvedor']) ?></div></div>
    </div>
    <div class="tbl-wrap">
      <table class="data-table">
        <thead><tr><th>Motivo</th><th>Ocorrências</th><th>% do total</th><th>Etapa mais comum</th><th>Frequência</th></tr></thead>
        <tbody>
          <?php if (empty($tDev)): ?>
          <tr><td colspan="5" class="empty-state">Nenhuma devolução no período.</td></tr>
          <?php else: foreach ($tDev as $r): ?>
          <tr>
            <td><?= h($r['motivo']) ?></td>
            <td><?= (int)$r['ocorrencias'] ?></td>
            <td><?= $r['pct'] ?>%</td>
            <td><?= h($r['etapa_comum'] ?? '—') ?></td>
            <td>
              <div class="part-bar-wrap">
                <div class="part-bar"><div class="part-bar-fill" style="width:<?= min(100, (float)$r['pct']) ?>%;background:var(--color-danger)"></div></div>
                <span style="font-size:11px;color:var(--color-gray-medium)"><?= $r['pct'] ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (rptPode('desempenho_usuarios')): ?>
<?php
// ─── Relatório 8: Desempenho por usuário ─────────────────────────
$tDesemp = $dados['desempenho_usuarios']['tabela'];
?>
<div class="report-panel" id="rpt-desemp">
  <div class="report-panel-header" onclick="rptToggle('desemp')">
    <div class="report-panel-icon purple"><i data-lucide="users" width="18" height="18"></i></div>
    <div class="report-panel-titles">
      <div class="report-panel-title">Desempenho por usuário / grupo</div>
      <div class="report-panel-sub">Processos, tempo de resposta e devoluções por colaborador</div>
    </div>
    <div class="report-panel-actions">
      <div class="export-wrap" id="exp-desemp" onclick="event.stopPropagation()">
        <button class="export-btn" onclick="rptToggleExport('desemp')">
          <i data-lucide="download" width="13" height="13"></i> Exportar <i data-lucide="chevron-down" width="11" height="11"></i>
        </button>
        <div class="export-dropdown" id="exp-dd-desemp">
          <?php rptExportOpcoes('desempenho_usuarios', $filtros); ?>
        </div>
      </div>
      <i data-lucide="chevron-down" width="16" height="16" class="report-chevron"></i>
    </div>
  </div>
  <div class="report-body">
    <form method="get" action="" class="filter-bar">
      <input type="hidden" name="module" value="relatorios">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="operacional">
      <div class="filter-search">
        <i data-lucide="search" width="14" height="14" style="color:var(--color-gray-medium)"></i>
        <input type="text" name="busca" value="<?= h($filtros['busca'] ?? '') ?>" placeholder="Buscar usuário...">
      </div>
      <?php rptFiltrosPeriodo('desemp', $filtros); ?>
      <?php rptFiltroSelect('grupo-desemp', 'grupo_id', $dropdowns['grupos'], $filtros, 'Grupo'); ?>
      <input type="hidden" name="periodo_preset" id="inp-periodo_preset-desemp" value="<?= h($filtros['periodo_preset'] ?? 'este-mes') ?>">
      <input type="hidden" name="periodo_inicio" id="inp-periodo_inicio-desemp" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="periodo_fim"    id="inp-periodo_fim-desemp"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">
      <button type="submit" class="btn-gerar"><i data-lucide="bar-chart-2" width="13" height="13"></i> Gerar relatório</button>
    </form>
    <div class="tbl-wrap">
      <table class="data-table">
        <thead><tr><th>Usuário</th><th>Função</th><th>Processos</th><th>Concluídos</th><th>Tempo médio resp.</th><th>Devoluções</th><th>Taxa devolução</th></tr></thead>
        <tbody>
          <?php if (empty($tDesemp)): ?>
          <tr><td colspan="7" class="empty-state">Sem dados no período.</td></tr>
          <?php else: foreach ($tDesemp as $u): ?>
          <tr class="<?= $u['critico'] ? 'row-atrasado' : '' ?>">
            <td><strong><?= h($u['nome']) ?></strong></td>
            <td><?= h(ucfirst($u['funcao'] ?? '—')) ?></td>
            <td><?= (int)$u['processos_abertos'] ?></td>
            <td><?= (int)$u['aprovados_concluidos'] ?></td>
            <td><?= $u['media_resposta'] ? $u['media_resposta'] . ' dias' : '—' ?></td>
            <td><?= (int)$u['devolucoes'] ?></td>
            <td><?php
              $taxa = (float)$u['taxa_devolucao'];
              $cor  = $taxa > 20 ? 'var(--color-danger)' : ($taxa > 10 ? 'var(--color-warning)' : 'var(--color-success)');
              echo '<span style="color:' . $cor . ';font-family:var(--font-bold)">' . $taxa . '%</span>';
            ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// ─── Relatórios 9, 10 e 11: processo a processo, TODOS os status ──
// A tela de Processos esconde finalizado-pago, cancelado e encerrado mesmo
// em "Todos os status". Estes três são a única forma de ver o histórico
// completo — por isso existem separados, um por dimensão.
foreach ([
    ['chave' => 'processos_departamento', 'sufixo' => 'pdep',
     'titulo' => 'Processos por departamento',
     'sub'    => 'Todos os processos do departamento, em todos os status',
     'icone'  => 'building-2', 'cor' => 'blue',
     'campo'  => 'departamento_id', 'opcoes' => 'departamentos', 'rotulo' => 'Departamento'],
    ['chave' => 'processos_setor', 'sufixo' => 'pset',
     'titulo' => 'Processos por setor',
     'sub'    => 'Todos os processos do setor, em todos os status',
     'icone'  => 'users', 'cor' => 'green',
     'campo'  => 'setor_id', 'opcoes' => 'setores', 'rotulo' => 'Setor'],
    ['chave' => 'processos_fluxo', 'sufixo' => 'pflu',
     'titulo' => 'Processos por fluxo',
     'sub'    => 'Todos os processos do fluxo, em todos os status',
     'icone'  => 'git-branch', 'cor' => 'amber',
     'campo'  => 'template_id', 'opcoes' => 'fluxos', 'rotulo' => 'Fluxo'],
] as $rel):
    if (!rptPode($rel['chave'])) continue;
    $rD = $dados[$rel['chave']];
    $kD = $rD['kpis'];
    $tD = $rD['tabela'];
    $sf = $rel['sufixo'];
?>
<div class="report-panel" id="rpt-<?= $sf ?>">
  <div class="report-panel-header" onclick="rptToggle('<?= $sf ?>')">
    <div class="report-panel-icon <?= $rel['cor'] ?>"><i data-lucide="<?= $rel['icone'] ?>" width="18" height="18"></i></div>
    <div class="report-panel-titles">
      <div class="report-panel-title"><?= h($rel['titulo']) ?></div>
      <div class="report-panel-sub"><?= h($rel['sub']) ?></div>
    </div>
    <div class="report-panel-actions">
      <div class="export-wrap" id="exp-<?= $sf ?>" onclick="event.stopPropagation()">
        <button class="export-btn" onclick="rptToggleExport('<?= $sf ?>')">
          <i data-lucide="download" width="13" height="13"></i> Exportar <i data-lucide="chevron-down" width="11" height="11"></i>
        </button>
        <div class="export-dropdown" id="exp-dd-<?= $sf ?>">
          <?php rptExportOpcoes($rel['chave'], $filtros); ?>
        </div>
      </div>
      <i data-lucide="chevron-down" width="16" height="16" class="report-chevron"></i>
    </div>
  </div>
  <div class="report-body">
    <form method="get" action="" class="filter-bar">
      <input type="hidden" name="module" value="relatorios">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="operacional">
      <?php rptFiltrosPeriodo($sf, $filtros); ?>
      <?php rptFiltroSelect('dim-' . $sf, $rel['campo'], $dropdowns[$rel['opcoes']] ?? [], $filtros, $rel['rotulo']); ?>
      <?php rptFiltroLojas('loja-' . $sf, $dropdowns['filiais'], $filtros); ?>
      <input type="hidden" name="periodo_preset" id="inp-periodo_preset-<?= $sf ?>" value="<?= h($filtros['periodo_preset'] ?? 'este-mes') ?>">
      <input type="hidden" name="periodo_inicio" id="inp-periodo_inicio-<?= $sf ?>" value="<?= h($filtros['periodo_inicio'] ?? '') ?>">
      <input type="hidden" name="periodo_fim"    id="inp-periodo_fim-<?= $sf ?>"    value="<?= h($filtros['periodo_fim'] ?? '') ?>">
      <button type="submit" class="btn-gerar"><i data-lucide="bar-chart-2" width="13" height="13"></i> Gerar relatório</button>
    </form>
    <div class="kpi-strip">
      <div class="kpi-strip-item"><div class="kpi-strip-label">Processos</div><div class="kpi-strip-val blue"><?= $kD['qtd'] ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Em andamento</div><div class="kpi-strip-val amber"><?= $kD['em_andamento'] ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Finalizados</div><div class="kpi-strip-val green"><?= $kD['finalizados'] ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Encerrados / cancelados</div><div class="kpi-strip-val"><?= $kD['encerrados'] ?></div></div>
      <div class="kpi-strip-item"><div class="kpi-strip-label">Valor total</div><div class="kpi-strip-val">R$ <?= number_format($kD['valor_total'], 2, ',', '.') ?></div></div>
    </div>
    <div class="tbl-wrap">
      <table class="data-table">
        <thead><tr>
          <th>Código</th><th>Fornecedor</th><th>Nº NF</th><th style="text-align:right">Valor</th>
          <th>Departamento</th><th>Setor</th><th>Fluxo</th><th>Etapa</th>
          <th>Responsável</th><th style="text-align:center">Status</th><th>Abertura</th>
        </tr></thead>
        <tbody>
          <?php if (empty($tD)): ?>
          <tr><td colspan="11" class="empty-state">Sem processos no período.</td></tr>
          <?php else: foreach ($tD as $r): ?>
          <tr>
            <td style="font-family:var(--font-bold)"><?= h($r['codigo']) ?></td>
            <td><?= h($r['fornecedor_nome'] ?? '—') ?></td>
            <td><?= h($r['numero_nf'] ?? '—') ?></td>
            <td style="text-align:right;white-space:nowrap">R$ <?= number_format((float)$r['valor_nf'], 2, ',', '.') ?></td>
            <td><?= h($r['departamento_nome'] ?? '—') ?></td>
            <td><?= h($r['setor_nome'] ?? '—') ?></td>
            <td><?= h($r['template_nome'] ?? '—') ?></td>
            <td style="white-space:nowrap"><?= (int)$r['etapa_atual'] ?>/<?= (int)$r['total_etapas'] ?></td>
            <td><?php
              // Processo encerrado não está na mão de ninguém — não faz sentido
              // mostrar responsável de uma etapa que ninguém mais vai atuar.
              if (in_array($r['status'], ['aberto','devolvido','cancelamento_solicitado'], true)) {
                  echo h(trim((string)($r['responsavel_nome'] ?? '—')
                       . ($r['etapa_nome'] ? ' — ' . $r['etapa_nome'] : '')));
              } else {
                  echo '<span style="color:var(--color-gray-medium)">—</span>';
              }
            ?></td>
            <td style="text-align:center"><?php
              $badge = [
                'aberto'                  => ['#E3F0FF', '#1565C0'],
                'aprovado'                => ['#D8F5E5', '#00A960'],
                'devolvido'               => ['#FFF3E0', '#FF8A2D'],
                'cancelado'               => ['#FFE8EE', '#B3261E'],
                'cancelamento_solicitado' => ['#FFE8EE', '#B3261E'],
                'encerrado_apollo'        => ['#D8D4E4', '#606060'],
              ][$r['status']] ?? ['#EAEAEA', '#888888'];
              echo '<span style="display:inline-block; padding:2px 10px; border-radius:8px; font-size:10px;'
                 . ' font-family:var(--font-bold); background:' . $badge[0] . '; color:' . $badge[1] . '">'
                 . h(rptStatusLabel((string)$r['status'])) . '</span>';
            ?></td>
            <td style="white-space:nowrap;color:var(--color-gray-medium)"><?= date('d/m/Y', strtotime((string)$r['criado_em'])) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?php if (count($tD) >= 500): ?>
      <div style="padding:10px 14px;font-size:12px;color:var(--color-gray-medium)">
        Mostrando as 500 primeiras linhas do período. A exportação leva o período inteiro.
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php elseif ($aba === 'meus' && rptPode('meus_processos')): ?>
<?php include VIEWS . '/relatorios/meus_processos.php'; ?>
<?php endif; ?>

</div><!-- /rpt-content -->

<?php
// ─── Helper functions PHP para a view ───────────────────────────
function rptFiltrosPeriodo(string $suffix, array $filtros): void {
    $preset = $filtros['periodo_preset'] ?? 'este-mes';
    $label = match($preset) {
        'esta-semana'  => 'Esta semana',
        'este-trimestre' => 'Este trimestre',
        'personalizado'  => 'Personalizado',
        default        => 'Este mês',
    };
    ?>
    <div class="fp-wrap" id="fp-periodo-<?= $suffix ?>">
      <button type="button" class="filter-pill<?= $preset !== 'este-mes' ? ' active' : '' ?>"
              onclick="dashToggleFP('periodo-<?= $suffix ?>')">
        <i data-lucide="calendar" width="12" height="12"></i>
        <span><?= h($label) ?></span>
        <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
      </button>
      <div class="fp-dropdown" id="fp-dd-periodo-<?= $suffix ?>">
        <?php foreach (['esta-semana'=>'Esta semana','este-mes'=>'Este mês','este-trimestre'=>'Este trimestre'] as $v=>$l): ?>
        <div class="fp-option<?= $preset === $v ? ' selected' : '' ?>"
             onclick="dashSelectPeriodo('<?= $suffix ?>','<?= $v ?>','<?= $l ?>')">
          <span class="fp-check"><?= $preset === $v ? '✓' : '' ?></span><?= $l ?>
        </div>
        <?php endforeach; ?>
        <div class="fp-option-custom" onclick="dashToggleCustom('periodo-<?= $suffix ?>')">
          <i data-lucide="calendar" width="12" height="12"></i> Período personalizado
        </div>
        <div class="fp-custom-period" id="fp-custom-periodo-<?= $suffix ?>">
          <div class="fp-custom-row"><label>De</label><input type="date" id="fp-ini-<?= $suffix ?>" value="<?= h($filtros['periodo_inicio'] ?? '') ?>"></div>
          <div class="fp-custom-row"><label>Até</label><input type="date" id="fp-fim-<?= $suffix ?>" value="<?= h($filtros['periodo_fim'] ?? '') ?>"></div>
          <button type="button" class="fp-custom-apply" onclick="dashApplyCustom('<?= $suffix ?>')">Aplicar</button>
        </div>
      </div>
    </div>
    <?php
}

/**
 * Filtro de LOJAS — multi-seleção, o mesmo componente de Processos, Capturas e
 * Dashboard. As opções já vêm recortadas pelo escopo do usuário.
 */
function rptFiltroLojas(string $suffix, array $options, array $filtros): void {
    $flSufixo   = $suffix;
    $flOpcoes   = $options;
    $flSel      = (array) ($filtros['filial_id'] ?? []);
    $flVariante = 'pill';
    $flLabel    = 'Loja';
    include VIEWS . '/components/filtro-lojas.php';
}

function rptFiltroSelect(string $suffix, string $inputName, array $options, array $filtros, string $label): void {
    $currentVal = $filtros[$inputName] ?? '';
    ?>
    <div class="fp-wrap" id="fp-<?= $suffix ?>">
      <button type="button" class="filter-pill<?= $currentVal !== '' ? ' active' : '' ?>"
              onclick="dashToggleFP('<?= $suffix ?>')">
        <span><?= h($label) ?></span>
        <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
      </button>
      <div class="fp-dropdown" id="fp-dd-<?= $suffix ?>">
        <?php foreach ($options as $opt): ?>
        <div class="fp-option<?= $currentVal === $opt['id'] ? ' selected' : '' ?>"
             onclick="dashSelectSingle('<?= $suffix ?>','<?= $inputName ?>','<?= h($opt['id']) ?>','<?= h($opt['nome']) ?>')">
          <span class="fp-check"><?= $currentVal === $opt['id'] ? '✓' : '' ?></span>
          <?= h($opt['nome']) ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <input type="hidden" name="<?= $inputName ?>" id="inp-<?= $inputName ?>-<?= $suffix ?>" value="<?= h($currentVal) ?>">
    <?php
}
?>

<style>
.btn-gerar {
  margin-left: auto;
  display: flex; align-items: center; gap: 6px;
  padding: 7px 16px;
  background: var(--color-primary);
  color: #fff;
  border: none;
  border-radius: 4px;
  font-family: var(--font-bold);
  font-size: 12px;
  cursor: pointer;
  white-space: nowrap;
}
.btn-gerar:hover { background: var(--color-primary-hover); }
</style>

<script>
// ─── Report panel toggle ─────────────────────────────────────────
function rptToggle(id) {
    var panel = document.getElementById('rpt-' + id);
    if (panel) panel.classList.toggle('open');
}

// ─── Export dropdown toggle ──────────────────────────────────────
function rptToggleExport(id) {
    var wrap = document.getElementById('exp-' + id);
    if (!wrap) return;
    var open = wrap.classList.toggle('open');
    if (open) {
        document.querySelectorAll('.export-wrap.open').forEach(function(el) {
            if (el !== wrap) el.classList.remove('open');
        });
    }
}

// Fecha export dropdowns ao clicar fora
document.addEventListener('click', function(e) {
    if (!e.target.closest('.export-wrap')) {
        document.querySelectorAll('.export-wrap.open').forEach(function(el) {
            el.classList.remove('open');
        });
    }
    if (!e.target.closest('.fp-wrap')) {
        document.querySelectorAll('.fp-wrap.open').forEach(function(el) {
            el.classList.remove('open');
        });
    }
});

// Reutiliza as funções do dashboard.js inlined na view do dashboard
function dashToggleFP(id) {
    var wrap = document.getElementById('fp-' + id);
    var dd   = document.getElementById('fp-dd-' + id);
    if (!wrap || !dd) return;
    var open = wrap.classList.toggle('open');
    if (open) {
        document.querySelectorAll('.fp-wrap.open').forEach(function(el) {
            if (el !== wrap) el.classList.remove('open');
        });
    }
}
function dashSelectPeriodo(suffix, preset, label) {
    document.getElementById('inp-periodo_preset-' + suffix).value = preset;
    document.getElementById('inp-periodo_inicio-' + suffix).value = '';
    document.getElementById('inp-periodo_fim-'    + suffix).value = '';
    var wrap = document.getElementById('fp-periodo-' + suffix);
    if (wrap) wrap.querySelector('span').textContent = label;
    wrap?.classList.remove('open');
    // Aplica só quando o usuário clicar em "Gerar relatório"
}
function dashToggleCustom(id) {
    var cp = document.getElementById('fp-custom-' + id);
    if (cp) cp.classList.toggle('open');
}
function dashApplyCustom(suffix) {
    var ini = document.getElementById('fp-ini-' + suffix)?.value;
    var fim = document.getElementById('fp-fim-' + suffix)?.value;
    if (!ini || !fim) return;
    document.getElementById('inp-periodo_preset-' + suffix).value = 'personalizado';
    document.getElementById('inp-periodo_inicio-' + suffix).value = ini;
    document.getElementById('inp-periodo_fim-'    + suffix).value = fim;
    var wrap = document.getElementById('fp-periodo-' + suffix);
    if (wrap) {
        var fmt = function(d) { var p = d.split('-'); return p[2] + '/' + p[1]; };
        wrap.querySelector('span').textContent = fmt(ini) + ' - ' + fmt(fim);
    }
    document.getElementById('fp-custom-periodo-' + suffix)?.classList.remove('open');
    wrap?.classList.remove('open');
    // Aplica só quando o usuário clicar em "Gerar relatório"
}
function dashSelectSingle(wrapperId, inputName, value, label) {
    var wrap = document.getElementById('fp-' + wrapperId);
    if (!wrap) return;
    var inp = wrap.closest('form')?.querySelector('input[name="' + inputName + '"]');
    if (inp) inp.value = value;
    var btn = wrap.querySelector('button span');
    if (btn) btn.textContent = label;
    wrap.classList.remove('open');
    // Aplica só quando o usuário clicar em "Gerar relatório"
}
</script>
