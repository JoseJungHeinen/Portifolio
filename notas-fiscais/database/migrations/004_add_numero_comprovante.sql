-- Migration 004: Adiciona campo numero_comprovante em nf_parcelas
-- Relacionado ao mockup de Controle Financeiro — modal Registrar Pagamento

ALTER TABLE nf_parcelas
    ADD COLUMN numero_comprovante VARCHAR(100) NULL DEFAULT NULL
    AFTER observacao;
