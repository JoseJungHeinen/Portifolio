-- Migration 031: Vendedor e número do pedido do carro na requisição de brinde
ALTER TABLE requisicoes_brinde
    ADD COLUMN vendedor_nome     VARCHAR(160) NOT NULL DEFAULT '' AFTER cliente_nome,
    ADD COLUMN numero_pedido_carro VARCHAR(60) NULL AFTER vendedor_nome;
