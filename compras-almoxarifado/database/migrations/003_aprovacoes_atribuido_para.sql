-- ══════════════════════════════════════════════════════════════════
-- Migration 003 — Aprovações: campo atribuido_para (aprovador direto)
-- Data: 2026-05-15
-- ══════════════════════════════════════════════════════════════════

ALTER TABLE aprovacoes
    ADD COLUMN atribuido_para CHAR(36) NULL AFTER nivel,
    ADD CONSTRAINT fk_aprov_atribuido FOREIGN KEY (atribuido_para) REFERENCES usuarios(id);
