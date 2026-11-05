/**
 * Captura do DETRAN → multas.
 *
 * 1. `interpretarCartao` traduz um card do portal PCSDetran (as 8 colunas
 *    que o robô grava na aba "Captura") em campos — o mesmo que as
 *    fórmulas da aba DADOS fazem hoje.
 * 2. `aplicarCaptura` substitui o "colar em DETRAN, filtrar as novas e
 *    colar no Controle Geral": separa novas, atualizadas e as que saíram
 *    do DETRAN.
 */

export type TipoMulta = "multa" | "remulta";
export type SituacaoDetran = "a_vencer" | "vencida";

export interface CartaoBruto {
  /** "Placa ABC1D23 - FIAT/ARGO DRIVE 1.0" */
  placaModelo: string;
  /** "Órgão: 100" */
  orgao: string;
  /** " Série: J011542116" */
  serie: string;
  /** Descrição da infração */
  descricao: string;
  /** "Ocorrida em: 22/10/2026 às 16:43" */
  ocorrida: string;
  /** " Local: Rs - Br 386 - Km 305.00" */
  local: string;
  /** "A Vencer R$ 130,16" | "A Vencer até 04/01/2027 R$ 130,16" | "Vencida em 11/10/2018 R$ 130,16" */
  situacao: string;
  /** Loja informada na captura */
  loja: string;
}

export interface MultaCapturada {
  serie: string;
  placa: string;
  modelo: string | null;
  orgaoCodigo: string | null;
  descricao: string;
  local: string | null;
  /** ISO "aaaa-mm-ddThh:mm" */
  dataInfracao: string | null;
  /** Texto original, como a planilha guarda ("22/10/2026 às 16:43") */
  dataInfracaoTexto: string | null;
  situacaoDetran: SituacaoDetran;
  /** ISO "aaaa-mm-dd"; null = DETRAN ainda não deu vencimento ("--") */
  vencimento: string | null;
  valor: number | null;
  tipo: TipoMulta;
  loja: string;
}

const dataBr = /(\d{2})\/(\d{2})\/(\d{4})/;
const isoDeBr = (s: string): string | null => {
  const m = dataBr.exec(s);
  return m ? `${m[3]}-${m[2]}-${m[1]}` : null;
};

/** Devolve null para linhas que não são cards de multa (coluna Y ≠ "P"). */
export function interpretarCartao(c: CartaoBruto): MultaCapturada | null {
  const pm = (c.placaModelo ?? "").trim();
  if (!pm.startsWith("P")) return null;
  const placa = /^Placa\s+([A-Z0-9]{7})/i.exec(pm)?.[1]?.toUpperCase();
  const serie = (c.serie ?? "").replace(/^\s*S[ée]rie:\s*/i, "").trim();
  if (!placa || !serie) return null;

  const modelo = pm.split(" - ").slice(1).join(" - ").trim() || null;
  const orgaoCodigo = /(\d+)/.exec(c.orgao ?? "")?.[1] ?? null;
  const dataInfracaoTexto = (c.ocorrida ?? "").replace(/^\s*Ocorrida em:\s*/i, "").trim() || null;
  const hora = /(\d{2}):(\d{2})/.exec(dataInfracaoTexto ?? "");
  const dia = dataInfracaoTexto ? isoDeBr(dataInfracaoTexto) : null;

  const sit = (c.situacao ?? "").trim();
  const situacaoDetran: SituacaoDetran = /^vencida/i.test(sit) ? "vencida" : "a_vencer";
  const valorTxt = /R\$\s*([\d.]+,\d{2})/.exec(sit)?.[1];
  const descricao = (c.descricao ?? "").trim();

  return {
    serie,
    placa,
    modelo,
    orgaoCodigo,
    descricao,
    local: (c.local ?? "").replace(/^\s*Local:\s*/i, "").trim() || null,
    dataInfracao: dia ? (hora ? `${dia}T${hora[1]}:${hora[2]}` : dia) : null,
    dataInfracaoTexto,
    situacaoDetran,
    vencimento: isoDeBr(sit.split("R$")[0]),
    valor: valorTxt ? Number(valorTxt.replace(/\./g, "").replace(",", ".")) : null,
    // A multa por não identificar o condutor é a "Remulta" na planilha.
    tipo: /^multa/i.test(descricao) ? "remulta" : "multa",
    loja: (c.loja ?? "").trim(),
  };
}

export interface MultaExistente {
  serie: string;
  presenteNoDetran: boolean;
  lojaNome?: string | null;
}

export interface ResultadoCaptura {
  /** Não existiam: entram como "Nova". */
  novas: MultaCapturada[];
  /** Já existiam: atualizar vencimento/valor/situação e marcar presente. */
  atualizadas: MultaCapturada[];
  /** Estavam no DETRAN e sumiram (pagas/baixadas): viram "Finalizado Detran". */
  sairamDoDetran: string[];
  /** Séries que vieram repetidas na própria captura (mantida a 1ª). */
  repetidasNaCaptura: string[];
}

/**
 * @param lojasCobertas lojas que esta captura percorreu por completo.
 *   Só multas dessas lojas podem ser dadas como "saíram do DETRAN" —
 *   assim uma captura parcial (só uma loja) não finaliza as outras.
 */
export function aplicarCaptura(
  existentes: Iterable<MultaExistente>,
  capturadas: MultaCapturada[],
  lojasCobertas: string[],
): ResultadoCaptura {
  const chave = (s: string) => s.trim().toUpperCase();
  const porSerie = new Map<string, MultaExistente>();
  for (const e of existentes) {
    if (!porSerie.has(chave(e.serie))) porSerie.set(chave(e.serie), e);
  }

  const vistas = new Set<string>();
  const res: ResultadoCaptura = { novas: [], atualizadas: [], sairamDoDetran: [], repetidasNaCaptura: [] };
  for (const c of capturadas) {
    const k = chave(c.serie);
    if (vistas.has(k)) {
      res.repetidasNaCaptura.push(c.serie);
      continue;
    }
    vistas.add(k);
    (porSerie.has(k) ? res.atualizadas : res.novas).push(c);
  }

  const cobertas = new Set(lojasCobertas.map(l => l.trim().toLowerCase()));
  for (const [k, e] of porSerie) {
    if (!e.presenteNoDetran || vistas.has(k)) continue;
    if (cobertas.has((e.lojaNome ?? "").trim().toLowerCase())) res.sairamDoDetran.push(e.serie);
  }
  return res;
}

/**
 * Campos que a aba DETRAN já preenche ao lançar uma Remulta
 * (=SE(F2="Remulta";"Não";"")): não há condutor a identificar.
 */
export function camposIniciaisDaNova(m: MultaCapturada) {
  return m.tipo === "remulta"
    ? { emailEnviado: "Não", identificacaoCondutor: "Não" }
    : { emailEnviado: null, identificacaoCondutor: null };
}
