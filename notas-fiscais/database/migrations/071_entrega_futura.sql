-- @migration 071_entrega_futura
-- Venda com entrega futura: a nota de VENDA é um processo normal (segue o fluxo
-- e o pagamento), marcado com a flag na etapa 2. Cada REMESSA de entrega é só um
-- registro de acompanhamento (não abre processo nem gera pagamento). Quando a
-- soma das remessas atinge o valor da venda, o status passa de pendente a
-- concluido.
USE `compras`;

CREATE TABLE IF NOT EXISTS `nf_entrega_futura` (
  `id`            char(36)  NOT NULL,
  `processo_id`   char(36)  NOT NULL COMMENT 'Processo da nota de venda (fornecedor, nº, data e valor vêm dele)',
  `status`        enum('pendente','concluido') NOT NULL DEFAULT 'pendente',
  `concluido_em`  datetime  DEFAULT NULL,
  `criado_em`     timestamp NOT NULL DEFAULT current_timestamp(),
  `criado_por`    char(36)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nef_processo` (`processo_id`),
  KEY `idx_nef_status` (`status`),
  CONSTRAINT `fk_nef_processo` FOREIGN KEY (`processo_id`)
    REFERENCES `nf_processos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Notas de venda com entrega futura em acompanhamento';

CREATE TABLE IF NOT EXISTS `nf_entrega_futura_remessas` (
  `id`              char(36)      NOT NULL,
  `entrega_id`      char(36)      NOT NULL,
  `numero_nf`       varchar(255)  NOT NULL,
  `chave_nf`        varchar(50)   DEFAULT NULL,
  `data_nf`         date          NOT NULL,
  `valor_nf`        decimal(15,2) NOT NULL,
  `fornecedor_id`   char(36)      DEFAULT NULL,
  `fornecedor_nome` varchar(200)  DEFAULT NULL,
  `fornecedor_doc`  char(14)      DEFAULT NULL,
  `criado_em`       timestamp     NOT NULL DEFAULT current_timestamp(),
  `criado_por`      char(36)      NOT NULL,
  `deletado_em`     datetime      DEFAULT NULL COMMENT 'Soft delete',
  PRIMARY KEY (`id`),
  KEY `idx_nefr_entrega` (`entrega_id`),
  CONSTRAINT `fk_nefr_entrega` FOREIGN KEY (`entrega_id`)
    REFERENCES `nf_entrega_futura` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Remessas (entregas) de uma venda para entrega futura';
