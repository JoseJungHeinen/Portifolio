-- ══════════════════════════════════════════════════════════════════
-- Migration 005 — Requisições: frete_tipo, ipi_percentual, cno
-- Data: 2026-05-16
-- ══════════════════════════════════════════════════════════════════

ALTER TABLE requisicoes
    ADD COLUMN frete_tipo      ENUM('cif','fob') NULL    COMMENT 'Tipo de frete: CIF (incluso) ou FOB (por conta do comprador)' AFTER justificativa,
    ADD COLUMN ipi_percentual  DECIMAL(5,2)      NOT NULL DEFAULT 0.00 COMMENT 'Percentual de IPI aplicável' AFTER frete_tipo,
    ADD COLUMN cno             TINYINT(1)        NOT NULL DEFAULT 0     COMMENT 'Requisição vinculada a CNO (obra/construção civil)' AFTER ipi_percentual;
