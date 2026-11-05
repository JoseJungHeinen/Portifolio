-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 008 — Captura de notas (integração Brobot) + regras de pré-fluxo
--
-- nf_notas_capturadas: staging das NF-e capturadas via API (Brobot/SEFAZ).
--   Nota capturada NÃO é processo — vira processo por regra ou decisão manual.
-- nf_captura_regras: configurador de vínculos (CNPJ x CNPJ → template),
--   com payload de campos pré-configurados e modo sugerir/automático.
--
-- Banco: compras
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/008_create_nf_capturas.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

CREATE TABLE IF NOT EXISTS `nf_notas_capturadas` (
  `id`                char(36)      NOT NULL,
  `chave_nf`          char(44)      NOT NULL COMMENT 'Chave de acesso NF-e — dedup',
  `numero_nf`         varchar(50)   NOT NULL,
  `fornecedor_nome`   varchar(200)  NOT NULL,
  `fornecedor_cnpj`   char(14)      NOT NULL,
  `destinatario_cnpj` char(14)      NOT NULL COMMENT 'CNPJ da filial destinatária',
  `filial_id`         char(36)      DEFAULT NULL COMMENT 'FK suave → filiais.id (resolvida pelo CNPJ)',
  `filial_nome`       varchar(100)  DEFAULT NULL COMMENT 'Estabelecimento (denormalizado da captura)',
  `valor`             decimal(15,2) NOT NULL,
  `data_emissao`      date          NOT NULL,
  `cfop`              varchar(10)   DEFAULT NULL COMMENT 'Preenchido quando a Brobot entregar',
  `categoria_origem`  varchar(100)  DEFAULT NULL COMMENT 'Classificação na origem (ex.: PEÇAS - MONTADORA)',
  `origem`            varchar(50)   NOT NULL DEFAULT 'brobot',
  `xml_disponivel`    tinyint(1)    NOT NULL DEFAULT 1,
  `pdf_disponivel`    tinyint(1)    NOT NULL DEFAULT 1,
  `status`            enum('capturada','sugerida','vinculada','ignorada') NOT NULL DEFAULT 'capturada',
  `regra_id`          char(36)      DEFAULT NULL COMMENT 'Regra que sugeriu/vinculou',
  `processo_id`       char(36)      DEFAULT NULL COMMENT 'FK suave → nf_processos.id',
  `vinculada_por`     char(36)      DEFAULT NULL COMMENT 'FK suave → usuarios.id (NULL = automação)',
  `vinculada_em`      datetime      DEFAULT NULL,
  `motivo_ignorada`   varchar(255)  DEFAULT NULL,
  `capturada_em`      datetime      NOT NULL,
  `criado_em`         timestamp     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nfcap_chave` (`chave_nf`),
  KEY `idx_nfcap_status` (`status`),
  KEY `idx_nfcap_cnpjs` (`fornecedor_cnpj`, `destinatario_cnpj`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Staging de NF-e capturadas via integração (Brobot) — inbox de vinculação';

CREATE TABLE IF NOT EXISTS `nf_captura_regras` (
  `id`                 char(36)     NOT NULL,
  `nome`               varchar(150) NOT NULL,
  `fornecedor_cnpj`    char(14)     NOT NULL COMMENT 'CNPJ emitente (origem)',
  `destinatario_cnpj`  char(14)     NOT NULL COMMENT 'CNPJ filial destino',
  `cfop`               varchar(10)  DEFAULT NULL COMMENT 'Critério opcional — só compara se preenchido',
  `template_id`        char(36)     NOT NULL COMMENT 'FK suave → nf_templates.id',
  `campos_json`        longtext     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ('{}')
                       COMMENT 'JSON: payload de campos pré-configurados { campo: valor }',
  `modo`               enum('sugerir','automatico') NOT NULL DEFAULT 'sugerir',
  `ativo`              tinyint(1)   NOT NULL DEFAULT 1,
  `precisa_revisao`    tinyint(1)   NOT NULL DEFAULT 0
                       COMMENT '1 = template publicou nova versão; regra pausada até revisão',
  `total_disparos`     int unsigned NOT NULL DEFAULT 0,
  `ultima_execucao`    datetime     DEFAULT NULL,
  `criado_por`         char(36)     NOT NULL,
  `criado_em`          timestamp    NOT NULL DEFAULT current_timestamp(),
  `atualizado_em`      timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nfcr_match` (`ativo`, `fornecedor_cnpj`, `destinatario_cnpj`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Regras de pré-fluxo: CNPJ x CNPJ (+CFOP) → template com campos pré-configurados';
