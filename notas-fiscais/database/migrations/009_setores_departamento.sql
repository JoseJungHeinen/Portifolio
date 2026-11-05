-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 009 — Vínculo Setor → Departamento
--
-- Cada setor pode pertencer a um departamento (item da lista de referência
-- "Departamentos" em nf_listas/nf_lista_itens). FK suave — integridade na
-- camada de aplicação, padrão do projeto para tabelas do sistema maior.
--
-- Ex.: Departamento "Administração" contém os setores TI, RH, Fiscal, Contabilidade.
--
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/009_setores_departamento.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

ALTER TABLE `setores`
  ADD COLUMN `departamento_id` char(36) DEFAULT NULL
    COMMENT 'FK suave → nf_lista_itens.id (lista Departamentos)',
  ADD KEY `idx_setores_departamento` (`departamento_id`);
