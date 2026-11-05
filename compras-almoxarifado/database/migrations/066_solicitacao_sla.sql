-- 066_solicitacao_sla.sql
-- SLA por urgência (dias úteis, até a conclusão). O vencimento é calculado em PHP
-- (SolicitacaoCompraService::vencimentoSla): depois desta migration rodar
-- `php cli/recalcular_sla.php` para preencher sla_vence_em das SOLs existentes.
-- Produção: rodar DEPOIS da 065 e ANTES do código.

ALTER TABLE solicitacoes_compra
  ADD COLUMN sla_vence_em datetime NULL COMMENT 'NULL = sem SLA (Azul)' AFTER urgencia,
  ADD COLUMN concluida_em datetime NULL AFTER sla_vence_em,
  ADD KEY idx_sol_sla (urgencia, sla_vence_em);

-- Concluídas antes do SLA: data do evento de conclusão.
UPDATE solicitacoes_compra s
   SET s.concluida_em = (SELECT MAX(e.criado_em) FROM solicitacao_compra_eventos e
                          WHERE e.solicitacao_id = s.id AND e.status_para = 'concluida')
 WHERE s.status = 'concluida' AND s.concluida_em IS NULL;
