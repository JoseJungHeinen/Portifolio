<?php
// @versao Proc_01 V_13_12/08/2026
/**
 * views/processos/list.php
 * Lista de Processos de NF
 *
 * Variáveis esperadas:
 *   $processos     array   lista de processos
 *   $total         int     total de registros
 *   $totais        array   [total, abertos, devolvidos, aprovados, aprovados_mes, aguardando_minha_aprovacao]
 *   $totalPgs      int     total de páginas
 *   $pagina        int     página atual
 *   $filtros       array   [busca, status, filial_id, fluxo_id, departamento_id, setor_id]
 *   $filiais       array   lista de filiais para o select
 *   $fluxos        array   templates com processos [id, nome]
 *   $departamentos array   itens da lista Departamentos [id, valor]
 *   $setores       array   setores ativos [id, nome, departamento_id, departamento_nome]
 *   $currentUser   array   [id, nome, perfil]
 *   $flash         array|null
 */

$processos     = $processos     ?? [];
$total         = $total         ?? 0;
$totais        = $totais        ?? [];
$totalPgs      = $totalPgs      ?? 1;
$pagina        = $pagina        ?? 1;
$filtros       = $filtros       ?? ['busca' => '', 'status' => 'todos', 'filial_id' => []];
$filiais       = $filiais       ?? [];
$fluxos        = $fluxos        ?? [];
$departamentos = $departamentos ?? [];
$setores       = $setores       ?? [];
$is_teste      = $is_teste      ?? false;

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Retorna estilos do badge de status do processo.
 */
function processoStatusBadge(string $status): string {
    $mapa = [
        'aberto'                  => ['bg' => '#E3F0FF', 'color' => '#1565C0', 'label' => 'Aberto'],
        'aprovado'                => ['bg' => '#D8F5E5', 'color' => '#00A960', 'label' => 'Finalizado'],
        'devolvido'               => ['bg' => '#FFF3E0', 'color' => '#FF8A2D', 'label' => 'Devolvido'],
        'cancelado'               => ['bg' => '#FFE8EE', 'color' => '#B3261E', 'label' => 'Cancelado'],
        'cancelamento_solicitado' => ['bg' => '#FFE8EE', 'color' => '#B3261E', 'label' => 'Canc. Solicitado'],
        'encerrado_apollo'        => ['bg' => '#D8D4E4', 'color' => '#606060', 'label' => 'Encerrado'],
    ];
    $s = $mapa[$status] ?? ['bg' => '#EAEAEA', 'color' => '#888888', 'label' => ucfirst($status)];
    return '<span style="display:inline-block; padding:2px 10px; border-radius:8px; font-size:10px; font-weight:700;'
         . ' background:' . $s['bg'] . '; color:' . $s['color'] . '">' . h($s['label']) . '</span>';
}
?>

<?php if (!empty($flash) && ($flash['type'] ?? '') === 'processo_criado' && !empty($flash['codigo'])): ?>
<div id="prc-confirm-modal" style="position:fixed;inset:0;background:rgba(24,19,31,0.62);z-index:9999;display:flex;align-items:center;justify-content:center;padding:24px">
  <div style="background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.22);padding:48px 40px 40px;max-width:460px;width:100%;text-align:center">
    <div style="margin-bottom:16px;display:flex;justify-content:center">
      <i data-lucide="check-circle" width="52" height="52" style="color:#00A960"></i>
    </div>
    <h2 style="font-size:22px;font-weight:700;color:#141922;margin:0 0 8px">Processo criado com sucesso!</h2>
    <p style="font-size:14px;color:#606060;margin:0 0 20px">Anote o número do processo para acompanhar o andamento:</p>
    <div style="font-size:34px;font-weight:700;color:#2347C8;letter-spacing:2px;background:#FFF5F5;border:2px solid #2347C8;border-radius:8px;padding:12px 24px;margin:0 auto 14px;display:inline-block;user-select:all"><?= h($flash['codigo']) ?></div>
    <p style="font-size:12px;color:#888;margin:0 0 28px">Use este número na busca para localizar o processo a qualquer momento.</p>
    <button onclick="document.getElementById('prc-confirm-modal').remove()" style="display:inline-flex;align-items:center;gap:8px;background:#2347C8;color:#fff;border:none;border-radius:4px;padding:12px 32px;font-size:15px;font-weight:700;cursor:pointer">
      <i data-lucide="check" width="18" height="18"></i>
      Entendido
    </button>
  </div>
</div>
<?php endif; ?>

<?php if (empty($flash) || ($flash['type'] ?? '') !== 'processo_criado'): ?>
<?php include VIEWS . '/components/alert.php'; ?>
<?php endif; ?>

<!-- Cabeçalho da página -->
<div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:28px; flex-wrap:wrap; gap:12px">
  <div>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
      Processos <span style="color:#2347C8">de NF</span>
    </h1>
    <p style="font-size:14px; color:#888; margin:0">Gerencie o workflow de notas fiscais</p>
  </div>
  <?php if (Permissions::can('processos', 'criar')): ?>
  <a href="?module=processos&amp;action=novo"
     style="display:inline-flex; align-items:center; gap:8px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; text-decoration:none; cursor:pointer">
    <i data-lucide="plus" width="16" height="16"></i>
    Novo Processo
  </a>
  <?php endif; ?>
</div>

<!-- Cards de totais -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:16px; margin-bottom:24px">

  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:20px; border-left:4px solid #606060">
    <div style="font-size:28px; font-weight:700; color:#141922"><?= $totais['total'] ?? 0 ?></div>
    <div style="font-size:13px; color:#888; margin-top:4px">Total</div>
  </div>

  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:20px; border-left:4px solid #1565C0">
    <div style="font-size:28px; font-weight:700; color:#1565C0"><?= $totais['abertos'] ?? 0 ?></div>
    <div style="font-size:13px; color:#888; margin-top:4px">Em andamento</div>
  </div>

  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:20px; border-left:4px solid #FF8A2D">
    <div style="font-size:28px; font-weight:700; color:#FF8A2D"><?= $totais['devolvidos'] ?? 0 ?></div>
    <div style="font-size:13px; color:#888; margin-top:4px">Devolvidos</div>
  </div>

  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:20px; border-left:4px solid #00A960">
    <div style="font-size:28px; font-weight:700; color:#00A960"><?= $totais['aprovados_mes'] ?? 0 ?></div>
    <div style="font-size:13px; color:#888; margin-top:4px">Aprovados este mês</div>
  </div>

  <?php if (($totais['aguardando_minha_aprovacao'] ?? 0) > 0): ?>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:20px; border-left:4px solid #2347C8">
    <div style="font-size:28px; font-weight:700; color:#2347C8"><?= $totais['aguardando_minha_aprovacao'] ?></div>
    <div style="font-size:13px; color:#888; margin-top:4px">Aguardam minha aprovação</div>
  </div>
  <?php endif; ?>

</div>

<!-- Filtros -->
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:16px 20px; margin-bottom:20px">
  <form method="get" action="" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap">
    <input type="hidden" name="module" value="processos">
    <input type="hidden" name="action" value="index">
    <?php if ($is_teste): ?><input type="hidden" name="testes" value="1"><?php endif; ?>

    <!-- Busca -->
    <div style="flex:1; min-width:220px; position:relative">
      <i data-lucide="search" width="16" height="16"
         style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#888; pointer-events:none"></i>
      <input type="text" name="q" value="<?= h($filtros['busca'] ?? '') ?>"
             placeholder="Buscar por código, fornecedor, CNPJ, NF, placa..."
             style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px 0 34px;
                    font-size:14px; color:#141922; box-sizing:border-box; outline:none"
             onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
    </div>

    <!-- Filial — multi-seleção; sem nada marcado vale "todas as que enxergo" -->
    <?php
    $flSufixo   = 'proc';
    $flOpcoes   = $filiais;
    $flSel      = (array) ($filtros['filial_id'] ?? []);
    $flVariante = 'inline';
    include VIEWS . '/components/filtro-lojas.php';
    ?>

    <!-- Status -->
    <select name="status"
            style="height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; min-width:160px; outline:none"
            onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      <option value="todos" <?= ($filtros['status'] ?? 'todos') === 'todos' ? 'selected' : '' ?>>Andamento</option>
      <option value="aberto"    <?= ($filtros['status'] ?? '') === 'aberto'    ? 'selected' : '' ?>>Aberto</option>
      <option value="devolvido" <?= ($filtros['status'] ?? '') === 'devolvido' ? 'selected' : '' ?>>Devolvido</option>
      <option value="cancelamento_solicitado" <?= ($filtros['status'] ?? '') === 'cancelamento_solicitado' ? 'selected' : '' ?>>Canc. Solicitado</option>
      <option value="aprovado"  <?= ($filtros['status'] ?? '') === 'aprovado'  ? 'selected' : '' ?>>Finalizado</option>
      <option value="encerrado_apollo" <?= ($filtros['status'] ?? '') === 'encerrado_apollo' ? 'selected' : '' ?>>Encerrado</option>
      <option value="cancelado" <?= ($filtros['status'] ?? '') === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
    </select>

    <!-- Fluxo (template do processo) -->
    <select name="fluxo_id" id="flt-fluxo"
            style="height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; min-width:200px; max-width:280px; outline:none"
            onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      <option value="">Todos os fluxos</option>
      <?php foreach ($fluxos as $fx): ?>
      <option value="<?= h($fx['id']) ?>" <?= ($filtros['fluxo_id'] ?? '') === $fx['id'] ? 'selected' : '' ?>><?= h($fx['nome']) ?></option>
      <?php endforeach; ?>
    </select>

    <!-- Departamento -->
    <select name="departamento_id" id="flt-depto" onchange="procFiltraSetores()"
            style="height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; min-width:180px; outline:none"
            onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      <option value="">Todos os departamentos</option>
      <?php foreach ($departamentos as $dp): ?>
      <option value="<?= h($dp['id']) ?>" <?= ($filtros['departamento_id'] ?? '') === $dp['id'] ? 'selected' : '' ?>><?= h($dp['valor']) ?></option>
      <?php endforeach; ?>
    </select>

    <!-- Setor (opções filtradas pelo departamento escolhido) -->
    <select name="setor_id" id="flt-setor"
            style="height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; min-width:180px; outline:none"
            onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      <option value="">Todos os setores</option>
      <?php foreach ($setores as $st): ?>
      <option value="<?= h($st['id']) ?>" data-depto="<?= h($st['departamento_id'] ?? '') ?>"
              <?= ($filtros['setor_id'] ?? '') === $st['id'] ? 'selected' : '' ?>><?= h($st['nome']) ?></option>
      <?php endforeach; ?>
    </select>

    <!-- Responsável (aprovador da etapa atual) -->
    <?php
    $responsaveis = $responsaveis ?? [];
    $selResp = isset($_GET['resp']) ? (array) $_GET['resp'] : [];
    $respAtivo = count($selResp) > 0;
    ?>
    <div style="position:relative" id="resp-wrap">
      <button type="button" onclick="procToggleDrop('resp-drop','resp-wrap')"
              style="height:40px; display:flex; align-items:center; gap:8px; padding:0 14px; border:1px solid <?= $respAtivo ? '#2347C8' : '#DFDFDF' ?>; border-radius:4px; background:<?= $respAtivo ? '#FFF5F5' : '#fff' ?>; font-size:14px; color:<?= $respAtivo ? '#2347C8' : '#141922' ?>; cursor:pointer; white-space:nowrap; font-weight:<?= $respAtivo ? '700' : '400' ?>">
        <?= $respAtivo ? 'Responsável (' . count($selResp) . ')' : 'Responsável' ?>
        <i data-lucide="chevron-down" width="13" height="13" style="color:#888"></i>
      </button>
      <div id="resp-drop" style="display:none; position:absolute; top:calc(100% + 5px); left:0; min-width:260px; background:#fff; border:1px solid #DFDFDF; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.1); z-index:50; overflow:hidden">
        <div style="max-height:240px; overflow-y:auto">
          <?php if (empty($responsaveis)): ?>
          <div style="padding:14px 16px; font-size:13px; color:#888">Nenhum processo em andamento.</div>
          <?php else: foreach ($responsaveis as $r): $rVal = $r['tipo'] . ':' . $r['id']; ?>
          <label style="display:flex; align-items:center; gap:10px; padding:11px 16px; font-size:14px; color:#374151; cursor:pointer"
                 onmouseover="this.style.background='#FFF5F5'" onmouseout="this.style.background=''">
            <input type="checkbox" name="resp[]" value="<?= h($rVal) ?>" <?= in_array($rVal, $selResp) ? 'checked' : '' ?>
                   style="width:16px; height:16px; accent-color:#2347C8; cursor:pointer">
            <span style="flex:1"><?= h($r['nome']) ?></span>
            <span style="font-size:9px; font-weight:700; text-transform:uppercase; padding:2px 7px; border-radius:8px; background:<?= $r['tipo'] === 'grupo' ? '#D8D4E4' : '#EAEAEA' ?>; color:<?= $r['tipo'] === 'grupo' ? '#2347C8' : '#606060' ?>"><?= $r['tipo'] === 'grupo' ? 'Grupo' : 'Usuário' ?></span>
          </label>
          <?php endforeach; endif; ?>
        </div>
        <div style="border-top:1px solid #F1F5F9; padding:10px 16px">
          <button type="submit" style="width:100%; padding:9px 0; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">Aplicar</button>
        </div>
      </div>
    </div>

    <button type="submit"
            style="height:40px; padding:0 20px; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">
      Filtrar
    </button>

    <?php
    $temFiltro = !empty($filtros['busca'])
        || !empty($filtros['filial_id'])
        || !empty($filtros['fluxo_id'])
        || !empty($filtros['departamento_id'])
        || !empty($filtros['setor_id'])
        || (($filtros['status'] ?? 'todos') !== 'todos')
        || $respAtivo;
    ?>
    <?php if ($temFiltro): ?>
    <a href="?module=processos&amp;action=index<?= $is_teste ? '&testes=1' : '' ?>"
       style="height:40px; padding:0 16px; background:#EAEAEA; color:#606060; border-radius:4px; font-size:14px; font-weight:700; display:inline-flex; align-items:center; text-decoration:none">
      Limpar
    </a>
    <?php endif; ?>

    <?php /* Botão "Mostrar testes" removido em 01/08/2026 (GoLive). O modo continua
             acessível por ?testes=1 na URL, restrito a super_admin no controller. */ ?>
  </form>
</div>

<!-- Tabela -->
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden">

  <?php if (empty($processos)): ?>
  <?php
  $emptyIcon    = 'file-text';
  $emptyTitle   = 'Nenhum processo encontrado';
  $emptyMessage = $temFiltro
      ? 'Tente ajustar os filtros de busca.'
      : 'Crie seu primeiro processo de nota fiscal.';
  $emptyAction  = (!$temFiltro && Permissions::can('processos', 'criar'))
      ? ['label' => 'Novo Processo', 'url' => '?module=processos&action=novo', 'icon' => 'plus']
      : null;
  include VIEWS . '/components/empty-state.php';
  ?>

  <?php else: ?>
  <div style="overflow-x:auto">
    <table style="width:100%; border-collapse:collapse; font-size:13px">
      <thead>
        <tr style="background:#606060; color:#fff">
          <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase; white-space:nowrap">Código</th>
          <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase">Fornecedor</th>
          <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase; white-space:nowrap">Nº NF</th>
          <th style="padding:12px 16px; text-align:right; font-weight:700; font-size:13px; text-transform:uppercase; white-space:nowrap">Valor</th>
          <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase">Filial</th>
          <th style="padding:12px 16px; text-align:center; font-weight:700; font-size:13px; text-transform:uppercase">Etapa</th>
          <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase">Responsável</th>
          <th style="padding:12px 16px; text-align:center; font-weight:700; font-size:13px; text-transform:uppercase">Status</th>
          <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase; white-space:nowrap">Abertura</th>
          <th style="padding:12px 16px; text-align:center; font-weight:700; font-size:13px; text-transform:uppercase">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($processos as $i => $proc): ?>
        <tr onclick="window.location='<?= h(linkDetalheProcesso($proc['id'], 'processos')) ?>'"
            style="background:<?= $i % 2 === 0 ? '#FFFFFF' : '#F8F8F8' ?>;
                   cursor:pointer;
                   transition:background 0.15s"
            onmouseover="this.style.background='#EEF2FD'"
            onmouseout="this.style.background='<?= $i % 2 === 0 ? '#FFFFFF' : '#F8F8F8' ?>'">

          <!-- Código -->
          <td style="padding:12px 16px; white-space:nowrap">
            <a href="<?= h(linkDetalheProcesso($proc['id'], 'processos')) ?>"
               style="font-weight:700; color:#2347C8; text-decoration:none">
              <?= h($proc['codigo']) ?>
            </a>
            <?php if ($proc['is_teste']): ?>
            <span style="display:inline-block; padding:1px 6px; border-radius:8px; font-size:10px; font-weight:700; background:#FFF3CD; color:#856404; margin-left:4px">TESTE</span>
            <?php endif; ?>
          </td>

          <!-- Fornecedor -->
          <td style="padding:12px 16px; color:#141922; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
            <?= h($proc['fornecedor_nome']) ?>
          </td>

          <!-- Nº NF (processo sem nota: numero_nf e valor_nf ficam NULL) -->
          <td style="padding:12px 16px; color:#606060">
            <?php if (!empty($proc['sem_nota'])): ?>
            <span style="font-size:10px; font-weight:700; background:#EAEAEA; color:#888; padding:2px 8px; border-radius:8px" title="Processo aberto sem nota fiscal">SEM NOTA</span>
            <?php else: ?>
            <?= h($proc['numero_nf']) ?>
            <?php endif; ?>
          </td>

          <!-- Valor -->
          <td style="padding:12px 16px; text-align:right; font-weight:700; color:#141922; white-space:nowrap">
            <?php if ($proc['valor_nf'] === null): ?>
            <span style="color:#888; font-weight:400">—</span>
            <?php else: ?>
            R$ <?= number_format((float) $proc['valor_nf'], 2, ',', '.') ?>
            <?php endif; ?>
          </td>

          <!-- Filial -->
          <td style="padding:12px 16px; color:#606060; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
            <?= h($proc['filial_nome'] ?? '—') ?>
          </td>

          <!-- Etapa -->
          <td style="padding:12px 16px; text-align:center; color:#606060">
            <?= (int)$proc['etapa_atual'] ?>/<?= (int)$proc['total_etapas'] ?>
          </td>

          <!-- Responsável (aprovador da etapa atual; encerrado não tem) -->
          <td style="padding:12px 16px; max-width:190px">
            <?php if (in_array($proc['status'], ['aberto','devolvido','cancelamento_solicitado'], true) && !empty($proc['responsavel_nome'])): ?>
            <div style="font-size:12.5px; font-weight:600; color:#141922; overflow:hidden; text-overflow:ellipsis; white-space:nowrap"
                 title="<?= h($proc['responsavel_nome']) ?><?= !empty($proc['etapa_nome']) ? ' — ' . h($proc['etapa_nome']) : '' ?>">
              <?php if (($proc['tipo_aprovador'] ?? '') === 'grupo'): ?>
              <span style="font-size:9px; font-weight:700; background:#D8D4E4; color:#2347C8; padding:1px 5px; border-radius:6px; margin-right:3px">GRUPO</span>
              <?php endif; ?>
              <?= h($proc['responsavel_nome']) ?>
            </div>
            <?php if (!empty($proc['etapa_nome'])): ?>
            <div style="font-size:11px; color:#888; overflow:hidden; text-overflow:ellipsis; white-space:nowrap"><?= h($proc['etapa_nome']) ?></div>
            <?php endif; ?>
            <?php else: ?>
            <span style="font-size:12px; color:#888">—</span>
            <?php endif; ?>
          </td>

          <!-- Status -->
          <td style="padding:12px 16px; text-align:center">
            <?= processoStatusBadge($proc['status']) ?>
          </td>

          <!-- Data abertura -->
          <td style="padding:12px 16px; color:#888; white-space:nowrap; font-size:12px">
            <?= date('d/m/Y', strtotime($proc['criado_em'])) ?>
          </td>

          <!-- Ações -->
          <td style="padding:12px 16px; text-align:center; white-space:nowrap" onclick="event.stopPropagation()">
            <a href="<?= h(linkDetalheProcesso($proc['id'], 'processos')) ?>"
               title="Ver processo"
               style="display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:4px; background:#F6F6F6; color:#606060; text-decoration:none; transition:background 0.15s"
               onmouseover="this.style.background='#EAEAEA'" onmouseout="this.style.background='#F6F6F6'">
              <i data-lucide="eye" width="16" height="16"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Rodapé com contagem e paginação -->
  <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-top:1px solid #EAEAEA; flex-wrap:wrap; gap:12px">
    <span style="font-size:13px; color:#888">
      Exibindo <?= count($processos) ?> de <?= $total ?> processos
    </span>

    <?php if ($totalPgs > 1): ?>
    <?php
    $currentPage = $pagina;
    $totalPages  = $totalPgs;
    $module      = 'processos';
    $action      = 'index';
    $extraParams = array_filter([
        'q'               => $filtros['busca'] ?? '',
        'status'          => ($filtros['status'] ?? 'todos') !== 'todos' ? $filtros['status'] : '',
        'filial_id'       => !empty($filtros['filial_id']) ? (array) $filtros['filial_id'] : '',
        'fluxo_id'        => $filtros['fluxo_id'] ?? '',
        'departamento_id' => $filtros['departamento_id'] ?? '',
        'setor_id'        => $filtros['setor_id'] ?? '',
        'testes'          => $is_teste ? '1' : '',
        'resp'            => !empty($selResp) ? $selResp : '',
    ]);
    include VIEWS . '/components/pagination.php';
    ?>
    <?php endif; ?>
  </div>

  <?php endif; ?>
</div>

<script>
// Cascata Departamento → Setor: escolher um departamento deixa no select de
// setor só os setores dele (e limpa a seleção incompatível).
function procFiltraSetores() {
  var dep = document.getElementById('flt-depto');
  var set = document.getElementById('flt-setor');
  if (!dep || !set) return;
  var alvo = dep.value;
  for (var i = 0; i < set.options.length; i++) {
    var opt = set.options[i];
    if (!opt.value) continue;
    var mostra = !alvo || opt.getAttribute('data-depto') === alvo;
    opt.hidden = !mostra;
    opt.disabled = !mostra;
    if (!mostra && opt.selected) set.value = '';
  }
}
procFiltraSetores();

// Dropdown do multi-select Responsável (mesmo comportamento do Financeiro)
function procToggleDrop(dropId, wrapId) {
  var drop = document.getElementById(dropId);
  var open = drop.style.display === 'block';
  drop.style.display = open ? 'none' : 'block';
  if (!open) {
    document.addEventListener('click', function fecha(e) {
      if (!document.getElementById(wrapId).contains(e.target)) {
        drop.style.display = 'none';
        document.removeEventListener('click', fecha);
      }
    });
  }
}
</script>
