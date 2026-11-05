<?php
// @versao Ace_13 V_01_12/08/2026
/**
 * views/horarios/detail.php — cadastro de um horário de acesso.
 *
 * A grade é 7 linhas × 2 turnos, exatamente como a jornada é escrita no papel:
 *   Seg  07:45–12:00  e  13:30–19:00
 * Turno em branco = fechado. Dia com os dois em branco = dia fechado.
 *
 * Variáveis: $horario (null = novo), $membros, $usuarios
 */
$horario  = $horario  ?? null;
$membros  = $membros  ?? [];
$usuarios = $usuarios ?? [];
$isEdit   = $horario !== null;
$faixas   = $horario['faixas'] ?? [];

$inp = 'height:38px; border:1px solid #DFDFDF; border-radius:4px; padding:0 10px; font-size:14px; color:#141922; box-sizing:border-box; outline:none;';
?>
<?php include VIEWS . '/components/alert.php'; ?>
<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">

<div style="display:flex; align-items:center; gap:6px; margin-bottom:18px">
  <a href="?module=horarios&amp;action=index"
     style="display:flex; align-items:center; gap:5px; font-size:13px; color:#888; text-decoration:none">
    <i data-lucide="clock" width="13" height="13"></i> Horários de Acesso
  </a>
  <span style="color:#DFDFDF">/</span>
  <span style="font-size:13px; color:#141922"><?= $isEdit ? h($horario['nome']) : 'Novo horário' ?></span>
</div>

<form method="POST" action="?module=horarios&amp;action=salvar" id="form-horario">
  <?= Csrf::field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= h($horario['id']) ?>"><?php endif; ?>

  <div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px">
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0">
      <?= $isEdit ? h($horario['nome']) : 'Novo' ?> <span style="color:#2347C8">horário</span>
    </h1>
    <div style="display:flex; gap:8px">
      <a href="?module=horarios&amp;action=index"
         style="display:inline-flex; align-items:center; height:44px; padding:0 18px; background:#EAEAEA; color:#606060; border-radius:4px; font-size:14px; font-weight:700; text-decoration:none">
        Cancelar
      </a>
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; height:44px; padding:0 22px; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">
        <i data-lucide="check" width="16" height="16"></i> Salvar
      </button>
    </div>
  </div>

  <!-- Dados do horário -->
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:24px; margin-bottom:20px">
    <div style="display:grid; grid-template-columns:1fr 1fr 160px; gap:16px; align-items:end">
      <div>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
          Nome do horário <span style="color:#B3261E">*</span>
        </label>
        <input type="text" name="nome" required maxlength="60" style="<?= $inp ?> width:100%"
               value="<?= h($horario['nome'] ?? '') ?>" placeholder="Ex.: Comercial">
      </div>
      <div>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Descrição</label>
        <input type="text" name="descricao" maxlength="255" style="<?= $inp ?> width:100%"
               value="<?= h($horario['descricao'] ?? '') ?>" placeholder="Quem usa este horário">
      </div>
      <div>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Tolerância</label>
        <div style="display:flex; align-items:center; gap:8px">
          <input type="number" name="tolerancia_minutos" min="0" max="120" style="<?= $inp ?> width:80px"
                 value="<?= (int) ($horario['tolerancia_minutos'] ?? 0) ?>">
          <span style="font-size:13px; color:#888">min</span>
        </div>
      </div>
    </div>

    <p style="font-size:12px; color:#888; margin:10px 0 0">
      A <strong>tolerância</strong> são minutos de folga depois do fim de cada turno — evita que alguém
      perca um formulário preenchido por ser desligado exatamente às 12:00.
    </p>

    <label style="display:flex; align-items:center; gap:10px; margin-top:16px; cursor:pointer">
      <input type="checkbox" name="ativo" value="1" <?= (!$isEdit || (int) $horario['ativo'] === 1) ? 'checked' : '' ?>
             style="width:16px; height:16px; accent-color:#2347C8; cursor:pointer">
      <span style="font-size:14px; color:#141922">
        <strong>Ativo</strong>
        <span style="color:#888">— desmarcado, este horário deixa de barrar quem está nele</span>
      </span>
    </label>
  </div>

  <!-- Grade de dias -->
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:24px; margin-bottom:20px">
    <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px">Jornada</h2>
    <p style="font-size:13px; color:#888; margin:0 0 18px">
      Deixe em branco para fechado. Um dia com os dois turnos em branco fica fechado o dia inteiro.
    </p>

    <table style="width:100%; border-collapse:collapse">
      <thead>
        <tr>
          <th style="text-align:left; padding:8px 10px; font-size:12px; font-weight:700; color:#888; text-transform:uppercase">Dia</th>
          <th colspan="2" style="text-align:left; padding:8px 10px; font-size:12px; font-weight:700; color:#888; text-transform:uppercase">1º turno</th>
          <th colspan="2" style="text-align:left; padding:8px 10px; font-size:12px; font-weight:700; color:#888; text-transform:uppercase">2º turno</th>
          <th style="width:90px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (AccessSchedule::DIAS as $num => $label):
          $f = $faixas[$num] ?? [];
          $fds = ($num === 0 || $num === 6);
        ?>
        <tr style="background:<?= $fds ? '#FBFBFB' : '#fff' ?>; border-bottom:1px solid #F1F1F1">
          <td style="padding:8px 10px; font-size:14px; font-weight:700; color:<?= $fds ? '#888' : '#141922' ?>">
            <?= h($label) ?>
          </td>
          <?php foreach ([['t1_inicio', 't1_fim'], ['t2_inicio', 't2_fim']] as [$ci, $cf]): ?>
          <td style="padding:8px 4px 8px 10px">
            <input type="time" name="faixas[<?= $num ?>][<?= $ci ?>]" style="<?= $inp ?> width:110px"
                   value="<?= h(substr((string) ($f[$ci] ?? ''), 0, 5)) ?>">
          </td>
          <td style="padding:8px 10px 8px 4px">
            <span style="font-size:12px; color:#888; margin-right:6px">às</span>
            <input type="time" name="faixas[<?= $num ?>][<?= $cf ?>]" style="<?= $inp ?> width:110px"
                   value="<?= h(substr((string) ($f[$cf] ?? ''), 0, 5)) ?>">
          </td>
          <?php endforeach; ?>
          <td style="padding:8px 10px; text-align:right">
            <button type="button" onclick="limparDia(<?= $num ?>)"
                    style="background:none; border:none; color:#888; font-size:12px; cursor:pointer; text-decoration:underline">
              limpar
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top:16px; padding-top:16px; border-top:1px solid #EAEAEA">
      <button type="button" onclick="aplicarPadrao()"
              style="display:inline-flex; align-items:center; gap:6px; background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:8px 14px; font-size:13px; font-weight:700; cursor:pointer">
        <i data-lucide="wand-2" width="14" height="14"></i>
        Preencher seg–sex 07:45–12:00 e 13:30–19:00
      </button>
    </div>
  </div>
</form>

<?php if ($isEdit): ?>
<!-- Pessoas neste horário -->
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:24px">
  <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px">Pessoas neste horário</h2>
  <p style="font-size:13px; color:#888; margin:0 0 16px">
    Quem for desmarcado fica <strong>sem limite</strong> de horário. Quem já está em outro horário
    aparece marcado nele — uma pessoa tem um horário só.
  </p>

  <form method="POST" action="?module=horarios&amp;action=atribuir">
    <?= Csrf::field() ?>
    <input type="hidden" name="horario_id" value="<?= h($horario['id']) ?>">

    <div style="max-height:340px; overflow-y:auto; border:1px solid #EAEAEA; border-radius:8px">
      <?php foreach ($usuarios as $u):
        $doOutro = !empty($u['horario_id']) && $u['horario_id'] !== $horario['id'];
        $marcado = ($u['horario_id'] ?? '') === $horario['id'];
      ?>
      <label style="display:flex; align-items:center; gap:12px; padding:10px 16px; border-bottom:1px solid #F5F5F5; cursor:<?= $doOutro ? 'not-allowed' : 'pointer' ?>; opacity:<?= $doOutro ? '0.55' : '1' ?>">
        <input type="checkbox" name="usuarios[]" value="<?= h($u['id']) ?>"
               <?= $marcado ? 'checked' : '' ?> <?= $doOutro ? 'disabled' : '' ?>
               style="width:16px; height:16px; accent-color:#2347C8">
        <span style="flex:1; font-size:14px; color:#141922"><?= h($u['nome']) ?></span>
        <span style="font-size:12px; color:#888"><?= h($u['email']) ?></span>
        <span style="font-size:10px; font-weight:700; text-transform:uppercase; padding:2px 8px; border-radius:8px; background:#EAEAEA; color:#606060">
          <?= h($u['perfil']) ?>
        </span>
        <?php if ($doOutro): ?>
        <span style="font-size:10px; font-weight:700; color:#FF8A2D">em outro horário</span>
        <?php endif; ?>
      </label>
      <?php endforeach; ?>
    </div>

    <button type="submit"
            style="margin-top:16px; display:inline-flex; align-items:center; gap:8px; height:40px; padding:0 20px; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">
      <i data-lucide="users" width="16" height="16"></i> Salvar pessoas
    </button>
  </form>
</div>
<?php else: ?>
<p style="font-size:13px; color:#888; margin:0 2px">
  Salve o horário primeiro para poder atribuir pessoas a ele.
</p>
<?php endif; ?>

<script>
function limparDia(dia) {
  document.querySelectorAll('[name^="faixas[' + dia + ']"]').forEach(function (i) { i.value = ''; });
}
function aplicarPadrao() {
  [1, 2, 3, 4, 5].forEach(function (d) {
    var v = { t1_inicio: '07:45', t1_fim: '12:00', t2_inicio: '13:30', t2_fim: '19:00' };
    Object.keys(v).forEach(function (c) {
      var el = document.querySelector('[name="faixas[' + d + '][' + c + ']"]');
      if (el) el.value = v[c];
    });
  });
}
</script>
