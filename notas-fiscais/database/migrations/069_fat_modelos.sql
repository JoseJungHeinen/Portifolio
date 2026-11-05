-- @migration 069_fat_modelos
-- Cadastro de Modelos de veículo do Faturamento (campo C3 do pedido).
-- Pequeno de propósito: só o que o pedido usa (MVS, ano-modelo, descrição, combustível e
-- preço público sugerido; o MVS é a chave única — no ano-modelo novo, atualiza-se o mesmo modelo). Carga inicial: cli/seed_faturamento_modelos.php (tabela RAM).
USE `compras`;

CREATE TABLE IF NOT EXISTS `nf_fat_modelos` (
  `id`             char(36)      NOT NULL,
  `marca`          varchar(40)   NOT NULL DEFAULT 'RAM',
  `mvs`            varchar(20)   NOT NULL COMMENT 'código Modelo.Versão.Série da montadora — chave única do modelo',
  `ano_modelo`     smallint(6)   NOT NULL COMMENT 'MY',
  `descricao`      varchar(200)  NOT NULL,
  `combustivel`    varchar(30)   DEFAULT NULL,
  `preco_sugerido` decimal(12,2) DEFAULT NULL COMMENT 'preço público sugerido, frete incluso',
  `preco_vigencia` date          DEFAULT NULL COMMENT 'início da vigência do preço',
  `cores`          text          DEFAULT NULL COMMENT 'cores do modelo, uma por linha: "CÓDIGO - Nome" (campo C4 do pedido)',
  `ativo`          tinyint(1)    NOT NULL DEFAULT 1,
  `criado_em`      timestamp     NOT NULL DEFAULT current_timestamp(),
  `atualizado_em`  timestamp     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mvs` (`mvs`),
  KEY `idx_marca` (`marca`,`descricao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Modelos de veículo vendidos no Faturamento';
