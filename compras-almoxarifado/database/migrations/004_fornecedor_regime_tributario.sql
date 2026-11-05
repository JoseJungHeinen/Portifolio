-- ══════════════════════════════════════════════════════════════════
-- Migration 004 — Fornecedores: campo regime_tributario
-- Data: 2026-05-16
-- ══════════════════════════════════════════════════════════════════

ALTER TABLE fornecedores
    ADD COLUMN regime_tributario ENUM('simples_nacional','lucro_presumido','lucro_real','mei') NULL
        COMMENT 'Regime tributário do fornecedor' AFTER estado;
