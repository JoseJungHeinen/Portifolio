-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 010 — Rateio entre centros de custo
--
-- 1. setores.codigo — código do centro de custo (ex.: Contabilidade = 890)
-- 2. nf_template_versoes.tem_rateio — template permite rateio no processo
-- 3. nf_processo_rateios — divisão do valor da NF entre centros de custo,
--    gravada no processo (usada no lançamento da nota).
--    Setor denormalizado (código/nome) — imutável no histórico.
--
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/010_rateio.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

ALTER TABLE `setores`
  ADD COLUMN `codigo` varchar(20) DEFAULT NULL
    COMMENT 'Código do centro de custo (ex.: 890)';

ALTER TABLE `nf_template_versoes`
  ADD COLUMN `tem_rateio` tinyint(1) NOT NULL DEFAULT 0
    COMMENT 'Se 1, o solicitante informa rateio entre centros de custo ao abrir o processo';

CREATE TABLE IF NOT EXISTS `nf_processo_rateios` (
  `id`            char(36)      NOT NULL,
  `processo_id`   char(36)      NOT NULL,
  `setor_id`      char(36)      NOT NULL COMMENT 'FK suave → setores.id',
  `setor_codigo`  varchar(20)   DEFAULT NULL COMMENT 'Código do centro de custo — denormalizado',
  `setor_nome`    varchar(100)  NOT NULL COMMENT 'Denormalizado — imutável no histórico',
  `percentual`    decimal(7,3)  NOT NULL COMMENT 'Percentual do rateio (soma = 100)',
  `valor`         decimal(15,2) NOT NULL COMMENT 'Valor absoluto (soma = valor da NF)',
  `criado_em`     timestamp     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nfr_processo` (`processo_id`),
  CONSTRAINT `fk_nfr_processo` FOREIGN KEY (`processo_id`)
    REFERENCES `nf_processos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Rateio do valor da NF entre centros de custo (setores)';
