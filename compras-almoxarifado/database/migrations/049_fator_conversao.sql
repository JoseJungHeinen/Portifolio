-- 049_fator_conversao.sql
-- Conversão da unidade da nota para a unidade de estoque do item.
--
-- O fornecedor fatura na unidade comercial dele (uCom do XML), que nem sempre é
-- a unidade em que o item é estocado: compra-se a caixa, estoca-se a unidade.
-- Sem conversão, uma nota de 10 CX dá entrada de 10 UN em vez de 120.
--
-- O fator fica na LINHA DA NOTA, não no cadastro do item: o mesmo item pode
-- chegar em caixa numa nota e em unidade na outra, então isso é característica
-- do documento, não do produto. O conferente informa na tela de conferência.
--
-- decimal(12,4) cobre o caso inverso (0,0833) sem perder precisão relevante.
-- Em MariaDB o COMMENT vem ANTES do AFTER; a ordem inversa é erro de sintaxe.
-- Sem backfill: NOT NULL DEFAULT 1 já atribui 1 a tudo, que é "sem conversão".

ALTER TABLE nota_fiscal_itens
  ADD COLUMN IF NOT EXISTS fator_conversao decimal(12,4) NOT NULL DEFAULT 1
  COMMENT 'Quantas unidades de estoque valem 1 unidade desta linha da nota'
  AFTER unidade;
