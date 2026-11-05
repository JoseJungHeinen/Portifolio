-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 051 — Integração ADN (captura direta de NFSe Nacional, sem Brobot)
--
-- Prova de conceito validada contra a homologação do ADN (mTLS com certificado
-- .pfx por filial). Diferente da Brobot, o NSU é por CNPJ/certificado — não dá
-- pra usar um cursor único de conta, cada filial tem sua própria sequência.
--
-- • filiais: flag de ativação + onde está o certificado desta filial no ADN.
-- • nf_adn_nsu_cursor: 1 linha por filial — até onde o polling já avançou.
-- • nf_adn_log: histórico de execuções, mesmo papel que nf_brobot_log tem hoje.
--
-- Banco: compras
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/051_adn_nfse_integracao.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

-- ── Certificado do ADN por filial ────────────────────────────────────────────
ALTER TABLE `filiais`
  ADD COLUMN `adn_nfse_ativo` tinyint(1)   NOT NULL DEFAULT 0
      COMMENT 'Capturar NFSe Nacional direto do ADN (sem Brobot) para esta filial',
  ADD COLUMN `adn_cert_path`  varchar(255) DEFAULT NULL
      COMMENT 'Caminho local do certificado .pfx usado na mTLS com o ADN',
  ADD COLUMN `adn_cert_senha` varchar(255) DEFAULT NULL
      COMMENT 'Senha do certificado .pfx — mesmo nível de proteção do brobot.token hoje';

-- ── Cursor de NSU — um por filial/certificado ────────────────────────────────
CREATE TABLE IF NOT EXISTS `nf_adn_nsu_cursor` (
  `filial_id`     char(36)        NOT NULL COMMENT 'FK suave → filiais.id',
  `ultimo_nsu`    bigint unsigned NOT NULL DEFAULT 0
      COMMENT 'Último NSU processado com sucesso — próxima chamada usa este valor',
  `atualizado_em` datetime        DEFAULT NULL,
  PRIMARY KEY (`filial_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cursor de NSU por filial — distribuição DFe do ADN (NFSe Nacional)';

-- ── Histórico de execuções do poller ADN ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `nf_adn_log` (
  `id`              char(36)     NOT NULL,
  `filial_id`       char(36)     NOT NULL COMMENT 'FK suave → filiais.id',
  `status`          enum('ok','parcial','erro') NOT NULL DEFAULT 'ok',
  `iniciado_em`     datetime     NOT NULL,
  `finalizado_em`   datetime     DEFAULT NULL,
  `docs_importadas` int unsigned NOT NULL DEFAULT 0,
  `docs_ignoradas`  int unsigned NOT NULL DEFAULT 0,
  `detalhe`         varchar(500) DEFAULT NULL COMMENT 'Resumo humano ou mensagem de erro',
  `nsu_ate`         bigint unsigned DEFAULT NULL COMMENT 'Até onde o cursor avançou neste run',
  `criado_em`       timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_adn_log_filial` (`filial_id`, `iniciado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Histórico das execuções do poller ADN (por filial)';
