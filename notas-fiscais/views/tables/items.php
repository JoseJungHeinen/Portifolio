<?php
// @versao Tab_03 V_08_19/08/2026
/**
 * views/tables/items.php — Itens de uma lista de referência
 *
 * Variáveis: $lista, $itens, $total, $totalAtivos, $currentPage, $totalPages, $filters, $currentUser
 */

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$lista         = $lista         ?? [];
$itens         = $itens         ?? [];
$total         = $total         ?? 0;
$totalAtivos   = $totalAtivos   ?? 0;
$currentPage   = $currentPage   ?? 1;
$totalPages    = $totalPages    ?? 1;
$filters       = $filters       ?? ['status' => '', 'q' => ''];
$currentStatus = $filters['status'] ?? '';
$currentQ      = $filters['q']      ?? '';
$isPersonalizada = ($lista['tipo'] ?? '') === 'personalizada';

/** Setores: vínculo com departamento (coluna + seletor nos modais) */
$departamentos = $departamentos ?? [];
$marcacaoDepto = $marcacaoDepto ?? [];
// Dois vínculos distintos, ambos com departamento:
//  · Setores       → UM departamento (select) + código de centro de custo
//  · Tipos de nota → VÁRIOS departamentos (checklist), limitando onde o tipo
//    aparece na abertura do processo. Vazio = aparece em todos.
$ehTiposNota     = ($lista['id'] ?? '') === TableList::ID_LISTA_TIPOS_NOTA;
$temDepartamento = ($lista['id'] ?? '') === TableList::ID_SETORES;

/** Filiais: CNPJ (coluna + campo nos modais) */
$temCnpj = ($lista['id'] ?? '') === TableList::ID_FILIAIS;

/** Etapas: atributos de fluxo (coluna + campos nos modais) */
$temEtapa = ($lista['id'] ?? '') === TableList::ID_ETAPAS;

/**
 * Índice — classificação do item para etapa de decisão.
 *
 * Só nas listas personalizadas: as de sistema (setores, filiais, etapas) vivem
 * em tabelas próprias, que não têm a coluna. Enquanto nenhuma etapa de decisão
 * apontar para a lista, o índice é dado inerte — não altera formulário nem
 * fluxo.
 */
$temIndice = !TableList::isSystem($lista['id'] ?? '');

/** Converte sla_horas para [valor, unidade] legíveis (minutos/horas/dias) */
if (!function_exists('slaValorUnidade')) {
    function slaValorUnidade($horas): array {
        $h = (float) $horas;
        if ($h <= 0) return ['', 'horas'];
        if ($h < 1) return [(string) round($h * 60), 'minutos'];
        if ($h >= 24 && fmod($h, 24.0) === 0.0) return [(string) (int) ($h / 24), 'dias'];
        return [rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.'), 'horas'];
    }
}
if (!function_exists('slaLabelCurto')) {
    function slaLabelCurto($horas): string {
        [$v, $u] = slaValorUnidade($horas);
        if ($v === '') return '';
        return $v . ($u === 'minutos' ? 'min' : ($u === 'dias' ? ' dia(s)' : 'h'));
    }
}

/** Formata 14 dígitos como 11.222.333/0001-81 (retorna o original se não tiver 14 dígitos) */
if (!function_exists('formatCnpj')) {
    function formatCnpj($cnpj) {
        $d = preg_replace('/\D/', '', (string)$cnpj);
        if (strlen($d) !== 14) return (string)$cnpj;
        return substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12,2);
    }
}
?>

<?php include VIEWS . '/components/alert.php'; ?>

<!-- Breadcrumb -->
<div style="margin-bottom:16px; font-size:13px; color:#888; display:flex; align-items:center; gap:6px">
  <a href="?module=tabelas&action=index" style="color:#2347C8; text-decoration:none; font-weight:700; display:flex; align-items:center; gap:4px">
    <i data-lucide="arrow-left" width="14" height="14"></i>
    Listas
  </a>
  <span style="color:#EAEAEA">/</span>
  <span style="color:#141922; font-weight:600"><?= h($lista['nome'] ?? '') ?></span>
</div>

<!-- Header -->
<div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px">
  <div>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 6px">
      <?= h($lista['nome'] ?? '') ?>
    </h1>
    <?php if (!empty($lista['descricao'])): ?>
    <p style="font-size:14px; color:#888; margin:0"><?= h($lista['descricao']) ?></p>
    <?php endif; ?>
    <div style="display:flex; gap:6px; margin-top:8px; align-items:center">
      <?php if ($lista['tipo'] === 'sistema'): ?>
      <span class="badge-tipo-pre" style="background:#D8D4E4; color:#606060">
        <i data-lucide="building-2" width="10" height="10"></i> Lista de sistema
      </span>
      <?php elseif ($lista['tipo'] === 'pre_configurada'): ?>
      <span class="badge-tipo-pre">
        <i data-lucide="lock" width="10" height="10"></i> Pré-configurada
      </span>
      <?php endif; ?>
      <?php if ($lista['ativo']): ?>
      <span class="badge-ativo">Ativa</span>
      <?php else: ?>
      <span class="badge-inativo">Inativa</span>
      <?php endif; ?>
    </div>
  </div>
  <div style="display:flex; gap:8px">
    <?php if (!in_array($lista['tipo'], ['pre_configurada', 'sistema'], true)): ?>
    <button type="button" onclick="abrirModal('modal-excluir-lista')"
            style="display:inline-flex; align-items:center; gap:6px; padding:8px 16px; background:#EEF2FD; color:#2347C8; border:1px solid #2347C8; border-radius:4px; font-size:13px; font-weight:700; cursor:pointer"
            onmouseover="this.style.background='#2347C8';this.style.color='#fff'" onmouseout="this.style.background='#EEF2FD';this.style.color='#2347C8'">
      <i data-lucide="trash-2" width="14" height="14"></i>
      Excluir
    </button>
    <?php endif; ?>
    <?php if ($lista['tipo'] !== 'sistema'): ?>
    <a href="?module=tabelas&action=editar&id=<?= h($lista['id']) ?>" class="btn btn-secondary">
      <i data-lucide="pencil" width="15" height="15"></i>
      Editar
    </a>
    <?php endif; ?>
    <button type="button" class="btn btn-primary" id="btn-novo-item">
      <i data-lucide="plus" width="15" height="15"></i>
      Novo Item
    </button>
  </div>
</div>

<!-- Filtros -->
<?php
  // status e q vão sempre na URL das abas, mesmo vazios: o filtro fica guardado
  // na sessão e é a presença do campo na query que define o novo recorte —
  // sem isso, clicar em "Todos" reabriria com o status anterior.
  $itensAbaUrl = function (string $status) use ($lista, $currentQ): string {
      return '?' . http_build_query([
          'module' => 'tabelas', 'action' => 'itens', 'id' => $lista['id'],
          'status' => $status,   'q'      => $currentQ,
      ]);
  };
  $itensFiltroAtivo = $currentStatus !== '' || $currentQ !== '';
?>
<div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap">
  <div style="display:flex; gap:4px">
    <a href="<?= h($itensAbaUrl('')) ?>"
       style="padding:6px 14px; border-radius:4px; font-size:13px; font-weight:700; text-decoration:none;
              background:<?= $currentStatus === '' ? '#2347C8' : '#fff' ?>; color:<?= $currentStatus === '' ? '#fff' : '#606060' ?>;
              border:1px solid <?= $currentStatus === '' ? '#2347C8' : '#EAEAEA' ?>">Todos</a>
    <a href="<?= h($itensAbaUrl('ativo')) ?>"
       style="padding:6px 14px; border-radius:4px; font-size:13px; font-weight:700; text-decoration:none;
              background:<?= $currentStatus === 'ativo' ? '#2347C8' : '#fff' ?>; color:<?= $currentStatus === 'ativo' ? '#fff' : '#606060' ?>;
              border:1px solid <?= $currentStatus === 'ativo' ? '#2347C8' : '#EAEAEA' ?>">Ativos</a>
    <a href="<?= h($itensAbaUrl('inativo')) ?>"
       style="padding:6px 14px; border-radius:4px; font-size:13px; font-weight:700; text-decoration:none;
              background:<?= $currentStatus === 'inativo' ? '#2347C8' : '#fff' ?>; color:<?= $currentStatus === 'inativo' ? '#fff' : '#606060' ?>;
              border:1px solid <?= $currentStatus === 'inativo' ? '#2347C8' : '#EAEAEA' ?>">Inativos</a>
  </div>

  <form method="get" action="" role="search" style="position:relative; min-width:240px;">
    <input type="hidden" name="module" value="tabelas">
    <input type="hidden" name="action" value="itens">
    <input type="hidden" name="id" value="<?= h($lista['id']) ?>">
    <?php if ($currentStatus !== ''): ?><input type="hidden" name="status" value="<?= h($currentStatus) ?>"><?php endif; ?>
    <i data-lucide="search" width="16" height="16" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#888; pointer-events:none"></i>
    <input type="search" name="q" value="<?= h($currentQ) ?>" placeholder="Buscar item..."
           style="width:100%; height:40px; padding:0 12px 0 34px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
           onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
  </form>

  <?php // Sem botão de aplicar (a busca vale no Enter), mas com saída: o
        // recorte fica guardado até ser limpo. ?>
  <?php if ($itensFiltroAtivo): ?>
  <a href="?module=tabelas&amp;action=itens&amp;id=<?= h($lista['id']) ?>&amp;limpar=1" title="Remover os filtros aplicados"
     style="height:40px; display:inline-flex; align-items:center; gap:6px; background:#EAEAEA; color:#606060; border-radius:4px; padding:0 20px; font-size:14px; font-weight:700; text-decoration:none">
    <i data-lucide="x" width="15" height="15"></i> Limpar
  </a>
  <?php endif; ?>
</div>

<!-- Lista de itens -->
<?php if (empty($itens)): ?>
<?php
$emptyIcon    = 'list';
$emptyTitle   = $currentQ !== '' ? 'Nenhum item encontrado' : 'Nenhum item cadastrado';
$emptyMessage = $currentQ !== '' ? 'Tente um termo diferente.' : 'Adicione o primeiro item nesta lista.';
$emptyAction  = null;
include VIEWS . '/components/empty-state.php';
?>
<?php else: ?>
<div class="card" style="overflow:hidden; padding:0">
  <table class="table" style="width:100%; border-collapse:collapse">
    <thead>
      <tr>
        <th><?= $temCnpj ? 'Filial' : ($temEtapa ? 'Etapa' : 'Valor') ?></th>
        <?php if ($temCnpj): ?>
        <th style="width:200px">CNPJ</th>
        <?php endif; ?>
        <?php if ($temDepartamento): ?>
        <th style="width:220px">Departamento</th>
        <?php endif; ?>
        <?php if ($ehTiposNota): ?>
        <th style="width:300px">Aparece em</th>
        <?php endif; ?>
        <?php if ($temEtapa): ?>
        <th style="width:340px">Atributos</th>
        <?php endif; ?>
        <th style="width:100px">Status</th>
        <th style="width:120px; text-align:right">Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($itens as $item): ?>
      <tr class="<?= $item['ativo'] ? '' : 'row-inativo' ?>">
        <td style="font-size:14px; color:#141922; font-weight:500">
          <?php if ($temDepartamento && !empty($item['codigo'])): ?>
          <span style="font-family:Consolas,monospace; font-size:12px; font-weight:700; background:#F6F6F6; border:1px solid #EAEAEA; border-radius:4px; padding:1px 6px; margin-right:6px"><?= h($item['codigo']) ?></span>
          <?php endif; ?>
          <?= h($item['valor']) ?>
          <?php if ($temEtapa && !empty($item['descricao'])): ?>
          <div style="font-size:12px; color:#888; font-weight:400; margin-top:2px"><?= h($item['descricao']) ?></div>
          <?php endif; ?>
        </td>
        <?php if ($temCnpj): ?>
        <td style="font-size:13px">
          <?php if (!empty($item['cnpj'])): ?>
          <span style="font-family:Consolas,monospace; color:#141922"><?= h(formatCnpj($item['cnpj'])) ?></span>
          <?php else: ?>
          <span style="color:#B3261E; font-size:12px; font-weight:600">— sem CNPJ —</span>
          <?php endif; ?>
        </td>
        <?php endif; ?>
        <?php if ($temDepartamento): ?>
        <td style="font-size:13px">
          <?php if (!empty($item['departamento_nome'])): ?>
          <span style="background:#D8D4E4; color:#606060; font-size:11px; font-weight:700; padding:3px 10px; border-radius:8px"><?= h($item['departamento_nome']) ?></span>
          <?php else: ?>
          <span style="color:#BBB; font-size:12px">— sem vínculo —</span>
          <?php endif; ?>
        </td>
        <?php endif; ?>
        <?php if ($ehTiposNota): $marcados = $marcacaoDepto[$item['id']] ?? []; ?>
        <td style="font-size:13px">
          <?php if ($marcados): ?>
          <?php foreach ($departamentos as $d): if (!in_array($d['id'], $marcados, true)) continue; ?>
          <span style="display:inline-block; background:#D8D4E4; color:#606060; font-size:11px; font-weight:700; padding:3px 10px; border-radius:8px; margin:0 4px 4px 0"><?= h($d['valor']) ?></span>
          <?php endforeach; ?>
          <?php else: ?>
          <span style="color:#888; font-size:12px">todos os departamentos</span>
          <?php endif; ?>
          <?php if (empty($item['exige_valor'])): ?>
          <div style="margin-top:4px">
            <span style="display:inline-block; background:#EAEAEA; color:#606060; font-size:10px; font-weight:700; padding:3px 10px; border-radius:8px"
                  title="A abertura de processo com este tipo de nota nao pede o Valor Total">NAO EXIGE VALOR</span>
          </div>
          <?php endif; ?>
        </td>
        <?php endif; ?>
        <?php if ($temEtapa): ?>
        <td style="font-size:13px">
          <div style="display:flex; flex-wrap:wrap; gap:4px">
            <?php if (!empty($item['encerra_automaticamente'])): ?>
            <span style="background:#D8F5E5; color:#00A960; font-size:10px; font-weight:700; padding:3px 10px; border-radius:8px" title="Ao atingir esta etapa o processo é concluído e arquivado automaticamente">ARQUIVA AUTOMÁTICO</span>
            <?php endif; ?>
            <?php if (!empty($item['sla_horas'])): ?>
            <span style="background:#FFF3E0; color:#FF8A2D; font-size:10px; font-weight:700; padding:3px 10px; border-radius:8px" title="SLA padrão — vale para todos os fluxos que usam esta etapa">SLA <?= h(slaLabelCurto($item['sla_horas'])) ?></span>
            <?php endif; ?>
            <span style="background:#EAEAEA; color:#606060; font-size:10px; font-weight:700; padding:3px 10px; border-radius:8px" title="Tipo de aprovador aceito pela etapa">
              <?= ['grupo' => 'SÓ GRUPO', 'usuario' => 'SÓ USUÁRIO'][$item['tipo_aprovador'] ?? 'ambos'] ?? 'GRUPO OU USUÁRIO' ?>
            </span>
            <?php if (!empty($item['visualizacao_restrita'])): ?>
            <span style="background:#FFE8EE; color:#B3261E; font-size:10px; font-weight:700; padding:3px 10px; border-radius:8px" title="Com o processo nesta etapa, apenas os aprovadores da etapa (e admins) podem visualizá-lo">VISUALIZAÇÃO RESTRITA</span>
            <?php endif; ?>
            <?php if (!empty($item['somente_observacao'])): ?>
            <span style="background:#D8D4E4; color:#2347C8; font-size:10px; font-weight:700; padding:3px 10px; border-radius:8px" title="O responsável enxerga o processo desde a abertura sem precisar agir — não gera tarefa e o fluxo passa direto">SOMENTE OBSERVAÇÃO</span>
            <?php endif; ?>
            <?php if (!empty($item['decisao_lista_id'])): ?>
            <span style="background:#EEF2FD; color:#2347C8; font-size:10px; font-weight:700; padding:3px 10px; border-radius:8px" title="Divide o fluxo: o template indica, para cada índice da lista, para qual etapa o processo segue">DECISÃO</span>
            <?php endif; ?>
            <?php if (!empty($item['salto_incondicional'])): ?>
            <span style="background:#EEF2FD; color:#2347C8; font-size:10px; font-weight:700; padding:3px 10px; border-radius:8px" title="Manda o processo para a etapa que o template indicar, pulando o que ficou no caminho">IR PARA</span>
            <?php endif; ?>
            <?php if (!empty($item['abre_fluxo'])): ?>
            <span style="background:#EEF2FD; color:#2347C8; font-size:10px; font-weight:700; padding:3px 10px; border-radius:8px" title="Abre um processo novo no template indicado e encerra o processo atual">ABRE FLUXO</span>
            <?php endif; ?>
          </div>
        </td>
        <?php endif; ?>
        <td>
          <?php if ($item['ativo']): ?>
          <span style="font-size:10px; font-weight:700; background:#D8F5E5; color:#00A960; padding:2px 8px; border-radius:8px">Ativo</span>
          <?php else: ?>
          <span style="font-size:10px; font-weight:700; background:#EAEAEA; color:#888; padding:2px 8px; border-radius:8px">Inativo</span>
          <?php endif; ?>
        </td>
        <td style="text-align:right">
          <div style="display:flex; gap:4px; justify-content:flex-end">
            <?php if ($temEtapa) { [$slaV, $slaU] = slaValorUnidade($item['sla_horas'] ?? null); } else { $slaV = ''; $slaU = 'horas'; } ?>
            <button type="button" class="btn-edit-item"
                    data-id="<?= h($item['id']) ?>" data-valor="<?= h($item['valor']) ?>"
                    data-departamento="<?= h($item['departamento_id'] ?? '') ?>"
                    data-departamentos="<?= h(implode(',', $marcacaoDepto[$item['id']] ?? [])) ?>"
                    data-codigo="<?= h($item['codigo'] ?? '') ?>"
                    data-cnpj="<?= h($item['cnpj'] ?? '') ?>"
                    data-descricao="<?= h($item['descricao'] ?? '') ?>"
                    data-encerra="<?= !empty($item['encerra_automaticamente']) ? '1' : '0' ?>"
                    data-sla-valor="<?= h($slaV) ?>" data-sla-unidade="<?= h($slaU) ?>"
                    data-tipo-aprovador="<?= h($item['tipo_aprovador'] ?? 'ambos') ?>"
                    data-restrita="<?= !empty($item['visualizacao_restrita']) ? '1' : '0' ?>"
                    data-observacao="<?= !empty($item['somente_observacao']) ? '1' : '0' ?>"
                    data-decisao-lista="<?= h((string) ($item['decisao_lista_id'] ?? '')) ?>"
                    data-salto="<?= !empty($item['salto_incondicional']) ? '1' : '0' ?>"
                    data-abre-fluxo="<?= !empty($item['abre_fluxo']) ? '1' : '0' ?>"
                    data-exige-valor="<?= !empty($item['exige_valor']) ? '1' : '0' ?>"
                    data-indice="<?= isset($item['indice']) ? (int) $item['indice'] : '' ?>"
                    style="width:30px; height:30px; border-radius:4px; border:1px solid #EAEAEA; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#888"
                    title="Editar"
                    onmouseover="this.style.color='#2347C8';this.style.borderColor='#2347C8'" onmouseout="this.style.color='#888';this.style.borderColor='#EAEAEA'">
              <i data-lucide="pencil" width="14" height="14"></i>
            </button>
            <form method="post" action="?module=tabelas&action=toggle_item" style="display:inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= h($item['id']) ?>">
              <input type="hidden" name="lista_id" value="<?= h($lista['id']) ?>">
              <input type="hidden" name="redirect_filters[status]" value="<?= h($currentStatus) ?>">
              <input type="hidden" name="redirect_filters[q]" value="<?= h($currentQ) ?>">
              <button type="submit"
                      style="width:30px; height:30px; border-radius:4px; border:1px solid #EAEAEA; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; color:<?= $item['ativo'] ? '#00A960' : '#888' ?>"
                      title="<?= $item['ativo'] ? 'Desativar' : 'Ativar' ?>">
                <i data-lucide="<?= $item['ativo'] ? 'toggle-right' : 'toggle-left' ?>" width="16" height="16"></i>
              </button>
            </form>
            <?php if ($lista['tipo'] !== 'pre_configurada'): ?>
            <form method="post" action="?module=tabelas&action=excluir_item" style="display:inline"
                  onsubmit="return confirm('Excluir &quot;<?= h($item['valor']) ?>&quot;?\nSe estiver em uso por algum registro, a exclusão será bloqueada.')">
              <?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= h($item['id']) ?>">
              <input type="hidden" name="lista_id" value="<?= h($lista['id']) ?>">
              <button type="submit"
                      style="width:30px; height:30px; border-radius:4px; border:1px solid #EAEAEA; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#888"
                      title="Excluir"
                      onmouseover="this.style.color='#B3261E';this.style.borderColor='#B3261E'" onmouseout="this.style.color='#888';this.style.borderColor='#EAEAEA'">
                <i data-lucide="trash-2" width="14" height="14"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div style="padding:12px 16px; border-top:1px solid #EAEAEA; display:flex; align-items:center; justify-content:space-between; font-size:13px; color:#888">
    <span><?= $total ?> item(s) · <span style="color:#00A960"><?= $totalAtivos ?> ativo(s)</span></span>
    <?php if ($totalPages > 1):
      $module = 'tabelas'; $action = 'itens';
      $extraParams = ['id' => $lista['id']];
      if ($currentStatus !== '') $extraParams['status'] = $currentStatus;
      if ($currentQ !== '')      $extraParams['q']      = $currentQ;
      include VIEWS . '/components/pagination.php';
    endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Modal: Novo Item -->
<div id="modal-novo-item" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center">
  <div style="background:#fff; border-radius:12px; width:100%; max-width:420px; margin:20px; box-shadow:0 8px 32px rgba(0,0,0,0.2)">
    <div style="padding:20px 24px 0; display:flex; align-items:center; justify-content:space-between">
      <h3 style="font-size:18px; font-weight:700; color:#141922; margin:0">Novo Item</h3>
      <button type="button" onclick="fecharModal('modal-novo-item')" style="background:none; border:none; cursor:pointer; color:#888; font-size:20px">×</button>
    </div>
    <form method="post" action="?module=tabelas&action=novo_item">
      <?= Csrf::field() ?>
      <input type="hidden" name="lista_id" value="<?= h($lista['id']) ?>">
      <div style="padding:20px 24px">
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
          Valor <span style="color:#2347C8">*</span>
        </label>
        <input type="text" name="valor" id="novo-valor" required placeholder="<?= $temCnpj ? 'Nome da filial' : 'Ex: Boleto, PIX, Serviço...' ?>"
               style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <?php if ($temIndice): ?>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin:14px 0 6px">
          Índice <span style="font-size:11px; color:#888; font-weight:400">(opcional — agrupa itens que seguem o mesmo caminho)</span>
        </label>
        <input type="number" name="indice" id="novo-indice" min="1" max="255" step="1" placeholder="Ex.: 3"
               style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <span style="display:block; font-size:12px; color:#888; margin-top:5px">
          Itens com o mesmo índice são tratados como equivalentes por uma etapa de decisão.
          Só tem efeito se alguma etapa de decisão usar esta lista — caso contrário é apenas informativo.
        </span>
        <?php endif; ?>
        <?php if ($temCnpj): ?>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin:14px 0 6px">
          CNPJ <span style="font-size:11px; color:#888; font-weight:400">(opcional — 14 dígitos)</span>
        </label>
        <input type="text" name="cnpj" id="novo-cnpj" maxlength="18" inputmode="numeric" placeholder="11.222.333/0001-81"
               style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <?php endif; ?>
        <?php if ($temDepartamento): ?>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin:14px 0 6px">
          Código do centro de custo <span style="font-size:11px; color:#888; font-weight:400">(opcional — ex.: 890)</span>
        </label>
        <input type="text" name="codigo" id="novo-codigo" maxlength="20" placeholder="Ex.: 890"
               style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin:14px 0 6px">
          Departamento <span style="font-size:11px; color:#888; font-weight:400">(opcional)</span>
        </label>
        <select name="departamento_id" id="novo-departamento"
                style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; background:#fff">
          <option value="">— Sem vínculo —</option>
          <?php foreach ($departamentos as $d): ?>
          <option value="<?= h($d['id']) ?>"><?= h($d['valor']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if ($ehTiposNota): ?>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin:14px 0 6px">
          Departamentos onde aparece
          <span style="font-size:11px; color:#888; font-weight:400">(nenhum marcado = aparece em todos)</span>
        </label>
        <div style="border:1px solid #DFDFDF; border-radius:4px; padding:10px 12px; max-height:180px; overflow-y:auto">
          <?php foreach ($departamentos as $d): ?>
          <label style="display:flex; align-items:center; gap:8px; padding:5px 0; font-size:13px; color:#141922; cursor:pointer">
            <input type="checkbox" name="departamentos[]" value="<?= h($d['id']) ?>"
                   class="chk-depto-novo" style="width:15px; height:15px; accent-color:#2347C8">
            <?= h($d['valor']) ?>
          </label>
          <?php endforeach; ?>
        </div>

        <label style="display:flex; align-items:flex-start; gap:10px; margin:16px 0 0; cursor:pointer">
          <input type="checkbox" name="exige_valor" id="novo-exige-valor" value="1" checked style="margin-top:3px">
          <span>
            <span style="display:block; font-size:13px; font-weight:700; color:#141922">Exige valor na abertura</span>
            <span style="display:block; font-size:12px; color:#888">Desmarque em tipos que nao tem valor a informar (Cancelamento, Devolucao, Remessa): o campo Valor Total deixa de ser obrigatorio e a faixa de valor do template deixa de filtrar a busca.</span>
          </span>
        </label>
        <?php endif; ?>
        <?php if ($temEtapa): $p = 'novo'; include __DIR__ . '/_etapa_campos.php'; endif; ?>
      </div>
      <div style="padding:0 24px 20px; display:flex; gap:8px; justify-content:flex-end">
        <button type="button" onclick="fecharModal('modal-novo-item')" style="padding:10px 20px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">Cancelar</button>
        <button type="submit" id="btn-salvar-novo" style="padding:10px 20px; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px">
          <i data-lucide="plus" width="14" height="14"></i>
          Adicionar
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Editar Item -->
<div id="modal-editar-item" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center">
  <div style="background:#fff; border-radius:12px; width:100%; max-width:420px; margin:20px; box-shadow:0 8px 32px rgba(0,0,0,0.2)">
    <div style="padding:20px 24px 0; display:flex; align-items:center; justify-content:space-between">
      <h3 style="font-size:18px; font-weight:700; color:#141922; margin:0">Editar Item</h3>
      <button type="button" onclick="fecharModal('modal-editar-item')" style="background:none; border:none; cursor:pointer; color:#888; font-size:20px">×</button>
    </div>
    <form method="post" action="?module=tabelas&action=editar_item">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" id="edit-item-id" value="">
      <input type="hidden" name="lista_id" value="<?= h($lista['id']) ?>">
      <div style="padding:20px 24px">
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
          Valor <span style="color:#2347C8">*</span>
        </label>
        <input type="text" name="valor" id="edit-valor" required
               style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <?php if ($temIndice): ?>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin:14px 0 6px">
          Índice <span style="font-size:11px; color:#888; font-weight:400">(opcional — agrupa itens que seguem o mesmo caminho)</span>
        </label>
        <input type="number" name="indice" id="edit-indice" min="1" max="255" step="1" placeholder="Ex.: 3"
               style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <span style="display:block; font-size:12px; color:#888; margin-top:5px">
          Itens com o mesmo índice são tratados como equivalentes por uma etapa de decisão.
        </span>
        <?php endif; ?>
        <?php if ($temCnpj): ?>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin:14px 0 6px">
          CNPJ <span style="font-size:11px; color:#888; font-weight:400">(opcional — 14 dígitos)</span>
        </label>
        <input type="text" name="cnpj" id="edit-cnpj" maxlength="18" inputmode="numeric" placeholder="11.222.333/0001-81"
               style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <?php endif; ?>
        <?php if ($temDepartamento): ?>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin:14px 0 6px">
          Código do centro de custo <span style="font-size:11px; color:#888; font-weight:400">(opcional — ex.: 890)</span>
        </label>
        <input type="text" name="codigo" id="edit-codigo" maxlength="20" placeholder="Ex.: 890"
               style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin:14px 0 6px">
          Departamento <span style="font-size:11px; color:#888; font-weight:400">(opcional)</span>
        </label>
        <select name="departamento_id" id="edit-departamento"
                style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; background:#fff">
          <option value="">— Sem vínculo —</option>
          <?php foreach ($departamentos as $d): ?>
          <option value="<?= h($d['id']) ?>"><?= h($d['valor']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if ($ehTiposNota): ?>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin:14px 0 6px">
          Departamentos onde aparece
          <span style="font-size:11px; color:#888; font-weight:400">(nenhum marcado = aparece em todos)</span>
        </label>
        <div style="border:1px solid #DFDFDF; border-radius:4px; padding:10px 12px; max-height:180px; overflow-y:auto">
          <?php foreach ($departamentos as $d): ?>
          <label style="display:flex; align-items:center; gap:8px; padding:5px 0; font-size:13px; color:#141922; cursor:pointer">
            <input type="checkbox" name="departamentos[]" value="<?= h($d['id']) ?>"
                   class="chk-depto-edit" style="width:15px; height:15px; accent-color:#2347C8">
            <?= h($d['valor']) ?>
          </label>
          <?php endforeach; ?>
        </div>

        <label style="display:flex; align-items:flex-start; gap:10px; margin:16px 0 0; cursor:pointer">
          <input type="checkbox" name="exige_valor" id="edit-exige-valor" value="1" checked style="margin-top:3px">
          <span>
            <span style="display:block; font-size:13px; font-weight:700; color:#141922">Exige valor na abertura</span>
            <span style="display:block; font-size:12px; color:#888">Desmarque em tipos que nao tem valor a informar (Cancelamento, Devolucao, Remessa): o campo Valor Total deixa de ser obrigatorio e a faixa de valor do template deixa de filtrar a busca.</span>
          </span>
        </label>
        <?php endif; ?>
        <?php if ($temEtapa): $p = 'edit'; include __DIR__ . '/_etapa_campos.php'; endif; ?>
      </div>
      <div style="padding:0 24px 20px; display:flex; gap:8px; justify-content:flex-end">
        <button type="button" onclick="fecharModal('modal-editar-item')" style="padding:10px 20px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">Cancelar</button>
        <button type="submit" style="padding:10px 20px; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px">
          <i data-lucide="save" width="14" height="14"></i>
          Salvar
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirModal(id) { document.getElementById(id).style.display = 'flex'; }
function fecharModal(id) { document.getElementById(id).style.display = 'none'; }

// Máscara simples de CNPJ (11.222.333/0001-81)
function mascararCnpj(el) {
  if (!el) return;
  el.addEventListener('input', function () {
    var d = this.value.replace(/\D/g, '').slice(0, 14);
    var out = d;
    if (d.length > 2)  out = d.slice(0,2) + '.' + d.slice(2);
    if (d.length > 5)  out = d.slice(0,2) + '.' + d.slice(2,5) + '.' + d.slice(5);
    if (d.length > 8)  out = d.slice(0,2) + '.' + d.slice(2,5) + '.' + d.slice(5,8) + '/' + d.slice(8);
    if (d.length > 12) out = d.slice(0,2) + '.' + d.slice(2,5) + '.' + d.slice(5,8) + '/' + d.slice(8,12) + '-' + d.slice(12);
    this.value = out;
  });
}
mascararCnpj(document.getElementById('novo-cnpj'));
mascararCnpj(document.getElementById('edit-cnpj'));

function formatCnpjJs(v) {
  var d = (v || '').replace(/\D/g, '');
  if (d.length !== 14) return v || '';
  return d.slice(0,2)+'.'+d.slice(2,5)+'.'+d.slice(5,8)+'/'+d.slice(8,12)+'-'+d.slice(12);
}

document.getElementById('btn-novo-item').addEventListener('click', function () {
  document.getElementById('novo-valor').value = '';
  var novoCnpj = document.getElementById('novo-cnpj');
  if (novoCnpj) novoCnpj.value = '';
  var novoIndice = document.getElementById('novo-indice');
  if (novoIndice) novoIndice.value = '';
  document.querySelectorAll('.chk-depto-novo').forEach(function (c) { c.checked = false; });
  abrirModal('modal-novo-item');
  document.getElementById('novo-valor').focus();
});

document.querySelectorAll('.btn-edit-item').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('edit-item-id').value = this.dataset.id;
    document.getElementById('edit-valor').value    = this.dataset.valor;
    // Checklist de departamentos (Tipos de nota): remarca conforme o item.
    var marcados = (this.dataset.departamentos || '').split(',').filter(Boolean);
    document.querySelectorAll('.chk-depto-edit').forEach(function (c) {
      c.checked = marcados.indexOf(c.value) !== -1;
    });
    var selDep = document.getElementById('edit-departamento');
    if (selDep) selDep.value = this.dataset.departamento || '';
    var inpCod = document.getElementById('edit-codigo');
    if (inpCod) inpCod.value = this.dataset.codigo || '';
    var inpCnpj = document.getElementById('edit-cnpj');
    if (inpCnpj) inpCnpj.value = formatCnpjJs(this.dataset.cnpj || '');

    // Índice — classificação do item para etapa de decisão (listas personalizadas)
    var inpIndice = document.getElementById('edit-indice');
    if (inpIndice) inpIndice.value = this.dataset.indice || '';

    // Tipos de nota: exige o Valor Total na abertura do processo
    var chkValor = document.getElementById('edit-exige-valor');
    if (chkValor) chkValor.checked = this.dataset.exigeValor !== '0';

    // Campos de etapa (lista Etapas)
    var inpDesc = document.getElementById('edit-descricao');
    if (inpDesc) {
      inpDesc.value = this.dataset.descricao || '';
      document.getElementById('edit-encerra').checked  = this.dataset.encerra === '1';
      document.getElementById('edit-restrita').checked = this.dataset.restrita === '1';
      document.getElementById('edit-observacao').checked = this.dataset.observacao === '1';
      document.getElementById('edit-sla-valor').value  = this.dataset.slaValor || '';
      document.getElementById('edit-sla-unidade').value = this.dataset.slaUnidade || 'horas';
      document.getElementById('edit-tipo-aprovador').value = this.dataset.tipoAprovador || 'ambos';
      var chkSalto = document.getElementById('edit-salto');
      if (chkSalto) chkSalto.checked = this.dataset.salto === '1';
      var chkAbre = document.getElementById('edit-abre-fluxo');
      if (chkAbre) chkAbre.checked = this.dataset.abreFluxo === '1';
      var selDec = document.getElementById('edit-decisao-lista');
      if (selDec) selDec.value = this.dataset.decisaoLista || '';
      etapaObservacaoMudou('edit');
      if (typeof etapaDecisaoMudou === 'function') { etapaDecisaoMudou('edit'); }
    }

    abrirModal('modal-editar-item');
    document.getElementById('edit-valor').focus();
  });
});

// Modais fecham apenas pelos botões (sem clique-fora / ESC).


</script>

<!-- Modal: Excluir Lista -->
<div id="modal-excluir-lista" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center">
  <div style="background:#fff; border-radius:12px; width:100%; max-width:420px; margin:20px; box-shadow:0 8px 32px rgba(0,0,0,0.2)">
    <div style="padding:24px 24px 0; text-align:center;">
      <div style="width:48px; height:48px; border-radius:12px; background:#EEF2FD; display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px">
        <i data-lucide="trash-2" width="24" height="24" style="color:#2347C8"></i>
      </div>
      <h3 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 8px">Excluir lista</h3>
      <p style="font-size:14px; color:#606060; margin:0 0 8px">
        Tem certeza que deseja excluir <strong><?= h($lista['nome'] ?? '') ?></strong>?
      </p>
      <p style="font-size:13px; color:#888; margin:0">
        Todos os itens desta lista serão removidos permanentemente. Se a lista estiver em uso por processos ou templates, ela será <strong>inativada</strong> em vez de excluída.
      </p>
    </div>
    <div style="padding:20px 24px; display:flex; gap:8px; justify-content:center">
      <button type="button" onclick="fecharModal('modal-excluir-lista')"
              style="flex:1; padding:10px 20px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">
        Cancelar
      </button>
      <form method="post" action="?module=tabelas&action=excluir" style="flex:1; display:inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= h($lista['id']) ?>">
        <button type="submit"
                style="width:100%; padding:10px 20px; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:6px"
                onmouseover="this.style.background='#1B38A0'" onmouseout="this.style.background='#2347C8'">
          <i data-lucide="trash-2" width="14" height="14"></i>
          Excluir
        </button>
      </form>
    </div>
  </div>
</div>
