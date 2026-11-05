-- Migration 003: Adicionar data_inicio e data_fim à tabela projetos
ALTER TABLE projetos
  ADD COLUMN data_inicio DATE NULL AFTER status,
  ADD COLUMN data_fim    DATE NULL AFTER data_inicio;
