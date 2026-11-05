-- R005_oc_condicao_pagamento.sql — Exemplo Requisições (banco próprio `requisicoes`)
-- OC: "Prazo de pagamento (dias)" vira "Condição de pagamento" com parcelas ("30/60/90").
-- condicao_pagamento = texto como digitado; parcelas_dias = normalizado ("30/60/90");
-- prazo_pagamento_dias segue gravado com o prazo médio arredondado (o painel lê dele).
-- OCs antigas ficam com as duas colunas NULL e mostram só "N dias".
-- Produção: rodar à mão ANTES do código.

ALTER TABLE ordens_compra
  ADD COLUMN condicao_pagamento varchar(100) NULL COMMENT 'Requisições: condição de pagamento (texto)' AFTER prazo_pagamento_dias,
  ADD COLUMN parcelas_dias varchar(100) NULL COMMENT 'Requisições: dias de cada parcela, ex. 30/60/90' AFTER condicao_pagamento;
