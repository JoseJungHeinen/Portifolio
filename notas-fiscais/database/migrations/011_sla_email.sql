-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 011 — Notificação de SLA excedido por e-mail
--
-- 1. nf_grupos.gestor_id — quem recebe o alerta quando uma etapa de GRUPO
--    estoura o SLA (aprovador usuário recebe direto no próprio e-mail).
-- 2. nf_sla_notificacoes — controle de envio (1 alerta por etapa/processo,
--    sem re-notificar a cada execução do verificador).
--
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/011_sla_email.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

ALTER TABLE `nf_grupos`
  ADD COLUMN `gestor_id` char(36) DEFAULT NULL
    COMMENT 'FK suave → usuarios.id — recebe alertas de SLA das etapas deste grupo';

CREATE TABLE IF NOT EXISTS `nf_sla_notificacoes` (
  `processo_id`   char(36)   NOT NULL,
  `numero_etapa`  tinyint(3) unsigned NOT NULL,
  `notificado_em` timestamp  NOT NULL DEFAULT current_timestamp(),
  `destinatario`  varchar(150) DEFAULT NULL,
  PRIMARY KEY (`processo_id`, `numero_etapa`),
  CONSTRAINT `fk_nfsla_processo` FOREIGN KEY (`processo_id`)
    REFERENCES `nf_processos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controle de alertas de SLA enviados (1 por etapa/processo)';
