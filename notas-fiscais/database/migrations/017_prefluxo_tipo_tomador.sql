-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 017 — Pré-fluxo: critérios de tipo de documento e tomador (CTe)
--                 + modo "arquivar" (guardar CTe sem abrir processo)
--
-- nf_notas_capturadas: guarda quem é o tomador do frete no CTe (quem paga),
--   resolvido do código <toma> do XML (0=remetente 1=expedidor 2=recebedor
--   3=destinatário 4=outros/CNPJ explícito).
-- nf_captura_regras: novos critérios opcionais (tipo_documento, tomador) e:
--   · fornecedor_cnpj agora é opcional (vazio = qualquer fornecedor) — permite
--     regra "arquivar todo CTe em que a filial não é o tomador";
--   · template_id agora é opcional — o modo "arquivar" não abre processo.
--
-- Executar: mysql -u notas_app -p Notas < database/migrations/017_prefluxo_tipo_tomador.sql
-- ═══════════════════════════════════════════════════════════════════════════

-- (tipo_documento da nota já existe — criado na migration 016)
ALTER TABLE `nf_notas_capturadas`
  ADD COLUMN IF NOT EXISTS `tomador_cnpj`  varchar(14) DEFAULT NULL
      COMMENT 'CTe: CNPJ de quem paga o frete (resolvido do código toma)' AFTER `cfop`,
  ADD COLUMN IF NOT EXISTS `tomador_papel` varchar(12) DEFAULT NULL
      COMMENT 'CTe: papel do tomador (remetente/expedidor/recebedor/destinatario/outros)' AFTER `tomador_cnpj`;

ALTER TABLE `nf_captura_regras`
  MODIFY `fornecedor_cnpj` char(14) DEFAULT NULL
      COMMENT 'CNPJ emitente (origem) — NULL/vazio = qualquer fornecedor',
  MODIFY `template_id` char(36) DEFAULT NULL
      COMMENT 'FK suave → nf_templates.id — NULL no modo arquivar',
  MODIFY `modo` enum('sugerir','automatico','arquivar') NOT NULL DEFAULT 'sugerir',
  ADD COLUMN IF NOT EXISTS `tipo_documento` enum('NFe','CTe','NFSe','CTOs') DEFAULT NULL
      COMMENT 'Critério opcional — NULL = qualquer tipo' AFTER `cfop`,
  ADD COLUMN IF NOT EXISTS `tomador` enum('filial','terceiro') DEFAULT NULL
      COMMENT 'Critério p/ CTe: filial = nós pagamos o frete; terceiro = outro paga. NULL = indiferente'
      AFTER `tipo_documento`;
