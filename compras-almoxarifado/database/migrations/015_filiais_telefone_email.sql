-- Migration 015: Adiciona telefone e email em filiais; torna cnpj nullable
-- Executar: mysql -u root Compras < database/migrations/015_filiais_telefone_email.sql

ALTER TABLE filiais
    ADD COLUMN telefone VARCHAR(20)  NULL AFTER endereco,
    ADD COLUMN email    VARCHAR(100) NULL AFTER telefone,
    MODIFY COLUMN cnpj  CHAR(14)     NULL;
