-- 062_ordem_compra_politica.sql
-- Política de Compras COMP-001 na Ordem de Compra.
--   justificativa_excecao: OC gerada com menos cotações que o mínimo da política
--   (§8 — fornecedor exclusivo / emergência). Fica registrada para a Controladoria.
-- Deploy não envia migrations: rodar à mão no servidor, ANTES do código.

ALTER TABLE ordens_compra
  ADD COLUMN justificativa_excecao TEXT NULL
      COMMENT 'OC abaixo do mínimo de cotações (política §8)' AFTER status;
