<?php
// @versao Ger_06 V_01_12/08/2026
/**
 * views/geral/horarios.php — aba "Horários de Acesso" do módulo Geral.
 *
 * Só a porta de entrada: a lista, o cadastro e as exceções continuam vivendo
 * em ?module=horarios. Aqui é a vitrine, com o resumo do que já existe, para o
 * item sair da barra lateral sem ficar escondido.
 */
require_once SRC . '/Models/AccessSchedule.php';

$horarios = AccessSchedule::listar();
$excVig   = count(AccessSchedule::listarExcecoes('', 'vigentes'));
$comLimite = 0;
foreach ($horarios as $hh) { $comLimite += (int) $hh['usuarios']; }

$semLimite = (int) Database::getInstance()->query(
    'SELECT COUNT(*) FROM usuarios u
      WHERE u.ativo = 1
        AND NOT EXISTS (SELECT 1 FROM nf_usuario_horario uh WHERE uh.usuario_id = u.id)'
)->fetchColumn();
?>

<div style="margin-bottom:20px">
  <h2 style="font-size:20px; font-weight:700; color:#141922; margin:0 0 4px">Horários de Acesso</h2>
  <p style="font-size:13px; color:#888; margin:0">
    Jornada em que cada pessoa pode entrar no sistema. Quem não tem horário atribuído entra a qualquer hora,
    e o super administrador nunca é bloqueado.
  </p>
</div>

<!-- Resumo -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:16px; margin-bottom:24px">
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:16px 18px; border-left:4px solid #2347C8">
    <div style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase">Horários cadastrados</div>
    <div style="font-size:28px; font-weight:700; color:#141922"><?= count($horarios) ?></div>
  </div>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:16px 18px; border-left:4px solid #FF8A2D">
    <div style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase">Pessoas com limite</div>
    <div style="font-size:28px; font-weight:700; color:#FF8A2D"><?= $comLimite ?></div>
  </div>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:16px 18px; border-left:4px solid #606060">
    <div style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase">Sem limite</div>
    <div style="font-size:28px; font-weight:700; color:#606060"><?= $semLimite ?></div>
  </div>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:16px 18px; border-left:4px solid #00A960">
    <div style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase">Liberações vigentes</div>
    <div style="font-size:28px; font-weight:700; color:#00A960"><?= $excVig ?></div>
  </div>
</div>

<!-- Horários -->
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden; margin-bottom:20px">
  <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #EAEAEA">
    <span style="font-size:15px; font-weight:700; color:#141922">Horários</span>
    <a href="?module=horarios&amp;action=editar"
       style="display:inline-flex; align-items:center; gap:6px; background:#2347C8; color:#fff; border-radius:4px; padding:8px 16px; font-size:13px; font-weight:700; text-decoration:none">
      <i data-lucide="plus" width="14" height="14"></i> Novo horário
    </a>
  </div>

  <?php if (empty($horarios)): ?>
  <div style="padding:32px 20px; text-align:center; color:#888; font-size:13px">
    Nenhum horário cadastrado — hoje todo mundo entra a qualquer hora.
  </div>
  <?php else: ?>
  <table style="width:100%; border-collapse:collapse">
    <tbody>
      <?php foreach ($horarios as $i => $hh): ?>
      <tr style="background:<?= $i % 2 ? '#F8F8F8' : '#fff' ?>; border-bottom:1px solid #F1F1F1">
        <td style="padding:12px 20px">
          <a href="?module=horarios&amp;action=editar&amp;id=<?= h($hh['id']) ?>"
             style="font-size:14px; font-weight:700; color:#141922; text-decoration:none"><?= h($hh['nome']) ?></a>
          <?php if ($hh['descricao']): ?>
          <div style="font-size:12px; color:#888"><?= h($hh['descricao']) ?></div>
          <?php endif; ?>
        </td>
        <td style="padding:12px 20px; font-size:13px; color:#606060; text-align:right; white-space:nowrap">
          <?= (int) $hh['usuarios'] ?> pessoa(s)
        </td>
        <td style="padding:12px 20px; text-align:right; width:110px">
          <?php if ((int) $hh['ativo'] === 1): ?>
          <span style="background:#D8F5E5; color:#00A960; font-size:10px; font-weight:700; padding:4px 10px; border-radius:8px; text-transform:uppercase">Ativo</span>
          <?php else: ?>
          <span style="background:#EAEAEA; color:#888; font-size:10px; font-weight:700; padding:4px 10px; border-radius:8px; text-transform:uppercase">Inativo</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<a href="?module=horarios&amp;action=excecoes"
   style="display:inline-flex; align-items:center; gap:8px; background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:10px 18px; font-size:14px; font-weight:700; text-decoration:none">
  <i data-lucide="calendar-check" width="16" height="16"></i>
  Ver liberações concedidas<?= $excVig > 0 ? ' (' . $excVig . ' vigente' . ($excVig === 1 ? '' : 's') . ')' : '' ?>
</a>
