-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 028 — Consulta de chave no Apollo (Geral › Apollo)
--
-- Cria a infraestrutura da tela que confere a chave dos processos no ERP Apollo
-- (Oracle, tabela FAT_MOVIMENTO_CAPA) e opcionalmente encerra o processo.
--
--   nf_apollo_acessos        → liberação do console POR USUÁRIO (inclusive admin)
--   nf_apollo_consultas      → cabeçalho de cada execução de lote
--   nf_apollo_consulta_itens → resultado por processo (retomada de erro + auditoria)
--
-- Também adiciona o status terminal próprio `encerrado_apollo` em nf_processos e
-- semeia a configuração de conexão (grupo `apollo`) — SEM a senha, que é
-- preenchida na tela (Geral › Apollo), nunca versionada em git.
--
-- Banco alvo: passado na CLI (compras no local, Notas no servidor) — SEM `USE`,
-- seguindo a convenção das migrations 025–027.
-- Local:    mysql -u root --default-character-set=utf8mb4 compras < database/migrations/028_apollo_consulta.sql
-- Servidor: mysql -u notas_app -p'…' Notas < ~/deploy_028.sql
-- ═══════════════════════════════════════════════════════════════════════════

-- ── Status terminal próprio para processos encerrados via Apollo ───────────
ALTER TABLE `nf_processos`
  MODIFY COLUMN `status`
    enum('aberto','aprovado','devolvido','cancelado','cancelamento_solicitado','encerrado_apollo')
    NOT NULL DEFAULT 'aberto';

-- ── Liberação de acesso ao console Apollo, por usuário (inclusive admin) ───
-- Molde: nf_template_acessos. A checagem (ApolloAccess) NÃO tem bypass de admin.
CREATE TABLE IF NOT EXISTS `nf_apollo_acessos` (
  `id`            char(36)     NOT NULL,
  `usuario_id`    char(36)     NOT NULL COMMENT 'FK suave → usuarios.id — quem recebeu o acesso',
  `concedido_por` char(36)     NOT NULL COMMENT 'FK suave → usuarios.id — quem concedeu',
  `concedido_em`  timestamp    NOT NULL DEFAULT current_timestamp(),
  `motivo`        varchar(255) DEFAULT NULL,
  `revogado_por`  char(36)     DEFAULT NULL COMMENT 'FK suave → usuarios.id',
  `revogado_em`   datetime     DEFAULT NULL COMMENT 'Soft revoke — NULL = acesso ativo',
  PRIMARY KEY (`id`),
  KEY `idx_nfaa_usuario` (`usuario_id`),
  KEY `idx_nfaa_ativo` (`usuario_id`, `revogado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Liberação do console de consulta Apollo, por usuário (sem bypass de admin)';

-- ── Cabeçalho de cada execução de lote ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `nf_apollo_consultas` (
  `id`                char(36)  NOT NULL,
  `template_id`       char(36)  DEFAULT NULL COMMENT 'FK suave → nf_templates.id — tipo de processo consultado',
  `template_nome`     varchar(150) DEFAULT NULL,
  `encerrar_ao_achar` tinyint(1) NOT NULL DEFAULT 0,
  `total`             int NOT NULL DEFAULT 0,
  `encontradas`       int NOT NULL DEFAULT 0,
  `canceladas`        int NOT NULL DEFAULT 0,
  `nao_encontradas`   int NOT NULL DEFAULT 0,
  `erros`             int NOT NULL DEFAULT 0,
  `status`            enum('rodando','concluida','falha') NOT NULL DEFAULT 'rodando',
  `detalhe`           varchar(300) DEFAULT NULL COMMENT 'Mensagem em caso de falha global (fase 1)',
  `executado_por`     char(36)  NOT NULL COMMENT 'FK suave → usuarios.id',
  `criado_em`         datetime  NOT NULL DEFAULT current_timestamp(),
  `finalizado_em`     datetime  DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_nfac_criado` (`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Execuções de lote da consulta Apollo';

-- ── Resultado por processo ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `nf_apollo_consulta_itens` (
  `id`          char(36) NOT NULL,
  `consulta_id` char(36) NOT NULL,
  `processo_id` char(36) NOT NULL,
  `chave_nf`    char(44) DEFAULT NULL,
  `resultado`   enum('encontrada','cancelada_apollo','nao_encontrada','erro') NOT NULL,
  `encerrado`   tinyint(1) NOT NULL DEFAULT 0,
  `detalhe`     varchar(300) DEFAULT NULL COMMENT 'Valor/data do Apollo, ou mensagem de erro',
  `criado_em`   datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nfaci_consulta` (`consulta_id`),
  KEY `idx_nfaci_processo` (`processo_id`),
  CONSTRAINT `fk_nfaci_consulta` FOREIGN KEY (`consulta_id`)
    REFERENCES `nf_apollo_consultas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Resultado por processo de cada execução de consulta Apollo';

-- ── Configuração de conexão (grupo `apollo`) — SEM senha (preenchida na tela) ─
INSERT IGNORE INTO `nf_configuracoes` (`chave`, `valor`, `grupo`) VALUES
  ('apollo.dsn',        'servidor.exemplo.local:1521/APOLLO', 'apollo'),
  ('apollo.user',       'APP_METAS',              'apollo'),
  ('apollo.pass',       '',                       'apollo'),
  ('apollo.python_bin', 'python3',                'apollo');
