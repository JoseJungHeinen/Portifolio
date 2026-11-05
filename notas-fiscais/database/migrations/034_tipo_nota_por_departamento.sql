-- 034_tipo_nota_por_departamento.sql
--
-- Marcação de visibilidade dos itens de lista por departamento.
--
-- Motivo: na abertura do processo o campo "Tipo de Nota" lista TODOS os tipos,
-- inclusive os que só fazem sentido em um departamento (ex.: "Ajuste - Peças",
-- que é de Peças). Quem abre processo de outro setor precisa garimpar entre
-- opções que nunca vai usar — e pode escolher a errada.
--
-- Muitos-para-muitos de propósito: um tipo pode servir a mais de um
-- departamento (ex.: "Despesas" vale para todos), e por isso a marcação é uma
-- checklist e não um campo único como o vínculo de setor→departamento.
--
-- REGRA DE COMPATIBILIDADE: item SEM nenhuma linha aqui continua visível para
-- todos os departamentos. É o estado dos 8 tipos que existem hoje, então nada
-- muda de comportamento no dia do deploy — a limitação só passa a valer para o
-- tipo em que alguém marcar departamentos.
--
-- Tabela genérica (item de lista, não "tipo de nota") porque o alvo é
-- nf_lista_itens: se amanhã outra lista precisar do mesmo recorte, não nasce
-- uma segunda tabela igual.

CREATE TABLE IF NOT EXISTS nf_lista_item_departamentos (
  item_id         CHAR(36) NOT NULL COMMENT 'nf_lista_itens.id',
  departamento_id CHAR(36) NOT NULL COMMENT 'nf_lista_itens.id do item da lista Departamentos',
  criado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (item_id, departamento_id),
  KEY idx_lid_departamento (departamento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Departamentos em que um item de lista fica visivel. Sem linha = visivel para todos.';
