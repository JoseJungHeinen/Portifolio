-- 041_indice_lista_etapa_decisao.sql
--
-- Fundação da ETAPA DE DECISÃO (fluxo ramificado).
--
-- Duas coisas independentes, que só se encontram quando alguém liga uma na
-- outra:
--
--   1. nf_lista_itens.indice — classificação numérica do item. Vários itens
--      compartilham o mesmo índice (Baterias, Lubrificantes e Pneus = 3;
--      Peças Originais e Acessórios Originais = 1). É DADO PURO: nenhuma
--      query existente o lê, campo de formulário segue informativo, e nada
--      nos processos ou templates atuais muda por causa dele.
--
--   2. nf_etapas.decisao_lista_id — a etapa do catálogo passa a poder estar
--      vinculada a uma lista. Etapa com vínculo é uma ETAPA DE DECISÃO: o
--      template que a usa precisará mapear cada índice da lista para uma
--      etapa destino.
--
-- Por que coluna própria e não nf_lista_itens.dados (JSON, que já existe):
-- o índice é CONTROLE DE FLUXO, não metadado descritivo. Colocá-lo em JSON
-- opcional repetiria o problema do `etapa_ordem` nos tipos de anexo — chave
-- ausente vira 0, não casa com etapa nenhuma e a regra é pulada em silêncio
-- (hoje: 35 de 90 versões publicadas nessa situação). Coluna tipada permite
-- perguntar ao banco "quais itens estão sem índice?".
--
-- NULL = sem índice. Só vira problema quando a lista for usada por uma etapa
-- de decisão — e aí a aprovação bloqueia com mensagem nomeando o item.

ALTER TABLE nf_lista_itens
  ADD COLUMN indice TINYINT UNSIGNED NULL DEFAULT NULL
      COMMENT 'Classificacao do item para etapa de decisao. Itens do mesmo indice seguem o mesmo caminho no fluxo. NULL = nao classificado.'
      AFTER valor;

CREATE INDEX idx_nf_lista_itens_indice ON nf_lista_itens (lista_id, indice);

ALTER TABLE nf_etapas
  ADD COLUMN decisao_lista_id CHAR(36) NULL DEFAULT NULL
      COMMENT 'Lista (nf_listas) que governa a ramificacao quando esta etapa e de decisao. NULL = etapa comum.'
      AFTER somente_observacao;

CREATE INDEX idx_nf_etapas_decisao_lista ON nf_etapas (decisao_lista_id);
