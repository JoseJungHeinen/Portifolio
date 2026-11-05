-- Exemplo OFP — schema inicial. Reexecutável (IF NOT EXISTS). Rodar no banco já selecionado.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS unidades (
    id            CHAR(36)     NOT NULL PRIMARY KEY,
    nome          VARCHAR(80)  NOT NULL,
    codigo_dealer VARCHAR(20)  NULL,
    uf            CHAR(2)      NOT NULL DEFAULT 'RS',
    ativo         TINYINT(1)   NOT NULL DEFAULT 1,
    UNIQUE KEY uk_unidades_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS causais (
    id                  CHAR(36)      NOT NULL PRIMARY KEY,
    codigo              VARCHAR(4)    NOT NULL,
    descricao           VARCHAR(150)  NOT NULL,
    tipo_devolucao      ENUM('fisica','simbolica') NOT NULL,
    prazo_abertura_dias INT           NULL COMMENT 'NULL = sem prazo (sinistro)',
    fotos_obrigatorias  TEXT          NULL,
    valor_minimo        DECIMAL(12,2) NULL,
    frete_por           ENUM('fabricante','dealer') NOT NULL DEFAULT 'fabricante',
    exige_tp41          TINYINT(1)    NOT NULL DEFAULT 0,
    transportadora      ENUM('azul','jat') NULL,
    legado              TINYINT(1)    NOT NULL DEFAULT 0,
    ativo               TINYINT(1)    NOT NULL DEFAULT 1,
    UNIQUE KEY uk_causais_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
    id               CHAR(36)     NOT NULL PRIMARY KEY,
    nome             VARCHAR(120) NOT NULL,
    email            VARCHAR(160) NOT NULL,
    senha_hash       VARCHAR(255) NOT NULL,
    perfil           ENUM('estoque','comprador','gerente','financeiro','diretor','admin') NOT NULL,
    ativo            TINYINT(1)   NOT NULL DEFAULT 1,
    tentativas_login INT          NOT NULL DEFAULT 0,
    bloqueado_ate    DATETIME     NULL,
    ultimo_acesso    DATETIME     NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuario_unidades (
    usuario_id CHAR(36) NOT NULL,
    unidade_id CHAR(36) NOT NULL,
    PRIMARY KEY (usuario_id, unidade_id),
    CONSTRAINT fk_uu_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_uu_unidade FOREIGN KEY (unidade_id) REFERENCES unidades(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ofps (
    id                     CHAR(36)      NOT NULL PRIMARY KEY,
    unidade_id             CHAR(36)      NOT NULL,
    causal_id              CHAR(36)      NOT NULL,
    numero_ofp             VARCHAR(20)   NULL,
    desenho                VARCHAR(30)   NOT NULL,
    descricao_peca         VARCHAR(150)  NULL,
    quantidade             DECIMAL(10,2) NOT NULL DEFAULT 1,
    valor_reclamado        DECIMAL(12,2) NOT NULL DEFAULT 0,
    nf_origem              VARCHAR(20)   NULL,
    serie_origem           VARCHAR(5)    NULL,
    numero_volume          VARCHAR(30)   NULL,
    transportadora         ENUM('azul','jat','outra') NULL,
    data_recebimento       DATE          NULL COMMENT 'D — recebimento do CTe',
    status                 ENUM('registrada','em_analise','rejeitada','em_reanalise','rejeitada_final','aprovada',
                                'aguardando_tp41','nf_emitida','aguardando_coleta','coletada','aguardando_credito',
                                'creditada','perdida_prazo','encerrada','cancelada','peca_vendida','estornada')
                                         NOT NULL DEFAULT 'registrada',
    responsavel_id         CHAR(36)      NULL,
    observacao             TEXT          NULL,
    data_abertura          DATE          NULL,
    data_decisao           DATE          NULL,
    protocolo_grow         VARCHAR(30)   NULL,
    nf_devolucao           VARCHAR(20)   NULL,
    data_nf_devolucao      DATE          NULL,
    valor_nf_devolucao     DECIMAL(12,2) NULL,
    nf_icms_st             VARCHAR(20)   NULL,
    data_nf_icms_st        DATE          NULL,
    valor_icms_st          DECIMAL(12,2) NULL,
    data_coleta_solicitada DATE          NULL,
    data_coletada          DATE          NULL,
    codigo_rastreio        VARCHAR(40)   NULL,
    titulo_apollo          VARCHAR(30)   NULL,
    data_credito           DATE          NULL,
    valor_creditado_peca   DECIMAL(12,2) NULL,
    valor_creditado_st     DECIMAL(12,2) NULL,
    prazo_tipo             VARCHAR(20)   NULL,
    prazo_data             DATE          NULL,
    origem_importacao      VARCHAR(60)   NULL,
    created_by             CHAR(36)      NULL,
    created_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ofps_numero (numero_ofp),
    KEY ix_ofps_status (status),
    KEY ix_ofps_unidade_status (unidade_id, status),
    KEY ix_ofps_prazo (prazo_data),
    KEY ix_ofps_desenho (desenho),
    KEY ix_ofps_nf_devolucao (nf_devolucao),
    CONSTRAINT fk_ofps_unidade FOREIGN KEY (unidade_id) REFERENCES unidades(id),
    CONSTRAINT fk_ofps_causal FOREIGN KEY (causal_id) REFERENCES causais(id),
    CONSTRAINT fk_ofps_responsavel FOREIGN KEY (responsavel_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ofp_eventos (
    id          CHAR(36)    NOT NULL PRIMARY KEY,
    ofp_id      CHAR(36)    NOT NULL,
    usuario_id  CHAR(36)    NULL,
    status_de   VARCHAR(30) NULL,
    status_para VARCHAR(30) NULL,
    observacao  TEXT        NULL,
    created_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_eventos_ofp (ofp_id, created_at),
    CONSTRAINT fk_eventos_ofp FOREIGN KEY (ofp_id) REFERENCES ofps(id) ON DELETE CASCADE,
    CONSTRAINT fk_eventos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ofp_anexos (
    id            CHAR(36)     NOT NULL PRIMARY KEY,
    ofp_id        CHAR(36)     NOT NULL,
    tipo          ENUM('foto','cte','nf_pdf','nf_xml','outro') NOT NULL DEFAULT 'outro',
    caminho       VARCHAR(255) NOT NULL,
    nome_original VARCHAR(200) NOT NULL,
    usuario_id    CHAR(36)     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_anexos_ofp (ofp_id),
    CONSTRAINT fk_anexos_ofp FOREIGN KEY (ofp_id) REFERENCES ofps(id) ON DELETE CASCADE,
    CONSTRAINT fk_anexos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
