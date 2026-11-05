-- Migration 028: campo marca no catálogo de brindes
ALTER TABLE brindes ADD COLUMN marca VARCHAR(100) NULL AFTER codigo;
