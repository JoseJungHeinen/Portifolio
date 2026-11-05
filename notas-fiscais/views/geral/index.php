<?php
// @versao Ger_01 V_18_01/10/2026
/**
 * views/geral/index.php — Configurações gerais (admin)
 * Variáveis: $cfg (grupos: geral, email, seguranca, notificacoes), $aba, $flash
 */
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
$aba = $aba ?? 'geral';
$cfg = $cfg ?? [];
$g = fn(string $grupo, string $chave, $def = '') => $cfg[$grupo][$chave] ?? $def;

$abas = [
    'geral'        => ['label' => 'Geral',        'icon' => 'sliders-horizontal'],
    'email'        => ['label' => 'E-mail',       'icon' => 'mail'],
    'seguranca'    => ['label' => 'Segurança',    'icon' => 'shield'],
    'notificacoes' => ['label' => 'Notificações', 'icon' => 'bell'],
    'api'          => ['label' => 'API',          'icon' => 'plug'],
    'filiais'      => ['label' => 'Filiais',      'icon' => 'building-2'],
    'brobot'       => ['label' => 'Brobot',       'icon' => 'bot'],
    'nfe_nfse'     => ['label' => 'NF-e / NFS-e', 'icon' => 'landmark'],
    'apollo'       => ['label' => 'Apollo',       'icon' => 'database'],
    'faturamento'  => ['label' => 'Faturamento',  'icon' => 'car'],
    'horarios'     => ['label' => 'Horários de Acesso', 'icon' => 'clock'],
    'paginas'      => ['label' => 'Páginas e Arquivos', 'icon' => 'file-stack'],
];
// Faturamento só aparece onde o módulo já foi publicado (existe o controller).
$temFaturamento = is_file(SRC . '/Controllers/FaturamentoController.php');
if (!$temFaturamento) {
    unset($abas['faturamento']);
}
// Usuário de console (não-admin) só enxerga a aba Apollo.
if (empty($isAdmin)) {
    $abas = ['apollo' => $abas['apollo']];
} else {
    // Abas desligadas em "Utiliza módulos" (aba Geral) somem da navegação.
    $abas = array_filter($abas, static fn($k) => Settings::abaAtiva($k), ARRAY_FILTER_USE_KEY);
}
$filiaisBrobot = $filiaisBrobot ?? [];

$lblStyle = 'display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px';
$inpStyle = 'width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none';
$hintStyle = 'font-size:11px; color:#888; margin-top:4px';
$cardStyle = 'background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:28px; max-width:640px';
$btnSalvar = 'background:#2347C8; color:#fff; border:none; border-radius:4px; padding:10px 24px; font-size:14px; font-weight:700; cursor:pointer';
?>
<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">

<?php include VIEWS . '/components/alert.php'; ?>

<div style="margin-bottom:24px">
  <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
    Configurações <span style="color:#2347C8">Gerais</span>
  </h1>
  <p style="font-size:14px; color:#888; margin:0">Parâmetros do sistema — antes fixos no código, agora administráveis aqui.</p>
</div>

<!-- Abas -->
<div style="display:flex; gap:4px; border-bottom:2px solid #EAEAEA; margin-bottom:24px; flex-wrap:wrap">
  <?php foreach ($abas as $val => $meta): $ativa = $val === $aba; ?>
  <a href="?module=geral&amp;action=index&amp;aba=<?= h($val) ?>"
     style="display:inline-flex; align-items:center; gap:8px; padding:10px 18px; font-size:14px; font-weight:700; text-decoration:none; border-bottom:2px solid <?= $ativa ? '#2347C8' : 'transparent' ?>; margin-bottom:-2px; color:<?= $ativa ? '#2347C8' : '#888' ?>">
    <i data-lucide="<?= h($meta['icon']) ?>" width="16" height="16"></i>
    <?= h($meta['label']) ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($aba === 'geral'): ?>
<div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap">
<form method="post" action="?module=geral&amp;action=salvar" style="<?= $cardStyle ?>">
  <?= Csrf::field() ?><input type="hidden" name="grupo" value="geral">
  <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 20px">Preferências gerais</h2>
  <div style="margin-bottom:20px; max-width:220px">
    <label style="<?= $lblStyle ?>">Itens por página nas listagens</label>
    <input type="number" name="cfg[geral.itens_por_pagina]" min="5" max="100" value="<?= h($g('geral','geral.itens_por_pagina','20')) ?>" style="<?= $inpStyle ?>">
    <div style="<?= $hintStyle ?>">Quantas linhas mostrar por página em processos, grupos, listas, etc.</div>
  </div>
  <div style="margin-bottom:20px; max-width:220px">
    <label style="<?= $lblStyle ?>">Dias para sugestão de vencimento</label>
    <input type="number" name="cfg[geral.dias_vencimento_sugestao]" min="1" max="365" value="<?= h($g('geral','geral.dias_vencimento_sugestao','15')) ?>" style="<?= $inpStyle ?>">
    <div style="<?= $hintStyle ?>">Ao gerar parcelas manualmente (botão "Gerar" em Financeiro), quantos dias somar a partir de hoje para sugerir o vencimento. Não afeta nota já capturada com vencimento próprio — esse vem sempre da nota.</div>
  </div>
  <button type="submit" style="<?= $btnSalvar ?>">Salvar</button>
</form>

<!-- Utiliza módulos: liga/desliga as abas desta tela -->
<form method="post" action="?module=geral&amp;action=salvar_modulos" style="<?= $cardStyle ?>; max-width:420px; width:100%">
  <?= Csrf::field() ?>
  <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px">Utiliza <span style="color:#2347C8">módulos</span></h2>
  <p style="font-size:13px; color:#888; margin:0 0 16px">
    Desmarque o que você não usa mais: a aba some desta tela. Não desliga a integração em si
    (agendamentos e capturas continuam rodando) — só esconde a configuração.
  </p>
  <?php foreach (Settings::ABAS_OPCIONAIS as $chaveAba => $rotuloAba): ?>
  <?php if ($chaveAba === 'faturamento' && !$temFaturamento) continue; ?>
  <label style="display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #EAEAEA; font-size:14px; color:#141922; cursor:pointer">
    <input type="checkbox" name="modulos[]" value="<?= h($chaveAba) ?>" <?= Settings::abaAtiva($chaveAba) ? 'checked' : '' ?>
           style="width:16px; height:16px; accent-color:#2347C8">
    <?= h($rotuloAba) ?>
  </label>
  <?php endforeach; ?>
  <button type="submit" style="<?= $btnSalvar ?>; margin-top:18px">Salvar módulos</button>
</form>
</div>

<?php elseif ($aba === 'email'): ?>
<?php $modo = $g('email','email.modo','log'); ?>
<form method="post" action="?module=geral&amp;action=salvar" style="<?= $cardStyle ?>; margin-bottom:20px">
  <?= Csrf::field() ?><input type="hidden" name="grupo" value="email">
  <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px">Servidor de e-mail (SMTP)</h2>
  <p style="font-size:13px; color:#888; margin:0 0 20px">Usado nos alertas de SLA e notificações do sistema.</p>

  <div style="margin-bottom:20px">
    <label style="<?= $lblStyle ?>">Modo de envio</label>
    <div style="display:flex; gap:16px">
      <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px">
        <input type="radio" name="cfg[email.modo]" value="log" <?= $modo !== 'smtp' ? 'checked' : '' ?> style="accent-color:#2347C8"> Registrar em log (não envia)
      </label>
      <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px">
        <input type="radio" name="cfg[email.modo]" value="smtp" <?= $modo === 'smtp' ? 'checked' : '' ?> style="accent-color:#2347C8"> Enviar via SMTP
      </label>
    </div>
    <div style="<?= $hintStyle ?>">No modo log, os e-mails ficam em <code>storage/logs/emails.log</code> — ideal para testar sem enviar de verdade.</div>
  </div>

  <div style="display:grid; grid-template-columns:2fr 1fr; gap:16px; margin-bottom:16px">
    <div>
      <label style="<?= $lblStyle ?>">Servidor SMTP</label>
      <input type="text" name="cfg[email.host]" value="<?= h($g('email','email.host')) ?>" placeholder="smtp.exemplo.com.br" style="<?= $inpStyle ?>">
    </div>
    <div>
      <label style="<?= $lblStyle ?>">Porta</label>
      <input type="number" name="cfg[email.porta]" value="<?= h($g('email','email.porta','587')) ?>" style="<?= $inpStyle ?>">
    </div>
  </div>

  <div style="margin-bottom:16px; max-width:220px">
    <label style="<?= $lblStyle ?>">Segurança</label>
    <select name="cfg[email.seguranca]" style="<?= $inpStyle ?>; background:#fff">
      <?php foreach (['tls'=>'TLS (587)','ssl'=>'SSL (465)',''=>'Nenhuma'] as $v=>$l): ?>
      <option value="<?= h($v) ?>" <?= $g('email','email.seguranca','tls') === $v ? 'selected' : '' ?>><?= h($l) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px">
    <div>
      <label style="<?= $lblStyle ?>">Usuário</label>
      <input type="text" name="cfg[email.usuario]" value="<?= h($g('email','email.usuario')) ?>" style="<?= $inpStyle ?>">
    </div>
    <div>
      <label style="<?= $lblStyle ?>">Senha</label>
      <input type="password" name="cfg[email.senha]" value="" placeholder="<?= $g('email','email.senha') !== '' ? '•••••••• (deixe em branco para manter)' : '' ?>" style="<?= $inpStyle ?>">
    </div>
  </div>

  <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px">
    <div>
      <label style="<?= $lblStyle ?>">Remetente — e-mail</label>
      <input type="text" name="cfg[email.remetente_email]" value="<?= h($g('email','email.remetente_email')) ?>" style="<?= $inpStyle ?>">
    </div>
    <div>
      <label style="<?= $lblStyle ?>">Remetente — nome</label>
      <input type="text" name="cfg[email.remetente_nome]" value="<?= h($g('email','email.remetente_nome')) ?>" style="<?= $inpStyle ?>">
    </div>
  </div>

  <div style="margin-bottom:20px">
    <label style="<?= $lblStyle ?>">URL base do sistema (links nos e-mails)</label>
    <input type="text" name="cfg[email.base_url]" value="<?= h($g('email','email.base_url')) ?>" placeholder="https://app.exemplo.local/" style="<?= $inpStyle ?>">
  </div>

  <button type="submit" style="<?= $btnSalvar ?>">Salvar configurações de e-mail</button>
</form>

<!-- Teste de envio -->
<form method="post" action="?module=geral&amp;action=testar_email" style="<?= $cardStyle ?>">
  <?= Csrf::field() ?>
  <h3 style="font-size:15px; font-weight:700; color:#141922; margin:0 0 12px">Enviar e-mail de teste</h3>
  <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap">
    <div style="flex:1; min-width:240px">
      <label style="<?= $lblStyle ?>">E-mail de destino</label>
      <input type="email" name="email_teste" required placeholder="voce@exemplo.com" style="<?= $inpStyle ?>">
    </div>
    <button type="submit" style="background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; cursor:pointer; height:40px">Enviar teste</button>
  </div>
  <div style="<?= $hintStyle ?>">Salve as configurações antes de testar. No modo "log", o teste grava no arquivo em vez de enviar.</div>
</form>

<?php elseif ($aba === 'seguranca'): ?>
<form method="post" action="?module=geral&amp;action=salvar" style="<?= $cardStyle ?>">
  <?= Csrf::field() ?><input type="hidden" name="grupo" value="seguranca">
  <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 20px">Sessão e login</h2>

  <div style="margin-bottom:18px; max-width:280px">
    <label style="<?= $lblStyle ?>">Tempo de inatividade da sessão (minutos)</label>
    <input type="number" name="cfg[seguranca.session_timeout_min]" min="5" max="480" value="<?= h($g('seguranca','seguranca.session_timeout_min','30')) ?>" style="<?= $inpStyle ?>">
    <div style="<?= $hintStyle ?>">Após esse tempo sem atividade, o usuário é deslogado.</div>
  </div>
  <div style="margin-bottom:18px; max-width:280px">
    <label style="<?= $lblStyle ?>">Tentativas de login antes do bloqueio</label>
    <input type="number" name="cfg[seguranca.max_login_attempts]" min="3" max="20" value="<?= h($g('seguranca','seguranca.max_login_attempts','5')) ?>" style="<?= $inpStyle ?>">
  </div>
  <div style="margin-bottom:22px; max-width:280px">
    <label style="<?= $lblStyle ?>">Duração do bloqueio (minutos)</label>
    <input type="number" name="cfg[seguranca.lockout_min]" min="1" max="1440" value="<?= h($g('seguranca','seguranca.lockout_min','15')) ?>" style="<?= $inpStyle ?>">
  </div>
  <button type="submit" style="<?= $btnSalvar ?>">Salvar</button>
</form>

<?php elseif ($aba === 'notificacoes'): ?>
<form method="post" action="?module=geral&amp;action=salvar" style="<?= $cardStyle ?>">
  <?= Csrf::field() ?><input type="hidden" name="grupo" value="notificacoes">
  <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px">Alertas de SLA</h2>
  <p style="font-size:13px; color:#888; margin:0 0 20px">Quando uma etapa excede o prazo, o responsável (ou o gestor do grupo) é avisado por e-mail.</p>

  <div style="margin-bottom:18px; max-width:320px">
    <label style="<?= $lblStyle ?>">1º alerta após o estouro (minutos)</label>
    <input type="number" name="cfg[notif.sla_primeiro_alerta_min]" min="0" max="1440" value="<?= h($g('notificacoes','notif.sla_primeiro_alerta_min','5')) ?>" style="<?= $inpStyle ?>">
  </div>
  <div style="margin-bottom:18px; max-width:320px">
    <label style="<?= $lblStyle ?>">Intervalo entre reforços (minutos)</label>
    <input type="number" name="cfg[notif.sla_reenvio_min]" min="15" max="1440" value="<?= h($g('notificacoes','notif.sla_reenvio_min','60')) ?>" style="<?= $inpStyle ?>">
  </div>
  <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; max-width:320px; margin-bottom:22px">
    <div>
      <label style="<?= $lblStyle ?>">Reforço a partir de (hora)</label>
      <input type="number" name="cfg[notif.sla_hora_inicio]" min="0" max="23" value="<?= h($g('notificacoes','notif.sla_hora_inicio','8')) ?>" style="<?= $inpStyle ?>">
    </div>
    <div>
      <label style="<?= $lblStyle ?>">Reforço até (hora)</label>
      <input type="number" name="cfg[notif.sla_hora_fim]" min="1" max="24" value="<?= h($g('notificacoes','notif.sla_hora_fim','18')) ?>" style="<?= $inpStyle ?>">
    </div>
  </div>
  <div style="background:#F6F6F6; border-radius:8px; padding:12px 14px; font-size:12px; color:#606060; margin-bottom:20px">
    Exemplo: 1º alerta 5 min após o estouro (qualquer hora); reforços a cada 60 min, das 8h às 18h.
  </div>
  <button type="submit" style="<?= $btnSalvar ?>">Salvar</button>
</form>

<?php elseif ($aba === 'api'): ?>
<form method="post" action="?module=geral&amp;action=salvar" style="<?= $cardStyle ?>">
  <?= Csrf::field() ?><input type="hidden" name="grupo" value="api">
  <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px">API — Consulta de NF-e por chave</h2>
  <p style="font-size:13px; color:#888; margin:0 0 20px">Endpoint externo que devolve o XML da NF-e a partir da chave (44 dígitos). A aplicação externa autentica com a API key no header <code>X-API-Key</code>.</p>

  <div style="margin-bottom:18px">
    <label style="<?= $lblStyle ?>">API key <span style="color:#B3261E">(header X-API-Key)</span></label>
    <input type="text" name="cfg[api.nfe_key]" value="<?= h($g('api','api.nfe_key')) ?>"
           style="<?= $inpStyle ?>; font-family:Consolas,monospace; letter-spacing:.3px">
    <div style="<?= $hintStyle ?>">Compartilhe apenas com a aplicação externa. Troque o valor se for exposto.</div>
  </div>

  <div style="margin-bottom:20px; max-width:420px">
    <label style="<?= $lblStyle ?>">IPs permitidos <span style="font-weight:400; color:#888">(opcional)</span></label>
    <input type="text" name="cfg[api.nfe_allow_ips]" value="<?= h($g('api','api.nfe_allow_ips')) ?>"
           placeholder="servidor.exemplo.local, servidor.exemplo.local" style="<?= $inpStyle ?>">
    <div style="<?= $hintStyle ?>">Separe por vírgula. Vazio = qualquer IP pode consultar (desde que tenha a API key).</div>
  </div>

  <div style="background:#F6F6F6; border-radius:8px; padding:14px 16px; font-size:12.5px; color:#606060; line-height:1.7; margin-bottom:20px">
    <div><b>Endpoint:</b> <code>GET /Notas/public/api/nfe.php?chave={44 dígitos}</code> &nbsp;·&nbsp; header <code>X-API-Key: {a chave acima}</code></div>
    <div><b>Retorno:</b> o arquivo XML (<code>application/xml</code>) &nbsp;·&nbsp; 401 = key inválida &nbsp;·&nbsp; 404 = XML não encontrado</div>
    <div><b>Pasta dos XML:</b> <code>storage/nfe_xml/{chave}.xml</code> — a integração (Brobot) deve gravar cada XML aqui.</div>
    <div><b>Auditoria:</b> cada consulta é registrada em <code>storage/logs/nfe_api.log</code>.</div>
  </div>

  <button type="submit" style="<?= $btnSalvar ?>">Salvar configurações da API</button>
</form>

<!-- ══ API — Assinafy (assinatura eletrônica) ══════════════════════ -->
<?php
$aAtivo   = $g('assinafy','assinafy.ativo','0') === '1';
$aAmb     = $g('assinafy','assinafy.ambiente','sandbox');
$temChave = $g('assinafy','assinafy.api_key') !== '';
$temTok   = $g('assinafy','assinafy.webhook_token') !== '';
?>
<form method="post" action="?module=geral&amp;action=salvar" style="<?= $cardStyle ?>; margin-top:20px">
  <?= Csrf::field() ?><input type="hidden" name="grupo" value="assinafy">

  <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px">API — Assinafy <span style="color:#2347C8">assinatura eletrônica</span></h2>
  <p style="font-size:13px; color:#888; margin:0 0 20px">
    Envia para assinatura o documento dos fluxos com <b>assinatura eletrônica</b> habilitada no template.
    O processo aguarda na etapa até todos assinarem, e o PDF assinado volta como anexo.
  </p>

  <div style="margin-bottom:18px">
    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; font-weight:700; color:#141922">
      <input type="hidden" name="cfg[assinafy.ativo]" value="0">
      <input type="checkbox" name="cfg[assinafy.ativo]" value="1" <?= $aAtivo ? 'checked' : '' ?> style="accent-color:#2347C8; width:18px; height:18px">
      Integração ativa
    </label>
    <div style="<?= $hintStyle ?>">Desligada, nenhum documento é enviado — o processo para na etapa de assinatura aguardando. Útil para pausar sem mexer nos templates.</div>
  </div>

  <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:18px">
    <div>
      <label style="<?= $lblStyle ?>">Ambiente</label>
      <select name="cfg[assinafy.ambiente]" style="<?= $inpStyle ?>; background:#fff">
        <option value="sandbox"  <?= $aAmb === 'sandbox'  ? 'selected' : '' ?>>Sandbox (testes)</option>
        <option value="producao" <?= $aAmb === 'producao' ? 'selected' : '' ?>>Produção</option>
      </select>
      <div style="<?= $hintStyle ?>">Sandbox usa <code>sandbox.assinafy.com.br</code> e conta separada — a chave de produção não vale lá.</div>
    </div>
    <div>
      <label style="<?= $lblStyle ?>">Workspace <span style="font-weight:400; color:#888">(account ID)</span></label>
      <input type="text" name="cfg[assinafy.account_id]" value="<?= h($g('assinafy','assinafy.account_id')) ?>"
             placeholder="10409b1fae66260bc41522f97c76" style="<?= $inpStyle ?>; font-family:Consolas,monospace">
      <div style="<?= $hintStyle ?>">Na Assinafy: <b>Minha Conta &rsaquo; Workspaces</b>.</div>
    </div>
  </div>

  <div style="margin-bottom:18px">
    <label style="<?= $lblStyle ?>">Chave da API <span style="color:#B3261E">(header X-Api-Key)</span></label>
    <input type="text" name="cfg[assinafy.api_key]" value=""
           placeholder="<?= $temChave ? '•••••••• (deixe em branco para manter)' : 'gerada em Minha Conta › API' ?>"
           style="<?= $inpStyle ?>; font-family:Consolas,monospace; letter-spacing:.3px">
    <div style="<?= $hintStyle ?>">Segredo — nunca compartilhe nem versione. Se vazar, revogue na Assinafy e gere outra.</div>
  </div>

  <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:18px">
    <div>
      <label style="<?= $lblStyle ?>">Prazo padrão para assinar (dias)</label>
      <input type="number" name="cfg[assinafy.prazo_dias]" min="0" max="90"
             value="<?= h($g('assinafy','assinafy.prazo_dias','7')) ?>" style="<?= $inpStyle ?>">
      <div style="<?= $hintStyle ?>">Usado quando o template não define o próprio. 0 = sem prazo.</div>
    </div>
    <div>
      <label style="<?= $lblStyle ?>">Timeout (segundos)</label>
      <input type="number" name="cfg[assinafy.timeout_seg]" min="10" max="180"
             value="<?= h($g('assinafy','assinafy.timeout_seg','60')) ?>" style="<?= $inpStyle ?>">
      <div style="<?= $hintStyle ?>">O upload do PDF é a chamada mais lenta (~4s em documento de 480 KB).</div>
    </div>
  </div>

  <!-- Webhook -->
  <div style="height:1px; background:#EAEAEA; margin:22px 0"></div>
  <h3 style="font-size:15px; font-weight:700; color:#141922; margin:0 0 4px">Retorno da assinatura (webhook)</h3>
  <p style="font-size:12.5px; color:#888; margin:0 0 16px">
    Opcional — apenas <b>antecipa</b> o resultado. O worker <code>cli/assinafy_sync.php</code> já busca
    o desfecho a cada 10 minutos, então nada se perde se o webhook falhar ou não for cadastrado.
  </p>

  <div style="margin-bottom:18px">
    <label style="<?= $lblStyle ?>">Token do webhook <span style="color:#B3261E">(segredo na URL)</span></label>
    <input type="text" name="cfg[assinafy.webhook_token]" value=""
           placeholder="<?= $temTok ? '•••••••• (deixe em branco para manter)' : 'gere uma sequência longa e aleatória' ?>"
           style="<?= $inpStyle ?>; font-family:Consolas,monospace; letter-spacing:.3px">
    <div style="<?= $hintStyle ?>">Sem token preenchido o endpoint recusa tudo (401).</div>
  </div>

  <div style="margin-bottom:20px; max-width:420px">
    <label style="<?= $lblStyle ?>">IPs permitidos <span style="font-weight:400; color:#888">(opcional)</span></label>
    <input type="text" name="cfg[assinafy.allow_ips]" value="<?= h($g('assinafy','assinafy.allow_ips')) ?>"
           placeholder="deixe vazio se não souber os IPs do provedor" style="<?= $inpStyle ?>">
    <div style="<?= $hintStyle ?>">Separe por vírgula. Vazio = qualquer IP (o token continua obrigatório).</div>
  </div>

  <div style="background:#FFF8E1; border-left:4px solid #FF8A2D; border-radius:4px; padding:14px 16px; font-size:12.5px; color:#606060; line-height:1.7; margin-bottom:20px">
    <div style="font-weight:700; color:#FF8A2D; margin-bottom:6px">O webhook não é assinado pelo provedor</div>
    A Assinafy não envia HMAC nem assinatura no corpo — qualquer um que descubra a URL pode postar nela.
    Por isso o endpoint <b>nunca confia no que recebe</b>: ele só lê o ID do documento e reconsulta a
    Assinafy com a nossa chave. Um POST forjado no máximo causa uma consulta a mais — não move processo.
  </div>

  <div style="background:#F6F6F6; border-radius:8px; padding:14px 16px; font-size:12.5px; color:#606060; line-height:1.7; margin-bottom:20px">
    <div><b>URL a cadastrar</b> na Assinafy (Minha Conta &rsaquo; Webhooks):</div>
    <?php
      // SEM `/public/` no caminho: o vhost do servidor já aponta o alias da
      // aplicação para a pasta public (Alias /Notas → …/notas/public). Com
      // `/public/` a URL vira …/public/public/api/… , que não existe — o
      // rewrite manda para o index e devolve 302 para o login em vez de
      // processar o webhook. Conferido no deploy de 17/08/2026.
      $baseUrl  = rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/');
      $urlHook  = ($baseUrl !== '' ? $baseUrl : '') . '/api/assinafy.php?token={o token acima}';
    ?>
    <div style="margin:6px 0"><code><?= h($urlHook) ?></code></div>
    <div><b>Eventos úteis:</b> <code>document_ready</code>, <code>signer_signed_document</code>, <code>signer_rejected_document</code>, <code>user_rejected_document</code>, <code>document_processing_failed</code></div>
    <div><b>Auditoria:</b> cada chamada é registrada em <code>storage/logs/assinafy_api.log</code>.</div>
    <div><b>Worker:</b> agende <code>php cli/assinafy_sync.php</code> a cada 10 minutos — é ele que garante que nenhum processo fique preso.</div>
  </div>

  <button type="submit" style="<?= $btnSalvar ?>">Salvar configurações da Assinafy</button>
</form>

<!-- ══ API — Clicksign (assinatura eletrônica) ═════════════════════ -->
<?php
$kAtivo   = $g('clicksign','clicksign.ativo','0') === '1';
$kAmb     = $g('clicksign','clicksign.ambiente','sandbox');
$kTemTk   = $g('clicksign','clicksign.access_token') !== '';
$kTemHook = $g('clicksign','clicksign.webhook_token') !== '';
?>
<form method="post" action="?module=geral&amp;action=salvar" style="<?= $cardStyle ?>; margin-top:20px">
  <?= Csrf::field() ?><input type="hidden" name="grupo" value="clicksign">

  <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px">API — Clicksign <span style="color:#2347C8">assinatura eletrônica</span></h2>
  <p style="font-size:13px; color:#888; margin:0 0 20px">
    Atende as etapas <b>Assina ClickSign</b> dos fluxos: o documento anexado vai para <b>duas pessoas</b>
    (nome completo, CPF, e-mail, telefone e tipo de assinatura, preenchidos na abertura do processo).
    O processo aguarda na etapa até as duas assinarem, e o PDF assinado volta como anexo.
    Convive com a Assinafy — cada template usa a que a etapa escolhida indicar.
  </p>

  <div style="margin-bottom:18px">
    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; font-weight:700; color:#141922">
      <input type="hidden" name="cfg[clicksign.ativo]" value="0">
      <input type="checkbox" name="cfg[clicksign.ativo]" value="1" <?= $kAtivo ? 'checked' : '' ?> style="accent-color:#2347C8; width:18px; height:18px">
      Integração ativa
    </label>
    <div style="<?= $hintStyle ?>">Desligada, nenhum documento é enviado — o processo para na etapa aguardando. Útil para pausar sem mexer nos templates.</div>
  </div>

  <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:18px">
    <div>
      <label style="<?= $lblStyle ?>">Ambiente</label>
      <select name="cfg[clicksign.ambiente]" style="<?= $inpStyle ?>; background:#fff">
        <option value="sandbox"  <?= $kAmb === 'sandbox'  ? 'selected' : '' ?>>Sandbox (testes)</option>
        <option value="producao" <?= $kAmb === 'producao' ? 'selected' : '' ?>>Produção</option>
      </select>
      <div style="<?= $hintStyle ?>">Sandbox usa <code>sandbox.clicksign.com</code> e conta separada — o token de produção não vale lá.</div>
    </div>
    <div>
      <label style="<?= $lblStyle ?>">Access Token <span style="color:#B3261E">(segredo)</span></label>
      <input type="text" name="cfg[clicksign.access_token]" value=""
             placeholder="<?= $kTemTk ? '•••••••• (deixe em branco para manter)' : 'cole o Access Token da conta' ?>"
             style="<?= $inpStyle ?>; font-family:Consolas,monospace; letter-spacing:.3px">
      <div style="<?= $hintStyle ?>">Na Clicksign: <b>Configurações &rsaquo; API</b>. O <b>e-mail do usuário da API</b> lá precisa ser um usuário já cadastrado na conta — senão toda chamada falha mesmo com o token certo.</div>
    </div>
  </div>

  <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:18px">
    <div>
      <label style="<?= $lblStyle ?>">Prazo padrão (dias)</label>
      <input type="number" name="cfg[clicksign.prazo_dias]" min="0" max="90"
             value="<?= h($g('clicksign','clicksign.prazo_dias','7')) ?>" style="<?= $inpStyle ?>">
      <div style="<?= $hintStyle ?>">A Clicksign aceita no máximo 90 dias. O prazo de cada template (aba Configuração) prevalece.</div>
    </div>
    <div>
      <label style="<?= $lblStyle ?>">Timeout das chamadas (s)</label>
      <input type="number" name="cfg[clicksign.timeout_seg]" min="10" max="180"
             value="<?= h($g('clicksign','clicksign.timeout_seg','60')) ?>" style="<?= $inpStyle ?>">
    </div>
  </div>

  <div style="height:1px; background:#EAEAEA; margin:22px 0"></div>
  <h3 style="font-size:15px; font-weight:700; color:#141922; margin:0 0 4px">Retorno da assinatura (webhook)</h3>
  <p style="font-size:12.5px; color:#888; margin:0 0 16px">
    Opcional — apenas <b>antecipa</b> o resultado. O worker <code>cli/assinafy_sync.php</code> (o mesmo da Assinafy)
    já busca o desfecho a cada 10 minutos.
  </p>

  <div style="margin-bottom:18px">
    <label style="<?= $lblStyle ?>">Token do webhook <span style="color:#B3261E">(segredo na URL)</span></label>
    <input type="text" name="cfg[clicksign.webhook_token]" value=""
           placeholder="<?= $kTemHook ? '•••••••• (deixe em branco para manter)' : 'gere uma sequência longa e aleatória' ?>"
           style="<?= $inpStyle ?>; font-family:Consolas,monospace; letter-spacing:.3px">
    <div style="<?= $hintStyle ?>">Sem token preenchido o endpoint recusa tudo (401).</div>
  </div>

  <div style="margin-bottom:20px; max-width:420px">
    <label style="<?= $lblStyle ?>">IPs permitidos <span style="font-weight:400; color:#888">(opcional)</span></label>
    <input type="text" name="cfg[clicksign.allow_ips]" value="<?= h($g('clicksign','clicksign.allow_ips')) ?>"
           placeholder="deixe vazio se não souber os IPs do provedor" style="<?= $inpStyle ?>">
    <div style="<?= $hintStyle ?>">Separe por vírgula. Vazio = qualquer IP (o token continua obrigatório).</div>
  </div>

  <div style="background:#FFF8E1; border-left:4px solid #FF8A2D; border-radius:4px; padding:14px 16px; font-size:12.5px; color:#606060; line-height:1.7; margin-bottom:20px">
    <div style="font-weight:700; color:#FF8A2D; margin-bottom:6px">O webhook não é validado por assinatura</div>
    O endpoint <b>nunca confia no que recebe</b>: só lê o ID do envelope e reconsulta a Clicksign com a nossa chave.
    Um POST forjado no máximo causa uma consulta a mais — não move processo. O formato exato do payload
    ainda não foi visto em produção; se o webhook não funcionar, o worker cobre.
  </div>

  <div style="background:#F6F6F6; border-radius:8px; padding:14px 16px; font-size:12.5px; color:#606060; line-height:1.7; margin-bottom:20px">
    <div><b>URL a cadastrar</b> na Clicksign (Configurações &rsaquo; Webhooks):</div>
    <?php
      // Mesma regra do webhook da Assinafy: SEM `/public/` no caminho.
      $baseUrlK = rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/');
      $urlHookK = $baseUrlK . '/api/clicksign.php?token={o token acima}';
    ?>
    <div style="margin:6px 0"><code><?= h($urlHookK) ?></code></div>
    <div><b>Eventos úteis:</b> fechamento do envelope (<code>envelope.closed</code> / documento finalizado) e cancelamento.</div>
    <div><b>Auditoria:</b> cada chamada é registrada em <code>storage/logs/clicksign_api.log</code>.</div>
    <div><b>Worker:</b> <code>php cli/assinafy_sync.php</code> a cada 10 minutos atende os dois provedores.</div>
    <div><b>Custo:</b> a Clicksign cobra por documento finalizado; biometria facial e documentoscopia têm custo extra por uso na conta.</div>
  </div>

  <button type="submit" style="<?= $btnSalvar ?>">Salvar configurações da Clicksign</button>
</form>

<?php elseif ($aba === 'brobot'): ?>
<?php $bAtivo = $g('brobot','brobot.ativo','0') === '1'; ?>
<!-- Conexão com a Brobot -->
<form method="post" action="?module=geral&amp;action=salvar" style="<?= $cardStyle ?>; margin-bottom:20px">
  <?= Csrf::field() ?><input type="hidden" name="grupo" value="brobot">
  <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px">Integração Brobot — captura de DF-e</h2>
  <p style="font-size:13px; color:#888; margin:0 0 20px">
    Busca NF-e/CT-e/NFS-e/CT-e OS na plataforma Brobot. O poller roda a cada 5 min e a classificação a cada 30 min (via Agendador de Tarefas).
  </p>

  <div style="margin-bottom:18px">
    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; font-weight:700; color:#141922">
      <input type="hidden" name="cfg[brobot.ativo]" value="0">
      <input type="checkbox" name="cfg[brobot.ativo]" value="1" <?= $bAtivo ? 'checked' : '' ?> style="accent-color:#2347C8; width:18px; height:18px">
      Integração ativa
    </label>
    <div style="<?= $hintStyle ?>">Quando desligada, o poller não busca nada (útil para pausar sem remover o agendamento).</div>
  </div>

  <div style="margin-bottom:18px">
    <label style="<?= $lblStyle ?>">Token <span style="color:#B3261E">(header token)</span></label>
    <input type="text" name="cfg[brobot.token]" value=""
           placeholder="<?= $g('brobot','brobot.token') !== '' ? '•••••••• (deixe em branco para manter)' : 'cyqNAeRMjcM9KYpp1qgrN6p9' ?>"
           style="<?= $inpStyle ?>; font-family:Consolas,monospace; letter-spacing:.3px">
    <div style="<?= $hintStyle ?>">Gerado na Brobot em <b>Definições &rsaquo; Usuários &rsaquo; Editar Usuário</b>. Segredo — não compartilhe.</div>
  </div>

  <div style="display:grid; grid-template-columns:2fr 1fr; gap:16px; margin-bottom:16px">
    <div>
      <label style="<?= $lblStyle ?>">URL base da API</label>
      <input type="text" name="cfg[brobot.base_url]" value="<?= h($g('brobot','brobot.base_url','https://api.platform.brobot.com.br/api/v2')) ?>" style="<?= $inpStyle ?>">
    </div>
    <div>
      <label style="<?= $lblStyle ?>">Formato do XML</label>
      <select name="cfg[brobot.metodo_xml]" style="<?= $inpStyle ?>; background:#fff">
        <?php foreach (['xml_brobot'=>'xml_brobot (padronizado)','xml'=>'xml (original)'] as $v=>$l): ?>
        <option value="<?= h($v) ?>" <?= $g('brobot','brobot.metodo_xml','xml_brobot') === $v ? 'selected' : '' ?>><?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px; max-width:420px">
    <div>
      <label style="<?= $lblStyle ?>">Janela inicial (dias)</label>
      <input type="number" name="cfg[brobot.backfill_dias]" min="1" max="90" value="<?= h($g('brobot','brobot.backfill_dias','7')) ?>" style="<?= $inpStyle ?>">
      <div style="<?= $hintStyle ?>">Quanto o 1º run retroage quando ainda não há cursor.</div>
    </div>
    <div>
      <label style="<?= $lblStyle ?>">Timeout (segundos)</label>
      <input type="number" name="cfg[brobot.timeout_seg]" min="5" max="120" value="<?= h($g('brobot','brobot.timeout_seg','30')) ?>" style="<?= $inpStyle ?>">
    </div>
  </div>

  <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
    <button type="submit" style="<?= $btnSalvar ?>">Salvar</button>
    <button type="submit" formaction="?module=geral&amp;action=testar_brobot"
            style="background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; cursor:pointer">
      Testar conexão
    </button>
    <span style="<?= $hintStyle ?>; margin:0">Salve antes de testar — o teste usa o token gravado.</span>
  </div>
</form>

<!-- Filiais integradas -->
<form method="post" action="?module=geral&amp;action=salvar_filiais_brobot" style="<?= $cardStyle ?>; max-width:760px; margin-bottom:20px">
  <?= Csrf::field() ?>
  <h3 style="font-size:15px; font-weight:700; color:#141922; margin:0 0 4px">Filiais integradas</h3>
  <p style="font-size:13px; color:#888; margin:0 0 16px">Só entram notas cujo destinatário é uma filial marcada para aquele tipo de documento. Ao <b>ligar</b> um tipo numa filial, o sistema busca automaticamente no Brobot as notas anteriores dela (backfill) e depois segue capturando as novas.</p>

  <?php if (empty($filiaisBrobot)): ?>
  <div style="font-size:13px; color:#888">Nenhuma filial cadastrada.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table style="width:100%; border-collapse:collapse; font-size:13px">
      <thead>
        <tr style="background:#606060; color:#fff; text-align:left">
          <th style="padding:8px 12px; font-weight:700">Filial</th>
          <?php foreach (['nfe'=>'NFe','cte'=>'CTe','nfse'=>'NFSe','ctos'=>'CTOs'] as $tl): ?>
          <th style="padding:8px 12px; font-weight:700; text-align:center; width:64px"><?= $tl ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($filiaisBrobot as $i => $f): ?>
        <tr style="background:<?= $i % 2 ? '#F8F8F8' : '#fff' ?>; border-bottom:1px solid #EAEAEA; <?= $f['ativo'] ? '' : 'opacity:.55' ?>">
          <td style="padding:8px 12px; color:#141922">
            <div style="font-weight:700"><?= h($f['nome']) ?><?= $f['ativo'] ? '' : ' <span style="color:#888; font-weight:400">(inativa)</span>' ?></div>
            <div style="color:#888; font-size:11px"><?= h($f['cnpj']) ?></div>
          </td>
          <?php foreach (['nfe','cte','nfse','ctos'] as $tk): ?>
          <td style="padding:8px 12px; text-align:center">
            <input type="checkbox" name="brobot_flags[<?= h($f['id']) ?>][<?= $tk ?>]" value="1"
                   <?= (int)($f['brobot_'.$tk] ?? 0) === 1 ? 'checked' : '' ?> style="accent-color:#2347C8; width:16px; height:16px">
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <button type="submit" style="<?= $btnSalvar ?>; margin-top:16px">Salvar integração por filial</button>
  <?php endif; ?>
</form>

<!-- Buscas históricas (backfill) -->
<?php $backfillStatus = $backfillStatus ?? []; if (!empty($backfillStatus)): ?>
<?php
  $bfMap = [
    'pendente'  => ['#FFF3E0', '#FF8A2D', 'Na fila'],
    'rodando'   => ['#E3F0FF', '#1565C0', 'Buscando…'],
    'concluido' => ['#D8F5E5', '#00A960', 'Concluído'],
    'erro'      => ['#FFE8EE', '#B3261E', 'Erro'],
  ];
?>
<div style="<?= $cardStyle ?>; max-width:760px; margin-bottom:20px">
  <h3 style="font-size:15px; font-weight:700; color:#141922; margin:0 0 4px">Buscas históricas no Brobot</h3>
  <p style="font-size:13px; color:#888; margin:0 0 16px">
    Disparadas ao ligar uma filial. Rodam em segundo plano (a cada 5 min) e trazem as notas que já existiam
    no Brobot antes de a filial ser ligada.
    <strong>Sem backfill</strong> não quer dizer filial parada: a captura corrente roda a cada 5 minutos para
    toda filial ligada, independente disso — a coluna mostra quantas notas já entraram por ela.
  </p>
  <div style="overflow-x:auto">
    <table style="width:100%; border-collapse:collapse; font-size:13px">
      <thead>
        <tr style="background:#606060; color:#fff; text-align:left">
          <th style="padding:8px 12px; font-weight:700">Filial</th>
          <th style="padding:8px 12px; font-weight:700; text-align:center; width:110px">Status</th>
          <th style="padding:8px 12px; font-weight:700; text-align:right; width:90px">Importadas</th>
          <th style="padding:8px 12px; font-weight:700; text-align:right; width:90px">Varridas</th>
          <th style="padding:8px 12px; font-weight:700">Atualizado</th>
        </tr>
      </thead>
      <tbody>
        <?php $totImp = 0; $totVar = 0; ?>
        <?php foreach ($backfillStatus as $i => $bf):
          // Filial sem nenhuma busca registrada: status vem NULL.
          $semBusca = ($bf['status'] ?? null) === null;
          $s = $semBusca ? ['#F1F1F1','#888','Sem backfill']
                         : ($bfMap[$bf['status']] ?? ['#EAEAEA','#888',$bf['status']]);
          $totImp += (int)$bf['docs_importadas'];
          $totVar += (int)$bf['docs_varridos'];
        ?>
        <tr style="background:<?= $i % 2 ? '#F8F8F8' : '#fff' ?>; border-bottom:1px solid #EAEAEA">
          <td style="padding:8px 12px; color:#141922; font-weight:700"><?= h($bf['filial_nome'] ?? '—') ?></td>
          <td style="padding:8px 12px; text-align:center">
            <span style="display:inline-block; background:<?= $s[0] ?>; color:<?= $s[1] ?>; font-size:10px; font-weight:700; text-transform:uppercase; padding:3px 8px; border-radius:8px"><?= h($s[2]) ?></span>
          </td>
          <td style="padding:8px 12px; text-align:right; color:<?= $semBusca ? '#888' : '#00A960' ?>; font-weight:700"
              <?= $semBusca ? 'title="Capturadas pelo sync corrente (a cada 5 min), não por busca histórica"' : '' ?>>
            <?= $semBusca ? number_format((int)$bf['notas_filial'], 0, ',', '.') : (int)$bf['docs_importadas'] ?>
          </td>
          <td style="padding:8px 12px; text-align:right; color:#888">
            <?= $semBusca ? '—' : (int)$bf['docs_varridos'] ?>
          </td>
          <td style="padding:8px 12px; color:#888; font-size:11px">
            <?= $semBusca ? '—' : h(date('d/m H:i', strtotime((string)$bf['atualizado_em']))) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#EAEAEA; border-top:2px solid #606060">
          <td style="padding:8px 12px; color:#141922; font-weight:700">TOTAL</td>
          <td></td>
          <td style="padding:8px 12px; text-align:right; color:#00A960; font-weight:700"><?= number_format($totImp, 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#606060; font-weight:700"><?= number_format($totVar, 0, ',', '.') ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Notas capturadas por mês -->
<?php
$capPorMes    = $brobotPorMes ?? [];
$capDescricao = 'Quantas notas entraram pela API do Brobot em cada mês, contadas pela <b>data da captura</b> — '
              . 'não pela emissão. Nota trazida por busca histórica conta no mês em que a integração a trouxe. Últimos 24 meses.';
$capVazio     = 'Nenhuma nota capturada pela integração até agora.';
include VIEWS . '/geral/capturas_por_mes.php';
?>

<!-- Agendamento -->
<div style="<?= $cardStyle ?>; max-width:760px; background:#F6F6F6">
  <h3 style="font-size:15px; font-weight:700; color:#141922; margin:0 0 12px">Agendamento (Windows)</h3>
  <p style="font-size:12.5px; color:#606060; margin:0 0 12px">Registre uma única vez, como administrador, para os loops rodarem sozinhos:</p>
  <pre style="background:#141922; color:#7EE787; font-size:12px; line-height:1.7; padding:14px 16px; border-radius:8px; overflow:auto; margin:0; white-space:pre-wrap">schtasks /Create /SC MINUTE /MO 5  /TN "Brobot-Sync"        /TR "php <?= h(ROOT) ?>\cli\brobot_sync.php"
schtasks /Create /SC MINUTE /MO 5  /TN "Brobot-Backfill"    /TR "php <?= h(ROOT) ?>\cli\brobot_backfill.php"
schtasks /Create /SC MINUTE /MO 30 /TN "Brobot-Classificar" /TR "php <?= h(ROOT) ?>\cli\brobot_classificar.php"</pre>
</div>

<?php elseif ($aba === 'filiais'): ?>
<?php include VIEWS . '/geral/filiais.php'; ?>

<?php elseif ($aba === 'nfe_nfse'): ?>
<?php include VIEWS . '/geral/nfe_nfse.php'; ?>

<?php elseif ($aba === 'apollo'): ?>
<?php include VIEWS . '/geral/apollo.php'; ?>

<?php elseif ($aba === 'faturamento'): ?>
<div style="<?= $cardStyle ?>">
  <h2 style="font-size:20px; font-weight:700; color:#141922; margin:0 0 6px">Faturamento</h2>
  <p style="font-size:13px; color:#888; margin:0 0 20px">Fases do pedido, quem edita e dá o OK em cada uma, e as exigências para avançar.</p>
  <?php if (Auth::perfil() === 'super_admin'): ?>
  <a href="?module=faturamento&action=config" style="display:inline-flex; align-items:center; gap:6px; background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:10px 18px; font-size:14px; font-weight:700; text-decoration:none">
    <i data-lucide="settings" width="15" height="15"></i> Controle de fases e exigências
  </a>
  <?php else: ?>
  <p style="font-size:13px; color:#606060; margin:0">Apenas o super administrador pode alterar o controle de fases.</p>
  <?php endif; ?>
</div>

<?php elseif ($aba === 'paginas'): ?>
<?php include VIEWS . '/geral/paginas.php'; ?>

<?php elseif ($aba === 'horarios'): ?>
<?php include VIEWS . '/geral/horarios.php'; ?>
<?php endif; ?>
