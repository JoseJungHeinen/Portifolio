-- 065_solicitacao_urgencia.sql
-- Urgência da Solicitação (cor): ordena e destaca a fila; sem SLA em horas.
-- Spec: docs/superpowers/specs/2026-10-28-solicitacao-urgencia-design.md
-- Produção: rodar DEPOIS da 064 e ANTES do código.

ALTER TABLE solicitacoes_compra
  ADD COLUMN urgencia enum('emergencial','urgente','planejado','estrategico') NOT NULL DEFAULT 'planejado'
      COMMENT 'Vermelho/Laranja/Verde/Azul' AFTER emergencial,
  ADD KEY idx_sol_urgencia (urgencia);
