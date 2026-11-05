-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 029 — Sigilo por template (RH/Jurídico) + escopo por filial
--
-- Dois eixos INDEPENDENTES de restrição de visibilidade, que se compõem
-- (o usuário precisa passar nos dois para enxergar um processo/nota):
--
--   EIXO B — template confidencial
--     nf_templates.confidencial       → marca o tipo de processo como sigiloso
--     nf_template_acesso_grupos       → liberação por GRUPO (nf_grupos)
--     (a liberação por USUÁRIO já existe em nf_template_acessos)
--     Sem bypass de admin — molde de nf_apollo_acessos (migration 028).
--
--   EIXO A — escopo por CNPJ de destino
--     nf_usuario_filiais              → filiais visíveis por usuário
--     Usuário SEM vínculo = vê todas (comportamento atual preservado).
--
-- Banco alvo: passado na CLI (compras no local, Notas no servidor) — SEM `USE`,
-- seguindo a convenção das migrations 025–028.
-- Local:    mysql -u root --default-character-set=utf8mb4 compras < database/migrations/029_sigilo_templates_e_escopo_filiais.sql
-- Servidor: mysql -u notas_app -p'…' Notas < ~/deploy_029.sql
-- ═══════════════════════════════════════════════════════════════════════════

-- ── EIXO B: marca de sigilo no template ───────────────────────────────────
ALTER TABLE `nf_templates`
  ADD COLUMN IF NOT EXISTS `confidencial` tinyint(1) NOT NULL DEFAULT 0
    COMMENT '1 = só quem tem liberação explícita (usuário ou grupo) enxerga os processos deste template'
    AFTER `nome`;

-- ── EIXO B: liberação de template por GRUPO ───────────────────────────────
-- Molde: nf_template_acessos (liberação por usuário), com soft revoke.
CREATE TABLE IF NOT EXISTS `nf_template_acesso_grupos` (
  `id`            char(36)     NOT NULL,
  `template_id`   char(36)     NOT NULL COMMENT 'FK suave → nf_templates.id',
  `grupo_id`      char(36)     NOT NULL COMMENT 'FK suave → nf_grupos.id',
  `concedido_por` char(36)     NOT NULL COMMENT 'FK suave → usuarios.id',
  `concedido_em`  timestamp    NOT NULL DEFAULT current_timestamp(),
  `motivo`        varchar(255) DEFAULT NULL,
  `revogado_por`  char(36)     DEFAULT NULL COMMENT 'FK suave → usuarios.id',
  `revogado_em`   datetime     DEFAULT NULL COMMENT 'Soft revoke — NULL = liberação ativa',
  PRIMARY KEY (`id`),
  KEY `idx_ntag_template` (`template_id`),
  KEY `idx_ntag_grupo` (`grupo_id`),
  KEY `idx_ntag_ativo` (`template_id`, `revogado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Liberação de template confidencial por grupo (sem bypass de admin)';

-- ── EIXO A: filiais visíveis por usuário ──────────────────────────────────
-- Usuário SEM nenhuma linha aqui continua vendo todas as filiais.
CREATE TABLE IF NOT EXISTS `nf_usuario_filiais` (
  `id`         char(36)  NOT NULL,
  `usuario_id` char(36)  NOT NULL COMMENT 'FK suave → usuarios.id',
  `filial_id`  char(36)  NOT NULL COMMENT 'FK suave → filiais.id',
  `criado_por` char(36)  DEFAULT NULL COMMENT 'FK suave → usuarios.id',
  `criado_em`  timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nuf_usuario_filial` (`usuario_id`, `filial_id`),
  KEY `idx_nuf_usuario` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Escopo de visibilidade por filial (CNPJ de destino); sem linhas = vê todas';
