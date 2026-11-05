-- Migration 014: requisitions can contain items from multiple product groups
ALTER TABLE requisicoes
    MODIFY COLUMN grupo_produto_id CHAR(36) NULL;
