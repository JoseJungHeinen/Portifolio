-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 006 — Criar tabela nf_usuario_permissoes
--
-- Controle de acesso granular por usuário (substitui, quando configurado, o
-- mapa estático de config/permissions.php). Uma linha por usuário; ausência
-- de linha = usuário continua regido pelo mapa por perfil (comportamento
-- atual preservado até que um admin configure o usuário explicitamente).
--
-- Banco: compras
-- Executar: mysql -u root compras < database/migrations/006_create_nf_usuario_permissoes.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

CREATE TABLE IF NOT EXISTS `nf_usuario_permissoes` (
  `id`             char(36)  NOT NULL,
  `usuario_id`     char(36)  NOT NULL COMMENT 'FK suave → usuarios.id',
  `permissoes`     longtext  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ('{}')
                   COMMENT 'JSON: { "modulo": { "acao": true|false } }',
  `criado_em`      timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em`  timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `atualizado_por` char(36)  DEFAULT NULL COMMENT 'FK suave → usuarios.id (quem editou por último)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nfup_usuario` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Permissões granulares por usuário do módulo NF — sobrepõe o mapa por perfil quando presente';
