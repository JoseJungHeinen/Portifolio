<?php
// @versao Tar_06 V_02_05/08/2026
/**
 * views/tasks/fila.php
 * Fila por pessoa — visão de gestão: o que está pendente com cada usuário.
 *
 * Acesso: admin ou permissão processos.ver_todos (validado no controller).
 *
 * Variáveis esperadas:
 *   $usuarios      array  usuários ativos [id, nome]
 *   $selecionado   array|null usuário escolhido [id, nome]
 *   $tarefas       array  resultado de Task::listByUsuario(selecionado)
 *   $stats         array  Task::getStats($tarefas)
 *   $resumoEtapas  array  [nome_etapa => [qtd, valor, mais_antigo_dias]]
 */

$usuarios     = $usuarios     ?? [];
$selecionado  = $selecionado  ?? null;
$tarefas      = $tarefas      ?? [];
$stats        = $stats        ?? ['total' => 0, 'pessoais' => 0, 'grupo' => 0, 'sla_vencido' => 0];
$resumoEtapas = $resumoEtapas ?? [];

if (!function_exists('formatBrl')) {
    function formatBrl(mixed $val): string {
        // Processo sem nota fiscal tem valor_nf NULL — NULL não é zero.
        if ($val === null || $val === '') return '—';
        return 'R$ ' . number_format((float) $val, 2, ',', '.');
    }
}

$valorTotal = 0.0;
$maisAntigo = 0;
foreach ($tarefas as $t) {
    $valorTotal += (float) $t['valor_nf'];
    $maisAntigo  = max($maisAntigo, (int) ($t['sla']['dias_parado'] ?? 0));
}
?>

<?php include VIEWS . '/components/alert.php'; ?>

<!-- ── Cabeçalho ─────────────────────────────────────────────── -->
<div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px">
  <div>
    <h1 style="font-size:32px;font-weight:700;color:#141922;margin:0 0 4px;">
      Fila <span style="color:#2347C8;">por pessoa</span>
    </h1>
    <p style="font-size:14px;color:#888888;margin:0;">Resumo do que está pendente com cada aprovador — direto ou via grupos</p>
  </div>
  <a href="?module=tarefas&action=index" class="btn btn-secondary" style="text-decoration:none;">
    <i data-lucide="arrow-left" width="15" height="15"></i>
    Minhas Tarefas
  </a>
</div>

<!-- ── Seletor de pessoa ─────────────────────────────────────── -->
<form method="get" action="" style="display:flex; align-items:center; gap:10px; margin-bottom:24px; flex-wrap:wrap;">
  <input type="hidden" name="module" value="tarefas">
  <input type="hidden" name="action" value="fila">
  <label for="fila-usuario" style="font-size:13px; font-weight:700; color:#606060;">Pessoa:</label>
  <select id="fila-usuario" name="usuario_id" onchange="this.form.submit()"
          style="min-width:280px; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px;
                 font-size:14px; color:#141922; background:#fff; outline:none;"
          onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
    <option value="">— Selecione um usuário —</option>
    <?php foreach ($usuarios as $u): ?>
    <option value="<?= h($u['id']) ?>" <?= $selecionado && $selecionado['id'] === $u['id'] ? 'selected' : '' ?>>
      <?= h($u['nome']) ?>
    </option>
    <?php endforeach; ?>
  </select>
</form>

<?php if (!$selecionado): ?>
<!-- Estado vazio: nenhuma pessoa escolhida -->
<div class="card" style="padding:60px 40px; text-align:center;">
  <div style="width:56px;height:56px;border-radius:14px;background:#F6F6F6;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
    <i data-lucide="users" width="24" height="24" style="color:#AAAAAA;"></i>
  </div>
  <p style="font-size:15px;font-weight:700;color:#141922;margin-bottom:6px;">Escolha uma pessoa</p>
  <p style="font-size:13px;color:#888888;">Selecione um usuário acima para ver a fila de aprovações pendente com ele.</p>
</div>
<?php else: ?>

<!-- ── Cards de resumo ───────────────────────────────────────── -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:16px; margin-bottom:24px;">
  <div class="card" style="padding:20px; border-left:4px solid #2347C8;">
    <p style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:0.6px; margin:0 0 6px;">Processos pendentes</p>
    <p style="font-size:28px; font-weight:700; color:#141922; margin:0;"><?= (int) $stats['total'] ?></p>
    <p style="font-size:12px; color:#888; margin:4px 0 0;"><?= (int) $stats['pessoais'] ?> direto(s) · <?= (int) $stats['grupo'] ?> via grupo</p>
  </div>
  <div class="card" style="padding:20px; border-left:4px solid #606060;">
    <p style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:0.6px; margin:0 0 6px;">Valor total</p>
    <p style="font-size:28px; font-weight:700; color:#141922; margin:0;"><?= formatBrl($valorTotal) ?></p>
  </div>
  <div class="card" style="padding:20px; border-left:4px solid <?= $stats['sla_vencido'] > 0 ? '#B3261E' : '#00A960' ?>;">
    <p style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:0.6px; margin:0 0 6px;">SLA vencido</p>
    <p style="font-size:28px; font-weight:700; color:<?= $stats['sla_vencido'] > 0 ? '#B3261E' : '#00A960' ?>; margin:0;"><?= (int) $stats['sla_vencido'] ?></p>
  </div>
  <div class="card" style="padding:20px; border-left:4px solid #FF8A2D;">
    <p style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:0.6px; margin:0 0 6px;">Mais antigo</p>
    <p style="font-size:28px; font-weight:700; color:#141922; margin:0;"><?= $maisAntigo ?> dia(s)</p>
  </div>
</div>

<?php if (empty($tarefas)): ?>
<div class="card" style="padding:60px 40px; text-align:center;">
  <div style="width:56px;height:56px;border-radius:14px;background:#D8F5E5;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
    <i data-lucide="check-circle" width="24" height="24" style="color:#00A960;"></i>
  </div>
  <p style="font-size:15px;font-weight:700;color:#141922;margin-bottom:6px;">Fila limpa!</p>
  <p style="font-size:13px;color:#888888;">Não há processos pendentes com <?= h($selecionado['nome']) ?>.</p>
</div>
<?php else: ?>

<!-- ── Resumo por etapa ──────────────────────────────────────── -->
<div class="card" style="overflow:hidden; padding:0; margin-bottom:24px;">
  <div style="padding:16px 20px; border-bottom:1px solid #EAEAEA;">
    <p style="font-size:15px; font-weight:700; color:#141922; margin:0;">Resumo por etapa</p>
  </div>
  <table class="table" style="width:100%; border-collapse:collapse;">
    <thead>
      <tr>
        <th>Etapa</th>
        <th style="width:120px; text-align:right;">Processos</th>
        <th style="width:170px; text-align:right;">Valor</th>
        <th style="width:140px; text-align:right;">Mais antigo</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($resumoEtapas as $nomeEtapa => $r): ?>
      <tr>
        <td style="font-size:13px; font-weight:600; color:#141922;"><?= h($nomeEtapa) ?></td>
        <td style="font-size:13px; text-align:right;"><?= (int) $r['qtd'] ?></td>
        <td style="font-size:13px; text-align:right;"><?= formatBrl($r['valor']) ?></td>
        <td style="font-size:13px; text-align:right;"><?= (int) $r['mais_antigo_dias'] ?> dia(s)</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── Lista de processos ────────────────────────────────────── -->
<div class="card" style="overflow:hidden; padding:0;">
  <div style="padding:16px 20px; border-bottom:1px solid #EAEAEA;">
    <p style="font-size:15px; font-weight:700; color:#141922; margin:0;">
      Processos com <?= h($selecionado['nome']) ?>
      <span style="font-weight:400; color:#888; font-size:13px;">· ordenados do mais antigo para o mais recente</span>
    </p>
  </div>
  <div style="overflow-x:auto;">
    <table class="table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr>
          <th style="width:150px;">Processo</th>
          <th>Fornecedor</th>
          <th style="width:140px; text-align:right;">Valor</th>
          <th style="width:170px;">Etapa atual</th>
          <th style="width:170px;">Como chega</th>
          <th style="width:120px;">Tempo / SLA</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tarefas as $t): ?>
        <tr class="<?= !empty($t['is_teste']) ? 'row-inativo' : '' ?>">
          <td>
            <a href="?module=processos&action=detalhe&id=<?= h($t['processo_id']) ?>"
               style="font-size:13px; font-weight:700; color:#2347C8; text-decoration:none;">
              <?= h($t['codigo']) ?>
            </a>
            <?php if (!empty($t['is_teste'])): ?>
            <span style="font-size:9px; font-weight:700; background:#FFF3E0; color:#FF8A2D; padding:1px 6px; border-radius:8px; margin-left:4px;">TESTE</span>
            <?php endif; ?>
          </td>
          <td style="font-size:13px; color:#141922;">
            <?= h($t['fornecedor_nome']) ?>
            <span style="display:block; font-size:11px; color:#888;">NF <?= h($t['numero_nf']) ?: 'sem nota' ?><?= !empty($t['filial_nome']) ? ' · ' . h($t['filial_nome']) : '' ?></span>
          </td>
          <td style="font-size:13px; text-align:right; font-weight:600;"><?= formatBrl($t['valor_nf']) ?></td>
          <td style="font-size:13px;"><?= h($t['nome_etapa']) ?></td>
          <td style="font-size:12px;">
            <?php if ($t['tipo_aprovador'] === 'usuario'): ?>
            <span style="background:#D8D4E4; color:#606060; font-weight:700; font-size:10px; padding:3px 10px; border-radius:8px;">DIRETO</span>
            <?php else: ?>
            <span style="background:#EAEAEA; color:#606060; font-weight:700; font-size:10px; padding:3px 10px; border-radius:8px;" title="Chega via grupo — outros membros também podem atuar">GRUPO: <?= h(mb_strtoupper($t['grupo_nome'] ?? '—')) ?></span>
            <?php endif; ?>
          </td>
          <td><?= SlaCalculator::badge($t['sla']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="padding:12px 16px; border-top:1px solid #EAEAEA; font-size:13px; color:#888;">
    <?= count($tarefas) ?> processo(s) · <?= formatBrl($valorTotal) ?>
  </div>
</div>

<?php endif; ?>
<?php endif; ?>
