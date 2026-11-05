-- ════════════════════════════════════════════════════════════════════
-- 049 — Módulo Fiscal: leitura de IBS/CBS dos XMLs já recebidos
--
-- POR QUE
--   A reforma tributária entrou em fase de transição e os fornecedores
--   estão se adequando em ritmos diferentes: uns já mandam o grupo
--   <IBSCBSTot> preenchido, outros mandam zerado e outros nem mandam.
--   Medição no acervo do Server-APP em 26/08/2026: de 18.048 XMLs,
--   15.985 traziam o grupo e 13.251 traziam CBS > 0 — 507 de 939
--   fornecedores (54%) já emitem com valor.
--
--   Esta tela NÃO é base de pagamento nem de apuração. É o termômetro
--   de quem já se adequou.
--
-- O QUE MUDA
--   nf_fiscal_totais — uma linha por chave de NF-e, extraída do XML que
--     JÁ está em storage/nfe_xml. A carga não chama a Brobot: relê o
--     disco. `xml_mtime`/`xml_bytes` deixam a releitura barata — arquivo
--     inalterado é pulado.
--
--     Fornecedor sem IBS/CBS entra com valor ZERO (decisão do produto),
--     não some da lista. `tem_grupo_ibs_cbs` fica registrado à parte
--     porque "mandou zerado" e "não mandou o grupo" são coisas
--     diferentes na hora de explicar o zero.
--
--   nf_fiscal_conferencia — marcação MANUAL, uma linha por nota
--     conferida. Linha ausente = não conferida; desmarcar apaga a linha.
--     O status do fornecedor (Total/Parcial/Nenhuma) é derivado em
--     consulta, nunca gravado — ele muda sozinho quando entra nota nova
--     no período.
--
-- LIMITAÇÃO CONHECIDA
--   Não há tratamento de nota cancelada: a captura não guarda evento de
--   cancelamento hoje. Aceitável porque a tela não apura imposto.
--
-- Idempotente: rodar duas vezes não quebra.
-- ════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `nf_fiscal_totais` (
  `chave_nf`          char(44)      NOT NULL COMMENT 'Chave de acesso da NF-e — 1 linha por nota',
  `numero_nf`         varchar(20)   DEFAULT NULL,
  `data_emissao`      date          NOT NULL COMMENT 'dhEmi/dEmi do XML — é por ela que a tela filtra',
  `emitente_cnpj`     char(14)      NOT NULL COMMENT 'Fornecedor',
  `emitente_nome`     varchar(200)  NOT NULL,
  `destinatario_cnpj` char(14)      NOT NULL COMMENT 'CNPJ da filial de destino',
  `filial_id`         char(36)      DEFAULT NULL COMMENT 'FK suave → filiais.id (resolvida pelo CNPJ)',
  `filial_nome`       varchar(100)  DEFAULT NULL,
  `valor_produtos`    decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'ICMSTot/vProd',
  `valor_nota`        decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'ICMSTot/vNF',
  `base_ibs_cbs`      decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'IBSCBSTot/vBCIBSCBS',
  `valor_ibs_uf`      decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'gIBS/gIBSUF/vIBSUF',
  `valor_ibs_mun`     decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'gIBS/gIBSMun/vIBSMun',
  `valor_ibs`         decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'gIBS/vIBS — consolidado UF+Mun',
  `valor_cbs`         decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'gCBS/vCBS',
  `tem_grupo_ibs_cbs` tinyint(1)    NOT NULL DEFAULT 0 COMMENT '1 = o XML trouxe <IBSCBSTot>, com valor ou zerado',
  `xml_mtime`         int unsigned  NOT NULL DEFAULT 0 COMMENT 'mtime do arquivo lido — releitura pula o que não mudou',
  `xml_bytes`         int unsigned  NOT NULL DEFAULT 0,
  `lido_em`           datetime      NOT NULL,
  PRIMARY KEY (`chave_nf`),
  KEY `idx_nfft_periodo` (`data_emissao`, `emitente_cnpj`),
  KEY `idx_nfft_filial` (`destinatario_cnpj`, `data_emissao`),
  KEY `idx_nfft_emitente` (`emitente_cnpj`, `data_emissao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Totais fiscais (produtos, nota, IBS/CBS) extraidos dos XMLs em storage/nfe_xml';

CREATE TABLE IF NOT EXISTS `nf_fiscal_conferencia` (
  `chave_nf`      char(44) NOT NULL COMMENT 'FK suave → nf_fiscal_totais.chave_nf',
  `conferido_por` char(36) NOT NULL COMMENT 'FK suave → usuarios.id',
  `conferido_em`  datetime NOT NULL,
  PRIMARY KEY (`chave_nf`),
  KEY `idx_nffc_quem` (`conferido_por`, `conferido_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Conferencia MANUAL por nota. Linha ausente = nao conferida.';

-- ── Conferência ──────────────────────────────────────────────────────
SELECT
  (SELECT COUNT(*) FROM nf_fiscal_totais)       AS notas_carregadas,
  (SELECT COUNT(*) FROM nf_fiscal_conferencia)  AS notas_conferidas;
