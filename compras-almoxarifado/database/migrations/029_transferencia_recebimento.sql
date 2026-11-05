-- Migration 029 — Confirmação de recebimento de transferências
ALTER TABLE transferencia_itens
    ADD COLUMN quantidade_recebida DECIMAL(10,2) NULL AFTER quantidade;
