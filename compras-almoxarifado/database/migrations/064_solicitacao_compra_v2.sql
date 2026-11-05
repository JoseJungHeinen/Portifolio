-- 064_solicitacao_compra_v2.sql
-- Solicitação de Compra v2: itens com qtd/valor estimado, solicitante escolhido (aberto_por),
-- fornecedor sugerido, recorrência e marcações da Política COMP-001 (fora do orçamento, emergencial).
-- Spec: docs/superpowers/specs/2026-10-28-solicitacao-compra-v2-design.md
-- Produção: rodar DEPOIS da 059 e ANTES do código. COLLATE explícito (ver 045/059).

ALTER TABLE solicitacoes_compra
  MODIFY COLUMN descricao text NULL COMMENT 'Observações (opcional desde a v2)',
  ADD COLUMN aberto_por char(36) NULL COMMENT 'Quem preencheu; ≠ solicitante_id quando aberto em nome de outro' AFTER solicitante_id,
  ADD COLUMN fornecedor_sugerido varchar(150) NULL AFTER finalidade,
  ADD COLUMN recorrencia enum('unica','mensal') NOT NULL DEFAULT 'unica' AFTER fornecedor_sugerido,
  ADD COLUMN fora_orcamento tinyint(1) NOT NULL DEFAULT 0 AFTER recorrencia,
  ADD COLUMN emergencial tinyint(1) NOT NULL DEFAULT 0 AFTER fora_orcamento,
  ADD CONSTRAINT fk_sol_aberto_por FOREIGN KEY (aberto_por) REFERENCES usuarios(id);

CREATE TABLE IF NOT EXISTS solicitacao_compra_itens (
  id             char(36)          NOT NULL,
  solicitacao_id char(36)          NOT NULL,
  ordem          smallint unsigned NOT NULL,
  descricao      varchar(255)      NOT NULL,
  quantidade     decimal(10,2)     NOT NULL,
  unidade        varchar(10)       NOT NULL DEFAULT 'un',
  valor_unitario decimal(14,2)     NULL COMMENT 'estimado pelo solicitante',
  PRIMARY KEY (id),
  KEY idx_solit_sol (solicitacao_id, ordem),
  CONSTRAINT fk_solit_sol FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_compra(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: solicitações anteriores à v2 foram abertas pelo próprio solicitante.
UPDATE solicitacoes_compra SET aberto_por = solicitante_id WHERE aberto_por IS NULL;
