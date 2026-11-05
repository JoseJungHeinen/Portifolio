<?php
// @versao Tab_07 V_04_30/09/2026
/**
 * views/tables/modelos.php — Listas › Modelos de Veículos.
 *
 * Variáveis: $modelos, $total, $totalAtivos, $totalInativos, $marcas, $filters,
 *            $currentPage, $totalPages
 */
$urlPagina = static function (int $p) use ($filters): string {
    return '?' . http_build_query(array_filter([
        'module' => 'tabelas', 'action' => 'modelos',
        'q' => $filters['q'], 'marca' => $filters['marca'], 'status' => $filters['status'],
        'page' => $p > 1 ? $p : '',
    ], static fn($v) => $v !== ''));
};
$moeda = static fn($n): string => ($n === null || $n === '') ? '—' : 'R$ ' . number_format((float) $n, 2, ',', '.');
$th = 'padding:12px 16px; text-align:left; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase';
$td = 'padding:10px 16px; font-size:13px';
$campo = 'width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; box-sizing:border-box; background:#fff';
$rot   = 'display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px';
?>
<div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px">
  <div>
    <a href="?module=tabelas&amp;action=index" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#606060; text-decoration:none; margin-bottom:8px">
      <i data-lucide="arrow-left" width="14" height="14"></i> Voltar para Listas
    </a>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">Modelos de <span style="color:#2347C8">Veículos</span></h1>
    <p style="font-size:14px; color:#888; margin:0"><?= (int) $totalAtivos ?> ativo(s) · <?= (int) $totalInativos ?> inativo(s) — alimenta o campo Modelo (C3) do pedido de Faturamento</p>
  </div>
  <button type="button" onclick="modeloAbrir(null)"
          style="display:inline-flex; align-items:center; gap:8px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; cursor:pointer">
    <i data-lucide="plus" width="16" height="16"></i> Incluir
  </button>
</div>

<?php include VIEWS . '/components/alert.php'; ?>

<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:16px 20px; margin-bottom:20px">
  <form method="get" style="display:flex; gap:12px; flex-wrap:wrap; align-items:center">
    <input type="hidden" name="module" value="tabelas">
    <input type="hidden" name="action" value="modelos">
    <input type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="Pesquisar por MVS ou descrição..."
           style="flex:1; min-width:240px; max-width:400px; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box">
    <select name="marca" style="height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff">
      <option value="">Todas as marcas</option>
      <?php foreach ($marcas as $m): ?><option value="<?= h($m) ?>" <?= $filters['marca'] === $m ? 'selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?>
    </select>
    <select name="status" style="height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff">
      <option value="">Todos</option>
      <option value="ativo"   <?= $filters['status'] === 'ativo'   ? 'selected' : '' ?>>Ativos</option>
      <option value="inativo" <?= $filters['status'] === 'inativo' ? 'selected' : '' ?>>Inativos</option>
    </select>
    <button type="submit" style="height:40px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:0 20px; font-size:14px; font-weight:700; cursor:pointer">Pesquisar</button>
    <?php if ($filters['q'] !== '' || $filters['marca'] !== '' || $filters['status'] !== ''): ?>
    <a href="?module=tabelas&amp;action=modelos" style="height:40px; display:inline-flex; align-items:center; background:#EAEAEA; color:#606060; border-radius:4px; padding:0 20px; font-size:14px; font-weight:700; text-decoration:none">Limpar</a>
    <?php endif; ?>
  </form>
</div>

<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden">
  <div style="overflow-x:auto">
  <table style="width:100%; border-collapse:collapse; min-width:900px">
    <thead>
      <tr style="background:#606060">
        <th style="<?= $th ?>">Marca</th>
        <th style="<?= $th ?>">MVS</th>
        <th style="<?= $th ?>">Ano</th>
        <th style="<?= $th ?>">Descrição</th>
        <th style="<?= $th ?>">Combustível</th>
        <th style="<?= $th ?>; text-align:right">Preço sugerido</th>
        <th style="<?= $th ?>; text-align:center">Status</th>
        <th style="<?= $th ?>; text-align:center">Ações</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($modelos)): ?>
      <tr><td colspan="8" style="padding:40px 20px; text-align:center; color:#888; font-size:14px">Nenhum modelo encontrado.</td></tr>
    <?php else: foreach ($modelos as $i => $m): ?>
      <tr style="background:<?= $i % 2 ? '#F8F8F8' : '#fff' ?>; <?= $m['ativo'] ? '' : 'opacity:.6' ?>">
        <td style="<?= $td ?>; font-weight:700"><?= h($m['marca']) ?></td>
        <td style="<?= $td ?>; font-family:monospace"><?= h($m['mvs']) ?></td>
        <td style="<?= $td ?>"><?= (int) $m['ano_modelo'] ?></td>
        <td style="<?= $td ?>"><?= h($m['descricao']) ?>
          <?php $qtdCores = count(ModeloVeiculo::listaDeCores($m['cores'])); ?><div style="font-size:11px; color:#888"><?= $qtdCores ? $qtdCores . ' cor' . ($qtdCores > 1 ? 'es' : '') : 'sem cores cadastradas' ?></div></td>
        <td style="<?= $td ?>"><?= h($m['combustivel'] ?? '—') ?></td>
        <td style="<?= $td ?>; text-align:right; white-space:nowrap"><?= h($moeda($m['preco_sugerido'])) ?>
          <?php if ($m['preco_vigencia']): ?><div style="font-size:11px; color:#888">desde <?= h(date('d/m/Y', strtotime($m['preco_vigencia']))) ?></div><?php endif; ?></td>
        <td style="<?= $td ?>; text-align:center">
          <span style="display:inline-block; border-radius:8px; padding:3px 10px; font-size:10px; font-weight:700; text-transform:uppercase; background:<?= $m['ativo'] ? '#D8F5E5' : '#EAEAEA' ?>; color:<?= $m['ativo'] ? '#00A960' : '#888' ?>"><?= $m['ativo'] ? 'Ativo' : 'Inativo' ?></span>
        </td>
        <td style="<?= $td ?>; text-align:center; white-space:nowrap">
          <button type="button" class="modelo-editar" data-modelo="<?= h(json_encode($m, JSON_UNESCAPED_UNICODE)) ?>"
                  style="border:1px solid #DFDFDF; background:#fff; border-radius:4px; padding:6px 12px; font-size:12px; font-weight:700; color:#606060; cursor:pointer">Editar</button>
          <form method="post" action="?module=tabelas&amp;action=modelo_toggle" style="display:inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= h($m['id']) ?>">
            <button type="submit" style="border:1px solid <?= $m['ativo'] ? '#B3261E' : '#00A960' ?>; background:#fff; border-radius:4px; padding:6px 12px; font-size:12px; font-weight:700; color:<?= $m['ativo'] ? '#B3261E' : '#00A960' ?>; cursor:pointer"><?= $m['ativo'] ? 'Desativar' : 'Ativar' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
  <?php if ($totalPages > 1): ?>
  <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 16px; font-size:13px; color:#606060">
    <span>Página <?= (int) $currentPage ?> de <?= (int) $totalPages ?> · <?= (int) $total ?> modelo(s)</span>
    <span style="display:flex; gap:12px">
      <?php if ($currentPage > 1): ?><a href="<?= h($urlPagina($currentPage - 1)) ?>" style="color:#2347C8; font-weight:700; text-decoration:none">← Anterior</a><?php endif; ?>
      <?php if ($currentPage < $totalPages): ?><a href="<?= h($urlPagina($currentPage + 1)) ?>" style="color:#2347C8; font-weight:700; text-decoration:none">Próxima →</a><?php endif; ?>
    </span>
  </div>
  <?php endif; ?>
</div>

<!-- Modal: incluir / editar -->
<div id="modelo-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; align-items:center; justify-content:center; padding:16px">
  <form method="post" action="?module=tabelas&amp;action=modelo_salvar" style="background:#fff; border-radius:12px; width:100%; max-width:560px; padding:24px; max-height:92vh; overflow:auto">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" id="modelo-id">
    <h3 id="modelo-titulo" style="font-size:20px; margin:0 0 16px">Incluir modelo</h3>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px">
      <div><label style="<?= $rot ?>">Marca *</label><input name="marca" id="modelo-marca" list="modelo-marcas" maxlength="40" required style="<?= $campo ?>"></div>
      <div><label style="<?= $rot ?>">MVS *</label><input name="mvs" id="modelo-mvs" maxlength="20" required style="<?= $campo ?>"></div>
      <div><label style="<?= $rot ?>">Ano-modelo (MY) *</label><input name="ano_modelo" id="modelo-ano" type="number" min="2000" max="2100" required style="<?= $campo ?>"></div>
      <div><label style="<?= $rot ?>">Combustível</label>
        <select name="combustivel" id="modelo-comb" style="<?= $campo ?>"><option value="">—</option>
          <?php foreach (ModeloVeiculo::COMBUSTIVEIS as $c): ?><option><?= h($c) ?></option><?php endforeach; ?></select></div>
      <div style="grid-column:1 / -1"><label style="<?= $rot ?>">Descrição *</label><input name="descricao" id="modelo-desc" maxlength="200" required style="<?= $campo ?>"></div>
      <div><label style="<?= $rot ?>">Preço público sugerido (R$)</label><input name="preco_sugerido" id="modelo-preco" inputmode="decimal" placeholder="0,00" style="<?= $campo ?>"></div>
      <div><label style="<?= $rot ?>">Vigência do preço</label><input name="preco_vigencia" id="modelo-vig" type="date" style="<?= $campo ?>"></div>
      <div style="grid-column:1 / -1"><label style="<?= $rot ?>">Cores <span style="font-weight:400; color:#888">(uma por linha: CÓDIGO - Nome)</span></label>
        <textarea name="cores" id="modelo-cores" rows="5" placeholder="806 - Preto Vulcano&#10;979 - Cinza Silverstone" style="width:100%; border:1px solid #DFDFDF; border-radius:4px; padding:10px 12px; font-size:14px; box-sizing:border-box; font-family:inherit; resize:vertical"></textarea>
        <div style="font-size:11px; color:#888; margin-top:4px">Aparecem no campo Cor (C4) do pedido quando este modelo for escolhido. Em branco: o pedido usa a lista padrão de cores.</div></div>
      <div id="modelo-ativo-wrap" style="grid-column:1 / -1; display:none">
        <label style="display:inline-flex; align-items:center; gap:8px; font-size:14px; cursor:pointer"><input type="checkbox" name="ativo" value="1" id="modelo-ativo"> Ativo (aparece no pedido)</label>
      </div>
    </div>
    <datalist id="modelo-marcas"><?php foreach ($marcas as $m): ?><option value="<?= h($m) ?>"><?php endforeach; ?></datalist>
    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px">
      <button type="button" onclick="modeloFechar()" style="height:40px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; padding:0 20px; font-size:14px; font-weight:700; cursor:pointer">Cancelar</button>
      <button type="submit" style="height:40px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:0 24px; font-size:14px; font-weight:700; cursor:pointer">Salvar</button>
    </div>
  </form>
</div>

<script>
  function modeloAbrir(m) {
    const f = (id) => document.getElementById(id);
    f('modelo-titulo').textContent = m ? 'Editar modelo' : 'Incluir modelo';
    f('modelo-id').value    = m ? m.id : '';
    f('modelo-marca').value = m ? m.marca : '';
    f('modelo-mvs').value   = m ? m.mvs : '';
    f('modelo-ano').value   = m ? m.ano_modelo : '';
    f('modelo-comb').value  = m && m.combustivel ? m.combustivel : '';
    f('modelo-desc').value  = m ? m.descricao : '';
    f('modelo-preco').value = m && m.preco_sugerido !== null
      ? Number(m.preco_sugerido).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '';
    f('modelo-vig').value   = m && m.preco_vigencia ? m.preco_vigencia : '';
    f('modelo-cores').value = m && m.cores ? m.cores : '';
    f('modelo-ativo-wrap').style.display = m ? '' : 'none';
    f('modelo-ativo').checked = m ? String(m.ativo) === '1' : true;
    f('modelo-modal').style.display = 'flex';
    f('modelo-marca').focus();
  }
  function modeloFechar() { document.getElementById('modelo-modal').style.display = 'none'; }
  document.querySelectorAll('.modelo-editar').forEach((b) => {
    b.addEventListener('click', () => modeloAbrir(JSON.parse(b.dataset.modelo)));
  });
  document.getElementById('modelo-modal').addEventListener('mousedown', (e) => {
    if (e.target.id === 'modelo-modal') modeloFechar();
  });
</script>
