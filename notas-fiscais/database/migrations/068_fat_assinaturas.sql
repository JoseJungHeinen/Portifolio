-- @migration 068_fat_assinaturas
-- Assinaturas (grupo F) do pedido de Faturamento: quem precisa assinar e em que pé está.
-- A lista é DERIVADA dos dados do pedido a cada "Salvar" (vendedor, gerente, cliente,
-- terceiro pagador e dono do veículo de troca). O status só muda pela integração com a
-- Assinafy (ainda não habilitada) — por isso nasce sempre 'pendente'.
USE `compras`;

CREATE TABLE IF NOT EXISTS `nf_fat_assinaturas` (
  `id`              char(36)     NOT NULL,
  `pedido_id`       char(36)     NOT NULL,
  `fat_numero`      varchar(20)  NOT NULL,
  `papel`           enum('vendedor','gerente','cliente','terceiro_pagamento','terceiro_veiculo') NOT NULL,
  `origem_id`       char(36)     NOT NULL DEFAULT '' COMMENT 'id da forma de pagamento que originou o signatário (terceiros)',
  `nome`            varchar(150) DEFAULT NULL,
  `doc`             varchar(20)  DEFAULT NULL,
  `email`           varchar(150) DEFAULT NULL,
  `telefone`        varchar(30)  DEFAULT NULL,
  `status`          enum('pendente','enviado','assinado','recusado') NOT NULL DEFAULT 'pendente',
  `assinado_em`     datetime     DEFAULT NULL,
  `assinafy_doc_id` varchar(60)  DEFAULT NULL,
  `atualizado_em`   timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pedido_papel` (`pedido_id`,`papel`,`origem_id`),
  KEY `idx_fat` (`fat_numero`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Signatários do pedido de Faturamento (derivados dos dados; status vem da Assinafy)';
