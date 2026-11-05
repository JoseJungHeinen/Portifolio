<?php
// @versao Ace_14 V_01_12/08/2026
/**
 * views/horarios/excecoes.php — liberações de acesso fora do horário.
 *
 * Onde o RH confere o que já foi concedido e por qual processo. A exceção
 * vencida aparece marcada como tal: ela é gravada mesmo quando a aprovação sai
 * depois da janela pedida (decisão 10), e some-la daria a impressão errada.
 *
 * Variáveis: $excecoes, $usuarios, $usuarioId, $quando
 */
$excecoes  = $excecoes  ?? [];
$usuarios  = $usuarios  ?? [];
$usuarioId = $usuarioId ?? '';
$quando    = $quando    ?? 'vigentes';

$badge = [
    'vigente' => ['bg' => '#D8F5E5', 'cor' => '#00A960', 'txt' => 'Vigente'],
    'hoje'    => ['bg' => '#FFF3E0', 'cor' => '#FF8A2D', 'txt' => 'Hoje'],
    'vencida' => ['bg' => '#EAEAEA', 'cor' => '#888888', 'txt' => 'Vencida'],
];
?>
<?php include VIEWS . '/components/alert.php'; ?>

<div style="display:flex; align-items:center; gap:6px; margin-bottom:18px">
  <a href="?module=horarios&amp;action=index"
     style="display:flex; align-items:center; gap:5px; font-size:13px; color:#888; text-decoration:none">
    <i data-lucide="clock" width="13" height="13"></i> Horários de Acesso
  </a>
  <span style="color:#DFDFDF">/</span>
  <span style="font-size:13px; color:#141922">Exceções</span>
</div>

<div style="margin-bottom:24px">
  <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
    Exceções <span style="color:#2347C8">de horário</span>
  </h1>
  <p style="font-size:14px; color:#888; margin:0">
    Liberações concedidas para acesso fora da jornada. Cada uma nasce da aprovação de um processo.
  </p>
</div>

<!-- Filtros -->
<form method="GET" style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:16px 20px; margin-bottom:20px; display:flex; gap:12px; align-items:center; flex-wrap:wrap">
  <input type="hidden" name="module" value="horarios">
  <input type="hidden" name="action" value="excecoes">

  <select name="usuario_id" onchange="this.form.submit()"
          style="height:40px; border:1.5px solid #E5E7EB; border-radius:8px; padding:0 14px; font-size:14px; color:#606060; background:#fff; cursor:pointer; outline:none">
    <option value="">Todos os funcionários</option>
    <?php foreach ($usuarios as $u): ?>
    <option value="<?= h($u['id']) ?>" <?= $usuarioId === $u['id'] ? 'selected' : '' ?>><?= h($u['nome']) ?></option>
    <?php endforeach; ?>
  </select>

  <select name="quando" onchange="this.form.submit()"
          style="height:40px; border:1.5px solid #E5E7EB; border-radius:8px; padding:0 14px; font-size:14px; color:#606060; background:#fff; cursor:pointer; outline:none">
    <option value="vigentes" <?= $quando === 'vigentes' ? 'selected' : '' ?>>De hoje em diante</option>
    <option value="todas"    <?= $quando === 'todas'    ? 'selected' : '' ?>>Todas, inclusive vencidas</option>
  </select>

  <span style="font-size:13px; color:#888; margin-left:auto"><?= count($excecoes) ?> liberação(ões)</span>
</form>

<?php if (empty($excecoes)): ?>

<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:48px; text-align:center">
  <i data-lucide="calendar-off" width="40" height="40" style="color:#EAEAEA; display:block; margin:0 auto 12px"></i>
  <p style="font-size:15px; font-weight:700; color:#141922; margin:0 0 6px">Nenhuma liberação neste recorte</p>
  <p style="font-size:13px; color:#888; margin:0">
    As liberações aparecem aqui assim que o processo de solicitação é aprovado no fim do fluxo.
  </p>
</div>

<?php else: ?>

<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden">
  <table style="width:100%; border-collapse:collapse">
    <thead>
      <tr style="background:#606060">
        <?php foreach (['Funcionário','Data','Janela liberada','Situação','Processo'] as $th): ?>
        <th style="text-align:left; padding:12px 20px; color:#fff; font-size:13px; font-weight:700; text-transform:uppercase"><?= h($th) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($excecoes as $i => $e):
        $sit = AccessSchedule::situacaoExcecao($e);
        $b   = $badge[$sit];
      ?>
      <tr style="background:<?= $i % 2 ? '#F8F8F8' : '#fff' ?>; border-bottom:1px solid #EAEAEA">
        <td style="padding:14px 20px">
          <div style="font-size:14px; font-weight:700; color:#141922"><?= h($e['usuario_nome']) ?></div>
          <div style="font-size:12px; color:#888"><?= h($e['email']) ?></div>
        </td>
        <td style="padding:14px 20px; font-size:14px; color:#141922">
          <?= h(date('d/m/Y', strtotime((string) $e['data']))) ?>
        </td>
        <td style="padding:14px 20px; font-size:14px; color:#141922">
          <?= h(substr((string) $e['hora_inicio'], 0, 5)) ?> – <?= h(substr((string) $e['hora_fim'], 0, 5)) ?>
        </td>
        <td style="padding:14px 20px">
          <span style="background:<?= $b['bg'] ?>; color:<?= $b['cor'] ?>; font-size:10px; font-weight:700; padding:4px 10px; border-radius:8px; text-transform:uppercase"><?= h($b['txt']) ?></span>
        </td>
        <td style="padding:14px 20px; font-size:13px">
          <?php if (!empty($e['processo_id'])): ?>
          <a href="?module=processos&amp;action=detalhe&amp;id=<?= h($e['processo_id']) ?>"
             style="color:#2347C8; font-weight:700; text-decoration:none"><?= h($e['processo_codigo'] ?: 'ver processo') ?></a>
          <?php else: ?>
          <span style="color:#888">liberação manual</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<p style="font-size:12px; color:#888; margin:14px 2px 0">
  <strong>Vencida</strong> é a liberação cuja janela já passou — ela continua registrada, mas não libera nada.
  Acontece quando a aprovação sai depois do horário pedido.
</p>

<?php endif; ?>
