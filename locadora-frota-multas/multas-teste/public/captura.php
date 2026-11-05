<?php
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/captura_db.php';

$pastaUploads = __DIR__ . '/../data/uploads';
if (!is_dir($pastaUploads)) mkdir($pastaUploads, 0775, true);

$acao = $_POST['acao'] ?? '';
$erro = null;

if ($acao === 'enviar') {
    csrf_validar();
    $f = $_FILES['arquivo'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $erro = 'Selecione o arquivo .xlsx gerado pelo robô.';
    } elseif (!in_array($ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), ['xlsx', 'json'], true)) {
        $erro = 'O arquivo precisa ser .json (coletor do portal) ou .xlsx (robô).';
    } else {
        $id = bin2hex(random_bytes(8));
        move_uploaded_file($f['tmp_name'], "$pastaUploads/$id.$ext");
        $_SESSION['captura'] = ['id' => $id, 'ext' => $ext, 'nome' => basename($f['name'])];
        unset($_POST['lojas']);
    }
}

$sess = $_SESSION['captura'] ?? null;
$arquivo = $sess ? "$pastaUploads/{$sess['id']}." . ($sess['ext'] ?? 'xlsx') : null;
$cards = [];
$lido = null;
if ($arquivo && is_file($arquivo) && !$erro) {
    try {
        $lido = ler_captura_arquivo($arquivo, $sess['nome']);
        $cards = $lido['cards'];
        if (!$cards && $lido['fonte'] !== 'api') $erro = 'Nenhuma multa encontrada no arquivo (esperado: aba "Captura" com os cards do PCSDetran nas colunas A a H).';
    } catch (Throwable $e) {
        $erro = 'Não foi possível ler o arquivo: ' . h($e->getMessage());
    }
}
$empresas = $lido['empresas'] ?? null;
$falhasApi = $empresas ? count(array_filter($empresas, fn($e) => !$e['ok'])) : 0;

// Lojas que a captura pode "cobrir". Coletor da API: consulta a empresa inteira, então cobre
// todas as lojas — mas só se TODOS os CNPJs responderam. Robô: só as lojas presentes no arquivo.
if (($lido['fonte'] ?? '') === 'api') {
    $lojasArquivo = db()->query('SELECT DISTINCT l.nome FROM lojas l JOIN multas m ON m.loja_id = l.id ORDER BY l.nome')->fetchAll(PDO::FETCH_COLUMN);
    $lojasArquivo = array_values(array_unique(array_merge($lojasArquivo, array_filter(array_column($cards, 'loja')))));
    $padraoCobertas = $falhasApi ? [] : $lojasArquivo;
} else {
    $lojasArquivo = array_values(array_unique(array_filter(array_column($cards, 'loja'))));
    $padraoCobertas = $lojasArquivo;
}
sort($lojasArquivo);

if ($acao === 'cancelar') {
    csrf_validar();
    if ($arquivo && is_file($arquivo)) unlink($arquivo);
    unset($_SESSION['captura']);
    header('Location: captura.php');
    exit;
}

if ($acao === 'aplicar' && $lido && $cards) {
    csrf_validar();
    $cobertas = array_values(array_intersect($lojasArquivo, (array)($_POST['lojas'] ?? [])));
    $res = processar_captura($cards, $cobertas, $sess['nome'], true, $lido['fonte'], $empresas);
    unlink($arquivo);
    unset($_SESSION['captura']);
    flash(sprintf('Captura aplicada: <strong>%d novas</strong>, %d atualizadas, %d saíram do DETRAN, %d mudaram de etapa.',
        count($res['novas']), count($res['atualizadas']), count($res['sairam']), count($res['mudancasEtapa'])));
    header('Location: index.php');
    exit;
}

$cobertasPrevia = ($acao === 'previa') ? array_values(array_intersect($lojasArquivo, (array)($_POST['lojas'] ?? []))) : $padraoCobertas;
$previa = $lido ? processar_captura($cards, $cobertasPrevia, $sess['nome'], false, $lido['fonte'], $empresas) : null;
$podeAplicar = (bool)$cards;
$travarLojas = $falhasApi > 0 || !$cards;
$capturas = db()->query('SELECT * FROM capturas_detran ORDER BY id DESC LIMIT 10')->fetchAll();

cabecalho('Importar captura', 'captura');
?>
<h1>Importar captura do DETRAN</h1>
<p class="sub">Envie o <strong>.json</strong> gerado pelo <a href="coletor.php">coletor do portal</a> ou a planilha do robô (<code>C:\RPA\Multas_Geral.xlsx</code>). O sistema separa as multas novas, atualiza as existentes e finaliza as que sumiram do DETRAN — o que hoje é feito colando na aba Detran e filtrando.</p>

<?php if ($erro): ?><div class="aviso aviso-erro"><?= $erro ?></div><?php endif; ?>

<?php if (!$previa): ?>
<section class="card">
  <form method="post" enctype="multipart/form-data">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="enviar">
    <label>Arquivo do coletor (.json) ou do robô (.xlsx)</label>
    <input type="file" name="arquivo" accept=".json,.xlsx" required style="max-width:420px">
    <p><button>Ler arquivo</button></p>
  </form>
</section>
<?php else: ?>
<section class="card">
  <h2>Prévia — <?= h($sess['nome']) ?> (<?= count($cards) ?> multas lidas)</h2>
  <?php if ($empresas): ?>
    <table style="margin-bottom:14px"><thead><tr><th>CNPJ consultado</th><th class="n">Multas</th><th>Resultado</th></tr></thead><tbody>
    <?php foreach ($empresas as $e): ?>
      <tr><td><?= h(preg_replace('~^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$~', '$1.$2.$3/$4-$5', $e['cnpj'])) ?></td><td class="n"><?= $e['total'] ?></td>
        <td><?= $e['ok'] ? '✔ ok' : '<span class="badge b-nova">falhou</span> ' . h($e['erro']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php if ($falhasApi): ?><div class="aviso aviso-erro"><?= $falhasApi ?> CNPJ(s) não responderam. Por segurança, nenhuma loja vem marcada: as multas novas e atualizadas entram, mas nada é finalizado. Rode o coletor de novo mais tarde para uma captura completa.</div><?php endif; ?>
  <?php endif; ?>
  <div class="kpis">
    <div class="kpi" style="--c: var(--nova)"><div class="r">Novas (entram como "Nova")</div><div class="q"><?= count($previa['novas']) ?></div></div>
    <div class="kpi" style="--c: var(--vcto)"><div class="r">Já existentes (atualizar)</div><div class="q"><?= count($previa['atualizadas']) ?></div></div>
    <div class="kpi" style="--c: var(--final)"><div class="r">Saíram do DETRAN (finalizar)</div><div class="q"><?= count($previa['sairam']) ?></div></div>
    <?php if ($previa['repetidas']): ?><div class="kpi"><div class="r">Repetidas no arquivo</div><div class="q"><?= count($previa['repetidas']) ?></div></div><?php endif; ?>
  </div>

  <form method="post">
    <?= csrf_campo() ?>
    <label>Lojas percorridas por completo nesta captura — só multas destas lojas podem ser finalizadas por terem saído do DETRAN</label>
    <div style="display:flex; flex-wrap:wrap; margin-bottom:12px">
      <?php foreach ($lojasArquivo as $l): ?>
        <label class="check"><input type="checkbox" name="lojas[]" value="<?= h($l) ?>" <?= !$travarLojas && in_array($l, $cobertasPrevia, true) ? 'checked' : '' ?>
          <?= $travarLojas ? 'disabled' : '' ?> onchange="this.form.acao.value='previa'; this.form.submit()"> <?= h($l) ?></label>
      <?php endforeach; ?>
    </div>
    <?php if ($travarLojas): ?><p class="mini">Bloqueado: com CNPJ falhando ou captura vazia, o sistema não finaliza nenhuma multa.</p><?php endif; ?>
    <input type="hidden" name="acao" value="aplicar">
    <?php if ($podeAplicar): ?>
      <button onclick="this.form.acao.value='aplicar'">Aplicar captura</button>
    <?php else: ?>
      <div class="aviso aviso-erro">Nenhuma multa veio nesta captura — ela não pode ser aplicada. Verifique o login no portal e rode o coletor de novo.</div>
    <?php endif; ?>
    <button class="btn-sec" onclick="this.form.acao.value='cancelar'">Cancelar</button>
  </form>
</section>

<div class="grade" style="margin-top:16px">
  <section class="card tab-scroll">
    <h2>Novas (<?= count($previa['novas']) ?>)</h2>
    <table><thead><tr><th>Placa</th><th>Loja</th><th>Série</th><th>Data</th><th>Tipo</th><th class="n">Valor</th></tr></thead><tbody>
    <?php foreach ($previa['novas'] as $c): ?>
      <tr><td><?= h($c['placa']) ?></td><td><?= h($c['loja']) ?></td><td><?= h($c['serie']) ?></td>
        <td><?= h($c['data_infracao_texto']) ?></td><td><?= ucfirst($c['tipo']) ?></td><td class="n"><?= brl($c['valor']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </section>
  <section class="card tab-scroll">
    <h2>Saíram do DETRAN (<?= count($previa['sairam']) ?>)</h2>
    <p class="mini">Estavam em aberto e não vieram nesta captura — provavelmente pagas e baixadas.</p>
    <table><tbody>
    <?php if ($previa['sairam']):
        $in = implode(',', array_fill(0, count($previa['sairam']), '?'));
        $st = db()->prepare("SELECT m.id, m.placa, m.serie, m.etapa, l.nome loja FROM multas m LEFT JOIN lojas l ON l.id = m.loja_id WHERE m.serie IN ($in) ORDER BY l.nome, m.placa");
        $st->execute($previa['sairam']);
        foreach ($st as $m): ?>
        <tr><td><?= h($m['placa']) ?></td><td><?= h($m['loja']) ?></td><td><a href="multa.php?id=<?= $m['id'] ?>"><?= h($m['serie']) ?></a></td><td><?= badge_etapa($m['etapa']) ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody></table>
  </section>
</div>
<?php endif; ?>

<section class="card" style="margin-top:16px">
  <h2>Capturas anteriores</h2>
  <table><thead><tr><th>Data</th><th>Arquivo</th><th>Lojas</th><th class="n">Lidas</th><th class="n">Novas</th><th class="n">Atualizadas</th><th class="n">Saíram</th></tr></thead><tbody>
  <?php foreach ($capturas as $c): ?>
    <tr><td><?= data_br($c['criado_em']) ?></td><td><?= h($c['arquivo']) ?></td><td class="mini"><?= h(implode(', ', json_decode($c['lojas_cobertas'], true) ?: [])) ?></td>
      <td class="n"><?= $c['total_lidas'] ?></td><td class="n"><?= $c['novas'] ?></td><td class="n"><?= $c['atualizadas'] ?></td><td class="n"><?= $c['sairam_do_detran'] ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</section>
<?php rodape();
