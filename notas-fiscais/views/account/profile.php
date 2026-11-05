<?php
// @versao Cta_01 V_03_12/08/2026
/**
 * views/account/profile.php
 * Meu Perfil — dados do usuário logado + troca da própria senha.
 *
 * Variáveis esperadas:
 *   $currentUser  array  Auth::user()
 *   $dados        array  perfil, ultimo_acesso, filial_nome, setor_nome
 *   $flash        array|null
 */

$currentUser = $currentUser ?? [];
$dados       = $dados       ?? [];

$perfisLabel = [
    'solicitante' => 'Solicitante',
    'almoxarife'  => 'Almoxarife',
    'comprador'   => 'Comprador',
    'gestor'      => 'Gestor',
    'diretor'     => 'Diretor',
    'admin'       => 'Administrador (cadastros)',
    'super_admin' => 'Super Administrador',
];

$nome  = $currentUser['nome']  ?? '';
$email = $currentUser['email'] ?? '';
$perfil = $dados['perfil'] ?? ($currentUser['perfil'] ?? '');

$partes   = preg_split('/\s+/', trim($nome));
$iniciais = mb_strtoupper(mb_substr($nome, 0, 1));
if (count($partes) > 1) { $iniciais .= mb_strtoupper(mb_substr(end($partes), 0, 1)); }
?>
<?php include VIEWS . '/components/alert.php'; ?>

<!-- ── Cabeçalho ─────────────────────────────────────────────── -->
<div style="margin-bottom:24px;">
  <h1 style="font-size:var(--text-h1);font-weight:700;color:var(--color-dark);margin:0 0 4px;">
    Meu <span style="color:var(--color-primary);">Perfil</span>
  </h1>
  <p style="font-size:var(--text-body);color:var(--color-gray-medium);margin:0;">
    Seus dados de acesso ao módulo de Notas Fiscais.
  </p>
</div>

<div style="display:flex;flex-direction:column;gap:var(--space-5);max-width:520px;">

  <!-- ── Dados do usuário ────────────────────────────────────── -->
  <div class="card">
    <div style="display:flex;align-items:center;gap:var(--space-4);margin-bottom:var(--space-5);">
      <div style="width:56px;height:56px;border-radius:50%;background:var(--color-tag-bg);color:var(--color-primary);display:flex;align-items:center;justify-content:center;font-size:var(--text-h3);font-weight:700;flex-shrink:0;">
        <?= h($iniciais) ?>
      </div>
      <div>
        <div style="font-size:var(--text-h3);font-weight:700;color:var(--color-dark);"><?= h($nome) ?></div>
        <div style="font-size:var(--text-body-sm);color:var(--color-gray-medium);"><?= h($email) ?></div>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:var(--space-3);">
      <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--color-gray-light);padding-bottom:var(--space-2);">
        <span style="font-size:var(--text-body-sm);color:var(--color-gray-medium);">Perfil</span>
        <span class="badge badge--primary"><?= h($perfisLabel[$perfil] ?? ucfirst($perfil)) ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--color-gray-light);padding-bottom:var(--space-2);">
        <span style="font-size:var(--text-body-sm);color:var(--color-gray-medium);">Loja</span>
        <span style="font-size:var(--text-body-sm);color:var(--color-dark);font-weight:700;"><?= h($dados['filial_nome'] ?? '—') ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;">
        <span style="font-size:var(--text-body-sm);color:var(--color-gray-medium);">Departamento</span>
        <span style="font-size:var(--text-body-sm);color:var(--color-dark);font-weight:700;"><?= h($dados['setor_nome'] ?? '—') ?></span>
      </div>
    </div>
  </div>

  <?php
  // ── Horário de acesso (migration 043) ─────────────────────────
  // Só aparece para quem TEM horário — quem não tem entra a qualquer hora e
  // não precisa saber que a regra existe.
  require_once SRC . '/Models/AccessSchedule.php';
  $meuHorario = AccessSchedule::doUsuario((string) ($dados['id'] ?? ''));
  if ($meuHorario):
      $hoje     = AccessSchedule::faixaDoDia($meuHorario['id'], (int) date('w'));
      $faltam   = AccessSchedule::minutosParaFechar((string) $dados['id']);
      $tplPedir = AccessSchedule::templateDeSolicitacao();
  ?>
  <div class="card">
    <div class="card-header">
      <h3 style="display:inline-flex;align-items:center;gap:8px;">
        <i data-lucide="clock" width="18" height="18" style="color:var(--color-gray-medium);"></i>
        Horário de acesso
      </h3>
    </div>

    <div style="padding:var(--space-4) var(--space-5);">
      <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--color-gray-light);padding-bottom:var(--space-2);margin-bottom:var(--space-3);">
        <span style="font-size:var(--text-body-sm);color:var(--color-gray-medium);">Sua jornada</span>
        <span style="font-size:var(--text-body-sm);color:var(--color-dark);font-weight:700;"><?= h($meuHorario['nome']) ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;">
        <span style="font-size:var(--text-body-sm);color:var(--color-gray-medium);">Hoje</span>
        <span style="font-size:var(--text-body-sm);color:var(--color-dark);font-weight:700;">
          <?php
          $partes = [];
          foreach ([['t1_inicio','t1_fim'], ['t2_inicio','t2_fim']] as [$i, $f]) {
              if (!empty($hoje[$i]) && !empty($hoje[$f])) {
                  $partes[] = substr((string) $hoje[$i], 0, 5) . '–' . substr((string) $hoje[$f], 0, 5);
              }
          }
          echo $partes ? h(implode(' e ', $partes)) : 'fechado';
          ?>
        </span>
      </div>

      <?php if ($faltam !== null && $faltam <= 30): ?>
      <div style="margin-top:var(--space-4);background:#7A4FD0;color:#141922;border-radius:8px;padding:12px 14px;font-size:var(--text-body-sm);line-height:1.5;">
        <strong>Seu acesso encerra em <?= (int) $faltam ?> minuto<?= $faltam === 1 ? '' : 's' ?>.</strong>
        Se precisar continuar depois disso, solicite a liberação <u>agora</u> — depois de bloqueado não é possível pedir.
      </div>
      <?php endif; ?>

      <div style="margin-top:var(--space-4);">
        <?php if ($tplPedir): ?>
        <a href="?module=processos&amp;action=novo&amp;template_id=<?= h($tplPedir['template_id']) ?>"
           style="display:inline-flex;align-items:center;gap:8px;background:transparent;color:#2347C8;border:1px solid #2347C8;border-radius:4px;padding:10px 18px;font-size:14px;font-weight:700;text-decoration:none;">
          <i data-lucide="calendar-plus" width="16" height="16"></i>
          Solicitar acesso em horário de exceção
        </a>
        <p style="font-size:var(--text-caption);color:var(--color-gray-medium);margin:8px 0 0;">
          O pedido passa pelo fluxo de aprovação. Autorizado, o acesso é liberado sozinho no dia e na faixa pedidos.
        </p>
        <?php else: ?>
        <p style="font-size:var(--text-body-sm);color:var(--color-gray-medium);margin:0;">
          Precisa acessar fora deste horário? Fale com o RH — o fluxo de solicitação ainda não foi publicado.
        </p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Trocar senha ────────────────────────────────────────── -->
  <div class="card">
    <div class="card-header">
      <h3 style="display:inline-flex;align-items:center;gap:8px;">
        <i data-lucide="key" width="18" height="18" style="color:var(--color-gray-medium);"></i>
        Alterar senha
      </h3>
    </div>

    <form method="post" action="?module=account&action=trocar_senha" id="acc-form-senha">
      <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">

      <div class="form-group">
        <label for="acc-senha-atual" class="form-label">Senha atual</label>
        <input type="password" id="acc-senha-atual" name="senha_atual" class="form-input" required>
      </div>
      <div class="form-group">
        <label for="acc-senha-nova" class="form-label">Nova senha</label>
        <input type="password" id="acc-senha-nova" name="nova_senha" class="form-input" minlength="6" placeholder="Mínimo 6 caracteres" required>
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label for="acc-senha-confirma" class="form-label">Confirmar nova senha</label>
        <input type="password" id="acc-senha-confirma" name="confirma_senha" class="form-input" minlength="6" required>
      </div>

      <div class="card-footer" style="justify-content:flex-end;">
        <button type="submit" class="btn btn-primary">
          <i data-lucide="key" width="14" height="14"></i>
          Alterar senha
        </button>
      </div>
    </form>
  </div>

</div>

<script>
document.getElementById('acc-form-senha').addEventListener('submit', function (e) {
  var nova = document.getElementById('acc-senha-nova').value;
  var confirma = document.getElementById('acc-senha-confirma').value;
  if (nova !== confirma) {
    e.preventDefault();
    document.getElementById('acc-senha-confirma').classList.add('is-error');
    return;
  }
  document.getElementById('acc-senha-confirma').classList.remove('is-error');
});
</script>
