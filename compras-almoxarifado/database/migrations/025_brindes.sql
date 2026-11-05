-- Migration 025: Módulo de Brindes
CREATE TABLE IF NOT EXISTS brindes (
    id               CHAR(36)      NOT NULL,
    nome             VARCHAR(160)  NOT NULL,
    descricao        TEXT          NULL,
    codigo           VARCHAR(60)   NULL,
    valor_referencia DECIMAL(14,2) NOT NULL DEFAULT 0,
    ativo            TINYINT(1)    NOT NULL DEFAULT 1,
    criado_por       CHAR(36)      NOT NULL,
    criado_em        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_brinde_ativo (ativo),
    CONSTRAINT fk_brinde_criador FOREIGN KEY (criado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brinde_estoque (
    id         CHAR(36)      NOT NULL,
    brinde_id  CHAR(36)      NOT NULL,
    filial_id  CHAR(36)      NOT NULL,
    quantidade DECIMAL(10,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_brinde_filial (brinde_id, filial_id),
    CONSTRAINT fk_bestoque_brinde FOREIGN KEY (brinde_id) REFERENCES brindes(id) ON DELETE CASCADE,
    CONSTRAINT fk_bestoque_filial FOREIGN KEY (filial_id) REFERENCES filiais(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brinde_fotos (
    id            CHAR(36)     NOT NULL,
    brinde_id     CHAR(36)     NOT NULL,
    arquivo_path  VARCHAR(255) NOT NULL,
    nome_original VARCHAR(255) NULL,
    legenda       VARCHAR(255) NULL,
    tamanho_bytes INT          NULL,
    criado_por    CHAR(36)     NOT NULL,
    criado_em     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bfoto_brinde (brinde_id),
    CONSTRAINT fk_bfoto_brinde FOREIGN KEY (brinde_id)  REFERENCES brindes(id) ON DELETE CASCADE,
    CONSTRAINT fk_bfoto_user   FOREIGN KEY (criado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requisicoes_brinde (
    id           CHAR(36)      NOT NULL,
    filial_id    CHAR(36)      NOT NULL,
    cliente_nome VARCHAR(160)  NOT NULL,
    observacao   TEXT          NULL,
    status       ENUM('em_aprovacao','aprovado','rejeitado','cancelado') NOT NULL DEFAULT 'em_aprovacao',
    valor_total  DECIMAL(14,2) NOT NULL DEFAULT 0,
    criado_por   CHAR(36)      NOT NULL,
    criado_em    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolvido_em DATETIME      NULL,
    PRIMARY KEY (id),
    KEY idx_reqbrinde_filial (filial_id),
    KEY idx_reqbrinde_status (status),
    CONSTRAINT fk_reqbrinde_filial  FOREIGN KEY (filial_id)  REFERENCES filiais(id),
    CONSTRAINT fk_reqbrinde_criador FOREIGN KEY (criado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requisicao_brinde_itens (
    id                   CHAR(36)      NOT NULL,
    requisicao_brinde_id CHAR(36)      NOT NULL,
    brinde_id            CHAR(36)      NOT NULL,
    quantidade           DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_reqbritem_req (requisicao_brinde_id),
    CONSTRAINT fk_reqbritem_req    FOREIGN KEY (requisicao_brinde_id) REFERENCES requisicoes_brinde(id) ON DELETE CASCADE,
    CONSTRAINT fk_reqbritem_brinde FOREIGN KEY (brinde_id)            REFERENCES brindes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS movimentos_brinde (
    id              CHAR(36)      NOT NULL,
    brinde_id       CHAR(36)      NOT NULL,
    filial_id       CHAR(36)      NOT NULL,
    tipo            ENUM('entrada','saida') NOT NULL,
    quantidade      DECIMAL(10,2) NOT NULL,
    referencia_tipo VARCHAR(50)   NULL,
    referencia_id   CHAR(36)      NULL,
    criado_por      CHAR(36)      NOT NULL,
    criado_em       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_movbrinde_brinde (brinde_id),
    KEY idx_movbrinde_filial (filial_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE aprovacoes
    MODIFY entidade_tipo ENUM('requisicao','ordem_compra','transferencia','requisicao_brinde') NOT NULL;

ALTER TABLE filiais ADD COLUMN is_matriz TINYINT(1) NOT NULL DEFAULT 0;

-- Permissões de acesso ao módulo de brindes (a gestão x baixa é controlada por is_matriz_user()).
INSERT INTO permissoes_perfil (perfil, modulo, acesso) VALUES
  ('solicitante', 'brindes', 'criar'),
  ('almoxarife',  'brindes', 'criar'),
  ('comprador',   'brindes', 'criar'),
  ('gestor',      'brindes', 'total'),
  ('diretor',     'brindes', 'total')
ON DUPLICATE KEY UPDATE acesso = VALUES(acesso);
