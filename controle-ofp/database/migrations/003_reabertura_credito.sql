-- Onda 1 (revisão). Rodar UMA vez por banco.
SET NAMES utf8mb4;

ALTER TABLE ofps ADD COLUMN data_reabertura_credito DATE NULL AFTER data_credito;
