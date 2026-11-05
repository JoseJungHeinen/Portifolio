-- @migration 065_faturamento_valores_cliente_comprovante
-- Ajustes de estrutura para salvar um pedido novo (comparação com a tela do pedido):
--   D    Valores do pedido em colunas próprias (antes improvisado em dados.valor_total)
--   B1   Estado civil e nacionalidade do cliente
--   E3   Comprovante ligado à linha de pagamento
USE `compras`;

ALTER TABLE `nf_fat_pedidos`
  ADD COLUMN IF NOT EXISTS `valor_veiculo`   decimal(14,2) DEFAULT NULL COMMENT 'D — valor do veículo'  AFTER `filial_id`,
  ADD COLUMN IF NOT EXISTS `valor_opcionais` decimal(14,2) DEFAULT NULL COMMENT 'D1 — opcionais'        AFTER `valor_veiculo`,
  ADD COLUMN IF NOT EXISTS `valor_frete`     decimal(14,2) DEFAULT NULL COMMENT 'D2 — frete'             AFTER `valor_opcionais`,
  ADD COLUMN IF NOT EXISTS `valor_total`     decimal(14,2) DEFAULT NULL COMMENT 'D3 — total do pedido'   AFTER `valor_frete`;

ALTER TABLE `nf_fat_clientes`
  ADD COLUMN IF NOT EXISTS `estado_civil`  varchar(30) DEFAULT NULL COMMENT 'B1.14' AFTER `email`,
  ADD COLUMN IF NOT EXISTS `nacionalidade` varchar(40) DEFAULT NULL COMMENT 'B1.15' AFTER `estado_civil`;

ALTER TABLE `nf_fat_anexos`
  ADD COLUMN IF NOT EXISTS `pagamento_id` char(36) DEFAULT NULL COMMENT 'E3 — linha de pagamento a que o comprovante pertence' AFTER `fat_numero`,
  ADD KEY IF NOT EXISTS `idx_pagamento` (`pagamento_id`);

-- Traz o total que estava no JSON `dados` para a coluna nova.
UPDATE `nf_fat_pedidos`
   SET `valor_total` = CAST(JSON_VALUE(`dados`, '$.valor_total') AS DECIMAL(14,2))
 WHERE `valor_total` IS NULL AND JSON_VALUE(`dados`, '$.valor_total') IS NOT NULL;
