-- 055_oc_item_livre.sql
-- Linha de OC sem item de catalogo.
--
-- A requisicao tipo Ordem de Compra cota por valor total, entao nao ha preco por
-- item: a OC nasce com uma linha unica que descreve o pedido. As OCs de cotacao
-- formal continuam com item_id preenchido.

ALTER TABLE ordens_compra_itens
  MODIFY item_id char(36) NULL,
  ADD COLUMN descricao varchar(255) NULL AFTER item_id;
