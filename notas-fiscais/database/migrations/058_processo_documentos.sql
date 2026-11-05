-- @migration 058_processo_documentos
-- Documentos fiscais adicionais vinculados a um processo já existente:
--   papel='anexo_fiscal' -> 2º documento do MESMO processo, ex.: nota de
--     serviço (NFS-e) anexa à nota de produto (NF-e) principal, quando as
--     duas formam uma única obrigação/boleto.
--   papel='remessa'      -> entrega parcial de uma venda para entrega
--     futura (nota de venda = principal em nf_processos; cada remessa que
--     chega entra aqui, sem somar ao valor do processo).
-- O processo continua tendo sua nota PRINCIPAL nas colunas de sempre
-- (nf_processos.numero_nf/valor_nf/data_nf/chave_nf) — nada muda pra quem já
-- lê essas colunas. Esta tabela só guarda as notas ADICIONAIS.
USE `compras`;

CREATE TABLE IF NOT EXISTS `nf_processo_documentos` (
  `id`                char(36)      NOT NULL,
  `processo_id`        char(36)      NOT NULL,
  `papel`              enum('anexo_fiscal','remessa') NOT NULL,
  `tipo_documento`     varchar(20)   NOT NULL COMMENT 'NFe/NFSe/CTe/CTOs, igual nf_notas_capturadas.tipo_documento',
  `numero_nf`          varchar(255)  NOT NULL,
  `chave_nf`           varchar(50)   DEFAULT NULL,
  `valor_nf`           decimal(15,2) NOT NULL,
  `data_nf`            date          NOT NULL,
  `fornecedor_nome`    varchar(200)  DEFAULT NULL,
  `fornecedor_doc`     char(14)      DEFAULT NULL,
  `nota_capturada_id`  char(36)      DEFAULT NULL COMMENT 'FK suave -> nf_notas_capturadas.id (origem, se veio de captura)',
  `criado_em`          timestamp     NOT NULL DEFAULT current_timestamp(),
  `criado_por`         char(36)      NOT NULL,
  `deletado_em`        datetime      DEFAULT NULL COMMENT 'Soft delete',
  PRIMARY KEY (`id`),
  KEY `fk_npd_processo` (`processo_id`),
  KEY `idx_npd_chave` (`chave_nf`),
  KEY `idx_npd_dedup` (`fornecedor_doc`, `numero_nf`, `data_nf`),
  CONSTRAINT `fk_npd_processo` FOREIGN KEY (`processo_id`)
    REFERENCES `nf_processos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Documentos fiscais adicionais de um processo: anexo (produto+serviço no mesmo processo) ou remessa (entrega futura parcial)';
