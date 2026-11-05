-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 012 — Recorrência dos alertas de SLA
-- 1º alerta: 5 min após estourar o SLA. Reenvio: a cada hora, entre 8h e 18h,
-- enquanto a etapa continuar pendente. notificado_em passa a guardar o ÚLTIMO envio.
-- ═══════════════════════════════════════════════════════════════════════════
USE `compras`;

ALTER TABLE `nf_sla_notificacoes`
  ADD COLUMN `alertas_enviados` int unsigned NOT NULL DEFAULT 1
    COMMENT 'Total de alertas já enviados para esta etapa';
