<?php
require __DIR__ . '/_layout.php';

$grupo = $_GET['grupo'] ?? 'aberto';
$lojaId = (int)($_GET['loja'] ?? 0);
$busca = trim($_GET['q'] ?? '');
$pagina = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 50;

$grupos = ['aberto' => 'Em aberto no DETRAN'];
foreach (LINHAS_PAINEL as $l) $grupos[$l['chave']] = $l['rotulo'];
$grupos += ['finalizado' => 'Finalizadas', 'fraude' => 'Fraude', 'todas' => 'Todas'];

$where = [];
$par = [];
if ($grupo === 'aberto') $where[] = "m.etapa NOT IN ('finalizado_detran','fraude')";
elseif ($grupo === 'finalizado') $where[] = "m.etapa = 'finalizado_detran'";
elseif ($grupo === 'fraude') $where[] = "m.etapa = 'fraude'";
elseif ($grupo !== 'todas') {
    foreach (LINHAS_PAINEL as $l) if ($l['chave'] === $grupo) {
        $where[] = 'm.etapa IN (' . implode(',', array_fill(0, count($l['etapas']), '?')) . ')';
        array_push($par, ...$l['etapas']);
    }
}
if ($lojaId) { $where[] = 'm.loja_id = ?'; $par[] = $lojaId; }
if ($busca !== '') {
    $where[] = '(m.placa LIKE ? OR m.serie LIKE ? OR m.fatura_numero LIKE ? OR m.condutor_nome LIKE ?)';
    $like = '%' . $busca . '%';
    array_push($par, $like, $like, $like, $like);
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = db()->prepare("SELECT COUNT(*), COALESCE(SUM(valor),0) FROM multas m $sqlWhere");
$st->execute($par);
[$total, $soma] = $st->fetch(PDO::FETCH_NUM);

$st = db()->prepare("SELECT m.*, l.nome loja FROM multas m LEFT JOIN lojas l ON l.id = m.loja_id $sqlWhere
    ORDER BY m.data_infracao DESC, m.id DESC LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina));
$st->execute($par);
$multas = $st->fetchAll();
$lojas = db()->query('SELECT id, nome FROM lojas ORDER BY nome')->fetchAll();
$paginas = max(1, (int)ceil($total / $porPagina));
$url = fn($p) => 'multas.php?' . http_build_query(['grupo' => $grupo, 'loja' => $lojaId ?: null, 'q' => $busca ?: null, 'p' => $p]);

cabecalho('Multas', 'multas');
?>
<h1><?= h($grupos[$grupo] ?? 'Multas') ?></h1>
<p class="sub"><?= $total ?> multas · <?= brl($soma) ?></p>

<form class="filtros" method="get">
  <div><label>Status</label>
    <select name="grupo"><?php foreach ($grupos as $k => $r): ?><option value="<?= $k ?>" <?= $k === $grupo ? 'selected' : '' ?>><?= h($r) ?></option><?php endforeach; ?></select></div>
  <div><label>Loja</label>
    <select name="loja"><option value="">Todas</option><?php foreach ($lojas as $l): ?><option value="<?= $l['id'] ?>" <?= $l['id'] == $lojaId ? 'selected' : '' ?>><?= h($l['nome']) ?></option><?php endforeach; ?></select></div>
  <div style="flex:1; min-width:220px"><label>Placa, série, fatura ou condutor</label><input name="q" value="<?= h($busca) ?>" placeholder="Ex.: ABC1D23"></div>
  <div><button>Filtrar</button></div>
</form>

<div class="card tab-scroll">
<table>
  <thead><tr><th>Placa</th><th>Loja</th><th>Série</th><th>Data</th><th>Autuador</th><th>Tipo</th><th class="n">Valor</th><th>Vencimento</th><th>Etapa</th></tr></thead>
  <tbody>
  <?php foreach ($multas as $m): ?>
    <tr class="link" onclick="location='multa.php?id=<?= $m['id'] ?>'">
      <td><strong><?= h($m['placa']) ?></strong></td>
      <td><?= h($m['loja']) ?></td>
      <td><a href="multa.php?id=<?= $m['id'] ?>"><?= h($m['serie']) ?></a></td>
      <td><?= data_br($m['data_infracao']) ?></td>
      <td class="mini"><?= h($m['autuador']) ?></td>
      <td><?= $m['tipo'] ? ucfirst($m['tipo']) : '' ?></td>
      <td class="n"><?= brl($m['valor']) ?></td>
      <td><?= $m['vencimento_pendente'] ? '<span class="mini">sem vencimento</span>' : data_br($m['vencimento_detran']) ?></td>
      <td><?= badge_etapa($m['etapa']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$multas): ?><tr><td colspan="9" class="mini">Nenhuma multa encontrada.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<div class="pag">
  <?php if ($pagina > 1): ?><a class="btn btn-sec" href="<?= h($url($pagina - 1)) ?>">‹ Anterior</a><?php endif; ?>
  <span>Página <?= $pagina ?> de <?= $paginas ?></span>
  <?php if ($pagina < $paginas): ?><a class="btn btn-sec" href="<?= h($url($pagina + 1)) ?>">Próxima ›</a><?php endif; ?>
</div>
<?php rodape();
