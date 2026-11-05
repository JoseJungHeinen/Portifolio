<?php
// @versao Tpl_02 V_12_01/10/2026
/**
 * views/templates/editor.php
 * Editor de template — abas: Geral | Etapas | Campos | Anexos | Configuração
 *
 * Variáveis:
 *   $template        array   Template com versao_publicada e versao_rascunho
 *   $versaoAtual     array|null  Versão sendo visualizada/editada
 *   $somenteLeitura  bool
 *   $filiais         array
 *   $setores         array
 *   $tiposNota       array
 *   $grupos          array
 *   $historico       array   Todas as versões (para o seletor)
 *   $currentUser     array
 */
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// $extraHead é injetado pelo controller (CSS + JS do módulo)

$versaoId    = $versaoAtual['id']     ?? '';
$templateId  = $template['id']        ?? '';

// Confidencialidade do fluxo (vem do controller; defaults para não quebrar
// caso a view seja incluída fora do fluxo normal)
$sigiloLiberados = $sigiloLiberados ?? ['usuarios' => [], 'grupos' => []];
$podeGerirSigilo = $podeGerirSigilo ?? false;
$statusAtual = $versaoAtual['status'] ?? 'sem_versao';

$statusLabels = [
    'rascunho'      => ['label' => 'Rascunho',      'class' => 'badge-muted',    'cor' => '#888'],
    'teste_interno' => ['label' => 'Teste Interno',  'class' => 'badge-primary',  'cor' => '#2347C8'],
    'uat'           => ['label' => 'Teste com Usuários', 'class' => 'badge-warning',  'cor' => '#FF8A2D'],
    'publicado'     => ['label' => 'Publicado',      'class' => 'badge-success',  'cor' => '#00A960'],
    'arquivado'     => ['label' => 'Arquivado',      'class' => 'badge-muted',    'cor' => '#888'],
];
$statusInfo = $statusLabels[$statusAtual] ?? ['label' => $statusAtual, 'class' => 'badge-muted', 'cor' => '#888'];

// Ações de status disponíveis por estágio
$statusActions = [
    'rascunho'      => [
        ['status' => 'teste_interno', 'label' => 'Enviar para Teste Interno', 'icon' => 'flask-conical', 'style' => 'primary'],
    ],
    'teste_interno' => [
        ['status' => 'rascunho',  'label' => 'Voltar para Rascunho', 'icon' => 'pencil', 'style' => 'tertiary'],
        ['status' => 'uat',       'label' => 'Abrir para Usuários',  'icon' => 'users',  'style' => 'secondary'],
        ['status' => 'publicado', 'label' => 'Publicar',             'icon' => 'globe',  'style' => 'success'],
    ],
    'uat' => [
        ['status' => 'rascunho',      'label' => 'Voltar para Rascunho',       'icon' => 'pencil',     'style' => 'tertiary'],
        ['status' => 'teste_interno', 'label' => 'Encerrar teste com usuários', 'icon' => 'arrow-left', 'style' => 'secondary'],
        ['status' => 'publicado',     'label' => 'Publicar',                    'icon' => 'globe',      'style' => 'success'],
    ],
];
$acoes = $statusActions[$statusAtual] ?? [];

// Dados JSON da versão (já decodificados pelo model)
$campos      = $versaoAtual['campos']      ?? [];
$etapas      = $versaoAtual['etapas']      ?? [];
$tiposAnexo  = $versaoAtual['tipos_anexo'] ?? [];

// Config de assinatura, já com os defaults preenchidos
$assinaturaCfg = Template::configAssinatura($versaoAtual ?? []);

// Tipos de campo disponíveis
$tiposCampo = [
    'texto'       => 'Texto livre',
    'numero'      => 'Número',
    'valor'       => 'Valor (R$)',
    'data'        => 'Data',
    'select'      => 'Seleção de lista',
    'cpf_cnpj'    => 'CPF / CNPJ',
    'telefone'    => 'Telefone',
    'email'       => 'E-mail',
    'textarea'    => 'Texto longo',
];
?>

<!-- Breadcrumb -->
<div style="display:flex;align-items:center;gap:6px;margin-bottom:20px;">
  <a href="?module=templates&action=index"
     style="display:flex;align-items:center;gap:5px;font-size:13px;color:#888888;text-decoration:none;"
     onmouseover="this.style.color='#2347C8'" onmouseout="this.style.color='#888888'">
    <i data-lucide="layout-template" width="13" height="13"></i>
    Templates
  </a>
  <i data-lucide="chevron-right" width="12" height="12" style="color:#CCCCCC;"></i>
  <span style="font-size:13px;color:#141922;font-weight:600;"><?= h($template['nome']) ?></span>
</div>

<!-- Cabeçalho do editor -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:24px;flex-wrap:wrap;">

  <!-- Identidade do template -->
  <div style="display:flex;align-items:flex-start;gap:14px;flex:1;min-width:0;">
    <div style="width:48px;height:48px;flex-shrink:0;border-radius:12px;background:#EEF2FD;
                display:flex;align-items:center;justify-content:center;">
      <i data-lucide="layout-template" width="22" height="22" style="color:#2347C8;"></i>
    </div>
    <div style="min-width:0;">
      <h1 style="font-size:24px;font-weight:700;color:#141922;margin:0 0 6px 0;
                 white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
        <?= h($template['nome']) ?> <span style="color:#2347C8;">Editor</span>
      </h1>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <?php if ($versaoAtual): ?>
        <span class="badge <?= h($statusInfo['class']) ?>"><?= h($statusInfo['label']) ?></span>
        <?php if (!$somenteLeitura): ?>
        <span style="font-size:11px;color:#888888;">
          v<?= h($versaoAtual['numero_versao']) ?> · editável
        </span>
        <?php else: ?>
        <span style="font-size:11px;color:#FF8A2D;display:flex;align-items:center;gap:4px;">
          <i data-lucide="lock" width="11" height="11"></i> somente leitura
        </span>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Ações de status -->
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex-shrink:0;">

    <!-- Seletor de versão -->
    <?php
    $versaoPub  = $template['versao_publicada']  ?? null;
    $versaoRasc = $template['versao_rascunho']   ?? null;
    $showSegmented = $versaoPub && $versaoRasc;
    ?>
    <?php if ($showSegmented): ?>
    <?php
    $statusRascLabel = $statusLabels[$versaoRasc['status']]['label'] ?? $versaoRasc['status'];
    $segments = [
        ['v' => $versaoPub,  'label' => 'v' . $versaoPub['numero_versao']  . ' · Publicada',       'dot' => '#00A960'],
        ['v' => $versaoRasc, 'label' => 'v' . $versaoRasc['numero_versao'] . ' · ' . $statusRascLabel, 'dot' => '#2347C8'],
    ];
    ?>
    <div class="version-segmented">
      <?php foreach ($segments as $i => $seg):
          $isActive = $seg['v']['id'] === $versaoId;
      ?>
      <a href="?module=templates&action=editor&id=<?= h($templateId) ?>&versao_id=<?= h($seg['v']['id']) ?>"
         class="version-seg-opt <?= $isActive ? 'active' : '' ?>">
        <span style="width:7px;height:7px;border-radius:50%;background:<?= $seg['dot'] ?>;flex-shrink:0;"></span>
        <?= h($seg['label']) ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php elseif (count($historico) > 1): ?>
    <div style="position:relative;">
      <select onchange="if(this.value) window.location='?module=templates&action=editor&id=<?= h($templateId) ?>&versao_id='+this.value"
              style="height:36px;padding:0 32px 0 12px;border:1px solid #DFDFDF;border-radius:4px;
                     font-family:'Segoe UI',Arial,sans-serif;font-size:12px;appearance:none;
                     background:#fff;cursor:pointer;outline:none;color:#141922;"
              onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <?php foreach ($historico as $v): ?>
        <option value="<?= h($v['id']) ?>" <?= $v['id'] === $versaoId ? 'selected' : '' ?>>
          v<?= h($v['numero_versao']) ?> — <?= h($statusLabels[$v['status']]['label'] ?? $v['status']) ?>
          <?= $v['publicado_em'] ? ' (' . date('d/m/Y', strtotime($v['publicado_em'])) . ')' : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
      <i data-lucide="chevron-down" width="14" height="14" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);pointer-events:none;color:#888;"></i>
    </div>
    <?php endif; ?>

    <!-- Botão Novo Rascunho (se publicado sem rascunho) -->
    <?php if ($statusAtual === 'publicado' && !$template['versao_rascunho_id']): ?>
    <form method="post" action="?module=templates&action=novo_rascunho" style="display:inline;">
      <?= Csrf::field() ?>
      <input type="hidden" name="template_id" value="<?= h($templateId) ?>">
      <button type="submit" class="btn btn-secondary"
              onclick="event.preventDefault(); var f=this.closest('form'); btModalConfirm('Nova versão', 'Criar novo rascunho baseado nesta versão publicada?', function(){ f.submit(); })">
        <i data-lucide="copy-plus" width="15" height="15"></i>
        Nova versão
      </button>
    </form>
    <?php endif; ?>

    <!-- Ações de status -->
    <?php if (!empty($acoes) && !$somenteLeitura): ?>
    <?php foreach ($acoes as $acao): ?>
    <form method="post" action="?module=templates&action=avancar_status" class="form-avancar-status" style="display:inline;">
      <?= Csrf::field() ?>
      <input type="hidden" name="template_id" value="<?= h($templateId) ?>">
      <input type="hidden" name="versao_id" value="<?= h($versaoId) ?>">
      <input type="hidden" name="novo_status" value="<?= h($acao['status']) ?>">
      <button type="button" class="btn btn-avancar-status"
              data-novo-status="<?= h($acao['status']) ?>"
              style="<?php
                if ($acao['style'] === 'success') echo 'background:#00A960;color:#fff;border:none;';
                elseif ($acao['style'] === 'secondary') echo 'background:#fff;color:#2347C8;border:1.5px solid #2347C8;';
                elseif ($acao['style'] === 'tertiary') echo 'background:#EAEAEA;color:#606060;border:none;';
                else echo 'background:#2347C8;color:#fff;border:none;';
              ?>">
        <i data-lucide="<?= h($acao['icon']) ?>" width="15" height="15"></i>
        <?= h($acao['label']) ?>
      </button>
    </form>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Descartar rascunho (quando existe versão publicada) -->
    <?php if ($template['versao_publicada_id'] && $template['versao_rascunho_id'] && in_array($statusAtual, ['rascunho','teste_interno','uat'], true)): ?>
    <form method="post" action="?module=templates&action=descartar_rascunho" style="display:inline;">
      <?= Csrf::field() ?>
      <input type="hidden" name="template_id" value="<?= h($templateId) ?>">
      <button type="submit" class="btn" style="background:#FFE8EE;color:#B3261E;border:1px solid #B3261E;"
              onclick="event.preventDefault(); var f=this.closest('form'); btModalConfirm('Descartar rascunho', 'Descartar a versão v<?= h($versaoAtual['numero_versao'] ?? '') ?> em rascunho? O template voltará a usar apenas a versão publicada. Esta ação não pode ser desfeita.', function(){ f.submit(); }, {tipo:'error', confirmLabel:'Descartar', confirmBg:'#B3261E'})">
        <i data-lucide="trash-2" width="14" height="14"></i>
        Descartar rascunho
      </button>
    </form>
    <?php endif; ?>

    <!-- Excluir template (somente sem versão publicada) -->
    <?php if (!$template['versao_publicada_id']): ?>
    <form method="post" action="?module=templates&action=excluir" style="display:inline;">
      <?= Csrf::field() ?>
      <input type="hidden" name="template_id" value="<?= h($templateId) ?>">
      <button type="submit" class="btn" style="background:#FFE8EE;color:#B3261E;border:1px solid #B3261E;"
              data-nome="<?= h($template['nome']) ?>"
              onclick=”event.preventDefault(); var f=this.closest('form'), n=this.dataset.nome; btModalConfirm('Excluir template', 'Excluir o template \'' + n + '\'? Esta ação não pode ser desfeita.', function(){ f.submit(); }, {tipo:'error', confirmLabel:'Excluir', confirmBg:'#B3261E'})”>
        <i data-lucide="trash-2" width="14" height="14"></i>
        Excluir
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php include VIEWS . '/components/alert.php'; ?>

<!-- Painel de seleção de usuários UAT (oculto por padrão) -->
<?php if ($statusAtual === 'teste_interno' && !$somenteLeitura): ?>
<div id="uat-panel" style="display:none;background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.1);border:2px solid #FF8A2D;margin-bottom:20px;overflow:hidden;">
  <div style="height:3px;background:#FF8A2D;"></div>
  <div style="padding:20px 24px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
      <div style="width:36px;height:36px;border-radius:8px;background:#FFF3E0;display:flex;align-items:center;justify-content:center;">
        <i data-lucide="users" width="18" height="18" style="color:#FF8A2D;"></i>
      </div>
      <div>
        <p style="font-size:14px;font-weight:700;color:#141922;margin:0;">Selecionar usuários para teste</p>
        <p style="font-size:12px;color:#888;margin:0;">Escolha quem poderá criar processos de teste com este template</p>
      </div>
    </div>

    <div style="position:relative;margin-bottom:12px;">
      <div style="display:flex;align-items:center;gap:8px;border:1px solid #DFDFDF;border-radius:4px;padding:0 12px;height:40px;background:#fff;">
        <i data-lucide="search" width="16" height="16" style="color:#888;flex-shrink:0;"></i>
        <input type="text" id="uat-user-search" placeholder="Buscar usuário por nome..."
               autocomplete="off" style="border:none;outline:none;flex:1;font-size:14px;color:#141922;background:transparent;">
      </div>
      <div id="uat-user-results" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #DFDFDF;border-top:none;border-radius:0 0 4px 4px;max-height:200px;overflow-y:auto;z-index:10;box-shadow:0 4px 12px rgba(0,0,0,.1);"></div>
    </div>

    <div id="uat-selected-users" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px;min-height:32px;"></div>

    <div style="display:flex;gap:8px;padding-top:12px;border-top:1px solid #F0F0F0;">
      <button type="button" id="uat-confirm-btn" class="btn" disabled
              style="background:#FF8A2D;color:#fff;border:none;opacity:0.5;cursor:not-allowed;">
        <i data-lucide="check" width="15" height="15"></i>
        Confirmar e abrir para usuários
      </button>
      <button type="button" id="uat-cancel-btn" class="btn" style="background:#EAEAEA;color:#606060;border:none;">
        Cancelar
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!$versaoAtual): ?>
<!-- Estado: sem versão -->
<div class="card" style="padding:0;overflow:visible;">
  <div style="height:3px;background:#EAEAEA;border-radius:12px 12px 0 0;"></div>
  <div style="padding:60px 40px;text-align:center;">
    <div style="width:56px;height:56px;border-radius:14px;background:#F6F6F6;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
      <i data-lucide="file-x" width="24" height="24" style="color:#AAAAAA;"></i>
    </div>
    <p style="font-size:15px;font-weight:700;color:#141922;margin-bottom:6px;">Sem versões</p>
    <p style="font-size:13px;color:#888888;">Este template não possui versões ainda.</p>
  </div>
</div>
<?php else: ?>

<!-- Abas do editor -->
<div class="template-editor" id="template-editor">
  <!-- Nav de abas -->
  <div class="template-tabs" role="tablist">
    <?php
    $abas = [
        'geral'       => ['label' => 'Geral',        'icon' => 'settings'],
        'etapas'      => ['label' => 'Etapas',       'icon' => 'git-branch'],
        'campos'      => ['label' => 'Campos',       'icon' => 'form-input'],
        'anexos'      => ['label' => 'Anexos',       'icon' => 'paperclip'],
        'configuracao'=> ['label' => 'Configuração', 'icon' => 'sliders-horizontal'],
    ];
    $abaAtiva = $_GET['aba'] ?? 'geral';
    // Financeiro, rateio e tipo de despesa moraram em abas separadas até
    // 27/07/2026 — hoje são um bloco só em "Configuração". Links e favoritos
    // antigos continuam caindo no lugar certo.
    if (in_array($abaAtiva, ['financeiro', 'rateio'], true)) $abaAtiva = 'configuracao';
    if (!array_key_exists($abaAtiva, $abas)) $abaAtiva = 'geral';
    ?>
    <?php foreach ($abas as $key => $aba): ?>
    <button class="template-tab <?= $key === $abaAtiva ? 'active' : '' ?>"
            data-tab="<?= $key ?>"
            role="tab"
            aria-selected="<?= $key === $abaAtiva ? 'true' : 'false' ?>">
      <i data-lucide="<?= h($aba['icon']) ?>" width="15" height="15"></i>
      <?= h($aba['label']) ?>
      <?php if ($key === 'etapas' && !empty($etapas)): ?>
      <span style="background:#2347C8;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;font-family:var(--font-bold);"><?= count($etapas) ?></span>
      <?php endif; ?>
      <?php if ($key === 'campos' && !empty($campos)): ?>
      <span style="background:#606060;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;font-family:var(--font-bold);"><?= count($campos) ?></span>
      <?php endif; ?>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- Formulário principal (wrap em todas as abas) -->
  <form id="form-editor" method="post" action="?module=templates&action=salvar">
    <?= Csrf::field() ?>
    <input type="hidden" name="template_id" value="<?= h($templateId) ?>">
    <input type="hidden" name="versao_id"   value="<?= h($versaoId) ?>">
    <!-- JSONs serializados pelo JS antes do submit -->
    <input type="hidden" name="campos"      id="input-campos"      value="<?= h(json_encode($campos, JSON_UNESCAPED_UNICODE)) ?>">
    <input type="hidden" name="etapas"      id="input-etapas"      value="<?= h(json_encode($etapas, JSON_UNESCAPED_UNICODE)) ?>">
    <input type="hidden" name="tipos_anexo" id="input-tipos-anexo" value="<?= h(json_encode($tiposAnexo, JSON_UNESCAPED_UNICODE)) ?>">

    <!-- ══════════════════════════════════════════════════════════
         ABA: GERAL
         ══════════════════════════════════════════════════════════ -->
    <div class="tab-panel <?= $abaAtiva === 'geral' ? 'active' : '' ?>" data-panel="geral">
      <div class="card" style="padding:0;overflow:visible;max-width:700px;">

        <!-- Faixa de acento vermelha no topo -->
        <div style="height:3px;background:#2347C8;border-radius:12px 12px 0 0;"></div>

        <div style="padding:28px 32px 32px;">

          <!-- Cabeçalho do card com ícone -->
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;">
            <div style="width:40px;height:40px;flex-shrink:0;border-radius:8px;background:#EEF2FD;
                        display:flex;align-items:center;justify-content:center;">
              <i data-lucide="settings" width="18" height="18" style="color:#2347C8;"></i>
            </div>
            <div>
              <p style="font-size:15px;font-weight:700;color:#141922;margin:0 0 2px 0;">Informações gerais</p>
              <p style="font-size:12px;color:#888888;margin:0;">Identidade e condições de acionamento deste template.</p>
            </div>
          </div>

          <div style="height:1px;background:#F0F0F0;margin-bottom:24px;"></div>

          <div style="display:grid;gap:20px;">

            <!-- Nome do template -->
            <div>
              <label for="g-nome"
                     style="display:block;font-size:11px;font-weight:700;color:#606060;
                            letter-spacing:0.6px;text-transform:uppercase;margin-bottom:8px;">
                Nome do template <span style="color:#2347C8;">*</span>
              </label>
              <div id="g-nome-wrapper" style="position:relative;">
                <input type="text"
                       id="g-nome"
                       name="nome"
                       maxlength="80"
                       <?= $somenteLeitura ? 'readonly' : 'required' ?>
                       placeholder="Nome do template"
                       value="<?= h($template['nome']) ?>"
                       style="width:100%;height:52px;padding:0 52px 0 16px;
                              font-size:15px;font-family:'Segoe UI',Arial,sans-serif;
                              color:#141922;background:<?= $somenteLeitura ? '#F6F6F6' : '#FFFFFF' ?>;
                              border:2px solid #E0E0E0;border-radius:6px;
                              outline:none;transition:border-color 0.15s,box-shadow 0.15s;
                              <?= $somenteLeitura ? 'cursor:not-allowed;' : '' ?>">
                <span id="g-nome-counter"
                      style="position:absolute;right:14px;top:50%;transform:translateY(-50%);
                             font-size:11px;color:#C8C8C8;pointer-events:none;
                             font-family:'Segoe UI',Arial,sans-serif;transition:color 0.15s;">
                  <?= mb_strlen($template['nome']) ?>/80
                </span>
              </div>
            </div>

            <!-- Separador com título de seção -->
            <div style="display:flex;align-items:center;gap:10px;margin-top:4px;">
              <span style="font-size:11px;font-weight:700;color:#606060;text-transform:uppercase;letter-spacing:0.6px;white-space:nowrap;">
                Condições de acionamento
              </span>
              <div style="flex:1;height:1px;background:#EAEAEA;"></div>
            </div>
            <p style="font-size:12px;color:#AAAAAA;margin-top:-12px;">
              Deixe em branco para corresponder a qualquer valor. Quanto mais específico, maior a prioridade na seleção automática.
            </p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

              <!-- Filial -->
              <div>
                <label for="g-filial"
                       style="display:block;font-size:11px;font-weight:700;color:#606060;
                              letter-spacing:0.6px;text-transform:uppercase;margin-bottom:8px;">
                  Loja / Filial
                </label>
                <select id="g-filial" name="filial_id"
                        <?= $somenteLeitura ? 'disabled' : '' ?>
                        style="width:100%;height:40px;padding:0 12px;
                               border:1px solid #DFDFDF;border-radius:4px;
                               font-family:'Segoe UI',Arial,sans-serif;font-size:13px;
                               color:#141922;background:<?= $somenteLeitura ? '#F6F6F6' : '#FFFFFF' ?>;
                               outline:none;transition:border-color 0.15s;appearance:none;
                               background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E\");
                               background-repeat:no-repeat;background-position:right 10px center;"
                        onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
                  <option value="">Todas as filiais</option>
                  <?php foreach ($filiais as $f): ?>
                  <option value="<?= h($f['id']) ?>" <?= ($versaoAtual['filial_id'] ?? '') === $f['id'] ? 'selected' : '' ?>>
                    <?= h($f['nome']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Departamento — primeiro recorte do funil -->
              <div>
                <label for="g-departamento"
                       style="display:block;font-size:11px;font-weight:700;color:#606060;
                              letter-spacing:0.6px;text-transform:uppercase;margin-bottom:8px;">
                  Departamento
                </label>
                <select id="g-departamento" name="departamento_id"
                        <?= $somenteLeitura ? 'disabled' : '' ?>
                        onchange="tplFiltrarSetores(this.value)"
                        style="width:100%;height:40px;padding:0 12px;
                               border:1px solid #DFDFDF;border-radius:4px;
                               font-family:'Segoe UI',Arial,sans-serif;font-size:13px;
                               color:#141922;background:<?= $somenteLeitura ? '#F6F6F6' : '#FFFFFF' ?>;
                               outline:none;transition:border-color 0.15s;"
                        onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
                  <option value="">Selecione o departamento…</option>
                  <?php foreach (($departamentos ?? []) as $d): ?>
                  <option value="<?= h($d['id']) ?>" <?= ($versaoAtual['departamento_id'] ?? '') === $d['id'] ? 'selected' : '' ?>>
                    <?= h($d['valor']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <p style="font-size:11px;color:#888;margin:6px 0 0">
                  Sem departamento, o fluxo não aparece na abertura de processo.
                </p>
              </div>

              <!-- Setor — opcional: vazio = vale para todo o departamento -->
              <div>
                <label for="g-setor"
                       style="display:block;font-size:11px;font-weight:700;color:#606060;
                              letter-spacing:0.6px;text-transform:uppercase;margin-bottom:8px;">
                  Setor
                </label>
                <select id="g-setor" name="setor_id"
                        <?= $somenteLeitura ? 'disabled' : '' ?>
                        style="width:100%;height:40px;padding:0 12px;
                               border:1px solid #DFDFDF;border-radius:4px;
                               font-family:'Segoe UI',Arial,sans-serif;font-size:13px;
                               color:#141922;background:<?= $somenteLeitura ? '#F6F6F6' : '#FFFFFF' ?>;
                               outline:none;transition:border-color 0.15s;appearance:none;
                               background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E\");
                               background-repeat:no-repeat;background-position:right 10px center;"
                        onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
                  <option value="">Todo o departamento</option>
                  <?php foreach ($setores as $s): ?>
                  <option value="<?= h($s['id']) ?>"
                          data-departamento="<?= h($s['departamento_id'] ?? '') ?>"
                          <?= ($versaoAtual['setor_id'] ?? '') === $s['id'] ? 'selected' : '' ?>>
                    <?= h($s['nome']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <script>
                // Setor segue o departamento escolhido — o funil é
                // Departamento → Setor, e oferecer setor de outro departamento
                // deixaria o fluxo inalcançável na abertura.
                (function () {
                  var TPL_SETORES = <?= json_encode(array_map(fn($s) => [
                        'id'    => $s['id'],
                        'nome'  => $s['nome'],
                        'depto' => $s['departamento_id'] ?? null,
                      ], $setores), JSON_UNESCAPED_UNICODE) ?>;
                  var atual = <?= json_encode($versaoAtual['setor_id'] ?? '') ?>;

                  window.tplFiltrarSetores = function (deptoId) {
                    var sel = document.getElementById('g-setor');
                    if (!sel) return;
                    var escolhido = sel.value || atual;
                    sel.innerHTML = '';
                    sel.appendChild(new Option('Todo o departamento', ''));
                    TPL_SETORES.forEach(function (s) {
                      if (!deptoId || s.depto === deptoId) {
                        var o = new Option(s.nome, s.id);
                        if (s.id === escolhido) o.selected = true;
                        sel.appendChild(o);
                      }
                    });
                  };

                  var dep = document.getElementById('g-departamento');
                  if (dep && dep.value) window.tplFiltrarSetores(dep.value);
                })();
              </script>

              <!-- Tipo de nota -->
              <div>
                <label for="g-tipo-nota"
                       style="display:block;font-size:11px;font-weight:700;color:#606060;
                              letter-spacing:0.6px;text-transform:uppercase;margin-bottom:8px;">
                  Tipo de nota fiscal
                </label>
                <select id="g-tipo-nota" name="tipo_nota_id"
                        <?= $somenteLeitura ? 'disabled' : '' ?>
                        style="width:100%;height:40px;padding:0 12px;
                               border:1px solid #DFDFDF;border-radius:4px;
                               font-family:'Segoe UI',Arial,sans-serif;font-size:13px;
                               color:#141922;background:<?= $somenteLeitura ? '#F6F6F6' : '#FFFFFF' ?>;
                               outline:none;transition:border-color 0.15s;appearance:none;
                               background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E\");
                               background-repeat:no-repeat;background-position:right 10px center;"
                        onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
                  <option value="">Todos os tipos</option>
                  <?php foreach ($tiposNota as $tn): ?>
                  <option value="<?= h($tn['id']) ?>" <?= ($versaoAtual['tipo_nota_id'] ?? '') === $tn['id'] ? 'selected' : '' ?>>
                    <?= h($tn['nome']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Faixa de valor -->
              <div>
                <label style="display:block;font-size:11px;font-weight:700;color:#606060;
                              letter-spacing:0.6px;text-transform:uppercase;margin-bottom:8px;">
                  Faixa de valor (R$)
                </label>
                <div style="display:flex;align-items:center;gap:8px;">
                  <input type="text" name="valor_min"
                         placeholder="Mínimo"
                         <?= $somenteLeitura ? 'readonly' : '' ?>
                         value="<?= $versaoAtual['valor_min'] !== null ? number_format((float)$versaoAtual['valor_min'], 2, ',', '.') : '' ?>"
                         inputmode="decimal"
                         style="flex:1;height:40px;padding:0 12px;
                                border:1px solid #DFDFDF;border-radius:4px;
                                font-family:'Segoe UI',Arial,sans-serif;font-size:13px;
                                color:#141922;background:<?= $somenteLeitura ? '#F6F6F6' : '#FFFFFF' ?>;
                                outline:none;transition:border-color 0.15s;"
                         onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
                  <span style="color:#888;font-size:12px;white-space:nowrap;">até</span>
                  <input type="text" name="valor_max"
                         placeholder="Máximo"
                         <?= $somenteLeitura ? 'readonly' : '' ?>
                         value="<?= $versaoAtual['valor_max'] !== null ? number_format((float)$versaoAtual['valor_max'], 2, ',', '.') : '' ?>"
                         inputmode="decimal"
                         style="flex:1;height:40px;padding:0 12px;
                                border:1px solid #DFDFDF;border-radius:4px;
                                font-family:'Segoe UI',Arial,sans-serif;font-size:13px;
                                color:#141922;background:<?= $somenteLeitura ? '#F6F6F6' : '#FFFFFF' ?>;
                                outline:none;transition:border-color 0.15s;"
                         onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
                </div>
                <p style="font-size:11px;color:#AAAAAA;margin-top:6px;">Deixe vazio para qualquer valor.</p>
              </div>

            </div><!-- /grid 2 colunas -->
          </div><!-- /grid gap:20 -->

          <?php if (!$somenteLeitura): ?>
          <div style="margin-top:28px;padding-top:20px;border-top:1px solid #F0F0F0;display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="aba_proxima" value="etapas" form="form-editor" id="aba-proxima-geral">
            <button type="submit" class="btn btn-primary" style="gap:8px;">
              <i data-lucide="save" width="15" height="15"></i>
              Salvar e configurar etapas
              <i data-lucide="arrow-right" width="14" height="14"></i>
            </button>
            <button type="submit" class="btn btn-tertiary" id="btn-salvar-apenas"
                    onclick="document.getElementById('aba-proxima-geral').value=''">
              Salvar apenas
            </button>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         ABA: ETAPAS
         ══════════════════════════════════════════════════════════ -->
    <div class="tab-panel <?= $abaAtiva === 'etapas' ? 'active' : '' ?>" data-panel="etapas">
      <div class="card" style="padding:0;overflow:visible;">

        <div style="height:3px;background:#2347C8;border-radius:12px 12px 0 0;"></div>

        <div style="padding:28px 32px 32px;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;gap:12px;">
            <div style="display:flex;align-items:center;gap:12px;">
              <div style="width:40px;height:40px;flex-shrink:0;border-radius:8px;background:#EEF2FD;
                          display:flex;align-items:center;justify-content:center;">
                <i data-lucide="git-branch" width="18" height="18" style="color:#2347C8;"></i>
              </div>
              <div>
                <p style="font-size:15px;font-weight:700;color:#141922;margin:0 0 2px 0;">Etapas de aprovação</p>
                <p style="font-size:12px;color:#888888;margin:0;">
                  Selecione etapas do catálogo padronizado. A ordem pode ser reordenada arrastando.
                  <a href="?module=tabelas&action=itens&id=<?= h(TableList::ID_ETAPAS) ?>" target="_blank"
                     style="color:#2347C8;text-decoration:none;font-weight:700;">Gerenciar etapas →</a>
                </p>
              </div>
            </div>
            <?php if (!$somenteLeitura): ?>
            <button type="button" class="btn btn-secondary" id="btn-add-etapa">
              <i data-lucide="plus" width="15" height="15"></i>
              Adicionar etapa
            </button>
            <?php endif; ?>
          </div>

          <!-- Lista de etapas (gerenciada por JS) -->
          <div id="etapas-list">
            <?php if (empty($etapas)): ?>
            <div style="padding:48px 20px;text-align:center;" id="etapas-empty">
              <div style="width:48px;height:48px;border-radius:12px;background:#F6F6F6;display:inline-flex;align-items:center;justify-content:center;margin-bottom:14px;">
                <i data-lucide="git-branch" width="22" height="22" style="color:#AAAAAA;"></i>
              </div>
              <p style="font-size:14px;font-weight:700;color:#141922;margin-bottom:6px;">Nenhuma etapa adicionada</p>
              <p style="font-size:13px;color:#888888;margin-bottom:16px;">As etapas definem quem precisa aprovar este tipo de nota fiscal.</p>
              <?php if (!$somenteLeitura): ?>
              <button type="button" class="btn btn-secondary" onclick="document.getElementById('btn-add-etapa').click()">
                <i data-lucide="plus" width="15" height="15"></i>
                Adicionar primeira etapa
              </button>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>

          <?php if (!$somenteLeitura): ?>
          <div style="margin-top:20px;padding-top:20px;border-top:1px solid #F0F0F0;display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary"
                    onclick="document.getElementById('aba-proxima-geral').value='etapas'">
              <i data-lucide="save" width="15" height="15"></i>
              Salvar etapas
            </button>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         ABA: CAMPOS
         ══════════════════════════════════════════════════════════ -->
    <div class="tab-panel <?= $abaAtiva === 'campos' ? 'active' : '' ?>" data-panel="campos">
      <div class="card" style="padding:0;overflow:visible;">

        <div style="height:3px;background:#2347C8;border-radius:12px 12px 0 0;"></div>

        <div style="padding:28px 32px 32px;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;gap:12px;">
            <div style="display:flex;align-items:center;gap:12px;">
              <div style="width:40px;height:40px;flex-shrink:0;border-radius:8px;background:#EEF2FD;
                          display:flex;align-items:center;justify-content:center;">
                <i data-lucide="form-input" width="18" height="18" style="color:#2347C8;"></i>
              </div>
              <div>
                <p style="font-size:15px;font-weight:700;color:#141922;margin:0 0 2px 0;">Campos dinâmicos</p>
                <p style="font-size:12px;color:#888888;margin:0;">Campos extras exibidos no formulário de abertura do processo.</p>
              </div>
            </div>
            <?php if (!$somenteLeitura): ?>
            <button type="button" class="btn btn-secondary" id="btn-add-campo">
              <i data-lucide="plus" width="15" height="15"></i>
              Adicionar campo
            </button>
            <?php endif; ?>
          </div>

          <!-- Campos padrão do sistema — sempre presentes, não editáveis -->
          <div class="std-fields-section-label">
            <i data-lucide="lock" width="11" height="11"></i>
            Campos padrão do sistema · sempre presentes em todos os processos
          </div>
          <div class="std-fields-grid">
            <div class="std-field-chip">
              <div class="std-field-name">Fornecedor</div>
              <div class="std-field-type">Busca</div>
            </div>
            <div class="std-field-chip">
              <div class="std-field-name">CPF/CNPJ fornecedor</div>
              <div class="std-field-type">Auto-preenchido</div>
            </div>
            <div class="std-field-chip">
              <div class="std-field-name">Número da nota</div>
              <div class="std-field-type">Texto</div>
            </div>
            <div class="std-field-chip">
              <div class="std-field-name">Valor da nota</div>
              <div class="std-field-type">Monetário</div>
            </div>
            <div class="std-field-chip">
              <div class="std-field-name">Data de emissão</div>
              <div class="std-field-type">Data</div>
            </div>
            <div class="std-field-chip">
              <div class="std-field-name">Chave da nota</div>
              <div class="std-field-type">Texto</div>
            </div>
            <div class="std-field-chip">
              <div class="std-field-name">Emitido para (loja)</div>
              <div class="std-field-type">Lista (Filiais)</div>
            </div>
            <div class="std-field-chip">
              <div class="std-field-name">CNPJ destinatário</div>
              <div class="std-field-type">Auto-preenchido</div>
            </div>
          </div>

          <hr class="cfields-divider">

          <div id="campos-list">
            <?php if (empty($campos)): ?>
            <div style="padding:28px 20px;text-align:center;" id="campos-empty">
              <p style="font-size:13px;color:#888888;margin:0;">Nenhum campo personalizado adicionado. Use o botão acima para criar campos extras.</p>
            </div>
            <?php endif; ?>
          </div>

          <?php if (!$somenteLeitura): ?>
          <div style="margin-top:20px;padding-top:20px;border-top:1px solid #F0F0F0;display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary"
                    onclick="document.getElementById('aba-proxima-geral').value='campos'">
              <i data-lucide="save" width="15" height="15"></i>
              Salvar campos
            </button>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         ABA: ANEXOS
         ══════════════════════════════════════════════════════════ -->
    <div class="tab-panel <?= $abaAtiva === 'anexos' ? 'active' : '' ?>" data-panel="anexos">
      <div class="card" style="padding:0;overflow:visible;">

        <div style="height:3px;background:#2347C8;border-radius:12px 12px 0 0;"></div>

        <div style="padding:28px 32px 32px;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;gap:12px;">
            <div style="display:flex;align-items:center;gap:12px;">
              <div style="width:40px;height:40px;flex-shrink:0;border-radius:8px;background:#EEF2FD;
                          display:flex;align-items:center;justify-content:center;">
                <i data-lucide="paperclip" width="18" height="18" style="color:#2347C8;"></i>
              </div>
              <div>
                <p style="font-size:15px;font-weight:700;color:#141922;margin:0 0 2px 0;">Tipos de anexo</p>
                <p style="font-size:12px;color:#888888;margin:0;">Documentos que o solicitante deve enviar ao abrir o processo.</p>
              </div>
            </div>
            <?php if (!$somenteLeitura): ?>
            <button type="button" class="btn btn-secondary" id="btn-add-anexo">
              <i data-lucide="plus" width="15" height="15"></i>
              Adicionar tipo
            </button>
            <?php endif; ?>
          </div>

          <div id="anexos-list">
            <?php if (empty($tiposAnexo)): ?>
            <div style="padding:48px 20px;text-align:center;" id="anexos-empty">
              <div style="width:48px;height:48px;border-radius:12px;background:#F6F6F6;display:inline-flex;align-items:center;justify-content:center;margin-bottom:14px;">
                <i data-lucide="paperclip" width="22" height="22" style="color:#AAAAAA;"></i>
              </div>
              <p style="font-size:14px;font-weight:700;color:#141922;margin-bottom:6px;">Nenhum tipo de anexo definido</p>
              <p style="font-size:13px;color:#888888;">Adicione documentos que devem ser enviados junto com o processo.</p>
            </div>
            <?php endif; ?>
          </div>

          <?php if (!$somenteLeitura): ?>
          <div style="margin-top:20px;padding-top:20px;border-top:1px solid #F0F0F0;display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary"
                    onclick="document.getElementById('aba-proxima-geral').value='anexos'">
              <i data-lucide="save" width="15" height="15"></i>
              Salvar anexos
            </button>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         ABA: FINANCEIRO
         ══════════════════════════════════════════════════════════ -->
    <!-- ══════════════════════════════════════════════════════════
         ABA: CONFIGURAÇÃO — financeiro, rateio e tipo de despesa
         Os três definem o MESMO tipo de coisa (o que o solicitante informa
         sobre dinheiro ao abrir o processo), então ficam na mesma tela e
         salvam juntos, num único Salvar.
         ══════════════════════════════════════════════════════════ -->
    <div class="tab-panel <?= $abaAtiva === 'configuracao' ? 'active' : '' ?>" data-panel="configuracao">
      <div class="card" style="padding:0;overflow:visible;max-width:600px;">

        <div style="height:3px;background:#2347C8;border-radius:12px 12px 0 0;"></div>

        <div style="padding:28px 32px 32px;">

          <div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;">
            <div style="width:40px;height:40px;flex-shrink:0;border-radius:8px;background:#EEF2FD;
                        display:flex;align-items:center;justify-content:center;">
              <i data-lucide="sliders-horizontal" width="18" height="18" style="color:#2347C8;"></i>
            </div>
            <div>
              <p style="font-size:15px;font-weight:700;color:#141922;margin:0 0 2px 0;">Configuração do template</p>
              <p style="font-size:12px;color:#888888;margin:0;">Financeiro, rateio e tipo de despesa — o que o solicitante informa sobre valores ao abrir o processo.</p>
            </div>
          </div>

          <div style="height:1px;background:#F0F0F0;margin-bottom:24px;"></div>

          <!-- ── Controle financeiro ──────────────────────────────── -->
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
            <i data-lucide="banknote" width="16" height="16" style="color:#2347C8;"></i>
            <p style="font-size:14px;font-weight:700;color:#141922;margin:0;">Controle financeiro</p>
          </div>

          <!-- Toggle financeiro -->
          <div style="display:flex;align-items:center;gap:16px;background:#F8F8F8;border-radius:10px;padding:18px 20px;margin-bottom:20px;">
            <label class="toggle-slider-wrap"
                   style="position:relative;display:inline-flex;align-items:center;gap:16px;
                          cursor:<?= $somenteLeitura ? 'default' : 'pointer' ?>;">
              <input type="checkbox"
                     name="tem_financeiro"
                     id="chk-financeiro"
                     value="1"
                     <?= ($versaoAtual['tem_financeiro'] ?? 0) ? 'checked' : '' ?>
                     <?= $somenteLeitura ? 'disabled' : '' ?>
                     style="display:none;"
                     onchange="toggleFinanceiroPanel(this.checked)">
              <span class="toggle-slider" id="toggle-financeiro"></span>
              <div>
                <div style="font-size:14px;font-weight:700;color:#141922;margin-bottom:3px;">
                  Habilitar controle financeiro
                </div>
                <div style="font-size:12px;color:#888888;">
                  Solicitante poderá informar parcelas de pagamento ao abrir o processo.
                </div>
              </div>
            </label>
          </div>

          <!-- Info quando habilitado -->
          <div id="financeiro-info" style="display:<?= ($versaoAtual['tem_financeiro'] ?? 0) ? 'block' : 'none' ?>;">
            <div style="background:#D8F5E5;border-left:4px solid #00A960;border-radius:4px;padding:14px 18px;">
              <div style="font-size:13px;font-weight:700;color:#00A960;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                <i data-lucide="check-circle" width="14" height="14"></i>
                Financeiro habilitado
              </div>
              <ul style="font-size:12px;color:#606060;padding-left:18px;margin:0;line-height:1.8;">
                <li>Ao abrir o processo, o solicitante informará datas e valores de cada parcela.</li>
                <li>Ao concluir o fluxo, as parcelas são geradas automaticamente no Controle Financeiro.</li>
                <li>O financeiro poderá agendar e registrar os pagamentos.</li>
                <li>Uma etapa obrigatória <strong>"<?= h(Template::ETAPA_FINANCEIRO_NOME) ?>"</strong> foi adicionada na aba Etapas — defina manualmente o responsável (grupo ou usuário).</li>
              </ul>
            </div>
          </div>

          <!-- ── Assinatura eletrônica ───────────────────────────── -->
          <div style="height:1px;background:#F0F0F0;margin:24px 0;"></div>

          <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
            <i data-lucide="pen-tool" width="16" height="16" style="color:#2347C8;"></i>
            <p style="font-size:14px;font-weight:700;color:#141922;margin:0;">Assinatura eletrônica</p>
          </div>

          <div style="display:flex;align-items:center;gap:16px;background:#F8F8F8;border-radius:10px;padding:18px 20px;margin-bottom:20px;">
            <label class="toggle-slider-wrap"
                   style="position:relative;display:inline-flex;align-items:center;gap:16px;
                          cursor:<?= $somenteLeitura ? 'default' : 'pointer' ?>;">
              <input type="checkbox"
                     name="tem_assinatura"
                     id="chk-assinatura"
                     value="1"
                     <?= ($versaoAtual['tem_assinatura'] ?? 0) ? 'checked' : '' ?>
                     <?= $somenteLeitura ? 'disabled' : '' ?>
                     style="display:none;">
              <span class="toggle-slider" id="toggle-assinatura"></span>
              <div>
                <div style="font-size:14px;font-weight:700;color:#141922;margin-bottom:3px;">
                  Habilitar assinatura eletrônica
                </div>
                <div style="font-size:12px;color:#888888;">
                  O documento anexado é enviado para assinatura. O processo aguarda os signatários antes de seguir.
                </div>
              </div>
            </label>
          </div>

          <div id="assinatura-info" style="display:<?= ($versaoAtual['tem_assinatura'] ?? 0) ? 'block' : 'none' ?>;">

            <!-- Lista de papéis — serializada igual a campos/etapas -->
            <input type="hidden" name="assinatura_signatarios" id="input-assinatura-signatarios"
                   value="<?= h(json_encode($assinaturaCfg['signatarios'] ?? [], JSON_UNESCAPED_UNICODE)) ?>">

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
              <label style="font-size:12px;font-weight:700;color:#606060;">Quem participa</label>
              <?php if (!$somenteLeitura): ?>
                <button type="button" id="btn-add-signatario"
                        style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;background:transparent;
                               color:#2347C8;border:1px solid #2347C8;border-radius:4px;font-size:12px;font-weight:700;cursor:pointer;">
                  <i data-lucide="plus" width="13" height="13"></i> Adicionar papel
                </button>
              <?php endif; ?>
            </div>

            <div id="assinatura-signatarios-list" style="margin-bottom:8px;"></div>

            <p id="assinatura-clicksign-nota" style="display:<?= ($assinaturaCfg['provedor'] ?? '') === 'clicksign' ? 'block' : 'none' ?>;font-size:11px;color:#606060;margin:0 0 12px 0;line-height:1.7;background:#F6F6F6;border-radius:6px;padding:10px 12px;">
              <strong>Assinatura pela Clicksign:</strong> são sempre <strong>dois signatários</strong>, e os dois assinam.
              Só o <em>nome do papel</em> é editável (ele vira o prefixo dos campos). Para cada um o solicitante informa
              nome completo, CPF, e-mail, telefone (exigido só para SMS e WhatsApp) e o tipo de assinatura.
            </p>

            <p style="font-size:11px;color:#888888;margin:0 0 16px 0;line-height:1.7;">
              Quem <strong>assina</strong> recebe o convite e assina; quem <strong>recebe cópia</strong> só recebe o
              documento pronto. Todos que assinam são avisados de uma vez e assinam <strong>em paralelo</strong>,
              em qualquer ordem.<br>
              O papel vira o nome dos campos do processo — <em>“Contratante — E-mail”</em>. Ele organiza o fluxo aqui
              dentro, mas <strong>não é impresso no certificado</strong>: lá todos aparecem como partes iguais.
            </p>

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
              <div style="flex:1;min-width:150px;">
                <label style="display:block;font-size:12px;font-weight:700;color:#606060;margin-bottom:6px;">
                  Prazo para assinar
                </label>
                <select name="assinatura_prazo_dias"
                        <?= $somenteLeitura ? 'disabled' : '' ?>
                        style="width:100%;height:40px;border:1px solid #DFDFDF;border-radius:4px;padding:0 10px;font-size:13px;color:#141922;background:#FFFFFF;">
                  <?php $prazoAtual = (int) ($assinaturaCfg['prazo_dias'] ?? 7); ?>
                  <?php foreach ([0 => 'Sem prazo', 3 => '3 dias', 7 => '7 dias', 15 => '15 dias', 30 => '30 dias'] as $d => $rot): ?>
                    <option value="<?= $d ?>" <?= $prazoAtual === $d ? 'selected' : '' ?>><?= h($rot) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div style="flex:1;min-width:200px;">
                <label style="display:block;font-size:12px;font-weight:700;color:#606060;margin-bottom:6px;">
                  Se o prazo vencer
                </label>
                <select name="assinatura_ao_expirar"
                        <?= $somenteLeitura ? 'disabled' : '' ?>
                        style="width:100%;height:40px;border:1px solid #DFDFDF;border-radius:4px;padding:0 10px;font-size:13px;color:#141922;background:#FFFFFF;">
                  <?php $expAtual = (string) ($assinaturaCfg['ao_expirar'] ?? 'devolver_anterior'); ?>
                  <?php foreach (Template::AO_EXPIRAR as $val => $rot): ?>
                    <option value="<?= h($val) ?>" <?= $expAtual === $val ? 'selected' : '' ?>><?= h($rot) ?></option>
                  <?php endforeach; ?>
                </select>
                <span style="display:block;font-size:11px;color:#888888;margin-top:5px;">
                  Volta para quem aprovou a etapa anterior — quem conferiu o documento e mandou assinar.
                </span>
              </div>
            </div>

            <div style="background:#D8F5E5;border-left:4px solid #00A960;border-radius:4px;padding:14px 18px;">
              <div style="font-size:13px;font-weight:700;color:#00A960;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                <i data-lucide="check-circle" width="14" height="14"></i>
                Assinatura habilitada — o que foi criado automaticamente
              </div>
              <ul style="font-size:12px;color:#606060;padding-left:18px;margin:0;line-height:1.8;">
                <li>Etapa <strong>"<span id="assinatura-etapa-nome"><?= h(Template::PROVEDORES_ASSINATURA[$assinaturaCfg['provedor'] ?? 'assinafy'] ?? Template::ETAPA_ASSINATURA_NOME) ?></span>"</strong> na aba Etapas — defina o responsável (quem acompanha e recebe o aviso de recusa).</li>
                <li>Anexo obrigatório <strong>"<?= h(Template::ANEXO_ASSINATURA_NOME) ?>"</strong> na aba Anexos — é o arquivo que vai para assinatura, exigido numa etapa <em>antes</em> da assinatura.</li>
                <li>Campos obrigatórios de <strong>nome e e-mail de cada papel</strong> na aba Campos — preenchidos pelo solicitante ao abrir o processo.</li>
                <li>O processo <strong>não avança</strong> enquanto todos não assinarem; o PDF assinado volta como anexo, com o link público de verificação.</li>
              </ul>
            </div>

            <div style="background:#FFF8E1;border-left:4px solid #FF8A2D;border-radius:4px;padding:12px 16px;margin-top:12px;">
              <span style="font-size:12px;color:#606060;">
                <strong style="color:#FF8A2D;">Publicação bloqueada</strong> enquanto faltar a etapa, o anexo ou os campos —
                assim o fluxo nunca trava em produção por falta de dado.
              </span>
            </div>
          </div>

          <!-- ── Rateio entre centros de custo ────────────────────── -->
          <div style="height:1px;background:#F0F0F0;margin:24px 0;"></div>

          <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
            <i data-lucide="pie-chart" width="16" height="16" style="color:#2347C8;"></i>
            <p style="font-size:14px;font-weight:700;color:#141922;margin:0;">Rateio entre centros de custo</p>
          </div>

          <!-- Toggle rateio -->
          <div style="display:flex;align-items:center;gap:16px;background:#F8F8F8;border-radius:10px;padding:18px 20px;margin-bottom:20px;">
            <label class="toggle-slider-wrap"
                   style="position:relative;display:inline-flex;align-items:center;gap:16px;
                          cursor:<?= $somenteLeitura ? 'default' : 'pointer' ?>;">
              <input type="checkbox"
                     name="tem_rateio"
                     id="chk-rateio"
                     value="1"
                     <?= ($versaoAtual['tem_rateio'] ?? 0) ? 'checked' : '' ?>
                     <?= $somenteLeitura ? 'disabled' : '' ?>
                     style="display:none;"
                     onchange="toggleRateioPanel(this.checked)">
              <span class="toggle-slider" id="toggle-rateio"></span>
              <div>
                <div style="font-size:14px;font-weight:700;color:#141922;margin-bottom:3px;">
                  Habilitar rateio entre centros de custo
                </div>
                <div style="font-size:12px;color:#888888;">
                  Solicitante poderá dividir o valor da NF entre um ou mais centros de custo.
                </div>
              </div>
            </label>
          </div>

          <!-- Info quando habilitado -->
          <div id="rateio-info" style="display:<?= ($versaoAtual['tem_rateio'] ?? 0) ? 'block' : 'none' ?>;">
            <div style="background:#D8F5E5;border-left:4px solid #00A960;border-radius:4px;padding:14px 18px;">
              <div style="font-size:13px;font-weight:700;color:#00A960;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                <i data-lucide="check-circle" width="14" height="14"></i>
                Rateio habilitado
              </div>
              <ul style="font-size:12px;color:#606060;padding-left:18px;margin:0;line-height:1.8;">
                <li>Ao abrir o processo, o solicitante seleciona de 1 a vários centros de custo (setores).</li>
                <li>O valor é dividido igualmente a cada seleção — com ajuste manual por valor ou percentual.</li>
                <li>O rateio fica gravado no processo e é usado no lançamento da nota.</li>
                <li>Os centros de custo vêm do cadastro de Setores (Listas) — configure o <strong>código</strong> de cada setor lá.</li>
              </ul>
            </div>
          </div>

          <!-- ── Tipo de Despesa ─────────────────────────────────── -->
          <div style="height:1px;background:#F0F0F0;margin:24px 0;"></div>

          <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
            <i data-lucide="tag" width="16" height="16" style="color:#2347C8;"></i>
            <p style="font-size:14px;font-weight:700;color:#141922;margin:0;">Tipo de despesa</p>
          </div>

          <div style="display:flex;align-items:center;gap:16px;background:#F8F8F8;border-radius:10px;padding:18px 20px;margin-bottom:20px;">
            <label class="toggle-slider-wrap"
                   style="position:relative;display:inline-flex;align-items:center;gap:16px;
                          cursor:<?= $somenteLeitura ? 'default' : 'pointer' ?>;">
              <input type="checkbox"
                     name="tem_tipo_despesa"
                     id="chk-tipo-despesa"
                     value="1"
                     <?= ($versaoAtual['tem_tipo_despesa'] ?? 0) ? 'checked' : '' ?>
                     <?= $somenteLeitura ? 'disabled' : '' ?>
                     style="display:none;"
                     onchange="toggleTipoDespesaPanel(this.checked)">
              <span class="toggle-slider" id="toggle-tipo-despesa"></span>
              <div>
                <div style="font-size:14px;font-weight:700;color:#141922;margin-bottom:3px;">
                  Habilitar tipo de despesa
                </div>
                <div style="font-size:12px;color:#888888;">
                  Solicitante classifica a natureza do gasto (Internet, Telefone…) — base dos relatórios de acompanhamento.
                </div>
              </div>
            </label>
          </div>

          <div id="tipo-despesa-info" style="display:<?= ($versaoAtual['tem_tipo_despesa'] ?? 0) ? 'block' : 'none' ?>;">
            <label style="display:flex;align-items:flex-start;gap:10px;background:#FFF8E1;border-left:4px solid #FF8A2D;border-radius:4px;padding:14px 18px;margin-bottom:14px;cursor:<?= $somenteLeitura ? 'default' : 'pointer' ?>;">
              <input type="checkbox" name="tipo_despesa_obrigatorio" value="1"
                     <?= ($versaoAtual['tipo_despesa_obrigatorio'] ?? 0) ? 'checked' : '' ?>
                     <?= $somenteLeitura ? 'disabled' : '' ?>
                     style="margin-top:2px;width:16px;height:16px;accent-color:#2347C8;flex-shrink:0;">
              <span>
                <span style="display:block;font-size:13px;font-weight:700;color:#141922;margin-bottom:2px;">Exigir o preenchimento</span>
                <span style="display:block;font-size:12px;color:#606060;">
                  Sem ao menos um tipo, o processo não pode ser aberto. Deixe desmarcado enquanto a lista de tipos ainda estiver sendo definida.
                </span>
              </span>
            </label>

            <div style="background:#D8F5E5;border-left:4px solid #00A960;border-radius:4px;padding:14px 18px;">
              <div style="font-size:13px;font-weight:700;color:#00A960;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                <i data-lucide="check-circle" width="14" height="14"></i>
                Tipo de despesa habilitado
              </div>
              <ul style="font-size:12px;color:#606060;padding-left:18px;margin:0;line-height:1.8;">
                <li>Selecionando um único tipo, ele fica com 100% do valor — um clique.</li>
                <li>Quando a nota mistura naturezas (ex.: internet + telefone na mesma fatura), dá para dividir por % ou valor.</li>
                <li>Os tipos vêm da lista <strong>Tipos de despesa</strong> (Listas) e podem ser editados lá.</li>
                <li>É independente do rateio: o centro de custo diz <em>quem consome</em>, o tipo diz <em>o que foi comprado</em>.</li>
              </ul>
            </div>
          </div>


          <!-- ── Processo sem nota fiscal ─────────────────────────── -->
          <div style="height:1px;background:#F0F0F0;margin:24px 0;"></div>

          <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
            <i data-lucide="file-x" width="16" height="16" style="color:#2347C8;"></i>
            <p style="font-size:14px;font-weight:700;color:#141922;margin:0;">Processo sem nota</p>
          </div>

          <div style="display:flex;align-items:center;gap:16px;background:#F8F8F8;border-radius:10px;padding:18px 20px;margin-bottom:20px;">
            <label class="toggle-slider-wrap"
                   style="position:relative;display:inline-flex;align-items:center;gap:16px;
                          cursor:<?= $somenteLeitura ? 'default' : 'pointer' ?>;">
              <input type="checkbox"
                     name="permite_sem_nota"
                     id="chk-sem-nota"
                     value="1"
                     <?= ($versaoAtual['permite_sem_nota'] ?? 0) ? 'checked' : '' ?>
                     <?= $somenteLeitura ? 'disabled' : '' ?>
                     style="display:none;"
                     onchange="toggleSemNotaPanel(this.checked)">
              <span class="toggle-slider" id="toggle-sem-nota"></span>
              <div>
                <div style="font-size:14px;font-weight:700;color:#141922;margin-bottom:3px;">
                  Permitir abertura sem nota fiscal
                </div>
                <div style="font-size:12px;color:#888888;">
                  Libera na Etapa 2 a opção "Processo sem nota fiscal" — para adiantamento, reembolso, coleta ou nota que ainda não chegou.
                </div>
              </div>
            </label>
          </div>

          <div id="sem-nota-info" style="display:<?= ($versaoAtual['permite_sem_nota'] ?? 0) ? 'block' : 'none' ?>;">
            <div style="background:#D8F5E5;border-left:4px solid #00A960;border-radius:4px;padding:14px 18px;">
              <div style="font-size:13px;font-weight:700;color:#00A960;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                <i data-lucide="check-circle" width="14" height="14"></i>
                Abertura sem nota habilitada
              </div>
              <ul style="font-size:12px;color:#606060;padding-left:18px;margin:0;line-height:1.8;">
                <li>A opção é do solicitante, processo a processo — quem tem nota continua informando normalmente.</li>
                <li>Marcada, <strong>Número da NF, Valor Total e Data da NF</strong> deixam de ser exigidos e ficam vazios no processo.</li>
                <li><strong>Emitido para</strong> continua obrigatório: é o CNPJ que define visibilidade por filial e os relatórios por loja.</li>
                <li>Processo sem nota não entra na checagem de nota duplicada.</li>
              </ul>
            </div>
          </div>

          <!-- ── Solicitação de acesso fora do horário ─────────────── -->
          <div style="height:1px;background:#F0F0F0;margin:24px 0;"></div>

          <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
            <i data-lucide="clock" width="16" height="16" style="color:#2347C8;"></i>
            <p style="font-size:14px;font-weight:700;color:#141922;margin:0;">Solicitação de acesso fora do horário</p>
          </div>

          <div style="display:flex;align-items:center;gap:16px;background:#F8F8F8;border-radius:10px;padding:18px 20px;margin-bottom:20px;">
            <label class="toggle-slider-wrap"
                   style="position:relative;display:inline-flex;align-items:center;gap:16px;
                          cursor:<?= $somenteLeitura ? 'default' : 'pointer' ?>;">
              <input type="checkbox"
                     name="libera_excecao_horario"
                     id="chk-excecao-horario"
                     value="1"
                     <?= ($versaoAtual['libera_excecao_horario'] ?? 0) ? 'checked' : '' ?>
                     <?= $somenteLeitura ? 'disabled' : '' ?>
                     style="display:none;"
                     onchange="var p=document.getElementById('excecao-horario-info'); if(p) p.style.display=this.checked?'block':'none';">
              <span class="toggle-slider" id="toggle-excecao-horario"></span>
              <div>
                <div style="font-size:14px;font-weight:700;color:#141922;margin-bottom:3px;">
                  Ao aprovar, liberar acesso fora do horário
                </div>
                <div style="font-size:12px;color:#888888;">
                  Este fluxo é o pedido de exceção de jornada. Aprovado no fim, a liberação é gravada
                  sozinha para quem abriu o processo — no dia e na faixa informados.
                </div>
              </div>
            </label>
          </div>

          <div id="excecao-horario-info" style="display:<?= ($versaoAtual['libera_excecao_horario'] ?? 0) ? 'block' : 'none' ?>;">
            <div style="background:#FFF3E0;border-left:4px solid #FF8A2D;border-radius:4px;padding:14px 18px;">
              <div style="font-size:13px;font-weight:700;color:#C75B00;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                <i data-lucide="alert-triangle" width="14" height="14"></i>
                O formulário precisa ter três campos com estes nomes exatos
              </div>
              <ul style="font-size:12px;color:#606060;padding-left:18px;margin:0;line-height:1.8;">
                <li><code>data_acesso</code> — tipo <strong>Data</strong></li>
                <li><code>hora_inicio</code> e <code>hora_fim</code> — tipo <strong>Seleção de lista</strong> (06:00, 06:30 … 23:30)</li>
                <li>A <strong>publicação é bloqueada</strong> se algum faltar: sem eles a aprovação passaria e a
                    liberação não seria gravada, e a pessoa só descobriria no dia, sem acesso.</li>
                <li>Marque também <strong>Permitir abertura sem nota fiscal</strong> — solicitação de acesso não tem NF.</li>
              </ul>
            </div>
          </div>

          <!-- ── Confidencialidade do fluxo ───────────────────────── -->
          <div style="height:1px;background:#F0F0F0;margin:24px 0;"></div>

          <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
            <i data-lucide="lock" width="16" height="16" style="color:#2347C8;"></i>
            <p style="font-size:14px;font-weight:700;color:#141922;margin:0;">Confidencialidade</p>
          </div>
          <p style="font-size:12px;color:#888888;margin:0 0 14px;">
            Todo processo já é restrito a quem participa do fluxo — criador, aprovadores e membros
            dos grupos aprovadores. Marque abaixo apenas quando nem a visão global puder alcançar
            este fluxo (folha, jurídico).
          </p>

          <?php if ($podeGerirSigilo): ?>
          <div style="display:flex;align-items:center;gap:16px;background:#F8F8F8;border-radius:10px;padding:18px 20px;margin-bottom:14px;">
            <label class="toggle-slider-wrap" style="position:relative;display:inline-flex;align-items:center;gap:16px;cursor:pointer;">
              <input type="checkbox" id="chk-confidencial" value="1" style="display:none;"
                     <?= !empty($template['confidencial']) ? 'checked' : '' ?>
                     onchange="tplSigiloMarcar(this.checked)">
              <span class="toggle-slider" id="toggle-confidencial"></span>
              <div>
                <div style="font-size:14px;font-weight:700;color:#141922;margin-bottom:3px;">
                  Fluxo confidencial
                </div>
                <div style="font-size:12px;color:#888888;">
                  Some das telas agregadas (Dashboard, Financeiro, Relatórios) e da inbox de capturas
                  — inclusive para quem tem visão global. Cada liberação fica registrada em auditoria.
                </div>
              </div>
            </label>
          </div>

          <div id="sigilo-detalhe" style="display:<?= !empty($template['confidencial']) ? 'block' : 'none' ?>;">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
              <select id="sigilo-grupo"
                      style="height:34px;padding:0 10px;border:1px solid #DFDFDF;border-radius:4px;font-size:13px;background:#fff;min-width:220px;">
                <option value="">Liberar um grupo…</option>
                <?php foreach ($grupos as $g): ?>
                <option value="<?= h($g['id']) ?>"><?= h($g['nome']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" onclick="tplSigiloConcederGrupo()"
                      style="height:34px;background:#2347C8;color:#fff;border:none;border-radius:4px;padding:0 14px;font-size:12px;font-weight:700;cursor:pointer;">
                Liberar grupo
              </button>
            </div>

            <?php if (!empty($sigiloLiberados['grupos']) || !empty($sigiloLiberados['usuarios'])): ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
              <?php foreach ($sigiloLiberados['grupos'] as $g): ?>
              <span style="background:#D8D4E4;color:#141922;font-size:12px;border-radius:8px;padding:4px 8px;">
                <i data-lucide="users" width="12" height="12" style="vertical-align:-2px"></i>
                <?= h($g['nome'] ?? '—') ?> (<?= (int)$g['membros'] ?>)
                <a href="#" onclick="tplSigiloRevogarGrupo('<?= h($g['grupo_id']) ?>');return false;"
                   style="color:#B3261E;text-decoration:none;font-weight:700;margin-left:4px;" title="Revogar">×</a>
              </span>
              <?php endforeach; ?>
              <?php foreach ($sigiloLiberados['usuarios'] as $u): ?>
              <span style="background:#EAEAEA;color:#606060;font-size:12px;border-radius:8px;padding:4px 8px;">
                <i data-lucide="user" width="12" height="12" style="vertical-align:-2px"></i> <?= h($u['nome'] ?? '—') ?>
              </span>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="font-size:12px;color:#888888;margin:0;">
              Nenhuma liberação além de quem participa do fluxo.
            </p>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <div style="background:#F8F8F8;border-radius:10px;padding:16px 20px;font-size:13px;color:#606060;">
            <?= !empty($template['confidencial'])
                ? 'Este fluxo está marcado como <strong>confidencial</strong>.'
                : 'Este fluxo não é confidencial.' ?>
            Só o super administrador altera esta configuração.
          </div>
          <?php endif; ?>

          <?php if (!$somenteLeitura): ?>
          <div style="margin-top:28px;padding-top:20px;border-top:1px solid #F0F0F0;display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary"
                    onclick="document.getElementById('aba-proxima-geral').value='configuracao'">
              <i data-lucide="save" width="15" height="15"></i>
              Salvar
            </button>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </form><!-- /form-editor -->
</div><!-- /template-editor -->
<?php endif; ?>

<!-- Modal: Confirmar mudança de status -->
<div id="modal-confirmar-status" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center">
  <div style="background:#fff; border-radius:12px; width:100%; max-width:420px; margin:20px; box-shadow:0 8px 32px rgba(0,0,0,0.2)">
    <div style="padding:24px 24px 0; text-align:center;">
      <div id="modal-status-icone" style="width:48px; height:48px; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px">
        <i data-lucide="arrow-right-circle" width="24" height="24"></i>
      </div>
      <h3 id="modal-status-titulo" style="font-size:18px; font-weight:700; color:#141922; margin:0 0 8px"></h3>
      <p id="modal-status-texto" style="font-size:14px; color:#606060; margin:0"></p>
    </div>
    <div style="padding:20px 24px; display:flex; gap:8px; justify-content:center">
      <button type="button" id="modal-status-cancelar"
              style="flex:1; padding:10px 20px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">
        Cancelar
      </button>
      <button type="button" id="modal-status-confirmar"
              style="flex:1; padding:10px 20px; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">
        Confirmar
      </button>
    </div>
  </div>
</div>

<!-- Dados para o JS (injetados em data-* para evitar XSS) -->
<div id="editor-data"
     data-template-id="<?= h($templateId) ?>"
     data-versao-id="<?= h($versaoId) ?>"
     data-readonly="<?= $somenteLeitura ? '1' : '0' ?>"
     data-etapa-financeiro-nome="<?= h(Template::ETAPA_FINANCEIRO_NOME) ?>"
     data-etapa-assinatura-nome="<?= h(Template::ETAPA_ASSINATURA_NOME) ?>"
     data-etapa-clicksign-nome="<?= h(Template::ETAPA_CLICKSIGN_NOME) ?>"
     data-lista-tipos-assinatura="<?= h(Template::LISTA_TIPOS_ASSINATURA_ID) ?>"
     data-assinatura-provedor="<?= h($assinaturaCfg['provedor'] ?? 'assinafy') ?>"
     data-anexo-assinatura-nome="<?= h(Template::ANEXO_ASSINATURA_NOME) ?>"
     data-assinatura-signatarios="<?= h(json_encode($assinaturaCfg['signatarios'] ?? [], JSON_UNESCAPED_UNICODE)) ?>"
     data-assinatura-naturezas="<?= h(json_encode(Template::NATUREZAS, JSON_UNESCAPED_UNICODE)) ?>"
     data-assinatura-max="<?= (int) Template::MAX_SIGNATARIOS ?>"
     data-etapas="<?= h(json_encode($etapas, JSON_UNESCAPED_UNICODE)) ?>"
     data-campos="<?= h(json_encode($campos, JSON_UNESCAPED_UNICODE)) ?>"
     data-tipos-anexo="<?= h(json_encode($tiposAnexo, JSON_UNESCAPED_UNICODE)) ?>"
     data-etapas-catalogo="<?= h(json_encode(array_map(fn($e) => [
         'id'        => $e['id'],
         'nome'      => $e['nome'],
         'descricao' => $e['descricao'],
         'encerra'   => (int) $e['encerra_automaticamente'],
         'sla_horas' => $e['sla_horas'] !== null ? (float) $e['sla_horas'] : null,
         'tipo'      => $e['tipo_aprovador'],
         'restrita'  => (int) $e['visualizacao_restrita'],
         // Etapa de decisão: aponta para a lista que abre os caminhos. Sem isto
         // o editor não tem como saber que a linha ramifica, e a desenha como
         // etapa comum (com Usuário/Grupo, que a decisão não usa).
         'decisao_lista_id' => $e['decisao_lista_id'] ?? null,
         // "Ir para": salta para a etapa que ESTE template indicar.
         'salto'            => (int) ($e['salto_incondicional'] ?? 0),
         // "Abrir Fluxo": abre processo no template que ESTE template indicar.
         'abre_fluxo'       => (int) ($e['abre_fluxo'] ?? 0),
         // Etapa de assinatura: ao escolhê-la o editor liga a trava e monta os
         // campos do provedor dela (Assinafy ou Clicksign).
         'assinatura'       => (int) ($e['assinatura_externa'] ?? 0),
         'provedor'         => Template::provedorValido($e['assinatura_provedor'] ?? 'assinafy'),
     ], $etapasCatalogo ?? []), JSON_UNESCAPED_UNICODE)) ?>"
     <?php
     // Templates que a etapa "Abrir Fluxo" pode disparar. Só os que têm versão
     // publicada — abrir processo de template sem versão publicada travaria na
     // hora, e o erro apareceria lá no runtime em vez de aqui.
     $templatesDestino = [];
     if (!empty(array_filter($etapasCatalogo ?? [], fn($e) => !empty($e['abre_fluxo'])))) {
         $stmtTpl = Database::getInstance()->prepare(
             "SELECT t.id, t.nome
                FROM nf_templates t
                JOIN nf_template_versoes v ON v.id = t.versao_publicada_id
               WHERE t.id <> ?
               ORDER BY t.nome"
         );
         $stmtTpl->execute([$templateId]);
         $templatesDestino = $stmtTpl->fetchAll(PDO::FETCH_ASSOC);
     }
     ?>
     data-templates-destino="<?= h(json_encode($templatesDestino, JSON_UNESCAPED_UNICODE)) ?>"
     <?php
     // Índices de cada lista que governa alguma etapa de decisão do catálogo —
     // é o que diz ao editor QUANTOS destinos pedir e com que rótulo ("1 → PF").
     $listasIndices = [];
     foreach ($etapasCatalogo ?? [] as $e) {
         $lid = $e['decisao_lista_id'] ?? null;
         if ($lid && !isset($listasIndices[$lid])) {
             $listasIndices[$lid] = TableList::indicesDaLista((string) $lid);
         }
     }
     ?>
     data-listas-indices="<?= h(json_encode($listasIndices, JSON_UNESCAPED_UNICODE)) ?>"
     data-grupos="<?= h(json_encode(array_map(fn($g) => ['id' => $g['id'], 'nome' => $g['nome']], $grupos), JSON_UNESCAPED_UNICODE)) ?>"
     data-todos-usuarios="<?= h(json_encode(array_map(fn($u) => ['id' => $u['id'], 'nome' => $u['nome']], $todosUsuarios ?? []), JSON_UNESCAPED_UNICODE)) ?>"
     data-tipos-campo="<?= h(json_encode($tiposCampo, JSON_UNESCAPED_UNICODE)) ?>"
     data-fontes-lista="<?= h(json_encode(array_merge(
         [['id' => '_filiais', 'nome' => 'Filiais (lojas)'], ['id' => '_setores', 'nome' => 'Departamentos']],
         array_map(fn($l) => ['id' => $l['id'], 'nome' => $l['nome']],
             (function() { $pdo = Database::getInstance(); $s = $pdo->query('SELECT id, nome FROM nf_listas ORDER BY nome'); return $s->fetchAll(PDO::FETCH_ASSOC); })()
         )
     ), JSON_UNESCAPED_UNICODE)) ?>"
     style="display:none;">
</div>

<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">

<script>
// Confirmar antes de avançar/regredir status
(function () {
  var btns = document.querySelectorAll('.btn-avancar-status');
  if (!btns.length) return;

  var pendingForm = null;

  var msgs = {
    'publicado':      { titulo: 'Publicar template',          texto: 'Ele ficará disponível para novos processos.', cor: '#00A960' },
    'rascunho':       { titulo: 'Voltar para rascunho',       texto: 'O template ficará editável novamente e sairá do modo de teste.', cor: '#606060' },
    'teste_interno':  { titulo: 'Voltar para Teste Interno',  texto: 'O teste com usuários será encerrado.', cor: '#2347C8' },
    'uat':            { titulo: 'Abrir para Usuários',        texto: 'Usuários selecionados poderão criar processos de teste.', cor: '#FF8A2D' },
  };

  btns.forEach(function (btn) {
    if (btn.dataset.novoStatus === 'uat') return; // UAT tem painel próprio
    btn.addEventListener('click', function () {
      var novoStatus = this.dataset.novoStatus;
      var info = msgs[novoStatus] || { titulo: 'Confirmar', texto: 'Deseja prosseguir?', cor: '#2347C8' };
      pendingForm = this.closest('form');

      document.getElementById('modal-status-titulo').textContent = info.titulo;
      document.getElementById('modal-status-texto').textContent  = info.texto;
      document.getElementById('modal-status-icone').style.background = info.cor === '#606060' ? '#F6F6F6' : (info.cor + '20');
      document.getElementById('modal-status-icone').style.color = info.cor;
      var btnConfirm = document.getElementById('modal-status-confirmar');
      btnConfirm.style.background = info.cor;
      btnConfirm.textContent = info.titulo;

      document.getElementById('modal-confirmar-status').style.display = 'flex';
    });
  });

  document.getElementById('modal-status-confirmar').addEventListener('click', function () {
    if (pendingForm) pendingForm.submit();
  });
  document.getElementById('modal-status-cancelar').addEventListener('click', function () {
    document.getElementById('modal-confirmar-status').style.display = 'none';
  });
  // Modal fecha apenas pelos botões (sem clique-fora).
}());

function toggleFinanceiroPanel(checked) {
  document.getElementById('financeiro-info').style.display = checked ? 'block' : 'none';
}

function toggleRateioPanel(checked) {
  document.getElementById('rateio-info').style.display = checked ? 'block' : 'none';
}

function toggleTipoDespesaPanel(checked) {
  document.getElementById('tipo-despesa-info').style.display = checked ? 'block' : 'none';
}

function toggleSemNotaPanel(checked) {
  document.getElementById('sem-nota-info').style.display = checked ? 'block' : 'none';
}

// Counter do nome (aba Geral)
(function () {
  var input   = document.getElementById('g-nome');
  var counter = document.getElementById('g-nome-counter');
  if (!input || !counter) return;

  function updateCounter() {
    var len = input.value.length;
    counter.textContent = len + '/80';
    counter.style.color = len > 70 ? '#FF8A2D' : '#C8C8C8';
  }
  updateCounter();

  input.addEventListener('input', updateCounter);
  input.addEventListener('focus', function () {
    if (!this.readOnly) {
      this.style.borderColor = '#2347C8';
      this.style.boxShadow   = '0 0 0 3px rgba(35,71,200,0.08)';
    }
  });
  input.addEventListener('blur', function () {
    this.style.borderColor = '#E0E0E0';
    this.style.boxShadow   = 'none';
  });
}());
</script>

<script>
// ═══ Confidencialidade do fluxo (fora do form: é acesso, não conteúdo versionado) ═══
async function tplSigiloPost(campos) {
  var fd = new FormData();
  fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
  fd.append('template_id', '<?= h($templateId) ?>');
  Object.keys(campos).forEach(function (k) { fd.append(k, campos[k]); });
  try {
    var r = await fetch('?module=templates&action=sigilo', {
      method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    var d = await r.json();
    alert(d.msg || (d.ok ? 'Feito.' : 'Falha na operação.'));
    return !!d.ok;
  } catch (e) {
    alert('Erro: ' + e.message);
    return false;
  }
}

async function tplSigiloMarcar(marcado) {
  var ok = await tplSigiloPost({ op: 'marcar', valor: marcado ? '1' : '0' });
  if (ok) {
    document.getElementById('sigilo-detalhe').style.display = marcado ? 'block' : 'none';
  } else {
    document.getElementById('chk-confidencial').checked = !marcado;
  }
}

async function tplSigiloConcederGrupo() {
  var sel = document.getElementById('sigilo-grupo');
  if (!sel || !sel.value) { alert('Selecione um grupo.'); return; }
  var motivo = prompt('Motivo da liberação (opcional):') || '';
  if (await tplSigiloPost({ op: 'conceder_grupo', grupo_id: sel.value, motivo: motivo })) location.reload();
}

async function tplSigiloRevogarGrupo(grupoId) {
  if (!confirm('Revogar a liberação deste grupo?')) return;
  if (await tplSigiloPost({ op: 'revogar_grupo', grupo_id: grupoId })) location.reload();
}
</script>
