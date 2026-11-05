-- @migration 067_fat_pedido_apollo
-- Campo "Pedido" do Faturamento: o mesmo número do pedido no Apollo.
-- Sem chave única: o mesmo pedido do Apollo pode gerar mais de um FAT (a confirmar).
USE `compras`;

ALTER TABLE `nf_fat_pedidos`
  ADD COLUMN IF NOT EXISTS `pedido_apollo` varchar(30) DEFAULT NULL
  COMMENT 'Número do pedido no Apollo (C7)' AFTER `tipo_venda`,
  ADD KEY IF NOT EXISTS `idx_pedido_apollo` (`pedido_apollo`);
