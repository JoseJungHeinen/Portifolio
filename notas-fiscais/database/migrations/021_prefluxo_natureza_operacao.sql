-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 021 — Pré-fluxo: critério "Natureza da operação" (natOp do XML)
--
-- nf_notas_capturadas.natureza_operacao: texto do <natOp> da NF-e / CT-e,
--   gravado pelo BrobotSync no momento da captura (NFS-e não tem o campo).
-- nf_captura_regras.natureza_operacao: critério opcional do tipo CONTÉM —
--   a regra casa quando o texto (sem acento, sem caixa) estiver contido na
--   natureza da nota. Ex.: "venda", "devolucao", "remessa".
--
-- Executar: mysql -u notas_app -p Notas < database/migrations/021_prefluxo_natureza_operacao.sql
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE `nf_notas_capturadas`
  ADD COLUMN IF NOT EXISTS `natureza_operacao` varchar(120) DEFAULT NULL
      COMMENT 'natOp do XML (NF-e/CT-e) — alimenta o critério de match do pré-fluxo'
      AFTER `cfop`;

ALTER TABLE `nf_captura_regras`
  ADD COLUMN IF NOT EXISTS `natureza_operacao` varchar(60) DEFAULT NULL
      COMMENT 'Critério opcional CONTÉM sobre o natOp (comparação sem acento/caixa) — NULL = qualquer'
      AFTER `cfop`;
