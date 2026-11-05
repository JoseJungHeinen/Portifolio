-- 050_fator_converte_linha.sql
-- O fator passa a REESCREVER a linha da nota, não só a saída para estoque.
--
-- Decisão do usuário (2026-09-10): ao informar "1 caixa = 10 unidades", a
-- própria linha da conferência passa a valer 20,00 UN a 30,00 — e não mais
-- 2 CX a 300,00 com uma coluna separada mostrando a conversão.
--
-- Como quantidade, valor_unitario e unidade agora guardam o valor EFETIVO
-- (convertido), o que veio no XML precisa ser preservado em outro lugar:
--   *_nf  = documento fiscal, imutável, escrito só na importação
--   sem sufixo = efetivo, na unidade do item, reescrito pela conferência
--
-- Isso dá três coisas: conferência contra o papel continua possível, o fator
-- é idempotente (recalcula sempre a partir do original, nunca do já
-- convertido) e voltar o fator para 1 restaura os valores da nota.
--
-- Em MariaDB o COMMENT vem ANTES do AFTER; a ordem inversa é erro de sintaxe.

ALTER TABLE nota_fiscal_itens
  ADD COLUMN IF NOT EXISTS unidade_nf varchar(10) DEFAULT NULL
    COMMENT 'Unidade comercial do XML (uCom) - nunca reescrita'
    AFTER unidade,
  ADD COLUMN IF NOT EXISTS quantidade_nf decimal(10,2) NOT NULL DEFAULT 0
    COMMENT 'Quantidade do XML (qCom) - nunca reescrita pela conferencia'
    AFTER quantidade,
  ADD COLUMN IF NOT EXISTS valor_unitario_nf decimal(14,4) NOT NULL DEFAULT 0
    COMMENT 'Valor unitario do XML (vUnCom) - nunca reescrito'
    AFTER valor_unitario;

-- Backfill: notas já importadas nunca foram convertidas, então o valor atual
-- É o valor do documento. Reexecutar é seguro (reescreve com o mesmo valor).
UPDATE nota_fiscal_itens
   SET quantidade_nf     = quantidade,
       valor_unitario_nf = valor_unitario,
       unidade_nf        = unidade
 WHERE quantidade_nf = 0
    OR valor_unitario_nf = 0;

-- Notas já conferidas com a regra anterior (fator aplicado só na saída para
-- estoque) ficaram com a linha na unidade do fornecedor: quantidade 2 CX com
-- fator 10. O estoque dessas notas JÁ entrou convertido — 20 unidades — então
-- alinhar a linha à nova regra é só fazê-la refletir o que o estoque mostra.
-- Nada de estoque, histórico de preço ou valor_referencia é mexido aqui.
--
-- A condição quantidade = quantidade_nf é o que torna isso idempotente:
-- linha já alinhada não bate e fica de fora numa reexecução.
UPDATE nota_fiscal_itens nfi
  JOIN notas_fiscais nf ON nf.id = nfi.nota_fiscal_id
  LEFT JOIN itens i     ON i.id  = nfi.item_id
   SET nfi.quantidade       = nfi.quantidade_nf * nfi.fator_conversao,
       nfi.valor_unitario   = nfi.valor_unitario_nf / nfi.fator_conversao,
       nfi.custo_final_unit = nfi.custo_final_unit / nfi.fator_conversao,
       nfi.unidade          = COALESCE(NULLIF(i.unidade, ''), nfi.unidade_nf)
 WHERE nfi.fator_conversao <> 1
   AND nf.conferido_por IS NOT NULL
   AND nfi.quantidade = nfi.quantidade_nf;
