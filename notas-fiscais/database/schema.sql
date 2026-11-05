-- ═══════════════════════════════════════════════════════════════════════════
-- MÓDULO WORKFLOW NF — EXEMPLO
-- Schema das tabelas nf_* no banco `compras` (sistema maior)
--
-- IMPORTANTE: este arquivo vai no banco `compras`, não em banco separado.
--
-- Convenções:
--   • PKs: char(36) UUID — gerado no PHP (sem AUTO_INCREMENT)
--   • Timestamps: criado_em / atualizado_em (não created_at/updated_at)
--   • Naming: snake_case português, igual ao padrão do sistema maior
--   • Charset: utf8mb4, collation: utf8mb4_unicode_ci
--   • FKs rígidas: entre tabelas nf_* (CONSTRAINT FOREIGN KEY)
--   • FKs suaves: para tabelas do sistema maior (usuarios, filiais, setores, fornecedores)
--                 — integridade garantida na camada de aplicação
--
-- Execução: mysql -u root compras < database/schema.sql
--           (ou via phpMyAdmin / HeidiSQL no Laragon)
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

-- ─────────────────────────────────────────
-- 1. LISTAS CONFIGURÁVEIS (Módulo Tabelas)
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `nf_listas` (
  `id`            char(36)     NOT NULL,
  `nome`          varchar(100) NOT NULL,
  `slug`          varchar(100) NOT NULL,
  `tipo`          enum('pre_configurada','personalizada') NOT NULL DEFAULT 'personalizada',
  `descricao`     text         DEFAULT NULL,
  `ativo`         tinyint(1)   NOT NULL DEFAULT 1,
  `criado_em`     timestamp    NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nf_lista_slug` (`slug`),
  KEY `idx_nf_listas_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Listas de referência do módulo NF (pré-configuradas e personalizadas)';

-- ─────────────────────────────────────────
-- 2. COLUNAS DAS LISTAS
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `nf_lista_colunas` (
  `id`        char(36)    NOT NULL,
  `lista_id`  char(36)    NOT NULL,
  `nome`      varchar(100) NOT NULL,
  `tipo`      enum('texto','data','valor','inteiro','percentual','cpf_cnpj','telefone','lista_fk') NOT NULL,
  `e_label`   tinyint(1)  NOT NULL DEFAULT 0
              COMMENT 'Define qual coluna é exibida como label no dropdown',
  `posicao`   tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_nf_col_lista` (`lista_id`),
  CONSTRAINT `fk_nf_col_lista` FOREIGN KEY (`lista_id`)
    REFERENCES `nf_listas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Definição das colunas de cada lista NF';

-- ─────────────────────────────────────────
-- 3. ITENS DAS LISTAS
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `nf_lista_itens` (
  `id`        char(36)   NOT NULL,
  `lista_id`  char(36)   NOT NULL,
  `dados`     longtext   CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
              COMMENT 'JSON: { "col_id": "valor", ... }',
  `ativo`     tinyint(1) NOT NULL DEFAULT 1,
  `criado_em` timestamp  NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_nf_item_lista` (`lista_id`),
  KEY `idx_nf_lista_itens_ativo` (`ativo`),
  CONSTRAINT `fk_nf_item_lista` FOREIGN KEY (`lista_id`)
    REFERENCES `nf_listas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Itens de cada lista NF (dados como JSON por item)';

-- ─────────────────────────────────────────
-- 4. GRUPOS DE APROVAÇÃO
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `nf_grupos` (
  `id`            char(36)     NOT NULL,
  `nome`          varchar(100) NOT NULL,
  `setor_id`      char(36)     DEFAULT NULL
                  COMMENT 'Classificação — FK suave para setores.id',
  `descricao`     text         DEFAULT NULL,
  `ativo`         tinyint(1)   NOT NULL DEFAULT 1,
  `criado_em`     timestamp    NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nf_grupos_ativo` (`ativo`)
  -- Nota: setor_id é FK suave — referência informativa, sem CONSTRAINT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grupos de aprovadores do módulo NF';

-- ─────────────────────────────────────────
-- 5. MEMBROS DOS GRUPOS
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `nf_grupo_membros` (
  `grupo_id`      char(36)  NOT NULL,
  `usuario_id`    char(36)  NOT NULL
                  COMMENT 'FK suave → usuarios.id (mesmo banco)',
  `adicionado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`grupo_id`, `usuario_id`),
  KEY `fk_nfgm_grupo` (`grupo_id`),
  KEY `idx_nfgm_usuario` (`usuario_id`),
  CONSTRAINT `fk_nfgm_grupo` FOREIGN KEY (`grupo_id`)
    REFERENCES `nf_grupos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Membros de cada grupo de aprovação';

-- ─────────────────────────────────────────
-- 6. TEMPLATES DE FLUXO
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `nf_templates` (
  `id`                  char(36)     NOT NULL,
  `nome`                varchar(200) NOT NULL,
  `versao_publicada_id` char(36)     DEFAULT NULL
                        COMMENT 'FK suave → nf_template_versoes.id (versão ativa)',
  `versao_rascunho_id`  char(36)     DEFAULT NULL
                        COMMENT 'FK suave → nf_template_versoes.id (rascunho em edição)',
  `criado_em`           timestamp    NOT NULL DEFAULT current_timestamp(),
  `criado_por`          char(36)     NOT NULL
                        COMMENT 'FK suave → usuarios.id',
  PRIMARY KEY (`id`),
  KEY `idx_nf_templates_nome` (`nome`(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Templates de fluxo de NF (metadados do template)';

-- ─────────────────────────────────────────
-- 7. VERSÕES DOS TEMPLATES
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `nf_template_versoes` (
  `id`              char(36)     NOT NULL,
  `template_id`     char(36)     NOT NULL,
  `numero_versao`   tinyint(3) unsigned NOT NULL DEFAULT 1,
  `status`          enum('rascunho','teste_interno','uat','publicado','arquivado') NOT NULL DEFAULT 'rascunho',
  -- Condições de acionamento automático do template
  `filial_id`       char(36)     DEFAULT NULL COMMENT 'FK suave → filiais.id (NULL = todas)',
  `setor_id`        char(36)     DEFAULT NULL COMMENT 'FK suave → setores.id (NULL = todos)',
  `tipo_nota_id`    char(36)     DEFAULT NULL COMMENT 'FK suave → nf_lista_itens (Tipos de nota)',
  `valor_min`       decimal(15,2) DEFAULT NULL,
  `valor_max`       decimal(15,2) DEFAULT NULL,
  -- Configurações do fluxo
  `tem_financeiro`  tinyint(1)   NOT NULL DEFAULT 0
                    COMMENT 'Se 1, processo gera parcelas financeiras',
  -- Estrutura JSON do template
  `campos`          longtext     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ('[]')
                    COMMENT 'JSON: lista de campos dinâmicos do formulário',
  `etapas`          longtext     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ('[]')
                    COMMENT 'JSON: lista de etapas de aprovação com aprovadores',
  `tipos_anexo`     longtext     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ('[]')
                    COMMENT 'JSON: tipos de documento obrigatórios/opcionais',
  -- UAT — usuários convidados para teste
  `uat_usuario_ids` longtext     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
                    COMMENT 'JSON: array de IDs de usuários UAT',
  -- Metadados
  `criado_em`       timestamp    NOT NULL DEFAULT current_timestamp(),
  `criado_por`      char(36)     NOT NULL COMMENT 'FK suave → usuarios.id',
  `publicado_em`    datetime     DEFAULT NULL,
  `publicado_por`   char(36)     DEFAULT NULL COMMENT 'FK suave → usuarios.id',
  PRIMARY KEY (`id`),
  KEY `fk_nftv_template` (`template_id`),
  KEY `idx_nftv_status` (`status`),
  CONSTRAINT `fk_nftv_template` FOREIGN KEY (`template_id`)
    REFERENCES `nf_templates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Versões de cada template (rascunho → teste → UAT → publicado → arquivado)';

-- ─────────────────────────────────────────
-- 8. PROCESSOS DE NF
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `nf_processos` (
  `id`                  char(36)     NOT NULL,
  `codigo`              varchar(20)  NOT NULL COMMENT 'Ex: PROC-2026-0001 — gerado no PHP',
  `template_versao_id`  char(36)     NOT NULL,
  -- Dados da NF (campos obrigatórios independentes do template)
  `fornecedor_id`       char(36)     NOT NULL COMMENT 'FK suave → fornecedores.id',
  `fornecedor_nome`     varchar(200) NOT NULL COMMENT 'Desnormalizado — imutável no histórico',
  `fornecedor_doc`      char(14)     NOT NULL COMMENT 'CNPJ do fornecedor',
  `numero_nf`           varchar(255) NOT NULL,
  `valor_nf`            decimal(15,2) NOT NULL,
  `data_nf`             date         NOT NULL,
  `chave_nf`            char(44)     DEFAULT NULL COMMENT 'Chave de acesso NF-e (44 dígitos)',
  `filial_id`           char(36)     NOT NULL COMMENT 'FK suave → filiais.id',
  `filial_doc`          char(14)     NOT NULL COMMENT 'CNPJ da filial — preenchido automaticamente',
  -- Campos dinâmicos preenchidos no wizard (determinados pelo template)
  `dados_formulario`    longtext     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ('{}')
                        COMMENT 'JSON: { "campo_id": "valor" }',
  -- Controle do fluxo de aprovação
  `etapa_atual`         tinyint(3) unsigned NOT NULL DEFAULT 1,
  `status`              enum('aberto','aprovado','devolvido','cancelado','cancelamento_solicitado') NOT NULL DEFAULT 'aberto',
  -- Flag de teste (UAT / teste interno) — excluído de relatórios e financeiro
  `is_teste`            tinyint(1)   NOT NULL DEFAULT 0,
  `estagio_teste`       enum('interno','uat') DEFAULT NULL,
  -- Metadados
  `criado_em`           timestamp    NOT NULL DEFAULT current_timestamp(),
  `criado_por`          char(36)     NOT NULL COMMENT 'FK suave → usuarios.id',
  `concluido_em`        datetime     DEFAULT NULL,
  `deletado_em`         datetime     DEFAULT NULL COMMENT 'Soft delete — processos de teste',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nfp_codigo` (`codigo`),
  KEY `fk_nfp_versao` (`template_versao_id`),
  KEY `idx_nfp_status` (`status`),
  KEY `idx_nfp_filial` (`filial_id`),
  KEY `idx_nfp_criado_em` (`criado_em`),
  KEY `idx_nfp_is_teste` (`is_teste`),
  KEY `idx_nfp_fornecedor` (`fornecedor_id`),
  CONSTRAINT `fk_nfp_versao` FOREIGN KEY (`template_versao_id`)
    REFERENCES `nf_template_versoes` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Processos de NF abertos pelos usuários';

-- ─────────────────────────────────────────
-- 9. ETAPAS DOS PROCESSOS
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `nf_processo_etapas` (
  `id`               char(36)     NOT NULL,
  `processo_id`      char(36)     NOT NULL,
  `numero_etapa`     tinyint(3) unsigned NOT NULL,
  `nome_etapa`       varchar(100) NOT NULL,
  `tipo_aprovador`   enum('grupo','usuario') NOT NULL,
  `aprovador_id`     char(36)     NOT NULL
                     COMMENT 'FK suave → nf_grupos.id ou usuarios.id (depende de tipo_aprovador)',
  `status`           enum('pendente','aprovado','devolvido','pulado') NOT NULL DEFAULT 'pendente',
  `atuado_por`       char(36)     DEFAULT NULL COMMENT 'FK suave → usuarios.id',
  `atuado_em`        datetime     DEFAULT NULL,
  `motivo_devolucao` text         DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_nfpe_processo` (`processo_id`),
  KEY `idx_nfpe_status` (`status`),
  CONSTRAINT `fk_nfpe_processo` FOREIGN KEY (`processo_id`)
    REFERENCES `nf_processos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Etapas de aprovação de cada processo NF';

-- ─────────────────────────────────────────
-- 10. ANEXOS DOS PROCESSOS
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `nf_processo_anexos` (
  `id`               char(36)     NOT NULL,
  `processo_id`      char(36)     NOT NULL,
  `tipo_anexo_nome`  varchar(100) NOT NULL COMMENT 'Nome do tipo de documento (do template)',
  `nome_original`    varchar(255) NOT NULL COMMENT 'Nome original do arquivo enviado',
  `nome_armazenado`  char(36)     NOT NULL COMMENT 'UUID do arquivo em disco (sem extensão)',
  `caminho`          varchar(500) NOT NULL COMMENT 'Caminho relativo em storage/',
  `mime_type`        varchar(100) NOT NULL,
  `tamanho_bytes`    int(10) unsigned NOT NULL,
  `is_teste`         tinyint(1)   NOT NULL DEFAULT 0,
  `enviado_em`       timestamp    NOT NULL DEFAULT current_timestamp(),
  `enviado_por`      char(36)     NOT NULL COMMENT 'FK suave → usuarios.id',
  `deletado_em`      datetime     DEFAULT NULL COMMENT 'Soft delete',
  PRIMARY KEY (`id`),
  KEY `fk_nfpa_processo` (`processo_id`),
  KEY `idx_nfpa_deletado` (`deletado_em`),
  CONSTRAINT `fk_nfpa_processo` FOREIGN KEY (`processo_id`)
    REFERENCES `nf_processos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Anexos enviados em processos NF (path em storage/, acesso só via rota autenticada)';

-- ─────────────────────────────────────────
-- 11. HISTÓRICO DOS PROCESSOS
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `nf_processo_historico` (
  `id`           char(36)     NOT NULL,
  `processo_id`  char(36)     NOT NULL,
  `acao`         varchar(100) NOT NULL
                 COMMENT 'Ex: processo_criado, etapa_aprovada, etapa_avancada, processo_devolvido, processo_aprovado, processo_cancelado, cancelamento_solicitado, cancelamento_desfeito, responsavel_reatribuido, arquivo_substituido, arquivo_enviado',
  `numero_etapa` tinyint(3) unsigned DEFAULT NULL,
  `ator_id`      char(36)     NOT NULL COMMENT 'FK suave → usuarios.id',
  `ator_nome`    varchar(100) NOT NULL COMMENT 'Desnormalizado — imutável no histórico',
  `detalhe`      text         DEFAULT NULL COMMENT 'Motivo de devolução, observação, etc.',
  `criado_em`    timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_nfph_processo` (`processo_id`),
  KEY `idx_nfph_criado_em` (`criado_em`),
  CONSTRAINT `fk_nfph_processo` FOREIGN KEY (`processo_id`)
    REFERENCES `nf_processos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Linha do tempo imutável de cada processo NF';

-- ─────────────────────────────────────────
-- 12. PARCELAS FINANCEIRAS
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `nf_parcelas` (
  `id`                  char(36)     NOT NULL,
  `processo_id`         char(36)     NOT NULL,
  `numero_parcela`      tinyint(3) unsigned NOT NULL COMMENT 'Número da parcela (1 de N)',
  `total_parcelas`      tinyint(3) unsigned NOT NULL COMMENT 'Total de parcelas do processo',
  `vencimento`          date         NOT NULL,
  `valor`               decimal(15,2) NOT NULL,
  `forma_pagamento_id`  char(36)     DEFAULT NULL
                        COMMENT 'FK suave → nf_lista_itens (lista Formas de pagamento)',
  `status`              enum('previsto','agendado','agvenc','vencido','pago','cancelado') NOT NULL DEFAULT 'previsto',
  `data_agendamento`    date         DEFAULT NULL,
  `data_pagamento`      date         DEFAULT NULL,
  `observacao`          text         DEFAULT NULL,
  `is_teste`            tinyint(1)   NOT NULL DEFAULT 0,
  `criado_em`           timestamp    NOT NULL DEFAULT current_timestamp(),
  `atualizado_em`       timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deletado_em`         datetime     DEFAULT NULL COMMENT 'Soft delete',
  PRIMARY KEY (`id`),
  KEY `fk_nfpar_processo` (`processo_id`),
  KEY `idx_nfpar_status` (`status`),
  KEY `idx_nfpar_vencimento` (`vencimento`),
  KEY `idx_nfpar_is_teste` (`is_teste`),
  CONSTRAINT `fk_nfpar_processo` FOREIGN KEY (`processo_id`)
    REFERENCES `nf_processos` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Parcelas financeiras geradas pelos processos NF com tem_financeiro=1';

-- ─────────────────────────────────────────
-- 13. HISTÓRICO DAS PARCELAS
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `nf_parcela_historico` (
  `id`                char(36)     NOT NULL,
  `parcela_id`        char(36)     NOT NULL,
  `campo_alterado`    varchar(50)  NOT NULL COMMENT 'Ex: status, data_agendamento, forma_pagamento_id',
  `valor_anterior`    varchar(255) DEFAULT NULL,
  `valor_novo`        varchar(255) DEFAULT NULL,
  `alterado_por`      char(36)     NOT NULL COMMENT 'FK suave → usuarios.id',
  `alterado_por_nome` varchar(100) NOT NULL COMMENT 'Desnormalizado — imutável no histórico',
  `alterado_em`       timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_nfparh_parcela` (`parcela_id`),
  KEY `idx_nfparh_alterado_em` (`alterado_em`),
  CONSTRAINT `fk_nfparh_parcela` FOREIGN KEY (`parcela_id`)
    REFERENCES `nf_parcelas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Histórico de alterações de cada parcela financeira';

-- ═══════════════════════════════════════════════════════════════════════════
-- FIM DO SCHEMA DO MÓDULO NF
-- ═══════════════════════════════════════════════════════════════════════════
-- Próximos passos após criar o schema:
--   1. Executar database/migrations/001_extend_notificacoes_enum.sql
--   2. Executar database/migrations/002_add_workflow_nf_permissions.sql
--   3. Executar database/seeds/001_tipos_nota.sql
--   4. Executar database/seeds/002_formas_pagamento.sql
-- ═══════════════════════════════════════════════════════════════════════════
