-- Migration 030 — Permite registrar histórico de preço sem fornecedor/origem
-- (necessário para edições manuais do valor de referência, que não têm
-- fornecedor nem OC/cotação/NF associada).
ALTER TABLE historico_precos
    MODIFY COLUMN fornecedor_id CHAR(36) NULL,
    MODIFY COLUMN origem_id CHAR(36) NULL,
    MODIFY COLUMN origem ENUM('cotacao','ordem_compra','nota_fiscal','manual') NOT NULL;
