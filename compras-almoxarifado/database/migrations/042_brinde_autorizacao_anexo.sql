-- 042_brinde_autorizacao_anexo.sql
-- Anexo obrigatorio da autorizacao do gerente para liberacao do brinde.
ALTER TABLE requisicoes_brinde
    ADD COLUMN autorizacao_anexo VARCHAR(255) NULL AFTER observacao;
