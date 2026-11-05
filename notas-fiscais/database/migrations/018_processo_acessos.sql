-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 018 — Criar tabela nf_processo_acessos
--
-- Liberação granular de acesso a um processo específico, para usuários que
-- não participam dele (não são criador nem aprovador de nenhuma etapa) e não
-- têm a permissão global processos.ver_todos. Complementa — não substitui —
-- o RBAC por módulo já existente em nf_usuario_permissoes/nf_perfil_permissoes.
--
-- Revogação é soft (revogado_em), no mesmo padrão de deletado_em já usado em
-- todo o schema, para manter histórico auditável em vez de apagar a linha.
--
-- Banco: compras
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/018_processo_acessos.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

CREATE TABLE IF NOT EXISTS `nf_processo_acessos` (
  `id`            char(36)     NOT NULL,
  `processo_id`   char(36)     NOT NULL,
  `usuario_id`    char(36)     NOT NULL COMMENT 'FK suave → usuarios.id — quem recebeu o acesso',
  `concedido_por` char(36)     NOT NULL COMMENT 'FK suave → usuarios.id — quem concedeu',
  `concedido_em`  timestamp    NOT NULL DEFAULT current_timestamp(),
  `motivo`        varchar(255) DEFAULT NULL,
  `revogado_por`  char(36)     DEFAULT NULL COMMENT 'FK suave → usuarios.id',
  `revogado_em`   datetime     DEFAULT NULL COMMENT 'Soft revoke — NULL = acesso ativo',
  PRIMARY KEY (`id`),
  KEY `idx_nfpa_usuario` (`usuario_id`),
  KEY `idx_nfpa_processo_usuario` (`processo_id`, `usuario_id`),
  CONSTRAINT `fk_nfpacessos_processo` FOREIGN KEY (`processo_id`)
    REFERENCES `nf_processos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Liberação granular pontual de acesso a um processo específico';
