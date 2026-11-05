<?php
// @versao Ace_12 V_01_12/08/2026
/**
 * views/horarios/index.php — lista de horários de acesso.
 *
 * Variáveis: $horarios (com a contagem de usuários de cada um)
 */
$horarios = $horarios ?? [];
?>
<?php include VIEWS . '/components/alert.php'; ?>

<div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:28px; flex-wrap:wrap; gap:12px">
  <div>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
      Horários <span style="color:#2347C8">de Acesso</span>
    </h1>
    <p style="font-size:14px; color:#888; margin:0">
      Jornada em que cada pessoa pode entrar no sistema. Quem não tem horário atribuído entra a qualquer hora.
    </p>
  </div>
  <a href="?module=horarios&amp;action=editar"
     style="display:inline-flex; align-items:center; gap:8px; background:#2347C8; color:#fff; border-radius:4px; padding:11px 20px; font-size:14px; font-weight:700; text-decoration:none">
    <i data-lucide="plus" width="16" height="16"></i>
    Novo horário
  </a>
</div>

<?php if (empty($horarios)): ?>

<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:48px; text-align:center">
  <i data-lucide="clock" width="40" height="40" style="color:#EAEAEA; display:block; margin:0 auto 12px"></i>
  <p style="font-size:15px; font-weight:700; color:#141922; margin:0 0 6px">Nenhum horário cadastrado</p>
  <p style="font-size:13px; color:#888; margin:0">
    Sem horários, todo mundo entra a qualquer hora — que é como o sistema funciona hoje.
  </p>
</div>

<?php else: ?>

<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden">
  <table style="width:100%; border-collapse:collapse">
    <thead>
      <tr style="background:#606060">
        <th style="text-align:left;   padding:12px 20px; color:#fff; font-size:13px; font-weight:700; text-transform:uppercase">Horário</th>
        <th style="text-align:left;   padding:12px 20px; color:#fff; font-size:13px; font-weight:700; text-transform:uppercase">Descrição</th>
        <th style="text-align:center; padding:12px 20px; color:#fff; font-size:13px; font-weight:700; text-transform:uppercase">Pessoas</th>
        <th style="text-align:center; padding:12px 20px; color:#fff; font-size:13px; font-weight:700; text-transform:uppercase">Tolerância</th>
        <th style="text-align:center; padding:12px 20px; color:#fff; font-size:13px; font-weight:700; text-transform:uppercase">Status</th>
        <th style="width:60px"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($horarios as $i => $h): ?>
      <tr style="background:<?= $i % 2 ? '#F8F8F8' : '#fff' ?>; border-bottom:1px solid #EAEAEA">
        <td style="padding:14px 20px; font-size:14px; font-weight:700; color:#141922">
          <a href="?module=horarios&amp;action=editar&amp;id=<?= h($h['id']) ?>"
             style="color:#141922; text-decoration:none"><?= h($h['nome']) ?></a>
        </td>
        <td style="padding:14px 20px; font-size:13px; color:#606060"><?= h($h['descricao'] ?: '—') ?></td>
        <td style="padding:14px 20px; font-size:14px; color:#141922; text-align:center"><?= (int) $h['usuarios'] ?></td>
        <td style="padding:14px 20px; font-size:13px; color:#606060; text-align:center">
          <?= (int) $h['tolerancia_minutos'] > 0 ? (int) $h['tolerancia_minutos'] . ' min' : '—' ?>
        </td>
        <td style="padding:14px 20px; text-align:center">
          <?php if ((int) $h['ativo'] === 1): ?>
            <span style="background:#D8F5E5; color:#00A960; font-size:10px; font-weight:700; padding:4px 10px; border-radius:8px; text-transform:uppercase">Ativo</span>
          <?php else: ?>
            <span style="background:#EAEAEA; color:#888; font-size:10px; font-weight:700; padding:4px 10px; border-radius:8px; text-transform:uppercase">Inativo</span>
          <?php endif; ?>
        </td>
        <td style="padding:14px 20px; text-align:right">
          <a href="?module=horarios&amp;action=editar&amp;id=<?= h($h['id']) ?>"
             title="Editar" style="color:#606060">
            <i data-lucide="pencil" width="16" height="16"></i>
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<p style="font-size:12px; color:#888; margin:14px 2px 0">
  Horário <strong>inativo</strong> não barra ninguém — serve para desligar a regra sem perder o cadastro.
  O <strong>super administrador</strong> nunca é bloqueado, em nenhum horário.
</p>

<?php endif; ?>
