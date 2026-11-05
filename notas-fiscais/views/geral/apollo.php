<?php
// @versao Apo_01 V_01_25/07/2026
/**
 * views/geral/apollo.php — Aba Apollo (consulta de chave no ERP Apollo).
 * Incluída por views/geral/index.php. Variáveis em escopo:
 *   $cfg, $g, $isAdmin, $apolloConsole, $apolloTemplates, $apolloLiberados,
 *   $apolloUsuarios, $apolloResultado + estilos ($cardStyle, $lblStyle, ...).
 */
$isAdmin        = $isAdmin        ?? false;
$apolloConsole  = $apolloConsole  ?? false;
$apolloTemplates = $apolloTemplates ?? [];
$apolloLiberados = $apolloLiberados ?? [];
$apolloUsuarios  = $apolloUsuarios  ?? [];
$apolloResultado = $apolloResultado ?? null;
$temSenha = ($cfg['apollo']['apollo.pass'] ?? '') !== '';
?>

<div style="margin-bottom:24px">
  <h2 style="font-size:24px; font-weight:700; color:#141922; margin:0 0 4px">
    Consulta <span style="color:#2347C8">Apollo</span>
  </h2>
  <p style="font-size:14px; color:#888; margin:0">
    Confere a chave dos processos abertos no ERP Apollo (somente leitura) e, se marcado, encerra o processo.
  </p>
</div>

<?php if ($isAdmin || $apolloConsole): ?>
<!-- ═══════════ CONSOLE DE CONSULTA ═══════════ -->
<form method="post" action="?module=geral&amp;action=apollo_consultar" style="<?= $cardStyle ?>; max-width:720px; margin-bottom:24px"
      onsubmit="return confirm('Rodar a consulta no Apollo para os processos abertos do tipo selecionado?');">
  <?= Csrf::field() ?>
  <h3 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px">Rodar consulta</h3>
  <p style="font-size:13px; color:#888; margin:0 0 20px">Um tipo de processo por execução. Só processos com status <b>Aberto</b> e chave preenchida.</p>

  <?php if (!$temSenha): ?>
    <div style="background:#FFF3E0; border-radius:8px; padding:12px 16px; margin-bottom:20px; font-size:13px; color:#8A5000">
      ⚠ A conexão com o Apollo ainda não foi configurada<?= $isAdmin ? ' (veja abaixo)' : ' — peça a um administrador' ?>. A consulta vai falhar até lá.
    </div>
  <?php endif; ?>

  <div style="margin-bottom:20px">
    <label style="<?= $lblStyle ?>">Tipo de processo</label>
    <?php if (empty($apolloTemplates)): ?>
      <div style="font-size:14px; color:#888; padding:10px 0">Nenhum processo aberto com chave no momento.</div>
    <?php else: ?>
      <select name="template_id" required style="<?= $inpStyle ?>; background:#fff">
        <option value="">— selecione —</option>
        <?php foreach ($apolloTemplates as $t): ?>
          <option value="<?= h($t['id']) ?>"><?= h($t['nome']) ?> (<?= (int) $t['qtd'] ?> aberto<?= (int) $t['qtd'] === 1 ? '' : 's' ?>)</option>
        <?php endforeach; ?>
      </select>
      <div style="<?= $hintStyle ?>">O número entre parênteses é quantos processos abertos com chave serão consultados.</div>
    <?php endif; ?>
  </div>

  <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; color:#141922; margin-bottom:24px">
    <input type="checkbox" name="encerrar" value="1" style="width:18px; height:18px; accent-color:#2347C8">
    <span><b>Encerrar o processo</b> quando a nota constar no Apollo (válida). Desmarcado = apenas consulta e registra observação.</span>
  </label>

  <button type="submit" <?= empty($apolloTemplates) ? 'disabled' : '' ?>
          style="<?= $btnSalvar ?>; <?= empty($apolloTemplates) ? 'opacity:.5; cursor:not-allowed' : '' ?>">
    Consultar no Apollo
  </button>
</form>

<?php if ($apolloResultado): $r = $apolloResultado; ?>
<!-- Resultado da última execução -->
<div style="<?= $cardStyle ?>; max-width:720px; margin-bottom:24px">
  <h3 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px">Resultado — <?= h($r['template_nome'] ?? '') ?></h3>
  <p style="font-size:13px; color:#888; margin:0 0 18px">
    <?= !empty($r['encerrar']) ? 'Modo: consultar e encerrar.' : 'Modo: apenas consultar.' ?>
    <?php if (($r['status'] ?? '') === 'falha'): ?>
      <span style="color:#B3261E; font-weight:700">Falha na conexão — nenhum processo alterado.</span>
    <?php endif; ?>
  </p>
  <div style="display:flex; flex-wrap:wrap; gap:10px">
    <?php
      $tiles = [
        ['Total',          (int) ($r['total'] ?? 0),           '#EAEAEA', '#606060'],
        ['Encontradas',    (int) ($r['encontradas'] ?? 0),     '#D8F5E5', '#00A960'],
        ['Encerradas',     (int) ($r['encerrados'] ?? 0),      '#D8D4E4', '#606060'],
        ['Canceladas',     (int) ($r['canceladas'] ?? 0),      '#FFF3E0', '#FF8A2D'],
        ['Não encontradas',(int) ($r['nao_encontradas'] ?? 0), '#E3F0FF', '#1565C0'],
        ['Erros',          (int) ($r['erros'] ?? 0),           '#FFE8EE', '#B3261E'],
      ];
      foreach ($tiles as [$lbl, $val, $bg, $col]):
    ?>
      <div style="flex:1; min-width:96px; background:<?= $bg ?>; border-radius:8px; padding:12px 14px; text-align:center">
        <div style="font-size:26px; font-weight:700; color:<?= $col ?>"><?= $val ?></div>
        <div style="font-size:11px; font-weight:700; color:#606060; text-transform:uppercase; letter-spacing:.3px"><?= h($lbl) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($isAdmin): ?>
<!-- ═══════════ CONFIGURAÇÃO DA CONEXÃO (admin) ═══════════ -->
<form method="post" action="?module=geral&amp;action=salvar" style="<?= $cardStyle ?>; max-width:720px; margin-bottom:16px">
  <?= Csrf::field() ?><input type="hidden" name="grupo" value="apollo">
  <h3 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px">Conexão com o Apollo (Oracle)</h3>
  <p style="font-size:13px; color:#888; margin:0 0 20px">Somente administradores. A conexão é feita por um bridge Python local (python-oracledb).</p>

  <div style="display:grid; grid-template-columns:2fr 1fr; gap:16px; margin-bottom:16px">
    <div>
      <label style="<?= $lblStyle ?>">DSN (host:porta/serviço)</label>
      <input type="text" name="cfg[apollo.dsn]" value="<?= h($g('apollo','apollo.dsn')) ?>" placeholder="servidor.exemplo.local:1521/APOLLO" style="<?= $inpStyle ?>">
    </div>
    <div>
      <label style="<?= $lblStyle ?>">Usuário Oracle</label>
      <input type="text" name="cfg[apollo.user]" value="<?= h($g('apollo','apollo.user')) ?>" style="<?= $inpStyle ?>">
    </div>
  </div>

  <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px">
    <div>
      <label style="<?= $lblStyle ?>">Senha Oracle</label>
      <input type="password" name="cfg[apollo.pass]" value="" placeholder="<?= $temSenha ? '•••••••• (deixe em branco para manter)' : 'informe a senha' ?>" style="<?= $inpStyle ?>">
    </div>
    <div>
      <label style="<?= $lblStyle ?>">Executável Python</label>
      <input type="text" name="cfg[apollo.python_bin]" value="<?= h($g('apollo','apollo.python_bin','python3')) ?>" style="<?= $inpStyle ?>">
      <div style="<?= $hintStyle ?>">Normalmente <code>python3</code> (precisa do pacote <code>oracledb</code>).</div>
    </div>
  </div>

  <button type="submit" style="<?= $btnSalvar ?>">Salvar conexão</button>
</form>

<form method="post" action="?module=geral&amp;action=apollo_testar" style="max-width:720px; margin-bottom:24px">
  <?= Csrf::field() ?>
  <button type="submit" style="background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:9px 22px; font-size:14px; font-weight:700; cursor:pointer">
    Testar conexão
  </button>
</form>

<!-- ═══════════ ACESSO AO CONSOLE (admin) ═══════════ -->
<div style="<?= $cardStyle ?>; max-width:720px">
  <h3 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px">Quem pode usar o console</h3>
  <p style="font-size:13px; color:#888; margin:0 0 20px">Liberação por usuário — <b>inclusive administradores</b> precisam constar aqui para rodar a consulta.</p>

  <form method="post" action="?module=geral&amp;action=apollo_liberar" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:20px; flex-wrap:wrap">
    <?= Csrf::field() ?>
    <div style="flex:1; min-width:240px">
      <label style="<?= $lblStyle ?>">Conceder acesso a</label>
      <select name="usuario_id" required style="<?= $inpStyle ?>; background:#fff">
        <option value="">— selecione um usuário —</option>
        <?php foreach ($apolloUsuarios as $u): ?>
          <option value="<?= h($u['id']) ?>"><?= h($u['nome']) ?><?= !empty($u['email']) ? ' — ' . h($u['email']) : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" style="<?= $btnSalvar ?>">Conceder</button>
  </form>

  <?php if (empty($apolloLiberados)): ?>
    <div style="font-size:14px; color:#888">Nenhum usuário liberado ainda.</div>
  <?php else: ?>
    <table style="width:100%; border-collapse:collapse; font-size:13px">
      <thead>
        <tr style="background:#606060; color:#fff; text-align:left">
          <th style="padding:8px 12px; font-weight:700; font-size:12px; text-transform:uppercase">Usuário</th>
          <th style="padding:8px 12px; font-weight:700; font-size:12px; text-transform:uppercase">Concedido em</th>
          <th style="padding:8px 12px; width:1%"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($apolloLiberados as $i => $l): ?>
        <tr style="background:<?= $i % 2 ? '#F8F8F8' : '#fff' ?>">
          <td style="padding:8px 12px; color:#141922">
            <?= h($l['nome'] ?? '—') ?><?= !empty($l['email']) ? ' <span style="color:#888">— ' . h($l['email']) . '</span>' : '' ?>
          </td>
          <td style="padding:8px 12px; color:#606060"><?= h(date('d/m/Y H:i', strtotime((string) $l['concedido_em']))) ?></td>
          <td style="padding:8px 12px; text-align:right">
            <form method="post" action="?module=geral&amp;action=apollo_revogar" onsubmit="return confirm('Revogar o acesso deste usuário ao console Apollo?');" style="margin:0">
              <?= Csrf::field() ?>
              <input type="hidden" name="usuario_id" value="<?= h($l['usuario_id']) ?>">
              <button type="submit" style="background:#EAEAEA; color:#606060; border:none; border-radius:4px; padding:6px 14px; font-size:12px; font-weight:700; cursor:pointer">Revogar</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endif; ?>
