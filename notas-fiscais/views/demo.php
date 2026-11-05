<?php /* @versao Sys_28 V_01_16/07/2026 */ ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Design System — Exemplo financeiro</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/design-system.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/layout.css">
  <!-- Lucide Icons — cópia local para evitar dependência de CDN -->
  <script src="<?= BASE_URL ?>/public/assets/js/vendor/lucide.min.js"></script>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════════════════════════ -->
<aside class="sidebar" id="main-sidebar">

  <!-- Logo -->
  <a href="#" class="sidebar__logo">
    <div class="sidebar__logo-icon">
      <div class="sidebar__logo-symbol">B</div>
    </div>
    <div class="sidebar__logo-text">
      <span class="sidebar__logo-brand">Exemplo</span>
      <span class="sidebar__logo-vertical">financeiro</span>
    </div>
  </a>

  <!-- Nav -->
  <nav class="sidebar__nav">
    <a href="#" class="sidebar__link">
      <span class="sidebar__link-icon"><i data-lucide="layout-dashboard" width="20" height="20"></i></span>
      <span class="sidebar__label">Dashboard</span>
    </a>
    <a href="#" class="sidebar__link is-active">
      <span class="sidebar__link-icon"><i data-lucide="file-text" width="20" height="20"></i></span>
      <span class="sidebar__label">Processos</span>
    </a>
    <a href="#" class="sidebar__link">
      <span class="sidebar__link-icon"><i data-lucide="check-square" width="20" height="20"></i></span>
      <span class="sidebar__label">Minhas Tarefas</span>
    </a>
    <a href="#" class="sidebar__link">
      <span class="sidebar__link-icon"><i data-lucide="credit-card" width="20" height="20"></i></span>
      <span class="sidebar__label">Controle Financeiro</span>
    </a>
    <a href="#" class="sidebar__link">
      <span class="sidebar__link-icon"><i data-lucide="bar-chart-2" width="20" height="20"></i></span>
      <span class="sidebar__label">Relatórios</span>
    </a>

    <hr class="sidebar__divider">
    <span class="sidebar__group-label">Configuração</span>

    <a href="#" class="sidebar__link">
      <span class="sidebar__link-icon"><i data-lucide="copy" width="20" height="20"></i></span>
      <span class="sidebar__label">Templates</span>
    </a>
    <a href="#" class="sidebar__link">
      <span class="sidebar__link-icon"><i data-lucide="users" width="20" height="20"></i></span>
      <span class="sidebar__label">Grupos</span>
    </a>
    <a href="#" class="sidebar__link">
      <span class="sidebar__link-icon"><i data-lucide="table" width="20" height="20"></i></span>
      <span class="sidebar__label">Tabelas</span>
    </a>
  </nav>

  <!-- Rodapé usuário -->
  <div class="sidebar__footer">
    <div class="sidebar__user-avatar">JS</div>
    <div class="sidebar__user-info">
      <div class="sidebar__user-name">João Silva</div>
      <div class="sidebar__user-role">Gestor</div>
    </div>
  </div>

</aside>

<!-- ═══════════════════════════════════════════════════════════
     TOPBAR
════════════════════════════════════════════════════════════ -->
<header class="topbar">
  <div class="topbar__left">
    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
      <i data-lucide="menu" width="20" height="20"></i>
    </button>
    <h1 class="topbar__title">Design System</h1>
  </div>
  <div class="topbar__right">
    <button class="topbar__notif" aria-label="Notificações">
      <i data-lucide="bell" width="20" height="20"></i>
      <span class="topbar__notif-badge">3</span>
    </button>
    <div class="topbar__separator"></div>
    <a href="#" class="topbar__user">
      <div class="topbar__user-avatar">JS</div>
      <span class="topbar__user-name">João</span>
      <i data-lucide="chevron-down" width="16" height="16" style="color:#888888;"></i>
    </a>
    <div class="topbar__separator"></div>
    <a href="#" class="btn btn-tertiary btn-sm">
      <i data-lucide="log-out" width="16" height="16"></i>
    </a>
  </div>
</header>

<!-- ═══════════════════════════════════════════════════════════
     CONTEÚDO PRINCIPAL
════════════════════════════════════════════════════════════ -->
<main class="main-content">

  <!-- Título bicolor obrigatório -->
  <div class="page-header">
    <div class="page-header__left">
      <h1 class="page-header__title">
        Design System <span style="color:#2347C8;">Exemplo</span>
      </h1>
      <p class="page-header__subtitle">Demonstração de todos os componentes — paleta, tipografia, botões, cards, tabelas, formulários e mais.</p>
    </div>
    <div class="page-header__actions">
      <button class="btn btn-tertiary">Cancelar</button>
      <button class="btn btn-primary">
        <i data-lucide="plus" width="16" height="16"></i>
        Novo Processo
      </button>
    </div>
  </div>

  <!-- ─── ALERTAS ─────────────────────────────────────────────── -->
  <section class="mb-6">
    <h2 class="h2 mb-4">Alertas</h2>
    <div class="d-flex flex-col gap-3">
      <div class="alert alert-success">
        <span class="alert-icon"><i data-lucide="check-circle" width="18" height="18"></i></span>
        <div class="alert-content">
          <div class="alert-title">Sucesso!</div>
          <div>Processo criado com sucesso e enviado para aprovação.</div>
        </div>
        <button class="alert-dismiss" aria-label="Fechar"><i data-lucide="x" width="16" height="16"></i></button>
      </div>
      <div class="alert alert-warning">
        <span class="alert-icon"><i data-lucide="alert-triangle" width="18" height="18"></i></span>
        <div class="alert-content">
          <div class="alert-title">Atenção</div>
          <div>Prazo de aprovação expira em 2 dias.</div>
        </div>
        <button class="alert-dismiss" aria-label="Fechar"><i data-lucide="x" width="16" height="16"></i></button>
      </div>
      <div class="alert alert-danger">
        <span class="alert-icon"><i data-lucide="x-circle" width="18" height="18"></i></span>
        <div class="alert-content">
          <div class="alert-title">Erro</div>
          <div>Não foi possível processar a nota fiscal. Verifique os dados e tente novamente.</div>
        </div>
        <button class="alert-dismiss" aria-label="Fechar"><i data-lucide="x" width="16" height="16"></i></button>
      </div>
      <div class="alert alert-info">
        <span class="alert-icon"><i data-lucide="info" width="18" height="18"></i></span>
        <div class="alert-content">
          <div>Informativo: sistema em manutenção programada às 23h.</div>
        </div>
        <button class="alert-dismiss" aria-label="Fechar"><i data-lucide="x" width="16" height="16"></i></button>
      </div>
    </div>
  </section>

  <!-- ─── CARDS STAT ──────────────────────────────────────────── -->
  <section class="mb-6">
    <h2 class="h2 mb-4">Cards de Estatística</h2>
    <div class="stats-grid">
      <div class="stat-card card-accent-primary">
        <div class="stat-card__label">Total de Processos</div>
        <div class="stat-card__value">147</div>
        <div class="stat-card__trend stat-card__trend--up">
          <i data-lucide="trending-up" width="12" height="12"></i>
          +12% este mês
        </div>
      </div>
      <div class="stat-card card-accent-warning">
        <div class="stat-card__label">Aguardando Aprovação</div>
        <div class="stat-card__value">23</div>
        <div class="stat-card__trend">Prazo médio: 3 dias</div>
      </div>
      <div class="stat-card card-accent-success">
        <div class="stat-card__label">Concluídos no Mês</div>
        <div class="stat-card__value">89</div>
        <div class="stat-card__trend stat-card__trend--up">
          <i data-lucide="trending-up" width="12" height="12"></i>
          +8% vs mês anterior
        </div>
      </div>
      <div class="stat-card card-accent-danger">
        <div class="stat-card__label">Vencidos / Críticos</div>
        <div class="stat-card__value">4</div>
        <div class="stat-card__trend stat-card__trend--down">
          <i data-lucide="alert-triangle" width="12" height="12"></i>
          Requer atenção
        </div>
      </div>
    </div>
  </section>

  <!-- ─── BADGES ──────────────────────────────────────────────── -->
  <section class="mb-6">
    <h2 class="h2 mb-4">Badges / Status</h2>
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <span class="badge badge-success"><i data-lucide="check" width="10" height="10"></i> Aprovado</span>
      <span class="badge badge-warning"><i data-lucide="clock" width="10" height="10"></i> Aguardando</span>
      <span class="badge badge-danger"><i data-lucide="x" width="10" height="10"></i> Reprovado</span>
      <span class="badge badge-muted">Rascunho</span>
      <span class="badge badge-primary">Em Análise</span>
      <span class="badge badge-accent">Novo</span>
    </div>
  </section>

  <!-- ─── BOTÕES ───────────────────────────────────────────────── -->
  <section class="mb-6">
    <h2 class="h2 mb-4">Botões</h2>

    <h4 class="h4 mb-3" style="color:#888888;">Variantes</h4>
    <div class="d-flex align-items-center gap-3 flex-wrap mb-4">
      <button class="btn btn-primary">
        <i data-lucide="plus" width="16" height="16"></i>
        Primário
      </button>
      <button class="btn btn-secondary">
        <i data-lucide="edit" width="16" height="16"></i>
        Secundário
      </button>
      <button class="btn btn-tertiary">
        <i data-lucide="arrow-left" width="16" height="16"></i>
        Terciário
      </button>
      <button class="btn btn-danger">
        <i data-lucide="trash-2" width="16" height="16"></i>
        Destrutivo
      </button>
      <button class="btn btn-primary" disabled>Desabilitado</button>
    </div>

    <h4 class="h4 mb-3" style="color:#888888;">Tamanhos</h4>
    <div class="d-flex align-items-center gap-3 flex-wrap mb-4">
      <button class="btn btn-primary btn-sm">Pequeno</button>
      <button class="btn btn-primary">Médio (padrão)</button>
      <button class="btn btn-primary btn-lg">Grande</button>
    </div>

    <h4 class="h4 mb-3" style="color:#888888;">Botões com ícone apenas</h4>
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-primary btn-icon" title="Visualizar"><i data-lucide="eye" width="16" height="16"></i></button>
      <button class="btn btn-secondary btn-icon" title="Editar"><i data-lucide="edit" width="16" height="16"></i></button>
      <button class="btn btn-tertiary btn-icon" title="Download"><i data-lucide="download" width="16" height="16"></i></button>
      <button class="btn btn-danger btn-icon" title="Excluir"><i data-lucide="trash-2" width="16" height="16"></i></button>
    </div>
  </section>

  <!-- ─── TABELA ───────────────────────────────────────────────── -->
  <section class="mb-6">
    <div class="d-flex align-items-center justify-content-between mb-4">
      <h2 class="h2">Tabela de Processos</h2>
      <button class="btn btn-primary btn-sm">
        <i data-lucide="plus" width="14" height="14"></i>
        Novo
      </button>
    </div>

    <div class="filter-period mb-4">
      <button type="button" class="filter-period-btn" data-period="week">Esta semana</button>
      <button type="button" class="filter-period-btn is-active" data-period="month">Este mês</button>
      <button type="button" class="filter-period-btn" data-period="quarter">Este trimestre</button>
      <button type="button" class="filter-period-btn" data-period="custom">Personalizado</button>
      <div class="filter-period-custom">
        <input type="date" class="form-input" style="width:150px;" value="2026-06-01">
        <span style="color:#888888;font-size:13px;">até</span>
        <input type="date" class="form-input" style="width:150px;" value="2026-06-08">
        <button type="button" class="btn btn-primary btn-sm">
          <i data-lucide="search" width="14" height="14"></i>
          Filtrar
        </button>
      </div>
    </div>

    <div class="table-wrapper">
      <table class="table">
        <thead>
          <tr>
            <th>Nº / Referência</th>
            <th>Fornecedor</th>
            <th>Valor</th>
            <th>Vencimento</th>
            <th>Status</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><strong>NF-2026-001</strong><br><span class="text-muted text-caption">Ref: OC-4521</span></td>
            <td>Distribuidora ABC Ltda</td>
            <td><strong>R$ 12.450,00</strong></td>
            <td>15/06/2026</td>
            <td><span class="badge badge-warning">Aguardando</span></td>
            <td>
              <div class="table-actions">
                <button class="btn btn-tertiary btn-icon btn-sm" title="Visualizar" data-modal-open="modal-demo">
                  <i data-lucide="eye" width="14" height="14"></i>
                </button>
                <button class="btn btn-secondary btn-icon btn-sm" title="Editar">
                  <i data-lucide="edit" width="14" height="14"></i>
                </button>
                <button class="btn btn-danger btn-icon btn-sm" title="Excluir" data-confirm="Excluir este processo?">
                  <i data-lucide="trash-2" width="14" height="14"></i>
                </button>
              </div>
            </td>
          </tr>
          <tr>
            <td><strong>NF-2026-002</strong><br><span class="text-muted text-caption">Ref: OC-4398</span></td>
            <td>Fornecedora XYZ S.A.</td>
            <td><strong>R$ 3.820,50</strong></td>
            <td>20/06/2026</td>
            <td><span class="badge badge-success">Aprovado</span></td>
            <td>
              <div class="table-actions">
                <button class="btn btn-tertiary btn-icon btn-sm" title="Visualizar" data-modal-open="modal-demo">
                  <i data-lucide="eye" width="14" height="14"></i>
                </button>
                <button class="btn btn-secondary btn-icon btn-sm" title="Editar">
                  <i data-lucide="edit" width="14" height="14"></i>
                </button>
                <button class="btn btn-danger btn-icon btn-sm" title="Excluir" data-confirm="Excluir este processo?">
                  <i data-lucide="trash-2" width="14" height="14"></i>
                </button>
              </div>
            </td>
          </tr>
          <tr>
            <td><strong>NF-2026-003</strong><br><span class="text-muted text-caption">Ref: OC-4201</span></td>
            <td>Global Parts Comércio</td>
            <td><strong>R$ 78.900,00</strong></td>
            <td>02/06/2026</td>
            <td><span class="badge badge-danger">Vencido</span></td>
            <td>
              <div class="table-actions">
                <button class="btn btn-tertiary btn-icon btn-sm" title="Visualizar" data-modal-open="modal-demo">
                  <i data-lucide="eye" width="14" height="14"></i>
                </button>
                <button class="btn btn-secondary btn-icon btn-sm" title="Editar">
                  <i data-lucide="edit" width="14" height="14"></i>
                </button>
                <button class="btn btn-danger btn-icon btn-sm" title="Excluir" data-confirm="Excluir este processo?">
                  <i data-lucide="trash-2" width="14" height="14"></i>
                </button>
              </div>
            </td>
          </tr>
          <tr>
            <td><strong>NF-2026-004</strong><br><span class="text-muted text-caption">Ref: OC-4099</span></td>
            <td>Peças & Cia Distribuidora</td>
            <td><strong>R$ 5.600,00</strong></td>
            <td>30/06/2026</td>
            <td><span class="badge badge-muted">Rascunho</span></td>
            <td>
              <div class="table-actions">
                <button class="btn btn-tertiary btn-icon btn-sm" title="Visualizar" data-modal-open="modal-demo">
                  <i data-lucide="eye" width="14" height="14"></i>
                </button>
                <button class="btn btn-secondary btn-icon btn-sm" title="Editar">
                  <i data-lucide="edit" width="14" height="14"></i>
                </button>
                <button class="btn btn-danger btn-icon btn-sm" title="Excluir" data-confirm="Excluir este processo?">
                  <i data-lucide="trash-2" width="14" height="14"></i>
                </button>
              </div>
            </td>
          </tr>
          <tr>
            <td><strong>NF-2026-005</strong><br><span class="text-muted text-caption">Ref: OC-3987</span></td>
            <td>Auto Suprimentos ME</td>
            <td><strong>R$ 1.250,75</strong></td>
            <td>25/06/2026</td>
            <td><span class="badge badge-primary">Em Análise</span></td>
            <td>
              <div class="table-actions">
                <button class="btn btn-tertiary btn-icon btn-sm" title="Visualizar" data-modal-open="modal-demo">
                  <i data-lucide="eye" width="14" height="14"></i>
                </button>
                <button class="btn btn-secondary btn-icon btn-sm" title="Editar">
                  <i data-lucide="edit" width="14" height="14"></i>
                </button>
                <button class="btn btn-danger btn-icon btn-sm" title="Excluir" data-confirm="Excluir este processo?">
                  <i data-lucide="trash-2" width="14" height="14"></i>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Paginação demo -->
    <nav class="pagination mt-4">
      <span class="pagination-item is-disabled"><i data-lucide="chevron-left" width="14" height="14"></i></span>
      <span class="pagination-item is-active">1</span>
      <a href="#" class="pagination-item">2</a>
      <a href="#" class="pagination-item">3</a>
      <span class="pagination-ellipsis">…</span>
      <a href="#" class="pagination-item">8</a>
      <a href="#" class="pagination-item"><i data-lucide="chevron-right" width="14" height="14"></i></a>
    </nav>
  </section>

  <!-- ─── CARDS ────────────────────────────────────────────────── -->
  <section class="mb-6">
    <h2 class="h2 mb-4">Cards com Acento</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">

      <div class="card card-accent-success">
        <div class="card-header">
          <h3 class="h3">Aprovado</h3>
          <span class="badge badge-success">Concluído</span>
        </div>
        <div class="card-body">
          <p class="text-muted">NF-2026-002 — Fornecedora XYZ</p>
          <p class="mt-2"><strong>R$ 3.820,50</strong></p>
        </div>
        <div class="card-footer">
          <button class="btn btn-secondary btn-sm">Ver detalhes</button>
        </div>
      </div>

      <div class="card card-accent-warning">
        <div class="card-header">
          <h3 class="h3">Aguardando</h3>
          <span class="badge badge-warning">Pendente</span>
        </div>
        <div class="card-body">
          <p class="text-muted">NF-2026-001 — Distribuidora ABC</p>
          <p class="mt-2"><strong>R$ 12.450,00</strong></p>
        </div>
        <div class="card-footer">
          <button class="btn btn-primary btn-sm">Aprovar</button>
          <button class="btn btn-tertiary btn-sm">Recusar</button>
        </div>
      </div>

      <div class="card card-accent-danger">
        <div class="card-header">
          <h3 class="h3">Vencido</h3>
          <span class="badge badge-danger">Crítico</span>
        </div>
        <div class="card-body">
          <p class="text-muted">NF-2026-003 — Global Parts</p>
          <p class="mt-2"><strong>R$ 78.900,00</strong></p>
        </div>
        <div class="card-footer">
          <button class="btn btn-danger btn-sm">Resolver</button>
        </div>
      </div>

    </div>
  </section>

  <!-- ─── FORMULÁRIO ───────────────────────────────────────────── -->
  <section class="mb-6">
    <h2 class="h2 mb-4">Formulário</h2>
    <div class="card" style="max-width:680px;">
      <div class="card-header">
        <h3 class="h3">Novo Processo de NF</h3>
      </div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group">
            <label class="form-label required">Fornecedor</label>
            <select class="form-select">
              <option value="">Selecione o fornecedor</option>
              <option>Distribuidora ABC Ltda</option>
              <option>Fornecedora XYZ S.A.</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label required">Valor da NF</label>
            <input type="text" class="form-input" placeholder="R$ 0,00">
          </div>
          <div class="form-group">
            <label class="form-label required">Data de Emissão</label>
            <input type="date" class="form-input" value="2026-06-08">
          </div>
          <div class="form-group">
            <label class="form-label required">Data de Vencimento</label>
            <input type="date" class="form-input">
          </div>
          <div class="form-group" style="grid-column:1/-1;">
            <label class="form-label">Observações</label>
            <textarea class="form-textarea" rows="3" placeholder="Notas adicionais sobre este processo..."></textarea>
          </div>
          <!-- Estado de erro -->
          <div class="form-group">
            <label class="form-label">Campo com erro</label>
            <input type="text" class="form-input is-error" value="valor inválido">
            <span class="form-error">
              <i data-lucide="alert-circle" width="12" height="12"></i>
              Este campo é obrigatório.
            </span>
          </div>
          <!-- Campo desabilitado -->
          <div class="form-group">
            <label class="form-label">Campo desabilitado</label>
            <input type="text" class="form-input" value="Apenas leitura" disabled>
            <span class="form-hint">Este campo não pode ser editado.</span>
          </div>
        </div>
      </div>
      <div class="card-footer" style="justify-content:flex-end;gap:12px;">
        <button class="btn btn-tertiary">Cancelar</button>
        <button class="btn btn-secondary">Salvar rascunho</button>
        <button class="btn btn-primary">
          <i data-lucide="send" width="16" height="16"></i>
          Enviar para aprovação
        </button>
      </div>
    </div>
  </section>

  <!-- ─── PALETA DE CORES ──────────────────────────────────────── -->
  <section class="mb-6">
    <h2 class="h2 mb-4">Paleta de Cores</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;">
      <?php
      $colors = [
        ['#2347C8','Vermelho Exemplo','--color-primary','#FFF'],
        ['#1B38A0','Vermelho Hover','--color-primary-hover','#FFF'],
        ['#141922','Preto Exemplo','--color-dark','#FFF'],
        ['#606060','Cinza Institucional','--color-gray-dark','#FFF'],
        ['#888888','Cinza Médio','--color-gray-medium','#FFF'],
        ['#EAEAEA','Cinza Claro','--color-gray-light','#141922'],
        ['#FFFFFF','Branco Surface','--color-surface','#141922'],
        ['#F6F6F6','Off-white BG','--color-bg','#141922'],
        ['#7A4FD0','Amarelo Exemplo','--color-accent','#141922'],
        ['#D8D4E4','Tag BG','--color-tag-bg','#141922'],
        ['#00A960','Verde Sucesso','--color-success','#FFF'],
        ['#FF8A2D','Laranja Alerta','--color-warning','#FFF'],
        ['#B3261E','Rosa Perigo','--color-danger','#FFF'],
      ];
      foreach ($colors as [$hex, $name, $token, $text]): ?>
      <div style="border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.10);">
        <div style="background:<?= $hex ?>;height:64px;display:flex;align-items:center;justify-content:center;">
          <span style="color:<?= $text ?>;font-family:'Segoe UI',sans-serif;font-size:13px;font-weight:700;"><?= $hex ?></span>
        </div>
        <div style="background:#fff;padding:8px 10px;">
          <div style="font-size:12px;font-weight:700;color:#141922;"><?= $name ?></div>
          <div style="font-size:11px;color:#888888;"><?= $token ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ─── MODAL (botão de abertura) ───────────────────────────── -->
  <section class="mb-6">
    <h2 class="h2 mb-4">Modal</h2>
    <p class="text-muted mb-4">Clique no botão abaixo para testar o modal.</p>
    <button class="btn btn-primary" data-modal-open="modal-demo">
      <i data-lucide="external-link" width="16" height="16"></i>
      Abrir modal de exemplo
    </button>
  </section>

  <!-- ─── EMPTY STATE ──────────────────────────────────────────── -->
  <section class="mb-6">
    <h2 class="h2 mb-4">Empty State</h2>
    <div class="card">
      <div class="empty-state">
        <div class="empty-state-icon">
          <i data-lucide="file-x" width="64" height="64" style="color:#EAEAEA;"></i>
        </div>
        <div class="empty-state-title">Nenhum processo encontrado</div>
        <div class="empty-state-text">
          Não há processos que correspondam aos filtros aplicados. Tente ajustar os critérios de busca ou crie um novo processo.
        </div>
        <button class="btn btn-primary">
          <i data-lucide="plus" width="16" height="16"></i>
          Criar primeiro processo
        </button>
      </div>
    </div>
  </section>

  <!-- ─── SPINNER ──────────────────────────────────────────────── -->
  <section class="mb-6">
    <h2 class="h2 mb-4">Loading / Spinner</h2>
    <div class="d-flex align-items-center gap-5">
      <div>
        <div class="spinner spinner-sm"></div>
        <div class="text-caption text-muted mt-2">Pequeno</div>
      </div>
      <div>
        <div class="spinner"></div>
        <div class="text-caption text-muted mt-2">Padrão</div>
      </div>
      <div>
        <div class="spinner spinner-lg"></div>
        <div class="text-caption text-muted mt-2">Grande</div>
      </div>
    </div>
  </section>

</main>

<!-- ═══════════════════════════════════════════════════════════
     MODAL DE DEMONSTRAÇÃO
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-demo" role="dialog" aria-modal="true" aria-labelledby="modal-demo-title">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="modal-demo-title">
        Detalhes do <span style="color:#2347C8;">Processo</span>
      </h3>
      <button class="modal-close" data-modal-close aria-label="Fechar modal">
        <i data-lucide="x" width="20" height="20"></i>
      </button>
    </div>
    <div class="modal-body">
      <div style="display:grid;gap:12px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div>
            <div class="form-label">Número / Referência</div>
            <div style="font-family:'Segoe UI',sans-serif;font-weight:700;color:#141922;">NF-2026-001</div>
          </div>
          <div>
            <div class="form-label">Status</div>
            <span class="badge badge-warning">Aguardando Aprovação</span>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div>
            <div class="form-label">Fornecedor</div>
            <div>Distribuidora ABC Ltda</div>
          </div>
          <div>
            <div class="form-label">Valor</div>
            <div style="font-weight:700;">R$ 12.450,00</div>
          </div>
        </div>
        <div>
          <div class="form-label">Observações</div>
          <div class="text-muted">Nenhuma observação registrada.</div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-tertiary" data-modal-close>Fechar</button>
      <button class="btn btn-danger">
        <i data-lucide="x-circle" width="16" height="16"></i>
        Reprovar
      </button>
      <button class="btn btn-primary">
        <i data-lucide="check-circle" width="16" height="16"></i>
        Aprovar
      </button>
    </div>
  </div>
</div>

<!-- JS -->
<script src="<?= BASE_URL ?>/public/assets/js/app.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof lucide !== 'undefined') lucide.createIcons();
  });
</script>

</body>
</html>
