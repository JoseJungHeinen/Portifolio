<?php
// @versao Fat_03 V_09_30/09/2026
/**
 * views/faturamento/lista.php — lista de pedidos de faturamento.
 *
 * Variáveis: $pedidos, $total, $fases, $filtros, $escopo
 * A visibilidade já vem aplicada pelo Model (vendedor: só os seus;
 * gerente: equipe; demais grupos: todos).
 */
$porPagina = 25;
$paginas   = max(1, (int) ceil($total / $porPagina));

$rotuloEscopo = [
    'todos'    => 'Todos os pedidos',
    'equipe'   => 'Pedidos da sua equipe',
    'proprios' => 'Seus pedidos',
][$escopo['tipo']] ?? '';

/** Cor do badge de status por posição da fase (Aberto→…→última). */
$corStatus = static function (string $codigo): array {
    return match ($codigo) {
        'aberto'     => ['#FFF3E0', '#FF8A2D'],
        'gerencial'  => ['#D8D4E4', '#2347C8'],
        'financeiro' => ['#FFE8EE', '#B3261E'],
        'documental' => ['#D8F5E5', '#00A960'],
        default      => ['#EAEAEA', '#606060'],
    };
};

$urlPagina = static function (int $p) use ($filtros): string {
    $q = array_filter([
        'module'  => 'faturamento', 'action' => 'index',
        'busca'   => $filtros['busca'], 'fase_id' => $filtros['fase_id'],
        'de'      => $filtros['de'],    'ate'     => $filtros['ate'],
        'pagina'  => $p > 1 ? $p : '',
        'ordem'   => $filtros['ordem'], 'dir' => $filtros['ordem'] !== '' ? $filtros['dir'] : '',
    ], static fn($v) => $v !== '');
    return '?' . http_build_query($q);
};

/** Cabeçalho ordenável: clica alterna asc/desc; a seta mostra a coluna ativa. */
$thOrdem = static function (string $chave, string $rotulo, string $extra = '') use ($filtros): string {
    $ativa = $filtros['ordem'] === $chave;
    $prox  = ($ativa && $filtros['dir'] === 'asc') ? 'desc' : 'asc';
    $q = array_filter([
        'module' => 'faturamento', 'action' => 'index',
        'busca'  => $filtros['busca'], 'fase_id' => $filtros['fase_id'],
        'de'     => $filtros['de'],    'ate'     => $filtros['ate'],
        'ordem'  => $chave,            'dir'     => $prox,
    ], static fn($v) => $v !== '');
    $seta = $ativa ? ($filtros['dir'] === 'asc' ? '▲' : '▼') : '⇅';
    $cor  = $ativa ? '#fff' : 'rgba(255,255,255,0.45)';
    return '<th style="padding:10px 12px;' . $extra . '"><a href="?' . h(http_build_query($q)) . '" title="Ordenar por ' . h(mb_strtolower($rotulo)) . '" '
         . 'style="color:#fff; text-decoration:none; display:inline-flex; align-items:center; gap:6px">' . h($rotulo)
         . '<span style="font-size:10px; color:' . $cor . '">' . $seta . '</span></a></th>';
};
?>

<div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:12px">
  <div>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">Faturamento <span style="color:#2347C8">de veículos</span></h1>
    <p style="font-size:13px; color:#888; margin:0"><?= h($rotuloEscopo) ?> · <?= (int) $total ?> pedido(s)</p>
  </div>
  <a href="?module=faturamento&action=pedido" style="display:inline-flex; align-items:center; gap:6px; background:#2347C8; color:#fff; border-radius:4px; padding:10px 18px; font-size:14px; font-weight:700; text-decoration:none">
    <i data-lucide="plus" width="15" height="15"></i> Novo Pedido
  </a>
</div>

<form method="get" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px">
  <input type="hidden" name="module" value="faturamento">
  <input type="hidden" name="action" value="index">
  <?php if ($filtros['ordem'] !== ''): ?>
  <input type="hidden" name="ordem" value="<?= h($filtros['ordem']) ?>">
  <input type="hidden" name="dir" value="<?= h($filtros['dir']) ?>">
  <?php endif; ?>
  <div>
    <label style="display:block; font-size:12px; color:#606060; margin-bottom:4px">Busca</label>
    <input type="text" name="busca" value="<?= h($filtros['busca']) ?>" placeholder="FAT, pedido, cliente, veículo, vendedor"
           style="height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; min-width:260px; font-size:14px">
  </div>
  <div>
    <label style="display:block; font-size:12px; color:#606060; margin-bottom:4px">Status</label>
    <select name="fase_id" style="height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; background:#fff">
      <option value="">Todos</option>
      <?php foreach ($fases as $f): ?>
        <option value="<?= h($f['id']) ?>" <?= $filtros['fase_id'] === $f['id'] ? 'selected' : '' ?>><?= h($f['nome']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label style="display:block; font-size:12px; color:#606060; margin-bottom:4px">Início de</label>
    <input type="date" name="de" value="<?= h($filtros['de']) ?>" style="height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 10px; font-size:14px">
  </div>
  <div>
    <label style="display:block; font-size:12px; color:#606060; margin-bottom:4px">até</label>
    <input type="date" name="ate" value="<?= h($filtros['ate']) ?>" style="height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 10px; font-size:14px">
  </div>
  <button type="submit" style="height:40px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:0 20px; font-size:14px; font-weight:700; cursor:pointer">Filtrar</button>
  <a href="?module=faturamento&action=index" style="height:40px; display:inline-flex; align-items:center; background:#EAEAEA; color:#606060; border-radius:4px; padding:0 16px; font-size:14px; font-weight:700; text-decoration:none">Limpar</a>
</form>

<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden">
  <?php if (empty($pedidos)): ?>
    <div style="padding:32px; text-align:center; color:#888; font-size:13px">Nenhum pedido encontrado.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table style="width:100%; border-collapse:collapse; font-size:13px">
      <thead>
        <tr style="background:#606060; color:#fff; text-align:left; text-transform:uppercase">
          <?= $thOrdem('fat', 'FAT') ?>
          <?= $thOrdem('veiculo', 'Veículo') ?>
          <?= $thOrdem('cliente', 'Cliente') ?>
          <?= $thOrdem('vendedor', 'Vendedor') ?>
          <?= $thOrdem('valor', 'Valor total', 'text-align:right') ?>
          <?= $thOrdem('status', 'Status') ?>
          <?= $thOrdem('situacao', 'Situação') ?>
          <?= $thOrdem('inicio', 'Início') ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pedidos as $i => $p):
          [$bg, $fg] = $corStatus($p['fase_codigo']);
          $sit  = $p['situacao'];
          $link = '?module=faturamento&action=pedido&id=' . urlencode($p['id']);
          $veiculo = trim(($p['veiculo_desc'] ?? '') . ($p['veiculo_codigo'] ? ' · ' . $p['veiculo_codigo'] : ''), ' ·');
      ?>
        <tr class="fat-lista-row" style="background:<?= $i % 2 ? '#F8F8F8' : '#FFFFFF' ?>; cursor:pointer" onclick="location.href='<?= h($link) ?>'">
          <td style="padding:10px 12px; font-weight:700"><a href="<?= h($link) ?>" style="color:#2347C8; text-decoration:none"><?= h($p['numero']) ?></a></td>
          <td style="padding:10px 12px"><?= $veiculo !== '' ? h($veiculo) : '<span style="color:#888">Aguardando chegada da fábrica…</span>' ?></td>
          <td style="padding:10px 12px"><?= h($p['cliente_nome'] ?? '—') ?></td>
          <td style="padding:10px 12px"><?= h($p['vendedor_nome'] ?? '—') ?></td>
          <td style="padding:10px 12px; text-align:right; white-space:nowrap"><?= $p['valor_exibido'] !== null ? 'R$ ' . number_format((float) $p['valor_exibido'], 2, ',', '.') : '<span style="color:#888">—</span>' ?></td>
          <td style="padding:10px 12px"><span style="display:inline-block; background:<?= $bg ?>; color:<?= $fg ?>; border-radius:8px; padding:3px 10px; font-size:10px; font-weight:700; text-transform:uppercase"><?= h($p['fase_nome']) ?></span></td>
          <td style="padding:10px 12px">
            <?php if ($sit['completo']): ?>
              <span style="display:inline-block; background:#D8F5E5; color:#00A960; border-radius:8px; padding:3px 10px; font-size:10px; font-weight:700; text-transform:uppercase">Completo</span>
            <?php else: ?>
              <span style="display:inline-block; background:#FFF3E0; color:#FF8A2D; border-radius:8px; padding:3px 10px; font-size:10px; font-weight:700; text-transform:uppercase"><?= (int) $sit['pendentes'] ?> pendência<?= $sit['pendentes'] > 1 ? 's' : '' ?></span>
            <?php endif; ?>
          </td>
          <td style="padding:10px 12px; white-space:nowrap"><?= h(date('d/m/Y', strtotime($p['data_inicio']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($paginas > 1): ?>
  <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 16px; font-size:13px; color:#606060">
    <span>Página <?= (int) $filtros['pagina'] ?> de <?= $paginas ?></span>
    <span style="display:flex; gap:8px">
      <?php if ($filtros['pagina'] > 1): ?><a href="<?= h($urlPagina($filtros['pagina'] - 1)) ?>" style="color:#2347C8; font-weight:700; text-decoration:none">← Anterior</a><?php endif; ?>
      <?php if ($filtros['pagina'] < $paginas): ?><a href="<?= h($urlPagina($filtros['pagina'] + 1)) ?>" style="color:#2347C8; font-weight:700; text-decoration:none">Próxima →</a><?php endif; ?>
    </span>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<style>.fat-lista-row:hover { background:#EEF2FD !important; }</style>
