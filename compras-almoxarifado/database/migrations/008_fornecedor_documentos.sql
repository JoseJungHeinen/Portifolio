-- ══════════════════════════════════════════════════════════════════
-- Migration 008 — Fornecedores: tabela de documentos para upload
-- Data: 2026-05-16
-- ══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS documentos_fornecedor (
    id              CHAR(36)      NOT NULL,
    fornecedor_id   CHAR(36)      NOT NULL,
    tipo            ENUM('contrato','cartao_cnpj','certidao','nota_fiscal','outros') NOT NULL DEFAULT 'outros',
    nome_original   VARCHAR(255)  NOT NULL COMMENT 'Nome original do arquivo',
    arquivo_path    VARCHAR(500)  NOT NULL COMMENT 'Caminho relativo em storage/fornecedores/',
    tamanho_bytes   INT           NOT NULL DEFAULT 0,
    criado_por      CHAR(36)      NOT NULL,
    criado_em       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_doc_fornecedor (fornecedor_id),
    CONSTRAINT fk_doc_fornecedor FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_usuario    FOREIGN KEY (criado_por)    REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
