-- @migration 060_certificados_matriz
-- Um único certificado "da matriz" (dist.cert_path/dist.cert_senha em
-- nf_configuracoes) não cobre grupo com mais de uma raiz de CNPJ. Descoberto
-- em 18/09/2026: o Empresa Exemplo tem 5 raízes distintas entre as filiais
-- ativas (09327318, 14499746, 07961646, 12941032, 87048310), cada uma
-- juridicamente separada — certificado emitido pra uma raiz não autentica
-- consulta de outra.
--
-- Esta tabela guarda um certificado por raiz de CNPJ, cadastrado manualmente
-- (não auto-detectado) via "+ Adicionar" na tela Geral > NF-e/NFS-e. Ordem
-- de resolução do certificado usado numa chamada (ver NfeDistSync/AdnSync):
--   1. filiais.dist_cert_path — override por filial individual (já existia)
--   2. nf_certificados_matriz — por raiz de CNPJ (esta tabela, nova)
--   3. dist.cert_path (nf_configuracoes) — certificado único, agora só
--      reserva pra raiz sem certificado próprio cadastrado
USE `compras`;

CREATE TABLE IF NOT EXISTS `nf_certificados_matriz` (
  `id`             char(36)      NOT NULL,
  `cnpj_raiz`      char(8)       NOT NULL COMMENT '8 primeiros dígitos do CNPJ (raiz) — identifica a matriz',
  `nome`           varchar(150)  DEFAULT NULL COMMENT 'Rótulo opcional pra identificar a matriz na lista',
  `cert_path`      varchar(500)  DEFAULT NULL,
  `cert_senha`     varchar(255)  DEFAULT NULL,
  `criado_em`      timestamp     NOT NULL DEFAULT current_timestamp(),
  `atualizado_em`  timestamp     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `atualizado_por` char(36)      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cnpj_raiz` (`cnpj_raiz`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Certificado digital por raiz de CNPJ (matriz) — captura direta NF-e/NFS-e';
