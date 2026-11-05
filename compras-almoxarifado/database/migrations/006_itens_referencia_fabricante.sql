-- ══════════════════════════════════════════════════════════════════
-- Migration 006 — Itens: referencia e fabricante
-- Data: 2026-05-16
-- ══════════════════════════════════════════════════════════════════

ALTER TABLE itens
    ADD COLUMN referencia  VARCHAR(100) NULL COMMENT 'Código de referência / código alternativo do produto' AFTER nome,
    ADD COLUMN fabricante  VARCHAR(100) NULL COMMENT 'Nome do fabricante/marca' AFTER referencia;
