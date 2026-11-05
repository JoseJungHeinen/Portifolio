<?php
// @versao Sys_03 V_16_05/10/2026
/**
 * views/layout/sidebar.php
 * Sidebar fixa do sistema Exemplo NF
 *
 * Variáveis esperadas (definidas antes do include):
 *   $activeModule  string  ex: 'dashboard', 'processes', 'tasks', 'financial',
 *                          'templates', 'groups', 'tables', 'reports'
 *   $currentUser   array   ['nome' => '...', 'perfil' => '...']  (ou Auth::user())
 */

// Garante variáveis com fallback seguro
$activeModule = $activeModule ?? 'dashboard';
$currentUser  = $currentUser  ?? ['nome' => 'Usuário', 'perfil' => 'usuario'];

$perfil = strtolower($currentUser['perfil'] ?? 'usuario');
// Cada link do menu usa exatamente a permissão que o controller exige, para
// nunca oferecer uma tela que responde 403. Só o que NÃO passa pelo mapa de
// permissões fica por perfil: Configurações Gerais (super admin) e o Controle
// de Acesso (Permissions::isAdminCadastros()).
$isSuper = $perfil === 'super_admin';

// Dashboard tem permissão granular por aba — o link só aparece se o usuário
// tiver acesso a pelo menos uma delas (ex: comprador só vê "Meus Processos").
$temAcessoDashboard = Auth::canAny('dashboard', [
    'ver_visao_geral', 'ver_meus_processos', 'ver_processos',
    'ver_aprovacoes', 'ver_financeiro', 'ver_desempenho',
]);

// Helpers
function sidebarActive(string $module, string $active): string {
    return $module === $active ? ' is-active' : '';
}

function sidebarInitials(string $nome): string {
    $parts = explode(' ', trim($nome));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $initials .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $initials ?: 'U';
}

$base = '?module=';
?>
<aside class="sidebar" id="main-sidebar" role="navigation" aria-label="Menu principal">

  <!-- Logo -->
  <a href="<?= $base ?>dashboard&action=index" class="sidebar__logo" title="Exemplo financeiro — Início">
    <div class="sidebar__logo-icon">
      <div class="sidebar__logo-symbol">B</div>
    </div>
    <div class="sidebar__logo-text">
      <span class="sidebar__logo-brand">Exemplo</span>
      <span class="sidebar__logo-vertical">financeiro</span>
    </div>
  </a>

  <!-- Navegação principal -->
  <nav class="sidebar__nav">

    <?php if ($temAcessoDashboard && Auth::can('menu', 'dashboard')): ?>
    <a href="<?= $base ?>dashboard&action=index"
       class="sidebar__link<?= sidebarActive('dashboard', $activeModule) ?>"
       title="Dashboard">
      <span class="sidebar__link-icon">
        <i data-lucide="layout-dashboard" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Dashboard</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('processos', 'ver') && Auth::can('menu', 'pesquisar_processos')): ?>
    <a href="<?= $base ?>processos&action=pesquisar"
       class="sidebar__link<?= sidebarActive('pesquisar_processos', $activeModule) ?>"
       title="Pesquisar Processos">
      <span class="sidebar__link-icon">
        <i data-lucide="search" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Pesquisar Processos</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('capturas', 'ver') && Auth::can('menu', 'notas_capturadas')): ?>
    <a href="<?= $base ?>capturas&action=index"
       class="sidebar__link<?= sidebarActive('capturas', $activeModule) ?>"
       title="Notas Capturadas">
      <span class="sidebar__link-icon">
        <i data-lucide="inbox" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Notas Capturadas</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('processos', 'ver') && Auth::can('menu', 'processos')): ?>
    <a href="<?= $base ?>processos&action=index"
       class="sidebar__link<?= sidebarActive('processos', $activeModule) ?>"
       title="Processos">
      <span class="sidebar__link-icon">
        <i data-lucide="file-text" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Processos</span>
    </a>
    <?php endif; ?>

    <?php // Nasce desligado (Permissions::MENU_DESLIGADOS_POR_PADRAO); admin e super admin sempre veem. Só o link: a tela segue processos.ver. ?>
    <?php if (Auth::can('processos', 'ver') && (Auth::can('menu', 'entrega_futura') || Permissions::isAdminCadastros())): ?>
    <a href="<?= $base ?>processos&action=entrega_futura"
       class="sidebar__link<?= sidebarActive('entrega_futura', $activeModule) ?>"
       title="Entrega Futura — vendas e remessas">
      <span class="sidebar__link-icon">
        <i data-lucide="truck" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Entrega Futura</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('tarefas', 'ver') && Auth::can('menu', 'minhas_tarefas')): ?>
    <a href="<?= $base ?>tarefas&action=index"
       class="sidebar__link<?= sidebarActive('tarefas', $activeModule) ?>"
       title="Minhas Tarefas">
      <span class="sidebar__link-icon">
        <i data-lucide="check-square" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Minhas Tarefas</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('financeiro', 'ver') && Auth::can('menu', 'controle_financeiro')): ?>
    <a href="<?= $base ?>financeiro&action=index"
       class="sidebar__link<?= sidebarActive('financeiro', $activeModule) ?>"
       title="Controle Financeiro">
      <span class="sidebar__link-icon">
        <i data-lucide="credit-card" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Controle Financeiro</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('pagamentos', 'ver') && Auth::can('menu', 'pagamentos')): ?>
    <a href="<?= $base ?>financeiro&action=pagamentos"
       class="sidebar__link<?= sidebarActive('pagamentos', $activeModule) ?>"
       title="Pagamentos">
      <span class="sidebar__link-icon">
        <i data-lucide="wallet" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Pagamentos</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('fiscal', 'ver') && Auth::can('menu', 'fiscal')): ?>
    <a href="<?= $base ?>fiscal&action=index"
       class="sidebar__link<?= sidebarActive('fiscal', $activeModule) ?>"
       title="Fiscal — IBS e CBS por fornecedor">
      <span class="sidebar__link-icon">
        <i data-lucide="landmark" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Fiscal</span>
    </a>
    <?php endif; ?>

    <?php // Protótipo em construção — só aparece para quem tem a função
          // Faturamento liberada no Controle de Acesso. ?>
    <?php if (is_file(SRC . '/Controllers/FaturamentoController.php') && Auth::can('faturamento', 'ver') && Auth::can('menu', 'faturamento')): ?>
    <a href="<?= $base ?>faturamento&action=index"
       class="sidebar__link<?= sidebarActive('faturamento', $activeModule) ?>"
       title="Faturamento — protótipo em construção">
      <span class="sidebar__link-icon">
        <i data-lucide="car" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Faturamento</span>
      <span style="margin-left:auto; font-size:9px; font-family:var(--font-bold); text-transform:uppercase; background:rgba(255,255,255,0.18); color:#fff; border-radius:4px; padding:2px 5px;">Beta</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('relatorios', 'ver') && Auth::can('menu', 'relatorios')): ?>
    <a href="<?= $base ?>relatorios&action=index"
       class="sidebar__link<?= sidebarActive('relatorios', $activeModule) ?>"
       title="Relatórios">
      <span class="sidebar__link-icon">
        <i data-lucide="bar-chart-2" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Relatórios</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('processos', 'importar_pdfs') && Auth::can('menu', 'importar_pdfs')): ?>
    <a href="<?= $base ?>importar&action=index"
       class="sidebar__link<?= sidebarActive('importar', $activeModule) ?>"
       title="Importar PDFs — fatura e NFS-e em lote">
      <span class="sidebar__link-icon">
        <i data-lucide="file-up" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Importar PDFs</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('processos', 'criar') && Auth::can('menu', 'novo_processo')): ?>
    <a href="<?= $base ?>processos&action=novo"
       class="sidebar__link<?= sidebarActive('novo_processo', $activeModule) ?>"
       title="Novo Processo">
      <span class="sidebar__link-icon">
        <i data-lucide="plus-circle" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Novo Processo</span>
    </a>
    <?php endif; ?>

    <?php
      // O bloco só aparece se houver ao menos um item liberado — e cada item
      // usa exatamente a permissão que o controller exige, para o menu nunca
      // oferecer uma tela que responde 403.
      $temConfig = (Auth::can('templates', 'ver') && Auth::can('menu', 'templates'))
                || (Auth::can('grupos', 'ver') && Auth::can('menu', 'grupos'))
                || (Auth::can('prefluxo', 'ver') && Auth::can('menu', 'prefluxo'))
                || (Auth::can('tabelas', 'ver') && Auth::can('menu', 'listas'))
                || Permissions::isAdminCadastros() || $isSuper;
    ?>
    <?php if ($temConfig): ?>
    <hr class="sidebar__divider">
    <button type="button" class="sidebar__group-label" id="sidebar-config-toggle"
            onclick="sidebarToggleConfig()" aria-expanded="true" aria-controls="sidebar-config-itens"
            style="display:flex; align-items:center; justify-content:space-between; gap:8px; width:100%;
                   background:none; border:none; cursor:pointer; text-align:left;
                   font:inherit; color:inherit; letter-spacing:inherit; text-transform:inherit;">
      <span>Configuração</span>
      <?php // A rotação fica no span, não no <i>: o lucide troca a tag por um
            // <svg> ao renderizar e levaria junto o style aplicado antes. ?>
      <span id="sidebar-config-chevron"
            style="display:inline-flex; flex-shrink:0; transition:transform .15s">
        <i data-lucide="chevron-down" width="14" height="14"></i>
      </span>
    </button>
    <div id="sidebar-config-itens">

    <?php if (Auth::can('templates', 'ver') && Auth::can('menu', 'templates')): ?>
    <a href="<?= $base ?>templates&action=index"
       class="sidebar__link<?= sidebarActive('templates', $activeModule) ?>"
       title="Templates de Workflow">
      <span class="sidebar__link-icon">
        <i data-lucide="copy" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Templates</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('grupos', 'ver') && Auth::can('menu', 'grupos')): ?>
    <a href="<?= $base ?>grupos&action=index"
       class="sidebar__link<?= sidebarActive('grupos', $activeModule) ?>"
       title="Grupos de Aprovação">
      <span class="sidebar__link-icon">
        <i data-lucide="users" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Grupos</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('prefluxo', 'ver') && Auth::can('menu', 'prefluxo')): ?>
    <a href="<?= $base ?>prefluxo&action=index"
       class="sidebar__link<?= sidebarActive('prefluxo', $activeModule) ?>"
       title="Pré-fluxo — regras de vínculo automático">
      <span class="sidebar__link-icon">
        <i data-lucide="git-branch" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Pré-fluxo</span>
    </a>
    <?php endif; ?>

    <?php if (Auth::can('tabelas', 'ver') && Auth::can('menu', 'listas')): ?>
    <a href="<?= $base ?>tabelas&action=index"
       class="sidebar__link<?= sidebarActive('tabelas', $activeModule) ?>"
       title="Listas">
      <span class="sidebar__link-icon">
        <i data-lucide="list" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Listas</span>
    </a>
    <?php endif; ?>

    <?php // Controle de Acesso: gate próprio (admin de cadastros + super admin),
          // igual ao do AccessController — não passa pelo mapa de permissões. ?>
    <?php if (Permissions::isAdminCadastros()): ?>
    <a href="<?= $base ?>acessos&action=index"
       class="sidebar__link<?= sidebarActive('acessos', $activeModule) ?>"
       title="Controle de Acesso">
      <span class="sidebar__link-icon">
        <i data-lucide="shield-check" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Controle de Acesso</span>
    </a>
    <?php endif; ?>

    <?php if ($isSuper): ?>
    <a href="<?= $base ?>geral&action=index"
       class="sidebar__link<?= sidebarActive('geral', $activeModule) ?>"
       title="Configurações Gerais">
      <span class="sidebar__link-icon">
        <i data-lucide="settings" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Geral</span>
    </a>
    <?php endif; ?>
    </div>
    <script>
      // Recolhe/expande o bloco Configuração. A escolha fica no navegador, senão
      // ela se perderia a cada navegação — a sidebar é renderizada de novo em
      // toda página. Aplicado antes da pintura para não piscar aberto.
      (function () {
        var itens = document.getElementById('sidebar-config-itens');
        var chev  = document.getElementById('sidebar-config-chevron');
        var botao = document.getElementById('sidebar-config-toggle');
        if (!itens) return;

        var aberto = localStorage.getItem('sidebarConfigAberto') !== '0';

        function aplicar() {
          itens.style.display = aberto ? '' : 'none';
          if (chev)  chev.style.transform = aberto ? '' : 'rotate(-90deg)';
          if (botao) botao.setAttribute('aria-expanded', aberto ? 'true' : 'false');
        }

        window.sidebarToggleConfig = function () {
          aberto = !aberto;
          localStorage.setItem('sidebarConfigAberto', aberto ? '1' : '0');
          aplicar();
        };

        aplicar();
      })();
    </script>
    <?php endif; ?>

    <?php // Console Apollo: liberado por usuário (nf_apollo_acessos) — link para
          // quem tem acesso mas NÃO é admin (o admin já chega pela aba em "Geral"). ?>
    <?php if (!$isSuper && class_exists('ApolloAccess') && ApolloAccess::pode($currentUser['id'] ?? '')): ?>
    <a href="<?= $base ?>geral&action=index&aba=apollo"
       class="sidebar__link<?= sidebarActive('geral', $activeModule) ?>"
       title="Consulta Apollo">
      <span class="sidebar__link-icon">
        <i data-lucide="database" width="20" height="20"></i>
      </span>
      <span class="sidebar__label">Apollo</span>
    </a>
    <?php endif; ?>

  </nav>

  <!-- Rodapé: usuário logado -->
  <div class="sidebar__footer">
    <div class="sidebar__user-avatar" title="<?= htmlspecialchars($currentUser['nome']) ?>">
      <?= sidebarInitials($currentUser['nome']) ?>
    </div>
    <div class="sidebar__user-info">
      <div class="sidebar__user-name"><?= htmlspecialchars($currentUser['nome']) ?></div>
      <div class="sidebar__user-role"><?= htmlspecialchars(ucfirst($perfil)) ?></div>
    </div>
  </div>

</aside>
