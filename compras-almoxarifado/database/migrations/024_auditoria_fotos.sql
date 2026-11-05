-- Migration 024: Fotos do estoque na Auditoria de Estoque
CREATE TABLE IF NOT EXISTS auditoria_fotos (
    id            CHAR(36)     NOT NULL,
    auditoria_id  CHAR(36)     NOT NULL,
    arquivo_path  VARCHAR(255) NOT NULL,
    nome_original VARCHAR(255) NULL,
    legenda       VARCHAR(255) NULL,
    tamanho_bytes INT          NULL,
    criado_por    CHAR(36)     NOT NULL,
    criado_em     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audfoto_aud (auditoria_id),
    CONSTRAINT fk_audfoto_aud  FOREIGN KEY (auditoria_id) REFERENCES auditorias_estoque(id) ON DELETE CASCADE,
    CONSTRAINT fk_audfoto_user FOREIGN KEY (criado_por)   REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
