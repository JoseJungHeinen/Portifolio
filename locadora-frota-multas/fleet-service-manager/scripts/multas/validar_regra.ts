/**
 * Confere se a regra de status do sistema (shared/multas/status.ts)
 * reproduz exatamente o que a planilha calcula.
 *
 * Uso (Node 22.18+ / 24, sem dependências):
 *   node scripts/multas/validar_regra.ts [data/import]
 */
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { calcularEtapa, montarPainel, type Etapa } from "../../shared/multas/status.ts";

const pasta = process.argv[2] ?? "data/import";
const ler = (n: string) => JSON.parse(readFileSync(join(pasta, n), "utf-8"));

type LinhaControle = Record<string, any>;
const controle: LinhaControle[] = ler("controle_geral.json");
const detran: LinhaControle[] = ler("detran.json");
const painelPlanilha = ler("painel_planilha.json");

// Coluna T = XLOOKUP(série, Detran!E, Detran!J) — primeira ocorrência, sem diferenciar maiúsculas.
const vencPorSerie = new Map<string, string | null>();
for (const d of detran) {
  const k = d.serie.toUpperCase();
  if (!vencPorSerie.has(k)) vencPorSerie.set(k, d.vencimentoTexto);
}

// Nome da etapa como aparece na coluna S da planilha.
const NOME_PLANILHA: Record<Etapa, string> = {
  falta_data: "Falta Data",
  falta_email: "Falta E-mail",
  falta_identificar: "Falta Identificar",
  falta_faturar: "Falta Faturar",
  aguardando_vcto: "Aguardando Vcto",
  falta_pagar: "Falta Pagar",
  aguardando_baixar: "Aguardando Baixar",
  finalizado_detran: "Finalizado Detran",
  fraude: "FRAUDE",
};

const itens: { etapa: Etapa; valor: number; loja: string | null }[] = [];
const divergencias: string[] = [];

for (const l of controle) {
  const venc = vencPorSerie.get(l.serie.toUpperCase());
  const etapa = calcularEtapa({
    presenteNoDetran: venc !== undefined && venc !== null && venc !== "",
    vencimentoPendenteNoDetran: venc === "--",
    prazoIdentificacao: l.prazoIdentificacao,
    emailEnviado: l.email,
    identificacaoCondutor: l.identificado,
    faturaNumero: l.faturado,
    pagamentoSituacao: l.pagamento,
    // Na planilha FRAUDE é digitado por cima da fórmula da coluna S.
    ocorrenciaEspecial: l.planilhaEtapa?.trim().toUpperCase() === "FRAUDE" ? "fraude" : null,
  });
  itens.push({ etapa, valor: l.valor ?? 0, loja: l.loja });

  const esperado = (l.planilhaEtapa ?? "").trim();
  if (NOME_PLANILHA[etapa] !== esperado) {
    divergencias.push(
      `linha ${l.linha} série ${l.serie}: sistema=${NOME_PLANILHA[etapa]} planilha=${JSON.stringify(l.planilhaEtapa)}`,
    );
  }
}

console.log(`Linhas comparadas: ${controle.length}`);
console.log(`Etapa idêntica à planilha: ${controle.length - divergencias.length}`);
console.log(`Divergências: ${divergencias.length}`);
for (const d of divergencias.slice(0, 30)) console.log("  " + d);

const painel = montarPainel(itens);
console.log("\nPainel                                    sistema            planilha");
const fmt = (q: number, v: number) => `${String(q).padStart(5)}  R$ ${v.toFixed(2).padStart(10)}`;
for (const [i, linha] of painel.linhas.entries()) {
  const p = painelPlanilha.linhas[i];
  const ok = p.quantidade === linha.quantidade && Math.abs((p.valor ?? 0) - linha.valor) < 0.01;
  console.log(`${ok ? "OK " : "XX "}${linha.rotulo.padEnd(40)}${fmt(linha.quantidade, linha.valor)}   ${fmt(p.quantidade, p.valor ?? 0)}`);
}
const pAberto = painelPlanilha.linhas.find((x: any) => x.rotulo.startsWith("Total de infrações em Aberto"));
const pFinal = painelPlanilha.linhas.find((x: any) => x.rotulo.startsWith("Total de Infrações Finalizadas"));
console.log(`${pAberto.quantidade === painel.emAberto.quantidade ? "OK " : "XX "}${"Total em aberto".padEnd(40)}${fmt(painel.emAberto.quantidade, painel.emAberto.valor)}   ${fmt(pAberto.quantidade, pAberto.valor)}`);
console.log(`${pFinal.quantidade === painel.finalizadas.quantidade ? "OK " : "XX "}${"Total finalizadas".padEnd(40)}${fmt(painel.finalizadas.quantidade, painel.finalizadas.valor)}   ${fmt(pFinal.quantidade, pFinal.valor)}`);

console.log("\nPor loja          sistema total/aberto   planilha total/aberto(digitado)");
for (const pl of painelPlanilha.lojas) {
  const s = painel.porLoja.find(x => x.loja === pl.loja);
  console.log(`${pl.loja.padEnd(18)}${String(s?.total).padStart(6)} / ${String(s?.emAberto).padEnd(10)}${String(pl.total).padStart(6)} / ${pl.emAberto}`);
}
const outras = painel.porLoja.filter(x => !painelPlanilha.lojas.some((p: any) => p.loja === x.loja));
for (const o of outras) console.log(`(fora do gráfico) ${o.loja}: ${o.total} total / ${o.emAberto} em aberto`);

process.exitCode = divergencias.length ? 1 : 0;
