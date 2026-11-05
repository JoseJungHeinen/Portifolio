<?php
require __DIR__ . '/_layout.php';

$porEtapa = db()->query('SELECT etapa, COUNT(*) q, COALESCE(SUM(valor),0) v FROM multas GROUP BY etapa')->fetchAll(PDO::FETCH_UNIQUE);
$linhas = [];
$aberto = ['q' => 0, 'v' => 0];
foreach (LINHAS_PAINEL as $l) {
    $q = 0; $v = 0;
    foreach ($l['etapas'] as $e) { $q += $porEtapa[$e]['q'] ?? 0; $v += $porEtapa[$e]['v'] ?? 0; }
    $linhas[] = $l + ['q' => $q, 'v' => $v];
    $aberto['q'] += $q; $aberto['v'] += $v;
}
$final = ['q' => $porEtapa['finalizado_detran']['q'] ?? 0, 'v' => $porEtapa['finalizado_detran']['v'] ?? 0];
$fraude = $porEtapa['fraude']['q'] ?? 0;

$lojas = db()->query("SELECT l.id, l.nome, COUNT(m.id) total,
        SUM(m.etapa NOT IN ('finalizado_detran','fraude')) aberto,
        COALESCE(SUM(CASE WHEN m.etapa NOT IN ('finalizado_detran','fraude') THEN m.valor END),0) valor_aberto
    FROM lojas l LEFT JOIN multas m ON m.loja_id = l.id
    GROUP BY l.id HAVING total > 0 ORDER BY total DESC")->fetchAll();
$totLoja = ['total' => array_sum(array_column($lojas, 'total')), 'aberto' => array_sum(array_column($lojas, 'aberto'))];

$meses = db()->query("SELECT DATE_FORMAT(data_infracao, '%Y-%m') mes, COUNT(*) q, SUM(valor) v
    FROM multas WHERE etapa NOT IN ('finalizado_detran','fraude') AND data_infracao IS NOT NULL
    GROUP BY mes ORDER BY mes")->fetchAll();
$maxMes = max(array_column($meses, 'q') ?: [1]);

$ultima = db()->query('SELECT * FROM capturas_detran ORDER BY id DESC LIMIT 1')->fetch();
$arqColeta = __DIR__ . '/../data/coletas/ultima_execucao.json';
$coleta = is_file($arqColeta) ? json_decode(file_get_contents($arqColeta), true) : null;

const COR_KPI = ['nova' => '--nova', 'em_processo' => '--processo', 'a_faturar' => '--faturar',
    'aguardando_vcto' => '--vcto', 'a_pagar' => '--pagar', 'aguardando_baixar' => '--baixar'];

cabecalho('Painel', 'index');
?>
<h1 style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap">Painel Andamento das Multas
  <a class="btn btn-sec" href="resumo.php" style="font-size:14px">📤 Resumo para o WhatsApp</a></h1>
<p class="sub"><?= date('d/m/Y') ?>
  <?php if ($ultima): ?> · última captura do DETRAN: <?= data_br($ultima['criado_em']) ?> (<?= h($ultima['arquivo']) ?>)<?php endif; ?></p>

<?php
$ultimaReal = db()->query("SELECT * FROM capturas_detran WHERE origem <> 'planilha' ORDER BY id DESC LIMIT 1")->fetch();
$diaUtil = (int)date('N') <= 5;
$capturouHoje = $ultimaReal && date('Y-m-d', strtotime($ultimaReal['criado_em'])) === date('Y-m-d');
if ($diaUtil && !$capturouHoje): ?>
  <div class="aviso aviso-erro"><strong>Ainda não houve captura do DETRAN hoje.</strong>
    Entre no portal e clique no favorito <em>Coletar multas DETRAN</em> (<a href="coletor.php">como fazer</a>).</div>
<?php elseif ($capturouHoje):
    $emp = json_decode($ultimaReal['empresas'] ?? 'null', true);
    $falhou = $emp ? count(array_filter($emp, fn($e) => !$e['ok'])) : 0; ?>
  <div class="aviso aviso-<?= $falhou ? 'erro' : 'ok' ?>">Captura de hoje às <?= date('H:i', strtotime($ultimaReal['criado_em'])) ?>:
    <?= $ultimaReal['novas'] ?> novas, <?= $ultimaReal['atualizadas'] ?> atualizadas, <?= $ultimaReal['sairam_do_detran'] ?> saíram do DETRAN<?= $falhou ? " — <strong>$falhou CNPJ(s) falharam, rode de novo para finalizar as pagas</strong>" : '' ?>.</div>
<?php endif; ?>

<?php if ($coleta && $coleta['etapa'] !== 'verificar'):
    $quando = date('d/m/Y H:i', strtotime($coleta['quando']));
    $hoje = date('Y-m-d', strtotime($coleta['quando'])) === date('Y-m-d');
    $ok = $coleta['ok'] && $hoje; ?>
  <div class="aviso aviso-<?= $ok ? 'ok' : 'erro' ?>">
    <strong>Coletor automático</strong> · <?= $quando ?>:
    <?= h($coleta['mensagem'] ?? '') ?>
    <?php if (!empty($coleta['importacao'])): $i = $coleta['importacao']; ?>
      — <?= $i['novas'] ?> novas, <?= $i['atualizadas'] ?> atualizadas, <?= $i['sairam'] ?> saíram do DETRAN<?= $i['completa'] ? '' : ' (incompleta: nada finalizado)' ?>.
    <?php endif; ?>
    <?php if (!$hoje): ?> <strong>Não rodou hoje.</strong><?php endif; ?>
  </div>
<?php endif; ?>

<div class="kpis">
  <?php foreach ($linhas as $l): ?>
    <a class="kpi" style="--c: var(<?= COR_KPI[$l['chave']] ?>)" href="multas.php?grupo=<?= $l['chave'] ?>">
      <div class="r"><?= h($l['rotulo']) ?></div>
      <div class="q"><?= $l['q'] ?></div>
      <div class="v"><?= brl($l['v']) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<div class="grade">
  <section class="card">
    <h2>Status</h2>
    <table>
      <thead><tr><th>Status</th><th class="n">Quantidade</th><th class="n">Valor</th></tr></thead>
      <tbody>
      <?php foreach ($linhas as $l): ?>
        <tr class="link" onclick="location='multas.php?grupo=<?= $l['chave'] ?>'">
          <td><?= h($l['rotulo']) ?></td><td class="n"><?= $l['q'] ?></td><td class="n"><?= brl($l['v']) ?></td></tr>
      <?php endforeach; ?>
        <tr class="total link" onclick="location='multas.php?grupo=aberto'"><td>Total em aberto no DETRAN</td><td class="n"><?= $aberto['q'] ?></td><td class="n"><?= brl($aberto['v']) ?></td></tr>
        <tr class="link" onclick="location='multas.php?grupo=finalizado'"><td>Total de infrações finalizadas</td><td class="n"><?= $final['q'] ?></td><td class="n"><?= brl($final['v']) ?></td></tr>
        <tr><td>Total geral</td><td class="n"><?= $aberto['q'] + $final['q'] ?></td><td class="n"><?= brl($aberto['v'] + $final['v']) ?></td></tr>
        <?php if ($fraude): ?><tr class="link" onclick="location='multas.php?grupo=fraude'"><td class="mini">Marcadas como fraude (fora dos totais)</td><td class="n mini"><?= $fraude ?></td><td></td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="card">
    <h2>Lojas</h2>
    <table>
      <thead><tr><th>Loja</th><th class="n">Total</th><th class="n">Em aberto</th><th class="n">%</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($lojas as $l): $p = $l['total'] ? $l['aberto'] / $l['total'] : 0; ?>
        <tr class="link" onclick="location='multas.php?grupo=aberto&loja=<?= $l['id'] ?>'">
          <td><?= h($l['nome']) ?></td><td class="n"><?= $l['total'] ?></td><td class="n"><?= (int)$l['aberto'] ?></td>
          <td class="n"><?= number_format($p * 100, 1, ',', '') ?>%</td>
          <td style="width:30%"><div class="barra"><i style="width:<?= round($p * 100, 1) ?>%"></i></div></td></tr>
      <?php endforeach; ?>
        <tr class="total"><td>Total</td><td class="n"><?= $totLoja['total'] ?></td><td class="n"><?= $totLoja['aberto'] ?></td>
          <td class="n"><?= $totLoja['total'] ? number_format($totLoja['aberto'] / $totLoja['total'] * 100, 1, ',', '') : 0 ?>%</td><td></td></tr>
      </tbody>
    </table>
    <p class="mini">Conferência: em aberto por loja (<?= $totLoja['aberto'] ?>) = total em aberto (<?= $aberto['q'] ?>)
      <?= $totLoja['aberto'] == $aberto['q'] ? '✔' : '✘' ?></p>
  </section>

  <section class="card">
    <h2>Meses com infrações em aberto</h2>
    <table>
      <thead><tr><th>Mês/Ano</th><th class="n">Qtd.</th><th class="n">Valor</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($meses as $m): ?>
        <tr><td><?= substr($m['mes'], 5, 2) . '/' . substr($m['mes'], 0, 4) ?></td><td class="n"><?= $m['q'] ?></td>
          <td class="n"><?= brl($m['v']) ?></td>
          <td style="width:40%"><div class="barra"><i style="width:<?= round($m['q'] / $maxMes * 100) ?>%"></i></div></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>
<?php rodape();
