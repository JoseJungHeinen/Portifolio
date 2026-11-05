-- ══════════════════════════════════════════════════════════════════
-- Migration 007 — Movimentos de Estoque: motivo e justificativa
-- Data: 2026-05-16
-- ══════════════════════════════════════════════════════════════════

ALTER TABLE movimentos_estoque
    ADD COLUMN motivo        ENUM('compra','inventario','perda','avaria','correcao','transferencia','outro')
                             NULL COMMENT 'Motivo do movimento' AFTER tipo,
    ADD COLUMN justificativa TEXT NULL COMMENT 'Justificativa obrigatória para ajustes manuais' AFTER motivo;
