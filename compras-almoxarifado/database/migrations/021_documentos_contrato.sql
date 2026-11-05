-- ---------------------------------------------------------------------------
-- Múltiplos anexos para contratos
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documentos_contrato (
    id             CHAR(36)     NOT NULL,
    contrato_id    CHAR(36)     NOT NULL,
    nome_original  VARCHAR(255) NOT NULL,
    arquivo_path   VARCHAR(255) NOT NULL,
    tamanho_bytes  INT UNSIGNED NOT NULL DEFAULT 0,
    criado_por     CHAR(36)     NOT NULL,
    criado_em      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_doccontrato_contrato (contrato_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
