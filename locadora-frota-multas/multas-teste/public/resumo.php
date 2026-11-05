<?php
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/painel.php';

$d = dados_painel();
$texto = resumo_texto($d);
$v = time();

cabecalho('Resumo para WhatsApp', 'resumo');
?>
<h1>Resumo para o WhatsApp</h1>
<p class="sub">Substitui o print do "Gráfico - Controle". Copie e cole no grupo com <kbd>Ctrl</kbd>+<kbd>V</kbd>.</p>

<?php if (!$d['capturaHoje'] && (int)date('N') <= 5): ?>
  <div class="aviso aviso-erro">Atenção: ainda não houve captura do DETRAN hoje — os números podem estar desatualizados.</div>
<?php endif; ?>

<div class="grade" style="grid-template-columns: minmax(300px, 460px) 1fr; align-items:start">
  <section class="card" style="padding:10px">
    <img id="img" src="resumo_imagem.php?v=<?= $v ?>" alt="Painel Andamento das Multas" style="width:100%; display:block; border-radius:6px">
  </section>
  <section class="card">
    <h2>Enviar no grupo</h2>
    <p><button id="copiarImg">📋 Copiar imagem</button>
       <a class="btn btn-sec" href="resumo_imagem.php?baixar=1&v=<?= $v ?>">⬇ Baixar PNG</a></p>
    <p class="mini" id="st">Depois de copiar, abra o grupo no WhatsApp (Web ou Desktop) e cole com Ctrl+V.</p>

    <h2 style="margin-top:22px">Ou em texto</h2>
    <textarea id="txt" rows="16" readonly style="font-family:ui-monospace,Consolas,monospace; font-size:13px"><?= h($texto) ?></textarea>
    <p><button class="btn-sec" id="copiarTxt">📋 Copiar texto</button></p>
  </section>
</div>

<script>
const st = document.getElementById('st');
document.getElementById('copiarImg').onclick = async () => {
  try {
    const blob = await fetch(document.getElementById('img').src).then(r => r.blob());
    await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
    st.textContent = '✔ Imagem copiada. Cole no grupo com Ctrl+V.';
  } catch (e) {
    st.textContent = 'Não deu para copiar automaticamente (' + e.message + '). Use "Baixar PNG" ou clique com o botão direito na imagem → Copiar imagem.';
  }
};
document.getElementById('copiarTxt').onclick = async () => {
  const t = document.getElementById('txt');
  try { await navigator.clipboard.writeText(t.value); } catch (_) { t.select(); document.execCommand('copy'); }
  document.getElementById('copiarTxt').textContent = '✔ Texto copiado';
};
</script>
<?php rodape();
