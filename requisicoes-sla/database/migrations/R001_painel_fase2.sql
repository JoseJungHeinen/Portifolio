-- R001_painel_fase2.sql — Exemplo Requisições (código próprio desde 29/10/2026)
-- Painel fase 2: entrega prevista e prazo de pagamento na OC; CND Federal e confirmação de regime
-- no fornecedor; entrega ao solicitante na SOL.
-- Spec: docs/2026-10-29-painel-compras-fase2.md
-- Banco COMPARTILHADO com o Compras: só colunas novas e NULL — o Compras não as conhece e segue igual.
-- Produção: rodar à mão ANTES do código. COLLATE/charset herdados das tabelas.

ALTER TABLE ordens_compra
  ADD COLUMN entrega_prevista date NULL COMMENT 'Requisições: data prevista de entrega' AFTER frete_previsto,
  ADD COLUMN prazo_pagamento_dias smallint unsigned NULL COMMENT 'Requisições: prazo de pagamento em dias' AFTER entrega_prevista;

ALTER TABLE fornecedores
  ADD COLUMN cnd_federal_validade date NULL COMMENT 'Requisições: validade da CND Federal' AFTER regime_tributario,
  ADD COLUMN cnd_federal_situacao enum('negativa','positiva','positiva_efeitos_negativa') NULL AFTER cnd_federal_validade,
  ADD COLUMN regime_confirmado_em date NULL COMMENT 'Requisições: última confirmação do regime tributário' AFTER cnd_federal_situacao;

ALTER TABLE solicitacoes_compra
  ADD COLUMN recebido_em datetime NULL COMMENT 'NF aprovada; aguarda confirmação do solicitante' AFTER concluida_em,
  ADD COLUMN entregue_em datetime NULL AFTER recebido_em,
  ADD COLUMN entregue_a varchar(150) NULL AFTER entregue_em,
  ADD COLUMN entrega_registrada_por char(36) NULL AFTER entregue_a,
  ADD CONSTRAINT fk_sol_entrega_por FOREIGN KEY (entrega_registrada_por) REFERENCES usuarios(id);
