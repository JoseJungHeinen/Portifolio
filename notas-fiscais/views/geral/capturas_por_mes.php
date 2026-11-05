<?php
// @versao Ger_11 V_03_01/10/2026
/**
 * views/geral/capturas_por_mes.php — card "Notas capturadas por mês", usado
 * nas abas Brobot e NF-e / NFS-e do módulo Geral.
 *
 * Variáveis de entrada:
 *   $capPorMes     linhas de GeneralController::capturasPorMes()
 *   $capDescricao  HTML do texto explicativo (já escapado pelo chamador)
 *   $capVazio      texto quando não há nenhuma nota
 *   $cardStyle     estilo de card da página
 */
$capPorMes    = $capPorMes ?? [];
$capDescricao = $capDescricao ?? '';
$capVazio     = $capVazio ?? 'Nenhuma nota capturada até agora.';
$capComOrigem = $capComOrigem ?? false;   // true: acrescenta Direta / Brobot / Outras
?>
<div style="<?= $cardStyle ?>; max-width:<?= $capComOrigem ? '960' : '760' ?>px; margin-bottom:20px">
  <h3 style="font-size:15px; font-weight:700; color:#141922; margin:0 0 4px">Notas capturadas por <span style="color:#2347C8">mês</span></h3>
  <?php if ($capDescricao !== ''): ?>
  <p style="font-size:13px; color:#888; margin:0 0 16px"><?= $capDescricao ?></p>
  <?php else: ?>
  <div style="height:12px"></div>
  <?php endif; ?>
  <?php if (empty($capPorMes)): ?>
    <p style="font-size:13px; color:#888; margin:0"><?= h($capVazio) ?></p>
  <?php else: ?>
  <?php
    $mesesPt = [1=>'jan',2=>'fev',3=>'mar',4=>'abr',5=>'mai',6=>'jun',
                7=>'jul',8=>'ago',9=>'set',10=>'out',11=>'nov',12=>'dez'];
    // Barra proporcional ao mês de maior volume — leitura rápida da tendência.
    $maxMes = 0;
    foreach ($capPorMes as $m) { $maxMes = max($maxMes, (int)$m['total']); }
    $maxMes = max($maxMes, 1);
    $th = 'padding:8px 12px; font-weight:700; text-transform:uppercase; font-size:12px';
  ?>
  <div style="overflow-x:auto">
    <table style="width:100%; border-collapse:collapse; font-size:13px">
      <thead>
        <tr style="background:#606060; color:#fff; text-align:left">
          <th style="<?= $th ?>">Mês</th>
          <th style="<?= $th ?>; text-align:right; width:150px">Notas</th>
          <th style="<?= $th ?>; text-align:right; width:70px">NF-e</th>
          <th style="<?= $th ?>; text-align:right; width:70px">CT-e</th>
          <th style="<?= $th ?>; text-align:right; width:70px">NFS-e</th>
          <th style="<?= $th ?>; text-align:right; width:70px">CT-e OS</th>
          <?php if ($capComOrigem): ?>
          <th style="<?= $th ?>; text-align:right; width:80px" title="NF-e na SEFAZ e NFS-e no Portal Nacional">Direta</th>
          <th style="<?= $th ?>; text-align:right; width:80px">Brobot</th>
          <th style="<?= $th ?>; text-align:right; width:80px" title="Qualquer outra origem (importação manual etc.)">Outras</th>
          <?php endif; ?>
          <th style="<?= $th ?>; text-align:right; width:95px">Vinculadas</th>
        </tr>
      </thead>
      <tbody>
        <?php
          $tTot = 0; $tNfe = 0; $tCte = 0; $tNfse = 0; $tCtos = 0; $tVinc = 0; $tDir = 0; $tBro = 0; $tOut = 0;
          foreach ($capPorMes as $i => $m):
            [$ano, $mes] = array_pad(explode('-', (string)$m['mes']), 2, '');
            $rotulo = ($mesesPt[(int)$mes] ?? '?') . '/' . $ano;
            $tot = (int)$m['total'];
            $tTot += $tot;      $tNfe  += (int)$m['nfe'];  $tCte  += (int)$m['cte'];
            $tNfse += (int)$m['nfse']; $tCtos += (int)$m['ctos']; $tVinc += (int)$m['vinculadas'];
            $tDir  += (int)($m['direta'] ?? 0); $tBro += (int)($m['brobot'] ?? 0); $tOut += (int)($m['outras'] ?? 0);
        ?>
        <tr style="background:<?= $i % 2 ? '#F8F8F8' : '#fff' ?>; border-bottom:1px solid #EAEAEA">
          <td style="padding:8px 12px; color:#141922; font-weight:700"><?= h($rotulo) ?></td>
          <td style="padding:8px 12px; text-align:right; color:#141922; font-weight:700; white-space:nowrap">
            <span style="display:inline-block; vertical-align:middle; height:8px; border-radius:4px; background:#2347C8;
                         width:<?= max(2, (int) round($tot * 60 / $maxMes)) ?>px; margin-right:8px"></span>
            <?= number_format($tot, 0, ',', '.') ?>
          </td>
          <td style="padding:8px 12px; text-align:right; color:#606060"><?= number_format((int)$m['nfe'], 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#606060"><?= number_format((int)$m['cte'], 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#606060"><?= number_format((int)$m['nfse'], 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#606060"><?= number_format((int)$m['ctos'], 0, ',', '.') ?></td>
          <?php if ($capComOrigem): ?>
          <td style="padding:8px 12px; text-align:right; color:#606060"><?= number_format((int)($m['direta'] ?? 0), 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#606060"><?= number_format((int)($m['brobot'] ?? 0), 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#606060"><?= number_format((int)($m['outras'] ?? 0), 0, ',', '.') ?></td>
          <?php endif; ?>
          <td style="padding:8px 12px; text-align:right; color:#00A960; font-weight:700"><?= number_format((int)$m['vinculadas'], 0, ',', '.') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#EAEAEA; border-top:2px solid #606060">
          <td style="padding:8px 12px; color:#141922; font-weight:700">TOTAL</td>
          <td style="padding:8px 12px; text-align:right; color:#141922; font-weight:700"><?= number_format($tTot, 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#606060; font-weight:700"><?= number_format($tNfe, 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#606060; font-weight:700"><?= number_format($tCte, 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#606060; font-weight:700"><?= number_format($tNfse, 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#606060; font-weight:700"><?= number_format($tCtos, 0, ',', '.') ?></td>
          <?php if ($capComOrigem): ?>
          <td style="padding:8px 12px; text-align:right; color:#606060; font-weight:700"><?= number_format($tDir, 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#606060; font-weight:700"><?= number_format($tBro, 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#606060; font-weight:700"><?= number_format($tOut, 0, ',', '.') ?></td>
          <?php endif; ?>
          <td style="padding:8px 12px; text-align:right; color:#00A960; font-weight:700"><?= number_format($tVinc, 0, ',', '.') ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>
