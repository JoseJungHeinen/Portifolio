/**
 * Regra de status das multas — tradução das colunas R e S da aba
 * "Controle Geral" da planilha CONTROLE DE MULTAS.
 *
 * A planilha não guarda o status: ela o deduz de quais campos do
 * checklist já estão preenchidos, verificando-os em ordem e parando
 * no primeiro vazio. Aqui é a mesma coisa, só que com campos tipados.
 *
 * Arquivo sem imports de propósito: roda em Node puro (type stripping),
 * no servidor e no cliente.
 */

/** Coluna S da planilha: em que passo do checklist a multa parou. */
export const ETAPAS = [
  "falta_data",
  "falta_email",
  "falta_identificar",
  "falta_faturar",
  "aguardando_vcto",
  "falta_pagar",
  "aguardando_baixar",
  "finalizado_detran",
  "fraude",
] as const;
export type Etapa = (typeof ETAPAS)[number];

/** Coluna R da planilha: o agrupamento usado no painel. */
export const STATUS = [
  "nova",
  "em_processo",
  "a_faturar",
  "aguardando_vcto",
  "a_pagar",
  "finalizado",
] as const;
export type StatusMulta = (typeof STATUS)[number];

export const OCORRENCIAS_ESPECIAIS = ["fraude"] as const;
export type OcorrenciaEspecial = (typeof OCORRENCIAS_ESPECIAIS)[number];

/** Campos que a regra precisa olhar. `null`/`undefined` = ainda não preenchido. */
export interface DadosStatusMulta {
  /** A multa apareceu na última captura do DETRAN? (coluna T não vazia) */
  presenteNoDetran: boolean;
  /** No DETRAN ainda sem data de vencimento ("--") — é autuação, não penalidade. */
  vencimentoPendenteNoDetran: boolean;
  /** L — PRAZO DE IDENTIFICAR */
  prazoIdentificacao?: Date | string | null;
  /** M — EMAIL (qualquer resposta conta, inclusive "não") */
  emailEnviado?: string | null;
  /** O — IDENTIFICADO (qualquer resposta conta, inclusive "não") */
  identificacaoCondutor?: string | null;
  /** P — FATURADO (número da fatura) */
  faturaNumero?: string | null;
  /** Q — PAGAMENTO (impresso, paga, advertência...) */
  pagamentoSituacao?: string | null;
  /** Marcação manual que se sobrepõe ao checklist (ex.: FRAUDE). */
  ocorrenciaEspecial?: OcorrenciaEspecial | null;
}

const preenchido = (v: unknown): boolean =>
  v !== null && v !== undefined && !(typeof v === "string" && v.trim() === "");

export function calcularEtapa(d: DadosStatusMulta): Etapa {
  if (d.ocorrenciaEspecial === "fraude") return "fraude";
  if (!d.presenteNoDetran) return "finalizado_detran";
  if (!preenchido(d.prazoIdentificacao)) return "falta_data";
  if (!preenchido(d.emailEnviado)) return "falta_email";
  if (!preenchido(d.identificacaoCondutor)) return "falta_identificar";
  if (!preenchido(d.faturaNumero)) return "falta_faturar";
  if (!preenchido(d.pagamentoSituacao)) {
    return d.vencimentoPendenteNoDetran ? "aguardando_vcto" : "falta_pagar";
  }
  return "aguardando_baixar";
}

export function statusDaEtapa(etapa: Etapa): StatusMulta {
  switch (etapa) {
    case "falta_data":
      return "nova";
    case "falta_email":
    case "falta_identificar":
      return "em_processo";
    case "falta_faturar":
      return "a_faturar";
    case "aguardando_vcto":
      return "aguardando_vcto";
    case "falta_pagar":
      return "a_pagar";
    default:
      return "finalizado";
  }
}

/** Linhas do "Painel Andamento das Multas", na ordem da planilha. */
export const LINHAS_PAINEL = [
  { chave: "nova", rotulo: "Novas Infrações", etapas: ["falta_data"] },
  {
    chave: "em_processo",
    rotulo: "Em Processo de Identificação Condutor",
    etapas: ["falta_email", "falta_identificar"],
  },
  { chave: "a_faturar", rotulo: "Realizar o Faturamento ao Cliente", etapas: ["falta_faturar"] },
  { chave: "aguardando_vcto", rotulo: "Aguardando Vcto no Detran", etapas: ["aguardando_vcto"] },
  { chave: "a_pagar", rotulo: "Realizar o Pagamento da Infração", etapas: ["falta_pagar"] },
  {
    chave: "aguardando_baixar",
    rotulo: "Paga aguardando Baixar no Detran",
    etapas: ["aguardando_baixar"],
  },
] as const satisfies ReadonlyArray<{ chave: string; rotulo: string; etapas: readonly Etapa[] }>;

/** Multa ainda em aberto no DETRAN (entra no "Total em Aberto"). */
export function emAberto(etapa: Etapa): boolean {
  return etapa !== "finalizado_detran" && etapa !== "fraude";
}

export interface ItemPainel {
  etapa: Etapa;
  valor: number;
  loja?: string | null;
}

export interface Painel {
  linhas: { chave: string; rotulo: string; quantidade: number; valor: number }[];
  emAberto: { quantidade: number; valor: number };
  finalizadas: { quantidade: number; valor: number };
  totalGeral: number;
  porLoja: { loja: string; total: number; emAberto: number; percentual: number }[];
}

/** Recalcula o "Gráfico - Controle" a partir das multas. */
export function montarPainel(itens: ItemPainel[]): Painel {
  const linhas = LINHAS_PAINEL.map(l => {
    const doGrupo = itens.filter(i => (l.etapas as readonly Etapa[]).includes(i.etapa));
    return {
      chave: l.chave,
      rotulo: l.rotulo,
      quantidade: doGrupo.length,
      valor: arred(doGrupo.reduce((s, i) => s + i.valor, 0)),
    };
  });
  const abertas = itens.filter(i => emAberto(i.etapa));
  const finalizadas = itens.filter(i => i.etapa === "finalizado_detran");

  const lojas = new Map<string, { total: number; emAberto: number }>();
  for (const i of itens) {
    const loja = i.loja?.trim() || "Sem loja";
    const acc = lojas.get(loja) ?? { total: 0, emAberto: 0 };
    acc.total++;
    if (emAberto(i.etapa)) acc.emAberto++;
    lojas.set(loja, acc);
  }

  return {
    linhas,
    emAberto: {
      quantidade: abertas.length,
      valor: arred(abertas.reduce((s, i) => s + i.valor, 0)),
    },
    finalizadas: {
      quantidade: finalizadas.length,
      valor: arred(finalizadas.reduce((s, i) => s + i.valor, 0)),
    },
    totalGeral: abertas.length + finalizadas.length,
    porLoja: [...lojas.entries()].map(([loja, v]) => ({
      loja,
      total: v.total,
      emAberto: v.emAberto,
      percentual: v.total ? v.emAberto / v.total : 0,
    })),
  };
}

const arred = (n: number) => Math.round(n * 100) / 100;
