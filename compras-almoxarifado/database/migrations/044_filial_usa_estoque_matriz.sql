-- Migration 044: filial que usa o estoque da matriz na Requisicao Interna
--
-- O combo de itens da Requisicao Interna so oferecia o que tinha saldo na
-- propria unidade. Como a matriz e uma filial separada e as unidades de
-- Cidade A quase nao mantem estoque proprio, o combo vinha vazio para elas.
--
-- A baixa nunca precisou mudar: Estoque::filialComEstoque() ja tenta a propria
-- unidade, depois a matriz, depois qualquer outra. Esta flag so libera a
-- exibicao.
--
-- COMMENT vem antes de AFTER: a ordem inversa e erro de sintaxe no MariaDB.

ALTER TABLE filiais
    ADD COLUMN IF NOT EXISTS usa_estoque_matriz TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Requisicao Interna desta unidade tambem lista o estoque da matriz' AFTER is_matriz;

-- Estado inicial acordado: as tres unidades de Cidade A. O nome e usado apenas
-- aqui, uma vez; em tempo de execucao tudo roda pela flag, entao renomear uma
-- filial depois nao quebra nada. Reexecutar e inofensivo.
UPDATE filiais
   SET usa_estoque_matriz = 1
 WHERE nome IN ('Cidade A FIAT', 'Cidade A Jeep', 'Cidade A Seminovos');
