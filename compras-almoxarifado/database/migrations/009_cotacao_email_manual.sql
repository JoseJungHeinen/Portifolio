-- Migration 009: add email_manual_enviado_at to cotacao_fornecedores
-- Column already exists on live DB; this migration ensures it is recreated
-- on a fresh deployment from migrations.

ALTER TABLE cotacao_fornecedores
    ADD COLUMN IF NOT EXISTS email_manual_enviado_at DATETIME NULL DEFAULT NULL
        COMMENT 'Preenchido quando o comprador confirma envio manual do e-mail ao fornecedor';
