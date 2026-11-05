-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 019 — Substituir nf_processo_acessos por nf_template_acessos
--
-- Correção de modelo: a liberação granular pontual não faz sentido por
-- PROCESSO individual (cada NF é uma instância nova) — faz sentido por
-- TEMPLATE (o fluxo/categoria de NF, ex.: "Notas de despesa TI"). Uma
-- liberação por template cobre todos os processos daquele fluxo, passados
-- e futuros, sem precisar reconceder a cada novo processo aberto.
--
-- nf_processo_acessos foi criada na migration 018 e nunca teve dado real
-- em produção (só teste, já limpo) — droppar em vez de manter as duas
-- estruturas evita duplicidade de regra de acesso.
--
-- Banco: compras
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/019_template_acessos.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

DROP TABLE IF EXISTS `nf_processo_acessos`;

CREATE TABLE IF NOT EXISTS `nf_template_acessos` (
  `id`            char(36)     NOT NULL,
  `template_id`   char(36)     NOT NULL COMMENT 'FK suave → nf_templates.id',
  `usuario_id`    char(36)     NOT NULL COMMENT 'FK suave → usuarios.id — quem recebeu o acesso',
  `concedido_por` char(36)     NOT NULL COMMENT 'FK suave → usuarios.id — quem concedeu',
  `concedido_em`  timestamp    NOT NULL DEFAULT current_timestamp(),
  `motivo`        varchar(255) DEFAULT NULL,
  `revogado_por`  char(36)     DEFAULT NULL COMMENT 'FK suave → usuarios.id',
  `revogado_em`   datetime     DEFAULT NULL COMMENT 'Soft revoke — NULL = acesso ativo',
  PRIMARY KEY (`id`),
  KEY `idx_nfta_usuario` (`usuario_id`),
  KEY `idx_nfta_template_usuario` (`template_id`, `usuario_id`),
  CONSTRAINT `fk_nfta_template` FOREIGN KEY (`template_id`)
    REFERENCES `nf_templates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Liberação granular pontual de acesso a TODOS os processos de um template';
