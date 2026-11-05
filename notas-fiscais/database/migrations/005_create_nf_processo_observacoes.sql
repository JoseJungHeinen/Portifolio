-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 005 — Criar tabela nf_processo_observacoes
--
-- Observações/comunicação entre participantes do processo.
-- Separado do histórico automático (nf_processo_historico).
--
-- Banco: compras
-- Executar: mysql -u root compras < database/migrations/005_create_nf_processo_observacoes.sql
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `nf_processo_observacoes` (
  `id`           char(36)     NOT NULL,
  `processo_id`  char(36)     NOT NULL,
  `autor_id`     char(36)     NOT NULL COMMENT 'FK suave → usuarios.id',
  `autor_nome`   varchar(100) NOT NULL COMMENT 'Desnormalizado — imutável',
  `texto`        text         NOT NULL,
  `criado_em`    timestamp    NOT NULL DEFAULT current_timestamp(),
  `deletado_em`  datetime     DEFAULT NULL COMMENT 'Soft delete',
  PRIMARY KEY (`id`),
  KEY `idx_nfpobs_processo` (`processo_id`),
  KEY `idx_nfpobs_criado` (`criado_em`),
  CONSTRAINT `fk_nfpobs_processo` FOREIGN KEY (`processo_id`)
    REFERENCES `nf_processos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Observações/comunicação entre participantes do processo NF';
