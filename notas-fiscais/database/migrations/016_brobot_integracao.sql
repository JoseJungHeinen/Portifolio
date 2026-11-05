-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 016 — Integração Brobot (captura automática de DF-e)
--
-- • filiais: flags de integração por tipo de documento (NFe/CTe/NFSe/CTOs).
--   O poller só importa uma nota se o destinatário for uma filial ativa E a
--   flag daquele tipo estiver habilitada.
-- • nf_notas_capturadas: tipo do documento + id na Brobot (rastreio).
-- • nf_brobot_log: histórico de execuções (sync a cada 5 min / classificação
--   a cada 30 min) — alimenta os carimbos "última busca / última classificação".
-- • nf_configuracoes: chaves do grupo 'brobot' (a aba Geral só grava chaves
--   que já existem — por isso são semeadas aqui com os defaults).
--
-- Banco: compras
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/016_brobot_integracao.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

-- ── Flags de integração por filial e por tipo de documento ──────────────────
ALTER TABLE `filiais`
  ADD COLUMN `brobot_nfe`  tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Capturar NF-e desta filial via Brobot',
  ADD COLUMN `brobot_cte`  tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Capturar CT-e desta filial via Brobot',
  ADD COLUMN `brobot_nfse` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Capturar NFS-e desta filial via Brobot',
  ADD COLUMN `brobot_ctos` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Capturar CT-e OS desta filial via Brobot';

-- ── Tipo do documento + id de origem na staging ─────────────────────────────
ALTER TABLE `nf_notas_capturadas`
  ADD COLUMN `tipo_documento` enum('NFe','CTe','NFSe','CTOs') NOT NULL DEFAULT 'NFe'
      COMMENT 'Tipo do DF-e capturado' AFTER `chave_nf`,
  ADD COLUMN `brobot_id` varchar(50) DEFAULT NULL
      COMMENT 'ID do documento na plataforma Brobot (rastreio/deduplicação secundária)' AFTER `origem`;

-- ── Histórico de execuções da integração ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `nf_brobot_log` (
  `id`              char(36)     NOT NULL,
  `tipo`            enum('sync','classificacao') NOT NULL,
  `status`          enum('ok','parcial','erro')  NOT NULL DEFAULT 'ok',
  `iniciado_em`     datetime     NOT NULL,
  `finalizado_em`   datetime     DEFAULT NULL,
  `docs_importadas` int unsigned NOT NULL DEFAULT 0 COMMENT 'sync: notas gravadas · classificacao: vínculos criados',
  `docs_ignoradas`  int unsigned NOT NULL DEFAULT 0 COMMENT 'sync: fora de filtro/duplicadas · classificacao: sugeridas+ambíguas',
  `detalhe`         varchar(500) DEFAULT NULL COMMENT 'Resumo humano ou mensagem de erro',
  `cursor_ate`      datetime     DEFAULT NULL COMMENT 'sync: até onde a janela avançou (issued_at)',
  `criado_em`       timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_brobot_log_tipo` (`tipo`, `iniciado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Histórico das execuções da integração Brobot (poller e classificação)';

-- ── Configurações do grupo 'brobot' (semeadas com defaults) ─────────────────
INSERT IGNORE INTO `nf_configuracoes` (`chave`, `valor`, `grupo`) VALUES
  ('brobot.ativo',             '0',                                              'brobot'),
  ('brobot.base_url',          'https://api.platform.brobot.com.br/api/v2',      'brobot'),
  ('brobot.token',             '',                                               'brobot'),
  ('brobot.metodo_xml',        'xml_brobot',                                     'brobot'),
  ('brobot.backfill_dias',     '7',                                              'brobot'),
  ('brobot.timeout_seg',       '30',                                             'brobot'),
  ('brobot.cursor_issued_at',  '',                                               'brobot');
