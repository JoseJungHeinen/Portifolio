-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 002 — Adicionar permissões do módulo Workflow NF
--
-- Insere as permissões por perfil na tabela `permissoes_perfil` (existente).
-- Usa INSERT IGNORE para ser idempotente — seguro executar mais de uma vez.
--
-- Banco: compras
-- Tabela: permissoes_perfil
-- Executar: mysql -u root compras < database/migrations/002_add_workflow_nf_permissions.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

-- Permissões do módulo workflow_nf por perfil
-- acesso: 'total' | 'aprovar' | 'criar' | 'ver'
INSERT IGNORE INTO `permissoes_perfil` (perfil, modulo, acesso) VALUES
  ('admin',       'workflow_nf', 'total'),
  ('gestor',      'workflow_nf', 'aprovar'),
  ('diretor',     'workflow_nf', 'aprovar'),
  ('comprador',   'workflow_nf', 'criar'),
  ('almoxarife',  'workflow_nf', 'ver'),
  ('solicitante', 'workflow_nf', 'criar');
