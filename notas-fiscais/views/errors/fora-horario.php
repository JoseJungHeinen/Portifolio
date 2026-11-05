<?php
// @versao Ace_10 V_03_18/09/2026
/**
 * Tela de bloqueio por horário de acesso (migration 043).
 *
 * Nunca só "acesso negado": a política é solicitar a exceção ANTES de ser
 * bloqueado, então esta tela precisa dizer qual é o horário da pessoa, quando
 * o acesso volta, e que o pedido se faz durante o expediente. Sem isso, quem
 * bate aqui não tem o que fazer além de ligar para alguém.
 */
$b = $_SESSION['bloqueio_horario'] ?? [];
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
$nome = $_SESSION['usuario_nome'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Fora do horário de acesso — Exemplo financeiro</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'Futura-Book','Futura LT Book','Segoe UI',Arial,sans-serif;
           background: #F6F6F6; display: flex; align-items: center; justify-content: center;
           min-height: 100vh; margin: 0; padding: 20px; }
    .box { background: #fff; border-radius: 12px; padding: 40px; max-width: 520px; width: 100%;
           box-shadow: 0 2px 8px rgba(0,0,0,.10); border-left: 4px solid #FF8A2D; }
    .ico { width: 52px; height: 52px; border-radius: 12px; background: #FFF3E0;
           display: flex; align-items: center; justify-content: center; margin-bottom: 20px; }
    h1 { font-family: 'Futura-Bold','Futura LT Bold','Segoe UI',Arial,sans-serif;
         font-size: 24px; color: #141922; margin: 0 0 8px; }
    h1 span { color: #2347C8; }
    p  { color: #606060; font-size: 14px; line-height: 1.6; margin: 0 0 16px; }
    .quadro { background: #F6F6F6; border-radius: 8px; padding: 16px 18px; margin: 0 0 20px; }
    .linha { display: flex; justify-content: space-between; gap: 16px; font-size: 13px;
             padding: 6px 0; color: #606060; }
    .linha strong { color: #141922; text-align: right; }
    .aviso { font-size: 13px; color: #856404; background: #7A4FD0; padding: 12px 14px;
             border-radius: 8px; margin: 0 0 20px; line-height: 1.5; }
    .btns { display: flex; gap: 10px; flex-wrap: wrap; }
    .btn  { display: inline-flex; align-items: center; padding: 11px 22px; border-radius: 4px;
            font-family: 'Futura-Bold','Segoe UI',Arial,sans-serif; font-size: 14px;
            text-decoration: none; border: 1.5px solid transparent; }
    .btn--sair { background: #2347C8; color: #fff; }
    .btn--volta{ background: transparent; color: #606060; border-color: #EAEAEA; }
  </style>
</head>
<body>
  <div class="box">
    <div class="ico">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#FF8A2D"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
      </svg>
    </div>

    <h1>Fora do <span>horário de acesso</span></h1>
    <p>
      <?= $nome !== '' ? h($nome) . ', o' : 'O' ?> seu acesso ao sistema está
      liberado apenas dentro da sua jornada. Neste momento ele está fechado.
    </p>

    <div class="quadro">
      <?php if (!empty($b['horario'])): ?>
      <div class="linha"><span>Seu horário</span><strong><?= h($b['horario']) ?></strong></div>
      <?php endif; ?>
      <div class="linha">
        <span>Hoje</span>
        <strong><?= h($b['janela'] ?? 'fechado') ?></strong>
      </div>
      <div class="linha">
        <span>Agora</span>
        <strong><?= h(date('d/m/Y H:i')) ?></strong>
      </div>
      <?php if (!empty($b['volta'])): ?>
      <div class="linha">
        <span>O acesso volta</span>
        <strong style="color:#00A960"><?= h($b['volta']) ?></strong>
      </div>
      <?php endif; ?>
    </div>

    <div class="aviso">
      <strong>Precisa trabalhar fora desse horário?</strong>
      A liberação deve ser solicitada <u>durante o expediente</u>, em
      <strong>Meu Perfil → Solicitar acesso em horário de exceção</strong>.
      O pedido passa pelo RH e, uma vez aprovado, o acesso é liberado
      automaticamente no dia e na faixa autorizados.
    </div>

    <div class="btns">
      <a class="btn btn--volta" href="<?= h(APP_PUBLIC_URL) ?>/index.php">Tentar novamente</a>
      <a class="btn btn--sair"  href="<?= h(APP_PUBLIC_URL) ?>/index.php?module=auth&amp;action=logout">Sair</a>
    </div>
  </div>
</body>
</html>
