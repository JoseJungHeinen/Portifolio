<?php
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/captura_db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $lista = [];
    foreach ((array)($_POST['cnpj'] ?? []) as $i => $c) {
        $c = preg_replace('~\D~', '', (string)$c);
        if (strlen($c) !== 14) continue;
        $lista[$c] = ['cnpj' => $c, 'loja' => loja_canonica($_POST['loja'][$i] ?? '') ?? ''];
    }
    db()->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('coletor_cnpjs', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute([json_encode(array_values($lista), JSON_UNESCAPED_UNICODE)]);
    flash('CNPJs salvos. <strong>Arraste o botão de novo</strong> para a barra de favoritos — ele leva a lista atualizada.');
    header('Location: coletor.php');
    exit;
}

$cnpjs = cnpjs_coletor();
$fonte = file_get_contents(__DIR__ . '/assets/coletor.js');
$fonte = preg_replace('~/\*.*?\*/~s', '', $fonte);
$fonte = str_replace('__CNPJS__', json_encode(array_map(fn($c) => ['cnpj' => $c['cnpj']], $cnpjs)), $fonte);
// Endereço deste sistema, para o botão abrir a prévia aqui (http://localhost:8090 no teste).
$sistema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$fonte = str_replace('__SISTEMA__', json_encode($sistema), $fonte);
$fonte = preg_replace('~\n\s*~', "\n", trim($fonte));
$bookmarklet = 'javascript:' . rawurlencode($fonte);
$lojas = LOJAS;
$fmt = fn($c) => preg_replace('~^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$~', '$1.$2.$3/$4-$5', $c);

cabecalho('Coletor do portal', 'coletor');
?>
<h1>Coletor de multas do portal PCSDetran</h1>
<p class="sub">Substitui o robô RPA. Um botão de favorito que, com você logado no portal, consulta as multas de cada CNPJ pela própria API do DETRAN e baixa um arquivo para importar aqui.</p>

<div class="grade">
<section class="card">
  <h2>1. Instalar (uma vez)</h2>
  <p>Mostre a barra de favoritos do Chrome (<kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>B</kbd>) e <strong>arraste este botão</strong> para ela:</p>
  <p><a class="btn" href="<?= h($bookmarklet) ?>" onclick="alert('Arraste este botão para a barra de favoritos. Ele só funciona clicado de dentro do portal PCSDetran.'); return false;">📥 Coletar multas DETRAN</a></p>
  <p class="mini">Consulta <?= count($cnpjs) ?> CNPJ(s). Se mudar a lista abaixo, arraste o botão de novo e apague o antigo.</p>

  <h2 style="margin-top:20px">2. Usar (todo dia, 8h)</h2>
  <ol>
    <li>Entre em <strong>pcsdetran.rs.gov.br</strong> com o gov.br (certificado A1).</li>
    <li>Abra <strong>Minhas Empresas</strong> (garante que a sessão está carregada).</li>
    <li>Clique no favorito <strong>Coletar multas DETRAN</strong>. Um quadro no canto mostra o andamento e o sistema abre numa nova aba <strong>já com a prévia</strong>.</li>
    <li>Confira a prévia e clique em <strong>Aplicar captura</strong>.</li>
  </ol>
  <p class="mini">Se o navegador bloquear a nova aba, permita pop-ups do pcsdetran.rs.gov.br — ou use o arquivo <code>multas_detran_….json</code> que o botão baixa nesse caso, em <a href="captura.php">Importar captura</a>.</p>
  <p class="mini">O coletor só faz consultas (leitura). Não clica em nada no portal, não gera guia, não apresenta condutor. O arquivo não leva token nem CPF — só os dados das multas.</p>
</section>

<section class="card">
  <h2>CNPJs consultados</h2>
  <p class="mini">A loja de cada multa vem do cadastro do veículo. A loja abaixo só é usada quando a placa não está cadastrada na frota.</p>
  <form method="post">
    <?= csrf_campo() ?>
    <table><thead><tr><th>CNPJ</th><th>Loja (se a placa não estiver cadastrada)</th></tr></thead><tbody>
    <?php foreach (array_merge($cnpjs, [['cnpj' => '', 'loja' => ''], ['cnpj' => '', 'loja' => '']]) as $i => $c): ?>
      <tr>
        <td><input name="cnpj[<?= $i ?>]" value="<?= h($fmt($c['cnpj'])) ?>" placeholder="11.222.333/0001-81"></td>
        <td><select name="loja[<?= $i ?>]"><option value="">—</option>
          <?php foreach ($lojas as $l): ?><option <?= $l === $c['loja'] ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <p class="mini">Para remover um CNPJ, apague o número e salve.</p>
    <p><button>Salvar CNPJs</button></p>
  </form>
</section>
</div>
<?php rodape();
