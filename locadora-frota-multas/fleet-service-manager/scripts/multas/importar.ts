/**
 * Importa o histórico das planilhas para o banco.
 *
 *   node scripts/multas/importar.ts [data/import]            → simulação (não grava)
 *   node scripts/multas/importar.ts [data/import] --gravar   → grava (precisa DATABASE_URL
 *                                                              e dependências instaladas)
 *
 * Antes: python -I scripts/multas/exportar_planilhas.py --controle ... --captura ...
 */
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { calcularEtapa, montarPainel, statusDaEtapa } from "../../shared/multas/status.ts";

const args = process.argv.slice(2);
const gravar = args.includes("--gravar");
const pasta = args.find(a => !a.startsWith("--")) ?? "data/import";
const ler = (n: string) => JSON.parse(readFileSync(join(pasta, n), "utf-8"));

const controle: any[] = ler("controle_geral.json");
const detran: any[] = ler("detran.json");
const veiculos: any[] = ler("veiculos.json");
const orgaos: any[] = ler("orgaos.json");
const infracoes: any[] = ler("infracoes.json");

// ---------- Lojas: nome canônico (corrige "Caxias Do Sul", "Cidade A " etc.)
const LOJAS = ["Cidade A", "Cidade D", "Cidade C", "Cidade B", "Cidade E"];
const lojaCanonica = (s: string | null | undefined): string | null => {
  const k = (s ?? "").trim().toLowerCase();
  if (!k) return null;
  return LOJAS.find(l => l.toLowerCase() === k) ?? s!.trim();
};

// ---------- Valores livres da planilha → valores do sistema (mantém o texto original no importRaw)
const simNao = (s: string | null) => {
  if (s === null) return null;
  const k = s.trim().toLowerCase();
  if (k === "sim") return "Sim";
  if (k === "não" || k === "nao") return "Não";
  return s.trim(); // "VENDIDO", "baixado", nome do condutor...
};
const dataIso = (s: string | null) => (s && /^\d{4}-\d{2}-\d{2}/.test(s) ? s.slice(0, 10) : null);

// ---------- Presença no DETRAN (aba Detran da última atualização da planilha)
const vencPorSerie = new Map<string, any>();
for (const d of detran) if (!vencPorSerie.has(d.serie.toUpperCase())) vencPorSerie.set(d.serie.toUpperCase(), d);

// ---------- Multas
const vistas = new Map<string, number>();
const duplicadas: string[] = [];
const multas: any[] = [];
for (const l of controle) {
  const k = l.serie.toUpperCase();
  if (vistas.has(k)) {
    duplicadas.push(`${l.serie} (linhas ${vistas.get(k)} e ${l.linha})`);
    continue; // planilha usa a 1ª ocorrência (PROCV/XLOOKUP)
  }
  vistas.set(k, l.linha);

  const d = vencPorSerie.get(k);
  const presente = !!d && !!d.vencimentoTexto;
  const ocorrencia = l.planilhaEtapa?.trim().toUpperCase() === "FRAUDE" ? ("fraude" as const) : null;
  // Prazo: data ISO, data dd/mm/aaaa perdida em texto, ou uma nota ("Não se aplica", "recurso"...)
  const prazoTxt: string | null = l.prazoIdentificacao;
  const prazoBr = prazoTxt ? /^(\d{2})\/(\d{2})\/(\d{4})/.exec(prazoTxt) : null;
  const prazoData = dataIso(prazoTxt) ?? (prazoBr ? `${prazoBr[3]}-${prazoBr[2]}-${prazoBr[1]}` : null);
  const prazoNota = prazoTxt && !prazoData ? prazoTxt.slice(0, 60) : null;
  const m = {
    serie: l.serie,
    placa: (l.placa ?? "").toUpperCase(),
    loja: lojaCanonica(l.loja),
    orgaoCodigo: l.orgaoCodigo,
    autuador: l.autuador,
    tipo: l.tipo ? (l.tipo.toLowerCase() === "remulta" ? "remulta" : "multa") : null,
    codigoInfracao: l.codigoInfracao && l.codigoInfracao !== "erro" ? l.codigoInfracao : null,
    valor: l.valor,
    dataInfracao: l.dataInfracao,
    dataInfracaoTexto: l.dataInfracaoTexto,
    presenteNoDetran: presente,
    vencimentoPendente: presente && d.vencimentoTexto === "--",
    vencimentoDetran: presente ? d.vencimento : null,
    primeiraCapturaEm: dataIso(l.dataCaptura),
    prazoIdentificacao: prazoData,
    prazoIdentificacaoNota: prazoNota,
    emailEnviado: simNao(l.email),
    identificacaoCondutor: simNao(l.identificado),
    faturaNumero: l.faturado,
    pagamentoSituacao: l.pagamento,
    ocorrenciaEspecial: ocorrencia,
    observacoes: l.obs,
    origem: "planilha" as const,
    importRaw: l,
  };
  const etapa = calcularEtapa({
    presenteNoDetran: m.presenteNoDetran,
    vencimentoPendenteNoDetran: m.vencimentoPendente,
    prazoIdentificacao: m.prazoIdentificacao ?? m.prazoIdentificacaoNota,
    emailEnviado: m.emailEnviado,
    identificacaoCondutor: m.identificacaoCondutor,
    faturaNumero: m.faturaNumero,
    pagamentoSituacao: m.pagamentoSituacao,
    ocorrenciaEspecial: m.ocorrenciaEspecial,
  });
  multas.push({ ...m, etapa, status: statusDaEtapa(etapa) });
}

// ---------- Veículos
const placasFrota = new Map<string, any>();
for (const v of veiculos) if (!placasFrota.has(v.placa)) placasFrota.set(v.placa, { ...v, loja: lojaCanonica(v.loja) });
const semVeiculo = new Set(multas.filter(m => !placasFrota.has(m.placa)).map(m => m.placa));

// ---------- Relatório
const painel = montarPainel(multas.map(m => ({ etapa: m.etapa, valor: m.valor ?? 0, loja: m.loja })));
console.log(`Lojas:              ${LOJAS.length}`);
console.log(`Veículos:           ${placasFrota.size} (${veiculos.length - placasFrota.size} placas repetidas na aba Veículos)`);
console.log(`Órgãos autuadores:  ${orgaos.length}`);
console.log(`Infrações (CTB):    ${infracoes.length}`);
console.log(`Multas:             ${multas.length}  (${duplicadas.length} séries duplicadas ignoradas)`);
for (const d of duplicadas.slice(0, 10)) console.log(`   duplicada: ${d}`);
console.log(`Placas de multa sem cadastro na frota: ${semVeiculo.size}`);
console.log(`Sem código de infração (planilha "erro"/vazio): ${multas.filter(m => !m.codigoInfracao).length}`);
console.log("\nPainel após importação:");
for (const l of painel.linhas) console.log(`  ${l.rotulo.padEnd(40)} ${String(l.quantidade).padStart(5)}  R$ ${l.valor.toFixed(2)}`);
console.log(`  ${"Total em aberto".padEnd(40)} ${String(painel.emAberto.quantidade).padStart(5)}  R$ ${painel.emAberto.valor.toFixed(2)}`);
console.log(`  ${"Finalizadas".padEnd(40)} ${String(painel.finalizadas.quantidade).padStart(5)}  R$ ${painel.finalizadas.valor.toFixed(2)}`);
console.log("  Por loja (total / em aberto):");
for (const l of painel.porLoja) console.log(`    ${l.loja.padEnd(16)} ${String(l.total).padStart(5)} / ${l.emAberto}`);

if (!gravar) {
  console.log("\nSimulação — nada foi gravado. Use --gravar para gravar no banco.");
} else {
  await gravarNoBanco();
}

async function gravarNoBanco() {
  if (!process.env.DATABASE_URL) throw new Error("DATABASE_URL não definida");
  const { drizzle } = await import("drizzle-orm/mysql2");
  const { sql } = await import("drizzle-orm");
  const s = await import("../../drizzle/multas.ts");
  const db = drizzle(process.env.DATABASE_URL);
  const lotes = <T>(xs: T[], n = 500) => Array.from({ length: Math.ceil(xs.length / n) }, (_, i) => xs.slice(i * n, i * n + n));

  await db.transaction(async tx => {
    for (const nome of LOJAS) await tx.insert(s.lojas).values({ nome }).onDuplicateKeyUpdate({ set: { nome } });
    const lojaId = new Map((await tx.select().from(s.lojas)).map(l => [l.nome.toLowerCase(), l.id]));
    const idLoja = (n: string | null) => (n ? lojaId.get(n.toLowerCase()) ?? null : null);

    for (const lote of lotes(orgaos))
      await tx.insert(s.orgaosAutuadores).values(lote).onDuplicateKeyUpdate({ set: { nome: sql`values(nome)` } });
    for (const lote of lotes(infracoes))
      await tx.insert(s.infracoesCtb).values(lote).onDuplicateKeyUpdate({ set: { descricao: sql`values(descricao)` } });

    const vs = [...placasFrota.values()].map(v => ({
      placa: v.placa, modelo: v.modelo, renavam: v.renavam, empresa: v.empresa, lojaId: idLoja(v.loja),
    }));
    for (const lote of lotes(vs))
      await tx.insert(s.veiculosFrota).values(lote).onDuplicateKeyUpdate({ set: { modelo: sql`values(modelo)` } });
    const veiculoId = new Map((await tx.select().from(s.veiculosFrota)).map(v => [v.placa, v.id]));

    const linhas = multas.map(({ loja, valor, dataInfracao, ...m }) => ({
      ...m,
      lojaId: idLoja(loja),
      veiculoId: veiculoId.get(m.placa) ?? null,
      valor: valor === null ? null : String(valor),
      dataInfracao: dataInfracao ? new Date(dataInfracao) : null,
    }));
    for (const lote of lotes(linhas, 300))
      await tx.insert(s.multas).values(lote).onDuplicateKeyUpdate({ set: { updatedAt: sql`now()` } });
  });
  console.log("\nGravado no banco.");
}
