<?php
// @versao Ger_03 V_01_16/07/2026
/**
 * views/geral/paginas.php — Aba "Páginas e Arquivos" (somente leitura)
 *
 * Apresenta a versão de cada arquivo do sistema, lida diretamente do
 * cabeçalho @versao dos próprios arquivos. Nada aqui é editável — a tela
 * existe para auditar o que está publicado e proteger contra regressão
 * no deploy (ver cli/verificar_versao.php).
 *
 * Variáveis: $arquivosVersao (FileVersion::scan()), $modulosMapa (FileVersion::MODULOS)
 */
$arquivosVersao = $arquivosVersao ?? [];
$modulosMapa    = $modulosMapa ?? [];

$total      = count($arquivosVersao);
$comVersao  = count(array_filter($arquivosVersao, fn($a) => $a['codigo'] !== null));
$semVersao  = $total - $comVersao;

// Agrupa por módulo (na ordem já vinda do scan)
$grupos = [];
foreach ($arquivosVersao as $a) {
    $grupos[$a['modulo'] ?? '— Sem versão —'][] = $a;
}
?>

<!-- Cards resumo -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px; max-width:900px">
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:20px; border-left:4px solid #606060">
    <div style="font-size:12px; font-weight:700; color:#888; text-transform:uppercase">Arquivos monitorados</div>
    <div style="font-size:32px; font-weight:700; color:#141922"><?= $total ?></div>
  </div>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:20px; border-left:4px solid #00A960">
    <div style="font-size:12px; font-weight:700; color:#888; text-transform:uppercase">Com versão</div>
    <div style="font-size:32px; font-weight:700; color:#00A960"><?= $comVersao ?></div>
  </div>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:20px; border-left:4px solid <?= $semVersao > 0 ? '#B3261E' : '#EAEAEA' ?>">
    <div style="font-size:12px; font-weight:700; color:#888; text-transform:uppercase">Sem versão</div>
    <div style="font-size:32px; font-weight:700; color:<?= $semVersao > 0 ? '#B3261E' : '#888' ?>"><?= $semVersao ?></div>
  </div>
</div>

<!-- Mapa da nomenclatura (aberto, como referência) -->
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:24px; margin-bottom:24px; max-width:900px">
  <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px; display:flex; align-items:center; gap:8px">
    <i data-lucide="map" width="18" height="18" style="color:#2347C8"></i> Mapa da nomenclatura
  </h2>
  <p style="font-size:13px; color:#888; margin:0 0 16px">
    Padrão: <strong style="color:#141922">Prefixo_Seq V_Versão_Data</strong> — ex.:
    <code style="background:#F6F6F6; padding:2px 6px; border-radius:4px">Cap_01 V_01_16/07/2026</code>
    = tela Notas Captura, versão 01, de 16/07/2026.
    Sequência <strong>_01</strong> é a tela principal do módulo; <strong>_02 em diante</strong> são os arquivos
    internos dela (sync, controller, model, CSS/JS). A marca vive na primeira linha de cada arquivo
    (<code style="background:#F6F6F6; padding:2px 6px; border-radius:4px">@versao</code>) — esta tela apenas lê, não edita.
  </p>
  <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:8px">
    <?php foreach ($modulosMapa as $pfx => $nome): ?>
    <div style="display:flex; align-items:center; gap:10px; padding:8px 12px; background:#F6F6F6; border-radius:8px">
      <span style="background:#D8D4E4; color:#2347C8; font-size:10px; font-weight:700; text-transform:uppercase; padding:3px 8px; border-radius:8px; min-width:36px; text-align:center"><?= h($pfx) ?></span>
      <span style="font-size:13px; color:#141922"><?= h($nome) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Tabelas por módulo -->
<?php foreach ($grupos as $modulo => $itens): $ehSem = $modulo === '— Sem versão —'; ?>
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; margin-bottom:20px; overflow:hidden; max-width:1100px; <?= $ehSem ? 'border-left:4px solid #B3261E' : '' ?>">
  <div style="padding:16px 20px; border-bottom:1px solid #EAEAEA; display:flex; align-items:center; gap:10px">
    <h3 style="font-size:16px; font-weight:700; color:<?= $ehSem ? '#B3261E' : '#141922' ?>; margin:0"><?= h($modulo) ?></h3>
    <span style="background:#EAEAEA; color:#888; font-size:10px; font-weight:700; padding:3px 8px; border-radius:8px"><?= count($itens) ?> arquivo(s)</span>
  </div>
  <div style="overflow-x:auto">
    <table style="width:100%; border-collapse:collapse; font-size:13px">
      <thead>
        <tr style="background:#606060">
          <th style="padding:10px 16px; text-align:left; color:#fff; font-size:11px; text-transform:uppercase">Código</th>
          <th style="padding:10px 16px; text-align:left; color:#fff; font-size:11px; text-transform:uppercase">Versão</th>
          <th style="padding:10px 16px; text-align:left; color:#fff; font-size:11px; text-transform:uppercase">Data</th>
          <th style="padding:10px 16px; text-align:left; color:#fff; font-size:11px; text-transform:uppercase">Tipo</th>
          <th style="padding:10px 16px; text-align:left; color:#fff; font-size:11px; text-transform:uppercase">Arquivo</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($itens as $i => $a): $bg = $i % 2 === 0 ? '#FFFFFF' : '#F8F8F8'; ?>
        <tr style="background:<?= $bg ?>" onmouseover="this.style.background='#EEF2FD'" onmouseout="this.style.background='<?= $bg ?>'">
          <td style="padding:10px 16px; font-weight:700; color:#141922"><?= $a['codigo'] !== null ? h($a['codigo']) : '—' ?></td>
          <td style="padding:10px 16px">
            <?php if ($a['versao'] !== null): ?>
            <span style="background:#D8F5E5; color:#00A960; font-size:10px; font-weight:700; padding:3px 8px; border-radius:8px">V_<?= str_pad((string)$a['versao'], 2, '0', STR_PAD_LEFT) ?></span>
            <?php else: ?>
            <span style="background:#FFE8EE; color:#B3261E; font-size:10px; font-weight:700; text-transform:uppercase; padding:3px 8px; border-radius:8px">Sem versão</span>
            <?php endif; ?>
          </td>
          <td style="padding:10px 16px; color:#606060"><?= $a['data'] !== null ? h($a['data']) : '—' ?></td>
          <td style="padding:10px 16px; color:#888"><?= h($a['tipo']) ?></td>
          <td style="padding:10px 16px; color:#606060; font-family:Consolas, monospace; font-size:12px"><?= h($a['arquivo']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<div style="font-size:12px; color:#888; max-width:900px; margin-bottom:32px">
  <i data-lucide="shield-check" width="14" height="14" style="vertical-align:-2px; color:#00A960"></i>
  O deploy é protegido pelo verificador <code style="background:#F6F6F6; padding:2px 6px; border-radius:4px">cli/verificar_versao.php</code>:
  arquivos sem versão, com versão menor que a publicada, ou alterados sem subir a versão são <strong>bloqueados</strong> antes de sobrescrever o servidor.
</div>
