/**
 * Módulo de multas — substitui a planilha CONTROLE DE MULTAS.
 *
 *   Controle Geral  → multas
 *   aba Detran      → capturasDetran + capturaItens (o que o portal mostrou em cada captura)
 *   Veículos        → veiculosFrota
 *   Configuração    → orgaosAutuadores   (Multas_Geral.xlsx)
 *   Planilha1       → infracoesCtb       (Multas_Geral.xlsx)
 *
 * O status NÃO é editado: é recalculado por shared/multas/status.ts a partir
 * dos campos do checklist e guardado em `etapa`/`status` só para filtrar rápido.
 */
import {
  boolean,
  date,
  datetime,
  decimal,
  index,
  int,
  json,
  mysqlEnum,
  mysqlTable,
  text,
  timestamp,
  varchar,
} from "drizzle-orm/mysql-core";
import { ETAPAS, OCORRENCIAS_ESPECIAIS, STATUS } from "../shared/multas/status";

export const lojas = mysqlTable("lojas", {
  id: int("id").autoincrement().primaryKey(),
  nome: varchar("nome", { length: 80 }).notNull().unique(),
  /** CNPJ usado na consulta do PCSDetran (cnpj=... na URL). */
  cnpj: varchar("cnpj", { length: 14 }),
  ativa: boolean("ativa").default(true).notNull(),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
});
export type Loja = typeof lojas.$inferSelect;

export const veiculosFrota = mysqlTable(
  "veiculosFrota",
  {
    id: int("id").autoincrement().primaryKey(),
    placa: varchar("placa", { length: 7 }).notNull().unique(),
    modelo: varchar("modelo", { length: 120 }),
    renavam: varchar("renavam", { length: 20 }),
    lojaId: int("lojaId"),
    /** EXEMPLO, EUROVALE... (aba "Carros Eurovale") */
    empresa: varchar("empresa", { length: 40 }).default("EXEMPLO").notNull(),
    ativo: boolean("ativo").default(true).notNull(),
    createdAt: timestamp("createdAt").defaultNow().notNull(),
    updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
  },
  t => [index("veiculosFrota_loja_idx").on(t.lojaId)],
);
export type VeiculoFrota = typeof veiculosFrota.$inferSelect;

export const orgaosAutuadores = mysqlTable("orgaosAutuadores", {
  codigo: varchar("codigo", { length: 10 }).primaryKey(),
  nome: varchar("nome", { length: 200 }).notNull(),
});

export const infracoesCtb = mysqlTable("infracoesCtb", {
  /** Código + desdobramento, ex.: "74550" */
  codigo: varchar("codigo", { length: 10 }).primaryKey(),
  descricao: text("descricao").notNull(),
  amparoLegal: varchar("amparoLegal", { length: 60 }),
  infrator: varchar("infrator", { length: 40 }),
  gravidade: varchar("gravidade", { length: 40 }),
  orgaoCompetente: varchar("orgaoCompetente", { length: 60 }),
});

export const multas = mysqlTable(
  "multas",
  {
    id: int("id").autoincrement().primaryKey(),
    /** Nº do auto de infração — identifica a multa (coluna SERIE). */
    serie: varchar("serie", { length: 20 }).notNull().unique(),
    placa: varchar("placa", { length: 7 }).notNull(),
    veiculoId: int("veiculoId"),
    lojaId: int("lojaId"),

    // --- Dados vindos do DETRAN
    orgaoCodigo: varchar("orgaoCodigo", { length: 10 }),
    autuador: varchar("autuador", { length: 200 }),
    tipo: mysqlEnum("tipo", ["multa", "remulta"]),
    codigoInfracao: varchar("codigoInfracao", { length: 10 }),
    descricao: text("descricao"),
    local: text("local"),
    valor: decimal("valor", { precision: 10, scale: 2 }),
    dataInfracao: datetime("dataInfracao"),
    dataInfracaoTexto: varchar("dataInfracaoTexto", { length: 30 }),
    situacaoDetran: mysqlEnum("situacaoDetran", ["a_vencer", "vencida"]),
    /** null + vencimentoPendente=true → DETRAN mostra "--" */
    vencimentoDetran: date("vencimentoDetran", { mode: "string" }),
    vencimentoPendente: boolean("vencimentoPendente").default(false).notNull(),
    presenteNoDetran: boolean("presenteNoDetran").default(true).notNull(),
    primeiraCapturaEm: date("primeiraCapturaEm", { mode: "string" }),
    ultimaCapturaId: int("ultimaCapturaId"),
    saiuDoDetranEm: date("saiuDoDetranEm", { mode: "string" }),

    // --- Checklist (colunas L, M, O, P, Q). null = ainda não preenchido.
    prazoIdentificacao: date("prazoIdentificacao", { mode: "string" }),
    /** Quando não há prazo: "Não se aplica", "recurso", "VENDIDO"... (conta como preenchido) */
    prazoIdentificacaoNota: varchar("prazoIdentificacaoNota", { length: 60 }),
    emailEnviado: varchar("emailEnviado", { length: 30 }),
    identificacaoCondutor: varchar("identificacaoCondutor", { length: 60 }),
    condutorNome: varchar("condutorNome", { length: 120 }),
    faturaNumero: varchar("faturaNumero", { length: 30 }),
    pagamentoSituacao: varchar("pagamentoSituacao", { length: 60 }),
    pagamentoData: date("pagamentoData", { mode: "string" }),
    ocorrenciaEspecial: mysqlEnum("ocorrenciaEspecial", OCORRENCIAS_ESPECIAIS),
    observacoes: text("observacoes"),

    // --- Derivados (recalculados a cada alteração)
    etapa: mysqlEnum("etapa", ETAPAS).notNull(),
    status: mysqlEnum("status", STATUS).notNull(),

    origem: mysqlEnum("origem", ["planilha", "captura", "manual"]).notNull(),
    /** Linha original da planilha, para auditoria da migração. */
    importRaw: json("importRaw"),
    createdAt: timestamp("createdAt").defaultNow().notNull(),
    updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
  },
  t => [
    index("multas_status_idx").on(t.status),
    index("multas_loja_idx").on(t.lojaId),
    index("multas_placa_idx").on(t.placa),
  ],
);
export type Multa = typeof multas.$inferSelect;
export type InsertMulta = typeof multas.$inferInsert;

export const capturasDetran = mysqlTable("capturasDetran", {
  id: int("id").autoincrement().primaryKey(),
  origem: mysqlEnum("origem", ["robo_excel", "portal", "planilha_detran"]).notNull(),
  /** Lojas percorridas por completo — só elas podem "sair do DETRAN". */
  lojasCobertas: json("lojasCobertas").$type<string[]>().notNull(),
  totalLidas: int("totalLidas").default(0).notNull(),
  novas: int("novas").default(0).notNull(),
  atualizadas: int("atualizadas").default(0).notNull(),
  sairamDoDetran: int("sairamDoDetran").default(0).notNull(),
  erro: text("erro"),
  usuarioId: int("usuarioId"),
  iniciadaEm: timestamp("iniciadaEm").defaultNow().notNull(),
  finalizadaEm: timestamp("finalizadaEm"),
});

export const capturaItens = mysqlTable(
  "capturaItens",
  {
    id: int("id").autoincrement().primaryKey(),
    capturaId: int("capturaId").notNull(),
    serie: varchar("serie", { length: 20 }).notNull(),
    loja: varchar("loja", { length: 80 }),
    situacaoDetran: mysqlEnum("situacaoDetran", ["a_vencer", "vencida"]),
    vencimentoDetran: date("vencimentoDetran", { mode: "string" }),
    valor: decimal("valor", { precision: 10, scale: 2 }),
    bruto: json("bruto"),
  },
  t => [index("capturaItens_captura_idx").on(t.capturaId), index("capturaItens_serie_idx").on(t.serie)],
);

/** Histórico de alterações do checklist (quem mudou o quê). */
export const multasHistorico = mysqlTable(
  "multasHistorico",
  {
    id: int("id").autoincrement().primaryKey(),
    multaId: int("multaId").notNull(),
    usuarioId: int("usuarioId"),
    campo: varchar("campo", { length: 40 }).notNull(),
    de: text("de"),
    para: text("para"),
    createdAt: timestamp("createdAt").defaultNow().notNull(),
  },
  t => [index("multasHistorico_multa_idx").on(t.multaId)],
);
