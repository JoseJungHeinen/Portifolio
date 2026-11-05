<?php
// @versao Ace_01 V_13_01/10/2026
/**
 * views/acessos/index.php
 * Controle de Acesso — permissões granulares por usuário
 *
 * Variáveis esperadas:
 *   $usuarios  array   lista de usuários (UserPermission::listarUsuarios)
 *   $catalogo  array   catálogo de módulos/ações (UserPermission::catalogo)
 *   $stats     array   estatísticas de acesso (AccessStats::painel)
 *   $busca     string
 *   $flash     array|null
 */

$usuarios = $usuarios ?? [];
$catalogo = $catalogo ?? [];
$stats    = $stats    ?? [];
$filiais  = $filiais  ?? [];
$setores  = $setores  ?? [];
$busca    = $busca    ?? '';
$horarioPorUsuario  = $horarioPorUsuario  ?? [];
$horariosDisponiveis = $horariosDisponiveis ?? [];

$perfisDisponiveis = [
    'solicitante' => 'Solicitante',
    'almoxarife'  => 'Almoxarife',
    'comprador'   => 'Comprador',
    'gestor'      => 'Gestor',
    'diretor'     => 'Diretor',
    'admin'       => 'Administrador (cadastros)',
];
// Marca reservada: só aparece no select — e no painel de perfis — para quem já
// é super admin. Para os demais, o super admin se apresenta como 'admin'.
if (Permissions::isSuperAdmin()) {
    $perfisDisponiveis['super_admin'] = 'Super Administrador';
}

// Perfil e permissões são território do administrador (admin e super admin);
// o backend confirma o mesmo gate em todas as ações de gravação.
$podeGerirPermissoes = Permissions::isAdminCadastros();
?>
<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">
<script>var acsPodeGerirPerms = <?= $podeGerirPermissoes ? 'true' : 'false' ?>;</script>
<?php if ($podeGerirPermissoes && !Permissions::isSuperAdmin()): ?>
<div style="background:#FFF3E0;border-left:4px solid #FF8A2D;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#141922">
  <i data-lucide="info" width="15" height="15" style="vertical-align:-3px;color:#FF8A2D"></i>
  Você administra <strong>cadastros, perfis e permissões</strong> do módulo.
  O perfil <strong>Super Administrador</strong> é reservado: não pode ser concedido e o acesso de quem já o tem só é alterado por outro super administrador.
</div>
<?php endif; ?>

<?php include VIEWS . '/components/alert.php'; ?>

<!-- ── Cabeçalho ─────────────────────────────────────────────── -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--space-4);margin-bottom:24px;">
  <div>
    <h1 style="font-size:var(--text-h1);font-weight:700;color:var(--color-dark);margin:0 0 4px;">
      Controle de <span style="color:var(--color-primary);">Acesso</span>
    </h1>
    <p style="font-size:var(--text-body);color:var(--color-gray-medium);margin:0;">
      Configure quais telas e ações cada usuário pode acessar dentro do módulo de Notas Fiscais.
    </p>
  </div>
  <div style="display:flex;gap:8px;flex-shrink:0;">
    <?php // Jornada mora aqui porque quem cadastra a pessoa é quem define o
          // horário dela. A aba do Geral continua existindo, mas é super_admin
          // — este link é o caminho de quem administra cadastros. ?>
    <a href="?module=horarios&amp;action=index" class="btn btn-secondary"
       style="text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
      <i data-lucide="clock" width="16" height="16"></i>
      Horários de Acesso
    </a>
    <button type="button" class="btn btn-primary" onclick="acsAbrirModalNovo()">
      <i data-lucide="user-plus" width="16" height="16"></i>
      Novo usuário
    </button>
  </div>
</div>

<div class="acs-layout">

  <!-- ── Painel esquerdo: perfis + lista de usuários ────────── -->
  <div class="acs-users">
    <?php if ($podeGerirPermissoes): ?>
    <div class="acs-users__header" style="display:flex;align-items:center;justify-content:space-between;gap:8px">
      <span class="acs-users__title"><i data-lucide="id-card" width="16" height="16"></i> Perfis (padrão)</span>
      <button type="button" id="acs-perfis-toggle" onclick="acsTogglePerfis()" title="Minimizar / expandir perfis"
              style="width:28px;height:28px;border:1px solid var(--color-gray-light);border-radius:6px;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#606060;transition:transform .15s;flex-shrink:0">
        <i data-lucide="chevron-down" width="15" height="15"></i>
      </button>
    </div>
    <div id="acs-perfis-list" style="border-bottom:1px solid var(--color-gray-light);display:none">
      <?php foreach ($perfisDisponiveis as $valor => $label): ?>
      <div class="acs-user-item" id="acs-perfil-<?= h($valor) ?>" onclick="acsSelecionarPerfil('<?= h($valor) ?>')">
        <div class="acs-user-item__avatar" style="background:#D8D4E4;color:#606060">
          <i data-lucide="shield" width="14" height="14"></i>
        </div>
        <div class="acs-user-item__meta">
          <div class="acs-user-item__nome"><?= h($label) ?></div>
          <div class="acs-user-item__email" style="font-size:11px">
            <?php if ($valor === 'super_admin'): ?>acesso completo (fixo)
            <?php elseif ($valor === 'admin'): ?>só cadastros: funcionários, grupos, templates
            <?php else: ?>permissões padrão do perfil<?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="acs-users__header">
      <span class="acs-users__title"><i data-lucide="users" width="16" height="16"></i> Usuários</span>
      <span class="acs-users__count" id="acs-users-count"><?= count($usuarios) ?></span>
    </div>
    <div class="acs-users__search">
      <i data-lucide="search" width="14" height="14"></i>
      <input type="text" id="acs-busca" placeholder="Buscar usuário..." oninput="acsFiltrar(this.value)">
    </div>
    <div class="acs-users__list" id="acs-users-list">
      <?php foreach ($usuarios as $u):
          $nome  = $u['nome'] ?? '';
          $partes   = preg_split('/\s+/', trim($nome));
          $iniciais = mb_strtoupper(mb_substr($nome, 0, 1));
          if (count($partes) > 1) { $iniciais .= mb_strtoupper(mb_substr(end($partes), 0, 1)); }
      ?>
      <div class="acs-user-item"
           id="acs-user-<?= h($u['id']) ?>"
           data-nome="<?= h(mb_strtolower($nome)) ?>"
           onclick="acsSelecionar('<?= h($u['id']) ?>')">
        <div class="acs-user-item__avatar"><?= h($iniciais) ?></div>
        <div class="acs-user-item__meta">
          <div class="acs-user-item__nome"><?= h($nome) ?></div>
          <div class="acs-user-item__email"><?= h($u['email'] ?? '') ?></div>
          <div class="acs-user-item__badges">
            <span class="badge badge--muted"><?= h(ucfirst((string)($u['perfil'] ?? ''))) ?></span>
            <?php if (empty($u['ativo'])): ?>
            <span class="badge badge--danger" style="background:#FFE8EE;color:#B3261E">Inativo</span>
            <?php endif; ?>
            <?php if (!empty($u['tem_override'])): ?>
            <span class="badge badge--primary">Personalizado</span>
            <?php endif; ?>
            <?php
            // Sem horário = sem limite, que é o padrão — não vira badge, senão
            // a lista inteira ficaria carimbada sem informar nada.
            $hUser = $horarioPorUsuario[$u['id']] ?? null;
            if ($hUser):
            ?>
            <span class="badge" title="Horário de acesso: <?= h($hUser['nome']) ?><?= (int)$hUser['ativo'] === 1 ? '' : ' (horário inativo — não bloqueia)' ?>"
                  style="background:#FFF3E0;color:#FF8A2D;display:inline-flex;align-items:center;gap:4px">
              <i data-lucide="clock" width="10" height="10"></i><?= h($hUser['nome']) ?>
            </span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($usuarios)): ?>
      <div class="acs-users__empty">Nenhum usuário encontrado.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Painel direito: estatísticas até escolher um usuário ── -->
  <div class="acs-detail" id="acs-detail">
    <?php if (!empty($stats)): ?>
    <?php include VIEWS . '/acessos/_estatisticas.php'; ?>
    <?php else: ?>
    <div class="acs-empty">
      <i data-lucide="shield" width="40" height="40"></i>
      <p>Selecione um usuário para configurar as permissões</p>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- Template do painel de detalhe (clonado via JS) -->
<template id="acs-tpl-detalhe">
  <div class="acs-detail__header">
    <div>
      <div class="acs-detail__nome" id="acs-d-nome"></div>
      <div class="acs-detail__subtitle" id="acs-d-subtitle"></div>
    </div>
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; justify-content:flex-end">
      <label style="display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:700; color:var(--color-gray-dark); cursor:pointer">
        <input type="checkbox" id="acs-d-vendedor-veiculos" onchange="acsSalvarVendedorVeiculos(this)"> Vendedor Veículos
      </label>
      <span class="badge" id="acs-d-origem-badge"></span>
    </div>
  </div>

  <?php if ($podeGerirPermissoes): ?>
  <div style="display:flex; justify-content:flex-end; margin-bottom:var(--space-3)">
    <button type="button" class="btn btn-primary" onclick="acsSalvar()">
      <i data-lucide="save" width="14" height="14"></i>
      Salvar permissões
    </button>
  </div>
  <?php endif; ?>

  <div id="acs-msg-erro" class="alert alert-danger" style="display:none"></div>
  <div id="acs-msg-ok" class="alert alert-success" style="display:none"></div>

  <div id="acs-grupos-aprovacao" style="margin-bottom:18px"></div>

  <div class="acs-groups" id="acs-groups"></div>

  <div class="acs-detail__footer">
    <div style="display:flex;gap:var(--space-2)">
      <button type="button" class="btn btn-tertiary" onclick="acsAbrirModalEditar()">
        <i data-lucide="user-pen" width="14" height="14"></i>
        Editar cadastro
      </button>
      <button type="button" class="btn btn-tertiary" onclick="acsAbrirModalSenha()">
        <i data-lucide="key" width="14" height="14"></i>
        Trocar senha
      </button>
      <button type="button" class="btn btn-tertiary" id="acs-btn-reverter" onclick="acsReverterPadrao()" style="display:none">
        <i data-lucide="rotate-ccw" width="14" height="14"></i>
        Reverter para o padrão do perfil
      </button>
    </div>
    <?php if ($podeGerirPermissoes): ?>
    <button type="button" class="btn btn-primary" onclick="acsSalvar()">
      <i data-lucide="save" width="14" height="14"></i>
      Salvar permissões
    </button>
    <?php endif; ?>
  </div>
</template>

<!-- Template do card "Templates liberados individualmente" — inserido via JS
     logo abaixo do card de permissões "Processos", já que é a 3ª camada do
     mesmo controle de acesso (participação → ver_todos → liberação pontual). -->
<template id="acs-tpl-templates-liberados">
  <div class="acs-group acs-group--liberacao">
    <div class="acs-group__header" style="cursor:default">
      <span class="acs-group__title"><i data-lucide="folder-key" width="16" height="16"></i> Liberação individual de templates</span>
    </div>
    <div class="acs-group__body" style="padding:var(--space-3) var(--space-4) var(--space-4)">
      <p class="acs-liberacao__intro">
        Além dos processos que participa (criador ou aprovador) e do acesso global acima, este usuário pode ver
        <strong>todos os processos de um template específico</strong> — passados e futuros — sem precisar liberar um por um.
      </p>
      <div style="position:relative;margin-bottom:10px">
        <input type="text" id="acs-tpl-busca" placeholder="Buscar template por nome..." autocomplete="off"
               class="acs-liberacao__input"
               oninput="acsBuscarTemplateDebounced(this.value)">
        <div id="acs-tpl-resultados" class="acs-liberacao__resultados"></div>
      </div>
      <div id="acs-tpl-lista"></div>
    </div>
  </div>
</template>

<!-- Template do card "Filiais visíveis" — inserido via JS no painel do usuário. -->
<template id="acs-tpl-filiais">
  <div class="acs-group acs-group--liberacao">
    <div class="acs-group__header" style="cursor:default">
      <span class="acs-group__title"><i data-lucide="building-2" width="16" height="16"></i> Filiais visíveis (CNPJ de destino)</span>
    </div>
    <div class="acs-group__body" style="padding:var(--space-3) var(--space-4) var(--space-4)">
      <p class="acs-liberacao__intro">
        Sem nenhuma filial marcada, o usuário enxerga <strong>todas</strong> (comportamento padrão).
        Marcando uma ou mais, ele passa a ver apenas as notas capturadas, processos, parcelas e relatórios
        dessas filiais.
      </p>
      <div id="acs-filiais-lista" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:6px;margin-bottom:12px">
        <?php foreach ($filiais as $f): ?>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#141922;cursor:pointer">
          <input type="checkbox" class="acs-filial-chk" value="<?= h($f['id']) ?>" style="accent-color:#2347C8">
          <?= h($f['nome']) ?>
        </label>
        <?php endforeach; ?>
      </div>
      <button type="button" onclick="acsSalvarFiliais()"
              style="background:#2347C8;color:#fff;border:none;border-radius:4px;padding:8px 18px;font-size:13px;font-weight:700;cursor:pointer">
        Salvar filiais
      </button>
    </div>
  </div>
</template>

<!-- ── Modal: Novo Usuário ─────────────────────────────────── -->
<!-- Modal de sucesso (verde) — Salvar permissões. Estilo próprio, independente das classes .modal -->
<div id="acs-modal-sucesso" onclick="if (event.target === this) acsFecharSucesso()"
     style="display:none; position:fixed; inset:0; z-index:1000; background:rgba(24,19,31,0.55); align-items:center; justify-content:center; padding:20px">
  <div role="alertdialog" aria-live="polite" aria-labelledby="acs-sucesso-titulo"
       style="background:var(--color-success); color:#fff; border-radius:12px; width:100%; max-width:380px; padding:36px 28px; text-align:center; box-shadow:rgba(0,0,0,0.25) 0 12px 32px">
    <div style="width:64px; height:64px; margin:0 auto 16px; border-radius:50%; background:rgba(255,255,255,0.22); display:flex; align-items:center; justify-content:center">
      <i data-lucide="check" width="36" height="36" style="stroke:#fff; stroke-width:3"></i>
    </div>
    <div id="acs-sucesso-titulo" style="font-size:20px; font-weight:700; line-height:1.3; color:#fff">Alteração salva com sucesso.</div>
  </div>
</div>

<div class="modal-overlay" id="acs-modal-novo">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title">Novo usuário</h3>
      <button type="button" class="modal-close" onclick="acsFecharModalNovo()">
        <i data-lucide="x" width="18" height="18"></i>
      </button>
    </div>

    <div class="modal-body" id="acs-novo-form-wrap">
      <div id="acs-novo-erro" class="alert alert-danger" style="display:none"></div>

      <div class="form-group">
        <label for="acs-novo-nome" class="form-label">Nome completo</label>
        <input type="text" id="acs-novo-nome" class="form-input" maxlength="100">
      </div>
      <div class="form-group">
        <label for="acs-novo-email" class="form-label">E-mail</label>
        <input type="email" id="acs-novo-email" class="form-input">
      </div>
      <div class="form-group">
        <label for="acs-novo-perfil" class="form-label">Perfil</label>
        <select id="acs-novo-perfil" class="form-select" <?= $podeGerirPermissoes ? '' : 'disabled' ?>>
          <?php foreach ($perfisDisponiveis as $valor => $label): ?>
          <option value="<?= h($valor) ?>"><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="acs-novo-filial" class="form-label">Loja</label>
        <select id="acs-novo-filial" class="form-select">
          <?php foreach ($filiais as $f): ?>
          <option value="<?= h($f['id']) ?>"><?= h($f['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="acs-novo-setor" class="form-label">Departamento</label>
        <select id="acs-novo-setor" class="form-select">
          <?php foreach ($setores as $s): ?>
          <option value="<?= h($s['id']) ?>"><?= h($s['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label style="display:inline-flex; align-items:center; gap:8px; font-size:14px; cursor:pointer">
          <input type="checkbox" id="acs-novo-vendedor-veiculos"> Vendedor Veículos
        </label>
        <small style="display:block; color:var(--color-gray-medium)">Se marcado, aparece como opção de Vendedor no pedido de Faturamento.</small>
      </div>
    </div>

    <!-- Estado de sucesso — senha temporária exibida uma única vez -->
    <div class="modal-body" id="acs-novo-sucesso" style="display:none">
      <div class="alert alert-success">
        Usuário criado com sucesso. Informe a senha temporária abaixo — ela não será exibida novamente.
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Senha temporária</label>
        <div style="display:flex;gap:var(--space-2)">
          <input type="text" id="acs-novo-senha-gerada" class="form-input" readonly style="font-family:Consolas,monospace;font-weight:700;letter-spacing:.05em">
          <button type="button" class="btn btn-tertiary" onclick="acsCopiarSenhaGerada()">
            <i data-lucide="copy" width="14" height="14"></i>
          </button>
        </div>
        <small style="color:var(--color-gray-medium)">O usuário será obrigado a trocá-la no primeiro acesso.</small>
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn btn-tertiary" onclick="acsFecharModalNovo()" id="acs-novo-btn-cancelar">Cancelar</button>
      <button type="button" class="btn btn-primary" onclick="acsSalvarNovoUsuario()" id="acs-novo-btn-salvar">
        <i data-lucide="user-plus" width="14" height="14"></i>
        Criar usuário
      </button>
    </div>
  </div>
</div>

<!-- ── Modal: Editar Cadastro (admin — correção de dados) ──── -->
<div class="modal-overlay" id="acs-modal-editar">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title">Editar cadastro</h3>
      <button type="button" class="modal-close" onclick="acsFecharModalEditar()">
        <i data-lucide="x" width="18" height="18"></i>
      </button>
    </div>

    <div class="modal-body">
      <div id="acs-edit-erro" class="alert alert-danger" style="display:none"></div>

      <div class="form-group">
        <label for="acs-edit-nome" class="form-label">Nome completo</label>
        <input type="text" id="acs-edit-nome" class="form-input" maxlength="100">
      </div>
      <div class="form-group">
        <label for="acs-edit-email" class="form-label">E-mail</label>
        <input type="email" id="acs-edit-email" class="form-input">
      </div>
      <div class="form-group">
        <label for="acs-edit-perfil" class="form-label">Perfil</label>
        <select id="acs-edit-perfil" class="form-select" <?= $podeGerirPermissoes ? '' : 'disabled' ?>>
          <?php foreach ($perfisDisponiveis as $valor => $label): ?>
          <option value="<?= h($valor) ?>"><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="acs-edit-filial" class="form-label">Loja</label>
        <select id="acs-edit-filial" class="form-select">
          <?php foreach ($filiais as $f): ?>
          <option value="<?= h($f['id']) ?>"><?= h($f['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="acs-edit-setor" class="form-label">Departamento</label>
        <select id="acs-edit-setor" class="form-select">
          <?php foreach ($setores as $s): ?>
          <option value="<?= h($s['id']) ?>"><?= h($s['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="acs-edit-horario" class="form-label">Horário de acesso</label>
        <select id="acs-edit-horario" class="form-select">
          <option value="">Sem limite — entra a qualquer hora</option>
          <?php foreach ($horariosDisponiveis as $hd): ?>
          <option value="<?= h($hd['id']) ?>"><?= h($hd['nome']) ?><?= (int)$hd['ativo'] === 1 ? '' : ' (inativo)' ?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--color-gray-medium);margin-top:4px">
          Fora da jornada o acesso é bloqueado. Exceções pontuais são pedidas pelo próprio funcionário e passam pelo RH.
        </div>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" id="acs-edit-ativo" style="accent-color:var(--color-primary);width:16px;height:16px">
          Usuário ativo (desmarcado = sem acesso ao sistema)
        </label>
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn btn-tertiary" onclick="acsFecharModalEditar()">Cancelar</button>
      <button type="button" class="btn btn-primary" onclick="acsSalvarEdicao()" id="acs-edit-btn-salvar">
        <i data-lucide="save" width="14" height="14"></i>
        Salvar cadastro
      </button>
    </div>
  </div>
</div>

<!-- ── Modal: Trocar Senha ─────────────────────────────────── -->
<div class="modal-overlay" id="acs-modal-senha">
  <div class="modal modal-sm">
    <div class="modal-header">
      <h3 class="modal-title">Trocar senha</h3>
      <button type="button" class="modal-close" onclick="acsFecharModalSenha()">
        <i data-lucide="x" width="18" height="18"></i>
      </button>
    </div>
    <div class="modal-body" id="acs-senha-form-wrap">
      <p id="acs-senha-nome" style="font-size:var(--text-body-sm);color:var(--color-gray-medium);margin-bottom:var(--space-4)"></p>
      <div id="acs-senha-erro" class="alert alert-danger" style="display:none"></div>
      <div class="form-group">
        <label for="acs-senha-nova" class="form-label">Nova senha</label>
        <input type="password" id="acs-senha-nova" class="form-input" minlength="6" placeholder="Mínimo 6 caracteres">
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label for="acs-senha-confirma" class="form-label">Confirmar nova senha</label>
        <input type="password" id="acs-senha-confirma" class="form-input" minlength="6">
      </div>
    </div>

    <!-- Estado de sucesso — exibido dentro do modal para não passar despercebido -->
    <div class="modal-body" id="acs-senha-sucesso" style="display:none">
      <div class="alert alert-success">
        <span class="alert-icon"><i data-lucide="check-circle" width="18" height="18"></i></span>
        <div class="alert-content"><div id="acs-senha-sucesso-msg"></div></div>
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn btn-tertiary" onclick="acsFecharModalSenha()" id="acs-senha-btn-cancelar">Cancelar</button>
      <button type="button" class="btn btn-primary" onclick="acsSalvarSenha()" id="acs-senha-btn-salvar">
        <i data-lucide="key" width="14" height="14"></i>
        Trocar senha
      </button>
    </div>
  </div>
</div>

<script>
const acsCatalogo = <?= json_encode($catalogo, JSON_UNESCAPED_UNICODE) ?>;
const acsCsrf      = document.querySelector('meta[name="csrf-token"]').content;
let acsUsuarioAtivo = null;
let acsUsuarioDados = null; // último usuário carregado (para pré-preencher a edição)

function acsFiltrar(q) {
  q = q.toLowerCase().trim();
  let visiveis = 0;
  document.querySelectorAll('.acs-user-item').forEach(function (el) {
    const show = !q || (el.dataset.nome || '').indexOf(q) !== -1;
    el.style.display = show ? '' : 'none';
    if (show) visiveis++;
  });
  document.getElementById('acs-users-count').textContent = visiveis;
}

async function acsSelecionar(id) {
  document.querySelectorAll('.acs-user-item').forEach(function (el) { el.classList.remove('is-active'); });
  var el = document.getElementById('acs-user-' + id);
  if (el) el.classList.add('is-active');
  acsUsuarioAtivo = id;
  acsPerfilAtivo  = null;

  var painel = document.getElementById('acs-detail');
  painel.innerHTML = '<div class="acs-empty"><i data-lucide="loader" width="28" height="28"></i><p>Carregando...</p></div>';
  if (window.lucide) lucide.createIcons();

  try {
    var r = await fetch('?module=acessos&action=usuario_dados&id=' + encodeURIComponent(id), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    var d = await r.json();
    if (!d.ok) {
      painel.innerHTML = '<div class="acs-empty"><p style="color:var(--color-danger)">' + d.msg + '</p></div>';
      return;
    }

    var tpl = document.getElementById('acs-tpl-detalhe');
    painel.innerHTML = '';
    painel.appendChild(tpl.content.cloneNode(true));

    acsUsuarioDados = d.usuario;
    document.getElementById('acs-d-nome').textContent = d.usuario.nome;
    document.getElementById('acs-d-subtitle').textContent = d.usuario.email + ' · ' + d.usuario.perfil
      + (parseInt(d.usuario.ativo, 10) ? '' : ' · INATIVO');

    var chkVend = document.getElementById('acs-d-vendedor-veiculos');
    chkVend.checked  = parseInt(d.usuario.vendedor_veiculos, 10) === 1;
    chkVend.disabled = !acsPodeGerirPerms;

    var origemBadge = document.getElementById('acs-d-origem-badge');
    if (d.tem_override) {
      origemBadge.textContent = 'Personalizado';
      origemBadge.className   = 'badge badge--primary';
      document.getElementById('acs-btn-reverter').style.display = acsPodeGerirPerms ? 'inline-flex' : 'none';
    } else {
      origemBadge.textContent = 'Segue o perfil (' + d.usuario.perfil + ')';
      origemBadge.className   = 'badge badge--muted';
      document.getElementById('acs-btn-reverter').style.display = 'none';
    }

    acsMontarGrupos(d.permissoes);
    acsMontarGruposAprovacao(d.grupos);
    acsMontarTemplatesLiberados(d.templates_liberados);
    acsMarcarFiliais(d.filiais_usuario);
    if (window.lucide) lucide.createIcons();
  } catch (e) {
    painel.innerHTML = '<div class="acs-empty"><p style="color:var(--color-danger)">Erro ao carregar: ' + e.message + '</p></div>';
  }
}

function acsEsc(s) {
  var d = document.createElement('div');
  d.textContent = (s === null || s === undefined) ? '' : String(s);
  return d.innerHTML;
}

// Grupos de aprovação de que o usuário participa (somente leitura, com link)
function acsMontarGruposAprovacao(grupos) {
  var box = document.getElementById('acs-grupos-aprovacao');
  if (!box) return;
  grupos = grupos || [];

  var titulo = '<div style="font-size:13px;font-weight:700;color:#94A3B8;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;display:flex;align-items:center;gap:6px">'
             + '<i data-lucide="users" width="15" height="15"></i> Grupos de aprovação (' + grupos.length + ')</div>';

  if (grupos.length === 0) {
    box.innerHTML = titulo + '<div style="font-size:14px;color:#94A3B8">Não participa de nenhum grupo de aprovação.</div>';
    if (window.lucide) lucide.createIcons();
    return;
  }

  var chips = grupos.map(function (g) {
    var ativo  = parseInt(g.ativo, 10) === 1;
    var nome   = acsEsc(g.nome);
    var setor  = g.setor_nome ? ' · ' + acsEsc(g.setor_nome) : '';
    var bg     = ativo ? '#D8D4E4' : '#F1F1F1';
    var cor    = ativo ? '#606060' : '#999';
    var inativo = ativo ? '' : ' <span style="font-size:11px;opacity:.8">(inativo)</span>';
    var href   = '?module=grupos&action=detalhe&id=' + encodeURIComponent(g.id);
    return '<a href="' + href + '" title="Abrir grupo" style="display:inline-flex;align-items:center;gap:6px;padding:6px 13px;border-radius:99px;background:' + bg + ';color:' + cor + ';font-size:13px;font-weight:700;text-decoration:none">'
         + '<i data-lucide="users" width="13" height="13"></i>' + nome + setor + inativo + '</a>';
  }).join('');

  box.innerHTML = titulo + '<div style="display:flex;flex-wrap:wrap;gap:6px">' + chips + '</div>';
  if (window.lucide) lucide.createIcons();
}

// ═══ Templates liberados individualmente (liberação granular pontual) ═══

function acsMontarTemplatesLiberados(lista) {
  var box = document.getElementById('acs-tpl-lista');
  if (!box) return;
  lista = lista || [];

  if (lista.length === 0) {
    box.innerHTML = '<div class="acs-liberacao__vazio">Nenhum template liberado individualmente.</div>';
    return;
  }

  box.innerHTML = lista.map(function (ac) {
    var motivo = ac.motivo ? ' — ' + acsEsc(ac.motivo) : '';
    var dataFmt = new Date(ac.concedido_em.replace(' ', 'T')).toLocaleDateString('pt-BR');
    return '<div class="acs-liberacao__item">'
         + '<div>'
         +   '<div class="acs-liberacao__item-nome">' + acsEsc(ac.template_nome) + '</div>'
         +   '<div class="acs-liberacao__item-meta">Concedido por ' + acsEsc(ac.concedido_por_nome || '—') + ' em ' + dataFmt + motivo + '</div>'
         + '</div>'
         + '<button type="button" class="acs-liberacao__revogar" onclick="acsRevogarTemplate(\'' + ac.template_id + '\')">Revogar</button>'
         + '</div>';
  }).join('');
}

// ═══ Escopo de filiais visíveis do usuário ═══

function acsMarcarFiliais(ids) {
  ids = ids || [];
  document.querySelectorAll('.acs-filial-chk').forEach(function (cb) {
    cb.checked = ids.indexOf(cb.value) !== -1;
  });
}

async function acsSalvarFiliais() {
  if (!acsUsuarioAtivo) return;

  var fd = new FormData();
  fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
  fd.append('id_usuario', acsUsuarioAtivo);
  document.querySelectorAll('.acs-filial-chk:checked').forEach(function (cb) {
    fd.append('filiais[]', cb.value);
  });

  try {
    var r = await fetch('?module=acessos&action=salvar_filiais', {
      method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    var d = await r.json();
    alert(d.msg || (d.ok ? 'Filiais salvas.' : 'Falha ao salvar.'));
  } catch (e) {
    alert('Erro ao salvar filiais: ' + e.message);
  }
}

var acsTplBuscaTimer = null;
function acsBuscarTemplateDebounced(q) {
  clearTimeout(acsTplBuscaTimer);
  var box = document.getElementById('acs-tpl-resultados');
  if (q.trim().length < 2) { box.style.display = 'none'; box.innerHTML = ''; return; }
  acsTplBuscaTimer = setTimeout(function () { acsBuscarTemplate(q); }, 300);
}

async function acsBuscarTemplate(q) {
  var box = document.getElementById('acs-tpl-resultados');
  try {
    var r = await fetch('?module=acessos&action=buscar_template&q=' + encodeURIComponent(q), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    var d = await r.json();
    var lista = (d.ok && d.templates) ? d.templates : [];
    if (lista.length === 0) {
      box.innerHTML = '<div class="acs-liberacao__resultado-vazio">Nenhum template encontrado.</div>';
      box.style.display = 'block';
      return;
    }
    box.innerHTML = lista.map(function (t) {
      return '<div class="acs-liberacao__resultado-item" onclick="acsConcederTemplate(\'' + t.id + '\',\'' + acsEsc(t.nome) + '\')">'
           + '<strong>' + acsEsc(t.nome) + '</strong>'
           + '</div>';
    }).join('');
    box.style.display = 'block';
  } catch (e) {
    box.style.display = 'none';
  }
}

async function acsConcederTemplate(templateId, nome) {
  document.getElementById('acs-tpl-resultados').style.display = 'none';
  document.getElementById('acs-tpl-busca').value = '';
  if (!acsUsuarioAtivo) return;

  var motivo = prompt('Motivo da liberação do template "' + nome + '" (opcional):') || '';

  var fd = new FormData();
  fd.append('csrf_token', acsCsrf);
  fd.append('id_usuario', acsUsuarioAtivo);
  fd.append('template_id', templateId);
  fd.append('motivo', motivo);

  try {
    var r = await fetch('?module=acessos&action=conceder_template', {
      method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd
    });
    var d = await r.json();
    if (!d.ok) { alert(d.msg || 'Erro ao conceder acesso.'); return; }
    acsSelecionar(acsUsuarioAtivo);
  } catch (e) {
    alert('Erro: ' + e.message);
  }
}

async function acsRevogarTemplate(templateId) {
  if (!acsUsuarioAtivo) return;
  if (!confirm('Revogar o acesso deste usuário a este template?')) return;

  var fd = new FormData();
  fd.append('csrf_token', acsCsrf);
  fd.append('id_usuario', acsUsuarioAtivo);
  fd.append('template_id', templateId);

  try {
    var r = await fetch('?module=acessos&action=revogar_template', {
      method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd
    });
    var d = await r.json();
    if (!d.ok) { alert(d.msg || 'Erro ao revogar acesso.'); return; }
    acsSelecionar(acsUsuarioAtivo);
  } catch (e) {
    alert('Erro: ' + e.message);
  }
}

function acsMontarGrupos(permissoes, incluirLiberacaoTemplates) {
  incluirLiberacaoTemplates = incluirLiberacaoTemplates !== false;
  var container = document.getElementById('acs-groups');
  container.innerHTML = '';

  Object.entries(acsCatalogo).forEach(function ([modulo, grupo]) {
    var acoesKeys = Object.keys(grupo.acoes);
    var todosOn   = acoesKeys.every(function (a) { return !!(permissoes[modulo] && permissoes[modulo][a]); });

    var card = document.createElement('div');
    card.className = 'acs-group';
    card.innerHTML =
      '<div class="acs-group__header" onclick="this.parentElement.classList.toggle(\'is-closed\')">' +
        '<span class="acs-group__title"><i data-lucide="' + grupo.icone + '" width="15" height="15"></i>' + grupo.label + '</span>' +
        '<span class="acs-group__bulk" data-modulo="' + modulo + '" onclick="acsToggleGrupo(event,\'' + modulo + '\')">' +
          (todosOn ? 'desmarcar todos' : 'marcar todos') +
        '</span>' +
        '<i data-lucide="chevron-down" width="14" height="14" class="acs-group__chevron"></i>' +
      '</div>' +
      '<div class="acs-group__body"></div>';

    var valoresAgregados = Array.isArray(grupo.valores_agregados) ? grupo.valores_agregados : [];

    var body = card.querySelector('.acs-group__body');
    Object.entries(grupo.acoes).forEach(function ([acao, label]) {
      var checked = !!(permissoes[modulo] && permissoes[modulo][acao]);
      var chave   = modulo + '.' + acao;
      var temValorAgregado = valoresAgregados.indexOf(acao) !== -1;
      var row = document.createElement('label');
      row.className = 'acs-toggle-row';
      row.innerHTML =
        '<span>' + label +
          (temValorAgregado
            ? ' <span class="badge badge-warning" style="margin-left:6px" title="Esta tela mostra valores consolidados (somas em R$), não só contagens.">valores agregados</span>'
            : '') +
        '</span>' +
        '<input type="checkbox" data-perm="' + chave + '" ' + (checked ? 'checked' : '')
        + (acsPodeGerirPerms ? '' : ' disabled') + '>';
      body.appendChild(row);
    });

    container.appendChild(card);

    // Liberação individual de templates é a 3ª camada do mesmo controle de
    // acesso a Processos — fica logo abaixo do card de permissões dele.
    // Só faz sentido no contexto de um USUÁRIO específico, não de um perfil.
    if (modulo === 'processos' && incluirLiberacaoTemplates) {
      var tpl = document.getElementById('acs-tpl-templates-liberados');
      if (tpl) container.appendChild(tpl.content.cloneNode(true));

      // Escopo por filial — mesma natureza (recorte do que o usuário enxerga).
      var tplF = acsPodeGerirPerms ? document.getElementById('acs-tpl-filiais') : null;
      if (tplF) container.appendChild(tplF.content.cloneNode(true));
    }
  });
}

function acsToggleGrupo(e, modulo) {
  e.stopPropagation();
  var grupo = acsCatalogo[modulo];
  if (!grupo) return;
  var keys = Object.keys(grupo.acoes).map(function (a) { return modulo + '.' + a; });
  var algumOn = keys.some(function (k) {
    var cb = document.querySelector('[data-perm="' + k + '"]');
    return cb && cb.checked;
  });
  keys.forEach(function (k) {
    var cb = document.querySelector('[data-perm="' + k + '"]');
    if (cb) cb.checked = !algumOn;
  });
  e.target.textContent = algumOn ? 'marcar todos' : 'desmarcar todos';
}

/** Volta ao topo e mostra o modal verde de confirmação (fecha sozinho em 3 s). */
function acsMostrarSucesso() {
  window.scrollTo({ top: 0, behavior: 'smooth' });
  var painel = document.getElementById('acs-detail');
  if (painel) painel.scrollTop = 0;
  document.getElementById('acs-modal-sucesso').style.display = 'flex';
  if (window.lucide) lucide.createIcons();
  clearTimeout(window.acsSucessoTimer);
  window.acsSucessoTimer = setTimeout(acsFecharSucesso, 3000);
}

function acsFecharSucesso() {
  clearTimeout(window.acsSucessoTimer);
  document.getElementById('acs-modal-sucesso').style.display = 'none';
}

async function acsSalvar() {
  if (!acsUsuarioAtivo) return;
  var erroEl = document.getElementById('acs-msg-erro');
  var okEl   = document.getElementById('acs-msg-ok');
  erroEl.style.display = 'none';
  okEl.style.display   = 'none';

  var fd = new FormData();
  fd.append('csrf_token', acsCsrf);
  fd.append('id_usuario', acsUsuarioAtivo);
  document.querySelectorAll('#acs-groups input[type="checkbox"][data-perm]').forEach(function (cb) {
    if (cb.checked) fd.append('perms[' + cb.dataset.perm + ']', '1');
  });

  try {
    var r = await fetch('?module=acessos&action=salvar', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    var d = await r.json();
    if (!d.ok) {
      erroEl.textContent = d.msg || 'Erro ao salvar.';
      erroEl.style.display = 'block';
      return;
    }
    acsMostrarSucesso();

    var badge = document.getElementById('acs-d-origem-badge');
    badge.textContent = 'Personalizado';
    badge.className   = 'badge badge--primary';
    document.getElementById('acs-btn-reverter').style.display = 'inline-flex';

    var item = document.getElementById('acs-user-' + acsUsuarioAtivo);
    if (item && !item.querySelector('.badge--primary')) {
      var span = document.createElement('span');
      span.className = 'badge badge--primary';
      span.textContent = 'Personalizado';
      item.querySelector('.acs-user-item__badges').appendChild(span);
    }
  } catch (e) {
    erroEl.textContent = 'Erro: ' + e.message;
    erroEl.style.display = 'block';
  }
}

async function acsReverterPadrao() {
  if (!acsUsuarioAtivo) return;
  var fd = new FormData();
  fd.append('csrf_token', acsCsrf);
  fd.append('id_usuario', acsUsuarioAtivo);

  try {
    var r = await fetch('?module=acessos&action=remover_override', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    var d = await r.json();
    if (d.ok) {
      acsSelecionar(acsUsuarioAtivo);
      var item = document.getElementById('acs-user-' + acsUsuarioAtivo);
      var tag = item ? item.querySelector('.badge--primary') : null;
      if (tag) tag.remove();
    }
  } catch (e) { /* silencioso — próxima seleção recarrega o estado real */ }
}

// ═══ Modal: Novo Usuário ═══════════════════════════════════
function acsAbrirModalNovo() {
  document.getElementById('acs-novo-erro').style.display = 'none';
  document.getElementById('acs-novo-nome').value = '';
  document.getElementById('acs-novo-email').value = '';
  document.getElementById('acs-novo-form-wrap').style.display = '';
  document.getElementById('acs-novo-sucesso').style.display = 'none';
  document.getElementById('acs-novo-btn-salvar').style.display = '';
  document.getElementById('acs-novo-btn-cancelar').textContent = 'Cancelar';
  document.getElementById('acs-modal-novo').classList.add('is-open');
  setTimeout(function () { document.getElementById('acs-novo-nome').focus(); }, 50);
}

function acsFecharModalNovo() {
  document.getElementById('acs-modal-novo').classList.remove('is-open');
  // Se um usuário foi criado nesta sessão do modal, recarrega a lista para mostrá-lo
  if (document.getElementById('acs-novo-sucesso').style.display !== 'none') {
    window.location.reload();
  }
}

async function acsSalvarVendedorVeiculos(chk) {
  var msgOk   = document.getElementById('acs-msg-ok');
  var msgErro = document.getElementById('acs-msg-erro');
  msgOk.style.display = msgErro.style.display = 'none';

  var fd = new FormData();
  fd.append('csrf_token', acsCsrf);
  fd.append('id_usuario', acsUsuarioDados.id);
  fd.append('valor', chk.checked ? '1' : '0');
  try {
    var r = await fetch('?module=acessos&action=salvar_vendedor_veiculos', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    var d = await r.json();
    if (!d.ok) { throw new Error(d.msg || 'Erro ao salvar.'); }
    acsUsuarioDados.vendedor_veiculos = chk.checked ? 1 : 0;
    msgOk.textContent = d.msg;
    msgOk.style.display = 'block';
  } catch (e) {
    chk.checked = !chk.checked;
    msgErro.textContent = e.message;
    msgErro.style.display = 'block';
  }
}

async function acsSalvarNovoUsuario() {
  var erroEl = document.getElementById('acs-novo-erro');
  erroEl.style.display = 'none';

  var fd = new FormData();
  fd.append('csrf_token', acsCsrf);
  fd.append('nome', document.getElementById('acs-novo-nome').value.trim());
  fd.append('email', document.getElementById('acs-novo-email').value.trim());
  fd.append('perfil', document.getElementById('acs-novo-perfil').value);
  fd.append('filial_id', document.getElementById('acs-novo-filial').value);
  fd.append('setor_id', document.getElementById('acs-novo-setor').value);
  fd.append('vendedor_veiculos', document.getElementById('acs-novo-vendedor-veiculos').checked ? '1' : '0');

  if (!fd.get('nome') || !fd.get('email')) {
    erroEl.textContent = 'Preencha nome e e-mail.';
    erroEl.style.display = 'block';
    return;
  }

  try {
    var r = await fetch('?module=acessos&action=criar_usuario', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    var d = await r.json();

    if (!d.ok) {
      erroEl.textContent = d.msg || 'Erro ao criar usuário.';
      erroEl.style.display = 'block';
      return;
    }

    document.getElementById('acs-novo-form-wrap').style.display = 'none';
    document.getElementById('acs-novo-sucesso').style.display = '';
    document.getElementById('acs-novo-senha-gerada').value = d.senha_temporaria;
    document.getElementById('acs-novo-btn-salvar').style.display = 'none';
    document.getElementById('acs-novo-btn-cancelar').textContent = 'Concluir';
  } catch (e) {
    erroEl.textContent = 'Erro: ' + e.message;
    erroEl.style.display = 'block';
  }
}

function acsCopiarSenhaGerada() {
  var input = document.getElementById('acs-novo-senha-gerada');
  input.select();
  navigator.clipboard && navigator.clipboard.writeText(input.value);
}

// ═══ Perfis: minimizar / expandir a lista ══════════════════
function acsTogglePerfis() {
  var list = document.getElementById('acs-perfis-list');
  var btn  = document.getElementById('acs-perfis-toggle');
  if (!list) return;
  var expandir = list.style.display === 'none';
  list.style.display = expandir ? '' : 'none';
  if (btn) btn.style.transform = expandir ? 'rotate(180deg)' : '';
  try { localStorage.setItem('acs_perfis_collapsed', expandir ? '0' : '1'); } catch (e) {}
}
(function () {
  var v = null;
  try { v = localStorage.getItem('acs_perfis_collapsed'); } catch (e) {}
  var collapsed = (v === null) ? true : (v === '1'); // padrão: minimizado
  var list = document.getElementById('acs-perfis-list');
  var btn  = document.getElementById('acs-perfis-toggle');
  if (list) list.style.display = collapsed ? 'none' : '';
  if (btn)  btn.style.transform = collapsed ? '' : 'rotate(180deg)';
})();

// ═══ Perfis: permissões padrão administráveis ══════════════
var acsPerfilAtivo = null;

async function acsSelecionarPerfil(perfil) {
  document.querySelectorAll('.acs-user-item').forEach(function (el) { el.classList.remove('is-active'); });
  var el = document.getElementById('acs-perfil-' + perfil);
  if (el) el.classList.add('is-active');
  acsPerfilAtivo  = perfil;
  acsUsuarioAtivo = null;

  var painel = document.getElementById('acs-detail');
  painel.innerHTML = '<div class="acs-empty"><i data-lucide="loader" width="28" height="28"></i><p>Carregando...</p></div>';
  if (window.lucide) lucide.createIcons();

  try {
    var r = await fetch('?module=acessos&action=perfil_dados&perfil=' + encodeURIComponent(perfil), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    var d = await r.json();
    if (!d.ok) {
      painel.innerHTML = '<div class="acs-empty"><p style="color:var(--color-danger)">' + d.msg + '</p></div>';
      return;
    }

    var badge = d.editavel
      ? (d.customizado
          ? '<span class="badge badge--primary">Customizado</span>'
          : '<span class="badge badge--muted">Padrão do sistema</span>')
      : '<span class="badge badge--success">Acesso total — fixo</span>';

    painel.innerHTML =
      '<div class="acs-detail__header">' +
        '<div>' +
          '<div class="acs-detail__nome">Perfil: ' + perfil.charAt(0).toUpperCase() + perfil.slice(1) + '</div>' +
          '<div class="acs-detail__subtitle">Padrão aplicado a todos os usuários deste perfil sem personalização própria</div>' +
        '</div>' + badge +
      '</div>' +
      (d.editavel
        ? '<div style="display:flex; justify-content:flex-end; margin-bottom:var(--space-3)"><button type="button" class="btn btn-primary" onclick="acsSalvarPerfil()"><i data-lucide="save" width="14" height="14"></i> Salvar permissões do perfil</button></div>'
        : '') +
      '<div id="acs-msg-erro" class="alert alert-danger" style="display:none"></div>' +
      '<div id="acs-msg-ok" class="alert alert-success" style="display:none"></div>' +
      '<div class="acs-groups" id="acs-groups"></div>' +
      '<div class="acs-detail__footer">' +
        '<div style="display:flex;gap:var(--space-2)">' +
          (d.editavel && d.customizado
            ? '<button type="button" class="btn btn-tertiary" onclick="acsReverterPerfil()"><i data-lucide="rotate-ccw" width="14" height="14"></i> Reverter ao padrão do sistema</button>'
            : '') +
        '</div>' +
        (d.editavel
          ? '<button type="button" class="btn btn-primary" onclick="acsSalvarPerfil()"><i data-lucide="save" width="14" height="14"></i> Salvar permissões do perfil</button>'
          : '') +
      '</div>';

    acsMontarGrupos(d.permissoes, false);
    if (!d.editavel) {
      document.querySelectorAll('#acs-groups input[type="checkbox"]').forEach(function (cb) { cb.disabled = true; });
    }
    if (window.lucide) lucide.createIcons();
  } catch (e) {
    painel.innerHTML = '<div class="acs-empty"><p style="color:var(--color-danger)">Erro: ' + e.message + '</p></div>';
  }
}

async function acsSalvarPerfil() {
  if (!acsPerfilAtivo) return;
  var erroEl = document.getElementById('acs-msg-erro');
  var okEl   = document.getElementById('acs-msg-ok');
  erroEl.style.display = 'none'; okEl.style.display = 'none';

  var fd = new FormData();
  fd.append('csrf_token', acsCsrf);
  fd.append('perfil', acsPerfilAtivo);
  document.querySelectorAll('#acs-groups input[type="checkbox"][data-perm]').forEach(function (cb) {
    if (cb.checked) fd.append('perms[' + cb.dataset.perm + ']', '1');
  });

  try {
    var r = await fetch('?module=acessos&action=salvar_perfil', {
      method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd
    });
    var d = await r.json();
    if (!d.ok) { erroEl.textContent = d.msg; erroEl.style.display = 'block'; return; }
    acsSelecionarPerfil(acsPerfilAtivo); // recarrega badge Customizado + botão reverter
    acsMostrarSucesso();
  } catch (e) {
    erroEl.textContent = 'Erro: ' + e.message; erroEl.style.display = 'block';
  }
}

async function acsReverterPerfil() {
  if (!acsPerfilAtivo) return;
  var fd = new FormData();
  fd.append('csrf_token', acsCsrf);
  fd.append('perfil', acsPerfilAtivo);
  try {
    var r = await fetch('?module=acessos&action=reverter_perfil', {
      method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd
    });
    var d = await r.json();
    if (d.ok) acsSelecionarPerfil(acsPerfilAtivo);
  } catch (e) { /* próxima seleção recarrega o estado real */ }
}

// ═══ Modal: Editar Cadastro ════════════════════════════════
function acsAbrirModalEditar() {
  if (!acsUsuarioAtivo || !acsUsuarioDados) return;
  document.getElementById('acs-edit-erro').style.display = 'none';
  document.getElementById('acs-edit-nome').value   = acsUsuarioDados.nome || '';
  document.getElementById('acs-edit-email').value  = acsUsuarioDados.email || '';
  document.getElementById('acs-edit-perfil').value = acsUsuarioDados.perfil || 'solicitante';
  if (acsUsuarioDados.filial_id) document.getElementById('acs-edit-filial').value = acsUsuarioDados.filial_id;
  if (acsUsuarioDados.setor_id)  document.getElementById('acs-edit-setor').value  = acsUsuarioDados.setor_id;
  document.getElementById('acs-edit-ativo').checked = !!parseInt(acsUsuarioDados.ativo, 10);
  // '' = sem horário, que é o padrão do sistema (entra a qualquer hora)
  document.getElementById('acs-edit-horario').value = acsUsuarioDados.horario_id || '';
  document.getElementById('acs-modal-editar').classList.add('is-open');
  setTimeout(function () { document.getElementById('acs-edit-nome').focus(); }, 50);
}

function acsFecharModalEditar() {
  document.getElementById('acs-modal-editar').classList.remove('is-open');
}

async function acsSalvarEdicao() {
  var erroEl = document.getElementById('acs-edit-erro');
  erroEl.style.display = 'none';

  var fd = new FormData();
  fd.append('csrf_token', acsCsrf);
  fd.append('id_usuario', acsUsuarioAtivo);
  fd.append('nome',  document.getElementById('acs-edit-nome').value.trim());
  fd.append('email', document.getElementById('acs-edit-email').value.trim());
  fd.append('perfil', document.getElementById('acs-edit-perfil').value);
  fd.append('filial_id', document.getElementById('acs-edit-filial').value);
  fd.append('setor_id',  document.getElementById('acs-edit-setor').value);
  fd.append('ativo', document.getElementById('acs-edit-ativo').checked ? '1' : '0');
  fd.append('horario_id', document.getElementById('acs-edit-horario').value);

  if (!fd.get('nome') || !fd.get('email')) {
    erroEl.textContent = 'Preencha nome e e-mail.';
    erroEl.style.display = 'block';
    return;
  }

  try {
    var r = await fetch('?module=acessos&action=atualizar_usuario', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    var d = await r.json();

    if (!d.ok) {
      erroEl.textContent = d.msg || 'Erro ao salvar o cadastro.';
      erroEl.style.display = 'block';
      return;
    }

    // Recarrega para refletir nome/perfil/status na lista da esquerda
    window.location.reload();
  } catch (e) {
    erroEl.textContent = 'Erro: ' + e.message;
    erroEl.style.display = 'block';
  }
}

// ═══ Modal: Trocar Senha ═══════════════════════════════════
function acsAbrirModalSenha() {
  if (!acsUsuarioAtivo) return;
  document.getElementById('acs-senha-erro').style.display = 'none';
  document.getElementById('acs-senha-nova').value = '';
  document.getElementById('acs-senha-confirma').value = '';
  document.getElementById('acs-senha-nome').textContent =
    'Definir nova senha para ' + (document.getElementById('acs-d-nome').textContent || 'este usuário') + '.';

  document.getElementById('acs-senha-form-wrap').style.display = '';
  document.getElementById('acs-senha-sucesso').style.display = 'none';
  document.getElementById('acs-senha-btn-salvar').style.display = '';
  document.getElementById('acs-senha-btn-cancelar').textContent = 'Cancelar';

  document.getElementById('acs-modal-senha').classList.add('is-open');
  setTimeout(function () { document.getElementById('acs-senha-nova').focus(); }, 50);
}

function acsFecharModalSenha() {
  document.getElementById('acs-modal-senha').classList.remove('is-open');
}

async function acsSalvarSenha() {
  var erroEl = document.getElementById('acs-senha-erro');
  erroEl.style.display = 'none';

  var nova     = document.getElementById('acs-senha-nova').value;
  var confirma = document.getElementById('acs-senha-confirma').value;

  if (nova.length < 6) {
    erroEl.textContent = 'A senha deve ter no mínimo 6 caracteres.';
    erroEl.style.display = 'block';
    return;
  }
  if (nova !== confirma) {
    erroEl.textContent = 'As senhas não coincidem.';
    erroEl.style.display = 'block';
    return;
  }

  var fd = new FormData();
  fd.append('csrf_token', acsCsrf);
  fd.append('id_usuario', acsUsuarioAtivo);
  fd.append('nova_senha', nova);

  try {
    var r = await fetch('?module=acessos&action=trocar_senha', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    var d = await r.json();

    if (!d.ok) {
      erroEl.textContent = d.msg || 'Erro ao trocar senha.';
      erroEl.style.display = 'block';
      return;
    }

    document.getElementById('acs-senha-form-wrap').style.display = 'none';
    document.getElementById('acs-senha-btn-salvar').style.display = 'none';
    document.getElementById('acs-senha-btn-cancelar').textContent = 'Fechar';
    document.getElementById('acs-senha-sucesso-msg').textContent =
      'Senha redefinida com sucesso para ' + (document.getElementById('acs-d-nome').textContent || 'o usuário') + '.';
    document.getElementById('acs-senha-sucesso').style.display = '';
  } catch (e) {
    erroEl.textContent = 'Erro: ' + e.message;
    erroEl.style.display = 'block';
  }
}

// Modais fecham apenas pelos botões (sem clique-fora).

// Dropdown de busca de template fecha ao clicar fora
document.addEventListener('click', function (e) {
  var box = document.getElementById('acs-tpl-resultados');
  var input = document.getElementById('acs-tpl-busca');
  if (box && box.style.display !== 'none' && !e.target.closest('#acs-tpl-resultados') && e.target !== input) {
    box.style.display = 'none';
  }
});
</script>
