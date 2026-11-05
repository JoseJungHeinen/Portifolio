-- Migration 010: credit limit per supplier
ALTER TABLE fornecedores
    ADD COLUMN credito_limite DECIMAL(14,2) NULL DEFAULT NULL
        COMMENT 'Limite de crédito concedido a este fornecedor (NULL = sem limite definido)';
