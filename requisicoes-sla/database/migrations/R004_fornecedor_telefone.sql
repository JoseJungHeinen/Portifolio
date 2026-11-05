-- R004_fornecedor_telefone.sql — Exemplo Requisições (banco próprio `requisicoes`)
-- A importação por planilha junta "Fone Fixo / Celular" num campo só; 20 caracteres cortava quase sempre.
-- Produção: rodar à mão ANTES do código.

ALTER TABLE fornecedores
  MODIFY COLUMN telefone varchar(60) NULL;
