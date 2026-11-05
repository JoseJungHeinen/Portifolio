-- @migration 064_faturamento_dados_por_fat
-- Cliente, veículo e pagamentos passam a viver em tabelas próprias, TODAS com a
-- chave do FAT (pedido_id + fat_numero). Cada FAT guarda o seu retrato dos dados:
-- o mesmo Pedro pode ter um endereço no ABC1D23-000002 e outro, atualizado, no
-- ABC1D23-000500 — a consulta de cada FAT mostra o dado daquele faturamento.
-- Sem chave única em CPF/CNPJ ou código de veículo: o mesmo cliente compra vários
-- carros e o mesmo carro pode ser vendido várias vezes.
USE `compras`;

CREATE TABLE IF NOT EXISTS `nf_fat_clientes` (
  `id`            char(36)     NOT NULL,
  `pedido_id`     char(36)     NOT NULL,
  `fat_numero`    varchar(20)  NOT NULL,
  `nome`          varchar(150) DEFAULT NULL,
  `doc`           varchar(20)  DEFAULT NULL COMMENT 'CPF ou CNPJ',
  `endereco`      varchar(200) DEFAULT NULL,
  `numero`        varchar(20)  DEFAULT NULL,
  `cidade`        varchar(100) DEFAULT NULL,
  `uf`            char(2)      DEFAULT NULL,
  `telefone`      varchar(30)  DEFAULT NULL,
  `email`         varchar(150) DEFAULT NULL,
  `origem_ref`    varchar(60)  DEFAULT NULL COMMENT 'código do cliente no Linx/Apollo, quando vier de lá',
  `atualizado_em` timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pedido` (`pedido_id`),
  KEY `idx_fat` (`fat_numero`),
  KEY `idx_doc` (`doc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cliente do FAT (retrato por faturamento — pode divergir entre FATs)';

CREATE TABLE IF NOT EXISTS `nf_fat_veiculos` (
  `id`            char(36)     NOT NULL,
  `pedido_id`     char(36)     NOT NULL,
  `fat_numero`    varchar(20)  NOT NULL,
  `codigo`        varchar(40)  DEFAULT NULL COMMENT 'unidade específica (estoque/chassi interno)',
  `descricao`     varchar(200) DEFAULT NULL,
  `modelo`        varchar(100) DEFAULT NULL,
  `cor`           varchar(40)  DEFAULT NULL,
  `ano_fab_mod`   varchar(9)   DEFAULT NULL,
  `combustivel`   varchar(30)  DEFAULT NULL,
  `chassi`        varchar(30)  DEFAULT NULL,
  `placa`         varchar(10)  DEFAULT NULL,
  `atualizado_em` timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pedido` (`pedido_id`),
  KEY `idx_fat` (`fat_numero`),
  KEY `idx_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Veículo do FAT (retrato por faturamento — o mesmo carro pode ter vários FATs)';

CREATE TABLE IF NOT EXISTS `nf_fat_pagamentos` (
  `id`            char(36)      NOT NULL,
  `pedido_id`     char(36)      NOT NULL,
  `fat_numero`    varchar(20)   NOT NULL,
  `ordem`         int(11)       NOT NULL DEFAULT 1,
  `tipo`          varchar(40)   DEFAULT NULL,
  `valor`         decimal(14,2) DEFAULT NULL,
  `data`          date          DEFAULT NULL,
  `dados`         longtext      DEFAULT NULL COMMENT 'JSON: pagador, veículo de troca etc.',
  `atualizado_em` timestamp     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pedido` (`pedido_id`),
  KEY `idx_fat` (`fat_numero`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Linhas de forma de pagamento do FAT';

-- Chave legível do FAT também em anexos e histórico
ALTER TABLE `nf_fat_anexos`    ADD COLUMN IF NOT EXISTS `fat_numero` varchar(20) NOT NULL DEFAULT '' AFTER `pedido_id`, ADD KEY IF NOT EXISTS `idx_fat` (`fat_numero`);
ALTER TABLE `nf_fat_historico` ADD COLUMN IF NOT EXISTS `fat_numero` varchar(20) NOT NULL DEFAULT '' AFTER `pedido_id`, ADD KEY IF NOT EXISTS `idx_fat` (`fat_numero`);

-- Move o que já estava em nf_fat_pedidos e remove as colunas antigas.
-- ATENÇÃO: este trecho roda UMA vez (as colunas deixam de existir depois).
INSERT INTO `nf_fat_clientes` (`id`,`pedido_id`,`fat_numero`,`nome`,`doc`)
SELECT UUID(), p.id, p.numero, p.cliente_nome, p.cliente_doc FROM `nf_fat_pedidos` p;

INSERT INTO `nf_fat_veiculos` (`id`,`pedido_id`,`fat_numero`,`codigo`,`descricao`)
SELECT UUID(), p.id, p.numero, p.veiculo_codigo, p.veiculo_desc FROM `nf_fat_pedidos` p;

UPDATE `nf_fat_historico` h JOIN `nf_fat_pedidos` p ON p.id = h.pedido_id SET h.fat_numero = p.numero;
UPDATE `nf_fat_anexos`    a JOIN `nf_fat_pedidos` p ON p.id = a.pedido_id SET a.fat_numero = p.numero;

ALTER TABLE `nf_fat_pedidos`
  DROP COLUMN `cliente_nome`, DROP COLUMN `cliente_doc`,
  DROP COLUMN `veiculo_codigo`, DROP COLUMN `veiculo_desc`;
