-- @migration 063_faturamento_base
-- Módulo Faturamento (venda de veículos) — base de dados.
--
-- Conceitos (decididos com o usuário em 29/09/2026):
--   STATUS   = fase do fluxo (Aberto → Gerencial → Financeiro → Documental → …).
--              Só muda com o OK de quem tem a vez. Cadastro em nf_fat_fases.
--   SITUAÇÃO = quão completo está o pedido (dados + anexos). Calculada a partir
--              de nf_fat_requisitos, sem campo manual.
--   Preencher é livre na fase em que o grupo pode editar; AVANÇAR exige que os
--   requisitos da fase atual estejam cumpridos.
--   Quem edita vai encolhendo a cada fase: nf_fat_fase_grupos (pode_editar/pode_ok).
--   Requisito pode ser condicional (ex.: Empresa/Filial só obrigatória se o
--   Tipo de venda for Novos, Seminovos ou Repasse).
USE `compras`;

CREATE TABLE IF NOT EXISTS `nf_fat_fases` (
  `id`            char(36)     NOT NULL,
  `codigo`        varchar(40)  NOT NULL,
  `nome`          varchar(80)  NOT NULL,
  `ordem`         int(11)      NOT NULL,
  `ativo`         tinyint(1)   NOT NULL DEFAULT 1,
  `criado_em`     timestamp    NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_codigo` (`codigo`),
  KEY `idx_ordem` (`ordem`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fases (status) do pedido de faturamento, em ordem crescente';

CREATE TABLE IF NOT EXISTS `nf_fat_fase_grupos` (
  `fase_id`      char(36)   NOT NULL,
  `grupo_id`     char(36)   NOT NULL,
  `pode_editar`  tinyint(1) NOT NULL DEFAULT 0,
  `pode_ok`      tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`fase_id`,`grupo_id`),
  KEY `idx_grupo` (`grupo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Por fase: quais grupos editam e quais dão o OK que avança';

CREATE TABLE IF NOT EXISTS `nf_fat_requisitos` (
  `id`               char(36)     NOT NULL,
  `nome`             varchar(120) NOT NULL,
  `tipo`             enum('campo','anexo') NOT NULL DEFAULT 'campo',
  `chave`            varchar(60)  NOT NULL COMMENT 'campo: coluna do pedido ou chave em dados; anexo: tipo do anexo',
  `fase_id`          char(36)     NOT NULL COMMENT 'fase em que o requisito BLOQUEIA o avanço',
  `condicao_campo`   varchar(60)  DEFAULT NULL,
  `condicao_op`      enum('em','nao_em') DEFAULT NULL,
  `condicao_valores` varchar(255) DEFAULT NULL COMMENT 'lista separada por | (ex.: Novos|Seminovos|Repasse)',
  `ordem`            int(11)      NOT NULL DEFAULT 0,
  `ativo`            tinyint(1)   NOT NULL DEFAULT 1,
  `criado_em`        timestamp    NOT NULL DEFAULT current_timestamp(),
  `atualizado_em`    timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fase` (`fase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Exigências que compõem a Situação e travam o avanço de fase';

CREATE TABLE IF NOT EXISTS `nf_fat_pedidos` (
  `id`             char(36)      NOT NULL,
  `numero`         varchar(20)   NOT NULL COMMENT 'FAT-AAAA-NNNNNN',
  `ano`            smallint(6)   NOT NULL,
  `seq`            int(11)       NOT NULL,
  `fase_id`        char(36)      NOT NULL,
  `tipo_venda`     varchar(30)   DEFAULT NULL COMMENT 'Novos|Seminovos|Repasse|Venda Direta|Corsia',
  `filial_id`      char(36)      DEFAULT NULL,
  `cliente_nome`   varchar(150)  DEFAULT NULL,
  `cliente_doc`    varchar(20)   DEFAULT NULL,
  `veiculo_codigo` varchar(40)   DEFAULT NULL,
  `veiculo_desc`   varchar(200)  DEFAULT NULL,
  `vendedor_id`    char(36)      NOT NULL,
  `dados`          longtext      DEFAULT NULL COMMENT 'JSON com os demais campos do pedido',
  `apollo_ref`     varchar(60)   DEFAULT NULL COMMENT 'chave do registro no Apollo (integração futura)',
  `data_inicio`    date          NOT NULL,
  `criado_por`     char(36)      DEFAULT NULL,
  `criado_em`      timestamp     NOT NULL DEFAULT current_timestamp(),
  `atualizado_em`  timestamp     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_numero` (`numero`),
  UNIQUE KEY `uk_ano_seq` (`ano`,`seq`),
  KEY `idx_vendedor` (`vendedor_id`),
  KEY `idx_fase` (`fase_id`),
  KEY `idx_data` (`data_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pedido de faturamento (dados do Apollo copiados + dados do Notas)';

CREATE TABLE IF NOT EXISTS `nf_fat_anexos` (
  `id`            char(36)     NOT NULL,
  `pedido_id`     char(36)     NOT NULL,
  `tipo`          varchar(60)  NOT NULL,
  `nome_original` varchar(255) NOT NULL,
  `caminho`       varchar(500) NOT NULL,
  `enviado_por`   char(36)     DEFAULT NULL,
  `criado_em`     timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pedido_tipo` (`pedido_id`,`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Anexos do pedido (arquivo privado, fora do public/)';

CREATE TABLE IF NOT EXISTS `nf_fat_historico` (
  `id`          bigint(20)   NOT NULL AUTO_INCREMENT,
  `pedido_id`   char(36)     NOT NULL,
  `acao`        varchar(30)  NOT NULL COMMENT 'criado|ok|edicao|anexo',
  `fase_de`     char(36)     DEFAULT NULL,
  `fase_para`   char(36)     DEFAULT NULL,
  `usuario_id`  char(36)     DEFAULT NULL,
  `observacao`  varchar(500) DEFAULT NULL,
  `criado_em`   timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pedido` (`pedido_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Histórico de OKs e alterações do pedido';

-- ── Grupos de papel (os que faltavam; Diretoria e Financeiro já existem) ──
INSERT INTO `nf_grupos` (`id`,`nome`,`descricao`,`ativo`)
SELECT UUID(), t.nome, t.descricao, 1 FROM (
  SELECT 'Vendedores' AS nome, 'Faturamento — vendedores (veem só os próprios pedidos)' AS descricao
  UNION ALL SELECT 'Gerentes de Vendas', 'Faturamento — gerentes (veem os pedidos da equipe: grupos de vendedores onde são gestor)'
  UNION ALL SELECT 'Secretárias', 'Faturamento — secretárias de vendas (veem todos os vendedores)'
  UNION ALL SELECT 'Central de Conferência', 'Faturamento — conferência (veem todos)'
  UNION ALL SELECT 'Documental', 'Faturamento — documental (veem todos)'
) t
WHERE NOT EXISTS (SELECT 1 FROM `nf_grupos` g WHERE g.nome = t.nome);

-- ── Fases iniciais ──
INSERT IGNORE INTO `nf_fat_fases` (`id`,`codigo`,`nome`,`ordem`) VALUES
  (UUID(),'aberto','Aberto',10),
  (UUID(),'gerencial','Gerencial',20),
  (UUID(),'financeiro','Financeiro',30),
  (UUID(),'documental','Documental',40);

-- ── Quem edita / dá OK em cada fase (padrão inicial; editável na tela de controle) ──
-- Aberto: todos agem. Depois o grupo vai encolhendo.
INSERT IGNORE INTO `nf_fat_fase_grupos` (`fase_id`,`grupo_id`,`pode_editar`,`pode_ok`)
SELECT f.id, g.id,
  CASE
    WHEN f.codigo='aberto'     THEN 1
    WHEN f.codigo='gerencial'  THEN (g.nome <> 'Vendedores')
    WHEN f.codigo='financeiro' THEN g.nome IN ('Financeiro','Documental')
    WHEN f.codigo='documental' THEN g.nome = 'Documental'
  END,
  CASE
    WHEN f.codigo='aberto'     THEN g.nome = 'Vendedores'
    WHEN f.codigo='gerencial'  THEN g.nome IN ('Gerentes de Vendas','Secretárias')
    WHEN f.codigo='financeiro' THEN g.nome = 'Financeiro'
    WHEN f.codigo='documental' THEN g.nome = 'Documental'
  END
FROM `nf_fat_fases` f
JOIN `nf_grupos` g ON g.nome IN
  ('Vendedores','Gerentes de Vendas','Secretárias','Central de Conferência','Documental','Diretoria','Financeiro');

-- Diretoria: age só até a fase Gerencial (trava depois, fica só visualizando)
UPDATE `nf_fat_fase_grupos` fg
JOIN `nf_fat_fases` f ON f.id = fg.fase_id
JOIN `nf_grupos` g ON g.id = fg.grupo_id
SET fg.pode_editar = IF(f.codigo IN ('aberto','gerencial'),1,0)
WHERE g.nome = 'Diretoria';

-- ── Requisitos iniciais ──
INSERT INTO `nf_fat_requisitos`
  (`id`,`nome`,`tipo`,`chave`,`fase_id`,`condicao_campo`,`condicao_op`,`condicao_valores`,`ordem`)
SELECT UUID(), r.nome, r.tipo, r.chave, f.id, r.cc, r.co, r.cv, r.ordem FROM (
  SELECT 'Empresa / Filial' AS nome,'campo' AS tipo,'filial_id' AS chave,'aberto' AS fase,
         'tipo_venda' AS cc,'em' AS co,'Novos|Seminovos|Repasse' AS cv,10 AS ordem
  UNION ALL SELECT 'Tipo de venda','campo','tipo_venda','aberto',NULL,NULL,NULL,20
  UNION ALL SELECT 'Cliente','campo','cliente_nome','aberto',NULL,NULL,NULL,30
  UNION ALL SELECT 'Veículo (unidade específica)','campo','veiculo_codigo','gerencial',NULL,NULL,NULL,40
  UNION ALL SELECT 'Comprovante de pagamento','anexo','comprovante_pagamento','financeiro',NULL,NULL,NULL,50
  UNION ALL SELECT 'CNH do cliente','anexo','cnh_cliente','documental',NULL,NULL,NULL,60
  UNION ALL SELECT 'Comprovante de endereço','anexo','comprovante_endereco','documental',NULL,NULL,NULL,70
  UNION ALL SELECT 'Pedido assinado','anexo','pedido_assinado','documental',NULL,NULL,NULL,80
) r
JOIN `nf_fat_fases` f ON f.codigo = r.fase
WHERE NOT EXISTS (SELECT 1 FROM `nf_fat_requisitos` x WHERE x.chave = r.chave);
