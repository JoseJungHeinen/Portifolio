-- Migration 026: Conferência de custo OC x NF (landed cost)
ALTER TABLE notas_fiscais
    ADD COLUMN frete_total    DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER valor_total,
    ADD COLUMN rateio_metodo  ENUM('valor','quantidade') NOT NULL DEFAULT 'valor' AFTER frete_total;

CREATE TABLE IF NOT EXISTS nota_fiscal_itens (
    id               CHAR(36)      NOT NULL,
    nota_fiscal_id   CHAR(36)      NOT NULL,
    oc_item_id       CHAR(36)      NULL,
    item_id          CHAR(36)      NULL,
    codigo_nf        VARCHAR(60)   NULL,
    descricao_nf     VARCHAR(200)  NOT NULL,
    unidade          VARCHAR(10)   NULL,
    quantidade       DECIMAL(10,2) NOT NULL DEFAULT 0,
    valor_unitario   DECIMAL(14,4) NOT NULL DEFAULT 0,
    ipi_percentual   DECIMAL(5,2)  NOT NULL DEFAULT 0,
    ipi_valor        DECIMAL(14,2) NOT NULL DEFAULT 0,
    frete_rateado    DECIMAL(14,2) NOT NULL DEFAULT 0,
    custo_final_unit DECIMAL(14,4) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_nfitem_nf (nota_fiscal_id),
    CONSTRAINT fk_nfitem_nf FOREIGN KEY (nota_fiscal_id) REFERENCES notas_fiscais(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fase 2 (lado previsto) — já criadas aqui para a migration ser única:
ALTER TABLE ordens_compra
    ADD COLUMN frete_previsto DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER valor_total;
ALTER TABLE ordens_compra_itens
    ADD COLUMN ipi_percentual DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER valor_unitario;
