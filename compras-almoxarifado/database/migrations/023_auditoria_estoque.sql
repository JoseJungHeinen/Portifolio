-- Migration 023: Auditoria de Estoque
CREATE TABLE IF NOT EXISTS auditorias_estoque (
    id            CHAR(36)      NOT NULL,
    filial_id     CHAR(36)      NOT NULL,
    escopo        ENUM('todos','grupo','categoria') NOT NULL DEFAULT 'todos',
    escopo_id     CHAR(36)      NULL,
    escopo_nome   VARCHAR(120)  NULL,
    status        ENUM('aberta','em_conferencia','finalizada') NOT NULL DEFAULT 'aberta',
    observacao    TEXT          NULL,
    criado_por    CHAR(36)      NOT NULL,
    criado_em     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finalizado_em TIMESTAMP     NULL,
    PRIMARY KEY (id),
    KEY idx_aud_filial (filial_id),
    KEY idx_aud_status (status),
    CONSTRAINT fk_aud_filial   FOREIGN KEY (filial_id)  REFERENCES filiais(id),
    CONSTRAINT fk_aud_criador  FOREIGN KEY (criado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auditoria_itens (
    id            CHAR(36)      NOT NULL,
    auditoria_id  CHAR(36)      NOT NULL,
    item_id       CHAR(36)      NOT NULL,
    qtd_sistema   DECIMAL(10,2) NOT NULL DEFAULT 0,
    qtd_fisica    DECIMAL(10,2) NULL,
    PRIMARY KEY (id),
    KEY idx_auditem_audit (auditoria_id),
    CONSTRAINT fk_auditem_auditoria FOREIGN KEY (auditoria_id) REFERENCES auditorias_estoque(id) ON DELETE CASCADE,
    CONSTRAINT fk_auditem_item      FOREIGN KEY (item_id)      REFERENCES itens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
