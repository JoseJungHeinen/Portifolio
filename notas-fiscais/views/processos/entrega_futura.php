<?php
// @versao Proc_15 V_02_05/10/2026
/**
 * views/processos/entrega_futura.php
 * Acompanhamento das vendas com entrega futura (migration 071).
 *
 * Variáveis esperadas:
 *   $efLista    array       vendas (lista) — vazio quando $efVenda está definida
 *   $efStatus   string      pendente | concluido | todos
 *   $efBusca    string
 *   $efVenda    array|null  venda aberta (detalhe)
 *   $efRemessas array       remessas da venda aberta
 *   $flash      array|null
 */

$efLista    = $efLista    ?? [];
$efStatus   = $efStatus   ?? 'pendente';
$efBusca    = $efBusca    ?? '';
$efVenda    = $efVenda    ?? null;
$efRemessas = $efRemessas ?? [];

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
$rs   = static fn($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
$data = static fn($d) => $d ? date('d/m/Y', strtotime((string) $d)) : '—';
$badge = static function (string $st): string {
    return $st === 'concluido'
        ? '<span style="display:inline-block; background:#D8F5E5; color:#00A960; border-radius:8px; padding:3px 10px; font-size:10px; font-weight:700; text-transform:uppercase">Concluído</span>'
        : '<span style="display:inline-block; background:#FFF3E0; color:#FF8A2D; border-radius:8px; padding:3px 10px; font-size:10px; font-weight:700; text-transform:uppercase">Pendente</span>';
};
$th = 'padding:12px 14px; text-align:left; font-size:13px; font-weight:700; text-transform:uppercase; color:#fff; background:#606060';
?>
<?php include VIEWS . '/components/alert.php'; ?>

<?php if ($efVenda): ?>
<?php
$pct = $efVenda['valor_nf'] > 0 ? min(100, round($efVenda['entregue'] / $efVenda['valor_nf'] * 100)) : 0;
$podeExcluir = Permissions::can('processos', 'editar');
?>
<div style="margin-bottom:20px">
  <a href="?module=processos&amp;action=entrega_futura" style="font-size:13px; font-weight:700; color:#606060; text-decoration:none">← Voltar para Entrega Futura</a>
</div>

<div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:20px">
  <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0">
    Venda <span style="color:#2347C8">NF <?= h($efVenda['numero_nf']) ?></span>
  </h1>
  <?= $badge($efVenda['status']) ?>
</div>

<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; border-left:4px solid <?= $efVenda['status'] === 'concluido' ? '#00A960' : '#FF8A2D' ?>; padding:20px 24px; margin-bottom:24px">
  <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px">
    <div><div style="font-size:12px; color:#888">Fornecedor</div><div style="font-size:14px; font-weight:700; color:#141922"><?= h($efVenda['fornecedor_nome']) ?></div></div>
    <div><div style="font-size:12px; color:#888">Data da nota</div><div style="font-size:14px; font-weight:700; color:#141922"><?= h($data($efVenda['data_nf'])) ?></div></div>
    <div><div style="font-size:12px; color:#888">Valor da venda</div><div style="font-size:14px; font-weight:700; color:#141922"><?= h($rs($efVenda['valor_nf'])) ?></div></div>
    <div><div style="font-size:12px; color:#888">Entregue</div><div style="font-size:14px; font-weight:700; color:#00A960"><?= h($rs($efVenda['entregue'])) ?></div></div>
    <div><div style="font-size:12px; color:#888">Saldo a entregar</div><div style="font-size:14px; font-weight:700; color:<?= $efVenda['saldo'] > 0 ? '#FF8A2D' : '#00A960' ?>"><?= h($rs($efVenda['saldo'])) ?></div></div>
    <div><div style="font-size:12px; color:#888">Processo</div>
      <a href="?module=processos&amp;action=detalhe&amp;id=<?= h($efVenda['processo_id']) ?>" style="font-size:14px; font-weight:700; color:#2347C8; text-decoration:none"><?= h($efVenda['codigo']) ?></a></div>
  </div>
  <div style="margin-top:16px; height:8px; background:#EAEAEA; border-radius:4px; overflow:hidden">
    <div style="height:100%; width:<?= (int) $pct ?>%; background:<?= $efVenda['status'] === 'concluido' ? '#00A960' : '#FF8A2D' ?>"></div>
  </div>
  <div style="font-size:12px; color:#888; margin-top:4px"><?= (int) $pct ?>% entregue<?= $efVenda['concluido_em'] ? ' · concluída em ' . h(date('d/m/Y H:i', strtotime((string) $efVenda['concluido_em']))) : '' ?></div>
</div>

<h2 style="font-size:20px; font-weight:700; color:#141922; margin:0 0 12px">Remessas registradas</h2>
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden; margin-bottom:12px">
  <table style="width:100%; border-collapse:collapse">
    <thead><tr>
      <th style="<?= $th ?>">NF da remessa</th>
      <th style="<?= $th ?>">Data</th>
      <th style="<?= $th ?>">Fornecedor</th>
      <th style="<?= $th ?>; text-align:right">Valor</th>
      <th style="<?= $th ?>">Registrada por</th>
      <?php if ($podeExcluir): ?><th style="<?= $th ?>"></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php if (!$efRemessas): ?>
      <tr><td colspan="6" style="padding:24px; text-align:center; font-size:13px; color:#888">Nenhuma remessa registrada ainda.</td></tr>
    <?php endif; ?>
    <?php foreach ($efRemessas as $i => $r): ?>
      <tr style="background:<?= $i % 2 ? '#F8F8F8' : '#fff' ?>">
        <td style="padding:12px 14px; font-size:13px; color:#141922">
          <b><?= h($r['numero_nf']) ?></b>
          <?php if (!empty($r['chave_nf'])): ?><div style="font-size:11px; color:#888"><?= h($r['chave_nf']) ?></div><?php endif; ?>
          <?php if (!empty($r['processo_id']) && !empty($r['processo_codigo'])): ?>
          <div style="font-size:11px"><a href="?module=processos&amp;action=detalhe&amp;id=<?= h($r['processo_id']) ?>" style="color:#2347C8; font-weight:700; text-decoration:none">Processo <?= h($r['processo_codigo']) ?></a></div>
          <?php endif; ?>
        </td>
        <td style="padding:12px 14px; font-size:13px; color:#141922"><?= h($data($r['data_nf'])) ?></td>
        <td style="padding:12px 14px; font-size:13px; color:#141922"><?= h($r['fornecedor_nome'] ?: '—') ?></td>
        <td style="padding:12px 14px; font-size:13px; color:#141922; text-align:right"><?= h($rs($r['valor_nf'])) ?></td>
        <td style="padding:12px 14px; font-size:12px; color:#606060"><?= h($r['criado_por_nome'] ?? '—') ?><div style="color:#888"><?= h(date('d/m/Y H:i', strtotime((string) $r['criado_em']))) ?></div></td>
        <?php if ($podeExcluir): ?>
        <td style="padding:12px 14px; text-align:right">
          <form method="POST" action="?module=processos&amp;action=entrega_futura_excluir_remessa" style="margin:0"
                onsubmit="return confirm('Excluir a remessa NF <?= h($r['numero_nf']) ?>? O saldo da venda será recalculado.')">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="remessa_id" value="<?= h($r['id']) ?>">
            <button type="submit" title="Excluir remessa" style="background:transparent; border:none; color:#B3261E; cursor:pointer; font-size:12px; font-weight:700">Excluir</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div style="font-size:12px; color:#888">Novas remessas entram por <b>Novo Processo → Etapa 2 → "Remessa de entrega futura"</b>, ou em <b>Completar dados</b> de um processo já aberto. A remessa segue o fluxo normal (Fiscal), sem o Financeiro.</div>

<?php else: ?>

<div style="margin-bottom:24px">
  <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">Entrega <span style="color:#2347C8">Futura</span></h1>
  <p style="font-size:14px; color:#888; margin:0">Notas de venda com entrega futura e as remessas já realizadas. A venda é concluída quando a soma das remessas atinge o valor dela.</p>
</div>

<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:18px 20px; margin-bottom:24px">
  <form method="GET" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap">
    <input type="hidden" name="module" value="processos">
    <input type="hidden" name="action" value="entrega_futura">
    <div>
      <label for="ef-status" style="display:block; font-size:12px; font-weight:700; color:#606060; margin-bottom:4px">Status</label>
      <select id="ef-status" name="status" style="height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff">
        <option value="pendente"  <?= $efStatus === 'pendente'  ? 'selected' : '' ?>>Pendentes</option>
        <option value="concluido" <?= $efStatus === 'concluido' ? 'selected' : '' ?>>Concluídas</option>
        <option value="todos"     <?= $efStatus === 'todos'     ? 'selected' : '' ?>>Todas</option>
      </select>
    </div>
    <div style="flex:1 1 220px">
      <label for="ef-q" style="display:block; font-size:12px; font-weight:700; color:#606060; margin-bottom:4px">Buscar</label>
      <input type="text" id="ef-q" name="q" value="<?= h($efBusca) ?>" placeholder="Nº da NF, fornecedor ou código do processo"
             style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box"
             onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
    </div>
    <button type="submit" style="height:40px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:0 24px; font-size:14px; font-weight:700; cursor:pointer">Filtrar</button>
  </form>
</div>

<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:auto">
  <table style="width:100%; border-collapse:collapse; min-width:860px">
    <thead><tr>
      <th style="<?= $th ?>">Fornecedor</th>
      <th style="<?= $th ?>">NF venda</th>
      <th style="<?= $th ?>">Data</th>
      <th style="<?= $th ?>; text-align:right">Valor</th>
      <th style="<?= $th ?>; text-align:right">Entregue</th>
      <th style="<?= $th ?>; text-align:right">Saldo</th>
      <th style="<?= $th ?>">Remessas</th>
      <th style="<?= $th ?>">Status</th>
    </tr></thead>
    <tbody>
    <?php if (!$efLista): ?>
      <tr><td colspan="8" style="padding:32px; text-align:center; font-size:13px; color:#888">Nenhuma venda com entrega futura encontrada.</td></tr>
    <?php endif; ?>
    <?php foreach ($efLista as $i => $v): ?>
      <?php $url = '?module=processos&action=entrega_futura&id=' . urlencode($v['id']); ?>
      <tr style="background:<?= $i % 2 ? '#F8F8F8' : '#fff' ?>; cursor:pointer" onclick="location.href='<?= h($url) ?>'"
          onmouseover="this.style.background='#EEF2FD'" onmouseout="this.style.background='<?= $i % 2 ? '#F8F8F8' : '#fff' ?>'">
        <td style="padding:12px 14px; font-size:13px; color:#141922"><?= h($v['fornecedor_nome']) ?></td>
        <td style="padding:12px 14px; font-size:13px; color:#141922"><a href="<?= h($url) ?>" style="color:#2347C8; font-weight:700; text-decoration:none"><?= h($v['numero_nf']) ?></a></td>
        <td style="padding:12px 14px; font-size:13px; color:#141922"><?= h($data($v['data_nf'])) ?></td>
        <td style="padding:12px 14px; font-size:13px; color:#141922; text-align:right"><?= h($rs($v['valor_nf'])) ?></td>
        <td style="padding:12px 14px; font-size:13px; color:#00A960; text-align:right"><?= h($rs($v['entregue'])) ?></td>
        <td style="padding:12px 14px; font-size:13px; font-weight:700; color:<?= $v['saldo'] > 0 ? '#FF8A2D' : '#00A960' ?>; text-align:right"><?= h($rs($v['saldo'])) ?></td>
        <td style="padding:12px 14px; font-size:13px; color:#606060"><?= (int) $v['qtd_remessas'] ?></td>
        <td style="padding:12px 14px"><?= $badge($v['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
