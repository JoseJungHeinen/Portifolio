/* Coletor de multas — PCSDetran.
   Roda como botão de favorito na página do portal, com a sessão já logada.
   Faz SOMENTE consultas de leitura (GET infracoes/requerentes) e baixa um .json
   para importar no sistema. Não clica em nada no portal, não envia dados a terceiros,
   não grava o token nem o CPF no arquivo.
   __CNPJS__ é substituído pela lista configurada no sistema. */
(async () => {
  const CNPJS = __CNPJS__;
  const SISTEMA = __SISTEMA__;
  /* Abre a janela do sistema já no clique (senão o navegador bloqueia o pop-up).
     Os dados vão para ela por postMessage — o portal não faz nenhuma chamada à rede local. */
  const janela = /(^|\.)pcsdetran\.rs\.gov\.br$/.test(location.hostname) ? window.open(SISTEMA + '/receber.php', 'multas_sistema') : null;
  const API = 'https://pcsdetran.procergs.com.br/pcsdetran/rest';
  const CAMPOS = ['serieAIT', 'placa', 'ufPlaca', 'marcaVeiculo', 'codOat', 'orgaoFiscalizador', 'codInfracao',
    'descrInfracao', 'local', 'data', 'hora', 'valor', 'grupo', 'codGrupo', 'dataLimApresCond', 'pontuacao', 'podeGerarGAD'];

  if (!/(^|\.)pcsdetran\.rs\.gov\.br$/.test(location.hostname)) {
    alert('Abra o portal pcsdetran.rs.gov.br, entre com o gov.br e clique de novo no favorito.');
    return;
  }
  const ler = k => sessionStorage.getItem(k) || localStorage.getItem(k);
  /* O token pode estar no sessionStorage ou no localStorage (às vezes um antigo, vencido, sobra num deles).
     Usa o que tiver a validade mais longa, olhando expires_at e o "exp" do próprio token. */
  const expDoJwt = t => { try { return JSON.parse(atob(t.split('.')[1].replace(/-/g, '+').replace(/_/g, '/'))).exp * 1000; } catch (_) { return 0; } };
  const candidatos = [['sessionStorage', sessionStorage], ['localStorage', localStorage]]
    .map(([onde, s]) => { const t = s.getItem('access_token'); return t && { onde, t, exp: Number(s.getItem('expires_at') || 0) || expDoJwt(t) }; })
    .filter(Boolean).sort((a, b) => b.exp - a.exp);
  const escolhido = candidatos[0] || null;
  const token = escolhido && escolhido.t;
  const expira = escolhido ? escolhido.exp : 0;
  /* O portal manda, em toda chamada à API, "X-User-Id: base64(cpf do login)" — tirado dos dados do login. */
  let claims = {};
  try { claims = JSON.parse((escolhido && (escolhido.onde === 'sessionStorage' ? sessionStorage : localStorage).getItem('id_token_claims_obj')) || ler('id_token_claims_obj') || '{}'); } catch (_) { }
  const userId = claims.cpf ? btoa(String(claims.cpf)) : null;
  if (!token || (expira && expira < Date.now())) {
    if (janela) janela.close();
    alert('Sessão do portal não encontrada ou expirada. Entre com o gov.br e tente de novo.');
    return;
  }
  let cpf = claims.cpf ? String(claims.cpf).replace(/\D/g, '') : null;
  if (cpf && cpf.length !== 11) cpf = null;
  if (!cpf) for (const e of performance.getEntriesByType('resource')) {
    const m = /\/pessoas\/(?:autorizacoes\/)?(\d{11})(?:\/|$|\?)/.exec(e.name) || /\/requerentes\/(\d{11})/.exec(e.name);
    if (m) { cpf = m[1]; break; }
  }
  if (!cpf) {
    try {
      const c = JSON.parse(ler('id_token_claims_obj') || '{}');
      cpf = [c.cpf, c.preferred_username, c.sub].map(v => String(v || '').replace(/\D/g, '')).find(v => v.length === 11) || null;
    } catch (_) { }
  }
  if (!cpf) {
    if (janela) janela.close();
    alert('Não consegui identificar o usuário logado. Abra "Minhas Empresas" no portal e clique de novo no favorito.');
    return;
  }

  const painel = document.createElement('div');
  painel.style.cssText = 'position:fixed;z-index:2147483647;right:16px;bottom:16px;width:360px;max-height:70vh;overflow:auto;' +
    'background:#fff;color:#1d2421;border:2px solid #0f6e56;border-radius:10px;padding:14px;font:13px/1.45 system-ui,sans-serif;box-shadow:0 8px 30px rgba(0,0,0,.25)';
  painel.innerHTML = '<b style="color:#0f6e56">Coletor de multas</b><div id="__col_log" style="margin-top:8px"></div>';
  document.body.appendChild(painel);
  const log = t => { const d = document.createElement('div'); d.textContent = t; painel.querySelector('#__col_log').appendChild(d); };
  const espera = ms => new Promise(r => setTimeout(r, ms));

  async function consultar(cnpj) {
    const url = `${API}/infracoes/requerentes/${cpf}?grupo=5&cnpj=${cnpj}`;
    let ultimoErro = '';
    for (const pausa of [0, 5000, 15000, 30000]) {
      if (pausa) { log(`   tentando de novo em ${pausa / 1000}s...`); await espera(pausa); }
      const ctl = new AbortController();
      const t = setTimeout(() => ctl.abort(), 90000);
      try {
        const cab = { Authorization: 'Bearer ' + token, Accept: 'application/json' };
        if (userId) cab['X-User-Id'] = userId;
        const r = await fetch(url, { headers: cab, signal: ctl.signal });
        clearTimeout(t);
        if (r.status === 401 || r.status === 403) return { ok: false, erro: `sem permissão (${r.status}) — sessão expirada ou CNPJ não vinculado` };
        if (!r.ok) { ultimoErro = `HTTP ${r.status}`; continue; }
        const corpo = await r.json();
        const lista = Array.isArray(corpo) ? corpo : (corpo.lista || []);
        return { ok: true, infracoes: lista.map(i => Object.fromEntries(CAMPOS.map(k => [k, i[k] ?? null]))) };
      } catch (e) {
        clearTimeout(t);
        ultimoErro = e.name === 'AbortError' ? 'tempo esgotado' : String(e.message || e);
      }
    }
    return { ok: false, erro: ultimoErro };
  }

  log(`Sessão: token do ${escolhido.onde}, válido até ${expira ? new Date(expira).toLocaleTimeString('pt-BR') : '?'} · identificação do usuário: ${userId ? 'ok' : 'NÃO ENCONTRADA'}`);
  const empresas = [];
  for (const { cnpj } of CNPJS) {
    log(`CNPJ ${cnpj.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5')}...`);
    const r = await consultar(cnpj);
    empresas.push({ cnpj, ok: r.ok, erro: r.erro || null, total: r.ok ? r.infracoes.length : 0, infracoes: r.infracoes || [] });
    log(r.ok ? `   ${r.infracoes.length} multas` : `   FALHOU: ${r.erro}`);
    /* Portal recusou a sessão logo no 1º CNPJ: não adianta seguir. */
    if (!r.ok && /\(401\)/.test(r.erro || '') && empresas.length === 1) break;
    await espera(1500);
  }

  if (!empresas.some(e => e.ok)) {
    if (janela && !janela.closed) janela.close();
    log('');
    log('NENHUM CNPJ respondeu — nada foi enviado ao sistema.');
    if (empresas.some(e => /\(401\)/.test(e.erro || ''))) {
      log('O portal recusou a sessão (401). Saia do portal, entre de novo com o gov.br, abra "Minhas Empresas", aguarde a página carregar e clique no botão de novo.');
    }
    const f = document.createElement('button');
    f.textContent = 'Fechar';
    f.style.cssText = 'margin-top:10px;padding:6px 12px;border-radius:6px;border:1px solid #0f6e56;background:#0f6e56;color:#fff;cursor:pointer';
    f.onclick = () => painel.remove();
    painel.appendChild(f);
    return;
  }

  const agora = new Date();
  const p = n => String(n).padStart(2, '0');
  const nome = `multas_detran_${agora.getFullYear()}-${p(agora.getMonth() + 1)}-${p(agora.getDate())}_${p(agora.getHours())}${p(agora.getMinutes())}.json`;
  const arquivo = { formato: 'pcsdetran-api', versao: 1, geradoEm: agora.toISOString(), nome, empresas };
  const falhas = empresas.filter(e => !e.ok).length;
  log('');
  log(falhas ? `${falhas} CNPJ(s) falharam — o sistema não vai finalizar multas nesta captura.` : 'Todos os CNPJs consultados.');

  /* 1º caminho: entregar à janela do sistema (ela avisa "pronto" e depois "recebido"). */
  let entregue = false;
  if (janela && !janela.closed) {
    log('Enviando para o sistema...');
    entregue = await new Promise(resolve => {
      const fim = setTimeout(() => { removeEventListener('message', ouvir); resolve(false); }, 20000);
      const enviar = () => janela.postMessage({ tipo: 'multas-detran', arquivo }, SISTEMA);
      function ouvir(ev) {
        if (ev.origin !== SISTEMA || ev.source !== janela) return;
        if (ev.data === 'multas-pronto') enviar();
        if (ev.data === 'multas-recebido') { clearTimeout(fim); removeEventListener('message', ouvir); resolve(true); }
      }
      addEventListener('message', ouvir);
      enviar();
    });
  }
  if (entregue) {
    log('Pronto! A prévia abriu na janela do sistema — confira e clique em "Aplicar captura".');
    try { janela.focus(); } catch (_) { }
  } else {
    /* 2º caminho (janela bloqueada ou sistema fora do ar): baixa o arquivo. */
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([JSON.stringify(arquivo)], { type: 'application/json' }));
    a.download = nome;
    document.body.appendChild(a);
    a.click();
    a.remove();
    log(`Não consegui abrir o sistema. Arquivo salvo: ${nome}`);
    log('Envie o arquivo em "Importar captura" no sistema.');
  }
  const fechar = document.createElement('button');
  fechar.textContent = 'Fechar';
  fechar.style.cssText = 'margin-top:10px;padding:6px 12px;border-radius:6px;border:1px solid #0f6e56;background:#0f6e56;color:#fff;cursor:pointer';
  fechar.onclick = () => painel.remove();
  painel.appendChild(fechar);
})();
