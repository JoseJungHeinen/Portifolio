<?php
/**
 * Janela aberta pelo botão "Coletar multas DETRAN".
 * Recebe os dados por postMessage — só aceita mensagens vindas de https://pcsdetran.rs.gov.br —
 * salva como uma captura pendente e abre a prévia em captura.php.
 */
require __DIR__ . '/_layout.php';

const ORIGEM_PORTAL = 'https://pcsdetran.rs.gov.br';
$pastaUploads = __DIR__ . '/../data/uploads';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    csrf_validar();
    $corpo = $_POST['arquivo'] ?? '';
    $j = json_decode($corpo, true);
    if (!is_array($j) || ($j['formato'] ?? '') !== 'pcsdetran-api' || !is_array($j['empresas'] ?? null)) {
        http_response_code(400);
        exit(json_encode(['ok' => false, 'erro' => 'Dados inválidos']));
    }
    if (!is_dir($pastaUploads)) mkdir($pastaUploads, 0775, true);
    $id = bin2hex(random_bytes(8));
    file_put_contents("$pastaUploads/$id.json", $corpo);
    $nome = preg_replace('~[^\w.\-]~', '_', (string)($j['nome'] ?? 'coletor.json'));
    $_SESSION['captura'] = ['id' => $id, 'ext' => 'json', 'nome' => $nome];
    exit(json_encode(['ok' => true]));
}

cabecalho('Recebendo do portal', 'captura');
?>
<h1>Recebendo multas do portal…</h1>
<p class="sub" id="st">Aguardando os dados do coletor na janela do portal PCSDetran. Não feche esta janela.</p>
<script>
(() => {
  const ORIGEM = <?= json_encode(ORIGEM_PORTAL) ?>;
  const CSRF = <?= json_encode(csrf_token()) ?>;
  const st = document.getElementById('st');
  let recebido = false;
  addEventListener('message', async ev => {
    if (ev.origin !== ORIGEM || recebido || !ev.data || ev.data.tipo !== 'multas-detran') return;
    recebido = true;
    st.textContent = 'Dados recebidos. Preparando a prévia…';
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('arquivo', JSON.stringify(ev.data.arquivo));
    try {
      const r = await fetch('receber.php', { method: 'POST', body: fd });
      const j = await r.json();
      if (!j.ok) throw new Error(j.erro || 'erro');
      ev.source.postMessage('multas-recebido', ORIGEM);
      location.href = 'captura.php';
    } catch (e) {
      recebido = false;
      st.textContent = 'Não foi possível salvar os dados: ' + e.message;
    }
  });
  // Avisa o portal que esta janela está pronta para receber.
  if (window.opener) window.opener.postMessage('multas-pronto', ORIGEM);
  setTimeout(() => { if (!recebido) st.textContent = 'Nenhum dado recebido. Abra o portal PCSDetran e clique no botão "Coletar multas DETRAN".'; }, 30000);
})();
</script>
<?php rodape();
