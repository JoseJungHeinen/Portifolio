-- R003_oc_marcacoes.sql — Exemplo Requisições (banco próprio `requisicoes` desde 30/10/2026)
-- Etapa 2 — Fluxo (S5): marcações manuais na OC para a barra de andamento.
--   06 Envio da OC         → enviada_em / enviada_por (e status 'enviado')
--   08 CT-e / trânsito     → transito_em / transito_por / cte_numero (opcional)
--   09 Recebimento físico  → recebido_fisico_em (data) / recebido_fisico_por (quem recebeu, texto)
--                            / recebimento_registrado_por (usuário que marcou)
-- Spec: docs/2026-10-30-sistema-compras-proprio.md
-- Só colunas novas e NULL (aditiva). Produção: rodar à mão ANTES do código.

ALTER TABLE ordens_compra
  ADD COLUMN enviada_em datetime NULL COMMENT 'Etapa 06: OC enviada ao fornecedor' AFTER prazo_pagamento_dias,
  ADD COLUMN enviada_por char(36) NULL AFTER enviada_em,
  ADD COLUMN transito_em datetime NULL COMMENT 'Etapa 08: mercadoria em trânsito' AFTER enviada_por,
  ADD COLUMN transito_por char(36) NULL AFTER transito_em,
  ADD COLUMN cte_numero varchar(60) NULL COMMENT 'Etapa 08: nº do CT-e (opcional)' AFTER transito_por,
  ADD COLUMN recebido_fisico_em date NULL COMMENT 'Etapa 09: data do recebimento físico' AFTER cte_numero,
  ADD COLUMN recebido_fisico_por varchar(150) NULL COMMENT 'Etapa 09: quem recebeu' AFTER recebido_fisico_em,
  ADD COLUMN recebimento_registrado_por char(36) NULL AFTER recebido_fisico_por,
  ADD CONSTRAINT fk_oc_enviada_por FOREIGN KEY (enviada_por) REFERENCES usuarios(id),
  ADD CONSTRAINT fk_oc_transito_por FOREIGN KEY (transito_por) REFERENCES usuarios(id),
  ADD CONSTRAINT fk_oc_receb_reg_por FOREIGN KEY (recebimento_registrado_por) REFERENCES usuarios(id);
