-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 007 — Adicionar coluna `valor` em nf_lista_itens
--
-- O código (TableList, Process::getParcelas, views) trata itens de lista como
-- texto simples em `valor`. O schema original guardava JSON em `dados`
-- (chave = id da coluna em nf_lista_colunas). Esta migration adiciona a
-- coluna `valor` e a popula com o primeiro valor do JSON existente.
--
-- Banco: compras
-- Executar: mysql -u root compras < database/migrations/007_add_valor_lista_itens.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

ALTER TABLE `nf_lista_itens`
  ADD COLUMN IF NOT EXISTS `valor` VARCHAR(255) NOT NULL DEFAULT '' AFTER `lista_id`;

UPDATE `nf_lista_itens`
SET `valor` = JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(`dados`, '$.*'), '$[0]'))
WHERE `valor` = '' AND JSON_VALID(`dados`);
