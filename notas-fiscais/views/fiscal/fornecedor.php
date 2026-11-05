<?php
// @versao Fis_02 V_02_26/08/2026
/**
 * views/fiscal/fornecedor.php
 * Fiscal — notas de um fornecedor no período filtrado, com a conferência
 * manual nota a nota.
 *
 * Variáveis esperadas:
 *   $fornecedor array  linha agregada do fornecedor (mesmas somas da listagem)
 *   $notas      array  notas do período, com 'conferida'
 *   $filtro     array  [aplicado, data_ini, data_fim, filial_ids, erro]
 *   $qs         string querystring do filtro, para o link de voltar
 */

$fornecedor = $fornecedor ?? [];
$notas      = $notas      ?? [];
$filtro     = $filtro     ?? ['data_ini' => '', 'data_fim' => '', 'filial_ids' => []];
$qs         = $qs         ?? '';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fisCnpj')) {
    function fisCnpj(string $c): string {
        if (strlen($c) !== 14) return $c;
        return substr($c,0,2).'.'.substr($c,2,3).'.'.substr($c,5,3).'/'.substr($c,8,4).'-'.substr($c,12,2);
    }
    function fisValor($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
    function fisNum($v): string    { return number_format((float)$v, 0, ',', '.'); }
    function fisStatus(string $s): array {
        return match ($s) {
            'total'   => ['Conferido Total',   '#D8F5E5', '#00A960'],
            'parcial' => ['Conferido Parcial', '#FFF3E0', '#FF8A2D'],
            default   => ['Não Conferido',     '#EAEAEA', '#888888'],
        };
    }
}

[$rotulo, $bg, $fg] = fisStatus((string) ($fornecedor['status'] ?? 'nenhuma'));
$podeConferir = Auth::can('fiscal', 'conferir');
?>
<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">

<?php include VIEWS . '/components/alert.php'; ?>

<a href="?module=fiscal&amp;action=index<?= h($qs) ?>"
   style="display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:700; color:#606060; text-decoration:none; margin-bottom:14px">
  <i data-lucide="arrow-left" width="16" height="16"></i> Voltar aos fornecedores
</a>

<!-- Cabeçalho do fornecedor -->
<div class="page-header" style="margin-bottom:24px">
  <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
    <?= h($fornecedor['emitente_nome'] ?? '') ?>
  </h1>
  <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap">
    <span style="font-size:14px; color:#888"><?= h(fisCnpj((string) ($fornecedor['emitente_cnpj'] ?? ''))) ?></span>
    <span style="display:inline-block; background:<?= $bg ?>; color:<?= $fg ?>; border-radius:8px; padding:3px 8px; font-size:10px; font-weight:700; text-transform:uppercase"><?= h($rotulo) ?></span>
    <span style="font-size:13px; color:#888">
      Emissão de <?= h(date('d/m/Y', strtotime($filtro['data_ini']))) ?>
      a <?= h(date('d/m/Y', strtotime($filtro['data_fim']))) ?>
      · <?= fisNum($fornecedor['conferidas'] ?? 0) ?> de <?= fisNum($fornecedor['notas'] ?? 0) ?> conferidas
    </span>
  </div>
</div>

<!-- Somas do fornecedor no período -->
<?php
  $cards = [
    ['Notas',             fisNum($fornecedor['notas'] ?? 0),            '#606060'],
    ['Total produtos',    fisValor($fornecedor['valor_produtos'] ?? 0), '#606060'],
    ['Total nota fiscal', fisValor($fornecedor['valor_nota'] ?? 0),     '#141922'],
    ['Base IBS e CBS',    fisValor($fornecedor['base_ibs_cbs'] ?? 0),   '#606060'],
    ['Valor IBS',         fisValor($fornecedor['valor_ibs'] ?? 0),      '#2347C8'],
    ['Valor CBS',         fisValor($fornecedor['valor_cbs'] ?? 0),      '#2347C8'],
  ];
?>
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:16px; margin-bottom:24px">
  <?php foreach ($cards as [$rot, $val, $cor]): ?>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:14px 18px; border-left:4px solid <?= $cor ?>">
    <div style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase"><?= h($rot) ?></div>
    <div style="font-size:20px; font-weight:700; color:#141922; margin-top:4px"><?= h($val) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<form method="POST" action="?module=fiscal&amp;action=conferir">
  <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
  <input type="hidden" name="cnpj" value="<?= h($fornecedor['emitente_cnpj'] ?? '') ?>">
  <input type="hidden" name="f" value="1">
  <input type="hidden" name="data_ini" value="<?= h($filtro['data_ini']) ?>">
  <input type="hidden" name="data_fim" value="<?= h($filtro['data_fim']) ?>">
  <?php foreach ($filtro['filial_ids'] as $fid): ?>
  <input type="hidden" name="filial[]" value="<?= h($fid) ?>">
  <?php endforeach; ?>

  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden">
    <div style="padding:16px 20px; border-bottom:1px solid #EAEAEA; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px">
      <div style="font-size:20px; font-weight:700; color:#141922">
        Notas <span style="color:#888; font-size:14px; font-weight:400">(<?= fisNum(count($notas)) ?>)</span>
      </div>
      <?php if ($podeConferir && $notas !== []): ?>
      <div style="display:flex; gap:10px">
        <button type="submit" name="marcar" value="1"
                style="display:inline-flex; align-items:center; gap:8px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:10px 18px; font-size:14px; font-weight:700; cursor:pointer">
          <i data-lucide="check" width="16" height="16"></i> Marcar como conferidas
        </button>
        <button type="submit" name="marcar" value="0"
                style="display:inline-flex; align-items:center; gap:8px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; padding:10px 18px; font-size:14px; font-weight:700; cursor:pointer">
          <i data-lucide="rotate-ccw" width="16" height="16"></i> Desmarcar
        </button>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($notas === []): ?>
    <div style="padding:48px 24px; text-align:center; color:#888; font-size:14px">Nenhuma nota deste fornecedor no período.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table style="width:100%; border-collapse:collapse; font-size:13px">
        <thead>
          <tr style="background:#606060; color:#fff; text-align:left">
            <?php if ($podeConferir): ?>
            <th style="padding:10px 14px; width:36px">
              <input type="checkbox" id="fis-todos" title="Selecionar todas" style="cursor:pointer">
            </th>
            <?php endif; ?>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px">Nota</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px">Emissão</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px">Filial destino</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px; text-align:right">Total produtos</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px; text-align:right">Total NF</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px; text-align:right">Base IBS/CBS</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px; text-align:right">Valor IBS</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px; text-align:right">Valor CBS</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($notas as $i => $n): ?>
          <?php
            $conferida = (bool) $n['conferida'];
            $zebra = $i % 2 === 0 ? '#FFFFFF' : '#F8F8F8';
            $semGrupo = !((int) $n['tem_grupo_ibs_cbs']);
            // Zerado DENTRO do grupo pode ser correto (isenta, imune, fora do
            // campo de incidência). O que marca não-adequação é o grupo ausente.
            $titulo = $semGrupo
              ? 'O XML desta nota não trouxe o grupo IBS/CBS (vBCIBSCBS).'
              : '';
          ?>
          <tr style="background:<?= $zebra ?>; border-bottom:1px solid #EAEAEA"
              onmouseover="this.style.background='#EEF2FD'" onmouseout="this.style.background='<?= $zebra ?>'">
            <?php if ($podeConferir): ?>
            <td style="padding:10px 14px">
              <input type="checkbox" class="fis-chk" name="chave[]" value="<?= h($n['chave_nf']) ?>" style="cursor:pointer">
            </td>
            <?php endif; ?>
            <td style="padding:10px 14px; color:#141922; font-weight:700" title="<?= h($n['chave_nf']) ?>">
              <?= h($n['numero_nf']) ?>
              <?php if ($semGrupo): ?>
              <span title="<?= h($titulo) ?>"
                    style="display:inline-block; background:#EAEAEA; color:#888888; border-radius:8px; padding:2px 6px; font-size:10px; font-weight:700; text-transform:uppercase; margin-left:6px">sem grupo</span>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px; color:#141922; white-space:nowrap"><?= h(date('d/m/Y', strtotime((string) $n['data_emissao']))) ?></td>
            <td style="padding:10px 14px; color:#606060"><?= h($n['filial_nome'] ?? fisCnpj((string) $n['destinatario_cnpj'])) ?></td>
            <td style="padding:10px 14px; text-align:right; color:#141922"><?= h(fisValor($n['valor_produtos'])) ?></td>
            <td style="padding:10px 14px; text-align:right; color:#141922; font-weight:700"><?= h(fisValor($n['valor_nota'])) ?></td>
            <td style="padding:10px 14px; text-align:right; color:#606060" <?= $titulo !== '' ? 'title="' . h($titulo) . '"' : '' ?>><?= h(fisValor($n['base_ibs_cbs'])) ?></td>
            <td style="padding:10px 14px; text-align:right; color:<?= ((float) $n['valor_ibs']) > 0 ? '#141922' : '#888' ?>"><?= h(fisValor($n['valor_ibs'])) ?></td>
            <td style="padding:10px 14px; text-align:right; color:<?= ((float) $n['valor_cbs']) > 0 ? '#141922' : '#888' ?>"><?= h(fisValor($n['valor_cbs'])) ?></td>
            <td style="padding:10px 14px; white-space:nowrap">
              <?php if ($conferida): ?>
              <span style="display:inline-block; background:#D8F5E5; color:#00A960; border-radius:8px; padding:3px 8px; font-size:10px; font-weight:700; text-transform:uppercase"
                    title="<?= h(($n['conferido_por_nome'] ?? '') . ' · ' . ($n['conferido_em'] ? date('d/m/Y H:i', strtotime((string) $n['conferido_em'])) : '')) ?>">Conferida</span>
              <?php else: ?>
              <span style="display:inline-block; background:#EAEAEA; color:#888888; border-radius:8px; padding:3px 8px; font-size:10px; font-weight:700; text-transform:uppercase">Não conferida</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</form>

<?php if ($podeConferir && $notas !== []): ?>
<script>
  // "Selecionar todas" do cabeçalho da tabela.
  (function () {
    var todos = document.getElementById('fis-todos');
    if (!todos) return;
    todos.addEventListener('change', function () {
      document.querySelectorAll('.fis-chk').forEach(function (c) { c.checked = todos.checked; });
    });
  })();
</script>
<?php endif; ?>
