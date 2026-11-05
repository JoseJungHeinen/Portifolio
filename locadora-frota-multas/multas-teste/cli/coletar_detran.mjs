/**
 * Coletor automático de multas — PCSDetran (sem ninguém abrir o site).
 *
 *   node cli/coletar_detran.mjs              → login + consulta + importa no sistema
 *   node cli/coletar_detran.mjs --verificar  → só abre a página inicial (sem login, sem cliques)
 *   node cli/coletar_detran.mjs --so-login   → só testa o login (não consulta nada)
 *   node cli/coletar_detran.mjs --nao-importar → consulta e salva o .json, sem importar
 *   node cli/coletar_detran.mjs --visivel    → mostra o Chrome (para depurar)
 *
 * Sem dependências: usa o Chrome instalado (modo invisível) controlado pelo protocolo
 * DevTools via WebSocket nativo do Node 22+. O certificado A1 é escolhido pelo próprio
 * Chrome através da política AutoSelectCertificateForUrls (ver cli/configurar_windows.md).
 *
 * O que ele faz no portal: entra pelo gov.br com certificado e chama SOMENTE
 *   GET .../infracoes/requerentes/{cpf}?grupo=5&cnpj={cnpj}   (leitura)
 * Não clica em nada além dos botões de login. Se aparecer captcha, termo para aceitar
 * ou qualquer tela inesperada, para e registra o erro (com print) — não tenta contornar.
 */
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = join(dirname(fileURLToPath(import.meta.url)), '..');
const PHP = process.env.MULTAS_PHP || 'C:/xampp/php/php.exe';
const CHROME = process.env.MULTAS_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PERFIL = join(RAIZ, 'data', 'chrome-coletor');
const args = new Set(process.argv.slice(2));
const agora = new Date();
const p2 = n => String(n).padStart(2, '0');
const carimbo = `${agora.getFullYear()}-${p2(agora.getMonth() + 1)}-${p2(agora.getDate())}_${p2(agora.getHours())}${p2(agora.getMinutes())}`;
const PASTA = join(RAIZ, 'data', 'coletas', carimbo);
mkdirSync(PASTA, { recursive: true });

const linhasLog = [];
const log = (...m) => {
  const l = `[${new Date().toLocaleTimeString('pt-BR')}] ${m.join(' ')}`;
  linhasLog.push(l);
  console.log(l);
};
const espera = ms => new Promise(r => setTimeout(r, ms));

// ---------------------------------------------------------------- Chrome + DevTools
async function abrirChrome() {
  if (!existsSync(CHROME)) throw new Error(`Chrome não encontrado em ${CHROME}`);
  mkdirSync(PERFIL, { recursive: true });
  const porta = join(PERFIL, 'DevToolsActivePort');
  if (existsSync(porta)) rmSync(porta);
  const proc = spawn(CHROME, [
    ...(args.has('--visivel') ? [] : ['--headless=new']),
    `--user-data-dir=${PERFIL}`, '--remote-debugging-port=0', '--no-first-run', '--no-default-browser-check',
    '--disable-extensions', '--disable-sync', '--window-size=1280,900', '--lang=pt-BR', 'about:blank',
  ], { stdio: 'ignore' });
  for (let i = 0; i < 60 && !existsSync(porta); i++) await espera(250);
  if (!existsSync(porta)) { proc.kill(); throw new Error('Chrome não abriu a porta de depuração'); }
  const [numero, caminho] = readFileSync(porta, 'utf-8').trim().split('\n');
  const ws = new WebSocket(`ws://127.0.0.1:${numero}${caminho}`);
  await new Promise((ok, erro) => { ws.onopen = ok; ws.onerror = () => erro(new Error('Falha ao conectar no Chrome')); });

  let id = 0;
  const pendentes = new Map();
  const ouvintes = [];
  ws.onmessage = ev => {
    const msg = JSON.parse(ev.data);
    if (msg.id && pendentes.has(msg.id)) {
      const { ok, erro } = pendentes.get(msg.id);
      pendentes.delete(msg.id);
      msg.error ? erro(new Error(msg.error.message)) : ok(msg.result);
    } else if (msg.method) ouvintes.forEach(f => f(msg));
  };
  const enviar = (method, params = {}, sessionId) => new Promise((ok, erro) => {
    const n = ++id;
    pendentes.set(n, { ok, erro });
    ws.send(JSON.stringify({ id: n, method, params, sessionId }));
  });

  const { targetId } = await enviar('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await enviar('Target.attachToTarget', { targetId, flatten: true });
  const pg = (m, p) => enviar(m, p, sessionId);
  await pg('Page.enable');
  await pg('Runtime.enable');

  return {
    proc,
    ir: url => pg('Page.navigate', { url }),
    avaliar: async (expr, aguardar = false) => {
      const r = await pg('Runtime.evaluate', { expression: expr, awaitPromise: aguardar, returnByValue: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    },
    print: async nome => {
      try {
        const { data } = await pg('Page.captureScreenshot', { format: 'png' });
        writeFileSync(join(PASTA, `${nome}.png`), Buffer.from(data, 'base64'));
      } catch (_) { }
    },
    fechar: async () => { try { await enviar('Browser.close'); } catch (_) { } ws.close(); setTimeout(() => proc.kill(), 2000); },
  };
}

// ---------------------------------------------------------------- Login gov.br com certificado
const JS_SESSAO = `(() => {
  const ler = k => sessionStorage.getItem(k) || localStorage.getItem(k);
  const t = ler('access_token'), e = Number(ler('expires_at') || 0);
  return { logado: !!t && (!e || e > Date.now()), expiraEm: e || null };
})()`;

const JS_CAPTCHA = `!!document.querySelector('iframe[src*="hcaptcha"], iframe[src*="recaptcha"], iframe[src*="turnstile"], .h-captcha, .g-recaptcha, .cf-turnstile, #captcha')`;

/** Clica no primeiro botão/link visível cujo texto bate com o padrão. Devolve o texto clicado ou null. */
const jsClicar = (padrao, seletorPreferido = '') => `(() => {
  const re = new RegExp(${JSON.stringify(padrao)}, 'i');
  const pref = ${JSON.stringify(seletorPreferido)} ? document.querySelector(${JSON.stringify(seletorPreferido)}) : null;
  const els = pref ? [pref] : [...document.querySelectorAll('button, a, [role=button], input[type=submit]')];
  const el = els.find(e => e && e.offsetParent !== null && re.test((e.innerText || e.value || e.title || e.getAttribute('aria-label') || '').trim()));
  if (!el) return null;
  el.click();
  return (el.innerText || el.value || '').trim().slice(0, 60);
})()`;

async function login(c) {
  log('Abrindo pcsdetran.rs.gov.br');
  await c.ir('https://pcsdetran.rs.gov.br/');
  await espera(6000);
  let s = await c.avaliar(JS_SESSAO).catch(() => ({}));
  if (s.logado) { log('Sessão do portal ainda válida — login não necessário'); return s; }

  const clicou = await c.avaliar(jsClicar('^entrar\\s*(com\\s*)?gov\\.?br'));
  if (!clicou) { await c.print('erro-sem-botao-entrar'); throw new Error('Botão "Entrar gov.br" não encontrado no portal'); }
  log(`Clicou em "${clicou}"`);

  const cliques = new Set();
  const limite = Date.now() + 120000;
  while (Date.now() < limite) {
    await espera(2000);
    const url = await c.avaliar('location.href').catch(() => '');
    const host = (() => { try { return new URL(url).host; } catch { return ''; } })();

    if (host === 'pcsdetran.rs.gov.br') {
      s = await c.avaliar(JS_SESSAO).catch(() => ({}));
      if (s.logado) { log('Login concluído'); await c.print('ok-logado'); return s; }
      continue; // ainda processando o retorno (/redirect)
    }
    if (await c.avaliar(JS_CAPTCHA).catch(() => false)) {
      await c.print('erro-captcha');
      throw new Error(`O login pediu captcha em ${host}. A automação não contorna captcha — será preciso entrar manualmente.`);
    }
    if (host === 'sso.acesso.gov.br') {
      if (!cliques.has('cert')) {
        const t = await c.avaliar(jsClicar('certificado\\s*digital', '#login-certificate'));
        if (t) { cliques.add('cert'); log(`gov.br: clicou em "${t}"`); continue; }
      }
      const texto = await c.avaliar('document.body.innerText.slice(0, 400)').catch(() => '');
      if (/autoriz|consent|permitir/i.test(texto)) {
        await c.print('erro-autorizacao');
        throw new Error('O gov.br pediu autorização de compartilhamento de dados. Faça um login manual uma vez para autorizar.');
      }
      continue;
    }
    if (host.endsWith('acesso.gov.br')) continue; // certificado.sso.acesso.gov.br: Chrome envia o certificado
    if (host === 'logincidadao.rs.gov.br') {
      if (!cliques.has('lc')) {
        const t = await c.avaliar(jsClicar('gov\\.?br'));
        if (t) { cliques.add('lc'); log(`Login Cidadão: clicou em "${t}"`); }
      }
      continue;
    }
  }
  await c.print('erro-tempo-login');
  const url = await c.avaliar('location.href').catch(() => '?');
  throw new Error(`Login não terminou em 2 minutos (parou em ${new URL(url).host}). Se o Chrome pediu para escolher o certificado, a política de seleção automática não está configurada.`);
}

// ---------------------------------------------------------------- Consulta (só leitura)
const jsConsultar = cnpjs => `(async () => {
  const API = 'https://pcsdetran.procergs.com.br/pcsdetran/rest';
  const CAMPOS = ['serieAIT','placa','ufPlaca','marcaVeiculo','codOat','orgaoFiscalizador','codInfracao','descrInfracao',
    'local','data','hora','valor','grupo','codGrupo','dataLimApresCond','pontuacao','podeGerarGAD'];
  const ler = k => sessionStorage.getItem(k) || localStorage.getItem(k);
  const token = ler('access_token');
  let claims = {};
  try { claims = JSON.parse(ler('id_token_claims_obj') || '{}'); } catch (_) {}
  const userId = claims.cpf ? btoa(String(claims.cpf)) : null;
  let cpf = claims.cpf ? String(claims.cpf).replace(/\\D/g, '') : null;
  if (cpf && cpf.length !== 11) cpf = null;
  if (!cpf) for (const e of performance.getEntriesByType('resource')) {
    const m = /\\/pessoas\\/(?:autorizacoes\\/)?(\\d{11})(?:\\/|$|\\?)/.exec(e.name) || /\\/requerentes\\/(\\d{11})/.exec(e.name);
    if (m) { cpf = m[1]; break; }
  }
  if (!cpf) { try { const c = JSON.parse(ler('id_token_claims_obj') || '{}');
    cpf = [c.cpf, c.preferred_username, c.sub].map(v => String(v || '').replace(/\\D/g, '')).find(v => v.length === 11) || null; } catch (_) {} }
  if (!token || !cpf) return { erro: 'sessão sem token ou usuário identificado' };
  const espera = ms => new Promise(r => setTimeout(r, ms));
  const empresas = [];
  for (const cnpj of ${JSON.stringify(cnpjs)}) {
    let r = { ok: false, erro: '' };
    for (const pausa of [0, 5000, 15000, 30000]) {
      if (pausa) await espera(pausa);
      const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 90000);
      try {
        const resp = await fetch(API + '/infracoes/requerentes/' + cpf + '?grupo=5&cnpj=' + cnpj,
          { headers: Object.assign({ Authorization: 'Bearer ' + token, Accept: 'application/json' }, userId ? { 'X-User-Id': userId } : {}), signal: ctl.signal });
        clearTimeout(t);
        if (resp.status === 401 || resp.status === 403) { r = { ok: false, erro: 'sem permissão (' + resp.status + ')' }; break; }
        if (!resp.ok) { r.erro = 'HTTP ' + resp.status; continue; }
        const corpo = await resp.json();
        const lista = Array.isArray(corpo) ? corpo : (corpo.lista || []);
        r = { ok: true, infracoes: lista.map(i => Object.fromEntries(CAMPOS.map(k => [k, i[k] ?? null]))) };
        break;
      } catch (e) { clearTimeout(t); r.erro = e.name === 'AbortError' ? 'tempo esgotado' : String(e.message || e); }
    }
    empresas.push({ cnpj, ok: r.ok, erro: r.ok ? null : r.erro, total: r.ok ? r.infracoes.length : 0, infracoes: r.infracoes || [] });
    await espera(1500);
  }
  return { empresas };
})()`;

// ---------------------------------------------------------------- Execução
function registrar(resultado) {
  const dados = { quando: new Date().toISOString(), pasta: PASTA, ...resultado };
  writeFileSync(join(PASTA, 'resultado.json'), JSON.stringify(dados, null, 1));
  writeFileSync(join(RAIZ, 'data', 'coletas', 'ultima_execucao.json'), JSON.stringify(dados, null, 1));
  writeFileSync(join(PASTA, 'log.txt'), linhasLog.join('\n'));
}

let chrome;
try {
  const cnpjs = JSON.parse(execFileSync(PHP, [join(RAIZ, 'cli', 'cnpjs.php')], { encoding: 'utf-8' })).map(c => c.cnpj);
  if (!cnpjs.length) throw new Error('Nenhum CNPJ configurado (página Coletor do portal)');
  chrome = await abrirChrome();
  if (args.has('--verificar')) {
    // Só abre a página inicial e lê o título — não clica em nada, não faz login.
    await chrome.ir('https://pcsdetran.rs.gov.br/');
    await espera(6000);
    const titulo = await chrome.avaliar('document.title');
    const s = await chrome.avaliar(JS_SESSAO).catch(() => ({}));
    await chrome.print('verificar');
    log(`Chrome invisível OK · página: "${titulo}" · sessão já aberta neste perfil: ${s.logado ? 'sim' : 'não'}`);
    registrar({ ok: true, etapa: 'verificar', mensagem: 'Teste do Chrome invisível (sem login).' });
  } else {
  const sessao = await login(chrome);
  if (sessao.expiraEm) log(`Sessão do portal válida até ${new Date(sessao.expiraEm).toLocaleString('pt-BR')}`);

  if (args.has('--so-login')) {
    registrar({ ok: true, etapa: 'so-login', mensagem: 'Login automático funcionou (nenhuma consulta feita).', expiraEm: sessao.expiraEm });
    log('Teste de login OK. Nenhuma consulta feita.');
  } else {
    log(`Consultando ${cnpjs.length} CNPJ(s)...`);
    const r = await chrome.avaliar(jsConsultar(cnpjs), true);
    if (r.erro) throw new Error(r.erro);
    for (const e of r.empresas) log(`  ${e.cnpj}: ${e.ok ? e.total + ' multas' : 'FALHOU ' + e.erro}`);
    const arquivo = join(PASTA, `multas_detran_${carimbo}.json`);
    writeFileSync(arquivo, JSON.stringify({ formato: 'pcsdetran-api', versao: 1, geradoEm: new Date().toISOString(), empresas: r.empresas }));
    log(`Arquivo salvo: ${arquivo}`);
    let importacao = null;
    if (!args.has('--nao-importar')) {
      importacao = JSON.parse(execFileSync(PHP, [join(RAIZ, 'cli', 'importar_captura.php'), arquivo], { encoding: 'utf-8' }));
      log(`Importado: ${importacao.novas} novas, ${importacao.atualizadas} atualizadas, ${importacao.sairam} saíram do DETRAN` +
        (importacao.completa ? '' : ' (captura INCOMPLETA: nada foi finalizado)'));
    }
    const falhas = r.empresas.filter(e => !e.ok).length;
    registrar({ ok: falhas === 0, etapa: 'coleta', arquivo, falhas, importacao,
      mensagem: falhas ? `${falhas} CNPJ(s) não responderam` : 'Coleta completa' });
  }
  }
} catch (e) {
  log('ERRO:', e.message);
  if (chrome) await chrome.print('erro-final');
  registrar({ ok: false, etapa: 'erro', mensagem: e.message });
  process.exitCode = 1;
} finally {
  if (chrome) await chrome.fechar();
}
