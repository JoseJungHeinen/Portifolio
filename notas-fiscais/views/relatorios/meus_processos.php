<?php
// @versao Rel_06 V_02_12/08/2026
/**
 * views/relatorios/meus_processos.php
 * Relatório "Meus processos" — todos os processos em que o usuário logado
 * participa, direta ou via grupo. Incluído por views/relatorios/index.php
 * quando $aba === 'meus'.
 *
 * Variáveis herdadas do ReportController / index.php:
 *   $filtros   array   filtros ativos (inclui part_direta e part_grupo)
 *   $dados     array   ['meus_processos' => ['kpis' => ..., 'tabela' => ...]]
 *   $dropdowns array   empresas (filiais no escopo do usuário) e setores
 *
 * Ao contrário das outras abas, aqui não existe visão gerencial: o recorte
 * por participação vale para todo mundo, inclusive admin e quem tem
 * `processos.ver_todos`. Cada usuário vê só os seus.
 */

$rMeus = $dados['meus_processos'] ?? ['kpis' => [], 'tabela' => []];
$kMeus = $rMeus['kpis']   ?: ['qtd' => 0, 'valor_total' => 0.0, 'em_andamento' => 0, 'finalizados' => 0, 'encerrados' => 0];
$tMeus = $rMeus['tabela'] ?: [];

$partDireta = !empty($filtros['part_direta']);
$partGrupo  = !empty($filtros['part_grupo']);

// Datas já resolvidas (o filtro nunca aparece em branco na tela)
[$meusIni, $meusFim] = ReportQueries::resolvePeriodo($filtros);

// A exportação repete os filtros da tela, inclusive a participação marcada.
$meusExportBase = [
    'module'         => 'relatorios',
    'action'         => 'exportar',
    'relatorio'      => 'meus_processos',
    'periodo_preset' => 'personalizado',
    'periodo_inicio' => $meusIni,
    'periodo_fim'    => $meusFim,
    'filial_id'      => $filtros['filial_id'] ?? '',
    'setor_id'       => $filtros['setor_id']  ?? '',
    'part'           => '1',
    'part_direta'    => $partDireta ? '1' : '0',
    'part_grupo'     => $partGrupo  ? '1' : '0',
];
$meusExportUrl = static function (string $formato) use ($meusExportBase): string {
    return '?' . http_build_query($meusExportBase + ['formato' => $formato]);
};

/** Select de filtro com opção "Todas" — os pills do módulo não têm limpar. */
function rptMeusSelect(string $suffix, string $inputName, array $options, string $atual, string $label, string $labelTodos): void {
    $nomeAtual = $labelTodos;
    foreach ($options as $o) {
        if (($o['id'] ?? '') === $atual) { $nomeAtual = $o['nome']; break; }
    }
    ?>
    <div class="fp-wrap" id="fp-<?= $suffix ?>">
      <button type="button" class="filter-pill<?= $atual !== '' ? ' active' : '' ?>"
              onclick="dashToggleFP('<?= $suffix ?>')">
        <span><?= h($atual !== '' ? $nomeAtual : $label) ?></span>
        <i data-lucide="chevron-down" width="11" height="11" class="fp-chevron"></i>
      </button>
      <div class="fp-dropdown" id="fp-dd-<?= $suffix ?>">
        <div class="fp-option<?= $atual === '' ? ' selected' : '' ?>"
             onclick="dashSelectSingle('<?= $suffix ?>','<?= $inputName ?>','','<?= h($labelTodos) ?>')">
          <span class="fp-check"><?= $atual === '' ? '✓' : '' ?></span><?= h($labelTodos) ?>
        </div>
        <?php foreach ($options as $opt): ?>
        <div class="fp-option<?= $atual === $opt['id'] ? ' selected' : '' ?>"
             onclick="dashSelectSingle('<?= $suffix ?>','<?= $inputName ?>','<?= h($opt['id']) ?>','<?= h($opt['nome']) ?>')">
          <span class="fp-check"><?= $atual === $opt['id'] ? '✓' : '' ?></span><?= h($opt['nome']) ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <input type="hidden" name="<?= $inputName ?>" value="<?= h($atual) ?>">
    <?php
}
?>

<h1 style="font-family:var(--font-bold);font-size:22px;color:var(--color-dark);margin-bottom:20px">
  Meus <span style="color:var(--color-primary)">processos</span>
</h1>

<div class="report-panel open" id="rpt-meus">
  <div class="report-panel-header" onclick="rptToggle('meus')">
    <div class="report-panel-icon red"><i data-lucide="user-check" width="18" height="18"></i></div>
    <div class="report-panel-titles">
      <div class="report-panel-title">Processos em que participo</div>
      <div class="report-panel-sub">Abertos por mim, sob minha responsabilidade, em que atuei ou que passam por um grupo meu</div>
    </div>
    <div class="report-panel-actions">
      <div class="export-wrap" id="exp-meus" onclick="event.stopPropagation()">
        <button class="export-btn" onclick="rptToggleExport('meus')">
          <i data-lucide="download" width="13" height="13"></i> Exportar
          <i data-lucide="chevron-down" width="11" height="11"></i>
        </button>
        <div class="export-dropdown" id="exp-dd-meus">
          <a class="export-option" href="<?= h($meusExportUrl('xlsx')) ?>"><i data-lucide="file-spreadsheet" width="14" height="14"></i> Excel (XLSX)</a>
          <a class="export-option" href="<?= h($meusExportUrl('csv')) ?>"><i data-lucide="file-text" width="14" height="14"></i> CSV</a>
          <a class="export-option" href="<?= h($meusExportUrl('pdf')) ?>"><i data-lucide="file-type-2" width="14" height="14"></i> PDF</a>
        </div>
      </div>
      <i data-lucide="chevron-down" width="16" height="16" class="report-chevron"></i>
    </div>
  </div>

  <div class="report-body">

    <!-- Filtros -->
    <form method="get" action="" class="filter-bar" style="flex-wrap:wrap; gap:10px">
      <input type="hidden" name="module" value="relatorios">
      <input type="hidden" name="action" value="index">
      <input type="hidden" name="aba" value="meus">
      <input type="hidden" name="part" value="1">

      <div class="meus-data-wrap">
        <label for="meus-data-de">De</label>
        <input type="date" id="meus-data-de" name="periodo_inicio" value="<?= h($meusIni) ?>">
      </div>
      <div class="meus-data-wrap">
        <label for="meus-data-ate">Até</label>
        <input type="date" id="meus-data-ate" name="periodo_fim" value="<?= h($meusFim) ?>">
      </div>

      <?php
      // Empresas — multi-seleção; sem nada marcado vale "todas as que enxergo".
      $flSufixo   = 'emp-meus';
      $flOpcoes   = $dropdowns['empresas'] ?? [];
      $flSel      = (array) ($filtros['filial_id'] ?? []);
      $flVariante = 'pill';
      $flLabel    = 'Empresa';
      include VIEWS . '/components/filtro-lojas.php';
      ?>
      <?php rptMeusSelect('set-meus', 'setor_id',  $dropdowns['setores']  ?? [], (string)($filtros['setor_id']  ?? ''), 'Setor',   'Todos os setores'); ?>

      <label class="meus-check<?= $partDireta ? ' on' : '' ?>">
        <input type="checkbox" name="part_direta" value="1" <?= $partDireta ? 'checked' : '' ?>>
        <span>Participação direta</span>
      </label>
      <label class="meus-check<?= $partGrupo ? ' on' : '' ?>">
        <input type="checkbox" name="part_grupo" value="1" <?= $partGrupo ? 'checked' : '' ?>>
        <span>Via grupo</span>
      </label>

      <button type="submit" class="btn-gerar">
        <i data-lucide="bar-chart-2" width="13" height="13"></i> Gerar relatório
      </button>
    </form>

    <!-- KPIs -->
    <div class="kpi-strip">
      <div class="kpi-strip-item">
        <div class="kpi-strip-label">Processos</div>
        <div class="kpi-strip-val"><?= (int)$kMeus['qtd'] ?></div>
      </div>
      <div class="kpi-strip-item">
        <div class="kpi-strip-label">Em andamento</div>
        <div class="kpi-strip-val blue"><?= (int)$kMeus['em_andamento'] ?></div>
      </div>
      <div class="kpi-strip-item">
        <div class="kpi-strip-label">Finalizados</div>
        <div class="kpi-strip-val green"><?= (int)$kMeus['finalizados'] ?></div>
      </div>
      <div class="kpi-strip-item">
        <div class="kpi-strip-label">Valor total</div>
        <div class="kpi-strip-val"><?= rptMoeda((float)$kMeus['valor_total']) ?></div>
      </div>
    </div>

    <!-- Tabela -->
    <div class="tbl-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Código</th>
            <th>Fornecedor</th>
            <th>Nº NF</th>
            <th style="text-align:right">Valor</th>
            <th>Filial</th>
            <th style="text-align:center">Etapa</th>
            <th>Responsável</th>
            <th style="text-align:center">Status</th>
            <th>Abertura</th>
            <th>Participação</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$partDireta && !$partGrupo): ?>
          <tr><td colspan="10" class="empty-state">Marque ao menos um tipo de participação para gerar o relatório.</td></tr>

          <?php elseif (empty($tMeus)): ?>
          <tr><td colspan="10" class="empty-state">Você não participa de nenhum processo com esses filtros.</td></tr>

          <?php else: foreach ($tMeus as $r): ?>
          <tr>
            <td style="white-space:nowrap">
              <a href="<?= h(linkDetalheProcesso($r['id'], 'relatorios')) ?>"
                 style="font-family:var(--font-bold); color:var(--color-primary); text-decoration:none">
                <?= h($r['codigo']) ?>
              </a>
            </td>
            <td style="max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap"
                title="<?= h($r['fornecedor_nome'] ?? '') ?>"><?= h($r['fornecedor_nome'] ?? '—') ?></td>
            <td><?= h($r['numero_nf'] ?? '—') ?></td>
            <td style="text-align:right; font-family:var(--font-bold); white-space:nowrap"><?= rptMoeda((float)$r['valor_nf']) ?></td>
            <td style="max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap"><?= h($r['filial_nome'] ?? '—') ?></td>
            <td style="text-align:center; white-space:nowrap"><?= (int)$r['etapa_atual'] ?>/<?= (int)$r['total_etapas'] ?></td>
            <td style="max-width:190px">
              <?php if (in_array($r['status'], ['aberto','devolvido','cancelamento_solicitado'], true) && !empty($r['responsavel_nome'])): ?>
              <div style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
                <?php if (($r['tipo_aprovador'] ?? '') === 'grupo'): ?>
                <span style="font-size:9px; font-family:var(--font-bold); background:var(--color-tag-bg); color:var(--color-primary); padding:1px 5px; border-radius:6px; margin-right:3px">GRUPO</span>
                <?php endif; ?>
                <?= h($r['responsavel_nome']) ?>
              </div>
              <?php if (!empty($r['etapa_nome'])): ?>
              <div style="font-size:11px; color:var(--color-gray-medium); overflow:hidden; text-overflow:ellipsis; white-space:nowrap"><?= h($r['etapa_nome']) ?></div>
              <?php endif; ?>
              <?php else: ?>
              <span style="color:var(--color-gray-medium)">—</span>
              <?php endif; ?>
            </td>
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
            <td style="white-space:nowrap; color:var(--color-gray-medium)"><?= date('d/m/Y', strtotime((string)$r['criado_em'])) ?></td>
            <td style="white-space:nowrap">
              <?php if ((bool)$r['part_direta']): ?>
              <span class="meus-tag direta">Direta</span>
              <?php endif; ?>
              <?php if ((bool)$r['part_grupo']): ?>
              <span class="meus-tag grupo">Grupo</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if (count($tMeus) >= RPT_MEUS_LIMITE_TELA): ?>
    <div style="padding:12px 20px; font-size:12px; color:var(--color-gray-medium); border-top:1px solid #f3f3f3">
      Exibindo as <?= RPT_MEUS_LIMITE_TELA ?> mais recentes. Reduza o período ou exporte para ver o restante.
    </div>
    <?php endif; ?>

  </div>
</div>

<style>
.meus-data-wrap {
  display: flex; align-items: center; gap: 6px;
  padding: 4px 10px;
  border: 1.5px solid var(--color-gray-light);
  border-radius: 4px;
  background: #fff;
}
.meus-data-wrap label {
  font-family: var(--font-bold);
  font-size: 11px;
  color: var(--color-gray-medium);
  text-transform: uppercase;
}
.meus-data-wrap input[type="date"] {
  border: none; outline: none;
  font-size: 12px;
  color: var(--color-dark);
  background: transparent;
}
.meus-data-wrap:focus-within { border-color: var(--color-primary); }

.meus-check {
  display: flex; align-items: center; gap: 6px;
  padding: 7px 12px;
  border: 1.5px solid var(--color-gray-light);
  border-radius: 4px;
  background: #fff;
  font-size: 12px;
  color: var(--color-gray-dark);
  cursor: pointer;
  user-select: none;
}
.meus-check.on {
  border-color: var(--color-primary);
  color: var(--color-primary);
  font-family: var(--font-bold);
}
.meus-check input { accent-color: var(--color-primary); cursor: pointer; }

.meus-tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 8px;
  font-family: var(--font-bold);
  font-size: 10px;
  margin-right: 4px;
}
.meus-tag.direta { background: #FFE8EE; color: var(--color-primary); }
.meus-tag.grupo  { background: var(--color-tag-bg); color: var(--color-gray-dark); }
</style>

<script>
// Realce imediato do chip de participação, sem esperar o "Gerar relatório".
document.querySelectorAll('.meus-check input').forEach(function (inp) {
    inp.addEventListener('change', function () {
        inp.closest('.meus-check').classList.toggle('on', inp.checked);
    });
});
</script>
