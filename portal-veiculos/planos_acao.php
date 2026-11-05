<?php
require_once __DIR__ . '/planos_acao_comum.php';
pa_guard();
?><!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Planos de Ação</title>
<style>
:root{--red:#e11d2e;--ink:#1e293b;--mut:#64748b;--line:#e2e8f0;--bg:#f1f5f9;--ok:#16a34a;--warn:#d97706;--blue:#2563eb}
*{box-sizing:border-box}body{margin:0;font:14px/1.45 system-ui,Segoe UI,sans-serif;color:var(--ink);background:var(--bg)}
.wrap{max-width:1200px;margin:0 auto;padding:16px}
h1{font-size:20px;margin:0}.top{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}.top .sp{flex:1}
.tabs{display:flex;gap:4px;margin-bottom:12px;border-bottom:2px solid var(--line)}
.tab{padding:8px 16px;cursor:pointer;border:0;background:none;font:inherit;color:var(--mut);border-bottom:3px solid transparent;margin-bottom:-2px}
.tab.on{color:var(--red);border-color:var(--red);font-weight:600}
.btn{border:1px solid var(--line);background:#fff;border-radius:6px;padding:6px 12px;cursor:pointer;font:inherit;color:var(--ink)}
.btn:hover{background:#f8fafc}.btn.pri{background:var(--red);border-color:var(--red);color:#fff}.btn.sm{padding:3px 9px;font-size:12px}.btn.dan{color:#b91c1c;border-color:#fecaca}
.card{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px 14px;margin-bottom:10px}
.alert{background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;margin-bottom:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.bar{background:#e5e7eb;border-radius:99px;height:8px;overflow:hidden}.bar>i{display:block;height:100%;background:var(--ok)}
.bar.w>i{background:var(--warn)}.bar.d>i{background:#dc2626}
.badge{display:inline-block;border-radius:4px;padding:1px 7px;font-size:11px;font-weight:600;background:#e2e8f0;color:#334155}
.b-setor{background:#111827;color:#fff}.b-em_andamento{background:var(--blue);color:#fff}.b-concluido{background:var(--ok);color:#fff}
.b-pausado{background:#f59e0b;color:#fff}.b-cancelado{background:#9ca3af;color:#fff}
.b-nao_iniciado{background:#94a3b8;color:#fff}.b-aguardando_validacao{background:#7c3aed;color:#fff}.b-encerrado{background:#475569;color:#fff}.b-atrasado{background:#dc2626;color:#fff}
.av.validar{background:#ede9fe;color:#5b21b6}.av.devolvido{background:#fee2e2;color:#991b1b}
.av{display:inline-block;border-radius:5px;padding:1px 8px;font-size:12px;margin:2px 4px 0 0;background:#fef3c7;color:#92400e}
.av.vencido,.av.critico,.av.atraso_acao{background:#dc2626;color:#fff}.av.alerta{background:#fed7aa;color:#9a3412}.av.atingida{background:#bbf7d0;color:#166534}
.row{display:flex;gap:12px;flex-wrap:wrap}.grow{flex:1;min-width:260px}
.ms{position:relative}.ms>button{width:100%;text-align:left;background:#fff;border:1px solid #cbd5e1;padding:6px 8px;border-radius:6px;font:inherit;display:flex;justify-content:space-between}
.msp{position:absolute;top:100%;left:0;z-index:30;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.15);width:260px;max-height:320px;overflow:auto;padding:6px;margin-top:2px}
.msp label{display:flex;gap:8px;align-items:center;margin:0;padding:5px 6px;border-radius:4px;color:var(--ink);font-size:14px;cursor:pointer}.msp label:hover{background:#f1f5f9}
.msp input{width:auto}.msp .ac{display:flex;gap:12px;padding:4px 6px 6px;border-bottom:1px solid var(--line);margin-bottom:4px;font-size:13px}
input,select,textarea{font:inherit;padding:6px 8px;border:1px solid #cbd5e1;border-radius:6px;width:100%;background:#fff}
label{display:block;font-size:12px;color:var(--mut);margin:8px 0 2px}label b{color:var(--red)}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:12px}
.kpi{background:#fff;border:1px solid var(--line);border-radius:8px;padding:10px 12px}.kpi b{font-size:24px;display:block}.kpi span{color:var(--mut);font-size:12px}
table{width:100%;border-collapse:collapse}th,td{padding:6px 8px;border-bottom:1px solid var(--line);text-align:left;font-size:13px}th{color:var(--mut);font-weight:600}
.mut{color:var(--mut)}.r{text-align:right}.hide{display:none!important}
.ov{position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:flex-start;justify-content:center;overflow:auto;padding:24px 12px;z-index:50}
.md{background:#fff;border-radius:10px;width:100%;max-width:820px;padding:16px 18px}.md h2{margin:0 0 8px;font-size:17px}
.meta{background:#f8fafc;border:1px solid var(--line);border-radius:8px;padding:10px;margin-top:8px}
.hint{color:var(--red);font-weight:600;font-size:13px;border-bottom:2px solid var(--red);padding-bottom:4px;margin-bottom:4px}
.toast{position:fixed;bottom:16px;right:16px;background:#111827;color:#fff;padding:8px 14px;border-radius:8px;z-index:99}
@media(max-width:640px){.g2,.g3{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><h1>Planos de Ação</h1><span class="sp"></span>
    <button class="btn" data-act="resumo">Resumo geral</button>
    <button class="btn pri hide" id="btnNovo" data-act="novo">+ Novo plano</button></div>
  <div class="tabs">
    <button class="tab on" data-tab="planos">Planos</button>
    <button class="tab" data-tab="minhas">Minhas ações <span class="badge" id="nMinhas">0</span></button>
    <button class="tab" data-tab="dash">Dashboard</button>
  </div>

  <section id="v-planos">
    <div id="alerta"></div>
    <div class="row" style="margin-bottom:10px">
      <div class="ms" id="msSetor" style="width:230px"><button type="button" id="msBtn"><span id="msTxt">Todos os setores</span><span>▾</span></button><div class="msp hide" id="msPanel"></div></div>
      <div style="width:190px"><select id="fStatus"><option value="abertos">Abertos (não iniciado + andamento)</option><option value="atrasado">Atrasados</option><option value="aguardando_validacao">Aguardando validação</option><option value="nao_iniciado">Não iniciados</option><option value="em_andamento">Em andamento</option><option value="pausado">Pausados</option><option value="concluido">Concluídos</option><option value="encerrado">Encerrados</option><option value="cancelado">Cancelados</option><option value="todos">Todos os status</option></select></div>
      <div style="width:170px"><select id="fLoja"><option value="">Todas as lojas</option></select></div>
      <div style="width:220px"><select id="fResp"><option value="">Todas as pessoas</option></select></div>
      <div class="grow"><input id="fQ" placeholder="Buscar plano ou indicador…"></div>
    </div>
    <div id="lista"></div>
  </section>

  <section id="v-minhas" class="hide"><div id="minhas"></div></section>

  <section id="v-dash" class="hide">
    <div class="row" style="margin-bottom:10px">
      <div style="width:220px"><select id="dSetor"><option value="">Todos os setores</option></select></div>
      <div style="width:240px"><select id="dPessoa"><option value="">Todas as pessoas</option></select></div>
    </div>
    <div id="dash"></div>
  </section>
</div>
<div id="modal"></div>

<script>
const API = 'planos_acao_api.php';
let M = null, abaDet = 'acoes';
const setoresSel = new Set();   // setores marcados no filtro (vazio = todos)
const $ = s => document.querySelector(s);
const h = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const brl = v => v == null ? '—' : Number(v).toLocaleString('pt-BR', {style:'currency', currency:'BRL'});
const dt = d => d ? d.slice(0,10).split('-').reverse().join('/') : '—';
const ST = {nao_iniciado:'Não iniciado', em_andamento:'Em andamento', pausado:'Pausado', aguardando_validacao:'Aguardando validação', concluido:'Concluído', cancelado:'Cancelado', encerrado:'Encerrado'};
const ABERTO = s => s==='nao_iniciado' || s==='em_andamento';
const badgeSt = p => `<span class="badge b-${p.status}">${ST[p.status]}</span>${p.atrasado?' <span class="badge b-atrasado">Atrasado</span>':''}`;
// Loja cadastrada; senão o local em texto (ex.: "Euro Matriz"); senão todas
const destLabel = d => ({encerrado:'Encerramento', cancelado:'Cancelamento'})[d] || 'Conclusão';
const nomeLoja = p => p.loja_nome || p.onde || 'Todas as lojas';
const AST = {pendente:'Pendente', em_andamento:'Em andamento', concluida:'Concluída', cancelada:'Cancelada'};

function toast(m){ const t=document.createElement('div'); t.className='toast'; t.textContent=m; document.body.append(t); setTimeout(()=>t.remove(),2800); }
async function api(a, params, post){
  const o = {};
  let url = API + '?a=' + a;
  if (post) { o.method='POST'; o.headers={'X-CSRF': M ? M.csrf : '', 'Content-Type':'application/x-www-form-urlencoded'}; o.body=new URLSearchParams(params||{}).toString(); }
  else if (params) url += '&' + new URLSearchParams(params);
  const r = await fetch(url, o); const j = await r.json().catch(()=>({ok:false,msg:'Resposta inválida.'}));
  return j;
}
const nomeMeta = p => p.tipo_meta === 'percentual' ? `${p.valor_meta}%` : brl(p.valor_meta);
const fmtV = (p, v) => brl(v);
function barra(pct, cls){ const w=Math.max(0,Math.min(100,pct||0)); return `<div class="bar ${cls||''}"><i style="width:${w}%"></i></div>`; }
function corProg(p){ return p.avisos.some(a=>a.tipo==='vencido') ? 'd' : (p.progresso!=null && p.progresso<40 ? 'w' : ''); }

// ── Bootstrap ──
async function init(){
  M = await api('meta');
  if (!M.ok) { document.body.innerHTML = '<p style="padding:20px">'+h(M.msg)+'</p>'; return; }
  if (M.me.admin || M.me.gestor_em.length) $('#btnNovo').classList.remove('hide');
  const so = M.setores.map(s=>`<option value="${s.id}">${h(s.nome)}</option>`).join('');
  $('#dSetor').insertAdjacentHTML('beforeend', so);
  const po = M.usuarios.map(u=>`<option value="${u.id}">${h(u.nome)}</option>`).join('');
  $('#fResp').insertAdjacentHTML('beforeend', po); $('#dPessoa').insertAdjacentHTML('beforeend', po);
  $('#fLoja').insertAdjacentHTML('beforeend', M.lojas.map(l=>`<option value="${l.id}">${h(l.nome)}</option>`).join(''));
  // Para quem não é admin/gestor, o dashboard é "Minhas": pré-seleciona a própria pessoa
  if (!M.me.admin && !M.me.gestor_em.length) { $('#dPessoa').value = M.me.id; $('#dPessoa').disabled = true; }
  renderMs();
  await Promise.all([carregarLista(), carregarMinhas()]);
}
// ── Filtro de setores (select com checkboxes) ──
function renderMs(){
  $('#msPanel').innerHTML = `<div class="ac"><a href="#" data-act="ms-all">Marcar todos</a><a href="#" data-act="ms-clear">Limpar</a></div>` +
    M.setores.map(s=>`<label><input type="checkbox" data-ms="${s.id}" ${setoresSel.has(String(s.id))?'checked':''}> ${h(s.nome)}</label>`).join('');
  const n = setoresSel.size, um = n===1 ? M.setores.find(s=>setoresSel.has(String(s.id))) : null;
  $('#msTxt').textContent = !n ? 'Todos os setores' : (um ? um.nome : n + ' setores');
}

// ── Lista ──
async function carregarLista(soPendentes){
  const p = {status: $('#fStatus').value, q: $('#fQ').value};
  if (setoresSel.size) p.setor_id = [...setoresSel].join(','); if ($('#fResp').value) p.responsavel_id = $('#fResp').value; if ($('#fLoja').value) p.loja_id = $('#fLoja').value; if (soPendentes) p.pendentes = 1;
  const j = await api('listar', p);
  if (!j.ok) return toast(j.msg);
  const pend = j.planos.filter(x=>x.avisos.length && (ABERTO(x.status) || x.status==='aguardando_validacao'));
  const cnt = t => pend.filter(x=>x.avisos.some(a=>a.tipo===t)).length;
  $('#alerta').innerHTML = pend.length ? `<div class="alert"><b>${pend.length} plano(s) com pendência</b>
    ${cnt('vencido')?`<span class="av vencido">Vencido: ${cnt('vencido')}</span>`:''}${cnt('atualizar')?`<span class="av">Sem atualização: ${cnt('atualizar')}</span>`:''}
    ${cnt('validar')?`<span class="av validar">Aguardando validação: ${cnt('validar')}</span>`:''}
    ${cnt('atraso_acao')?`<span class="av atraso_acao">Ações atrasadas: ${cnt('atraso_acao')}</span>`:''}${cnt('atingida')?`<span class="av atingida">Meta atingida: ${cnt('atingida')}</span>`:''}
    <a href="#" data-act="pend">Ver pendentes</a></div>` : '';
  $('#lista').innerHTML = j.planos.length ? j.planos.map(cardPlano).join('') : '<div class="card mut">Nenhum plano encontrado.</div>';
}
function cardPlano(p){
  const metaBox = +p.tem_meta ? `<div class="meta" style="min-width:250px;max-width:300px">
      <b>${h(p.indicador)}</b> <span class="mut">${p.sentido==='aumento'?'▲':'▼'} ${nomeMeta(p)}</span><br>
      <span class="mut">De</span> ${fmtV(p,p.valor_base)} <span class="mut">→ Para</span> <b>${fmtV(p,p.valor_alvo)}</b><br>
      <span class="mut">Último: ${p.ultimo_valor!=null?fmtV(p,p.ultimo_valor)+' ('+dt(p.ultima_data)+')':'—'}</span>
      ${p.progresso!=null?barra(p.progresso,corProg(p))+`<small>${p.progresso}%</small>`:'<div class="mut"><i>Sem acompanhamento</i></div>'}</div>`
    : (p.qtd_acoes>0 ? `<div class="meta" style="min-width:200px"><span class="mut">Ações: ${p.acoes_ok}/${p.qtd_acoes} concluídas</span>${p.progresso!=null?barra(p.progresso,corProg(p))+`<small>${p.progresso}%</small>`:''}</div>` : '');
  return `<div class="card"><div class="row">
    <div class="grow">
      <span class="badge b-setor">${h(p.setor_nome)}</span> ${badgeSt(p)}
      ${p.prioridade==='alta'?'<span class="badge" style="background:#fee2e2;color:#b91c1c">Prioridade</span>':''}
      ${p.categoria?`<span class="badge">${h(p.categoria)}</span>`:''}
      <b style="font-size:15px;margin-left:4px;cursor:pointer" data-act="abrir" data-id="${p.id}">${h(p.titulo)}</b>
      <div class="mut" style="margin:4px 0">👤 ${h(p.responsavel_nome)}${p.atribuido_nome&&p.atribuido_nome!==p.responsavel_nome?` → ${h(p.atribuido_nome)}`:''} · 📍 ${h(nomeLoja(p))} · 📅 ${p.inicio||p.prazo?`${dt(p.inicio)} → ${dt(p.prazo)}`:'sem prazo'}${p.qtd_acoes>0&&+p.tem_meta?` · ☑ ${p.acoes_ok}/${p.qtd_acoes} ações`:''}</div>
      ${p.observacoes?`<div class="mut" style="font-size:12px">💬 ${h(p.observacoes.slice(0,160))}${p.observacoes.length>160?'…':''}</div>`:''}
      <div>${p.avisos.map(a=>`<span class="av ${a.tipo}">${h(a.msg)}</span>`).join('')}</div>
    </div>${metaBox}
    <div style="display:flex;flex-direction:column;gap:4px;width:110px">
      <button class="btn sm pri" data-act="abrir" data-id="${p.id}">Acompanhar</button>
      ${p.pode_editar?`<button class="btn sm" data-act="editar" data-id="${p.id}">Editar</button>`:''}
    </div></div></div>`;
}

// ── Minhas ações ──
async function carregarMinhas(){
  const j = await api('minhas_acoes'); if (!j.ok) return;
  $('#nMinhas').textContent = j.acoes.length + j.planos.length + j.validar.length;
  const aValidar = j.validar.length ? `<h3 style="margin:4px 0 8px">Aguardando validação da Diretoria</h3>` + j.validar.map(p=>`<div class="card row" style="align-items:center">
    <div class="grow"><span class="badge b-setor">${h(p.setor_nome)}</span> <span class="badge b-aguardando_validacao">${destLabel(p.validacao_destino)}</span> <b>${h(p.titulo)}</b><br>
      <span class="mut">👤 ${h(p.responsavel_nome)} · 📍 ${h(nomeLoja(p))} · Prazo: ${dt(p.prazo)}${p.encerrado_motivo?' · Motivo: '+h(p.encerrado_motivo):''}${p.observacoes?' · '+h(p.observacoes.slice(0,120)):''}</span></div>
    <button class="btn sm" data-act="abrir" data-id="${p.id}">Ver</button>
    <button class="btn sm" data-act="devolver" data-id="${p.id}">Devolver</button>
    <button class="btn sm pri" data-act="aprovar" data-id="${p.id}">Validar</button></div>`).join('') + '<hr style="border:0;border-top:1px solid var(--line);margin:12px 0">' : '';
  const meusPlanos = j.planos.length ? `<h3 style="margin:4px 0 8px">Planos sob minha responsabilidade</h3>` + j.planos.map(p=>`<div class="card row" style="align-items:center">
    <div class="grow"><span class="badge b-setor">${h(p.setor_nome)}</span> <b>${h(p.titulo)}</b><br>
      <span class="mut">${badgeSt(p)} 📍 ${h(nomeLoja(p))} · 📅 ${p.inicio||p.prazo?`${dt(p.inicio)} → ${dt(p.prazo)}`:'sem prazo'}${p.qtd_acoes>0?` · ☑ ${p.acoes_ok}/${p.qtd_acoes} ações`:''}</span>
      <div>${p.avisos.map(a=>`<span class="av ${a.tipo}">${h(a.msg)}</span>`).join('')}</div></div>
    <button class="btn sm pri" data-act="abrir" data-id="${p.id}">Abrir</button></div>`).join('') : '';
  const minhasAcoes = '<h3 style="margin:4px 0 8px">Minhas ações</h3>' + (j.acoes.length ? j.acoes.map(a=>`<div class="card row" style="align-items:center">
    <div class="grow"><b>${h(a.titulo)}</b> ${+a.atrasada?'<span class="av vencido">Atrasada</span>':''}<br>
      <span class="mut">${h(a.setor_nome)} · Plano: <a href="#" data-act="abrir" data-id="${a.plano_id}">${h(a.plano_titulo)}</a> · Prazo: ${dt(a.prazo)}</span></div>
    ${a.status==='pendente'?`<button class="btn sm" data-act="acao-st" data-id="${a.id}" data-st="em_andamento">Iniciar</button>`:''}
    <button class="btn sm pri" data-act="acao-st" data-id="${a.id}" data-st="concluida">Concluir</button></div>`).join('')
    : '<div class="card mut">Você não tem ações pendentes. 🎉</div>');
  // Ordem: validações (só Diretoria) → minhas ações → planos sob minha responsabilidade
  $('#minhas').innerHTML = aValidar + minhasAcoes + (meusPlanos ? '<div style="height:12px"></div>' + meusPlanos : '');
}

// ── Dashboard ──
async function carregarDash(){
  const p = {}; if ($('#dSetor').value) p.setor_id = $('#dSetor').value; if ($('#dPessoa').value) p.usuario_id = $('#dPessoa').value;
  const j = await api('dashboard', p); if (!j.ok) return toast(j.msg);
  const k = j.kpis, kp = (n,l,c)=>`<div class="kpi"><b ${c?`style="color:${c}"`:''}>${n}</b><span>${l}</span></div>`;
  $('#dash').innerHTML = `<div class="kpis">${kp(k.total,'Planos')}${kp(k.nao_iniciado,'Não iniciados','#64748b')}${kp(k.em_andamento,'Em andamento','#2563eb')}${kp(k.aguardando_validacao,'Aguardando validação','#7c3aed')}${kp(k.concluido,'Concluídos','#16a34a')}${kp(k.encerrado,'Encerrados','#475569')}
    ${kp(k.vencidos,'Vencidos','#dc2626')}${kp(k.sem_atualizacao,'Sem atualização','#d97706')}${kp(k.metas_atingidas,'Metas atingidas','#16a34a')}</div>
    <div class="row"><div class="card grow"><b>Por setor</b><table><tr><th>Setor</th><th class="r">Planos</th><th class="r">Andamento</th><th class="r">Concl.</th><th class="r">Venc.</th><th style="width:130px">Progresso médio</th></tr>
      ${j.por_setor.map(s=>`<tr><td>${h(s.setor)}</td><td class="r">${s.total}</td><td class="r">${s.em_andamento}</td><td class="r">${s.concluido}</td><td class="r">${s.vencidos>0?`<b style="color:#dc2626">${s.vencidos}</b>`:0}</td>
      <td>${s.progresso_medio!=null?barra(s.progresso_medio)+`<small>${s.progresso_medio}%</small>`:'<span class="mut">—</span>'}</td></tr>`).join('')||'<tr><td class="mut" colspan="6">Sem dados</td></tr>'}</table></div>
    <div class="card grow"><b>Por pessoa (ações)</b><table><tr><th>Pessoa</th><th class="r">Ações</th><th class="r">Abertas</th><th class="r">Atrasadas</th><th class="r">Concl.</th></tr>
      ${j.por_pessoa.map(u=>`<tr><td>${h(u.nome)}</td><td class="r">${u.acoes}</td><td class="r">${u.abertas}</td><td class="r">${+u.atrasadas?`<b style="color:#dc2626">${u.atrasadas}</b>`:0}</td><td class="r">${u.concluidas}</td></tr>`).join('')||'<tr><td class="mut" colspan="5">Sem ações atribuídas</td></tr>'}</table></div></div>
    <div class="card"><b>Próximos vencimentos</b><table>${j.proximos.map(x=>`<tr><td>${dt(x.prazo)}</td><td><a href="#" data-act="abrir" data-id="${x.id}">${h(x.titulo)}</a></td><td>${h(x.setor)}</td><td>${h(x.responsavel)}</td></tr>`).join('')||'<tr><td class="mut">Nenhum plano em andamento</td></tr>'}</table></div>`;
}

// ── Detalhe do plano ──
async function abrir(id){
  const j = await api('plano', {id}); if (!j.ok) return toast(j.msg);
  const p = j.plano, can = p.pode_editar;
  const tabs = ['acoes', ...(+p.tem_meta?['acomp']:[]), 'hist'];
  if (!tabs.includes(abaDet)) abaDet = 'acoes';
  let body = '';
  if (abaDet === 'acoes') {
    body = (j.acoes.map(a=>`<div class="row" style="align-items:center;border-bottom:1px solid var(--line);padding:6px 0">
      <div class="grow"><b style="${a.status==='concluida'?'text-decoration:line-through;color:var(--mut)':''}">${h(a.titulo)}</b>
        ${a.status!=='concluida'&&a.status!=='cancelada'&&a.prazo&&a.prazo<new Date().toISOString().slice(0,10)?'<span class="av vencido">Atrasada</span>':''}<br>
        <span class="mut">👤 ${h(a.responsavel_nome)} · ${dt(a.prazo)}${a.descricao?' · '+h(a.descricao):''}</span></div>
      ${a.pode_alterar?`<select style="width:140px" data-act="acao-sel" data-id="${a.id}" data-plano="${p.id}">${Object.entries(AST).map(([k,v])=>`<option value="${k}" ${k===a.status?'selected':''}>${v}</option>`).join('')}</select>`:`<span class="badge">${AST[a.status]}</span>`}
      ${can?`<button class="btn sm" data-act="acao-edit" data-plano="${p.id}" data-id="${a.id}" data-j='${h(JSON.stringify({t:a.titulo,d:a.descricao,r:a.responsavel_id,p:a.prazo}))}'>✎</button><button class="btn sm dan" data-act="acao-del" data-plano="${p.id}" data-id="${a.id}">✕</button>`:''}</div>`).join('') || '<p class="mut">Nenhuma ação cadastrada.</p>')
      + (can ? `<form class="meta" data-form="acao" data-plano="${p.id}"><input type="hidden" name="id"><div class="hint">Registre a Ação que foi ou será executada</div><div class="g3">
        <div><label>Ação <b>*</b></label><input name="titulo" required></div>
        <div><label>Responsável <b>*</b></label><select name="responsavel_id">${respOpts(p.setor_id, p.responsavel_id)}</select></div>
        <div><label>Prazo</label><input type="date" name="prazo"></div></div>
        <label>Detalhes</label><input name="descricao"><div style="margin-top:8px"><button class="btn pri sm">Salvar ação</button></div></form>` : '');
  } else if (abaDet === 'acomp') {
    body = `<table><tr><th>Data</th><th class="r">Realizado</th><th>Obs.</th><th>Por</th><th></th></tr>${j.acompanhamentos.map(c=>`<tr><td>${dt(c.data_ref)}</td><td class="r">${fmtV(p,c.valor)}</td><td>${h(c.observacao)}</td><td class="mut">${h(c.usuario)}</td><td>${can?`<button class="btn sm dan" data-act="acomp-del" data-id="${c.id}" data-plano="${p.id}">✕</button>`:''}</td></tr>`).join('')||'<tr><td colspan="5" class="mut">Sem lançamentos.</td></tr>'}</table>`
      + (can ? `<form class="meta" data-form="acomp" data-plano="${p.id}"><div class="g3"><div><label>Data <b>*</b></label><input type="date" name="data_ref" value="${new Date().toISOString().slice(0,10)}" max="${new Date().toISOString().slice(0,10)}" required></div>
        <div><label>Valor realizado (R$) <b>*</b></label><input name="valor" inputmode="decimal" required></div><div><label>Observação</label><input name="observacao" maxlength="255"></div></div>
        <div style="margin-top:8px"><button class="btn pri sm">Lançar</button></div></form>` : '');
  } else {
    body = j.historico.map(x=>`<div style="padding:4px 0;border-bottom:1px solid var(--line)"><span class="mut">${h(x.criado_em)} · ${h(x.usuario||'—')}</span> <b>${h(x.evento)}</b> ${h(x.detalhe)}</div>`).join('') || '<p class="mut">Sem histórico.</p>';
  }
  const B = (st, txt, cls) => `<button class="btn sm ${cls||''}" data-act="plano-st" data-id="${p.id}" data-st="${st}">${txt}</button> `;
  const dir = M.me.diretoria, gest = M.me.admin || M.me.gestor_em.includes(+p.setor_id);
  let stBtns = '';
  if (p.status==='aguardando_validacao') {
    stBtns = dir ? `<button class="btn sm pri" data-act="aprovar" data-id="${p.id}">Validar conclusão</button> <button class="btn sm" data-act="devolver" data-id="${p.id}">Devolver</button> ` : '';
  } else if (can) {
    if (p.status==='nao_iniciado') stBtns += B('em_andamento','Iniciar');
    if (ABERTO(p.status)) stBtns += B('concluido', dir ? 'Concluir plano' : 'Concluir (enviar p/ validação)', 'pri');
    if (p.status==='em_andamento') stBtns += B('pausado','Pausar');
    if (!ABERTO(p.status)) stBtns += B('em_andamento','Reabrir');
    if (gest && ABERTO(p.status)) stBtns += B('encerrado', dir ? 'Encerrar' : 'Encerrar (enviar p/ validação)');
  }
  const stBtns2 = '';
  modal(`<h2>${h(p.titulo)}</h2><span class="badge b-setor">${h(p.setor_nome)}</span> ${badgeSt(p)} ${p.categoria?`<span class="badge">${h(p.categoria)}</span>`:''}
    <div class="mut" style="margin:6px 0">👤 ${h(p.responsavel_nome)}${p.atribuido_nome?' → atribuído a '+h(p.atribuido_nome):''} · 📍 ${h(nomeLoja(p))} · ${p.inicio||p.prazo?`${dt(p.inicio)} → ${dt(p.prazo)}`:'sem prazo definido'}${p.fim_real?' · Fim real '+dt(p.fim_real):''}</div>
    <div>${p.avisos.map(a=>`<span class="av ${a.tipo}">${h(a.msg)}</span>`).join('')}</div>
    ${p.validacao==='aprovada'||p.validacao==='devolvida'?`<div class="meta" style="margin-top:6px"><b>Validação da Diretoria:</b> ${p.validacao==='aprovada'?'✔ Validado':'↩ Devolvido'} por ${h(p.validador_nome||'—')} em ${dt(p.validado_em)}${p.parecer?`<br><i>${h(p.parecer)}</i>`:''}</div>`:''}
    ${(p.status==='encerrado'||p.status==='cancelado'||p.status==='aguardando_validacao')&&p.encerrado_motivo?`<div class="meta" style="margin-top:6px"><b>${p.status==='cancelado'?'Cancelado':(p.status==='aguardando_validacao'?destLabel(p.validacao_destino)+' solicitado':'Encerrado')}:</b> ${h(p.encerrado_motivo)}</div>`:''}
    <div class="meta"><div class="g2"><div><b>Por quê:</b> ${h(p.justificativa)||'—'}</div><div><b>Onde:</b> ${h(nomeLoja(p))}${p.loja_nome&&p.onde?' · '+h(p.onde):''}</div><div><b>Como:</b> ${h(p.como)||'—'}</div><div><b>Quanto:</b> ${h(p.quanto)||'—'}${p.custo_estimado!=null?' · Custo estimado '+brl(p.custo_estimado):''}</div></div>
    ${p.observacoes?`<div style="margin-top:6px"><b>Situação / observações:</b> ${h(p.observacoes)}</div>`:''}
    ${+p.tem_meta?`<hr><b>${h(p.indicador)}</b> — de ${fmtV(p,p.valor_base)} para <b>${fmtV(p,p.valor_alvo)}</b> (${p.sentido==='aumento'?'aumento':'redução'} de ${nomeMeta(p)})`:''}
    ${p.progresso!=null?barra(p.progresso,corProg(p))+`<small>${p.progresso}% ${+p.tem_meta?'da meta':'das ações'}</small>`:''}</div>
    <div class="tabs" style="margin-top:10px">${tabs.map(t=>`<button class="tab ${t===abaDet?'on':''}" data-act="aba" data-aba="${t}" data-id="${p.id}">${{acoes:'Ações ('+j.acoes.length+')',acomp:'Acompanhamento',hist:'Histórico'}[t]}</button>`).join('')}</div>
    ${body}<div style="margin-top:12px;display:flex;gap:6px;justify-content:flex-end">${stBtns}${stBtns2}<button class="btn sm" data-act="fechar">Fechar</button></div>`);
}
const respOpts = (setorId, sel) => {
  const us = M.usuarios.slice().sort((a,b)=>(b.setores.includes(+setorId))-(a.setores.includes(+setorId)) || a.nome.localeCompare(b.nome));
  return us.map(u=>`<option value="${u.id}" ${+u.id===+sel?'selected':''}>${h(u.nome)}${u.setores.includes(+setorId)?'':' (outro setor)'}</option>`).join('');
};

// ── Formulário de plano ──
async function formPlano(id){
  let p = {tem_meta:0, tipo_meta:'percentual', sentido:'aumento', prioridade:'media', status:'nao_iniciado', responsavel_id:M.me.id, loja_id:'', atribuido_id:''};
  if (id) { const j = await api('plano', {id}); if (!j.ok) return toast(j.msg); p = j.plano; }
  const setores = M.setores.filter(s=>M.me.admin||M.me.gestor_em.includes(+s.id)||+s.id===+p.setor_id);
  // Cancelar / Excluir ficam escondidos atrás do check "Solicitar Cancelamento" (só ao editar)
  const podeCancelar = id && p.pode_editar && !['cancelado','aguardando_validacao'].includes(p.status);
  const cancelBox = id && (podeCancelar || M.me.admin) ? `<label style="margin-top:10px;color:#b91c1c;font-size:14px"><input type="checkbox" id="chkCancel" style="width:auto"> Solicitar Cancelamento</label>
    <div id="boxCancel" class="hide" style="margin-top:6px;gap:8px;display:flex;flex-wrap:wrap">
      ${podeCancelar?`<button type="button" class="btn dan" data-act="plano-st" data-id="${id}" data-st="cancelado">${M.me.diretoria?'Cancelar plano':'Cancelar (enviar p/ validação)'}</button>`:''}
      ${M.me.admin?`<button type="button" class="btn dan" data-act="excluir" data-id="${id}">Excluir plano</button>`:''}
    </div>` : '';
  const sel = (n,v,opts)=>`<select name="${n}">${opts.map(([k,l])=>`<option value="${k}" ${k==v?'selected':''}>${l}</option>`).join('')}</select>`;
  modal(`<h2>${id?'Editar':'Novo'} plano</h2><form data-form="plano"><input type="hidden" name="id" value="${id||''}">
    <div class="g2"><div><label>Categoria / assunto</label><input name="categoria" value="${h(p.categoria)}" list="dlCat" placeholder="Ex: Sistemas / Configurações"><datalist id="dlCat"><option value="Sistemas / Configurações"><option value="Sistemas / Acessos"><option value="Organização TI"><option value="Redução de custos / Contratos"><option value="Telefonia / Linhas e Ramais"></datalist></div>
      <div><label>Loja (onde)</label>${sel('loja_id',p.loja_id||'',[['','Todas as lojas'],...M.lojas.map(l=>[l.id,l.nome])])}</div></div>
    <label>O que será feito <b>*</b></label><input name="titulo" value="${h(p.titulo)}" required>
    <div class="g3"><div><label>Setor <b>*</b></label>${sel('setor_id',p.setor_id,setores.map(s=>[s.id,s.nome]))}</div>
      <div><label>Responsável (por quem) <b>*</b></label><select name="responsavel_id">${id && !p.responsavel_id ? `<option value="">${h(p.responsavel_texto||'Sem responsável')} (sem usuário no sistema)</option>` : ''}${respOpts(p.setor_id||setores[0]?.id, p.responsavel_id)}</select></div>
      <div><label>Ação atribuída a</label><select name="atribuido_id"><option value="">—</option>${respOpts(p.setor_id||setores[0]?.id, p.atribuido_id)}</select></div></div>
    <div class="g3"><div><label>Prioridade</label>${sel('prioridade',p.prioridade,[['baixa','Baixa'],['media','Média'],['alta','Alta (em andamento prioridade)']])}</div>
      ${id?'':`<div><label>Status inicial</label>${sel('status','nao_iniciado',[['nao_iniciado','Não iniciado'],['em_andamento','Em andamento']])}</div>`}</div>
    <div class="g3"><div><label>Data de início</label><input type="date" name="inicio" value="${h(p.inicio)}"></div><div><label>Data de término (prazo)</label><input type="date" name="prazo" value="${h(p.prazo)}"></div>
      <div><label>Custo estimado (R$)</label><input name="custo_estimado" inputmode="decimal" value="${p.custo_estimado??''}"></div></div>
    <label>Por quê</label><textarea name="justificativa" rows="2">${h(p.justificativa)}</textarea>
    <div class="g3"><div><label>Onde (detalhe)</label><input name="onde" value="${h(p.onde)}"></div><div><label>Como fazer</label><input name="como" value="${h(p.como)}"></div><div><label>Quanto (esforço)</label><input name="quanto" value="${h(p.quanto)}"></div></div>
    <label>Situação atual / observações</label><textarea name="observacoes" rows="2">${h(p.observacoes)}</textarea>
    <label style="margin-top:12px;color:var(--ink);font-size:14px"><input type="checkbox" name="tem_meta" value="1" style="width:auto" ${+p.tem_meta?'checked':''}> Este plano tem meta / indicador</label>
    <div class="meta ${+p.tem_meta?'':'hide'}" id="boxMeta"><div class="g3"><div><label>Indicador <b>*</b></label><input name="indicador" value="${h(p.indicador)}" placeholder="Ex: Faturamento de Peças"></div>
      <div><label>Sentido</label>${sel('sentido',p.sentido,[['aumento','Aumento ▲'],['reducao','Redução ▼']])}</div>
      <div><label>Tipo de meta</label>${sel('tipo_meta',p.tipo_meta,[['percentual','Percentual (%)'],['valor','Valor (R$)']])}</div></div>
      <div class="g3"><div><label>Meta <b>*</b></label><input name="valor_meta" inputmode="decimal" value="${p.valor_meta??''}"></div>
      <div><label>Saindo de (R$) <b>*</b></label><input name="valor_base" inputmode="decimal" value="${p.valor_base??''}"></div>
      <div><label>Para (calculado)</label><input id="calcAlvo" disabled></div></div></div>
    ${cancelBox}
    <div style="margin-top:12px;display:flex;gap:6px;justify-content:flex-end"><button type="button" class="btn" data-act="fechar">Fechar</button><button class="btn pri">Salvar</button></div></form>`);
  const chk = $('#chkCancel'); if (chk) chk.addEventListener('change', () => $('#boxCancel').classList.toggle('hide', !chk.checked));
  const f = $('#modal form'); const calc = () => {
    const n = v=>parseFloat(String(v).replace(/\./g,'').replace(',','.')), b=n(f.valor_base.value), m=n(f.valor_meta.value);
    const d = f.tipo_meta.value==='percentual' ? b*m/100 : m; const al = f.sentido.value==='aumento'?b+d:b-d;
    $('#calcAlvo').value = isNaN(al) ? '' : brl(al);
  };
  f.addEventListener('input', calc); calc();
  f.tem_meta.addEventListener('change', ()=>$('#boxMeta').classList.toggle('hide', !f.tem_meta.checked));
  f.setor_id.addEventListener('change', ()=>{ f.responsavel_id.innerHTML = respOpts(f.setor_id.value, f.responsavel_id.value); const a=f.atribuido_id.value; f.atribuido_id.innerHTML = '<option value="">—</option>'+respOpts(f.setor_id.value, a); });
}

function modal(html){ $('#modal').innerHTML = `<div class="ov"><div class="md">${html}</div></div>`; }
const fechar = () => $('#modal').innerHTML = '';
async function refresh(planoId){ await Promise.all([carregarLista(), carregarMinhas()]); if (planoId) abrir(planoId); }
async function acao(a, params, planoId, okMsg){
  const j = await api(a, params, true);
  if (j.confirmar && confirm(j.msg)) return acao(a, {...params, forcar:1}, planoId, okMsg);
  if (!j.ok) { if (!j.confirmar) toast(j.msg); return j; }
  toast(okMsg||'Salvo.'); await refresh(planoId); return j;
}

// ── Eventos ──
document.addEventListener('click', async e => {
  const t = e.target.closest('[data-act],[data-tab]'); if (!t) return;
  if (t.dataset.tab) {
    document.querySelectorAll('.tabs:first-of-type .tab, .wrap > .tabs .tab').forEach(b=>b.classList.toggle('on', b===t));
    ['planos','minhas','dash'].forEach(v=>$('#v-'+v).classList.toggle('hide', v!==t.dataset.tab));
    if (t.dataset.tab==='dash') carregarDash(); return;
  }
  e.preventDefault(); const id = t.dataset.id, d = t.dataset;
  switch (d.act) {
    case 'ms-all': M.setores.forEach(s=>setoresSel.add(String(s.id))); renderMs(); carregarLista(); break;
    case 'ms-clear': setoresSel.clear(); renderMs(); carregarLista(); break;
    case 'pend': carregarLista(true); break;
    case 'novo': formPlano(); break;
    case 'editar': formPlano(id); break;
    case 'abrir': abaDet='acoes'; abrir(id); break;
    case 'aba': abaDet = d.aba; abrir(id); break;
    case 'fechar': fechar(); break;
    case 'resumo': document.querySelector('[data-tab=dash]').click(); break;
    case 'excluir': if (confirm('Excluir este plano e todas as suas ações e lançamentos? Não pode ser desfeito.')) acao('excluir_plano', {id}, 0, 'Plano excluído.').then(j => { if (j && j.ok) fechar(); }); break;
    case 'plano-st': {
      const extra = {};
      if (d.st==='encerrado' || d.st==='cancelado') { const m = prompt('Motivo do ' + (d.st==='cancelado'?'cancelamento':'encerramento') + ' (obrigatório):'); if (!m || !m.trim()) break; extra.motivo = m.trim(); }
      const r = await acao('status_plano', {id, status:d.st, ...extra}, id, ['concluido','encerrado','cancelado'].includes(d.st) && !M.me.diretoria ? 'Enviado para validação.' : 'Status atualizado.');
      break; }
    case 'aprovar': { const m = prompt('Parecer (opcional):', ''); if (m === null) break; acao('validar_plano', {id, decisao:'aprovar', parecer:m}, id, 'Conclusão validada.'); break; }
    case 'devolver': { const m = prompt('Motivo da devolução (obrigatório):'); if (!m || !m.trim()) break; acao('validar_plano', {id, decisao:'devolver', parecer:m.trim()}, id, 'Plano devolvido ao responsável.'); break; }
    case 'acao-st': acao('status_acao', {id, status:d.st}, 0, 'Ação atualizada.'); break;
    case 'acao-del': if (confirm('Excluir esta ação?')) acao('excluir_acao', {id}, d.plano, 'Ação excluída.'); break;
    case 'acomp-del': if (confirm('Excluir este lançamento?')) acao('excluir_acomp', {id}, d.plano, 'Lançamento excluído.'); break;
    case 'acao-edit': { const j = JSON.parse(d.j), f = $('#modal form[data-form=acao]');
      f.id.value=id; f.titulo.value=j.t; f.descricao.value=j.d||''; f.responsavel_id.value=j.r; f.prazo.value=j.p||''; f.scrollIntoView(); break; }
  }
});
$('#msBtn').addEventListener('click', e => { e.stopPropagation(); $('#msPanel').classList.toggle('hide'); });
document.addEventListener('click', e => { if (!e.target.closest('#msSetor')) $('#msPanel').classList.add('hide'); });
document.addEventListener('change', e => {
  if (e.target.dataset.ms) {
    e.target.checked ? setoresSel.add(e.target.dataset.ms) : setoresSel.delete(e.target.dataset.ms);
    renderMs(); carregarLista(); return;
  }
  if (e.target.dataset.act === 'acao-sel') acao('status_acao', {id:e.target.dataset.id, status:e.target.value}, e.target.dataset.plano, 'Ação atualizada.');
});
document.addEventListener('submit', async e => {
  const f = e.target; if (!f.dataset.form) return; e.preventDefault();
  const fd = Object.fromEntries(new FormData(f));
  if (f.dataset.form === 'plano') { const j = await acao('salvar_plano', fd, 0, 'Plano salvo.'); if (j && j.ok) fechar(); }
  if (f.dataset.form === 'acao')  acao('salvar_acao',  {...fd, plano_id:f.dataset.plano}, f.dataset.plano, 'Ação salva.');
  if (f.dataset.form === 'acomp') acao('acompanhar',   {...fd, plano_id:f.dataset.plano}, f.dataset.plano, 'Lançamento salvo.');
});
['fStatus','fResp','fLoja'].forEach(i=>$('#'+i).addEventListener('change', ()=>carregarLista()));
let tq; $('#fQ').addEventListener('input', ()=>{ clearTimeout(tq); tq=setTimeout(()=>carregarLista(),300); });
['dSetor','dPessoa'].forEach(i=>$('#'+i).addEventListener('change', carregarDash));
init();
</script>
</body>
</html>
