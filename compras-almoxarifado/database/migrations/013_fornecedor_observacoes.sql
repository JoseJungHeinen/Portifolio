-- Migration 013: supplier notes
ALTER TABLE fornecedores
    ADD COLUMN observacoes TEXT NULL
        COMMENT 'Observacoes internas do cadastro do fornecedor'
        AFTER credito_limite;
