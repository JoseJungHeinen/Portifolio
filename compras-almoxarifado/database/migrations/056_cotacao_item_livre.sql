-- 056_cotacao_item_livre.sql
-- Requisição tipo Ordem de Compra: o item do pedido é texto livre, então a
-- resposta de cotação precisa aceitar linha sem item de catálogo — mesmo
-- padrão da 055, que fez isso em ordens_compra_itens.
-- A FK fk_cotitem_item continua válida: ela aceita NULL.

ALTER TABLE cotacao_itens
    MODIFY item_id char(36) NULL,
    ADD COLUMN IF NOT EXISTS descricao varchar(255) NULL AFTER item_id;
