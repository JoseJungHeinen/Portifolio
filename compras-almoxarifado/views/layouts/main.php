<?php
// @versao Ver.003.291026
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <meta name="app-url" content="<?= e(rtrim($_ENV['APP_URL'] ?? '', '/')) ?>">
    <title><?= e($title ?? ($_ENV['APP_NAME'] ?? 'Exemplo Compras')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= asset('css/app.css') ?>?v=2" rel="stylesheet">
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════════ -->
<?php if (empty($noSidebar)): ?>
<?php
$usuarioAtual = auth() ?? [];
$marca        = sistema_marca();
// Exemplo Requisições mostra só o fluxo de compras; o resto do menu é do Compras.
$soCompras    = sistema() === 'compras';
$uriAtual     = rota_atual();
?>
<nav id="sidebar">

    <!-- Logo — área vermelha obrigatória (Manual de Design § 4.2) -->
    <a class="sidebar-brand" href="<?= url('dashboard') ?>">
        <div class="sidebar-brand-icon">
            <i class="bi <?= $marca['icone'] ?>"></i>
        </div>
        <div class="sidebar-brand-text">
            Exemplo
            <small><?= e($marca['nome']) ?></small>
        </div>
    </a>

    <ul class="sidebar-nav">

        <?php if (pode('solicitacoes')): ?>
        <li>
            <a href="<?= url('solicitacoes') ?>" class="<?= str_starts_with(parse_url($uriAtual, PHP_URL_PATH) ?? '', '/solicitacoes') ? 'active' : '' ?>">
                <i class="bi bi-chat-square-text"></i>
                <span>Solicitações</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if ($soCompras && !in_array($usuarioAtual['perfil'] ?? '', ['solicitante'], true)): ?>
        <li>
            <a href="<?= url('dashboard') ?>" class="<?= str_contains($uriAtual, 'dashboard') ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2"></i>
                <span>Dashboard</span>
            </a>
        </li>
        <li class="nav-section">Operação</li>
        <?php endif; ?>

        <?php if (!solicitante_usa_solicitacao()): /* esse solicitante pede pela Solicitação de Compra */ ?>
        <li>
            <a href="<?= url('requisicoes') ?>" class="<?= str_contains($uriAtual, 'requisicoes') && !str_contains($uriAtual, 'tipo=ordem_compra') ? 'active' : '' ?>">
                <i class="bi bi-cart-plus"></i>
                <span>Requisições</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (podeModulo('aprovacoes')): ?>
        <li>
            <a href="<?= url('aprovacoes') ?>" class="<?= str_contains($uriAtual, 'aprovacoes') ? 'active' : '' ?>">
                <i class="bi bi-check2-square"></i>
                <span>Aprovações</span>
                <?php if (!empty($nAprovacoesPendentes) && $nAprovacoesPendentes > 0): ?>
                    <span class="badge ms-auto" style="background:var(--color-primary);color:#fff"><?= $nAprovacoesPendentes ?></span>
                <?php endif; ?>
            </a>
        </li>
        <?php endif; ?>

        <?php if ($soCompras && podeModulo('estoque')): ?>
        <li>
            <a href="<?= url('estoque') ?>" class="<?= str_contains($uriAtual, 'estoque') ? 'active' : '' ?>">
                <i class="bi bi-boxes"></i>
                <span>Estoque</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if ($soCompras && podeModulo('itens')): ?>
        <li>
            <a href="<?= url('itens') ?>" class="<?= str_contains($uriAtual, '/itens') ? 'active' : '' ?>">
                <i class="bi bi-card-list"></i>
                <span>Itens</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if ($soCompras && podeModulo('brindes')): ?>
        <li>
            <a href="<?= url('brindes') ?>" class="<?= str_contains($uriAtual, 'brindes') ? 'active' : '' ?>">
                <i class="bi bi-gift"></i>
                <span>Brindes</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (podeModulo('requisicoes.ordem_compra') || podeModulo('cotacoes') || podeModulo('fornecedores') || ($soCompras && podeModulo('contratos')) || podeModulo('fiscal')): ?>
        <li class="nav-section">Compras</li>
        <?php endif; ?>
        <?php if (podeModulo('requisicoes.ordem_compra')): ?>
        <li>
            <a href="<?= url('requisicoes/nova?tipo=ordem_compra') ?>" class="<?= str_contains($uriAtual, 'tipo=ordem_compra') ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-text"></i>
                <span>Ordem de Compra</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (podeModulo('cotacoes')): ?>
        <li>
            <a href="<?= url('compras/cotacoes') ?>" class="<?= str_contains($uriAtual, 'cotacoes') ? 'active' : '' ?>">
                <i class="bi bi-shop"></i>
                <span>Cotações</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (podeModulo('fornecedores')): ?>
        <li>
            <a href="<?= url('fornecedores') ?>" class="<?= str_contains($uriAtual, 'fornecedores') ? 'active' : '' ?>">
                <i class="bi bi-truck"></i>
                <span>Fornecedores</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if ($soCompras && podeModulo('contratos')): ?>
        <li>
            <a href="<?= url('contratos') ?>" class="<?= str_contains($uriAtual, 'contratos') ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-text"></i>
                <span>Contratos</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (podeModulo('fiscal')): ?>
        <li>
            <a href="<?= url('fiscal') ?>" class="<?= str_contains($uriAtual, 'fiscal') ? 'active' : '' ?>">
                <i class="bi bi-receipt"></i>
                <span>Fiscal</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if ($soCompras): /* módulos fora do fluxo de compras */ ?>
        <?php if (podeModulo('relatorios')): ?>
        <li class="nav-section">Financeiro</li>
        <?php endif; ?>
        <?php if (podeModulo('relatorios')): ?>
        <li>
            <a href="<?= url('relatorios') ?>" class="<?= str_contains($uriAtual, 'relatorios') ? 'active' : '' ?>">
                <i class="bi bi-bar-chart-line"></i>
                <span>Relatórios</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (podeModulo('auditoria_estoque')): ?>
        <li>
            <a href="<?= url('auditoria-estoque') ?>" class="<?= str_contains($uriAtual, 'auditoria-estoque') ? 'active' : '' ?>">
                <i class="bi bi-clipboard2-check"></i>
                <span>Auditoria</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (podeModulo('confirmacao_recebimento')): ?>
        <li>
            <a href="<?= url('transferencias/recebimento') ?>" class="<?= str_contains($uriAtual, 'transferencias') ? 'active' : '' ?>">
                <i class="bi bi-truck"></i>
                <span>Confirmação de Recebimento</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (podeModulo('ramais') || podeModulo('links_uteis')): ?>
        <li class="nav-section">Utilidades</li>
        <?php endif; ?>
        <?php if (podeModulo('ramais')): ?>
        <li>
            <a href="<?= url('ramais') ?>" class="<?= str_contains($uriAtual, '/ramais') ? 'active' : '' ?>">
                <i class="bi bi-telephone-fill"></i>
                <span>Ramais</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (podeModulo('links_uteis')): ?>
        <li>
            <a href="<?= url('links-uteis') ?>" class="<?= str_contains($uriAtual, 'links-uteis') ? 'active' : '' ?>">
                <i class="bi bi-link-45deg"></i>
                <span>Links Úteis</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (podeModulo('usuarios') || podeModulo('configuracoes')): ?>
        <li class="nav-section">Admin</li>
        <?php endif; ?>
        <?php if (podeModulo('usuarios')): ?>
        <li>
            <a href="<?= url('usuarios') ?>" class="<?= str_contains($uriAtual, 'usuarios') ? 'active' : '' ?>">
                <i class="bi bi-people"></i>
                <span>Usuários</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (podeModulo('configuracoes')): ?>
        <li>
            <a href="<?= url('configuracoes/alcadas') ?>" class="<?= str_contains($uriAtual, 'configuracoes') ? 'active' : '' ?>">
                <i class="bi bi-gear"></i>
                <span>Configurações</span>
            </a>
        </li>
        <?php endif; ?>
        <?php endif; ?>

    </ul>

    <div class="sidebar-footer" id="sidebarToggle">
        <i class="bi bi-layout-sidebar-reverse"></i>
        <span>Recolher menu</span>
    </div>

</nav>
<?php endif; ?>

<!-- ══ MAIN WRAPPER + TOPBAR ════════════════════════════════ -->
<div id="main-wrapper" <?= !empty($noSidebar) ? "class=\"no-sidebar\"" : "" ?>>
<nav class="topbar">

    <button class="topbar-toggle d-lg-none" id="sidebarMobileToggle">
        <i class="bi bi-list fs-5"></i>
    </button>

    <span class="topbar-title"><?= e($title ?? '') ?></span>

    <div class="ms-auto d-flex align-items-center gap-2">

        <!-- Notificações -->
        <?php
        // Só o Dashboard passa $notificacoes; nas demais telas o sino carrega aqui.
        if (!isset($notificacoes) && auth()) {
            try {
                $notifSvc     = new \App\Services\NotificacaoService();
                $notificacoes = $notifSvc->paraUsuario(auth()['id'], 5);
                $naoLidas     = $notifSvc->naoLidas(auth()['id']);
            } catch (\Throwable $e) {
                $notificacoes = [];
                $naoLidas     = 0;
            }
        }
        $notifLink = static function (array $n): string {
            if (($n['entidade_tipo'] ?? '') === 'solicitacao_compra' && !empty($n['entidade_id'])) {
                return url('solicitacoes/' . $n['entidade_id']);
            }
            return '#';
        };
        ?>
        <div class="dropdown">
            <button class="topbar-icon-btn" data-bs-toggle="dropdown" aria-label="Notificações">
                <i class="bi bi-bell fs-6"></i>
                <?php if (!empty($naoLidas) && $naoLidas > 0): ?>
                    <span class="notif-dot"></span>
                <?php endif; ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width:300px;max-height:380px;overflow-y:auto">
                <li><span class="dropdown-header">Notificações</span></li>
                <?php if (empty($notificacoes)): ?>
                    <li><span class="dropdown-item text-muted" style="cursor:default">Nenhuma notificação.</span></li>
                <?php else: foreach ($notificacoes as $n): ?>
                    <li>
                        <a href="<?= e($notifLink($n)) ?>" class="dropdown-item notif-item <?= $n['lida'] ? 'text-muted' : 'fw-semibold' ?>"
                           data-id="<?= $n['id'] ?>" <?= $n['lida'] ? 'data-lida=1' : '' ?>>
                            <i class="bi bi-bell-fill" style="color:var(--color-primary);font-size:.75rem"></i>
                            <span><?= e($n['titulo']) ?></span>
                            <div class="text-muted ms-auto" style="font-size:.7rem;white-space:nowrap"><?= datetime_br($n['criado_em']) ?></div>
                        </a>
                    </li>
                <?php endforeach; endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item justify-content-center" style="font-size:.75rem" href="#">Ver todas</a></li>
            </ul>
        </div>

        <!-- Usuário -->
        <div class="dropdown">
            <button class="btn btn-link d-flex align-items-center gap-2 text-decoration-none p-0" data-bs-toggle="dropdown">
                <div class="avatar-sm"><?= strtoupper(substr(auth()['nome'] ?? 'U', 0, 2)) ?></div>
                <div class="d-none d-md-block text-start">
                    <div style="font-size:.8rem;font-family:var(--font-bold);color:var(--text-primary);line-height:1.2"><?= e(auth()['nome'] ?? '') ?></div>
                    <div style="font-size:.65rem;color:var(--text-muted)"><?= ucfirst(auth()['perfil'] ?? '') ?></div>
                </div>
                <i class="bi bi-chevron-down d-none d-md-inline" style="font-size:.6rem;color:var(--text-muted)"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <div class="px-3 py-2" style="border-bottom:1px solid var(--color-gray-light)">
                        <div style="font-size:.8rem;font-family:var(--font-bold);color:var(--text-secondary)"><?= e(auth()['nome'] ?? '') ?></div>
                        <div style="font-size:.7rem;color:var(--text-muted)"><?= e(auth()['email'] ?? '') ?></div>
                    </div>
                </li>
                <li>
                    <form action="<?= url('logout') ?>" method="POST">
                        <?= csrf_field() ?>
                        <button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right"></i>Sair</button>
                    </form>
                </li>
            </ul>
        </div>

    </div>
</nav>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

<!-- ══ CONTENT ═══════════════════════════════════════════════ -->
<main class="main-content">

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill"></i><?= e($msg) ?>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-circle-fill"></i><?= e($msg) ?>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($msg = flash('warning')): ?>
    <div class="alert alert-warning alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill"></i><?= e($msg) ?>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?= $content ?>

</main>
</div><!-- /main-wrapper -->

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:199"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset('js/app.js') ?>?v=2"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('sidebarToggle')?.addEventListener('click', () => {
        document.getElementById('sidebar').classList.toggle('collapsed');
        document.getElementById('main-wrapper').classList.toggle('sidebar-collapsed');
    });

    const overlay = document.getElementById('sidebarOverlay');
    document.getElementById('sidebarMobileToggle')?.addEventListener('click', () => {
        const sb = document.getElementById('sidebar');
        const open = sb.classList.toggle('mobile-open');
        overlay.style.display = open ? 'block' : 'none';
    });
    overlay?.addEventListener('click', () => {
        document.getElementById('sidebar')?.classList.remove('mobile-open');
        overlay.style.display = 'none';
    });
});
</script>
</body>
</html>
