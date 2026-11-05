<?php
declare(strict_types=1);

session_start();
require 'conexao_inventario.php';
require 'recontagem_lib.php';

/**
 * A grade de recontagem do gestor: itens por locação, na ordem de caminhada,
 * com o sistema e até 4 contagens. O gestor marca itens ou locações e manda
 * para a próxima contagem. A grade se atualiza a cada 15 s.
 *
 * Os dados vêm do recontagem_ajax.php; esta página só monta a moldura.
 */

$perfil = (string)($_SESSION['inventario_login']['perfil'] ?? '');

if (!in_array($perfil, ['admin', 'gestor'], true)) {
    header('Location: index.php');
    exit;
}

$codigo = trim((string)($_GET['codigo'] ?? ''));
$inv    = (int)($_GET['inv'] ?? 0);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Recontagem</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root { --red:#2347C8; --gray-100:#ECEFF1; --gray-200:#E0E0E0; --gray-600:#546E7A; --gray-800:#263238; }
    * { box-sizing: border-box; }
    body { margin:0; font-family: system-ui,-apple-system,"Segoe UI",sans-serif; background:#F5F5F7; color:var(--gray-800); }
    header { padding:10px 16px; background:linear-gradient(135deg,#B71C1C,var(--red)); color:#fff;
             display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    header a { color:#fff; font-size:13px; }
    main { padding:16px; }
    .card { background:#fff; border-radius:14px; padding:12px 14px; box-shadow:0 4px 12px rgba(15,23,42,.08); margin-bottom:12px; }
    .chips { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
    .chip { padding:5px 12px; border-radius:999px; border:1px solid #CFD8DC; background:#fff; font-size:12px; cursor:pointer; }
    .chip.on { background:#1565C0; border-color:#1565C0; color:#fff; }
    table { width:100%; border-collapse:collapse; font-size:12px; }
    th { text-align:right; font-weight:600; color:var(--gray-600); padding:6px 4px; border-bottom:1px solid var(--gray-200); position:sticky; top:0; background:#fff; }
    td { text-align:right; padding:5px 4px; border-bottom:1px solid var(--gray-100); }
    .l { text-align:left; }
    tr.loc td { background:#FAFAFA; text-align:left; font-weight:600; color:var(--gray-600); }
    .neg { color:#C62828; } .pos { color:#E65100; } .ok { color:#2E7D32; } .vz { color:#B0BEC5; }
    .sit { font-size:11px; padding:1px 8px; border-radius:999px; white-space:nowrap; }
    .sit-aguardando { background:#FFF3E0; color:#E65100; }
    .sit-contando   { background:#E3F2FD; color:#0D47A1; }
    .sit-nao_contado{ background:#ECEFF1; color:#546E7A; }
    .sit-bateu      { background:#E8F5E9; color:#1B5E20; }
    .sit-decisao    { background:#FCE4EC; color:#880E4F; }
    .barra { display:flex; gap:8px; align-items:center; flex-wrap:wrap; justify-content:space-between; }
    .btn { padding:7px 14px; border-radius:999px; border:1px solid #CFD8DC; background:#fff; font-size:12px; cursor:pointer; }
    .btn-forte { background:linear-gradient(135deg,var(--red),#FF1744); border:none; color:#fff; }
    .aviso { font-size:12px; color:var(--gray-600); }
    .erro { color:#C62828; font-size:12px; }
    .banner-ok { background:#E8F5E9; color:#1B5E20; border:1px solid #A5D6A7; border-radius:10px;
                 padding:10px 14px; font-size:13px; font-weight:600; margin-bottom:12px; }
    .banner-falta { font-size:12px; color:var(--gray-600); margin-bottom:12px; }
  </style>
</head>
<body>
<header>
  <strong>Recontagem</strong>
  <span id="cab-inv"><?php echo $inv > 0 ? 'Inventário #' . (int)$inv : ''; ?></span>
  <span style="flex:1"></span>
  <a href="dashboard_inventario.php?codigo=<?php echo urlencode($codigo); ?>&inv=<?php echo (int)$inv; ?>">Voltar ao dashboard</a>
</header>

<main>
<?php if ($inv <= 0 || strpos($codigo, '.') === false): ?>
  <div class="card">Escolha a loja e o inventário no dashboard e clique em "Recontagem".</div>
<?php else: ?>
  <div id="banner-conclusao"></div>

  <div class="card">
    <div class="chips" id="chips-situacao">
      <span class="aviso">Mostrar:</span>
      <button class="chip on" data-sit="recontar">Recontar <span data-n="recontar"></span></button>
      <button class="chip" data-sit="a_contar">A contar <span data-n="a_contar"></span></button>
      <button class="chip" data-sit="contado">Contado <span data-n="contado"></span></button>
      <button class="chip" data-sit="todos">Todos</button>
    </div>
    <div class="chips" style="margin-top:8px;">
      <span class="aviso">Contagem atual:</span>
      <select id="f-rodada">
        <option value="0">Todas</option>
        <option value="1">1ª</option><option value="2">2ª</option>
        <option value="3">3ª</option><option value="4">4ª</option>
      </select>
      <input id="f-busca" type="search" placeholder="Item ou locação" style="padding:5px 10px; border-radius:999px; border:1px solid #CFD8DC;">
    </div>
    <div id="terminaram" class="aviso" style="margin-top:8px;"></div>
  </div>

  <div class="card">
    <div class="chips" id="barra-selecao">
      <span class="aviso">Selecionar da locação</span>
      <select id="f-de"></select>
      <span class="aviso">até</span>
      <select id="f-ate"></select>
      <span class="aviso" style="margin-left:8px;">Sinal:</span>
      <button class="chip on" data-sinal="todos">Todos</button>
      <button class="chip" data-sinal="mais">A +</button>
      <button class="chip" data-sinal="menos">A −</button>
    </div>
  </div>

  <div class="card">
    <div class="barra" style="margin-bottom:8px;">
      <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <span id="sel" class="aviso">Nenhum item marcado</span>
        <select id="f-coletor"></select>
        <button class="btn btn-forte" id="btn-mandar">Mandar para contagem</button>
        <button class="btn" id="btn-encerrar">Encerrar recontagem</button>
      </div>
      <div class="aviso"><span id="tot-itens">—</span> · valor em divergência <strong id="tot-valor">—</strong></div>
    </div>
    <div id="msg" class="aviso" role="status"></div>
    <div style="overflow-x:auto;">
      <table>
        <thead>
          <tr>
            <th></th><th class="l">Item</th><th class="l">Descrição</th><th>Sistema</th>
            <th>1ª</th><th>Dif</th><th>2ª</th><th>Dif</th><th>3ª</th><th>Dif</th><th>4ª</th><th>Dif</th>
            <th class="l">Situação</th>
          </tr>
        </thead>
        <tbody id="grade"></tbody>
      </table>
    </div>
    <div id="cortado" class="aviso" style="margin-top:8px;"></div>
  </div>
<?php endif; ?>
</main>

<?php if ($inv > 0 && strpos($codigo, '.') !== false): ?>
<script>
  const LOJA = { codigo: <?php echo json_encode($codigo); ?>, inv: <?php echo (int)$inv; ?> };
  // A coluna Situação da linha mostra a situação detalhada, não o grupo do filtro.
  const ROTULOS = { aguardando:'Recontar', contando:'Contando', nao_contado:'Não contado', bateu:'Bateu', decisao:'Decisão' };
  const el = (id) => document.getElementById(id);

  let filtro = { situacao: 'recontar', rodada: '0', busca: '' };
  let sinal = 'todos';
  let marcados = new Set();
  let linhasAtuais = [];
  let locacoesAtuais = [];
  let coletores = {};
  let ocupado = false;
  let resetSelects = true; // true: o próximo pintar() reseta de/ate para o primeiro/último da lista.
  let locacoesChave = ''; // último d.locacoes.join('\n') pintado nas caixas de/ate — só refaz o <select> quando muda.
  let selSeq = 0; // conta cada selecionar(): uma resposta atrasada de um filtro antigo é descartada, não pintada por cima do filtro novo.

  const moeda = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c]));

  function url(modo, extra) {
    const p = new URLSearchParams(Object.assign({ modo, codigo: LOJA.codigo, inv: LOJA.inv }, extra || {}));
    return 'recontagem_ajax.php?' + p.toString();
  }

  function celContagem(v, sistema) {
    if (v === null) return '<td><span class="vz">—</span></td><td><span class="vz">—</span></td>';
    const d = sistema === null ? null : v - sistema;
    const cls = d === null ? 'vz' : d < 0 ? 'neg' : d > 0 ? 'pos' : 'ok';
    const txt = d === null ? '—' : (d > 0 ? '+' : '') + d;
    return '<td>' + v + '</td><td><span class="' + cls + '">' + txt + '</span></td>';
  }

  function textoSituacao(l) {
    let t = ROTULOS[l.situacao] || l.situacao;
    if (l.situacao === 'contando') {
      t += ' · ' + (l.rodada) + 'ª · ' + (l.coletor ? (coletores[l.coletor] || 'Coletor ' + l.coletor) : 'sem coletor');
    }
    // Uma linha em decisão por ter sido encerrada pelo gestor mostra "· encerrada";
    // uma que chegou na decisão por ter esgotado a 4ª contagem sem bater mostra só "Decisão".
    if (l.situacao === 'decisao' && l.encerrada) {
      t += ' · encerrada';
    }
    return '<span class="sit sit-' + l.situacao + '">' + esc(t) + '</span>';
  }

  function pintar(d) {
    linhasAtuais = d.linhas;
    locacoesAtuais = d.locacoes || [];

    // Só tira do marcado quem está visível e não é mais marcável — quem está
    // fora das linhas mostradas continua marcado (a seleção por faixa marca
    // ids fora das 500 primeiras). O servidor confere tudo de novo no mandar.
    const visiveis = new Map(d.linhas.map((l) => [l.id, l]));
    marcados = new Set([...marcados].filter((id) => {
      const l = visiveis.get(id);
      return l === undefined || l.marcavel;
    }));

    atualizarSelects(resetSelects);
    resetSelects = false;

    let html = '';
    let locAtual = null;
    d.linhas.forEach((l) => {
      if (l.locacao !== locAtual) {
        locAtual = l.locacao;
        const daLoc = d.linhas.filter((x) => x.locacao === locAtual);
        const marcaveis = daLoc.filter((x) => x.marcavel);
        const todas = marcaveis.length > 0 && marcaveis.every((x) => marcados.has(x.id));
        html += '<tr class="loc"><td>' + (marcaveis.length
            ? '<input type="checkbox" data-loc="' + esc(locAtual) + '"' + (todas ? ' checked' : '') + ' aria-label="Marcar a locação ' + esc(locAtual) + '">'
            : '') + '</td><td colspan="12">' + esc(locAtual) + ' · ' + daLoc.length + ' item(ns)</td></tr>';
      }
      html += '<tr><td>' + (l.marcavel
          ? '<input type="checkbox" data-id="' + l.id + '"' + (marcados.has(l.id) ? ' checked' : '') + ' aria-label="Marcar ' + esc(l.item) + '">'
          : '') + '</td>'
        + '<td class="l">' + esc(l.item) + '</td>'
        + '<td class="l" style="max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' + esc(l.descricao) + '</td>'
        + '<td>' + (l.sistema === null ? '—' : l.sistema) + '</td>'
        + celContagem(l.contagens[1], l.sistema) + celContagem(l.contagens[2], l.sistema)
        + celContagem(l.contagens[3], l.sistema) + celContagem(l.contagens[4], l.sistema)
        + '<td class="l">' + textoSituacao(l) + '</td></tr>';
    });
    el('grade').innerHTML = html || '<tr><td colspan="13" class="l aviso">Nenhum item neste filtro.</td></tr>';

    el('tot-itens').textContent = d.total + ' item(ns)';
    el('tot-valor').textContent = moeda(d.valor_divergencia);
    el('cortado').textContent = d.cortado
      ? 'Mostrando as primeiras ' + d.linhas.length + ' de ' + d.total + ' linhas. Use a busca ou o filtro de contagem para ver o resto.'
      : '';

    // Os chips agrupam o resumo em 3 grupos — os mesmos do filtro (recontagem_lib.php).
    const grupos = {
      recontar: d.resumo.aguardando,
      a_contar: d.resumo.contando + d.resumo.nao_contado,
      contado:  d.resumo.bateu + d.resumo.decisao,
    };
    Object.entries(grupos).forEach(([s, n]) => {
      const alvo = document.querySelector('[data-n="' + s + '"]');
      if (alvo) alvo.textContent = '(' + n + ')';
    });

    coletores = d.coletores;
    const sel = el('f-coletor');
    const atual = sel.value;
    sel.innerHTML = Object.entries(coletores).map(([n, r]) => '<option value="' + n + '">' + esc(r) + '</option>').join('');
    if (atual) sel.value = atual;

    el('terminaram').textContent = Object.entries(d.terminaram || {}).map(([n, t]) =>
      (coletores[n] || 'Coletor ' + n) + ' avisou que terminou às ' + t.terminou_em.slice(11, 16) + ' · faltaram ' + t.pendentes
    ).join(' · ');

    pintarConclusao(d.conclusao);
    atualizarSelecao();
  }

  /**
   * O banner acima da grade: verde quando a recontagem está concluída
   * (nada mais aguardando/contando/nunca contado, e algo foi contado), ou a
   * linha neutra "Falta: ..." com só as partes que ainda faltam. Atualiza a
   * cada poll, como o resto da tela.
   */
  function pintarConclusao(c) {
    const alvo = el('banner-conclusao');
    if (!c) { alvo.innerHTML = ''; return; }

    if (c.concluida) {
      alvo.innerHTML = '<div class="banner-ok">✓ Recontagem concluída — ' + c.bateu + ' bateram · '
        + c.decisao + ' em decisão. Próximo passo: Tomada de Decisão.</div>';
      return;
    }

    const partes = [];
    if (c.falta_recontar) partes.push(c.falta_recontar + ' a recontar');
    if (c.falta_contando) partes.push(c.falta_contando + ' com coletor');
    if (c.falta_nunca_contados) partes.push(c.falta_nunca_contados + ' nunca contados');

    alvo.innerHTML = partes.length ? '<div class="banner-falta">Falta: ' + partes.join(' · ') + '</div>' : '';
  }

  function atualizarSelecao() {
    const n = marcados.size;
    el('sel').textContent = n ? n + ' item(ns) marcado(s)' : 'Nenhum item marcado';
  }

  /**
   * Preenche as caixas "de"/"até" com as locações do filtro atual. Mantém a
   * escolha do gestor se ela ainda existir na lista nova; senão volta para a
   * primeira/última — inclusive quando o filtro/rodada/busca mudou (reset=true).
   */
  function atualizarSelects(reset) {
    const de = el('f-de');
    const ate = el('f-ate');

    // Só refaz as <option> quando a lista de locações realmente mudou — do
    // contrário, a cada poll de 15 s um <select> aberto fecharia e o gestor
    // perderia o que estava sob o cursor.
    const chave = locacoesAtuais.join('\n');
    const mudou = chave !== locacoesChave;
    if (mudou) {
      locacoesChave = chave;
      const opts = locacoesAtuais.map((l) => '<option value="' + esc(l) + '">' + esc(l) + '</option>').join('');
      de.innerHTML = opts;
      ate.innerHTML = opts;
    }

    if (locacoesAtuais.length === 0) return;

    const prevDe = de.value;
    const prevAte = ate.value;
    const primeira = locacoesAtuais[0];
    const ultima = locacoesAtuais[locacoesAtuais.length - 1];
    de.value = (!reset && locacoesAtuais.includes(prevDe)) ? prevDe : primeira;
    ate.value = (!reset && locacoesAtuais.includes(prevAte)) ? prevAte : ultima;
  }

  /**
   * Pede ao servidor os ids da faixa de/ate + sinal, no filtro atual, e
   * SUBSTITUI o marcado por eles (não soma) — o gestor ainda pode ajustar
   * linha a linha depois.
   */
  async function selecionar() {
    if (locacoesAtuais.length === 0) { marcados = new Set(); atualizarSelecao(); return; }

    const de = el('f-de').value;
    const ate = el('f-ate').value;

    // Cada chamada leva o número da vez. Se o filtro mudar (chip, rodada,
    // busca) antes desta resposta voltar, selSeq já avançou lá na frente —
    // uma resposta atrasada do filtro antigo marcaria itens escondidos pelo
    // filtro novo, e o Mandar mandaria gente errada sem o gestor perceber.
    const meu = ++selSeq;

    try {
      const r = await fetch(url('selecionar', Object.assign({}, filtro, { de, ate, sinal })));
      const d = await r.json();
      if (meu !== selSeq) return;

      if (!d.ok) {
        if (d.sessao === false) { window.location.href = 'index.php'; return; }
        el('msg').innerHTML = '<span class="erro">' + esc(d.mensagem) + '</span>';
        return;
      }
      marcados = new Set(d.ids);
      el('grade').querySelectorAll('input[data-id]').forEach((c) => { c.checked = marcados.has(Number(c.dataset.id)); });
      el('grade').querySelectorAll('input[data-loc]').forEach((c) => {
        const daLoc = linhasAtuais.filter((l) => l.locacao === c.dataset.loc && l.marcavel);
        c.checked = daLoc.length > 0 && daLoc.every((l) => marcados.has(l.id));
      });
      atualizarSelecao();
      el('msg').textContent = marcados.size
        ? marcados.size + ' item(ns) marcado(s) pela faixa.'
        : 'Nenhum item na faixa.';
    } catch (e) {
      if (meu !== selSeq) return;
      el('msg').innerHTML = '<span class="erro">Sem conexão com o servidor.</span>';
    }
  }

  async function carregar() {
    if (ocupado) return;
    ocupado = true;
    try {
      const r = await fetch(url('grade', filtro));
      const d = await r.json();
      if (!d.ok) {
        if (d.sessao === false) { window.location.href = 'index.php'; return; }
        el('msg').innerHTML = '<span class="erro">' + esc(d.mensagem) + '</span>';
        return;
      }
      pintar(d);
    } catch (e) {
      el('msg').innerHTML = '<span class="erro">Sem conexão com o servidor. Tentando de novo em 15 s.</span>';
    } finally {
      ocupado = false;
    }
  }

  el('grade').addEventListener('change', (ev) => {
    const t = ev.target;
    if (t.dataset.id) {
      const id = Number(t.dataset.id);
      t.checked ? marcados.add(id) : marcados.delete(id);
    }
    if (t.dataset.loc !== undefined) {
      linhasAtuais.filter((l) => l.locacao === t.dataset.loc && l.marcavel)
        .forEach((l) => { t.checked ? marcados.add(l.id) : marcados.delete(l.id); });
      el('grade').querySelectorAll('input[data-id]').forEach((c) => { c.checked = marcados.has(Number(c.dataset.id)); });
    }
    atualizarSelecao();
  });

  el('chips-situacao').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-sit]');
    if (!b) return;
    document.querySelectorAll('[data-sit]').forEach((x) => x.classList.toggle('on', x === b));
    filtro.situacao = b.dataset.sit;
    marcados.clear();
    resetSelects = true;
    selSeq++; // descarta qualquer selecionar() do filtro anterior ainda em voo.
    carregar();
  });
  el('f-rodada').addEventListener('change', () => {
    filtro.rodada = el('f-rodada').value;
    marcados.clear();
    resetSelects = true;
    selSeq++;
    carregar();
  });
  let espera = null;
  el('f-busca').addEventListener('input', () => {
    clearTimeout(espera);
    espera = setTimeout(() => {
      filtro.busca = el('f-busca').value.trim();
      marcados.clear();
      resetSelects = true;
      selSeq++;
      carregar();
    }, 400);
  });

  el('f-de').addEventListener('change', selecionar);
  el('f-ate').addEventListener('change', selecionar);
  el('barra-selecao').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-sinal]');
    if (!b) return;
    document.querySelectorAll('[data-sinal]').forEach((x) => x.classList.toggle('on', x === b));
    sinal = b.dataset.sinal;
    selecionar();
  });

  // Rótulos dos motivos de recusa do recontagem_mandar — usados tanto no
  // resultado quanto na confirmação prévia, para as duas telas falarem igual.
  const ROTULOS_RECUSA = {
    bateu: 'já bateram', decisao: 'estão em decisão', descartado: 'foram descartados',
    teto: 'já estão na última contagem', nao_encontrado: 'não foram encontrados',
    mudou: 'mudaram por outra pessoa agora há pouco',
  };

  /**
   * Botão único: pede a prévia (porque a marca pode ter ids fora da tela —
   * seleção por faixa), confirma com o gestor, manda de verdade e mostra o
   * resultado. mandar/trocar_coletor continuam existindo no servidor (os
   * testes usam), só não têm mais botão próprio aqui.
   */
  async function agirEnviar() {
    if (marcados.size === 0) { el('msg').innerHTML = '<span class="erro">Marque ao menos um item.</span>'; return; }
    const coletor = el('f-coletor').value;
    const nome = coletores[coletor] || ('Coletor ' + coletor);
    const ids = [...marcados];
    const corpoIds = new URLSearchParams({ ids: JSON.stringify(ids) });

    let p;
    try {
      const r = await fetch(url('previa'), { method: 'POST', body: corpoIds });
      p = await r.json();
    } catch (e) {
      el('msg').innerHTML = '<span class="erro">Sem conexão com o servidor.</span>';
      return;
    }
    if (!p.ok) { el('msg').innerHTML = '<span class="erro">' + esc(p.mensagem) + '</span>'; return; }

    const partes = [];
    if (p.para_proxima) partes.push(p.para_proxima + ' item(ns) vão para a próxima contagem');
    if (p.trocar) partes.push(p.trocar + ' que já estão sendo contados passam para ' + nome);
    let pergunta = partes.length ? partes.join(' e ') + '.' : 'Nenhum dos itens marcados pode ser mandado agora.';
    if (p.recusados) {
      pergunta += '\n\n' + p.recusados + ' não podem ser mandados (já bateram, estão em decisão ou na última contagem).';
    }
    if (!confirm(pergunta)) return;

    const corpo = new URLSearchParams({ ids: JSON.stringify(ids), coletor });
    let d;
    try {
      const r = await fetch(url('enviar'), { method: 'POST', body: corpo });
      d = await r.json();
    } catch (e) {
      el('msg').innerHTML = '<span class="erro">Sem conexão com o servidor.</span>';
      return;
    }
    if (!d.ok) { el('msg').innerHTML = '<span class="erro">' + esc(d.mensagem) + '</span>'; return; }

    const rec = Object.entries(d.recusados || {}).filter(([, n]) => n > 0)
      .map(([m, n]) => n + ' ' + (ROTULOS_RECUSA[m] || m));
    const resultado = [];
    if (d.enviados) resultado.push(d.enviados + ' mandado(s) para a próxima contagem');
    if (d.trocados) resultado.push(d.trocados + ' passado(s) para ' + nome);
    el('msg').textContent = (resultado.length ? resultado.join(' · ') + '.' : 'Nada foi mandado.')
      + (rec.length ? ' Ficaram de fora: ' + rec.join(', ') + '.' : '');

    marcados.clear();
    carregar();
  }

  /**
   * Botão "Encerrar recontagem": os itens marcados param de ser recontados e
   * vão para Decisão com as contagens que já têm. Mesma dança do agirEnviar —
   * prévia primeiro (ids podem estar fora da tela), confirma, encerra de
   * verdade, mostra o resultado.
   */
  async function agirEncerrar() {
    if (marcados.size === 0) { el('msg').innerHTML = '<span class="erro">Marque ao menos um item.</span>'; return; }
    const ids = [...marcados];
    const corpoIds = new URLSearchParams({ ids: JSON.stringify(ids) });

    let p;
    try {
      const r = await fetch(url('previa'), { method: 'POST', body: corpoIds });
      p = await r.json();
    } catch (e) {
      el('msg').innerHTML = '<span class="erro">Sem conexão com o servidor.</span>';
      return;
    }
    if (!p.ok) { el('msg').innerHTML = '<span class="erro">' + esc(p.mensagem) + '</span>'; return; }

    const naoPodem = ids.length - p.encerraveis;
    let pergunta = p.encerraveis + ' item(ns) param de ser recontados e vão para Decisão com as contagens que já têm.';
    if (naoPodem > 0) {
      pergunta += '\n\n' + naoPodem + ' não podem ser encerrados (já bateram ou já estão em decisão).';
    }
    if (!confirm(pergunta)) return;

    const corpo = new URLSearchParams({ ids: JSON.stringify(ids) });
    let d;
    try {
      const r = await fetch(url('encerrar'), { method: 'POST', body: corpo });
      d = await r.json();
    } catch (e) {
      el('msg').innerHTML = '<span class="erro">Sem conexão com o servidor.</span>';
      return;
    }
    if (!d.ok) { el('msg').innerHTML = '<span class="erro">' + esc(d.mensagem) + '</span>'; return; }

    el('msg').textContent = d.encerrados + ' item(ns) encerrado(s) e mandado(s) para Decisão.';

    marcados.clear();
    carregar();
  }

  el('btn-mandar').addEventListener('click', agirEnviar);
  el('btn-encerrar').addEventListener('click', agirEncerrar);

  carregar();
  setInterval(carregar, 15000);
</script>
<?php endif; ?>
</body>
</html>
