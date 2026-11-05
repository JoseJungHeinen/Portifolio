-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 052 — Distribuição direta de NF-e (SEFAZ/AN), sem Brobot
--
-- Mesmo desenho da migration 051 (ADN/NFSe), aplicado ao webservice nacional
-- NFeDistribuicaoDFe (SOAP). Reaproveita o certificado já guardado em
-- filiais.adn_cert_path/adn_cert_senha — é o mesmo e-CNPJ, dois consumidores
-- diferentes (ADN pra NFSe, SEFAZ/AN pra NF-e).
--
-- NSU aqui é uma sequência TOTALMENTE separada da do ADN (mesmo CNPJ, streams
-- independentes) — por isso cursor e log em tabelas próprias, não reaproveita
-- nf_adn_nsu_cursor/nf_adn_log.
--
-- Achado do teste em produção (16/09/2026): a SEFAZ rejeita com cStat 656
-- ("Consumo Indevido") se o ultNSU enviado não for o que ela espera, e devolve
-- o ultNSU correto até na própria rejeição. O poller usa isso pra autocorrigir
-- o cursor e nunca reiniciar do zero — reiniciar é o que causa o bloqueio de
-- 1h, então nunca deve acontecer por engano num próximo run.
--
-- Banco: compras
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/052_nfe_dist_integracao.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

ALTER TABLE `filiais`
  ADD COLUMN `nfe_dist_ativo` tinyint(1) NOT NULL DEFAULT 0
      COMMENT 'Capturar NF-e direto da distribuição SEFAZ/AN (sem Brobot) — usa o mesmo certificado do ADN';

CREATE TABLE IF NOT EXISTS `nf_dist_nsu_cursor` (
  `filial_id`     char(36)        NOT NULL COMMENT 'FK suave → filiais.id',
  `ultimo_nsu`    bigint unsigned NOT NULL DEFAULT 0
      COMMENT 'Último NSU confirmado pela SEFAZ para esta filial — nunca reiniciar do zero (cStat 656)',
  `atualizado_em` datetime        DEFAULT NULL,
  PRIMARY KEY (`filial_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cursor de NSU por filial — distribuição NFeDistribuicaoDFe (SEFAZ/AN)';

CREATE TABLE IF NOT EXISTS `nf_dist_log` (
  `id`              char(36)     NOT NULL,
  `filial_id`       char(36)     NOT NULL COMMENT 'FK suave → filiais.id',
  `status`          enum('ok','parcial','erro','bloqueado') NOT NULL DEFAULT 'ok'
      COMMENT 'bloqueado = cStat 656 (consumo indevido) — SEFAZ pede espera de 1h',
  `iniciado_em`     datetime     NOT NULL,
  `finalizado_em`   datetime     DEFAULT NULL,
  `docs_importadas` int unsigned NOT NULL DEFAULT 0,
  `docs_ignoradas`  int unsigned NOT NULL DEFAULT 0,
  `detalhe`         varchar(500) DEFAULT NULL,
  `nsu_ate`         bigint unsigned DEFAULT NULL,
  `criado_em`       timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dist_log_filial` (`filial_id`, `iniciado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Histórico das execuções do poller de distribuição de NF-e (SEFAZ/AN, por filial)';
