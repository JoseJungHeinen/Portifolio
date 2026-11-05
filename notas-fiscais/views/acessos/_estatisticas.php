<?php
// @versao Ace_08 V_01_11/08/2026
/**
 * views/acessos/_estatisticas.php
 * Painel de estatísticas do Controle de Acesso — ocupa o lado direito da tela
 * enquanto nenhum usuário está selecionado. Some quando o admin clica em alguém
 * (o JS troca o conteúdo de #acs-detail pelo detalhe de permissões).
 *
 * Só leitura — AccessStats::painel().
 *
 * Variáveis esperadas:
 *   $stats  array  AccessStats::painel()
 */

$stats = $stats ?? [];
if (!$stats) { return; }

$cad  = $stats['cadastro']  ?? [];
$ac   = $stats['acesso']    ?? [];
$uso  = $stats['uso']       ?? [];
$prob = $stats['problemas'] ?? [];
$dias = (int) ($stats['janela_dias'] ?? 30);

$curva    = $stats['curva'] ?? [];
$curvaMax = 0;
foreach ($curva as $p) { $curvaMax = max($curvaMax, (int) $p['qtd']); }

/** Percentual de uma parte sobre o total de ativos, para o texto de apoio. */
$pct = static function (int $parte) use ($cad): string {
    $base = (int) ($cad['ativos'] ?? 0);
    return $base > 0 ? round($parte * 100 / $base) . '% dos ativos' : '';
};

/** dd/mm às HH:MM — datas curtas cabem melhor nas listas. */
$quando = static function (?string $iso): string {
    if (!$iso) { return '—'; }
    $t = strtotime($iso);
    return $t ? date('d/m H:i', $t) : '—';
};
?>
<div class="acs-stats">

  <div class="acs-stats__head">
    <div>
      <h2 class="acs-stats__title">Visão do <span>acesso</span></h2>
      <p class="acs-stats__sub">
        Últimos <?= $dias ?> dias. Selecione um usuário à esquerda para configurar as permissões.
      </p>
    </div>
    <span class="badge badge--muted">somente leitura</span>
  </div>

  <!-- ── Bloco 1: estão conseguindo entrar? ────────────────── -->
  <div class="acs-stats__section-label">Estão conseguindo entrar?</div>
  <div class="acs-kpis">

    <div class="acs-kpi<?= ($ac['nunca_logaram'] ?? 0) > 0 ? ' acs-kpi--danger' : '' ?>">
      <div class="acs-kpi__valor"><?= (int) ($ac['nunca_logaram'] ?? 0) ?></div>
      <div class="acs-kpi__label">Nunca logaram</div>
      <div class="acs-kpi__hint"><?= h($pct((int) ($ac['nunca_logaram'] ?? 0))) ?></div>
    </div>

    <div class="acs-kpi<?= ($ac['sem_acesso_janela'] ?? 0) > 0 ? ' acs-kpi--warning' : '' ?>">
      <div class="acs-kpi__valor"><?= (int) ($ac['sem_acesso_janela'] ?? 0) ?></div>
      <div class="acs-kpi__label">Sem acesso há <?= $dias ?> dias</div>
      <div class="acs-kpi__hint"><?= h($pct((int) ($ac['sem_acesso_janela'] ?? 0))) ?></div>
    </div>

    <div class="acs-kpi acs-kpi--success">
      <div class="acs-kpi__valor"><?= $ac['taxa_ok'] === null ? '—' : (int) $ac['taxa_ok'] . '%' ?></div>
      <div class="acs-kpi__label">Login com sucesso</div>
      <div class="acs-kpi__hint"><?= (int) ($ac['sucessos'] ?? 0) ?> ok · <?= (int) ($ac['falhas'] ?? 0) ?> erros</div>
    </div>

    <div class="acs-kpi<?= ($ac['bloqueados'] ?? 0) > 0 ? ' acs-kpi--danger' : '' ?>">
      <div class="acs-kpi__valor"><?= (int) ($ac['bloqueados'] ?? 0) ?></div>
      <div class="acs-kpi__label">Bloqueados agora</div>
      <div class="acs-kpi__hint"><?= (int) ($ac['com_tentativa'] ?? 0) ?> com tentativa pendente</div>
    </div>

    <div class="acs-kpi">
      <div class="acs-kpi__valor"><?= (int) ($ac['resets'] ?? 0) ?></div>
      <div class="acs-kpi__label">Senhas redefinidas</div>
      <div class="acs-kpi__hint">em <?= $dias ?> dias</div>
    </div>

  </div>

  <!-- ── Bloco 2: uso ──────────────────────────────────────── -->
  <div class="acs-stats__section-label">Uso</div>
  <div class="acs-kpis">

    <div class="acs-kpi">
      <div class="acs-kpi__valor"><?= (int) ($uso['logaram_24h'] ?? 0) ?></div>
      <div class="acs-kpi__label">Logaram nas últimas 24h</div>
      <div class="acs-kpi__hint"><?= (int) ($uso['logins_hoje'] ?? 0) ?> logins hoje</div>
    </div>

    <div class="acs-kpi">
      <div class="acs-kpi__valor"><?= (int) ($uso['distintos_7d'] ?? 0) ?></div>
      <div class="acs-kpi__label">Pessoas distintas (7 dias)</div>
      <div class="acs-kpi__hint"><?= h($pct((int) ($uso['distintos_7d'] ?? 0))) ?></div>
    </div>

    <div class="acs-kpi">
      <div class="acs-kpi__valor"><?= $prob['hora_pico'] === null ? '—' : sprintf('%02dh', (int) $prob['hora_pico']) ?></div>
      <div class="acs-kpi__label">Horário de pico</div>
      <div class="acs-kpi__hint"><?= (int) ($prob['fora_horario'] ?? 0) ?> logins fora do expediente</div>
    </div>

  </div>

  <!-- Curva de logins por dia -->
  <?php if ($curva): ?>
  <div class="acs-chart">
    <div class="acs-chart__head">
      <span>Logins por dia</span>
      <span class="acs-chart__max">pico <?= $curvaMax ?></span>
    </div>
    <div class="acs-chart__bars">
      <?php foreach ($curva as $ponto):
          $qtd = (int) $ponto['qtd'];
          $alt = $curvaMax > 0 ? max(2, (int) round($qtd * 100 / $curvaMax)) : 2;
          $dia = date('d/m', strtotime((string) $ponto['dia']));
      ?>
      <div class="acs-chart__col" title="<?= h($dia) ?> — <?= $qtd ?> login(s)">
        <div class="acs-chart__bar" style="height:<?= $alt ?>%"></div>
        <span class="acs-chart__dia"><?= h(substr($dia, 0, 2)) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Bloco 3: rankings ─────────────────────────────────── -->
  <div class="acs-stats__cols">

    <div class="acs-rank">
      <div class="acs-rank__head">Mais ativos (<?= $dias ?> dias)</div>
      <?php if (empty($stats['top_ativos'])): ?>
      <div class="acs-rank__vazio">Nenhum login no período.</div>
      <?php else: foreach ($stats['top_ativos'] as $r): ?>
      <div class="acs-rank__item">
        <div class="acs-rank__meta">
          <span class="acs-rank__nome"><?= h($r['nome'] ?? '') ?></span>
          <span class="acs-rank__sub">último em <?= h($quando($r['ultimo'] ?? null)) ?></span>
        </div>
        <span class="acs-rank__valor"><?= (int) $r['qtd'] ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="acs-rank">
      <div class="acs-rank__head">Mais erros de senha (<?= $dias ?> dias)</div>
      <?php if (empty($stats['top_falhas'])): ?>
      <div class="acs-rank__vazio">Nenhuma falha de login no período.</div>
      <?php else: foreach ($stats['top_falhas'] as $r): ?>
      <div class="acs-rank__item">
        <div class="acs-rank__meta">
          <span class="acs-rank__nome"><?= h($r['nome'] ?? '') ?></span>
          <span class="acs-rank__sub">última em <?= h($quando($r['ultimo'] ?? null)) ?></span>
        </div>
        <span class="acs-rank__valor acs-rank__valor--alerta"><?= (int) $r['qtd'] ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>

  </div>

  <!-- Travados: erraram e nunca entraram -->
  <?php if (!empty($stats['travados'])): ?>
  <div class="acs-rank acs-rank--destaque">
    <div class="acs-rank__head">
      Tentaram e nunca entraram
      <span class="acs-rank__head-hint">erro de senha sem nenhum login bem-sucedido</span>
    </div>
    <?php foreach ($stats['travados'] as $r): ?>
    <div class="acs-rank__item">
      <div class="acs-rank__meta">
        <span class="acs-rank__nome"><?= h($r['nome'] ?? '') ?></span>
        <span class="acs-rank__sub"><?= h($r['email'] ?? '') ?></span>
      </div>
      <span class="acs-rank__valor acs-rank__valor--alerta"><?= (int) $r['qtd'] ?> erro(s)</span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ── Bloco 4: cadastro ─────────────────────────────────── -->
  <div class="acs-stats__section-label">Cadastro</div>
  <div class="acs-kpis">

    <div class="acs-kpi">
      <div class="acs-kpi__valor"><?= (int) ($cad['ativos'] ?? 0) ?></div>
      <div class="acs-kpi__label">Usuários ativos</div>
      <div class="acs-kpi__hint"><?= (int) ($cad['total'] ?? 0) ?> no total · <?= (int) ($cad['inativos'] ?? 0) ?> inativos</div>
    </div>

    <div class="acs-kpi">
      <div class="acs-kpi__valor"><?= (int) ($cad['grupos'] ?? 0) ?></div>
      <div class="acs-kpi__label">Grupos</div>
      <div class="acs-kpi__hint"><?= (int) ($cad['grupos_vazios'] ?? 0) ?> sem nenhum membro</div>
    </div>

    <div class="acs-kpi<?= ($cad['sem_grupo'] ?? 0) > 0 ? ' acs-kpi--warning' : '' ?>">
      <div class="acs-kpi__valor"><?= (int) ($cad['sem_grupo'] ?? 0) ?></div>
      <div class="acs-kpi__label">Ativos sem grupo</div>
      <div class="acs-kpi__hint">não recebem fluxo por grupo</div>
    </div>

    <div class="acs-kpi">
      <div class="acs-kpi__valor"><?= (int) ($cad['novos'] ?? 0) ?></div>
      <div class="acs-kpi__label">Cadastrados</div>
      <div class="acs-kpi__hint">nos últimos <?= $dias ?> dias</div>
    </div>

  </div>

  <?php if (!empty($stats['perfis'])): ?>
  <div class="acs-perfis-dist">
    <?php foreach ($stats['perfis'] as $p): ?>
    <span class="acs-perfil-chip">
      <?= h(ucfirst(str_replace('_', ' ', (string) $p['perfil']))) ?>
      <strong><?= (int) $p['qtd'] ?></strong>
    </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <p class="acs-stats__nota">
    <i data-lucide="info" width="13" height="13"></i>
    O último acesso é registrado no login, não a cada clique — por isso o painel
    mostra “logaram nas últimas 24h” em vez de “online agora”. Tentativa com
    e-mail inexistente ainda não é registrada.
  </p>

</div>
