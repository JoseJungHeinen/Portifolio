-- ══════════════════════════════════════════════════════════════════════
-- Exemplo Compras — Schema Completo (25 tabelas)
-- Banco: Compras | Charset: utf8mb4 | Engine: InnoDB
-- ══════════════════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ────────────────────────────────────────────────────────────────────────
-- 01. FILIAIS
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS filiais (
    id            CHAR(36)       NOT NULL,
    nome          VARCHAR(100)   NOT NULL,
    cnpj          CHAR(14)       NOT NULL,
    endereco      VARCHAR(200),
    cidade        VARCHAR(100),
    estado        CHAR(2),
    budget_mensal DECIMAL(14,2)  NOT NULL DEFAULT 0.00,
    ativo         TINYINT(1)     NOT NULL DEFAULT 1,
    criado_em     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_filial_cnpj (cnpj),
    KEY idx_filial_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 02. SETORES
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS setores (
    id    CHAR(36)     NOT NULL,
    nome  VARCHAR(100) NOT NULL,
    tipo  ENUM('operacional','administrativo') NOT NULL DEFAULT 'operacional',
    ativo TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_setor_nome (nome),
    KEY idx_setor_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 03. CATEGORIAS
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS categorias (
    id        CHAR(36)     NOT NULL,
    nome      VARCHAR(100) NOT NULL,
    descricao TEXT,
    ativo     TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_categoria_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 04. GRUPOS DE PRODUTOS
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS grupos_produtos (
    id        CHAR(36)     NOT NULL,
    nome      VARCHAR(100) NOT NULL,
    descricao TEXT,
    ativo     TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_grupo_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 05. USUÁRIOS
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS usuarios (
    id                  CHAR(36)     NOT NULL,
    nome                VARCHAR(100) NOT NULL,
    email               VARCHAR(150) NOT NULL,
    senha_hash          VARCHAR(255) NOT NULL,
    perfil              ENUM('solicitante','almoxarife','comprador','gestor','diretor','admin') NOT NULL,
    filial_id           CHAR(36)     NOT NULL,
    setor_id            CHAR(36)     NOT NULL,
    ativo               TINYINT(1)   NOT NULL DEFAULT 1,
    force_change_senha  TINYINT(1)   NOT NULL DEFAULT 0,
    tentativas_login    TINYINT      NOT NULL DEFAULT 0,
    bloqueado_ate       DATETIME     NULL,
    token_reset_senha   VARCHAR(64)  NULL,
    token_reset_expiry  DATETIME     NULL,
    ultimo_acesso       DATETIME     NULL,
    criado_por          CHAR(36)     NULL,
    criado_em           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usuario_email (email),
    KEY idx_usuario_filial (filial_id),
    KEY idx_usuario_setor  (setor_id),
    KEY idx_usuario_perfil (perfil),
    CONSTRAINT fk_usuario_filial FOREIGN KEY (filial_id) REFERENCES filiais(id),
    CONSTRAINT fk_usuario_setor  FOREIGN KEY (setor_id)  REFERENCES setores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 06. ITENS
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS itens (
    id               CHAR(36)      NOT NULL,
    codigo           VARCHAR(50)   NOT NULL,
    nome             VARCHAR(200)  NOT NULL,
    descricao        TEXT,
    categoria_id     CHAR(36)      NOT NULL,
    grupo_produto_id CHAR(36)      NOT NULL,
    unidade          VARCHAR(20)   NOT NULL DEFAULT 'UN',
    curva_abc        ENUM('A','B','C') NOT NULL DEFAULT 'C',
    estoque_minimo   DECIMAL(10,2) NOT NULL DEFAULT 0,
    valor_referencia DECIMAL(14,2) NULL,
    ativo            TINYINT(1)    NOT NULL DEFAULT 1,
    criado_em        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_item_codigo (codigo),
    KEY idx_item_categoria     (categoria_id),
    KEY idx_item_grupo_produto (grupo_produto_id),
    CONSTRAINT fk_item_categoria     FOREIGN KEY (categoria_id)     REFERENCES categorias(id),
    CONSTRAINT fk_item_grupo_produto FOREIGN KEY (grupo_produto_id) REFERENCES grupos_produtos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 07. ESTOQUE
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS estoque (
    id            CHAR(36)      NOT NULL,
    item_id       CHAR(36)      NOT NULL,
    filial_id     CHAR(36)      NOT NULL,
    quantidade    DECIMAL(10,2) NOT NULL DEFAULT 0,
    localizacao   VARCHAR(100),
    qr_code       VARCHAR(200),
    atualizado_em TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_estoque_item_filial (item_id, filial_id),
    CONSTRAINT fk_estoque_item   FOREIGN KEY (item_id)   REFERENCES itens(id),
    CONSTRAINT fk_estoque_filial FOREIGN KEY (filial_id) REFERENCES filiais(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 08. MOVIMENTOS DE ESTOQUE (histórico)
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS movimentos_estoque (
    id              CHAR(36)      NOT NULL,
    item_id         CHAR(36)      NOT NULL,
    filial_id       CHAR(36)      NOT NULL,
    tipo            ENUM('entrada','saida') NOT NULL,
    quantidade      DECIMAL(10,2) NOT NULL,
    referencia_tipo VARCHAR(50),
    referencia_id   CHAR(36),
    usuario_id      CHAR(36)      NOT NULL,
    criado_em       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mov_item    (item_id),
    KEY idx_mov_filial  (filial_id),
    KEY idx_mov_data    (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 09. ALÇADAS
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alcadas (
    id         CHAR(36) NOT NULL,
    perfil     ENUM('solicitante','almoxarife','comprador','gestor','diretor','admin') NOT NULL,
    valor_min  DECIMAL(14,2) NOT NULL DEFAULT 0,
    valor_max  DECIMAL(14,2) NULL COMMENT 'NULL = sem limite superior',
    nivel      TINYINT       NOT NULL,
    ativo      TINYINT(1)    NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_alcada_perfil_nivel (perfil, nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 10. ORÇAMENTOS (filial × setor × grupo × mês/ano)
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS orcamentos (
    id                CHAR(36)      NOT NULL,
    filial_id         CHAR(36)      NOT NULL,
    setor_id          CHAR(36)      NOT NULL,
    grupo_produto_id  CHAR(36)      NOT NULL,
    mes               TINYINT       NOT NULL,
    ano               SMALLINT      NOT NULL,
    valor_aprovado    DECIMAL(14,2) NOT NULL DEFAULT 0,
    valor_consumido   DECIMAL(14,2) NOT NULL DEFAULT 0,
    valor_mes_anterior DECIMAL(14,2) NULL,
    saving_realizado  DECIMAL(14,2) GENERATED ALWAYS AS (COALESCE(valor_mes_anterior,0) - valor_consumido) STORED,
    status            ENUM('ativo','esgotado','suspenso') NOT NULL DEFAULT 'ativo',
    criado_por        CHAR(36)      NOT NULL,
    aprovado_por      CHAR(36)      NULL,
    criado_em         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_orcamento_periodo (filial_id, setor_id, grupo_produto_id, mes, ano),
    KEY idx_orcamento_status (status),
    CONSTRAINT fk_orc_filial  FOREIGN KEY (filial_id)        REFERENCES filiais(id),
    CONSTRAINT fk_orc_setor   FOREIGN KEY (setor_id)         REFERENCES setores(id),
    CONSTRAINT fk_orc_grupo   FOREIGN KEY (grupo_produto_id) REFERENCES grupos_produtos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 11. REQUISIÇÕES
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS requisicoes (
    id                    CHAR(36)      NOT NULL,
    filial_id             CHAR(36)      NOT NULL,
    setor_id              CHAR(36)      NOT NULL,
    grupo_produto_id      CHAR(36)      NULL,
    usuario_id            CHAR(36)      NOT NULL,
    status                ENUM('rascunho','pendente','em_aprovacao','aprovado','rejeitado','cancelado') NOT NULL DEFAULT 'rascunho',
    tipo_despesa          ENUM('recorrente','variavel','eventual') NOT NULL DEFAULT 'variavel',
    valor_total           DECIMAL(14,2) NOT NULL DEFAULT 0,
    justificativa         TEXT          NULL,
    nivel_aprovacao_atual TINYINT       NOT NULL DEFAULT 1,
    criado_em             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_req_filial    (filial_id),
    KEY idx_req_setor     (setor_id),
    KEY idx_req_usuario   (usuario_id),
    KEY idx_req_status    (status),
    CONSTRAINT fk_req_filial  FOREIGN KEY (filial_id)        REFERENCES filiais(id),
    CONSTRAINT fk_req_setor   FOREIGN KEY (setor_id)         REFERENCES setores(id),
    CONSTRAINT fk_req_grupo   FOREIGN KEY (grupo_produto_id) REFERENCES grupos_produtos(id),
    CONSTRAINT fk_req_usuario FOREIGN KEY (usuario_id)       REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requisicao_itens (
    id              CHAR(36)      NOT NULL,
    requisicao_id   CHAR(36)      NOT NULL,
    item_id         CHAR(36)      NOT NULL,
    quantidade      DECIMAL(10,2) NOT NULL,
    valor_unitario  DECIMAL(14,2) NOT NULL DEFAULT 0,
    observacao      TEXT,
    PRIMARY KEY (id),
    CONSTRAINT fk_reqitem_req  FOREIGN KEY (requisicao_id) REFERENCES requisicoes(id) ON DELETE CASCADE,
    CONSTRAINT fk_reqitem_item FOREIGN KEY (item_id)       REFERENCES itens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 12. SOLICITAÇÕES DE AUMENTO DE ORÇAMENTO
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS solicitacoes_orcamento (
    id                  CHAR(36)      NOT NULL,
    orcamento_id        CHAR(36)      NOT NULL,
    requisicao_id       CHAR(36)      NULL,
    filial_id           CHAR(36)      NOT NULL,
    setor_id            CHAR(36)      NOT NULL,
    grupo_produto_id    CHAR(36)      NOT NULL,
    valor_saldo_atual   DECIMAL(14,2) NOT NULL DEFAULT 0,
    valor_necessario    DECIMAL(14,2) NOT NULL,
    valor_solicitado    DECIMAL(14,2) NOT NULL,
    justificativa       TEXT          NOT NULL,
    status              ENUM('pendente','aprovado','rejeitado','cancelado') NOT NULL DEFAULT 'pendente',
    solicitado_por      CHAR(36)      NOT NULL,
    analisado_por       CHAR(36)      NULL,
    observacao_direcao  TEXT          NULL,
    criado_em           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolvido_em        DATETIME      NULL,
    PRIMARY KEY (id),
    KEY idx_sol_orc_status (status),
    CONSTRAINT fk_solorc_orcamento FOREIGN KEY (orcamento_id)     REFERENCES orcamentos(id),
    CONSTRAINT fk_solorc_filial    FOREIGN KEY (filial_id)         REFERENCES filiais(id),
    CONSTRAINT fk_solorc_setor     FOREIGN KEY (setor_id)          REFERENCES setores(id),
    CONSTRAINT fk_solorc_grupo     FOREIGN KEY (grupo_produto_id)  REFERENCES grupos_produtos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 13. TRANSFERÊNCIAS
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS transferencias (
    id                    CHAR(36) NOT NULL,
    filial_origem_id      CHAR(36) NOT NULL,
    filial_destino_id     CHAR(36) NOT NULL,
    requisicao_id         CHAR(36) NULL,
    usuario_solicitante   CHAR(36) NOT NULL,
    status                ENUM('pendente','aprovado','enviado','recebido','cancelado') NOT NULL DEFAULT 'pendente',
    modelo                VARCHAR(100) NULL,
    cor                   VARCHAR(50)  NULL,
    placa                 VARCHAR(20)  NULL,
    transportadora        VARCHAR(150) NULL,
    observacao            TEXT,
    criado_em             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_transf_origem  FOREIGN KEY (filial_origem_id)  REFERENCES filiais(id),
    CONSTRAINT fk_transf_destino FOREIGN KEY (filial_destino_id) REFERENCES filiais(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transferencia_itens (
    id               CHAR(36)      NOT NULL,
    transferencia_id CHAR(36)      NOT NULL,
    item_id          CHAR(36)      NOT NULL,
    quantidade       DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_transitem_transf FOREIGN KEY (transferencia_id) REFERENCES transferencias(id) ON DELETE CASCADE,
    CONSTRAINT fk_transitem_item   FOREIGN KEY (item_id)          REFERENCES itens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 14. FORNECEDORES
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS fornecedores (
    id            CHAR(36)      NOT NULL,
    razao_social  VARCHAR(200)  NOT NULL,
    nome_fantasia VARCHAR(200),
    cnpj          CHAR(14)      NOT NULL,
    email         VARCHAR(150),
    telefone      VARCHAR(20),
    endereco      VARCHAR(200),
    cidade        VARCHAR(100),
    estado        CHAR(2),
    observacoes   TEXT,
    avaliacao     DECIMAL(3,2)  NULL,
    ativo         TINYINT(1)    NOT NULL DEFAULT 1,
    criado_em     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_fornecedor_cnpj (cnpj),
    KEY idx_forn_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certificados_fornecedor (
    id            CHAR(36)     NOT NULL,
    fornecedor_id CHAR(36)     NOT NULL,
    tipo          VARCHAR(100) NOT NULL,
    arquivo_path  VARCHAR(300),
    validade      DATE         NULL,
    ativo         TINYINT(1)   NOT NULL DEFAULT 1,
    criado_em     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cert_validade (validade),
    CONSTRAINT fk_cert_fornecedor FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 15. CONTRATOS
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS contratos (
    id                      CHAR(36)      NOT NULL,
    fornecedor_id           CHAR(36)      NOT NULL,
    numero                  VARCHAR(50),
    objeto                  TEXT          NOT NULL,
    valor                   DECIMAL(14,2) NOT NULL,
    data_inicio             DATE          NOT NULL,
    data_fim                DATE          NOT NULL,
    reajuste_indexador      VARCHAR(20)   NULL,
    percentual_reajuste     DECIMAL(5,2)  NULL,
    arquivo_path            VARCHAR(300)  NULL,
    alerta_antecipacao_dias TINYINT       NOT NULL DEFAULT 30,
    recorrente              TINYINT(1)    NOT NULL DEFAULT 1,
    categoria_dashboard     ENUM('operacional','administrativo','investimento') NOT NULL DEFAULT 'operacional',
    status                  ENUM('ativo','vencido','cancelado') NOT NULL DEFAULT 'ativo',
    criado_em               TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contrato_status   (status),
    KEY idx_contrato_data_fim (data_fim),
    CONSTRAINT fk_contrato_fornecedor FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 16. COTAÇÕES
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cotacoes (
    id             CHAR(36)  NOT NULL,
    requisicao_id  CHAR(36)  NOT NULL,
    status         ENUM('aberta','aguardando_respostas','encerrada','cancelada') NOT NULL DEFAULT 'aberta',
    prazo_resposta DATETIME  NOT NULL,
    criado_por     CHAR(36)  NOT NULL,
    criado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_cotacao_req FOREIGN KEY (requisicao_id) REFERENCES requisicoes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cotacao_fornecedores (
    id             CHAR(36)  NOT NULL,
    cotacao_id     CHAR(36)  NOT NULL,
    fornecedor_id  CHAR(36)  NOT NULL,
    status         ENUM('enviada','respondida','recusada') NOT NULL DEFAULT 'enviada',
    token_acesso   CHAR(36)  NOT NULL,
    respondido_em  DATETIME  NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cotforn_token (token_acesso),
    CONSTRAINT fk_cotforn_cotacao    FOREIGN KEY (cotacao_id)    REFERENCES cotacoes(id),
    CONSTRAINT fk_cotforn_fornecedor FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cotacao_itens (
    id                    CHAR(36)      NOT NULL,
    cotacao_fornecedor_id CHAR(36)      NOT NULL,
    item_id               CHAR(36)      NOT NULL,
    quantidade            DECIMAL(10,2) NOT NULL,
    valor_unitario        DECIMAL(14,2) NOT NULL,
    prazo_entrega_dias    TINYINT       NULL,
    observacao            TEXT,
    PRIMARY KEY (id),
    CONSTRAINT fk_cotitem_cotforn FOREIGN KEY (cotacao_fornecedor_id) REFERENCES cotacao_fornecedores(id),
    CONSTRAINT fk_cotitem_item    FOREIGN KEY (item_id) REFERENCES itens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 17. ORDENS DE COMPRA
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ordens_compra (
    id             CHAR(36)      NOT NULL,
    numero_oc      VARCHAR(20)   NOT NULL,
    requisicao_id  CHAR(36)      NOT NULL,
    cotacao_id     CHAR(36)      NULL,
    fornecedor_id  CHAR(36)      NOT NULL,
    valor_total    DECIMAL(14,2) NOT NULL,
    status         ENUM('rascunho','aprovado','enviado','recebido_parcial','recebido_total','cancelado') NOT NULL DEFAULT 'rascunho',
    criado_por     CHAR(36)      NOT NULL,
    criado_em      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_oc_numero (numero_oc),
    CONSTRAINT fk_oc_requisicao FOREIGN KEY (requisicao_id) REFERENCES requisicoes(id),
    CONSTRAINT fk_oc_cotacao    FOREIGN KEY (cotacao_id)    REFERENCES cotacoes(id),
    CONSTRAINT fk_oc_fornecedor FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ordens_compra_itens (
    id                  CHAR(36)      NOT NULL,
    oc_id               CHAR(36)      NOT NULL,
    item_id             CHAR(36)      NOT NULL,
    quantidade          DECIMAL(10,2) NOT NULL,
    valor_unitario      DECIMAL(14,2) NOT NULL,
    quantidade_recebida DECIMAL(10,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT fk_ocitem_oc   FOREIGN KEY (oc_id)   REFERENCES ordens_compra(id) ON DELETE CASCADE,
    CONSTRAINT fk_ocitem_item FOREIGN KEY (item_id)  REFERENCES itens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 18. NOTAS FISCAIS
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notas_fiscais (
    id                CHAR(36)      NOT NULL,
    oc_id             CHAR(36)      NOT NULL,
    numero_nf         VARCHAR(20)   NOT NULL,
    chave_acesso      CHAR(44)      NOT NULL,
    emitente_cnpj     CHAR(14)      NOT NULL,
    valor_total       DECIMAL(14,2) NOT NULL,
    xml_path          VARCHAR(300)  NOT NULL,
    status            ENUM('pendente_conferencia','aprovada','divergente','rejeitada') NOT NULL DEFAULT 'pendente_conferencia',
    divergencias_json JSON          NULL,
    tolerancia_pct    DECIMAL(5,2)  NOT NULL DEFAULT 0.01,
    conferido_por     CHAR(36)      NULL,
    conferido_em      DATETIME      NULL,
    criado_em         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_nf_chave (chave_acesso),
    CONSTRAINT fk_nf_oc FOREIGN KEY (oc_id) REFERENCES ordens_compra(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 19. APROVAÇÕES
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS aprovacoes (
    id            CHAR(36)  NOT NULL,
    entidade_tipo ENUM('requisicao','ordem_compra','transferencia') NOT NULL,
    entidade_id   CHAR(36)  NOT NULL,
    nivel         TINYINT   NOT NULL,
    status        ENUM('pendente','aprovado','rejeitado') NOT NULL DEFAULT 'pendente',
    aprovado_por  CHAR(36)  NULL,
    observacao    TEXT      NULL,
    criado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolvido_em  DATETIME  NULL,
    PRIMARY KEY (id),
    KEY idx_aprovacao_entidade (entidade_tipo, entidade_id),
    KEY idx_aprovacao_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 20. HISTÓRICO DE PREÇOS
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS historico_precos (
    id             CHAR(36)      NOT NULL,
    item_id        CHAR(36)      NOT NULL,
    fornecedor_id  CHAR(36)      NOT NULL,
    valor_unitario DECIMAL(14,2) NOT NULL,
    origem         ENUM('cotacao','ordem_compra','nota_fiscal') NOT NULL,
    origem_id      CHAR(36)      NOT NULL,
    registrado_em  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hpreco_item_forn (item_id, fornecedor_id),
    KEY idx_hpreco_data      (registrado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 21. METAS DE ECONOMIA
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS metas_economia (
    id               CHAR(36)      NOT NULL,
    filial_id        CHAR(36)      NULL,
    setor_id         CHAR(36)      NULL,
    grupo_produto_id CHAR(36)      NULL,
    mes              TINYINT       NOT NULL,
    ano              SMALLINT      NOT NULL,
    valor_referencia DECIMAL(14,2) NOT NULL,
    percentual_meta  DECIMAL(5,2)  NOT NULL DEFAULT 10.00,
    valor_meta       DECIMAL(14,2) NOT NULL,
    valor_realizado  DECIMAL(14,2) NOT NULL DEFAULT 0,
    economia_gerada  DECIMAL(14,2) GENERATED ALWAYS AS (valor_referencia - valor_realizado) STORED,
    status           ENUM('em_andamento','atingida','nao_atingida') NOT NULL DEFAULT 'em_andamento',
    criado_por       CHAR(36)      NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 22. ANOMALIAS
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS anomalias (
    id             CHAR(36)      NOT NULL,
    tipo           ENUM(
                    'compra_duplicada','preco_acima_historico','sem_cotacao_obrigatoria',
                    'pico_fim_mes','centralizacao_possivel','fornecedor_unico',
                    'reajuste_acima_inflacao','orcamento_esgotado_cedo','despesa_eventual_recorrente'
                   ) NOT NULL,
    titulo         VARCHAR(200)  NOT NULL,
    descricao      TEXT          NOT NULL,
    valor_impacto  DECIMAL(14,2) NOT NULL DEFAULT 0,
    entidade_tipo  VARCHAR(50)   NULL,
    entidade_id    CHAR(36)      NULL,
    filial_id      CHAR(36)      NULL,
    setor_id       CHAR(36)      NULL,
    status         ENUM('ativa','resolvida','ignorada') NOT NULL DEFAULT 'ativa',
    ignorado_por   CHAR(36)      NULL,
    ignorado_motivo TEXT         NULL,
    criado_em      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_anomalia_tipo   (tipo),
    KEY idx_anomalia_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 23. NOTIFICAÇÕES
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notificacoes (
    id            CHAR(36)     NOT NULL,
    usuario_id    CHAR(36)     NOT NULL,
    tipo          ENUM('aprovacao_pendente','oc_aprovada','nf_divergente','contrato_vencendo',
                       'estoque_critico','budget_critico','transferencia_pendente') NOT NULL,
    titulo        VARCHAR(200) NOT NULL,
    mensagem      TEXT         NOT NULL,
    lida          TINYINT(1)   NOT NULL DEFAULT 0,
    entidade_tipo VARCHAR(50)  NULL,
    entidade_id   CHAR(36)     NULL,
    criado_em     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_usuario_lida (usuario_id, lida),
    CONSTRAINT fk_notif_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 24. LOGS DE AUDITORIA (imutável)
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS logs_auditoria (
    id             CHAR(36)     NOT NULL,
    usuario_id     CHAR(36)     NULL,
    entidade_tipo  VARCHAR(50)  NOT NULL,
    entidade_id    CHAR(36)     NULL,
    acao           VARCHAR(100) NOT NULL,
    payload_antes  JSON         NULL,
    payload_depois JSON         NULL,
    ip_address     VARCHAR(45),
    user_agent     VARCHAR(300),
    criado_em      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_log_entidade  (entidade_tipo, entidade_id),
    KEY idx_log_usuario   (usuario_id),
    KEY idx_log_criado_em (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tabela de auditoria — proibido UPDATE e DELETE';

-- ────────────────────────────────────────────────────────────────────────
-- 25. PARÂMETROS DO SISTEMA
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS parametros_sistema (
    id             CHAR(36)     NOT NULL,
    chave          VARCHAR(100) NOT NULL,
    valor          TEXT         NOT NULL,
    descricao      TEXT,
    atualizado_por CHAR(36)     NULL,
    atualizado_em  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_param_chave (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────
-- 26. DASHBOARD: PREFERÊNCIAS POR USUÁRIO
-- ────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dashboard_preferencias (
    usuario_id CHAR(36)    NOT NULL,
    widget     VARCHAR(50) NOT NULL,
    visivel    TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (usuario_id, widget)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 27. DOCUMENTOS DE FORNECEDORES
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documentos_fornecedor (
    id             CHAR(36)     NOT NULL,
    fornecedor_id  CHAR(36)     NOT NULL,
    tipo           VARCHAR(50)  NOT NULL DEFAULT 'outros',
    nome_original  VARCHAR(255) NOT NULL,
    arquivo_path   VARCHAR(255) NOT NULL,
    tamanho_bytes  INT UNSIGNED NOT NULL DEFAULT 0,
    criado_por     CHAR(36)     NOT NULL,
    criado_em      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_docforn_fornecedor (fornecedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- ALTER: contratos -- valor_mensal, num_parcelas, is_valor_total
-- ---------------------------------------------------------------------------
ALTER TABLE contratos
  ADD COLUMN IF NOT EXISTS is_valor_total TINYINT(1)       NOT NULL DEFAULT 0     AFTER valor,
  ADD COLUMN IF NOT EXISTS num_parcelas   SMALLINT UNSIGNED NULL                  AFTER is_valor_total,
  ADD COLUMN IF NOT EXISTS valor_mensal   DECIMAL(14,2)    NULL                   AFTER num_parcelas;

-- Migra registros existentes: valor era mensal, copiar para valor_mensal
UPDATE contratos SET valor_mensal = valor WHERE valor_mensal IS NULL;

-- ---------------------------------------------------------------------------
-- ALTER: estoque -- quantidade_reservada para rastreamento de reservas
-- ---------------------------------------------------------------------------
ALTER TABLE estoque
  ADD COLUMN IF NOT EXISTS quantidade_reservada DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER quantidade;

-- ---------------------------------------------------------------------------
-- ALTER: cotacao_fornecedores -- frete e observacao
-- ---------------------------------------------------------------------------
ALTER TABLE cotacao_fornecedores
  ADD COLUMN IF NOT EXISTS frete      DECIMAL(14,2) NOT NULL DEFAULT 0   AFTER respondido_em,
  ADD COLUMN IF NOT EXISTS observacao TEXT          NULL                  AFTER frete;

-- ---------------------------------------------------------------------------
-- ALTER: transferencias -- modelo, cor e placa do veiculo
-- ---------------------------------------------------------------------------
ALTER TABLE transferencias
  ADD COLUMN IF NOT EXISTS modelo         VARCHAR(100) NULL AFTER status,
  ADD COLUMN IF NOT EXISTS cor            VARCHAR(50)  NULL AFTER modelo,
  ADD COLUMN IF NOT EXISTS placa          VARCHAR(20)  NULL AFTER cor,
  ADD COLUMN IF NOT EXISTS transportadora VARCHAR(150) NULL AFTER placa;

-- ---------------------------------------------------------------------------
-- ALTER: contratos -- flag sem valor fixo
-- ---------------------------------------------------------------------------
ALTER TABLE contratos
  ADD COLUMN IF NOT EXISTS sem_valor TINYINT(1) NOT NULL DEFAULT 0 AFTER valor;

-- ---------------------------------------------------------------------------
-- ALTER: fornecedores -- flags de utilização e descrição do serviço
-- ---------------------------------------------------------------------------
ALTER TABLE fornecedores
  ADD COLUMN IF NOT EXISTS para_compras      TINYINT(1)   NOT NULL DEFAULT 1    AFTER avaliacao,
  ADD COLUMN IF NOT EXISTS para_contratos    TINYINT(1)   NOT NULL DEFAULT 0    AFTER para_compras,
  ADD COLUMN IF NOT EXISTS descricao_servico VARCHAR(500) NULL                  AFTER para_contratos;

SET FOREIGN_KEY_CHECKS = 1;
