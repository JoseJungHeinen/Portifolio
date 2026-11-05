-- Migration 003: Tabelas de Templates de Fluxo NF
-- Executar apenas se as tabelas ainda não existirem

CREATE TABLE IF NOT EXISTS `nf_templates` (
  `id`                      char(36) NOT NULL,
  `nome`                    varchar(200) NOT NULL,
  `versao_publicada_id`     char(36) DEFAULT NULL,
  `versao_rascunho_id`      char(36) DEFAULT NULL,
  `criado_em`               timestamp NOT NULL DEFAULT current_timestamp(),
  `criado_por`              char(36) NOT NULL COMMENT 'FK suave → usuarios.id',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nf_template_versoes` (
  `id`                char(36) NOT NULL,
  `template_id`       char(36) NOT NULL,
  `numero_versao`     tinyint(3) unsigned NOT NULL DEFAULT 1,
  `status`            enum('rascunho','teste_interno','uat','publicado','arquivado') NOT NULL DEFAULT 'rascunho',
  `filial_id`         char(36) DEFAULT NULL COMMENT 'FK suave → filiais.id (NULL = todas)',
  `setor_id`          char(36) DEFAULT NULL COMMENT 'FK suave → setores.id (NULL = todos)',
  `tipo_nota_id`      char(36) DEFAULT NULL COMMENT 'FK suave → nf_lista_itens (Tipos de nota)',
  `valor_min`         decimal(15,2) DEFAULT NULL,
  `valor_max`         decimal(15,2) DEFAULT NULL,
  `tem_financeiro`    tinyint(1) NOT NULL DEFAULT 0,
  `campos`            longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ('[]'),
  `etapas`            longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ('[]'),
  `tipos_anexo`       longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ('[]'),
  `uat_usuario_ids`   longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `criado_em`         timestamp NOT NULL DEFAULT current_timestamp(),
  `criado_por`        char(36) NOT NULL COMMENT 'FK suave → usuarios.id',
  `publicado_em`      datetime DEFAULT NULL,
  `publicado_por`     char(36) DEFAULT NULL COMMENT 'FK suave → usuarios.id',
  PRIMARY KEY (`id`),
  KEY `fk_nftv_template` (`template_id`),
  CONSTRAINT `fk_nftv_template` FOREIGN KEY (`template_id`) REFERENCES `nf_templates` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
