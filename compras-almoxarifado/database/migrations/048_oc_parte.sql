-- 048_oc_parte.sql
-- Compra fracionada: uma requisição pode gerar mais de uma OC.
--
-- O número da OC é derivado do número da requisição, e numero_oc tem índice
-- UNIQUE (uk_oc_numero) — sem um sufixo, a segunda OC da mesma requisição
-- morre com erro de chave duplicada. Esta coluna torna o sufixo um dado
-- consultável, em vez de algo a ser extraído da string do número.
--
-- Em MariaDB o COMMENT vem ANTES do AFTER; a ordem inversa é erro de sintaxe.
-- ADD COLUMN IF NOT EXISTS é o padrão das migrations 009, 043 e 044 daqui.
--
-- Não há backfill: NOT NULL DEFAULT 1 já atribui 1 às linhas existentes no
-- próprio ALTER, que é o valor correto — toda OC de hoje é a primeira da sua
-- requisição.

ALTER TABLE ordens_compra
  ADD COLUMN IF NOT EXISTS parte smallint(5) unsigned NOT NULL DEFAULT 1
  COMMENT 'Ordem da OC dentro da requisicao: 1 = primeira, 2+ = compra fracionada'
  AFTER requisicao_id;
