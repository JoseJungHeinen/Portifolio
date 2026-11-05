<?php
require __DIR__ . '/_layout.php';

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT m.*, l.nome loja, v.modelo, v.renavam, i.descricao infracao_ctb, i.gravidade
    FROM multas m LEFT JOIN lojas l ON l.id = m.loja_id LEFT JOIN veiculos_frota v ON v.id = m.veiculo_id
    LEFT JOIN infracoes_ctb i ON i.codigo = m.codigo_infracao WHERE m.id = ?');
$st->execute([$id]);
$m = $st->fetch();
if (!$m) { http_response_code(404); exit('Multa não encontrada'); }

/** Campos do checklist editáveis na tela → rótulo. */
const CAMPOS = [
    'prazo_identificacao' => 'Prazo de identificar',
    'prazo_identificacao_nota' => 'Sem prazo (motivo)',
    'email_enviado' => 'E-mail enviado',
    'identificacao_condutor' => 'Condutor identificado',
    'condutor_nome' => 'Nome do condutor',
    'fatura_numero' => 'Nº da fatura',
    'pagamento_situacao' => 'Pagamento',
    'pagamento_data' => 'Data do pagamento',
    'ocorrencia_especial' => 'Ocorrência especial',
    'observacoes' => 'Observações',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $novo = $m;
    foreach (CAMPOS as $c => $_) {
        $v = trim((string)($_POST[$c] ?? ''));
        $novo[$c] = $v === '' ? null : $v;
    }
    if ($novo['ocorrencia_especial'] !== null && $novo['ocorrencia_especial'] !== 'fraude') $novo['ocorrencia_especial'] = null;
    $lojaNova = loja_canonica($_POST['loja'] ?? '');
    $novo['loja_id'] = $lojaNova ? loja_id($lojaNova) : null;
    $novo = com_status($novo);

    $pdo = db();
    $pdo->beginTransaction();
    $sets = implode(', ', array_map(fn($c) => "$c = ?", [...array_keys(CAMPOS), 'loja_id', 'etapa', 'status']));
    $pdo->prepare("UPDATE multas SET $sets WHERE id = ?")
        ->execute([...array_map(fn($c) => $novo[$c], array_keys(CAMPOS)), $novo['loja_id'], $novo['etapa'], $novo['status'], $id]);
    $hist = $pdo->prepare('INSERT INTO multas_historico (multa_id, campo, de, para) VALUES (?, ?, ?, ?)');
    $mudou = 0;
    foreach ([...array_keys(CAMPOS), 'etapa'] as $c) {
        if ((string)$m[$c] !== (string)$novo[$c]) { $hist->execute([$id, $c, $m[$c], $novo[$c]]); $mudou++; }
    }
    if ((string)$m['loja_id'] !== (string)$novo['loja_id']) { $hist->execute([$id, 'loja', $m['loja'], $lojaNova]); $mudou++; }
    $pdo->commit();
    flash($mudou ? 'Multa atualizada. Etapa: <strong>' . h(ETAPAS[$novo['etapa']]) . '</strong>' : 'Nada foi alterado.');
    header("Location: multa.php?id=$id");
    exit;
}

$historico = db()->prepare('SELECT * FROM multas_historico WHERE multa_id = ? ORDER BY id DESC LIMIT 50');
$historico->execute([$id]);
$historico = $historico->fetchAll();

$proximo = [
    'falta_data' => 'Informe o <strong>prazo de identificação</strong> (ou o motivo de não haver prazo).',
    'falta_email' => 'Registre se o <strong>e-mail</strong> ao cliente foi enviado.',
    'falta_identificar' => 'Registre se o <strong>condutor foi identificado</strong>.',
    'falta_faturar' => 'Fature ao cliente e informe o <strong>nº da fatura</strong>.',
    'aguardando_vcto' => 'O DETRAN ainda não deu vencimento. Aguarde — a próxima captura atualiza.',
    'falta_pagar' => 'Pague a infração e registre o <strong>pagamento</strong>.',
    'aguardando_baixar' => 'Paga. Sai sozinha quando deixar de aparecer na captura do DETRAN.',
    'finalizado_detran' => 'Finalizada: não aparece mais no DETRAN.',
    'fraude' => 'Marcada como fraude: fora dos totais.',
][$m['etapa']];

$val = fn($c) => h($m[$c] ?? '');
cabecalho($m['placa'] . ' · ' . $m['serie'], 'multas');
?>
<p><a href="javascript:history.back()">‹ voltar</a></p>
<h1><?= h($m['placa']) ?> · <?= h($m['serie']) ?> <?= badge_etapa($m['etapa']) ?></h1>
<p class="sub"><?= h(implode(' · ', array_filter([$m['loja'], $m['modelo'] ?: 'veículo não cadastrado na frota', brl($m['valor'])]))) ?></p>

<div class="passo">Próximo passo: <?= $proximo ?></div>

<div class="grade">
<section class="card">
  <h2>Checklist</h2>
  <form method="post" class="form">
    <?= csrf_campo() ?>
    <div><label>Loja</label><select name="loja"><option value="">— sem loja —</option>
      <?php foreach (LOJAS as $l): ?><option <?= $l === $m['loja'] ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
    <div><label>Prazo de identificar</label><input type="date" name="prazo_identificacao" value="<?= $val('prazo_identificacao') ?>"></div>
    <div><label>…ou motivo de não haver prazo</label><input name="prazo_identificacao_nota" list="l-nota" value="<?= $val('prazo_identificacao_nota') ?>"></div>
    <div><label>E-mail enviado</label><input name="email_enviado" list="l-simnao" value="<?= $val('email_enviado') ?>"></div>
    <div><label>Condutor identificado</label><input name="identificacao_condutor" list="l-ident" value="<?= $val('identificacao_condutor') ?>"></div>
    <div><label>Nome do condutor</label><input name="condutor_nome" value="<?= $val('condutor_nome') ?>"></div>
    <div><label>Nº da fatura</label><input name="fatura_numero" value="<?= $val('fatura_numero') ?>"></div>
    <div><label>Pagamento</label><input name="pagamento_situacao" list="l-pag" value="<?= $val('pagamento_situacao') ?>"></div>
    <div><label>Data do pagamento</label><input type="date" name="pagamento_data" value="<?= $val('pagamento_data') ?>"></div>
    <div><label>Ocorrência especial</label>
      <select name="ocorrencia_especial"><option value="">—</option><option value="fraude" <?= $m['ocorrencia_especial'] === 'fraude' ? 'selected' : '' ?>>Fraude</option></select></div>
    <div class="full"><label>Observações</label><textarea name="observacoes"><?= $val('observacoes') ?></textarea></div>
    <div class="full"><button>Salvar</button> <span class="mini">A etapa é recalculada ao salvar.</span></div>
  </form>
  <datalist id="l-simnao"><option>Sim</option><option>Não</option></datalist>
  <datalist id="l-ident"><option>Sim</option><option>Não</option><option>VENDIDO</option></datalist>
  <datalist id="l-pag"><option>Boleto impresso <?= date('d/m') ?></option><option>PAGA</option><option>ADVERTENCIA</option></datalist>
  <datalist id="l-nota"><option>Não se aplica</option><option>recurso</option><option>VENDIDO</option></datalist>
</section>

<section class="card">
  <h2>Dados do DETRAN</h2>
  <dl class="dl">
    <dt>Infração</dt><dd><?= h($m['descricao'] ?: $m['infracao_ctb']) ?></dd>
    <dt>Código</dt><dd><?= h($m['codigo_infracao'] ?: '—') ?> <?= $m['gravidade'] ? '· ' . h($m['gravidade']) : '' ?></dd>
    <dt>Tipo</dt><dd><?= $m['tipo'] ? ucfirst($m['tipo']) : '—' ?></dd>
    <dt>Data / hora</dt><dd><?= h($m['data_infracao_texto'] ?: data_br($m['data_infracao'])) ?></dd>
    <dt>Local</dt><dd><?= h($m['local_infracao'] ?: '—') ?></dd>
    <dt>Órgão</dt><dd><?= h($m['orgao_codigo']) ?> · <?= h($m['autuador']) ?></dd>
    <dt>Vencimento</dt><dd><?= $m['vencimento_pendente'] ? 'ainda sem vencimento ("--")' : (data_br($m['vencimento_detran']) ?: '—') ?>
      <?= $m['situacao_detran'] === 'vencida' ? '<span class="badge b-nova">vencida</span>' : '' ?></dd>
    <dt>No DETRAN</dt><dd><?= $m['presente_no_detran'] ? 'sim' : 'não (saiu em ' . (data_br($m['saiu_do_detran_em']) ?: 'data desconhecida') . ')' ?></dd>
    <dt>1ª captura</dt><dd><?= data_br($m['primeira_captura_em']) ?: '—' ?></dd>
    <dt>Renavam</dt><dd><?= h($m['renavam'] ?: '—') ?></dd>
    <dt>Origem</dt><dd><?= h($m['origem']) ?></dd>
  </dl>
</section>

<section class="card">
  <h2>Histórico</h2>
  <?php if (!$historico): ?><p class="mini">Sem alterações registradas.</p><?php else: ?>
  <table><tbody>
  <?php foreach ($historico as $hh): $rot = CAMPOS[$hh['campo']] ?? ucfirst($hh['campo']);
      $fmt = fn($v) => $hh['campo'] === 'etapa' && $v ? (ETAPAS[$v] ?? $v) : ($v ?? '—'); ?>
    <tr><td class="mini"><?= data_br($hh['criado_em']) ?><br><?= h($hh['origem']) ?></td>
      <td><strong><?= h($rot) ?></strong><br><span class="mini"><?= h($fmt($hh['de'])) ?> → </span><?= h($fmt($hh['para'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
</div>
<?php rodape();
