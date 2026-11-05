-- Migration 004: Adicionar datas de inicio e termino as tarefas
ALTER TABLE tarefas
  ADD COLUMN data_inicio DATE NULL AFTER prioridade,
  ADD COLUMN data_fim    DATE NULL AFTER data_inicio;

UPDATE tarefas
SET data_inicio = DATE(criado_em)
WHERE data_inicio IS NULL
  AND criado_em IS NOT NULL;

UPDATE tarefas
SET data_fim = prazo
WHERE data_fim IS NULL
  AND prazo IS NOT NULL;
