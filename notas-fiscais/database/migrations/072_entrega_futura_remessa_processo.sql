-- @migration 072_entrega_futura_remessa_processo
-- A remessa de entrega futura passa a ser um PROCESSO normal (segue o fluxo e é
-- escriturada no Fiscal), só sem a obrigação do Financeiro (parcelas). Esta
-- coluna liga a remessa ao processo que a carrega.
USE `compras`;

ALTER TABLE `nf_entrega_futura_remessas`
  ADD COLUMN `processo_id` char(36) DEFAULT NULL
      COMMENT 'Processo da nota de remessa (NULL = remessa lançada sem processo)' AFTER `entrega_id`,
  ADD KEY `idx_nefr_processo` (`processo_id`);
