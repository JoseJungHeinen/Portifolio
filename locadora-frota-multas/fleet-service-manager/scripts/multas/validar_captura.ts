/**
 * Confere o parser da captura (shared/multas/captura.ts) contra o que as
 * fórmulas da aba DADOS extraem, e simula o "filtrar as novas" da aba
 * DETRAN contra o Controle Geral.
 *
 *   node scripts/multas/validar_captura.ts [data/import]
 */
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { aplicarCaptura, interpretarCartao, type MultaCapturada } from "../../shared/multas/captura.ts";

const pasta = process.argv[2] ?? "data/import";
const ler = (n: string) => JSON.parse(readFileSync(join(pasta, n), "utf-8"));

// ---- 1. Parser x fórmulas da aba DADOS
const robo: any[] = ler("captura_robo.json");
const erros: string[] = [];
let validas = 0;
const capturadas: MultaCapturada[] = [];
for (const r of robo) {
  const m = interpretarCartao(r.bruto);
  const p = r.planilha;
  const planilhaValida = p.valida === "P";
  if (!m || !planilhaValida) {
    if (!!m !== planilhaValida) erros.push(`linha ${r.linha}: válida sistema=${!!m} planilha=${planilhaValida}`);
    continue;
  }
  validas++;
  capturadas.push(m);
  const vencPlanilha = p.vencimento === "--" ? null : (p.vencimento.match(/(\d{2})\/(\d{2})\/(\d{4})/) ?? []).slice(1).reverse().join("-") || p.vencimento;
  const checar: [string, unknown, unknown][] = [
    ["placa", m.placa, p.placa],
    ["série", m.serie, p.serie],
    ["órgão", m.orgaoCodigo, p.orgao],
    ["tipo", m.tipo, p.tipo?.toLowerCase()],
    ["valor", m.valor, p.valor],
    ["data", m.dataInfracaoTexto, p.data],
    ["vencimento", m.vencimento, vencPlanilha],
  ];
  for (const [campo, s, pl] of checar) {
    if (String(s) !== String(pl)) erros.push(`linha ${r.linha} ${campo}: sistema=${s} planilha=${pl}`);
  }
}
console.log(`Captura do robô: ${robo.length} linhas, ${validas} cards de multa`);
console.log(`Campos divergentes das fórmulas: ${erros.length}`);
for (const e of erros.slice(0, 20)) console.log("  " + e);

// ---- 2. "Filtrar as novas": captura atual x Controle Geral
const controle: any[] = ler("controle_geral.json");
const detran: any[] = ler("detran.json");
const presentes = new Set(detran.map(d => d.serie.toUpperCase()));
const existentes = controle.map(c => ({
  serie: c.serie,
  presenteNoDetran: presentes.has(c.serie.toUpperCase()),
  lojaNome: c.loja,
}));
const lojas = [...new Set(capturadas.map(c => c.loja))];
const r = aplicarCaptura(existentes, capturadas, lojas);
console.log(`\nAplicando a captura de hoje ao Controle Geral (lojas: ${lojas.join(", ")}):`);
console.log(`  novas (entrariam como "Nova"): ${r.novas.length}`);
console.log(`  já existentes (atualizar):     ${r.atualizadas.length}`);
console.log(`  saíram do DETRAN (finalizar):  ${r.sairamDoDetran.length}`);
console.log(`  repetidas na captura:          ${r.repetidasNaCaptura.length}`);
for (const n of r.novas.slice(0, 10)) console.log(`    nova: ${n.placa} ${n.serie} ${n.tipo} R$ ${n.valor} (${n.loja})`);

process.exitCode = erros.length ? 1 : 0;
