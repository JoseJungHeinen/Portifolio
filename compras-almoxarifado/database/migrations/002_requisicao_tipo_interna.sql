-- ══════════════════════════════════════════════════════════════════
-- Migration 002 — Tipo de Requisição: compra | interna
-- Data: 2026-05-15
-- ══════════════════════════════════════════════════════════════════

-- Adiciona tipo e filial_destino na tabela requisicoes
ALTER TABLE requisicoes
    ADD COLUMN tipo              ENUM('compra','interna') NOT NULL DEFAULT 'compra' AFTER setor_id,
    ADD COLUMN filial_destino_id CHAR(36) NULL AFTER tipo,
    ADD KEY idx_req_tipo (tipo),
    ADD CONSTRAINT fk_req_filial_destino FOREIGN KEY (filial_destino_id) REFERENCES filiais(id);

-- grupo_produto_id pode ser NULL para requisições internas
ALTER TABLE requisicoes
    MODIFY COLUMN grupo_produto_id CHAR(36) NULL;
